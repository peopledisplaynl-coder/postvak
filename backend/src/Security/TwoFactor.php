<?php
declare(strict_types=1);

namespace Postvak\Security;

use Postvak\Config;
use Postvak\Crypto;
use Postvak\Database;
use Postvak\Http\HttpException;

/**
 * Two-factor authentication per Postvak user: TOTP (authenticator app), single-use
 * recovery codes, and devices that may skip the code for a while ("remember this device").
 */
final class TwoFactor
{
    public const COOKIE = 'postvak_td';
    private const TRUST_DAYS = 30;
    private const RECOVERY_COUNT = 10;

    public function enabled(int $userId): bool
    {
        $row = Database::one('SELECT totp_enabled_at FROM users WHERE id = ?', [$userId]);
        return !empty($row['totp_enabled_at']);
    }

    /** For the settings page. */
    public function status(int $userId): array
    {
        $row = Database::one('SELECT totp_enabled_at, totp_recovery FROM users WHERE id = ?', [$userId]) ?? [];
        return [
            'enabled'        => !empty($row['totp_enabled_at']),
            'enabled_at'     => $row['totp_enabled_at'] ?? null,
            'recovery_left'  => count((array) json_decode((string) ($row['totp_recovery'] ?? '[]'), true)),
            'devices'        => array_map(static fn ($d) => [
                'id'           => (int) $d['id'],
                'user_agent'   => $d['user_agent'],
                'created_at'   => $d['created_at'],
                'last_used_at' => $d['last_used_at'],
                'current'      => hash_equals($d['token_hash'], hash('sha256', (string) ($_COOKIE[self::COOKIE] ?? ''))),
            ], Database::all('SELECT * FROM trusted_devices WHERE user_id = ? AND expires_at > NOW() ORDER BY created_at DESC', [$userId])),
        ];
    }

    // ------------------------------------------------------------------ setup

    /** Step 1: a new secret, kept in the session until a code confirms it. */
    public function beginSetup(int $userId, string $email): array
    {
        $secret = Totp::newSecret();
        $_SESSION['totp_setup'] = ['user_id' => $userId, 'secret' => $secret, 'until' => time() + 900];
        $issuer = 'Postvak' . (($host = parse_url((string) Config::get('app_url', ''), PHP_URL_HOST)) ? " ($host)" : '');
        return ['secret' => $secret, 'uri' => Totp::uri($secret, $email, $issuer)];
    }

    /** Step 2: confirm with a code from the app. Returns the recovery codes (shown once). */
    public function enable(int $userId, string $code): array
    {
        $setup = $_SESSION['totp_setup'] ?? null;
        if (!is_array($setup) || $setup['user_id'] !== $userId || $setup['until'] < time()) {
            throw HttpException::badRequest('Start the setup again');
        }
        $step = Totp::verify($setup['secret'], $code);
        if ($step === null) {
            throw HttpException::badRequest('Wrong code');
        }
        $codes = $this->newRecoveryCodes();
        Database::run(
            'UPDATE users SET totp_secret = ?, totp_enabled_at = NOW(), totp_last_step = ?, totp_recovery = ? WHERE id = ?',
            [Crypto::encrypt($setup['secret']), $step, json_encode(array_map([self::class, 'hashCode'], $codes)), $userId]
        );
        unset($_SESSION['totp_setup']);
        return $codes;
    }

    /** Switch off (needs a valid code or recovery code). */
    public function disable(int $userId, string $code): void
    {
        if (!$this->check($userId, $code)) {
            throw HttpException::badRequest('Wrong code');
        }
        $this->reset($userId);
    }

    /** Switch off without a code (admin, for someone who lost their phone). */
    public function reset(int $userId): void
    {
        Database::run('UPDATE users SET totp_secret = NULL, totp_enabled_at = NULL, totp_last_step = NULL, totp_recovery = NULL WHERE id = ?', [$userId]);
        Database::run('DELETE FROM trusted_devices WHERE user_id = ?', [$userId]);
    }

    /** New set of recovery codes (needs a valid code). */
    public function regenerateRecovery(int $userId, string $code): array
    {
        if (!$this->check($userId, $code, allowRecovery: false)) {
            throw HttpException::badRequest('Wrong code');
        }
        $codes = $this->newRecoveryCodes();
        Database::run('UPDATE users SET totp_recovery = ? WHERE id = ?', [json_encode(array_map([self::class, 'hashCode'], $codes)), $userId]);
        return $codes;
    }

