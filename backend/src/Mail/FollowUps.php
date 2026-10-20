<?php
declare(strict_types=1);

namespace Postvak\Mail;

use Postvak\Database;
use Throwable;

/**
 * "Remind me if nobody answers" (like Gmail's nudges). When a message is sent with a reminder, its Message-ID is
 * stored. The cron job checks, once the time has come, whether a reply arrived (In-Reply-To / References in the
 * inbox or the archive). If not, a copy of the sent message is put back in the inbox, unread, starred and marked
 * with the keyword $followup, so the reader can offer "follow up".
 */
final class FollowUps
{
    public const KEYWORD = '$followup';
    public const MAX_DAYS = 30;

    /** Remember a sent message; $raw is the message as sent. */
    public static function add(int $accountId, string $raw, string $subject, int $days): void
    {
        $days = max(1, min(self::MAX_DAYS, $days));
        if (!preg_match('/^Message-ID:\s*(<[^>\s]+>)/mi', $raw, $m)) {
            return;
        }
        Database::run(
            'INSERT INTO followups (account_id, message_id, subject, remind_at) VALUES (?, ?, ?, ?)',
            [$accountId, $m[1], mb_substr($subject, 0, 500), gmdate('Y-m-d H:i:s', time() + $days * 86400)]
        );
    }

    /** @param callable(string):void $log @return array{checked:int, answered:int, reminded:int, errors:int} */
    public function runDue(callable $log): array
    {
        $stats = ['checked' => 0, 'answered' => 0, 'reminded' => 0, 'errors' => 0];
        $due = Database::all(
            "SELECT f.*, a.id AS acc_id FROM followups f JOIN accounts a ON a.id = f.account_id
             WHERE f.status = 'waiting' AND f.remind_at <= ? ORDER BY f.account_id, f.id LIMIT 50",
            [gmdate('Y-m-d H:i:s')]
        );
        $byAccount = [];
        foreach ($due as $row) {
            $byAccount[(int) $row['account_id']][] = $row;
        }
        foreach ($byAccount as $accountId => $rows) {
            $account = Database::one('SELECT * FROM accounts WHERE id = ?', [$accountId]);
            $imap = null;
            try {
                $imap = new ImapService($account);
                [, $nextBefore] = $imap->inboxState();
                $appended = false;
                foreach ($rows as $row) {
                    $stats['checked']++;
                    if ($this->answered($imap, (string) $row['message_id'])) {
                        Database::run("UPDATE followups SET status = 'answered' WHERE id = ?", [$row['id']]);
                        $stats['answered']++;
                        continue;
                    }
                    $raw = $this->sentCopy($imap, (string) $row['message_id']);
                    if ($raw !== null) {
                        $imap->append('INBOX', $raw, [self::KEYWORD, '\\Flagged']);
                        $appended = true;
                        $stats['reminded']++;
                        $log(sprintf('%s: no answer to "%s", back in the inbox', $account['email'], $row['subject']));
                    }
                    Database::run("UPDATE followups SET status = 'reminded' WHERE id = ?", [$row['id']]);
                }
                if ($appended) {
                    // Rules must not treat the copies as new mail.
                    MailProcessor::skipReturned($accountId, $nextBefore, $imap->inboxState()[1]);
                }
            } catch (Throwable $e) {
                $stats['errors']++;
                $log(($account['email'] ?? "account $accountId") . ': follow-up check failed: ' . $e->getMessage());
            } finally {
                $imap?->disconnect();
            }
        }
        return $stats;
    }

    /** Is there a reply in the inbox or the archive? */
    private function answered(ImapService $imap, string $messageId): bool
    {
        foreach (array_filter(['INBOX', $imap->roleFolder('archive', false)]) as $folder) {
            if ($imap->repliesTo((string) $folder, $messageId) !== []) {
                return true;
            }
        }
        return false;
    }

    private function sentCopy(ImapService $imap, string $messageId): ?string
    {
        $sent = $imap->roleFolder('sent', false);
        if (!$sent) {
            return null;
        }
        $uids = $imap->uidsByMessageId($sent, $messageId);
        return $uids !== [] ? $imap->fetchRaw($sent, $uids[0]) : null;
    }
}
