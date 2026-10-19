<?php
/**
 * 轩宇-导航 · 前台首页（v2.0.0）
 * 视图来源：登录用户 → 自己的导航；未登录 → 后台指定的默认访客视图（默认为公共导航）。
 */
require_once __DIR__ . '/includes/bootstrap.php';
require_once 'includes/functions.php';
require_once 'includes/Services.php';

$functions = new Functions();
$users = new UserService($functions);
$engine = new CategoryEngine($functions);

$functions->recordVisit();

$currentUser = $users->currentUser();
$viewUserId = $currentUser ? (int) $currentUser['id'] : $users->defaultViewUserId();
$isOwner = $currentUser && (int) $currentUser['id'] === $viewUserId;
$engine->setUserId($viewUserId);

// 头像：登录用户显示自己的头像，访客显示管理员头像
$displayAvatar = '';
$displayAvatarInitial = $brandName !== '' ? mb_substr($brandName, 0, 1, 'UTF-8') : '轩';
if ($currentUser) {
    $displayAvatarInitial = mb_substr($currentUser['username'], 0, 1, 'UTF-8');
    if (!empty($currentUser['avatar'])) {
        $displayAvatar = $functions->getLogoDisplayUrl((string) $currentUser['avatar']);
    }
} else {
    // 访客：取管理员头像作为默认头像
    try {
        $adminStmt = $db->prepare('SELECT avatar, username FROM users WHERE is_admin = 1 ORDER BY id LIMIT 1');
        $adminStmt->execute();
        $adminRow = $adminStmt->fetch();
        if ($adminRow) {
            $displayAvatarInitial = mb_substr((string) $adminRow['username'], 0, 1, 'UTF-8');
            if (!empty($adminRow['avatar'])) {
                $displayAvatar = $functions->getLogoDisplayUrl((string) $adminRow['avatar']);
            }
        }
    } catch (Throwable $error) {
        // users 表不存在时忽略
    }
}

$siteTitle = trim((string) $functions->getSetting('site_title'));
$siteDescription = trim((string) $functions->getSetting('site_description'));
$siteKeywords = trim((string) $functions->getSetting('site_keywords'));
$siteLogoUrl = $functions->getLogoUrl($functions->getSetting('site_logo'));

$showICP = $functions->getSetting('show_icp');
$icpNumber = trim((string) $functions->getSetting('icp_number'));
$showGongan = $functions->getSetting('show_gongan');
$gonganNumber = trim((string) $functions->getSetting('gongan_number'));

$monitorEnabled = $functions->getSetting('monitor_enabled') !== '0';
$showSpeedLabel = $functions->getSetting('show_speed_label') !== '0';
$announcementEnabled = $functions->getSetting('announcement_enabled') === '1';
$announcementContent = trim((string) $functions->getSetting('announcement_content'));

$frontendTheme = (string) $functions->getSetting('frontend_theme');
if (!in_array($frontendTheme, ['system', 'light', 'dark'], true)) {
    $frontendTheme = 'system';
}

$defaultView = (string) $functions->getSetting('default_view');
if (!in_array($defaultView, ['grid', 'list', 'compact'], true)) {
    $defaultView = 'grid';
}

$defaultTarget = $functions->getSetting('default_target') === '_self' ? '_self' : '_blank';

$skin = (string) $functions->getSetting('theme_skin');
if (!in_array($skin, ['default', 'purple', 'orange', 'mint'], true)) {
    $skin = 'default';
}

$hotCount = (int) $functions->getSetting('hot_count');
$hotCount = ($hotCount >= 1 && $hotCount <= 24) ? $hotCount : 8;

$bgImage = trim((string) $functions->getSetting('bg_image'));
$seoTitle = trim((string) $functions->getSetting('seo_title'));
$seoDescription = trim((string) $functions->getSetting('seo_description'));

$brandName = $siteTitle !== '' ? $siteTitle : '轩宇-导航';
$brandSubtitle = $siteDescription !== '' ? $siteDescription : '一个简洁实用的导航网站';
$metaTitle = $seoTitle !== '' ? $seoTitle : $brandName . ' - ' . $brandSubtitle;
$metaDescription = $seoDescription !== '' ? $seoDescription : $brandSubtitle;
$currentYear = date('Y');

$brandInitial = $functions->textCut($brandName, 1);

$db = Database::getInstance()->getConnection();

/* ------------------------------ 数据准备 ------------------------------ */

/**
 * 取当前视图应展示的导航。
 * 如果老库还没执行 v2 升级脚本（缺少 user_id / deleted_at），自动退回公共导航查询，
 * 而不是让前台直接 500。
 */
$rawLinks = [];

try {
    $stmt = $db->prepare(
        "SELECT nl.*
         FROM nav_links nl
         WHERE nl.user_id = ? AND nl.is_active = 1 AND nl.deleted_at IS NULL
         ORDER BY COALESCE(nl.clicks, 0) DESC, nl.sort_order ASC, nl.id ASC"
    );
    $stmt->execute([$viewUserId]);
    $rawLinks = $stmt->fetchAll();
} catch (Throwable $error) {
    $viewUserId = 0;
    $isOwner = false;

    $stmt = $db->prepare(
        "SELECT nl.*
         FROM nav_links nl
         WHERE nl.is_active = 1
         ORDER BY COALESCE(nl.clicks, 0) DESC, nl.sort_order ASC, nl.id ASC"
    );
    $stmt->execute();
    $rawLinks = $stmt->fetchAll();
}

