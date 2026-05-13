<?php

// This file is kept as a reference for older deployments.
// The active configuration file is ../config.php.

define('UPLOAD_DIR', dirname(__DIR__) . '/uploads');
define('APP_TIMEZONE', 'Asia/Tokyo');
define('MAX_IMAGE_SIZE', 25 * 1024 * 1024);
define('MAX_UPLOAD_COUNT', 20);
define('ALLOWED_IMAGE_EXTENSIONS', 'jpg,jpeg,png,gif,webp,heic,heif');
define('THUMBNAIL_MAX_WIDTH', 640);
define('THUMBNAIL_MAX_HEIGHT', 640);
define('THUMBNAIL_JPEG_QUALITY', 72);
define('GALLERY_DEFAULT_SORT', 'newest');
define('GALLERY_DEFAULT_LIMIT', 48);
define('GALLERY_MAX_LIMIT', 120);
define('GALLERY_POLL_INTERVAL_SECONDS', 10);
define('ADMIN_PASSWORD', getenv('ADMIN_PASSWORD') ?: '');
define('UPLOAD_RETENTION_SECONDS', 0);
define('UPLOAD_SUCCESS_MESSAGE', '写真が送信されました');
