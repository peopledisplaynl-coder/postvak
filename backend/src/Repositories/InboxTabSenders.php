<?php
declare(strict_types=1);

namespace Postvak\Repositories;

use Postvak\Database;
use Postvak\Http\HttpException;
use Postvak\Mail\MessageInsights;

/** Senders the user moved to another inbox tab, per account: their mail goes to that tab from then on. */
final class InboxTabSenders
{
    /** Kept within what fits comfortably in one IMAP SEARCH. */
    public const MAX = 300;

    /** @return array<string, string> address => tab, newest first */
    public function map(int $accountId): array
    {
        $out = [];
        foreach (Database::all('SELECT sender, category FROM inbox_tab_senders WHERE account_id = ? ORDER BY id DESC LIMIT ' . self::MAX, [$accountId]) as $row) {
            $out[(string) $row['sender']] = (string) $row['category'];
        }
        return $out;
    }

    /** @return array[] {account_id, sender, category} of all the user's accounts */
    public function forUser(int $userId): array
    {
        return array_map(static fn ($r) => ['account_id' => (int) $r['account_id'], 'sender' => $r['sender'], 'category' => $r['category']], Database::all(
            'SELECT t.account_id, t.sender, t.category FROM inbox_tab_senders t JOIN accounts a ON a.id = t.account_id
             WHERE a.user_id = ? ORDER BY t.sender', [$userId]
        ));
    }

    public function set(int $accountId, string $sender, string $category): void
    {
        $sender = strtolower(trim($sender));
        if (!filter_var($sender, FILTER_VALIDATE_EMAIL) || strlen($sender) > 255) {
            throw HttpException::badRequest('Invalid sender');
        }
        if (!in_array($category, ['primary', ...MessageInsights::TABS], true)) {
            throw HttpException::badRequest('Invalid tab');
        }
        Database::run(
            'INSERT INTO inbox_tab_senders (account_id, sender, category) VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE category = VALUES(category)',
            [$accountId, $sender, $category]
        );
    }

    public function remove(int $accountId, string $sender): void
    {
        Database::run('DELETE FROM inbox_tab_senders WHERE account_id = ? AND sender = ?', [$accountId, strtolower(trim($sender))]);
    }
}
