<?php
declare(strict_types=1);

namespace Postvak\Repositories;

use Postvak\Database;
use Postvak\Http\HttpException;
use Throwable;

/** Senders and domains whose remote images are always shown, per user. */
final class TrustedSenderRepository
{
    public function forUser(int $userId): array
    {
        try {
            return array_map(static fn ($r) => ['id' => (int) $r['id'], 'kind' => $r['kind'], 'value' => $r['value'], 'created_at' => $r['created_at']],
                Database::all('SELECT * FROM trusted_senders WHERE user_id = ? ORDER BY kind, value', [$userId]));
        } catch (Throwable) {
            return []; // before migration 002
        }
    }

    public function add(int $userId, string $kind, string $value): array
    {
        $value = strtolower(trim($value));
        if ($kind === 'email' && !filter_var($value, FILTER_VALIDATE_EMAIL)) {
            throw HttpException::badRequest('Invalid address');
        }
        if ($kind === 'domain') {
            $value = ltrim($value, '@');
            if (!preg_match('/^[a-z0-9-]+(\.[a-z0-9-]+)+$/', $value)) {
                throw HttpException::badRequest('Invalid domain');
            }
        }
        if (!in_array($kind, ['email', 'domain'], true)) {
            throw HttpException::badRequest('Invalid kind');
        }
        Database::run('INSERT IGNORE INTO trusted_senders (user_id, kind, value) VALUES (?, ?, ?)', [$userId, $kind, $value]);
        $row = Database::one('SELECT * FROM trusted_senders WHERE user_id = ? AND kind = ? AND value = ?', [$userId, $kind, $value]);
        return ['id' => (int) $row['id'], 'kind' => $row['kind'], 'value' => $row['value'], 'created_at' => $row['created_at']];
    }

    public function remove(int $userId, int $id): void
    {
        Database::run('DELETE FROM trusted_senders WHERE id = ? AND user_id = ?', [$id, $userId]);
    }

    /** Is this address trusted, directly or through its domain (including subdomains)? */
    public function isTrusted(int $userId, ?string $email): bool
    {
        $email = strtolower(trim((string) $email));
        $at = strrpos($email, '@');
        if ($email === '' || $at === false) {
            return false;
        }
        $domain = substr($email, $at + 1);
        foreach ($this->forUser($userId) as $t) {
            if ($t['kind'] === 'email' && $t['value'] === $email) {
                return true;
            }
            if ($t['kind'] === 'domain' && ($domain === $t['value'] || str_ends_with($domain, '.' . $t['value']))) {
                return true;
            }
        }
        return false;
    }
}
