<?php
/**
 * 轩宇-导航 · 关于本站
 * 内容优先取后台「站点设置 → 关于内容」，留空时展示默认文案。
 */
require_once __DIR__ . '/includes/bootstrap.php';
require_once 'includes/functions.php';
require_once 'includes/Services.php';

$functions = new Functions();
$users = new UserService($functions);

$brandName = trim((string) $functions->getSetting('site_title'));
$brandName = $brandName !== '' ? $brandName : '轩宇-导航';
$siteDescription = trim((string) $functions->getSetting('site_description'));
$siteLogoUrl = $functions->getLogoUrl($functions->getSetting('site_logo'));
$frontendTheme = (string) $functions->getSetting('frontend_theme');

if (!in_array($frontendTheme, ['system', 'light', 'dark'], true)) {
    $frontendTheme = 'system';
}

$currentUser = $users->currentUser();
$aboutContent = trim((string) $functions->getSetting('about_content'));

// 未配置时使用默认文案；已配置时按纯文本渲染并保留换行。
if ($aboutContent !== '') {
    $aboutHtml = nl2br($functions->escape($aboutContent));
} else {
    $aboutHtml = null;
}

$version = defined('APP_VERSION') ? APP_VERSION : '2.0.0';

// 更新日志：解析根目录 CHANGELOG.md，按「## vX.Y.Z（日期）」切分，最多展示最近 5 个版本；
// 文件缺失或无可解析内容时该板块整体不渲染。
$changelogEntries = [];
$changelogPath = __DIR__ . '/CHANGELOG.md';

if (is_file($changelogPath) && is_readable($changelogPath)) {
    $changelogRaw = (string) @file_get_contents($changelogPath);

    if ($changelogRaw !== '' && preg_match_all('/^##\s+(v[\d.]+)（([^）]+)）\s*$/m', $changelogRaw, $heads, PREG_OFFSET_CAPTURE)) {
        $headCount = count($heads[0]);

        for ($i = 0; $i < $headCount && count($changelogEntries) < 5; $i++) {
            $bodyStart = $heads[0][$i][1] + strlen($heads[0][$i][0]);
            $bodyEnd = ($i + 1 < $headCount) ? $heads[0][$i + 1][1] : strlen($changelogRaw);
            $body = trim(substr($changelogRaw, $bodyStart, $bodyEnd - $bodyStart));

            $notes = [];
            $sections = [];
            $section = '';

            foreach (preg_split('/\R/', $body) as $line) {
                $line = trim($line);

                if ($line === '') {
                    continue;
                }

                if (strpos($line, '>') === 0) {
                    $note = trim((string) preg_replace('/^>\s?/', '', $line));

                    if ($note !== '') {
                        $notes[] = $note;
                    }
                    continue;
                }

                if (preg_match('/^\*\*(.+)\*\*$/u', $line, $m)) {
                    $section = trim($m[1]);
                    continue;
                }

                if (strpos($line, '-') === 0) {
                    $text = trim(mb_substr($line, 1));
                    $title = '';

                    if (preg_match('/^\*\*(.+?)\*\*[:：]?\s*/u', $text, $m)) {
                        $title = trim($m[1]);
                        $text = trim(mb_substr($text, mb_strlen($m[0])));
                    }

                    if (!isset($sections[$section])) {
                        $sections[$section] = [];
                    }

                    $sections[$section][] = ['title' => $title, 'text' => $text];
                }
            }

            $changelogEntries[] = [
                'version' => trim($heads[1][$i][0]),
                'date' => trim($heads[2][$i][0]),
                'notes' => $notes,
                'sections' => $sections,
            ];
        }
    }
}
?>
<!DOCTYPE html>
<html lang="zh-CN" data-skin="<?php echo $functions->escape($functions->getSetting('theme_skin')); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title>关于本站 - <?php echo $functions->escape($brandName); ?></title>
    <meta name="description" content="<?php echo $functions->escape('关于 ' . $brandName . ' 的介绍与使用说明。'); ?>">
    <meta name="theme-color" content="#f4f6fa" id="themeColor">
    <meta name="robots" content="index, follow">

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

    <link rel="stylesheet" href="assets/css/nav.css?v=2.5.0">
    <link rel="stylesheet" href="assets/css/page.css">
