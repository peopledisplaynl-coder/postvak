<?php
declare(strict_types=1);

namespace Postvak\Cron;

use DateTimeImmutable;
use DateTimeZone;
use Postvak\Database;
use Postvak\Dav\PrincipalBackend;
use Postvak\Push\PushService;
use Postvak\Repositories\AppSettingsRepository;
use Sabre\VObject;
use Throwable;

/**
 * Reminders (VALARM) of events and tasks as push notifications. Runs from cron every few minutes,
 * so a reminder is sent up to LOOKAHEAD seconds early rather than late. Works for items made on a
 * phone as well, as long as they carry an alarm.
 */
final class AlarmNotifier
{
    private const LOOKAHEAD = 240;
    private const MAX_LATE = 3600; // after a cron outage: skip reminders older than this

    public function __construct(
        private readonly PushService $push = new PushService(),
        private readonly AppSettingsRepository $app = new AppSettingsRepository(),
    ) {}

    /** @return array{users:int, pushed:int, errors:int} */
    public function run(callable $log, ?int $now = null): array
    {
        $now ??= time();
        $last = (int) ($this->app->get('alarm_last_check') ?? 0);
        $from = max($last, $now - self::MAX_LATE);
        $until = $now + self::LOOKAHEAD;
        $stats = ['users' => 0, 'pushed' => 0, 'errors' => 0];

        foreach ($this->push->usersWithSubscriptions() as $userId) {
            $stats['users']++;
            try {
                foreach ($this->due($userId, $from, $until) as $alarm) {
                    $fresh = Database::run(
                        'INSERT IGNORE INTO calendar_alarms_sent (object_id, occurrence, trigger_at) VALUES (?, ?, ?)',
                        [$alarm['object_id'], $alarm['occurrence'], $alarm['trigger_at']]
                    )->rowCount() > 0;
                    if (!$fresh) {
                        continue;
                    }
                    $this->push->sendToUser($userId, $alarm['message'], 'alarm-' . $alarm['object_id']);
                    $stats['pushed']++;
                }
            } catch (Throwable $e) {
                $stats['errors']++;
                $log("reminders for user $userId failed: " . $e->getMessage());
            }
        }
        $this->app->set('alarm_last_check', $until);
        if (random_int(1, 100) === 1) {
            Database::run('DELETE FROM calendar_alarms_sent WHERE sent_at < ?', [date('Y-m-d H:i:s', $now - 60 * 86400)]);
        }
        return $stats;
    }

    /**
     * Alarms of this user with a trigger time in ($from, $until].
     * @return array<int, array{object_id:int, occurrence:int, trigger_at:int, message:array}>
     */
    public function due(int $userId, int $from, int $until): array
    {
        $user = Database::one('SELECT login_email FROM users WHERE id = ?', [$userId]);
        if ($user === null) {
            return [];
        }
        // Alarms can be days before the event: look a week ahead.
        $rows = Database::all(
            "SELECT o.id, o.componenttype, o.calendardata FROM dav_calendarobjects o
             JOIN dav_calendarinstances i ON i.calendarid = o.calendarid AND i.principaluri = ?
             WHERE o.calendardata LIKE '%BEGIN:VALARM%'
               AND (o.componenttype = 'VTODO'
                    OR ((o.firstoccurence IS NULL OR o.firstoccurence <= ?) AND (o.lastoccurence IS NULL OR o.lastoccurence >= ?)))",
            [PrincipalBackend::uriFor($user['login_email']), $until + 8 * 86400, $from - 86400]
        );

        $utc = new DateTimeZone('UTC');
        $out = [];
        foreach ($rows as $row) {
            try {
                $vcal = VObject\Reader::read((string) $row['calendardata']);
                if ($row['componenttype'] === 'VEVENT') {
                    $vcal = $vcal->expand(
                        (new DateTimeImmutable('@' . ($from - 86400)))->setTimezone($utc),
                        (new DateTimeImmutable('@' . ($until + 8 * 86400)))->setTimezone($utc),
                        $utc
                    );
                }
                foreach ($vcal->getComponents() as $item) {
                    if (!in_array($item->name, ['VEVENT', 'VTODO'], true) || !isset($item->VALARM)) {
                        continue;
                    }
                    if ($item->name === 'VTODO' && (strtoupper((string) ($item->STATUS ?? '')) === 'COMPLETED' || isset($item->COMPLETED))) {
                        continue;
                    }
                    foreach ($item->select('VALARM') as $alarm) {
                        try {
                            $at = $alarm->getEffectiveTriggerTime()->getTimestamp();
                        } catch (Throwable) {
                            continue;
                        }
                        if ($at <= $from || $at > $until) {
                            continue;
                        }
                        $out[] = [
                            'object_id'  => (int) $row['id'],
                            'occurrence' => isset($item->{'RECURRENCE-ID'}) ? $item->{'RECURRENCE-ID'}->getDateTime()->getTimestamp() : 0,
                            'trigger_at' => $at,
                            'message'    => self::message($item),
                        ];
                    }
                }
            } catch (Throwable $e) {
                error_log('[postvak] alarms of object ' . $row['id'] . ' skipped: ' . $e->getMessage());
            }
        }
        return $out;
    }

    private static function message(VObject\Component $item): array
    {
        $isTask = $item->name === 'VTODO';
        $when = $isTask ? ($item->DUE ?? null) : ($item->DTSTART ?? null);
        $allDay = $when !== null && !$when->hasTime();
        $date = $when?->getDateTime();
        return [
            'type'     => 'reminder',
            'kind'     => $isTask ? 'task' : 'event',
            'title'    => (string) ($item->SUMMARY ?? ''),
            'location' => (string) ($item->LOCATION ?? ''),
            'all_day'  => $allDay,
            // Timed: an instant (the service worker shows it in the device's time zone). All-day: the date.
            'start'    => $date === null ? null : ($allDay ? $date->format('Y-m-d') : $date->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z')),
            'url'      => $isTask ? 'tasks' : 'calendar' . ($date ? '?date=' . $date->format('Y-m-d') : ''),
        ];
    }
}
