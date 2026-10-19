<?php
/**
 * 开放 API · 站点管理（REST）
 *
 * 鉴权：HTTP 头 Authorization: Bearer <token>（兼容 X-Api-Token，以及在 URL 上带 ?token=）
 *
 *   GET    ?category=&user_id=&q=&page=&size=   列表（需 read 或 write）
 *   GET    ?id=                                 单条详情
 *   POST   创建                                 需 write
 *   PUT    更新（body: id + 字段）               需 write
 *   DELETE ?id= （或 body: id）                  需 write
 *
 * 权限范围：api_tokens.user_id = 0 表示管理级（可操作公共与全部条目）；
 *          > 0 时仅能读写该用户自己的条目。
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
$db = Database::getInstance()->getConnection();

/**
 * 从多种位置取出访问令牌。
 */
function navApiToken()
{
    $candidates = [];

    if (!empty($_SERVER['HTTP_AUTHORIZATION'])) {
        $candidates[] = (string) $_SERVER['HTTP_AUTHORIZATION'];
    }

    if (!empty($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
        $candidates[] = (string) $_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
    }

    if (!empty($_SERVER['HTTP_X_API_TOKEN'])) {
        $candidates[] = 'Bearer ' . (string) $_SERVER['HTTP_X_API_TOKEN'];
    }

    if (function_exists('getallheaders')) {
        $headers = getallheaders();

        if (is_array($headers)) {
            foreach ($headers as $name => $value) {
                $lower = strtolower((string) $name);

                if ($lower === 'authorization') {
                    $candidates[] = (string) $value;
                } elseif ($lower === 'x-api-token') {
                    $candidates[] = 'Bearer ' . (string) $value;
                }
            }
        }
    }

    if (!empty($_GET['token'])) {
        $candidates[] = 'Bearer ' . (string) $_GET['token'];
    }

    foreach ($candidates as $candidate) {
        if (preg_match('/^Bearer\s+(.+)$/i', trim($candidate), $match)) {
            return trim($match[1]);
        }
    }

    return '';
}

/**
 * 读取请求体（支持 JSON 与表单编码；PUT/DELETE 只能走这里）。
 */
function navApiInput()
{
    $ctype = strtolower((string) ($_SERVER['CONTENT_TYPE'] ?? ''));

    if (strpos($ctype, 'application/json') !== false) {
        $raw = file_get_contents('php://input');
        $decoded = is_string($raw) ? json_decode($raw, true) : null;

        if (is_array($decoded)) {
            return $decoded;
        }

        return [];
    }

    if ($_POST) {
        return $_POST;
    }

    $raw = file_get_contents('php://input');

    if (is_string($raw) && $raw !== '') {
        $parsed = [];
        parse_str($raw, $parsed);

        return $parsed;
    }

    return [];
}

function navApiFail($functions, $message, $status = 400, $code = 'bad_request')
{
    $functions->jsonResponse(['ok' => false, 'error' => $code, 'message' => $message], $status);
}

function navApiNormalizeUrl($url)
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

$token = navApiToken();

if ($token === '') {
    navApiFail($functions, '缺少访问令牌，请在请求头中提供 Authorization: Bearer <token>', 401, 'unauthorized');
}

if (!preg_match('/^[A-Za-z0-9]{16,64}$/', $token)) {
    navApiFail($functions, '令牌格式不正确', 401, 'unauthorized');
}

$stmt = $db->prepare('SELECT id, name, permissions, user_id, expires_at FROM api_tokens WHERE token = ? LIMIT 1');
$stmt->execute([$token]);
$tokenRow = $stmt->fetch();

if (!$tokenRow) {
    navApiFail($functions, '令牌无效', 401, 'unauthorized');
}

if (!empty($tokenRow['expires_at']) && strtotime((string) $tokenRow['expires_at']) < time()) {
    navApiFail($functions, '令牌已过期', 401, 'token_expired');
}

$tokenUserId = (int) $tokenRow['user_id'];
$isAdminScope = $tokenUserId === 0;
$canWrite = strtolower((string) $tokenRow['permissions']) === 'write';

// 记录最近使用时间（失败不影响主流程）
try {
    $db->prepare('UPDATE api_tokens SET last_used_at = NOW() WHERE id = ?')->execute([(int) $tokenRow['id']]);
} catch (Throwable $error) {
    // 忽略
}

$method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
$input = in_array($method, ['POST', 'PUT', 'PATCH', 'DELETE'], true) ? navApiInput() : [];

if (in_array($method, ['POST', 'PUT', 'PATCH', 'DELETE'], true) && !$canWrite) {
    navApiFail($functions, '当前令牌只有只读权限', 403, 'forbidden');
}

/**
 * 校验目标条目是否在令牌权限范围内。
 */
function navApiOwned($db, $id, $tokenUserId, $isAdminScope)
{
    $stmt = $db->prepare('SELECT id, user_id FROM nav_links WHERE id = ? AND deleted_at IS NULL LIMIT 1');
    $stmt->execute([(int) $id]);
    $row = $stmt->fetch();

    if (!$row) {
        return null;
    }

    if ($isAdminScope) {
        return $row;
    }

    return (int) $row['user_id'] === (int) $tokenUserId ? $row : false;
}

switch ($method) {
    case 'GET':
        $id = (int) ($_GET['id'] ?? 0);

        if ($id > 0) {
            $row = navApiOwned($db, $id, $tokenUserId, $isAdminScope);

            if ($row === null) {
                navApiFail($functions, '记录不存在', 404, 'not_found');
            }

            if ($row === false) {
                navApiFail($functions, '无权访问该记录', 403, 'forbidden');
            }

            $stmt = $db->prepare(
                'SELECT nl.id, nl.name, nl.url, nl.logo, nl.description, nl.category_id, nl.user_id,
                        nl.clicks, nl.is_active, nl.check_status, nl.latency_ms, nl.sort_order, nl.created_at,
                        c.name AS category_name
                 FROM nav_links nl
                 LEFT JOIN categories c ON nl.category_id = c.id
                 WHERE nl.id = ? LIMIT 1'
            );
            $stmt->execute([$id]);
            $detail = $stmt->fetch();

            if (!$detail) {
                navApiFail($functions, '记录不存在', 404, 'not_found');
            }

            $functions->jsonResponse([
                'ok' => true,
                'item' => [
                    'id' => (int) $detail['id'],
                    'name' => (string) $detail['name'],
                    'url' => (string) $detail['url'],
                    'logo' => (string) ($detail['logo'] ?? ''),
                    'description' => (string) ($detail['description'] ?? ''),
                    'category_id' => (int) $detail['category_id'],
                    'category_name' => (string) ($detail['category_name'] ?? ''),
                    'user_id' => (int) $detail['user_id'],
                    'clicks' => (int) $detail['clicks'],
                    'is_active' => (int) $detail['is_active'] === 1,
                    'check_status' => (string) $detail['check_status'],
                    'latency_ms' => $detail['latency_ms'] === null ? null : (int) $detail['latency_ms'],
                    'created_at' => (string) $detail['created_at'],
                ],
            ]);
        }

        $page = max(1, (int) ($_GET['page'] ?? 1));
        $size = min(100, max(1, (int) ($_GET['size'] ?? 20)));
        $offset = ($page - 1) * $size;
        $category = (int) ($_GET['category'] ?? 0);
        $keyword = trim((string) ($_GET['q'] ?? ''));

        $conditions = ['nl.deleted_at IS NULL'];
        $params = [];

        // 令牌范围：管理级可指定 user_id（-1 表示不过滤），用户级固定为自己的数据
        if ($isAdminScope) {
            $requested = isset($_GET['user_id']) ? (int) $_GET['user_id'] : -1;

            if ($requested >= 0) {
                $conditions[] = 'nl.user_id = ?';
                $params[] = $requested;
            }
        } else {
            $conditions[] = 'nl.user_id = ?';
            $params[] = $tokenUserId;
        }

        if ($category > 0) {
            $conditions[] = 'nl.category_id = ?';
            $params[] = $category;
        }

        if ($keyword !== '') {
            $conditions[] = '(nl.name LIKE ? OR nl.url LIKE ? OR nl.description LIKE ?)';
            $params[] = '%' . $keyword . '%';
            $params[] = '%' . $keyword . '%';
            $params[] = '%' . $keyword . '%';
        }

        $where = ' WHERE ' . implode(' AND ', $conditions);

        $countStmt = $db->prepare('SELECT COUNT(*) AS total FROM nav_links nl' . $where);
        $countStmt->execute($params);
        $total = (int) ($countStmt->fetch()['total'] ?? 0);

        $stmt = $db->prepare(
            'SELECT nl.id, nl.name, nl.url, nl.logo, nl.description, nl.category_id, nl.user_id,
                    nl.clicks, nl.is_active, nl.check_status, nl.latency_ms, nl.sort_order, nl.created_at,
                    c.name AS category_name
             FROM nav_links nl
             LEFT JOIN categories c ON nl.category_id = c.id' . $where . '
             ORDER BY nl.sort_order ASC, nl.id ASC LIMIT ?, ?'
        );

        foreach ($params as $index => $value) {
            $stmt->bindValue($index + 1, $value, PDO::PARAM_STR);
        }

        $stmt->bindValue(count($params) + 1, $offset, PDO::PARAM_INT);
        $stmt->bindValue(count($params) + 2, $size, PDO::PARAM_INT);
        $stmt->execute();

        $items = [];

        foreach ($stmt->fetchAll() as $row) {
            $items[] = [
                'id' => (int) $row['id'],
                'name' => (string) $row['name'],
                'url' => (string) $row['url'],
                'logo' => (string) ($row['logo'] ?? ''),
                'description' => (string) ($row['description'] ?? ''),
                'category_id' => (int) $row['category_id'],
                'category_name' => (string) ($row['category_name'] ?? ''),
                'user_id' => (int) $row['user_id'],
                'clicks' => (int) $row['clicks'],
                'is_active' => (int) $row['is_active'] === 1,
                'check_status' => (string) $row['check_status'],
                'latency_ms' => $row['latency_ms'] === null ? null : (int) $row['latency_ms'],
                'created_at' => (string) $row['created_at'],
            ];
        }

        $functions->jsonResponse([
            'ok' => true,
            'page' => $page,
            'size' => $size,
            'total' => $total,
            'pages' => (int) ceil($total / $size),
            'items' => $items,
        ]);
        break;

    case 'POST':
        $url = navApiNormalizeUrl($input['url'] ?? '');

        if ($url === '') {
            navApiFail($functions, 'url 不能为空且必须是合法的 http/https 地址');
        }

        $name = trim((string) ($input['name'] ?? ''));

        if ($name === '') {
            navApiFail($functions, 'name 不能为空');
        }

        if (function_exists('mb_substr')) {
            $name = mb_substr($name, 0, 100, 'UTF-8');
        }

        $description = trim((string) ($input['description'] ?? ''));

        if (function_exists('mb_substr')) {
            $description = mb_substr($description, 0, 200, 'UTF-8');
        }

        $categoryId = (int) ($input['category_id'] ?? 0);

        if ($categoryId > 0) {
            $check = $db->prepare('SELECT id FROM categories WHERE id = ? LIMIT 1');
            $check->execute([$categoryId]);

            if (!$check->fetch()) {
                navApiFail($functions, 'category_id 不存在');
            }
        } else {
            $engine = new CategoryEngine($functions);
            $categoryId = $engine->ensure('未分类', 'bi-collection');
        }

        // 管理级令牌默认写入公共导航；用户级令牌写入自己名下
        $owner = $isAdminScope ? (int) ($input['user_id'] ?? 0) : $tokenUserId;

        $logo = trim((string) ($input['logo'] ?? ''));

        if (function_exists('mb_substr')) {
            $logo = mb_substr($logo, 0, 500, 'UTF-8');
        }

        $sortStmt = $db->prepare('SELECT COALESCE(MAX(sort_order), 0) + 1 AS next_sort FROM nav_links WHERE user_id = ?');
        $sortStmt->execute([$owner]);
        $sort = (int) ($sortStmt->fetch()['next_sort'] ?? 1);

        $isActive = array_key_exists('is_active', $input)
            ? (in_array($input['is_active'], ['1', 1, true, 'true'], true) ? 1 : 0)
            : 1;

        $stmt = $db->prepare(
            'INSERT INTO nav_links (name, url, logo, description, category_id, user_id, sort_order, is_active, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())'
        );
        $stmt->execute([$name, $url, $logo, $description, $categoryId, $owner, $sort, $isActive]);

        $functions->jsonResponse([
            'ok' => true,
            'message' => '创建成功',
            'id' => (int) $db->lastInsertId(),
        ], 201);
        break;

    case 'PUT':
    case 'PATCH':
        $id = (int) ($input['id'] ?? ($_GET['id'] ?? 0));

        if ($id <= 0) {
            navApiFail($functions, '缺少 id');
        }

        $owned = navApiOwned($db, $id, $tokenUserId, $isAdminScope);

        if ($owned === null) {
            navApiFail($functions, '记录不存在', 404, 'not_found');
        }

        if ($owned === false) {
            navApiFail($functions, '无权修改该记录', 403, 'forbidden');
        }

        $fields = [];
        $values = [];

        if (array_key_exists('name', $input)) {
            $name = trim((string) $input['name']);

            if ($name === '') {
                navApiFail($functions, 'name 不能为空');
            }

            $fields[] = 'name = ?';
            $values[] = function_exists('mb_substr') ? mb_substr($name, 0, 100, 'UTF-8') : $name;
        }

        if (array_key_exists('url', $input)) {
            $url = navApiNormalizeUrl($input['url']);

            if ($url === '') {
                navApiFail($functions, 'url 不合法');
            }

            $fields[] = 'url = ?';
            $values[] = $url;
        }

        if (array_key_exists('description', $input)) {
            $description = trim((string) $input['description']);
            $fields[] = 'description = ?';
            $values[] = function_exists('mb_substr') ? mb_substr($description, 0, 200, 'UTF-8') : $description;
        }

        if (array_key_exists('logo', $input)) {
            $logo = trim((string) $input['logo']);
            $fields[] = 'logo = ?';
            $values[] = function_exists('mb_substr') ? mb_substr($logo, 0, 500, 'UTF-8') : $logo;
        }

        if (array_key_exists('category_id', $input)) {
            $categoryId = (int) $input['category_id'];

            if ($categoryId > 0) {
                $check = $db->prepare('SELECT id FROM categories WHERE id = ? LIMIT 1');
                $check->execute([$categoryId]);

                if (!$check->fetch()) {
                    navApiFail($functions, 'category_id 不存在');
                }
            } else {
                $engine = new CategoryEngine($functions);
                $categoryId = $engine->ensure('未分类', 'bi-collection');
            }

            $fields[] = 'category_id = ?';
            $values[] = $categoryId;
        }

        if (array_key_exists('is_active', $input)) {
            $fields[] = 'is_active = ?';
            $values[] = in_array($input['is_active'], ['1', 1, true, 'true'], true) ? 1 : 0;
        }

        if (array_key_exists('sort_order', $input)) {
            $fields[] = 'sort_order = ?';
            $values[] = (int) $input['sort_order'];
        }

        if (!$fields) {
            navApiFail($functions, '没有需要更新的字段');
        }

        $values[] = $id;

        $stmt = $db->prepare('UPDATE nav_links SET ' . implode(', ', $fields) . ', updated_at = NOW() WHERE id = ?');
        $stmt->execute($values);

        $functions->jsonResponse(['ok' => true, 'message' => '更新成功', 'id' => $id]);
        break;

    case 'DELETE':
        $id = (int) ($input['id'] ?? ($_GET['id'] ?? 0));

        if ($id <= 0) {
            navApiFail($functions, '缺少 id');
        }

        $owned = navApiOwned($db, $id, $tokenUserId, $isAdminScope);

        if ($owned === null) {
            navApiFail($functions, '记录不存在', 404, 'not_found');
        }

        if ($owned === false) {
            navApiFail($functions, '无权删除该记录', 403, 'forbidden');
        }

        // 与后台一致：移入回收站（deleted_at），可在后台恢复
        try {
            $stmt = $db->prepare('UPDATE nav_links SET deleted_at = NOW(), is_active = 0 WHERE id = ?');
            $stmt->execute([$id]);

            if ($stmt->rowCount() === 0) {
                throw new RuntimeException('soft delete failed');
            }

            $functions->jsonResponse(['ok' => true, 'message' => '已移入回收站', 'id' => $id]);
        } catch (Throwable $error) {
            // 老库没有 deleted_at 列时退化为直接删除
            $db->prepare('DELETE FROM nav_links WHERE id = ?')->execute([$id]);

            $functions->jsonResponse(['ok' => true, 'message' => '删除成功', 'id' => $id]);
        }
        break;

    default:
        navApiFail($functions, '不支持的请求方法', 405, 'method_not_allowed');
}
