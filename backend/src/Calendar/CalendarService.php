<?php
declare(strict_types=1);

namespace Postvak\Calendar;

use DateInterval;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Postvak\Database;
use Postvak\Dav\CalendarBackend;
use Postvak\Dav\PrincipalBackend;
use Postvak\Http\HttpException;
use Postvak\Repositories\SettingsRepository;
use Sabre\VObject;
use Sabre\VObject\Component\VCalendar;
use Sabre\VObject\Component\VEvent;
use Throwable;

/**
 * Calendars, events and tasks for the Postvak interface. Everything is stored through sabre/dav's
 * backend, so phones syncing over CalDAV see the same data (and changes get sync tokens).
 */
final class CalendarService
{
    private const COLORS = ['#3646D9', '#0F8A5F', '#C2410C', '#9333EA', '#DB2777', '#0E7490', '#CA8A04'];

    private ?CalendarBackend $davBackend = null;

    /** Created on first use: every API request builds the controllers, most never touch the calendar. */
    private function backend(): CalendarBackend
    {
        return $this->davBackend ??= new CalendarBackend();
    }

    // -------------------------------------------------------------- calendars

    public static function principal(int $userId): string
    {
        $user = Database::one('SELECT login_email FROM users WHERE id = ?', [$userId]);
        if ($user === null) {
            throw HttpException::unauthorized();
        }
        return PrincipalBackend::uriFor($user['login_email']);
    }

    /** A new user gets one calendar and one task list. */
    public function ensureDefaults(int $userId): void
    {
        $principal = self::principal($userId);
        if (Database::one('SELECT id FROM dav_calendarinstances WHERE principaluri = ? LIMIT 1', [$principal]) !== null) {
            return;
        }
        $nl = self::language($userId) === 'nl';
        $this->createRaw($principal, 'agenda', $nl ? 'Agenda' : 'Calendar', self::COLORS[0], ['VEVENT'], 0);
        $this->createRaw($principal, 'taken', $nl ? 'Taken' : 'Tasks', self::COLORS[1], ['VTODO'], 1);
    }

    /** A calendar that only Postvak itself fills (a feed from elsewhere): read-only for the user and for phones. */
    public function createReadOnlyCalendar(int $userId, string $name, string $color): int
    {
        $principal = self::principal($userId);
        $uri = 'feed-' . bin2hex(random_bytes(6));
        $order = (int) (Database::one('SELECT MAX(calendarorder) m FROM dav_calendarinstances WHERE principaluri = ?', [$principal])['m'] ?? 0) + 1;
        $this->createRaw($principal, $uri, self::cleanName($name), self::color($color), ['VEVENT'], $order);
        $id = (int) Database::one('SELECT id FROM dav_calendarinstances WHERE principaluri = ? AND uri = ?', [$principal, $uri])['id'];
        // Access 2 = read: sabre/dav then refuses changes from calendar apps, and instance() refuses them here.
        Database::run('UPDATE dav_calendarinstances SET access = 2 WHERE id = ?', [$id]);
        return $id;
    }

    /** Remove a calendar completely, whatever its access level (feeds are read-only for the user). */
    public function dropCalendar(int $instanceId): void
    {
        $row = Database::one('SELECT id, calendarid FROM dav_calendarinstances WHERE id = ?', [$instanceId]);
        if ($row === null) {
            return;
        }
        Database::run('UPDATE dav_calendarinstances SET access = 1 WHERE id = ?', [$instanceId]);
        $this->backend()->deleteCalendar([(int) $row['calendarid'], (int) $row['id']]);
        Database::run('DELETE FROM calendar_shares WHERE instance_id = ?', [$instanceId]);
    }

    public function backendForFeeds(): CalendarBackend
    {
        return $this->backend();
    }

    private function createRaw(string $principal, string $uri, string $name, string $color, array $components, int $order): void
    {
        $this->backend()->createCalendar($principal, $uri, [
            '{DAV:}displayname' => $name,
            '{http://apple.com/ns/ical/}calendar-color' => $color,
            '{http://apple.com/ns/ical/}calendar-order' => $order,
            '{urn:ietf:params:xml:ns:caldav}supported-calendar-component-set' => new \Sabre\CalDAV\Xml\Property\SupportedCalendarComponentSet($components),
        ]);
    }

    /** @return array<int, array{id:int, name:string, color:string, type:string, order:int, writable:bool}> */
    public function calendars(int $userId): array
    {
        $this->ensureDefaults($userId);
        $out = [];
        foreach ($this->backend()->getCalendarsForUser(self::principal($userId)) as $c) {
            $components = $c['{urn:ietf:params:xml:ns:caldav}supported-calendar-component-set']->getValue();
            $events = in_array('VEVENT', $components, true);
            $tasks = in_array('VTODO', $components, true);
            $out[] = [
                'id'       => (int) $c['id'][1],
                'name'     => (string) ($c['{DAV:}displayname'] ?? $c['uri']),
                'color'    => substr((string) ($c['{http://apple.com/ns/ical/}calendar-color'] ?? self::COLORS[0]), 0, 7),
                'type'     => $events && $tasks ? 'both' : ($tasks ? 'tasks' : 'events'),
                'order'    => (int) ($c['{http://apple.com/ns/ical/}calendar-order'] ?? 0),
                'writable' => (int) ($c['share-access'] ?? 1) !== 2 && empty($c['read-only']),
            ];
        }
        usort($out, static fn ($a, $b) => [$a['order'], $a['id']] <=> [$b['order'], $b['id']]);
        return $out;
    }

