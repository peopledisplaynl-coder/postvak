<?php
declare(strict_types=1);

namespace Postvak\Cron;

use Postvak\Migrator;
use Postvak\Repositories\AppSettingsRepository;
use Throwable;

/**
 * Everything that runs in the background, started by cron.php (or the cron URL).
 * A lock file makes sure two runs never overlap.
 */
final class CronRunner
{
    /** @param callable(string):void $log */
    public static function run(callable $log): array
    {
        $dir = POSTVAK_ROOT . '/storage';
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        $lock = @fopen($dir . '/cron.lock', 'c');
        if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
            $log('another run is still busy, skipping');
            return ['skipped' => 'locked'];
        }

        $started = microtime(true);
        $results = [];
        try {
            Migrator::runQuietly();
            // Order matters: snoozed mail returns to the inbox before the new-mail check announces it.
            $results['scheduled'] = self::task('scheduled', static fn () => (new \Postvak\Mail\Scheduler())->runDue($log), $log);
            $results['snoozes'] = self::task('snoozes', static fn () => (new \Postvak\Mail\Snoozer())->runDue($log), $log);
            $results['notifications'] = self::task('notifications', static fn () => (new NewMailNotifier())->run($log), $log);
        } finally {
            $summary = ['at' => date(DATE_ATOM), 'took_ms' => (int) ((microtime(true) - $started) * 1000), 'results' => $results];
            try {
                (new AppSettingsRepository())->set('cron_last_run', $summary);
            } catch (Throwable $e) {
                $log('could not store run info: ' . $e->getMessage());
            }
            flock($lock, LOCK_UN);
            fclose($lock);
        }
        return $summary;
    }

    /** Run one task; a failure is logged and does not stop the other tasks. */
    private static function task(string $name, callable $fn, callable $log): mixed
    {
        try {
            return $fn();
        } catch (Throwable $e) {
            $log("$name failed: " . $e->getMessage());
            error_log("[postvak] cron $name: " . $e->getMessage());
            return ['error' => $e->getMessage()];
        }
    }

    /** Last run (for the admin and settings pages): null if cron never ran. */
    public static function lastRun(): ?array
    {
        $value = (new AppSettingsRepository())->get('cron_last_run');
        return is_array($value) ? $value : null;
    }
}
