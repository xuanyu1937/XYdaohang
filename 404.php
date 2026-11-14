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
    <meta name="theme-color" content="#f5f5f3" id="themeColor">
    <link rel="icon" href="assets/img/icons/icon-192.png">
    <link rel="stylesheet" href="assets/css/nav.css?v=2.18.4">
    <script>
        (function () {
            try {
                var saved = localStorage.getItem('xy-nav-theme');
                var theme = (saved === 'dark' || saved === 'light') ? saved
                    : (window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light');
                document.documentElement.setAttribute('data-theme', theme);
            } catch (e) {}
        })();
    </script>
    <style>
        .error-shell {
            max-width: 560px;
            margin: 0 auto;
            padding: 96px 24px 64px;
            text-align: center;
        }
        .error-code {
            font-size: 72px;
            font-weight: 700;
            line-height: 1;
            letter-spacing: -2px;
            color: var(--primary);
            margin: 0 0 12px;
        }
        .error-title {
            font-size: 22px;
            font-weight: 600;
            margin: 0 0 10px;
            color: var(--text);
        }
        .error-desc {
            font-size: 14px;
            line-height: 1.7;
            color: var(--muted);
            margin: 0 0 28px;
        }
        .error-form {
            display: flex;
            gap: 8px;
            max-width: 420px;
            margin: 0 auto 20px;
        }
        .error-form input[type="search"] {
            flex: 1 1 auto;
            min-width: 0;
            padding: 11px 14px;
            font-size: 14px;
            color: var(--text);
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: var(--radius-sm, 10px);
        }
        .error-form button,
        .error-home {
            flex: none;
            padding: 11px 20px;
            font-size: 14px;
            font-weight: 500;
            color: var(--primary-contrast);
            background: var(--primary);
            border: 0;
            border-radius: var(--radius-sm, 10px);
            cursor: pointer;
        }
        .error-home {
            display: inline-block;
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
            font-size: 13px;
            color: var(--muted);
        }
        .error-links a { color: var(--primary); }
    </style>
</head>
<body>
    <main class="error-shell">
        <p class="error-code" aria-hidden="true">404</p>
        <h1 class="error-title">页面不存在</h1>
        <p class="error-desc">你访问的地址可能已经删除、更名，或者从来没有存在过。</p>

        <form class="error-form" action="index.php" method="get" role="search">
            <label for="errorSearch" style="position:absolute;width:1px;height:1px;overflow:hidden;clip:rect(0 0 0 0);">搜索站点</label>
            <input type="search" id="errorSearch" name="q" placeholder="搜索网址或站点名称" autocomplete="off">
            <button type="submit">搜索</button>
        </form>

        <a class="error-home" href="index.php">返回首页</a>

        <p class="error-links"><a href="index.php"><?php echo $escape($siteName); ?></a> · 或直接在地址栏检查是否有拼写错误</p>
    </main>
</body>
</html>
