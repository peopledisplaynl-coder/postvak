<?php
declare(strict_types=1);

/**
 * CalDAV entry point: /dav/... is rewritten here by .htaccess. See Postvak\Dav\Server.
 */

$appDir = getenv('POSTVAK_APP') ?: (is_file(__DIR__ . '/app-path.php') ? require __DIR__ . '/app-path.php' : __DIR__ . '/../..');

require $appDir . '/bootstrap.php';

// Apache with PHP-FPM/CGI hides the Authorization header unless .htaccess passes it on.
if (empty($_SERVER['HTTP_AUTHORIZATION'])) {
    $_SERVER['HTTP_AUTHORIZATION'] = $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
    if ($_SERVER['HTTP_AUTHORIZATION'] === '') {
        unset($_SERVER['HTTP_AUTHORIZATION']);
    }
}

// Library deprecation notices must never end up in the XML.
while (ob_get_level() > 0) {
    ob_end_clean();
}
restore_exception_handler();

Postvak\Dav\Server::run((string) ($_SERVER['REMOTE_ADDR'] ?? ''));
