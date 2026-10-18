<?php
declare(strict_types=1);

namespace Postvak\Controllers;

use Postvak\Auth;
use Postvak\Database;
use Postvak\Http\HttpException;
use Postvak\Http\Request;
use Postvak\Http\Response;

/**
 * Images for signatures (a company logo). The browser scales them down before uploading; when a mail is
 * sent they become inline images (Mail\SmtpService::embedSignatureImages), so recipients see them without
 * "load remote images".
 */
final class SignatureImageController
{
    public const MAX_BYTES = 300_000;
    public const TYPES = ['image/png' => 'png', 'image/jpeg' => 'jpg', 'image/gif' => 'gif', 'image/webp' => 'webp'];
    private const MAX_PER_USER = 20;

    /** POST /signature-images (multipart: file) → {url} */
    public function store(Request $r): void
    {
        $userId = Auth::requireUser();
        $file = $_FILES['file'] ?? null;
        if (!is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'])) {
            throw HttpException::badRequest('No image received');
        }
        if ($file['size'] > self::MAX_BYTES) {
            throw HttpException::badRequest('Image too large (max 300 kB)');
        }
        $data = (string) file_get_contents($file['tmp_name']);
        $info = @getimagesizefromstring($data);
        $mime = is_array($info) ? (string) ($info['mime'] ?? '') : '';
        if (!isset(self::TYPES[$mime])) {
            throw HttpException::badRequest('Only PNG, JPEG, GIF or WebP images');
        }
        // Keep the newest few: images not used in any signature any more are cleaned up here.
        $old = Database::all('SELECT id FROM signature_images WHERE user_id = ? ORDER BY id DESC LIMIT 1000 OFFSET ' . (self::MAX_PER_USER - 1), [$userId]);
        foreach ($old as $row) {
            $this->deleteIfUnused($userId, (int) $row['id']);
        }
        $token = bin2hex(random_bytes(16));
        Database::run('INSERT INTO signature_images (user_id, token, mime, data) VALUES (?, ?, ?, ?)', [$userId, $token, $mime, $data]);
        Response::json(['url' => '/api/signature-images/' . $token, 'width' => $info[0] ?? null, 'height' => $info[1] ?? null], 201);
    }

    /** GET /signature-images/{token} — for the signature editor and the composer (own images only). */
    public function show(Request $r): void
    {
        $userId = Auth::requireUser();
        $row = Database::one('SELECT mime, data FROM signature_images WHERE token = ? AND user_id = ?', [$r->param('token'), $userId]);
        if ($row === null) {
            throw HttpException::notFound('Image not found');
        }
        Response::download((string) $row['data'], 'signature.' . self::TYPES[$row['mime']], (string) $row['mime'], true);
    }

    private function deleteIfUnused(int $userId, int $id): void
    {
        $row = Database::one('SELECT token FROM signature_images WHERE id = ? AND user_id = ?', [$id, $userId]);
        if ($row === null) {
            return;
        }
        $used = Database::one('SELECT id FROM accounts WHERE user_id = ? AND signature_html LIKE ?', [$userId, '%' . $row['token'] . '%']);
        if ($used === null) {
            Database::run('DELETE FROM signature_images WHERE id = ?', [$id]);
        }
    }
}
