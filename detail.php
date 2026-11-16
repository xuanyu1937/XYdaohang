<?php
/**
 * 轩宇-导航 · 网站详情
 */
require_once __DIR__ . '/includes/bootstrap.php';
require_once 'includes/functions.php';

$functions = new Functions();
$functions->recordVisit();

$siteTitle = $functions->getSetting('site_title');
$siteDescription = $functions->getSetting('site_description');
$siteLogo = $functions->getSetting('site_logo');
$siteLogoUrl = $functions->getLogoUrl($siteLogo);

$showICP = $functions->getSetting('show_icp');
$icpNumber = $functions->getSetting('icp_number');
$showGongan = $functions->getSetting('show_gongan');
$gonganNumber = $functions->getSetting('gongan_number');

$db = Database::getInstance()->getConnection();

$id = isset($_GET['id']) ? intval($_GET['id']) : 0;

if (!$id) {
    header('Location: index.php');
    exit;
}

$stmt = $db->prepare("
    SELECT n.*, c.name as category_name 
    FROM nav_links n 
    LEFT JOIN categories c ON n.category_id = c.id 
    WHERE n.id = ? AND n.is_active = 1
");
$stmt->execute([$id]);
$link = $stmt->fetch();

if (!$link) {
    header('Location: index.php');
    exit;
}

$updateStmt = $db->prepare("UPDATE nav_links SET clicks = COALESCE(clicks, 0) + 1 WHERE id = ?");
$updateStmt->execute([$id]);
$link['clicks'] = ($link['clicks'] ?? 0) + 1;

$relatedStmt = $db->prepare("
    SELECT * FROM nav_links 
    WHERE category_id = ? AND id != ? AND is_active = 1 
    ORDER BY COALESCE(clicks, 0) DESC 
    LIMIT 4
");
$relatedStmt->execute([$link['category_id'], $id]);
$relatedLinks = $relatedStmt->fetchAll();

$urlParts = parse_url($link['url']);
$domain = isset($urlParts['host']) ? $urlParts['host'] : $link['url'];

$detailCategory = trim((string) ($link['category_name'] ?? ''));
/* v2.18.3 · 标题补上分类，避免几百个详情页只有「名称 - 站名」这一种模板 */
$pageTitle = $link['name'] . ($detailCategory !== '' ? '（' . $detailCategory . '）' : '') . ' - ' . $siteTitle;

$detailDescRaw = trim(preg_replace('/\s+/u', ' ', (string) ($link['description'] ?? '')));

if ($detailDescRaw === '') {
    $detailDescRaw = $link['name'] . '是' . $siteTitle . '收录的' . ($detailCategory !== '' ? $detailCategory . '分类下' : '') . '网站，提供站点简介、访问地址与二维码，可直接跳转访问。';
}

$pageDescription = $detailDescRaw;

/* 站点简介普遍只有一句（十几个字），搜索结果里等于空描述：
   太短时补成「名称（分类）：简介。站点定位 + 能做什么」。 */
if (mb_strlen($pageDescription, 'UTF-8') < 60) {
    $pageDescription = $link['name']
        . ($detailCategory !== '' ? '（' . $detailCategory . '）' : '')
        . '：' . rtrim($detailDescRaw, '。')
        . '。' . $siteTitle . '收录的网址导航条目，含站点简介、标签、访问地址与二维码。';
}

$pageDescription = mb_strlen($pageDescription, 'UTF-8') > 155
    ? mb_substr($pageDescription, 0, 155, 'UTF-8') . '…'
    : $pageDescription;

$detailBase = navSiteBaseUrl();
$detailUrl = $detailBase . '/detail.php?id=' . (int) $link['id'];
$detailLogoUrl = $functions->getLogoDisplayUrl((string) ($link['logo'] ?? ''));
$detailOgImage = $detailLogoUrl !== '' ? $detailLogoUrl : $detailBase . '/assets/img/icons/icon-512.png';

/* 结构化数据：面包屑 + 网页实体。详情页是目录站唯一有独立内容的页面，
   标好层级搜索引擎才知道「首页 › 分类 › 具体站点」的关系。 */
$detailBreadcrumb = [
    ['@type' => 'ListItem', 'position' => 1, 'name' => '首页', 'item' => $detailBase . '/'],
];

if ($detailCategory !== '') {
    $detailBreadcrumb[] = ['@type' => 'ListItem', 'position' => 2, 'name' => $detailCategory, 'item' => $detailBase . '/index.php'];
}

$detailBreadcrumb[] = ['@type' => 'ListItem', 'position' => count($detailBreadcrumb) + 1, 'name' => $link['name'], 'item' => $detailUrl];

$detailJsonLd = [
    '@context' => 'https://schema.org',
    '@graph' => [
        [
            '@type' => 'WebPage',
            '@id' => $detailUrl,
            'url' => $detailUrl,
            'name' => $pageTitle,
            'description' => $pageDescription,
            'inLanguage' => 'zh-CN',
            'isPartOf' => ['@id' => $detailBase . '/#website'],
            'breadcrumb' => ['@id' => $detailUrl . '#breadcrumb'],
            'primaryImageOfPage' => ['@type' => 'ImageObject', 'url' => $detailOgImage],
            'about' => [
                '@type' => 'WebSite',
                'name' => $link['name'],
                'url' => $link['url'],
            ],
        ],
        [
            '@type' => 'BreadcrumbList',
            '@id' => $detailUrl . '#breadcrumb',
            'itemListElement' => $detailBreadcrumb,
        ],
    ],
];

/* v2.18.11 P1-2：统一 header 需要的站点数，与首页同源 */
$detailTotalStmt = $db->query("SELECT COUNT(*) FROM nav_links WHERE is_active = 1");
$detailTotalSites = (int) $detailTotalStmt->fetchColumn();
?>
<!DOCTYPE html>
<html lang="zh-CN" data-skin="default">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title><?php echo $functions->escape($pageTitle); ?></title>
    <meta name="description" content="<?php echo $functions->escape($pageDescription); ?>">
    <meta name="keywords" content="<?php echo $functions->escape($link['name']); ?>,网站导航,网址推荐">
    <meta name="author" content="<?php echo $functions->escape($siteTitle); ?>">
    <meta name="robots" content="index, follow">
    <link rel="canonical" href="<?php echo $functions->escape($detailUrl); ?>">
    <meta property="og:type" content="article">
    <meta property="og:title" content="<?php echo $functions->escape($pageTitle); ?>">
    <meta property="og:description" content="<?php echo $functions->escape($pageDescription); ?>">
    <meta property="og:url" content="<?php echo $functions->escape($detailUrl); ?>">
    <meta property="og:site_name" content="<?php echo $functions->escape($siteTitle); ?>">
    <meta property="og:image" content="<?php echo $functions->escape($detailOgImage); ?>">
    <meta name="twitter:card" content="summary">
    <meta name="twitter:title" content="<?php echo $functions->escape($pageTitle); ?>">
    <meta name="twitter:description" content="<?php echo $functions->escape($pageDescription); ?>">
    <script type="application/ld+json"><?php echo json_encode($detailJsonLd, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP); ?></script>
    <meta name="theme-color" content="#ffffff" id="themeColor">
    <?php if ($siteLogoUrl !== ''): ?>
        <link rel="icon" href="<?php echo $functions->escape($siteLogoUrl); ?>">
    <?php endif; ?>
    <script>
        /* v2.18.11 P1-3 / P2-4：与首页共用 early script，写 data-theme 并同步 theme-color */
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
            } catch (e) {}
        })();
    </script>
    <link rel="stylesheet" href="assets/css/apple-tokens.css?v=2.18.11">
    <link rel="stylesheet" href="assets/css/nav.css?v=2.18.11">
    <link rel="stylesheet" href="assets/css/page.css?v=2.18.11">
    <script src="assets/js/jquery3.7.1.js"></script>
    <?php require_once __DIR__ . '/includes/asset_fallbacks.php'; ?>
    <script src="assets/js/qrcode.min.js"></script>
    <style>
        /* v2.18.11 P1-2：详情页全部走 design token，无内联 hex、无阴影，
           圆角只用 11 / 18 / 999；图标一律内联 SVG。 */
        .detail-card { padding: 28px 30px 30px; }
        .detail-hero { display: flex; align-items: flex-start; gap: 22px; }
        .detail-logo {
            width: 100px; height: 100px; flex: 0 0 100px;
            display: grid; place-items: center; overflow: hidden;
            border: 1px solid var(--a-hairline);
            border-radius: var(--a-radius-lg); background: var(--surface-2);
        }
        html[data-theme="dark"] .detail-logo { border-color: rgba(255,255,255,.12); }
        .detail-logo img { width: 100%; height: 100%; object-fit: contain; }
        .detail-logo-letter { color: var(--primary); font-size: var(--a-fs-section); font-weight: 700; }
        .detail-head { min-width: 0; flex: 1 1 auto; }
        .detail-name { margin: 0 0 8px; color: var(--text); font-size: var(--a-fs-heading); font-weight: 700; letter-spacing: -0.02em; }
        .detail-facts { display: flex; flex-wrap: wrap; gap: 6px 16px; color: var(--muted); font-size: var(--a-fs-caption); }
        .detail-facts span { display: inline-flex; align-items: center; gap: 5px; }
        .detail-facts svg { width: 14px; height: 14px; }
        .detail-desc {
            margin: 22px 0 0; padding: 16px;
            border-radius: var(--a-radius-md); background: var(--surface-soft);
            color: var(--text-2); font-size: 14px; line-height: 1.6;
        }
        .detail-tags { display: flex; flex-wrap: wrap; gap: 8px; margin-top: 16px; }
        .detail-tag {
            display: inline-flex; align-items: center; gap: 5px;
            padding: 5px 12px; border: 1px solid var(--a-hairline);
            border-radius: var(--a-radius-pill); background: var(--surface);
            color: var(--muted); font-size: var(--a-fs-fine);
        }
        html[data-theme="dark"] .detail-tag { border-color: rgba(255,255,255,.12); }
        .detail-tag svg { width: 12px; height: 12px; }
        .detail-actions { display: flex; flex-wrap: wrap; gap: 10px; margin-top: 22px; }
        .detail-btn {
            display: inline-flex; align-items: center; justify-content: center; gap: 8px;
            height: 44px; padding: 0 18px; border: 1px solid var(--a-hairline);
            border-radius: var(--a-radius-md); background: var(--surface);
            color: var(--text-2); font-size: 14px; font-weight: 600; font-family: inherit;
            text-decoration: none; cursor: pointer;
            transition: border-color .16s ease, background-color .16s ease, color .16s ease;
        }
        html[data-theme="dark"] .detail-btn { border-color: rgba(255,255,255,.12); }
        .detail-btn:hover { border-color: var(--primary-border); background: var(--primary-soft); color: var(--link); }
        .detail-btn:active { transform: scale(.95); }
        .detail-btn svg { width: 17px; height: 17px; }
        .detail-btn.is-primary { border-color: var(--primary); background: var(--primary); color: var(--a-on-primary); }
        .detail-btn.is-primary:hover { border-color: var(--primary-hover); background: var(--primary-hover); color: var(--a-on-primary); }
        .detail-url {
            display: flex; align-items: center; gap: 12px;
            margin-top: 24px; padding-top: 20px; border-top: 1px solid var(--a-hairline);
        }
        html[data-theme="dark"] .detail-url { border-top-color: rgba(255,255,255,.12); }
        .detail-url-icon {
            width: 40px; height: 40px; flex: 0 0 40px; overflow: hidden;
            display: grid; place-items: center; border-radius: var(--a-radius-sm);
            border: 1px solid var(--a-hairline); background: var(--surface-2);
        }
        html[data-theme="dark"] .detail-url-icon { border-color: rgba(255,255,255,.12); }
        .detail-url-icon img { width: 100%; height: 100%; object-fit: contain; }
        .detail-url-label { margin: 0 0 2px; color: var(--muted); font-size: var(--a-fs-fine); }
        .detail-url a { color: var(--link); font-size: 14px; word-break: break-all; }
        .detail-related { margin-top: 40px; }
        .section-head { display: flex; align-items: baseline; gap: 8px; margin: 0 0 16px; }
        .section-head h2 { margin: 0; color: var(--text); font-size: 15px; font-weight: 600; }
        .section-head .n { color: var(--muted); font-size: 12px; }
        .detail-related-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(240px, 1fr)); gap: 12px; }
        .rel-card {
            display: flex; gap: 12px; padding: 14px; text-decoration: none;
            border: 1px solid var(--a-hairline); border-radius: var(--a-radius-md);
            background: transparent; transition: border-color .16s ease;
        }
        html[data-theme="dark"] .rel-card { border-color: rgba(255,255,255,.12); }
        .rel-card:hover { border-color: var(--primary-border); }
        .rel-logo {
            width: 44px; height: 44px; flex: 0 0 44px; overflow: hidden;
            display: grid; place-items: center; border-radius: var(--a-radius-sm);
            border: 1px solid var(--a-hairline); background: var(--surface-2);
        }
        html[data-theme="dark"] .rel-logo { border-color: rgba(255,255,255,.12); }
        .rel-logo img { width: 100%; height: 100%; object-fit: contain; }
        .rel-logo-letter { color: var(--primary); font-size: var(--a-fs-title); font-weight: 700; }
        .rel-body { min-width: 0; flex: 1 1 auto; }
        .rel-name { display: block; overflow: hidden; color: var(--text); font-size: 14px; font-weight: 600; text-overflow: ellipsis; white-space: nowrap; }
        .rel-desc { display: -webkit-box; -webkit-box-orient: vertical; -webkit-line-clamp: 2; line-clamp: 2; overflow: hidden; margin: 2px 0 4px; color: var(--muted); font-size: 12px; line-height: 1.45; }
        .rel-meta { color: var(--muted); font-size: 12px; }
        .qrcode-modal {
            display: none; position: fixed; inset: 0; z-index: 1000;
            align-items: center; justify-content: center; background: rgba(0,0,0,.5);
        }
        .qrcode-content {
            max-width: 320px; padding: 28px; border-radius: var(--a-radius-lg);
            background: var(--surface); color: var(--text); text-align: center;
        }
        .qrcode-content h3 { margin: 0 0 16px; font-size: var(--a-fs-title); font-weight: 700; }
        .qrcode-content p { margin: 14px 0 18px; color: var(--muted); font-size: 14px; }
        .qrcode-stage { display: inline-block; padding: 14px; border-radius: var(--a-radius-md); background: var(--surface-soft); }
        .toast-message {
            position: fixed; top: 20px; left: 50%; transform: translateX(-50%);
            z-index: 9999; display: none; padding: 12px 24px;
            border-radius: var(--a-radius-pill); background: var(--a-ink); color: var(--a-white);
            font-size: 14px;
        }
        #backToTop {
            position: fixed; right: 24px; bottom: 24px; z-index: 50;
            width: 44px; height: 44px; display: none; align-items: center; justify-content: center;
            border: 1px solid var(--a-hairline); border-radius: var(--a-radius-md);
            background: var(--surface); color: var(--text-2); cursor: pointer;
        }
        html[data-theme="dark"] #backToTop { border-color: rgba(255,255,255,.12); }
        #backToTop svg { width: 19px; height: 19px; }
        @media (max-width: 640px) {
            .detail-card { padding: 20px 18px 24px; }
            .detail-hero { flex-direction: column; gap: 16px; }
            .detail-logo { width: 72px; height: 72px; flex-basis: 72px; }
            .detail-actions .detail-btn { flex: 1 1 45%; }
        }
    </style>
