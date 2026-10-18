<?php
use Postvak\Mail\FolderResolver;
use Postvak\Mail\ImapService;
use Postvak\Mail\MessageSanitizer;
use Postvak\Mail\SmtpService;

test('THREAD response: each top-level group is one conversation', function () {
    assertSame([[1], [2, 3, 4, 5, 6], [7, 8, 9], [10]], ImapService::parseThreads(' (1)(2 3 (4 5)(6))((7)(8 9))(10)'));
    assertSame([], ImapService::parseThreads(''));
});

test('special folders are detected in English and Dutch, configured ones win', function () {
    $folders = array_map(static fn ($p) => ['path' => $p, 'name' => substr($p, strrpos($p, '.') !== false ? strrpos($p, '.') + 1 : 0), 'delimiter' => '.'], [
        'INBOX', 'INBOX.Verzonden', 'INBOX.Concepten', 'INBOX.Prullenbak', 'INBOX.Archief', 'INBOX.Spam', 'INBOX.Gepland', 'INBOX.Snoozed', 'INBOX.Projecten.Archief',
    ]);
    $roles = FolderResolver::resolve($folders, ['trash_folder' => 'INBOX.Projecten.Archief']);
    assertSame('INBOX.Verzonden', $roles['sent']);
    assertSame('INBOX.Concepten', $roles['drafts']);
    assertSame('INBOX.Archief', $roles['archive'], 'shallowest match');
    assertSame('INBOX.Projecten.Archief', $roles['trash'], 'configured');
    assertSame('INBOX.Gepland', $roles['scheduled']);
    assertSame('INBOX.Snoozed', $roles['snoozed']);
    assertSame('inbox', FolderResolver::roleOf('INBOX', $roles));
});

test('sanitizer removes scripts and event handlers, blocks remote images', function () {
    $r = MessageSanitizer::html('<p onclick="x()">Hoi</p><script>alert(1)</script><img src="https://tracker.example/p.gif"><a href="javascript:alert(1)">x</a>', [], false);
    assertNotContains('<script', $r['html']);
    assertNotContains('onclick', $r['html']);
    assertNotContains('javascript:', $r['html']);
    assertSame(0, preg_match('/(?<![-\w])src="https:\/\/tracker/', $r['html']), 'image must not load');
    assertSame(1, $r['blocked_images']);
});

test('sanitizer allows remote images when asked and rewrites cid: images', function () {
    $r = MessageSanitizer::html('<img src="https://example.com/a.png"><img src="cid:logo@x">', ['logo@x' => '/api/accounts/1/messages/2/attachments/0?folder=INBOX&inline=1'], true);
    assertContains('https://example.com/a.png', $r['html']);
    assertContains('/api/accounts/1/messages/2/attachments/0', $r['html']);
});

test('removeHeader drops a header with its folded lines, keeps the body', function () {
    // Several Bcc recipients are folded over lines; a leftover line would end the header block early.
    $raw = "From: a@b.nl\r\nBcc: x@y.nl,\r\n z@y.nl\r\nSubject: Hoi\r\n\r\nBcc: in de tekst\r\n";
    $out = SmtpService::removeHeader($raw, 'Bcc');
    assertSame("From: a@b.nl\r\nSubject: Hoi\r\n\r\nBcc: in de tekst\r\n", $out);
});

test('removeHeader also handles the last header and LF-only messages', function () {
    assertSame("From: a@b.nl\r\n\r\nbody", SmtpService::removeHeader("From: a@b.nl\r\nBcc: x@y.nl\r\n\r\nbody", 'Bcc'));
    assertSame("Subject: Hoi\r\n\r\nTo: blijft", SmtpService::removeHeader("To: a@b.nl\r\nSubject: Hoi\r\n\r\nTo: blijft", 'To'));
});
