<?php
declare(strict_types=1);

namespace Postvak\Mail;

/**
 * What a message's headers tell about it, for the reader: how to unsubscribe from a mailing list, whether the
 * sender is who they claim to be, and which inbox tab it belongs in. Pure functions on the raw header text.
 */
final class MessageInsights
{
    /** Domains whose mail goes to the "Social" tab. */
    public const SOCIAL_DOMAINS = [
        'facebookmail.com', 'linkedin.com', 'x.com', 'twitter.com', 'instagram.com', 'pinterest.com', 'tiktok.com',
        'reddit.com', 'redditmail.com', 'youtube.com', 'snapchat.com', 'discord.com', 'mastodon.social', 'meetup.com', 'strava.com',
    ];

    /** One header (unfolded), or ''. When it occurs more than once, the first (topmost) one. */
    public static function header(string $raw, string $name): string
    {
        $all = self::headers($raw, $name);
        return $all[0] ?? '';
    }

    /** @return string[] every occurrence of a header, top first */
    public static function headers(string $raw, string $name): array
    {
        $raw = preg_replace("/\r?\n[ \t]+/", ' ', $raw) ?? $raw;
        preg_match_all('/^' . preg_quote($name, '/') . ':[ \t]*(.*)$/mi', $raw, $m);
        return array_map('trim', $m[1]);
    }

    /**
     * How to unsubscribe (RFC 2369 / 8058), or null.
     * @return array{one_click:bool, url:?string, mailto:?string}|null
     */
    public static function unsubscribe(string $raw): ?array
    {
        $value = self::header($raw, 'List-Unsubscribe');
        if ($value === '') {
            return null;
        }
        preg_match_all('/<([^>]+)>/', $value, $m);
        $url = $mailto = null;
        foreach ($m[1] as $target) {
            $target = trim($target);
            if ($url === null && preg_match('#^https://#i', $target)) {
                $url = $target;
            } elseif ($mailto === null && preg_match('#^mailto:#i', $target)) {
                $mailto = $target;
            }
        }
        if ($url === null && $mailto === null) {
            return null;
        }
        $oneClick = $url !== null && stripos(self::header($raw, 'List-Unsubscribe-Post'), 'List-Unsubscribe=One-Click') !== false;
        return ['one_click' => $oneClick, 'url' => $url, 'mailto' => $mailto];
    }

    /** "mailto:x@y.nl?subject=unsub&body=…" → [address, subject, body] */
    public static function parseMailto(string $mailto): ?array
    {
        if (!preg_match('#^mailto:([^?]+)(?:\?(.*))?$#i', $mailto, $m)) {
            return null;
        }
        $address = rawurldecode($m[1]);
        if (!filter_var($address, FILTER_VALIDATE_EMAIL)) {
            return null;
        }
        parse_str($m[2] ?? '', $q);
        $q = array_change_key_case($q);
        return [$address, (string) ($q['subject'] ?? 'unsubscribe'), (string) ($q['body'] ?? 'unsubscribe')];
    }

    /**
     * Is the sender who they claim to be? Uses the Authentication-Results header that the receiving server added
     * (the topmost one), and looks for a display name that pretends to be another address.
     *
     * @return array{level:?string, reasons:string[], spf:?string, dkim:?string, dmarc:?string}
     *   level: null (fine or unknown), 'caution' or 'danger'
     */
    public static function authenticity(string $raw, ?string $fromEmail, ?string $fromName): array
    {
        $results = self::header($raw, 'Authentication-Results');
        $get = static function (string $method) use ($results): ?string {
            return preg_match('/\b' . $method . '\s*=\s*([a-z]+)/i', $results, $m) ? strtolower($m[1]) : null;
        };
        $spf = $get('spf');
        $dkim = $get('dkim');
        $dmarc = $get('dmarc');
        $reasons = [];
        $level = null;

        if ($dmarc === 'fail') {
            $reasons[] = 'dmarc_fail';
            $level = 'danger';
        } elseif (in_array($spf, ['fail', 'softfail'], true) && in_array($dkim, ['fail', 'none', null], true) && $results !== '') {
            $reasons[] = 'not_verified';
            $level = $spf === 'fail' ? 'danger' : 'caution';
        }

        // "ING <info@ing-veilig.example>" or "service@bank.nl <someone@gmail.com>": a name that shows another address.
        $fromDomain = $fromEmail !== null ? strtolower((string) substr(strrchr($fromEmail, '@') ?: '', 1)) : '';
        if ($fromName !== null && preg_match('/[a-z0-9._%+-]+@([a-z0-9.-]+\.[a-z]{2,})/i', $fromName, $m)) {
            $shown = strtolower($m[1]);
            if ($fromDomain !== '' && $shown !== $fromDomain && !str_ends_with($fromDomain, '.' . $shown)) {
                $reasons[] = 'name_shows_other_address';
                $level ??= 'caution';
            }
        }

        // Replies would go to a different domain than the sender's.
        $replyTo = self::header($raw, 'Reply-To');
        if ($replyTo !== '' && $fromDomain !== '' && preg_match('/@([a-z0-9.-]+\.[a-z]{2,})/i', $replyTo, $m)) {
            $replyDomain = strtolower($m[1]);
            if (self::baseDomain($replyDomain) !== self::baseDomain($fromDomain) && $level !== null) {
                $reasons[] = 'reply_to_elsewhere';
            }
        }
        return ['level' => $level, 'reasons' => $reasons, 'spf' => $spf, 'dkim' => $dkim, 'dmarc' => $dmarc];
    }

