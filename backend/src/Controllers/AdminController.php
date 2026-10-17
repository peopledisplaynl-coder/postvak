<?php
declare(strict_types=1);

namespace Postvak\Controllers;

use Postvak\Auth;
use Postvak\Config;
use Postvak\Database;
use Postvak\Http\HttpException;
use Postvak\Http\Request;
use Postvak\Http\Response;
use Postvak\Migrator;
use Postvak\RateLimiter;
use Postvak\Repositories\AppSettingsRepository;
use Postvak\Repositories\SettingsRepository;
use Throwable;

/**
 * Admin page: overview, users, security and app-wide settings.
 * Only for addresses listed in config.php 'admins' (or user 1 when that list is empty).
 */
final class AdminController
{
    public function __construct(
        private readonly AppSettingsRepository $app = new AppSettingsRepository(),
        private readonly SettingsRepository $settings = new SettingsRepository(),
    ) {}

    // ----------------------------------------------------------------- overview

    /** GET /admin/overview */
    public function overview(Request $r): void
    {
        Auth::requireAdmin();
        $since = date('Y-m-d H:i:s', time() - 86400);

        $count = static fn (string $sql, array $p = []) => (int) (Database::one($sql, $p)['c'] ?? 0);

        $extensions = [];
        foreach (['mbstring', 'openssl', 'sodium', 'pdo_mysql', 'iconv', 'fileinfo', 'zip', 'libxml'] as $ext) {
            $extensions[$ext] = extension_loaded($ext);
        }

        Response::json([
            'version'     => defined('POSTVAK_VERSION') ? POSTVAK_VERSION : null,
            'php'         => PHP_VERSION,
            'extensions'  => $extensions,
            'limits'      => [
                'upload_max_filesize' => ini_get('upload_max_filesize'),
                'post_max_size'       => ini_get('post_max_size'),
                'memory_limit'        => ini_get('memory_limit'),
                'max_execution_time'  => ini_get('max_execution_time'),
            ],
            'database'    => [
                'server'     => Database::pdo()->getAttribute(\PDO::ATTR_SERVER_VERSION),
                'migrations' => [
                    'applied' => array_column(Database::all('SELECT version, applied_at FROM schema_migrations ORDER BY version'), 'applied_at', 'version'),
                    'pending' => Migrator::pending(),
                ],
            ],
            'mail_server' => $this->checkMailServer(),
            'stats'       => [
                'users'          => $count('SELECT COUNT(*) c FROM users'),
                'accounts'       => $count('SELECT COUNT(*) c FROM accounts'),
                'logins_24h'     => $count('SELECT COUNT(*) c FROM login_attempts WHERE success = 1 AND attempted_at > ?', [$since]),
                'failed_24h'     => $count('SELECT COUNT(*) c FROM login_attempts WHERE success = 0 AND attempted_at > ?', [$since]),
            ],
            'debug'       => (bool) Config::get('debug'),
        ]);
    }

    /** POST /admin/migrate — apply pending database updates. */
    public function migrate(Request $r): void
    {
        Auth::requireAdmin();
        Response::json(['applied' => Migrator::run(), 'pending' => Migrator::pending()]);
    }

    /** Can the server reach the default IMAP and SMTP servers? (TCP connect only, no login.) */
    private function checkMailServer(): array
    {
        $d = (array) Config::get('default_servers', []);
        $out = [];
        foreach (['imap' => [$d['imap_host'] ?? '', (int) ($d['imap_port'] ?? 993), $d['imap_encryption'] ?? 'ssl'],
                  'smtp' => [$d['smtp_host'] ?? '', (int) ($d['smtp_port'] ?? 465), $d['smtp_encryption'] ?? 'ssl']] as $kind => [$host, $port, $enc]) {
            if ($host === '' || str_contains($host, '{domain}')) {
                $out[$kind] = ['host' => $host, 'port' => $port, 'ok' => null, 'note' => 'depends on the domain of each address'];
                continue;
            }
            $start = microtime(true);
            $scheme = $enc === 'ssl' ? 'ssl://' : 'tcp://';
            $ctx = stream_context_create(['ssl' => ['verify_peer' => (bool) ($d['validate_cert'] ?? true), 'verify_peer_name' => (bool) ($d['validate_cert'] ?? true)]]);
            $errno = 0;
            $errstr = '';
            $socket = @stream_socket_client($scheme . $host . ':' . $port, $errno, $errstr, 5, STREAM_CLIENT_CONNECT, $ctx);
            $out[$kind] = [
                'host' => $host,
                'port' => $port,
                'ok'   => $socket !== false,
                'ms'   => (int) round((microtime(true) - $start) * 1000),
                'note' => $socket === false ? ($errstr ?: 'connection failed') : null,
            ];
            if ($socket) {
                fclose($socket);
            }
        }
        return $out;
    }

