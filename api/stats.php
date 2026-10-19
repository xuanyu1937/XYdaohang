<?php
/**
 * 数据统计接口（后台统计中心，供 Chart.js 使用）
 *
 * GET range=7|30|90        统计区间（天）
 * GET user_id=0            统计范围（0=公共导航）
 *
 * 返回：概览数字、点击趋势、热门 Top10、健康状态四类、用户增长。
 * 需管理员登录。
 */

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/Services.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, max-age=0');
header('X-Content-Type-Options: nosniff');
header('X-Robots-Tag: noindex, nofollow');
@ini_set('display_errors', '0');

$functions = new Functions();

if (!$functions->isLoggedIn()) {
    $functions->jsonResponse(['ok' => false, 'error' => '请先登录后台'], 401);
}

$db = Database::getInstance()->getConnection();
$users = new UserService($functions);
$health = new HealthCheckService($functions);

$range = (int) ($_GET['range'] ?? 7);
$range = in_array($range, [7, 30, 90], true) ? $range : 7;
$userId = (int) ($_GET['user_id'] ?? 0);

/**
 * 补全缺失日期，保证图表连续。
 */
function navFillDays(array $rows, $days, $valueKey)
{
    $found = [];

    foreach ($rows as $row) {
        $found[(string) $row['day']] = (float) $row[$valueKey];
    }

    $series = [];

    for ($i = $days - 1; $i >= 0; $i--) {
        $day = date('Y-m-d', strtotime('-' . $i . ' day'));
        $series[] = ['day' => $day, 'value' => $found[$day] ?? 0];
    }

    return $series;
}

/* ------------------------------ 概览 ------------------------------ */

$overview = [];

$stmt = $db->prepare(
    'SELECT COUNT(*) AS total FROM nav_links WHERE user_id = ? AND deleted_at IS NULL'
);
$stmt->execute([$userId]);
$overview['links'] = (int) ($stmt->fetch()['total'] ?? 0);

$stmt = $db->prepare(
    'SELECT COUNT(*) AS total FROM nav_links WHERE user_id = ? AND is_active = 1 AND deleted_at IS NULL'
);
$stmt->execute([$userId]);
$overview['active_links'] = (int) ($stmt->fetch()['total'] ?? 0);

$stmt = $db->prepare('SELECT COUNT(*) AS total FROM categories');
$stmt->execute();
$overview['categories'] = (int) ($stmt->fetch()['total'] ?? 0);

$overview['users'] = $users->countAll();

$stmt = $db->prepare('SELECT COALESCE(SUM(clicks), 0) AS total FROM nav_links WHERE user_id = ? AND deleted_at IS NULL');
$stmt->execute([$userId]);
$overview['clicks'] = (int) ($stmt->fetch()['total'] ?? 0);

// 今日点击：优先用 visit_logs，表缺失时回退为 0
$todayClicks = 0;

try {
    $stmt = $db->prepare(
        'SELECT COUNT(*) AS total FROM visit_logs
         WHERE visited_at >= CURDATE() AND (? = 0 OR user_id = ?)'
    );
    $stmt->execute([$userId, $userId]);
    $todayClicks = (int) ($stmt->fetch()['total'] ?? 0);
} catch (Throwable $error) {
    $todayClicks = 0;
}

$overview['today_clicks'] = $todayClicks;

$stmt = $db->prepare(
    'SELECT COUNT(*) AS total FROM applications WHERE status = ?'
);
$stmt->execute(['pending']);
$overview['pending_applications'] = (int) ($stmt->fetch()['total'] ?? 0);

/* --------------------------- 点击趋势（按天） --------------------------- */

$trend = [];
$trendSource = 'visit_logs';

try {
    $stmt = $db->prepare(
        "SELECT DATE(visited_at) AS day, COUNT(*) AS total
         FROM visit_logs
         WHERE visited_at >= DATE_SUB(CURDATE(), INTERVAL ? DAY) AND (? = 0 OR user_id = ?)
         GROUP BY DATE(visited_at)
         ORDER BY day"
    );
    $stmt->bindValue(1, $range, PDO::PARAM_INT);
    $stmt->bindValue(2, $userId, PDO::PARAM_INT);
    $stmt->bindValue(3, $userId, PDO::PARAM_INT);
    $stmt->execute();

    $rows = [];

    foreach ($stmt->fetchAll() as $row) {
        $rows[] = ['day' => $row['day'], 'value' => $row['total']];
    }

    $trend = navFillDays($rows, $range, 'value');
} catch (Throwable $error) {
    // 未执行升级脚本时 visit_logs 不存在，返回空趋势而不是报错
    $trendSource = 'unavailable';
    $trend = navFillDays([], $range, 'value');
}

/* ------------------------------ 热门 Top10 ------------------------------ */

$stmt = $db->prepare(
    'SELECT id, name, url, clicks FROM nav_links
     WHERE user_id = ? AND deleted_at IS NULL AND clicks > 0
     ORDER BY clicks DESC, id ASC LIMIT 10'
);
$stmt->execute([$userId]);

$top = [];

foreach ($stmt->fetchAll() as $row) {
    $host = parse_url((string) $row['url'], PHP_URL_HOST);

    $top[] = [
        'id' => (int) $row['id'],
        'name' => (string) $row['name'],
        'host' => $host ? preg_replace('/^www\./i', '', $host) : '',
        'clicks' => (int) $row['clicks'],
    ];
}

/* --------------------------- 健康状态四类 --------------------------- */

$healthSummary = $health->summary($userId);

/* ----------------------------- 用户增长 ----------------------------- */

$userGrowth = $users->growthByDay($range);

/* ------------------------------ 分类分布 ------------------------------ */

$stmt = $db->prepare(
    'SELECT c.name, COUNT(nl.id) AS total
     FROM categories c
     LEFT JOIN nav_links nl ON nl.category_id = c.id AND nl.is_active = 1 AND nl.deleted_at IS NULL AND nl.user_id = ?
     GROUP BY c.id, c.name, c.sort_order
     HAVING total > 0
     ORDER BY total DESC
     LIMIT 12'
);
$stmt->execute([$userId]);

$categoryDist = [];

foreach ($stmt->fetchAll() as $row) {
    $categoryDist[] = ['name' => (string) $row['name'], 'total' => (int) $row['total']];
}

$functions->jsonResponse([
    'ok' => true,
    'range' => $range,
    'user_id' => $userId,
    'overview' => $overview,
    'trend' => ['source' => $trendSource, 'items' => $trend],
    'top' => $top,
    'health' => $healthSummary,
    'growth' => $userGrowth,
    'categories' => $categoryDist,
]);
