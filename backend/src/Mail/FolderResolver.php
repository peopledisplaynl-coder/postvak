<?php
declare(strict_types=1);

namespace Postvak\Mail;

/**
 * Detects special folders (archive, trash, sent, drafts, junk) by name.
 * A folder configured on the account always wins over detection.
 */
final class FolderResolver
{
    /** Lower-case last path segment => role. Covers common English and Dutch names. */
    private const NAMES = [
        'archive'  => ['archive', 'archives', 'archief', 'all mail', 'alle e-mail'],
        'trash'    => ['trash', 'deleted', 'deleted items', 'deleted messages', 'bin', 'prullenbak', 'verwijderd', 'verwijderde items'],
        'sent'     => ['sent', 'sent items', 'sent messages', 'sent mail', 'verzonden', 'verzonden items'],
        'drafts'   => ['drafts', 'draft', 'concepten', 'concept'],
        'junk'     => ['junk', 'spam', 'junk e-mail', 'ongewenste e-mail', 'ongewenst'],
    ];

    /** Fallback paths to create when a role folder is missing (Dovecot style). */
    public const CREATE_DEFAULTS = [
        'archive' => 'Archive',
        'trash'   => 'Trash',
        'sent'    => 'Sent',
        'drafts'  => 'Drafts',
        'junk'    => 'Junk',
    ];

    /**
     * @param array<int, array{path:string, name:string}> $folders
     * @return array<string, ?string> role => folder path
     */
    public static function resolve(array $folders, array $account): array
    {
        $result = [];
        foreach (array_keys(self::NAMES) as $role) {
            $configured = $account[$role . '_folder'] ?? null;
            $result[$role] = $configured ?: self::detect($folders, $role);
        }
        return $result;
    }

    public static function roleOf(string $path, array $resolved): ?string
    {
        if (strcasecmp($path, 'INBOX') === 0) {
            return 'inbox';
        }
        $role = array_search($path, $resolved, true);
        return $role === false ? null : $role;
    }

    private static function detect(array $folders, string $role): ?string
    {
        // Prefer top-level (or INBOX.<name>) folders over deeper matches.
        $best = null;
        $bestDepth = PHP_INT_MAX;
        foreach ($folders as $f) {
            if (!in_array(mb_strtolower($f['name']), self::NAMES[$role], true)) {
                continue;
            }
            $depth = substr_count($f['path'], $f['delimiter'] ?? '/');
            if ($depth < $bestDepth) {
                $best = $f['path'];
                $bestDepth = $depth;
            }
        }
        return $best;
    }
}
