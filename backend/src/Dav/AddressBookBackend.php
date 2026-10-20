<?php
declare(strict_types=1);

namespace Postvak\Dav;

use Postvak\Contacts\ContactCards;
use Postvak\Database;
use Postvak\Repositories\ContactRepository;
use Sabre\CardDAV\Backend\AbstractBackend;
use Sabre\CardDAV\Backend\SyncSupport;
use Sabre\DAV\Exception\BadRequest;
use Sabre\DAV\Exception\Forbidden;
use Sabre\DAV\Exception\InsufficientStorage;
use Sabre\DAV\PropPatch;

/**
 * CardDAV on top of Postvak's own contacts table: one address book "Postvak" per user with the saved
 * contacts. Changes from phones land in the same rows the contacts page shows, and the other way round.
 * The address book id is the user id.
 */
final class AddressBookBackend extends AbstractBackend implements SyncSupport
{
    private static function userFor(string $principalUri): ?int
    {
        if (!str_starts_with($principalUri, PrincipalBackend::PREFIX)) {
            return null;
        }
        $user = Database::one('SELECT id FROM users WHERE login_email = ?', [substr($principalUri, strlen(PrincipalBackend::PREFIX))]);
        return $user ? (int) $user['id'] : null;
    }

    public function getAddressBooksForUser($principalUri)
    {
        $userId = self::userFor((string) $principalUri);
        if ($userId === null) {
            return [];
        }
        $token = ContactCards::syncToken($userId);
        return [[
            'id'                => $userId,
            'uri'               => 'contacts',
            'principaluri'      => $principalUri,
            '{DAV:}displayname' => 'Postvak',
            '{urn:ietf:params:xml:ns:carddav}addressbook-description' => 'Postvak',
            '{http://calendarserver.org/ns/}getctag' => (string) $token,
            '{http://sabredav.org/ns}sync-token'     => (string) $token,
        ]];
    }

    public function updateAddressBook($addressBookId, PropPatch $propPatch)
    {
        // Name and description are fixed.
    }

    public function createAddressBook($principalUri, $url, array $properties)
    {
        throw new Forbidden('Postvak has one address book per user');
    }

    public function deleteAddressBook($addressBookId)
    {
        throw new Forbidden('The Postvak address book cannot be deleted');
    }

    private static function meta(array $row, bool $withData): array
    {
        $data = ContactCards::data($row);
        $card = [
            'uri'          => ContactCards::uri($row),
            'lastmodified' => strtotime((string) ($row['updated_at'] ?? 'now')) ?: time(),
            'etag'         => '"' . md5($data) . '"',
            'size'         => strlen($data),
        ];
        if ($withData) {
            $card['carddata'] = $data;
        }
        return $card;
    }

    public function getCards($addressbookId)
    {
        $rows = Database::all('SELECT * FROM contacts WHERE user_id = ? AND saved = 1 ORDER BY id LIMIT ' . ContactRepository::MAX_CONTACTS, [(int) $addressbookId]);
        return array_map(static fn ($row) => self::meta($row, false), $rows);
    }

    public function getCard($addressBookId, $cardUri)
    {
        $row = ContactCards::findByUri((int) $addressBookId, (string) $cardUri);
        return $row ? self::meta($row, true) : false;
    }

    public function getMultipleCards($addressBookId, array $uris)
    {
        $out = [];
        foreach ($uris as $uri) {
            $card = $this->getCard($addressBookId, $uri);
            if ($card) {
                $out[] = $card;
            }
        }
        return $out;
    }

