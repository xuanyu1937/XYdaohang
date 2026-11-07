<?php
/**
 * 轩宇-导航 · 统一「新建分类 / 新建分组」接口（v2.12.0）
 *
 * 后台分类管理、后台导航管理筛选下拉、前台首页分类抽屉、我的导航页，
 * 全部走这一个接口 + 同一套字段与校验，避免每个界面各写一套规则。
 *
 * 权限：
 *   - 后台管理员（$_SESSION['admin_id']）：可指定上级分类、归属用户、私密标记，
 *     归属用户留空表示公共分类。
 *   - 前台登录用户：强制归属本人、不允许指定上级分类，保证不能越权建公共分组。
 *
 * POST: name, parent_id(可选), user_id(可选), is_private(可选)
 * 返回: {ok, id, name, label, parent_id, user_id, is_private, reused}
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

if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
    $functions->jsonResponse(['ok' => false, 'error' => 'method_not_allowed'], 405);
}

$functions->requireSameOrigin();

$isAdmin = $functions->isLoggedIn();
$frontUser = $users->currentUser();

if (!$isAdmin && !$frontUser) {
    $functions->jsonResponse(['ok' => false, 'error' => '请先登录后再新建分类'], 401);
}

$db = Database::getInstance()->getConnection();

/* 老库（未执行 upgrade_v2.1.0.sql）缺少 user_id / is_private / parent_id 等列 */
$hasFullColumns = true;
try {
    $db->query('SELECT parent_id, icon, remark, user_id, is_private FROM categories LIMIT 1');
} catch (Throwable $error) {
    $hasFullColumns = false;
}

if (!$isAdmin && !$hasFullColumns) {
    $functions->jsonResponse(['ok' => false, 'error' => '站点尚未升级数据库，无法管理分组'], 500);
}

/** 名称清洗：与后台 categories.php、前台 user_categories.php 保持同一套规则 */
function xycqCleanName($raw)
{
    $name = trim((string) $raw);
    $name = str_replace(["\r", "\n", "\t"], ' ', $name);
    $name = preg_replace('/[\x00-\x1f\x7f]/u', '', $name);

    if (function_exists('mb_substr')) {
        $name = mb_substr($name, 0, 30, 'UTF-8');
    } else {
        $name = substr($name, 0, 90);
    }

    return trim((string) $name);
}

$name = xycqCleanName($_POST['name'] ?? '');
$length = function_exists('mb_strlen') ? mb_strlen($name, 'UTF-8') : strlen($name);

if ($name === '' || $length < 2 || $length > 30) {
    $functions->jsonResponse(['ok' => false, 'error' => '名称长度需为 2 ~ 30 个字符']);
}

if (preg_match('/[<>]/u', $name)) {
    $functions->jsonResponse(['ok' => false, 'error' => '名称不能包含 < 或 >']);
}

$isPrivate = !empty($_POST['is_private']) && (string) $_POST['is_private'] !== '0' ? 1 : 0;
$parentId = 0;
$ownerId = 0;

if ($isAdmin) {
    $parentId = $hasFullColumns ? max(0, (int) ($_POST['parent_id'] ?? 0)) : 0;
    $ownerId = max(0, (int) ($_POST['user_id'] ?? 0));

    if ($parentId > 0) {
        $stmt = $db->prepare('SELECT id, parent_id FROM categories WHERE id = ? LIMIT 1');
        $stmt->execute([$parentId]);
        $parent = $stmt->fetch();

        if (!$parent || (int) ($parent['parent_id'] ?? 0) > 0) {
            $functions->jsonResponse(['ok' => false, 'error' => '上级分类无效（仅支持两级分类）']);
        }
    }

    if ($ownerId > 0) {
        $stmt = $db->prepare('SELECT id, username FROM users WHERE id = ? LIMIT 1');
        $stmt->execute([$ownerId]);

        if (!$stmt->fetch()) {
            $functions->jsonResponse(['ok' => false, 'error' => '归属用户不存在']);
        }
    }
} else {
    /* 前台用户：无视传入的归属与上级，强制建成本人分组 */
    $ownerId = (int) $frontUser['id'];
    $parentId = 0;

    try {
        $count = (int) $db->query('SELECT COUNT(*) AS c FROM categories WHERE user_id = ' . $ownerId)->fetch()['c'];
    } catch (Throwable $error) {
        $count = 0;
    }

    if ($count >= 100) {
        $functions->jsonResponse(['ok' => false, 'error' => '分组数量已达上限（100 个）']);
    }
}

