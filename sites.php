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
require_once __DIR__ . '/includes/site_index_data.php';

$functions = new Functions();
$functions->recordVisit();

$brandName = trim((string) $functions->getSetting('site_title')) ?: '轩宇-导航';
$brandSubtitle = trim((string) $functions->getSetting('site_description')) ?: '一个简洁实用的导航网站';

$rows = navPublicIndexLinks($functions);
$categories = navIndexCategories($rows);

$totalSites = count($rows);
$totalCategories = count($categories);

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
    <style>
        :root { color-scheme: light dark; --fg: #1f2328; --muted: #6b7280; --line: #e5e7eb; --accent: #3457c5; --bg: #fff; }
        @media (prefers-color-scheme: dark) { :root { --fg: #e6e6e6; --muted: #9ca3af; --line: #2c2f36; --accent: #8ab4ff; --bg: #16181d; } }
        * { box-sizing: border-box; }
        body { margin: 0; padding: 28px 18px 60px; background: var(--bg); color: var(--fg);
               font: 15px/1.7 -apple-system, BlinkMacSystemFont, "Segoe UI", "PingFang SC", "Microsoft YaHei", sans-serif; }
        .wrap { max-width: 1080px; margin: 0 auto; }
        a { color: var(--accent); text-decoration: none; }
        a:hover { text-decoration: underline; }
        a:focus-visible { outline: 2px solid var(--accent); outline-offset: 2px; border-radius: 3px; }
        h1 { font-size: 24px; margin: 0 0 6px; }
        .lead { color: var(--muted); margin: 0 0 22px; font-size: 14px; }
        .crumb { font-size: 13px; color: var(--muted); margin-bottom: 14px; }
        section { margin-bottom: 26px; }
        h2 { font-size: 16px; margin: 0 0 10px; padding-bottom: 6px; border-bottom: 1px solid var(--line); }
        h2 .n { font-weight: 400; font-size: 12px; color: var(--muted); margin-left: 6px; }
        ul { list-style: none; margin: 0; padding: 0; display: grid; gap: 6px 20px; grid-template-columns: repeat(auto-fill, minmax(240px, 1fr)); }
        li { min-width: 0; }
        li a { display: inline-block; max-width: 100%; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; vertical-align: bottom; }
        li .d { color: var(--muted); font-size: 12.5px; display: block; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        .top { margin-bottom: 18px; }
    </style>
</head>
<body>
<div class="wrap">
    <nav class="crumb" aria-label="面包屑"><a href="index.php">首页</a> › 全部站点</nav>
    <h1>全部网站索引</h1>
    <p class="lead">
        共 <strong><?php echo $totalSites; ?></strong> 个站点，分 <strong><?php echo $totalCategories; ?></strong> 个分类。
        点站点名进入站内详情页（简介、标签、二维码），点右侧域名直达原站。
    </p>

    <form class="top" action="index.php" method="get" role="search">
        <label for="q" class="lead" style="display:inline-block;margin:0 8px 0 0">站内搜索</label>
        <input id="q" name="q" type="search" placeholder="输入网站名或域名" style="padding:6px 10px;border:1px solid var(--line);border-radius:6px;background:transparent;color:inherit">
        <button type="submit" style="padding:6px 14px;border:1px solid var(--line);border-radius:6px;background:transparent;color:inherit;cursor:pointer">搜索</button>
    </form>

    <?php foreach ($categories as $category): ?>
        <section>
            <h2><?php echo $functions->escape($category['name']); ?><span class="n"><?php echo count($category['links']); ?> 个</span></h2>
            <ul>
                <?php foreach ($category['links'] as $row): ?>
                    <?php $desc = trim(preg_replace('/\s+/u', ' ', (string) ($row['description'] ?? ''))); ?>
                    <li>
                        <a href="detail.php?id=<?php echo (int) $row['id']; ?>" title="<?php echo $functions->escape($row['name']); ?>"><?php echo $functions->escape($row['name']); ?></a>
                        <?php if ($desc !== ''): ?>
                            <span class="d"><?php echo $functions->escape(mb_substr($desc, 0, 42, 'UTF-8') . (mb_strlen($desc, 'UTF-8') > 42 ? '…' : '')); ?></span>
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
</body>
</html>
