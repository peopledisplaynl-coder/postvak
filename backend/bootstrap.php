<?php
declare(strict_types=1);

/**
 * Loads config, autoloader and error handling. Included by public/api/index.php.
 *
 * Every API response must be valid JSON, whatever happens on the server:
 *  - stray output (notices, BOMs, library debug output) is buffered and discarded;
 *  - deprecation notices from libraries are logged, never turned into errors
 *    (PHP 8.4 emits many of them while compiling third-party code);
 *  - fatal errors (timeouts, memory) still produce a JSON error via the shutdown handler.
 */

use Postvak\Config;
use Postvak\Http\Response;

define('POSTVAK_ROOT', __DIR__);

ob_start();
ini_set('display_errors', '0');
error_reporting(E_ALL);

// Errors from our own code become exceptions; library notices and all deprecations are only logged.
set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
    if (!(error_reporting() & $severity)) {
        return false;
    }
    $isDeprecation = in_array($severity, [E_DEPRECATED, E_USER_DEPRECATED], true);
    $isOwnCode = str_starts_with($file, POSTVAK_ROOT . DIRECTORY_SEPARATOR . 'src')
        || str_starts_with($file, POSTVAK_ROOT . DIRECTORY_SEPARATOR . 'routes.php');
    if ($isDeprecation || !$isOwnCode) {
        if (!$isDeprecation) {
            error_log("[postvak] notice: $message in $file:$line");
        }
        return true; // handled, continue
    }
    throw new ErrorException($message, 0, $severity, $file, $line);
});

register_shutdown_function(static function (): void {
    $error = error_get_last();
    if ($error === null || !in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR], true)) {
        return;
    }
    error_log("[postvak] fatal: {$error['message']} in {$error['file']}:{$error['line']}");
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
    }
    $timeout = str_contains($error['message'], 'Maximum execution time');
    $body = ['error' => $timeout ? 'The mail server took too long to respond' : 'Internal server error'];
    if (class_exists(Config::class, false) && Config::get('debug')) {
        $body['debug'] = ['message' => $error['message'], 'file' => $error['file'], 'line' => $error['line']];
    }
    echo json_encode($body);
});

$autoload = __DIR__ . '/vendor/autoload.php';
if (!is_file($autoload)) {
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Dependencies missing. Run "composer install" in the backend folder.']);
    exit;
}
require $autoload;

$configFile = __DIR__ . '/config/config.php';
if (!is_file($configFile)) {
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'config/config.php missing. Copy config.example.php and fill it in.']);
    exit;
}

Config::load(require $configFile);

// Give slow mail servers some room (only works where the host allows it).
@set_time_limit(60);

set_exception_handler(static function (Throwable $e): void {
    error_log('[postvak] ' . $e::class . ': ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
    $status = $e instanceof \Postvak\Http\HttpException ? $e->status : 500;
    $body = ['error' => $e instanceof \Postvak\Http\HttpException ? $e->getMessage() : 'Internal server error'];
    if (Config::get('debug') && !$e instanceof \Postvak\Http\HttpException) {
        $body['debug'] = ['type' => $e::class, 'message' => $e->getMessage(), 'file' => $e->getFile(), 'line' => $e->getLine()];
    }
    Response::json($body, $status);
});