    public function createCalendar(int $userId, string $name, string $color, string $type): array
    {
        $name = self::cleanName($name);
        $principal = self::principal($userId);
        $uri = substr(preg_replace('/[^a-z0-9]+/', '-', strtolower($name)) ?: 'agenda', 0, 40) . '-' . bin2hex(random_bytes(3));
        $order = (int) (Database::one('SELECT MAX(calendarorder) m FROM dav_calendarinstances WHERE principaluri = ?', [$principal])['m'] ?? 0) + 1;
        $this->createRaw($principal, $uri, $name, self::color($color), $type === 'tasks' ? ['VTODO'] : ['VEVENT'], $order);
        $id = (int) Database::one('SELECT id FROM dav_calendarinstances WHERE principaluri = ? AND uri = ?', [$principal, $uri])['id'];
        return $this->findCalendar($userId, $id);
    }

    public function updateCalendar(int $userId, int $id, array $input): array
    {
        // Name and colour may change for read-only calendars (from elsewhere) too: they are the user's own view.
        $cal = Database::one('SELECT id, calendarid FROM dav_calendarinstances WHERE id = ? AND principaluri = ?', [$id, self::principal($userId)])
            ?? throw HttpException::notFound('Calendar not found');
        $props = [];
        if (isset($input['name'])) {
            $props['{DAV:}displayname'] = self::cleanName((string) $input['name']);
        }
        if (isset($input['color'])) {
            $props['{http://apple.com/ns/ical/}calendar-color'] = self::color((string) $input['color']);
        }
        if ($props) {
            $patch = new \Sabre\DAV\PropPatch($props);
            $this->backend()->updateCalendar([(int) $cal['calendarid'], (int) $cal['id']], $patch);
            $patch->commit();
        }
        return $this->findCalendar($userId, $id);
    }

    public function deleteCalendar(int $userId, int $id): void
    {
        $target = $this->findCalendar($userId, $id);
        $sameType = array_filter($this->calendars($userId), static fn ($c) => $c['type'] === $target['type']);
        if (count($sameType) <= 1) {
            throw HttpException::badRequest('The last calendar or task list cannot be deleted');
        }
        $cal = $this->instance($userId, $id);
        $this->backend()->deleteCalendar([(int) $cal['calendarid'], (int) $cal['id']]);
        Database::run('DELETE FROM calendar_shares WHERE instance_id = ?', [$id]);
    }

    /** Everything of a user that is removed from Postvak. */
    public function deleteAllFor(int $userId): void
    {
        try {
            $principal = self::principal($userId);
        } catch (Throwable) {
            return;
        }
        foreach (Database::all('SELECT id FROM dav_calendarinstances WHERE principaluri = ?', [$principal]) as $row) {
            $this->dropCalendar((int) $row['id']);
        }
    }

    private function findCalendar(int $userId, int $id): array
    {
        foreach ($this->calendars($userId) as $c) {
            if ($c['id'] === $id) {
                return $c;
            }
        }
        throw HttpException::notFound('Calendar not found');
    }

    /** The user's own (writable) calendar instance row. */
    private function instance(int $userId, int $instanceId): array
    {
        $row = Database::one(
            'SELECT id, calendarid, access FROM dav_calendarinstances WHERE id = ? AND principaluri = ?',
            [$instanceId, self::principal($userId)]
        );
        if ($row === null) {
            throw HttpException::notFound('Calendar not found');
        }
        if (!in_array((int) $row['access'], [1, 3], true)) {
            throw HttpException::forbidden('This calendar is read-only');
        }
        return $row;
    }

    // ----------------------------------------------------------------- events