</head>
<body data-monitor="0">
    <a class="skip-link" href="#detailMain">跳到正文</a>
    <div id="toast" class="toast-message" role="status" aria-live="polite"></div>

    <div class="page-shell">
        <header class="site-header">
            <div class="header-brand">
                <a class="brand" href="index.php" aria-label="返回首页">
                    <span class="brand-mark" aria-hidden="true">轩<?php if ($siteLogoUrl !== ''): ?><img src="<?php echo $functions->escape($siteLogoUrl); ?>" alt="" onerror="this.remove();"><?php endif; ?></span>
                    <span class="brand-info">
                        <span class="brand-title" translate="no"><?php echo $functions->escape($siteTitle); ?></span>
                        <span class="brand-subtitle">共 <?php echo (int) $detailTotalSites; ?> 个站点</span>
                    </span>
                </a>
            </div>
            <div class="header-tools">
                <button type="button" class="tool-btn theme-toggle" id="themeToggle" aria-label="切换到深色模式" title="切换到深色模式" aria-pressed="false">
                    <svg class="icon-moon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20.5 14.2A8.5 8.5 0 0 1 9.8 3.5 8.5 8.5 0 1 0 20.5 14.2Z"></path></svg>
                    <svg class="icon-sun" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="4"></circle><path d="M12 1.8v2.5M12 19.7v2.5M4.2 4.2l1.8 1.8M18 18l1.8 1.8M1.8 12h2.5M19.7 12h2.5M4.2 19.8 6 18M18 6l1.8-1.8"></path></svg>
                </button>
                <a class="tool-btn" href="index.php" aria-label="返回首页" title="返回首页">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 10.5 12 3l9 7.5"></path><path d="M5 9.5V21h14V9.5"></path></svg>
                </a>
            </div>
        </header>

        <main class="page-main" id="detailMain">
            <!-- v2.18.11 P1-4：面包屑只放在内容区顶部，不替代 header -->
            <nav class="crumb" aria-label="面包屑">
                <a href="index.php">首页</a> ›
                <a href="sites.php"><?php echo $functions->escape($link['category_name'] ?: '全部分类'); ?></a> ›
                <span><?php echo $functions->escape($link['name']); ?></span>
            </nav>

            <section class="page-card detail-card">
                <div class="detail-hero">
                    <?php if (!empty($detailLogoUrl)): ?>
                        <div class="detail-logo"><img src="<?php echo $functions->escape($detailLogoUrl); ?>" alt="<?php echo $functions->escape($link['name']); ?>" onerror="this.style.display='none';this.nextElementSibling.style.display='grid';"><span class="detail-logo-letter" style="display:none;"><?php echo $functions->escape(mb_substr($link['name'], 0, 1, 'UTF-8')); ?></span></div>
                    <?php else: ?>
                        <div class="detail-logo"><span class="detail-logo-letter"><?php echo $functions->escape(mb_substr($link['name'], 0, 1, 'UTF-8')); ?></span></div>
                    <?php endif; ?>
                    <div class="detail-head">
                        <h1 class="detail-name"><?php echo $functions->escape($link['name']); ?></h1>
                        <div class="detail-facts">
                            <span><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M10 13a5 5 0 0 0 7.5.5l3-3a5 5 0 0 0-7-7l-1.7 1.7"></path><path d="M14 11a5 5 0 0 0-7.5-.5l-3 3a5 5 0 0 0 7 7l1.7-1.7"></path></svg><?php echo $functions->escape($domain); ?></span>
                            <span><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M2 12s3.6-7 10-7 10 7 10 7-3.6 7-10 7-10-7-10-7Z"></path><circle cx="12" cy="12" r="3"></circle></svg><?php echo number_format($link['clicks'] ?? 0); ?> 次浏览</span>
                            <span><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="5" width="18" height="16" rx="2.5"></rect><path d="M8 3v4M16 3v4M3 11h18"></path></svg><?php echo date('Y-m-d', strtotime($link['created_at'])); ?></span>
                        </div>
                    </div>
                </div>

                <?php if (!empty($link['description'])): ?>
                    <div class="detail-desc"><?php echo nl2br($functions->escape($link['description'])); ?></div>
                <?php endif; ?>

                <?php if ($link['category_name'] || parse_url($link['url'], PHP_URL_SCHEME)): ?>
                    <div class="detail-tags">
                        <?php if ($link['category_name']): ?>
                            <span class="detail-tag"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 7a2 2 0 0 1 2-2h5l2 2h7a2 2 0 0 1 2 2v9a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2Z"></path></svg><?php echo $functions->escape($link['category_name']); ?></span>
                        <?php endif; ?>
                        <?php $scheme = parse_url($link['url'], PHP_URL_SCHEME); if ($scheme): ?>
                            <span class="detail-tag"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 3 4 6v6c0 5 3.4 8.3 8 9 4.6-.7 8-4 8-9V6Z"></path></svg><?php echo strtoupper($scheme); ?></span>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>

                <div class="detail-actions">
                    <a href="<?php echo $functions->escape($link['url']); ?>" target="_blank" rel="noopener noreferrer" class="detail-btn is-primary">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"></path><path d="M15 3h6v6M10 14 21 3"></path></svg>访问网站
                    </a>
                    <button type="button" class="detail-btn" onclick="shareWebsite()">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="18" cy="5" r="3"></circle><circle cx="6" cy="12" r="3"></circle><circle cx="18" cy="19" r="3"></circle><path d="m8.6 13.5 6.8 4M15.4 6.5 8.6 10.5"></path></svg>分享
                    </button>
                    <button type="button" class="detail-btn" onclick="showQRCode()">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="3" width="7" height="7" rx="1"></rect><rect x="14" y="3" width="7" height="7" rx="1"></rect><rect x="3" y="14" width="7" height="7" rx="1"></rect><path d="M14 14h3v3M21 14v7M14 21h3M17 17h4"></path></svg>手机访问
                    </button>
                    <button type="button" class="detail-btn" onclick="copyUrl()">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="9" y="9" width="13" height="13" rx="2"></rect><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"></path></svg>复制链接
                    </button>
                </div>

                <div class="detail-url">
                    <?php if (!empty($detailLogoUrl)): ?>
                        <span class="detail-url-icon"><img src="<?php echo $functions->escape($detailLogoUrl); ?>" alt="" onerror="this.remove();"></span>
                    <?php else: ?>
                        <span class="detail-url-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" style="width:20px;height:20px;color:var(--primary)"><circle cx="12" cy="12" r="9"></circle><path d="M3 12h18M12 3a15 15 0 0 1 0 18M12 3a15 15 0 0 0 0 18"></path></svg></span>
                    <?php endif; ?>
                    <div>
                        <p class="detail-url-label">网站地址</p>
                        <a href="<?php echo $functions->escape($link['url']); ?>" target="_blank" rel="noopener noreferrer"><?php echo $functions->escape($link['url']); ?></a>
                    </div>
                </div>
            </section>

            <?php if (!empty($relatedLinks)): ?>
                <section class="detail-related">
                    <div class="section-head">
                        <h2>相关推荐</h2>
                        <span class="n"><?php echo count($relatedLinks); ?> 个网站</span>
                    </div>
                    <div class="detail-related-grid">
                        <?php foreach ($relatedLinks as $related): ?>
                            <?php $relLogo = $functions->getLogoDisplayUrl($related['logo']); ?>
                            <a class="rel-card" href="detail.php?id=<?php echo (int) $related['id']; ?>">
                                <span class="rel-logo">
                                    <?php if (!empty($relLogo)): ?>
                                        <img src="<?php echo $functions->escape($relLogo); ?>" alt="" onerror="this.style.display='none';this.nextElementSibling.style.display='grid';">
                                        <span class="rel-logo-letter" style="display:none;"><?php echo $functions->escape(mb_substr($related['name'], 0, 1, 'UTF-8')); ?></span>
                                    <?php else: ?>
                                        <span class="rel-logo-letter"><?php echo $functions->escape(mb_substr($related['name'], 0, 1, 'UTF-8')); ?></span>
                                    <?php endif; ?>
                                </span>
                                <span class="rel-body">
                                    <span class="rel-name"><?php echo $functions->escape($related['name']); ?></span>
                                    <span class="rel-desc"><?php echo $functions->escape($related['description'] ?: '暂无描述'); ?></span>
                                    <span class="rel-meta"><?php echo number_format($related['clicks'] ?? 0); ?> 次访问</span>
                                </span>
                            </a>
                        <?php endforeach; ?>
                    </div>
                </section>
            <?php endif; ?>
        </main>

        <footer class="site-footer">
            <p class="copyright">
                &copy; <?php echo date('Y'); ?> <?php echo $functions->escape($siteTitle); ?>
                <?php if ($showICP == '1' && $icpNumber): ?> · <a href="https://beian.miit.gov.cn/" target="_blank" rel="noopener noreferrer"><?php echo $functions->escape($icpNumber); ?></a><?php endif; ?>
                <?php if ($showGongan == '1' && $gonganNumber): ?> · <a href="http://www.beian.gov.cn/" target="_blank" rel="noopener noreferrer"><?php echo $functions->escape($gonganNumber); ?></a><?php endif; ?>
                · <a href="index.php">首页</a>
            </p>
        </footer>
    </div>

    <button id="backToTop" aria-label="回到顶部" onclick="scrollToTop()">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 19V5M5 12l7-7 7 7"></path></svg>
    </button>

    <div class="qrcode-modal" id="qrcodeModal" onclick="closeQRCode(event)">
        <div class="qrcode-content" onclick="event.stopPropagation()">
            <h3>手机扫码访问</h3>
            <div class="qrcode-stage"><div id="qrcode"></div></div>
            <p>使用手机扫描二维码即可访问</p>
            <button type="button" class="detail-btn" onclick="closeQRCode()">关闭</button>
        </div>
    </div>

    <script>
        $(document).ready(function() {
            new QRCode(document.getElementById("qrcode"), {
                text: "<?php echo $functions->escape($link['url']); ?>",
                width: 200, height: 200,
                colorDark: "#000000", colorLight: "#ffffff",
                correctLevel: QRCode.CorrectLevel.H
            });
            document.querySelectorAll('#qrcode img').forEach(function (qrImg) {
                if (!qrImg.hasAttribute('alt')) { qrImg.setAttribute('alt', '网址二维码'); }
            });
            $(window).scroll(function() {
                if ($(this).scrollTop() > 300) { $('#backToTop').css('display', 'inline-flex'); }
                else { $('#backToTop').css('display', 'none'); }
            });
        });
        function showToast(message) {
            $('#toast').text(message).stop(true, true).fadeIn();
            setTimeout(function() { $('#toast').fadeOut(); }, 2000);
        }
        function shareWebsite() {
            if (navigator.share) {
                navigator.share({
                    title: '<?php echo $functions->escape($link['name']); ?>',
                    text: '<?php echo $functions->escape($link['description'] ?: $link['name']); ?>',
                    url: '<?php echo $functions->escape($link['url']); ?>'
                }).catch(function () { copyUrl(); });
            } else { copyUrl(); showToast('链接已复制，可以分享给朋友啦！'); }
        }
        function copyUrl() {
            var url = '<?php echo $functions->escape($link['url']); ?>';
            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(url).then(function(){ showToast('链接已复制到剪贴板'); }).catch(fallbackCopy);
            } else { fallbackCopy(); }
            function fallbackCopy() {
                var ta = document.createElement('textarea');
                ta.value = url; document.body.appendChild(ta); ta.select();
                try { document.execCommand('copy'); } catch (e) {}
                document.body.removeChild(ta); showToast('链接已复制到剪贴板');
            }
        }
        function showQRCode() { $('#qrcodeModal').css('display', 'flex'); }
        function closeQRCode(event) {
            if (!event || event.target === event.currentTarget) { $('#qrcodeModal').fadeOut(); }
        }
        function scrollToTop() { $('html, body').animate({ scrollTop: 0 }, 300); }
        $(document).keydown(function(e) { if (e.keyCode === 27) { $('#qrcodeModal').fadeOut(); } });

        /* v2.18.11 P1-4：统一 header 主题切换 */
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
