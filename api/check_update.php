<?php
/**
 * 版本检测接口（后台「系统工具 → 版本检测」使用）
 *
 * 为什么不在浏览器里直接请求 GitHub API：
 *   api.github.com 在部分网络环境（尤其国内）不可达，浏览器直连必然报
 *   「检测失败，请检查网络连接后重试」。改为服务器端抓取，并按顺序尝试
 *   多个可用镜像，结果缓存 10 分钟，避免触发 GitHub 匿名限流。
 *
 * 检测链路（原有 Releases API 逻辑保持不变，新增 raw VERSION 文件链路兜底）：
 *   1. GitHub Releases API（官方 + 3 个国内镜像）：能拿到完整 Release 说明
 *   2. raw VERSION 文件（官方 + 4 个国内镜像）：纯文本版本号，更轻量，
 *      在 Releases API 全部不可达时仍能判定是否有新版本
 *
 * 兼容：cURL 不可用时自动回退 file_get_contents（stream）；
 *       响应头兼容跨域（同源部署时不影响，前后端分离部署时允许跨域携带凭证）。
 *
 * 安全：仅后台登录用户可调用；目标地址固定为本仓库，不接受外部传入 URL，
 *       不构成开放代理。
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
// 跨域兼容：同源部署不受影响；管理后台与 API 分离部署时允许携带凭证访问
header('Access-Control-Allow-Origin: ' . ($_SERVER['HTTP_ORIGIN'] ?? '*'));
header('Access-Control-Allow-Credentials: true');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Accept, Content-Type');
@ini_set('display_errors', '0');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

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
$rawPath = 'https://raw.githubusercontent.com/' . $repo . '/main/VERSION';

// Releases API 端点：官方优先，失败时回退国内镜像（原有逻辑保持不变）
$apiEndpoints = [
    $apiPath,
    'https://ghfast.top/' . $apiPath,
    'https://gh-proxy.com/' . $apiPath,
    'https://ghproxy.net/' . $apiPath,
];

// raw VERSION 端点：官方 + 国内镜像（jsdelivr 走 CDN，国内可用性最好）
$rawEndpoints = [
    $rawPath,
    'https://fastly.jsdelivr.net/gh/' . $repo . '@main/VERSION',
    'https://gcore.jsdelivr.net/gh/' . $repo . '@main/VERSION',
    'https://ghfast.top/' . $rawPath,
    'https://gh-proxy.com/' . $rawPath,
    'https://ghproxy.net/' . $rawPath,
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

/** 失败原因优先级：SSL 证书问题最具体，其次 DNS、超时，最后兜底。 */
$navUpdateErrorPriority = ['ssl_error' => 4, 'dns_error' => 3, 'timeout' => 2, 'fetch_failed' => 1];
$GLOBALS['navUpdateError'] = '';
$GLOBALS['navUpdateErrno'] = 0;
// 整体抓取预算：端点较多且逐个串行，超预算直接停止并返回 timeout，
// 避免接口执行时间超过 PHP max_execution_time 后返回空白页
$GLOBALS['navUpdateDeadline'] = microtime(true) + 25;

/** 记录最近一次抓取失败原因，保留优先级最高（最具体）的那个。 */
function navUpdateMarkError($error, $errno = 0)
{
    global $navUpdateErrorPriority;

    if ($error === '') {
        return;
    }

    $current = (string) ($GLOBALS['navUpdateError'] ?? '');

    if ($current === '' || ($navUpdateErrorPriority[$error] ?? 0) > ($navUpdateErrorPriority[$current] ?? 0)) {
        $GLOBALS['navUpdateError'] = $error;
    }

    $GLOBALS['navUpdateErrno'] = (int) $errno;
}

/** 把 cURL errno 映射为可直接返回给前端的错误码。 */
function navUpdateMapCurlError($errno)
{
    // 35/51/58/60/77/83 均与 SSL 证书校验有关，Windows 下未配置 curl.cainfo 时最常见
    if (in_array($errno, [35, 51, 58, 60, 77, 83], true)) {
        return 'ssl_error';
    }

    if (in_array($errno, [6, 7], true)) {
        return 'dns_error';
    }

    if ($errno === 28) {
        return 'timeout';
    }

    return 'fetch_failed';
}

/**
 * 抓取一个地址，返回 [HTTP 状态码, 响应体]，失败返回 null 并记录失败原因。
 * 优先 cURL，不可用时回退 file_get_contents；全程异常捕获，绝不抛出。
 * $insecure 为 true 时跳过 SSL 证书校验，仅用于对固定仓库地址的降级重试。
 */
