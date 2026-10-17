<?php
declare(strict_types=1);

namespace Postvak\Repositories;

use Postvak\Database;
use Throwable;

/** Preview lines per message, so they are fetched from the mail server only once. */
final class SnippetCache
{
    public function __construct(private readonly int $accountId) {}

    /** @return array<int, string> uid => snippet */
    public function get(string $folder, int $uidvalidity, array $uids): array
    {
        if ($uids === []) {
            return [];
        }
        try {
            $in = implode(',', array_fill(0, count($uids), '?'));
            $rows = Database::all(
                "SELECT uid, snippet FROM message_snippets
                 WHERE account_id = ? AND folder_hash = ? AND uidvalidity = ? AND uid IN ($in)",
                [$this->accountId, sha1($folder), $uidvalidity, ...array_map('intval', $uids)]
            );
        } catch (Throwable) {
            return []; // before migration 003
        }
        return array_column($rows, 'snippet', 'uid');
    }

    /** @param array<int, string> $snippets uid => snippet */
    public function put(string $folder, int $uidvalidity, array $snippets): void
    {
        if ($snippets === []) {
            return;
        }
        try {
            foreach ($snippets as $uid => $snippet) {
                Database::run(
                    'REPLACE INTO message_snippets (account_id, folder_hash, uidvalidity, uid, snippet) VALUES (?, ?, ?, ?, ?)',
                    [$this->accountId, sha1($folder), $uidvalidity, (int) $uid, mb_substr($snippet, 0, 400)]
                );
            }
        } catch (Throwable) {
        }
    }
}
