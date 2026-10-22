<?php
/**
 * 用户自定义搜索引擎接口
 *
 * GET                       获取搜索引擎列表（用户自定义 + 全局默认合并）
 * POST action=add           新增
 * POST action=update        修改
 * POST action=delete        删除
 * POST action=sort          按 ids 顺序重排
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

$method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
$db = Database::getInstance()->getConnection();

/**
 * 获取当前用户（允许未登录，未登录时仅返回全局默认）
 */
$user = $users->currentUser();
$userId = $user ? (int) $user['id'] : 0;

/**
 * 规范化 URL 模板：必须包含 {q} 或 {query} 占位符
 */
function normalizeUrlTemplate($url)
{
    $url = trim((string) $url);

    if ($url === '' || strlen($url) > 500) {
        return '';
    }

    // 必须包含 {q} 或 {query} 占位符
    if (strpos($url, '{q}') === false && strpos($url, '{query}') === false) {
        return '';
    }

    // 统一替换 {query} 为 {q}
    $url = str_replace('{query}', '{q}', $url);

    if (!preg_match('#^https?://#i', $url)) {
        return '';
    }

    return $url;
}

function trimField($value, $limit)
{
    $value = trim((string) $value);
    $value = str_replace(["\r", "\n", "\t"], ' ', $value);

    if (function_exists('mb_substr')) {
        return mb_substr($value, 0, $limit, 'UTF-8');
    }

    return substr($value, 0, $limit);
}

/**
 * 获取合并后的搜索引擎列表：
 * - 全局默认搜索引擎始终显示（除非用户隐藏）
 * - 用户编辑过的全局默认 → 用用户版本覆盖
 * - 用户新增的搜索引擎追加在后面
 * - 站内搜索始终在最前
 */
