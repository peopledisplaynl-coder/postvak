<?php
declare(strict_types=1);

/**
 * Background jobs (new mail notifications, …). Run every few minutes from the
 * hosting control panel, e.g. in Plesk: Scheduled Tasks → "Run a PHP script" →
 * postvak-app/cron.php, every 5 minutes. Hosts that can only call a URL:
 * set 'cron_key' in config.php and use https://your-postvak/api/cron?key=…
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only\n");
}

require __DIR__ . '/bootstrap.php';

// bootstrap.php buffers output for the JSON API; here we want to see it.
while (ob_get_level() > 0) {
    ob_end_clean();
}
// Routine log lines go to storage/logs/cron.log instead of the output: hosts such as Plesk e-mail
// every line a cron job writes to stderr. Only a real failure (below) still ends up in that e-mail.
$logDir = POSTVAK_ROOT . '/storage/logs';
if (is_dir($logDir) || @mkdir($logDir, 0700, true)) {
    $logFile = $logDir . '/cron.log';
    if (@filesize($logFile) > 1024 * 1024) {
        @rename($logFile, $logFile . '.1');
    }
    ini_set('error_log', $logFile);
}

set_exception_handler(static function (Throwable $e): void {
    fwrite(STDERR, '[postvak cron] ' . $e::class . ': ' . $e->getMessage() . "\n");
    exit(1);
});
@set_time_limit(240);

$result = Postvak\Cron\CronRunner::run(static function (string $line): void {
    echo '[' . date('H:i:s') . '] ' . $line . "\n";
});
echo json_encode($result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), "\n";