    // -------------------------------------------------------------------- users

    /** GET /admin/users */
    public function users(Request $r): void
    {
        Auth::requireAdmin();
        $users = Database::all('SELECT * FROM users ORDER BY login_email');
        $accounts = Database::all('SELECT id, user_id, email, display_name, is_login, imap_host FROM accounts ORDER BY sort_order, id');
        $byUser = [];
        foreach ($accounts as $a) {
            $byUser[(int) $a['user_id']][] = ['id' => (int) $a['id'], 'email' => $a['email'], 'is_login' => (bool) $a['is_login'], 'imap_host' => $a['imap_host']];
        }
        Response::json(array_map(fn ($u) => [
            'id'            => (int) $u['id'],
            'login_email'   => $u['login_email'],
            'display_name'  => $u['display_name'],
            'created_at'    => $u['created_at'],
            'last_login_at' => $u['last_login_at'],
            'blocked'       => !empty($u['blocked']),
            'is_admin'      => Auth::isAdmin((int) $u['id']),
            'accounts'      => $byUser[(int) $u['id']] ?? [],
        ], $users));
    }

    /** PATCH /admin/users/{id}  {blocked: bool} */
    public function updateUser(Request $r): void
    {
        $me = Auth::requireAdmin();
        $id = $r->intParam('id');
        if ($id === $me) {
            throw HttpException::badRequest('You cannot block yourself');
        }
        if (Database::one('SELECT id FROM users WHERE id = ?', [$id]) === null) {
            throw HttpException::notFound('User not found');
        }
        $blocked = (bool) $r->input('blocked', false);
        Database::run('UPDATE users SET blocked = ? WHERE id = ?', [$blocked ? 1 : 0, $id]);
        Response::json(['id' => $id, 'blocked' => $blocked]);
    }

    /** DELETE /admin/users/{id} — removes the user, their accounts and settings from Postvak (not their mail). */
    public function deleteUser(Request $r): void
    {
        $me = Auth::requireAdmin();
        $id = $r->intParam('id');
        if ($id === $me) {
            throw HttpException::badRequest('You cannot remove yourself');
        }
        Database::run('DELETE FROM users WHERE id = ?', [$id]);
        Response::noContent();
    }

    // ----------------------------------------------------------------- security

