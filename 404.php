<?php
/**
 * 轩宇-导航 · 自定义 404 页面（v2.18.1）
 *
 * 目的：不再让不存在的路径落到 IIS / Apache 的默认错误页，避免泄露
 * IIS 版本、详细错误号（404.0）、物理路径等信息。
 *
 * 本文件刻意「自足」：
 *   - 只依赖 bootstrap（版本 / 时区 / 安全头），不引入 Functions、不连数据库，
 *     数据库故障时也能正常显示；
 *   - 不输出任何异常信息、堆栈、服务器变量；
 *   - 状态码显式设为 404（被 httpErrors 以 ExecuteURL 调用时同样返回 404）。
 *
 * 部署：web.config 的 httpErrors 已指向本文件；若主机面板锁定了 httpErrors，
 * 请在面板的「错误页」设置里把 404 指向 /404.php。
 */

require_once __DIR__ . '/includes/bootstrap.php';

http_response_code(404);
header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('X-Robots-Tag: noindex, nofollow');

// 站点名：读一次设置表；数据库不可用则用默认名，页面照常显示
$siteName = '轩宇导航';
try {
    require_once __DIR__ . '/includes/functions.php';
    $siteTitle = trim((string) (new Functions())->getSetting('site_title'));
    if ($siteTitle !== '') {
        $siteName = $siteTitle;
    }
} catch (Throwable $error) {
    // 忽略：不影响 404 页面本身
}

$escape = static function ($value) {
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
};
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title>页面不存在 · <?php echo $escape($siteName); ?></title>
    <meta name="robots" content="noindex, nofollow">
    <meta name="theme-color" content="#ffffff" id="themeColor">
    <link rel="icon" type="image/svg+xml" href="assets/img/icons/favicon.svg">
    <link rel="icon" type="image/x-icon" href="assets/img/icons/favicon.ico">
    <link rel="stylesheet" href="assets/css/apple-tokens.css?v=2.18.23">
    <link rel="stylesheet" href="assets/css/nav.css?v=2.18.23">
    <script>
        (function () {
            try {
                var saved = localStorage.getItem('xy-nav-theme');
                var theme = (saved === 'dark' || saved === 'light') ? saved
                    : (window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light');
                document.documentElement.setAttribute('data-theme', theme);
                var meta = document.querySelector('meta[name="theme-color"]');
                if (meta) { meta.setAttribute('content', theme === 'dark' ? '#000000' : '#ffffff'); }
            } catch (e) {}
        })();
    </script>
    <style>
        /* v2.18.11 P2-5：内容块在视口内垂直居中，短内容不再贴顶 */
        body { min-height: 100vh; margin: 0; display: flex; align-items: center; justify-content: center; }
        .error-shell {
            width: 100%;
            max-width: 560px;
            margin: 0 auto;
            padding: 48px 24px;
            text-align: center;
        }
        .error-code {
            font-size: 72px;
            font-weight: 600;
            line-height: 1;
            letter-spacing: -2px;
            color: var(--text);
            margin: 0 0 12px;
        }
        .error-title {
            font-size: 22px;
            font-weight: 600;
            margin: 0 0 10px;
            color: var(--text);
        }
        .error-desc {
            font-size: var(--a-fs-body);
            line-height: var(--a-lh-body);
            color: var(--muted);
            margin: 0 0 28px;
        }
        .error-form {
            display: flex;
            align-items: center;
            gap: 6px;
            max-width: 440px;
            margin: 0 auto 20px;
        }
        /* v2.18.11 P0-1：404 搜索框与首页 / sites.php 同一套胶囊尺寸与描边 */
        /* v2.18.12：与首页同款放大镜 + 占位文字不重叠（图标右缘 + ≥10px） */
        .error-search {
            position: relative;
            flex: 1 1 auto;
            min-width: 0;
        }
        .error-search .error-search-icon {
            position: absolute; left: 12px; top: 50%; transform: translateY(-50%);
            width: 18px; height: 18px; color: var(--a-muted); pointer-events: none;
        }
        .error-form input[type="search"] {
            width: 100%;
            height: var(--a-search-h);
            padding: 0 12px 0 40px;
            font-size: 14px;
            color: var(--text);
            background: var(--a-canvas);
            border: 1px solid var(--a-line-soft);
            border-radius: var(--a-radius-pill);
        }
        html[data-theme="dark"] .error-form input[type="search"] {
            border-color: rgba(255, 255, 255, 0.12);
            background: var(--a-tile-1);
        }
        .error-form input[type="search"]:focus-visible {
            outline: 2px solid var(--a-focus);
            outline-offset: 2px;
        }
        .error-form button {
            flex: none;
            height: 44px;
            padding: 0 22px;
            font-size: 14px;
            font-weight: var(--a-fw-medium);
            color: var(--primary-contrast);
            background: var(--primary);
            border: 0;
            border-radius: var(--a-radius-pill);
            cursor: pointer;
        }
        .error-home {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            height: 44px;
            padding: 0 22px;
            font-size: 14px;
            font-weight: var(--a-fw-medium);
            color: var(--primary-contrast);
            background: var(--primary);
            border: 0;
            border-radius: var(--a-radius-pill);
            text-decoration: none;
        }
        .error-form button:hover,
        .error-home:hover { background: var(--primary-hover); }
        .error-form button:focus-visible,
        .error-home:focus-visible,
        .error-form input:focus-visible {
            outline: 2px solid var(--primary);
            outline-offset: 2px;
        }
        .error-links {
            margin-top: 14px;
            font-size: var(--a-fs-caption);
            color: var(--muted);
        }
        .error-links a {
            color: var(--link);
            text-decoration: underline;
            text-underline-offset: 2px;
        }
    </style>
</head>
<body>
    <main class="error-shell">
        <p class="error-code" aria-hidden="true">404</p>
        <h1 class="error-title">页面不存在</h1>
        <p class="error-desc">你访问的地址可能已经删除、更名，或者从来没有存在过。</p>

        <form class="error-form" action="index.php" method="get" role="search">
            <label for="errorSearch" style="position:absolute;width:1px;height:1px;overflow:hidden;clip:rect(0 0 0 0);">搜索站点</label>
            <span class="error-search">
                <svg class="error-search-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="11" cy="11" r="7"></circle><path d="m20 20-3.2-3.2"></path></svg>
                <input type="search" id="errorSearch" name="q" placeholder="搜索网址或站点名称" autocomplete="off">
            </span>
            <button type="submit">搜索</button>
        </form>

        <a class="error-home" href="index.php">返回首页</a>

        <p class="error-links"><a href="index.php"><?php echo $escape($siteName); ?></a> · 或直接在地址栏检查是否有拼写错误</p>
    </main>
</body>
</html>
