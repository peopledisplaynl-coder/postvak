<?php
declare(strict_types=1);

namespace Postvak\Mail;

use Postvak\Database;
use Postvak\Http\HttpException;
use Throwable;

/**
 * Snooze: the message moves to the account's Snoozed folder and comes back to the
 * inbox, unread, at the chosen time (cron). Like Gmail it always returns to the inbox,
 * so the normal new-mail notification announces it.
 *
 * Messages are found again by Message-ID, because a MOVE gives them a new UID.
 */
final class Snoozer
{
    /** @param int[] $uids  @return int number snoozed */
    public function snooze(array $account, ImapService $imap, string $folder, array $uids, int $until): int
    {
        if ($until < time() + 60) {
            throw HttpException::badRequest('Choose a time in the future');
        }
        if ($until > time() + 366 * 86400) {
            throw HttpException::badRequest('Choose a time within a year');
        }
        $snoozed = (string) $imap->roleFolder('snoozed');
        $summaries = $imap->summaries($folder, $uids);
        $withId = array_values(array_filter($summaries, static fn ($m) => !empty($m['message_id'])));
        if ($withId === []) {
            throw HttpException::badRequest('These messages cannot be snoozed (no Message-ID)');
        }
        foreach ($withId as $m) {
            Database::run(
                'INSERT INTO snoozes (user_id, account_id, message_id, subject, until_at) VALUES (?, ?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE until_at = VALUES(until_at), subject = VALUES(subject)',
                [(int) $account['user_id'], (int) $account['id'], $m['message_id'], mb_substr((string) $m['subject'], 0, 255), gmdate('Y-m-d H:i:s', $until)]
            );
        }
        // Snoozing again from the Snoozed folder only changes the time.
        if ($folder !== $snoozed) {
            $imap->move($folder, array_column($withId, 'uid'), $snoozed);
        }
        return count($withId);
    }

    /** Back to the inbox now ("Nu terugzetten"). @param int[] $uids in the Snoozed folder */
    public function unsnooze(array $account, ImapService $imap, array $uids): int
    {
        $snoozed = (string) $imap->roleFolder('snoozed');
        $summaries = $imap->summaries($snoozed, $uids);
        foreach ($summaries as $m) {
            if ($m['message_id']) {
                Database::run('DELETE FROM snoozes WHERE account_id = ? AND message_id = ?', [(int) $account['id'], $m['message_id']]);
            }
        }
        $imap->move($snoozed, array_column($summaries, 'uid'), 'INBOX');
        return count($summaries);
    }

    /** until_at per Message-ID for an account (to show "back on …" in the list). */
    public function forAccount(int $accountId): array
    {
        $out = [];
        foreach (Database::all('SELECT message_id, until_at FROM snoozes WHERE account_id = ?', [$accountId]) as $row) {
            $out[$row['message_id']] = gmdate(DATE_ATOM, (int) strtotime($row['until_at'] . ' UTC'));
        }
        return $out;
    }

    /** Cron: return what is due, per account in one connection. @param callable(string):void $log */
    public function runDue(callable $log): array
    {
        $stats = ['returned' => 0, 'missing' => 0, 'errors' => 0];
        $due = Database::all('SELECT * FROM snoozes WHERE until_at <= ? ORDER BY account_id, until_at LIMIT 200', [gmdate('Y-m-d H:i:s')]);
        $byAccount = [];
        foreach ($due as $row) {
            $byAccount[$row['account_id']][] = $row;
        }
        foreach ($byAccount as $accountId => $rows) {
            $account = Database::one('SELECT * FROM accounts WHERE id = ?', [$accountId]);
            if (!$account) {
                continue;
            }
            $imap = null;
            try {
                $imap = new ImapService($account);
                [, $nextBefore] = $imap->inboxState();
                $snoozed = $imap->roleFolder('snoozed', false);
                foreach ($rows as $row) {
                    $uids = $snoozed ? $imap->uidsByMessageId($snoozed, $row['message_id']) : [];
                    if ($uids !== []) {
                        // Unread, so it stands out (and the notifier announces it).
                        $imap->setFlags($snoozed, $uids, ['seen' => false]);
                        $imap->move($snoozed, $uids, 'INBOX');
                        $stats['returned']++;
                    } else {
                        $stats['missing']++; // moved or deleted in the meantime
                    }
                    Database::run('DELETE FROM snoozes WHERE id = ?', [$row['id']]);
                }
                // Returned mail is not new mail: rules must not move it away again.
                MailProcessor::skipReturned((int) $accountId, $nextBefore, $imap->inboxState()[1]);
                $log(sprintf('%s: %d snoozed message(s) back', $account['email'], count($rows)));
            } catch (Throwable $e) {
                $stats['errors']++;
                $log($account['email'] . ': snooze failed: ' . $e->getMessage());
            } finally {
                $imap?->disconnect();
            }
        }
        return $stats;
    }
}
