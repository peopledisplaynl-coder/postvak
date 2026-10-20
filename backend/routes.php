<?php
declare(strict_types=1);

use Postvak\Controllers\AccountController;
use Postvak\Controllers\AdminController;
use Postvak\Controllers\TrustedSenderController;
use Postvak\Controllers\AuthController;
use Postvak\Controllers\CalendarController;
use Postvak\Controllers\ContactController;
use Postvak\Controllers\FileController;
use Postvak\Controllers\MailController;
use Postvak\Controllers\PushController;
use Postvak\Controllers\RuleController;
use Postvak\Controllers\SignatureImageController;
use Postvak\Controllers\TwoFactorController;
use Postvak\Controllers\SettingsController;
use Postvak\Http\Router;

/** @var Router $router */

$auth = new AuthController();
$accounts = new AccountController();
$mail = new MailController();
$settings = new SettingsController();
$trusted = new TrustedSenderController();
$admin = new AdminController();
$contacts = new ContactController();
$push = new PushController();
$twoFactor = new TwoFactorController();
$calendar = new CalendarController();

$router->post('/auth/login', [$auth, 'login']);
$router->post('/auth/2fa', [$auth, 'verifyTwoFactor']);
$router->post('/auth/logout', [$auth, 'logout']);
$router->get('/auth/me', [$auth, 'me']);
$router->get('/auth/options', [$auth, 'options']);

$router->get('/settings', [$settings, 'show']);
$router->patch('/settings', [$settings, 'update']);

$router->get('/accounts', [$accounts, 'index']);
$router->post('/accounts', [$accounts, 'store']);
$router->patch('/accounts/{id}', [$accounts, 'update']);
$router->delete('/accounts/{id}', [$accounts, 'destroy']);

$router->get('/accounts/{id}/folders', [$mail, 'folders']);
$router->post('/accounts/{id}/folders', [$mail, 'createFolder']);
$router->patch('/accounts/{id}/folders', [$mail, 'renameFolder']);
$router->delete('/accounts/{id}/folders', [$mail, 'deleteFolder']);
$router->post('/accounts/{id}/folders/empty', [$mail, 'emptyFolder']);
$router->post('/accounts/{id}/folders/read', [$mail, 'markFolderRead']);
$router->get('/accounts/{id}/messages', [$mail, 'messages']);
$router->get('/accounts/{id}/messages/{uid}', [$mail, 'show']);
$router->post('/accounts/{id}/messages/{uid}/invitation', [$mail, 'answerInvitation']);
$router->post('/accounts/{id}/messages/{uid}/unsubscribe', [$mail, 'unsubscribe']);
$router->post('/accounts/{id}/messages/{uid}/mute', [$mail, 'mute']);
$router->get('/accounts/{id}/messages/{uid}/source', [$mail, 'source']);
$router->get('/accounts/{id}/quota', [$mail, 'quota']);
$router->get('/accounts/{id}/attachments', [$mail, 'attachments']);
$router->get('/accounts/{id}/messages/{uid}/attachments/{index}', [$mail, 'attachment']);
$router->get('/accounts/{id}/messages/{uid}/conversation', [$mail, 'conversation']);
$router->post('/accounts/{id}/move', [$mail, 'move']);
$router->post('/accounts/{id}/flags', [$mail, 'flags']);
$router->post('/accounts/{id}/labels', [$mail, 'labels']);
$router->post('/accounts/{id}/send', [$mail, 'send']);
$router->post('/accounts/{id}/drafts', [$mail, 'saveDraft']);
$router->delete('/accounts/{id}/drafts/{uid}', [$mail, 'deleteDraft']);

$router->get('/calendars', [$calendar, 'calendars']);
$router->post('/calendars', [$calendar, 'createCalendar']);
$router->patch('/calendars/{id}', [$calendar, 'updateCalendar']);
$router->delete('/calendars/{id}', [$calendar, 'deleteCalendar']);
$router->post('/calendars/{id}/share', [$calendar, 'share']);
$router->delete('/calendars/{id}/share', [$calendar, 'unshare']);
$router->post('/calendar-feeds', [$calendar, 'addFeed']);
$router->post('/calendar-feeds/{id}/refresh', [$calendar, 'refreshFeed']);
$router->delete('/calendar-feeds/{id}', [$calendar, 'deleteFeed']);
$router->get('/ical/{token}', [$calendar, 'ical']);
$router->get('/events', [$calendar, 'events']);
$router->get('/events/{id}', [$calendar, 'event']);
$router->post('/events', [$calendar, 'createEvent']);
$router->put('/events/{id}', [$calendar, 'updateEvent']);
$router->delete('/events/{id}', [$calendar, 'deleteEvent']);
$router->get('/tasks', [$calendar, 'tasks']);
$router->post('/tasks', [$calendar, 'createTask']);
$router->patch('/tasks/{id}', [$calendar, 'updateTask']);
$router->delete('/tasks/{id}', [$calendar, 'deleteTask']);
$router->get('/dav', [$calendar, 'davInfo']);
$router->post('/dav/passwords', [$calendar, 'createAppPassword']);
$router->delete('/dav/passwords/{id}', [$calendar, 'deleteAppPassword']);

