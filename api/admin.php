<?php

require __DIR__ . '/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(405, ['ok' => false, 'error' => 'POSTメソッドで実行してください。']);
}

$payload = json_decode((string) file_get_contents('php://input'), true);
if (!is_array($payload)) {
    $payload = $_POST;
}

if (!admin_password_is_configured()) {
    json_response(403, ['ok' => false, 'error' => '管理パスワードが設定されていません。config.phpでADMIN_PASSWORDを設定してください。']);
}

$password = (string) (isset($payload['password']) ? $payload['password'] : '');
if (!verify_admin_password($password)) {
    json_response(403, ['ok' => false, 'error' => '管理パスワードが違います。']);
}

$action = (string) (isset($payload['action']) ? $payload['action'] : '');

if ($action === 'verify') {
    json_response(200, ['ok' => true]);
}

if ($action === 'delete') {
    $name = (string) (isset($payload['name']) ? $payload['name'] : '');
    if (!is_safe_stored_name($name)) {
        json_response(400, ['ok' => false, 'error' => '削除対象のファイル名が不正です。']);
    }

    if (!delete_photo_file($name)) {
        json_response(404, ['ok' => false, 'error' => '削除対象のファイルが見つかりません。']);
    }

    json_response(200, ['ok' => true, 'deleted' => 1]);
}

if ($action === 'delete_all') {
    $uploadDir = upload_dir();
    $deleted = 0;
    $failed = 0;
    $generatedDeleted = 0;

    if (is_dir($uploadDir)) {
        foreach (new DirectoryIterator($uploadDir) as $entry) {
            if (!$entry->isFile()) {
                continue;
            }

            $filename = $entry->getFilename();
            if (!is_safe_stored_name($filename)) {
                continue;
            }

            if (delete_photo_file($filename)) {
                $deleted += 1;
            } else {
                $failed += 1;
            }
        }
    }

    foreach ([thumbnail_dir(), metadata_dir()] as $generatedDir) {
        if (!is_dir($generatedDir)) {
            continue;
        }

        foreach (new DirectoryIterator($generatedDir) as $entry) {
            if (!$entry->isFile()) {
                continue;
            }

            $filename = $entry->getFilename();
            if ($filename === '.htaccess' || $filename === '.gitkeep') {
                continue;
            }

            if (@unlink($entry->getPathname())) {
                $generatedDeleted += 1;
            }
        }
    }

    json_response(200, [
        'ok' => true,
        'deleted' => $deleted,
        'failed' => $failed,
        'generatedDeleted' => $generatedDeleted,
    ]);
}

json_response(400, ['ok' => false, 'error' => '管理操作を選択してください。']);
