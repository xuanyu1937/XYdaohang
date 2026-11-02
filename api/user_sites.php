<?php
/**
 * 前台用户自有导航（user_id = 当前登录用户）的增删改排序接口
 *
 * POST action=add     新增（url 必填，name 为空时自动抓取标题）
 * POST action=update  修改
 * POST action=delete  删除
 * POST action=sort    按 ids 顺序重排
 * POST action=fetch   仅抓取站点信息（预填用）
 * POST action=quick_preview 收录预览：抓取标题/描述/图标 + 推荐分组，不入库
 * POST action=quick_add     确认收录：按前端确认后的字段落库
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
$users = new UserService($functions);
$siteInfo = new SiteInfoService($functions);

if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
    $functions->jsonResponse(['ok' => false, 'error' => 'method_not_allowed'], 405);
}

$functions->requireSameOrigin();

$user = $users->currentUser();

if (!$user) {
    $functions->jsonResponse(['ok' => false, 'error' => '请先登录'], 401);
}

$userId = (int) $user['id'];
$db = Database::getInstance()->getConnection();
$action = strtolower(trim((string) ($_POST['action'] ?? '')));

/** 每个用户最多保存的导航条数 */
const USER_LINK_LIMIT = 1000;

/**
 * 规范化 URL：补全协议并校验。
 */
function userSiteNormalizeUrl($url)
{
    $url = trim((string) $url);

    if ($url === '' || strlen($url) > 500) {
        return '';
    }

    if (!preg_match('#^https?://#i', $url)) {
        $url = 'https://' . ltrim($url, '/');
    }

    if (!filter_var($url, FILTER_VALIDATE_URL)) {
        return '';
    }

    $parts = parse_url($url);

    if (!$parts || empty($parts['host']) || !in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true)) {
        return '';
    }

    return $url;
}

function userSiteTrim($value, $limit)
{
    $value = trim((string) $value);
    $value = str_replace(["\r", "\n", "\t"], ' ', $value);

    if (function_exists('mb_substr')) {
        return mb_substr($value, 0, $limit, 'UTF-8');
    }

    return substr($value, 0, $limit);
}

/**
 * 校验分类存在且属于当前用户（分组完全私有化，不允许归入他人/公共分组）。
 */
function userSiteCategoryId($db, $value, $userId)
{
    $id = (int) $value;

    if ($id <= 0) {
        return 0;
    }

    $stmt = $db->prepare('SELECT id FROM categories WHERE id = ? AND user_id = ? LIMIT 1');
    $stmt->execute([$id, (int) $userId]);

    return $stmt->fetch() ? $id : 0;
}

/**
 * 把抓到的页面 title 收拾成「能放进卡片」的名字。
 *
 * 站点 title 常见三种形态：
 *   Figma: The collaborative canvas for design, code, and AI   ← 冒号后是一整段宣传语
 *   GitHub - 全球最大的代码托管平台                              ← 横线后是副标题
 *   某某网 | 首页                                              ← 竖线后是频道名
 * 原样入库会让卡片名称被省略号切断，所以按分隔符取主名；
 * 但第一段太短或本身就是「首页」这类通用词时不能取，否则反而丢信息。
 */