    /**
     * Occurrences between $start and $end (recurring events expanded).
     * @param int[]|null $calendarIds instance ids; null = all
     */
    public function events(int $userId, DateTimeImmutable $start, DateTimeImmutable $end, DateTimeZone $tz, ?array $calendarIds = null): array
    {
        $calendars = array_column(array_filter($this->calendars($userId), static fn ($c) => $c['type'] !== 'tasks'), null, 'id');
        if ($calendarIds !== null) {
            $calendars = array_intersect_key($calendars, array_flip($calendarIds));
        }
        if (!$calendars) {
            return [];
        }
        $in = implode(',', array_fill(0, count($calendars), '?'));
        $rows = Database::all(
            "SELECT o.id, o.calendardata, i.id AS instance_id FROM dav_calendarobjects o
             JOIN dav_calendarinstances i ON i.calendarid = o.calendarid
             WHERE i.id IN ($in) AND o.componenttype = 'VEVENT'
               AND (o.firstoccurence IS NULL OR o.firstoccurence <= ?)
               AND (o.lastoccurence IS NULL OR o.lastoccurence >= ?)",
            [...array_keys($calendars), $end->getTimestamp(), $start->getTimestamp()]
        );

        $out = [];
        foreach ($rows as $row) {
            try {
                $vcal = VObject\Reader::read((string) $row['calendardata']);
                $master = self::master($vcal, 'VEVENT');
                if ($master === null) {
                    continue;
                }
                $info = self::masterInfo($master, $tz);
                $recurring = isset($master->RRULE) || isset($master->RDATE);
                $expanded = $vcal->expand($start, $end, $tz);
                foreach ($expanded->select('VEVENT') as $ev) {
                    $out[] = $this->occurrence((int) $row['id'], (int) $row['instance_id'], $ev, $info, $recurring, $calendars[(int) $row['instance_id']]['color'], $tz);
                }
            } catch (Throwable $e) {
                error_log('[postvak] calendar object ' . $row['id'] . ' skipped: ' . $e->getMessage());
            }
        }
        usort($out, static fn ($a, $b) => [$a['start'], $a['title']] <=> [$b['start'], $b['title']]);
        return $out;
    }

    private function occurrence(int $id, int $calendarId, VEvent $ev, array $info, bool $recurring, string $color, DateTimeZone $tz): array
    {
        $allDay = !$ev->DTSTART->hasTime();
        $start = $ev->DTSTART->getDateTime($tz);
        $end = self::endOf($ev, $tz);
        $occurrence = isset($ev->{'RECURRENCE-ID'}) ? $ev->{'RECURRENCE-ID'}->getDateTime()->getTimestamp() : null;
        return [
            'key'         => $id . ':' . ($occurrence ?? 0),
            'id'          => $id,
            'calendar_id' => $calendarId,
            'color'       => $color,
            'title'       => (string) ($ev->SUMMARY ?? ''),
            'location'    => (string) ($ev->LOCATION ?? ''),
            'description' => (string) ($ev->DESCRIPTION ?? ''),
            'all_day'     => $allDay,
            'start'       => $allDay ? $start->format('Y-m-d') : $start->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z'),
            'end'         => $allDay ? $end->format('Y-m-d') : $end->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z'),
            'recurring'   => $recurring,
            'occurrence'  => $recurring ? $occurrence : null,
            'reminder'    => $info['reminder'],
        ];
    }

    /** One event with the fields of the editor (the series for a recurring event). */
    public function event(int $userId, int $id, DateTimeZone $tz): array
    {
        $obj = $this->object($userId, $id);
        $vcal = VObject\Reader::read((string) $obj['calendardata']);
        $master = self::master($vcal, 'VEVENT') ?? throw HttpException::notFound('Event not found');
        return ['id' => $id, 'calendar_id' => (int) $obj['instance_id']] + self::masterInfo($master, $tz) + [
            'title'       => (string) ($master->SUMMARY ?? ''),
            'location'    => (string) ($master->LOCATION ?? ''),
            'description' => (string) ($master->DESCRIPTION ?? ''),
        ];
    }

    /**
     * Create (id null) or change an event. Input: calendar_id, title, location, description, all_day,
     * start/end ('Y-m-d' or 'Y-m-d\TH:i', local in timezone), timezone, reminder (minutes before, null = none),
     * recurrence (null | {rrule} to keep | {freq, interval, until, count}).
     */
    public function saveEvent(int $userId, ?int $id, array $input): array
    {
        $tz = self::tz((string) ($input['timezone'] ?? 'UTC'));
        $calendarId = (int) ($input['calendar_id'] ?? 0);
        $existing = $id !== null ? $this->object($userId, $id) : null;
        if ($calendarId === 0) {
            $calendarId = $existing ? (int) $existing['instance_id'] : $this->firstOfType($userId, 'events');
        }
        $target = $this->instance($userId, $calendarId);

        if ($existing) {
            $vcal = VObject\Reader::read((string) $existing['calendardata']);
            $ev = self::master($vcal, 'VEVENT') ?? throw HttpException::notFound('Event not found');
            $ev->SEQUENCE = (int) (string) ($ev->SEQUENCE ?? 0) + 1;
        } else {
            $vcal = new VCalendar(['PRODID' => '-//Postvak//Postvak//EN']);
            $ev = $vcal->add('VEVENT', ['UID' => self::uuid()]);
        }

        $title = trim((string) ($input['title'] ?? ''));
        $ev->SUMMARY = $title !== '' ? mb_substr($title, 0, 500) : '(…)';
        self::setText($ev, 'LOCATION', $input['location'] ?? null);
        self::setText($ev, 'DESCRIPTION', $input['description'] ?? null);

        $allDay = !empty($input['all_day']);
        [$start, $end] = self::parseRange((string) ($input['start'] ?? ''), (string) ($input['end'] ?? ''), $allDay, $tz);
        $oldStart = isset($ev->DTSTART) ? (string) $ev->DTSTART->getValue() : null;
        unset($ev->DTSTART, $ev->DTEND, $ev->DURATION);
        if ($allDay) {
            $ev->add('DTSTART', $start, ['VALUE' => 'DATE']);
            $ev->add('DTEND', $end, ['VALUE' => 'DATE']);
        } else {
            $ev->add('DTSTART', $start);
            $ev->add('DTEND', $end);
            self::addTimezone($vcal, $tz, (int) $start->format('Y'));
        }

        if (array_key_exists('recurrence', $input)) {
            $keep = is_array($input['recurrence']) && isset($input['recurrence']['rrule']) && isset($ev->RRULE)
                && (string) $ev->RRULE === (string) $input['recurrence']['rrule'];
            if (!$keep) {
                unset($ev->RRULE, $ev->EXDATE, $ev->RDATE);
                // Moved occurrences belong to the old rule.
                foreach ($vcal->select('VEVENT') as $other) {
                    if (isset($other->{'RECURRENCE-ID'})) {
                        $vcal->remove($other);
                    }
                }
                $rule = self::buildRule($input['recurrence'], $allDay, $tz);
                if ($rule !== null) {
                    $ev->RRULE = $rule;
                }
            } elseif ($oldStart !== null && $oldStart !== (string) $ev->DTSTART->getValue()) {
                // The series moved: excluded dates no longer match.
                unset($ev->EXDATE);
            }
        }

        if (array_key_exists('reminder', $input)) {
            foreach ($ev->select('VALARM') as $alarm) {
                $ev->remove($alarm);
            }
            if ($input['reminder'] !== null && $input['reminder'] !== '') {
                $minutes = (int) $input['reminder'];
                $alarm = $ev->add('VALARM', ['ACTION' => 'DISPLAY', 'DESCRIPTION' => $ev->SUMMARY->getValue()]);
                $alarm->add('TRIGGER', self::minutesToDuration(-$minutes));
            }
        }

        self::stamp($ev);
        return ['id' => $this->store($userId, $existing, $target, $vcal, (string) $ev->UID)];
    }

