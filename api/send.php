<?php

require __DIR__ . '/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');

function normalize_uploads(array $files)
{
    if (!isset($files['name']) || !is_array($files['name'])) {
        return [];
    }

    $normalized = [];
    $count = count($files['name']);

    for ($index = 0; $index < $count; $index += 1) {
        if ((isset($files['error'][$index]) ? $files['error'][$index] : UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            continue;
        }

        $normalized[] = [
            'name' => (string) (isset($files['name'][$index]) ? $files['name'][$index] : ''),
            'tmp_name' => (string) (isset($files['tmp_name'][$index]) ? $files['tmp_name'][$index] : ''),
            'error' => (int) (isset($files['error'][$index]) ? $files['error'][$index] : UPLOAD_ERR_NO_FILE),
            'size' => (int) (isset($files['size'][$index]) ? $files['size'][$index] : 0),
        ];
    }

    return $normalized;
}

function ensure_upload_dir()
{
    $uploadDir = upload_dir();

    if (!is_dir($uploadDir) && !mkdir($uploadDir, 0755, true)) {
        json_response(500, ['ok' => false, 'error' => '画像保存先を作成できません。']);
    }

    if (!is_writable($uploadDir)) {
        json_response(500, ['ok' => false, 'error' => '画像保存先に書き込めません。']);
    }

    return $uploadDir;
}

function save_uploads(array $uploads)
{
    $maxCount = config_int('MAX_UPLOAD_COUNT', 20);
    $maxSize = config_int('MAX_IMAGE_SIZE', 25 * 1024 * 1024);

    if (count($uploads) > $maxCount) {
        json_response(413, ['ok' => false, 'error' => '一度に保存できる写真は' . $maxCount . '枚までです。']);
    }

    $uploadDir = ensure_upload_dir();
    $saved = [];

    foreach ($uploads as $upload) {
        if ($upload['error'] !== UPLOAD_ERR_OK) {
            json_response(400, ['ok' => false, 'error' => '画像のアップロードに失敗しました。']);
        }

        if ($upload['size'] > $maxSize) {
            json_response(413, ['ok' => false, 'error' => '1枚あたり' . round($maxSize / 1024 / 1024) . 'MBまで保存できます。']);
        }

        if (!is_uploaded_file($upload['tmp_name'])) {
            json_response(400, ['ok' => false, 'error' => '不正なアップロードです。']);
        }

        if (!is_allowed_image_name($upload['name'])) {
            json_response(415, ['ok' => false, 'error' => 'この画像形式は保存できません。']);
        }

        $mime = image_mime_type($upload['tmp_name'], $upload['name']);
        if (strpos($mime, 'image/') !== 0) {
            json_response(415, ['ok' => false, 'error' => '画像ファイルを選択してください。']);
        }

        $storedName = safe_public_filename($upload['name']);
        $targetPath = $uploadDir . '/' . $storedName;

        if (!move_uploaded_file($upload['tmp_name'], $targetPath)) {
            json_response(500, ['ok' => false, 'error' => '画像を保存できませんでした。']);
        }

        $hasThumbnail = ensure_thumbnail_for($storedName);

        $saved[] = [
            'name' => $storedName,
            'url' => 'api/image.php?name=' . rawurlencode($storedName) . '&variant=original',
            'originalUrl' => 'api/image.php?name=' . rawurlencode($storedName) . '&variant=original',
            'thumbnailUrl' => 'api/image.php?name=' . rawurlencode($storedName) . '&variant=' . ($hasThumbnail ? 'thumbnail' : 'original'),
            'size' => $upload['size'],
        ];
    }

    return $saved;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(405, ['ok' => false, 'error' => 'POSTメソッドで送信してください。']);
}

$uploads = normalize_uploads(isset($_FILES['photos']) ? $_FILES['photos'] : []);
if ($uploads === []) {
    json_response(400, ['ok' => false, 'error' => '保存する写真を選択してください。']);
}

$savedFiles = save_uploads($uploads);

json_response(200, [
    'ok' => true,
    'files' => count($savedFiles),
    'photos' => $savedFiles,
    'successMessage' => (string) config_value('UPLOAD_SUCCESS_MESSAGE', '写真を保存しました。'),
]);
