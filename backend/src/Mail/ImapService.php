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
        $order = ['inbox' => 0, 'snoozed' => 1, 'drafts' => 2, 'scheduled' => 3, 'sent' => 4, 'archive' => 5, 'junk' => 6, 'trash' => 7];
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
        $name = $this->ownFolderName($role) ?? FolderResolver::CREATE_DEFAULTS[$role];
        // Servers with the "INBOX." namespace (common with Dovecot/cPanel/DirectAdmin) need the prefix.
        $inbox = $this->findFolder('INBOX');
        if ($inbox && $this->usesInboxNamespace()) {
            $name = 'INBOX' . $inbox->delimiter . $name;
        }
        $this->client->createFolder($name, false);
        $this->folderCache = null;
        return $name;
    }

    /**
     * Name for Postvak's own folders (send later, snooze) in the user's language:
     * the language setting, or the browser's language when it is "auto".
     */
    private function ownFolderName(string $role): ?string
    {
        $names = ['scheduled' => ['nl' => 'Gepland', 'en' => 'Scheduled'], 'snoozed' => ['nl' => 'Gesnoozed', 'en' => 'Snoozed']];
        if (!isset($names[$role])) {
            return null;
        }
        $lang = 'nl';
        try {
            $setting = (new \Postvak\Repositories\SettingsRepository())->get((int) $this->account['user_id'])['language'] ?? 'auto';
            $lang = $setting !== 'auto' ? $setting : (str_starts_with(strtolower((string) ($_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? 'nl')), 'nl') ? 'nl' : 'en');
        } catch (Throwable) {
        }
        return $names[$role][$lang] ?? $names[$role]['en'];
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

    // ------------------------------------------------- raw messages, Message-ID

    /** The complete raw message (RFC 822), without setting \Seen. */
    public function fetchRaw(string $folderPath, int $uid): string
    {
        $folder = $this->folder($folderPath);
        $this->client->openFolder($folder->path, true);
        $data = $this->client->getConnection()->fetch(['UID', 'BODY.PEEK[]'], [$uid], null, IMAP::ST_UID)->validatedData();
        foreach ((array) ($data[$uid] ?? []) as $key => $value) {
            if (is_string($key) && str_starts_with($key, 'BODY[')) {
                return (string) $value;
            }
        }
        throw HttpException::notFound('Message not found');
    }

    /** UIDs in a folder with this Message-ID header. @return int[] */
    public function uidsByMessageId(string $folderPath, string $messageId): array
    {
        $folder = $this->folder($folderPath);
        $this->client->openFolder($folder->path, true);
        $found = $this->client->getConnection()->search(['HEADER', 'Message-ID', $this->imapString($messageId)], IMAP::ST_UID)->validatedData();
        return array_map('intval', is_array($found) ? $found : []);
    }

    /**
     * Storage use of the mailbox (IMAP QUOTA, RFC 2087), or null when the server does not tell.
     * @return array{used:int, limit:int}|null bytes
     */
    public function quota(): ?array
    {
        try {
            $data = $this->client->getConnection()->getQuotaRoot('INBOX')->data();
        } catch (Throwable) {
            return null;
        }
        $tokens = [];
        array_walk_recursive($data, static function ($v) use (&$tokens) {
            foreach (preg_split('/[\s()]+/', (string) $v) ?: [] as $t) {
                if ($t !== '') {
                    $tokens[] = $t;
                }
            }
        });
        return self::parseQuota($tokens);
    }

    /** ["QUOTA", "\"User", "quota\"", "STORAGE", "1234", "51200"] → used/limit in bytes (STORAGE is in KiB). */
    public static function parseQuota(array $tokens): ?array
    {
        foreach ($tokens as $i => $t) {
            if (strcasecmp((string) $t, 'STORAGE') === 0 && isset($tokens[$i + 2]) && ctype_digit((string) $tokens[$i + 1]) && ctype_digit((string) $tokens[$i + 2])) {
                $limit = (int) $tokens[$i + 2] * 1024;
                return $limit > 0 ? ['used' => (int) $tokens[$i + 1] * 1024, 'limit' => $limit] : null;
            }
        }
        return null;
    }

    /** UIDs of messages that answer the given Message-ID (In-Reply-To or References). */
    public function repliesTo(string $folderPath, string $messageId): array
    {
        $folder = $this->folder($folderPath);
        $this->client->openFolder($folder->path, true);
        $id = $this->imapString($messageId);
        $found = $this->client->getConnection()->search(['OR', 'HEADER', 'In-Reply-To', $id, 'HEADER', 'References', $id], IMAP::ST_UID)->validatedData();
        return array_map('intval', is_array($found) ? $found : []);
    }

    /**
     * Messages with attachments in a folder, newest first, with the attachments' names, types and sizes (from
     * BODYSTRUCTURE, so nothing is downloaded). A search term matches sender or subject; $nameFilter keeps only
     * attachments whose name contains it.
     *
     * @return array{total:int, page:int, per_page:int, items:array}
     */
    public function attachmentOverview(string $folderPath, int $page = 1, int $perPage = 25, ?string $search = null): array
    {
        $folder = $this->folder($folderPath);
        $this->client->openFolder($folder->path, true);
        $saved = $this->filters;
        $this->filters = ['attachment' => true];
        try {
            $uids = $this->sortedUids('date_desc', $search);
        } finally {
            $this->filters = $saved;
        }
        $total = count($uids);
        $pageUids = array_slice($uids, ($page - 1) * $perPage, $perPage);
        $items = [];
        if ($pageUids !== []) {
            $raw = $this->client->getConnection()->requestAndResponse('UID FETCH', [implode(',', $pageUids), '(UID BODYSTRUCTURE)'], true)->data();
            $structures = BodyStructure::parseFetch(array_filter((array) $raw, 'is_string'));
            $summaries = [];
            foreach ($this->summaries($folderPath, $pageUids) as $summary) {
                $summaries[(int) $summary['uid']] = $summary;
            }
            foreach ($pageUids as $uid) {
                $files = isset($structures[$uid]) ? BodyStructure::attachments($structures[$uid]) : [];
                if ($files === [] || !isset($summaries[$uid])) {
                    continue; // multipart/mixed without a real attachment, or deleted meanwhile
                }
                $items[] = ['message' => $summaries[$uid], 'attachments' => $files];
            }
        }
        return ['total' => $total, 'page' => $page, 'per_page' => $perPage, 'items' => $items];
    }

    /** Summaries (incl. message_id) of the given messages. */
    public function summaries(string $folderPath, array $uids): array
    {
        $folder = $this->folder($folderPath);
        $selected = $this->client->openFolder($folder->path, true);
        return $uids === [] ? [] : $this->summariesWithIds($folderPath, (int) ($selected['uidvalidity'] ?? 0), array_map('intval', $uids), null);
    }

    // ------------------------------------------------------------ new mail check

    /** [uidvalidity, uidnext] of the inbox (cheap: one SELECT). */
    public function inboxState(): array
    {
        $folder = $this->folder('INBOX');
        $status = $this->client->openFolder($folder->path, true);
        $validity = (int) ($status['uidvalidity'] ?? 0);
        $next = (int) ($status['uidnext'] ?? 0);
        if ($next === 0) {
            // Server without UIDNEXT in the SELECT answer: highest uid + 1.
            $all = $this->client->getConnection()->search(['ALL'], IMAP::ST_UID)->validatedData();
            $next = (is_array($all) && $all !== [] ? max(array_map('intval', $all)) : 0) + 1;
        }
        return [$validity, $next];
    }

    /**
     * Inbox messages with a uid above $afterUid (oldest first), with decoded addresses and the raw
     * header, for rules and out-of-office replies. $afterUid = null: the last $limit messages.
     * @return array<int, array{uid:int, subject:string, from:?array, to:array, cc:array, message_id:?string, raw:string}>
     */
    public function arrivals(?int $afterUid, int $limit = 200): array
    {
        $folder = $this->folder('INBOX');
        $this->client->openFolder($folder->path, true);
        $conn = $this->client->getConnection();
        $found = $conn->search($afterUid === null ? ['ALL'] : ['UID', ($afterUid + 1) . ':*'], IMAP::ST_UID)->validatedData();
        $uids = array_map('intval', is_array($found) ? $found : []);
        if ($afterUid !== null) {
            $uids = array_values(array_filter($uids, static fn ($u) => $u > $afterUid));
        }
        sort($uids);
        $uids = $afterUid === null ? array_slice($uids, -$limit) : array_slice($uids, 0, $limit);
        if ($uids === []) {
            return [];
        }
        $headers = $conn->headers($uids, 'RFC822', IMAP::ST_UID)->validatedData();
        $out = [];
        foreach ($uids as $uid) {
            if (!isset($headers[$uid])) {
                continue;
            }
            $raw = (string) $headers[$uid];
            $m = $this->summaryFromHeader($uid, $raw, [], 'INBOX');
            $cc = [];
            try {
                $cc = self::addresses((new Header($raw, $this->client->getConfig()))->get('cc'));
            } catch (Throwable) {
            }
            $out[] = ['uid' => $uid, 'subject' => $m['subject'], 'from' => $m['from'], 'to' => $m['to'], 'cc' => $cc, 'date' => $m['date'], 'message_id' => $m['message_id'], 'raw' => $raw];
        }
        return $out;
    }

    /**
     * Unread inbox messages with a uid above $afterUid, newest first (call inboxState() first).
     * @return array{total:int, messages:array}
     */
    public function unseenSince(int $afterUid, int $limit = 3): array
    {
        $conn = $this->client->getConnection();
        $found = $conn->search(['UID', ($afterUid + 1) . ':*', 'UNSEEN'], IMAP::ST_UID)->validatedData();
        // "n:*" always includes the highest uid, even when it is lower than n.
        $uids = array_values(array_filter(array_map('intval', is_array($found) ? $found : []), static fn ($u) => $u > $afterUid));
        rsort($uids);
        $take = array_slice($uids, 0, $limit);
        $messages = [];
        if ($take !== []) {
            $headers = $conn->headers($take, 'RFC822', IMAP::ST_UID)->validatedData();
            $snippets = $this->snippets('INBOX', 0, $take, $headers, null);
            foreach ($take as $uid) {
                if (isset($headers[$uid])) {
                    $m = $this->summaryFromHeader($uid, (string) $headers[$uid], [], 'INBOX');
                    $m['snippet'] = $snippets[$uid] ?? '';
                    $messages[] = $m;
                }
            }
        }
        return ['total' => count($uids), 'messages' => $messages];
    }

    // ------------------------------------------------------------ conversations

    /** Most messages of one conversation whose headers are fetched for the list row. */
    private const THREAD_HEADERS = 10;

    /** Most messages shown in the conversation view. */
    private const CONVERSATION_MAX = 100;

    private ?string $threadAlgorithm = null;

    /** THREAD algorithm the server supports (REFS preferred), or '' when it has none. */
    private function threadAlgorithm(): string
    {
        if ($this->threadAlgorithm === null) {
            $this->threadAlgorithm = '';
            try {
                $caps = array_map('strtoupper', (array) $this->client->getConnection()->getCapabilities()->validatedData());
                foreach (['REFS', 'REFERENCES'] as $alg) {
                    if (in_array("THREAD=$alg", $caps, true)) {
                        $this->threadAlgorithm = $alg;
                        break;
                    }
                }
            } catch (Throwable $e) {
                error_log('[postvak] CAPABILITY failed: ' . $e->getMessage());
            }
        }
        return $this->threadAlgorithm;
    }

    /**
     * Conversations in the selected folder as lists of UIDs (IMAP THREAD), or null
     * when the server cannot thread.
     *
     * @return array<int, int[]>|null
     */
    private function threadUids(?string $search): ?array
    {
        $alg = $this->threadAlgorithm();
        if ($alg === '') {
            return null;
        }
        try {
            // Unparsed: the library's tokenizer does not handle THREAD's nested lists.
            $lines = $this->client->getConnection()
                ->requestAndResponse('UID THREAD', [$alg, 'UTF-8', ...$this->searchCriteria($search)], true)
                ->data();
        } catch (Throwable $e) {
            error_log('[postvak] THREAD failed: ' . $e->getMessage());
            return null;
        }
        foreach ((array) $lines as $line) {
            if (is_string($line) && preg_match('/^\*?\s*THREAD\b(.*)$/is', trim($line), $m)) {
                return self::parseThreads($m[1]);
            }
        }
        return [];
    }

    /**
     * "(1)(2 3 (4 5)(6))" -> [[1], [2, 3, 4, 5, 6]]: every top-level group is one conversation.
     * @return array<int, int[]>
     */
    public static function parseThreads(string $text): array
    {
        $threads = [];
        $depth = 0;
        $current = [];
        foreach (preg_split('/([()])|\s+/', $text, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY) ?: [] as $token) {
            if ($token === '(') {
                $depth++;
            } elseif ($token === ')') {
                $depth = max(0, $depth - 1);
                if ($depth === 0 && $current !== []) {
                    $threads[] = $current;
                    $current = [];
                }
            } elseif ($depth > 0 && ctype_digit($token)) {
                $current[] = (int) $token;
            }
        }
        return $threads;
    }

    /**
     * One page of conversations: one row per conversation, in the order of its newest
     * message (same order as the flat list sorted by date). Each row is the summary of
     * the newest message plus a "thread" block. Falls back to the flat list when the
     * server cannot thread ("threaded" => false).
     */
    public function listThreads(string $folderPath, int $page = 1, int $perPage = 50, ?string $search = null, ?SnippetCache $cache = null): array
    {
        $folder = $this->folder($folderPath);
        $selected = $this->client->openFolder($folder->path, true);
        $uidvalidity = (int) ($selected['uidvalidity'] ?? 0);

        $threads = $this->threadUids($search);
        if ($threads === null) {
            return $this->listMessages($folderPath, $page, $perPage, $search, 'date_desc', $cache) + ['threaded' => false];
        }

        // Newest first, by the same date order as the normal list.
        $rank = array_flip($this->sortedUids('date_desc', $search));
        $missing = PHP_INT_MAX;
        foreach ($threads as &$uids) {
            usort($uids, static fn ($a, $b) => ($rank[$a] ?? $missing) <=> ($rank[$b] ?? $missing) ?: $b <=> $a);
        }
        unset($uids);
        usort($threads, static fn ($a, $b) => ($rank[$a[0]] ?? $missing) <=> ($rank[$b[0]] ?? $missing) ?: $b[0] <=> $a[0]);

        $total = count($threads);
        $pageThreads = array_slice($threads, ($page - 1) * $perPage, $perPage);

        $messages = [];
        if ($pageThreads !== []) {
            $headerUids = array_merge(...array_map(static fn ($t) => array_slice($t, 0, self::THREAD_HEADERS), $pageThreads));
            $allUids = array_merge(...$pageThreads);
            $conn = $this->client->getConnection();
            $headers = $conn->headers($headerUids, 'RFC822', IMAP::ST_UID)->validatedData();
            $flags = $conn->flags($allUids, IMAP::ST_UID)->validatedData();
            $newest = [];
            foreach ($pageThreads as $i => $uids) {
                foreach ($uids as $uid) {
                    if (isset($headers[$uid])) {
                        $newest[$i] = $uid;
                        break;
                    }
                }
            }
            $snippets = $this->snippets($folderPath, $uidvalidity, array_values($newest), $headers, $cache);

            foreach ($pageThreads as $i => $uids) {
                if (!isset($newest[$i])) {
                    continue; // deleted in the meantime
                }
                $top = $newest[$i];
                $row = $this->summaryFromHeader($top, (string) $headers[$top], (array) ($flags[$top] ?? []), $folderPath);
                $row['snippet'] = $snippets[$top] ?? '';
                $row['thread'] = $this->threadInfo($uids, $headers, $flags);
                $row['seen'] = $row['thread']['unseen'] === 0;
                $row['flagged'] = $row['flagged'] || $row['thread']['flagged'];
                $messages[] = $row;
            }
        }

        return ['total' => $total, 'page' => $page, 'per_page' => $perPage, 'sort' => 'date_desc', 'threaded' => true, 'messages' => $messages];
    }

    /** Count, unread count and participants of a conversation (uids newest first). */
    private function threadInfo(array $uids, array $headers, array $flags): array
    {
        $unseen = 0;
        $flagged = false;
        foreach ($uids as $uid) {
            $f = strtolower(implode(' ', self::flatten((array) ($flags[$uid] ?? []))));
            if (!str_contains($f, '\\seen')) {
                $unseen++;
            }
            $flagged = $flagged || str_contains($f, '\\flagged');
        }

        // Participants in order of their first message, like "Peter, ik, Anja".
        $own = strtolower((string) $this->account['email']);
        $people = [];
        foreach (array_reverse(array_slice($uids, 0, self::THREAD_HEADERS)) as $uid) {
            if (!isset($headers[$uid]) || !preg_match('/^From:\s*(.+(?:\r?\n[ \t].+)*)/mi', (string) $headers[$uid], $m)) {
                continue;
            }
            [$name, $email] = self::parseAddress($m[1]);
            if ($email === '' || isset($people[$email])) {
                continue;
            }
            $people[$email] = ['name' => $name, 'email' => $email, 'me' => $email === $own];
        }

        return [
            'count'        => count($uids),
            'uids'         => $uids,
            'unseen'       => $unseen,
            'flagged'      => $flagged,
            'participants' => array_values($people),
        ];
    }

    /**
     * All messages of the conversation that $uid belongs to, oldest first: the ones in
     * this folder plus your own replies in the Sent folder.
     *
     * @return array<int, array<string, mixed>> message summaries (each with its own "folder")
     */
    public function conversation(string $folderPath, int $uid, ?SnippetCache $cache = null): array
    {
        $folder = $this->folder($folderPath);
        $selected = $this->client->openFolder($folder->path, true);
        $uids = [$uid];
        foreach ($this->threadUids(null) ?? [] as $thread) {
            if (in_array($uid, $thread, true)) {
                $uids = $thread;
                break;
            }
        }
        rsort($uids);
        $uids = array_slice($uids, 0, self::CONVERSATION_MAX);

        $items = $this->summariesWithIds($folderPath, (int) ($selected['uidvalidity'] ?? 0), $uids, $cache);

        // Your replies live in Sent. Find them by the message ids of this conversation.
        $sent = $this->roleFolder('sent', false);
        if ($sent && $sent !== $folderPath && count($items) < self::CONVERSATION_MAX) {
            $ids = array_slice(array_values(array_unique(array_filter(array_column($items, 'message_id')))), 0, 20);
            if ($ids !== []) {
                try {
                    $sentFolder = $this->folder($sent);
                    $sel = $this->client->openFolder($sentFolder->path, true);
                    $criteria = [];
                    foreach ($ids as $id) {
                        $q = $this->imapString($id);
                        $criteria[] = ['OR', 'HEADER', 'In-Reply-To', $q, 'HEADER', 'References', $q];
                    }
                    $or = array_fill(0, count($criteria) - 1, 'OR');
                    $found = $this->client->getConnection()->search([...$or, ...array_merge(...$criteria)], IMAP::ST_UID)->validatedData();
                    $found = array_map('intval', is_array($found) ? $found : []);
                    rsort($found);
                    $found = array_slice($found, 0, self::CONVERSATION_MAX - count($items));
                    if ($found !== []) {
                        array_push($items, ...$this->summariesWithIds($sent, (int) ($sel['uidvalidity'] ?? 0), $found, $cache));
                    }
                } catch (Throwable $e) {
                    error_log('[postvak] conversation: Sent search failed: ' . $e->getMessage());
                }
            }
        }

        usort($items, static fn ($a, $b) => strcmp((string) $a['date'], (string) $b['date']) ?: $a['uid'] <=> $b['uid']);
        return $items;
    }

    /** Summaries (with snippet and Message-ID) of messages in the currently selected folder. */
    private function summariesWithIds(string $folderPath, int $uidvalidity, array $uids, ?SnippetCache $cache): array
    {
        $conn = $this->client->getConnection();
        $headers = $conn->headers($uids, 'RFC822', IMAP::ST_UID)->validatedData();
        $flags = $conn->flags($uids, IMAP::ST_UID)->validatedData();
        $snippets = $this->snippets($folderPath, $uidvalidity, $uids, $headers, $cache);
        $out = [];
        foreach ($uids as $uid) {
            if (!isset($headers[$uid])) {
                continue;
            }
            $summary = $this->summaryFromHeader($uid, (string) $headers[$uid], (array) ($flags[$uid] ?? []), $folderPath);
            $summary['snippet'] = $snippets[$uid] ?? '';
            $summary['message_id'] = preg_match('/^Message-ID:\s*(<[^>\s]+>)/mi', (string) $headers[$uid], $m) ? $m[1] : null;
            $out[] = $summary;
        }
        return $out;
    }

    /** '"Jan de Vries" <jan@x.nl>' -> ['Jan de Vries', 'jan@x.nl'] (MIME words decoded). */
    private static function parseAddress(string $value): array
    {
        $value = trim(preg_replace('/\r?\n[ \t]+/', ' ', $value) ?? $value);
        $decoded = function_exists('iconv_mime_decode') ? @iconv_mime_decode($value, ICONV_MIME_DECODE_CONTINUE_ON_ERROR, 'UTF-8') : false;
        if (is_string($decoded) && $decoded !== '') {
            $value = $decoded;
        }
        if (preg_match('/^(.*?)<([^>]+)>/', $value, $m)) {
            return [trim($m[1], " \t\"'"), strtolower(trim($m[2]))];
        }
        return ['', strtolower(trim($value, " \t<>"))];
    }

    /** @return string[] */
    private static function flatten(array $value): array
    {
        $out = [];
        array_walk_recursive($value, static function ($v) use (&$out) {
            $out[] = (string) $v;
        });
        return $out;
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

    /** Also search the message text (slower on servers without a full-text index). */
    private bool $searchInBody = false;

    public function setSearchInBody(bool $on): void
    {
        $this->searchInBody = $on;
    }

    /** Only messages with this label (IMAP keyword). */
    private ?string $keyword = null;

    public function setKeywordFilter(?string $keyword): void
    {
        $this->keyword = $keyword !== null && self::validKeyword($keyword) ? $keyword : null;
    }

    /** Search filters: unseen, flagged, attachment, since (days back), from (address). */
    private array $filters = [];

    /** Take the filters from the list request (?filter=unseen,flagged,attachment&since=30&from=a@b.nl). */
    public function setFilters(?string $flags, mixed $sinceDays, ?string $from, ?string $category = null): void
    {
        $f = [];
        foreach (explode(',', (string) $flags) as $flag) {
            if (in_array($flag, ['unseen', 'flagged', 'attachment'], true)) {
                $f[$flag] = true;
            }
        }
        $days = (int) $sinceDays;
        if ($days > 0) {
            $f['since'] = min($days, 3650);
        }
        $from = trim((string) $from);
        if ($from !== '' && mb_strlen($from) <= 200 && preg_match('/^[\x20-\x7e]+$/', $from)) {
            $f['from'] = $from;
        }
        if (in_array($category, ['primary', 'promotions', 'social', 'updates'], true)) {
            $f['category'] = $category;
        }
        $this->filters = $f;
    }

    /** The filters as IMAP SEARCH criteria. */
    public static function filterCriteria(array $f, ?int $now = null): array
    {
        $c = [];
        if (!empty($f['unseen'])) {
            $c[] = 'UNSEEN';
        }
        if (!empty($f['flagged'])) {
            $c[] = 'FLAGGED';
        }
        if (!empty($f['attachment'])) {
            // No standard IMAP criterion; mail with attachments is (almost always) multipart/mixed.
            array_push($c, 'HEADER', 'Content-Type', '"multipart/mixed"');
        }
        if (!empty($f['since'])) {
            array_push($c, 'SINCE', gmdate('j-M-Y', ($now ?? time()) - (int) $f['since'] * 86400));
        }
        if (!empty($f['from'])) {
            $from = str_replace(["\r", "\n"], ' ', (string) $f['from']);
            array_push($c, 'FROM', '"' . addcslashes($from, '"\\') . '"');
        }
        if (!empty($f['category'])) {
            array_push($c, ...MessageInsights::searchCriteria((string) $f['category']));
        }
        return $c;
    }

    /** IMAP search criteria: everything, or the text in sender, recipient, subject (and body); optionally one label and filters. */
    private function searchCriteria(?string $search): array
    {
        $base = [...($this->keyword !== null ? ['KEYWORD', $this->keyword] : []), ...self::filterCriteria($this->filters)];
        $search = trim((string) $search);
        if ($search === '') {
            return $base ?: ['ALL'];
        }
        $q = $this->imapString($search);
        return [...$base, ...($this->searchInBody
            ? ['OR', 'OR', 'OR', 'FROM', $q, 'TO', $q, 'SUBJECT', $q, 'BODY', $q]
            : ['OR', 'OR', 'FROM', $q, 'TO', $q, 'SUBJECT', $q])];
    }

    /** IMAP keywords that are not labels (set by mail programs for their own bookkeeping). */
    private const SYSTEM_KEYWORDS = ['$followup', '$forwarded', '$mdnsent', '$junk', '$notjunk', 'junk', 'nonjunk', 'notjunk', '$phishing', '$submitpending', '$submitted', '$hasattachment', '$hasnoattachment', '$answered', '$notphishing', '$autojunk', '$hastd'];

    /** Labels of a message: its keywords, without the system ones. @return string[] */
    public static function labelsFrom(array $rawFlags): array
    {
        $out = [];
        array_walk_recursive($rawFlags, static function ($f) use (&$out) {
            $f = (string) $f;
            if ($f === '' || $f[0] === '\\' || str_starts_with(strtolower($f), '$mailflagbit') || in_array(strtolower($f), self::SYSTEM_KEYWORDS, true)) {
                return;
            }
            $out[] = strtolower($f);
        });
        return array_values(array_unique($out));
    }

    /** Labels of one message (the library's flag list leaves keywords out). @return string[] */
    public function labelsOf(string $folderPath, int $uid): array
    {
        $folder = $this->folder($folderPath);
        $this->client->openFolder($folder->path, true);
        $flags = $this->client->getConnection()->flags([$uid], IMAP::ST_UID)->validatedData();
        return self::labelsFrom((array) ($flags[$uid] ?? []));
    }

    public static function validKeyword(string $k): bool
    {
        return (bool) preg_match('/^\$?[A-Za-z0-9_.-]{1,40}$/', $k);
    }

    /**
     * Add and remove labels (IMAP keywords).
     * @param int[] $uids @param string[] $add @param string[] $remove
     */
    public function setLabels(string $folderPath, array $uids, array $add, array $remove): void
    {
        $folder = $this->folder($folderPath);
        $this->client->openFolder($folder->path, true);
        $conn = $this->client->getConnection();
        foreach ([['+', $add], ['-', $remove]] as [$mode, $keywords]) {
            $keywords = array_values(array_filter($keywords, [self::class, 'validKeyword']));
            if ($keywords === [] || $uids === []) {
                continue;
            }
            foreach ($uids as $uid) {
                $conn->store($keywords, (int) $uid, null, $mode, true, IMAP::ST_UID);
            }
        }
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
                // UTC, so dates from different senders sort correctly as text.
                $date = $header->get('date')->toDate()->utc()->toIso8601String();
            } catch (Throwable) {
            }
        } catch (Throwable $e) {
            error_log("[postvak] could not parse header of uid $uid: " . $e->getMessage());
        }

        return [
            'uid'             => $uid,
            'message_id'      => preg_match('/^Message-ID:\s*(<[^>\s]+>)/mi', $rawHeader, $mid) ? $mid[1] : null,
            'account_id'      => (int) $this->account['id'],
            'folder'          => $folderPath,
            'subject'         => $subject,
            'from'            => $from,
            'to'              => $to,
            'date'            => $date,
            'seen'            => isset($flags['seen']),
            'flagged'         => isset($flags['flagged']),
            'answered'        => isset($flags['answered']),
            'labels'          => self::labelsFrom($rawFlags),
            // Without fetching the body, multipart/mixed is a good indication of attachments.
            'has_attachments' => str_contains($contentType, 'multipart/mixed'),
            'category'        => MessageInsights::category($rawHeader, $from['email'] ?? null),
            'followup'        => isset($flags['$followup']),
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
        $map = ['seen' => '\\Seen', 'flagged' => '\\Flagged', 'answered' => '\\Answered', 'draft' => '\\Draft'];
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

    /**
     * Permanently delete every message in a folder (emptying trash / spam).
     * @return int number of messages removed
     */
    public function emptyFolder(string $folderPath): int
    {
        $folder = $this->folder($folderPath);
        $status = $this->client->openFolder($folder->path, true);
        $exists = (int) ($status['exists'] ?? 0);
        if ($exists === 0) {
            return 0;
        }
        $conn = $this->client->getConnection();
        // Sequence set 1:N of the folder as just selected; mail arriving later is not touched.
        $conn->store(['\\Deleted'], 1, $exists, '+', true, IMAP::ST_MSGN);
        $conn->expunge();
        return $exists;
    }

    /** Mark every message in a folder as read. @return int number of messages in the folder */
    public function markAllRead(string $folderPath): int
    {
        $folder = $this->folder($folderPath);
        $status = $this->client->openFolder($folder->path, true);
        $exists = (int) ($status['exists'] ?? 0);
        if ($exists > 0) {
            $this->client->getConnection()->store(['\\Seen'], 1, $exists, '+', true, IMAP::ST_MSGN);
        }
        return $exists;
    }

    /** UID of the newest message in a folder with the given header value (used to find a saved draft). */
    public function findUidByHeader(string $folderPath, string $header, string $value): ?int
    {
        $folder = $this->folder($folderPath);
        $this->client->openFolder($folder->path, true);
        $ids = $this->client->getConnection()->search(['HEADER', $header, '"' . addcslashes($value, '"\\') . '"'], IMAP::ST_UID)->validatedData();
        $ids = array_map('intval', is_array($ids) ? $ids : []);
        return $ids ? max($ids) : null;
    }

    /**
     * Attachments of a message by index (as listed by getMessage/present).
     * @return array<int, array{name:string, mime:string, content:string}>
     */
    public function attachmentsOf(string $folderPath, int $uid, array $indexes): array
    {
        $message = $this->getMessage($folderPath, $uid);
        $out = [];
        $i = 0;
        foreach ($message->getAttachments() as $att) {
            if (in_array($i++, $indexes, true)) {
                $out[] = [
                    'name'    => (string) ($att->name ?: 'attachment'),
                    'mime'    => (string) ($att->getMimeType() ?: 'application/octet-stream'),
                    'content' => (string) $att->getContent(),
                ];
            }
        }
        return $out;
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
