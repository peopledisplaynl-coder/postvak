<?php
declare(strict_types=1);

namespace Postvak\Calendar;

use DateTimeZone;
use Postvak\Database;
use Postvak\Http\HttpException;
use Postvak\Mail\SmtpService;
use Sabre\VObject;
use Throwable;
use Webklex\PHPIMAP\Message;

/**
 * Meeting invitations in mail (iMIP, RFC 6047): an invitation (METHOD:REQUEST) shows as a card with
 * accept / maybe / decline; the answer goes into the calendar and, when asked, back to the organizer
 * (METHOD:REPLY). A cancellation (METHOD:CANCEL) can remove the event again.
 */
final class InvitationService
{
    public const RESPONSES = ['accepted' => 'ACCEPTED', 'tentative' => 'TENTATIVE', 'declined' => 'DECLINED'];

    public function __construct(private readonly CalendarService $calendar = new CalendarService()) {}

    /** The iCalendar part of a message, if it is an invitation, update or cancellation. */
    public static function find(Message $m): ?array
    {
        $index = 0;
        foreach ($m->getAttachments() as $att) {
            $i = $index++;
            $mime = strtolower((string) $att->getMimeType());
            $name = strtolower((string) $att->name);
            if (!in_array($mime, ['text/calendar', 'application/ics', 'text/x-vcalendar'], true) && !str_ends_with($name, '.ics')) {
                continue;
            }
            if (($att->size ?? 0) > 1_000_000) {
                continue;
            }
            try {
                $vcal = VObject\Reader::read((string) $att->getContent(), VObject\Reader::OPTION_FORGIVING);
            } catch (Throwable) {
                continue;
            }
            if (!$vcal instanceof VObject\Component\VCalendar) {
                continue;
            }
            $method = strtoupper((string) ($vcal->METHOD ?? ''));
            if (!in_array($method, ['REQUEST', 'CANCEL', 'PUBLISH'], true) || CalendarService::master($vcal, 'VEVENT') === null) {
                continue;
            }
            return ['index' => $i, 'vcal' => $vcal, 'method' => $method];
        }
        return null;
    }

    /** What the reader card shows. */
    public function describe(int $userId, array $found, DateTimeZone $tz): array
    {
        /** @var VObject\Component\VCalendar $vcal */
        $vcal = $found['vcal'];
        $ev = CalendarService::master($vcal, 'VEVENT');
        $uid = (string) ($ev->UID ?? '');
        $me = $this->myAddresses($userId);
        $allDay = !$ev->DTSTART->hasTime();
        $start = $ev->DTSTART->getDateTime($tz);
        $end = isset($ev->DTEND) ? $ev->DTEND->getDateTime($tz) : (isset($ev->DURATION) ? $start->add($ev->DURATION->getDateInterval()) : $start);

        $myStatus = null;
        foreach ($ev->select('ATTENDEE') as $a) {
            if (in_array(self::mailOf((string) $a->getValue()), $me, true)) {
                $myStatus = strtolower((string) ($a['PARTSTAT'] ?? ''));
            }
        }
        $existing = $uid !== '' ? $this->calendar->findByUid($userId, $uid) : null;
        $calendarStatus = null;
        if ($existing) {
            try {
                $stored = CalendarService::master(VObject\Reader::read((string) $existing['calendardata']), 'VEVENT');
                foreach ($stored?->select('ATTENDEE') ?? [] as $a) {
                    if (in_array(self::mailOf((string) $a->getValue()), $me, true)) {
                        $calendarStatus = strtolower((string) ($a['PARTSTAT'] ?? '')) ?: 'accepted';
                    }
                }
                $calendarStatus ??= 'accepted';
            } catch (Throwable) {
            }
        }
        $organizer = isset($ev->ORGANIZER) ? ['name' => (string) ($ev->ORGANIZER['CN'] ?? ''), 'email' => self::mailOf((string) $ev->ORGANIZER->getValue())] : null;

        return [
            'method'      => strtolower($found['method']),
            'uid'         => $uid,
            'title'       => (string) ($ev->SUMMARY ?? ''),
            'location'    => (string) ($ev->LOCATION ?? ''),
            'description' => mb_substr((string) ($ev->DESCRIPTION ?? ''), 0, 2000),
            'all_day'     => $allDay,
            'start'       => $allDay ? $start->format('Y-m-d') : $start->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z'),
            // All-day: DTEND is the day after the last day.
            'end'         => $allDay ? ($end > $start ? $end->modify('-1 day') : $start)->format('Y-m-d') : $end->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z'),
            'recurring'   => isset($ev->RRULE),
            'organizer'   => $organizer,
            'attendees'   => count($ev->select('ATTENDEE')),
            // Organised by me (e.g. my own invitation in Sent): no answer buttons.
            'own'         => $organizer !== null && in_array($organizer['email'], $me, true),
            'my_status'   => $myStatus,
            'in_calendar' => $existing !== null,
            'calendar_status' => $calendarStatus,
            'part'        => $found['index'],
        ];
    }

