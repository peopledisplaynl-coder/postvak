<?php
use Postvak\Calendar\FeedService;
use Postvak\Contacts\ContactCards;
use Postvak\Http\Fetcher;
use Sabre\VObject;

test('webcal links become https', function () {
    assertSame('https://calendar.google.com/x/basic.ics', Fetcher::normalize(' webcal://calendar.google.com/x/basic.ics '));
    assertSame('https://a.example/b', Fetcher::normalize('webcals://a.example/b'));
});

test('feeds from private networks and other schemes are refused', function () {
    foreach (['http://127.0.0.1/cal.ics', 'http://10.0.0.5/cal.ics', 'http://[::1]/x', 'ftp://example.com/a.ics', 'file:///etc/passwd', 'https://user:pw@example.com/a.ics'] as $url) {
        assertThrows(fn () => Fetcher::check($url));
    }
});

test('a feed must be an iCalendar file', function () {
    assertThrows(fn () => FeedService::parse('<html>Sign in</html>'));
    $vcal = FeedService::parse("\xEF\xBB\xBFBEGIN:VCALENDAR\r\nVERSION:2.0\r\nX-WR-CALNAME:Werk\r\nBEGIN:VEVENT\r\nUID:a\r\nDTSTART:20261020T100000Z\r\nSUMMARY:Test\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n");
    assertSame('Werk', (string) $vcal->{'X-WR-CALNAME'});
});

test('card fields: name, first address, other addresses, phone, note', function () {
    $f = ContactCards::fields("BEGIN:VCARD\r\nVERSION:3.0\r\nN:Jansen;Peter;;;\r\nFN:Peter Jansen\r\nEMAIL;TYPE=HOME:Peter@Example.org\r\nEMAIL;TYPE=WORK:p@werk.example\r\nTEL;TYPE=CELL:06 1234\r\nNOTE:Bas\r\nEND:VCARD\r\n");
    assertSame('Peter Jansen', $f['name']);
    assertSame('peter@example.org', $f['email']);
    assertSame('p@werk.example', $f['extra_emails']);
    assertSame('06 1234', $f['phone']);
    assertSame('Bas', $f['notes']);
    $none = ContactCards::fields("BEGIN:VCARD\r\nVERSION:3.0\r\nFN:Loodgieter\r\nTEL:010 123\r\nEND:VCARD\r\n");
    assertSame(null, $none['email']);
});

test('editing in Postvak keeps what a phone stored in the card', function () {
    $card = VObject\Reader::read("BEGIN:VCARD\r\nVERSION:3.0\r\nUID:X1\r\nN:Jansen;Peter;;;\r\nFN:Peter Jansen\r\nEMAIL:peter@example.org\r\nEMAIL:p@werk.example\r\nTEL;TYPE=CELL:06 1\r\nTEL;TYPE=WORK:013 2\r\nBDAY:1980-05-04\r\nPHOTO;ENCODING=b;TYPE=JPEG:AAAA\r\nEND:VCARD\r\n");
    ContactCards::apply($card, ['id' => 1, 'name' => 'Peter Jansen', 'email' => 'peter@example.org', 'phone' => '06 9', 'notes' => 'Nieuw']);
    $out = $card->serialize();
    assertContains('BDAY:1980-05-04', $out);
    assertContains('PHOTO', $out);
    assertContains('TEL;TYPE=CELL:06 9', $out);
    assertContains('TEL;TYPE=WORK:013 2', $out);
    assertContains('EMAIL:p@werk.example', $out);
    assertContains('NOTE:Nieuw', $out);
    assertSame('X1', (string) $card->UID);
});

test('a new card from Postvak fields is valid vCard 3.0', function () {
    $data = ContactCards::build(['id' => 7, 'name' => "Ton l'Abee", 'email' => 'ton@labee.eu', 'phone' => '', 'notes' => '']);
    $card = VObject\Reader::read($data);
    assertSame('3.0', (string) $card->VERSION);
    assertSame("Ton l'Abee", (string) $card->FN);
    assertSame("l'Abee;Ton;;;", (string) $card->N);
    assertSame('postvak-contact-7', (string) $card->UID);
    assertSame([], $card->validate());
});
