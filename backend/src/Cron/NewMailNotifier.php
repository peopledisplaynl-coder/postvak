<?php
declare(strict_types=1);

namespace Postvak\Cron;

use Postvak\Database;
use Postvak\Mail\ImapService;
use Postvak\Push\PushService;
use Throwable;

/**
 * Checks the inboxes of everyone with push notifications switched on and sends a
 * notification for new unread mail. Remembers the last seen uid per account, so a
 * message is announced once. The first check of an account only sets the starting point.
 */
final class NewMailNotifier
{
    private const MAX_PER_MESSAGE = 3; // more new messages than this: one summary notification

    public function __construct(private readonly PushService $push = new PushService()) {}

    /** @param callable(string):void $log  @return array{accounts:int, new:int, pushed:int, errors:int} */
    public function run(callable $log): array
    {
        $stats = ['accounts' => 0, 'new' => 0, 'pushed' => 0, 'errors' => 0];
        foreach ($this->push->usersWithSubscriptions() as $userId) {
            $accounts = Database::all('SELECT * FROM accounts WHERE user_id = ? ORDER BY sort_order, id', [$userId]);
            $several = count($accounts) > 1;
            foreach ($accounts as $account) {
                if (!(int) $account['notify']) {
                    continue;
                }
                $stats['accounts']++;
                $imap = null;
                try {
                    $imap = new ImapService($account);
                    [$validity, $next] = $imap->inboxState();
                    $last = $account['notify_last_uid'] !== null ? (int) $account['notify_last_uid'] : null;

                    if ($last === null || (int) $account['notify_uidvalidity'] !== $validity) {
                        $this->remember((int) $account['id'], $validity, $next - 1); // starting point
                        continue;
                    }
                    if ($next - 1 <= $last) {
                        continue;
                    }
                    $new = $imap->unseenSince($last, self::MAX_PER_MESSAGE);
                    $this->remember((int) $account['id'], $validity, $next - 1);
                    if ($new['total'] === 0) {
                        continue;
                    }
                    $stats['new'] += $new['total'];
                    $result = $this->push->sendToUser($userId, $this->message($account, $new, $several));
                    $stats['pushed'] += $result['sent'];
                    $log(sprintf('%s: %d new, pushed to %d device(s)', $account['email'], $new['total'], $result['sent']));
                } catch (Throwable $e) {
                    $stats['errors']++;
                    $log($account['email'] . ': ' . $e->getMessage());
                } finally {
                    $imap?->disconnect();
                }
            }
        }
        return $stats;
    }

    private function remember(int $accountId, int $validity, int $lastUid): void
    {
        Database::run('UPDATE accounts SET notify_uidvalidity = ?, notify_last_uid = ? WHERE id = ?', [$validity, max(0, $lastUid), $accountId]);
    }

    /** Compact payload; the service worker turns it into notifications (texts live there). */
    private function message(array $account, array $new, bool $several): array
    {
        $cut = static fn (?string $s, int $n) => mb_substr(trim((string) $s), 0, $n);
        return [
            'type'       => 'mail',
            'account_id' => (int) $account['id'],
            'account'    => $several ? ((string) ($account['display_name'] ?: $account['email'])) : null,
            'total'      => $new['total'],
            'messages'   => array_map(static fn ($m) => [
                'uid'     => $m['uid'],
                'from'    => $cut($m['from']['name'] ?? '', 80) ?: $cut($m['from']['email'] ?? '', 80),
                'subject' => $cut($m['subject'], 140),
                'snippet' => $cut($m['snippet'] ?? '', 140),
            ], $new['messages']),
        ];
    }
}
