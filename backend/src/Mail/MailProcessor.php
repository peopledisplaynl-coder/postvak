<?php
declare(strict_types=1);

namespace Postvak\Mail;

use Postvak\Database;
use Postvak\Http\HttpException;
use Throwable;

/**
 * Runs rules and out-of-office replies on new inbox mail. Called from cron (every few minutes) and from
 * "apply now" on the settings page. Shared hosting has no server-side hooks, so Postvak looks at what
 * arrived since the last run (per account: uidvalidity + last uid).
 */
final class MailProcessor
{
    private const BATCH = 200;

    /** Rules of a user, in order, decoded. @return array<int, array> */
    public static function rules(int $userId): array
    {
        return array_map(static fn ($r) => [
            'id'              => (int) $r['id'],
            'name'            => $r['name'],
            'account_id'      => $r['account_id'] !== null ? (int) $r['account_id'] : null,
            'enabled'         => (bool) $r['enabled'],
            'match_all'       => (bool) $r['match_all'],
            'conditions'      => json_decode((string) $r['conditions_json'], true) ?: [],
            'actions'         => (json_decode((string) $r['actions_json'], true) ?: []) + ['move' => null, 'trash' => false, 'read' => false, 'flag' => false, 'label' => null],
            'times_applied'   => (int) $r['times_applied'],
            'last_applied_at' => $r['last_applied_at'] ? gmdate(DATE_ATOM, (int) strtotime($r['last_applied_at'] . ' UTC')) : null,
        ], Database::all('SELECT * FROM mail_rules WHERE user_id = ? ORDER BY sort_order, id', [$userId]));
    }

    /** @param callable(string):void $log @return array{accounts:int, messages:int, matched:int, replies:int, errors:int} */
    public function run(callable $log): array
    {
        $stats = ['accounts' => 0, 'messages' => 0, 'matched' => 0, 'replies' => 0, 'errors' => 0];
        // Accounts with an enabled rule (for that account or for all accounts of its user) or an out-of-office reply.
        $accounts = Database::all(
            'SELECT a.* FROM accounts a
             WHERE EXISTS (SELECT 1 FROM mail_rules r WHERE r.user_id = a.user_id AND r.enabled = 1 AND (r.account_id IS NULL OR r.account_id = a.id))
                OR EXISTS (SELECT 1 FROM autoreplies x WHERE x.account_id = a.id AND x.enabled = 1)'
        );
        $rulesByUser = [];
        foreach ($accounts as $account) {
            $reply = AutoReply::settings((int) $account['id']);
            $rules = array_values(array_filter(
                $rulesByUser[$account['user_id']] ??= self::rules((int) $account['user_id']),
                static fn ($r) => $r['enabled'] && ($r['account_id'] === null || $r['account_id'] === (int) $account['id'])
            ));
            $replyOn = AutoReply::active($reply);
            if ($rules === [] && !$replyOn) {
                continue; // a reply set for later: nothing to do yet (and no starting point, so old mail is skipped)
            }
            $stats['accounts']++;
            $imap = null;
            try {
                $imap = new ImapService($account);
                [$validity, $next] = $imap->inboxState();
                $state = Database::one('SELECT * FROM mail_processing WHERE account_id = ?', [$account['id']]);
                if ($state === null || (int) $state['uidvalidity'] !== $validity) {
                    self::remember((int) $account['id'], $validity, $next - 1); // starting point: only new mail
                    continue;
                }
                $last = (int) $state['last_uid'];
                if ($next - 1 <= $last) {
                    continue;
                }
                $messages = $imap->arrivals($last, self::BATCH);
                if ($messages === []) {
                    self::remember((int) $account['id'], $validity, $next - 1);
                    continue;
                }
                $stats['messages'] += count($messages);
                $plan = RuleEngine::plan($rules, $messages);
                $this->execute($imap, $plan);
                $stats['matched'] += count($plan);

                if ($replyOn) {
                    $own = self::ownAddresses((int) $account['user_id']);
                    $lang = \Postvak\Calendar\CalendarService::language((int) $account['user_id']);
                    foreach ($messages as $msg) {
                        $fate = $plan[$msg['uid']] ?? null;
                        if ($fate !== null && ($fate['trash'] || self::isJunk($imap, $fate['move']))) {
                            continue;
                        }
                        if (AutoReply::eligible($msg, (string) $account['email'], $own) && AutoReply::maybeSend($account, $reply, $msg, $lang)) {
                            $stats['replies']++;
                        }
                    }
                }
                self::remember((int) $account['id'], $validity, max(array_column($messages, 'uid')));
                if ($plan !== [] || $stats['replies']) {
                    $log(sprintf('%s: %d new, %d by rules', $account['email'], count($messages), count($plan)));
                }
            } catch (Throwable $e) {
                $stats['errors']++;
                $log($account['email'] . ': ' . $e->getMessage());
            } finally {
                $imap?->disconnect();
            }
        }
        return $stats;
    }

