<?php
/**
 * 轩宇-导航 · 后台 AJAX：批量补齐本地图标（v2.18.1）
 *
 * 每次请求处理一批（默认 10 条），前端循环调用直到 done。
 * 只处理 logo 为空的条目，不改动已有图标。
 */

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/Services.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');

$functions = new Functions();

if (!$functions->isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'unauthorized']);
    exit;
}

if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'method_not_allowed']);
    exit;
}

$functions->requireSameOrigin();

$offset = isset($_POST['offset']) ? max(0, (int) $_POST['offset']) : 0;
$limit = isset($_POST['limit']) ? (int) $_POST['limit'] : 10;
$skipRaw = isset($_POST['skip']) ? (string) $_POST['skip'] : '';
$skipIds = $skipRaw === '' ? [] : array_slice(array_filter(array_map('intval', explode(',', $skipRaw))), 0, 500);

try {
    $service = new IconCacheService($functions);
    $result = $service->backfillEmptyLogos($limit, $offset, $skipIds);
    echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $error) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'server_error', 'message' => '批量补齐失败，请稍后重试'], JSON_UNESCAPED_UNICODE);
}