    /** Parts of a sender address that mark automatic notifications (the "Updates" tab). */
    public const UPDATE_SENDERS = ['noreply', 'no-reply', 'donotreply', 'do-not-reply', 'notification', 'alert@', 'alerts@', 'billing@', 'invoice', 'factuur', 'facturen'];

    /** The tabs besides Primary; the user can switch each one off (its mail then shows under Primary). */
    public const TABS = ['promotions', 'social', 'updates'];

    /** Inbox tab as worked out from the headers alone: social, promotions, updates or primary. */
    public static function autoCategory(string $raw, ?string $fromEmail): string
    {
        $from = strtolower((string) $fromEmail);
        foreach (self::SOCIAL_DOMAINS as $social) {
            if (str_contains($from, $social)) {
                return 'social';
            }
        }
        if (self::header($raw, 'List-Unsubscribe') !== '' || stripos(self::header($raw, 'Precedence'), 'bulk') !== false) {
            return 'promotions';
        }
        foreach (self::UPDATE_SENDERS as $part) {
            if (str_contains($from, $part)) {
                return 'updates';
            }
        }
        return stripos(self::header($raw, 'Auto-Submitted'), 'auto-') !== false ? 'updates' : 'primary';
    }

    /**
     * The tab a message is shown in. $tabs: ['enabled' => tabs switched on, 'senders' => [address => tab]] —
     * a sender the user moved wins over the headers; a tab that is off sends its mail to Primary.
     * Kept in step with searchCriteria(), which finds the same messages on the IMAP server.
     */
    public static function category(string $raw, ?string $fromEmail, ?array $tabs = null): string
    {
        $cat = $tabs['senders'][strtolower((string) $fromEmail)] ?? self::autoCategory($raw, $fromEmail);
        $enabled = $tabs['enabled'] ?? self::TABS;
        return $cat === 'primary' || in_array($cat, $enabled, true) ? $cat : 'primary';
    }

    /** IMAP SEARCH criteria for one inbox tab (see category()). */
    public static function searchCriteria(string $category, ?array $tabs = null): array
    {
        $enabled = array_values(array_intersect(self::TABS, $tabs['enabled'] ?? self::TABS));
        if ($category !== 'primary' && !in_array($category, $enabled, true)) {
            $category = 'primary';
        }
        $senders = $tabs['senders'] ?? [];
        $or = static function (array $keys) use (&$or): array {
            return count($keys) === 1 ? $keys[0] : ['OR', ...$keys[0], ...$or(array_slice($keys, 1))];
        };
        $q = static fn (string $s): string => '"' . addcslashes($s, '"\\') . '"';
        // One parenthesised search key (IMAP: "(" key *(SP key) ")"), usable after NOT or as an OR operand.
        $group = static fn (array $tokens): string => '(' . implode(' ', $tokens) . ')';
        $fromAny = static fn (array $addresses): array => $or(array_map(static fn ($a) => ['FROM', $q($a)], $addresses));

        $base = self::baseCriteria($or, $q);
        if ($category === 'primary') {
            $auto = [];
            foreach ($enabled as $tab) {
                array_push($auto, 'NOT', $group($base[$tab]));
            }
            if ($auto === []) {
                $auto = ['ALL'];
            }
        } else {
            $auto = $base[$category];
        }
        if ($senders === []) {
            return $auto;
        }
        // Senders moved by hand: their mail goes to the chosen tab (or Primary when that tab is off), never by headers.
        $mine = array_keys(array_filter($senders, static fn ($tab) => $category === 'primary'
            ? ($tab === 'primary' || !in_array($tab, $enabled, true))
            : $tab === $category));
        $notMoved = ['NOT', $group($fromAny(array_keys($senders)))];
        $autoPart = [...($auto === ['ALL'] ? [] : $auto), ...$notMoved];
        return $mine === [] ? $autoPart : ['OR', $group($fromAny($mine)), $group($autoPart)];
    }

    /** The headers-only criteria per tab (mutually exclusive, like autoCategory()). @return array<string, array> */
    private static function baseCriteria(callable $or, callable $q): array
    {
        $social = $or(array_map(static fn ($d) => ['FROM', $q($d)], self::SOCIAL_DOMAINS));
        $promo = ['OR', 'HEADER', 'List-Unsubscribe', '""', 'HEADER', 'Precedence', $q('bulk')];
        $updates = $or([...array_map(static fn ($p) => ['FROM', $q($p)], self::UPDATE_SENDERS), ['HEADER', 'Auto-Submitted', $q('auto-')]]);
        return [
            'social'     => $social,
            'promotions' => [...$promo, 'NOT', ...$social],
            'updates'    => [...$updates, 'NOT', ...$promo, 'NOT', ...$social],
        ];
    }

    /** "mail.ing.nl" → "ing.nl" (good enough to compare; not a public-suffix list). */
    private static function baseDomain(string $domain): string
    {
        $parts = explode('.', $domain);
        return implode('.', array_slice($parts, -2));
    }
}
