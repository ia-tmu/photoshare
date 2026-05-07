# 写真共有
スマホから写真を選ぶだけでサーバ上のフォルダへ保存し、会場スクリーンや大型プロジェクタで即時閲覧できるシンプルな写真共有システムです。

## 動作環境
- PHP 8.1 以上（推奨: 8.2 以上）
- Webサーバ（Apache / Nginx）または PHP 組み込みサーバ
- PHP拡張:
	- fileinfo（必須）
	- gd または Imagick（必須、アップロード画像のメタデータ削除に使用）
	- exif（推奨、撮影日時の取得に使用）
- モダンブラウザ（Chrome / Safari / Edge の最新版）

## 使い方

アップロード画面:

```text
/
```

ギャラリー画面:

```text
/gallery.html
```

## 設定

調整が必要な値はプロジェクト直下の `config.php` に集約しています。

```php
define('UPLOAD_DIR', __DIR__ . '/uploads');
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
define('UPLOAD_RETENTION_SECONDS', 0);
define('UPLOAD_SUCCESS_MESSAGE', '写真を保存しました。');
```

`UPLOAD_DIR` は絶対パス、またはプロジェクトルートからの相対パスで指定できます。Web公開ディレクトリ外に置いた場合も、ギャラリーは `api/image.php` 経由で表示します。

アップロード時に `UPLOAD_DIR/thumbnails` へ軽量サムネイルを生成します。ギャラリー一覧はサムネイルを使い、画像クリック時の拡大表示のみ original を読み込みます。GD が未対応の画像形式では original にフォールバックします。

アップロード画像は保存前に再エンコードし、EXIF/GPSなどの画像内メタデータを削除します。撮影日時を取得できた場合のみ、ギャラリーの並び替え用データとして `UPLOAD_DIR/.metadata` に保存します。HEIC/HEIFなど、サーバ環境でメタデータ削除を保証できない形式は保存されません。

`GALLERY_POLL_INTERVAL_SECONDS` は `gallery.html` が新規画像を確認する間隔です。新しい画像が見つかると、既存グリッドへふわっと追加表示されます。

## ローカル起動

```bash
php -S 127.0.0.1:8000
```

ブラウザで `http://127.0.0.1:8000/` を開きます。

## アップロード先の権限

デプロイ先で「画像保存先を作成できません。」または「画像保存先に書き込めません。」と出る場合は、PHP実行ユーザが `UPLOAD_DIR` に書き込めるようにしてください。

```bash
mkdir -p uploads
chmod 755 uploads
```