/**
 * 把一条记录整理成卡片所需字段。
 */
function navBuildCard(array $row, Functions $functions)
{
    $rawUrl = trim((string) ($row['url'] ?? ''));

    if ($rawUrl === '') {
        return null;
    }

    if (!preg_match('#^https?://#i', $rawUrl)) {
        $rawUrl = 'https://' . ltrim($rawUrl, '/');
    }

    $parts = parse_url($rawUrl);

    if (!$parts || empty($parts['host'])) {
        return null;
    }

    $host = $parts['host'];
    $scheme = strtolower($parts['scheme'] ?? 'https');

    if (!in_array($scheme, ['http', 'https'], true)) {
        $scheme = 'https';
    }

    $displayName = trim((string) ($row['name'] ?? ''));

    if ($displayName === '') {
        $displayName = $host;
    }

    $logoUrl = $functions->getLogoDisplayUrl($row['logo'] ?? '');
    $chain = [];

    if ($logoUrl !== '') {
        $chain[] = $logoUrl;
    }

    $chain[] = $scheme . '://' . $host . '/favicon.ico';
    $chain[] = 'https://api.iowen.cn/favicon/' . $host . '.png';
    $chain[] = 'https://www.google.com/s2/favicons?sz=64&domain=' . rawurlencode($host);
    $chain[] = 'https://icons.duckduckgo.com/ip3/' . $host . '.ico';

    $chain = array_values(array_unique(array_filter($chain, function ($url) {
        return is_string($url) && $url !== '';
    })));

    $initial = function_exists('mb_substr')
        ? mb_substr($displayName, 0, 1, 'UTF-8')
        : strtoupper(substr($displayName, 0, 1));

    if ($initial === '' || $initial === false) {
        $initial = '#';
    } elseif (preg_match('/^[a-z]$/', $initial)) {
        $initial = strtoupper($initial);
    }

    $row['safe_url'] = $rawUrl;
    $row['host'] = $host;
    $row['display_domain'] = preg_replace('/^www\./i', '', $host);
    $row['display_name'] = $displayName;
    $row['initial'] = $initial;
    $row['favicon_chain'] = $chain;
    $row['click_count'] = max(0, (int) ($row['clicks'] ?? 0));
    $row['check_status'] = (string) ($row['check_status'] ?? 'unknown');
    $row['latency_ms'] = isset($row['latency_ms']) ? (int) $row['latency_ms'] : null;

    return $row;
}

/** 把状态枚举映射为前端既有 class / 文案，保证与 nav.js 的探测结果一致。 */
function navStatusLabel($status)
{
    switch ($status) {
        case 'ok':
            return ['state' => 'online', 'label' => '在线'];
        case 'slow':
            return ['state' => 'online', 'label' => '在线（较慢）'];
        case 'down':
            return ['state' => 'offline', 'label' => '离线'];
        default:
            return ['state' => 'pending', 'label' => '检测中'];
    }
}

$links = [];
$linkCountByCategory = [];

foreach ($rawLinks as $row) {
    $card = navBuildCard($row, $functions);

    if ($card === null) {
        continue;
    }

    $links[] = $card;
    $categoryId = (int) ($card['category_id'] ?? 0);

    if (!isset($linkCountByCategory[$categoryId])) {
        $linkCountByCategory[$categoryId] = 0;
    }

    $linkCountByCategory[$categoryId]++;
}

$totalSites = count($links);

// 分类树：只保留有链接的分类，父分类计数含子分类。
$categoryRows = $engine->all();
$categoryById = [];

foreach ($categoryRows as $category) {
    $categoryById[(int) $category['id']] = $category;
}

$directCount = $linkCountByCategory;
$totalCount = $directCount;

foreach ($categoryById as $id => $category) {
    $parent = (int) ($category['parent_id'] ?? 0);

    if ($parent > 0 && isset($categoryById[$parent])) {
        $totalCount[$parent] = ($totalCount[$parent] ?? 0) + ($directCount[$id] ?? 0);
    }
}

$railGroups = [];

foreach ($categoryRows as $category) {
    $id = (int) $category['id'];

    if ((int) ($category['parent_id'] ?? 0) !== 0) {
        continue;
    }

    $children = [];

    foreach ($categoryRows as $child) {
        $childId = (int) $child['id'];

        if ((int) ($child['parent_id'] ?? 0) === $id && ($totalCount[$childId] ?? 0) > 0) {
            $children[] = [
                'id' => $childId,
                'name' => (string) $child['name'],
                'count' => (int) ($totalCount[$childId] ?? 0),
            ];
        }
    }

    $ownCount = (int) ($totalCount[$id] ?? 0);

    if ($ownCount <= 0 && !$children) {
        continue;
    }

    $railGroups[] = [
        'id' => $id,
        'name' => (string) $category['name'],
        'icon' => (string) ($category['icon'] ?? ''),
        'count' => $ownCount,
        'children' => $children,
    ];
}

