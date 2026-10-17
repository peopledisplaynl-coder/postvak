<?php
declare(strict_types=1);

namespace Postvak;

use PDOException;
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

    /** Run pending migrations, but never let a failure block the request (logged instead). */
    public static function runQuietly(): void
    {
        try {
            if (self::pending() !== []) {
                $done = self::run();
                error_log('[postvak] applied migrations: ' . implode(', ', $done));
            }
        } catch (Throwable $e) {
            error_log('[postvak] automatic migration failed: ' . $e->getMessage());
        }
    }

    /** Split a SQL file into statements (no stored procedures, so ";" at line end is enough). */
    private static function statements(string $sql): array
    {
        $sql = preg_replace('/^\s*--.*$/m', '', $sql) ?? $sql;
        $parts = preg_split('/;\s*(\r?\n|$)/', $sql) ?: [];
        return array_values(array_filter(array_map('trim', $parts), static fn ($s) => $s !== ''));
    }
}
