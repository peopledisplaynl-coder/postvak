<?php
declare(strict_types=1);

namespace Postvak\Contacts;

use Postvak\Http\HttpException;
use Sabre\VObject;
use Throwable;

/**
 * The full details of a contact, as on Google Contacts, and their place in a vCard:
 *
 *   given, family, nickname      N / FN / NICKNAME
 *   company, department, title   ORG (company;department) / TITLE
 *   emails[]   {value, type}     EMAIL;TYPE=home|work|other
 *   phones[]   {value, type}     TEL;TYPE=cell|home|work|main|other
 *   addresses[] {street, postal_code, city, region, country, type}   ADR
 *   birthday   YYYY-MM-DD or --MM-DD                                 BDAY
 *   websites[] {value}           URL
 *   groups[]   string            CATEGORIES
 *
 * Notes stay in the `notes` column, the photo in `photo` (or in the phone's card).
 * Stored as JSON in contacts.details.
 */
final class ContactDetails
{
    public const EMAIL_TYPES = ['home', 'work', 'other'];
    public const PHONE_TYPES = ['mobile', 'home', 'work', 'main', 'other'];
    public const ADDRESS_TYPES = ['home', 'work', 'other'];
    private const MAX_ITEMS = 10;

    public static function empty(): array
    {
        return [
            'given' => '', 'family' => '', 'nickname' => '', 'company' => '', 'department' => '', 'title' => '',
            'emails' => [], 'phones' => [], 'addresses' => [], 'birthday' => '', 'websites' => [], 'groups' => [],
        ];
    }

    /** Validate and normalise input from the contacts page. */
    public static function validate(array $in): array
    {
        $d = self::empty();
        foreach (['given' => 100, 'family' => 100, 'nickname' => 100, 'company' => 150, 'department' => 150, 'title' => 150] as $k => $max) {
            $d[$k] = self::text($in[$k] ?? '', $max, $k);
        }
        foreach (self::items($in['emails'] ?? []) as $e) {
            $value = strtolower(self::text($e['value'] ?? '', 255, 'email'));
            if ($value === '') {
                continue;
            }
            if (!filter_var($value, FILTER_VALIDATE_EMAIL)) {
                throw HttpException::badRequest('Invalid e-mail address: ' . $value);
            }
            $d['emails'][] = ['value' => $value, 'type' => self::type($e['type'] ?? '', self::EMAIL_TYPES)];
        }
        foreach (self::items($in['phones'] ?? []) as $p) {
            $value = self::text($p['value'] ?? '', 64, 'phone');
            if ($value === '') {
                continue;
            }
            if (!preg_match('/^[0-9+()\-.\s\/]{2,64}$/', $value)) {
                throw HttpException::badRequest('Invalid phone number: ' . $value);
            }
            $d['phones'][] = ['value' => $value, 'type' => self::type($p['type'] ?? '', self::PHONE_TYPES)];
        }
        foreach (self::items($in['addresses'] ?? []) as $a) {
            $addr = [];
            foreach (['street' => 300, 'postal_code' => 20, 'city' => 100, 'region' => 100, 'country' => 100] as $k => $max) {
                $addr[$k] = self::text($a[$k] ?? '', $max, 'address');
            }
            if (implode('', $addr) === '') {
                continue;
            }
            $addr['type'] = self::type($a['type'] ?? '', self::ADDRESS_TYPES);
            $d['addresses'][] = $addr;
        }
        $birthday = trim((string) ($in['birthday'] ?? ''));
        if ($birthday !== '' && !preg_match('/^(\d{4}-|--)(0[1-9]|1[0-2])-(0[1-9]|[12]\d|3[01])$/', $birthday)) {
            throw HttpException::badRequest('Invalid birthday');
        }
        $d['birthday'] = $birthday;
        foreach (self::items($in['websites'] ?? []) as $w) {
            $value = self::text(is_array($w) ? ($w['value'] ?? '') : $w, 300, 'website');
            if ($value === '') {
                continue;
            }
            if (!preg_match('#^https?://#i', $value)) {
                $value = 'https://' . $value;
            }
            if (!filter_var($value, FILTER_VALIDATE_URL)) {
                throw HttpException::badRequest('Invalid website: ' . $value);
            }
            $d['websites'][] = ['value' => $value];
        }
        $groups = [];
        foreach (self::items($in['groups'] ?? [], 20) as $g) {
            $g = self::text(is_string($g) ? $g : '', 50, 'group');
            if ($g !== '' && !in_array(mb_strtolower($g), array_map('mb_strtolower', $groups), true)) {
                $groups[] = $g;
            }
        }
        $d['groups'] = $groups;
        return $d;
    }

