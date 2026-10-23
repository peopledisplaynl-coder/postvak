<?php
declare(strict_types=1);

namespace Postvak\Controllers;

use Postvak\Auth;
use Postvak\Config;
use Postvak\Database;
use Postvak\Calendar\CalendarService;
use Postvak\Calendar\InvitationService;
use Postvak\Http\HttpException;
use Postvak\Http\Request;
use Postvak\Http\Response;
use Postvak\Mail\ImapService;
use Postvak\Mail\MessageSanitizer;
use Postvak\Mail\MessageInsights;
use Postvak\Mail\SmtpService;
use Postvak\Mail\Scheduler;
use Postvak\Mail\Snoozer;
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

    /**
     * GET /unified/messages?page=1&per_page=50&q=&sort=date_desc
     * The inboxes of all the user's accounts in one list. Each message has account_id.
     */
    public function unified(Request $r): void
    {
        $userId = Auth::requireUser();
        session_write_close();
        $page = max(1, (int) $r->query('page', 1));
        $perPage = min(100, max(5, (int) $r->query('per_page', 50)));
        $q = trim((string) $r->query('q', ''));
        $sort = (string) $r->query('sort', 'date_desc');
        if (!in_array($sort, ['date_desc', 'date_asc', 'from', 'subject'], true)) {
            $sort = 'date_desc'; // size is not known for every message, so sort by date instead
        }

        $threads = $r->query('threads') === '1' && $sort === 'date_desc';

        // Take the first page*perPage of every inbox; the merged page is always within those.
        $need = $page * $perPage;
        $all = [];
        $total = 0;
        $errors = [];
        foreach ($this->accounts->forUser($userId) as $account) {
            try {
                $imap = new ImapService($account);
                $imap->setSearchInBody($r->query('body') === '1');
                $imap->setKeywordFilter($r->query('label') ?: null);
                $imap->setFilters($r->query('filter'), $r->query('since'), $r->query('from'), $r->query('category'));
                $cache = new SnippetCache((int) $account['id']);
                $list = $threads
                    ? $imap->listThreads('INBOX', 1, $need, $q === '' ? null : $q, $cache)
                    : $imap->listMessages('INBOX', 1, $need, $q === '' ? null : $q, $sort, $cache);
                $imap->disconnect();
                $total += $list['total'];
                array_push($all, ...$list['messages']);
            } catch (Throwable $e) {
                $errors[] = ['account_id' => (int) $account['id'], 'email' => $account['email'], 'error' => $e->getMessage()];
            }
        }

        $key = static fn (array $m): string => match ($sort) {
            'from'    => mb_strtolower(($m['from']['name'] ?? '') ?: ($m['from']['email'] ?? '')),
            'subject' => mb_strtolower(preg_replace('/^((re|fw|fwd|aw|antw)\s*:\s*)+/i', '', $m['subject']) ?? ''),
            default   => (string) $m['date'],
        };
        usort($all, static function ($a, $b) use ($key, $sort) {
            $cmp = strcmp($key($a), $key($b));
            if ($sort === 'date_desc') {
                $cmp = -$cmp;
            }
            return $cmp ?: strcmp((string) $b['date'], (string) $a['date']);
        });

        Response::json([
            'total'    => $total,
            'page'     => $page,
            'per_page' => $perPage,
            'sort'     => $sort,
            'threaded' => $threads,
            'messages' => array_slice($all, ($page - 1) * $perPage, $perPage),
            'errors'   => $errors,
        ]);
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
        $perPage = min(100, max(5, (int) $r->query('per_page', 50)));
        $q = trim((string) $r->query('q', ''));
        $sort = (string) $r->query('sort', 'date_desc');

        $cache = new SnippetCache((int) $account['id']);
        $folder = $this->folderParam($r);
        $imap->setSearchInBody($r->query('body') === '1');
        $imap->setKeywordFilter($r->query('label') ?: null);
        $imap->setFilters($r->query('filter'), $r->query('since'), $r->query('from'), $r->query('category'));
        // threads=1: one row per conversation (only in date order, like Gmail).
        $list = $r->query('threads') === '1' && $sort === 'date_desc'
            ? $imap->listThreads($folder, $page, $perPage, $q === '' ? null : $q, $cache)
            : $imap->listMessages($folder, $page, $perPage, $q === '' ? null : $q, $sort, $cache);
        Response::json($this->annotate($account, $imap, $folder, $list));
    }

    /** In the Scheduled and Snoozed folders: add the send time / return time to each row. */
    private function annotate(array $account, ImapService $imap, string $folder, array $list): array
    {
        $role = null;
        foreach (['scheduled', 'snoozed'] as $candidate) {
            if ($imap->roleFolder($candidate, false) === $folder) {
                $role = $candidate;
            }
        }
        if ($role === null) {
            return $list;
        }
        $info = $role === 'scheduled' ? (new Scheduler())->forAccount((int) $account['id']) : (new Snoozer())->forAccount((int) $account['id']);
        foreach ($list['messages'] as &$m) {
            $value = $m['message_id'] ? ($info[$m['message_id']] ?? null) : null;
            if ($role === 'scheduled') {
                $m['scheduled'] = $value;
            } else {
                $m['snoozed_until'] = $value;
            }
        }
        unset($m);
        return $list;
    }

    // ------------------------------------------------------- send later / snooze

    /** POST /accounts/{id}/scheduled — like send, plus payload.send_at (ISO 8601). */
    public function schedule(Request $r): void
    {
        [$account, $imap] = $this->open($r);
        $payload = json_decode((string) ($_POST['payload'] ?? ''), true);
        if (!is_array($payload)) {
            $payload = $r->body();
        }
        $sendAt = strtotime((string) ($payload['send_at'] ?? ''));
        if ($sendAt === false) {
            throw HttpException::badRequest('Invalid send time');
        }
        $mail = $this->validateMail($payload);
        $attachments = [...$this->keptAttachments($imap, $payload), ...$this->uploadedFiles()];
        $replyRef = !empty($payload['reply_folder']) && !empty($payload['reply_uid'])
            ? ['folder' => (string) $payload['reply_folder'], 'uid' => (int) $payload['reply_uid']]
            : null;
        $scheduled = (new Scheduler())->schedule($account, $imap, $mail, $attachments, $sendAt, $replyRef);

        if (!empty($payload['draft_uid'])) {
            try {
                if ($drafts = $imap->roleFolder('drafts', false)) {
                    $imap->deletePermanently($drafts, [(int) $payload['draft_uid']]);
                }
            } catch (Throwable $e) {
                error_log('[postvak] could not remove scheduled draft: ' . $e->getMessage());
            }
        }
        Response::json($scheduled, 201);
    }

    /** POST /scheduled/{id}/cancel — back to Drafts; returns {account_id, folder, uid} of the draft. */
    public function cancelScheduled(Request $r): void
    {
        $userId = Auth::requireUser();
        session_write_close();
        Response::json((new Scheduler())->cancel($userId, $r->intParam('id')));
    }

    /** POST /scheduled/{id}/send — send now instead of later. */
    public function sendScheduledNow(Request $r): void
    {
        $userId = Auth::requireUser();
        session_write_close();
        (new Scheduler())->sendNow($userId, $r->intParam('id'));
        Response::json(['sent' => true]);
    }

    /** POST /accounts/{id}/snooze  {folder, uids, until (ISO 8601)} */
    public function snooze(Request $r): void
    {
        [$account, $imap] = $this->open($r);
        $until = strtotime($r->string('until'));
        if ($until === false) {
            throw HttpException::badRequest('Invalid time');
        }
        Response::json(['snoozed' => (new Snoozer())->snooze($account, $imap, $this->folderParam($r), $this->uids($r), $until)]);
    }

    /** POST /accounts/{id}/unsnooze  {uids} (in the Snoozed folder) — back to the inbox now. */
    public function unsnooze(Request $r): void
    {
        [$account, $imap] = $this->open($r);
        Response::json(['returned' => (new Snoozer())->unsnooze($account, $imap, $this->uids($r))]);
    }

    /** GET /accounts/{id}/messages/{uid}/conversation?folder=INBOX — the whole conversation, oldest first. */
    public function conversation(Request $r): void
    {
        [$account, $imap] = $this->open($r);
        Response::json(['messages' => $imap->conversation($this->folderParam($r), $r->intParam('uid'), new SnippetCache((int) $account['id']))]);
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
        // From the headers: unsubscribe link, whether the sender is genuine, mailbox tab, muted conversation.
        $rawHeader = (string) ($message->getHeader()?->raw ?? '');
        $fromName = $message->getFrom()->first()->personal ?? null;
        $data['unsubscribe'] = MessageInsights::unsubscribe($rawHeader) !== null;
        $data['authenticity'] = MessageInsights::authenticity($rawHeader, $fromEmail, is_string($fromName) ? $fromName : null);
        $data['muted'] = (new \Postvak\Mail\MutedThreads())->isMuted((int) $account['id'], $rawHeader);
        try {
            $data['labels'] = $imap->labelsOf($folder, $uid);
        } catch (Throwable) {
            $data['labels'] = [];
        }

        // Meeting invitation: a card with answer buttons instead of a cryptic .ics attachment.
        $data['invitation'] = null;
        try {
            $found = InvitationService::find($message);
            if ($found !== null) {
                $data['invitation'] = (new InvitationService())->describe((int) $account['user_id'], $found, CalendarService::tz((string) ($r->query('tz') ?? 'UTC')));
                // The calendar part inside the message body has no file name of its own: hide it.
                $data['attachments'] = array_values(array_filter($data['attachments'], static fn ($a) => !(
                    $a['index'] === $found['index'] && !str_ends_with(strtolower($a['name']), '.ics')
                )));
            }
        } catch (Throwable $e) {
            error_log('[postvak] invitation not shown: ' . $e->getMessage());
        }
        Response::json($data);
    }

    /** POST /accounts/{id}/messages/{uid}/invitation  {folder, response: accepted|tentative|declined|remove, reply?: bool, calendar_id?} */
    public function answerInvitation(Request $r): void
    {
        [$account, $imap] = $this->open($r);
        $message = $imap->getMessage($this->folderParam($r), $r->intParam('uid'));
        $found = InvitationService::find($message) ?? throw HttpException::notFound('No invitation in this message');
        $response = $r->string('response');
        if (!in_array($response, ['accepted', 'tentative', 'declined', 'remove'], true)) {
            throw HttpException::badRequest('Unknown answer');
        }
        $calendarId = $r->input('calendar_id');
        Response::json((new InvitationService())->respond(
            (int) $account['user_id'], $account, $found, $response, (bool) $r->input('reply', true),
            is_numeric($calendarId) ? (int) $calendarId : null,
        ));
    }

    /**
     * POST /accounts/{id}/messages/{uid}/unsubscribe  {folder}
     * One-click (RFC 8058) is done here; a mailto address gets a mail from this account; otherwise the page to
     * open is returned. → {done: bool, method: one_click|mailto|link, url?}
     */
    public function unsubscribe(Request $r): void
    {
        [$account, $imap] = $this->open($r);
        $message = $imap->getMessage($this->folderParam($r), $r->intParam('uid'));
        $info = MessageInsights::unsubscribe((string) ($message->getHeader()?->raw ?? ''))
            ?? throw HttpException::badRequest('This message has no unsubscribe information');
        if ($info['one_click']) {
            try {
                $res = \Postvak\Http\Fetcher::post($info['url'], 'List-Unsubscribe=One-Click');
                if ($res['status'] >= 200 && $res['status'] < 300) {
                    Response::json(['done' => true, 'method' => 'one_click']);
                }
                error_log('[postvak] one-click unsubscribe answered ' . $res['status']);
            } catch (Throwable $e) {
                error_log('[postvak] one-click unsubscribe failed: ' . $e->getMessage());
            }
        }
        if ($info['mailto'] !== null && ($to = MessageInsights::parseMailto($info['mailto'])) !== null) {
            [$address, $subject, $body] = $to;
            SmtpService::send($account, [
                'to' => [['email' => $address, 'name' => '']], 'cc' => [], 'bcc' => [],
                'subject' => $subject, 'html' => '<p>' . htmlspecialchars($body) . '</p>',
            ]);
            Response::json(['done' => true, 'method' => 'mailto']);
        }
        if ($info['url'] !== null) {
            Response::json(['done' => false, 'method' => 'link', 'url' => $info['url']]);
        }
        throw new HttpException(502, 'Unsubscribing did not work; try the link in the message itself');
    }

    /** GET /accounts/{id}/quota → {used, limit} in bytes, or null when the mail server does not tell */
    public function quota(Request $r): void
    {
        [, $imap] = $this->open($r);
        session_write_close();
        Response::json($imap->quota());
    }

    /** GET /accounts/{id}/attachments?folder=INBOX&page=1&q= — messages with attachments and their files. */
    public function attachments(Request $r): void
    {
        [, $imap] = $this->open($r);
        session_write_close();
        $page = max(1, (int) $r->query('page', 1));
        $q = trim((string) $r->query('q', ''));
        Response::json($imap->attachmentOverview($this->folderParam($r), $page, 25, $q !== '' ? mb_substr($q, 0, 200) : null));
    }

    /** POST /accounts/{id}/messages/{uid}/mute  {folder, muted: bool} — ignore the conversation (or stop ignoring). */
    public function mute(Request $r): void
    {
        [$account, $imap] = $this->open($r);
        $raw = (string) ($imap->getMessage($this->folderParam($r), $r->intParam('uid'))->getHeader()?->raw ?? '');
        $threads = new \Postvak\Mail\MutedThreads();
        $on = (bool) $r->input('muted', true);
        $on ? $threads->mute((int) $account['id'], $raw) : $threads->unmute((int) $account['id'], $raw);
        Response::json(['muted' => $on]);
    }

    /** GET /accounts/{id}/messages/{uid}/source?folder=&download=1 — the original message (headers and all). */
    public function source(Request $r): void
    {
        $uid = $r->intParam('uid');
        $folder = $this->folderParam($r);
        [, $imap] = $this->openForLink($r, $uid, $folder, null);
        $raw = $imap->fetchRaw($folder, $uid);
        if ($r->query('download') === '1') {
            Response::download($raw, "message-$uid.eml", 'message/rfc822');
        }
        Response::download($raw, "message-$uid.txt", 'text/plain; charset=utf-8', true);
    }

    /** GET /accounts/{id}/messages/{uid}/attachments/{index}?folder=INBOX&inline=1 */
    public function attachment(Request $r): void
    {
        $uid = $r->intParam('uid');
        $folder = $this->folderParam($r);
        $index = $r->intParam('index');
        [, $imap] = $this->openForLink($r, $uid, $folder, $index);
        $message = $imap->getMessage($folder, $uid);

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

    /** POST /accounts/{id}/folders/read  {path}: mark everything in the folder as read. */
    public function markFolderRead(Request $r): void
    {
        [, $imap] = $this->open($r);
        Response::json(['read' => $imap->markAllRead($r->string('path'))]);
    }

    /** POST /accounts/{id}/folders/empty  {path}: empty the trash or spam folder. */
    public function emptyFolder(Request $r): void
    {
        [, $imap] = $this->open($r);
        $path = $r->string('path');
        $allowed = array_filter([$imap->roleFolder('trash', false), $imap->roleFolder('junk', false)]);
        if ($path === '' || !in_array($path, $allowed, true)) {
            throw HttpException::badRequest('Only the trash and spam folders can be emptied');
        }
        Response::json(['deleted' => $imap->emptyFolder($path)]);
    }

    /** POST /accounts/{id}/flags  {folder, uids:[..], seen?:bool, flagged?:bool} */
    public function flags(Request $r): void
    {
        [, $imap] = $this->open($r);
        $flags = array_intersect_key($r->body(), array_flip(['seen', 'flagged', 'answered']));
        $imap->setFlags($this->folderParam($r), $this->uids($r), array_map('boolval', $flags));
        Response::json(['ok' => true]);
    }

    /** POST /accounts/{id}/labels  {folder, uids, add?: [keyword], remove?: [keyword]} */
    public function labels(Request $r): void
    {
        [, $imap] = $this->open($r);
        $clean = static fn ($v) => array_values(array_filter(array_map('strval', (array) $v), [ImapService::class, 'validKeyword']));
        $imap->setLabels($this->folderParam($r), $this->uids($r), $clean($r->input('add', [])), $clean($r->input('remove', [])));
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
        $attachments = [...$this->keptAttachments($imap, $payload), ...$this->uploadedFiles()];

        $raw = SmtpService::send($account, $mail, $attachments);

        // A message sent from a draft: remove the draft.
        if (!empty($payload['draft_uid'])) {
            try {
                if ($drafts = $imap->roleFolder('drafts', false)) {
                    $imap->deletePermanently($drafts, [(int) $payload['draft_uid']]);
                }
            } catch (Throwable $e) {
                error_log('[postvak] could not remove sent draft: ' . $e->getMessage());
            }
        }

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

        // "Remind me if nobody answers within … days".
        $days = (int) ($payload['followup_days'] ?? 0);
        if ($days > 0) {
            try {
                \Postvak\Mail\FollowUps::add((int) $account['id'], $raw, (string) $mail['subject'], $days);
            } catch (Throwable $e) {
                error_log('[postvak] follow-up not stored: ' . $e->getMessage());
            }
        }

        \Postvak\Repositories\ContactRepository::remember((int) $account['user_id'], [...$mail['to'], ...($mail['cc'] ?? []), ...($mail['bcc'] ?? [])]);
        Response::json(['sent' => true]);
    }

    /**
     * POST /accounts/{id}/drafts  (multipart/form-data, same fields as send)
     *   payload.draft_uid: the previous version, replaced by this one
     *   payload.include_attachments: {folder, uid, indexes[]} attachments to carry over
     * Returns the new draft's uid and its attachments.
     */
    public function saveDraft(Request $r): void
    {
        [$account, $imap] = $this->open($r);
        $payload = json_decode((string) ($_POST['payload'] ?? ''), true);
        if (!is_array($payload)) {
            $payload = $r->body();
        }
        $mail = $this->validateMail($payload, false);
        if (!empty($payload['reply_folder']) && !empty($payload['reply_uid'])) {
            $mail['reply_ref'] = base64_encode((string) $payload['reply_folder']) . ':' . (int) $payload['reply_uid'];
        }
        $kept = $this->keptAttachments($imap, $payload);
        $uploaded = $this->uploadedFiles();
        $attachments = [...$kept, ...$uploaded];

        $folder = (string) $imap->roleFolder('drafts');
        $token = bin2hex(random_bytes(12));
        $raw = SmtpService::buildDraft($account, $mail, $attachments, $token);
        $imap->append($folder, $raw, ['\\Draft', '\\Seen']);
        $uid = $imap->findUidByHeader($folder, SmtpService::DRAFT_HEADER, $token);

        if (!empty($payload['draft_uid']) && (int) $payload['draft_uid'] !== $uid) {
            try {
                $imap->deletePermanently($folder, [(int) $payload['draft_uid']]);
            } catch (Throwable $e) {
                error_log('[postvak] could not remove old draft: ' . $e->getMessage());
            }
        }

        Response::json([
            'uid'         => $uid,
            'folder'      => $folder,
            'saved_at'    => date(DATE_ATOM),
            'attachments' => array_map(static fn ($a, $i) => ['index' => $i, 'name' => $a['name'], 'mime' => $a['mime'], 'size' => strlen($a['content'])], $attachments, array_keys($attachments)),
        ]);
    }

    /** DELETE /accounts/{id}/drafts/{uid} — discard a draft. */
    public function deleteDraft(Request $r): void
    {
        [, $imap] = $this->open($r);
        if ($drafts = $imap->roleFolder('drafts', false)) {
            $imap->deletePermanently($drafts, [$r->intParam('uid')]);
        }
        Response::noContent();
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

    /**
     * Like open(), but a signed link (see SignedLink) also works without the session cookie: phones do not always
     * send it when they open or download a file.
     */
    private function openForLink(Request $r, int $uid, string $folder, ?int $index): array
    {
        $accountId = $r->intParam('id');
        $userId = \Postvak\Http\SignedLink::verify($r, $accountId, $uid, $folder, $index);
        if ($userId === null) {
            return $this->open($r);
        }
        $account = $this->accounts->findOwned($userId, $accountId);
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
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
        // Signed, so the file also opens where the phone leaves the session cookie out (see SignedLink).
        $sign = static fn (?int $index): string => '&' . \Postvak\Http\SignedLink::query((int) $account['user_id'], (int) $account['id'], $uid, $folder, $index);

        $attachments = [];
        $cidMap = [];
        $i = 0;
        foreach ($m->getAttachments() as $att) {
            $index = $i++;
            $cid = trim((string) ($att->id ?? ''), '<>');
            $url = "$base/$index$q" . $sign($index);
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
            'bcc'            => ImapService::addresses($m->getBcc()),
            'reply_to'       => ImapService::addresses($m->getReplyTo()),
            'date'           => $date,
            'message_id'     => self::msgIds((string) $m->getMessageId()),
            'references'     => self::msgIds(self::attrString($header?->get('references'))),
            'in_reply_to'    => self::msgIds(self::attrString($header?->get('in_reply_to'))),
            'reply_ref'      => self::parseReplyRef((string) ($header?->get(strtolower(str_replace('-', '_', SmtpService::REPLY_HEADER))) ?? '')),
            'draft'          => $flags->has('draft'),
            'seen'           => $flags->has('seen'),
            'flagged'        => $flags->has('flagged'),
            'answered'       => $flags->has('answered'),
            'html'           => $html,
            'text'           => $m->hasTextBody() ? $m->getTextBody() : null,
            'blocked_images' => $blocked,
            'attachments'    => $attachments,
            // The original message (view / .eml), signed like the attachments.
            'source_url'     => sprintf('/api/accounts/%d/messages/%d/source', $account['id'], $uid) . $q . $sign(null),
        ];
    }

    /** Message-IDs in header form: "<a@b> <c@d>" (the library strips the angle brackets). */
    private static function msgIds(string $value): string
    {
        $ids = preg_split('/[\s,]+/', trim(str_replace(['<', '>'], ' ', $value))) ?: [];
        return implode(' ', array_map(static fn ($id) => "<$id>", array_filter($ids)));
    }

    private static function attrString(mixed $attr): string
    {
        if ($attr === null) {
            return '';
        }
        $values = method_exists($attr, 'all') ? $attr->all() : [(string) $attr];
        return implode(' ', array_map('strval', array_filter($values, 'is_scalar')));
    }

    /** @return array{folder:string, uid:int}|null */
    private static function parseReplyRef(string $value): ?array
    {
        if (!preg_match('/^([A-Za-z0-9+\/=]+):(\d+)$/', trim($value), $m)) {
            return null;
        }
        $folder = base64_decode($m[1], true);
        return $folder === false ? null : ['folder' => $folder, 'uid' => (int) $m[2]];
    }

    /** Attachments copied from an existing message (the draft being edited, or a forwarded mail). */
    private function keptAttachments(ImapService $imap, array $payload): array
    {
        $inc = $payload['include_attachments'] ?? null;
        if (!is_array($inc) || empty($inc['folder']) || empty($inc['uid']) || empty($inc['indexes']) || !is_array($inc['indexes'])) {
            return [];
        }
        return $imap->attachmentsOf((string) $inc['folder'], (int) $inc['uid'], array_map('intval', $inc['indexes']));
    }

    private function validateMail(array $p, bool $requireRecipients = true): array
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
        if ($requireRecipients && $mail['to'] === [] && $mail['cc'] === [] && $mail['bcc'] === []) {
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

}
