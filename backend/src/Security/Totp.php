<?php
declare(strict_types=1);

namespace Postvak\Security;

/**
 * Time-based one-time passwords (RFC 6238): SHA-1, 30 seconds, 6 digits —
 * what Google Authenticator, Microsoft Authenticator, 1Password, Aegis etc. use.
 */
final class Totp
{
    private const PERIOD = 30;
    private const DIGITS = 6;
    private const ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    /** New secret, base32 (160 bits). */
    public static function newSecret(): string
    {
        return self::base32(random_bytes(20));
    }

    /**
     * The time step the code belongs to (current step ± $window), or null.
     * The caller rejects steps that were used before (replay).
     */
    public static function verify(string $secret, string $code, int $window = 1, ?int $now = null): ?int
    {
        $code = preg_replace('/\D/', '', $code) ?? '';
        if (strlen($code) !== self::DIGITS) {
            return null;
        }
        $key = self::unbase32($secret);
        $step = intdiv($now ?? time(), self::PERIOD);
        for ($i = -$window; $i <= $window; $i++) {
            if (hash_equals(self::code($key, $step + $i), $code)) {
                return $step + $i;
            }
        }
        return null;
    }

    /** otpauth:// link for the QR code. */
    public static function uri(string $secret, string $account, string $issuer): string
    {
        return 'otpauth://totp/' . rawurlencode($issuer . ':' . $account)
            . '?secret=' . $secret . '&issuer=' . rawurlencode($issuer) . '&algorithm=SHA1&digits=' . self::DIGITS . '&period=' . self::PERIOD;
    }

    public static function code(string $key, int $step): string
    {
        $hash = hash_hmac('sha1', pack('J', $step), $key, true);
        $offset = ord($hash[19]) & 0x0f;
        $value = ((ord($hash[$offset]) & 0x7f) << 24) | (ord($hash[$offset + 1]) << 16) | (ord($hash[$offset + 2]) << 8) | ord($hash[$offset + 3]);
        return str_pad((string) ($value % (10 ** self::DIGITS)), self::DIGITS, '0', STR_PAD_LEFT);
    }

    public static function base32(string $bin): string
    {
        $bits = '';
        foreach (str_split($bin) as $c) {
            $bits .= str_pad(decbin(ord($c)), 8, '0', STR_PAD_LEFT);
        }
        $out = '';
        foreach (str_split($bits, 5) as $chunk) {
            $out .= self::ALPHABET[bindec(str_pad($chunk, 5, '0'))];
        }
        return $out;
    }

    public static function unbase32(string $text): string
    {
        $text = strtoupper(preg_replace('/[\s=-]/', '', $text) ?? '');
        $bits = '';
        foreach (str_split($text) as $c) {
            $pos = strpos(self::ALPHABET, $c);
            if ($pos === false) {
                throw new \InvalidArgumentException('Invalid base32');
            }
            $bits .= str_pad(decbin($pos), 5, '0', STR_PAD_LEFT);
        }
        $out = '';
        foreach (str_split($bits, 8) as $byte) {
            if (strlen($byte) === 8) {
                $out .= chr(bindec($byte));
            }
        }
        return $out;
    }
}
