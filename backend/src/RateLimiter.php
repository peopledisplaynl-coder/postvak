<?php
declare(strict_types=1);

namespace Postvak;

use Postvak\Http\HttpException;

/** Limits failed login attempts per IP and per address. */
final class RateLimiter
{
    public static function check(string $ip, string $email): void
    {
        $window = (int) Config::get('rate_limit.window_minutes', 15);
        $since = date('Y-m-d H:i:s', time() - $window * 60);

        $ipFails = (int) Database::one(
            'SELECT COUNT(*) c FROM login_attempts WHERE ip_hash = ? AND success = 0 AND attempted_at > ?',
            [Crypto::hash($ip), $since]
        )['c'];
        $mailFails = (int) Database::one(
            'SELECT COUNT(*) c FROM login_attempts WHERE email_hash = ? AND success = 0 AND attempted_at > ?',
            [Crypto::hash(strtolower($email)), $since]
        )['c'];

        if ($ipFails >= (int) Config::get('rate_limit.max_per_ip', 20)
            || $mailFails >= (int) Config::get('rate_limit.max_per_email', 5)) {
            throw HttpException::tooMany();
        }
    }

    public static function record(string $ip, string $email, bool $success): void
    {
        try {
            Database::run(
                'INSERT INTO login_attempts (ip_hash, email_hash, ip, email, success) VALUES (?, ?, ?, ?, ?)',
                [Crypto::hash($ip), Crypto::hash(strtolower($email)), $ip, strtolower($email), $success ? 1 : 0]
            );
        } catch (\PDOException) {
            // Before migration 002 the ip/email columns do not exist yet.
            Database::run(
                'INSERT INTO login_attempts (ip_hash, email_hash, success) VALUES (?, ?, ?)',
                [Crypto::hash($ip), Crypto::hash(strtolower($email)), $success ? 1 : 0]
            );
        }
        // Occasional cleanup of old rows.
        if (random_int(1, 50) === 1) {
            Database::run('DELETE FROM login_attempts WHERE attempted_at < ?', [date('Y-m-d H:i:s', time() - 86400)]);
        }
    }

    /** Remove failed attempts so a blocked IP or address can log in again. */
    public static function release(?string $ipHash, ?string $emailHash): void
    {
        if ($ipHash) {
            Database::run('DELETE FROM login_attempts WHERE ip_hash = ? AND success = 0', [$ipHash]);
        }
        if ($emailHash) {
            Database::run('DELETE FROM login_attempts WHERE email_hash = ? AND success = 0', [$emailHash]);
        }
    }
}
