<?php
/**
 * 分享链接接口
 *
 * GET  ?token=xxx          读取分享内容（浏览数 +1）
 * POST action=create       创建分享（需登录），body: site_ids[] | site_ids(JSON), title
 * POST action=import       把分享内容导入自己的导航（需登录），body: token
 * POST action=delete       删除自己的分享，body: id
 * GET  ?action=mine        我创建的分享列表（需登录）
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
$shares = new ShareService($functions);

$method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));

if ($method === 'GET') {
    $token = trim((string) ($_GET['token'] ?? ''));

    if ($token !== '') {
        $share = $shares->findByToken($token);

        if (!$share) {
            $functions->jsonResponse(['ok' => false, 'error' => '分享不存在或已失效'], 404);
        }

        $shares->incrementViews((int) $share['id']);
        $sites = $shares->sitesOf($share);

        $functions->jsonResponse([
            'ok' => true,
            'title' => (string) $share['title'],
            'views' => (int) $share['views'] + 1,
            'created_at' => (string) $share['created_at'],
            'total' => count($sites),
            'sites' => array_map(function ($site) use ($functions) {
                return [
                    'id' => (int) $site['id'],
                    'name' => (string) $site['name'],
                    'url' => (string) $site['url'],
                    'logo' => $functions->getLogoDisplayUrl((string) ($site['logo'] ?? '')),
                    'description' => (string) ($site['description'] ?? ''),
                    'category' => (string) ($site['category_name'] ?? ''),
                ];
            }, $sites),
        ]);
    }

    if (strtolower(trim((string) ($_GET['action'] ?? ''))) === 'mine') {
        $user = $users->currentUser();

        if (!$user) {
            $functions->jsonResponse(['ok' => false, 'error' => '请先登录'], 401);
        }

        $list = [];

        foreach ($shares->listByUser((int) $user['id']) as $row) {
            $ids = json_decode((string) $row['site_ids'], true);

            $list[] = [
                'id' => (int) $row['id'],
                'token' => (string) $row['token'],
                'title' => (string) $row['title'],
                'url' => 'share.php?token=' . $row['token'],
                'count' => is_array($ids) ? count($ids) : 0,
                'views' => (int) $row['views'],
                'created_at' => (string) $row['created_at'],
            ];
        }

        $functions->jsonResponse(['ok' => true, 'items' => $list]);
    }

    $functions->jsonResponse(['ok' => false, 'error' => 'invalid_request'], 400);
}

if ($method !== 'POST') {
    $functions->jsonResponse(['ok' => false, 'error' => 'method_not_allowed'], 405);
}

$functions->requireSameOrigin();

$user = $users->currentUser();

if (!$user) {
    $functions->jsonResponse(['ok' => false, 'error' => '请先登录'], 401);
}

$userId = (int) $user['id'];
$action = strtolower(trim((string) ($_POST['action'] ?? '')));

switch ($action) {
    case 'create':
        $siteIds = $_POST['site_ids'] ?? [];

        if (is_string($siteIds)) {
            $decoded = json_decode($siteIds, true);
            $siteIds = is_array($decoded) ? $decoded : explode(',', $siteIds);
        }

        if (!is_array($siteIds)) {
            $functions->jsonResponse(['ok' => false, 'error' => '请选择要分享的网站'], 400);
        }

        $result = $shares->create($userId, $siteIds, (string) ($_POST['title'] ?? ''));

        if (!$result['ok']) {
            $messages = [
                'login_required' => '请先登录',
                'empty_selection' => '请至少选择一个网站',
                'too_many' => '一次最多分享 500 个网站',
                'no_permission' => '所选网站不在你的导航中',
            ];

            $functions->jsonResponse([
                'ok' => false,
                'error' => $messages[$result['error']] ?? '创建分享失败',
            ], 400);
        }

        $functions->jsonResponse([
            'ok' => true,
            'token' => $result['token'],
            'url' => $result['url'],
            'count' => $result['count'],
        ]);
        break;

    case 'import':
        $result = $shares->import($userId, (string) ($_POST['token'] ?? ''));

        if (!$result['ok']) {
            $functions->jsonResponse([
                'ok' => false,
                'error' => $result['error'] === 'share_not_found' ? '分享不存在或已失效' : '导入失败',
            ], 400);
        }

        $functions->jsonResponse([
            'ok' => true,
            'imported' => $result['imported'],
            'skipped' => $result['skipped'],
        ]);
        break;

    case 'delete':
        $id = (int) ($_POST['id'] ?? 0);

        if ($id <= 0) {
            $functions->jsonResponse(['ok' => false, 'error' => '参数不正确'], 400);
        }

        $functions->jsonResponse(['ok' => true, 'deleted' => (int) $shares->delete($id, $userId)]);
        break;

    default:
        $functions->jsonResponse(['ok' => false, 'error' => 'unsupported_action'], 400);
}
