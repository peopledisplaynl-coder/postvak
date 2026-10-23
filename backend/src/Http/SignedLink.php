<?php
declare(strict_types=1);

namespace Postvak\Http;

use Postvak\Crypto;

/**
 * Links to an attachment or the original message that work without the session cookie.
 *
 * Phones do not always send the cookie when a file is opened: an installed app on iPhone opens links in Safari
 * (its own cookies), and Chrome on Android may fetch a PDF again for its viewer or the download list without the
 * SameSite=Strict session cookie. The link then carries a signature over exactly this file of this message for
 * this user, valid for a few hours, so it opens anyway and cannot be reused for anything else.
 */
final class SignedLink
{
    public const TTL = 6 * 3600;

    /** "u=…&exp=…&sig=…" for one attachment ($index) or the original message ($index null). */
    public static function query(int $userId, int $accountId, int $uid, string $folder, ?int $index, ?int $now = null): string
    {
        $exp = ($now ?? time()) + self::TTL;
        $sig = self::sign($userId, $accountId, $uid, $folder, $index, $exp);
        return http_build_query(['u' => $userId, 'exp' => $exp, 'sig' => $sig]);
    }

    /** The user the link was made for, or null when the request carries no (valid, unexpired) signature. */
    public static function verify(Request $r, int $accountId, int $uid, string $folder, ?int $index, ?int $now = null): ?int
    {
        $sig = (string) $r->query('sig', '');
        if ($sig === '') {
            return null;
        }
        $userId = (int) $r->query('u', 0);
        $exp = (int) $r->query('exp', 0);
        if ($userId <= 0 || $exp < ($now ?? time())) {
            return null;
        }
        $expected = self::sign($userId, $accountId, $uid, $folder, $index, $exp);
        return hash_equals($expected, $sig) ? $userId : null;
    }

    private static function sign(int $userId, int $accountId, int $uid, string $folder, ?int $index, int $exp): string
    {
        $data = implode("\n", ['postvak-link-v1', $userId, $accountId, $uid, $folder, $index ?? 'source', $exp]);
        return substr(rtrim(strtr(base64_encode(hex2bin(Crypto::hash($data))), '+/', '-_'), '='), 0, 32);
    }
}
