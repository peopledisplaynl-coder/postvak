<?php
use Postvak\Security\Totp;

test('RFC 6238 test vectors (last 6 digits)', function () {
    $key = '12345678901234567890';
    foreach ([59 => '287082', 1111111109 => '081804', 1234567890 => '005924', 2000000000 => '279037'] as $time => $code) {
        assertSame($code, Totp::code($key, intdiv($time, 30)), "t=$time");
    }
});

test('base32 matches the RFC 4648 example and round-trips', function () {
    assertSame('GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ', Totp::base32('12345678901234567890'));
    $secret = Totp::newSecret();
    assertSame(32, strlen($secret));
    assertSame($secret, Totp::base32(Totp::unbase32($secret)));
});

test('verify accepts the current and neighbouring steps, rejects others', function () {
    $secret = Totp::newSecret();
    $key = Totp::unbase32($secret);
    $now = 1_800_000_000;
    $step = intdiv($now, 30);
    assertSame($step, Totp::verify($secret, Totp::code($key, $step), 1, $now));
    assertSame($step - 1, Totp::verify($secret, Totp::code($key, $step - 1), 1, $now));
    assertSame(null, Totp::verify($secret, Totp::code($key, $step + 3), 1, $now));
    assertSame($step, Totp::verify($secret, substr(Totp::code($key, $step), 0, 3) . ' ' . substr(Totp::code($key, $step), 3), 1, $now), 'spaces allowed');
    assertSame(null, Totp::verify($secret, '12345', 1, $now));
});

test('otpauth URI contains issuer and secret', function () {
    $uri = Totp::uri('ABC', 'ton@example.nl', 'Postvak');
    assertContains('otpauth://totp/Postvak%3Aton%40example.nl', $uri);
    assertContains('secret=ABC', $uri);
});
