<?php
declare(strict_types=1);

namespace Postvak\Mail;

use Postvak\Crypto;
use Postvak\Http\HttpException;
use Postvak\Repositories\SnippetCache;
use Throwable;
use Webklex\PHPIMAP\Client;
use Webklex\PHPIMAP\ClientManager;
use Webklex\PHPIMAP\Exceptions\AuthFailedException;
use Webklex\PHPIMAP\Folder;
use Webklex\PHPIMAP\Header;
use Webklex\PHPIMAP\IMAP;
use Webklex\PHPIMAP\Message;

/**
 * All IMAP access goes through this class, so the rest of the app does not
 * depend on the IMAP library. Uses webklex/php-imap in native socket mode
 * (no ext-imap needed, which was removed from PHP 8.4).
 */
final class ImapService
{
    private Client $client;
    private ?array $folderCache = null;

    public function __construct(private readonly array $account)
    {
        $this->client = self::makeClient(
            ServerSettings::fromAccount($account),
            Crypto::decrypt($account['password_enc'])
        );
        self::connect($this->client);
    }

    /** Check that the credentials work. Throws 401 on bad login, 502 when the server is unreachable. */
    public static function verify(ServerSettings $settings, string $password): void
    {
        $client = self::makeClient($settings, $password);
        self::connect($client);
        $client->disconnect();
    }

    // ------------------------------------------------------------------ folders

    /** @return array<int, array<string, mixed>> */
    public function folders(bool $withCounts = true): array
    {
        if ($this->folderCache !== null && !$withCounts) {
            return $this->folderCache;
        }
        $list = [];
        foreach ($this->client->getFolders(false) as $folder) {
            /** @var Folder $folder */
            $item = [
                'path'       => $folder->path,
                'name'       => $folder->name,
                'full_name'  => $folder->full_name,
                'delimiter'  => $folder->delimiter,
                'selectable' => !$folder->no_select,
                'unseen'     => null,
                'total'      => null,
            ];
            if ($withCounts && !$folder->no_select) {
                try {
                    $status = $folder->status();
                    $item['unseen'] = (int) ($status['unseen'] ?? 0);
                    $item['total'] = (int) ($status['messages'] ?? 0);
                } catch (Throwable) {
                    // Some servers refuse STATUS on certain folders; counts stay null.
                }
            }
            $list[] = $item;
        }

        $roles = FolderResolver::resolve($list, $this->account);
        foreach ($list as &$item) {
            $item['role'] = FolderResolver::roleOf($item['path'], $roles);
        }
        unset($item);

        // INBOX first, then special folders, then the rest alphabetically.
        $order = ['inbox' => 0, 'drafts' => 1, 'sent' => 2, 'archive' => 3, 'junk' => 4, 'trash' => 5];
        usort($list, static fn ($a, $b) =>
            [($order[$a['role']] ?? 9), mb_strtolower($a['full_name'])] <=> [($order[$b['role']] ?? 9), mb_strtolower($b['full_name'])]
        );

        return $this->folderCache = $list;
    }

    /** Path of a special folder (archive, trash, sent, drafts, junk); creates it if missing and $create is true. */
    public function roleFolder(string $role, bool $create = true): ?string
    {
        $roles = FolderResolver::resolve($this->folders(false), $this->account);
        if (!empty($roles[$role])) {
            return $roles[$role];
        }
        if (!$create || !isset(FolderResolver::CREATE_DEFAULTS[$role])) {
            return null;
        }
        $name = FolderResolver::CREATE_DEFAULTS[$role];
        // Servers with the "INBOX." namespace (common with Dovecot/cPanel/DirectAdmin) need the prefix.
        $inbox = $this->findFolder('INBOX');
        if ($inbox && $this->usesInboxNamespace()) {
            $name = 'INBOX' . $inbox->delimiter . $name;
        }
        $this->client->createFolder($name, false);
        $this->folderCache = null;
        return $name;
    }

