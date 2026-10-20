<?php
declare(strict_types=1);

namespace Postvak\Mail;

use Postvak\Database;

/**
 * "Ignore conversation": new replies in a muted conversation are archived (and marked read) by the cron job.
 * A conversation is recognised by the Message-IDs in Message-ID / In-Reply-To / References.
 */
final class MutedThreads
{
    /** @return string[] the Message-IDs a message refers to (its own included), oldest first */
    public static function ids(string $rawHeader): array
    {
        $ids = [];
        foreach (['References', 'In-Reply-To', 'Message-ID'] as $name) {
            foreach (MessageInsights::headers($rawHeader, $name) as $value) {
                preg_match_all('/<[^<>\s]+>/', $value, $m);
                foreach ($m[0] as $id) {
                    $ids[strtolower($id)] = true;
                }
            }
        }
        return array_keys($ids);
    }

    public function isMuted(int $accountId, string $rawHeader): bool
    {
        $ids = self::ids($rawHeader);
        if ($ids === []) {
            return false;
        }
        $marks = implode(',', array_fill(0, count($ids), '?'));
        return Database::one("SELECT id FROM muted_threads WHERE account_id = ? AND message_id IN ($marks) LIMIT 1", [$accountId, ...$ids]) !== null;
    }

    /** Mute: remember every id of this message (so a reply to any message in the conversation matches). */
    public function mute(int $accountId, string $rawHeader): void
    {
        foreach (array_slice(self::ids($rawHeader), -50) as $id) {
            Database::run('INSERT IGNORE INTO muted_threads (account_id, message_id) VALUES (?, ?)', [$accountId, mb_substr($id, 0, 255)]);
        }
    }

    public function unmute(int $accountId, string $rawHeader): void
    {
        $ids = self::ids($rawHeader);
        if ($ids === []) {
            return;
        }
        $marks = implode(',', array_fill(0, count($ids), '?'));
        Database::run("DELETE FROM muted_threads WHERE account_id = ? AND message_id IN ($marks)", [$accountId, ...$ids]);
    }

    /** Remember the ids of a new reply too, so the conversation stays muted as it grows. */
    public function extend(int $accountId, string $rawHeader): void
    {
        $this->mute($accountId, $rawHeader);
    }
}
