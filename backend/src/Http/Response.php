<?php
declare(strict_types=1);

namespace Postvak\Http;

final class Response
{
    public static function json(mixed $data, int $status = 200): never
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        exit;
    }

    public static function noContent(): never
    {
        http_response_code(204);
        exit;
    }

    /** Stream a file download (attachments). */
    public static function download(string $content, string $filename, string $mime, bool $inline = false): never
    {
        $safe = preg_replace('/[^\w.\- ]+/u', '_', $filename) ?: 'attachment';
        http_response_code(200);
        header('Content-Type: ' . ($inline ? $mime : 'application/octet-stream'));
        header('Content-Length: ' . strlen($content));
        header(sprintf(
            "Content-Disposition: %s; filename=\"%s\"; filename*=UTF-8''%s",
            $inline ? 'inline' : 'attachment',
            $safe,
            rawurlencode($filename)
        ));
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: private, max-age=3600');
        echo $content;
        exit;
    }
}
