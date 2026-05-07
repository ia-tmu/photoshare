<?php

$projectRoot = dirname(__DIR__);
$configPath = $projectRoot . '/config.php';
if (is_file($configPath)) {
    require_once $configPath;
}

function json_response($status, array $payload)
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function config_value($name, $fallback)
{
    if (defined($name)) {
        return constant($name);
    }

    $value = getenv($name);
    return $value === false || $value === '' ? $fallback : $value;
}

date_default_timezone_set((string) config_value('APP_TIMEZONE', 'Asia/Tokyo'));

function config_int($name, $fallback)
{
    return max(0, (int) config_value($name, $fallback));
}

function config_array($name, array $fallback)
{
    $value = config_value($name, $fallback);
    if (is_array($value)) {
        return array_values($value);
    }

    if (is_string($value)) {
        return array_values(array_filter(array_map('trim', explode(',', $value))));
    }

    return $fallback;
}

function is_absolute_path($path)
{
    return substr($path, 0, 1) === '/' || preg_match('/^[A-Za-z]:[\/\\\\]/', $path) === 1;
}

function project_root()
{
    return dirname(__DIR__);
}

function upload_dir()
{
    $configuredDir = (string) config_value('UPLOAD_DIR', project_root() . '/uploads');
    $configuredDir = rtrim($configuredDir, "/\\");

    if ($configuredDir === '') {
        return project_root() . '/uploads';
    }

    if (is_absolute_path($configuredDir)) {
        return $configuredDir;
    }

    return project_root() . '/' . $configuredDir;
}

function thumbnail_dir()
{
    return upload_dir() . '/thumbnails';
}

function allowed_image_extensions()
{
    return array_map(function ($extension) {
        return strtolower(ltrim((string) $extension, '.'));
    }, config_array('ALLOWED_IMAGE_EXTENSIONS', ['jpg', 'jpeg', 'png', 'gif', 'webp', 'heic', 'heif']));
}

function is_allowed_image_name($name)
{
    $extension = strtolower((string) pathinfo($name, PATHINFO_EXTENSION));
    return $extension !== '' && in_array($extension, allowed_image_extensions(), true);
}

function random_suffix()
{
    if (function_exists('random_bytes')) {
        return bin2hex(random_bytes(5));
    }

    if (function_exists('openssl_random_pseudo_bytes')) {
        $bytes = openssl_random_pseudo_bytes(5);
        if ($bytes !== false) {
            return bin2hex($bytes);
        }
    }

    return substr(str_replace('.', '', uniqid('', true)), -10);
}

function safe_public_filename($originalName)
{
    $extension = strtolower((string) pathinfo($originalName, PATHINFO_EXTENSION));
    $extension = preg_replace('/[^a-z0-9]+/', '', $extension);
    if ($extension === null) {
        $extension = '';
    }
    $suffix = random_suffix();

    return date('Ymd_His') . '_' . $suffix . ($extension !== '' ? '.' . $extension : '');
}

function image_mime_type($path, $fallbackName = '')
{
    if (function_exists('finfo_open')) {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        if ($finfo !== false) {
            $mime = finfo_file($finfo, $path);
            finfo_close($finfo);
            if (is_string($mime) && $mime !== '') {
                return $mime;
            }
        }
    }

    $extension = strtolower((string) pathinfo($fallbackName !== '' ? $fallbackName : $path, PATHINFO_EXTENSION));
    switch ($extension) {
        case 'jpg':
        case 'jpeg':
            return 'image/jpeg';
        case 'png':
            return 'image/png';
        case 'gif':
            return 'image/gif';
        case 'webp':
            return 'image/webp';
        case 'heic':
            return 'image/heic';
        case 'heif':
            return 'image/heif';
        default:
            return 'application/octet-stream';
    }
}

function thumbnail_name($originalName)
{
    $baseName = pathinfo($originalName, PATHINFO_FILENAME);
    $safeBaseName = preg_replace('/[^a-zA-Z0-9._-]+/', '-', $baseName);
    if (!is_string($safeBaseName) || $safeBaseName === '') {
        $safeBaseName = sha1($originalName);
    }

    return $safeBaseName . '.jpg';
}

function thumbnail_path($originalName)
{
    return thumbnail_dir() . '/' . thumbnail_name($originalName);
}