    /**
     * "Apply now": run rules (one, or all) over the last messages in the inbox of the user's accounts.
     * @return array{messages:int, matched:int}
     */
    public function applyNow(int $userId, ?int $ruleId = null): array
    {
        $rules = array_values(array_filter(self::rules($userId), static fn ($r) => $ruleId === null ? $r['enabled'] : $r['id'] === $ruleId));
        if ($rules === []) {
            throw HttpException::notFound('Rule not found');
        }
        $result = ['messages' => 0, 'matched' => 0];
        foreach (Database::all('SELECT * FROM accounts WHERE user_id = ?', [$userId]) as $account) {
            $mine = array_values(array_filter($rules, static fn ($r) => $r['account_id'] === null || $r['account_id'] === (int) $account['id']));
            if ($mine === []) {
                continue;
            }
            // A rule that is switched off can still be tried by hand.
            $mine = array_map(static fn ($r) => ['enabled' => true] + $r, $mine);
            $imap = new ImapService($account);
            try {
                $messages = $imap->arrivals(null, 500);
                $plan = RuleEngine::plan($mine, $messages);
                $this->execute($imap, $plan);
                $result['messages'] += count($messages);
                $result['matched'] += count($plan);
            } finally {
                $imap->disconnect();
            }
        }
        return $result;
    }

    /** Carry out a plan: flags first (while the messages are still in the inbox), then the moves. */
    private function execute(ImapService $imap, array $plan): void
    {
        if ($plan === []) {
            return;
        }
        $read = array_keys(array_filter($plan, static fn ($p) => $p['read']));
        $flag = array_keys(array_filter($plan, static fn ($p) => $p['flag']));
        if ($read) {
            $imap->setFlags('INBOX', $read, ['seen' => true]);
        }
        if ($flag) {
            $imap->setFlags('INBOX', $flag, ['flagged' => true]);
        }
        $byLabel = [];
        foreach ($plan as $uid => $p) {
            foreach ($p['labels'] ?? [] as $label) {
                $byLabel[$label][] = $uid;
            }
        }
        foreach ($byLabel as $label => $uids) {
            $imap->setLabels('INBOX', $uids, [(string) $label], []);
        }
        $moves = [];
        foreach ($plan as $uid => $p) {
            $target = $p['trash'] ? $imap->roleFolder('trash') : $p['move'];
            if ($target) {
                $moves[$target][] = $uid;
            }
        }
        foreach ($moves as $target => $uids) {
            try {
                $imap->move('INBOX', $uids, (string) $target);
            } catch (Throwable $e) {
                error_log("[postvak] rule could not move to $target: " . $e->getMessage());
            }
        }
        // Counters per rule.
        $counts = [];
        foreach ($plan as $p) {
            foreach ($p['rules'] as $id) {
                $counts[$id] = ($counts[$id] ?? 0) + 1;
            }
        }
        foreach ($counts as $id => $n) {
            Database::run('UPDATE mail_rules SET times_applied = times_applied + ?, last_applied_at = ? WHERE id = ?', [$n, gmdate('Y-m-d H:i:s'), $id]);
        }
    }

    private static function isJunk(ImapService $imap, ?string $folder): bool
    {
        try {
            return $folder !== null && $folder === $imap->roleFolder('junk', false);
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Messages that came back to the inbox by Postvak itself (snooze) between uid $nextBefore and $nextAfter:
     * when the rules had already seen everything before them, skip them.
     */
    public static function skipReturned(int $accountId, int $nextBefore, int $nextAfter): void
    {
        Database::run(
            'UPDATE mail_processing SET last_uid = ? WHERE account_id = ? AND last_uid >= ?',
            [max(0, $nextAfter - 1), $accountId, max(0, $nextBefore - 1)]
        );
    }

    private static function remember(int $accountId, int $validity, int $lastUid): void
    {
        Database::run(
            'INSERT INTO mail_processing (account_id, uidvalidity, last_uid) VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE uidvalidity = VALUES(uidvalidity), last_uid = VALUES(last_uid)',
            [$accountId, $validity, max(0, $lastUid)]
        );
    }

    /** @return string[] */
    private static function ownAddresses(int $userId): array
    {
        $rows = Database::all('SELECT email FROM accounts WHERE user_id = ?', [$userId]);
        return array_map(static fn ($r) => strtolower((string) $r['email']), $rows);
    }
}
