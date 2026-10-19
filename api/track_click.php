<?php
/**
 * 点击量统计接口
 * 前端使用 sendBeacon / keepalive fetch 调用，不阻塞外链跳转。
 * v2.0.0 起同时写入 visit_logs，用于热门排行与访问趋势。
 */

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../config/database.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, max-age=0');
header('X-Content-Type-Options: nosniff');
header('X-Robots-Tag: noindex, nofollow');
@ini_set('display_errors', '0');

function navClickResponse($data, $status = 200)
{
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    navClickResponse(['ok' => false, 'error' => 'method_not_allowed'], 405);
}

$id = isset($_POST['id']) ? (int) $_POST['id'] : 0;

if ($id <= 0) {
    $rawBody = file_get_contents('php://input');

    if (is_string($rawBody) && $rawBody !== '') {
        $parsed = [];
        parse_str($rawBody, $parsed);

        if (isset($parsed['id'])) {
            $id = (int) $parsed['id'];
        }
    }
}

if ($id <= 0) {
    navClickResponse(['ok' => false, 'error' => 'invalid_id'], 400);
}

try {
    $db = Database::getInstance()->getConnection();

    $update = $db->prepare(
        'UPDATE nav_links SET clicks = COALESCE(clicks, 0) + 1 WHERE id = ? AND is_active = 1 AND deleted_at IS NULL'
    );
    $update->execute([$id]);

    if ($update->rowCount() === 0) {
        // 兼容尚未执行 v2 升级脚本的老库（没有 deleted_at 列）
        $legacy = $db->prepare('UPDATE nav_links SET clicks = COALESCE(clicks, 0) + 1 WHERE id = ? AND is_active = 1');
        $legacy->execute([$id]);
    }

    $select = $db->prepare('SELECT clicks FROM nav_links WHERE id = ?');
    $select->execute([$id]);
    $row = $select->fetch();

    if (!$row) {
        navClickResponse(['ok' => false, 'error' => 'not_found'], 404);
    }

    // 访问日志：老库或升级失败时忽略，不影响点击数返回。
    try {
        $userId = isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : 0;
        $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
        $ip = substr($ip, 0, 45);

        $log = $db->prepare('INSERT INTO visit_logs (site_id, user_id, ip, visited_at) VALUES (?, ?, ?, NOW())');
        $log->execute([$id, $userId, $ip]);
    } catch (Throwable $logError) {
        // visit_logs 不存在时静默跳过
    }

    navClickResponse([
        'ok' => true,
        'clicks' => (int) $row['clicks'],
    ]);
} catch (Throwable $error) {
    navClickResponse(['ok' => false, 'error' => 'server_error'], 500);
}
