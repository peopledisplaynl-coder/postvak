<?php
declare(strict_types=1);

namespace Postvak\Repositories;

use Postvak\Crypto;
use Postvak\Database;
use Postvak\Http\HttpException;
use Postvak\Mail\ServerSettings;

final class AccountRepository
{
    /** Columns a user may change via the API. */
    public const EDITABLE = [
        'display_name', 'color', 'sort_order',
        'imap_host', 'imap_port', 'imap_encryption',
        'smtp_host', 'smtp_port', 'smtp_encryption', 'username',
        'archive_folder', 'trash_folder', 'sent_folder', 'drafts_folder', 'junk_folder',
        'signature_html',
    ];

    public function forUser(int $userId): array
    {
        return Database::all('SELECT * FROM accounts WHERE user_id = ? ORDER BY sort_order, id', [$userId]);
    }

    /** Load an account and make sure it belongs to the user. */
    public function findOwned(int $userId, int $accountId): array
    {
        $row = Database::one('SELECT * FROM accounts WHERE id = ? AND user_id = ?', [$accountId, $userId]);
        if ($row === null) {
            throw HttpException::notFound('Account not found');
        }
        return $row;
    }

    public function findByEmail(int $userId, string $email): ?array
    {
        return Database::one('SELECT * FROM accounts WHERE user_id = ? AND email = ?', [$userId, strtolower($email)]);
    }

    public function create(int $userId, string $email, string $password, ServerSettings $s, bool $isLogin, ?string $displayName = null): int
    {
        $sort = (int) (Database::one('SELECT COALESCE(MAX(sort_order), -1) + 1 n FROM accounts WHERE user_id = ?', [$userId])['n'] ?? 0);
        Database::run(
            'INSERT INTO accounts (user_id, email, display_name, is_login, sort_order,
                imap_host, imap_port, imap_encryption, smtp_host, smtp_port, smtp_encryption,
                username, password_enc)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)',
            [
                $userId, strtolower($email), $displayName, $isLogin ? 1 : 0, $sort,
                $s->imapHost, $s->imapPort, $s->imapEncryption,
                $s->smtpHost, $s->smtpPort, $s->smtpEncryption,
                $s->username, Crypto::encrypt($password),
            ]
        );
        return Database::lastId();
    }

    public function updatePassword(int $accountId, string $password): void
    {
        Database::run('UPDATE accounts SET password_enc = ? WHERE id = ?', [Crypto::encrypt($password), $accountId]);
    }

    public function update(int $accountId, array $fields): void
    {
        $fields = array_intersect_key($fields, array_flip(self::EDITABLE));
        if ($fields === []) {
            return;
        }
        $sets = implode(', ', array_map(static fn ($c) => "$c = ?", array_keys($fields)));
        $values = array_map(static fn ($v) => $v === '' ? null : $v, array_values($fields));
        Database::run("UPDATE accounts SET $sets WHERE id = ?", [...$values, $accountId]);
    }

    public function delete(int $accountId): void
    {
        Database::run('DELETE FROM accounts WHERE id = ?', [$accountId]);
    }

    /** Safe representation for the frontend (no password). */
    public static function toPublic(array $row): array
    {
        unset($row['password_enc'], $row['user_id']);
        $row['id'] = (int) $row['id'];
        $row['is_login'] = (bool) $row['is_login'];
        $row['sort_order'] = (int) $row['sort_order'];
        $row['imap_port'] = (int) $row['imap_port'];
        $row['smtp_port'] = (int) $row['smtp_port'];
        return $row;
    }
}
