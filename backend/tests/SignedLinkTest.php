<?php
use Postvak\Http\Request;
use Postvak\Http\SignedLink;

/** Fill $_GET from a query string, as a request with that link would. */
function linkRequest(string $query): Request
{
    $_GET = [];
    parse_str($query, $_GET);
    return new Request('GET', '/accounts/3/messages/40/attachments/1');
}

test('signed link: opens exactly that attachment for that user, until it expires', function () {
    $now = 1_800_000_000;
    $q = SignedLink::query(7, 3, 40, 'INBOX', 1, $now);
    assertSame(7, SignedLink::verify(linkRequest($q), 3, 40, 'INBOX', 1, $now + 60));
    // Any other file, message, folder or account: no.
    assertSame(null, SignedLink::verify(linkRequest($q), 3, 40, 'INBOX', 2, $now));
    assertSame(null, SignedLink::verify(linkRequest($q), 3, 41, 'INBOX', 1, $now));
    assertSame(null, SignedLink::verify(linkRequest($q), 3, 40, 'Archive', 1, $now));
    assertSame(null, SignedLink::verify(linkRequest($q), 4, 40, 'INBOX', 1, $now));
    // The original message has its own signature.
    assertSame(null, SignedLink::verify(linkRequest($q), 3, 40, 'INBOX', null, $now));
    // Expired, another user, or tampered.
    assertSame(null, SignedLink::verify(linkRequest($q), 3, 40, 'INBOX', 1, $now + SignedLink::TTL + 1));
    assertSame(null, SignedLink::verify(linkRequest(str_replace('u=7', 'u=8', $q)), 3, 40, 'INBOX', 1, $now));
    assertSame(null, SignedLink::verify(linkRequest(preg_replace('/exp=\d+/', 'exp=' . ($now + 999999), $q)), 3, 40, 'INBOX', 1, $now));
    // No signature: the normal login check applies.
    assertSame(null, SignedLink::verify(linkRequest('folder=INBOX'), 3, 40, 'INBOX', 1, $now));
    $_GET = [];
});