    /** Display name: "given family", or nickname / company. */
    public static function displayName(array $d): string
    {
        $name = trim($d['given'] . ' ' . $d['family']);
        return $name !== '' ? $name : ($d['nickname'] !== '' ? $d['nickname'] : $d['company']);
    }

    /** Details of a contact row: the stored JSON, else read from its card, else from the simple columns. */
    public static function ofRow(array $row): array
    {
        if (!empty($row['details'])) {
            $d = json_decode((string) $row['details'], true);
            if (is_array($d)) {
                return array_merge(self::empty(), $d);
            }
        }
        if (!empty($row['vcard'])) {
            try {
                $card = VObject\Reader::read((string) $row['vcard'], VObject\Reader::OPTION_FORGIVING);
                if ($card instanceof VObject\Component\VCard) {
                    return self::fromCard($card);
                }
            } catch (Throwable) {
            }
        }
        $d = self::empty();
        [$d['given'], $d['family']] = self::split((string) ($row['name'] ?? ''));
        foreach (array_filter([$row['email'] ?? null, ...array_map('trim', explode(',', (string) ($row['extra_emails'] ?? '')))]) as $e) {
            $d['emails'][] = ['value' => $e, 'type' => 'other'];
        }
        if (!empty($row['phone'])) {
            $d['phones'][] = ['value' => (string) $row['phone'], 'type' => 'mobile'];
        }
        return $d;
    }

