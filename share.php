<?php
/**
 * 轩宇-导航 · 分享预览页
 * 打开分享链接可查看内容，登录后可一键导入到自己的导航。
 */
require_once __DIR__ . '/includes/bootstrap.php';
require_once 'includes/functions.php';
require_once 'includes/Services.php';

$functions = new Functions();
$users = new UserService($functions);
$shares = new ShareService($functions);

$brandName = trim((string) $functions->getSetting('site_title'));
$brandName = $brandName !== '' ? $brandName : '轩宇-导航';
$siteLogoUrl = $functions->getLogoUrl($functions->getSetting('site_logo'));
$frontendTheme = (string) $functions->getSetting('frontend_theme');

if (!in_array($frontendTheme, ['system', 'light', 'dark'], true)) {
    $frontendTheme = 'system';
}

$brandInitial = $functions->textCut($brandName, 1);

$token = trim((string) ($_GET['token'] ?? ''));
$share = $shares->findByToken($token);

if ($share) {
    $shares->incrementViews((int) $share['id']);
    $sites = $shares->sitesOf($share);
} else {
    $sites = [];
}

$currentUser = $users->currentUser();
$isOwner = $currentUser && (int) $currentUser['id'] === (int) ($share['user_id'] ?? 0);

$shareTitle = $share ? trim((string) $share['title']) : '';

if ($shareTitle === '') {
    $shareTitle = '导航分享';
}

// 站点按分类分组展示，便于浏览。
$grouped = [];

foreach ($sites as $site) {
    $category = trim((string) ($site['category_name'] ?? ''));

    if ($category === '') {
        $category = '未分类';
    }

    if (!isset($grouped[$category])) {
        $grouped[$category] = [];
    }

    $grouped[$category][] = $site;
}