    /** Create a folder; returns its path. Lands under INBOX on servers that use the INBOX. namespace. */
    public function createFolder(string $name, ?string $parentPath = null): string
    {
        $name = $this->validFolderName($name);
        $delimiter = $this->delimiter();
        if ($parentPath === null || $parentPath === '') {
            $parentPath = $this->usesInboxNamespace() ? 'INBOX' : '';
        } else {
            $this->folder($parentPath); // must exist
        }
        $path = ($parentPath !== '' ? $parentPath . $delimiter : '') . mb_convert_encoding($name, 'UTF7-IMAP', 'UTF-8');
        if ($this->findFolder($path)) {
            throw HttpException::badRequest('A folder with this name already exists');
        }
        $conn = $this->client->getConnection();
        $conn->createFolder($path)->validatedData();
        try {
            $conn->subscribeFolder($path)->validatedData();
        } catch (Throwable) {
        }
        $this->folderCache = null;
        return $path;
    }

    /** Rename a folder (keeps it under the same parent); returns the new path. */
    public function renameFolder(string $path, string $newName): string
    {
        $this->assertFolderEditable($path);
        $newName = $this->validFolderName($newName);
        $delimiter = $this->delimiter();
        $pos = strrpos($path, $delimiter);
        $parent = $pos === false ? '' : substr($path, 0, $pos);
        $newPath = ($parent !== '' ? $parent . $delimiter : '') . mb_convert_encoding($newName, 'UTF7-IMAP', 'UTF-8');
        if ($newPath === $path) {
            return $path;
        }
        if ($this->findFolder($newPath)) {
            throw HttpException::badRequest('A folder with this name already exists');
        }
        $conn = $this->client->getConnection();
        $conn->renameFolder($path, $newPath)->validatedData();
        try {
            $conn->subscribeFolder($newPath)->validatedData();
        } catch (Throwable) {
        }
        $this->folderCache = null;
        return $newPath;
    }

    /** Delete a folder including its messages. INBOX and special folders cannot be deleted. */
    public function deleteFolder(string $path): void
    {
        $this->assertFolderEditable($path);
        $this->client->getConnection()->deleteFolder($path)->validatedData();
        $this->folderCache = null;
    }

    private function assertFolderEditable(string $path): void
    {
        $this->folder($path); // 404 when missing
        $roles = FolderResolver::resolve($this->folders(false), $this->account);
        if (strcasecmp($path, 'INBOX') === 0 || in_array($path, array_filter($roles), true)) {
            throw HttpException::badRequest('This folder cannot be changed');
        }
    }

    private function validFolderName(string $name): string
    {
        $name = trim(preg_replace('/\s+/u', ' ', $name) ?? '');
        $delimiter = $this->delimiter();
        if ($name === '' || mb_strlen($name) > 100 || preg_match('/[\x00-\x1f*%]/', $name) || str_contains($name, $delimiter)) {
            throw HttpException::badRequest("Invalid folder name (do not use \"$delimiter\", * or %)");
        }
        return $name;
    }

    private function delimiter(): string
    {
        foreach ($this->folders(false) as $f) {
            if (!empty($f['delimiter'])) {
                return $f['delimiter'];
            }
        }
        return '/';
    }

    // ----------------------------------------------------------------- messages

    /** Sort orders a user can choose, mapped to IMAP SORT criteria (RFC 5256). */
    public const SORTS = [
        'date_desc'    => '(REVERSE DATE)',
        'date_asc'     => '(DATE)',
        'from'         => '(FROM)',
        'subject'      => '(SUBJECT)',
        'size_desc'    => '(REVERSE SIZE)',
    ];