    /** Read the details from a vCard (from a phone or an import). */
    public static function fromCard(VObject\Component\VCard $card): array
    {
        $d = self::empty();
        if (isset($card->N)) {
            $n = $card->N->getParts();
            $d['family'] = trim((string) ($n[0] ?? ''));
            $d['given'] = trim(implode(' ', array_filter([trim((string) ($n[1] ?? '')), trim((string) ($n[2] ?? ''))])));
        }
        if ($d['given'] === '' && $d['family'] === '' && isset($card->FN)) {
            [$d['given'], $d['family']] = self::split((string) $card->FN);
        }
        $d['nickname'] = mb_substr(trim((string) ($card->NICKNAME ?? '')), 0, 100);
        if (isset($card->ORG)) {
            $org = $card->ORG->getParts();
            $d['company'] = mb_substr(trim((string) ($org[0] ?? '')), 0, 150);
            $d['department'] = mb_substr(trim((string) ($org[1] ?? '')), 0, 150);
        }
        $d['title'] = mb_substr(trim((string) ($card->TITLE ?? '')), 0, 150);
        foreach ($card->select('EMAIL') as $e) {
            $value = strtolower(trim(preg_replace('/^mailto:/i', '', (string) $e->getValue()) ?? ''));
            if (filter_var($value, FILTER_VALIDATE_EMAIL) && count($d['emails']) < self::MAX_ITEMS) {
                $d['emails'][] = ['value' => $value, 'type' => self::typeOf($e, ['home' => 'home', 'work' => 'work'], 'other')];
            }
        }
        foreach ($card->select('TEL') as $t) {
            $value = trim(preg_replace('/^tel:/i', '', (string) $t->getValue()) ?? '');
            if ($value !== '' && count($d['phones']) < self::MAX_ITEMS) {
                $d['phones'][] = ['value' => mb_substr($value, 0, 64), 'type' => self::typeOf($t, ['cell' => 'mobile', 'mobile' => 'mobile', 'iphone' => 'mobile', 'home' => 'home', 'work' => 'work', 'main' => 'main', 'pref' => 'main'], 'other')];
            }
        }
        foreach ($card->select('ADR') as $a) {
            $p = $a->getParts(); // PO box; extended; street; locality; region; postal code; country
            $street = trim(implode("\n", array_filter([trim((string) ($p[2] ?? '')), trim((string) ($p[1] ?? ''))])));
            $addr = [
                'street' => mb_substr($street, 0, 300), 'postal_code' => mb_substr(trim((string) ($p[5] ?? '')), 0, 20),
                'city' => mb_substr(trim((string) ($p[3] ?? '')), 0, 100), 'region' => mb_substr(trim((string) ($p[4] ?? '')), 0, 100),
                'country' => mb_substr(trim((string) ($p[6] ?? '')), 0, 100),
            ];
            if (implode('', $addr) !== '' && count($d['addresses']) < self::MAX_ITEMS) {
                $addr['type'] = self::typeOf($a, ['home' => 'home', 'work' => 'work'], 'other');
                $d['addresses'][] = $addr;
            }
        }
        if (isset($card->BDAY)) {
            $d['birthday'] = self::birthday((string) $card->BDAY);
        }
        foreach ($card->select('URL') as $u) {
            $value = trim((string) $u->getValue());
            if (preg_match('#^https?://#i', $value) && count($d['websites']) < self::MAX_ITEMS) {
                $d['websites'][] = ['value' => mb_substr($value, 0, 300)];
            }
        }
        if (isset($card->CATEGORIES)) {
            foreach ($card->CATEGORIES->getParts() as $g) {
                $g = mb_substr(trim((string) $g), 0, 50);
                if ($g !== '' && count($d['groups']) < 20) {
                    $d['groups'][] = $g;
                }
            }
        }
        return $d;
    }

    /** Write the details into a vCard (replacing those properties; anything else on the card stays). */
    public static function toCard(VObject\Component\VCard $card, array $d, ?string $photo = null, ?string $photoMime = null): void
    {
        $d = array_merge(self::empty(), $d);
        $card->N = [$d['family'], $d['given'], '', '', ''];
        foreach (['NICKNAME', 'ORG', 'TITLE', 'EMAIL', 'TEL', 'ADR', 'BDAY', 'URL', 'CATEGORIES'] as $prop) {
            unset($card->{$prop});
        }
        if ($d['nickname'] !== '') {
            $card->NICKNAME = $d['nickname'];
        }
        if ($d['company'] !== '' || $d['department'] !== '') {
            $card->ORG = $d['department'] !== '' ? [$d['company'], $d['department']] : $d['company'];
        }
        if ($d['title'] !== '') {
            $card->TITLE = $d['title'];
        }
        foreach ($d['emails'] as $i => $e) {
            $types = ['INTERNET'];
            if ($e['type'] !== 'other') {
                $types[] = strtoupper($e['type']);
            }
            if ($i === 0) {
                $types[] = 'PREF';
            }
            $card->add('EMAIL', $e['value'], ['TYPE' => $types]);
        }
        $telType = ['mobile' => 'CELL', 'home' => 'HOME', 'work' => 'WORK', 'main' => 'MAIN', 'other' => 'VOICE'];
        foreach ($d['phones'] as $p) {
            $card->add('TEL', $p['value'], ['TYPE' => $telType[$p['type']] ?? 'VOICE']);
        }
        foreach ($d['addresses'] as $a) {
            $card->add('ADR', ['', '', $a['street'], $a['city'], $a['region'], $a['postal_code'], $a['country']], ['TYPE' => strtoupper($a['type'] === 'other' ? 'other' : $a['type'])]);
        }
        if ($d['birthday'] !== '') {
            $card->BDAY = $d['birthday'];
        }
        foreach ($d['websites'] as $w) {
            $card->add('URL', $w['value']);
        }
        if ($d['groups'] !== []) {
            $card->CATEGORIES = $d['groups'];
        }
        if ($photo !== null) {
            unset($card->PHOTO);
            $card->add('PHOTO', $photo, ['ENCODING' => 'b', 'TYPE' => strtoupper(str_replace('image/', '', $photoMime ?: 'image/jpeg'))]);
        }
    }

