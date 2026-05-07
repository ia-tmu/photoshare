<?php

require __DIR__ . '/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    json_response(405, ['ok' => false, 'error' => 'GETメソッドで取得してください。']);
}

$defaultSort = (string) config_value('GALLERY_DEFAULT_SORT', 'newest');
$sort = (string) (isset($_GET['sort']) ? $_GET['sort'] : $defaultSort);
$offset = max(0, (int) (isset($_GET['offset']) ? $_GET['offset'] : 0));
$defaultLimit = config_int('GALLERY_DEFAULT_LIMIT', 48);
$maxLimit = max(1, config_int('GALLERY_MAX_LIMIT', 120));
$limit = max(1, min($maxLimit, (int) (isset($_GET['limit']) ? $_GET['limit'] : $defaultLimit)));
$pollIntervalSeconds = max(2, config_int('GALLERY_POLL_INTERVAL_SECONDS', 10));

$photos = photo_entries();

usort($photos, function (array $left, array $right) use ($sort) {
    switch ($sort) {
        case 'oldest':
            if ($left['timestamp'] === $right['timestamp']) {
                return 0;
            }
            return $left['timestamp'] < $right['timestamp'] ? -1 : 1;
        case 'name_asc':
            return strnatcasecmp($left['name'], $right['name']);
        case 'name_desc':
            return strnatcasecmp($right['name'], $left['name']);
        default:
            if ($right['timestamp'] === $left['timestamp']) {
                return 0;
            }
            return $right['timestamp'] < $left['timestamp'] ? -1 : 1;
    }
});

$total = count($photos);
$slice = array_slice($photos, $offset, $limit);

json_response(200, [
    'ok' => true,
    'sort' => $sort,
    'offset' => $offset,
    'limit' => $limit,
    'total' => $total,
    'hasMore' => $offset + count($slice) < $total,
    'pollIntervalMs' => $pollIntervalSeconds * 1000,
    'photos' => $slice,
]);