    /**
     * One page of message summaries in the requested order.
     *
     * The server sorts (IMAP SORT), so the order is correct for the whole folder,
     * not just for the page. Only the headers and flags of the messages on this
     * page are fetched.
     *
     * @return array{total:int, page:int, per_page:int, sort:string, messages:array}
     */
    public function listMessages(string $folderPath, int $page = 1, int $perPage = 50, ?string $search = null, string $sort = 'date_desc', ?SnippetCache $cache = null): array
    {
        $folder = $this->folder($folderPath);
        $selected = $this->client->openFolder($folder->path, true);
        $uidvalidity = (int) ($selected['uidvalidity'] ?? 0);
        $sort = isset(self::SORTS[$sort]) ? $sort : 'date_desc';

        $uids = $this->sortedUids($sort, $search);
        $total = count($uids);
        $pageUids = array_slice($uids, ($page - 1) * $perPage, $perPage);

        $messages = [];
        if ($pageUids !== []) {
            $conn = $this->client->getConnection();
            $headers = $conn->headers($pageUids, 'RFC822', IMAP::ST_UID)->validatedData();
            $flags = $conn->flags($pageUids, IMAP::ST_UID)->validatedData();
            $snippets = $this->snippets($folderPath, $uidvalidity, $pageUids, $headers, $cache);
            foreach ($pageUids as $uid) {
                if (!isset($headers[$uid])) {
                    continue; // deleted in the meantime
                }
                $summary = $this->summaryFromHeader($uid, (string) $headers[$uid], (array) ($flags[$uid] ?? []), $folderPath);
                $summary['snippet'] = $snippets[$uid] ?? '';
                $messages[] = $summary;
            }
        }

        return ['total' => $total, 'page' => $page, 'per_page' => $perPage, 'sort' => $sort, 'messages' => $messages];
    }

    /**
     * Preview lines for the given messages: from the cache, otherwise from the first
     * few kB of each body (one IMAP command for all missing ones).
     *
     * @return array<int, string>
     */
    private function snippets(string $folder, int $uidvalidity, array $uids, array $headers, ?SnippetCache $cache): array
    {
        $known = $cache && $uidvalidity ? $cache->get($folder, $uidvalidity, $uids) : [];
        $missing = array_values(array_filter($uids, static fn ($u) => !array_key_exists($u, $known) && isset($headers[$u])));
        if ($missing === []) {
            return $known;
        }

        $new = [];
        try {
            $item = 'BODY.PEEK[TEXT]<0.' . SnippetExtractor::FETCH_BYTES . '>';
            // Two items, so the library returns every field of the response by name.
            $bodies = $this->client->getConnection()->fetch(['UID', $item], $missing, null, IMAP::ST_UID)->validatedData();
            foreach ($missing as $uid) {
                $data = (array) ($bodies[$uid] ?? []);
                $start = '';
                foreach ($data as $key => $value) {
                    if (is_string($key) && str_starts_with($key, 'BODY[TEXT]')) {
                        $start = (string) $value;
                    }
                }
                $new[$uid] = SnippetExtractor::extract((string) $headers[$uid], $start);
            }
        } catch (Throwable $e) {
            error_log('[postvak] snippets failed: ' . $e->getMessage());
            return $known;
        }

        if ($cache && $uidvalidity) {
            $cache->put($folder, $uidvalidity, $new);
        }
        return $known + $new;
    }

    /** All matching UIDs in the requested order. Falls back to UID order if the server lacks SORT. */
    private function sortedUids(string $sort, ?string $search): array
    {
        $conn = $this->client->getConnection();
        $criteria = $this->searchCriteria($search);

        try {
            $response = $conn->requestAndResponse('UID SORT', [self::SORTS[$sort], 'UTF-8', ...$criteria]);
            foreach ($response->data() as $line) {
                if (is_array($line) && ($line[0] ?? null) === 'SORT') {
                    return array_map('intval', array_slice($line, 1));
                }
            }
            return [];
        } catch (Throwable $e) {
            error_log('[postvak] SORT not available, falling back to SEARCH: ' . $e->getMessage());
        }

        $ids = $conn->search(['CHARSET', 'UTF-8', ...$criteria], IMAP::ST_UID)->validatedData();
        $ids = array_map('intval', is_array($ids) ? $ids : []);
        $sort === 'date_asc' ? sort($ids) : rsort($ids); // UID order approximates arrival order
        return $ids;
    }

