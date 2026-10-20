<?php
declare(strict_types=1);

namespace Postvak\Contacts;

use Postvak\Database;
use Sabre\VObject;
use Throwable;

/**
 * The bridge between Postvak's contacts (one row per address, simple fields) and the vCards that phones
 * sync over CardDAV. Only saved contacts are cards; addresses collected while sending are not.
 *
 * A card a phone sent is kept whole in `vcard`. Edits in Postvak write the full details (ContactDetails) into
 * it; properties Postvak does not know (anniversaries, social profiles, …) stay as they were.
 */
final class ContactCards
{
    public const ADDED = 1;
    public const MODIFIED = 2;
    public const DELETED = 3;

    /** Name of a contact row on the CardDAV server. */
    public static function uri(array $row): string
    {
        return $row['card_uri'] ?: 'pv-' . (int) $row['id'] . '.vcf';
    }

    /** The row behind a card name, or null. */
    public static function findByUri(int $userId, string $uri): ?array
    {
        $row = Database::one('SELECT * FROM contacts WHERE user_id = ? AND card_uri = ? AND saved = 1', [$userId, $uri]);
        if ($row === null && preg_match('/^pv-(\d+)\.vcf$/', $uri, $m)) {
            $row = Database::one('SELECT * FROM contacts WHERE user_id = ? AND id = ? AND card_uri IS NULL AND saved = 1', [$userId, (int) $m[1]]);
        }
        return $row;
    }

    /** vCard text for a row: the phone's card when there is one, otherwise made from the fields. */
    public static function data(array $row): string
    {
        if (!empty($row['vcard'])) {
            return (string) $row['vcard'];
        }
        return self::build($row);
    }

    public static function build(array $row): string
    {
        $card = new VObject\Component\VCard(['VERSION' => '3.0', 'PRODID' => '-//Postvak//Contacts//EN']);
        $card->UID = 'postvak-contact-' . (int) $row['id'];
        self::apply($card, $row);
        return $card->serialize();
    }

    /** Put the Postvak fields into a vCard, leaving everything else in it alone. */
    public static function apply(VObject\Component\VCard $card, array $row): void
    {
        $name = trim((string) ($row['name'] ?? ''));
        $email = trim((string) ($row['email'] ?? ''));
        $card->FN = $name !== '' ? $name : ($email !== '' ? $email : '?');

        if (!empty($row['details'])) {
            // Edited with the full form: the details decide the names, addresses, numbers, …
            $photo = !empty($row['photo']) ? (string) $row['photo'] : null;
            ContactDetails::toCard($card, ContactDetails::ofRow($row), $photo, $row['photo_mime'] ?? null);
        } else {
            self::applySimple($card, $name, $email, (string) ($row['phone'] ?? ''));
        }
        unset($card->NOTE);
        if (trim((string) ($row['notes'] ?? '')) !== '') {
            $card->NOTE = (string) $row['notes'];
        }
        $card->REV = gmdate('Ymd\THis\Z');
    }

    /** Rows without details (older contacts): only the name, first address and first number. */
    private static function applySimple(VObject\Component\VCard $card, string $name, string $email, string $phone): void
    {
        if (!isset($card->N) || ($name !== '' && self::nameOf($card) !== $name)) {
            [$given, $family] = ContactDetails::split($name);
            $card->N = [$family, $given, '', '', ''];
        }
        $emails = $card->select('EMAIL');
        if ($email === '') {
            // No address in Postvak: keep the card's own list.
        } elseif ($emails === []) {
            $card->add('EMAIL', $email, ['TYPE' => 'INTERNET']);
        } elseif (strtolower((string) $emails[0]->getValue()) !== strtolower($email)) {
            $found = false;
            foreach ($emails as $e) {
                if (strtolower((string) $e->getValue()) === strtolower($email)) {
                    $found = true;
                }
            }
            if (!$found) {
                $emails[0]->setValue($email);
            }
        }
        self::setFirst($card, 'TEL', $phone, ['TYPE' => 'CELL']);
    }

    private static function setFirst(VObject\Component\VCard $card, string $prop, string $value, array $params): void
    {
        $existing = $card->select($prop);
        $value = trim($value);
        if ($value === '') {
            if ($existing !== []) {
                $card->remove($existing[0]);
            }
            return;
        }
        if ($existing === []) {
            $card->add($prop, $value, $params);
        } elseif ((string) $existing[0]->getValue() !== $value) {
            $existing[0]->setValue($value);
        }
    }

    private static function nameOf(VObject\Component\VCard $card): string
    {
        return trim((string) ($card->FN ?? ''));
    }

    /**
     * Fields Postvak keeps from a card sent by a phone (or imported).
     * @return array{name:string, email:?string, extra_emails:?string, phone:?string, notes:?string, details:?string}
     */
    public static function fields(string $vcard): array
    {
        $parsed = VCard::parse($vcard)[0] ?? ['name' => '', 'emails' => [], 'phone' => '', 'notes' => ''];
        $details = null;
        try {
            $card = VObject\Reader::read($vcard, VObject\Reader::OPTION_FORGIVING);
            if ($card instanceof VObject\Component\VCard) {
                $details = ContactDetails::fromCard($card);
            }
        } catch (Throwable) {
        }
        $emails = array_values(array_unique($parsed['emails']));
        $phone = $parsed['phone'];
        if ($details !== null) {
            // The details know the order the phone gave (first = main).
            $emails = array_values(array_unique([...array_column($details['emails'], 'value'), ...$emails]));
            $phone = $details['phones'][0]['value'] ?? $phone;
        }
        return [
            'name'         => mb_substr($parsed['name'] !== '' ? $parsed['name'] : ($details ? ContactDetails::displayName($details) : ''), 0, 255),
            'email'        => $emails[0] ?? null,
            'extra_emails' => count($emails) > 1 ? mb_substr(implode(', ', array_slice($emails, 1)), 0, 1000) : null,
            'phone'        => $phone !== '' ? mb_substr($phone, 0, 64) : null,
            'notes'        => $parsed['notes'] !== '' ? $parsed['notes'] : null,
            'details'      => $details !== null ? json_encode($details, JSON_UNESCAPED_UNICODE) : null,
        ];
    }

    /** After a change in Postvak: refresh the stored card of a saved contact and tell syncing phones. */
    public static function changedInPostvak(int $userId, int $id, int $operation = self::MODIFIED): void
    {
        $row = Database::one('SELECT * FROM contacts WHERE id = ? AND user_id = ?', [$id, $userId]);
        if ($row === null || !(int) $row['saved']) {
            return;
        }
        if (!empty($row['vcard'])) {
            try {
                $card = VObject\Reader::read((string) $row['vcard'], VObject\Reader::OPTION_FORGIVING);
                if ($card instanceof VObject\Component\VCard) {
                    self::apply($card, $row);
                    Database::run('UPDATE contacts SET vcard = ? WHERE id = ?', [$card->serialize(), $id]);
                }
            } catch (Throwable $e) {
                error_log('[postvak] contact card ' . $id . ' not updated: ' . $e->getMessage());
            }
        }
        self::log($userId, self::uri($row), $operation);
    }

    /** Record a change for CardDAV sync. */
    public static function log(int $userId, string $uri, int $operation): void
    {
        Database::run('INSERT INTO contact_changes (user_id, uri, operation) VALUES (?, ?, ?)', [$userId, $uri, $operation]);
    }

    public static function syncToken(int $userId): int
    {
        return (int) (Database::one('SELECT MAX(id) m FROM contact_changes WHERE user_id = ?', [$userId])['m'] ?? 0);
    }
}
