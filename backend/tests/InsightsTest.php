<?php
use Postvak\Mail\MessageInsights as MI;
use Postvak\Mail\MutedThreads;

test('unsubscribe: one-click, mailto and plain link', function () {
    $raw = "From: News <news@shop.example>\r\nList-Unsubscribe: <mailto:unsub@shop.example?subject=stop>,\r\n <https://shop.example/u/abc>\r\nList-Unsubscribe-Post: List-Unsubscribe=One-Click\r\n";
    assertSame(['one_click' => true, 'url' => 'https://shop.example/u/abc', 'mailto' => 'mailto:unsub@shop.example?subject=stop'], MI::unsubscribe($raw));
    assertSame(['unsub@shop.example', 'stop', 'unsubscribe'], MI::parseMailto('mailto:unsub@shop.example?subject=stop'));
    assertSame(false, MI::unsubscribe("List-Unsubscribe: <http://insecure.example/u>\r\n") !== null && MI::unsubscribe("List-Unsubscribe: <http://insecure.example/u>\r\n")['url'] !== null);
    assertSame(null, MI::unsubscribe("Subject: hoi\r\n"));
});

test('authenticity: failed DMARC, unverified sender, a name showing another address', function () {
    $ok = "Authentication-Results: mx.labee.eu; spf=pass smtp.mailfrom=ing.nl; dkim=pass header.d=ing.nl; dmarc=pass\r\n";
    assertSame(null, MI::authenticity($ok, 'info@ing.nl', 'ING')['level']);
    $fake = "Authentication-Results: mx.labee.eu; spf=fail smtp.mailfrom=ing.nl; dkim=none; dmarc=fail (p=reject)\r\n";
    $a = MI::authenticity($fake, 'info@ing.nl', 'ING');
    assertSame('danger', $a['level']);
    assertSame(['dmarc_fail'], $a['reasons']);
    $name = MI::authenticity('', 'x123@gmail.com', 'klantenservice@ing.nl');
    assertSame('caution', $name['level']);
    assertSame(['name_shows_other_address'], $name['reasons']);
    // The topmost result counts (added by our own server).
    $two = "Authentication-Results: mx.labee.eu; dmarc=pass\r\nAuthentication-Results: evil.example; dmarc=fail\r\n";
    assertSame(null, MI::authenticity($two, 'a@b.nl', 'A')['level']);
});

test('inbox tabs: social, promotions, updates, primary', function () {
    assertSame('social', MI::category('', 'notification@facebookmail.com'));
    assertSame('promotions', MI::category("List-Unsubscribe: <https://x.example/u>\r\n", 'news@shop.example'));
    assertSame('updates', MI::category('', 'noreply@bank.example'));
    assertSame('primary', MI::category('', 'marieke@voorbeeld.nl'));
    $c = MI::searchCriteria('primary');
    assertSame('NOT', $c[0]);
    assertTrue(str_contains(implode(' ', $c), 'List-Unsubscribe'));
});

test('inbox tabs: switched-off tabs go to Primary, moved senders win', function () {
    $tabs = ['enabled' => ['promotions', 'updates'], 'senders' => ['news@shop.example' => 'primary', 'jan@voorbeeld.nl' => 'updates']];
    assertSame('primary', MI::category('', 'notification@facebookmail.com', $tabs)); // Social is off
    assertSame('primary', MI::category("List-Unsubscribe: <https://x.example/u>\r\n", 'news@shop.example', $tabs));
    assertSame('updates', MI::category('', 'Jan@Voorbeeld.nl', $tabs));
    // A sender moved to a tab that is off ends up in Primary too.
    assertSame('primary', MI::category('', 'x@y.nl', ['enabled' => ['updates'], 'senders' => ['x@y.nl' => 'social']]));
    // Search: Primary = moved there OR (not automatically elsewhere AND not moved), as one OR of two groups.
    $p = MI::searchCriteria('primary', $tabs);
    assertSame('OR', $p[0]);
    assertSame('(FROM "news@shop.example")', $p[1]);
    assertTrue(str_starts_with($p[2], '(NOT (') && str_contains($p[2], 'NOT (OR FROM "news@shop.example" FROM "jan@voorbeeld.nl")'));
    // Social is off: Primary is not "NOT social" (social mail stays in Primary), but Promotions still leaves social mail out.
    assertTrue(!str_contains($p[2], 'NOT (OR FROM "facebookmail.com"'));
    // A tab that is off is searched as Primary.
    assertSame($p, MI::searchCriteria('social', $tabs));
    $u = MI::searchCriteria('updates', $tabs);
    assertSame(['OR', '(FROM "jan@voorbeeld.nl")'], array_slice($u, 0, 2));
});

test('muted threads: ids from References, In-Reply-To and Message-ID', function () {
    $raw = "Message-ID: <c@x>\r\nIn-Reply-To: <b@x>\r\nReferences: <a@x>\r\n <b@x>\r\n";
    assertSame(['<a@x>', '<b@x>', '<c@x>'], MutedThreads::ids($raw));
});

test('bodystructure: attachments from a raw FETCH response', function () {
    $lines = [
        "* 116 FETCH (UID 129 BODYSTRUCTURE (((\"text\" \"plain\" (\"charset\" \"utf-8\") NIL NIL \"base64\" 78 1 NIL NIL NIL NIL)(\"text\" \"html\" (\"charset\" \"utf-8\") NIL NIL \"base64\" 34 1 NIL NIL NIL NIL) \"alternative\" (\"boundary\" \"b2\") NIL NIL NIL)(\"application\" \"pdf\" (\"name\" \"programma.pdf\") NIL NIL \"base64\" 400 NIL (\"attachment\" (\"filename\" \"programma.pdf\")) NIL NIL) \"mixed\" (\"boundary\" \"b1\") NIL NIL NIL))\r\n",
        // An image used inside the HTML (Content-ID, inline) is not an attachment; RFC 2231 and literal names are read.
        "* 117 FETCH (UID 130 BODYSTRUCTURE ((\"image\" \"png\" (\"name\" \"logo.png\") \"<logo@x>\" NIL \"base64\" 100 NIL (\"inline\" NIL) NIL NIL)(\"application\" \"pdf\" NIL NIL NIL \"base64\" 40 NIL (\"attachment\" (\"filename*\" \"utf-8''Offerte%20%E2%82%AC.pdf\")) NIL NIL)(\"text\" \"csv\" (\"name\" {9}\r\n",
        "lijst.csv) NIL NIL \"7bit\" 12 1 NIL (\"attachment\" NIL) NIL NIL) \"mixed\" (\"boundary\" \"b\") NIL NIL NIL))\r\n",
        "OK Fetch completed\r\n",
    ];
    $all = Postvak\Mail\BodyStructure::parseFetch($lines);
    assertSame([129, 130], array_keys($all));
    assertSame([['name' => 'programma.pdf', 'mime' => 'application/pdf', 'size' => 300]], Postvak\Mail\BodyStructure::attachments($all[129]));
    $names = array_column(Postvak\Mail\BodyStructure::attachments($all[130]), 'name');
    assertSame(['Offerte €.pdf', 'lijst.csv'], $names);
});
