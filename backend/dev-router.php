<?php
// Router for PHP's built-in server during local development (replaces .htaccess):
//   php -S localhost:8000 -t backend/public backend/dev-router.php
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if (preg_match('#^/\.well-known/(caldav|carddav)$#', $path)) {
    header('Location: /dav/', true, 301);
    return true;
}
if (str_starts_with($path, '/dav/') || $path === '/dav') {
    require __DIR__ . '/public/api/dav.php';
    return true;
}
if (str_starts_with($path, '/api/') || $path === '/api') {
    require __DIR__ . '/public/api/index.php';
    return true;
}
return false; // let the built-in server serve static files