    // ------------------------------------------------------------------ check

    /**
     * A code from the app (each time step only once) or an unused recovery code
     * (which is then used up).
     */
    public function check(int $userId, string $code, bool $allowRecovery = true): bool
    {
        $row = Database::one('SELECT totp_secret, totp_last_step, totp_recovery FROM users WHERE id = ?', [$userId]);
        if (!$row || empty($row['totp_secret'])) {
            return false;
        }
        $digits = preg_replace('/\D/', '', $code) ?? '';
        if (strlen($digits) === 6) {
            $step = Totp::verify(Crypto::decrypt($row['totp_secret']), $digits);
            if ($step === null || ($row['totp_last_step'] !== null && $step <= (int) $row['totp_last_step'])) {
                return false;
            }
            // Only advance: two requests with the same code cannot both succeed.
            $updated = Database::run('UPDATE users SET totp_last_step = ? WHERE id = ? AND (totp_last_step IS NULL OR totp_last_step < ?)', [$step, $userId, $step]);
            return $updated->rowCount() === 1;
        }
        if (!$allowRecovery) {
            return false;
        }
        $hash = self::hashCode($code);
        $list = (array) json_decode((string) ($row['totp_recovery'] ?? '[]'), true);
        foreach ($list as $i => $stored) {
            if (hash_equals((string) $stored, $hash)) {
                unset($list[$i]);
                Database::run('UPDATE users SET totp_recovery = ? WHERE id = ?', [json_encode(array_values($list)), $userId]);
                return true;
            }
        }
        return false;
    }

    // ---------------------------------------------------------- trusted devices

    public function isTrustedDevice(int $userId): bool
    {
        $token = (string) ($_COOKIE[self::COOKIE] ?? '');
        if (strlen($token) < 32) {
            return false;
        }
        $row = Database::one('SELECT id FROM trusted_devices WHERE user_id = ? AND token_hash = ? AND expires_at > NOW()', [$userId, hash('sha256', $token)]);
        if ($row) {
            Database::run('UPDATE trusted_devices SET last_used_at = NOW() WHERE id = ?', [$row['id']]);
        }
        return (bool) $row;
    }

    public function trustThisDevice(int $userId, ?string $userAgent): void
    {
        $token = bin2hex(random_bytes(32));
        Database::run(
            'INSERT INTO trusted_devices (user_id, token_hash, user_agent, expires_at) VALUES (?, ?, ?, ?)',
            [$userId, hash('sha256', $token), $userAgent !== null ? mb_substr($userAgent, 0, 255) : null, date('Y-m-d H:i:s', time() + self::TRUST_DAYS * 86400)]
        );
        setcookie(self::COOKIE, $token, [
            'expires'  => time() + self::TRUST_DAYS * 86400,
            'path'     => '/',
            'secure'   => self::https(),
            'httponly' => true,
            'samesite' => 'Strict',
        ]);
    }

    public function forgetDevices(int $userId): void
    {
        Database::run('DELETE FROM trusted_devices WHERE user_id = ?', [$userId]);
    }

    // ------------------------------------------------------------------ helpers

    /** @return string[] like "k7m2-x9pq" */
    private function newRecoveryCodes(): array
    {
        $alphabet = 'abcdefghjkmnpqrstuvwxyz23456789'; // no 0/o, 1/l/i
        $codes = [];
        for ($i = 0; $i < self::RECOVERY_COUNT; $i++) {
            $c = '';
            for ($j = 0; $j < 8; $j++) {
                $c .= $alphabet[random_int(0, strlen($alphabet) - 1)];
            }
            $codes[] = substr($c, 0, 4) . '-' . substr($c, 4);
        }
        return $codes;
    }

    private static function hashCode(string $code): string
    {
        $normalized = strtolower(preg_replace('/[^a-z0-9]/i', '', $code) ?? '');
        return hash_hmac('sha256', $normalized, (string) Config::get('app_key'));
    }

    private static function https(): bool
    {
        return (($_SERVER['HTTPS'] ?? '') !== '' && ($_SERVER['HTTPS'] ?? '') !== 'off')
            || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
    }
}
