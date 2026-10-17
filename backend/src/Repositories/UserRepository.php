<?php
declare(strict_types=1);

namespace Postvak\Repositories;

use Postvak\Database;

final class UserRepository
{
    public function findByLoginEmail(string $email): ?array
    {
        return Database::one('SELECT * FROM users WHERE login_email = ?', [strtolower($email)]);
    }

    public function find(int $id): ?array
    {
        return Database::one('SELECT id, login_email, display_name, created_at, last_login_at FROM users WHERE id = ?', [$id]);
    }

    public function create(string $email, ?string $displayName = null): int
    {
        Database::run('INSERT INTO users (login_email, display_name) VALUES (?, ?)', [strtolower($email), $displayName]);
        return Database::lastId();
    }

    public function touchLogin(int $id): void
    {
        Database::run('UPDATE users SET last_login_at = NOW() WHERE id = ?', [$id]);
    }
}