    /** GET /admin/security — login attempts of the last 24 hours and what is blocked right now. */
    public function security(Request $r): void
    {
        Auth::requireAdmin();
        $window = (int) Config::get('rate_limit.window_minutes', 15);
        $maxIp = (int) Config::get('rate_limit.max_per_ip', 20);
        $maxMail = (int) Config::get('rate_limit.max_per_email', 5);
        $windowStart = date('Y-m-d H:i:s', time() - $window * 60);

        try {
            $recent = Database::all(
                'SELECT ip, email, success, attempted_at FROM login_attempts
                 WHERE attempted_at > ? ORDER BY attempted_at DESC LIMIT 100',
                [date('Y-m-d H:i:s', time() - 86400)]
            );
            $blockedIps = Database::all(
                'SELECT ip_hash, MAX(ip) ip, COUNT(*) fails, MAX(attempted_at) last_at FROM login_attempts
                 WHERE success = 0 AND attempted_at > ? GROUP BY ip_hash HAVING fails >= ?',
                [$windowStart, $maxIp]
            );
            $blockedEmails = Database::all(
                'SELECT email_hash, MAX(email) email, COUNT(*) fails, MAX(attempted_at) last_at FROM login_attempts
                 WHERE success = 0 AND attempted_at > ? GROUP BY email_hash HAVING fails >= ?',
                [$windowStart, $maxMail]
            );
        } catch (Throwable) {
            throw HttpException::badRequest('Run the pending database update first (Overview tab)');
        }

        Response::json([
            'limits'         => ['window_minutes' => $window, 'max_per_ip' => $maxIp, 'max_per_email' => $maxMail],
            'blocked_ips'    => array_map(static fn ($b) => ['key' => $b['ip_hash'], 'ip' => $b['ip'], 'fails' => (int) $b['fails'], 'last_at' => $b['last_at']], $blockedIps),
            'blocked_emails' => array_map(static fn ($b) => ['key' => $b['email_hash'], 'email' => $b['email'], 'fails' => (int) $b['fails'], 'last_at' => $b['last_at']], $blockedEmails),
            'recent'         => array_map(static fn ($a) => ['ip' => $a['ip'], 'email' => $a['email'], 'success' => (bool) $a['success'], 'at' => $a['attempted_at']], $recent),
        ]);
    }

    /** POST /admin/security/release  {ip_key?: string, email_key?: string} */
    public function release(Request $r): void
    {
        Auth::requireAdmin();
        $ip = $r->input('ip_key');
        $email = $r->input('email_key');
        foreach ([$ip, $email] as $key) {
            if ($key !== null && !preg_match('/^[a-f0-9]{64}$/', (string) $key)) {
                throw HttpException::badRequest('Invalid key');
            }
        }
        RateLimiter::release($ip, $email);
        Response::json(['released' => true]);
    }

    // ----------------------------------------------------------------- settings

    /** GET /admin/settings */
    public function settings(Request $r): void
    {
        Auth::requireAdmin();
        Response::json($this->settingsPayload());
    }

    /** PATCH /admin/settings  {allowed_login_domains?: string[], user_defaults?: {...}} */
    public function updateSettings(Request $r): void
    {
        Auth::requireAdmin();
        $body = $r->body();

        if (array_key_exists('allowed_login_domains', $body)) {
            $domains = [];
            foreach ((array) $body['allowed_login_domains'] as $d) {
                $d = strtolower(trim(ltrim((string) $d, '@')));
                if ($d === '') {
                    continue;
                }
                if (!preg_match('/^[a-z0-9-]+(\.[a-z0-9-]+)+$/', $d)) {
                    throw HttpException::badRequest("Invalid domain: $d");
                }
                $domains[] = $d;
            }
            // Safety net: never lock out the admin's own domain.
            $me = Database::one('SELECT login_email FROM users WHERE id = ?', [Auth::userId()]);
            $myDomain = substr(strrchr((string) ($me['login_email'] ?? ''), '@') ?: '', 1);
            if ($domains !== [] && $myDomain !== '' && !in_array($myDomain, $domains, true)) {
                $domains[] = $myDomain;
            }
            if ($domains === []) {
                // Empty list on the admin page = use the value from config.php again.
                $this->app->delete('allowed_login_domains');
            } else {
                $this->app->set('allowed_login_domains', array_values(array_unique($domains)));
            }
        }

        if (isset($body['user_defaults']) && is_array($body['user_defaults'])) {
            $this->app->set('user_defaults', $this->settings->validateAll($body['user_defaults']));
        }

        Response::json($this->settingsPayload());
    }

    private function settingsPayload(): array
    {
        return [
            'allowed_login_domains' => $this->app->allowedLoginDomains(),
            'allowed_from_config'   => !is_array($this->app->get('allowed_login_domains')),
            'user_defaults'         => SettingsRepository::defaults(),
            'admins'                => (array) Config::get('admins', []),
        ];
    }
}
