<?php
declare(strict_types=1);

namespace Postvak\Dav;

use Postvak\Database;
use Sabre\DAV\PropPatch;
use Sabre\DAVACL\PrincipalBackend\AbstractBackend;

/** Principals are Postvak users: principals/<login address>. No groups or delegation. */
final class PrincipalBackend extends AbstractBackend
{
    public const PREFIX = 'principals/';

    public static function uriFor(string $email): string
    {
        return self::PREFIX . strtolower($email);
    }

    private static function toPrincipal(array $user): array
    {
        return [
            'uri' => self::uriFor($user['login_email']),
            '{DAV:}displayname' => $user['display_name'] ?: $user['login_email'],
            '{http://sabredav.org/ns}email-address' => $user['login_email'],
        ];
    }

    public function getPrincipalsByPrefix($prefixPath)
    {
        // Only "principals"; listing every user is not needed by clients and would leak addresses.
        return [];
    }

    public function getPrincipalByPath($path)
    {
        if (!str_starts_with((string) $path, self::PREFIX)) {
            return null;
        }
        $email = substr((string) $path, strlen(self::PREFIX));
        if (str_contains($email, '/')) {
            return null; // calendar-proxy-read/-write groups are not supported
        }
        $user = Database::one('SELECT login_email, display_name FROM users WHERE login_email = ?', [$email]);
        return $user ? self::toPrincipal($user) : null;
    }

    public function updatePrincipal($path, PropPatch $propPatch)
    {
    }

    public function searchPrincipals($prefixPath, array $searchProperties, $test = 'allof')
    {
        return [];
    }

    public function findByUri($uri, $principalPrefix)
    {
        if (str_starts_with((string) $uri, 'mailto:')) {
            $user = Database::one('SELECT login_email FROM users WHERE login_email = ?', [strtolower(substr((string) $uri, 7))]);
            return $user ? self::uriFor($user['login_email']) : null;
        }
        return null;
    }

    public function getGroupMemberSet($principal)
    {
        return [];
    }

    public function getGroupMembership($principal)
    {
        return [];
    }

    public function setGroupMemberSet($principal, array $members)
    {
    }
}
