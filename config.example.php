<?php

// Copy this file to config.php and adjust values for your environment.

// Absolute path, or a path relative to this project root.
define('UPLOAD_DIR', __DIR__ . '/uploads');
define('APP_TIMEZONE', 'Asia/Tokyo');

// Upload limits.
define('MAX_IMAGE_SIZE', 25 * 1024 * 1024);
define('MAX_UPLOAD_COUNT', 20);
define('ALLOWED_IMAGE_EXTENSIONS', 'jpg,jpeg,png,gif,webp,heic,heif');
define('THUMBNAIL_MAX_WIDTH', 512);
define('THUMBNAIL_MAX_HEIGHT', 512);
define('THUMBNAIL_JPEG_QUALITY', 72);

// Gallery behavior.
define('GALLERY_DEFAULT_SORT', 'newest');
define('GALLERY_DEFAULT_LIMIT', 48);
define('GALLERY_MAX_LIMIT', 120);
define('GALLERY_POLL_INTERVAL_SECONDS', 10);

// Admin mode. Set a non-empty password to enable deletion via ?admin=1.
define('ADMIN_PASSWORD', getenv('ADMIN_PASSWORD') ?: '');

// Cleanup behavior. Set to 0 to keep files indefinitely.
define('UPLOAD_RETENTION_SECONDS', 0);

// User-facing messages.
define('UPLOAD_SUCCESS_MESSAGE', '写真が送信されました');
