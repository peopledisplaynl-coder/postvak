<?php
declare(strict_types=1);

namespace Postvak\Push;

use Postvak\Config;
use Postvak\Crypto;
use Postvak\Database;
use Postvak\Http\HttpException;
use Postvak\Repositories\AppSettingsRepository;
use Throwable;

/**
 * Push subscriptions (one per browser/device) and sending to them.
 * The VAPID key pair is created on first use and kept in app_settings (private key encrypted).
 */
final class PushService
{
    private ?WebPush $sender = null;

    public function __construct(private readonly AppSettingsRepository $settings = new AppSettingsRepository()) {}

    public function publicKey(): string
    {
        return $this->keys()['public'];
    }

    /** @return array{public:string, private:string} */
    private function keys(): array
    {
        $stored = $this->settings->get('vapid');
        if (is_array($stored) && !empty($stored['public']) && !empty($stored['private'])) {
            return ['public' => $stored['public'], 'private' => Crypto::decrypt($stored['private'])];
        }
        $keys = WebPush::generateKeys();
        $this->settings->set('vapid', ['public' => $keys['public'], 'private' => Crypto::encrypt($keys['private'])]);
        return $keys;
    }

    private function sender(): WebPush
    {
        if ($this->sender === null) {
            $keys = $this->keys();
            $url = (string) Config::get('app_url', '');
            $subject = str_starts_with($url, 'https://') ? $url : 'mailto:postmaster@' . (parse_url($url, PHP_URL_HOST) ?: 'localhost');
            $this->sender = new WebPush($keys['public'], $keys['private'], $subject);
        }
        return $this->sender;
    }

    // ------------------------------------------------------------ subscriptions

    public function subscribe(int $userId, string $endpoint, string $p256dh, string $auth, ?string $userAgent, ?string $lang = null): void
    {
        self::assertEndpoint($endpoint);
        try {
            if (strlen(WebPush::unb64($p256dh)) !== 65 || strlen(WebPush::unb64($auth)) !== 16) {
                throw new \RuntimeException();
            }
        } catch (Throwable) {
            throw HttpException::badRequest('Invalid subscription keys');
        }
        Database::run(
            'INSERT INTO push_subscriptions (user_id, endpoint, endpoint_hash, p256dh, auth, lang, user_agent) VALUES (?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE user_id = VALUES(user_id), p256dh = VALUES(p256dh), auth = VALUES(auth), lang = VALUES(lang), user_agent = VALUES(user_agent)',
            [$userId, $endpoint, hash('sha256', $endpoint), $p256dh, $auth, $lang !== null && preg_match('/^[a-z]{2}$/', $lang) ? $lang : null, $userAgent !== null ? mb_substr($userAgent, 0, 255) : null]
        );
    }

    public function unsubscribe(int $userId, string $endpoint): void
    {
        Database::run('DELETE FROM push_subscriptions WHERE user_id = ? AND endpoint_hash = ?', [$userId, hash('sha256', $endpoint)]);
    }

    public function find(int $userId, string $endpoint): ?array
    {
        return Database::one('SELECT * FROM push_subscriptions WHERE user_id = ? AND endpoint_hash = ?', [$userId, hash('sha256', $endpoint)]);
    }

    public function countForUser(int $userId): int
    {
        return (int) (Database::one('SELECT COUNT(*) AS n FROM push_subscriptions WHERE user_id = ?', [$userId])['n'] ?? 0);
    }

    /** @return int[] */
    public function usersWithSubscriptions(): array
    {
        return array_map('intval', array_column(Database::all('SELECT DISTINCT user_id FROM push_subscriptions'), 'user_id'));
    }

    // ------------------------------------------------------------------ sending

    /** Send to every device of a user. @return array{sent:int, failed:int, removed:int} */
    public function sendToUser(int $userId, array $message, ?string $topic = null): array
    {
        $stats = ['sent' => 0, 'failed' => 0, 'removed' => 0];
        foreach (Database::all('SELECT * FROM push_subscriptions WHERE user_id = ?', [$userId]) as $sub) {
            $result = $this->sendTo($sub, $message, $topic);
            $stats[$result]++;
        }
        return $stats;
    }

    /** @return 'sent'|'failed'|'removed' */
    public function sendTo(array $subscription, array $message, ?string $topic = null): string
    {
        try {
            // Texts are made by the service worker, in the device's language.
            $message['lang'] = $subscription['lang'] ?? 'nl';
            $payload = json_encode($message, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
            $res = $this->sender()->send($subscription, (string) $payload, 86400, 'normal', $topic);
        } catch (Throwable $e) {
            error_log('[postvak] push failed: ' . $e->getMessage());
            return 'failed';
        }
        if ($res['status'] >= 200 && $res['status'] < 300) {
            Database::run('UPDATE push_subscriptions SET last_success_at = NOW() WHERE id = ?', [$subscription['id']]);
            return 'sent';
        }
        // 404/410: the browser unsubscribed or the subscription expired.
        if (in_array($res['status'], [404, 410], true)) {
            Database::run('DELETE FROM push_subscriptions WHERE id = ?', [$subscription['id']]);
            return 'removed';
        }
        error_log("[postvak] push service answered {$res['status']}: {$res['body']}");
        return 'failed';
    }

    /** Only HTTPS endpoints on public hosts (the server makes requests to them). */
    public static function assertEndpoint(string $endpoint): void
    {
        $parts = parse_url($endpoint);
        $private = (bool) Config::get('allow_private_hosts', false);
        if (strlen($endpoint) > 1000 || !$parts || empty($parts['host']) || !in_array($parts['scheme'] ?? '', $private ? ['https', 'http'] : ['https'], true)) {
            throw HttpException::badRequest('Invalid push endpoint');
        }
        if ($private) {
            return;
        }
        foreach (gethostbynamel($parts['host']) ?: [] as $ip) {
            if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                throw HttpException::badRequest('Push endpoint not allowed');
            }
        }
    }
}
