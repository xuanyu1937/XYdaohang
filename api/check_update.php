<?php
/**
 * 版本检测接口（后台「系统工具 → 版本检测」使用）
 *
 * 为什么不在浏览器里直接请求 GitHub API：
 *   api.github.com 在部分网络环境（尤其国内）不可达，浏览器直连必然报
 *   「检测失败，请检查网络连接后重试」。改为服务器端 cURL 抓取，并按顺序尝试
 *   多个可用镜像，结果缓存 10 分钟，避免触发 GitHub 匿名限流。
 *
 * 安全：仅后台登录用户可调用；目标地址固定为本仓库的 releases/latest，
 *       不接受外部传入 URL，不构成开放代理。
 *
 * 返回：
 *   { ok:true, tag_name, name, body, html_url, published_at, source }
 *   { ok:false, error }
 */

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/functions.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, max-age=0');
header('X-Content-Type-Options: nosniff');
header('X-Robots-Tag: noindex, nofollow');
@ini_set('display_errors', '0');

function navUpdateResponse($data, $status = 200)
{
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

$functions = new Functions();

if (!$functions->isLoggedIn()) {
    navUpdateResponse(['ok' => false, 'error' => 'unauthorized'], 401);
}

$repo = 'xuanyu1937/XYdaohang';
$apiPath = 'https://api.github.com/repos/' . $repo . '/releases/latest';

// 依次尝试的抓取地址：官方 API 优先，失败时回退到公共镜像
$endpoints = [
    $apiPath,
    'https://ghfast.top/' . $apiPath,
    'https://gh-proxy.com/' . $apiPath,
    'https://ghproxy.net/' . $apiPath,
];

$cacheDir = dirname(__DIR__) . '/cache';
$cacheFile = $cacheDir . '/update_check.json';
$cacheTtl = 600; // 10 分钟

// 命中缓存直接返回（缓存里附带缓存时间，前端不需要感知）
if (is_file($cacheFile) && (time() - filemtime($cacheFile)) < $cacheTtl) {
    $cached = json_decode((string) file_get_contents($cacheFile), true);

    if (is_array($cached) && !empty($cached['ok'])) {
        navUpdateResponse($cached);
    }
}

/**
 * 用 cURL 抓取一个地址，要求 2xx 且返回 JSON 对象。
 */
function navUpdateFetch($endpoint)
{
    if (!function_exists('curl_init')) {
        return null;
    }

    $ch = curl_init($endpoint);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 3,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_USERAGENT => 'XYdaohang-Update-Check',
        CURLOPT_HTTPHEADER => ['Accept: application/vnd.github+json'],
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
    ]);
    $body = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);

    if (!is_string($body) || $body === '' || $status < 200 || $status >= 300) {
        return null;
    }

    $data = json_decode($body, true);

    return is_array($data) ? $data : null;
}

$release = null;
$source = '';

foreach ($endpoints as $endpoint) {
    $data = navUpdateFetch($endpoint);

    if (is_array($data) && !empty($data['tag_name'])) {
        $release = $data;
        $source = $endpoint === $apiPath ? 'github' : 'mirror';
        break;
    }
}

if (!$release) {
    navUpdateResponse(['ok' => false, 'error' => 'fetch_failed']);
}

$payload = [
    'ok' => true,
    'tag_name' => (string) $release['tag_name'],
    'name' => (string) ($release['name'] ?? ''),
    'body' => (string) ($release['body'] ?? ''),
    'html_url' => (string) ($release['html_url'] ?? ''),
    'published_at' => (string) ($release['published_at'] ?? ''),
    'source' => $source,
];

// 写缓存（失败不影响返回）
if (is_dir($cacheDir) || @mkdir($cacheDir, 0755, true)) {
    @file_put_contents($cacheFile, json_encode($payload, JSON_UNESCAPED_UNICODE), LOCK_EX);
}

navUpdateResponse($payload);