    /** Delete an event; with $occurrence (timestamp from the list) only that occurrence of a series. */
    public function deleteEvent(int $userId, int $id, ?int $occurrence = null): void
    {
        $obj = $this->object($userId, $id);
        $cal = [(int) $obj['calendarid'], (int) $obj['instance_id']];
        if ($occurrence === null) {
            $this->backend()->deleteCalendarObject($cal, $obj['uri']);
            return;
        }
        $vcal = VObject\Reader::read((string) $obj['calendardata']);
        $master = self::master($vcal, 'VEVENT') ?? throw HttpException::notFound('Event not found');
        foreach ($vcal->select('VEVENT') as $other) {
            if (isset($other->{'RECURRENCE-ID'}) && $other->{'RECURRENCE-ID'}->getDateTime()->getTimestamp() === $occurrence) {
                $vcal->remove($other);
            }
        }
        $at = (new DateTimeImmutable('@' . $occurrence));
        if ($master->DTSTART->hasTime()) {
            $tz = $master->DTSTART->getDateTime()->getTimezone();
            $master->add('EXDATE', $at->setTimezone($tz));
        } else {
            $master->add('EXDATE', $at, ['VALUE' => 'DATE']);
        }
        self::stamp($master);
        $this->backend()->updateCalendarObject($cal, $obj['uri'], $vcal->serialize());
    }

    // ------------------------------------------------------------------ tasks

    public function tasks(int $userId, DateTimeZone $tz, bool $withCompleted = true): array
    {
        $calendars = array_column(array_filter($this->calendars($userId), static fn ($c) => $c['type'] !== 'events'), null, 'id');
        if (!$calendars) {
            return [];
        }
        $in = implode(',', array_fill(0, count($calendars), '?'));
        $rows = Database::all(
            "SELECT o.id, o.calendardata, i.id AS instance_id FROM dav_calendarobjects o
             JOIN dav_calendarinstances i ON i.calendarid = o.calendarid
             WHERE i.id IN ($in) AND o.componenttype = 'VTODO'",
            array_keys($calendars)
        );
        $out = [];
        foreach ($rows as $row) {
            try {
                $todo = self::master(VObject\Reader::read((string) $row['calendardata']), 'VTODO');
                if ($todo === null) {
                    continue;
                }
                $task = self::taskInfo((int) $row['id'], (int) $row['instance_id'], $todo, $tz);
                if ($withCompleted || !$task['completed']) {
                    $out[] = $task + ['color' => $calendars[(int) $row['instance_id']]['color']];
                }
            } catch (Throwable $e) {
                error_log('[postvak] task ' . $row['id'] . ' skipped: ' . $e->getMessage());
            }
        }
        usort($out, static fn ($a, $b) => [$a['completed'], $a['due'] === null, $a['due'] ?? '', self::prioRank($a['priority']), $a['created']]
            <=> [$b['completed'], $b['due'] === null, $b['due'] ?? '', self::prioRank($b['priority']), $b['created']]);
        return $out;
    }

    private static function prioRank(int $p): int
    {
        return $p === 0 ? 5 : $p; // no priority sorts like "medium"
    }

