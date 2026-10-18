<?php
declare(strict_types=1);

namespace Postvak\Calendar;

use Postvak\Crypto;
use Postvak\Database;
use Postvak\Http\Fetcher;
use Postvak\Http\HttpException;
use RuntimeException;
use Sabre\VObject;
use Throwable;

/**
 * Calendars from elsewhere through an iCal address (Google's "secret address in iCal format", Outlook,
 * sports clubs, holidays, …). The cron job downloads them into a read-only calendar of the user, so
 * they show in Postvak and, through CalDAV, on the user's phone. Only changed events are written,
 * so phones sync just the differences.
 */
final class FeedService
{
    public const MAX_FEEDS = 20;
    private const MAX_EVENTS = 5000;
    private const REFRESH_MINUTES = 30;

    public function __construct(private readonly CalendarService $calendars = new CalendarService()) {}

    /** @return array<int, array{id:int, calendar_id:int, url:string, last_fetch_at:?string, last_success_at:?string, last_error:?string}> */
    public function list(int $userId): array
    {
        $out = [];
        foreach (Database::all('SELECT * FROM calendar_feeds WHERE user_id = ? ORDER BY id', [$userId]) as $row) {
            $out[] = self::shape($row);
        }
        return $out;
    }

    /** Feed info per calendar instance id (for the calendar list). @return array<int, array> */
    public function byInstance(int $userId): array
    {
        return array_column($this->list($userId), null, 'calendar_id');
    }

    /** Add a feed and download it right away, so a wrong address is reported at once. */
    public function add(int $userId, string $url, string $name, string $color): array
    {
        $url = Fetcher::normalize($url);
        try {
            Fetcher::check($url);
        } catch (RuntimeException $e) {
            throw HttpException::badRequest($e->getMessage());
        }
        $hash = hash('sha256', $userId . '|' . $url);
        if (Database::one('SELECT id FROM calendar_feeds WHERE user_id = ? AND url_hash = ?', [$userId, $hash])) {
            throw new HttpException(409, 'This calendar has already been added');
        }
        if ((int) (Database::one('SELECT COUNT(*) n FROM calendar_feeds WHERE user_id = ?', [$userId])['n'] ?? 0) >= self::MAX_FEEDS) {
            throw HttpException::badRequest('Too many calendars from elsewhere (max ' . self::MAX_FEEDS . ')');
        }

        // Download first: nothing is created for an address that does not work.
        try {
            $download = $this->download($url, null);
            $vcal = self::parse($download['body']);
        } catch (RuntimeException $e) {
            throw HttpException::badRequest($e->getMessage());
        }
        if (trim($name) === '') {
            $name = trim((string) ($vcal->{'X-WR-CALNAME'} ?? '')) ?: (string) parse_url($url, PHP_URL_HOST);
        }

        $instanceId = $this->calendars->createReadOnlyCalendar($userId, $name, $color);
        Database::run(
            'INSERT INTO calendar_feeds (user_id, instance_id, url_enc, url_hash) VALUES (?, ?, ?, ?)',
            [$userId, $instanceId, Crypto::encrypt($url), $hash]
        );
        $feed = Database::one('SELECT * FROM calendar_feeds WHERE id = ?', [Database::lastId()]);
        try {
            Database::run('UPDATE calendar_feeds SET last_fetch_at = ? WHERE id = ?', [gmdate('Y-m-d H:i:s'), $feed['id']]);
            $this->store($feed, $vcal, $download['headers']);
        } catch (Throwable $e) {
            $this->remove($userId, (int) $feed['id']);
            throw $e;
        }
        return self::shape(Database::one('SELECT * FROM calendar_feeds WHERE id = ?', [(int) $feed['id']]));
    }

    public function remove(int $userId, int $feedId): void
    {
        $feed = $this->find($userId, $feedId);
        $this->calendars->dropCalendar((int) $feed['instance_id']);
        Database::run('DELETE FROM calendar_feeds WHERE id = ?', [$feedId]);
    }

    /** The calendar of a feed was deleted (from the calendar list): forget the feed too. */
    public function removeByInstance(int $userId, int $instanceId): bool
    {
        $feed = Database::one('SELECT id FROM calendar_feeds WHERE user_id = ? AND instance_id = ?', [$userId, $instanceId]);
        if ($feed === null) {
            return false;
        }
        $this->remove($userId, (int) $feed['id']);
        return true;
    }

