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
    define('APP_VERSION', '2.18.1');
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
    // v2.18.1 · 去掉 PHP 版本泄露。IIS FastCGI 下 web.config 的 <customHeaders><remove>
    // 管不到 SAPI 自己追加的 X-Powered-By，只能在脚本里显式摘掉；
    // Apache 侧 .htaccess 的 Header unset 作为另一条兜底。
    header_remove('X-Powered-By');
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

/**
 * v2.18.1 · 站点基础地址（canonical / og:image / sitemap 用）。
 * 协议与域名都取当前实际请求，不写死。
 */
if (!function_exists('navSiteBaseUrl')) {
    function navSiteBaseUrl()
    {
        static $base = null;

        if ($base !== null) {
            return $base;
        }

        $https = (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off')
            || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower((string) $_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https')
            || (isset($_SERVER['SERVER_PORT']) && (int) $_SERVER['SERVER_PORT'] === 443);

        $host = (string) ($_SERVER['HTTP_HOST'] ?? 'localhost');
        $port = (string) ($_SERVER['SERVER_PORT'] ?? '');

        if ($port !== '' && !in_array((int) $port, [80, 443], true) && strpos($host, ':') === false) {
            $host .= ':' . $port;
        }

        // 站点可能部署在子目录；直接取请求目录（/admin/xx.php → /admin），不做正则推断
        $script = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? ''));
        $dir = rtrim(dirname($script), '/');

        if ($dir === '.' || $dir === '/') {
            $dir = '';
        }

        $base = ($https ? 'https://' : 'http://') . $host . $dir;

        return $base;
    }
}

/**
 * v2.18.1 · 匿名页短缓存 + ETag。
 *
 * 默认 session_start() 会给所有响应带上 `Cache-Control: no-store`，
 * 匿名访客的公开页面因此完全无法被浏览器 / CDN 缓存。
 * 这里只对「未登录 + 只读公开页」放开 60 秒短缓存：
 *   - 加 Vary: Cookie，任何带 session 的响应都会被缓存层单独存放，
 *     不会把别人登录后的页面发给访客；
 *   - 输出缓冲结束时按最终 HTML 计算 ETag，命中 If-None-Match 返回 304；
 *   - 登录态、my.php、admin/、其它接口一律维持 no-store（不动 session 默认行为）。
 */
/**
 * 整页 HTML 空白压缩：只压标签之间的缩进与换行。
 * <pre>/<textarea>/<script>/<style> 内容原样保留（占位后再还原）。
 */
if (!function_exists('navMinifyHtml')) {
    function navMinifyHtml($html)
    {
        if (!is_string($html) || strlen($html) < 2048) {
            return $html;
        }

        $hold = [];
        $html = preg_replace_callback(
            '#<(pre|textarea|script|style)\b[^>]*>.*?</\1>#is',
            function ($match) use (&$hold) {
                $key = "\x01NAVHOLD" . count($hold) . "\x01";
                $hold[$key] = $match[0];

                return $key;
            },
            $html
        );

        if (!is_string($html)) {
            return $html;
        }

        $html = preg_replace('/>\s+</', '><', $html);

        if ($hold) {
            $html = strtr($html, $hold);
        }

        return $html;
    }
}

if (!function_exists('navPublicCache')) {
    function navPublicCache()
    {
        // 注意：php -S 的 SAPI 也是 cli-server，只排除纯命令行（cli），否则本地调试永远看不到缓存头
        // 只排除纯命令行；php -S 的 SAPI 是 cli-server，本地调试也要能验证缓存头。
        if (PHP_SAPI === 'cli' || isset($_GET['nocache']) || isset($_GET['preview'])) {
            return;
        }

        // 登录用户（$_SESSION 里有身份信息）一律不缓存
        if (session_status() !== PHP_SESSION_ACTIVE
            || !empty($_SESSION['user_id'])
            || !empty($_SESSION['username'])
            || !empty($_SESSION['admin_id'])
        ) {
            return;
        }

        if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'GET') {
            return;
        }

        // 只读公开页白名单（按脚本文件名，子目录也算）
        $script = strtolower(basename((string) ($_SERVER['SCRIPT_NAME'] ?? '')));
        $publicScripts = ['index.php', 'about.php', 'contact.php', 'apply.php', 'detail.php', 'share.php'];

        if (!in_array($script, $publicScripts, true)) {
            return;
        }

        if (!headers_sent()) {
            header_remove('Expires');
            header_remove('Pragma');
            header('Cache-Control: public, max-age=60, s-maxage=60');
            // 覆盖 session_start 的 no-store，同时保证 Vary 里只出现一次 Cookie
            header('Vary: Cookie');
        }

        ob_start(function ($buffer) {
            if (!is_string($buffer) || $buffer === '' || headers_sent()) {
                return $buffer;
            }

            // 先做整页空白压缩，再算 ETag：ETag 必须对「真正发出去的内容」取摘要，
            // 否则压缩前后内容不同、ETag 却一样，304 判断会失真。
            // <pre>/<textarea>/<script>/<style> 里的空白有语义（排版、模板字符串），
            // 先挖出来占位，压完再填回去，不能整页跳过——首页确实带 <textarea>。
            $buffer = navMinifyHtml($buffer);

            // gzip 层可能已经压过，这类响应跳过 ETag（内容不可逆）
            foreach (headers_list() as $header) {
                if (stripos($header, 'Content-Encoding:') === 0) {
                    return $buffer;
                }
            }

            $etag = '"' . md5($buffer) . '"';
            $modified = gmdate('D, d M Y H:i:s') . ' GMT';
            $inline = !empty($_SERVER['HTTP_IF_NONE_MATCH'])
                && trim((string) $_SERVER['HTTP_IF_NONE_MATCH']) === $etag;

            header('ETag: ' . $etag);
            header('Last-Modified: ' . $modified);

            if ($inline) {
                http_response_code(304);
                header('Content-Length: 0');

                return '';
            }

            header('Content-Length: ' . strlen($buffer));

            return $buffer;
        });
    }
}

navMaybeGzip();

if (!isset($GLOBALS['navPublicCacheStarted'])) {
    $GLOBALS['navPublicCacheStarted'] = true;

    // gzip 层先注册，本层后注册：后注册的先执行，即先算 ETag，再交给 gzip 压缩
    navPublicCache();
}
