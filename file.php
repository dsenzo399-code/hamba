<?php
declare(strict_types=1);
require __DIR__ . '/includes/bootstrap.php';

if (!current_user()) {
    http_response_code(403);
    exit;
}

$p = (string)($_GET['p'] ?? '');
$p = str_replace('\\', '/', $p);
if ($p === '' || str_contains($p, '..') || !preg_match('/^[a-z0-9_\/.-]+$/i', $p)) {
    http_response_code(400);
    exit;
}
$full = storage_path('uploads/' . $p);
if (!is_file($full)) {
    http_response_code(404);
    exit;
}
$mime = (new finfo(FILEINFO_MIME_TYPE))->file($full) ?: 'application/octet-stream';
if (!str_starts_with($mime, 'image/')) {
    http_response_code(403);
    exit;
}
header('Content-Type: ' . $mime);
header('Cache-Control: public, max-age=86400');
readfile($full);
