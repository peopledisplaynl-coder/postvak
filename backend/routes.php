<?php
declare(strict_types=1);

use Postvak\Controllers\AccountController;
use Postvak\Controllers\AdminController;
use Postvak\Controllers\TrustedSenderController;
use Postvak\Controllers\AuthController;
use Postvak\Controllers\MailController;
use Postvak\Controllers\SettingsController;
use Postvak\Http\Router;

/** @var Router $router */

$auth = new AuthController();
$accounts = new AccountController();
$mail = new MailController();
$settings = new SettingsController();
$trusted = new TrustedSenderController();
$admin = new AdminController();

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

$router->get('/trusted-senders', [$trusted, 'index']);
$router->post('/trusted-senders', [$trusted, 'store']);
$router->delete('/trusted-senders/{id}', [$trusted, 'destroy']);

$router->get('/admin/overview', [$admin, 'overview']);
$router->post('/admin/migrate', [$admin, 'migrate']);
$router->get('/admin/users', [$admin, 'users']);
$router->patch('/admin/users/{id}', [$admin, 'updateUser']);
$router->delete('/admin/users/{id}', [$admin, 'deleteUser']);
$router->get('/admin/security', [$admin, 'security']);
$router->post('/admin/security/release', [$admin, 'release']);
$router->get('/admin/settings', [$admin, 'settings']);
$router->patch('/admin/settings', [$admin, 'updateSettings']);

$router->get('/health', static fn () => \Postvak\Http\Response::json(['ok' => true, 'version' => POSTVAK_VERSION]));
