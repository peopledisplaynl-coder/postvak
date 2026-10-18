<?php
declare(strict_types=1);

namespace Postvak\Mail;

use DateTimeImmutable;
use Postvak\Database;
use Throwable;

/**
 * Out-of-office replies (RFC 3834): at most one per sender per few days, never to mailing lists,
 * automatic mail, no-reply addresses or yourself, and only to mail sent to this account directly.
 */
final class AutoReply
{
    public const DAYS_BETWEEN = 4;

    /** Settings of an account (defaults when never saved). */
    public static function settings(int $accountId): array
    {
        $row = Database::one('SELECT * FROM autoreplies WHERE account_id = ?', [$accountId]);
        return [
            'enabled'       => (bool) ($row['enabled'] ?? false),
            'starts_on'     => $row['starts_on'] ?? null,
            'ends_on'       => $row['ends_on'] ?? null,
            'subject'       => (string) ($row['subject'] ?? ''),
            'body'          => (string) ($row['body'] ?? ''),
            'contacts_only' => (bool) ($row['contacts_only'] ?? false),
        ];
    }

    /** Is it switched on and is today inside the period? */
    public static function active(array $s, ?DateTimeImmutable $today = null): bool
    {
        if (!$s['enabled'] || trim($s['body']) === '') {
            return false;
        }
        $day = ($today ?? new DateTimeImmutable('today'))->format('Y-m-d');
        return ($s['starts_on'] === null || $day >= $s['starts_on']) && ($s['ends_on'] === null || $day <= $s['ends_on']);
    }

    /**
     * Should this message get a reply? Pure check on the headers (the once-per-sender limit is separate).
     * @param string[] $own all addresses of the user, lower case
     */
    public static function eligible(array $msg, string $accountEmail, array $own): bool
    {
        // Only fresh mail: after a pause (reply switched off for a while, cron down) old mail gets no reply.
        if (!empty($msg['date']) && strtotime((string) $msg['date']) < time() - 2 * 86400) {
            return false;
        }
        $sender = strtolower((string) ($msg['from']['email'] ?? ''));
        if ($sender === '' || !str_contains($sender, '@') || in_array($sender, $own, true)) {
            return false;
        }
        $local = strstr($sender, '@', true);
        if (preg_match('/^(no-?reply|do-?not-?reply|mailer-daemon|postmaster|bounce[s]?|notifications?|listserv|majordomo|owner-.*|.*-request)$/i', (string) $local)) {
            return false;
        }
        $raw = (string) ($msg['raw'] ?? '');
        $header = static fn (string $name) => preg_match('/^' . preg_quote($name, '/') . ':\s*([^\r\n]*)/mi', $raw, $m) ? strtolower(trim($m[1])) : null;
        if ($header('List-Id') !== null || $header('List-Unsubscribe') !== null || $header('List-Post') !== null) {
            return false;
        }
        if (in_array($header('Precedence'), ['bulk', 'list', 'junk'], true)) {
            return false;
        }
        $auto = $header('Auto-Submitted');
        if ($auto !== null && $auto !== 'no') {
            return false;
        }
        if ($header('X-Auto-Response-Suppress') !== null && preg_match('/\b(all|oof|autoreply)\b/', (string) $header('X-Auto-Response-Suppress'))) {
            return false;
        }
        // Only mail addressed to this account (not Bcc'd copies of a mass mailing).
        $to = array_map(static fn ($a) => strtolower((string) ($a['email'] ?? '')), [...($msg['to'] ?? []), ...($msg['cc'] ?? [])]);
        return in_array(strtolower($accountEmail), $to, true);
    }

    /** Send the reply when the sender has not had one in the last few days. @return bool sent */
    public static function maybeSend(array $account, array $settings, array $msg, string $lang): bool
    {
        $sender = strtolower((string) $msg['from']['email']);
        $hash = hash('sha256', $sender);
        $last = Database::one('SELECT sent_at FROM autoreply_log WHERE account_id = ? AND sender_hash = ?', [$account['id'], $hash]);
        if ($last && strtotime($last['sent_at'] . ' UTC') > time() - self::DAYS_BETWEEN * 86400) {
            return false;
        }
        if ($settings['contacts_only'] && !Database::one(
            'SELECT id FROM contacts WHERE user_id = ? AND saved = 1 AND (email = ? OR extra_emails LIKE ?)',
            [$account['user_id'], $sender, '%' . $sender . '%']
        )) {
            return false;
        }
        $original = trim((string) $msg['subject']);
        $subject = trim($settings['subject']) !== ''
            ? $settings['subject']
            : ($lang === 'nl' ? 'Automatisch antwoord' : 'Automatic reply') . ($original !== '' ? ': ' . $original : '');
        $headers = ['Auto-Submitted' => 'auto-replied', 'X-Auto-Response-Suppress' => 'All', 'Precedence' => 'auto_reply'];
        if (!empty($msg['message_id'])) {
            $headers['In-Reply-To'] = $msg['message_id'];
            $headers['References'] = $msg['message_id'];
        }
        try {
            SmtpService::send($account, [
                'to'      => [['email' => $sender, 'name' => (string) ($msg['from']['name'] ?? '')]],
                'subject' => mb_substr($subject, 0, 250),
                'html'    => nl2br(htmlspecialchars($settings['body'], ENT_QUOTES, 'UTF-8')),
                'headers' => $headers,
            ]);
        } catch (Throwable $e) {
            error_log('[postvak] out-of-office reply to ' . $sender . ' failed: ' . $e->getMessage());
            return false;
        }
        Database::run(
            'INSERT INTO autoreply_log (account_id, sender_hash, sent_at) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE sent_at = VALUES(sent_at)',
            [$account['id'], $hash, gmdate('Y-m-d H:i:s')]
        );
        return true;
    }
}