$router->get('/contacts', [$contacts, 'suggest']);
$router->get('/contacts/list', [$contacts, 'index']);
$router->get('/contacts/export', [$contacts, 'export']);
$router->post('/contacts/import', [$contacts, 'import']);
$router->post('/contacts', [$contacts, 'store']);
$router->patch('/contacts/{id}', [$contacts, 'update']);
$router->delete('/contacts/{id}', [$contacts, 'destroy']);
$router->get('/contacts/{id}/photo', [$contacts, 'photo']);
$router->post('/contacts/{id}/photo', [$contacts, 'setPhoto']);
$router->delete('/contacts/{id}/photo', [$contacts, 'removePhoto']);
$router->get('/unified/messages', [$mail, 'unified']);
$router->post('/accounts/{id}/scheduled', [$mail, 'schedule']);
$router->post('/scheduled/{id}/cancel', [$mail, 'cancelScheduled']);
$router->post('/scheduled/{id}/send', [$mail, 'sendScheduledNow']);
$router->post('/accounts/{id}/snooze', [$mail, 'snooze']);
$router->post('/accounts/{id}/unsnooze', [$mail, 'unsnooze']);

$router->get('/trusted-senders', [$trusted, 'index']);
$router->post('/trusted-senders', [$trusted, 'store']);
$router->delete('/trusted-senders/{id}', [$trusted, 'destroy']);

$router->get('/admin/overview', [$admin, 'overview']);
$router->post('/admin/migrate', [$admin, 'migrate']);
$router->get('/admin/users', [$admin, 'users']);
$router->patch('/admin/users/{id}', [$admin, 'updateUser']);
$router->delete('/admin/users/{id}', [$admin, 'deleteUser']);
$router->post('/admin/users/{id}/reset-2fa', [$admin, 'resetTwoFactor']);
$router->get('/admin/security', [$admin, 'security']);
$router->post('/admin/security/release', [$admin, 'release']);
$router->get('/admin/settings', [$admin, 'settings']);
$router->patch('/admin/settings', [$admin, 'updateSettings']);

// Rules, out-of-office replies, templates
$rules = new RuleController();
$router->get('/rules', [$rules, 'index']);
$router->post('/rules', [$rules, 'store']);
$router->post('/rules/order', [$rules, 'order']);
$router->post('/rules/apply', [$rules, 'apply']);
$router->put('/rules/{id}', [$rules, 'update']);
$router->patch('/rules/{id}', [$rules, 'update']);
$router->delete('/rules/{id}', [$rules, 'destroy']);
$router->get('/autoreplies', [$rules, 'autoreplies']);
$router->put('/autoreplies/{id}', [$rules, 'saveAutoreply']);
$router->get('/templates', [$rules, 'templates']);
$router->post('/templates', [$rules, 'storeTemplate']);
$router->put('/templates/{id}', [$rules, 'updateTemplate']);
$router->delete('/templates/{id}', [$rules, 'destroyTemplate']);

$signatureImages = new SignatureImageController();
$router->post('/signature-images', [$signatureImages, 'store']);
$router->get('/signature-images/{token}', [$signatureImages, 'show']);

// Large attachments as a download link. The page and the download itself are public (secret link).
$files = new FileController();
$router->get('/files', [$files, 'index']);
$router->post('/files', [$files, 'create']);
$router->post('/files/{token}/chunk', [$files, 'chunk']);
$router->delete('/files/{token}', [$files, 'destroy']);
$router->get('/files/{token}', [$files, 'page']);
$router->get('/files/{token}/download', [$files, 'download']);

$router->get('/health', static fn () => \Postvak\Http\Response::json(['ok' => true, 'version' => POSTVAK_VERSION]));

$router->get('/push', [$push, 'show']);
$router->post('/push/subscriptions', [$push, 'subscribe']);
$router->post('/push/unsubscribe', [$push, 'unsubscribe']);
$router->post('/push/test', [$push, 'test']);
$router->get('/cron', [$push, 'cron']);

$router->get('/security', [$twoFactor, 'show']);
$router->post('/security/2fa/setup', [$twoFactor, 'setup']);
$router->post('/security/2fa/enable', [$twoFactor, 'enable']);
$router->post('/security/2fa/disable', [$twoFactor, 'disable']);
$router->post('/security/2fa/recovery', [$twoFactor, 'recovery']);
$router->post('/security/devices/forget', [$twoFactor, 'forgetDevices']);