function userSiteNiceName($title, $host = '')
{
    $title = trim((string) $title);
    $title = str_replace(["\r", "\n", "\t"], ' ', $title);

    // 压缩连续空白，避免抓到的 title 里带一堆空格
    $title = (string) preg_replace('/\s{2,}/u', ' ', $title);

    if ($title === '') {
        return trim((string) $host);
    }

    $len = function ($value) {
        return function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : strlen($value);
    };

    // 通用词：作为分段名没有辨识度，遇到就跳过这一段
    $generic = ['首页', '主页', '官网', '官方网站', '网站首页', 'home', 'homepage', 'index', 'welcome'];

    $isUsable = static function ($value) use ($generic, $len) {
        $value = trim((string) $value);

        if ($value === '' || $len($value) < 2) {
            return false;
        }

        return !in_array(mb_strtolower($value, 'UTF-8'), $generic, true);
    };

    // 竖线优先级最高：通常是「站点名 | 频道名」结构。
    // 逐段找第一个「不是通用词」的片段，这样「首页 | 某某导航站」能取到后者。
    $candidate = '';

    $parts = preg_split('/\s*[|｜]\s*/u', $title);

    if ($parts && count($parts) > 1) {
        foreach ($parts as $part) {
            if ($isUsable($part)) {
                $candidate = trim((string) $part);
                break;
            }
        }
    }

    // 没有可用的竖线段落，就用完整 title 继续往下判断
    if ($candidate === '') {
        $candidate = $title;
    }

    // 再看冒号 / 破折号：只在整体确实很长时才截，
    // 否则「Vue.js: 渐进式框架」这种本身就够短的名字不该被动
    if ($candidate === $title && $len($candidate) > 24) {
        $split = preg_split('/\s*[:：]\s*|\s+[-–—]\s+|\s*·\s*/u', $candidate, 2);

        if ($split && count($split) > 1) {
            $head = trim((string) $split[0]);

            if ($isUsable($head) && $len($head) <= 24) {
                $candidate = $head;
            }
        }
    }

    $candidate = trim($candidate);

    return $candidate !== '' ? $candidate : trim((string) $host);
}

/**
 * 确认目标条目属于当前用户。
 */
function userSiteOwned($db, $id, $userId)
{
    $stmt = $db->prepare('SELECT id FROM nav_links WHERE id = ? AND user_id = ? LIMIT 1');
    $stmt->execute([(int) $id, (int) $userId]);

    return $stmt->fetch() ? true : false;
}

/**
 * 取分组名，供前端就地渲染新卡片的分组标签。
 */
function userSiteCategoryName($db, $categoryId)
{
    $categoryId = (int) $categoryId;

    if ($categoryId <= 0) {
        return '';
    }

    try {
        $stmt = $db->prepare('SELECT name FROM categories WHERE id = ? LIMIT 1');
        $stmt->execute([$categoryId]);
        $row = $stmt->fetch();

        return $row ? (string) $row['name'] : '';
    } catch (Throwable $error) {
        return '';
    }
}

