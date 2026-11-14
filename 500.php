<?php
/**
 * 轩宇-导航 · 自定义 500 页面（v2.18.1）
 *
 * 与 404.php 同源：自足、不连数据库、不输出任何异常信息，
 * 只告诉访问者「服务暂时不可用」，并显式返回 500。
 * web.config 的 httpErrors 已把 500 指向本文件。
 */

require_once __DIR__ . '/includes/bootstrap.php';

http_response_code(500);
header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Retry-After: 120');
header('X-Robots-Tag: noindex, nofollow');
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title>服务暂时不可用</title>
    <meta name="robots" content="noindex, nofollow">
    <meta name="theme-color" content="#f5f5f3" id="themeColor">
    <link rel="icon" href="assets/img/icons/icon-192.png">
    <link rel="stylesheet" href="assets/css/nav.css?v=2.18.6">
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
        .error-shell { max-width: 520px; margin: 0 auto; padding: 104px 24px 64px; text-align: center; }
        .error-code {
            font-size: 72px; font-weight: 700; line-height: 1; letter-spacing: -2px;
            color: var(--muted, #8a8f98); margin: 0 0 12px;
        }
        .error-title { font-size: 22px; font-weight: 600; margin: 0 0 10px; color: var(--text); }
        .error-desc { font-size: 14px; line-height: 1.7; color: var(--muted); margin: 0 0 26px; }
        .error-home {
            display: inline-block; padding: 11px 22px; font-size: 14px; font-weight: 500;
            color: var(--primary-contrast); background: var(--primary);
            border-radius: var(--radius-sm, 10px); text-decoration: none;
        }
        .error-home:hover { background: var(--primary-hover); }
        .error-home:focus-visible { outline: 2px solid var(--primary); outline-offset: 2px; }
    </style>
</head>
<body>
    <main class="error-shell">
        <p class="error-code" aria-hidden="true">500</p>
        <h1 class="error-title">服务暂时不可用</h1>
        <p class="error-desc">服务器正在维护或负载过高，请稍后刷新重试。</p>
        <a class="error-home" href="index.php">返回首页</a>
    </main>
</body>
</html>
