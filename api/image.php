<?php

require __DIR__ . '/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET' && $_SERVER['REQUEST_METHOD'] !== 'HEAD') {
    http_response_code(405);
    exit;
}

$name = (string) (isset($_GET['name']) ? $_GET['name'] : '');
if (!is_safe_stored_name($name)) {
    http_response_code(404);
    exit;
}

$variant = (string) (isset($_GET['variant']) ? $_GET['variant'] : 'original');
$path = upload_dir() . '/' . $name;
if ($variant === 'thumbnail') {
    if (!ensure_thumbnail_for($name)) {
        $variant = 'original';
    } else {
        $path = thumbnail_path($name);
    }
}

if ($variant !== 'original' && $variant !== 'thumbnail') {
    http_response_code(404);
    exit;
}

if (!is_file($path)) {
    http_response_code(404);
    exit;
}

header('Content-Type: ' . image_mime_type($path));
header('Content-Length: ' . (string) filesize($path));
header('Cache-Control: public, max-age=31536000, immutable');
header('X-Content-Type-Options: nosniff');
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    readfile($path);
}
