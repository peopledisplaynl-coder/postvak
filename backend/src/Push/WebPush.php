<?php
declare(strict_types=1);

namespace Postvak\Push;

use RuntimeException;

/**
 * Minimal Web Push sender without dependencies (only ext-openssl):
 *  - VAPID authentication (RFC 8292): ES256-signed JWT
 *  - payload encryption "aes128gcm" (RFC 8291 + RFC 8188)
 *
 * Keys are P-256. Public keys travel as base64url of the uncompressed point (65 bytes).
 */
final class WebPush
{
    /** ASN.1 prefix of a P-256 SubjectPublicKeyInfo; followed by the 65-byte point. */
    private const SPKI_PREFIX = '3059301306072a8648ce3d020106082a8648ce3d030107034200';

    public function __construct(
        private readonly string $vapidPublic,      // base64url, 65-byte point
        private readonly string $vapidPrivatePem,
        private readonly string $subject,          // "mailto:…" or "https://…"
    ) {}

    /** New VAPID key pair: ['public' => base64url point, 'private' => PEM]. */
    public static function generateKeys(): array
    {
        $key = self::newKey();
        openssl_pkey_export($key, $pem);
        return ['public' => self::b64(self::publicPoint($key)), 'private' => $pem];
    }

    /**
     * Send one push message.
     *
     * @param array{endpoint:string, p256dh:string, auth:string} $subscription
     * @return array{status:int, body:string} HTTP status of the push service (201 = accepted, 404/410 = gone)
     */
    public function send(array $subscription, string $payload, int $ttl = 86400, string $urgency = 'normal', ?string $topic = null): array
    {
        $body = self::encrypt($payload, self::unb64($subscription['p256dh']), self::unb64($subscription['auth']));
        $headers = [
            'Content-Type: application/octet-stream',
            'Content-Encoding: aes128gcm',
            'TTL: ' . $ttl,
            'Urgency: ' . $urgency,
            'Authorization: vapid t=' . $this->jwt($subscription['endpoint']) . ', k=' . $this->vapidPublic,
        ];
        if ($topic !== null) {
            $headers[] = 'Topic: ' . substr(preg_replace('/[^A-Za-z0-9_-]/', '', $topic) ?? '', 0, 32);
        }
        return self::post($subscription['endpoint'], $headers, $body);
    }

    // ------------------------------------------------------------------ VAPID

