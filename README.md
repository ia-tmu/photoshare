# 写真共有

スマホから写真を選ぶだけでアップロードでき、同じページ内のギャラリーで共有写真を閲覧できるシンプルな写真共有システムです。

## 動作環境

- PHP 8.1 以上
- Webサーバ、または PHP 組み込みサーバ
- PHP拡張:
  - fileinfo
  - gd または Imagick
  - exif（撮影日時ソートを使う場合）
- モダンブラウザ

## Getting Started

1. 設定ファイルを作成します。

```bash
cp config.example.php config.php
```

2. `config.php` を編集します。最低限、管理モードを使う場合は `ADMIN_PASSWORD` を設定してください。

```php
define('ADMIN_PASSWORD', 'your-admin-password');
```

3. アップロード先ディレクトリを用意します。

```bash
mkdir -p uploads
chmod 755 uploads
```

4. ローカルサーバを起動します。

```bash
php -S 127.0.0.1:8000
```

5. ブラウザで開きます。

```text
http://127.0.0.1:8000/
```

## 使い方

通常ページ:

```text
/
```

管理モード:

```text
/?admin=1
```

管理モードでは最初にパスワード入力を求められます。認証に成功すると、写真ごとの削除とアップロード済み写真の全削除ができます。

## 設定

`config.php` は管理パスワードなどの秘密情報を含むためGit管理外です。共有・デプロイ用のひな形は `config.example.php` を更新してください。

主な設定:

- `UPLOAD_DIR`: 画像保存先。絶対パス、またはプロジェクトルートからの相対パス
- `MAX_IMAGE_SIZE`: 1枚あたりの最大アップロードサイズ
- `MAX_UPLOAD_COUNT`: 一度にアップロードできる最大枚数
- `ALLOWED_IMAGE_EXTENSIONS`: 許可する画像拡張子
- `GALLERY_POLL_INTERVAL_SECONDS`: ギャラリーが新規画像を確認する間隔
- `ADMIN_PASSWORD`: 管理モード用パスワード。空の場合、削除APIは無効
- `UPLOAD_RETENTION_SECONDS`: 自動削除までの秒数。`0` なら無効

## メタデータ

アップロード画像は保存前に再エンコードし、EXIF/GPSなどの画像内メタデータを削除します。撮影日時を取得できた場合のみ、ギャラリーの並び替え用データとして `UPLOAD_DIR/.metadata` に保存します。

サーバ環境でメタデータ削除を保証できない形式は保存されません。HEIC/HEIFを受け付けたい場合は、対応するImagick環境を用意してください。

## デプロイメモ

- `config.php` はGit管理外のため、デプロイ先で個別に作成してください。
- `UPLOAD_DIR` はPHP実行ユーザが書き込める必要があります。
- Web公開ディレクトリ外に `UPLOAD_DIR` を置いた場合も、画像は `api/image.php` 経由で表示されます。