    /** Photo stored inside a vCard: [bytes, mime] or null. */
    public static function photoOfCard(string $vcard): ?array
    {
        try {
            $card = VObject\Reader::read($vcard, VObject\Reader::OPTION_FORGIVING);
        } catch (Throwable) {
            return null;
        }
        if (!$card instanceof VObject\Component\VCard || !isset($card->PHOTO)) {
            return null;
        }
        $value = (string) $card->PHOTO->getValue();
        if (preg_match('#^data:(image/[a-z+.-]+);base64,(.+)$#is', $value, $m)) {
            $bytes = base64_decode($m[2], true);
        } elseif (preg_match('#^(https?|file):#i', $value)) {
            return null; // a link: not fetched
        } else {
            // ENCODING=b (vCard 3): the library already decoded it. Some writers leave base64 text.
            $bytes = @getimagesizefromstring($value) ? $value : (base64_decode($value, true) ?: $value);
        }
        if (!is_string($bytes) || $bytes === '') {
            return null;
        }
        $info = @getimagesizefromstring($bytes);
        $mime = is_array($info) ? (string) ($info['mime'] ?? '') : '';
        return in_array($mime, ['image/jpeg', 'image/png', 'image/gif', 'image/webp'], true) ? [$bytes, $mime] : null;
    }

    /** "Ton l'Abee" → ["Ton", "l'Abee"] (the last word is the family name). */
    public static function split(string $name): array
    {
        $parts = preg_split('/\s+/', trim($name)) ?: [];
        if (count($parts) < 2) {
            return [trim($name), ''];
        }
        $family = array_pop($parts);
        return [implode(' ', $parts), $family];
    }

    private static function birthday(string $value): string
    {
        $value = trim($value);
        if (preg_match('/^(\d{4})-?(\d{2})-?(\d{2})/', $value, $m)) {
            return "$m[1]-$m[2]-$m[3]";
        }
        if (preg_match('/^--(\d{2})-?(\d{2})/', $value, $m)) {
            return "--$m[1]-$m[2]";
        }
        return '';
    }

    private static function typeOf(VObject\Property $prop, array $map, string $default): string
    {
        $types = [];
        foreach ($prop->parameters() as $param) {
            if (strtoupper($param->name) === 'TYPE') {
                foreach ($param->getParts() as $part) {
                    $types[] = strtolower((string) $part);
                }
            }
        }
        foreach ($map as $type => $ours) {
            if (in_array($type, $types, true)) {
                return $ours;
            }
        }
        return $default;
    }

    private static function items(mixed $list, int $max = self::MAX_ITEMS): array
    {
        if (!is_array($list)) {
            return [];
        }
        return array_slice(array_values(array_filter($list, static fn ($x) => is_array($x) || is_string($x))), 0, $max);
    }

    private static function type(mixed $value, array $allowed): string
    {
        return in_array($value, $allowed, true) ? (string) $value : $allowed[array_key_last($allowed)];
    }

    private static function text(mixed $value, int $max, string $field): string
    {
        $value = trim(str_replace(["\r\n", "\r"], "\n", (string) (is_scalar($value) ? $value : '')));
        if (mb_strlen($value) > $max) {
            throw HttpException::badRequest("Field '$field' is too long");
        }
        return $value;
    }
}
