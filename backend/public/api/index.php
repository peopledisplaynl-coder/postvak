<?php
declare(strict_types=1);

/**
 * Single entry point for the API: /api/...
 *
 * This file lives in the public web root. The rest of the backend (src, vendor,
 * config) should live outside it. POSTVAK_APP points to that folder; the deploy
 * script fills in the right relative path. Default: the backend folder in this repo.
 */

const POSTVAK_VERSION = '0.1.0';

$appDir = getenv('POSTVAK_APP') ?: (is_file(__DIR__ . '/app-path.php') ? require __DIR__ . '/app-path.php' : __DIR__ . '/../..');

require $appDir . '/bootstrap.php';

use Postvak\Auth;
use Postvak\Http\Request;
use Postvak\Http\Router;

header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
header('X-Frame-Options: DENY');

$request = Request::fromGlobals();

Auth::start();
if ($request->path !== '/auth/login') {
    Auth::checkCsrf($request);
}

$router = new Router();
require $appDir . '/routes.php';
$router->dispatch($request);