switch ($action) {
    case 'fetch':
        $url = userSiteNormalizeUrl($_POST['url'] ?? '');

        if ($url === '') {
            $functions->jsonResponse(['ok' => false, 'error' => '网址格式不正确'], 400);
        }

        $functions->jsonResponse(['ok' => true, 'url' => $url, 'meta' => $siteInfo->fetch($url)]);
        break;

    case 'add':
        $url = userSiteNormalizeUrl($_POST['url'] ?? '');

        if ($url === '') {
            $functions->jsonResponse(['ok' => false, 'error' => '请输入有效的网址'], 400);
        }

        $countStmt = $db->prepare('SELECT COUNT(*) AS total FROM nav_links WHERE user_id = ?');
        $countStmt->execute([$userId]);

        if ((int) ($countStmt->fetch()['total'] ?? 0) >= USER_LINK_LIMIT) {
            $functions->jsonResponse(['ok' => false, 'error' => '已达收藏上限（' . USER_LINK_LIMIT . ' 条）'], 400);
        }

        $dupStmt = $db->prepare('SELECT id FROM nav_links WHERE user_id = ? AND url = ? LIMIT 1');
        $dupStmt->execute([$userId, $url]);

        if ($dupStmt->fetch()) {
            $functions->jsonResponse(['ok' => false, 'error' => '该网址已在你的导航中'], 400);
        }

        $name = userSiteTrim($_POST['name'] ?? '', 100);
        $description = userSiteTrim($_POST['description'] ?? '', 200);
        $logo = userSiteTrim($_POST['logo'] ?? '', 500);
        $tags = userSiteTrim($_POST['tags'] ?? '', 255);
        $alias = userSiteTrim($_POST['alias'] ?? '', 255);
        $categoryId = userSiteCategoryId($db, $_POST['category_id'] ?? 0, $userId);

        // 名称为空时抓取标题，顺带补齐描述与图标。
        if ($name === '') {
            $meta = $siteInfo->fetch($url);
            $name = $meta['ok'] ? (string) $meta['title'] : '';

            if ($description === '' && !empty($meta['description'])) {
                $description = userSiteTrim($meta['description'], 200);
            }

            if ($logo === '' && !empty($meta['icons'][0])) {
                $logo = userSiteTrim($meta['icons'][0], 500);
            }

            if ($name === '') {
                $host = parse_url($url, PHP_URL_HOST);
                $name = $host ? preg_replace('/^www\./i', '', $host) : $url;
            }
        }

        if ($categoryId <= 0) {
            $engine = new CategoryEngine($functions);
            $categoryId = $engine->ensure('未分类', 'bi-collection');
        }

        $sortStmt = $db->prepare('SELECT COALESCE(MAX(sort_order), 0) + 1 AS next_sort FROM nav_links WHERE user_id = ?');
        $sortStmt->execute([$userId]);
        $sort = (int) ($sortStmt->fetch()['next_sort'] ?? 1);

        $isStarred = !empty($_POST['is_starred']) ? 1 : 0;

        $stmt = $db->prepare(
            'INSERT INTO nav_links (name, url, logo, description, category_id, user_id, sort_order, is_active, is_starred, tags, alias, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, 1, ?, ?, ?, NOW())'
        );
        $stmt->execute([$name, $url, $logo, $description, $categoryId, $userId, $sort, $isStarred, $tags, $alias]);

        $functions->jsonResponse([
            'ok' => true,
            'id' => (int) $db->lastInsertId(),
            'name' => $name,
            'url' => $url,
            'category_id' => $categoryId,
            'is_starred' => $isStarred,
        ]);
        break;

    case 'star':
        $id = (int) ($_POST['id'] ?? 0);
        $star = !empty($_POST['star']) ? 1 : 0;

        if ($id <= 0 || !userSiteOwned($db, $id, $userId)) {
            $functions->jsonResponse(['ok' => false, 'error' => '记录不存在或无权操作'], 404);
        }

        $stmt = $db->prepare('UPDATE nav_links SET is_starred = ?, updated_at = NOW() WHERE id = ? AND user_id = ?');
        $stmt->execute([$star, $id, $userId]);

        $functions->jsonResponse(['ok' => true, 'id' => $id, 'is_starred' => $star]);
        break;

    case 'move':
        $id = (int) ($_POST['id'] ?? 0);

        if ($id <= 0 || !userSiteOwned($db, $id, $userId)) {
            $functions->jsonResponse(['ok' => false, 'error' => '记录不存在或无权操作'], 404);
        }

        $categoryId = userSiteCategoryId($db, $_POST['category_id'] ?? 0, $userId);

        if ($categoryId <= 0) {
            $engine = new CategoryEngine($functions);
            $categoryId = $engine->ensure('未分类', 'bi-collection');
        }

        $stmt = $db->prepare('UPDATE nav_links SET category_id = ?, updated_at = NOW() WHERE id = ? AND user_id = ?');
        $stmt->execute([$categoryId, $id, $userId]);

        $functions->jsonResponse(['ok' => true, 'id' => $id, 'category_id' => $categoryId]);
        break;

    /* 收录前预览：只抓取、不入库，把结果连同「推荐分组」一起交给前端，
       等用户在收录框里确认（可改标题/描述/分组）后再调 quick_add 落库。 */
    case 'quick_preview':
        $url = userSiteNormalizeUrl($_POST['url'] ?? '');

        if ($url === '') {
            $functions->jsonResponse(['ok' => false, 'error' => '请输入有效的网址'], 400);
        }

        // 已在导航里就不必再走收录流程，直接告诉前端去标记收藏
        $dupStmt = $db->prepare('SELECT id, name FROM nav_links WHERE user_id = ? AND url = ? LIMIT 1');
        $dupStmt->execute([$userId, $url]);
        $existing = $dupStmt->fetch();

        if ($existing) {
            $functions->jsonResponse([
                'ok' => true,
                'url' => $url,
                'duplicate' => true,
                'id' => (int) $existing['id'],
                'name' => (string) $existing['name'],
                'message' => '该网址已在你的导航中',
            ]);
        }

        $meta = $siteInfo->fetch($url);

        $host = (string) parse_url($url, PHP_URL_HOST);
        $hostLabel = $host !== '' ? preg_replace('/^www\./i', '', $host) : $url;

        $name = !empty($meta['ok']) ? userSiteNiceName((string) ($meta['title'] ?? ''), $hostLabel) : '';

        if ($name === '') {
            $name = $hostLabel;
        }

        $description = !empty($meta['description']) ? userSiteTrim($meta['description'], 200) : '';
        $icons = !empty($meta['icons']) && is_array($meta['icons']) ? $meta['icons'] : [];
        $logo = !empty($icons[0]) ? userSiteTrim($icons[0], 500) : '';

        // 候选分组：当前用户可见的分组（公共 + 自己的）
        $engine = new CategoryEngine($functions);
        $engine->setUserId($userId);
        $rows = $engine->all($userId, true);

        $categories = [];

        foreach ($rows as $row) {
            $categories[] = [
                'id' => (int) $row['id'],
                'name' => (string) $row['name'],
                'is_private' => (int) ($row['is_private'] ?? 0),
                'link_count' => (int) ($row['link_count'] ?? 0),
            ];
        }

        // 推荐分组：拿标题 + 描述 + 域名去模糊匹配
        $names = array_column($categories, 'name');
        $suggestedName = $engine->smartMatch($name . ' ' . $description . ' ' . $hostLabel, $names);

        $suggestedId = 0;
        $suggestReason = 'none';

        if ($suggestedName !== null) {
            foreach ($categories as $category) {
                if ($category['name'] === $suggestedName) {
                    $suggestedId = $category['id'];
                    $suggestReason = 'keyword';
                    break;
                }
            }
        }

        // 没匹配上就退回「未分类」，再退回第一个分组
        if ($suggestedId === 0) {
            foreach ($categories as $category) {
                if ($category['name'] === '未分类') {
                    $suggestedId = $category['id'];
                    $suggestReason = 'fallback';
                    break;
                }
            }
        }

        if ($suggestedId === 0 && $categories) {
            $suggestedId = $categories[0]['id'];
            $suggestReason = 'fallback';
        }

        $functions->jsonResponse([
            'ok' => true,
            'url' => $url,
            'duplicate' => false,
            'name' => $name,
            'description' => $description,
            'logo' => $logo,
            'icons' => array_slice($icons, 0, 5),
            'host' => $hostLabel,
            'fetch_ok' => !empty($meta['ok']),
            'categories' => $categories,
            'suggested_category_id' => $suggestedId,
            'suggest_reason' => $suggestReason,
        ]);
        break;

    case 'quick_add':
        $url = userSiteNormalizeUrl($_POST['url'] ?? '');

        if ($url === '') {
            $functions->jsonResponse(['ok' => false, 'error' => '请输入有效的网址'], 400);
        }

        $countStmt = $db->prepare('SELECT COUNT(*) AS total FROM nav_links WHERE user_id = ?');
        $countStmt->execute([$userId]);

        if ((int) ($countStmt->fetch()['total'] ?? 0) >= USER_LINK_LIMIT) {
            $functions->jsonResponse(['ok' => false, 'error' => '已达收藏上限（' . USER_LINK_LIMIT . ' 条）'], 400);
        }

        $dupStmt = $db->prepare('SELECT id FROM nav_links WHERE user_id = ? AND url = ? LIMIT 1');
        $dupStmt->execute([$userId, $url]);
        $existing = $dupStmt->fetch();

        if ($existing) {
            // 已存在则直接标记为收藏
            $starStmt = $db->prepare('UPDATE nav_links SET is_starred = 1, updated_at = NOW() WHERE id = ? AND user_id = ?');
            $starStmt->execute([(int) $existing['id'], $userId]);

            $functions->jsonResponse([
                'ok' => true,
                'id' => (int) $existing['id'],
                'duplicate' => true,
                'is_starred' => 1,
                'message' => '该网址已在你的导航中，已标记为收藏',
            ]);
        }

        // 用户在收录框里确认过的字段优先；没传（或传空）时才现场抓取，
        // 这样 my.php 里「只传 url」的老调用仍然可用。
        $nameGiven = userSiteTrim($_POST['name'] ?? '', 100);
        $descriptionGiven = userSiteTrim($_POST['description'] ?? '', 200);
        $logoGiven = userSiteTrim($_POST['logo'] ?? '', 500);
        $hasConfirmed = $nameGiven !== '';

        $meta = [];
        $name = $nameGiven;
        $description = $descriptionGiven;
        $logo = $logoGiven;

        if (!$hasConfirmed) {
            $meta = $siteInfo->fetch($url);
            $metaHost = (string) parse_url($url, PHP_URL_HOST);
            $metaHost = $metaHost !== '' ? preg_replace('/^www\./i', '', $metaHost) : '';
            $name = !empty($meta['ok']) ? userSiteNiceName((string) ($meta['title'] ?? ''), $metaHost) : '';
            $description = $descriptionGiven !== '' ? $descriptionGiven
                : (!empty($meta['description']) ? userSiteTrim($meta['description'], 200) : '');
            $logo = $logoGiven !== '' ? $logoGiven
                : (!empty($meta['icons'][0]) ? userSiteTrim($meta['icons'][0], 500) : '');
        }

        if ($name === '') {
            $host = parse_url($url, PHP_URL_HOST);
            $name = $host ? preg_replace('/^www\./i', '', $host) : $url;
        }

        $categoryId = userSiteCategoryId($db, $_POST['category_id'] ?? 0, $userId);

        // 前端传的是公共分组（user_id = 0）时上面那条查不到，这里补一次：
        // 收录框里的候选分组来自 CategoryEngine::all()，包含公共分组。
        if ($categoryId <= 0) {
            $requested = (int) ($_POST['category_id'] ?? 0);

            if ($requested > 0) {
                $pubStmt = $db->prepare('SELECT id FROM categories WHERE id = ? AND (user_id = ? OR (user_id = 0 AND is_private = 0)) LIMIT 1');
                $pubStmt->execute([$requested, $userId]);

                if ($pubStmt->fetch()) {
                    $categoryId = $requested;
                }
            }
        }

        if ($categoryId <= 0) {
            $engine = new CategoryEngine($functions);
            $categoryId = $engine->ensure('未分类', 'bi-collection');
        }

        $sortStmt = $db->prepare('SELECT COALESCE(MAX(sort_order), 0) + 1 AS next_sort FROM nav_links WHERE user_id = ?');
        $sortStmt->execute([$userId]);
        $sort = (int) ($sortStmt->fetch()['next_sort'] ?? 1);

        $stmt = $db->prepare(
            'INSERT INTO nav_links (name, url, logo, description, category_id, user_id, sort_order, is_active, is_starred, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, 1, 1, NOW())'
        );
        $stmt->execute([$name, $url, $logo, $description, $categoryId, $userId, $sort]);

        $functions->jsonResponse([
            'ok' => true,
            'id' => (int) $db->lastInsertId(),
            'name' => $name,
            'url' => $url,
            'logo' => $logo,
            'description' => $description,
            'category_id' => $categoryId,
            'category_name' => userSiteCategoryName($db, $categoryId),
            'is_starred' => 1,
            'meta' => $meta,
        ]);
        break;

    case 'update':
        $id = (int) ($_POST['id'] ?? 0);

        if ($id <= 0 || !userSiteOwned($db, $id, $userId)) {
            $functions->jsonResponse(['ok' => false, 'error' => '记录不存在或无权操作'], 404);
        }

        $url = userSiteNormalizeUrl($_POST['url'] ?? '');

        if ($url === '') {
            $functions->jsonResponse(['ok' => false, 'error' => '请输入有效的网址'], 400);
        }

        $dupStmt = $db->prepare('SELECT id FROM nav_links WHERE user_id = ? AND url = ? AND id != ? LIMIT 1');
        $dupStmt->execute([$userId, $url, $id]);

        if ($dupStmt->fetch()) {
            $functions->jsonResponse(['ok' => false, 'error' => '该网址已在你的导航中'], 400);
        }

        $name = userSiteTrim($_POST['name'] ?? '', 100);

        if ($name === '') {
            $functions->jsonResponse(['ok' => false, 'error' => '请输入网站名称'], 400);
        }

        $description = userSiteTrim($_POST['description'] ?? '', 200);
        $logo = userSiteTrim($_POST['logo'] ?? '', 500);
        $tags = userSiteTrim($_POST['tags'] ?? '', 255);
        $alias = userSiteTrim($_POST['alias'] ?? '', 255);
        $categoryId = userSiteCategoryId($db, $_POST['category_id'] ?? 0, $userId);

        if ($categoryId <= 0) {
            $engine = new CategoryEngine($functions);
            $categoryId = $engine->ensure('未分类', 'bi-collection');
        }

        $stmt = $db->prepare(
            'UPDATE nav_links SET name = ?, url = ?, logo = ?, description = ?, category_id = ?, tags = ?, alias = ?, updated_at = NOW()
             WHERE id = ? AND user_id = ?'
        );
        $stmt->execute([$name, $url, $logo, $description, $categoryId, $tags, $alias, $id, $userId]);

        $functions->jsonResponse([
            'ok' => true,
            'id' => $id,
            'name' => $name,
            'url' => $url,
            'category_id' => $categoryId,
        ]);
        break;

    case 'delete':
        $id = (int) ($_POST['id'] ?? 0);

        if ($id <= 0 || !userSiteOwned($db, $id, $userId)) {
            $functions->jsonResponse(['ok' => false, 'error' => '记录不存在或无权操作'], 404);
        }

        $stmt = $db->prepare('DELETE FROM nav_links WHERE id = ? AND user_id = ?');
        $stmt->execute([$id, $userId]);

        $functions->jsonResponse(['ok' => true]);
        break;

    case 'batch_move':
        $ids = $_POST['ids'] ?? [];

        if (is_string($ids)) {
            $decoded = json_decode($ids, true);
            $ids = is_array($decoded) ? $decoded : array_filter(array_map('trim', explode(',', $ids)));
        }

        if (!is_array($ids) || empty($ids)) {
            $functions->jsonResponse(['ok' => false, 'error' => '请选择要移动的网址'], 400);
        }

        $idList = array_values(array_filter(array_map('intval', $ids), function ($v) { return $v > 0; }));

        if (empty($idList)) {
            $functions->jsonResponse(['ok' => false, 'error' => '请选择有效的网址'], 400);
        }

        // 校验所有权
        $placeholders = implode(',', array_fill(0, count($idList), '?'));
        $checkStmt = $db->prepare("SELECT COUNT(*) AS total FROM nav_links WHERE id IN ($placeholders) AND user_id = ?");
        $params = $idList;
        $params[] = $userId;
        $checkStmt->execute($params);
        $owned = (int) ($checkStmt->fetch()['total'] ?? 0);

        if ($owned !== count($idList)) {
            $functions->jsonResponse(['ok' => false, 'error' => '部分网址无权操作'], 403);
        }

        $categoryId = userSiteCategoryId($db, $_POST['category_id'] ?? 0, $userId);

        if ($categoryId <= 0) {
            $engine = new CategoryEngine($functions);
            $categoryId = $engine->ensure('未分类', 'bi-collection');
        }

        $updateStmt = $db->prepare("UPDATE nav_links SET category_id = ?, updated_at = NOW() WHERE id IN ($placeholders) AND user_id = ?");
        $params2 = array_merge([$categoryId], $idList, [$userId]);
        $updateStmt->execute($params2);

        $functions->jsonResponse(['ok' => true, 'count' => count($idList), 'category_id' => $categoryId]);
        break;

    case 'batch_delete':
        $ids = $_POST['ids'] ?? [];

        if (is_string($ids)) {
            $decoded = json_decode($ids, true);
            $ids = is_array($decoded) ? $decoded : array_filter(array_map('trim', explode(',', $ids)));
        }

        if (!is_array($ids) || empty($ids)) {
            $functions->jsonResponse(['ok' => false, 'error' => '请选择要删除的网址'], 400);
        }

        $idList = array_values(array_filter(array_map('intval', $ids), function ($v) { return $v > 0; }));

        if (empty($idList)) {
            $functions->jsonResponse(['ok' => false, 'error' => '请选择有效的网址'], 400);
        }

        $placeholders = implode(',', array_fill(0, count($idList), '?'));
        $checkStmt = $db->prepare("SELECT COUNT(*) AS total FROM nav_links WHERE id IN ($placeholders) AND user_id = ?");
        $params = $idList;
        $params[] = $userId;
        $checkStmt->execute($params);
        $owned = (int) ($checkStmt->fetch()['total'] ?? 0);

        if ($owned !== count($idList)) {
            $functions->jsonResponse(['ok' => false, 'error' => '部分网址无权操作'], 403);
        }

        $delStmt = $db->prepare("DELETE FROM nav_links WHERE id IN ($placeholders) AND user_id = ?");
        $delStmt->execute($params);

        $functions->jsonResponse(['ok' => true, 'count' => count($idList)]);
        break;

    case 'batch_tag':
        $ids = $_POST['ids'] ?? [];

        if (is_string($ids)) {
            $decoded = json_decode($ids, true);
            $ids = is_array($decoded) ? $decoded : array_filter(array_map('trim', explode(',', $ids)));
        }

        if (!is_array($ids) || empty($ids)) {
            $functions->jsonResponse(['ok' => false, 'error' => '请选择网址'], 400);
        }

        $idList = array_values(array_filter(array_map('intval', $ids), function ($v) { return $v > 0; }));

        if (empty($idList)) {
            $functions->jsonResponse(['ok' => false, 'error' => '请选择有效的网址'], 400);
        }

        $placeholders = implode(',', array_fill(0, count($idList), '?'));
        $checkStmt = $db->prepare("SELECT COUNT(*) AS total FROM nav_links WHERE id IN ($placeholders) AND user_id = ?");
        $params = $idList;
        $params[] = $userId;
        $checkStmt->execute($params);
        $owned = (int) ($checkStmt->fetch()['total'] ?? 0);

        if ($owned !== count($idList)) {
            $functions->jsonResponse(['ok' => false, 'error' => '部分网址无权操作'], 403);
        }

        $newTags = userSiteTrim($_POST['tags'] ?? '', 255);
        $mode = ($_POST['mode'] ?? 'append') === 'overwrite' ? 'overwrite' : 'append';
        $newTagArr = array_values(array_filter(array_map('trim', explode(',', $newTags))));

        if ($mode === 'append') {
            // 逐条读取现有 tags 并合并去重
            $selectStmt = $db->prepare("SELECT id, tags FROM nav_links WHERE id IN ($placeholders) AND user_id = ?");
            $selectStmt->execute($params);
            $rows = $selectStmt->fetchAll();
            $updateStmt = $db->prepare('UPDATE nav_links SET tags = ?, updated_at = NOW() WHERE id = ? AND user_id = ?');

            foreach ($rows as $row) {
                $existing = array_values(array_filter(array_map('trim', explode(',', (string) ($row['tags'] ?? '')))));
                $merged = array_values(array_unique(array_merge($existing, $newTagArr)));
                $mergedStr = implode(',', array_slice($merged, 0, 20)); // 最多 20 个标签
                $updateStmt->execute([$mergedStr, (int) $row['id'], $userId]);
            }
        } else {
            $mergedStr = implode(',', array_slice($newTagArr, 0, 20));
            $updateStmt = $db->prepare("UPDATE nav_links SET tags = ?, updated_at = NOW() WHERE id IN ($placeholders) AND user_id = ?");
            $updParams = array_merge([$mergedStr], $idList, [$userId]);
            $updateStmt->execute($updParams);
        }

        $functions->jsonResponse(['ok' => true, 'count' => count($idList)]);
        break;

    case 'batch_add':
        $raw = (string) ($_POST['urls'] ?? '');

        if ($raw === '') {
            $functions->jsonResponse(['ok' => false, 'error' => '请输入网址'], 400);
        }

        $lines = preg_split('/\r\n|\r|\n/', $raw);
        $items = [];

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '') { continue; }

            $name = '';
            $url = '';

            // 支持「名称,网址」「名称\t网址」或纯网址
            if (strpos($line, "\t") !== false) {
                $parts = explode("\t", $line, 2);
                $name = trim($parts[0]);
                $url = userSiteNormalizeUrl($parts[1] ?? '');
            } elseif (strpos($line, ',') !== false && strpos($line, 'http') !== 0) {
                $parts = explode(',', $line, 2);
                $name = trim($parts[0]);
                $url = userSiteNormalizeUrl($parts[1] ?? '');
            } else {
                $url = userSiteNormalizeUrl($line);
            }

            if ($url === '') { continue; }
            $items[] = ['name' => $name, 'url' => $url];
        }

        if (empty($items)) {
            $functions->jsonResponse(['ok' => false, 'error' => '未解析到有效网址'], 400);
        }

        // 上限校验
        $countStmt = $db->prepare('SELECT COUNT(*) AS total FROM nav_links WHERE user_id = ?');
        $countStmt->execute([$userId]);
        $current = (int) ($countStmt->fetch()['total'] ?? 0);

        $categoryId = userSiteCategoryId($db, $_POST['category_id'] ?? 0, $userId);
        if ($categoryId <= 0) {
            $engine = new CategoryEngine($functions);
            $categoryId = $engine->ensure('未分类', 'bi-collection');
        }

        $sortStmt = $db->prepare('SELECT COALESCE(MAX(sort_order), 0) AS max_sort FROM nav_links WHERE user_id = ?');
        $sortStmt->execute([$userId]);
        $sort = (int) ($sortStmt->fetch()['max_sort'] ?? 0);

        $imported = 0;
        $skipped = 0;
        $dupStmt = $db->prepare('SELECT id FROM nav_links WHERE user_id = ? AND url = ? LIMIT 1');
        $insertStmt = $db->prepare(
            'INSERT INTO nav_links (name, url, category_id, user_id, sort_order, is_active, is_starred, created_at)
             VALUES (?, ?, ?, ?, ?, 1, 0, NOW())'
        );

        $seen = [];

        foreach ($items as $item) {
            if (isset($seen[$item['url']])) { $skipped++; continue; }
            $seen[$item['url']] = true;

            $dupStmt->execute([$userId, $item['url']]);
            if ($dupStmt->fetch()) { $skipped++; continue; }

            if ($current + $imported >= USER_LINK_LIMIT) { break; }

            $name = $item['name'];
            if ($name === '') {
                $host = parse_url($item['url'], PHP_URL_HOST);
                $name = $host ? preg_replace('/^www\./i', '', $host) : $item['url'];
            }

            $sort++;
            $insertStmt->execute([$name, $item['url'], $categoryId, $userId, $sort]);
            $imported++;
        }

        $functions->jsonResponse([
            'ok' => true,
            'imported' => $imported,
            'skipped' => $skipped,
            'category_id' => $categoryId,
        ]);
        break;

    case 'sort':
        $ids = $_POST['ids'] ?? [];

        if (is_string($ids)) {
            $decoded = json_decode($ids, true);
            $ids = is_array($decoded) ? $decoded : explode(',', $ids);
        }

        if (!is_array($ids)) {
            $functions->jsonResponse(['ok' => false, 'error' => '参数格式不正确'], 400);
        }

        $update = $db->prepare('UPDATE nav_links SET sort_order = ? WHERE id = ? AND user_id = ?');
        $order = 0;

        try {
            $db->beginTransaction();

            foreach ($ids as $rawId) {
                $id = (int) $rawId;

                if ($id <= 0) {
                    continue;
                }

                $order++;
                $update->execute([$order, $id, $userId]);
            }

            $db->commit();
        } catch (Throwable $error) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }

            $functions->logMessage('[user_sites] 排序失败: ' . $error->getMessage(), 'ERROR');
            $functions->jsonResponse(['ok' => false, 'error' => '保存排序失败'], 500);
        }

        $functions->jsonResponse(['ok' => true, 'count' => $order]);
        break;

    default:
        $functions->jsonResponse(['ok' => false, 'error' => 'unsupported_action'], 400);
}
