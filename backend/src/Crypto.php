<?php
declare(strict_types=1);

namespace Postvak;

use RuntimeException;

/**
 * Symmetric encryption for stored mailbox passwords (libsodium secretbox).
 * Format: "v1:" . base64(nonce . ciphertext)
 */
final class Crypto
{
    private const PREFIX = 'v1:';

    public static function encrypt(string $plain): string
    {
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $cipher = sodium_crypto_secretbox($plain, $nonce, self::key());
        return self::PREFIX . base64_encode($nonce . $cipher);
    }

    public static function decrypt(string $encoded): string
    {
        if (!str_starts_with($encoded, self::PREFIX)) {
            throw new RuntimeException('Unknown ciphertext format');
        }
        $raw = base64_decode(substr($encoded, strlen(self::PREFIX)), true);
        if ($raw === false || strlen($raw) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
            throw new RuntimeException('Corrupt ciphertext');
        }
        $nonce = substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $cipher = substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $plain = sodium_crypto_secretbox_open($cipher, $nonce, self::key());
        if ($plain === false) {
            throw new RuntimeException('Decryption failed (wrong app_key?)');
        }
        return $plain;
    }

    /** SHA-256 with the app key, for hashing IPs/addresses in the rate limiter. */
    public static function hash(string $value): string
    {
        return hash_hmac('sha256', $value, self::key());
    }

    private static function key(): string
    {
        $key = base64_decode((string) Config::get('app_key'), true);
        if ($key === false || strlen($key) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES) {
            throw new RuntimeException('app_key in config.php must be 32 random bytes, base64 encoded');
        }
        return $key;
    }
}
