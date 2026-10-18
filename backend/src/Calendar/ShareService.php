<?php
declare(strict_types=1);

namespace Postvak\Calendar;

use Postvak\Config;
use Postvak\Crypto;
use Postvak\Database;
use Postvak\Http\HttpException;
use Sabre\VObject;
use Throwable;

/**
 * A secret link per calendar that serves it as one .ics file, for services that can only subscribe
 * to a calendar by address (Google Calendar "From URL", Outlook "Subscribe from web"). Read-only,
 * revocable; the token is kept encrypted so the user can copy the link again later.
 */
final class ShareService
{
    public static function url(string $token): string
    {
        return rtrim((string) Config::get('app_url', ''), '/') . '/api/ical/' . $token . '.ics';
    }

    /** Share links per calendar instance id. @return array<int, string> */
    public function linksFor(int $userId): array
    {
        $out = [];
        foreach (Database::all('SELECT instance_id, token_enc FROM calendar_shares WHERE user_id = ?', [$userId]) as $row) {
            try {
                $out[(int) $row['instance_id']] = self::url(Crypto::decrypt((string) $row['token_enc']));
            } catch (Throwable) {
            }
        }
        return $out;
    }

    /** Create (or replace) the link of a calendar of this user. */
    public function create(int $userId, int $instanceId): string
    {
        $this->assertOwn($userId, $instanceId);
        $token = rtrim(strtr(base64_encode(random_bytes(24)), '+/', '-_'), '=');
        Database::run(
            'REPLACE INTO calendar_shares (instance_id, user_id, token_enc, token_hash) VALUES (?, ?, ?, ?)',
            [$instanceId, $userId, Crypto::encrypt($token), hash('sha256', $token)]
        );
        return self::url($token);
    }

    public function revoke(int $userId, int $instanceId): void
    {
        Database::run('DELETE FROM calendar_shares WHERE instance_id = ? AND user_id = ?', [$instanceId, $userId]);
    }

    /** The calendar as iCalendar text, or null when the token is unknown. */
    public function export(string $token): ?string
    {
        if (!preg_match('/^[A-Za-z0-9_-]{20,64}$/', $token)) {
            return null;
        }
        $share = Database::one(
            'SELECT s.instance_id, i.calendarid, i.displayname, i.calendarcolor FROM calendar_shares s
             JOIN dav_calendarinstances i ON i.id = s.instance_id WHERE s.token_hash = ?',
            [hash('sha256', $token)]
        );
        if ($share === null) {
            return null;
        }
        Database::run('UPDATE calendar_shares SET last_used_at = ? WHERE instance_id = ?', [gmdate('Y-m-d H:i:s'), $share['instance_id']]);

        $out = new VObject\Component\VCalendar(['PRODID' => '-//Postvak//Share//EN']);
        $out->add('X-WR-CALNAME', (string) ($share['displayname'] ?? 'Postvak'));
        if (!empty($share['calendarcolor'])) {
            $out->add('X-APPLE-CALENDAR-COLOR', substr((string) $share['calendarcolor'], 0, 7));
        }
        $out->add('REFRESH-INTERVAL', 'PT1H', ['VALUE' => 'DURATION']);
        $out->add('X-PUBLISHED-TTL', 'PT1H');
        $timezones = [];
        foreach (Database::all('SELECT calendardata FROM dav_calendarobjects WHERE calendarid = ?', [$share['calendarid']]) as $row) {
            try {
                $vcal = VObject\Reader::read((string) $row['calendardata']);
            } catch (Throwable) {
                continue;
            }
            foreach ($vcal->getComponents() as $component) {
                if ($component->name === 'VTIMEZONE') {
                    $tzid = (string) $component->TZID;
                    if (!isset($timezones[$tzid])) {
                        $timezones[$tzid] = true;
                        $out->add(clone $component);
                    }
                } elseif (in_array($component->name, ['VEVENT', 'VTODO'], true)) {
                    $copy = clone $component;
                    // Reminders are personal; a subscriber should not get them.
                    foreach ($copy->select('VALARM') as $alarm) {
                        $copy->remove($alarm);
                    }
                    $out->add($copy);
                }
            }
        }
        return $out->serialize();
    }

    private function assertOwn(int $userId, int $instanceId): void
    {
        $principal = CalendarService::principal($userId);
        if (Database::one('SELECT id FROM dav_calendarinstances WHERE id = ? AND principaluri = ?', [$instanceId, $principal]) === null) {
            throw HttpException::notFound('Calendar not found');
        }
    }
}
