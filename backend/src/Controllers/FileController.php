<?php
declare(strict_types=1);

namespace Postvak\Controllers;

use Postvak\Auth;
use Postvak\Files\SharedFiles;
use Postvak\Http\Request;
use Postvak\Http\Response;

/** Large attachments as a download link: upload in pieces, the list in Settings, and the public download page. */
final class FileController
{
    private SharedFiles $files;

    public function __construct()
    {
        $this->files = new SharedFiles();
    }

    /** GET /files → {files, chunk_bytes, max_bytes, days} */
    public function index(Request $r): void
    {
        $userId = Auth::requireUser();
        Response::json([
            'files'       => $this->files->listForUser($userId),
            'chunk_bytes' => SharedFiles::CHUNK_BYTES,
            'max_bytes'   => SharedFiles::MAX_BYTES,
            'days'        => SharedFiles::DAYS,
            // Normal attachments above this (in total) cannot be sent: the config limit, or the host's upload limit.
            'attach_max_bytes' => min(
                (int) \Postvak\Config::get('max_attachment_mb', 20) * 1024 * 1024,
                self::iniBytes((string) ini_get('upload_max_filesize')) ?: PHP_INT_MAX,
                self::iniBytes((string) ini_get('post_max_size')) ?: PHP_INT_MAX,
            ),
        ]);
    }

    /** POST /files {name, size, mime} → the new (empty) file with its token */
    public function create(Request $r): void
    {
        $userId = Auth::requireUser();
        session_write_close();
        Response::json($this->files->create($userId, $r->string('name'), (int) $r->input('size', 0), (string) $r->input('mime', '')), 201);
    }

    /** POST /files/{token}/chunk?offset=N  (body: the raw bytes of one piece) */
    public function chunk(Request $r): void
    {
        $userId = Auth::requireUser();
        session_write_close();
        @set_time_limit(300);
        $input = fopen('php://input', 'rb');
        Response::json($this->files->append($userId, (string) $r->param('token'), (int) $r->query('offset', 0), $input));
    }

    /** DELETE /files/{token} */
    public function destroy(Request $r): void
    {
        $this->files->remove(Auth::requireUser(), (string) $r->param('token'));
        Response::json(['ok' => true]);
    }

    /** GET /files/{token} — public page with the file name, size and a download button. */
    public function page(Request $r): void
    {
        $file = $this->files->public((string) $r->param('token'));
        $nl = str_starts_with(strtolower((string) ($_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? '')), 'nl');
        $tr = $nl
            ? ['title' => 'Bestand downloaden', 'gone' => 'Deze link werkt niet meer', 'goneText' => 'Het bestand is verlopen of verwijderd. Vraag de afzender om het opnieuw te sturen.', 'download' => 'Downloaden', 'until' => 'Beschikbaar tot %s', 'via' => 'Gedeeld via Postvak']
            : ['title' => 'Download file', 'gone' => 'This link no longer works', 'goneText' => 'The file has expired or was removed. Ask the sender to send it again.', 'download' => 'Download', 'until' => 'Available until %s', 'via' => 'Shared with Postvak'];
        $e = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');

        if ($file === null) {
            http_response_code(404);
            $content = '<h1>' . $e($tr['gone']) . '</h1><p class="muted">' . $e($tr['goneText']) . '</p>';
            $title = $tr['gone'];
        } else {
            $until = $nl ? self::dateNl($file['expires_at']) : gmdate('j F Y', strtotime($file['expires_at']));
            $content = '<div class="icon">⬇</div><h1>' . $e($file['name']) . '</h1>'
                . '<p class="muted">' . $e(self::size($file['size'], $nl)) . ' · ' . $e(sprintf($tr['until'], $until)) . '</p>'
                . '<a class="button" href="' . $e($file['url']) . '/download">' . $e($tr['download']) . '</a>';
            $title = $file['name'];
        }

        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        header('Content-Type: text/html; charset=utf-8');
        header('Cache-Control: no-store');
        header('X-Robots-Tag: noindex, nofollow');
        echo '<!doctype html><html lang="' . ($nl ? 'nl' : 'en') . '"><head><meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex">'
            . '<title>' . $e($title) . '</title><style>'
            . ':root{color-scheme:light dark;--bg:#f3f4f7;--card:#fff;--ink:#1b1f2a;--muted:#677385;--accent:#3646D9}'
            . '@media (prefers-color-scheme:dark){:root{--bg:#12141a;--card:#1c1f27;--ink:#e8eaf0;--muted:#98a1b3;--accent:#7d89ff}}'
            . 'body{margin:0;min-height:100vh;display:flex;align-items:center;justify-content:center;background:var(--bg);color:var(--ink);font:16px/1.5 system-ui,-apple-system,"Segoe UI",sans-serif;padding:16px;box-sizing:border-box}'
            . 'main{background:var(--card);border-radius:20px;padding:40px 28px;max-width:440px;width:100%;text-align:center;box-shadow:0 1px 3px rgba(0,0,0,.08)}'
            . 'h1{font-size:20px;margin:12px 0 4px;word-break:break-word}.muted{color:var(--muted);margin:0 0 24px}'
            . '.icon{font-size:28px;width:56px;height:56px;line-height:56px;margin:0 auto;border-radius:16px;background:var(--accent);color:#fff}'
            . '.button{display:inline-block;background:var(--accent);color:#fff;text-decoration:none;font-weight:600;padding:12px 28px;border-radius:12px}'
            . 'footer{margin-top:28px;font-size:13px;color:var(--muted)}</style></head><body><main>'
            . $content . '<footer>' . $e($tr['via']) . '</footer></main></body></html>';
        exit;
    }

    /** GET /files/{token}/download — the file itself. */
    public function download(Request $r): void
    {
        $token = (string) $r->param('token');
        $file = $this->files->public($token);
        if ($file === null) {
            Response::json(['error' => 'Not found'], 404);
        }
        session_write_close();
        @set_time_limit(0);
        $this->files->countDownload($token);
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        $safe = preg_replace('/[^\w.\- ]+/u', '_', $file['name']) ?: 'file';
        header('Content-Type: application/octet-stream');
        header('Content-Length: ' . $file['size']);
        header(sprintf("Content-Disposition: attachment; filename=\"%s\"; filename*=UTF-8''%s", $safe, rawurlencode($file['name'])));
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: no-store');
        header('X-Robots-Tag: noindex, nofollow');
        readfile(SharedFiles::path($token));
        exit;
    }

    /** "64M" → bytes (0 = no limit). */
    public static function iniBytes(string $value): int
    {
        $value = trim($value);
        if ($value === '' || $value === '-1') {
            return 0;
        }
        $n = (int) $value;
        return match (strtolower(substr($value, -1))) {
            'g' => $n * 1024 ** 3,
            'm' => $n * 1024 ** 2,
            'k' => $n * 1024,
            default => $n,
        };
    }

    private static function size(int $bytes, bool $nl): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $i = 0;
        $n = (float) $bytes;
        while ($n >= 1024 && $i < 3) {
            $n /= 1024;
            $i++;
        }
        $text = $i === 0 ? (string) $bytes : number_format($n, $n < 10 ? 1 : 0, $nl ? ',' : '.', '');
        return $text . ' ' . $units[$i];
    }

    private static function dateNl(string $iso): string
    {
        $months = ['januari', 'februari', 'maart', 'april', 'mei', 'juni', 'juli', 'augustus', 'september', 'oktober', 'november', 'december'];
        $ts = strtotime($iso);
        return gmdate('j', $ts) . ' ' . $months[(int) gmdate('n', $ts) - 1] . ' ' . gmdate('Y', $ts);
    }
}
