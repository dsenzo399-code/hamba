<?php
declare(strict_types=1);

header('X-Frame-Options: SAMEORIGIN');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: strict-origin-when-cross-origin');

require dirname(__DIR__) . '/includes/bootstrap.php';
require dirname(__DIR__) . '/includes/api_core.php';
require dirname(__DIR__) . '/includes/api_rest.php';

if (!is_file(storage_path('installed.lock'))) {
    json_response(['ok' => false, 'error' => 'Hamba is not installed. Open /install.php'], 503);
}

$route = trim((string)($_GET['route'] ?? ''), '/');
if ($route === '') {
    $uri = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?? '';
    if (preg_match('#/api/(.+)$#', $uri, $m)) {
        $route = trim($m[1], '/');
    }
}
$parts = $route === '' ? [] : explode('/', $route);
$resource = $parts[0] ?? '';
$action = $parts[1] ?? 'index';
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

try {
    match ($resource) {
        'auth' => handle_auth($action, $method),
        'profile' => handle_profile($action, $method),
        'driver' => handle_driver($action, $method),
        'rides' => handle_rides($action, $method),
        'nearby' => handle_nearby($action, $method),
        'taxi' => handle_taxi($action, $method),
        'ratings' => handle_ratings($action, $method),
        'admin' => handle_admin($action, $method),
        'notifications', 'saved', 'reports', 'support', 'places' => handle_misc($resource, $action, $method),
        default => json_response(['ok' => false, 'error' => 'Not found', 'route' => $route], 404),
    };
} catch (Throwable $e) {
    json_response(['ok' => false, 'error' => 'Server error.'], 500);
}
