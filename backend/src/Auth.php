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
    /** Without "stay logged in": the session ends when the browser closes, or after this many idle seconds. */
    private const SHORT_IDLE = 8 * 3600;

    /**
     * Start the session only when the browser has one, or to log in. A request without the cookie (a phone fetching
     * a file again for its PDF viewer or downloads, an iCal subscription, the health check) must not get a fresh
     * session cookie: in the same browser that would replace the real one and log the user out.
     */
    public static function startIfNeeded(string $path): void
    {
        $name = (string) Config::get('session.name', 'postvak_sid');
        if (isset($_COOKIE[$name]) || in_array($path, ['/auth/login', '/auth/2fa'], true)) {
            self::start();
        }
    }

    public static function start(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }
        $lifetime = self::lifetime();
        ini_set('session.use_strict_mode', '1');
        ini_set('session.gc_maxlifetime', (string) $lifetime);

        // Own session folder: shared hosts often clean their common session folder after ~24 minutes,
        // which would log everyone out regardless of "stay logged in".
        $dir = POSTVAK_ROOT . '/storage/sessions';
        if (is_dir($dir) || @mkdir($dir, 0700, true)) {
            if (is_writable($dir)) {
                session_save_path($dir);
                ini_set('session.gc_probability', '1');
                ini_set('session.gc_divisor', '200');
            }
        }

        session_name((string) Config::get('session.name', 'postvak_sid'));
        session_set_cookie_params(self::cookieParams($lifetime));
        session_start();

        if (isset($_SESSION['user_id'])) {
            $now = time();
            $remember = ($_SESSION['remember'] ?? true) !== false; // sessions from before 1.0.1 count as remembered
            if (!$remember && ($_SESSION['last_seen'] ?? $now) < $now - self::SHORT_IDLE) {
                $_SESSION = [];
                session_regenerate_id(true);
                return;
            }
            $_SESSION['last_seen'] = $now;
            if (!$remember) {
                // session_start() sent a persistent cookie for a new id; this one must not outlive the browser.
                if (!isset($_SESSION['cookie_at'])) {
                    self::sendCookie(0);
                    $_SESSION['cookie_at'] = $now;
                }
            } elseif (($_SESSION['cookie_at'] ?? 0) < $now - 86400) {
                // Rolling: active users stay logged in; the cookie is renewed once a day.
                self::sendCookie($now + $lifetime);
                $_SESSION['cookie_at'] = $now;
            }
        }
    }

    public static function lifetime(): int
    {
        return max(3600, (int) Config::get('session.lifetime', 60 * 60 * 24 * 30));
    }

    private static function cookieParams(int $lifetime): array
    {
        $https = ($_SERVER['HTTPS'] ?? '') !== '' && ($_SERVER['HTTPS'] ?? '') !== 'off'
            || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
        return ['lifetime' => $lifetime, 'path' => '/', 'secure' => $https, 'httponly' => true, 'samesite' => 'Strict'];
    }

    private static function sendCookie(int $expires): void
    {
        if (headers_sent()) {
            return;
        }
        $p = self::cookieParams(0);
        setcookie(session_name(), session_id(), [
            'expires' => $expires, 'path' => $p['path'], 'secure' => $p['secure'], 'httponly' => true, 'samesite' => 'Strict',
        ]);
    }

    public static function login(int $userId, bool $remember = true): string
    {
        session_regenerate_id(true);
        $_SESSION = [
            'user_id'   => $userId,
            'csrf'      => bin2hex(random_bytes(32)),
            'remember'  => $remember,
            'last_seen' => time(),
            'cookie_at' => time(),
        ];
        self::sendCookie($remember ? time() + self::lifetime() : 0);
        return $_SESSION['csrf'];
    }

    /** Remove session files older than the lifetime (called from cron; PHP's own GC may be off). */
    public static function cleanSessions(): int
    {
        $removed = 0;
        $limit = time() - self::lifetime();
        foreach (glob(POSTVAK_ROOT . '/storage/sessions/sess_*') ?: [] as $file) {
            if (@filemtime($file) < $limit && @unlink($file)) {
                $removed++;
            }
        }
        return $removed;
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
