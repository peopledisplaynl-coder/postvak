<?php
declare(strict_types=1);

use Postvak\Controllers\AccountController;
use Postvak\Controllers\AuthController;
use Postvak\Controllers\MailController;
use Postvak\Controllers\SettingsController;
use Postvak\Http\Router;

/** @var Router $router */

$auth = new AuthController();
$accounts = new AccountController();
$mail = new MailController();
$settings = new SettingsController();

$router->post('/auth/login', [$auth, 'login']);
$router->post('/auth/logout', [$auth, 'logout']);
$router->get('/auth/me', [$auth, 'me']);

$router->get('/settings', [$settings, 'show']);
$router->patch('/settings', [$settings, 'update']);

$router->get('/accounts', [$accounts, 'index']);
$router->post('/accounts', [$accounts, 'store']);
$router->patch('/accounts/{id}', [$accounts, 'update']);
$router->delete('/accounts/{id}', [$accounts, 'destroy']);

$router->get('/accounts/{id}/folders', [$mail, 'folders']);
$router->get('/accounts/{id}/messages', [$mail, 'messages']);
$router->get('/accounts/{id}/messages/{uid}', [$mail, 'show']);
$router->get('/accounts/{id}/messages/{uid}/attachments/{index}', [$mail, 'attachment']);
$router->post('/accounts/{id}/move', [$mail, 'move']);
$router->post('/accounts/{id}/flags', [$mail, 'flags']);
$router->post('/accounts/{id}/send', [$mail, 'send']);

$router->get('/contacts', [$mail, 'contacts']);

$router->get('/health', static fn () => \Postvak\Http\Response::json(['ok' => true, 'version' => POSTVAK_VERSION]));
