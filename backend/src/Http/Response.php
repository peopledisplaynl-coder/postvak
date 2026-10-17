<?php
declare(strict_types=1);

namespace Postvak\Http;

final class Response
{
    public static function json(mixed $data, int $status = 200): never
    {
        $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR);
        if ($json === false) {
            error_log('[postvak] json_encode failed: ' . json_last_error_msg());
            $status = 500;
            $json = '{"error":"Could not encode the response"}';
        }
        self::discardOutput();
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        echo $json;
        exit;
    }

    public static function noContent(): never
    {
        self::discardOutput();
        http_response_code(204);
        exit;
    }

    /** Throw away anything printed before the response (notices, library debug output, BOMs). */
    private static function discardOutput(): void
    {
        while (ob_get_level() > 0) {
            $stray = ob_get_clean();
            if ($stray !== false && trim($stray) !== '') {
                error_log('[postvak] discarded stray output: ' . substr(trim($stray), 0, 300));
            }
        }
    }

    /** Stream a file download (attachments). */
    public static function download(string $content, string $filename, string $mime, bool $inline = false): never
    {
        $safe = preg_replace('/[^\w.\- ]+/u', '_', $filename) ?: 'attachment';
        self::discardOutput();
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