    private function jwt(string $endpoint): string
    {
        $parts = parse_url($endpoint);
        if (empty($parts['scheme']) || empty($parts['host'])) {
            throw new RuntimeException('Invalid push endpoint');
        }
        $audience = $parts['scheme'] . '://' . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '');
        $header = self::b64(json_encode(['typ' => 'JWT', 'alg' => 'ES256']));
        $claims = self::b64(json_encode(['aud' => $audience, 'exp' => time() + 12 * 3600, 'sub' => $this->subject]));
        $input = "$header.$claims";
        if (!openssl_sign($input, $der, $this->vapidPrivatePem, OPENSSL_ALGO_SHA256)) {
            throw new RuntimeException('Could not sign VAPID token');
        }
        return $input . '.' . self::b64(self::derToRaw($der));
    }

    /** ECDSA signature: DER SEQUENCE{INTEGER r, INTEGER s} -> r||s (2 × 32 bytes) as JWS wants it. */
    private static function derToRaw(string $der): string
    {
        $pos = 2;
        if ((ord($der[1]) & 0x80) !== 0) {
            $pos += ord($der[1]) & 0x7f;
        }
        $out = '';
        for ($i = 0; $i < 2; $i++) {
            if (ord($der[$pos]) !== 0x02) {
                throw new RuntimeException('Unexpected signature format');
            }
            $len = ord($der[$pos + 1]);
            $int = ltrim(substr($der, $pos + 2, $len), "\x00");
            $out .= str_pad($int, 32, "\x00", STR_PAD_LEFT);
            $pos += 2 + $len;
        }
        return $out;
    }

    // ------------------------------------------------------------- encryption

    /**
     * RFC 8291: encrypt for the browser's key (ua_public) and auth secret.
     * Result: salt(16) | record size(4) | key id length(1) | our public key(65) | ciphertext+tag.
     */
    public static function encrypt(string $payload, string $uaPublic, string $authSecret): string
    {
        if (strlen($uaPublic) !== 65 || $uaPublic[0] !== "\x04" || strlen($authSecret) !== 16) {
            throw new RuntimeException('Invalid subscription keys');
        }
        if (strlen($payload) > 3800) {
            throw new RuntimeException('Push payload too large');
        }

        $local = self::newKey();
        $asPublic = self::publicPoint($local);
        $peer = openssl_pkey_get_public(self::pem(hex2bin(self::SPKI_PREFIX) . $uaPublic, 'PUBLIC KEY'));
        if ($peer === false) {
            throw new RuntimeException('Invalid browser public key');
        }
        $ecdh = openssl_pkey_derive($peer, $local, 32);
        if ($ecdh === false || strlen($ecdh) !== 32) {
            throw new RuntimeException('Key agreement failed');
        }

        // IKM = HKDF(salt = auth secret, ikm = ECDH secret, info = "WebPush: info\0" | ua_public | as_public)
        $ikm = hash_hkdf('sha256', $ecdh, 32, "WebPush: info\x00" . $uaPublic . $asPublic, $authSecret);
        $salt = random_bytes(16);
        $cek = hash_hkdf('sha256', $ikm, 16, "Content-Encoding: aes128gcm\x00", $salt);
        $nonce = hash_hkdf('sha256', $ikm, 12, "Content-Encoding: nonce\x00", $salt);

        // One record; 0x02 marks the last (and only) record.
        $cipher = openssl_encrypt($payload . "\x02", 'aes-128-gcm', $cek, OPENSSL_RAW_DATA, $nonce, $tag, '', 16);
        if ($cipher === false) {
            throw new RuntimeException('Encryption failed');
        }
        return $salt . pack('N', 4096) . chr(65) . $asPublic . $cipher . $tag;
    }

    // ------------------------------------------------------------------ helpers

    private static function newKey(): \OpenSSLAsymmetricKey
    {
        $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        if ($key === false) {
            throw new RuntimeException('OpenSSL cannot create P-256 keys on this server');
        }
        return $key;
    }

    private static function publicPoint(\OpenSSLAsymmetricKey $key): string
    {
        $d = openssl_pkey_get_details($key);
        return "\x04" . str_pad($d['ec']['x'], 32, "\x00", STR_PAD_LEFT) . str_pad($d['ec']['y'], 32, "\x00", STR_PAD_LEFT);
    }

    private static function pem(string $der, string $label): string
    {
        return "-----BEGIN $label-----\n" . chunk_split(base64_encode($der), 64, "\n") . "-----END $label-----\n";
    }

    public static function b64(string $bin): string
    {
        return rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');
    }

    public static function unb64(string $text): string
    {
        $bin = base64_decode(strtr($text, '-_', '+/'), true);
        if ($bin === false) {
            throw new RuntimeException('Invalid base64url');
        }
        return $bin;
    }

    /** @return array{status:int, body:string} */
    private static function post(string $url, array $headers, string $body): array
    {
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_POST           => true,
                CURLOPT_HTTPHEADER     => [...$headers, 'Content-Length: ' . strlen($body)],
                CURLOPT_POSTFIELDS     => $body,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 10,
                CURLOPT_CONNECTTIMEOUT => 5,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_PROTOCOLS      => CURLPROTO_HTTPS | CURLPROTO_HTTP,
            ]);
            $response = curl_exec($ch);
            $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            $error = curl_error($ch);
            curl_close($ch);
            if ($response === false) {
                throw new RuntimeException('Push service unreachable: ' . $error);
            }
            return ['status' => $status, 'body' => substr((string) $response, 0, 500)];
        }

        $context = stream_context_create(['http' => [
            'method'        => 'POST',
            'header'        => implode("\r\n", [...$headers, 'Content-Length: ' . strlen($body)]),
            'content'       => $body,
            'timeout'       => 10,
            'ignore_errors' => true,
            'follow_location' => 0,
        ]]);
        $response = @file_get_contents($url, false, $context);
        $status = 0;
        foreach ($http_response_header ?? [] as $line) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $m)) {
                $status = (int) $m[1];
            }
        }
        if ($response === false && $status === 0) {
            throw new RuntimeException('Push service unreachable');
        }
        return ['status' => $status, 'body' => substr((string) $response, 0, 500)];
    }
}