    /** IMAP search criteria: everything, or the text in sender, recipient or subject. */
    private function searchCriteria(?string $search): array
    {
        $search = trim((string) $search);
        if ($search === '') {
            return ['ALL'];
        }
        $q = $this->imapString($search);
        return ['OR', 'OR', 'FROM', $q, 'TO', $q, 'SUBJECT', $q];
    }

    /** Quoted string for plain ASCII, literal {n} for anything else (UTF-8). */
    private function imapString(string $value): string|array
    {
        $value = str_replace(["\r", "\n"], ' ', $value);
        if (preg_match('/^[\x20-\x7e]*$/', $value)) {
            return '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], $value) . '"';
        }
        return ['{' . strlen($value) . '}', $value];
    }

    private function summaryFromHeader(int $uid, string $rawHeader, array $rawFlags, string $folderPath): array
    {
        $flags = [];
        array_walk_recursive($rawFlags, static function ($f) use (&$flags) {
            $flags[strtolower(ltrim((string) $f, '\\'))] = true;
        });

        $subject = '';
        $from = null;
        $to = [];
        $date = null;
        $contentType = '';
        try {
            $header = new Header($rawHeader, $this->client->getConfig());
            $subject = (string) $header->get('subject');
            $first = $header->get('from')->first();
            $from = is_object($first) && isset($first->mail) ? ['name' => $first->personal, 'email' => $first->mail] : null;
            $to = self::addresses($header->get('to'));
            $contentType = strtolower((string) $header->get('content_type'));
            try {
                $date = $header->get('date')->toDate()->toIso8601String();
            } catch (Throwable) {
            }
        } catch (Throwable $e) {
            error_log("[postvak] could not parse header of uid $uid: " . $e->getMessage());
        }

        return [
            'uid'             => $uid,
            'folder'          => $folderPath,
            'subject'         => $subject,
            'from'            => $from,
            'to'              => $to,
            'date'            => $date,
            'seen'            => isset($flags['seen']),
            'flagged'         => isset($flags['flagged']),
            'answered'        => isset($flags['answered']),
            // Without fetching the body, multipart/mixed is a good indication of attachments.
            'has_attachments' => str_contains($contentType, 'multipart/mixed'),
        ];
    }

    /** Full message with headers, body and attachment list. */
    public function getMessage(string $folderPath, int $uid): Message
    {
        $folder = $this->folder($folderPath);
        try {
            return $folder->query()->setFetchBody(true)->leaveUnread()->getMessageByUid($uid);
        } catch (Throwable) {
            throw HttpException::notFound('Message not found');
        }
    }

    /** @param int[] $uids */
    public function move(string $fromPath, array $uids, string $toPath): void
    {
        $from = $this->folder($fromPath);
        $to = $this->folder($toPath); // throws 404 when the target does not exist
        if ($from->path === $to->path || $uids === []) {
            return;
        }
        $this->client->openFolder($from->path, true);
        // One UID MOVE for all messages (falls back to COPY + delete on servers without MOVE).
        $this->client->getConnection()->moveManyMessages(array_map('intval', $uids), $to->path, IMAP::ST_UID)->validatedData();
    }

    /**
     * @param int[] $uids
     * @param array{seen?:bool, flagged?:bool, answered?:bool} $flags
     */
    public function setFlags(string $folderPath, array $uids, array $flags): void
    {
        $map = ['seen' => '\\Seen', 'flagged' => '\\Flagged', 'answered' => '\\Answered'];
        $folder = $this->folder($folderPath);
        $this->client->openFolder($folder->path, true);
        $conn = $this->client->getConnection();
        foreach ($flags as $key => $on) {
            if (!isset($map[$key])) {
                continue;
            }
            foreach ($uids as $uid) {
                $conn->store([$map[$key]], (int) $uid, null, $on ? '+' : '-', true, IMAP::ST_UID);
            }
        }
    }

