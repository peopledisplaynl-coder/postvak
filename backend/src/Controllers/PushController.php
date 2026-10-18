<?php
declare(strict_types=1);

namespace Postvak\Controllers;

use Postvak\Auth;
use Postvak\Config;
use Postvak\Cron\CronRunner;
use Postvak\Http\HttpException;
use Postvak\Http\Request;
use Postvak\Http\Response;
use Postvak\Push\PushService;

/** Push notifications: VAPID key, subscribe/unsubscribe this device, test message, cron via URL. */
final class PushController
{
    public function __construct(private readonly PushService $push = new PushService()) {}

    /** GET /push — what the settings page needs. */
    public function show(Request $r): void
    {
        $userId = Auth::requireUser();
        $last = CronRunner::lastRun();
        Response::json([
            'public_key' => $this->push->publicKey(),
            'devices'    => $this->push->countForUser($userId),
            'cron'       => [
                'last_run' => $last['at'] ?? null,
                // Notifications only arrive when the cron job runs regularly.
                'ok'       => $last !== null && strtotime((string) $last['at']) > time() - 30 * 60,
            ],
        ]);
    }

    /** POST /push/subscriptions  {endpoint, keys: {p256dh, auth}, lang?} — the browser's PushSubscription.toJSON() */
    public function subscribe(Request $r): void
    {
        $userId = Auth::requireUser();
        $keys = $r->input('keys');
        if (!is_array($keys) || !is_string($keys['p256dh'] ?? null) || !is_string($keys['auth'] ?? null)) {
            throw HttpException::badRequest('Invalid subscription');
        }
        $lang = $r->input('lang');
        $this->push->subscribe($userId, $r->string('endpoint'), $keys['p256dh'], $keys['auth'], $r->header('User-Agent'), is_string($lang) ? $lang : null);
        Response::json(['devices' => $this->push->countForUser($userId)], 201);
    }

    /** POST /push/unsubscribe  {endpoint} */
    public function unsubscribe(Request $r): void
    {
        $userId = Auth::requireUser();
        $this->push->unsubscribe($userId, $r->string('endpoint'));
        Response::json(['devices' => $this->push->countForUser($userId)]);
    }

    /** POST /push/test  {endpoint} — send a test notification to this device right away. */
    public function test(Request $r): void
    {
        $userId = Auth::requireUser();
        $sub = $this->push->find($userId, $r->string('endpoint'));
        if (!$sub) {
            throw HttpException::notFound('This device is not registered for notifications');
        }
        $result = $this->push->sendTo($sub, ['type' => 'test']);
        if ($result !== 'sent') {
            throw HttpException::upstream($result === 'removed' ? 'The browser no longer accepts notifications; switch them on again' : 'The push service did not accept the message');
        }
        Response::json(['sent' => true]);
    }

    /** GET /cron?key=… — for hosts that can only call a URL. Needs 'cron_key' in config.php. */
    public function cron(Request $r): void
    {
        $key = (string) Config::get('cron_key', '');
        if (strlen($key) < 16 || !hash_equals($key, (string) $r->query('key', ''))) {
            throw HttpException::forbidden('Invalid cron key');
        }
        session_write_close();
        @set_time_limit(240);
        $log = [];
        $result = CronRunner::run(static function (string $line) use (&$log): void {
            $log[] = $line;
        });
        Response::json(['result' => $result, 'log' => $log]);
    }
}
