<?php

/*
|--------------------------------------------------------------------------
| Dev smoke-test router (php -S)
|--------------------------------------------------------------------------
| `php artisan serve` spawns a child PHP process that does NOT inherit
| `-d extension=...` flags — and this machine's php.ini lacks pdo_sqlite.
| Run the app locally with:
|
|   php -d extension=pdo_sqlite -d extension=sqlite3 -S 127.0.0.1:8127 server.php
|
| In-process, no child process, extensions loaded. Production uses the
| Docker image (tools/Dockerfile) where pdo_sqlite is present.
*/

// Serve existing files under public/ directly (PHP's built-in server does this
// when the router returns false). Without this branch every static asset is
// routed into the app: Filament publishes its CSS/JS/fonts under public/, so
// the panel would load HTML where it expects assets — and the app answered the
// request with a redirect to /login. The Blade pages never needed this because
// their CSS/JS come from CDNs.
$uri = urldecode((string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH));
if ($uri !== '/' && is_file(__DIR__.'/public'.$uri)) {
    return false;
}

$path = __DIR__.'/public/index.php';

require $path;
