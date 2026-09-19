<?php
// Dev router for `php -S` (Termux runs without Apache rewrites).
// Serves this project under the /hamba/ prefix with /api/v1/ REST URLs:
//   /hamba/api/v1/<resource>/<action>  ->  api/index.php?route=<resource>/<action>
// Anything else under /hamba/ falls through to the built-in server,
// and anything outside /hamba/ is left alone (e.g. a sibling /imali/ folder).
declare(strict_types=1);

$uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/';

if (strpos($uri, '/hamba/') === 0) {
    $local = ltrim(substr($uri, strlen('/hamba')), '/');
    if (strpos($local, 'api/') === 0) {
        $route = preg_replace('#^api/(?:v1/)?#', '', $local);
        $_GET['route'] = trim((string) $route, '/');
        require __DIR__ . '/api/index.php';
        return;
    }
    if ($local === '' || $local === '/') {
        require __DIR__ . '/index.php';
        return;
    }
    $file = realpath(__DIR__ . '/' . ltrim($local, '/'));
    if ($file !== false && is_file($file) && strpos($file, __DIR__) === 0) {
        return false; // let the built-in server execute/serve it
    }
    http_response_code(404);
    echo 'Not found';
    return;
}

return false;