function navUpdateFetchRaw($endpoint, $insecure = false)
{
    try {
        if (function_exists('curl_init')) {
            $ch = @curl_init($endpoint);

            if ($ch === false) {
                navUpdateMarkError('fetch_failed');
                return null;
            }

            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS => 3,
                CURLOPT_CONNECTTIMEOUT => 8,
                CURLOPT_TIMEOUT => 15,
                CURLOPT_USERAGENT => 'XYdaohang-Update-Check',
                CURLOPT_HTTPHEADER => ['Accept: application/vnd.github+json, text/plain, */*'],
                CURLOPT_SSL_VERIFYPEER => !$insecure,
                CURLOPT_SSL_VERIFYHOST => $insecure ? 0 : 2,
            ]);
            $body = @curl_exec($ch);
            $status = (int) @curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            $errno = (int) @curl_errno($ch);
            @curl_close($ch);

            if (!is_string($body) || $body === '' || $status < 200 || $status >= 300) {
                navUpdateMarkError(navUpdateMapCurlError($errno), $errno);
                return null;
            }

            return [$status, $body];
        }

        // 无 cURL 环境：回退 stream（需 allow_url_fopen = On）
        if (ini_get('allow_url_fopen')) {
            $context = stream_context_create([
                'http' => [
                    'method' => 'GET',
                    'timeout' => 15,
                    'user_agent' => 'XYdaohang-Update-Check',
                    'ignore_errors' => true,
                    'follow_location' => 1,
                    'max_redirects' => 3,
                ],
                'ssl' => [
                    'verify_peer' => !$insecure,
                    'verify_peer_name' => !$insecure,
                ],
            ]);
            $body = @file_get_contents($endpoint, false, $context);

            if (!is_string($body) || $body === '') {
                navUpdateMarkError('fetch_failed');
                return null;
            }

            return [200, $body];
        }
    } catch (Throwable $e) {
        navUpdateMarkError('fetch_failed');
        return null;
    }

    navUpdateMarkError('fetch_failed');
    return null;
}

/**
 * 抓取一个地址：先按正常 SSL 校验请求，若失败原因是证书问题，
 * 则对同一地址放宽证书校验重试一次。目标地址固定为本仓库，不构成开放代理。
 * 服务器未配置 curl.cainfo 时，这一层降级是版本检测能否成功的关键。
 */
function navUpdateFetchWithRetry($endpoint)
{
    if (navUpdateBudgetExceeded()) {
        return null;
    }

    $result = navUpdateFetchRaw($endpoint, false);

    if ($result !== null) {
        return $result;
    }

    if ((string) ($GLOBALS['navUpdateError'] ?? '') === 'ssl_error' && !navUpdateBudgetExceeded()) {
        return navUpdateFetchRaw($endpoint, true);
    }

    return null;
}

/** 是否已超出整体抓取预算。 */
function navUpdateBudgetExceeded()
{
    $deadline = (float) ($GLOBALS['navUpdateDeadline'] ?? 0);

    if ($deadline > 0 && microtime(true) > $deadline) {
        navUpdateMarkError('timeout');
        return true;
    }

    return false;
}

/** 抓取 Releases API，要求返回 JSON 对象。 */
function navUpdateFetchApi($endpoint)
{
    $result = navUpdateFetchWithRetry($endpoint);

    if ($result === null) {
        return null;
    }

    $data = json_decode($result[1], true);

    return is_array($data) ? $data : null;
}

$release = null;
$source = '';

// 第一链路：Releases API（原有逻辑）
foreach ($apiEndpoints as $endpoint) {
    $data = navUpdateFetchApi($endpoint);

    if (is_array($data) && !empty($data['tag_name'])) {
        $release = $data;
        $source = $endpoint === $apiPath ? 'github' : 'mirror';
        break;
    }
}

// 第二链路：raw VERSION 文件（Releases API 全失败时兜底）
if (!$release) {
    foreach ($rawEndpoints as $endpoint) {
        $result = navUpdateFetchWithRetry($endpoint);

        if ($result === null) {
            continue;
        }

        $version = trim((string) $result[1]);

        // VERSION 文件内容必须形如 2.2.1，防止抓到错误页
        if (!preg_match('/^\d+\.\d+\.\d+$/', $version)) {
            continue;
        }

        $release = [
            'tag_name' => 'v' . $version,
            'name' => 'v' . $version,
            'body' => '',
            'html_url' => 'https://github.com/' . $repo . '/releases',
            'published_at' => '',
        ];
        $source = $endpoint === $rawPath ? 'github-raw' : 'mirror-raw';
        break;
    }
}

if (!$release) {
    // 返回最具体的失败原因（ssl_error / dns_error / timeout / fetch_failed），前端据此给出可操作提示
    navUpdateResponse(['ok' => false, 'error' => (string) ($GLOBALS['navUpdateError'] ?: 'fetch_failed')]);
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
