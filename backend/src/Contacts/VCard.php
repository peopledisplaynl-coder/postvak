<?php
declare(strict_types=1);

namespace Postvak\Contacts;

/**
 * Minimal vCard (2.1 / 3.0 / 4.0) reader and 3.0 writer for the address book.
 *
 * Reads FN, N, EMAIL, TEL and NOTE; handles folded lines, escaped characters,
 * QUOTED-PRINTABLE (vCard 2.1, Android/Outlook) and non-UTF-8 charsets.
 */
final class VCard
{
    /**
     * @return array<int, array{name:string, emails:string[], phone:string, notes:string}>
     */
    public static function parse(string $text): array
    {
        $text = self::toUtf8($text);
        $text = str_replace(["\r\n", "\r"], "\n", $text);

        $cards = [];
        $card = null;
        foreach (self::logicalLines($text) as $line) {
            $line = rtrim($line);
            if ($line === '') {
                continue;
            }
            if (strcasecmp($line, 'BEGIN:VCARD') === 0) {
                $card = ['fn' => '', 'n' => '', 'emails' => [], 'tels' => [], 'notes' => ''];
                continue;
            }
            if (strcasecmp($line, 'END:VCARD') === 0) {
                if ($card !== null) {
                    $cards[] = self::finish($card);
                }
                $card = null;
                continue;
            }
            if ($card === null) {
                continue;
            }
            $prop = self::property($line);
            if ($prop === null) {
                continue;
            }
            [$name, $params, $value] = $prop;
            switch ($name) {
                case 'FN':
                    $card['fn'] = $value;
                    break;
                case 'N':
                    $card['n'] = $value;
                    break;
                case 'EMAIL':
                    $email = strtolower(trim(preg_replace('/^mailto:/i', '', $value) ?? $value));
                    if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
                        $card['emails'][] = $email;
                    }
                    break;
                case 'TEL':
                    $tel = trim(preg_replace('/^tel:/i', '', $value) ?? $value);
                    if ($tel !== '') {
                        $types = strtolower($params['TYPE'] ?? '') . ' ' . strtolower(implode(' ', array_keys($params)));
                        $score = (str_contains($types, 'pref') ? 2 : 0) + (str_contains($types, 'cell') ? 1 : 0);
                        $card['tels'][] = [$score, $tel];
                    }
                    break;
                case 'NOTE':
                    $card['notes'] = $value;
                    break;
            }
        }
        return $cards;
    }

    /**
     * vCard 3.0 for a list of contacts.
     * @param array<int, array{name?:?string, email:string, phone?:?string, notes?:?string}> $contacts
     */
    public static function write(array $contacts): string
    {
        $out = '';
        foreach ($contacts as $c) {
            $name = trim((string) ($c['name'] ?? ''));
            $lines = ['BEGIN:VCARD', 'VERSION:3.0'];
            $lines[] = 'FN:' . self::escape($name !== '' ? $name : $c['email']);
            $lines[] = 'N:' . self::nameField($name);
            $lines[] = 'EMAIL;TYPE=INTERNET:' . self::escape($c['email']);
            if (!empty($c['phone'])) {
                $lines[] = 'TEL;TYPE=CELL:' . self::escape((string) $c['phone']);
            }
            if (!empty($c['notes'])) {
                $lines[] = 'NOTE:' . self::escape((string) $c['notes']);
            }
            $lines[] = 'END:VCARD';
            foreach ($lines as $l) {
                $out .= self::fold($l) . "\r\n";
            }
        }
        return $out;
    }

    // ------------------------------------------------------------------ helpers

    /**
     * Join physical lines into logical ones: folded lines (starting with a space or tab,
     * RFC 6350 3.2) and vCard 2.1 quoted-printable soft breaks ("=" at the end of a line).
     * @return string[]
     */
    private static function logicalLines(string $text): array
    {
        $out = [];
        $qpOpen = false;
        foreach (explode("\n", $text) as $line) {
            $last = count($out) - 1;
            if ($qpOpen && $last >= 0) {
                $out[$last] = substr($out[$last], 0, -1) . $line;
            } elseif ($last >= 0 && $line !== '' && ($line[0] === ' ' || $line[0] === "\t")) {
                $out[$last] .= substr($line, 1);
            } else {
                $out[] = $line;
                $last++;
            }
            // Trailing spaces may matter in a folded line, so only trim for this check.
            $current = rtrim($out[$last]);
            $head = strstr($current, ':', true) ?: '';
            $qpOpen = stripos($head, 'QUOTED-PRINTABLE') !== false && str_ends_with($current, '=');
            if ($qpOpen) {
                $out[$last] = $current;
            }
        }
        return $out;
    }

    /** @return array{0:string, 1:array<string,string>, 2:string}|null */
    private static function property(string $line): ?array
    {
        // The value starts after the first colon that is not inside a quoted parameter.
        $inQuote = false;
        $pos = null;
        for ($i = 0, $len = strlen($line); $i < $len; $i++) {
            $ch = $line[$i];
            if ($ch === '"') {
                $inQuote = !$inQuote;
            } elseif ($ch === ':' && !$inQuote) {
                $pos = $i;
                break;
            }
        }
        if ($pos === null) {
            return null;
        }
        $head = explode(';', substr($line, 0, $pos));
        $value = substr($line, $pos + 1);

        $name = strtoupper((string) array_shift($head));
        if (str_contains($name, '.')) {
            $name = substr($name, strrpos($name, '.') + 1); // "item1.EMAIL" -> "EMAIL"
        }
        $params = [];
        foreach ($head as $p) {
            if (str_contains($p, '=')) {
                [$k, $v] = explode('=', $p, 2);
                $params[strtoupper(trim($k))] = trim($v, '"');
            } else {
                $params[strtoupper(trim($p))] = ''; // vCard 2.1: "TEL;CELL;PREF:..."
            }
        }

        if (strtoupper($params['ENCODING'] ?? '') === 'QUOTED-PRINTABLE' || array_key_exists('QUOTED-PRINTABLE', $params)) {
            $value = quoted_printable_decode($value);
        }
        if (!empty($params['CHARSET']) && strtoupper($params['CHARSET']) !== 'UTF-8') {
            $converted = @iconv($params['CHARSET'], 'UTF-8//IGNORE', $value);
            if ($converted !== false) {
                $value = $converted;
            }
        }
        return [$name, $params, $name === 'N' ? $value : self::unescape($value)];
    }

    private static function finish(array $card): array
    {
        $name = trim($card['fn']);
        if ($name === '' && $card['n'] !== '') {
            // N = Family;Given;Middle;Prefix;Suffix  ->  "Given Middle Family"
            $parts = array_map([self::class, 'unescape'], preg_split('/(?<!\\\\);/', $card['n']) ?: []);
            $name = trim(implode(' ', array_filter([$parts[3] ?? '', $parts[1] ?? '', $parts[2] ?? '', $parts[0] ?? '', $parts[4] ?? ''], 'strlen')));
        }
        usort($card['tels'], static fn ($a, $b) => $b[0] <=> $a[0]);
        return [
            'name'   => mb_substr($name, 0, 255),
            'emails' => array_values(array_unique($card['emails'])),
            'phone'  => mb_substr($card['tels'][0][1] ?? '', 0, 64),
            'notes'  => mb_substr(trim(str_replace(["\r\n", "\r"], "\n", $card['notes'])), 0, 5000),
        ];
    }

    private static function unescape(string $v): string
    {
        return strtr($v, ['\\n' => "\n", '\\N' => "\n", '\\,' => ',', '\\;' => ';', '\\\\' => '\\']);
    }

    private static function escape(string $v): string
    {
        return strtr($v, ['\\' => '\\\\', "\r\n" => '\\n', "\n" => '\\n', ',' => '\\,', ';' => '\\;']);
    }

    /** "Jan de Vries" -> "de Vries;Jan;;;" (best effort). */
    private static function nameField(string $name): string
    {
        $parts = preg_split('/\s+/', trim($name)) ?: [];
        if (count($parts) < 2) {
            return self::escape($name) . ';;;;';
        }
        $given = array_shift($parts);
        return self::escape(implode(' ', $parts)) . ';' . self::escape($given) . ';;;';
    }

    /** Fold lines longer than 75 octets without splitting UTF-8 characters. */
    private static function fold(string $line): string
    {
        if (strlen($line) <= 75) {
            return $line;
        }
        $out = '';
        $chunk = '';
        foreach (mb_str_split($line) as $ch) {
            if (strlen($chunk) + strlen($ch) > ($out === '' ? 75 : 74)) {
                $out .= ($out === '' ? '' : "\r\n ") . $chunk;
                $chunk = '';
            }
            $chunk .= $ch;
        }
        return $out . ($chunk !== '' ? "\r\n " . $chunk : '');
    }

    private static function toUtf8(string $text): string
    {
        if (str_starts_with($text, "\xEF\xBB\xBF")) {
            return substr($text, 3);
        }
        if (str_starts_with($text, "\xFF\xFE") || str_starts_with($text, "\xFE\xFF")) {
            return (string) mb_convert_encoding($text, 'UTF-8', 'UTF-16');
        }
        return mb_check_encoding($text, 'UTF-8') ? $text : (string) mb_convert_encoding($text, 'UTF-8', 'Windows-1252');
    }
}
