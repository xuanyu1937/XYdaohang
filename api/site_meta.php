<?php
/**
 * 网站信息抓取接口（前台卡片异步校正标题、后台预填使用）
 *
 * v2.0.0 起改为委托给 SiteInfoService，与后台的「一键添加」共用同一套
 * SSRF 防护、字符编码处理与 cache/site_meta 缓存，避免两份实现各自演化。
 *
 * 返回：
 *   { ok, title, description, host, icons[] }
 *   ok=false 时附带 error，由前端回退到后台录入的名称。
 */

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/Services.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, max-age=0');
header('X-Content-Type-Options: nosniff');
header('X-Robots-Tag: noindex, nofollow');
@ini_set('display_errors', '0');

function navMetaResponse($data, $status = 200)
{
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

$url = isset($_GET['url']) && is_string($_GET['url']) ? trim($_GET['url']) : '';

if ($url === '' || strlen($url) > 2048) {
    navMetaResponse(['ok' => false, 'error' => 'invalid_url'], 400);
}

$functions = new Functions();
$siteInfo = new SiteInfoService($functions);

$result = $siteInfo->fetch($url);

// 与旧版保持一致的 HTTP 状态语义：地址非法/被拦截返回 4xx，抓取失败仍返回 200
$statusMap = [
    'invalid_url' => 400,
    'invalid_scheme' => 400,
    'invalid_host' => 400,
    'blocked_host' => 403,
    'blocked_ip' => 403,
];

$status = 200;

if (empty($result['ok']) && isset($result['error']) && isset($statusMap[$result['error']])) {
    $status = $statusMap[$result['error']];
}

navMetaResponse($result, $status);