    public function refreshOne(int $userId, int $feedId): array
    {
        $feed = $this->find($userId, $feedId);
        $this->refresh($feed, true);
        return self::shape(Database::one('SELECT * FROM calendar_feeds WHERE id = ?', [$feedId]));
    }

    /** From cron: refresh feeds not fetched in the last half hour. @return array{feeds:int, updated:int, errors:int} */
    public function refreshDue(?callable $log = null): array
    {
        $stats = ['feeds' => 0, 'updated' => 0, 'errors' => 0];
        $due = Database::all(
            'SELECT * FROM calendar_feeds WHERE last_fetch_at IS NULL OR last_fetch_at < ? ORDER BY last_fetch_at LIMIT 25',
            [gmdate('Y-m-d H:i:s', time() - self::REFRESH_MINUTES * 60)]
        );
        foreach ($due as $feed) {
            $stats['feeds']++;
            try {
                if ($this->refresh($feed)) {
                    $stats['updated']++;
                }
            } catch (Throwable $e) {
                $stats['errors']++;
                $log && $log("feed {$feed['id']}: " . $e->getMessage());
            }
        }
        return $stats;
    }

    /** @return bool whether the calendar changed */
    private function refresh(array $feed, bool $force = false): bool
    {
        Database::run('UPDATE calendar_feeds SET last_fetch_at = ? WHERE id = ?', [gmdate('Y-m-d H:i:s'), $feed['id']]);
        try {
            $download = $this->download(Crypto::decrypt((string) $feed['url_enc']), $force ? null : $feed);
            if ($download['status'] === 304) {
                Database::run('UPDATE calendar_feeds SET last_success_at = ?, last_error = NULL WHERE id = ?', [gmdate('Y-m-d H:i:s'), $feed['id']]);
                return false;
            }
            return $this->store($feed, self::parse($download['body']), $download['headers']);
        } catch (Throwable $e) {
            Database::run('UPDATE calendar_feeds SET last_error = ? WHERE id = ?', [mb_substr($e->getMessage(), 0, 500), $feed['id']]);
            throw $e;
        }
    }

    /** @return array{status:int, body:string, headers:array<string,string>} */
    private function download(string $url, ?array $feed): array
    {
        $headers = ['Accept' => 'text/calendar, */*;q=0.5'];
        if ($feed && $feed['etag']) {
            $headers['If-None-Match'] = $feed['etag'];
        }
        if ($feed && $feed['last_modified']) {
            $headers['If-Modified-Since'] = $feed['last_modified'];
        }
        $res = Fetcher::get($url, 10_000_000, 25, $headers);
        if ($res['status'] === 304) {
            return $res;
        }
        if ($res['status'] === 404 || $res['status'] === 410) {
            throw new RuntimeException('The calendar was not found at this address (HTTP ' . $res['status'] . ')');
        }
        if ($res['status'] === 401 || $res['status'] === 403) {
            throw new RuntimeException('The other service refuses access (HTTP ' . $res['status'] . '); the address may have been reset');
        }
        if ($res['status'] < 200 || $res['status'] >= 300) {
            throw new RuntimeException('The other service answered HTTP ' . $res['status']);
        }
        return $res;
    }

    public static function parse(string $body): VObject\Component\VCalendar
    {
        $body = ltrim($body, "\xEF\xBB\xBF \r\n\t");
        if (!str_starts_with(strtoupper($body), 'BEGIN:VCALENDAR')) {
            throw new RuntimeException('This address does not give an iCal calendar (.ics)');
        }
        try {
            $vcal = VObject\Reader::read($body, VObject\Reader::OPTION_FORGIVING);
        } catch (Throwable $e) {
            throw new RuntimeException('The calendar could not be read: ' . $e->getMessage());
        }
        if (!$vcal instanceof VObject\Component\VCalendar) {
            throw new RuntimeException('This address does not give an iCal calendar (.ics)');
        }
        return $vcal;
    }

