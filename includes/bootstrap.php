<?php
/**
 * 轩宇-导航 · 公共启动文件
 * 统一处理版本、时区、Session 安全参数与基础安全响应头。
 * 只允许通过 require_once 引入。
 */

if (defined('NAV_BOOTSTRAPPED')) {
    return;
}

define('NAV_BOOTSTRAPPED', true);

error_reporting(E_ALL);
@ini_set('display_errors', '0');
@ini_set('log_errors', '1');

date_default_timezone_set('Asia/Shanghai');

$navVersionFile = __DIR__ . '/../config/version.php';
if (is_file($navVersionFile)) {
    require_once $navVersionFile;
}

if (!defined('APP_VERSION')) {
    define('APP_VERSION', '2.16.3');
}

if (!defined('APP_RELEASE')) {
    define('APP_RELEASE', date('Y-m-d'));
}

$navIsHttps = (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off')
    || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower((string) $_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https')
    || (isset($_SERVER['SERVER_PORT']) && (int) $_SERVER['SERVER_PORT'] === 443);

if (session_status() === PHP_SESSION_NONE) {
    if (PHP_VERSION_ID >= 70300) {
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => '/',
            'domain' => '',
            'secure' => $navIsHttps,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    } else {
        session_set_cookie_params(0, '/; samesite=Lax', '', $navIsHttps, true);
    }

    @ini_set('session.use_strict_mode', '1');
    @ini_set('session.use_only_cookies', '1');
    @ini_set('session.cookie_httponly', '1');

    session_start();
}

if (!headers_sent()) {
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: SAMEORIGIN');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Permissions-Policy: geolocation=(), microphone=(), camera=()');

    if ($navIsHttps) {
        header('Strict-Transport-Security: max-age=31536000');
    }
}

/**
 * v2.16.0 · gzip 压缩兜底。
 *
 * 静态资源由 IIS / Apache / Nginx 直接响应，压缩交给 web.config / .htaccess / httpd.conf；
 * 但 PHP 输出的页面（首页 340KB、我的导航 250KB+）在部分主机上没有被压缩，
 * 这里在「服务器本身还没压缩」时用输出缓冲补一层，实测首页 HTML 可省约 94%。
 *
 * 约束：
 *   - 客户端没声明 gzip / deflate 时不做任何事；
 *   - 已经装了 zlib.output_compression 或服务器已压缩（Content-Encoding 已设置）时跳过，
 *     避免二次压缩导致乱码；
 *   - 只有 HTML / JSON / JS / CSS / SVG 这类文本才压，图片、字体、下载保留原样；
 *   - 关掉了压缩时顺便补 Vary: Accept-Encoding，避免 CDN 缓存串味。
 */
if (!function_exists('navMaybeGzip')) {
    function navMaybeGzip()
    {
        if (PHP_SAPI === 'cli' || headers_sent()) {
            return;
        }

        if (!function_exists('gzencode')) {
            return;
        }

        if (ini_get('zlib.output_compression')) {
            return;
        }

        // 服务器层（mod_deflate / IIS 动态压缩）已经处理过就不再插手
        foreach (headers_list() as $header) {
            if (stripos($header, 'Content-Encoding:') === 0) {
                return;
            }
        }

        $accept = strtolower((string) ($_SERVER['HTTP_ACCEPT_ENCODING'] ?? ''));

        if (strpos($accept, 'gzip') === false && strpos($accept, 'deflate') === false) {
            return;
        }

        header('Vary: Accept-Encoding');

        ob_start(function ($buffer) {
            // 太短的内容压缩反而变大，且不值得多一次 CPU
            if (!is_string($buffer) || strlen($buffer) < 1024) {
                return $buffer;
            }

            if (headers_sent()) {
                return $buffer;
            }

            // 只压缩文本类响应
            $contentType = '';
            foreach (headers_list() as $header) {
                if (stripos($header, 'Content-Type:') === 0) {
                    $contentType = strtolower($header);
                    break;
                }
            }

            $isText = $contentType === ''
                || strpos($contentType, 'text/') !== false
                || strpos($contentType, 'json') !== false
                || strpos($contentType, 'javascript') !== false
                || strpos($contentType, 'xml') !== false
                || strpos($contentType, 'svg') !== false;

            if (!$isText) {
                return $buffer;
            }

            $compressed = @gzencode($buffer, 6);

            if ($compressed === false || strlen($compressed) >= strlen($buffer)) {
                return $buffer;
            }

            header('Content-Encoding: gzip');
            header('Content-Length: ' . strlen($compressed));

            return $compressed;
        });
    }
}

navMaybeGzip();
