<?php
/**
 * 前台用户自有分组管理接口（v2.2.0 新增）
 *
 * 只操作「当前登录用户自己的分组」（categories.user_id = 当前用户），
 * 公共分组（user_id = 0）由后台管理员维护，前台不可改。
 *
 * POST action=create       新建分组（name 必填，is_private 可选 0/1）
 * POST action=rename       重命名分组（id + name）
 * POST action=set_private  切换私密标记（id + is_private）
 * POST action=delete       删除分组（id；分组下网址自动移到未分类）
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

$user = $users->currentUser();

if (!$user) {
    $functions->jsonResponse(['ok' => false, 'error' => '请先登录'], 401);
}

$userId = (int) $user['id'];
$db = Database::getInstance()->getConnection();
$action = strtolower(trim((string) ($_POST['action'] ?? '')));

// 老库结构（未执行 upgrade_v2.1.0.sql）没有 user_id/is_private 列，直接报错提示
try {
    $db->query('SELECT user_id, is_private FROM categories LIMIT 1');
} catch (Throwable $error) {
    $functions->jsonResponse(['ok' => false, 'error' => '站点尚未升级数据库，无法管理分组'], 500);
}

/** 分组数量上限，防止滥用 */
const USER_CATEGORY_LIMIT = 100;

function userCategoryName($name)
{
    $name = trim((string) $name);
    $name = str_replace(["\r", "\n", "\t"], ' ', $name);

    if (function_exists('mb_substr')) {
        $name = mb_substr($name, 0, 30, 'UTF-8');
    } else {
        $name = substr($name, 0, 90);
    }

    return $name;
}

/**
 * 取当前用户自己的一个分组；不存在返回 null。
 */
function userCategoryFindOwn($db, $userId, $id)
{
    $stmt = $db->prepare('SELECT id, name, is_private FROM categories WHERE id = ? AND user_id = ? LIMIT 1');
    $stmt->execute([$id, $userId]);
    $row = $stmt->fetch();

    return is_array($row) ? $row : null;
}

switch ($action) {
    case 'create': {
        $name = userCategoryName($_POST['name'] ?? '');
        $isPrivate = !empty($_POST['is_private']) && (string) $_POST['is_private'] !== '0' ? 1 : 0;

        if ($name === '') {
            $functions->jsonResponse(['ok' => false, 'error' => '请输入分组名称']);
        }

        $count = (int) $db->query('SELECT COUNT(*) AS c FROM categories WHERE user_id = ' . $userId)->fetch()['c'];

        if ($count >= USER_CATEGORY_LIMIT) {
            $functions->jsonResponse(['ok' => false, 'error' => '分组数量已达上限']);
        }

        // 同一用户下重名分组直接复用，避免重复
        $stmt = $db->prepare('SELECT id, is_private FROM categories WHERE user_id = ? AND name = ? LIMIT 1');
        $stmt->execute([$userId, $name]);
        $exist = $stmt->fetch();

        if ($exist) {
            $functions->jsonResponse(['ok' => true, 'id' => (int) $exist['id'], 'name' => $name, 'is_private' => (int) $exist['is_private'], 'reused' => true]);
        }

        $max = (int) $db->query('SELECT COALESCE(MAX(sort_order), 0) AS m FROM categories WHERE user_id = ' . $userId)->fetch()['m'];
        $stmt = $db->prepare('INSERT INTO categories (parent_id, name, icon, sort_order, remark, user_id, is_private) VALUES (NULL, ?, ?, ?, NULL, ?, ?)');
        $stmt->execute([$name, 'bi-folder', $max + 1, $userId, $isPrivate]);

        $functions->jsonResponse(['ok' => true, 'id' => (int) $db->lastInsertId(), 'name' => $name, 'is_private' => $isPrivate]);
    }

    case 'rename': {
        $id = (int) ($_POST['id'] ?? 0);
        $name = userCategoryName($_POST['name'] ?? '');

        if ($id <= 0 || $name === '') {
            $functions->jsonResponse(['ok' => false, 'error' => '参数不完整']);
        }

        if (!userCategoryFindOwn($db, $userId, $id)) {
            $functions->jsonResponse(['ok' => false, 'error' => '分组不存在或无权限'], 404);
        }

        $stmt = $db->prepare('UPDATE categories SET name = ? WHERE id = ? AND user_id = ?');
        $stmt->execute([$name, $id, $userId]);

        $functions->jsonResponse(['ok' => true]);
    }

    case 'set_private': {
        $id = (int) ($_POST['id'] ?? 0);
        $isPrivate = !empty($_POST['is_private']) && (string) $_POST['is_private'] !== '0' ? 1 : 0;

        if ($id <= 0) {
            $functions->jsonResponse(['ok' => false, 'error' => '参数不完整']);
        }

        if (!userCategoryFindOwn($db, $userId, $id)) {
            $functions->jsonResponse(['ok' => false, 'error' => '分组不存在或无权限'], 404);
        }

        $stmt = $db->prepare('UPDATE categories SET is_private = ? WHERE id = ? AND user_id = ?');
        $stmt->execute([$isPrivate, $id, $userId]);

        $functions->jsonResponse(['ok' => true]);
    }

    case 'delete': {
        $id = (int) ($_POST['id'] ?? 0);

        if ($id <= 0) {
            $functions->jsonResponse(['ok' => false, 'error' => '参数不完整']);
        }

        if (!userCategoryFindOwn($db, $userId, $id)) {
            $functions->jsonResponse(['ok' => false, 'error' => '分组不存在或无权限'], 404);
        }

        // 分组下的网址移到未分类（category_id = 0），不删除网址本身
        $stmt = $db->prepare('UPDATE nav_links SET category_id = 0 WHERE category_id = ? AND user_id = ?');
        $stmt->execute([$id, $userId]);

        $stmt = $db->prepare('DELETE FROM categories WHERE id = ? AND user_id = ?');
        $stmt->execute([$id, $userId]);

        $functions->jsonResponse(['ok' => true]);
    }

    default:
        $functions->jsonResponse(['ok' => false, 'error' => 'unknown_action'], 400);
}
