<?php
declare(strict_types=1);

namespace Postvak\Repositories;

use PDOException;
use Postvak\Contacts\ContactCards;
use Postvak\Contacts\ContactDetails;
use Postvak\Database;
use Postvak\Http\HttpException;

/**
 * Address book. Rows are either collected automatically when sending (saved = 0)
 * or added/edited/imported by the user (saved = 1). One row per e-mail address.
 */
final class ContactRepository
{
    private const COLUMNS = 'id, email, name, phone, notes, details, favorite, saved, times_used, last_used_at, extra_emails, card_uri, vcard, updated_at,
        (photo IS NOT NULL OR (vcard IS NOT NULL AND vcard LIKE \'%PHOTO%\')) AS has_photo';
    public const MAX_CONTACTS = 5000;

    /** Remember addresses you send to (for autocomplete); does not touch saved details. */
    public static function remember(int $userId, array $recipients): void
    {
        foreach ($recipients as $r) {
            Database::run(
                'INSERT INTO contacts (user_id, email, name, times_used, last_used_at) VALUES (?, ?, ?, 1, NOW())
                 ON DUPLICATE KEY UPDATE times_used = times_used + 1, last_used_at = NOW(),
                   name = IF(saved = 1, name, COALESCE(NULLIF(VALUES(name), \'\'), name))',
                [$userId, strtolower($r['email']), ($r['name'] ?? '') ?: null]
            );
        }
    }

    /**
     * Address autocomplete for the composer: contact groups whose name matches ({group, members}) first, then
     * addresses ({email, name}).
     */
    public function suggest(int $userId, string $q, int $limit = 10): array
    {
        $like = '%' . self::escapeLike(trim($q)) . '%';
        $people = Database::all(
            'SELECT email, name FROM contacts WHERE user_id = ? AND email IS NOT NULL AND (email LIKE ? OR name LIKE ?)
             ORDER BY favorite DESC, saved DESC, times_used DESC, last_used_at DESC LIMIT ' . max(1, min(25, $limit)),
            [$userId, $like, $like]
        );
        return [...$this->groupSuggestions($userId, trim($q)), ...$people];
    }

    /** Groups (from the contacts' details) whose name contains $q, with the members that have an address. */
    private function groupSuggestions(int $userId, string $q): array
    {
        if (mb_strlen($q) < 2) {
            return [];
        }
        $groups = [];
        $rows = Database::all(
            'SELECT email, name, details FROM contacts WHERE user_id = ? AND saved = 1 AND email IS NOT NULL AND details LIKE ?',
            [$userId, '%' . self::escapeLike($q) . '%']
        );
        foreach ($rows as $row) {
            $d = json_decode((string) $row['details'], true);
            foreach ((array) ($d['groups'] ?? []) as $g) {
                if (is_string($g) && mb_stripos($g, $q) !== false) {
                    $key = mb_strtolower($g);
                    $groups[$key] ??= ['group' => $g, 'members' => []];
                    $groups[$key]['members'][] = ['email' => (string) $row['email'], 'name' => (string) ($row['name'] ?? '')];
                }
            }
        }
        return array_slice(array_values($groups), 0, 3);
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
            $sql .= ' AND (email LIKE ? OR name LIKE ? OR phone LIKE ? OR extra_emails LIKE ? OR details LIKE ?)';
            array_push($params, $like, $like, $like, $like, $like);
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
        $data = self::withDetails($data);
        $existing = !empty($data['email']) ? $this->findByEmail($userId, $data['email']) : null;
        if ($existing && $existing['saved']) {
            throw new HttpException(409, 'This address is already in your contacts');
        }
        if ($existing) {
            return $this->update($userId, $existing['id'], $data);
        }
        $this->assertRoom($userId);
        Database::run(
            'INSERT INTO contacts (user_id, email, name, phone, notes, extra_emails, details, favorite, saved) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1)',
            [$userId, $data['email'] ?? null, $data['name'] ?? null, $data['phone'] ?? null, $data['notes'] ?? null,
             $data['extra_emails'] ?? null, $data['details'] ?? null, (int) ($data['favorite'] ?? false)]
        );
        $id = Database::lastId();
        ContactCards::changedInPostvak($userId, $id, ContactCards::ADDED);
        return $this->find($userId, $id);
    }

    /**
     * Full details from the form also fill the simple columns used for search, autocomplete and sync:
     * name, first e-mail address (+ the others), first phone number.
     */
    private static function withDetails(array $data): array
    {
        if (!isset($data['details']) || !is_array($data['details'])) {
            return $data;
        }
        $d = $data['details'];
        $emails = array_column($d['emails'], 'value');
        $data['name'] = ContactDetails::displayName($d) ?: null;
        $data['email'] = $emails[0] ?? null;
        $data['extra_emails'] = count($emails) > 1 ? mb_substr(implode(', ', array_slice($emails, 1)), 0, 1000) : null;
        $data['phone'] = $d['phones'][0]['value'] ?? null;
        $data['details'] = json_encode($d, JSON_UNESCAPED_UNICODE);
        return $data;
    }

    /** @param array<string, mixed> $data validated, only the given fields change */
    public function update(int $userId, int $id, array $data): array
    {
        $wasSaved = $this->find($userId, $id)['saved'];
        $data = self::withDetails($data);
        $sets = ['saved = 1'];
        $params = [];
        foreach (['email', 'name', 'phone', 'notes', 'favorite', 'extra_emails', 'details'] as $field) {
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
        ContactCards::changedInPostvak($userId, $id, $wasSaved ? ContactCards::MODIFIED : ContactCards::ADDED);
        return $this->find($userId, $id);
    }

    public function delete(int $userId, int $id): void
    {
        $this->find($userId, $id);
        $row = Database::one('SELECT * FROM contacts WHERE id = ?', [$id]);
        Database::run('DELETE FROM contacts WHERE id = ? AND user_id = ?', [$id, $userId]);
        if ((int) $row['saved']) {
            ContactCards::log($userId, ContactCards::uri($row), ContactCards::DELETED);
        }
    }

    /**
     * A phone deleted the card: an address you send to stays for autocomplete (as a collected one),
     * anything else goes.
     */
    public function unsave(int $userId, array $row): void
    {
        if ((int) $row['times_used'] > 0 && !empty($row['email'])) {
            Database::run(
                'UPDATE contacts SET saved = 0, favorite = 0, phone = NULL, notes = NULL, card_uri = NULL, vcard = NULL, extra_emails = NULL WHERE id = ? AND user_id = ?',
                [$row['id'], $userId]
            );
        } else {
            Database::run('DELETE FROM contacts WHERE id = ? AND user_id = ?', [$row['id'], $userId]);
        }
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
        $changed = [];
        $count = (int) (Database::one('SELECT COUNT(*) AS n FROM contacts WHERE user_id = ?', [$userId])['n'] ?? 0);
        $pdo = Database::pdo();
        $pdo->beginTransaction();
        try {
            foreach ($cards as $card) {
                if ($card['emails'] === []) {
                    $skipped++;
                    continue;
                }
                // One contact per card: the first address, the others as extra addresses (and in the details).
                $email = $card['emails'][0];
                $extra = count($card['emails']) > 1 ? mb_substr(implode(', ', array_slice($card['emails'], 1)), 0, 1000) : null;
                $details = isset($card['details']) ? json_encode($card['details'], JSON_UNESCAPED_UNICODE) : null;
                $existing = $this->findByEmail($userId, $email);
                if ($existing) {
                    Database::run(
                        // MySQL applies SET left to right, so "saved = 1" must come last.
                        'UPDATE contacts SET
                           name  = IF(saved = 1 AND name  IS NOT NULL AND name  <> \'\', name,  COALESCE(NULLIF(?, \'\'), name)),
                           phone = IF(phone IS NOT NULL AND phone <> \'\', phone, NULLIF(?, \'\')),
                           notes = IF(notes IS NOT NULL AND notes <> \'\', notes, NULLIF(?, \'\')),
                           extra_emails = COALESCE(extra_emails, ?),
                           details = IF(saved = 1 AND details IS NOT NULL, details, COALESCE(?, details)),
                           saved = 1
                         WHERE id = ?',
                        [$card['name'], $card['phone'], $card['notes'], $extra, $details, $existing['id']]
                    );
                    $changed[$existing['id']] = $existing['saved'] ? ContactCards::MODIFIED : ContactCards::ADDED;
                    $updated++;
                    continue;
                }
                if ($count >= self::MAX_CONTACTS) {
                    $skipped++;
                    continue;
                }
                Database::run(
                    'INSERT INTO contacts (user_id, email, name, phone, notes, extra_emails, details, saved) VALUES (?, ?, NULLIF(?, \'\'), NULLIF(?, \'\'), NULLIF(?, \'\'), ?, ?, 1)',
                    [$userId, $email, $card['name'], $card['phone'], $card['notes'], $extra, $details]
                );
                $changed[Database::lastId()] = ContactCards::ADDED;
                $count++;
                $added++;
            }
            foreach ($changed as $id => $operation) {
                ContactCards::changedInPostvak($userId, (int) $id, $operation);
            }
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
        return ['added' => $added, 'updated' => $updated, 'skipped' => $skipped];
    }

    /** Photo of a contact: the one added in Postvak, else the one in the phone's card. @return array{0:string,1:string}|null */
    public function photo(int $userId, int $id): ?array
    {
        $row = Database::one('SELECT photo, photo_mime, vcard FROM contacts WHERE id = ? AND user_id = ?', [$id, $userId]);
        if (!$row) {
            throw HttpException::notFound('Contact not found');
        }
        if (!empty($row['photo'])) {
            return [(string) $row['photo'], (string) $row['photo_mime']];
        }
        return !empty($row['vcard']) ? ContactDetails::photoOfCard((string) $row['vcard']) : null;
    }

    /** Set (or with null: remove) the photo; phones get it with the card. */
    public function setPhoto(int $userId, int $id, ?string $bytes, ?string $mime): array
    {
        $contact = $this->find($userId, $id);
        if ($bytes === null) {
            Database::run('UPDATE contacts SET photo = NULL, photo_mime = NULL WHERE id = ? AND user_id = ?', [$id, $userId]);
            $row = Database::one('SELECT vcard FROM contacts WHERE id = ?', [$id]);
            if (!empty($row['vcard'])) {
                // Remove the phone's photo from the card too.
                try {
                    $card = \Sabre\VObject\Reader::read((string) $row['vcard'], \Sabre\VObject\Reader::OPTION_FORGIVING);
                    unset($card->PHOTO);
                    Database::run('UPDATE contacts SET vcard = ? WHERE id = ?', [$card->serialize(), $id]);
                } catch (\Throwable) {
                }
            }
        } else {
            // Postvak's details now describe the card (so the photo can be written into it).
            $details = $contact['details'];
            Database::run(
                'UPDATE contacts SET photo = ?, photo_mime = ?, details = COALESCE(details, ?) WHERE id = ? AND user_id = ?',
                [$bytes, $mime, json_encode($details, JSON_UNESCAPED_UNICODE), $id, $userId]
            );
        }
        Database::run('UPDATE contacts SET updated_at = NOW() WHERE id = ?', [$id]);
        if ($contact['saved']) {
            ContactCards::changedInPostvak($userId, $id);
        }
        return $this->find($userId, $id);
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
        $details = ContactDetails::ofRow($row);
        $version = $row['updated_at'] ? strtotime((string) $row['updated_at']) : 0;
        return [
            'id'           => (int) $row['id'],
            'email'        => $row['email'] ?? '',
            'other_emails' => $row['extra_emails'] ? array_map('trim', explode(',', (string) $row['extra_emails'])) : [],
            'synced'       => !empty($row['card_uri']) || !empty($row['vcard']),
            'name'         => $row['name'] ?? '',
            'phone'        => $row['phone'] ?? '',
            'notes'        => $row['notes'] ?? '',
            'details'      => $details,
            'photo_url'    => !empty($row['has_photo']) ? '/api/contacts/' . (int) $row['id'] . '/photo?v=' . $version : null,
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
