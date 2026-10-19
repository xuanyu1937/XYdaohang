<?php
/**
 * 站点信息抓取 + 分类推荐（后台「一键添加」与「AI 分类」使用）
 *
 * POST url         → 抓取标题 / 描述 / 图标候选
 * POST url + name  → 额外给出分类推荐（AI 已配置时优先用 AI，否则用关键词规则）
 *
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

if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
    $functions->jsonResponse(['ok' => false, 'error' => 'method_not_allowed'], 405);
}

$functions->requireSameOrigin();

$siteInfo = new SiteInfoService($functions);
$ai = new AiService($functions);
$engine = new CategoryEngine($functions);

$url = trim((string) ($_POST['url'] ?? ''));
$name = trim((string) ($_POST['name'] ?? ''));
$description = trim((string) ($_POST['description'] ?? ''));

if ($url === '') {
    $functions->jsonResponse(['ok' => false, 'error' => '请填写网址'], 400);
}

// 抓取结果与 site_meta.php 共用同一份缓存，避免重复请求目标站点。
$meta = $siteInfo->fetch($url);

if (!$meta['ok'] && $name === '') {
    $functions->jsonResponse([
        'ok' => false,
        'error' => 'meta_failed',
        'message' => '未能获取网站信息，请手动填写名称',
        'meta' => $meta,
    ], 200);
}

$finalName = $name !== '' ? $name : (string) ($meta['title'] ?? '');
$finalDescription = $description !== '' ? $description : (string) ($meta['description'] ?? '');

$categories = $engine->all();
$categoryNames = [];

foreach ($categories as $category) {
    $categoryNames[] = (string) $category['name'];
}

$suggestion = null;

if ($finalName !== '' && $categoryNames) {
    $suggestion = $ai->suggestCategory($finalName, $finalDescription, $categoryNames);
}

$categoryId = null;

if (!empty($suggestion['ok'])) {
    $matched = $engine->findByName((string) $suggestion['category']);

    if ($matched) {
        $categoryId = (int) $matched['id'];
    }
}

$functions->jsonResponse([
    'ok' => true,
    'url' => $url,
    'title' => $finalName,
    'description' => $finalDescription,
    'icons' => $meta['icons'] ?? [],
    'ai_enabled' => $ai->enabled(),
    'suggestion' => $suggestion ? [
        'ok' => !empty($suggestion['ok']),
        'source' => $suggestion['source'] ?? 'none',
        'category' => $suggestion['category'] ?? '',
        'category_id' => $categoryId,
    ] : null,
]);