// 分组卡片区：按分类排序输出，父分类下先放自身链接，再放子分类。
$sections = [];
$usedCategoryIds = [];

foreach ($railGroups as $group) {
    $groupCards = [];

    foreach ($links as $card) {
        if ((int) $card['category_id'] === $group['id']) {
            $groupCards[] = $card;
            $usedCategoryIds[(int) $card['id']] = true;
        }
    }

    $children = [];

    foreach ($group['children'] as $child) {
        $childCards = [];

        foreach ($links as $card) {
            if ((int) $card['category_id'] === $child['id']) {
                $childCards[] = $card;
                $usedCategoryIds[(int) $card['id']] = true;
            }
        }

        if ($childCards) {
            $children[] = ['name' => $child['name'], 'cards' => $childCards];
        }
    }

    if (!$groupCards && !$children) {
        continue;
    }

    $sections[] = [
        'id' => $group['id'],
        'name' => $group['name'],
        'count' => $group['count'],
        'cards' => $groupCards,
        'children' => $children,
    ];
}

// 分类已被删除但链接仍在的记录，单独归入「未分类」，避免丢失展示。
$orphanCards = [];

foreach ($links as $card) {
    if (!isset($usedCategoryIds[(int) $card['id']])) {
        $orphanCards[] = $card;
    }
}

if ($orphanCards) {
    $sections[] = [
        'id' => 0,
        'name' => '未分类',
        'count' => count($orphanCards),
        'cards' => $orphanCards,
        'children' => [],
    ];
}

// 热门访问 TopN（仅统计有点击量的站点）。
$hotLinks = [];

if ($hotCount > 0) {
    foreach ($links as $card) {
        if ($card['click_count'] > 0) {
            $hotLinks[] = $card;
        }

        if (count($hotLinks) >= $hotCount) {
            break;
        }
    }
}

$bodyStyle = '';