/* 同一归属 + 同一上级下的重名直接复用，避免各处重复建同名分类 */
try {
    if ($hasFullColumns) {
        $stmt = $db->prepare('SELECT id, is_private FROM categories WHERE name = ? AND user_id = ? AND COALESCE(parent_id, 0) = ? LIMIT 1');
        $stmt->execute([$name, $ownerId, $parentId]);
    } else {
        $stmt = $db->prepare('SELECT id FROM categories WHERE name = ? LIMIT 1');
        $stmt->execute([$name]);
    }

    $exist = $stmt->fetch();
} catch (Throwable $error) {
    $exist = null;
}

if ($exist) {
    $functions->jsonResponse([
        'ok' => true,
        'id' => (int) $exist['id'],
        'name' => $name,
        'label' => xycqDisplayName($db, $name, $ownerId),
        'parent_id' => $parentId,
        'user_id' => $ownerId,
        'is_private' => (int) ($exist['is_private'] ?? $isPrivate),
        'reused' => true,
    ]);
}

try {
    if ($hasFullColumns) {
        $stmt = $db->prepare('SELECT COALESCE(MAX(sort_order), 0) AS m FROM categories WHERE user_id = ?');
        $stmt->execute([$ownerId]);
        $sort = (int) $stmt->fetch()['m'] + 1;

        $stmt = $db->prepare('INSERT INTO categories (parent_id, name, icon, sort_order, remark, user_id, is_private) VALUES (?, ?, ?, ?, NULL, ?, ?)');
        $stmt->execute([$parentId, $name, 'bi-folder', $sort, $ownerId, $isPrivate]);
    } else {
        $sort = (int) $db->query('SELECT COALESCE(MAX(sort_order), 0) AS m FROM categories')->fetch()['m'] + 1;
        $stmt = $db->prepare('INSERT INTO categories (name, sort_order) VALUES (?, ?)');
        $stmt->execute([$name, $sort]);
    }
} catch (Throwable $error) {
    $functions->jsonResponse(['ok' => false, 'error' => '保存失败，请稍后重试'], 500);
}

$functions->jsonResponse([
    'ok' => true,
    'id' => (int) $db->lastInsertId(),
    'name' => $name,
    'label' => xycqDisplayName($db, $name, $ownerId),
    'parent_id' => $parentId,
    'user_id' => $ownerId,
    'is_private' => $isPrivate,
    'reused' => false,
]);

/**
 * 下拉里显示用的名称：只在本站存在同名分类时追加归属后缀，
 * 与后台 nav_links.php 的显示规则保持一致。
 */
function xycqDisplayName(PDO $db, $name, $ownerId)
{
    try {
        $stmt = $db->prepare('SELECT COUNT(*) AS c FROM categories WHERE name = ?');
        $stmt->execute([$name]);

        if ((int) $stmt->fetch()['c'] < 2) {
            return $name;
        }

        if ($ownerId > 0) {
            $stmt = $db->prepare('SELECT username FROM users WHERE id = ? LIMIT 1');
            $stmt->execute([$ownerId]);
            $owner = $stmt->fetch();

            return $name . '（' . ($owner ? (string) $owner['username'] : '用户#' . $ownerId) . '）';
        }

        return $name . '（公共）';
    } catch (Throwable $error) {
        return $name;
    }
}