function getMergedEngines($db, $userId)
{
    // 站内搜索（固定第一项）
    $engines = [
        [
            'id' => 'site',
            'type' => 'site',
            'name' => '站内',
            'url_template' => '',
            'icon' => '🏠',
            'sort_order' => 0,
        ]
    ];

    // 获取全局默认搜索引擎
    $stmt = $db->query(
        'SELECT id, name, url_template, icon, sort_order
         FROM search_engines
         WHERE is_active = 1
         ORDER BY sort_order ASC, id ASC'
    );
    $globalEngines = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // 获取用户自定义搜索引擎
    $userEngines = [];
    if ($userId > 0) {
        $stmt = $db->prepare(
            'SELECT id, name, url_template, icon, sort_order, override_global_id, is_hidden
             FROM user_search_engines
             WHERE user_id = ?
             ORDER BY sort_order ASC, id ASC'
        );
        $stmt->execute([$userId]);
        $userEngines = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // 建立用户覆盖映射：override_global_id => 用户记录
    $overrideMap = [];
    // 用户新增的（override_global_id 为 NULL 且未隐藏）
    $userAdded = [];
    foreach ($userEngines as $e) {
        if (!empty($e['override_global_id'])) {
            $overrideMap[(int) $e['override_global_id']] = $e;
        } elseif (empty($e['is_hidden'])) {
            $userAdded[] = $e;
        }
    }

    // 处理全局默认：被覆盖则用用户版本，被隐藏则跳过
    foreach ($globalEngines as $g) {
        $gid = (int) $g['id'];
        if (isset($overrideMap[$gid])) {
            $ov = $overrideMap[$gid];
            if (!empty($ov['is_hidden'])) {
                // 用户隐藏了这个全局默认
                continue;
            }
            $engines[] = [
                'id' => 'user_' . $ov['id'],
                'ref_id' => $ov['id'],
                'type' => 'user',
                'source' => 'global',
                'global_id' => $gid,
                'name' => $ov['name'],
                'url_template' => $ov['url_template'],
                'icon' => $ov['icon'] ?: '🔍',
                'sort_order' => (int) $ov['sort_order'],
            ];
        } else {
            $engines[] = [
                'id' => 'global_' . $g['id'],
                'ref_id' => $g['id'],
                'type' => 'global',
                'source' => 'global',
                'global_id' => $gid,
                'name' => $g['name'],
                'url_template' => $g['url_template'],
                'icon' => $g['icon'] ?: '🔍',
                'sort_order' => (int) $g['sort_order'],
            ];
        }
    }

    // 追加用户新增的搜索引擎
    foreach ($userAdded as $e) {
        $engines[] = [
            'id' => 'user_' . $e['id'],
            'ref_id' => $e['id'],
            'type' => 'user',
            'source' => 'user',
            'name' => $e['name'],
            'url_template' => $e['url_template'],
            'icon' => $e['icon'] ?: '🔍',
            'sort_order' => (int) $e['sort_order'],
        ];
    }

    return $engines;
}

if ($method === 'GET') {
    $engines = getMergedEngines($db, $userId);
    $functions->jsonResponse([
        'ok' => true,
        'engines' => $engines,
        'has_custom' => $userId > 0 && !empty($userEngines),
    ]);
}

// 以下操作需要登录
$functions->requireSameOrigin();

if (!$user) {
    $functions->jsonResponse(['ok' => false, 'error' => '请先登录'], 401);
}

$action = strtolower(trim((string) ($_POST['action'] ?? '')));

if ($action === 'add') {
    $name = trimField($_POST['name'] ?? '', 50);
    $urlTemplate = normalizeUrlTemplate($_POST['url_template'] ?? '');
    $icon = trimField($_POST['icon'] ?? '', 500);

    if ($name === '') {
        $functions->jsonResponse(['ok' => false, 'error' => '请输入搜索引擎名称'], 400);
    }

    if ($urlTemplate === '') {
        $functions->jsonResponse(['ok' => false, 'error' => '搜索URL模板必须以 http(s):// 开头且包含 {q} 占位符'], 400);
    }

    $stmt = $db->prepare('SELECT COALESCE(MAX(sort_order), 0) FROM user_search_engines WHERE user_id = ?');
    $stmt->execute([$userId]);
    $maxSort = (int) $stmt->fetchColumn();

    $stmt = $db->prepare(
        'INSERT INTO user_search_engines (user_id, name, url_template, icon, sort_order, override_global_id)
         VALUES (?, ?, ?, ?, ?, NULL)'
    );
    $stmt->execute([$userId, $name, $urlTemplate, $icon, $maxSort + 1]);

    $functions->jsonResponse(['ok' => true, 'id' => (int) $db->lastInsertId()]);
}

// 编辑全局默认搜索引擎 → 创建/更新用户覆盖记录（仅对自己生效）
if ($action === 'edit_global') {
    $globalId = (int) ($_POST['global_id'] ?? 0);
    $name = trimField($_POST['name'] ?? '', 50);
    $urlTemplate = normalizeUrlTemplate($_POST['url_template'] ?? '');
    $icon = trimField($_POST['icon'] ?? '', 500);

    if ($globalId <= 0) {
        $functions->jsonResponse(['ok' => false, 'error' => '参数错误'], 400);
    }

    if ($name === '') {
        $functions->jsonResponse(['ok' => false, 'error' => '请输入搜索引擎名称'], 400);
    }

    if ($urlTemplate === '') {
        $functions->jsonResponse(['ok' => false, 'error' => '搜索URL模板必须以 http(s):// 开头且包含 {q} 占位符'], 400);
    }

    // 检查是否已有覆盖记录
    $stmt = $db->prepare(
        'SELECT id FROM user_search_engines WHERE user_id = ? AND override_global_id = ?'
    );
    $stmt->execute([$userId, $globalId]);
    $existing = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($existing) {
        $stmt = $db->prepare(
            'UPDATE user_search_engines
             SET name = ?, url_template = ?, icon = ?, is_hidden = 0
             WHERE id = ? AND user_id = ?'
        );
        $stmt->execute([$name, $urlTemplate, $icon, $existing['id'], $userId]);
    } else {
        $stmt = $db->prepare(
            'INSERT INTO user_search_engines (user_id, name, url_template, icon, sort_order, override_global_id, is_hidden)
             VALUES (?, ?, ?, ?, 0, ?, 0)'
        );
        $stmt->execute([$userId, $name, $urlTemplate, $icon, $globalId]);
    }

    $functions->jsonResponse(['ok' => true]);
}

if ($action === 'update') {
    $id = (int) ($_POST['id'] ?? 0);
    $name = trimField($_POST['name'] ?? '', 50);
    $urlTemplate = normalizeUrlTemplate($_POST['url_template'] ?? '');
    $icon = trimField($_POST['icon'] ?? '', 500);

    if ($id <= 0) {
        $functions->jsonResponse(['ok' => false, 'error' => '参数错误'], 400);
    }

    if ($name === '') {
        $functions->jsonResponse(['ok' => false, 'error' => '请输入搜索引擎名称'], 400);
    }

    if ($urlTemplate === '') {
        $functions->jsonResponse(['ok' => false, 'error' => '搜索URL模板必须以 http(s):// 开头且包含 {q} 占位符'], 400);
    }

    $stmt = $db->prepare(
        'UPDATE user_search_engines
         SET name = ?, url_template = ?, icon = ?
         WHERE id = ? AND user_id = ?'
    );
    $stmt->execute([$name, $urlTemplate, $icon, $id, $userId]);

    if ($stmt->rowCount() === 0) {
        $functions->jsonResponse(['ok' => false, 'error' => '搜索引擎不存在或无权操作'], 404);
    }

    $functions->jsonResponse(['ok' => true]);
}

if ($action === 'delete') {
    $id = (int) ($_POST['id'] ?? 0);
    $globalId = (int) ($_POST['global_id'] ?? 0);

    if ($id > 0) {
        // 删除用户自定义记录（包括覆盖记录 → 恢复全局默认）
        $stmt = $db->prepare('DELETE FROM user_search_engines WHERE id = ? AND user_id = ?');
        $stmt->execute([$id, $userId]);

        if ($stmt->rowCount() === 0) {
            $functions->jsonResponse(['ok' => false, 'error' => '搜索引擎不存在或无权操作'], 404);
        }
    } elseif ($globalId > 0) {
        // 隐藏全局默认搜索引擎（仅对自己生效）
        // 检查是否已有覆盖记录
        $stmt = $db->prepare(
            'SELECT id FROM user_search_engines WHERE user_id = ? AND override_global_id = ?'
        );
        $stmt->execute([$userId, $globalId]);
        $existing = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($existing) {
            // 已有覆盖记录，标记为隐藏
            $stmt = $db->prepare(
                'UPDATE user_search_engines SET is_hidden = 1 WHERE id = ? AND user_id = ?'
            );
            $stmt->execute([$existing['id'], $userId]);
        } else {
            // 创建隐藏记录
            $stmt = $db->prepare(
                'INSERT INTO user_search_engines (user_id, name, url_template, icon, sort_order, override_global_id, is_hidden)
                 SELECT ?, name, url_template, icon, sort_order, ?, 1
                 FROM search_engines WHERE id = ?'
            );
            $stmt->execute([$userId, $globalId, $globalId]);
        }
    } else {
        $functions->jsonResponse(['ok' => false, 'error' => '参数错误'], 400);
    }

    $functions->jsonResponse(['ok' => true]);
}

if ($action === 'sort') {
    $ids = $_POST['ids'] ?? '';

    if (is_string($ids)) {
        $ids = array_filter(explode(',', $ids), 'ctype_digit');
    } elseif (is_array($ids)) {
        $ids = array_filter($ids, 'ctype_digit');
    } else {
        $ids = [];
    }

    if (empty($ids)) {
        $functions->jsonResponse(['ok' => false, 'error' => '请提供排序ID列表'], 400);
    }

    $db->beginTransaction();

    try {
        $order = 1;
        $stmt = $db->prepare(
            'UPDATE user_search_engines SET sort_order = ? WHERE id = ? AND user_id = ?'
        );

        foreach ($ids as $id) {
            $stmt->execute([$order, (int) $id, $userId]);
            $order++;
        }

        $db->commit();
        $functions->jsonResponse(['ok' => true]);
    } catch (Exception $e) {
        $db->rollBack();
        $functions->jsonResponse(['ok' => false, 'error' => '排序失败'], 500);
    }
}

$functions->jsonResponse(['ok' => false, 'error' => '未知操作'], 400);