    /**
     * Answer an invitation: put it in the calendar (or take it out when declined / cancelled) and,
     * when $reply is true, send the answer to the organizer from $account.
     * @param 'accepted'|'tentative'|'declined'|'remove' $response
     */
    public function respond(int $userId, array $account, array $found, string $response, bool $reply, ?int $calendarId = null): array
    {
        /** @var VObject\Component\VCalendar $vcal */
        $vcal = clone $found['vcal'];
        $ev = CalendarService::master($vcal, 'VEVENT') ?? throw HttpException::badRequest('No event in this invitation');
        $uid = trim((string) ($ev->UID ?? ''));
        if ($uid === '') {
            throw HttpException::badRequest('This invitation has no UID');
        }

        if ($response === 'remove' || $found['method'] === 'CANCEL') {
            $this->calendar->deleteByUid($userId, $uid);
            return ['in_calendar' => false, 'calendar_status' => null, 'replied' => false];
        }
        $partstat = self::RESPONSES[$response] ?? throw HttpException::badRequest('Unknown answer');

        // Which of my addresses was invited (otherwise the address of the account answering).
        $me = $this->myAddresses($userId);
        $mine = null;
        foreach ($vcal->select('VEVENT') as $component) {
            foreach ($component->select('ATTENDEE') as $a) {
                if (in_array(self::mailOf((string) $a->getValue()), $me, true)) {
                    $a['PARTSTAT'] = $partstat;
                    unset($a['RSVP']);
                    $mine ??= $a;
                }
            }
        }
        if ($mine === null) {
            $mine = $ev->add('ATTENDEE', 'mailto:' . $account['email'], ['PARTSTAT' => $partstat, 'CN' => (string) ($account['display_name'] ?? '')]);
        }

        if ($partstat === 'DECLINED') {
            $this->calendar->deleteByUid($userId, $uid);
            $inCalendar = false;
        } else {
            $store = clone $vcal;
            unset($store->METHOD);
            $this->calendar->putObject($userId, $store, $uid, $calendarId);
            $inCalendar = true;
        }

        $replied = false;
        $replyError = null;
        $organizer = isset($ev->ORGANIZER) ? self::mailOf((string) $ev->ORGANIZER->getValue()) : '';
        if ($reply && $organizer !== '' && !in_array($organizer, $me, true)) {
            try {
                $this->sendReply($account, $ev, $mine, $partstat, $organizer, (string) ($ev->ORGANIZER['CN'] ?? ''), CalendarService::language($userId));
                $replied = true;
            } catch (HttpException $e) {
                // The calendar is already up to date; only the answer to the organizer failed.
                $replyError = $e->getMessage();
            }
        }
        return ['in_calendar' => $inCalendar, 'calendar_status' => strtolower($partstat), 'replied' => $replied, 'reply_error' => $replyError];
    }

    private function sendReply(array $account, VObject\Component $ev, VObject\Property $me, string $partstat, string $to, string $toName, string $lang): void
    {
        $reply = new VObject\Component\VCalendar(['PRODID' => '-//Postvak//Postvak//EN', 'METHOD' => 'REPLY']);
        $r = $reply->add('VEVENT', [
            'UID'      => (string) $ev->UID,
            'DTSTAMP'  => gmdate('Ymd\THis\Z'),
            'SEQUENCE' => (string) ($ev->SEQUENCE ?? '0'),
            'SUMMARY'  => (string) ($ev->SUMMARY ?? ''),
        ]);
        $r->add(clone $ev->DTSTART);
        if (isset($ev->DTEND)) {
            $r->add(clone $ev->DTEND);
        }
        if (isset($ev->{'RECURRENCE-ID'})) {
            $r->add(clone $ev->{'RECURRENCE-ID'});
        }
        $r->add(clone $ev->ORGANIZER);
        $attendee = clone $me;
        $attendee['PARTSTAT'] = $partstat;
        $r->add($attendee);
        // Keep the VTIMEZONEs the times refer to.
        foreach ($ev->parent->select('VTIMEZONE') as $tz) {
            $reply->add(clone $tz);
        }

        $nl = $lang === 'nl';
        $label = match ($partstat) {
            'ACCEPTED'  => $nl ? 'Geaccepteerd' : 'Accepted',
            'TENTATIVE' => $nl ? 'Voorlopig geaccepteerd' : 'Tentatively accepted',
            default     => $nl ? 'Afgewezen' : 'Declined',
        };
        $who = trim((string) ($account['display_name'] ?? '')) ?: $account['email'];
        $title = (string) ($ev->SUMMARY ?? '');
        $text = $nl ? "$who heeft deze uitnodiging beantwoord: " . strtolower($label) . '.' : "$who has answered this invitation: " . strtolower($label) . '.';
        SmtpService::send($account, [
            'to'      => [['email' => $to, 'name' => $toName]],
            'subject' => "$label: $title",
            'html'    => '<p>' . htmlspecialchars($text, ENT_QUOTES, 'UTF-8') . '</p>',
            'ical'    => $reply->serialize(),
        ]);
    }

    /** All addresses of the user's accounts, lower case. @return string[] */
    private function myAddresses(int $userId): array
    {
        $rows = Database::all('SELECT email FROM accounts WHERE user_id = ?', [$userId]);
        $login = Database::one('SELECT login_email FROM users WHERE id = ?', [$userId]);
        return array_values(array_unique(array_map('strtolower', [...array_column($rows, 'email'), ...($login ? [$login['login_email']] : [])])));
    }

    private static function mailOf(string $value): string
    {
        return strtolower(trim(preg_replace('/^mailto:/i', '', $value) ?? $value));
    }
}