function ensure_thumbnail_dir()
{
    $thumbnailDir = thumbnail_dir();

    if (!is_dir($thumbnailDir)) {
        @mkdir($thumbnailDir, 0755, true);
    }

    return is_dir($thumbnailDir) && is_writable($thumbnailDir);
}

function image_resource_from_path($path, $mime)
{
    switch ($mime) {
        case 'image/jpeg':
            return function_exists('imagecreatefromjpeg') ? @imagecreatefromjpeg($path) : false;
        case 'image/png':
            return function_exists('imagecreatefrompng') ? @imagecreatefrompng($path) : false;
        case 'image/gif':
            return function_exists('imagecreatefromgif') ? @imagecreatefromgif($path) : false;
        case 'image/webp':
            return function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($path) : false;
        default:
            return false;
    }
}

function create_thumbnail($sourcePath, $originalName)
{
    if (!function_exists('imagecreatetruecolor') || !function_exists('imagejpeg') || !ensure_thumbnail_dir()) {
        return false;
    }

    $sourceSize = @getimagesize($sourcePath);
    if (!is_array($sourceSize) || count($sourceSize) < 2) {
        return false;
    }

    $sourceWidth = (int) $sourceSize[0];
    $sourceHeight = (int) $sourceSize[1];
    if ($sourceWidth <= 0 || $sourceHeight <= 0) {
        return false;
    }

    $mime = image_mime_type($sourcePath, $originalName);
    $sourceImage = image_resource_from_path($sourcePath, $mime);
    if ($sourceImage === false) {
        return false;
    }

    $maxWidth = max(1, config_int('THUMBNAIL_MAX_WIDTH', 640));
    $maxHeight = max(1, config_int('THUMBNAIL_MAX_HEIGHT', 640));
    $scale = min($maxWidth / $sourceWidth, $maxHeight / $sourceHeight, 1);
    $targetWidth = max(1, (int) round($sourceWidth * $scale));
    $targetHeight = max(1, (int) round($sourceHeight * $scale));

    $thumbnailImage = imagecreatetruecolor($targetWidth, $targetHeight);
    if ($thumbnailImage === false) {
        imagedestroy($sourceImage);
        return false;
    }

    $background = imagecolorallocate($thumbnailImage, 255, 255, 255);
    imagefill($thumbnailImage, 0, 0, $background);

    $resampled = imagecopyresampled(
        $thumbnailImage,
        $sourceImage,
        0,
        0,
        0,
        0,
        $targetWidth,
        $targetHeight,
        $sourceWidth,
        $sourceHeight
    );

    $saved = false;
    if ($resampled) {
        $quality = max(1, min(100, config_int('THUMBNAIL_JPEG_QUALITY', 72)));
        $saved = imagejpeg($thumbnailImage, thumbnail_path($originalName), $quality);
    }

    imagedestroy($thumbnailImage);
    imagedestroy($sourceImage);

    return $saved;
}

function ensure_thumbnail_for($originalName)
{
    if (!is_safe_stored_name($originalName)) {
        return false;
    }

    $sourcePath = upload_dir() . '/' . $originalName;
    if (!is_file($sourcePath)) {
        return false;
    }

    $targetPath = thumbnail_path($originalName);
    if (is_file($targetPath) && filemtime($targetPath) >= filemtime($sourcePath)) {
        return true;
    }

    return create_thumbnail($sourcePath, $originalName);
}

function is_safe_stored_name($name)
{
    return $name !== '' && basename($name) === $name && is_allowed_image_name($name);
}

function format_iso_time($timestamp)
{
    return date('c', $timestamp);
}

function photo_entries()
{
    $uploadDir = upload_dir();
    if (!is_dir($uploadDir)) {
        return [];
    }

    $entries = [];
    foreach (new DirectoryIterator($uploadDir) as $entry) {
        if (!$entry->isFile()) {
            continue;
        }

        $name = $entry->getFilename();
        if (!is_safe_stored_name($name)) {
            continue;
        }

        $timestamp = $entry->getMTime();
        $entries[] = [
            'id' => $name,
            'name' => $name,
            'url' => 'api/image.php?name=' . rawurlencode($name) . '&variant=original',
            'originalUrl' => 'api/image.php?name=' . rawurlencode($name) . '&variant=original',
            'thumbnailUrl' => 'api/image.php?name=' . rawurlencode($name) . '&variant=thumbnail',
            'uploadedAt' => format_iso_time($timestamp),
            'timestamp' => $timestamp,
            'size' => $entry->getSize(),
        ];
    }

    return $entries;
}