    /**
     * Write the events of a downloaded calendar: one calendar object per UID (the series plus its
     * changed occurrences, with the time zones they use). Unchanged objects are left alone.
     * @return bool whether anything changed
     */
    private function store(array $feed, VObject\Component\VCalendar $vcal, array $headers): bool
    {
        $timezones = [];
        foreach ($vcal->select('VTIMEZONE') as $tz) {
            $timezones[(string) $tz->TZID] = $tz;
        }
        $groups = [];
        foreach ($vcal->select('VEVENT') as $ev) {
            $uid = trim((string) ($ev->UID ?? ''));
            if ($uid === '') {
                $uid = 'nouid-' . sha1($ev->serialize());
            }
            $groups[$uid][] = $ev;
            if (count($groups) > self::MAX_EVENTS) {
                break;
            }
        }

        $wanted = [];
        foreach ($groups as $uid => $events) {
            $out = new VObject\Component\VCalendar(['PRODID' => '-//Postvak//Feed//EN']);
            $used = [];
            foreach ($events as $ev) {
                $copy = clone $ev;
                // Reminders from someone else's calendar would turn into push notifications here.
                foreach ($copy->select('VALARM') as $alarm) {
                    $copy->remove($alarm);
                }
                foreach (['DTSTART', 'DTEND', 'RECURRENCE-ID', 'EXDATE', 'RDATE'] as $prop) {
                    foreach ($copy->select($prop) as $p) {
                        if (isset($p['TZID'])) {
                            $used[(string) $p['TZID']] = true;
                        }
                    }
                }
                $out->add($copy);
            }
            foreach (array_keys($used) as $tzid) {
                if (isset($timezones[$tzid])) {
                    $out->add(clone $timezones[$tzid]);
                }
            }
            $wanted['feed-' . sha1($uid) . '.ics'] = $out->serialize();
        }

        $backend = $this->calendars->backendForFeeds();
        $instance = Database::one('SELECT id, calendarid FROM dav_calendarinstances WHERE id = ?', [$feed['instance_id']]);
        if ($instance === null) {
            throw new RuntimeException('The calendar of this feed no longer exists');
        }
        $cal = [(int) $instance['calendarid'], (int) $instance['id']];
        $existing = array_column(Database::all('SELECT uri, etag FROM dav_calendarobjects WHERE calendarid = ?', [$cal[0]]), 'etag', 'uri');

        $changed = false;
        foreach ($wanted as $uri => $data) {
            if (!isset($existing[$uri])) {
                $backend->createCalendarObject($cal, $uri, $data);
                $changed = true;
            } elseif ($existing[$uri] !== md5($data)) {
                $backend->updateCalendarObject($cal, $uri, $data);
                $changed = true;
            }
        }
        foreach (array_diff_key($existing, $wanted) as $uri => $etag) {
            $backend->deleteCalendarObject($cal, (string) $uri);
            $changed = true;
        }

        Database::run(
            'UPDATE calendar_feeds SET last_success_at = ?, last_error = NULL, etag = ?, last_modified = ? WHERE id = ?',
            [gmdate('Y-m-d H:i:s'), isset($headers['etag']) ? mb_substr($headers['etag'], 0, 255) : null, isset($headers['last-modified']) ? mb_substr($headers['last-modified'], 0, 100) : null, $feed['id']]
        );
        return $changed;
    }

    private function find(int $userId, int $feedId): array
    {
        $feed = Database::one('SELECT * FROM calendar_feeds WHERE id = ? AND user_id = ?', [$feedId, $userId]);
        return $feed ?? throw HttpException::notFound('Calendar not found');
    }

    private static function shape(array $row): array
    {
        try {
            $url = Crypto::decrypt((string) $row['url_enc']);
        } catch (Throwable) {
            $url = '';
        }
        $iso = static fn ($v) => $v ? gmdate(DATE_ATOM, (int) strtotime($v . ' UTC')) : null;
        return [
            'id'              => (int) $row['id'],
            'calendar_id'     => (int) $row['instance_id'],
            // Shown shortened: the address is a password to the other calendar.
            'url'             => self::mask($url),
            'host'            => (string) parse_url($url, PHP_URL_HOST),
            'last_fetch_at'   => $iso($row['last_fetch_at']),
            'last_success_at' => $iso($row['last_success_at']),
            'last_error'      => $row['last_error'],
        ];
    }

    private static function mask(string $url): string
    {
        $p = parse_url($url);
        if (!$p || empty($p['host'])) {
            return '';
        }
        $path = (string) ($p['path'] ?? '');
        return $p['scheme'] . '://' . $p['host'] . (isset($p['port']) ? ':' . $p['port'] : '') . (strlen($path) > 16 ? substr($path, 0, 12) . '…' : $path);
    }
}
