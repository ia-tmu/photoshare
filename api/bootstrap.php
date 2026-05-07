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

function metadata_dir()
{
    return upload_dir() . '/.metadata';
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

function metadata_path($originalName)
{
    return metadata_dir() . '/' . thumbnail_name($originalName) . '.json';
}

function ensure_thumbnail_dir()
{
    $thumbnailDir = thumbnail_dir();

    if (!is_dir($thumbnailDir)) {
        @mkdir($thumbnailDir, 0755, true);
    }

    return is_dir($thumbnailDir) && is_writable($thumbnailDir);
}

function ensure_metadata_dir()
{
    $metadataDir = metadata_dir();

    if (!is_dir($metadataDir)) {
        @mkdir($metadataDir, 0755, true);
    }

    if (!is_dir($metadataDir) || !is_writable($metadataDir)) {
        return false;
    }

    $htaccessPath = $metadataDir . '/.htaccess';
    if (!is_file($htaccessPath)) {
        @file_put_contents($htaccessPath, "Require all denied\n");
    }

    return true;
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

function normalized_exif_datetime($value)
{
    $value = trim((string) $value);
    if ($value === '' || strpos($value, '0000:00:00') === 0) {
        return null;
    }

    if (!preg_match('/^(\d{4}):(\d{2}):(\d{2})[ T](\d{2}):(\d{2}):(\d{2})/', $value, $matches)) {
        return null;
    }

    $timezone = new DateTimeZone(date_default_timezone_get());
    $date = DateTimeImmutable::createFromFormat(
        '!Y-m-d H:i:s',
        $matches[1] . '-' . $matches[2] . '-' . $matches[3] . ' ' . $matches[4] . ':' . $matches[5] . ':' . $matches[6],
        $timezone
    );

    return $date === false ? null : $date->getTimestamp();
}

function extract_capture_timestamp($path)
{
    if (!function_exists('exif_read_data') || !is_file($path)) {
        return null;
    }

    $exif = @exif_read_data($path);
    if (!is_array($exif)) {
        return null;
    }

    foreach (['DateTimeOriginal', 'DateTimeDigitized', 'DateTime'] as $key) {
        if (!isset($exif[$key])) {
            continue;
        }

        $timestamp = normalized_exif_datetime($exif[$key]);
        if ($timestamp !== null) {
            return $timestamp;
        }
    }

    return null;
}

function exif_orientation($path)
{
    if (!function_exists('exif_read_data') || !is_file($path)) {
        return 1;
    }

    $exif = @exif_read_data($path);
    if (!is_array($exif) || !isset($exif['Orientation'])) {
        return 1;
    }

    return max(1, min(8, (int) $exif['Orientation']));
}

function image_apply_orientation($image, $orientation)
{
    switch ($orientation) {
        case 2:
            imageflip($image, IMG_FLIP_HORIZONTAL);
            return $image;
        case 3:
            $rotated = imagerotate($image, 180, 0);
            return $rotated === false ? $image : $rotated;
        case 4:
            imageflip($image, IMG_FLIP_VERTICAL);
            return $image;
        case 5:
            imageflip($image, IMG_FLIP_HORIZONTAL);
            $rotated = imagerotate($image, 270, 0);
            return $rotated === false ? $image : $rotated;
        case 6:
            $rotated = imagerotate($image, 270, 0);
            return $rotated === false ? $image : $rotated;
        case 7:
            imageflip($image, IMG_FLIP_HORIZONTAL);
            $rotated = imagerotate($image, 90, 0);
            return $rotated === false ? $image : $rotated;
        case 8:
            $rotated = imagerotate($image, 90, 0);
            return $rotated === false ? $image : $rotated;
        default:
            return $image;
    }
}

function sanitize_image_with_imagick($sourcePath, $targetPath)
{
    if (!class_exists('Imagick')) {
        return false;
    }

    try {
        $image = new Imagick($sourcePath);
        if (method_exists($image, 'autoOrient')) {
            $image->autoOrient();
        } elseif (method_exists($image, 'autoOrientImage')) {
            $image->autoOrientImage();
        }

        foreach ($image as $frame) {
            $frame->stripImage();
            if (method_exists($frame, 'setImageOrientation')) {
                $frame->setImageOrientation(Imagick::ORIENTATION_TOPLEFT);
            }
        }

        if (method_exists($image, 'setImageCompressionQuality')) {
            $image->setImageCompressionQuality(92);
        }

        $saved = $image->writeImages($targetPath, true);
        $image->clear();
        $image->destroy();

        return $saved && is_file($targetPath) && filesize($targetPath) > 0;
    } catch (Exception $error) {
        return false;
    }
}

function sanitize_image_with_gd($sourcePath, $targetPath, $originalName)
{
    $mime = image_mime_type($sourcePath, $originalName);
    $sourceImage = image_resource_from_path($sourcePath, $mime);
    if ($sourceImage === false) {
        return false;
    }

    if ($mime === 'image/jpeg') {
        $orientedImage = image_apply_orientation($sourceImage, exif_orientation($sourcePath));
        if ($orientedImage !== $sourceImage) {
            imagedestroy($sourceImage);
            $sourceImage = $orientedImage;
        }
    }

    $saved = false;
    switch ($mime) {
        case 'image/jpeg':
            $saved = imagejpeg($sourceImage, $targetPath, 92);
            break;
        case 'image/png':
            imagealphablending($sourceImage, false);
            imagesavealpha($sourceImage, true);
            $saved = imagepng($sourceImage, $targetPath, 6);
            break;
        case 'image/gif':
            $saved = imagegif($sourceImage, $targetPath);
            break;
        case 'image/webp':
            $saved = function_exists('imagewebp') ? imagewebp($sourceImage, $targetPath, 88) : false;
            break;
    }

    imagedestroy($sourceImage);

    return $saved && is_file($targetPath) && filesize($targetPath) > 0;
}

function sanitize_uploaded_image($sourcePath, $targetPath, $originalName)
{
    @unlink($targetPath);

    if (sanitize_image_with_imagick($sourcePath, $targetPath)) {
        return true;
    }

    if (sanitize_image_with_gd($sourcePath, $targetPath, $originalName)) {
        return true;
    }

    @unlink($targetPath);
    return false;
}

function write_photo_metadata($originalName, array $metadata)
{
    if (!is_safe_stored_name($originalName) || !ensure_metadata_dir()) {
        return false;
    }

    $payload = [
        'capturedAt' => isset($metadata['capturedAt']) ? $metadata['capturedAt'] : null,
        'capturedTimestamp' => isset($metadata['capturedTimestamp']) ? $metadata['capturedTimestamp'] : null,
    ];

    return @file_put_contents(
        metadata_path($originalName),
        json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
    ) !== false;
}

function read_photo_metadata($originalName)
{
    if (!is_safe_stored_name($originalName)) {
        return [];
    }

    $path = metadata_path($originalName);
    if (!is_file($path)) {
        return [];
    }

    $metadata = json_decode((string) @file_get_contents($path), true);
    return is_array($metadata) ? $metadata : [];
}

function delete_photo_metadata($originalName)
{
    if (!is_safe_stored_name($originalName)) {
        return;
    }

    $path = metadata_path($originalName);
    if (is_file($path)) {
        @unlink($path);
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
        $metadata = read_photo_metadata($name);
        $capturedTimestamp = isset($metadata['capturedTimestamp']) ? (int) $metadata['capturedTimestamp'] : null;
        if ($capturedTimestamp !== null && $capturedTimestamp <= 0) {
            $capturedTimestamp = null;
        }
        $capturedAt = isset($metadata['capturedAt']) && is_string($metadata['capturedAt']) ? $metadata['capturedAt'] : null;

        $entries[] = [
            'id' => $name,
            'name' => $name,
            'url' => 'api/image.php?name=' . rawurlencode($name) . '&variant=original',
            'originalUrl' => 'api/image.php?name=' . rawurlencode($name) . '&variant=original',
            'thumbnailUrl' => 'api/image.php?name=' . rawurlencode($name) . '&variant=thumbnail',
            'uploadedAt' => format_iso_time($timestamp),
            'timestamp' => $timestamp,
            'capturedAt' => $capturedAt,
            'capturedTimestamp' => $capturedTimestamp,
            'size' => $entry->getSize(),
        ];
    }

    return $entries;
}
