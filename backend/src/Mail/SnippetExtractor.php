<?php
declare(strict_types=1);

namespace Postvak\Mail;

use Throwable;

/**
 * Builds the short preview line for the message list from the first few kB of a
 * message body (fetched with BODY.PEEK[TEXT]<0.N>) and its header.
 *
 * Works on truncated data: multipart boundaries, base64 and quoted-printable
 * are handled as far as the fetched bytes go.
 */
final class SnippetExtractor
{
    public const FETCH_BYTES = 4096;
    private const LENGTH = 160;

    public static function extract(string $rawHeader, string $bodyStart): string
    {
        try {
            $text = self::fromPart(self::headers($rawHeader), $bodyStart, 0);
        } catch (Throwable) {
            return '';
        }
        return self::clip($text);
    }

    private static function fromPart(array $headers, string $body, int $depth): string
    {
        [$type, $params] = self::contentType($headers['content-type'] ?? 'text/plain');

        if (str_starts_with($type, 'multipart/') && $depth < 5 && !empty($params['boundary'])) {
            $html = '';
            foreach (self::splitParts($body, $params['boundary']) as $part) {
                [$partHeaderRaw, $partBody] = self::splitHeaderBody($part);
                $partHeaders = self::headers($partHeaderRaw);
                [$partType] = self::contentType($partHeaders['content-type'] ?? 'text/plain');
                if (str_contains(strtolower($partHeaders['content-disposition'] ?? ''), 'attachment')) {
                    continue;
                }
                if ($partType === 'text/plain' || str_starts_with($partType, 'multipart/')) {
                    $text = self::fromPart($partHeaders, $partBody, $depth + 1);
                    if (trim($text) !== '') {
                        return $text;
                    }
                } elseif ($partType === 'text/html' && $html === '') {
                    $html = self::fromPart($partHeaders, $partBody, $depth + 1);
                }
            }
            return $html;
        }

        if ($type !== 'text/plain' && $type !== 'text/html') {
            return '';
        }

        $decoded = self::decode($body, strtolower(trim($headers['content-transfer-encoding'] ?? '7bit')));
        $decoded = self::toUtf8($decoded, $params['charset'] ?? 'utf-8');

        return $type === 'text/html' ? self::htmlToText($decoded) : self::plainToText($decoded);
    }

    /** @return array<string, string> lower-case header name => unfolded value */
    private static function headers(string $raw): array
    {
        $raw = preg_replace("/\r?\n[ \t]+/", ' ', $raw) ?? $raw;
        $out = [];
        foreach (preg_split("/\r?\n/", $raw) ?: [] as $line) {
            if (preg_match('/^([A-Za-z0-9-]+):\s*(.*)$/', $line, $m)) {
                $out[strtolower($m[1])] ??= trim($m[2]);
            }
        }
        return $out;
    }

    /** @return array{0:string, 1:array<string,string>} */
    private static function contentType(string $value): array
    {
        $parts = explode(';', $value);
        $type = strtolower(trim(array_shift($parts)));
        $params = [];
        foreach ($parts as $p) {
            if (preg_match('/^\s*([a-z0-9*-]+)\s*=\s*"?([^";]*)"?/i', $p, $m)) {
                $params[strtolower($m[1])] = $m[2];
            }
        }
        return [$type ?: 'text/plain', $params];
    }

    /** @return string[] */
    private static function splitParts(string $body, string $boundary): array
    {
        $chunks = preg_split('/^--' . preg_quote($boundary, '/') . '(?:--)?[ \t]*\r?$/m', $body) ?: [];
        array_shift($chunks); // preamble
        return array_values(array_filter($chunks, static fn ($c) => trim($c) !== ''));
    }

    /** @return array{0:string, 1:string} */
    private static function splitHeaderBody(string $part): array
    {
        $part = ltrim($part, "\r\n");
        $pos = preg_match("/\r?\n\r?\n/", $part, $m, PREG_OFFSET_CAPTURE) ? $m[0][1] : false;
        if ($pos === false) {
            return [$part, ''];
        }
        return [substr($part, 0, $pos), substr($part, $pos + strlen($m[0][0]))];
    }

    private static function decode(string $body, string $encoding): string
    {
        if ($encoding === 'base64') {
            $clean = preg_replace('/[^A-Za-z0-9+\/=]/', '', $body) ?? '';
            $clean = substr($clean, 0, strlen($clean) - strlen($clean) % 4); // may be cut off mid-way
            return (string) base64_decode($clean);
        }
        if ($encoding === 'quoted-printable') {
            $body = preg_replace('/=[0-9A-Fa-f]?$/', '', $body) ?? $body; // half escape at the cut
            return quoted_printable_decode($body);
        }
        return $body;
    }

    private static function toUtf8(string $text, string $charset): string
    {
        $charset = strtolower(trim($charset, " \"'"));
        if ($charset === '' || $charset === 'utf-8' || $charset === 'us-ascii') {
            return mb_convert_encoding($text, 'UTF-8', 'UTF-8'); // drops invalid / cut-off bytes
        }
        $aliases = ['iso-8859-1' => 'ISO-8859-1', 'latin1' => 'ISO-8859-1', 'windows-1252' => 'Windows-1252', 'cp1252' => 'Windows-1252', 'iso-8859-15' => 'ISO-8859-15'];
        $from = $aliases[$charset] ?? $charset;
        $converted = @iconv($from, 'UTF-8//IGNORE', $text);
        if ($converted === false) {
            try {
                $converted = mb_convert_encoding($text, 'UTF-8', $from);
            } catch (Throwable) {
                $converted = mb_convert_encoding($text, 'UTF-8', 'UTF-8');
            }
        }
        return $converted;
    }

    private static function htmlToText(string $html): string
    {
        $html = preg_replace('#<(head|style|script|title)\b[^>]*>.*?(</\1>|$)#is', ' ', $html) ?? $html;
        $html = preg_replace('#<br\s*/?>|</(p|div|tr|li|h[1-6])>#i', "\n", $html) ?? $html;
        $text = strip_tags($html);
        $text = preg_replace('/<[^>]*$/', '', $text) ?? $text; // tag cut off at the end
        return html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    private static function plainToText(string $text): string
    {
        // Leave out quoted replies and the usual "On ... wrote:" line.
        $lines = array_filter(preg_split("/\r?\n/", $text) ?: [], static fn ($l) => !str_starts_with(ltrim($l), '>'));
        return implode("\n", $lines);
    }

    private static function clip(string $text): string
    {
        $text = trim(preg_replace('/\s+/u', ' ', $text) ?? '');
        if (mb_strlen($text) <= self::LENGTH) {
            return $text;
        }
        $cut = mb_substr($text, 0, self::LENGTH);
        $space = mb_strrpos($cut, ' ');
        return rtrim($space !== false && $space > self::LENGTH - 30 ? mb_substr($cut, 0, $space) : $cut, ' ,.;:') . '…';
    }
}
