<?php
declare(strict_types=1);

/**
 * Loads config, autoloader and error handling. Included by public/api/index.php.
 */

use Postvak\Config;
use Postvak\Http\Response;

define('POSTVAK_ROOT', __DIR__);

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

error_reporting(E_ALL);
ini_set('display_errors', '0');

set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
    if (!(error_reporting() & $severity)) {
        return false;
    }
    throw new ErrorException($message, 0, $severity, $file, $line);
});

set_exception_handler(static function (Throwable $e): void {
    error_log('[postvak] ' . $e::class . ': ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
    $status = $e instanceof \Postvak\Http\HttpException ? $e->status : 500;
    $body = ['error' => $e instanceof \Postvak\Http\HttpException ? $e->getMessage() : 'Internal server error'];
    if (Config::get('debug') && !$e instanceof \Postvak\Http\HttpException) {
        $body['debug'] = ['type' => $e::class, 'message' => $e->getMessage(), 'file' => $e->getFile(), 'line' => $e->getLine()];
    }
    Response::json($body, $status);
});
