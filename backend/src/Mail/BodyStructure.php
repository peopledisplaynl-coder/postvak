<?php
declare(strict_types=1);

namespace Postvak\Mail;

/**
 * Reads IMAP BODYSTRUCTURE responses (RFC 3501 §7.4.2) to list a message's attachments without downloading it.
 * The IMAP library's own parser breaks on quoted strings next to parentheses, so the raw response is parsed here.
 */
final class BodyStructure
{
    /**
     * Parse the raw lines of "UID FETCH … (UID BODYSTRUCTURE)".
     * @param string[] $lines
     * @return array<int, array> uid => bodystructure as nested arrays (strings, null for NIL)
     */
    public static function parseFetch(array $lines): array
    {
        $text = implode('', array_map('strval', $lines));
        $out = [];
        $pos = 0;
        $len = strlen($text);
        while ($pos < $len) {
            if (!preg_match('/\G\s*(?:\*\s+)?\d+\s+FETCH\s+/Ai', $text, $m, 0, $pos)) {
                break;
            }
            $pos += strlen($m[0]);
            $items = self::value($text, $pos);
            if (!is_array($items)) {
                break;
            }
            $uid = null;
            $structure = null;
            for ($i = 0; $i + 1 < count($items); $i += 2) {
                $key = strtoupper((string) $items[$i]);
                if ($key === 'UID') {
                    $uid = (int) $items[$i + 1];
                } elseif ($key === 'BODYSTRUCTURE') {
                    $structure = $items[$i + 1];
                }
            }
            if ($uid !== null && is_array($structure)) {
                $out[$uid] = $structure;
            }
            // Skip to the next response line.
            $next = strpos($text, "\n", $pos);
            $pos = $next === false ? $len : $next + 1;
        }
        return $out;
    }

    /**
     * The attachments in a bodystructure: files the sender attached, not the text or images inside the HTML.
     * @return array<int, array{name:string, mime:string, size:int}>
     */
    public static function attachments(array $structure): array
    {
        $out = [];
        self::walk($structure, $out);
        return $out;
    }

    private static function walk(array $part, array &$out): void
    {
        if (isset($part[0]) && is_array($part[0])) {
            foreach ($part as $child) {
                if (is_array($child)) {
                    self::walk($child, $out);
                }
            }
            return;
        }
        $type = strtolower((string) ($part[0] ?? ''));
        $subtype = strtolower((string) ($part[1] ?? ''));
        $params = self::params($part[2] ?? null);
        $id = $part[3] ?? null;
        $encoding = strtolower((string) ($part[5] ?? ''));
        $size = (int) ($part[6] ?? 0);
        $dispIndex = match (true) {
            $type === 'text' => 9,
            $type === 'message' && $subtype === 'rfc822' => 11,
            default => 8,
        };
        $disposition = $part[$dispIndex] ?? null;
        $dispType = is_array($disposition) ? strtolower((string) ($disposition[0] ?? '')) : '';
        $dispParams = is_array($disposition) ? self::params($disposition[1] ?? null) : [];

        $name = self::name($dispParams, 'filename') ?? self::name($params, 'name');
        $isAttachment = $dispType === 'attachment'
            || ($name !== null && $type !== 'text' && !($dispType === 'inline' && $id !== null) && !($dispType === '' && $id !== null));
        if (!$isAttachment) {
            return;
        }
        $out[] = [
            'name' => $name ?? ($type === 'message' ? 'message.eml' : 'attachment'),
            'mime' => "$type/$subtype",
            'size' => $encoding === 'base64' ? (int) floor($size * 3 / 4) : $size,
        ];
    }

    /** ("name" "x.pdf" "charset" "utf-8") → ['name' => 'x.pdf', …] (keys lowercased) */
    private static function params(mixed $list): array
    {
        $out = [];
        if (!is_array($list)) {
            return $out;
        }
        for ($i = 0; $i + 1 < count($list); $i += 2) {
            if (is_string($list[$i])) {
                $out[strtolower($list[$i])] = is_string($list[$i + 1]) ? $list[$i + 1] : '';
            }
        }
        return $out;
    }

    /** A file name from parameters: plain, RFC 2231 (name*=utf-8''…, also split over name*0*, name*1*) or MIME words. */
    private static function name(array $params, string $key): ?string
    {
        if (isset($params[$key]) && $params[$key] !== '') {
            return self::clean(mb_decode_mimeheader($params[$key]));
        }
        $extended = $params["$key*"] ?? null;
        if ($extended === null) {
            $pieces = [];
            for ($i = 0; $i < 50; $i++) {
                if (isset($params["$key*$i*"])) {
                    $pieces[] = $params["$key*$i*"];
                } elseif (isset($params["$key*$i"])) {
                    $pieces[] = rawurlencode($params["$key*$i"]);
                } else {
                    break;
                }
            }
            $extended = $pieces !== [] ? implode('', $pieces) : null;
        }
        if ($extended === null) {
            return null;
        }
        $charset = 'UTF-8';
        if (preg_match("/^([^']*)'[^']*'(.*)$/s", $extended, $m)) {
            $charset = $m[1] !== '' ? $m[1] : 'UTF-8';
            $extended = $m[2];
        }
        $value = rawurldecode($extended);
        if (strtoupper($charset) !== 'UTF-8') {
            $value = @mb_convert_encoding($value, 'UTF-8', $charset) ?: $value;
        }
        return self::clean($value);
    }

    private static function clean(string $name): ?string
    {
        $name = trim(preg_replace('/[\x00-\x1F\x7F]+/', '', $name) ?? '');
        return $name !== '' ? mb_substr($name, 0, 255) : null;
    }

    /** One value at $pos: list, quoted string, literal, NIL or atom. */
    private static function value(string $s, int &$pos): mixed
    {
        $len = strlen($s);
        while ($pos < $len && ($s[$pos] === ' ' || $s[$pos] === "\r" || $s[$pos] === "\n")) {
            $pos++;
        }
        if ($pos >= $len) {
            return null;
        }
        $c = $s[$pos];
        if ($c === '(') {
            $pos++;
            $list = [];
            while (true) {
                while ($pos < $len && ($s[$pos] === ' ' || $s[$pos] === "\r" || $s[$pos] === "\n")) {
                    $pos++;
                }
                if ($pos >= $len) {
                    return $list;
                }
                if ($s[$pos] === ')') {
                    $pos++;
                    return $list;
                }
                $list[] = self::value($s, $pos);
            }
        }
        if ($c === '"') {
            $pos++;
            $out = '';
            while ($pos < $len && $s[$pos] !== '"') {
                if ($s[$pos] === '\\' && $pos + 1 < $len) {
                    $pos++;
                }
                $out .= $s[$pos++];
            }
            $pos++;
            return $out;
        }
        if ($c === '{' && preg_match('/\G\{(\d+)\}\r?\n/A', $s, $m, 0, $pos)) {
            $pos += strlen($m[0]);
            $out = substr($s, $pos, (int) $m[1]);
            $pos += (int) $m[1];
            return $out;
        }
        $start = $pos;
        while ($pos < $len && !in_array($s[$pos], [' ', '(', ')', "\r", "\n"], true)) {
            $pos++;
        }
        $atom = substr($s, $start, $pos - $start);
        if ($pos === $start) {
            $pos++; // a stray ")" or the like: skip it
            return null;
        }
        return strtoupper($atom) === 'NIL' ? null : $atom;
    }
}