    public function createCard($addressBookId, $cardUri, $cardData)
    {
        $userId = (int) $addressBookId;
        $uri = (string) $cardUri;
        if (strlen($uri) > 200 || ContactCards::findByUri($userId, $uri)) {
            throw new BadRequest('Invalid card name');
        }
        $f = ContactCards::fields((string) $cardData);
        $email = $f['email'];

        // A collected address (or an earlier saved one without a card) becomes this card.
        $existing = $email !== null ? Database::one('SELECT * FROM contacts WHERE user_id = ? AND email = ?', [$userId, $email]) : null;
        if ($existing && (int) $existing['saved'] && ($existing['card_uri'] || $existing['vcard'])) {
            // The address already belongs to another card: keep this one without it.
            $existing = null;
            $email = null;
        }
        if ($existing) {
            Database::run(
                'UPDATE contacts SET name = ?, phone = ?, notes = ?, extra_emails = ?, details = ?, photo = NULL, photo_mime = NULL, saved = 1, card_uri = ?, vcard = ? WHERE id = ?',
                [$f['name'] ?: null, $f['phone'], $f['notes'], $f['extra_emails'], $f['details'], $uri, (string) $cardData, $existing['id']]
            );
            if ((int) $existing['saved'] && !$existing['card_uri']) {
                // It was known to phones under its pv-<id> name.
                ContactCards::log($userId, ContactCards::uri($existing), ContactCards::DELETED);
            }
        } else {
            $count = (int) (Database::one('SELECT COUNT(*) n FROM contacts WHERE user_id = ?', [$userId])['n'] ?? 0);
            if ($count >= ContactRepository::MAX_CONTACTS) {
                throw new InsufficientStorage('Too many contacts');
            }
            Database::run(
                'INSERT INTO contacts (user_id, email, name, phone, notes, extra_emails, details, saved, card_uri, vcard) VALUES (?, ?, ?, ?, ?, ?, ?, 1, ?, ?)',
                [$userId, $email, $f['name'] ?: null, $f['phone'], $f['notes'], $f['extra_emails'], $f['details'], $uri, (string) $cardData]
            );
        }
        ContactCards::log($userId, $uri, ContactCards::ADDED);
        return '"' . md5((string) $cardData) . '"';
    }

    public function updateCard($addressBookId, $cardUri, $cardData)
    {
        $userId = (int) $addressBookId;
        $row = ContactCards::findByUri($userId, (string) $cardUri);
        if ($row === null) {
            return $this->createCard($addressBookId, $cardUri, $cardData);
        }
        $f = ContactCards::fields((string) $cardData);
        $email = $f['email'];
        if ($email !== null && $email !== $row['email']) {
            $taken = Database::one('SELECT id, saved FROM contacts WHERE user_id = ? AND email = ? AND id <> ?', [$userId, $email, $row['id']]);
            if ($taken && !(int) $taken['saved']) {
                // A collected address the card now claims: merge it into this card.
                Database::run('UPDATE contacts SET times_used = times_used + (SELECT n FROM (SELECT times_used n FROM contacts WHERE id = ?) t) WHERE id = ?', [$taken['id'], $row['id']]);
                Database::run('DELETE FROM contacts WHERE id = ?', [$taken['id']]);
            } elseif ($taken) {
                $email = $row['email']; // used by another saved contact: keep the old one here
            }
        }
        Database::run(
            // The phone's card is the newest version now, photo included (a photo set in Postvak was in it).
            'UPDATE contacts SET email = ?, name = ?, phone = ?, notes = ?, extra_emails = ?, details = ?, photo = NULL, photo_mime = NULL, vcard = ? WHERE id = ?',
            [$email, $f['name'] ?: null, $f['phone'], $f['notes'], $f['extra_emails'], $f['details'], (string) $cardData, $row['id']]
        );
        ContactCards::log($userId, (string) $cardUri, ContactCards::MODIFIED);
        return '"' . md5((string) $cardData) . '"';
    }

    public function deleteCard($addressBookId, $cardUri)
    {
        $userId = (int) $addressBookId;
        $row = ContactCards::findByUri($userId, (string) $cardUri);
        if ($row === null) {
            return false;
        }
        (new ContactRepository())->unsave($userId, $row);
        ContactCards::log($userId, (string) $cardUri, ContactCards::DELETED);
        return true;
    }

    public function getChangesForAddressBook($addressBookId, $syncToken, $syncLevel, $limit = null)
    {
        $userId = (int) $addressBookId;
        $current = ContactCards::syncToken($userId);
        $result = ['syncToken' => (string) $current, 'added' => [], 'modified' => [], 'deleted' => []];
        if (!$syncToken) {
            $result['added'] = array_column($this->getCards($userId), 'uri');
            return $result;
        }
        if (!ctype_digit((string) $syncToken) || (int) $syncToken > $current) {
            return null; // unknown token: the client starts over
        }
        $changes = [];
        foreach (Database::all('SELECT uri, operation FROM contact_changes WHERE user_id = ? AND id > ? ORDER BY id', [$userId, (int) $syncToken]) as $c) {
            $changes[$c['uri']] = (int) $c['operation'];
        }
        foreach ($changes as $uri => $op) {
            $key = $op === ContactCards::ADDED ? 'added' : ($op === ContactCards::MODIFIED ? 'modified' : 'deleted');
            $result[$key][] = (string) $uri;
        }
        return $result;
    }
}
