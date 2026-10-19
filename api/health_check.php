<?php
/**
 * 链接健康检测接口（后台使用）
 *
 * POST id=<id>            检测单条
 * POST all=1 [user_id=0]  检测全部（默认公共导航）
 *
 * 需管理员登录。检测结果写入 nav_links.check_status / latency_ms / last_checked_at。
 */

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/Services.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, max-age=0');
header('X-Content-Type-Options: nosniff');
header('X-Robots-Tag: noindex, nofollow');
@ini_set('display_errors', '0');

// 全量检测耗时较长，放宽脚本时限（部分主机禁止 set_time_limit，失败则忽略）。
@set_time_limit(600);

$functions = new Functions();

if (!$functions->isLoggedIn()) {
    $functions->jsonResponse(['ok' => false, 'error' => '请先登录后台'], 401);
}

if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
    $functions->jsonResponse(['ok' => false, 'error' => 'method_not_allowed'], 405);
}

$functions->requireSameOrigin();

$health = new HealthCheckService($functions);

$id = (int) ($_POST['id'] ?? 0);
$all = (string) ($_POST['all'] ?? '') === '1';
$userId = isset($_POST['user_id']) ? (int) $_POST['user_id'] : 0;

if ($all) {
    $limit = isset($_POST['limit']) ? (int) $_POST['limit'] : 0;

    $result = $health->checkAll($userId, $limit);

    $functions->logMessage(sprintf(
        '[health_check] 全量检测：共 %d 条，正常 %d，较慢 %d，失败 %d',
        $result['total'],
        $result['ok_count'],
        $result['slow'],
        $result['down']
    ));

    $functions->jsonResponse([
        'ok' => true,
        'scope' => 'all',
        'summary' => $result,
    ]);
}

if ($id <= 0) {
    $functions->jsonResponse(['ok' => false, 'error' => 'invalid_id'], 400);
}

$result = $health->checkLink($id);

if (!$result['ok']) {
    $functions->jsonResponse(['ok' => false, 'error' => '记录不存在'], 404);
}

$functions->jsonResponse([
    'ok' => true,
    'scope' => 'single',
    'item' => $result,
]);
