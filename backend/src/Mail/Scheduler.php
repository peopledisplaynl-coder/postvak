<?php
declare(strict_types=1);

namespace Postvak\Mail;

use Postvak\Database;
use Postvak\Http\HttpException;
use Postvak\Push\PushService;
use Postvak\Repositories\ContactRepository;
use Throwable;

/**
 * "Send later". The message is built right away and stored in the account's Scheduled
 * folder (so attachments live in the mailbox, not in the database); a row in
 * scheduled_sends holds the time and the envelope. The cron job sends it as it is.
 */
final class Scheduler
{
    public const MAX_ATTEMPTS = 3;

    /**
     * @param array $mail validated mail (to/cc/bcc/subject/html/…)
     * @param ?array{folder:string, uid:int} $replyRef message that is answered (gets \Answered when sent)
     */
    public function schedule(array $account, ImapService $imap, array $mail, array $attachments, int $sendAt, ?array $replyRef): array
    {
        if ($sendAt < time() + 60) {
            throw HttpException::badRequest('Choose a time in the future');
        }
        if ($sendAt > time() + 366 * 86400) {
            throw HttpException::badRequest('Choose a time within a year');
        }
        $token = bin2hex(random_bytes(12));
        $raw = SmtpService::buildDraft($account, $mail, $attachments, $token);
        $raw = 'X-Postvak-Send-At: ' . gmdate(DATE_ATOM, $sendAt) . "\r\n" . $raw;
        $folder = (string) $imap->roleFolder('scheduled');
        $imap->append($folder, $raw, ['\\Seen']);

        $recipients = array_values(array_unique(array_map(
            static fn ($r) => strtolower($r['email']),
            [...$mail['to'], ...($mail['cc'] ?? []), ...($mail['bcc'] ?? [])]
        )));
        Database::run(
            'INSERT INTO scheduled_sends (user_id, account_id, token, message_id, send_at, recipients, subject, reply_ref) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
            [
                (int) $account['user_id'], (int) $account['id'], $token,
                preg_match('/^Message-ID:\s*(<[^>\s]+>)/mi', $raw, $m) ? $m[1] : null,
                gmdate('Y-m-d H:i:s', $sendAt), json_encode($recipients), mb_substr($mail['subject'], 0, 255),
                $replyRef ? json_encode($replyRef) : null,
            ]
        );
        return $this->find((int) $account['user_id'], Database::lastId());
    }

    public function find(int $userId, int $id): array
    {
        $row = Database::one('SELECT * FROM scheduled_sends WHERE id = ? AND user_id = ?', [$id, $userId]);
        if (!$row) {
            throw HttpException::notFound('Scheduled message not found');
        }
        return self::shape($row);
    }

    /** Scheduled rows of an account by Message-ID (to show the time in the Scheduled folder). */
    public function forAccount(int $accountId): array
    {
        $out = [];
        foreach (Database::all('SELECT * FROM scheduled_sends WHERE account_id = ?', [$accountId]) as $row) {
            if ($row['message_id']) {
                $out[$row['message_id']] = self::shape($row);
            }
        }
        return $out;
    }

    /** Cancel: the message goes back to Drafts (to edit or send yourself). Returns where it is now. */
    public function cancel(int $userId, int $id): array
    {
        $row = Database::one('SELECT * FROM scheduled_sends WHERE id = ? AND user_id = ?', [$id, $userId]);
        if (!$row) {
            throw HttpException::notFound('Scheduled message not found');
        }
        $account = Database::one('SELECT * FROM accounts WHERE id = ?', [$row['account_id']]);
        $imap = new ImapService($account);
        try {
            $draft = $this->moveToDrafts($imap, $row);
        } finally {
            $imap->disconnect();
        }
        Database::run('DELETE FROM scheduled_sends WHERE id = ?', [$id]);
        return ['account_id' => (int) $row['account_id'], ...$draft];
    }

    /** Send one now (button "Nu versturen"). */
    public function sendNow(int $userId, int $id): void
    {
        $row = Database::one('SELECT * FROM scheduled_sends WHERE id = ? AND user_id = ?', [$id, $userId]);
        if (!$row) {
            throw HttpException::notFound('Scheduled message not found');
        }
        $this->deliver($row);
    }

    /** Cron: send everything that is due. @param callable(string):void $log */
    public function runDue(callable $log): array
    {
        $stats = ['sent' => 0, 'failed' => 0];
        $due = Database::all('SELECT * FROM scheduled_sends WHERE send_at <= ? AND attempts < ? ORDER BY send_at LIMIT 50', [gmdate('Y-m-d H:i:s'), self::MAX_ATTEMPTS]);
        foreach ($due as $row) {
            try {
                $this->deliver($row);
                $stats['sent']++;
                $log("sent scheduled #{$row['id']} ({$row['subject']})");
            } catch (Throwable $e) {
                $stats['failed']++;
                $this->failed($row, $e, $log);
            }
        }
        return $stats;
    }

