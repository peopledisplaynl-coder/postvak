<?php
use Postvak\Push\WebPush;

/** Decrypt like a browser does (RFC 8291), to check our encryption. */
function decryptPush(string $body, $uaKey, string $uaPublic, string $auth): string
{
    $salt = substr($body, 0, 16);
    $idlen = ord($body[20]);
    $asPublic = substr($body, 21, $idlen);
    $cipher = substr($body, 21 + $idlen);
    $peer = openssl_pkey_get_public("-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode(hex2bin('3059301306072a8648ce3d020106082a8648ce3d030107034200') . $asPublic), 64, "\n") . "-----END PUBLIC KEY-----\n");
    $ecdh = openssl_pkey_derive($peer, $uaKey, 32);
    $ikm = hash_hkdf('sha256', $ecdh, 32, "WebPush: info\x00" . $uaPublic . $asPublic, $auth);
    $cek = hash_hkdf('sha256', $ikm, 16, "Content-Encoding: aes128gcm\x00", $salt);
    $nonce = hash_hkdf('sha256', $ikm, 12, "Content-Encoding: nonce\x00", $salt);
    $plain = openssl_decrypt(substr($cipher, 0, -16), 'aes-128-gcm', $cek, OPENSSL_RAW_DATA, $nonce, substr($cipher, -16));
    return substr($plain, 0, strrpos($plain, "\x02"));
}

test('aes128gcm payload decrypts to the original (browser side)', function () {
    $ua = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
    $d = openssl_pkey_get_details($ua);
    $uaPublic = "\x04" . str_pad($d['ec']['x'], 32, "\x00", STR_PAD_LEFT) . str_pad($d['ec']['y'], 32, "\x00", STR_PAD_LEFT);
    $auth = random_bytes(16);
    $payload = json_encode(['type' => 'mail', 'subject' => 'Repetitie — één test']);
    for ($i = 0; $i < 5; $i++) {
        assertSame($payload, decryptPush(WebPush::encrypt($payload, $uaPublic, $auth), $ua, $uaPublic, $auth));
    }
});

test('VAPID token is a valid ES256 JWT for the push service origin', function () {
    $keys = WebPush::generateKeys();
    $wp = new WebPush($keys['public'], $keys['private'], 'mailto:test@example.nl');
    $m = new ReflectionMethod($wp, 'jwt');
    for ($i = 0; $i < 5; $i++) {
        [$h, $c, $sig] = explode('.', $m->invoke($wp, 'https://fcm.googleapis.com/fcm/send/abc'));
        $raw = WebPush::unb64($sig);
        assertSame(64, strlen($raw));
        // raw r||s -> DER for openssl_verify
        $int = static function (string $b): string {
            $b = ltrim($b, "\x00");
            if ($b === '' || ord($b[0]) & 0x80) {
                $b = "\x00" . $b;
            }
            return "\x02" . chr(strlen($b)) . $b;
        };
        $seq = $int(substr($raw, 0, 32)) . $int(substr($raw, 32));
        $der = "\x30" . chr(strlen($seq)) . $seq;
        $pub = openssl_pkey_get_details(openssl_pkey_get_private($keys['private']))['key'];
        assertSame(1, openssl_verify("$h.$c", $der, $pub, OPENSSL_ALGO_SHA256));
        assertSame('https://fcm.googleapis.com', json_decode(WebPush::unb64($c), true)['aud']);
    }
});

test('rejects bad subscription keys', function () {
    assertThrows(fn () => WebPush::encrypt('x', str_repeat("\x04", 10), random_bytes(16)));
});
