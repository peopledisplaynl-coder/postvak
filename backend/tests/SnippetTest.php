<?php
use Postvak\Mail\SnippetExtractor;

test('plain text, quoted lines left out', function () {
    $s = SnippetExtractor::extract("Content-Type: text/plain; charset=utf-8\r\n", "Prima!\r\n\r\nOp zaterdag schreef Oma:\r\n> Hoi Ton\r\n");
    assertSame('Prima!', $s);
});

test('multipart/alternative prefers text/plain; base64 decoded', function () {
    $body = "--x\r\nContent-Type: text/plain; charset=utf-8\r\nContent-Transfer-Encoding: base64\r\n\r\n" . base64_encode('Factuur van oktober: € 42,50') . "\r\n--x\r\nContent-Type: text/html\r\n\r\n<p>html</p>\r\n--x--\r\n";
    assertSame('Factuur van oktober: € 42,50', SnippetExtractor::extract("Content-Type: multipart/alternative; boundary=\"x\"\r\n", $body));
});

test('HTML: style removed, entities decoded, blockquote dropped', function () {
    $html = '<html><head><style>p{color:red}</style></head><body><p>Hallo &amp; welkom</p><blockquote>oud bericht</blockquote></body></html>';
    assertSame('Hallo & welkom', SnippetExtractor::extract("Content-Type: text/html; charset=utf-8\r\n", $html));
});

test('quoted-printable and Latin-1, clipped at 160 characters', function () {
    $s = SnippetExtractor::extract("Content-Type: text/plain; charset=iso-8859-1\r\nContent-Transfer-Encoding: quoted-printable\r\n", 'Caf=E9 De Kroon ' . str_repeat('lange tekst ', 30));
    assertTrue(str_starts_with($s, 'Café De Kroon'));
    assertTrue(mb_strlen($s) <= 161);
    assertTrue(str_ends_with($s, '…'));
});