    private static function taskInfo(int $id, int $calendarId, VObject\Component $todo, DateTimeZone $tz): array
    {
        $due = isset($todo->DUE) ? $todo->DUE : null;
        $dueAllDay = $due !== null && !$due->hasTime();
        $mail = isset($todo->{'X-POSTVAK-MAIL'}) ? json_decode((string) $todo->{'X-POSTVAK-MAIL'}, true) : null;
        $status = strtoupper((string) ($todo->STATUS ?? ''));
        return [
            'id'           => $id,
            'calendar_id'  => $calendarId,
            'title'        => (string) ($todo->SUMMARY ?? ''),
            'description'  => (string) ($todo->DESCRIPTION ?? ''),
            'due'          => $due === null ? null : ($dueAllDay ? $due->getDateTime($tz)->format('Y-m-d') : $due->getDateTime($tz)->format('Y-m-d\TH:i')),
            'due_all_day'  => $dueAllDay,
            'priority'     => (int) (string) ($todo->PRIORITY ?? 0),
            'completed'    => $status === 'COMPLETED' || isset($todo->COMPLETED),
            'completed_at' => isset($todo->COMPLETED) ? $todo->COMPLETED->getDateTime()->format(DateTimeInterface::ATOM) : null,
            'reminder'     => isset($todo->VALARM),
            'mail'         => is_array($mail) ? $mail : null,
            'created'      => isset($todo->CREATED) ? $todo->CREATED->getDateTime()->format(DateTimeInterface::ATOM) : '',
        ];
    }