</head>
<body data-monitor="0">
    <a class="skip-link" href="#aboutMain">跳到正文</a>

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
                        <span class="brand-subtitle"><?php echo $functions->escape($siteDescription !== '' ? $siteDescription : '关于本站'); ?></span>
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
                <?php else: ?>
                    <a class="tool-btn tool-text" href="login.php?redirect=<?php echo rawurlencode('about.php'); ?>">登录</a>
                <?php endif; ?>
                <a class="tool-btn tool-text" href="index.php">返回首页</a>
            </div>
        </header>

        <main class="page-main" id="aboutMain">
            <div class="page-card">
                <h1 class="page-title">关于本站</h1>
                <p class="page-desc"><?php echo $functions->escape($brandName); ?> 是一个简洁实用的网址导航站。</p>

                <div class="about-body">
                    <?php if ($aboutHtml !== null): ?>
                        <?php echo $aboutHtml; ?>
                    <?php else: ?>
                        <h3>这是什么</h3>
                        <p>
                            本站把常去的网站按分类整理在一起，打开一个页面就能找到入口，省去翻书签和记网址的麻烦。
                            首页左侧是分类索引，右侧是分组的网站卡片。
                        </p>

                        <h3>可以做什么</h3>
                        <ul>
                            <li>搜索：顶部搜索框可按名称、域名或备注筛选，按 <kbd>/</kbd> 可快速聚焦。</li>
                            <li>显示方式：支持网格、列表、紧凑三种布局，右下角悬浮按钮可一键切换。</li>
                            <li>主题：可切换深浅色，也可换配色皮肤，选择会保存在本地浏览器。</li>
                            <li>个人导航：<a href="register.php">注册</a>后可以收藏自己的常用网址，也可以直接导入浏览器书签。</li>
                            <li>分享：在「我的导航」里多选站点，即可生成分享链接发给他人。</li>
                        </ul>

                        <h3>数据说明</h3>
                        <p>
                            站点信息由本站维护者录入。点击量仅用于展示热门排行，不会用于其他用途。
                            如果你发现某个站点无法访问或内容不合适，欢迎通过
                            <a href="contact.php">联系管理员</a>告诉我们。
                        </p>
                    <?php endif; ?>
                </div>

                <?php if ($changelogEntries !== []): ?>
                    <div class="about-changelog">
                        <h3 class="about-changelog-title">更新日志</h3>

                        <?php foreach ($changelogEntries as $entry): ?>
                            <section class="changelog-entry">
                                <header class="changelog-head">
                                    <span class="changelog-version"><?php echo $functions->escape($entry['version']); ?></span>
                                    <time class="changelog-date"><?php echo $functions->escape($entry['date']); ?></time>
                                </header>

                                <?php foreach ($entry['notes'] as $note): ?>
                                    <p class="changelog-note"><?php echo $functions->escape($note); ?></p>
                                <?php endforeach; ?>

                                <?php foreach ($entry['sections'] as $sectionName => $items): ?>
                                    <?php if ($sectionName !== ''): ?>
                                        <p class="changelog-section-label"><?php echo $functions->escape($sectionName); ?></p>
                                    <?php endif; ?>
                                    <ul class="changelog-list">
                                        <?php foreach ($items as $item): ?>
                                            <li>
                                                <?php if ($item['title'] !== ''): ?>
                                                    <strong><?php echo $functions->escape($item['title']); ?></strong>：
                                                <?php endif; ?>
                                                <?php echo $functions->escape($item['text']); ?>
                                            </li>
                                        <?php endforeach; ?>
                                    </ul>
                                <?php endforeach; ?>
                            </section>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>

                <div class="about-version">
                    <span>当前版本 <code><?php echo $functions->escape($version); ?></code></span>
                    <span>运行环境 PHP <?php echo $functions->escape(PHP_VERSION); ?></span>
                    <span>&copy; <?php echo date('Y'); ?> <?php echo $functions->escape($brandName); ?></span>
                </div>
            </div>
        </main>

        <footer class="site-footer">
            <p class="copyright">
                <?php echo $functions->escape($functions->footerCopyrightText('', $brandName)); ?>
                <span class="footer-sep">·</span>
                <a href="index.php">首页</a>
                <span class="footer-sep">·</span>
                <a href="contact.php">联系管理员</a>
            </p>
        </footer>
    </div>

    <script>
        (function () {
            var toggle = document.getElementById('themeToggle');

            if (!toggle) {
                return;
            }

            toggle.addEventListener('click', function () {
                var next = document.documentElement.getAttribute('data-theme') === 'dark' ? 'light' : 'dark';
                document.documentElement.setAttribute('data-theme', next);
                toggle.setAttribute('aria-pressed', next === 'dark' ? 'true' : 'false');
                try { localStorage.setItem('xy-nav-theme', next); } catch (e) {}
            });
        })();
    </script>
<script src="assets/js/search.js?v=2.5.0" defer></script>
</body>
</html>
