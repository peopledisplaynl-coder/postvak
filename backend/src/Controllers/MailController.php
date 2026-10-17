<?php
declare(strict_types=1);

namespace Postvak\Controllers;

use Postvak\Auth;
use Postvak\Config;
use Postvak\Database;
use Postvak\Http\HttpException;
use Postvak\Http\Request;
use Postvak\Http\Response;
use Postvak\Mail\ImapService;
use Postvak\Mail\MessageSanitizer;
use Postvak\Mail\SmtpService;
use Postvak\Repositories\AccountRepository;
use Postvak\Repositories\SnippetCache;
use Postvak\Repositories\TrustedSenderRepository;
use Throwable;
use Webklex\PHPIMAP\Message;

/**
 * Folders, messages and sending for one account.
 * Folder paths are passed as ?folder=... because they can contain "/" or ".".
 */
final class MailController
{
    private const MOVE_TARGETS = ['archive', 'trash', 'junk', 'inbox'];

    public function __construct(private readonly AccountRepository $accounts = new AccountRepository()) {}

    /** GET /accounts/{id}/folders */
    public function folders(Request $r): void
    {
        [, $imap] = $this->open($r);
        Response::json($imap->folders());
    }

    /** POST /accounts/{id}/folders  {name, parent?} */
    public function createFolder(Request $r): void
    {
        [, $imap] = $this->open($r);
        $parent = $r->input('parent');
        $path = $imap->createFolder($r->string('name'), is_string($parent) ? $parent : null);
        Response::json(['path' => $path, 'folders' => $imap->folders()], 201);
    }

    /** PATCH /accounts/{id}/folders  {path, name} */
    public function renameFolder(Request $r): void
    {
        [, $imap] = $this->open($r);
        $path = $imap->renameFolder($r->string('path'), $r->string('name'));
        Response::json(['path' => $path, 'folders' => $imap->folders()]);
    }

    /** DELETE /accounts/{id}/folders?path=... */
    public function deleteFolder(Request $r): void
    {
        [, $imap] = $this->open($r);
        $path = (string) $r->query('path', '');
        if ($path === '') {
            throw HttpException::badRequest('path is required');
        }
        $imap->deleteFolder($path);
        Response::json(['folders' => $imap->folders()]);
    }

    /** GET /accounts/{id}/messages?folder=INBOX&page=1&per_page=50&q=search&sort=date_desc */
    public function messages(Request $r): void
    {
        [$account, $imap] = $this->open($r);
        $page = max(1, (int) $r->query('page', 1));
        $perPage = min(100, max(10, (int) $r->query('per_page', 50)));
        $q = trim((string) $r->query('q', ''));
        $sort = (string) $r->query('sort', 'date_desc');

        Response::json($imap->listMessages($this->folderParam($r), $page, $perPage, $q === '' ? null : $q, $sort, new SnippetCache((int) $account['id'])));
    }

    /** GET /accounts/{id}/messages/{uid}?folder=INBOX&images=1 */
    public function show(Request $r): void
    {
        [$account, $imap] = $this->open($r);
        $folder = $this->folderParam($r);
        $uid = $r->intParam('uid');
        $message = $imap->getMessage($folder, $uid);

        // Remote images: asked for this once, or the sender is on the user's safe list.
        $fromEmail = $message->getFrom()->first()->mail ?? null;
        $trusted = (new TrustedSenderRepository())->isTrusted((int) $account['user_id'], $fromEmail);
        $data = $this->present($account, $folder, $uid, $message, $trusted || (bool) $r->query('images', false));
        $data['trusted_sender'] = $trusted;
        Response::json($data);
    }

    /** GET /accounts/{id}/messages/{uid}/attachments/{index}?folder=INBOX&inline=1 */
    public function attachment(Request $r): void
    {
        [, $imap] = $this->open($r);
        $message = $imap->getMessage($this->folderParam($r), $r->intParam('uid'));
        $index = $r->intParam('index');

        $i = 0;
        foreach ($message->getAttachments() as $att) {
            if ($i++ !== $index) {
                continue;
            }
            $mime = (string) ($att->getMimeType() ?: 'application/octet-stream');
            // Only show images/PDF inline; everything else is a download.
            $inline = (bool) $r->query('inline', false) && preg_match('~^(image/(png|jpe?g|gif|webp)|application/pdf)$~', $mime);
            Response::download((string) $att->getContent(), (string) ($att->name ?: 'attachment'), $mime, (bool) $inline);
        }
        throw HttpException::notFound('Attachment not found');
    }

