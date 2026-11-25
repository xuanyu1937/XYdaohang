<?php
/**
 * 轩宇-导航 · 全部站点索引（v2.18.3）
 *
 * 为什么单独做一页：
 *   1. 首页卡片直接跳外站，站内详情页（detail.php）没有任何内链入口，
 *      搜索引擎根本发现不了这几十上百个页面 —— 这一页就是它们的入口。
 *   2. 目录站天然缺"可被索引的内容页"，一页分好组、带简介的文字索引，
 *      比任何关键词堆砌都有效。
 */
require_once __DIR__ . '/includes/bootstrap.php';
require_once 'includes/functions.php';
require_once 'includes/Services.php';
require_once __DIR__ . '/includes/site_links.php';
require_once __DIR__ . '/includes/site_card_build.php';   /* v2.18.16：复用 navBuildCard 的 favicon 兜底链与字母兜底 */
require_once __DIR__ . '/includes/site_index_data.php';

$functions = new Functions();
$functions->recordVisit();

$brandName = trim((string) $functions->getSetting('site_title')) ?: '轩宇-导航';
$brandSubtitle = trim((string) $functions->getSetting('site_description')) ?: '一个简洁实用的导航网站';
$siteLogoUrl = $functions->getLogoUrl($functions->getSetting('site_logo'));

$rows = navPublicIndexLinks($functions);

/* v2.18.16：为每条索引记录构建与首页同源的站点图标数据（favicon 三级兜底链 +
   域名首字母兜底色）。navBuildCard 需要 safe_url/url 等字段，此处只取图标相关结果。 */
foreach ($rows as &$__row) {
    $__card = navBuildCard($__row, $functions);
    if ($__card) {
        $__row['icon_chain'] = $__card['favicon_chain'];
        $__row['icon_initial'] = $__card['fallback_initial'];
        $__row['icon_bg'] = $__card['fallback_bg'];
        $__row['icon_fg'] = $__card['fallback_fg'];
    } else {
        $__row['icon_chain'] = [];
        $__row['icon_initial'] = '#';
        $__row['icon_bg'] = '#e8efff';
        $__row['icon_fg'] = '#3457c5';
    }
}
unset($__row);

/* v2.19.0 P0：navIndexCategories 会把每行按值拷贝进分类快照，必须在图标字段
   补全之后再归档；原先放在 foreach 之前，渲染读的是旧快照，导致 42 行
   .site-favicon 的 data-src 与字母兜底全空。 */
$categories = navIndexCategories($rows);

$totalSites = count($rows);
$totalCategories = count($categories);

/* v2.19.0 P1-3：与列表同源的站点数。旧逻辑用「全库 is_active=1」统计（含其他用户与私有
   分类），与当前视图列表不一致；现在 $rows 已由 navPublicIndexLinks 按当前视图用户 +
   is_active=1 + 分类非私有 + 未删除筛出，count($rows) 即同源权威数。文案只保留一个数。 */
$publishedSiteTotal = $totalSites;
try {
    $__db = Database::getInstance()->getConnection();
    $__users = new UserService($functions);
    $__viewUserId = (int) $__users->defaultViewUserId();
    if ($__viewUserId > 0) {
        $publishedSiteStmt = $__db->prepare(
            "SELECT COUNT(*) FROM nav_links nl
             LEFT JOIN categories c ON c.id = nl.category_id
             WHERE nl.user_id = ? AND nl.is_active = 1 AND nl.deleted_at IS NULL
               AND COALESCE(c.is_private, 0) = 0"
        );
        $publishedSiteStmt->execute([$__viewUserId]);
        $__cnt = (int) $publishedSiteStmt->fetchColumn();
        // 空站自愈场景：defaultViewUserId 无数据而列表回退到了别的视图用户，
        // 此时以列表实际条数为准，避免两口径打架。
        if ($__cnt > 0) { $publishedSiteTotal = $__cnt; }
    }
} catch (Throwable $ignored) {
    // 老库兼容：查询失败时退回当前视图数
}


$baseUrl = navSiteBaseUrl();
$canonicalUrl = $baseUrl . '/sites.php';
$pageTitle = '全部网站索引（' . $totalSites . ' 个站点） - ' . $brandName;
$pageDescription = $brandName . '收录的 ' . $totalSites . ' 个网站完整索引，按' . $totalCategories
    . '个分类整理。每个站点都附一句简介并链接到站内详情页，可查看标签、访问地址与二维码，'
    . '也能直接用上方搜索框按站名或域名定位，适合想按分类系统地逛一遍全部收录的情况。';

