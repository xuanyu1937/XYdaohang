<?php
/**
 * 前台用户自有导航（user_id = 当前登录用户）的增删改排序接口
 *
 * POST action=add     新增（url 必填，name 为空时自动抓取标题）
 * POST action=update  修改
 * POST action=delete  删除
 * POST action=sort    按 ids 顺序重排
 * POST action=fetch   仅抓取站点信息（预填用）
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
 * 校验分类存在且可被用户选用（公共分类）。
 */
function userSiteCategoryId($db, $value)
{
    $id = (int) $value;

    if ($id <= 0) {
        return 0;
    }

    $stmt = $db->prepare('SELECT id FROM categories WHERE id = ? LIMIT 1');
    $stmt->execute([$id]);

    return $stmt->fetch() ? $id : 0;
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
        $categoryId = userSiteCategoryId($db, $_POST['category_id'] ?? 0);

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

        $stmt = $db->prepare(
            'INSERT INTO nav_links (name, url, logo, description, category_id, user_id, sort_order, is_active, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, 1, NOW())'
        );
        $stmt->execute([$name, $url, $logo, $description, $categoryId, $userId, $sort]);

        $functions->jsonResponse([
            'ok' => true,
            'id' => (int) $db->lastInsertId(),
            'name' => $name,
            'url' => $url,
            'category_id' => $categoryId,
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
        $categoryId = userSiteCategoryId($db, $_POST['category_id'] ?? 0);

        if ($categoryId <= 0) {
            $engine = new CategoryEngine($functions);
            $categoryId = $engine->ensure('未分类', 'bi-collection');
        }

        $stmt = $db->prepare(
            'UPDATE nav_links SET name = ?, url = ?, logo = ?, description = ?, category_id = ?, updated_at = NOW()
             WHERE id = ? AND user_id = ?'
        );
        $stmt->execute([$name, $url, $logo, $description, $categoryId, $id, $userId]);

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
