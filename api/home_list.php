<?php
/**
 * 轩宇-导航 · 首页分组按需加载（v2.18.1）
 *
 * 首页首屏每个分组只渲染 12 条，剩下的由「加载更多」调这里取。
 * 搜索也走这个接口（带 q），不再把全站数据一次性塞进 HTML。
 *
 *   GET ?group=<分类 id> &offset=0 &limit=12        取某分组第 N 条之后的数据
 *   GET ?q=<关键词> &offset=0 &limit=20             全站搜索（名称 / 域名 / 描述 / 别名 / 标签）
 *
 * 约束：
 *   - 只读，不需要登录，不改任何数据；
 *   - 只返回公开字段，私密分组下的条目对访客永远不可见；
 *   - group=0 表示「未分类」（分类已删除但链接仍在）；
 *   - limit 上限 50，offset 上限 5000，防止被当成全量导出接口刷；
 *   - 输出 60 秒公共缓存 + ETag，和首页保持同一套缓存策略。
 */

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/Services.php';
require_once __DIR__ . '/../includes/site_links.php';
require_once __DIR__ . '/../includes/site_card_build.php';
require_once __DIR__ . '/../includes/site_card.php';

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('X-Robots-Tag: noindex, follow');
@ini_set('display_errors', '0');

if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'GET') {
    header('Allow: GET');
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'method_not_allowed', 'message' => '只支持 GET']);
    exit;
}

$functions = new Functions();
$users = new UserService($functions);
$engine = new CategoryEngine($functions);

/**
 * 参数读取：非法值一律回落到默认值，不报错——前端传坏不应导致空白页。
 */
$groupRaw = isset($_GET['group']) ? trim((string) $_GET['group']) : '';
$group = is_numeric($groupRaw) ? (int) $groupRaw : -1;
$group = $group < 0 ? -1 : $group;

$offset = isset($_GET['offset']) ? (int) $_GET['offset'] : 0;
$offset = max(0, min(5000, $offset));

$limit = isset($_GET['limit']) ? (int) $_GET['limit'] : 12;
$limit = max(1, min(50, $limit));

$keyword = isset($_GET['q']) ? trim((string) $_GET['q']) : '';
$keyword = function_exists('mb_substr') ? mb_substr($keyword, 0, 60, 'UTF-8') : substr($keyword, 0, 60);

$db = Database::getInstance()->getConnection();
$currentUser = $users->currentUser();
$viewUserId = $currentUser ? (int) $currentUser['id'] : $users->defaultViewUserId();
$isOwner = $currentUser && (int) $currentUser['id'] === $viewUserId;
$engine->setUserId($viewUserId);

$defaultTarget = $functions->getSetting('default_target') === '_self' ? '_self' : '_blank';
$monitorEnabled = $functions->getSetting('monitor_enabled') !== '0';
$showSpeedLabel = $functions->getSetting('show_speed_label') !== '0';

/**
 * 统一的结果整形：卡片 HTML + 纯字段，两边都给，前端用哪个都行。
 */
function navHomeListShape(array $rows, Functions $functions, $isOwner, $defaultTarget)
{
    $items = [];
    $html = '';

    foreach ($rows as $index => $row) {
        $card = navBuildCard($row, $functions);

        if ($card === null) {
            continue;
        }

        $card['target'] = $defaultTarget;

        ob_start();
        $link = $card;
        $card_index = $index;
        $is_owner = $isOwner;
        require __DIR__ . '/../includes/site_card.php';
        $html .= ob_get_clean();

        $items[] = [
            'id' => (int) $card['id'],
            'name' => (string) $card['display_name'],
            'url' => (string) $card['safe_url'],
            'description' => (string) ($card['description'] ?? ''),
            'logo' => (string) ($card['logo'] ?? ''),
            'tags' => (string) ($card['tags'] ?? ''),
            'alias' => (string) ($card['alias'] ?? ''),
            'category_id' => (int) ($card['category_id'] ?? 0),
            'clicks' => (int) $card['click_count'],
        ];
    }

    return ['items' => $items, 'html' => $html];
}

try {
    $where = [];
    $params = [];

    if ($isOwner) {
        $where[] = 'nl.user_id = ?';
        $params[] = $viewUserId;
    } else {
        $where[] = 'nl.user_id = ?';
        $where[] = 'COALESCE(c.is_private, 0) = 0';
        $params[] = $viewUserId;
    }

    $where[] = 'nl.is_active = 1';
    $where[] = 'nl.deleted_at IS NULL';

    if ($keyword !== '') {
        // 关键词：名称 / 域名 / 描述 / 别名 / 标签 命中任一即可，拼音靠别名（alias）覆盖
        $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $keyword) . '%';
        $where[] = '(nl.name LIKE ? ESCAPE \'\\\\\' OR nl.url LIKE ? ESCAPE \'\\\\\'
                     OR nl.description LIKE ? ESCAPE \'\\\\\' OR nl.alias LIKE ? ESCAPE \'\\\\\'
                     OR nl.tags LIKE ? ESCAPE \'\\\\\' OR c.name LIKE ? ESCAPE \'\\\\\')';

        for ($i = 0; $i < 6; $i++) {
            $params[] = $like;
        }
    }

    if ($group >= 0) {
        if ($group === 0) {
            // 「未分类」：分类已被删除（分类表里没有对上的行）或分类 id 为 0
            $where[] = '(nl.category_id = 0 OR c.id IS NULL)';
        } else {
            $where[] = 'nl.category_id = ?';
            $params[] = $group;
        }
    }

    $sqlWhere = implode(' AND ', $where);

    $countStmt = $db->prepare(
        "SELECT COUNT(*) AS total
         FROM nav_links nl
         LEFT JOIN categories c ON c.id = nl.category_id
         WHERE {$sqlWhere}"
    );
    $countStmt->execute($params);
    $total = (int) ($countStmt->fetch()['total'] ?? 0);

    $stmt = $db->prepare(
        "SELECT nl.*
         FROM nav_links nl
         LEFT JOIN categories c ON c.id = nl.category_id
         WHERE {$sqlWhere}
         ORDER BY COALESCE(nl.clicks, 0) DESC, nl.sort_order ASC, nl.id ASC
         LIMIT {$limit} OFFSET {$offset}"
    );
    $stmt->execute($params);
    $rows = $stmt->fetchAll();
} catch (Throwable $error) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'query_failed', 'message' => '读取失败，请稍后重试']);
    exit;
}

$shaped = navHomeListShape($rows, $functions, $isOwner, $defaultTarget);
$nextOffset = $offset + count($shaped['items']);

$payload = [
    'ok' => true,
    'group' => $group,
    'q' => $keyword,
    'offset' => $offset,
    'limit' => $limit,
    'total' => $total,
    'count' => count($shaped['items']),
    'has_more' => $nextOffset < $total,
    'next_offset' => $nextOffset,
    'items' => $shaped['items'],
    'html' => $shaped['html'],
];

$json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

if ($json === false) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'encode_failed']);
    exit;
}

// 和首页同一套缓存策略：访客可缓存 60 秒，登录用户不缓存（卡片里带编辑按钮）
if ($currentUser) {
    header('Cache-Control: private, no-store, max-age=0');
} else {
    header('Cache-Control: public, max-age=60, s-maxage=60');
    header('Vary: Cookie');
}

$etag = '"' . md5($json) . '"';
header('ETag: ' . $etag);

if (!empty($_SERVER['HTTP_IF_NONE_MATCH']) && trim((string) $_SERVER['HTTP_IF_NONE_MATCH']) === $etag) {
    http_response_code(304);
    header('Content-Length: 0');
    exit;
}

echo $json;
