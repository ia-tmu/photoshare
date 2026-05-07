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

function compare_timestamps($leftTimestamp, $rightTimestamp, $direction)
{
    if ($leftTimestamp === $rightTimestamp) {
        return 0;
    }

    if ($direction === 'asc') {
        return $leftTimestamp < $rightTimestamp ? -1 : 1;
    }

    return $rightTimestamp < $leftTimestamp ? -1 : 1;
}

function compare_capture_timestamps(array $left, array $right, $direction)
{
    $leftCaptured = isset($left['capturedTimestamp']) ? (int) $left['capturedTimestamp'] : 0;
    $rightCaptured = isset($right['capturedTimestamp']) ? (int) $right['capturedTimestamp'] : 0;

    if ($leftCaptured > 0 && $rightCaptured > 0) {
        $capturedCompare = compare_timestamps($leftCaptured, $rightCaptured, $direction);
        if ($capturedCompare !== 0) {
            return $capturedCompare;
        }
    } elseif ($leftCaptured > 0) {
        return -1;
    } elseif ($rightCaptured > 0) {
        return 1;
    }

    return compare_timestamps($left['timestamp'], $right['timestamp'], $direction);
}

usort($photos, function (array $left, array $right) use ($sort) {
    switch ($sort) {
        case 'oldest':
            return compare_timestamps($left['timestamp'], $right['timestamp'], 'asc');
        case 'captured_newest':
            return compare_capture_timestamps($left, $right, 'desc');
        case 'captured_oldest':
            return compare_capture_timestamps($left, $right, 'asc');
        case 'name_asc':
            return strnatcasecmp($left['name'], $right['name']);
        case 'name_desc':
            return strnatcasecmp($right['name'], $left['name']);
        default:
            return compare_timestamps($left['timestamp'], $right['timestamp'], 'desc');
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