    /** Input: calendar_id, title, description, due ('Y-m-d' or 'Y-m-d\TH:i' | null), priority (0/1/5/9), completed, reminder, mail, timezone. */
    public function saveTask(int $userId, ?int $id, array $input): array
    {
        $tz = self::tz((string) ($input['timezone'] ?? 'UTC'));
        $existing = $id !== null ? $this->object($userId, $id) : null;
        $calendarId = (int) ($input['calendar_id'] ?? 0) ?: ($existing ? (int) $existing['instance_id'] : $this->firstOfType($userId, 'tasks'));
        $target = $this->instance($userId, $calendarId);

        if ($existing) {
            $vcal = VObject\Reader::read((string) $existing['calendardata']);
            $todo = self::master($vcal, 'VTODO') ?? throw HttpException::notFound('Task not found');
        } else {
            $vcal = new VCalendar(['PRODID' => '-//Postvak//Postvak//EN']);
            $todo = $vcal->add('VTODO', ['UID' => self::uuid(), 'STATUS' => 'NEEDS-ACTION']);
        }

        if (array_key_exists('title', $input)) {
            $title = trim((string) $input['title']);
            $todo->SUMMARY = $title !== '' ? mb_substr($title, 0, 500) : '(…)';
        }
        if (array_key_exists('description', $input)) {
            self::setText($todo, 'DESCRIPTION', $input['description']);
        }
        if (array_key_exists('priority', $input)) {
            unset($todo->PRIORITY);
            $p = (int) $input['priority'];
            if (in_array($p, [1, 5, 9], true)) {
                $todo->PRIORITY = $p;
            }
        }
        if (array_key_exists('due', $input)) {
            unset($todo->DUE);
            $due = $input['due'];
            if (is_string($due) && $due !== '') {
                if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $due)) {
                    $todo->add('DUE', new DateTimeImmutable($due, $tz), ['VALUE' => 'DATE']);
                } elseif (preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/', $due)) {
                    $todo->add('DUE', new DateTimeImmutable($due, $tz));
                } else {
                    throw HttpException::badRequest('Invalid due date');
                }
            }
        }
        if (array_key_exists('reminder', $input) || array_key_exists('due', $input)) {
            $wanted = array_key_exists('reminder', $input) ? !empty($input['reminder']) : isset($todo->VALARM);
            foreach ($todo->select('VALARM') as $alarm) {
                $todo->remove($alarm);
            }
            if ($wanted && isset($todo->DUE)) {
                // At the due time, or at 9:00 on the due day.
                $at = $todo->DUE->hasTime()
                    ? $todo->DUE->getDateTime()
                    : new DateTimeImmutable($todo->DUE->getDateTime($tz)->format('Y-m-d') . ' 09:00', $tz);
                $alarm = $todo->add('VALARM', ['ACTION' => 'DISPLAY', 'DESCRIPTION' => $todo->SUMMARY->getValue()]);
                $alarm->add('TRIGGER', $at->setTimezone(new DateTimeZone('UTC')), ['VALUE' => 'DATE-TIME']);
            }
        }
        if (array_key_exists('completed', $input)) {
            unset($todo->COMPLETED, $todo->{'PERCENT-COMPLETE'});
            if (!empty($input['completed'])) {
                $todo->STATUS = 'COMPLETED';
                $todo->COMPLETED = new DateTimeImmutable('now', new DateTimeZone('UTC'));
                $todo->{'PERCENT-COMPLETE'} = 100;
            } else {
                $todo->STATUS = 'NEEDS-ACTION';
            }
        }
        if (array_key_exists('mail', $input)) {
            unset($todo->{'X-POSTVAK-MAIL'});
            $mail = $input['mail'];
            if (is_array($mail) && isset($mail['account_id'], $mail['folder'], $mail['uid'])) {
                $todo->{'X-POSTVAK-MAIL'} = json_encode([
                    'account_id' => (int) $mail['account_id'],
                    'folder'     => mb_substr((string) $mail['folder'], 0, 300),
                    'uid'        => (int) $mail['uid'],
                    'subject'    => mb_substr((string) ($mail['subject'] ?? ''), 0, 300),
                    'from'       => mb_substr((string) ($mail['from'] ?? ''), 0, 300),
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            }
        }
        if (!isset($todo->CREATED)) {
            $todo->CREATED = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        }
        self::stamp($todo);
        $newId = $this->store($userId, $existing, $target, $vcal, (string) $todo->UID);
        $obj = $this->object($userId, $newId);
        $task = self::taskInfo($newId, (int) $obj['instance_id'], self::master(VObject\Reader::read((string) $obj['calendardata']), 'VTODO'), $tz);
        return $task + ['color' => $this->findCalendar($userId, (int) $obj['instance_id'])['color']];
    }

    public function deleteTask(int $userId, int $id): void
    {
        $obj = $this->object($userId, $id);
        $this->backend()->deleteCalendarObject([(int) $obj['calendarid'], (int) $obj['instance_id']], $obj['uri']);
    }

    // ---------------------------------------------------------------- helpers

    /** Write through sabre's backend (sync tokens, etag, first/last occurrence); moves between calendars. */
    private function store(int $userId, ?array $existing, array $target, VCalendar $vcal, string $uid): int
    {
        $data = $vcal->serialize();
        $targetCal = [(int) $target['calendarid'], (int) $target['id']];
        if ($existing && (int) $existing['calendarid'] === (int) $target['calendarid']) {
            $this->backend()->updateCalendarObject($targetCal, $existing['uri'], $data);
            return (int) $existing['id'];
        }
        $uri = $existing['uri'] ?? (preg_replace('/[^A-Za-z0-9._-]/', '', $uid) ?: self::uuid()) . '.ics';
        $this->backend()->createCalendarObject($targetCal, $uri, $data);
        if ($existing) {
            $this->backend()->deleteCalendarObject([(int) $existing['calendarid'], (int) $existing['instance_id']], $existing['uri']);
        }
        return (int) Database::one('SELECT id FROM dav_calendarobjects WHERE calendarid = ? AND uri = ?', [$target['calendarid'], $uri])['id'];
    }

    /** An event of this user with this UID, in any of the user's own (writable) calendars, or null. */
    public function findByUid(int $userId, string $uid): ?array
    {
        return Database::one(
            "SELECT o.id, o.uri, o.calendarid, o.calendardata, i.id AS instance_id, i.access FROM dav_calendarobjects o
             JOIN dav_calendarinstances i ON i.calendarid = o.calendarid AND i.principaluri = ?
             WHERE o.uid = ? AND i.access IN (1, 3) ORDER BY o.id LIMIT 1",
            [self::principal($userId), $uid]
        );
    }

    /**
     * Store a whole iCalendar object (from an invitation) as is: replaces the existing object with the same
     * UID, otherwise goes into $calendarId or the first calendar. Returns the object id.
     */
    public function putObject(int $userId, VCalendar $vcal, string $uid, ?int $calendarId = null): int
    {
        $existing = $this->findByUid($userId, $uid);
        $target = $this->instance($userId, $calendarId ?? ($existing ? (int) $existing['instance_id'] : $this->firstOfType($userId, 'events')));
        return $this->store($userId, $existing, $target, $vcal, $uid);
    }

    public function deleteByUid(int $userId, string $uid): bool
    {
        $existing = $this->findByUid($userId, $uid);
        if ($existing === null) {
            return false;
        }
        $this->backend()->deleteCalendarObject([(int) $existing['calendarid'], (int) $existing['instance_id']], $existing['uri']);
        return true;
    }

    /** A calendar object of this user (by its id). */
    private function object(int $userId, int $id): array
    {
        $row = Database::one(
            'SELECT o.id, o.uri, o.calendarid, o.calendardata, i.id AS instance_id, i.access FROM dav_calendarobjects o
             JOIN dav_calendarinstances i ON i.calendarid = o.calendarid AND i.principaluri = ?
             WHERE o.id = ?',
            [self::principal($userId), $id]
        );
        if ($row === null) {
            throw HttpException::notFound('Not found');
        }
        return $row;
    }

    private function firstOfType(int $userId, string $type): int
    {
        foreach ($this->calendars($userId) as $c) {
            if (($c['type'] === $type || $c['type'] === 'both') && $c['writable']) {
                return $c['id'];
            }
        }
        throw HttpException::badRequest('No calendar available');
    }

    /** The main component (not a moved occurrence). */
    public static function master(VCalendar $vcal, string $name): ?VObject\Component
    {
        foreach ($vcal->select($name) as $c) {
            if (!isset($c->{'RECURRENCE-ID'})) {
                return $c;
            }
        }
        return null;
    }

    /** Editor fields of the series: local start/end, timezone, recurrence, reminder. */
    private static function masterInfo(VObject\Component $ev, DateTimeZone $tz): array
    {
        $allDay = !$ev->DTSTART->hasTime();
        $eventTz = $allDay ? $tz : ($ev->DTSTART->isFloating() ? $tz : $ev->DTSTART->getDateTime()->getTimezone());
        $start = $ev->DTSTART->getDateTime($eventTz);
        $end = self::endOf($ev, $eventTz);
        if ($allDay) {
            $endShown = $end->modify('-1 day'); // DTEND of an all-day event is exclusive
            if ($endShown < $start) {
                $endShown = $start;
            }
        }
        $recurrence = null;
        if (isset($ev->RRULE)) {
            $parts = $ev->RRULE->getParts();
            $until = null;
            if (isset($parts['UNTIL'])) {
                try {
                    $until = VObject\DateTimeParser::parse((string) $parts['UNTIL'], $eventTz)->setTimezone($eventTz)->format('Y-m-d');
                } catch (Throwable) {
                }
            }
            // Rules made elsewhere (e.g. "every Monday and Thursday") are kept as they are unless changed here.
            $simple = !array_diff(array_keys($parts), ['FREQ', 'INTERVAL', 'UNTIL', 'COUNT', 'WKST']);
            $recurrence = [
                'rrule'    => (string) $ev->RRULE,
                'freq'     => (string) ($parts['FREQ'] ?? ''),
                'interval' => (int) ($parts['INTERVAL'] ?? 1),
                'until'    => $until,
                'count'    => isset($parts['COUNT']) ? (int) $parts['COUNT'] : null,
                'custom'   => !$simple,
            ];
        }
        $reminder = null;
        foreach ($ev->select('VALARM') as $alarm) {
            $trigger = $alarm->TRIGGER ?? null;
            if ($trigger === null || strtoupper((string) ($trigger['VALUE'] ?? 'DURATION')) !== 'DURATION'
                || strtoupper((string) ($trigger['RELATED'] ?? 'START')) !== 'START') {
                continue;
            }
            try {
                $interval = VObject\DateTimeParser::parseDuration((string) $trigger);
                $reminder = -self::intervalMinutes($interval);
                break;
            } catch (Throwable) {
            }
        }
        return [
            'all_day'    => $allDay,
            'start'      => $allDay ? $start->format('Y-m-d') : $start->format('Y-m-d\TH:i'),
            'end'        => $allDay ? $endShown->format('Y-m-d') : $end->format('Y-m-d\TH:i'),
            'timezone'   => $eventTz->getName(),
            'recurrence' => $recurrence,
            'reminder'   => $reminder,
        ];
    }

    private static function endOf(VObject\Component $ev, DateTimeZone $tz): DateTimeImmutable
    {
        $start = $ev->DTSTART->getDateTime($tz);
        if (isset($ev->DTEND)) {
            return $ev->DTEND->getDateTime($tz);
        }
        if (isset($ev->DURATION)) {
            return $start->add(VObject\DateTimeParser::parseDuration((string) $ev->DURATION));
        }
        return $ev->DTSTART->hasTime() ? $start : $start->modify('+1 day');
    }

    /** @return array{DateTimeImmutable, DateTimeImmutable} start and (exclusive for all-day) end */
    private static function parseRange(string $start, string $end, bool $allDay, DateTimeZone $tz): array
    {
        $pattern = $allDay ? '/^\d{4}-\d{2}-\d{2}$/' : '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/';
        if (!preg_match($pattern, $start)) {
            throw HttpException::badRequest('Invalid start');
        }
        $s = new DateTimeImmutable($start, $tz);
        $e = preg_match($pattern, $end) ? new DateTimeImmutable($end, $tz) : $s;
        if ($allDay) {
            if ($e < $s) {
                $e = $s;
            }
            return [$s, $e->modify('+1 day')];
        }
        if ($e < $s) {
            $e = $s->modify('+1 hour');
        }
        return [$s, $e];
    }

    private static function buildRule(mixed $r, bool $allDay, DateTimeZone $tz): ?string
    {
        if (!is_array($r) || empty($r['freq'])) {
            return null;
        }
        $freq = strtoupper((string) $r['freq']);
        if (!in_array($freq, ['DAILY', 'WEEKLY', 'MONTHLY', 'YEARLY'], true)) {
            throw HttpException::badRequest('Invalid recurrence');
        }
        $parts = ["FREQ=$freq"];
        $interval = max(1, min(99, (int) ($r['interval'] ?? 1)));
        if ($interval > 1) {
            $parts[] = "INTERVAL=$interval";
        }
        if (!empty($r['until']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $r['until'])) {
            $parts[] = 'UNTIL=' . ($allDay
                ? str_replace('-', '', (string) $r['until'])
                : (new DateTimeImmutable($r['until'] . ' 23:59:59', $tz))->setTimezone(new DateTimeZone('UTC'))->format('Ymd\THis\Z'));
        } elseif (!empty($r['count'])) {
            $parts[] = 'COUNT=' . max(1, min(999, (int) $r['count']));
        }
        return implode(';', $parts);
    }

    /**
     * VTIMEZONE for a TZID: some calendar apps (Outlook, older Android) need the definition next to
     * the name. Built from PHP's own time zone data, one observance per change, for a range of years.
     */
    public static function addTimezone(VCalendar $vcal, DateTimeZone $tz, int $year): void
    {
        $name = $tz->getName();
        if ($name === 'UTC' || $name === 'Z') {
            return;
        }
        foreach ($vcal->select('VTIMEZONE') as $existing) {
            if ((string) $existing->TZID === $name) {
                $vcal->remove($existing);
            }
        }
        $from = (new DateTimeImmutable(($year - 1) . '-01-01', new DateTimeZone('UTC')))->getTimestamp();
        $to = (new DateTimeImmutable(($year + 10) . '-01-01', new DateTimeZone('UTC')))->getTimestamp();
        $transitions = $tz->getTransitions($from, $to) ?: [];
        $vtz = $vcal->createComponent('VTIMEZONE');
        $vtz->TZID = $name;
        $offset = static function (int $seconds): string {
            $sign = $seconds < 0 ? '-' : '+';
            $seconds = abs($seconds);
            return sprintf('%s%02d%02d', $sign, intdiv($seconds, 3600), intdiv($seconds % 3600, 60));
        };
        $previous = $transitions[0]['offset'] ?? 0;
        foreach ($transitions as $i => $t) {
            if ($i === 0 && count($transitions) > 1) {
                continue; // the first entry is the state at $from, not a change
            }
            $local = (new DateTimeImmutable('@' . $t['ts']))->setTimezone(new DateTimeZone('UTC'))->modify(($previous >= 0 ? '+' : '') . $previous . ' seconds');
            $obs = $vtz->add($vcal->createComponent($t['isdst'] ? 'DAYLIGHT' : 'STANDARD'));
            $obs->DTSTART = $local->format('Ymd\THis');
            $obs->TZOFFSETFROM = $offset($previous);
            $obs->TZOFFSETTO = $offset((int) $t['offset']);
            if (!empty($t['abbr'])) {
                $obs->TZNAME = $t['abbr'];
            }
            $previous = (int) $t['offset'];
        }
        if (count($vtz->children()) <= 1) {
            // No changes in that period (e.g. no daylight saving time): one fixed observance.
            $obs = $vtz->add($vcal->createComponent('STANDARD'));
            $obs->DTSTART = '19700101T000000';
            $obs->TZOFFSETFROM = $offset($previous);
            $obs->TZOFFSETTO = $offset($previous);
        }
        $vcal->add($vtz);
    }

    private static function setText(VObject\Component $c, string $name, mixed $value): void
    {
        unset($c->$name);
        $value = trim((string) ($value ?? ''));
        if ($value !== '') {
            $c->$name = mb_substr($value, 0, 10000);
        }
    }

    private static function stamp(VObject\Component $c): void
    {
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $c->DTSTAMP = $now;
        $c->{'LAST-MODIFIED'} = $now;
    }

    public static function minutesToDuration(int $minutes): string
    {
        $sign = $minutes < 0 ? '-' : '';
        $m = abs($minutes);
        if ($m === 0) {
            return 'PT0S';
        }
        if ($m % 1440 === 0) {
            return $sign . 'P' . ($m / 1440) . 'D';
        }
        return $sign . 'PT' . ($m >= 60 ? intdiv($m, 60) . 'H' : '') . ($m % 60 ? ($m % 60) . 'M' : '');
    }

    public static function intervalMinutes(DateInterval $i): int
    {
        $m = $i->d * 1440 + $i->h * 60 + $i->i + intdiv($i->s, 60);
        return $i->invert ? -$m : $m;
    }

    public static function tz(string $name): DateTimeZone
    {
        try {
            return new DateTimeZone($name !== '' ? $name : 'UTC');
        } catch (Throwable) {
            throw HttpException::badRequest('Unknown time zone');
        }
    }

    private static function uuid(): string
    {
        $b = random_bytes(16);
        $b[6] = chr((ord($b[6]) & 0x0f) | 0x40);
        $b[8] = chr((ord($b[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($b), 4));
    }

    private static function cleanName(string $name): string
    {
        $name = trim($name);
        if ($name === '') {
            throw HttpException::badRequest('Name is required');
        }
        return mb_substr($name, 0, 100);
    }

    private static function color(string $color): string
    {
        return preg_match('/^#[0-9A-Fa-f]{6}$/', $color) ? strtoupper($color) : self::COLORS[0];
    }

    public static function language(int $userId): string
    {
        try {
            $setting = (new SettingsRepository())->get($userId)['language'] ?? 'auto';
            if ($setting !== 'auto') {
                return $setting;
            }
        } catch (Throwable) {
        }
        return str_starts_with(strtolower((string) ($_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? 'nl')), 'nl') ? 'nl' : 'en';
    }
}
