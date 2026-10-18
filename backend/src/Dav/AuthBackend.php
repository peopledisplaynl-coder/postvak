<?php
declare(strict_types=1);

namespace Postvak\Dav;

use Postvak\Http\HttpException;
use Postvak\RateLimiter;
use Sabre\DAV\Auth\Backend\BackendInterface;
use Sabre\HTTP;
use Sabre\HTTP\RequestInterface;
use Sabre\HTTP\ResponseInterface;

/**
 * HTTP Basic authentication for CalDAV clients: login address + app password.
 * Failed attempts count towards the same rate limit as the normal login.
 */
final class AuthBackend implements BackendInterface
{
    public ?int $userId = null;

    public function __construct(private readonly string $ip) {}

    public function check(RequestInterface $request, ResponseInterface $response)
    {
        $auth = new HTTP\Auth\Basic('Postvak', $request, $response);
        $credentials = $auth->getCredentials();
        if (!$credentials) {
            return [false, 'No Basic authentication header'];
        }
        $email = strtolower(trim((string) $credentials[0]));
        try {
            RateLimiter::check($this->ip, $email);
        } catch (HttpException) {
            return [false, 'Too many failed attempts, try again later'];
        }
        $userId = AppPasswords::verify($email, (string) $credentials[1]);
        if ($userId === null) {
            RateLimiter::record($this->ip, $email, false);
            return [false, 'Address or app password is incorrect'];
        }
        $this->userId = $userId;
        return [true, PrincipalBackend::PREFIX . $email];
    }

    public function challenge(RequestInterface $request, ResponseInterface $response)
    {
        (new HTTP\Auth\Basic('Postvak', $request, $response))->requireLogin();
    }
}