if ($bgImage !== '') {
    $bodyStyle = ' style="--bg-image: url(' . $functions->escape($bgImage) . ');"';
}
?>
<!DOCTYPE html>
<html lang="zh-CN" data-skin="<?php echo $functions->escape($skin); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title><?php echo $functions->escape($metaTitle); ?></title>
    <meta name="description" content="<?php echo $functions->escape($metaDescription); ?>">
    <meta name="keywords" content="<?php echo $functions->escape($siteKeywords); ?>">
    <meta name="author" content="<?php echo $functions->escape($brandName); ?>">
    <meta name="robots" content="index, follow">
    <meta name="theme-color" content="#f4f6fa" id="themeColor">
    <meta property="og:type" content="website">
    <meta property="og:title" content="<?php echo $functions->escape($metaTitle); ?>">
    <meta property="og:description" content="<?php echo $functions->escape($metaDescription); ?>">
    <meta name="format-detection" content="telephone=no">

    <?php if ($siteLogoUrl !== ''): ?>
        <link rel="icon" href="<?php echo $functions->escape($siteLogoUrl); ?>">
    <?php endif; ?>

    <script>
        (function () {
            var root = document.documentElement;
            var fallback = <?php echo json_encode($frontendTheme, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;

            try {
                var savedTheme = localStorage.getItem('xy-nav-theme');
                var theme = (savedTheme === 'dark' || savedTheme === 'light')
                    ? savedTheme
                    : ((fallback === 'dark' || fallback === 'light')
                        ? fallback
                        : (window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light'));
                root.setAttribute('data-theme', theme);

                var savedSkin = localStorage.getItem('xy-nav-skin');
                if (['default', 'purple', 'orange', 'mint'].indexOf(savedSkin) !== -1) {
                    root.setAttribute('data-skin', savedSkin);
                }

                var savedMode = localStorage.getItem('xy-nav-mode');
                if (['grid', 'list', 'compact'].indexOf(savedMode) !== -1) {
                    root.setAttribute('data-mode', savedMode);
                } else {
                    root.setAttribute('data-mode', <?php echo json_encode($defaultView); ?>);
                }
            } catch (e) {
                root.setAttribute('data-theme', 'light');
                root.setAttribute('data-mode', <?php echo json_encode($defaultView); ?>);
            }

            var meta = document.querySelector('meta[name="theme-color"]');
            if (meta) {
                meta.setAttribute('content', root.getAttribute('data-theme') === 'dark' ? '#0a1120' : '#f4f6fa');
            }
        })();
    </script>

    <link rel="stylesheet" href="assets/css/nav.css">
    <script src="assets/js/pinyin-pro.min.js" defer></script>
    <script src="assets/js/nav.js" defer></script>
    <noscript>
        <style>
            .site-status,
            .site-speed,
            .mode-switch,
            .float-bar {
                display: none !important;
            }
        </style>
    </noscript>
</head>
<body data-monitor="<?php echo $monitorEnabled ? '1' : '0'; ?>"
      data-speed-label="<?php echo $showSpeedLabel ? '1' : '0'; ?>"
      data-view="<?php echo (int) $viewUserId; ?>"
      data-owner="<?php echo $isOwner ? '1' : '0'; ?>"
      data-target="<?php echo $functions->escape($defaultTarget); ?>"<?php echo $bodyStyle; ?>>
    <a class="skip-link" href="#siteMain">跳到网站列表</a>

    <div class="page-shell">
        <header class="site-header">
            <div class="header-brand">
                <a class="brand" href="index.php" aria-label="<?php echo $functions->escape($brandName); ?> 首页">
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
                        <span class="brand-subtitle"><?php echo $functions->escape($brandSubtitle); ?></span>
                        <span class="brand-stats">
                            共 <strong><?php echo $totalSites; ?></strong> 个站点
                            <?php if ($isOwner && $currentUser): ?>· 我的导航<?php endif; ?>
                        </span>
                    </span>
                </a>
            </div>

            <div class="header-tools">
                <div class="search-field">
                    <svg class="search-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true">
                        <circle cx="11" cy="11" r="7"></circle>
                        <path d="m20 20-3.2-3.2"></path>
                    </svg>
                    <label class="visually-hidden" for="siteSearch">搜索网站</label>
                    <input type="search"
                           id="siteSearch"
                           class="search-input"
                           placeholder="搜索网站，支持拼音（按 / 聚焦）"
                           autocomplete="off"
                           spellcheck="false"
                           aria-label="搜索网站">
                    <button type="button"
                            id="searchClear"
                            class="search-clear"
                            aria-label="清空搜索"
                            title="清空搜索"
                            hidden>
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true">
                            <path d="m6 6 12 12M18 6 6 18"></path>
                        </svg>
                    </button>
                    <div class="search-engine" id="searchEngine">
                        <button type="button"
                                id="searchEngineToggle"
                                class="search-engine-toggle"
                                aria-label="选择搜索方式"
                                title="选择搜索方式"
                                aria-expanded="false"
                                aria-haspopup="menu">
                            <span id="searchEngineLabel">站内</span>
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                <path d="m6 9 6 6 6-6"></path>
                            </svg>
                        </button>
                        <div class="search-engine-menu" id="searchEngineMenu" role="menu" aria-label="搜索方式" hidden>
                            <button type="button" class="search-engine-option" role="menuitemradio" data-engine="site" aria-checked="true">
                                <span class="search-engine-name">站内搜索</span>
                                <span class="search-engine-desc">筛选已收录网站</span>
                            </button>
                            <button type="button" class="search-engine-option" role="menuitemradio" data-engine="baidu" aria-checked="false">
                                <span class="search-engine-name">百度</span>
                                <span class="search-engine-desc">新标签页打开</span>
                            </button>
                            <button type="button" class="search-engine-option" role="menuitemradio" data-engine="bing" aria-checked="false">
                                <span class="search-engine-name">必应</span>
                                <span class="search-engine-desc">新标签页打开</span>
                            </button>
                            <button type="button" class="search-engine-option" role="menuitemradio" data-engine="google" aria-checked="false">
                                <span class="search-engine-name">Google</span>
                                <span class="search-engine-desc">新标签页打开</span>
                            </button>
                        </div>
                    </div>
                </div>

                <div class="skin-picker" id="skinPicker">
                    <button type="button" class="tool-btn" id="skinToggle" aria-label="选择配色" title="选择配色" aria-expanded="false">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                            <circle cx="12" cy="12" r="9"></circle>
                            <path d="M12 3a9 9 0 0 0 0 18c1.2 0 1.8-.9 1.8-1.8 0-.5-.2-.9-.5-1.2-.3-.3-.5-.7-.5-1.2 0-1 .8-1.8 1.8-1.8H16a5 5 0 0 0 5-5c0-3.9-4-7-9-7z"></path>
                        </svg>
                    </button>
                    <div class="skin-menu" id="skinMenu" role="menu" aria-label="配色方案">
                        <?php
                        $skinOptions = [
                            'default' => '经典蓝',
                            'purple' => '静谧紫',
                            'orange' => '暖阳橙',
                            'mint' => '薄荷青',
                        ];
                        foreach ($skinOptions as $key => $label): ?>
                            <button type="button"
                                    class="skin-option<?php echo $skin === $key ? ' is-active' : ''; ?>"
                                    role="menuitemradio"
                                    aria-checked="<?php echo $skin === $key ? 'true' : 'false'; ?>"
                                    data-skin="<?php echo $key; ?>">
                                <span class="skin-dot" data-skin="<?php echo $key; ?>" aria-hidden="true"></span>
                                <?php echo $functions->escape($label); ?>
                            </button>
                        <?php endforeach; ?>
                    </div>
                </div>

                <div class="mode-switch" role="group" aria-label="显示方式">
                    <button type="button" class="mode-btn" data-mode="grid" aria-label="网格显示" title="网格显示">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" aria-hidden="true">
                            <rect x="3" y="3" width="7.5" height="7.5" rx="1.6"></rect>
                            <rect x="13.5" y="3" width="7.5" height="7.5" rx="1.6"></rect>
                            <rect x="3" y="13.5" width="7.5" height="7.5" rx="1.6"></rect>
                            <rect x="13.5" y="13.5" width="7.5" height="7.5" rx="1.6"></rect>
                        </svg>
                    </button>
                    <button type="button" class="mode-btn" data-mode="list" aria-label="列表显示" title="列表显示">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" aria-hidden="true">
                            <path d="M4 6h16M4 12h16M4 18h16"></path>
                        </svg>
                    </button>
                    <button type="button" class="mode-btn" data-mode="compact" aria-label="紧凑显示" title="紧凑显示">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" aria-hidden="true">
                            <path d="M4 7h6M4 12h6M4 17h6M14 7h6M14 12h6M14 17h6"></path>
                        </svg>
                    </button>
                </div>

                <button type="button"
                        id="themeToggle"
                        class="tool-btn theme-toggle"
                        aria-label="切换到深色模式"
                        title="切换到深色模式"
                        aria-pressed="false">
                    <svg class="icon-moon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                        <path d="M20.5 14.2A8.5 8.5 0 0 1 9.8 3.5 8.5 8.5 0 1 0 20.5 14.2Z"></path>
                    </svg>
                    <svg class="icon-sun" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                        <circle cx="12" cy="12" r="4"></circle>
                        <path d="M12 2v2M12 20v2M4.9 4.9l1.4 1.4M17.7 17.7l1.4 1.4M2 12h2M20 12h2M4.9 19.1l1.4-1.4M17.7 6.3l1.4-1.4"></path>
                    </svg>
                </button>

                <?php if ($currentUser): ?>
                    <details class="user-menu" id="userMenu">
                        <summary class="tool-btn user-summary">
                            <?php if ($displayAvatar !== ''): ?>
                                <img class="user-avatar-img" src="<?php echo $functions->escape($displayAvatar); ?>" alt="头像" onerror="this.style.display='none';this.nextElementSibling.style.display='grid';">
                                <span class="user-avatar" aria-hidden="true" style="display:none;"><?php echo $functions->escape($displayAvatarInitial); ?></span>
                            <?php else: ?>
                                <span class="user-avatar" aria-hidden="true"><?php echo $functions->escape($displayAvatarInitial); ?></span>
                            <?php endif; ?>
                            <span class="user-name"><?php echo $functions->escape($currentUser['username']); ?></span>
                            <svg class="user-caret" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true">
                                <path d="m6 9 6 6 6-6"></path>
                            </svg>
                        </summary>
                        <div class="user-dropdown">
                            <a class="user-item<?php echo $isOwner ? ' is-current' : ''; ?>" href="index.php">全部导航</a>
                            <a class="user-item" href="my.php">我的导航</a>
                            <?php if ((int) $currentUser['is_admin'] === 1): ?>
                                <a class="user-item" href="admin/index.php">管理后台</a>
                            <?php endif; ?>
                            <a class="user-item" href="about.php">关于本站</a>
                            <a class="user-item is-danger" href="logout.php">退出登录</a>
                        </div>
                    </details>
                <?php else: ?>
                    <div class="auth-links">
                        <?php if ($displayAvatar !== ''): ?>
                            <img class="user-avatar-img" src="<?php echo $functions->escape($displayAvatar); ?>" alt="管理员头像" style="width:34px;height:34px;border-radius:50%;object-fit:cover;margin-right:8px;">
                        <?php else: ?>
                            <span class="user-avatar" aria-hidden="true" style="margin-right:8px;"><?php echo $functions->escape($displayAvatarInitial); ?></span>
                        <?php endif; ?>
                        <a class="tool-btn tool-text" href="login.php">登录</a>
                        <a class="tool-btn tool-text is-primary" href="register.php">注册</a>
                    </div>
                <?php endif; ?>
            </div>
        </header>

        <?php if ($announcementEnabled && $announcementContent !== ''): ?>
            <div class="announce-bar" role="status" aria-label="站点公告">
                <span class="announce-tag">公告</span>
                <span class="announce-viewport">
                    <span class="announce-text"><?php echo $functions->escape($announcementContent); ?></span>
                </span>
            </div>
        <?php endif; ?>

        <div class="site-body">
            <aside class="category-rail" id="categoryRail" aria-label="网站分类">
                <div class="rail-title">
                    <span>分类索引</span>
                    <button type="button" class="rail-close" id="railClose" aria-label="收起分类">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="m6 6 12 12M18 6 6 18"></path></svg>
                    </button>
                </div>

                <nav class="rail-nav" id="railNav">
                    <button type="button" class="rail-item is-active" data-category="all" data-name="全部网站">
                        <span class="rail-label">全部</span>
                        <span class="rail-count"><?php echo $totalSites; ?></span>
                    </button>

                    <?php foreach ($railGroups as $group): ?>
                        <?php $groupId = (int) $group['id']; ?>
                        <div class="rail-group<?php echo $group['children'] ? ' has-children' : ''; ?>">
                            <button type="button"
                                    class="rail-item"
                                    data-category="<?php echo $groupId; ?>"
                                    data-name="<?php echo $functions->escape($group['name']); ?>"
                                    <?php echo $group['children'] ? 'aria-expanded="false"' : ''; ?>>
                                <span class="rail-label"><?php echo $functions->escape($group['name']); ?></span>
                                <span class="rail-count"><?php echo (int) $group['count']; ?></span>
                                <?php if ($group['children']): ?>
                                    <svg class="rail-caret" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true">
                                        <path d="m9 6 6 6-6 6"></path>
                                    </svg>
                                <?php endif; ?>
                            </button>

                            <?php if ($group['children']): ?>
                                <div class="rail-sub" hidden>
                                    <?php foreach ($group['children'] as $child): ?>
                                        <button type="button"
                                                class="rail-item is-sub"
                                                data-category="<?php echo (int) $child['id']; ?>"
                                                data-name="<?php echo $functions->escape($child['name']); ?>">
                                            <span class="rail-label"><?php echo $functions->escape($child['name']); ?></span>
                                            <span class="rail-count"><?php echo (int) $child['count']; ?></span>
                                        </button>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </nav>
            </aside>

            <div class="rail-backdrop" id="railBackdrop" hidden></div>

            <main class="site-main" id="siteMain">
                <div class="rail-bar">
                    <button type="button" class="rail-open" id="railOpen" aria-label="打开分类索引">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true">
                            <path d="M4 6h16M4 12h16M4 18h16"></path>
                        </svg>
                        分类
                    </button>
                    <span class="rail-current" id="railCurrent">全部</span>
                </div>

                <?php if ($hotLinks): ?>
                    <section class="hot-strip" aria-label="热门访问">
                        <h2 class="hot-title">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                <path d="M12 3c1.5 3.2.4 4.9-1 6.4-1.6 1.6-2.6 3-2.4 5 .2 2.4 2.2 4.1 4.4 4.1 3 0 5-2.3 5-5.3 0-4.4-3.5-7.5-6-10.2z"></path>
                            </svg>
                            热门访问
                        </h2>
                        <div class="hot-list">
                            <?php foreach ($hotLinks as $index => $hot): ?>
                                <a class="hot-item"
                                   href="<?php echo $functions->escape($hot['safe_url']); ?>"
                                   target="<?php echo $functions->escape($defaultTarget); ?>"
                                   rel="noopener noreferrer"
                                   data-id="<?php echo (int) $hot['id']; ?>"
                                   data-clicks="<?php echo (int) $hot['click_count']; ?>"
                                   title="<?php echo $functions->escape($hot['display_name'] . ' · ' . $hot['click_count'] . ' 次点击'); ?>">
                                    <span class="hot-rank"><?php echo $index + 1; ?></span>
                                    <span class="hot-name"><?php echo $functions->escape($hot['display_name']); ?></span>
                                    <span class="hot-count"><?php echo (int) $hot['click_count']; ?></span>
                                </a>
                            <?php endforeach; ?>
                        </div>
                    </section>
                <?php endif; ?>

                <div class="section-head">
                    <div class="section-title">
                        <h2 id="sectionTitle">全部网站</h2>
                        <span class="section-count" id="sectionCount" aria-live="polite"><?php echo $totalSites; ?> 个网站</span>
                    </div>
                    <div class="section-aside" id="sectionAside">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                            <path d="M12 5v14M7 14l5 5 5-5"></path>
                        </svg>
                        <span>按点击量排序</span>
                    </div>
                </div>

                <div class="link-sections" id="linkSections">
                    <?php foreach ($sections as $sectionIndex => $section): ?>
                        <section class="link-group"
                                 data-category="<?php echo (int) $section['id']; ?>"
                                 data-group-name="<?php echo $functions->escape($section['name']); ?>">
                            <header class="group-head">
                                <h3><?php echo $functions->escape($section['name']); ?></h3>
                                <span class="group-count" data-group-count="<?php echo (int) $section['id']; ?>"><?php echo (int) $section['count']; ?></span>
                            </header>

                            <?php if ($section['cards']): ?>
                                <div class="site-grid" data-grid-category="<?php echo (int) $section['id']; ?>">
                                    <?php foreach ($section['cards'] as $cardIndex => $link): ?>
                                        <?php
                                        $status = navStatusLabel($link['check_status']);
                                        $chain = $link['favicon_chain'];
                                        $clicks = $link['click_count'];
                                        ?>
                                        <a class="site-card"
                                           href="<?php echo $functions->escape($link['safe_url']); ?>"
                                           target="<?php echo $functions->escape($defaultTarget); ?>"
                                           rel="noopener noreferrer"
                                           style="--enter-index: <?php echo (int) ($cardIndex % 24); ?>;"
                                           aria-label="访问 <?php echo $functions->escape($link['display_name']); ?>（<?php echo $functions->escape($link['display_domain']); ?>）"
                                           data-id="<?php echo (int) $link['id']; ?>"
                                           data-category="<?php echo (int) $link['category_id']; ?>"
                                           data-name="<?php echo $functions->escape($link['display_name']); ?>"
                                           data-domain="<?php echo $functions->escape($link['display_domain']); ?>"
                                           data-host="<?php echo $functions->escape($link['host']); ?>"
                                           data-url="<?php echo $functions->escape($link['safe_url']); ?>"
                                           data-clicks="<?php echo $clicks; ?>"
                                           data-description="<?php echo $functions->escape((string) ($link['description'] ?? '')); ?>">
                                            <span class="site-status"
                                                  data-state="<?php echo $functions->escape($status['state']); ?>"
                                                  data-label="<?php echo $functions->escape($status['label']); ?>"
                                                  role="img"
                                                  aria-label="网站状态：<?php echo $functions->escape($status['label']); ?>">
                                                <span class="status-dot"></span>
                                            </span>

                                            <span class="site-card-main">
                                                <span class="site-icon" aria-hidden="true">
                                                    <span class="site-icon-letter"><?php echo $functions->escape($link['initial']); ?></span>
                                                    <img class="site-favicon"
                                                         alt=""
                                                         width="44"
                                                         height="44"
                                                         loading="lazy"
                                                         decoding="async"
                                                         referrerpolicy="no-referrer"
                                                         data-src="<?php echo $functions->escape($chain[0] ?? ''); ?>"
                                                         data-fb1="<?php echo $functions->escape($chain[1] ?? ''); ?>"
                                                         data-fb2="<?php echo $functions->escape($chain[2] ?? ''); ?>"
                                                         data-fb3="<?php echo $functions->escape($chain[3] ?? ''); ?>"
                                                         data-fb4="<?php echo $functions->escape($chain[4] ?? ''); ?>">
                                                </span>
                                                <span class="site-info">
                                                    <span class="site-name"><?php echo $functions->escape($link['display_name']); ?></span>
                                                    <span class="site-desc"><?php echo $functions->escape((string) ($link['description'] ?? '')); ?></span>
                                                </span>
                                            </span>

                                            <span class="site-meta">
                                                <span class="site-domain"><?php echo $functions->escape($link['display_domain']); ?></span>
                                                <span class="site-metrics">
                                                    <span class="site-clicks" title="累计点击 <?php echo number_format($clicks); ?> 次"><?php echo $clicks > 0 ? number_format($clicks) . ' 次' : '新收录'; ?></span>
                                                    <?php if ($monitorEnabled && $showSpeedLabel): ?>
                                                        <span class="site-speed"
                                                              data-state="<?php echo $link['check_status'] === 'unknown' ? 'pending' : 'ok'; ?>"
                                                              <?php if ($link['latency_ms']): ?>data-level="<?php echo $link['latency_ms'] < 300 ? 'fast' : ($link['latency_ms'] > 1000 ? 'slow' : 'mid'); ?>"<?php endif; ?>>
                                                            <?php
                                                            if ($link['latency_ms']) {
                                                                echo (int) $link['latency_ms'] . ' 毫秒';
                                                            } else {
                                                                echo '检测中';
                                                            }
                                                            ?>
                                                        </span>
                                                    <?php endif; ?>
                                                </span>
                                            </span>
                                        </a>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>

                            <?php foreach ($section['children'] as $child): ?>
                                <div class="group-subhead">
                                    <h4><?php echo $functions->escape($child['name']); ?></h4>
                                </div>
                                <div class="site-grid" data-grid-category="<?php echo (int) $child['cards'][0]['category_id']; ?>">
                                    <?php foreach ($child['cards'] as $cardIndex => $link): ?>
                                        <?php
                                        $status = navStatusLabel($link['check_status']);
                                        $chain = $link['favicon_chain'];
                                        $clicks = $link['click_count'];
                                        ?>
                                        <a class="site-card"
                                           href="<?php echo $functions->escape($link['safe_url']); ?>"
                                           target="<?php echo $functions->escape($defaultTarget); ?>"
                                           rel="noopener noreferrer"
                                           style="--enter-index: <?php echo (int) ($cardIndex % 24); ?>;"
                                           aria-label="访问 <?php echo $functions->escape($link['display_name']); ?>（<?php echo $functions->escape($link['display_domain']); ?>）"
                                           data-id="<?php echo (int) $link['id']; ?>"
                                           data-category="<?php echo (int) $link['category_id']; ?>"
                                           data-name="<?php echo $functions->escape($link['display_name']); ?>"
                                           data-domain="<?php echo $functions->escape($link['display_domain']); ?>"
                                           data-host="<?php echo $functions->escape($link['host']); ?>"
                                           data-url="<?php echo $functions->escape($link['safe_url']); ?>"
                                           data-clicks="<?php echo $clicks; ?>"
                                           data-description="<?php echo $functions->escape((string) ($link['description'] ?? '')); ?>">
                                            <span class="site-status"
                                                  data-state="<?php echo $functions->escape($status['state']); ?>"
                                                  data-label="<?php echo $functions->escape($status['label']); ?>"
                                                  role="img"
                                                  aria-label="网站状态：<?php echo $functions->escape($status['label']); ?>">
                                                <span class="status-dot"></span>
                                            </span>

                                            <span class="site-card-main">
                                                <span class="site-icon" aria-hidden="true">
                                                    <span class="site-icon-letter"><?php echo $functions->escape($link['initial']); ?></span>
                                                    <img class="site-favicon"
                                                         alt=""
                                                         width="44"
                                                         height="44"
                                                         loading="lazy"
                                                         decoding="async"
                                                         referrerpolicy="no-referrer"
                                                         data-src="<?php echo $functions->escape($chain[0] ?? ''); ?>"
                                                         data-fb1="<?php echo $functions->escape($chain[1] ?? ''); ?>"
                                                         data-fb2="<?php echo $functions->escape($chain[2] ?? ''); ?>"
                                                         data-fb3="<?php echo $functions->escape($chain[3] ?? ''); ?>"
                                                         data-fb4="<?php echo $functions->escape($chain[4] ?? ''); ?>">
                                                </span>
                                                <span class="site-info">
                                                    <span class="site-name"><?php echo $functions->escape($link['display_name']); ?></span>
                                                    <span class="site-desc"><?php echo $functions->escape((string) ($link['description'] ?? '')); ?></span>
                                                </span>
                                            </span>

                                            <span class="site-meta">
                                                <span class="site-domain"><?php echo $functions->escape($link['display_domain']); ?></span>
                                                <span class="site-metrics">
                                                    <span class="site-clicks" title="累计点击 <?php echo number_format($clicks); ?> 次"><?php echo $clicks > 0 ? number_format($clicks) . ' 次' : '新收录'; ?></span>
                                                    <?php if ($monitorEnabled && $showSpeedLabel): ?>
                                                        <span class="site-speed"
                                                              data-state="<?php echo $link['check_status'] === 'unknown' ? 'pending' : 'ok'; ?>"
                                                              <?php if ($link['latency_ms']): ?>data-level="<?php echo $link['latency_ms'] < 300 ? 'fast' : ($link['latency_ms'] > 1000 ? 'slow' : 'mid'); ?>"<?php endif; ?>>
                                                            <?php
                                                            if ($link['latency_ms']) {
                                                                echo (int) $link['latency_ms'] . ' 毫秒';
                                                            } else {
                                                                echo '检测中';
                                                            }
                                                            ?>
                                                        </span>
                                                    <?php endif; ?>
                                                </span>
                                            </span>
                                        </a>
                                    <?php endforeach; ?>
                                </div>
                            <?php endforeach; ?>
                        </section>
                    <?php endforeach; ?>
                </div>

                <div class="empty-state" id="emptyState"<?php echo $totalSites > 0 ? ' hidden' : ''; ?>>
                    <span class="empty-icon" aria-hidden="true">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" aria-hidden="true">
                            <circle cx="11" cy="11" r="7"></circle>
                            <path d="m20 20-3.2-3.2"></path>
                        </svg>
                    </span>
                    <h3 id="emptyTitle"><?php echo $totalSites > 0 ? '没有找到匹配的网站' : '暂无网站'; ?></h3>
                    <p id="emptyText"><?php echo $totalSites > 0 ? '试试其他关键词或分类。' : '请先在后台添加并启用网站。'; ?></p>
                    <?php if ($totalSites > 0): ?>
                        <button type="button" class="empty-reset" id="emptyReset">清除筛选</button>
                    <?php endif; ?>
                </div>
            </main>
        </div>

        <footer class="site-footer">
            <?php if (($showICP === '1' && $icpNumber !== '') || ($showGongan === '1' && $gonganNumber !== '')): ?>
                <div class="footer-legal">
                    <?php if ($showICP === '1' && $icpNumber !== ''): ?>
                        <a href="https://beian.miit.gov.cn/" target="_blank" rel="noopener noreferrer"><?php echo $functions->escape($icpNumber); ?></a>
                    <?php endif; ?>
                    <?php if ($showGongan === '1' && $gonganNumber !== ''): ?>
                        <a href="http://www.beian.gov.cn/" target="_blank" rel="noopener noreferrer"><?php echo $functions->escape($gonganNumber); ?></a>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <p class="copyright">
                &copy; <?php echo $currentYear; ?> <?php echo $functions->escape($brandName); ?>
                <span class="footer-sep">·</span>
                <a href="about.php">关于</a>
                <span class="footer-sep">·</span>
                <a href="contact.php">联系管理员</a>
                <span class="footer-sep">·</span>
                <a href="apply.php">申请收录</a>
            </p>
        </footer>
    </div>

    <div class="float-bar">
        <button type="button" class="float-btn" id="modeCycle" aria-label="切换显示方式" title="切换显示方式（网格 / 列表 / 紧凑）">
            <svg class="mode-icon-mode-grid" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" aria-hidden="true">
                <rect x="3" y="3" width="7.5" height="7.5" rx="1.6"></rect>
                <rect x="13.5" y="3" width="7.5" height="7.5" rx="1.6"></rect>
                <rect x="3" y="13.5" width="7.5" height="7.5" rx="1.6"></rect>
                <rect x="13.5" y="13.5" width="7.5" height="7.5" rx="1.6"></rect>
            </svg>
            <svg class="mode-icon-mode-list" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" aria-hidden="true">
                <path d="M4 6h16M4 12h16M4 18h16"></path>
            </svg>
            <svg class="mode-icon-mode-compact" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" aria-hidden="true">
                <path d="M4 7h6M4 12h6M4 17h6M14 7h6M14 12h6M14 17h6"></path>
            </svg>
        </button>
        <button type="button" class="float-btn to-top" id="toTop" aria-label="回到顶部" title="回到顶部">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                <path d="M12 19V5M6 11l6-6 6 6"></path>
            </svg>
        </button>
    </div>
</body>
</html>