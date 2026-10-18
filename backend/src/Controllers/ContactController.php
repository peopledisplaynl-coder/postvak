<?php
declare(strict_types=1);

namespace Postvak\Controllers;

use Postvak\Auth;
use Postvak\Contacts\VCard;
use Postvak\Http\HttpException;
use Postvak\Http\Request;
use Postvak\Http\Response;
use Postvak\Repositories\ContactRepository;

/** Address book: autocomplete, contacts page, vCard import/export. */
final class ContactController
{
    private const MAX_IMPORT_BYTES = 5 * 1024 * 1024;

    public function __construct(private readonly ContactRepository $repo = new ContactRepository()) {}

    /** GET /contacts?q=jan — address autocomplete in the composer. */
    public function suggest(Request $r): void
    {
        Response::json($this->repo->suggest(Auth::requireUser(), (string) $r->query('q', '')));
    }

    /** GET /contacts/list?q=&saved=1 */
    public function index(Request $r): void
    {
        $userId = Auth::requireUser();
        Response::json($this->repo->list($userId, (string) $r->query('q', ''), (string) $r->query('saved', '') === '1'));
    }

    /** POST /contacts  {email, name?, phone?, notes?, favorite?} */
    public function store(Request $r): void
    {
        $data = $this->validate($r->body(), true);
        Response::json($this->repo->save(Auth::requireUser(), $data), 201);
    }

    /** PATCH /contacts/{id} */
    public function update(Request $r): void
    {
        $data = $this->validate($r->body(), false);
        Response::json($this->repo->update(Auth::requireUser(), $r->intParam('id'), $data));
    }

    /** DELETE /contacts/{id} */
    public function destroy(Request $r): void
    {
        $this->repo->delete(Auth::requireUser(), $r->intParam('id'));
        Response::noContent();
    }

    /** POST /contacts/import  multipart: file (.vcf) */
    public function import(Request $r): void
    {
        $userId = Auth::requireUser();
        $file = $_FILES['file'] ?? null;
        if (!is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'])) {
            throw HttpException::badRequest('No file received');
        }
        if ($file['size'] > self::MAX_IMPORT_BYTES) {
            throw HttpException::badRequest('File too large (max 5 MB)');
        }
        $text = (string) file_get_contents($file['tmp_name']);
        if (stripos($text, 'BEGIN:VCARD') === false) {
            throw HttpException::badRequest('This is not a vCard file (.vcf)');
        }
        $cards = VCard::parse($text);
        Response::json(['cards' => count($cards), ...$this->repo->import($userId, $cards)]);
    }

    /** GET /contacts/export?all=1 — vCard file of the saved contacts (or all). */
    public function export(Request $r): void
    {
        $userId = Auth::requireUser();
        $contacts = $this->repo->list($userId, '', (string) $r->query('all', '') !== '1');
        Response::download(VCard::write($contacts), 'postvak-contacten.vcf', 'text/vcard');
    }

    /** @return array<string, mixed> */
    private function validate(array $in, bool $create): array
    {
        $out = [];
        if ($create || array_key_exists('email', $in)) {
            $email = strtolower(trim((string) ($in['email'] ?? '')));
            if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 255) {
                throw HttpException::badRequest('Invalid e-mail address');
            }
            $out['email'] = $email;
        }
        $limits = ['name' => 255, 'phone' => 64, 'notes' => 5000];
        foreach ($limits as $field => $max) {
            if (array_key_exists($field, $in)) {
                $value = trim((string) $in[$field]);
                if (mb_strlen($value) > $max) {
                    throw HttpException::badRequest("Field '$field' is too long");
                }
                if ($field === 'phone' && $value !== '' && !preg_match('/^[0-9+()\-.\s\/]{2,64}$/', $value)) {
                    throw HttpException::badRequest('Invalid phone number');
                }
                $out[$field] = $value === '' ? null : $value;
            }
        }
        if (array_key_exists('favorite', $in)) {
            $out['favorite'] = (bool) $in['favorite'];
        }
        return $out;
    }
}
