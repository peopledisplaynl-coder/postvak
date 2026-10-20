<?php
declare(strict_types=1);

namespace Postvak\Controllers;

use Postvak\Auth;
use Postvak\Contacts\ContactDetails;
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

    /** GET /contacts/{id}/photo */
    public function photo(Request $r): void
    {
        $photo = $this->repo->photo(Auth::requireUser(), $r->intParam('id'));
        if ($photo === null) {
            throw HttpException::notFound('No photo');
        }
        Response::download($photo[0], 'contact', $photo[1], true);
    }

    /** POST /contacts/{id}/photo  multipart: file (JPEG/PNG/WebP, max 1 MB; the browser makes it small) */
    public function setPhoto(Request $r): void
    {
        $userId = Auth::requireUser();
        $file = $_FILES['file'] ?? null;
        if (!is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'])) {
            throw HttpException::badRequest('No photo received');
        }
        if ($file['size'] > 1024 * 1024) {
            throw HttpException::badRequest('Photo too large (max 1 MB)');
        }
        $bytes = (string) file_get_contents($file['tmp_name']);
        $info = @getimagesizefromstring($bytes);
        $mime = is_array($info) ? (string) ($info['mime'] ?? '') : '';
        if (!in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true)) {
            throw HttpException::badRequest('Only JPEG, PNG or WebP photos');
        }
        Response::json($this->repo->setPhoto($userId, $r->intParam('id'), $bytes, $mime));
    }

    /** DELETE /contacts/{id}/photo */
    public function removePhoto(Request $r): void
    {
        Response::json($this->repo->setPhoto(Auth::requireUser(), $r->intParam('id'), null, null));
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
        // Full details (company, addresses, birthday, …) from the same cards, read with sabre/vobject.
        $details = self::detailsOf($text);
        if (count($details) === count($cards)) {
            foreach ($cards as $i => &$card) {
                $card['details'] = $details[$i];
            }
            unset($card);
        }
        Response::json(['cards' => count($cards), ...$this->repo->import($userId, $cards)]);
    }

    /** @return array[] details per card, in file order (empty when the file cannot be read this way) */
    private static function detailsOf(string $text): array
    {
        $out = [];
        try {
            $splitter = new \Sabre\VObject\Splitter\VCard($text, \Sabre\VObject\Reader::OPTION_FORGIVING);
            while ($card = $splitter->getNext()) {
                $out[] = ContactDetails::fromCard($card);
            }
        } catch (\Throwable) {
            return [];
        }
        return $out;
    }

    /** GET /contacts/export?all=1 — vCard file of the saved contacts (or all). */
    public function export(Request $r): void
    {
        $userId = Auth::requireUser();
        $all = (string) $r->query('all', '') === '1';
        // Full cards: what a phone added (photo, birthday, address, …) is exported too.
        $rows = \Postvak\Database::all(
            'SELECT * FROM contacts WHERE user_id = ?' . ($all ? '' : ' AND saved = 1') . ' ORDER BY COALESCE(NULLIF(name, \'\'), email)',
            [$userId]
        );
        $out = '';
        foreach ($rows as $row) {
            $out .= rtrim(str_replace(["\r\n", "\n"], ["\n", "\r\n"], \Postvak\Contacts\ContactCards::data($row))) . "\r\n";
        }
        Response::download($out, 'postvak-contacten.vcf', 'text/vcard');
    }

    /** @return array<string, mixed> */
    private function validate(array $in, bool $create): array
    {
        $out = [];
        if (array_key_exists('details', $in)) {
            // Full form: names, e-mail addresses, phones, … (the simple columns follow from them).
            $out['details'] = ContactDetails::validate(is_array($in['details']) ? $in['details'] : []);
            $d = $out['details'];
            if (ContactDetails::displayName($d) === '' && $d['emails'] === [] && $d['phones'] === []) {
                throw HttpException::badRequest('Give the contact a name, an e-mail address or a phone number');
            }
            foreach (['notes' => 5000] as $field => $max) {
                if (array_key_exists($field, $in)) {
                    $value = trim((string) $in[$field]);
                    if (mb_strlen($value) > $max) {
                        throw HttpException::badRequest("Field '$field' is too long");
                    }
                    $out[$field] = $value === '' ? null : $value;
                }
            }
            if (array_key_exists('favorite', $in)) {
                $out['favorite'] = (bool) $in['favorite'];
            }
            return $out;
        }
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
