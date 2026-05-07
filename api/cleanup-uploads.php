<?php

require __DIR__ . '/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(405, ['ok' => false, 'error' => 'POSTメソッドで実行してください。']);
}

$retentionSeconds = config_int('UPLOAD_RETENTION_SECONDS', 0);
if ($retentionSeconds === 0) {
    json_response(200, ['ok' => true, 'deleted' => 0, 'skipped' => true]);
}

$uploadDir = upload_dir();
if (!is_dir($uploadDir)) {
    json_response(200, ['ok' => true, 'deleted' => 0]);
}

$threshold = time() - $retentionSeconds;
$deleted = 0;
$failed = 0;

foreach (new DirectoryIterator($uploadDir) as $entry) {
    if (!$entry->isFile()) {
        continue;
    }

    $filename = $entry->getFilename();
    if (!is_safe_stored_name($filename) || $entry->getMTime() > $threshold) {
        continue;
    }

    if (@unlink($entry->getPathname())) {
        $thumbnailPath = thumbnail_path($filename);
        if (is_file($thumbnailPath)) {
            @unlink($thumbnailPath);
        }
        delete_photo_metadata($filename);
        $deleted += 1;
    } else {
        $failed += 1;
    }
}

json_response(200, [
    'ok' => true,
    'deleted' => $deleted,
    'failed' => $failed,
]);
