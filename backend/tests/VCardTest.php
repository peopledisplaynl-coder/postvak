<?php
use Postvak\Contacts\VCard;

test('vCard 3.0 with folding, grouped EMAIL and preferred mobile number', function () {
    $v = "BEGIN:VCARD\r\nVERSION:3.0\r\nFN:Jan de Vries\r\nitem1.EMAIL;TYPE=INTERNET:Jan@Example.nl\r\nEMAIL;TYPE=WORK:jan@werk.nl\r\nTEL;TYPE=HOME:013 123\r\nTEL;TYPE=CELL,pref:06 1234 5678\r\nNOTE:een\\, twee\\nlange regel die\r\n  gevouwen is\r\nEND:VCARD\r\n";
    $cards = VCard::parse($v);
    assertSame(1, count($cards));
    assertSame('Jan de Vries', $cards[0]['name']);
    assertSame(['jan@example.nl', 'jan@werk.nl'], $cards[0]['emails']);
    assertSame('06 1234 5678', $cards[0]['phone']);
    assertSame("een, twee\nlange regel die gevouwen is", $cards[0]['notes']);
});

test('vCard 2.1 quoted-printable, N field and soft line breaks', function () {
    $v = "BEGIN:VCARD\nVERSION:2.1\nN;CHARSET=UTF-8;ENCODING=QUOTED-PRINTABLE:M=C3=BCller;J=C3=BCrgen;;;\nEMAIL;HOME:juergen@x.de\nNOTE;ENCODING=QUOTED-PRINTABLE:Eerste=0D=0Atweede regel die=\n doorloopt\nEND:VCARD\n";
    $card = VCard::parse($v)[0];
    assertSame('Jürgen Müller', $card['name']);
    assertSame("Eerste\ntweede regel die doorloopt", $card['notes']);
});

test('Windows-1252 file is converted; cards without email are kept with an empty list', function () {
    $v = mb_convert_encoding("BEGIN:VCARD\nFN:Café René\nEMAIL:rene@cafe.nl\nEND:VCARD\nBEGIN:VCARD\nFN:Geen mail\nTEL:123\nEND:VCARD\n", 'Windows-1252', 'UTF-8');
    $cards = VCard::parse($v);
    assertSame('Café René', $cards[0]['name']);
    assertSame([], $cards[1]['emails']);
});

test('write then parse gives the same contact back', function () {
    $contact = ['name' => 'Ömer Çelik', 'email' => 'omer@celik.nl', 'phone' => '+31 6 1', 'notes' => "Puntkomma; komma, en\nnieuwe regel " . str_repeat('ééé ', 30)];
    $out = VCard::write([$contact]);
    foreach (explode("\r\n", $out) as $line) {
        assertTrue(strlen($line) <= 75, 'line longer than 75 octets');
    }
    $back = VCard::parse($out)[0];
    assertSame($contact['name'], $back['name']);
    assertSame([$contact['email']], $back['emails']);
    assertSame(trim($contact['notes']), $back['notes']);
});
