<?php
/**
 * 浏览器书签导入接口
 *
 * POST（multipart/form-data）：file = 浏览器导出的 .html 书签文件；需登录。
 * 解析 NETSCAPE-Bookmark 格式，按文件夹名归入分类，写入当前用户的私有导航。
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
$bookmarks = new BookmarkService($functions);

if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
    $functions->jsonResponse(['ok' => false, 'error' => 'method_not_allowed'], 405);
}

$functions->requireSameOrigin();

$user = $users->currentUser();

if (!$user) {
    $functions->jsonResponse(['ok' => false, 'error' => '请先登录'], 401);
}

$file = $_FILES['file'] ?? null;

if (!is_array($file) || !isset($file['error'], $file['tmp_name'])) {
    $functions->jsonResponse(['ok' => false, 'error' => '请选择书签文件'], 400);
}

if ((int) $file['error'] !== UPLOAD_ERR_OK) {
    $messages = [
        UPLOAD_ERR_INI_SIZE => '文件超过服务器上传限制',
        UPLOAD_ERR_FORM_SIZE => '文件超过表单限制',
        UPLOAD_ERR_PARTIAL => '文件上传中断',
        UPLOAD_ERR_NO_FILE => '请选择书签文件',
    ];

    $functions->jsonResponse(['ok' => false, 'error' => $messages[(int) $file['error']] ?? '文件上传失败'], 400);
}

if (!is_uploaded_file((string) $file['tmp_name'])) {
    $functions->jsonResponse(['ok' => false, 'error' => '非法上传'], 400);
}

// 书签文件通常几十 KB，超过 5MB 基本不是书签导出文件。
if ((int) ($file['size'] ?? 0) > 5 * 1024 * 1024) {
    $functions->jsonResponse(['ok' => false, 'error' => '文件过大，请确认是浏览器导出的 HTML 书签'], 400);
}

$name = strtolower((string) ($file['name'] ?? ''));
$extension = pathinfo($name, PATHINFO_EXTENSION);

if (!in_array($extension, ['html', 'htm', ''], true)) {
    $functions->jsonResponse(['ok' => false, 'error' => '请上传浏览器导出的 .html 书签文件'], 400);
}

$html = @file_get_contents((string) $file['tmp_name']);

if (!is_string($html) || $html === '') {
    $functions->jsonResponse(['ok' => false, 'error' => '文件内容为空'], 400);
}

if (stripos($html, '<a') === false && stripos($html, 'NETSCAPE') === false) {
    $functions->jsonResponse(['ok' => false, 'error' => '文件格式不正确，请选择浏览器导出的书签 HTML'], 400);
}

$parsed = $bookmarks->parse($html);

if (!$parsed['items']) {
    $functions->jsonResponse(['ok' => false, 'error' => '未在文件中找到任何书签'], 400);
}

// 单次导入上限，避免超大书签文件拖垮数据库。
$items = array_slice($parsed['items'], 0, 2000);

$result = $bookmarks->import((int) $user['id'], $items);

if (!$result['ok']) {
    $functions->jsonResponse(['ok' => false, 'error' => '导入失败，请稍后重试'], 400);
}

$functions->logMessage(
    sprintf(
        '[bookmark_import] 用户 %d 导入 %d 条（跳过 %d 条，新建分类 %d 个）',
        (int) $user['id'],
        (int) $result['imported'],
        (int) $result['skipped'],
        (int) $result['categories']
    )
);

$functions->jsonResponse([
    'ok' => true,
    'imported' => (int) $result['imported'],
    'skipped' => (int) $result['skipped'],
    'categories' => (int) $result['categories'],
    'total' => count($parsed['items']),
    'truncated' => count($parsed['items']) > count($items),
]);