    /**
     * POST /accounts/{id}/move  {folder, uids:[..], target: "archive"|"trash"|"junk"|"inbox"|<folder path>}
     * Moving to trash from the trash folder deletes permanently.
     */
    public function move(Request $r): void
    {
        [, $imap] = $this->open($r);
        $from = $this->folderParam($r);
        $uids = $this->uids($r);
        $target = $r->string('target');

        if (in_array($target, self::MOVE_TARGETS, true)) {
            $targetPath = $target === 'inbox' ? 'INBOX' : $imap->roleFolder($target);
        } else {
            $targetPath = $target;
        }

        if ($target === 'trash' && $targetPath === $from) {
            $imap->deletePermanently($from, $uids);
            Response::json(['deleted' => count($uids)]);
        }

        $imap->move($from, $uids, (string) $targetPath);
        Response::json(['moved' => count($uids), 'target' => $targetPath]);
    }

    /** POST /accounts/{id}/flags  {folder, uids:[..], seen?:bool, flagged?:bool} */
    public function flags(Request $r): void
    {
        [, $imap] = $this->open($r);
        $flags = array_intersect_key($r->body(), array_flip(['seen', 'flagged', 'answered']));
        $imap->setFlags($this->folderParam($r), $this->uids($r), array_map('boolval', $flags));
        Response::json(['ok' => true]);
    }

    /**
     * POST /accounts/{id}/send  (multipart/form-data)
     *   payload: JSON {to:[{email,name}], cc, bcc, subject, html, in_reply_to?, references?, reply_folder?, reply_uid?}
     *   files[]: attachments
     */
    public function send(Request $r): void
    {
        [$account, $imap] = $this->open($r);

        $payload = json_decode((string) ($_POST['payload'] ?? ''), true);
        if (!is_array($payload)) {
            $payload = $r->body();
        }
        $mail = $this->validateMail($payload);
        $attachments = $this->uploadedFiles();

        $raw = SmtpService::send($account, $mail, $attachments);

        // Save a copy in Sent (most IMAP servers do not do this automatically).
        try {
            if ($sent = $imap->roleFolder('sent')) {
                $imap->append($sent, $raw);
            }
        } catch (Throwable $e) {
            error_log('[postvak] could not store sent copy: ' . $e->getMessage());
        }

        // Mark the original as answered.
        if (!empty($payload['reply_folder']) && !empty($payload['reply_uid'])) {
            try {
                $imap->setFlags((string) $payload['reply_folder'], [(int) $payload['reply_uid']], ['answered' => true]);
            } catch (Throwable) {
            }
        }

        $this->rememberContacts((int) $account['user_id'], [...$mail['to'], ...($mail['cc'] ?? []), ...($mail['bcc'] ?? [])]);
        Response::json(['sent' => true]);
    }

    /** GET /contacts?q=jan — address autocomplete. */
    public function contacts(Request $r): void
    {
        $userId = Auth::requireUser();
        $q = '%' . str_replace(['%', '_'], ['\%', '\_'], trim((string) $r->query('q', ''))) . '%';
        Response::json(Database::all(
            'SELECT email, name FROM contacts WHERE user_id = ? AND (email LIKE ? OR name LIKE ?)
             ORDER BY times_used DESC, last_used_at DESC LIMIT 10',
            [$userId, $q, $q]
        ));
    }

    // ------------------------------------------------------------------ helpers

    /** @return array{0: array, 1: ImapService} */
    private function open(Request $r): array
    {
        $userId = Auth::requireUser();
        $account = $this->accounts->findOwned($userId, $r->intParam('id'));
        // Release the session lock so parallel requests (e.g. folder counts + list) do not wait on each other.
        session_write_close();
        return [$account, new ImapService($account)];
    }

    private function folderParam(Request $r): string
    {
        $folder = (string) ($r->query('folder') ?? $r->input('folder') ?? 'INBOX');
        if ($folder === '' || strlen($folder) > 500) {
            throw HttpException::badRequest('Invalid folder');
        }
        return $folder;
    }

    /** @return int[] */
    private function uids(Request $r): array
    {
        $uids = $r->input('uids');
        if (!is_array($uids) || $uids === [] || count($uids) > 500) {
            throw HttpException::badRequest('uids must be a non-empty list');
        }
        return array_values(array_filter(array_map('intval', $uids), static fn ($u) => $u > 0));
    }