    /** Permanently delete (only used from the trash folder). @param int[] $uids */
    public function deletePermanently(string $folderPath, array $uids): void
    {
        $folder = $this->folder($folderPath);
        $this->client->openFolder($folder->path, true);
        $conn = $this->client->getConnection();
        foreach ($uids as $uid) {
            $conn->store(['\\Deleted'], (int) $uid, null, '+', true, IMAP::ST_UID);
        }
        $conn->expunge();
    }

    /** Store a raw RFC 822 message, e.g. a copy of a sent mail in the Sent folder. */
    public function append(string $folderPath, string $raw, array $flags = ['\\Seen']): void
    {
        $this->folder($folderPath)->appendMessage($raw, $flags);
    }

    public function disconnect(): void
    {
        try {
            $this->client->disconnect();
        } catch (Throwable) {
        }
    }

    // ------------------------------------------------------------------ helpers

    public function folder(string $path): Folder
    {
        $folder = $this->findFolder($path);
        if ($folder === null) {
            throw HttpException::notFound('Folder not found');
        }
        return $folder;
    }

    private function findFolder(string $path): ?Folder
    {
        try {
            // Paths are kept in IMAP's modified UTF-7 as the server sends them: do not convert again.
            return $this->client->getFolderByPath($path, true, true);
        } catch (Throwable) {
            return null;
        }
    }

    private function usesInboxNamespace(): bool
    {
        foreach ($this->folders(false) as $f) {
            if (str_starts_with(strtoupper($f['path']), 'INBOX' . $f['delimiter'])) {
                return true;
            }
        }
        return false;
    }

    /** @return array<int, array{name:string, email:string}> */
    public static function addresses(mixed $attribute): array
    {
        $out = [];
        if ($attribute === null) {
            return $out;
        }
        foreach ($attribute->all() as $a) {
            if (is_object($a) && isset($a->mail)) {
                $out[] = ['name' => $a->personal, 'email' => $a->mail];
            }
        }
        return $out;
    }

    private static function makeClient(ServerSettings $s, string $password): Client
    {
        $manager = new ClientManager([
            'options' => [
                'fetch_order' => 'desc',
                'soft_fail'   => false,
                'rfc822'      => true,
            ],
            // 'utf-8' would call imap_utf8() from ext-imap; mimeheader works without it.
            'decoding' => [
                'options' => ['header' => 'mimeheader', 'message' => 'mimeheader', 'attachment' => 'mimeheader'],
            ],
        ]);

        return $manager->make([
            'host'          => $s->imapHost,
            'port'          => $s->imapPort,
            'encryption'    => $s->imapEncryption === 'none' ? false : $s->imapEncryption,
            'validate_cert' => $s->validateCert,
            'username'      => $s->username,
            'password'      => $password,
            'protocol'      => 'imap',
            'timeout'       => 20,
        ]);
    }

    private static function connect(Client $client): void
    {
        try {
            $client->connect();
        } catch (AuthFailedException) {
            throw HttpException::unauthorized('Login failed: wrong address or password');
        } catch (Throwable $e) {
            // Depending on the server and library version a wrong password arrives as
            // AuthFailedException, ConnectionFailedException or ImapServerErrorException
            // ("NO [AUTHENTICATIONFAILED]"). Look at the whole exception chain.
            for ($x = $e; $x !== null; $x = $x->getPrevious()) {
                if (preg_match('/AUTHENTICATIONFAILED|authentication failed|auth.*fail|invalid credentials|LOGIN failed/i', $x->getMessage())) {
                    throw HttpException::unauthorized('Login failed: wrong address or password');
                }
            }
            error_log('[postvak] IMAP connect failed: ' . $e::class . ': ' . $e->getMessage());
            throw HttpException::upstream('Could not connect to the mail server');
        }
    }
}