$jsonLd = [
    '@context' => 'https://schema.org',
    '@type' => 'CollectionPage',
    'url' => $canonicalUrl,
    'name' => $pageTitle,
    'description' => $pageDescription,
    'inLanguage' => 'zh-CN',
    'isPartOf' => ['@id' => $baseUrl . '/#website'],
    'breadcrumb' => [
        '@type' => 'BreadcrumbList',
        'itemListElement' => [
            ['@type' => 'ListItem', 'position' => 1, 'name' => '首页', 'item' => $baseUrl . '/'],
            ['@type' => 'ListItem', 'position' => 2, 'name' => '全部站点', 'item' => $canonicalUrl],
        ],
    ],
];
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $functions->escape($pageTitle); ?></title>
    <meta name="description" content="<?php echo $functions->escape($pageDescription); ?>">
    <meta name="robots" content="index, follow">
    <link rel="canonical" href="<?php echo $functions->escape($canonicalUrl); ?>">
    <meta property="og:type" content="website">
    <meta property="og:title" content="<?php echo $functions->escape($pageTitle); ?>">
    <meta property="og:description" content="<?php echo $functions->escape($pageDescription); ?>">
    <meta property="og:url" content="<?php echo $functions->escape($canonicalUrl); ?>">
    <meta property="og:image" content="<?php echo $functions->escape($baseUrl . '/assets/img/icons/icon-512.png'); ?>">
    <meta name="twitter:card" content="summary">
    <script type="application/ld+json"><?php echo json_encode($jsonLd, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP); ?></script>
    <meta name="theme-color" content="#ffffff" id="themeColor">
    <script>
        /* v2.18.11 P1-3：与首页共用同一套 early script，读取 xy-nav-theme 写入 data-theme，
           保证 sites.php 在暗色设置下也能命中 apple-tokens.css 的 data-theme 暗色块。 */
        (function () {
            try {
                var root = document.documentElement;
                var saved = localStorage.getItem('xy-nav-theme');
                var theme = (saved === 'dark' || saved === 'light')
                    ? saved
                    : (window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light');
                root.setAttribute('data-theme', theme);
                var meta = document.querySelector('meta[name="theme-color"]');
                if (meta) { meta.setAttribute('content', theme === 'dark' ? '#000000' : '#ffffff'); }
            } catch (e) {
                document.documentElement.setAttribute('data-theme', 'light');
            }
        })();
    </script>
    <link rel="stylesheet" href="assets/css/apple-tokens.css?v=2.19.3">
    <style>
        :root { color-scheme: light; --fg: var(--a-ink); --muted: var(--a-muted); --line: var(--a-hairline); --accent: var(--a-primary); --bg: var(--a-canvas); }
        /* v2.18.11 P1-3：暗色改由 html[data-theme="dark"] 驱动（与首页 / 关于页同源），
           --muted 用会跟随主题的 --a-muted，亮色 #5b5b60 / 暗色 #a1a1a6，两档都过 4.5:1。
           P2-4：theme-color 同步为 #ffffff / #000000，避免手机顶栏脱色。 */
        html[data-theme="dark"] { color-scheme: dark; --fg: var(--a-white); --muted: var(--a-muted); --line: rgba(255,255,255,.14); --accent: var(--a-link-on-dark); --bg: #000000; }
        * { box-sizing: border-box; }
        body { margin: 0; padding: 28px 18px 60px; background: var(--bg); color: var(--fg);
               font: 15px/1.7 -apple-system, BlinkMacSystemFont, "Segoe UI", "PingFang SC", "Microsoft YaHei", sans-serif; }
        .wrap { max-width: 1080px; margin: 0 auto; }
        a { color: var(--accent); text-decoration: none; }
        a:hover { text-decoration: underline; }
        a:focus-visible { outline: 2px solid var(--accent); outline-offset: 2px; border-radius: 5px; }
        h1 { font-size: 24px; margin: 0 0 6px; }
        .lead { color: var(--muted); margin: 0 0 22px; font-size: 14px; }
        .crumb { font-size: 13px; color: var(--muted); margin-bottom: 14px; }
        /* v2.18.9：面包屑链接不能只靠颜色区分（axe link-in-text-block），常态加下划线 */
        .crumb a { text-decoration: underline; text-underline-offset: 2px; }
        section { margin-bottom: 26px; }
        h2 { font-size: 16px; margin: 0 0 10px; padding-bottom: 6px; border-bottom: 1px solid var(--line); }
        h2 .n { font-weight: 400; font-size: 12px; color: var(--muted); margin-left: 6px; }
        /* v2.18.16 提交1：响应式列数 —— 桌面 3 列(≥260px) / 1024-1280 为 2 列 / ≤760 为 1 列 */
        ul { list-style: none; margin: 0; padding: 0; display: grid; gap: 0 24px; grid-template-columns: repeat(3, minmax(260px, 1fr)); }
        @media (max-width: 1280px) { ul { grid-template-columns: repeat(2, minmax(260px, 1fr)); } }
        @media (max-width: 760px) { ul { grid-template-columns: 1fr; } }
        li { min-width: 0; }
        /* v2.18.11 P0-1 / P1-3 / P2-6：本页搜索条与首页共用一套胶囊尺寸与描边，
           站点名常态用正文色、hover/focus 才变蓝，四列基线对齐（列间距 24px）。 */
        .top { margin-bottom: 18px; display: flex; align-items: center; gap: 10px; flex-wrap: wrap; }
        .top label { margin: 0; color: var(--muted); font-size: 14px; }
        .u-search {
            position: relative;
            display: flex; align-items: center; gap: 6px;
            height: var(--a-search-h); padding: 3px 4px 3px 0;
            border: 1px solid var(--a-line-soft); border-radius: var(--a-radius-pill);
            background: var(--a-canvas);
        }
        /* v2.18.12：与首页同款放大镜 + 占位文字不重叠（图标右缘 + ≥10px） */
        .u-search .u-search-icon {
            position: absolute; left: 12px; top: 50%; transform: translateY(-50%);
            width: 18px; height: 18px; color: var(--a-muted); pointer-events: none;
        }
        .u-search input[type="search"] {
            flex: 1 1 auto; min-width: 0; height: 42px; border: 0; outline: 0;
            padding-left: 40px;
            font-size: 14px; color: var(--fg); background: transparent;
        }
        .u-search button {
            flex: 0 0 auto; height: 44px; padding: 0 20px; border: 0;
            border-radius: var(--a-radius-pill); background: var(--primary);
            color: #fff; font-size: 14px; font-weight: 600; cursor: pointer;
        }
        .u-search:focus-within { outline: 2px solid var(--a-focus); outline-offset: 2px; }
        body li a { color: var(--fg); text-decoration: none; }
        body li a:hover, body li a:focus-visible { color: #0066cc; text-decoration: underline; text-underline-offset: 2px; }
        html[data-theme="dark"] .u-search { border-color: rgba(255,255,255,.12); background: var(--a-tile-1); }
        html[data-theme="dark"] body li a:hover, html[data-theme="dark"] body li a:focus-visible { color: var(--a-link-on-dark); }
        /* v2.18.11 P1-4：内容页统一 header（与 about.php 同构：品牌块 + 44×44 图标按钮组，间距 8px） */
        .site-header { display: flex; align-items: center; justify-content: space-between; gap: 16px; margin-bottom: 24px; }
        .site-header .brand { display: inline-flex; align-items: center; gap: 12px; color: var(--fg); text-decoration: none; min-width: 0; }
        .site-header .brand-mark {
            display: grid; place-items: center; width: 52px; height: 52px; flex: 0 0 52px;
            border-radius: var(--a-radius-lg); background: var(--a-primary); color: #fff;
            font-size: 25px; font-weight: 700; overflow: hidden;
            box-shadow: var(--a-shadow-product);
        }
        .site-header .brand-mark img { width: 100%; height: 100%; object-fit: contain; }
        /* P0-2：有真实 logo 时去蓝底、40px 高自适应宽（与全站同款） */
        .site-header .brand-mark:has(img) { display: flex; align-items: center; width: auto; height: 40px; flex-basis: auto; background: transparent; }
        .site-header .brand-mark:has(img) img { position: static; width: auto; height: 40px; max-width: 200px; padding: 0; background: transparent; object-fit: contain; }
        html[data-theme="dark"] .site-header .brand-mark:has(img) img { filter: drop-shadow(0 0 1px rgba(255,255,255,.6)); }
        .site-header .brand-info { display: flex; flex-direction: column; min-width: 0; }
        .site-header .brand-title { margin: 0; font-size: var(--a-fs-title-lg); font-weight: 700; letter-spacing: -0.02em; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .site-header .brand-subtitle { color: var(--muted); font-size: var(--a-fs-fine); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .header-tools { display: flex; align-items: center; gap: 8px; flex: 0 0 auto; }
        .header-tools .tool-btn {
            display: inline-flex; align-items: center; justify-content: center;
            width: 44px; height: 44px; padding: 0; border: 1px solid var(--line);
            border-radius: var(--a-radius-md); background: transparent; color: var(--fg); cursor: pointer;
            text-decoration: none; transition: border-color .16s ease, background-color .16s ease;
        }
        .header-tools .tool-btn:hover { border-color: var(--accent); background: var(--primary-soft); }
        .header-tools .tool-btn svg { width: 19px; height: 19px; }
        .header-tools .tool-btn:active { transform: scale(.95); }
        .header-tools .icon-sun, .header-tools .theme-toggle[aria-pressed="true"] .icon-moon { display: none; }
        .header-tools .theme-toggle[aria-pressed="true"] .icon-sun { display: block; }
        @media (max-width: 560px) {
            .site-header .brand-subtitle { display: none; }
            .site-header .brand-mark { width: 44px; height: 44px; flex-basis: 44px; }
        }
        /* v2.18.15 · 提交4 视觉对齐：四列文字墙 → 18px 圆角分组容器 + hairline +
           20px 站标 + 描述单行截断；与首页分组卡同源表面语言。 */
        body section {
            margin-bottom: 24px;
            padding: 16px 18px;
            border: 1px solid var(--a-hairline);
            border-radius: var(--a-radius-lg);
            background: var(--a-canvas);
        }
        html[data-theme="dark"] body section {
            background: var(--a-tile-1);
            border-color: rgba(255, 255, 255, 0.12);
        }
        body h2 { font-size: var(--a-fs-title); border-bottom-color: var(--a-hairline); }
        body h2 .n { font-size: var(--a-fs-micro); }
        /* v2.18.16 提交1：每条 = 20px 站点图标 + 列内嵌套纵向 flex（名称行 / 描述行）；
           行高 56px，条目间 1px 内缩 hairline（left 12 / right 12），不再有蓝色外链箭头。 */
        body section { padding: 16px; }
        body ul { gap: 0 24px; }
        body li {
            display: flex; align-items: center; gap: 10px;
            height: 56px; padding: 0;
            position: relative; min-width: 0;
        }
        /* 内缩分隔线：除每组首条外画在顶部，left 12 / right 12 */
        body li + li::before {
            content: ''; position: absolute; top: 0; left: 12px; right: 12px;
            height: 1px; background: var(--a-hairline); pointer-events: none;
        }
        html[data-theme="dark"] body li + li::before { background: rgba(255,255,255,.12); }
        /* 20px 站点图标：复用首页 .site-icon 的取图逻辑（favicon img + 字母兜底块） */
        body li .site-icon {
            position: relative; display: grid; place-items: center;
            width: 20px; height: 20px; flex: 0 0 20px;
            overflow: hidden; border-radius: 5px; background: var(--a-surface-soft);
        }
        body li .site-icon-letter {
            position: absolute; inset: 0; display: grid; place-items: center;
            background: var(--fallback-bg, var(--a-surface-soft));
            color: var(--fallback-fg, var(--a-ink-48-strong));
            font-size: 11px; font-weight: 700; line-height: 1; user-select: none;
        }
        body li .site-favicon {
            position: absolute; inset: 0; display: block;
            width: 100%; height: 100%; padding: 0; object-fit: contain;
            opacity: 0; transition: opacity .15s ease;
        }
        body li .site-favicon.is-loaded { opacity: 1; }
        /* 列内嵌套纵向 flex：名称第一行、描述第二行，名称不再被描述挤压 */
        body li .si-body {
            display: flex; flex-direction: column; justify-content: center;
            min-width: 0; flex: 1 1 auto; gap: 2px;
        }
        body li a.site-name {
            display: block; max-width: 100%; min-width: 0;
            font-size: var(--a-fs-subhead); font-weight: 500; line-height: 1.3;
            white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
            color: var(--fg); text-decoration: none;
        }
        body li .d {
            display: block; max-width: 100%; min-width: 0;
            font-size: 13px; line-height: 1.3; color: #6e6e73;
            white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
        }
        html[data-theme="dark"] body li .d { color: var(--a-muted); }
        /* v2.19.0 P2-3：每条右侧「打开原站」外链图标，44×44 触控区，
           与站名同色（var(--fg)），左侧 1px hairline 与内容分隔，不引入测速逻辑。 */
        body li .site-open {
            flex: 0 0 44px; width: 44px; height: 44px;
            display: grid; place-items: center; margin-left: 2px;
            color: var(--fg); text-decoration: none; border-radius: 8px;
            border-left: 1px solid var(--a-hairline);
        }
        html[data-theme="dark"] body li .site-open { border-left-color: rgba(255,255,255,.12); }
        body li .site-open svg { width: 17px; height: 17px; }
        body h1 { font-size: var(--a-fs-display); }
        body .lead, body .top label, .u-search input[type="search"] { font-size: var(--a-fs-fine); }
    </style>
</head>
<body>
<div class="wrap">
    <header class="site-header">
        <a class="brand" href="index.php" aria-label="返回首页">
            <span class="brand-mark" aria-hidden="true">轩<?php if ($siteLogoUrl !== ''): ?><img src="<?php echo $functions->escape($siteLogoUrl); ?>" alt="" onerror="this.remove();"><?php endif; ?></span>
            <span class="brand-info">
                <span class="brand-title" translate="no"><?php echo $functions->escape($brandName); ?></span>
                <span class="brand-subtitle"><?php echo $functions->escape($brandSubtitle); ?></span>
            </span>
        </a>
        <div class="header-tools">
            <button type="button" class="tool-btn theme-toggle" id="themeToggle" aria-label="切换到深色模式" title="切换到深色模式" aria-pressed="false">
                <svg class="icon-moon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20.5 14.2A8.5 8.5 0 0 1 9.8 3.5 8.5 8.5 0 1 0 20.5 14.2Z"></path></svg>
                <svg class="icon-sun" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="4"></circle><path d="M12 2v2M12 20v2M4.9 4.9l1.4 1.4M17.7 17.7l1.4 1.4M2 12h2M20 12h2M4.9 19.1l1.4-1.4M17.7 6.3l1.4-1.4"></path></svg>
            </button>
            <a class="tool-btn" href="index.php" aria-label="返回首页" title="返回首页">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 10.5 12 3l9 7.5"></path><path d="M5 9.5V21h14V9.5"></path></svg>
            </a>
        </div>
    </header>
    <nav class="crumb" aria-label="面包屑"><a href="index.php">首页</a> › 全部站点</nav>
    <h1>全部网站索引</h1>
    <p class="lead">
        当前视图 <strong><?php echo $totalSites; ?></strong> 个站点，分 <strong><?php echo $totalCategories; ?></strong> 个分类。
        点站点名进入站内详情页（简介、标签、二维码）。
    </p>

    <form class="top" action="index.php" method="get" role="search">
        <label for="q">站内搜索</label>
        <span class="u-search">
            <svg class="u-search-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="11" cy="11" r="7"></circle><path d="m20 20-3.2-3.2"></path></svg>
            <input id="q" name="q" type="search" placeholder="输入网站名或域名">
            <button type="submit">搜索</button>
        </span>
    </form>

    <?php foreach ($categories as $category): ?>
        <section>
            <h2><?php echo $functions->escape($category['name']); ?><span class="n"><?php echo count($category['links']); ?> 个</span></h2>
            <ul>
                <?php foreach ($category['links'] as $row): ?>
                    <?php
                    $desc = trim(preg_replace('/\s+/u', ' ', (string) ($row['description'] ?? '')));
                    $siteName = trim((string) ($row['name'] ?? ''));
                    if ($siteName === '') { $siteName = (string) ($row['url'] ?? '未命名'); }
                    $iconChain = (array) ($row['icon_chain'] ?? []);
                    ?>
                    <li>
                        <span class="site-icon" aria-hidden="true">
                            <span class="site-icon-letter" style="--fallback-bg: <?php echo $functions->escape($row['icon_bg']); ?>; --fallback-fg: <?php echo $functions->escape($row['icon_fg']); ?>"><?php echo $functions->escape($row['icon_initial']); ?></span>
                            <img class="site-favicon" alt="" width="20" height="20" loading="lazy" referrerpolicy="no-referrer"
                                 data-src="<?php echo $functions->escape($iconChain[0] ?? ''); ?>"
                                 <?php if (isset($iconChain[1])): ?>data-fb1="<?php echo $functions->escape($iconChain[1]); ?>"<?php endif; ?>
                                 <?php if (isset($iconChain[2])): ?>data-fb2="<?php echo $functions->escape($iconChain[2]); ?>"<?php endif; ?>>
                        </span>
                        <span class="si-body">
                            <a class="site-name" href="detail.php?id=<?php echo (int) $row['id']; ?>" title="<?php echo $functions->escape($siteName); ?>"><?php echo $functions->escape($siteName); ?></a>
                            <?php if ($desc !== ''): ?>
                                <span class="d" title="<?php echo $functions->escape($desc); ?>"><?php echo $functions->escape($desc); ?></span>
                            <?php endif; ?>
                        </span>
                        <?php $siteOpenUrl = trim((string) ($row['url'] ?? '')); ?>
                        <?php if ($siteOpenUrl !== ''): ?>
                        <a class="site-open" href="<?php echo $functions->escape($siteOpenUrl); ?>"
                           target="_blank" rel="noopener noreferrer" aria-label="打开原站：<?php echo $functions->escape($siteName); ?>" title="打开原站">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                <path d="M14 4h6v6M20 4l-8.5 8.5"></path>
                                <path d="M18 13v5a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h5"></path>
                            </svg>
                        </a>
                        <?php endif; ?>
                    </li>
                <?php endforeach; ?>
            </ul>
        </section>
    <?php endforeach; ?>

    <p class="lead" style="margin-top:32px">
        <a href="index.php">← 返回首页</a>
        <?php if ($totalSites === 0): ?>· 暂无公开站点<?php endif; ?>
    </p>
</div>
<script>
    /* v2.18.16 提交1：站点图标 favicon 三级兜底（与首页 nav.js 同源逻辑）。
       加载成功加 .is-loaded 覆盖字母兜底块；失败逐级回退，全失败则保留字母块。 */
    (function () {
        function loadFavicon(img) {
            if (!img || img.getAttribute('data-fav-done') === '1') { return; }
            img.setAttribute('data-fav-done', '1');
            /* v2.19.2：读服务端写好的兜底链（本地缓存 → 原站 https/http → 可关闭的第三方），
               http 源统一升 https；失败只隐身不删节点，露出字母块（见 nav.js 文件头硬规则）。 */
            var chain = ['data-src', 'data-fb1', 'data-fb2']
                .map(function (k) { return (img.getAttribute(k) || '').trim(); })
                .filter(function (u) { return u !== ''; })
                .map(function (u) { return u.indexOf('http://') === 0 ? 'https://' + u.slice(7) : u; });
            if (!chain.length) { img.style.opacity = '0'; return; }
            var i = 0;
            function advance() {
                i++;
                if (i < chain.length) { img.src = chain[i]; }
                else { img.style.opacity = '0'; }   /* 全链失败：保留节点，露出字母块 */
            }
            img.addEventListener('load', function () {
                if (img.naturalWidth <= 1 || img.naturalHeight <= 1) { advance(); return; }
                img.classList.add('is-loaded');
                img.style.opacity = '';
            });
            img.addEventListener('error', advance);
            img.src = chain[i];
        }
        var imgs = document.querySelectorAll('.site-icon .site-favicon');
        if ('IntersectionObserver' in window) {
            var io = new IntersectionObserver(function (entries) {
                entries.forEach(function (e) {
                    if (!e.isIntersecting) { return; }
                    io.unobserve(e.target); loadFavicon(e.target);
                });
            }, { root: null, rootMargin: '200px 0px', threshold: 0 });
            imgs.forEach(function (im) { io.observe(im); });
        } else {
            imgs.forEach(function (im) { loadFavicon(im); });
        }
    })();
    /* v2.18.11 P1-4：统一 header 的主题切换（与首页 / 关于页同源） */
    (function () {
        var toggle = document.getElementById('themeToggle');
        if (!toggle) { return; }
        var dark = document.documentElement.getAttribute('data-theme') === 'dark';
        toggle.setAttribute('aria-pressed', dark ? 'true' : 'false');
        toggle.setAttribute('aria-label', dark ? '切换到浅色模式' : '切换到深色模式');
        toggle.addEventListener('click', function () {
            var next = document.documentElement.getAttribute('data-theme') === 'dark' ? 'light' : 'dark';
            document.documentElement.setAttribute('data-theme', next);
            toggle.setAttribute('aria-pressed', next === 'dark' ? 'true' : 'false');
            var meta = document.querySelector('meta[name="theme-color"]');
            if (meta) { meta.setAttribute('content', next === 'dark' ? '#000000' : '#ffffff'); }
            try { localStorage.setItem('xy-nav-theme', next); } catch (e) {}
        });
    })();
</script>
</body>
</html>
