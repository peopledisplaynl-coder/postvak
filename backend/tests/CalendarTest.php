<?php
use Postvak\Calendar\CalendarService;
use Sabre\VObject;

test('reminder minutes become iCalendar durations and back', function () {
    assertSame('-PT15M', CalendarService::minutesToDuration(-15));
    assertSame('-PT1H30M', CalendarService::minutesToDuration(-90));
    assertSame('-P1D', CalendarService::minutesToDuration(-1440));
    assertSame('PT9H', CalendarService::minutesToDuration(540));
    assertSame('PT0S', CalendarService::minutesToDuration(0));
    foreach ([-15, -90, -1440, -2880, 540, 0] as $m) {
        $interval = VObject\DateTimeParser::parseDuration(CalendarService::minutesToDuration($m));
        assertSame($m, CalendarService::intervalMinutes($interval), "round trip $m");
    }
});

test('VTIMEZONE matches PHP time zone data and validates', function () {
    $vcal = new VObject\Component\VCalendar(['PRODID' => '-//test//EN']);
    $tz = new DateTimeZone('Europe/Amsterdam');
    $vcal->add('VEVENT', ['UID' => 'x', 'DTSTART' => new DateTimeImmutable('2026-07-01 10:00', $tz), 'SUMMARY' => 'Test']);
    CalendarService::addTimezone($vcal, $tz, 2026);
    $text = $vcal->serialize();
    assertContains('TZID:Europe/Amsterdam', $text);
    assertContains("DTSTART:20261025T030000\r\nTZOFFSETFROM:+0200\r\nTZOFFSETTO:+0100", $text, 'end of summer time 2026');
    $again = VObject\Reader::read($text);
    $errors = array_filter($again->validate(), static fn ($w) => $w['level'] >= 3);
    assertSame([], array_values($errors));
    // Adding it twice keeps one definition.
    CalendarService::addTimezone($again, $tz, 2026);
    assertSame(1, count($again->select('VTIMEZONE')));
});

test('time zones without daylight saving get one fixed observance', function () {
    $vcal = new VObject\Component\VCalendar();
    CalendarService::addTimezone($vcal, new DateTimeZone('Asia/Kolkata'), 2026);
    assertContains('TZOFFSETTO:+0530', $vcal->serialize());
    $vcal2 = new VObject\Component\VCalendar();
    CalendarService::addTimezone($vcal2, new DateTimeZone('UTC'), 2026);
    assertSame(0, count($vcal2->select('VTIMEZONE')));
});

test('unknown time zones are refused', function () {
    assertThrows(fn () => CalendarService::tz('Mars/Olympus'));
    assertSame('Europe/Amsterdam', CalendarService::tz('Europe/Amsterdam')->getName());
});
