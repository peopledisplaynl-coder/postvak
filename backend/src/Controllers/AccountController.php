<?php
declare(strict_types=1);

namespace Postvak\Controllers;

use Postvak\Auth;
use Postvak\Http\HttpException;
use Postvak\Http\Request;
use Postvak\Http\Response;
use Postvak\Mail\ImapService;
use Postvak\Mail\ServerSettings;
use Postvak\Repositories\AccountRepository;

/** Manage the mail accounts of the logged-in user. */
final class AccountController
{
    public function __construct(private readonly AccountRepository $accounts = new AccountRepository()) {}

    /** GET /accounts */
    public function index(Request $r): void
    {
        $userId = Auth::requireUser();
        Response::json(array_map([AccountRepository::class, 'toPublic'], $this->accounts->forUser($userId)));
    }

    /** POST /accounts  {email, password, display_name?, imap_host?, ...} — verifies the login first. */
    public function store(Request $r): void
    {
        $userId = Auth::requireUser();
        $email = strtolower($r->string('email'));
        $password = (string) $r->input('password', '');
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || $password === '') {
            throw HttpException::badRequest('Enter a valid address and password');
        }
        if ($this->accounts->findByEmail($userId, $email)) {
            throw HttpException::badRequest('This account has already been added');
        }

        $server = ServerSettings::forEmail($email, $r->body());
        ImapService::verify($server, $password);

        $id = $this->accounts->create($userId, $email, $password, $server, false, $r->input('display_name') ?: null);
        Response::json(AccountRepository::toPublic($this->accounts->findOwned($userId, $id)), 201);
    }

    /** PATCH /accounts/{id}  — display name, colour, signature, special folders, server settings, password. */
    public function update(Request $r): void
    {
        $userId = Auth::requireUser();
        $account = $this->accounts->findOwned($userId, $r->intParam('id'));
        $body = $r->body();

        $serverKeys = ['imap_host', 'imap_port', 'imap_encryption', 'smtp_host', 'smtp_port', 'smtp_encryption', 'username'];
        $serverChanged = array_intersect_key($body, array_flip($serverKeys)) !== [];
        $newPassword = isset($body['password']) && $body['password'] !== '' ? (string) $body['password'] : null;

        if (isset($body['color']) && $body['color'] !== '' && !preg_match('/^#[0-9a-f]{6}$/i', (string) $body['color'])) {
            throw HttpException::badRequest('Invalid colour');
        }

        if (isset($body['signature_html'])) {
            $body['signature_html'] = self::cleanSignature((string) $body['signature_html']);
        }
        if (isset($body['signatures'])) {
            // Several signatures: {list: [{id, name, html}], new, reply}. signature_html = the one for new mail.
            $in = is_array($body['signatures']) ? $body['signatures'] : [];
            $list = [];
            foreach (array_slice(is_array($in['list'] ?? null) ? $in['list'] : [], 0, 10) as $sig) {
                $id = preg_replace('/[^a-z0-9-]/i', '', (string) ($sig['id'] ?? '')) ?: bin2hex(random_bytes(4));
                $list[] = ['id' => mb_substr($id, 0, 20), 'name' => mb_substr(trim((string) ($sig['name'] ?? '')), 0, 50), 'html' => self::cleanSignature((string) ($sig['html'] ?? ''))];
            }
            $ids = array_column($list, 'id');
            $pick = static fn ($v) => in_array($v, $ids, true) ? $v : null;
            $data = ['list' => $list, 'new' => $pick($in['new'] ?? null), 'reply' => $pick($in['reply'] ?? null)];
            $body['signatures'] = json_encode($data, JSON_UNESCAPED_UNICODE);
            $forNew = array_values(array_filter($list, static fn ($s) => $s['id'] === $data['new']))[0]['html'] ?? '';
            $body['signature_html'] = $forNew;
        }

        // Changed server settings or password: verify before saving.
        if ($serverChanged || $newPassword !== null) {
            $merged = array_merge($account, array_intersect_key($body, array_flip($serverKeys)));
            $server = ServerSettings::forEmail($account['email'], $merged);
            ImapService::verify($server, $newPassword ?? \Postvak\Crypto::decrypt($account['password_enc']));
        }

        if (array_key_exists('notify', $body)) {
            $body['notify'] = $body['notify'] ? 1 : 0;
        }
        $this->accounts->update((int) $account['id'], $body);
        if ($newPassword !== null) {
            $this->accounts->updatePassword((int) $account['id'], $newPassword);
        }

        Response::json(AccountRepository::toPublic($this->accounts->findOwned($userId, (int) $account['id'])));
    }

    /** DELETE /accounts/{id} — the login account cannot be removed. */
    public function destroy(Request $r): void
    {
        $userId = Auth::requireUser();
        $account = $this->accounts->findOwned($userId, $r->intParam('id'));
        if ($account['is_login']) {
            throw HttpException::badRequest('The account you log in with cannot be removed');
        }
        $this->accounts->delete((int) $account['id']);
        Response::noContent();
    }

    /** Images belong in a signature as uploaded files, not as data: URIs; nothing active either. */
    private static function cleanSignature(string $sig): string
    {
        if (strlen($sig) > 20000) {
            throw HttpException::badRequest('The signature is too long');
        }
        $sig = preg_replace('#<(script|style|iframe|object|embed)\b[^>]*>.*?</\1>#is', '', $sig) ?? '';
        return preg_replace('/\son\w+\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)/i', '', $sig) ?? '';
    }
}
