<?php
declare(strict_types=1);

namespace Postvak\Files;

use Postvak\Database;
use Postvak\Http\HttpException;

/**
 * Large attachments sent as a download link ("too big for e-mail").
 *
 * The browser uploads the file in small pieces (so the host's upload limit does not matter); it is stored in
 * storage/files/ outside the web root. Anyone with the link can download it until it expires; the cron job
 * removes expired files.
 */
final class SharedFiles
{
    public const MAX_BYTES = 2 * 1024 * 1024 * 1024;   // 2 GB per file
    public const CHUNK_BYTES = 4 * 1024 * 1024;        // pieces of at most 4 MB (below the usual post_max_size of 8 MB)
    public const DAYS = 30;                            // how long a link works
    public const MAX_ACTIVE_BYTES = 10 * 1024 * 1024 * 1024; // per user, all files that have not expired yet

    public static function dir(): string
    {
        $dir = POSTVAK_ROOT . '/storage/files';
        if (!is_dir($dir)) {
            @mkdir($dir, 0700, true);
            @file_put_contents($dir . '/.htaccess', "Require all denied\n");
        }
        return $dir;
    }

    public static function path(string $token): string
    {
        if (!preg_match('/^[a-f0-9]{32}$/', $token)) {
            throw HttpException::notFound('File not found');
        }
        return self::dir() . '/' . $token;
    }

    /** Start an upload. @return array the new file (incomplete) */
    public function create(int $userId, string $name, int $size, string $mime): array
    {
        $name = self::cleanName($name);
        if ($size <= 0 || $size > self::MAX_BYTES) {
            throw HttpException::badRequest('File too large (max 2 GB)');
        }
        $active = (int) (Database::one(
            'SELECT COALESCE(SUM(size), 0) AS n FROM shared_files WHERE user_id = ? AND expires_at > ?',
            [$userId, gmdate('Y-m-d H:i:s')]
        )['n'] ?? 0);
        if ($active + $size > self::MAX_ACTIVE_BYTES) {
            throw HttpException::badRequest('You have too many large files shared at the moment; remove some under Settings → Privacy');
        }
        $mime = preg_match('#^[\w.+-]+/[\w.+-]+$#', $mime) ? $mime : 'application/octet-stream';
        $token = bin2hex(random_bytes(16));
        $now = time();
        Database::run(
            'INSERT INTO shared_files (user_id, token, name, mime, size, created_at, expires_at) VALUES (?, ?, ?, ?, ?, ?, ?)',
            [$userId, $token, $name, $mime, $size, gmdate('Y-m-d H:i:s', $now), gmdate('Y-m-d H:i:s', $now + self::DAYS * 86400)]
        );
        touch(self::path($token));
        return $this->own($userId, $token);
    }

    /**
     * Append one piece. $offset must equal what the server already has (so a retried piece is not added twice).
     * @param resource $input
     */
    public function append(int $userId, string $token, int $offset, $input): array
    {
        $file = $this->own($userId, $token);
        if ($file['complete']) {
            return $file;
        }
        if ($offset !== $file['received']) {
            // The piece was already stored (a retry after a lost answer): tell the browser where we are.
            return $file;
        }
        $out = fopen(self::path($token), 'ab');
        if ($out === false) {
            throw new \RuntimeException('Cannot write to storage/files');
        }
        $written = stream_copy_to_stream($input, $out, self::CHUNK_BYTES + 1);
        fclose($out);
        if ($written === false || $written > self::CHUNK_BYTES || $file['received'] + $written > $file['size']) {
            $this->remove($userId, $token);
            throw HttpException::badRequest('Upload piece too large');
        }
        $received = $file['received'] + $written;
        Database::run(
            'UPDATE shared_files SET received = ?, complete = ? WHERE id = ?',
            [$received, $received === $file['size'] ? 1 : 0, $file['id']]
        );
        return $this->own($userId, $token);
    }

    /** @return array[] the user's finished files, newest first */
    public function listForUser(int $userId): array
    {
        $rows = Database::all(
            'SELECT * FROM shared_files WHERE user_id = ? AND complete = 1 AND expires_at > ? ORDER BY id DESC',
            [$userId, gmdate('Y-m-d H:i:s')]
        );
        return array_map([self::class, 'present'], $rows);
    }

    public function remove(int $userId, string $token): void
    {
        $this->own($userId, $token);
        Database::run('DELETE FROM shared_files WHERE token = ? AND user_id = ?', [$token, $userId]);
        @unlink(self::path($token));
    }

    /** A file anyone with the link may download (complete and not expired), or null. */
    public function public(string $token): ?array
    {
        if (!preg_match('/^[a-f0-9]{32}$/', $token)) {
            return null;
        }
        $row = Database::one(
            'SELECT * FROM shared_files WHERE token = ? AND complete = 1 AND expires_at > ?',
            [$token, gmdate('Y-m-d H:i:s')]
        );
        return $row && is_file(self::path($token)) ? self::present($row) : null;
    }

    public function countDownload(string $token): void
    {
        Database::run('UPDATE shared_files SET downloads = downloads + 1 WHERE token = ?', [$token]);
    }

    /** Remove expired files and uploads that were never finished. @return int number removed */
    public function cleanup(): int
    {
        $rows = Database::all(
            'SELECT id, token FROM shared_files WHERE expires_at <= ? OR (complete = 0 AND created_at <= ?)',
            [gmdate('Y-m-d H:i:s'), gmdate('Y-m-d H:i:s', time() - 86400)]
        );
        foreach ($rows as $row) {
            Database::run('DELETE FROM shared_files WHERE id = ?', [$row['id']]);
            @unlink(self::path((string) $row['token']));
        }
        // Files on disk without a row (e.g. after a failed insert).
        foreach (glob(self::dir() . '/*') ?: [] as $path) {
            $token = basename($path);
            if (preg_match('/^[a-f0-9]{32}$/', $token) && filemtime($path) < time() - 86400
                && !Database::one('SELECT id FROM shared_files WHERE token = ?', [$token])) {
                @unlink($path);
            }
        }
        return count($rows);
    }

    private function own(int $userId, string $token): array
    {
        $row = Database::one('SELECT * FROM shared_files WHERE token = ? AND user_id = ?', [$token, $userId]);
        if ($row === null) {
            throw HttpException::notFound('File not found');
        }
        return self::present($row);
    }

    public static function present(array $row): array
    {
        return [
            'id'         => (int) $row['id'],
            'token'      => (string) $row['token'],
            'name'       => (string) $row['name'],
            'mime'       => (string) $row['mime'],
            'size'       => (int) $row['size'],
            'received'   => (int) $row['received'],
            'complete'   => (bool) $row['complete'],
            'downloads'  => (int) $row['downloads'],
            'created_at' => gmdate(DATE_ATOM, strtotime($row['created_at'] . ' UTC')),
            'expires_at' => gmdate(DATE_ATOM, strtotime($row['expires_at'] . ' UTC')),
            'url'        => '/api/files/' . $row['token'],
        ];
    }

    /** A safe file name: no paths, no control characters, at most 200 characters. */
    public static function cleanName(string $name): string
    {
        $name = basename(str_replace('\\', '/', $name));
        $name = trim(preg_replace('/[\x00-\x1f\x7f]+/u', '', $name) ?? '');
        if ($name === '' || $name === '.' || $name === '..') {
            $name = 'file';
        }
        return mb_substr($name, 0, 200);
    }
}