    /** Fetch from the Scheduled folder, send, store in Sent, clean up. */
    private function deliver(array $row): void
    {
        $account = Database::one('SELECT * FROM accounts WHERE id = ?', [$row['account_id']]);
        if (!$account) {
            Database::run('DELETE FROM scheduled_sends WHERE id = ?', [$row['id']]);
            return;
        }
        $imap = new ImapService($account);
        try {
            $folder = (string) $imap->roleFolder('scheduled');
            $uid = $imap->findUidByHeader($folder, SmtpService::DRAFT_HEADER, $row['token']);
            if ($uid === null) {
                // Removed or moved by hand in another mail program: nothing to send.
                Database::run('DELETE FROM scheduled_sends WHERE id = ?', [$row['id']]);
                throw new \RuntimeException('message is no longer in the Scheduled folder');
            }
            $raw = $imap->fetchRaw($folder, $uid);
            $sent = SmtpService::sendRaw($account, $raw, (array) json_decode((string) $row['recipients'], true));
            Database::run('DELETE FROM scheduled_sends WHERE id = ?', [$row['id']]);

            try {
                $imap->deletePermanently($folder, [$uid]);
            } catch (Throwable $e) {
                error_log('[postvak] could not remove sent scheduled message: ' . $e->getMessage());
            }
            try {
                if ($sentFolder = $imap->roleFolder('sent')) {
                    $imap->append($sentFolder, $sent);
                }
            } catch (Throwable $e) {
                error_log('[postvak] could not store sent copy: ' . $e->getMessage());
            }
            $ref = $row['reply_ref'] ? json_decode((string) $row['reply_ref'], true) : null;
            if (is_array($ref) && !empty($ref['folder']) && !empty($ref['uid'])) {
                try {
                    $imap->setFlags((string) $ref['folder'], [(int) $ref['uid']], ['answered' => true]);
                } catch (Throwable) {
                }
            }
            ContactRepository::remember((int) $row['user_id'], array_map(static fn ($e) => ['email' => $e], (array) json_decode((string) $row['recipients'], true)));
        } finally {
            $imap->disconnect();
        }
    }

    private function failed(array $row, Throwable $e, callable $log): void
    {
        $attempts = (int) $row['attempts'] + 1;
        $log("scheduled #{$row['id']} failed (attempt $attempts): " . $e->getMessage());
        if (!Database::one('SELECT id FROM scheduled_sends WHERE id = ?', [$row['id']])) {
            return; // already removed (message gone)
        }
        if ($attempts < self::MAX_ATTEMPTS) {
            // Try again in about 10 minutes.
            Database::run(
                'UPDATE scheduled_sends SET attempts = ?, last_error = ?, send_at = ? WHERE id = ?',
                [$attempts, mb_substr($e->getMessage(), 0, 500), gmdate('Y-m-d H:i:s', time() + 600), $row['id']]
            );
            return;
        }
        // Give up: back to Drafts, and tell the user.
        $url = 'mail/' . $row['account_id'];
        try {
            $account = Database::one('SELECT * FROM accounts WHERE id = ?', [$row['account_id']]);
            $imap = new ImapService($account);
            try {
                $draft = $this->moveToDrafts($imap, $row);
                $url .= '?folder=' . rawurlencode($draft['folder']);
            } finally {
                $imap->disconnect();
            }
        } catch (Throwable $inner) {
            $log('could not move failed message to Drafts: ' . $inner->getMessage());
        }
        Database::run('DELETE FROM scheduled_sends WHERE id = ?', [$row['id']]);
        try {
            (new PushService())->sendToUser((int) $row['user_id'], [
                'type'    => 'notice',
                'kind'    => 'send_failed',
                'subject' => (string) $row['subject'],
                'error'   => mb_substr($e->getMessage(), 0, 200),
                'url'     => $url,
            ]);
        } catch (Throwable) {
        }
    }

    /** @return array{folder:string, uid:?int} */
    private function moveToDrafts(ImapService $imap, array $row): array
    {
        $scheduled = (string) $imap->roleFolder('scheduled');
        $drafts = (string) $imap->roleFolder('drafts');
        $uid = $imap->findUidByHeader($scheduled, SmtpService::DRAFT_HEADER, $row['token']);
        if ($uid === null) {
            throw HttpException::notFound('The message is no longer in the Scheduled folder');
        }
        $imap->move($scheduled, [$uid], $drafts);
        // Mark it as a draft again (it was \Seen only) so other mail programs show it as one.
        $newUid = $imap->findUidByHeader($drafts, SmtpService::DRAFT_HEADER, $row['token']);
        if ($newUid !== null) {
            try {
                $imap->setFlags($drafts, [$newUid], ['draft' => true]);
            } catch (Throwable) {
            }
        }
        return ['folder' => $drafts, 'uid' => $newUid];
    }

    private static function shape(array $row): array
    {
        return [
            'id'         => (int) $row['id'],
            'account_id' => (int) $row['account_id'],
            'send_at'    => gmdate(DATE_ATOM, (int) strtotime($row['send_at'] . ' UTC')),
            'subject'    => $row['subject'],
            'recipients' => (array) json_decode((string) $row['recipients'], true),
            'attempts'   => (int) $row['attempts'],
            'last_error' => $row['last_error'],
        ];
    }
}