    private function present(array $account, string $folder, int $uid, Message $m, bool $allowImages): array
    {
        $base = sprintf('/api/accounts/%d/messages/%d/attachments', $account['id'], $uid);
        $q = '?folder=' . rawurlencode($folder);

        $attachments = [];
        $cidMap = [];
        $i = 0;
        foreach ($m->getAttachments() as $att) {
            $index = $i++;
            $cid = trim((string) ($att->id ?? ''), '<>');
            $url = "$base/$index$q";
            $isInline = $att->disposition === 'inline' && $cid !== '';
            if ($cid !== '') {
                $cidMap[$cid] = $url . '&inline=1';
            }
            $attachments[] = [
                'index'  => $index,
                'name'   => (string) ($att->name ?: 'attachment'),
                'mime'   => (string) ($att->getMimeType() ?: 'application/octet-stream'),
                'size'   => (int) ($att->size ?? 0),
                'inline' => $isInline,
                'url'    => $url,
            ];
        }

        $blocked = 0;
        if ($m->hasHTMLBody()) {
            $clean = MessageSanitizer::html($m->getHTMLBody(), $cidMap, $allowImages);
            $html = $clean['html'];
            $blocked = $clean['blocked_images'];
        } else {
            $html = MessageSanitizer::text($m->hasTextBody() ? $m->getTextBody() : '');
        }

        $flags = $m->getFlags();
        $from = $m->getFrom()->first();
        $header = $m->getHeader();
        $date = null;
        try {
            $date = $m->getDate()->toDate()->toIso8601String();
        } catch (Throwable) {
        }

        return [
            'uid'            => $uid,
            'folder'         => $folder,
            'account_id'     => (int) $account['id'],
            'subject'        => (string) $m->getSubject(),
            'from'           => $from ? ['name' => $from->personal, 'email' => $from->mail] : null,
            'to'             => ImapService::addresses($m->getTo()),
            'cc'             => ImapService::addresses($m->getCc()),
            'reply_to'       => ImapService::addresses($m->getReplyTo()),
            'date'           => $date,
            'message_id'     => (string) $m->getMessageId(),
            'references'     => (string) ($header?->get('references') ?? ''),
            'seen'           => $flags->has('seen'),
            'flagged'        => $flags->has('flagged'),
            'answered'       => $flags->has('answered'),
            'html'           => $html,
            'text'           => $m->hasTextBody() ? $m->getTextBody() : null,
            'blocked_images' => $blocked,
            'attachments'    => $attachments,
        ];
    }

    private function validateMail(array $p): array
    {
        $clean = static function ($list): array {
            $out = [];
            foreach ((array) $list as $r) {
                $email = is_array($r) ? ($r['email'] ?? '') : (string) $r;
                if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    throw HttpException::badRequest("Invalid address: $email");
                }
                $out[] = ['email' => $email, 'name' => is_array($r) ? (string) ($r['name'] ?? '') : ''];
            }
            return $out;
        };

        $mail = [
            'to'          => $clean($p['to'] ?? []),
            'cc'          => $clean($p['cc'] ?? []),
            'bcc'         => $clean($p['bcc'] ?? []),
            'subject'     => trim((string) ($p['subject'] ?? '')),
            'html'        => (string) ($p['html'] ?? ''),
            'in_reply_to' => $p['in_reply_to'] ?? null,
            'references'  => $p['references'] ?? null,
        ];
        if ($mail['to'] === [] && $mail['cc'] === [] && $mail['bcc'] === []) {
            throw HttpException::badRequest('Add at least one recipient');
        }
        return $mail;
    }

    /** @return array<int, array{name:string, mime:string, content:string}> */
    private function uploadedFiles(): array
    {
        $files = $_FILES['files'] ?? null;
        if (!$files || !is_array($files['name'])) {
            return [];
        }
        $max = (int) Config::get('max_attachment_mb', 20) * 1024 * 1024;
        $total = 0;
        $out = [];
        foreach ($files['name'] as $i => $name) {
            if ($files['error'][$i] !== UPLOAD_ERR_OK) {
                throw HttpException::badRequest("Upload failed for $name");
            }
            $total += (int) $files['size'][$i];
            if ($total > $max) {
                throw HttpException::badRequest('Attachments are too large');
            }
            $out[] = [
                'name'    => (string) $name,
                'mime'    => (string) ($files['type'][$i] ?: 'application/octet-stream'),
                'content' => (string) file_get_contents($files['tmp_name'][$i]),
            ];
        }
        return $out;
    }

    private function rememberContacts(int $userId, array $recipients): void
    {
        foreach ($recipients as $r) {
            Database::run(
                'INSERT INTO contacts (user_id, email, name, times_used, last_used_at) VALUES (?, ?, ?, 1, NOW())
                 ON DUPLICATE KEY UPDATE times_used = times_used + 1, last_used_at = NOW(),
                   name = COALESCE(NULLIF(VALUES(name), \'\'), name)',
                [$userId, strtolower($r['email']), $r['name'] ?: null]
            );
        }
    }
}
