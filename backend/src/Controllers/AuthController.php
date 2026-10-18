<?php
declare(strict_types=1);

namespace Postvak\Controllers;

use Postvak\Auth;
use Postvak\Config;
use Postvak\Migrator;
use Postvak\Repositories\AppSettingsRepository;
use Postvak\Http\HttpException;
use Postvak\Http\Request;
use Postvak\Http\Response;
use Postvak\Mail\ImapService;
use Postvak\Mail\ServerSettings;
use Postvak\RateLimiter;
use Postvak\Repositories\AccountRepository;
use Postvak\Repositories\SettingsRepository;
use Postvak\Repositories\UserRepository;
use Postvak\Security\TwoFactor;

final class AuthController
{
    public function __construct(
        private readonly UserRepository $users = new UserRepository(),
        private readonly AccountRepository $accounts = new AccountRepository(),
        private readonly SettingsRepository $settings = new SettingsRepository(),
    ) {}

    /**
     * POST /auth/login  {email, password, imap_host?, imap_port?, ...}
     * Logs in with the credentials of a mailbox. The first login creates the user.
     */
    public function login(Request $r): void
    {
        $email = strtolower($r->string('email'));
        $password = (string) $r->input('password', '');
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || $password === '') {
            throw HttpException::badRequest('Enter a valid address and password');
        }
        Migrator::runQuietly(); // shared hosting has no CLI: apply database updates here
        $this->assertDomainAllowed($email);
        RateLimiter::check($r->ip(), $email);

        $user = $this->users->findByLoginEmail($email);
        $existing = $user ? $this->accounts->findByEmail((int) $user['id'], $email) : null;

        // Known account: use its stored server settings. New: defaults + optional overrides.
        $server = $existing
            ? ServerSettings::fromAccount($existing)
            : ServerSettings::forEmail($email, $r->body());

        try {
            ImapService::verify($server, $password);
        } catch (HttpException $e) {
            if ($e->status === 401) {
                RateLimiter::record($r->ip(), $email, false);
            }
            throw $e;
        }
        if ($user !== null && !empty($user['blocked'])) {
            throw HttpException::forbidden('This account has been blocked');
        }
        RateLimiter::record($r->ip(), $email, true);

        if ($user === null) {
            $userId = $this->users->create($email);
            $this->accounts->create($userId, $email, $password, $server, true);
        } else {
            $userId = (int) $user['id'];
            if ($existing) {
                // The mailbox password may have been changed elsewhere.
                $this->accounts->updatePassword((int) $existing['id'], $password);
            } else {
                $this->accounts->create($userId, $email, $password, $server, true);
            }
        }

        // Two-factor: the password was right, now the code. Nothing is logged in yet.
        $twoFactor = new TwoFactor();
        if ($twoFactor->enabled($userId) && !$twoFactor->isTrustedDevice($userId)) {
            session_regenerate_id(true);
            $_SESSION = ['pending_2fa' => ['user_id' => $userId, 'email' => $email, 'until' => time() + 300, 'tries' => 0]];
            Response::json(['two_factor' => true]);
        }

        $this->users->touchLogin($userId);
        $csrf = Auth::login($userId);
        $this->respondWithSession($userId, $csrf);
    }

    /**
     * POST /auth/2fa  {code, remember?} — second step of the login: a code from the
     * authenticator app or a recovery code. Five tries, then log in again.
     */
    public function verifyTwoFactor(Request $r): void
    {
        $pending = $_SESSION['pending_2fa'] ?? null;
        if (!is_array($pending) || $pending['until'] < time()) {
            unset($_SESSION['pending_2fa']);
            throw HttpException::unauthorized('Log in again');
        }
        $twoFactor = new TwoFactor();
        $userId = (int) $pending['user_id'];
        if (!$twoFactor->check($userId, $r->string('code'))) {
            $_SESSION['pending_2fa']['tries'] = $pending['tries'] + 1;
            RateLimiter::record($r->ip(), (string) $pending['email'], false);
            if ($_SESSION['pending_2fa']['tries'] >= 5) {
                unset($_SESSION['pending_2fa']);
                throw HttpException::tooMany('Too many wrong codes, log in again');
            }
            throw HttpException::badRequest('Wrong code');
        }
        unset($_SESSION['pending_2fa']);
        if ($r->input('remember')) {
            $twoFactor->trustThisDevice($userId, $r->header('User-Agent'));
        }
        $this->users->touchLogin($userId);
        $csrf = Auth::login($userId);
        $this->respondWithSession($userId, $csrf);
    }

    /** POST /auth/logout */
    public function logout(Request $r): void
    {
        Auth::logout();
        Response::noContent();
    }

    /** GET /auth/me — current session, used by the frontend at startup. */
    public function me(Request $r): void
    {
        $userId = Auth::requireUser();
        $this->respondWithSession($userId, (string) Auth::csrfToken());
    }

    private function respondWithSession(int $userId, string $csrf): void
    {
        $user = $this->users->find($userId);
        $user['is_admin'] = Auth::isAdmin($userId);
        $user['two_factor'] = (new TwoFactor())->enabled($userId);
        Response::json([
            'user'     => $user,
            'accounts' => array_map([AccountRepository::class, 'toPublic'], $this->accounts->forUser($userId)),
            'settings' => $this->settings->get($userId),
            'csrf'     => $csrf,
        ]);
    }

    private function assertDomainAllowed(string $email): void
    {
        $allowed = (new AppSettingsRepository())->allowedLoginDomains();
        if ($allowed === []) {
            return;
        }
        $domain = substr(strrchr($email, '@') ?: '', 1);
        if (!in_array($domain, array_map('strtolower', $allowed), true)) {
            throw HttpException::forbidden('This address cannot be used to log in here');
        }
    }
}
