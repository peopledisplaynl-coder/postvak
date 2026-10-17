<?php
declare(strict_types=1);

namespace Postvak;

use Postvak\Http\HttpException;
use Postvak\Http\Request;

/**
 * Session handling and CSRF protection.
 *
 * The session only holds the user id and a CSRF token. Mailbox passwords stay
 * encrypted in the database and are decrypted per request when needed.
 */
final class Auth
{
    public static function start(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }
        $lifetime = (int) Config::get('session.lifetime', 1209600);
        $https = ($_SERVER['HTTPS'] ?? '') !== '' && ($_SERVER['HTTPS'] ?? '') !== 'off'
            || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';

        ini_set('session.use_strict_mode', '1');
        ini_set('session.gc_maxlifetime', (string) $lifetime);
        session_name((string) Config::get('session.name', 'postvak_sid'));
        session_set_cookie_params([
            'lifetime' => $lifetime,
            'path'     => '/',
            'secure'   => $https,
            'httponly' => true,
            'samesite' => 'Strict',
        ]);
        session_start();
    }

    public static function login(int $userId): string
    {
        session_regenerate_id(true);
        $_SESSION['user_id'] = $userId;
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
        return $_SESSION['csrf'];
    }

    public static function logout(): void
    {
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $p = session_get_cookie_params();
            setcookie(session_name(), '', [
                'expires' => time() - 3600, 'path' => $p['path'], 'secure' => $p['secure'],
                'httponly' => $p['httponly'], 'samesite' => $p['samesite'],
            ]);
        }
        session_destroy();
    }

    public static function userId(): ?int
    {
        return isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : null;
    }

    public static function requireUser(): int
    {
        $id = self::userId();
        if ($id === null) {
            throw HttpException::unauthorized();
        }
        // A user blocked by an admin is logged out on the next request.
        $user = Database::one('SELECT * FROM users WHERE id = ?', [$id]);
        if ($user === null || !empty($user['blocked'])) {
            self::logout();
            throw HttpException::unauthorized($user === null ? 'Not logged in' : 'This account has been blocked');
        }
        return $id;
    }

    /**
     * Admins are listed by address in config.php ('admins' => ['you@example.com']).
     * When that list is empty, the first user ever (id 1) is the admin.
     */
    public static function isAdmin(int $userId): bool
    {
        $admins = array_map('strtolower', (array) Config::get('admins', []));
        if ($admins === []) {
            return $userId === 1;
        }
        $user = Database::one('SELECT login_email FROM users WHERE id = ?', [$userId]);
        return $user !== null && in_array(strtolower($user['login_email']), $admins, true);
    }

    public static function requireAdmin(): int
    {
        $id = self::requireUser();
        if (!self::isAdmin($id)) {
            throw HttpException::forbidden('Admins only');
        }
        return $id;
    }

    public static function csrfToken(): ?string
    {
        return $_SESSION['csrf'] ?? null;
    }

    /** Reject state-changing requests without a valid X-CSRF-Token header. */
    public static function checkCsrf(Request $request): void
    {
        if (in_array($request->method, ['GET', 'HEAD', 'OPTIONS'], true)) {
            return;
        }
        $token = self::csrfToken();
        $sent = $request->header('X-CSRF-Token');
        if ($token === null || $sent === null || !hash_equals($token, $sent)) {
            throw HttpException::forbidden('Invalid CSRF token');
        }
    }
}
