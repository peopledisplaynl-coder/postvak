<?php
declare(strict_types=1);

namespace Postvak;

use PDOException;
use Postvak\Repositories\AppSettingsRepository;
use Throwable;

/**
 * Applies database/migrations/NNN_name.sql files that have not run yet.
 *
 * There is no command line on shared hosting, so migrations run automatically
 * when someone logs in, and can be started from the admin page.
 */
final class Migrator
{
    /** MySQL errors that mean "already done": duplicate column/key, table exists. */
    private const IGNORABLE = [1050, 1060, 1061, 1091];

    /** @return string[] versions not yet applied, in order */
    public static function pending(): array
    {
        $applied = array_column(Database::all('SELECT version FROM schema_migrations'), 'version');
        return array_values(array_diff(self::available(), $applied));
    }

    /** @return string[] all migration versions on disk */
    public static function available(): array
    {
        $files = glob(POSTVAK_ROOT . '/database/migrations/*.sql') ?: [];
        $versions = array_map(static fn ($f) => basename($f, '.sql'), $files);
        sort($versions);
        return $versions;
    }

    /** @return string[] versions applied now */
    public static function run(): array
    {
        $done = [];
        foreach (self::pending() as $version) {
            $sql = (string) file_get_contents(POSTVAK_ROOT . "/database/migrations/$version.sql");
            foreach (self::statements($sql) as $statement) {
                try {
                    Database::pdo()->exec($statement);
                } catch (PDOException $e) {
                    $code = (int) ($e->errorInfo[1] ?? 0);
                    if (!in_array($code, self::IGNORABLE, true)) {
                        throw new \RuntimeException("Migration $version failed: " . $e->getMessage(), 0, $e);
                    }
                }
            }
            Database::run('INSERT IGNORE INTO schema_migrations (version) VALUES (?)', [$version]);
            $done[] = $version;
        }
        return $done;
    }

    /** Run pending migrations, but never let a failure block the request (logged, and shown on the admin page). */
    public static function runQuietly(): void
    {
        try {
            if (self::pending() !== []) {
                $done = self::run();
                error_log('[postvak] applied migrations: ' . implode(', ', $done));
            }
            self::rememberError(null);
        } catch (Throwable $e) {
            error_log('[postvak] automatic migration failed: ' . $e->getMessage());
            self::rememberError($e->getMessage());
        }
    }

    /**
     * Apply database updates once per version, right after a deploy, instead of waiting for the next login.
     * Costs one file check per request once done.
     */
    public static function ensureCurrent(string $version): void
    {
        $marker = POSTVAK_ROOT . '/storage/.schema-' . preg_replace('/[^0-9A-Za-z.\-]/', '', $version);
        if (is_file($marker)) {
            return;
        }
        self::runQuietly();
        try {
            if (self::pending() === []) {
                @touch($marker);
            }
        } catch (Throwable) {
        }
    }

    /** The last automatic migration error, or null. */
    public static function lastError(): ?string
    {
        try {
            $error = (new AppSettingsRepository())->get('migration_error');
            return is_string($error) ? $error : null;
        } catch (Throwable) {
            return null;
        }
    }

    private static function rememberError(?string $message): void
    {
        try {
            $settings = new AppSettingsRepository();
            if ($message === null) {
                if ($settings->get('migration_error') !== null) {
                    $settings->delete('migration_error');
                }
            } else {
                $settings->set('migration_error', mb_substr($message, 0, 1000));
            }
        } catch (Throwable) {
        }
    }

    /** Split a SQL file into statements (no stored procedures, so ";" at line end is enough). */
    private static function statements(string $sql): array
    {
        // Remove "-- comments", also at the end of a line (a ";" before such a comment must still end the
        // statement, otherwise two statements run as one and MySQL silently skips errors in the second).
        $sql = preg_replace('/(^|\s)--(\s.*)?$/m', '', $sql) ?? $sql;
        $parts = preg_split('/;\s*(\r?\n|$)/', $sql) ?: [];
        return array_values(array_filter(array_map('trim', $parts), static fn ($s) => $s !== ''));
    }
}
