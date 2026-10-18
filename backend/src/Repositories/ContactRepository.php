<?php
declare(strict_types=1);

namespace Postvak\Repositories;

use PDOException;
use Postvak\Database;
use Postvak\Http\HttpException;

/**
 * Address book. Rows are either collected automatically when sending (saved = 0)
 * or added/edited/imported by the user (saved = 1). One row per e-mail address.
 */
final class ContactRepository
{
    private const COLUMNS = 'id, email, name, phone, notes, favorite, saved, times_used, last_used_at';
    public const MAX_CONTACTS = 5000;

    /** Remember addresses you send to (for autocomplete); does not touch saved details. */
    public static function remember(int $userId, array $recipients): void
    {
        foreach ($recipients as $r) {
            Database::run(
                'INSERT INTO contacts (user_id, email, name, times_used, last_used_at) VALUES (?, ?, ?, 1, NOW())
                 ON DUPLICATE KEY UPDATE times_used = times_used + 1, last_used_at = NOW(),
                   name = COALESCE(NULLIF(VALUES(name), \'\'), name)',
                [$userId, strtolower($r['email']), ($r['name'] ?? '') ?: null]
            );
        }
    }

    /** Address autocomplete for the composer. */
    public function suggest(int $userId, string $q, int $limit = 10): array
    {
        $like = '%' . self::escapeLike(trim($q)) . '%';
        return Database::all(
            'SELECT email, name FROM contacts WHERE user_id = ? AND (email LIKE ? OR name LIKE ?)
             ORDER BY favorite DESC, saved DESC, times_used DESC, last_used_at DESC LIMIT ' . max(1, min(25, $limit)),
            [$userId, $like, $like]
        );
    }

    /** All contacts for the contacts page; $savedOnly hides the automatically collected ones. */
    public function list(int $userId, string $q = '', bool $savedOnly = false): array
    {
        $sql = 'SELECT ' . self::COLUMNS . ' FROM contacts WHERE user_id = ?';
        $params = [$userId];
        if ($savedOnly) {
            $sql .= ' AND saved = 1';
        }
        if (trim($q) !== '') {
            $like = '%' . self::escapeLike(trim($q)) . '%';
            $sql .= ' AND (email LIKE ? OR name LIKE ? OR phone LIKE ?)';
            array_push($params, $like, $like, $like);
        }
        $sql .= ' ORDER BY COALESCE(NULLIF(name, \'\'), email) LIMIT ' . self::MAX_CONTACTS;
        return array_map([self::class, 'shape'], Database::all($sql, $params));
    }

    public function find(int $userId, int $id): array
    {
        $row = Database::one('SELECT ' . self::COLUMNS . ' FROM contacts WHERE id = ? AND user_id = ?', [$id, $userId]);
        if (!$row) {
            throw HttpException::notFound('Contact not found');
        }
        return self::shape($row);
    }

    public function findByEmail(int $userId, string $email): ?array
    {
        $row = Database::one('SELECT ' . self::COLUMNS . ' FROM contacts WHERE user_id = ? AND email = ?', [$userId, strtolower($email)]);
        return $row ? self::shape($row) : null;
    }

    /**
     * Add a contact, or turn an automatically collected one into a saved one.
     * @param array{email:string, name?:?string, phone?:?string, notes?:?string, favorite?:bool} $data validated
     */
    public function save(int $userId, array $data): array
    {
        $existing = $this->findByEmail($userId, $data['email']);
        if ($existing && $existing['saved']) {
            throw new HttpException(409, 'This address is already in your contacts');
        }
        if ($existing) {
            return $this->update($userId, $existing['id'], $data);
        }
        $this->assertRoom($userId);
        Database::run(
            'INSERT INTO contacts (user_id, email, name, phone, notes, favorite, saved) VALUES (?, ?, ?, ?, ?, ?, 1)',
            [$userId, $data['email'], $data['name'] ?? null, $data['phone'] ?? null, $data['notes'] ?? null, (int) ($data['favorite'] ?? false)]
        );
        return $this->find($userId, Database::lastId());
    }

    /** @param array<string, mixed> $data validated, only the given fields change */
    public function update(int $userId, int $id, array $data): array
    {
        $this->find($userId, $id);
        $sets = ['saved = 1'];
        $params = [];
        foreach (['email', 'name', 'phone', 'notes', 'favorite'] as $field) {
            if (array_key_exists($field, $data)) {
                $sets[] = "$field = ?";
                $params[] = $field === 'favorite' ? (int) $data[$field] : $data[$field];
            }
        }
        $params[] = $id;
        $params[] = $userId;
        try {
            Database::run('UPDATE contacts SET ' . implode(', ', $sets) . ' WHERE id = ? AND user_id = ?', $params);
        } catch (PDOException $e) {
            if ((int) ($e->errorInfo[1] ?? 0) === 1062) {
                throw new HttpException(409, 'This address is already in your contacts');
            }
            throw $e;
        }
        return $this->find($userId, $id);
    }

    public function delete(int $userId, int $id): void
    {
        $this->find($userId, $id);
        Database::run('DELETE FROM contacts WHERE id = ? AND user_id = ?', [$id, $userId]);
    }

    /**
     * Import parsed vCards. Existing addresses are marked as saved and only get the
     * fields that are still empty; nothing the user typed is overwritten.
     *
     * @param array<int, array{name:string, emails:string[], phone:string, notes:string}> $cards
     * @return array{added:int, updated:int, skipped:int}
     */
    public function import(int $userId, array $cards): array
    {
        $added = $updated = $skipped = 0;
        $count = (int) (Database::one('SELECT COUNT(*) AS n FROM contacts WHERE user_id = ?', [$userId])['n'] ?? 0);
        $pdo = Database::pdo();
        $pdo->beginTransaction();
        try {
            foreach ($cards as $card) {
                if ($card['emails'] === []) {
                    $skipped++;
                    continue;
                }
                foreach ($card['emails'] as $email) {
                    $existing = $this->findByEmail($userId, $email);
                    if ($existing) {
                        Database::run(
                            // MySQL applies SET left to right, so "saved = 1" must come last.
                            'UPDATE contacts SET
                               name  = IF(saved = 1 AND name  IS NOT NULL AND name  <> \'\', name,  COALESCE(NULLIF(?, \'\'), name)),
                               phone = IF(phone IS NOT NULL AND phone <> \'\', phone, NULLIF(?, \'\')),
                               notes = IF(notes IS NOT NULL AND notes <> \'\', notes, NULLIF(?, \'\')),
                               saved = 1
                             WHERE id = ?',
                            [$card['name'], $card['phone'], $card['notes'], $existing['id']]
                        );
                        $updated++;
                        continue;
                    }
                    if ($count >= self::MAX_CONTACTS) {
                        $skipped++;
                        continue;
                    }
                    Database::run(
                        'INSERT INTO contacts (user_id, email, name, phone, notes, saved) VALUES (?, ?, NULLIF(?, \'\'), NULLIF(?, \'\'), NULLIF(?, \'\'), 1)',
                        [$userId, $email, $card['name'], $card['phone'], $card['notes']]
                    );
                    $count++;
                    $added++;
                }
            }
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
        return ['added' => $added, 'updated' => $updated, 'skipped' => $skipped];
    }

    private function assertRoom(int $userId): void
    {
        $n = (int) (Database::one('SELECT COUNT(*) AS n FROM contacts WHERE user_id = ?', [$userId])['n'] ?? 0);
        if ($n >= self::MAX_CONTACTS) {
            throw HttpException::badRequest('Too many contacts (max ' . self::MAX_CONTACTS . ')');
        }
    }

    private static function shape(array $row): array
    {
        return [
            'id'           => (int) $row['id'],
            'email'        => $row['email'],
            'name'         => $row['name'] ?? '',
            'phone'        => $row['phone'] ?? '',
            'notes'        => $row['notes'] ?? '',
            'favorite'     => (bool) $row['favorite'],
            'saved'        => (bool) $row['saved'],
            'times_used'   => (int) $row['times_used'],
            'last_used_at' => $row['last_used_at'] ? date(DATE_ATOM, (int) strtotime((string) $row['last_used_at'])) : null,
        ];
    }

    private static function escapeLike(string $s): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $s);
    }
}
