<?php
declare(strict_types=1);

namespace Postvak\Dav;

use Postvak\Crypto;
use Postvak\Database;

/**
 * Passwords for calendar apps (CalDAV). A phone gets its own random password instead of the mailbox
 * password: it can be revoked on its own and works with two-step verification turned on.
 * Only an HMAC of the password is stored; the password itself is shown once.
 */
final class AppPasswords
{
    /** @return array{id:int, password:string} */
    public static function create(int $userId, string $name): array
    {
        // 4 groups of 4 letters/digits without look-alikes: easy to type on a phone, ~80 bits.
        $alphabet = 'abcdefghjkmnpqrstuvwxyz23456789';
        $groups = [];
        for ($g = 0; $g < 4; $g++) {
            $part = '';
            for ($i = 0; $i < 4; $i++) {
                $part .= $alphabet[random_int(0, strlen($alphabet) - 1)];
            }
            $groups[] = $part;
        }
        $password = implode('-', $groups);
        Database::run(
            'INSERT INTO app_passwords (user_id, name, token_hash) VALUES (?, ?, ?)',
            [$userId, mb_substr(trim($name) ?: 'Device', 0, 100), self::hash($password)]
        );
        return ['id' => Database::lastId(), 'password' => $password];
    }

    /** @return array<int, array{id:int, name:string, created_at:string, last_used_at:?string}> */
    public static function forUser(int $userId): array
    {
        return array_map(static fn ($r) => ['id' => (int) $r['id'], 'name' => $r['name'], 'created_at' => $r['created_at'], 'last_used_at' => $r['last_used_at']],
            Database::all('SELECT id, name, created_at, last_used_at FROM app_passwords WHERE user_id = ? ORDER BY created_at', [$userId]));
    }

    public static function delete(int $userId, int $id): void
    {
        Database::run('DELETE FROM app_passwords WHERE id = ? AND user_id = ?', [$id, $userId]);
    }

    /** User id when the address and password belong together, otherwise null. */
    public static function verify(string $email, string $password): ?int
    {
        $row = Database::one(
            'SELECT p.id, p.user_id, p.last_used_at FROM app_passwords p JOIN users u ON u.id = p.user_id
             WHERE p.token_hash = ? AND u.login_email = ? AND u.blocked = 0',
            [self::hash($password), strtolower(trim($email))]
        );
        if ($row === null) {
            return null;
        }
        // Remember when it was last used (at most once an hour, every sync makes many requests).
        if ($row['last_used_at'] === null || strtotime((string) $row['last_used_at']) < time() - 3600) {
            Database::run('UPDATE app_passwords SET last_used_at = NOW() WHERE id = ?', [$row['id']]);
        }
        return (int) $row['user_id'];
    }

    private static function hash(string $password): string
    {
        // Apps sometimes show it with capitals or without dashes; accept both.
        return Crypto::hash('app-password:' . strtolower(str_replace([' ', '-'], '', $password)));
    }
}