$tokenParam = rawurlencode($token);
?>
<!DOCTYPE html>
<html lang="zh-CN" data-skin="<?php echo $functions->escape($functions->getSetting('theme_skin')); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <meta name="robots" content="noindex,follow">
    <title><?php echo $functions->escape($shareTitle); ?> - <?php echo $functions->escape($brandName); ?></title>
    <meta name="description" content="<?php echo $functions->escape('来自 ' . $brandName . ' 的导航分享，共 ' . count($sites) . ' 个网站。'); ?>">
    <meta name="theme-color" content="#f4f6fa" id="themeColor">

    <?php if ($siteLogoUrl !== ''): ?>
        <link rel="icon" href="<?php echo $functions->escape($siteLogoUrl); ?>">
    <?php endif; ?>

    <script>
        (function () {
            try {
                var root = document.documentElement;
                var saved = localStorage.getItem('xy-nav-theme');
                var fallback = <?php echo json_encode($frontendTheme, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
                var theme = (saved === 'dark' || saved === 'light')
                    ? saved
                    : ((fallback === 'dark' || fallback === 'light')
                        ? fallback
                        : (window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light'));
                root.setAttribute('data-theme', theme);

                var meta = document.querySelector('meta[name="theme-color"]');
                if (meta) {
                    meta.setAttribute('content', theme === 'dark' ? '#0a1120' : '#f4f6fa');
                }
            } catch (e) {
                document.documentElement.setAttribute('data-theme', 'light');
            }
        })();
    </script>

    <link rel="stylesheet" href="assets/css/nav.css">
    <link rel="stylesheet" href="assets/css/page.css">
</head>
<body data-monitor="0">
    <a class="skip-link" href="#shareMain">跳到分享列表</a>

    <div class="page-shell">
        <header class="site-header">
            <div class="header-brand">
                <a class="brand" href="index.php" aria-label="返回首页">
                    <span class="brand-mark" aria-hidden="true">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                            <circle cx="12" cy="12" r="9"></circle>
                            <path d="m15.4 8.6-2.1 4.7-4.7 2.1 2.1-4.7z"></path>
                        </svg>
                        <?php if ($siteLogoUrl !== ''): ?>
                            <img src="<?php echo $functions->escape($siteLogoUrl); ?>" alt="" width="48" height="48" onerror="this.remove();">
                        <?php endif; ?>
                    </span>
                    <span class="brand-info">
                        <span class="brand-title" translate="no"><?php echo $functions->escape($brandName); ?></span>
                        <span class="brand-subtitle">导航分享</span>
                    </span>
                </a>
            </div>

            <div class="header-tools">
                <button type="button" class="tool-btn theme-toggle" id="themeToggle" aria-label="切换到深色模式" title="切换到深色模式" aria-pressed="false">
                    <svg class="icon-moon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                        <path d="M20.5 14.2A8.5 8.5 0 0 1 9.8 3.5 8.5 8.5 0 1 0 20.5 14.2Z"></path>
                    </svg>
                    <svg class="icon-sun" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                        <circle cx="12" cy="12" r="4"></circle>
                        <path d="M12 2v2M12 20v2M4.9 4.9l1.4 1.4M17.7 17.7l1.4 1.4M2 12h2M20 12h2M4.9 19.1l1.4-1.4M17.7 6.3l1.4-1.4"></path>
                    </svg>
                </button>

                <?php if ($currentUser): ?>
                    <a class="tool-btn tool-text" href="my.php">我的导航</a>
                    <a class="tool-btn tool-text" href="index.php">返回首页</a>
                <?php else: ?>
                    <a class="tool-btn tool-text" href="login.php?redirect=<?php echo rawurlencode('share.php?token=' . $tokenParam); ?>">登录</a>
                    <a class="tool-btn tool-text is-primary" href="register.php">注册</a>
                <?php endif; ?>
            </div>
        </header>

        <main class="page-main" id="shareMain">
            <?php if (!$share): ?>
                <div class="page-empty">
                    <h3>分享不存在或已失效</h3>
                    <p>链接可能已被创建者删除，或者地址不完整。请向分享者重新索取。</p>
                    <a class="page-btn is-primary" href="index.php">返回首页</a>
                </div>
            <?php else: ?>
                <div class="page-card">
                    <h1 class="page-title"><?php echo $functions->escape($shareTitle); ?></h1>
                    <p class="page-desc">别人分享给你的导航合集，登录后可以一键导入到自己的导航。</p>

                    <div class="page-meta">
                        <span>共 <strong><?php echo count($sites); ?></strong> 个网站</span>
                        <span>浏览 <strong><?php echo (int) $share['views'] + 1; ?></strong> 次</span>
                        <span>创建于 <?php echo $functions->escape(date('Y-m-d H:i', strtotime((string) $share['created_at']))); ?></span>
                        <?php if ($isOwner): ?>
                            <span>这是你创建的分享</span>
                        <?php endif; ?>
                    </div>

                    <div class="page-actions">
                        <?php if ($currentUser): ?>
                            <button type="button" class="page-btn is-primary" id="importBtn" <?php echo $sites ? '' : 'disabled'; ?>>
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                    <path d="M12 3v12M7 10l5 5 5-5M5 21h14"></path>
                                </svg>
                                导入到我的导航
                            </button>
                            <a class="page-btn" href="my.php">前往我的导航</a>
                        <?php else: ?>
                            <a class="page-btn is-primary" href="login.php?redirect=<?php echo rawurlencode('share.php?token=' . $tokenParam); ?>">登录后导入</a>
                            <a class="page-btn" href="register.php">注册新账号</a>
                        <?php endif; ?>
                    </div>

                    <p class="page-alert is-success" id="importMsg" hidden role="status" aria-live="polite"></p>
                    <p class="page-alert is-error" id="importError" hidden role="alert"></p>
                </div>

                <?php if (!$sites): ?>
                    <div class="page-empty" style="margin-top:18px;">
                        <h3>这份分享里没有网站</h3>
                        <p>创建者可能已删除了其中的条目。</p>
                    </div>
                <?php else: ?>
                    <?php foreach ($grouped as $categoryName => $items): ?>
                        <div class="page-card">
                            <h2 class="page-section-title"><?php echo $functions->escape($categoryName); ?> · <?php echo count($items); ?></h2>
                            <div class="share-list">
                                <?php foreach ($items as $site): ?>
                                    <?php
                                    $url = (string) $site['url'];
                                    $host = parse_url($url, PHP_URL_HOST);
                                    $host = $host ? preg_replace('/^www\./i', '', $host) : $url;
                                    $name = trim((string) $site['name']);
                                    $name = $name !== '' ? $name : $host;
                                    $initial = $functions->textCut($name, 1);
                                    $logoUrl = $functions->getLogoDisplayUrl((string) ($site['logo'] ?? ''));
                                    ?>
                                    <a class="share-item"
                                       href="<?php echo $functions->escape($url); ?>"
                                       target="_blank"
                                       rel="noopener noreferrer"
                                       aria-label="访问 <?php echo $functions->escape($name); ?>">
                                        <span class="share-icon" aria-hidden="true">
                                            <span class="share-icon-letter"><?php echo $functions->escape($initial); ?></span>
                                            <?php if ($logoUrl !== ''): ?>
                                                <img src="<?php echo $functions->escape($logoUrl); ?>"
                                                     alt=""
                                                     width="36"
                                                     height="36"
                                                     loading="lazy"
                                                     decoding="async"
                                                     referrerpolicy="no-referrer"
                                                     onerror="this.remove();">
                                            <?php endif; ?>
                                        </span>
                                        <span class="share-info">
                                            <span class="share-name"><?php echo $functions->escape($name); ?></span>
                                            <span class="share-host"><?php echo $functions->escape($host); ?></span>
                                        </span>
                                    </a>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            <?php endif; ?>
        </main>

        <footer class="site-footer">
            <p class="copyright">
                &copy; <?php echo date('Y'); ?> <?php echo $functions->escape($brandName); ?>
                <span class="footer-sep">·</span>
                <a href="about.php">关于</a>
                <span class="footer-sep">·</span>
                <a href="contact.php">联系管理员</a>
            </p>
        </footer>
    </div>

    <script>
        (function () {
            'use strict';

            var toggle = document.getElementById('themeToggle');

            if (toggle) {
                toggle.addEventListener('click', function () {
                    var next = document.documentElement.getAttribute('data-theme') === 'dark' ? 'light' : 'dark';
                    document.documentElement.setAttribute('data-theme', next);
                    toggle.setAttribute('aria-pressed', next === 'dark' ? 'true' : 'false');
                    try { localStorage.setItem('xy-nav-theme', next); } catch (e) {}
                });
            }

            var importBtn = document.getElementById('importBtn');
            var okMsg = document.getElementById('importMsg');
            var errMsg = document.getElementById('importError');

            if (!importBtn) {
                return;
            }

            importBtn.addEventListener('click', function () {
                okMsg.hidden = true;
                errMsg.hidden = true;

                var idle = importBtn.textContent.trim();
                importBtn.disabled = true;
                importBtn.textContent = '导入中…';

                var body = new FormData();
                body.append('action', 'import');
                body.append('token', <?php echo json_encode($token, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT); ?>);

                fetch('api/share.php', {
                    method: 'POST',
                    body: body,
                    credentials: 'same-origin',
                    headers: { 'X-Requested-With': 'XMLHttpRequest' }
                }).then(function (response) {
                    return response.json().catch(function () {
                        return { ok: false, error: '服务器返回异常' };
                    });
                }).then(function (result) {
                    importBtn.disabled = false;
                    importBtn.textContent = idle;

                    if (!result.ok) {
                        errMsg.textContent = result.error || '导入失败，请稍后重试';
                        errMsg.hidden = false;
                        return;
                    }

                    okMsg.textContent = '导入完成：新增 ' + result.imported + ' 个网站，跳过已有 ' + result.skipped + ' 个。可前往「我的导航」查看。';
                    okMsg.hidden = false;
                }).catch(function () {
                    importBtn.disabled = false;
                    importBtn.textContent = idle;
                    errMsg.textContent = '网络异常，请稍后重试';
                    errMsg.hidden = false;
                });
            });
        })();
    </script>
</body>
</html>
