<?php
/**
 * 轩宇-导航 · 我的导航（前台用户个人导航管理）
 */
require_once __DIR__ . '/includes/bootstrap.php';
require_once 'includes/functions.php';
require_once 'includes/Services.php';

$functions = new Functions();
$users = new UserService($functions);

if (!$users->isLoggedIn()) {
    $functions->redirect('login.php?redirect=my.php');
}

$user = $users->currentUser();

if (!$user) {
    $functions->redirect('login.php?redirect=my.php');
}

$userId = (int) $user['id'];
$db = Database::getInstance()->getConnection();
$engine = new CategoryEngine($functions);
$engine->setUserId($userId);

// 头像上传处理
$avatarMessage = '';
$avatarError = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_avatar'])) {
    $functions->requireSameOrigin();

    $action = (string) ($_POST['avatar_action'] ?? 'upload');

    if ($action === 'remove') {
        if ($users->updateAvatar($userId, '')) {
            $avatarMessage = '头像已清除';
        } else {
            $avatarError = '清除头像失败，请稍后重试';
        }
    } elseif (!empty($_FILES['avatar_file']['name'])) {
        $file = $_FILES['avatar_file'];

        if ($file['error'] !== UPLOAD_ERR_OK) {
            $avatarError = '上传失败，请重试';
        } else {
            $allowed = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/gif' => 'gif', 'image/webp' => 'webp'];
            $finfo = function_exists('finfo_open') ? finfo_open(FILEINFO_MIME_TYPE) : null;
            $mimeType = $finfo ? finfo_file($finfo, $file['tmp_name']) : ($file['type'] ?? '');
            if ($finfo) {
                finfo_close($finfo);
            }

            if (!isset($allowed[$mimeType])) {
                $avatarError = '仅支持 JPG / PNG / GIF / WebP 格式';
            } elseif ($file['size'] > 2 * 1024 * 1024) {
                $avatarError = '头像大小不能超过 2MB';
            } else {
                $uploadDir = __DIR__ . '/uploads/avatars';
                if (!is_dir($uploadDir)) {
                    @mkdir($uploadDir, 0755, true);
                }

                $ext = $allowed[$mimeType];
                $fileName = 'avatar_' . $userId . '_' . time() . '.' . $ext;
                $destPath = $uploadDir . '/' . $fileName;

                if (move_uploaded_file($file['tmp_name'], $destPath)) {
                    $relativePath = 'uploads/avatars/' . $fileName;
                    if ($users->updateAvatar($userId, $relativePath)) {
                        $avatarMessage = '头像更新成功';
                        $user = $users->findById($userId); // 刷新用户信息
                    } else {
                        @unlink($destPath);
                        $avatarError = '保存头像失败，请稍后重试';
                    }
                } else {
                    $avatarError = '保存头像失败，请检查目录权限';
                }
            }
        }
    } else {
        $avatarError = '请选择要上传的头像图片';
    }
}

$brandName = trim((string) $functions->getSetting('site_title'));
$brandName = $brandName !== '' ? $brandName : '轩宇-导航';
$siteLogoUrl = $functions->getLogoUrl($functions->getSetting('site_logo'));
$frontendTheme = (string) $functions->getSetting('frontend_theme');

if (!in_array($frontendTheme, ['system', 'light', 'dark'], true)) {
    $frontendTheme = 'system';
}

$brandInitial = function_exists('mb_substr')
    ? mb_substr($brandName, 0, 1, 'UTF-8')
    : strtoupper(substr($brandName, 0, 1));

$categories = $engine->all();

$schemaWarning = '';

$stmt = null;
$rawLinks = [];

try {
    $stmt = $db->prepare(
        'SELECT id, name, url, logo, description, category_id, sort_order, clicks, check_status, latency_ms
         FROM nav_links
         WHERE user_id = ? AND deleted_at IS NULL
         ORDER BY sort_order, id'
    );
    $stmt->execute([$userId]);
    $rawLinks = $stmt->fetchAll();
} catch (Throwable $error) {
    // 老库尚未执行 v2 升级脚本时没有 user_id / deleted_at，个人导航无法使用
    $schemaWarning = '数据库尚未升级到 v2 结构，个人导航暂不可用。请先执行 upgrade_v2.0.0.sql。';
    $rawLinks = [];
}

/**
 * 取 favicon 兜底链。
 */
$faviconFor = function ($logoPath, $url) use ($functions) {
    $chain = [];
    $logoUrl = $functions->getLogoDisplayUrl((string) $logoPath);

    if ($logoUrl !== '') {
        $chain[] = $logoUrl;
    }

    $host = parse_url((string) $url, PHP_URL_HOST);

    if ($host) {
        $chain[] = 'https://icons.duckduckgo.com/ip3/' . $host . '.ico';
        $chain[] = 'https://www.google.com/s2/favicons?sz=64&domain=' . rawurlencode($host);
    }

    return $chain;
};

// 按分类分组，保持分类排序。
$groups = [];

foreach ($categories as $category) {
    $groups[(int) $category['id']] = ['id' => (int) $category['id'], 'name' => (string) $category['name'], 'icon' => (string) ($category['icon'] ?? ''), 'items' => []];
}

$ungrouped = ['id' => 0, 'name' => '未分类', 'icon' => 'bi-collection', 'items' => []];
$totalLinks = 0;

foreach ($rawLinks as $row) {
    $url = trim((string) $row['url']);

    if ($url === '') {
        continue;
    }

    $categoryId = (int) $row['category_id'];
    $name = trim((string) $row['name']);
    $host = parse_url($url, PHP_URL_HOST);
    $host = $host ? preg_replace('/^www\./i', '', $host) : $url;

    if ($name === '') {
        $name = $host;
    }

    $initial = function_exists('mb_substr')
        ? mb_substr($name, 0, 1, 'UTF-8')
        : strtoupper(substr($name, 0, 1));

    if ($initial === '' || $initial === false) {
        $initial = '?';
    } elseif (preg_match('/^[a-z]$/', $initial)) {
        $initial = strtoupper($initial);
    }

    $item = [
        'id' => (int) $row['id'],
        'name' => $name,
        'url' => $url,
        'host' => $host,
        'initial' => $initial,
        'description' => (string) ($row['description'] ?? ''),
        'category_id' => $categoryId,
        'favicons' => $faviconFor($row['logo'] ?? '', $url),
    ];

    if (isset($groups[$categoryId])) {
        $groups[$categoryId]['items'][] = $item;
    } else {
        $ungrouped['items'][] = $item;
    }

    $totalLinks++;
}

$visibleGroups = [];

foreach ($groups as $group) {
    if ($group['items']) {
        $visibleGroups[] = $group;
    }
}

if ($ungrouped['items']) {
    $visibleGroups[] = $ungrouped;
}

$hasLinks = $totalLinks > 0;
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <meta name="robots" content="noindex,nofollow">
    <title>我的导航 - <?php echo $functions->escape($brandName); ?></title>
    <script>
        (function () {
            try {
                var saved = localStorage.getItem('xy-nav-theme');
                var fallback = <?php echo json_encode($frontendTheme, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
                var theme = (saved === 'dark' || saved === 'light')
                    ? saved
                    : ((fallback === 'dark' || fallback === 'light')
                        ? fallback
                        : (window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light'));
                document.documentElement.setAttribute('data-theme', theme);
            } catch (e) {
                document.documentElement.setAttribute('data-theme', 'light');
            }
        })();
    </script>
    <link rel="stylesheet" href="assets/css/nav.css">
    <style>
        /* ------------------------------ 我的导航页 ------------------------------ */
        .my-main {
            flex: 1 0 auto;
            padding: 24px 0 72px;
        }

        .my-head {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 16px;
            margin-bottom: 18px;
            flex-wrap: wrap;
        }

        .my-head-title {
            display: flex;
            align-items: baseline;
            gap: 10px;
            min-width: 0;
        }

        .my-head-title h2 {
            display: flex;
            align-items: center;
            gap: 9px;
            margin: 0;
            font-size: 19px;
            font-weight: 700;
            letter-spacing: -0.02em;
        }

        .my-head-title h2::before {
            width: 6px;
            height: 6px;
            border-radius: 50%;
            background: var(--primary);
            box-shadow: 0 0 0 3px var(--primary-soft);
            content: "";
        }

        .my-head-count {
            color: var(--muted);
            font-size: 12px;
        }

        .my-actions {
            display: flex;
            align-items: center;
            gap: 8px;
            flex-wrap: wrap;
        }

        .my-btn {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            height: 38px;
            padding: 0 14px;
            border: 1px solid var(--border);
            border-radius: 10px;
            background: var(--surface);
            color: var(--text-2);
            font-size: 13px;
            font-weight: 500;
            font-family: inherit;
            box-shadow: var(--shadow-sm);
            transition: border-color 0.16s ease, background-color 0.16s ease, color 0.16s ease, transform 0.16s ease;
        }

        .my-btn:hover {
            border-color: var(--primary-border);
            background: var(--primary-soft);
            color: var(--primary);
            transform: translateY(-1px);
        }

        .my-btn svg {
            width: 15px;
            height: 15px;
        }

        .my-btn.is-primary {
            border-color: var(--primary);
            background: var(--primary);
            color: var(--primary-contrast);
            box-shadow: 0 8px 18px -10px rgba(29, 78, 216, 0.7);
        }

        .my-btn.is-primary:hover {
            border-color: var(--primary-filled-hover);
            background: var(--primary-filled-hover);
            color: var(--primary-contrast);
        }

        .my-btn.is-ghost {
            border-color: transparent;
            background: transparent;
            box-shadow: none;
            color: var(--muted);
        }

        .my-btn.is-ghost:hover {
            background: var(--surface-2);
            color: var(--text);
        }

        .my-btn[aria-disabled="true"] {
            opacity: 0.5;
            pointer-events: none;
        }

        /* 用户条 */
        .my-userbar {
            display: flex;
            align-items: center;
            gap: 10px;
            min-width: 0;
        }

        .my-avatar {
            display: grid;
            place-items: center;
            width: 34px;
            height: 34px;
            flex: 0 0 34px;
            border-radius: 10px;
            background: linear-gradient(135deg, var(--primary), #7c3aed);
            color: #fff;
            font-size: 14px;
            font-weight: 700;
        }

        .my-avatar-img {
            width: 34px;
            height: 34px;
            flex: 0 0 34px;
            border-radius: 10px;
            object-fit: cover;
        }

        .my-avatar-edit {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 24px;
            height: 24px;
            border: none;
            border-radius: 50%;
            background: var(--surface-2);
            color: var(--text-2);
            cursor: pointer;
            font-size: 11px;
            transition: background 0.16s ease, color 0.16s ease;
        }

        .my-avatar-edit:hover {
            background: var(--primary-soft);
            color: var(--primary);
        }

        .my-username {
            min-width: 0;
            overflow: hidden;
            color: var(--text);
            font-size: 13px;
            font-weight: 600;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        /* 分组 */
        .my-group {
            margin-bottom: 26px;
        }

        .my-group-head {
            display: flex;
            align-items: center;
            gap: 9px;
            margin-bottom: 10px;
            color: var(--text);
        }

        .my-group-head h3 {
            margin: 0;
            font-size: 14.5px;
            font-weight: 600;
        }

        .my-group-head .my-group-count {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-width: 20px;
            height: 20px;
            padding: 0 6px;
            border-radius: 999px;
            background: var(--tab-bg);
            color: var(--muted);
            font-size: 11px;
            font-weight: 600;
        }

        .my-list {
            border: 1px solid var(--border);
            border-radius: var(--radius);
            background: var(--surface);
            box-shadow: var(--shadow-sm);
            overflow: hidden;
        }

        .my-item {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 11px 14px;
            border-bottom: 1px solid var(--border);
            background: var(--surface);
            transition: background-color 0.16s ease, opacity 0.16s ease;
        }

        .my-item:last-child {
            border-bottom: 0;
        }

        .my-item:hover {
            background: var(--surface-2);
        }

        .my-item.is-dragging {
            opacity: 0.45;
        }

        .my-item.is-drop-target {
            box-shadow: inset 0 2px 0 var(--primary);
        }

        .my-handle {
            display: grid;
            place-items: center;
            width: 20px;
            height: 26px;
            flex: 0 0 20px;
            border: 0;
            background: transparent;
            color: var(--muted-2);
            cursor: grab;
            padding: 0;
        }

        .my-handle:active {
            cursor: grabbing;
        }

        .my-handle svg {
            width: 15px;
            height: 15px;
        }

        .my-icon {
            position: relative;
            display: grid;
            place-items: center;
            width: 34px;
            height: 34px;
            flex: 0 0 34px;
            overflow: hidden;
            border: 1px solid var(--border);
            border-radius: 9px;
            background: var(--surface-2);
        }

        .my-icon-letter {
            color: var(--primary);
            font-size: 14px;
            font-weight: 700;
            line-height: 1;
        }

        .my-icon img {
            position: absolute;
            inset: 0;
            width: 100%;
            height: 100%;
            padding: 4px;
            background: var(--surface);
            object-fit: contain;
        }

        .my-info {
            min-width: 0;
            flex: 1 1 auto;
        }

        .my-link-name {
            display: block;
            overflow: hidden;
            color: var(--text);
            font-size: 14px;
            font-weight: 600;
            line-height: 1.4;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        .my-link-url {
            display: block;
            margin-top: 1px;
            overflow: hidden;
            color: var(--muted);
            font-size: 12px;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        .my-item-actions {
            display: flex;
            align-items: center;
            gap: 4px;
            flex: 0 0 auto;
        }

        .my-iconbtn {
            display: grid;
            place-items: center;
            width: 34px;
            height: 34px;
            padding: 0;
            border: 1px solid transparent;
            border-radius: 9px;
            background: transparent;
            color: var(--muted);
            transition: border-color 0.16s ease, background-color 0.16s ease, color 0.16s ease;
        }

        .my-iconbtn:hover {
            border-color: var(--border);
            background: var(--surface);
            color: var(--text);
        }

        .my-iconbtn.is-danger:hover {
            border-color: rgba(220, 38, 38, 0.32);
            background: var(--danger-soft);
            color: var(--danger);
        }

        .my-iconbtn svg {
            width: 16px;
            height: 16px;
        }

        /* 分享选择模式 */
        .my-picker {
            display: none;
            place-items: center;
            width: 22px;
            height: 22px;
            flex: 0 0 22px;
        }

        body[data-picking="1"] .my-picker {
            display: grid;
        }

        .my-picker input {
            width: 16px;
            height: 16px;
            accent-color: var(--primary);
        }

        .my-sharebar {
            position: fixed;
            left: 50%;
            bottom: 22px;
            z-index: 30;
            display: none;
            align-items: center;
            gap: 14px;
            padding: 12px 16px;
            border: 1px solid var(--border-strong);
            border-radius: 14px;
            background: var(--surface);
            box-shadow: var(--shadow-lg);
            transform: translateX(-50%);
        }

        body[data-picking="1"] .my-sharebar {
            display: flex;
        }

        .my-sharebar-text {
            color: var(--text-2);
            font-size: 13px;
            white-space: nowrap;
        }

        .my-sharebar-text strong {
            color: var(--primary);
        }

        /* 空状态 */
        .my-empty {
            padding: 56px 24px;
            border: 1px dashed var(--border-strong);
            border-radius: var(--radius);
            background: var(--surface);
            text-align: center;
        }

        .my-empty h3 {
            margin: 0 0 6px;
            font-size: 15px;
            font-weight: 600;
        }

        .my-empty p {
            margin: 0 0 18px;
            color: var(--muted);
            font-size: 12.5px;
        }

        /* 弹窗 */
        dialog.my-dialog {
            width: min(520px, calc(100vw - 32px));
            padding: 0;
            border: 1px solid var(--border);
            border-radius: 16px;
            background: var(--surface);
            color: var(--text);
            box-shadow: var(--shadow-lg);
        }

        dialog.my-dialog::backdrop {
            background: rgba(8, 14, 26, 0.55);
            backdrop-filter: blur(2px);
        }

        .my-dialog-head {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            padding: 16px 20px;
            border-bottom: 1px solid var(--border);
        }

        .my-dialog-head h3 {
            margin: 0;
            font-size: 15px;
            font-weight: 700;
        }

        .my-dialog-body {
            padding: 20px;
            max-height: min(70vh, 560px);
            overflow-y: auto;
            overscroll-behavior: contain;
        }

        .my-dialog-foot {
            display: flex;
            align-items: center;
            justify-content: flex-end;
            gap: 9px;
            padding: 14px 20px;
            border-top: 1px solid var(--border);
        }

        .my-field {
            margin-bottom: 14px;
        }

        .my-field label {
            display: block;
            margin-bottom: 6px;
            color: var(--text-2);
            font-size: 12.5px;
            font-weight: 600;
        }

        .my-field input,
        .my-field select,
        .my-field textarea {
            width: 100%;
            padding: 9px 12px;
            border: 1px solid var(--border-strong);
            border-radius: 10px;
            background: var(--surface-2);
            color: var(--text);
            font-size: 13.5px;
            font-family: inherit;
            outline: none;
            transition: border-color 0.16s ease, box-shadow 0.16s ease;
        }

        .my-field textarea {
            min-height: 74px;
            resize: vertical;
        }

        .my-field input:focus,
        .my-field select:focus,
        .my-field textarea:focus {
            border-color: var(--primary);
            background: var(--surface);
            box-shadow: 0 0 0 3px var(--primary-soft);
        }

        .my-field-row {
            display: flex;
            gap: 8px;
        }

        .my-field-row input {
            flex: 1 1 auto;
        }

        .my-note {
            margin: 8px 0 0;
            color: var(--muted);
            font-size: 11.5px;
            line-height: 1.6;
        }

        .my-msg {
            display: none;
            margin: 0 0 14px;
            padding: 10px 12px;
            border-radius: 10px;
            font-size: 12.5px;
            line-height: 1.6;
        }

        .my-msg.is-error {
            display: block;
            border: 1px solid rgba(220, 38, 38, 0.3);
            background: var(--danger-soft);
            color: var(--danger);
        }

        .my-msg.is-success {
            display: block;
            border: 1px solid rgba(15, 157, 88, 0.3);
            background: var(--success-soft);
            color: var(--success);
        }

        .my-share-result {
            display: flex;
            gap: 8px;
        }

        .my-share-result input {
            flex: 1 1 auto;
        }

        .my-file-input {
            padding: 14px;
            border: 1px dashed var(--border-strong);
            border-radius: 10px;
            background: var(--surface-2);
        }

        .my-share-list {
            display: flex;
            flex-direction: column;
            gap: 9px;
        }

        .my-share-row {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 11px 12px;
            border: 1px solid var(--border);
            border-radius: 10px;
            background: var(--surface-2);
        }

        .my-share-row-main {
            min-width: 0;
            flex: 1 1 auto;
        }

        .my-share-row-title {
            display: block;
            overflow: hidden;
            font-size: 13px;
            font-weight: 600;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        .my-share-row-meta {
            display: block;
            margin-top: 2px;
            color: var(--muted);
            font-size: 11.5px;
        }

        .my-sr {
            position: absolute;
            width: 1px;
            height: 1px;
            padding: 0;
            margin: -1px;
            overflow: hidden;
            clip: rect(0 0 0 0);
            white-space: nowrap;
            border: 0;
        }

        @media (max-width: 720px) {
            .my-item {
                gap: 9px;
                padding: 10px 11px;
            }

            .my-handle {
                display: none;
            }

            .my-btn .my-btn-label {
                display: none;
            }

            .my-btn {
                padding: 0 11px;
            }

            .my-sharebar {
                left: 12px;
                right: 12px;
                bottom: 12px;
                transform: none;
                justify-content: space-between;
            }
        }
    </style>
</head>
<body data-picking="0">
    <a class="skip-link" href="#myMain">跳到我的网址</a>

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
                    <div class="brand-info">
                        <h1 class="brand-title" translate="no"><?php echo $functions->escape($brandName); ?></h1>
                        <span class="brand-subtitle">我的导航</span>
                    </div>
                </a>
            </div>

            <div class="header-tools">
                <div class="my-userbar">
                    <?php
                    $userAvatar = trim((string) ($user['avatar'] ?? ''));
                    $avatarUrl = $userAvatar !== '' ? $functions->getLogoDisplayUrl($userAvatar) : '';
                    ?>
                    <?php if ($avatarUrl !== ''): ?>
                        <img class="my-avatar-img" src="<?php echo $functions->escape($avatarUrl); ?>" alt="头像" onerror="this.style.display='none';this.nextElementSibling.style.display='flex';">
                        <span class="my-avatar" aria-hidden="true" style="display:none;"><?php echo $functions->escape($brandInitial); ?></span>
                    <?php else: ?>
                        <span class="my-avatar" aria-hidden="true"><?php echo $functions->escape($brandInitial); ?></span>
                    <?php endif; ?>
                    <span class="my-username" title="<?php echo $functions->escape($user['username']); ?>"><?php echo $functions->escape($user['username']); ?></span>
                    <button type="button" class="my-avatar-edit" id="avatarEditBtn" title="设置头像">
                        <i class="fas fa-camera"></i>
                    </button>
                </div>
                <button type="button" class="theme-toggle" id="themeToggle" aria-label="切换深浅色" title="切换深浅色">
                    <svg class="icon-moon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                        <path d="M20.5 14.2A8.5 8.5 0 0 1 9.8 3.5 8.5 8.5 0 1 0 20.5 14.2Z"></path>
                    </svg>
                    <svg class="icon-sun" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                        <circle cx="12" cy="12" r="4"></circle>
                        <path d="M12 2v2M12 20v2M4.9 4.9l1.4 1.4M17.7 17.7l1.4 1.4M2 12h2M20 12h2M4.9 19.1l1.4-1.4M17.7 6.3l1.4-1.4"></path>
                    </svg>
                </button>
                <a class="my-btn is-ghost" href="index.php">返回首页</a>
                <a class="my-btn is-ghost" href="logout.php">退出登录</a>
            </div>
        </header>

        <main class="my-main" id="myMain">
            <?php if ($schemaWarning !== ''): ?>
                <p class="my-msg is-error" role="alert" style="display:block;"><?php echo $functions->escape($schemaWarning); ?></p>
            <?php endif; ?>

            <div class="my-head">
                <div class="my-head-title">
                    <h2>我的网址</h2>
                    <span class="my-head-count" id="linkCount"><?php echo $totalLinks; ?> 个网址 · <?php echo count($visibleGroups); ?> 个分组</span>
                </div>

                <div class="my-actions">
                    <button type="button" class="my-btn is-primary" id="addBtn">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true">
                            <path d="M12 5v14M5 12h14"></path>
                        </svg>
                        <span class="my-btn-label">添加网址</span>
                    </button>
                    <button type="button" class="my-btn" id="importBtn">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                            <path d="M12 3v12M7 10l5 5 5-5M5 21h14"></path>
                        </svg>
                        <span class="my-btn-label">导入书签</span>
                    </button>
                    <button type="button" class="my-btn" id="pickBtn" <?php echo $hasLinks ? '' : 'aria-disabled="true"'; ?>>
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                            <path d="M4 12v7a1 1 0 0 0 1 1h14a1 1 0 0 0 1-1v-7"></path>
                            <path d="M12 16V3M7 8l5-5 5 5"></path>
                        </svg>
                        <span class="my-btn-label">生成分享</span>
                    </button>
                    <button type="button" class="my-btn is-ghost" id="sharesBtn">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true">
                            <path d="M4 6h16M4 12h16M4 18h10"></path>
                        </svg>
                        <span class="my-btn-label">我的分享</span>
                    </button>
                    <button type="button" class="my-btn is-ghost" id="accountBtn">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true">
                            <circle cx="12" cy="8" r="4"></circle>
                            <path d="M5 21c0-3.5 3.1-6 7-6s7 2.5 7 6"></path>
                        </svg>
                        <span class="my-btn-label">账号</span>
                    </button>
                </div>
            </div>

            <?php if (!$hasLinks): ?>
                <div class="my-empty">
                    <h3>还没有收藏任何网址</h3>
                    <p>可以手动添加，也可以直接导入浏览器书签（HTML 文件）。</p>
                    <button type="button" class="my-btn is-primary" id="emptyAddBtn" style="display:inline-flex;">添加第一个网址</button>
                </div>
            <?php else: ?>
                <?php foreach ($visibleGroups as $group): ?>
                    <section class="my-group" data-group="<?php echo (int) $group['id']; ?>">
                        <div class="my-group-head">
                            <h3><?php echo $functions->escape($group['name']); ?></h3>
                            <span class="my-group-count"><?php echo count($group['items']); ?></span>
                        </div>

                        <div class="my-list" data-sortable="1">
                            <?php foreach ($group['items'] as $item): ?>
                                <div class="my-item"
                                     draggable="true"
                                     data-id="<?php echo (int) $item['id']; ?>"
                                     data-name="<?php echo $functions->escape($item['name']); ?>"
                                     data-url="<?php echo $functions->escape($item['url']); ?>"
                                     data-description="<?php echo $functions->escape($item['description']); ?>"
                                     data-category="<?php echo (int) $item['category_id']; ?>"
                                     data-logo="<?php echo $functions->escape((string) ($item['logo'] ?? '')); ?>">
                                    <span class="my-picker">
                                        <input type="checkbox" value="<?php echo (int) $item['id']; ?>" aria-label="选择 <?php echo $functions->escape($item['name']); ?>">
                                    </span>

                                    <button type="button" class="my-handle" aria-label="调整顺序，可用上下方向键" title="拖动排序，或聚焦后用上下方向键">
                                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true">
                                            <path d="M9 6h.01M9 12h.01M9 18h.01M15 6h.01M15 12h.01M15 18h.01"></path>
                                        </svg>
                                    </button>

                                    <span class="my-icon" aria-hidden="true">
                                        <span class="my-icon-letter"><?php echo $functions->escape($item['initial']); ?></span>
                                        <?php $favicons = $item['favicons']; ?>
                                        <?php if ($favicons): ?>
                                            <img src="<?php echo $functions->escape($favicons[0]); ?>"
                                                 alt=""
                                                 width="34"
                                                 height="34"
                                                 loading="lazy"
                                                 decoding="async"
                                                 referrerpolicy="no-referrer"
                                                 data-fb1="<?php echo $functions->escape($favicons[1] ?? ''); ?>"
                                                 data-fb2="<?php echo $functions->escape($favicons[2] ?? ''); ?>"
                                                 onerror="var n=this.parentNode.querySelector('.my-icon-letter');if(this.dataset.fb1){this.src=this.dataset.fb1;this.dataset.fb1='';}else if(this.dataset.fb2){this.src=this.dataset.fb2;this.dataset.fb2='';}else{this.remove();if(n){n.style.display='';}}">
                                        <?php endif; ?>
                                    </span>

                                    <span class="my-info">
                                        <span class="my-link-name"><?php echo $functions->escape($item['name']); ?></span>
                                        <span class="my-link-url"><?php echo $functions->escape($item['host']); ?></span>
                                    </span>

                                    <span class="my-item-actions">
                                        <a class="my-iconbtn"
                                           href="<?php echo $functions->escape($item['url']); ?>"
                                           target="_blank"
                                           rel="noopener noreferrer"
                                           aria-label="打开 <?php echo $functions->escape($item['name']); ?>"
                                           title="打开">
                                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                                <path d="M14 4h6v6M20 4l-9 9M18 14v5a1 1 0 0 1-1 1H5a1 1 0 0 1-1-1V7a1 1 0 0 1 1-1h5"></path>
                                            </svg>
                                        </a>
                                        <button type="button" class="my-iconbtn js-edit" aria-label="编辑 <?php echo $functions->escape($item['name']); ?>" title="编辑">
                                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                                <path d="M4 20h4l10-10-4-4L4 16v4z"></path>
                                                <path d="M14 6l4 4"></path>
                                            </svg>
                                        </button>
                                        <button type="button" class="my-iconbtn is-danger js-delete" aria-label="删除 <?php echo $functions->escape($item['name']); ?>" title="删除">
                                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true">
                                                <path d="M4 7h16M9 7V5h6v2M6 7l1 13h10l1-13"></path>
                                            </svg>
                                        </button>
                                    </span>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </section>
                <?php endforeach; ?>
            <?php endif; ?>
        </main>

        <footer class="site-footer">
            <p class="copyright">&copy; <?php echo date('Y'); ?> <?php echo $functions->escape($brandName); ?> · 我的导航</p>
        </footer>
    </div>

    <button type="button" class="to-top" id="toTop" aria-label="回到顶部" title="回到顶部">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
            <path d="M12 19V5M6 11l6-6 6 6"></path>
        </svg>
    </button>

    <div class="my-sharebar" role="region" aria-label="分享选择">
        <span class="my-sharebar-text">已选 <strong id="pickCount" aria-live="polite">0</strong> 项</span>
        <button type="button" class="my-btn is-primary" id="createShareBtn">生成分享链接</button>
        <button type="button" class="my-btn" id="cancelPickBtn">取消</button>
    </div>

    <p class="my-sr" id="reorderStatus" role="status" aria-live="polite"></p>

    <!-- 添加 / 编辑 -->
    <dialog class="my-dialog" id="linkDialog">
        <form method="dialog" id="linkForm">
            <div class="my-dialog-head">
                <h3 id="linkDialogTitle">添加网址</h3>
                <button type="button" class="my-iconbtn" id="linkDialogClose" aria-label="关闭">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="m6 6 12 12M18 6 6 18"></path></svg>
                </button>
            </div>

            <div class="my-dialog-body">
                <p class="my-msg" id="linkMsg" role="alert"></p>

                <input type="hidden" id="linkId" value="">

                <div class="my-field">
                    <label for="linkUrl">网址</label>
                    <div class="my-field-row">
                        <input type="url" id="linkUrl" name="url" placeholder="https://example.com" autocomplete="off" spellcheck="false" required>
                        <button type="button" class="my-btn" id="fetchBtn" title="自动获取网站信息">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                <path d="M20 12a8 8 0 1 1-2.3-5.6"></path>
                                <path d="M20 4v5h-5"></path>
                            </svg>
                            <span class="my-btn-label">抓取</span>
                        </button>
                    </div>
                    <p class="my-note">留空名称时，保存会自动抓取网站标题。</p>
                </div>

                <div class="my-field">
                    <label for="linkName">名称</label>
                    <input type="text" id="linkName" name="name" maxlength="100" autocomplete="off" placeholder="例如：GitHub">
                </div>

                <div class="my-field">
                    <label for="linkCategory">分组</label>
                    <select id="linkCategory" name="category_id">
                        <?php foreach ($categories as $category): ?>
                            <option value="<?php echo (int) $category['id']; ?>">
                                <?php echo $functions->escape(($category['parent_id'] ? '　└ ' : '') . $category['name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="my-field">
                    <label for="linkDescription">备注</label>
                    <textarea id="linkDescription" name="description" maxlength="200" autocomplete="off" placeholder="可留空"></textarea>
                </div>

                <div class="my-field">
                    <label for="linkLogo">图标地址</label>
                    <input type="url" id="linkLogo" name="logo" maxlength="500" autocomplete="off" spellcheck="false" placeholder="可留空，自动使用网站图标">
                </div>
            </div>

            <div class="my-dialog-foot">
                <button type="button" class="my-btn" id="linkCancel">取消</button>
                <button type="submit" class="my-btn is-primary" id="linkSave">保存</button>
            </div>
        </form>
    </dialog>

    <!-- 导入书签 -->
    <dialog class="my-dialog" id="importDialog">
        <div class="my-dialog-head">
            <h3>导入浏览器书签</h3>
            <button type="button" class="my-iconbtn" id="importClose" aria-label="关闭">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="m6 6 12 12M18 6 6 18"></path></svg>
            </button>
        </div>

        <div class="my-dialog-body">
            <p class="my-msg" id="importMsg" role="alert"></p>

            <div class="my-field">
                <label for="bookmarkFile">书签 HTML 文件</label>
                <input class="my-file-input" type="file" id="bookmarkFile" accept=".html,.htm,text/html">
                <p class="my-note">
                    在浏览器「书签管理器 → 导出书签」得到 HTML 文件。导入时会按书签文件夹自动创建分组，
                    网址与已有收藏重复的条目会被跳过。
                </p>
            </div>
        </div>

        <div class="my-dialog-foot">
            <button type="button" class="my-btn" id="importCancel">取消</button>
            <button type="button" class="my-btn is-primary" id="importSubmit">开始导入</button>
        </div>
    </dialog>

    <!-- 分享结果 -->
    <dialog class="my-dialog" id="shareDialog">
        <div class="my-dialog-head">
            <h3>分享链接已生成</h3>
            <button type="button" class="my-iconbtn" id="shareClose" aria-label="关闭">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="m6 6 12 12M18 6 6 18"></path></svg>
            </button>
        </div>

        <div class="my-dialog-body">
            <div class="my-field">
                <label for="shareUrl">复制下面的链接发给他人</label>
                <div class="my-share-result">
                    <input type="text" id="shareUrl" readonly>
                    <button type="button" class="my-btn" id="copyShareBtn">复制</button>
                </div>
                <p class="my-note" id="shareNote"></p>
            </div>
        </div>

        <div class="my-dialog-foot">
            <a class="my-btn" id="shareOpen" href="#" target="_blank" rel="noopener noreferrer">预览</a>
            <button type="button" class="my-btn is-primary" id="shareDone">完成</button>
        </div>
    </dialog>

    <!-- 我的分享 -->
    <dialog class="my-dialog" id="sharesDialog">
        <div class="my-dialog-head">
            <h3>我的分享</h3>
            <button type="button" class="my-iconbtn" id="sharesClose" aria-label="关闭">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="m6 6 12 12M18 6 6 18"></path></svg>
            </button>
        </div>

        <div class="my-dialog-body">
            <div class="my-share-list" id="sharesList">
                <p class="my-note">加载中…</p>
            </div>
        </div>

        <div class="my-dialog-foot">
            <button type="button" class="my-btn is-primary" id="sharesDone">关闭</button>
        </div>
    </dialog>

    <!-- 账号 -->
    <dialog class="my-dialog" id="accountDialog">
        <div class="my-dialog-head">
            <h3>账号设置</h3>
            <button type="button" class="my-iconbtn" id="accountClose" aria-label="关闭">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="m6 6 12 12M18 6 6 18"></path></svg>
            </button>
        </div>

        <div class="my-dialog-body">
            <p class="my-msg" id="accountMsg" role="alert"></p>

            <div class="my-field">
                <label>邮箱</label>
                <input type="text" value="<?php echo $functions->escape($user['email']); ?>" readonly>
            </div>

            <div class="my-field">
                <label for="oldPassword">当前密码</label>
                <input type="password" id="oldPassword" autocomplete="current-password">
            </div>

            <div class="my-field">
                <label for="newPassword">新密码</label>
                <input type="password" id="newPassword" autocomplete="new-password" placeholder="至少 8 位">
            </div>

            <div class="my-field">
                <label for="newPasswordConfirm">确认新密码</label>
                <input type="password" id="newPasswordConfirm" autocomplete="new-password">
            </div>
        </div>

        <div class="my-dialog-foot">
            <button type="button" class="my-btn is-primary" id="changePasswordBtn">修改密码</button>
        </div>
    </dialog>

    <!-- 头像设置弹窗 -->
    <dialog class="my-dialog" id="avatarDialog">
        <form method="POST" enctype="multipart/form-data">
            <div class="my-dialog-head">
                <h3>设置头像</h3>
                <button type="button" class="my-iconbtn" id="avatarDialogClose" aria-label="关闭">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="m6 6 12 12M18 6 6 18"></path></svg>
                </button>
            </div>

            <div class="my-dialog-body">
                <?php if ($avatarMessage !== ''): ?>
                    <p class="my-msg" role="alert" style="display:block; color: var(--success, #16a34a);"><?php echo $functions->escape($avatarMessage); ?></p>
                <?php endif; ?>
                <?php if ($avatarError !== ''): ?>
                    <p class="my-msg is-error" role="alert" style="display:block;"><?php echo $functions->escape($avatarError); ?></p>
                <?php endif; ?>

                <div class="my-field">
                    <label>当前头像</label>
                    <div style="display:flex;align-items:center;gap:12px;">
                        <?php if ($avatarUrl !== ''): ?>
                            <img src="<?php echo $functions->escape($avatarUrl); ?>" alt="当前头像" style="width:64px;height:64px;border-radius:50%;object-fit:cover;">
                        <?php else: ?>
                            <span style="width:64px;height:64px;border-radius:50%;background:var(--primary,#667eea);color:#fff;display:flex;align-items:center;justify-content:center;font-size:24px;"><?php echo $functions->escape($brandInitial); ?></span>
                        <?php endif; ?>
                        <span class="my-note">支持 JPG / PNG / GIF / WebP，最大 2MB</span>
                    </div>
                </div>

                <div class="my-field">
                    <label for="avatarFile">上传新头像</label>
                    <input type="file" id="avatarFile" name="avatar_file" accept="image/jpeg,image/png,image/gif,image/webp">
                </div>
            </div>

            <div class="my-dialog-foot">
                <button type="submit" name="update_avatar" value="1" class="my-btn is-primary">
                    <i class="fas fa-upload"></i> 上传头像
                </button>
                <?php if ($avatarUrl !== ''): ?>
                    <button type="submit" name="update_avatar" value="1" formaction="" class="my-btn" onclick="document.getElementById('avatarAction').value='remove';">
                        <i class="fas fa-trash"></i> 清除头像
                    </button>
                <?php endif; ?>
                <input type="hidden" name="avatar_action" id="avatarAction" value="upload">
            </div>
        </form>
    </dialog>

    <script>
        (function () {
            'use strict';

            var API_SITES = 'api/user_sites.php';
            var API_AUTH = 'api/auth.php';
            var API_SHARE = 'api/share.php';
            var API_BOOKMARK = 'api/bookmark_import.php';

            function qs(selector, scope) {
                return (scope || document).querySelector(selector);
            }

            function qsa(selector, scope) {
                return Array.prototype.slice.call((scope || document).querySelectorAll(selector));
            }

            function postForm(url, data) {
                var body = new FormData();

                Object.keys(data || {}).forEach(function (key) {
                    if (Array.isArray(data[key])) {
                        data[key].forEach(function (value) {
                            body.append(key + '[]', value);
                        });
                        return;
                    }

                    body.append(key, data[key]);
                });

                return fetch(url, {
                    method: 'POST',
                    body: body,
                    credentials: 'same-origin',
                    headers: { 'X-Requested-With': 'XMLHttpRequest' }
                }).then(function (response) {
                    return response.json().catch(function () {
                        return { ok: false, error: '服务器返回异常' };
                    });
                }).catch(function () {
                    return { ok: false, error: '网络异常，请稍后重试' };
                });
            }

            function showMsg(node, message, type) {
                if (!node) { return; }

                node.textContent = message || '';
                node.className = 'my-msg' + (message ? ' is-' + (type || 'error') : '');
            }

            /**
             * 请求期间禁用按钮并显示进行中的文案，避免重复提交。
             */
            function setBusy(button, busy, busyLabel) {
                if (!button) { return; }

                if (busy) {
                    button.dataset.idleLabel = button.textContent;
                    button.textContent = busyLabel || '处理中…';
                    button.disabled = true;
                    return;
                }

                if (button.dataset.idleLabel) {
                    button.textContent = button.dataset.idleLabel;
                    delete button.dataset.idleLabel;
                }

                button.disabled = false;
            }

            function toTop() {
                var button = qs('#toTop');

                if (!button) {
                    return;
                }

                var sync = function () {
                    button.classList.toggle('is-visible', window.scrollY > 320);
                };

                button.addEventListener('click', function () {
                    window.scrollTo({ top: 0, behavior: 'smooth' });
                });

                window.addEventListener('scroll', sync, { passive: true });
                sync();
            }

            /* ----------------------------- 主题 ----------------------------- */

            var themeToggle = qs('#themeToggle');

            if (themeToggle) {
                themeToggle.addEventListener('click', function () {
                    var next = document.documentElement.getAttribute('data-theme') === 'dark' ? 'light' : 'dark';
                    document.documentElement.setAttribute('data-theme', next);
                    try { localStorage.setItem('xy-nav-theme', next); } catch (e) {}
                });
            }

            /* --------------------------- 添加 / 编辑 --------------------------- */

            var linkDialog = qs('#linkDialog');
            var linkMsg = qs('#linkMsg');
            var linkId = qs('#linkId');
            var linkUrl = qs('#linkUrl');
            var linkName = qs('#linkName');
            var linkCategory = qs('#linkCategory');
            var linkDescription = qs('#linkDescription');
            var linkLogo = qs('#linkLogo');
            var linkDialogTitle = qs('#linkDialogTitle');

            function openLinkDialog(item) {
                showMsg(linkMsg, '');

                if (item) {
                    linkDialogTitle.textContent = '编辑网址';
                    linkId.value = item.id;
                    linkUrl.value = item.url;
                    linkName.value = item.name;
                    linkCategory.value = item.categoryId;
                    linkDescription.value = item.description || '';
                    linkLogo.value = item.logo || '';
                } else {
                    linkDialogTitle.textContent = '添加网址';
                    linkId.value = '';
                    linkUrl.value = '';
                    linkName.value = '';
                    linkDescription.value = '';
                    linkLogo.value = '';

                    if (linkCategory.options.length) {
                        linkCategory.selectedIndex = 0;
                    }
                }

                if (typeof linkDialog.showModal === 'function') {
                    linkDialog.showModal();
                } else {
                    linkDialog.setAttribute('open', 'open');
                }

                window.setTimeout(function () { linkUrl.focus(); }, 60);
            }

            function closeLinkDialog() {
                if (typeof linkDialog.close === 'function') {
                    linkDialog.close();
                } else {
                    linkDialog.removeAttribute('open');
                }
            }

            qs('#addBtn') && qs('#addBtn').addEventListener('click', function () { openLinkDialog(null); });
            qs('#emptyAddBtn') && qs('#emptyAddBtn').addEventListener('click', function () { openLinkDialog(null); });
            qs('#linkDialogClose') && qs('#linkDialogClose').addEventListener('click', closeLinkDialog);
            qs('#linkCancel') && qs('#linkCancel').addEventListener('click', closeLinkDialog);

            // 头像设置弹窗
            var avatarDialog = qs('#avatarDialog');
            var avatarEditBtn = qs('#avatarEditBtn');
            var avatarDialogClose = qs('#avatarDialogClose');

            if (avatarEditBtn && avatarDialog) {
                avatarEditBtn.addEventListener('click', function () {
                    avatarDialog.showModal();
                });
            }
            if (avatarDialogClose && avatarDialog) {
                avatarDialogClose.addEventListener('click', function () {
                    avatarDialog.close();
                });
            }

            var fetchBtn = qs('#fetchBtn');

            if (fetchBtn) {
                fetchBtn.addEventListener('click', function () {
                    var url = linkUrl.value.trim();

                    if (url === '') {
                        showMsg(linkMsg, '请先填写网址');
                        return;
                    }

                    showMsg(linkMsg, '正在抓取网站信息…', 'success');
                    setBusy(fetchBtn, true, '抓取中…');

                    postForm(API_SITES, { action: 'fetch', url: url }).then(function (result) {
                        setBusy(fetchBtn, false);

                        if (!result.ok) {
                            showMsg(linkMsg, result.error || '抓取失败，可手动填写名称');
                            return;
                        }

                        var meta = result.meta || {};

                        if (meta.title && linkName.value.trim() === '') {
                            linkName.value = meta.title;
                        }

                        if (meta.description && linkDescription.value.trim() === '') {
                            linkDescription.value = meta.description;
                        }

                        if (meta.icons && meta.icons.length && linkLogo.value.trim() === '') {
                            linkLogo.value = meta.icons[0];
                        }

                        if (linkUrl.value.trim() !== '' && !/^https?:\/\//i.test(linkUrl.value.trim())) {
                            linkUrl.value = result.url;
                        }

                        showMsg(linkMsg, meta.title ? '已获取：' + meta.title : '未获取到标题，可手动填写', 'success');
                    });
                });
            }

            var saving = false;
            var saveBtn = qs('#linkSave');
            var linkForm = qs('#linkForm');

            function saveLink() {
                if (saving) { return; }

                var url = linkUrl.value.trim();

                if (url === '') {
                    showMsg(linkMsg, '请填写网址');
                    linkUrl.focus();
                    return;
                }

                var payload = {
                    action: linkId.value ? 'update' : 'add',
                    url: url,
                    name: linkName.value.trim(),
                    category_id: linkCategory.value,
                    description: linkDescription.value.trim(),
                    logo: linkLogo.value.trim()
                };

                if (linkId.value) {
                    payload.id = linkId.value;
                }

                saving = true;
                setBusy(saveBtn, true, '保存中…');

                postForm(API_SITES, payload).then(function (result) {
                    saving = false;
                    setBusy(saveBtn, false);

                    if (!result.ok) {
                        showMsg(linkMsg, result.error || '保存失败');
                        return;
                    }

                    window.location.reload();
                });
            }

            if (saveBtn) {
                saveBtn.addEventListener('click', saveLink);
            }

            if (linkForm) {
                linkForm.addEventListener('submit', function (event) {
                    event.preventDefault();
                    saveLink();
                });
            }

            /* ----------------------------- 编辑 / 删除 ----------------------------- */

            document.addEventListener('click', function (event) {
                var editBtn = event.target.closest ? event.target.closest('.js-edit') : null;

                if (editBtn) {
                    var item = editBtn.closest('.my-item');

                    if (item) {
                        openLinkDialog({
                            id: item.getAttribute('data-id'),
                            url: item.getAttribute('data-url'),
                            name: item.getAttribute('data-name'),
                            description: item.getAttribute('data-description'),
                            categoryId: item.getAttribute('data-category'),
                            logo: item.getAttribute('data-logo') || ''
                        });
                    }

                    return;
                }

                var deleteBtn = event.target.closest ? event.target.closest('.js-delete') : null;

                if (deleteBtn) {
                    var row = deleteBtn.closest('.my-item');

                    if (!row) { return; }

                    if (!window.confirm('确定删除「' + row.getAttribute('data-name') + '」吗？')) {
                        return;
                    }

                    postForm(API_SITES, { action: 'delete', id: row.getAttribute('data-id') }).then(function (result) {
                        if (!result.ok) {
                            window.alert(result.error || '删除失败');
                            return;
                        }

                        window.location.reload();
                    });
                }
            });

            /* ----------------------------- 拖拽排序 ----------------------------- */

            var dragging = null;

            qsa('.my-list[data-sortable]').forEach(function (list) {
                list.addEventListener('dragstart', function (event) {
                    if (document.body.getAttribute('data-picking') === '1') {
                        event.preventDefault();
                        return;
                    }

                    var item = event.target.closest ? event.target.closest('.my-item') : null;

                    if (!item) { return; }

                    dragging = item;
                    item.classList.add('is-dragging');

                    if (event.dataTransfer) {
                        event.dataTransfer.effectAllowed = 'move';
                        event.dataTransfer.setData('text/plain', item.getAttribute('data-id'));
                    }
                });

                list.addEventListener('dragover', function (event) {
                    if (!dragging || dragging.parentNode !== list) { return; }

                    event.preventDefault();
                    event.dataTransfer.dropEffect = 'move';

                    var target = event.target.closest ? event.target.closest('.my-item') : null;

                    qsa('.my-item.is-drop-target', list).forEach(function (node) {
                        node.classList.remove('is-drop-target');
                    });

                    if (!target || target === dragging) { return; }

                    target.classList.add('is-drop-target');

                    var rect = target.getBoundingClientRect();
                    var after = event.clientY > rect.top + rect.height / 2;

                    if (after) {
                        list.insertBefore(dragging, target.nextSibling);
                    } else {
                        list.insertBefore(dragging, target);
                    }
                });

                list.addEventListener('drop', function (event) {
                    event.preventDefault();
                });

                list.addEventListener('dragend', function () {
                    if (!dragging) { return; }

                    dragging.classList.remove('is-dragging');
                    qsa('.my-item.is-drop-target').forEach(function (node) {
                        node.classList.remove('is-drop-target');
                    });

                    dragging = null;
                    persistOrder();
                });
            });

            var reorderStatus = qs('#reorderStatus');

            function persistOrder() {
                var items = qsa('.my-item');

                var ids = items.map(function (node) {
                    return node.getAttribute('data-id');
                });

                postForm(API_SITES, { action: 'sort', ids: ids }).then(function (result) {
                    if (!result.ok) {
                        window.alert(result.error || '排序保存失败');
                        window.location.reload();
                    }
                });
            }

            // 键盘替代方案：聚焦手柄后用上下方向键移动，避免只有拖拽这一种操作方式。
            qsa('.my-handle').forEach(function (handle) {
                handle.addEventListener('keydown', function (event) {
                    if (event.key !== 'ArrowUp' && event.key !== 'ArrowDown') {
                        return;
                    }

                    var item = handle.closest('.my-item');
                    var list = item ? item.parentNode : null;

                    if (!item || !list) {
                        return;
                    }

                    var sibling = event.key === 'ArrowUp'
                        ? item.previousElementSibling
                        : item.nextElementSibling;

                    if (!sibling) {
                        return;
                    }

                    event.preventDefault();

                    if (event.key === 'ArrowUp') {
                        list.insertBefore(item, sibling);
                    } else {
                        list.insertBefore(sibling, item);
                    }

                    handle.focus();

                    var position = qsa('.my-item', list).indexOf(item) + 1;

                    if (reorderStatus) {
                        reorderStatus.textContent = item.getAttribute('data-name') + ' 已移动到第 ' + position + ' 位';
                    }

                    persistOrder();
                });
            });

            /* ----------------------------- 分享 ----------------------------- */

            var pickBtn = qs('#pickBtn');
            var cancelPickBtn = qs('#cancelPickBtn');
            var pickCountNode = qs('#pickCount');

            function updatePickCount() {
                if (pickCountNode) {
                    pickCountNode.textContent = qsa('.my-picker input:checked').length;
                }
            }

            if (pickBtn) {
                pickBtn.addEventListener('click', function () {
                    document.body.setAttribute('data-picking', '1');
                    updatePickCount();
                });
            }

            if (cancelPickBtn) {
                cancelPickBtn.addEventListener('click', function () {
                    document.body.setAttribute('data-picking', '0');
                    qsa('.my-picker input').forEach(function (input) { input.checked = false; });
                    updatePickCount();
                });
            }

            document.addEventListener('change', function (event) {
                if (event.target && event.target.closest && event.target.closest('.my-picker')) {
                    updatePickCount();
                }
            });

            var shareDialog = qs('#shareDialog');
            var shareUrl = qs('#shareUrl');
            var shareNote = qs('#shareNote');
            var shareOpen = qs('#shareOpen');

            var createShareBtn = qs('#createShareBtn');

            if (createShareBtn) {
                createShareBtn.addEventListener('click', function () {
                    var ids = qsa('.my-picker input:checked').map(function (input) { return input.value; });

                    if (!ids.length) {
                        window.alert('请先勾选要分享的网站');
                        return;
                    }

                    setBusy(createShareBtn, true, '生成中…');

                    postForm(API_SHARE, {
                        action: 'create',
                        site_ids: ids,
                        title: document.querySelector('.brand-title') ? document.querySelector('.brand-title').textContent.trim() : ''
                    }).then(function (result) {
                        setBusy(createShareBtn, false);

                        if (!result.ok) {
                            window.alert(result.error || '生成分享失败');
                            return;
                        }

                        var full = new URL(result.url, window.location.href).href;

                        shareUrl.value = full;
                        shareOpen.setAttribute('href', result.url);
                        shareNote.textContent = '共 ' + result.count + ' 个网站，他人打开后可一键导入到自己的导航。';

                        document.body.setAttribute('data-picking', '0');
                        qsa('.my-picker input').forEach(function (input) { input.checked = false; });
                        updatePickCount();

                        if (typeof shareDialog.showModal === 'function') {
                            shareDialog.showModal();
                        }
                    });
                });
            }

            qs('#shareClose') && qs('#shareClose').addEventListener('click', function () { shareDialog.close(); });
            qs('#shareDone') && qs('#shareDone').addEventListener('click', function () { shareDialog.close(); });

            qs('#copyShareBtn') && qs('#copyShareBtn').addEventListener('click', function () {
                shareUrl.select();

                var done = function () {
                    qs('#copyShareBtn').textContent = '已复制';
                    window.setTimeout(function () { qs('#copyShareBtn').textContent = '复制'; }, 1600);
                };

                if (navigator.clipboard && navigator.clipboard.writeText) {
                    navigator.clipboard.writeText(shareUrl.value).then(done).catch(function () {
                        try { document.execCommand('copy'); done(); } catch (e) { window.alert('请手动复制'); }
                    });
                } else {
                    try { document.execCommand('copy'); done(); } catch (e) { window.alert('请手动复制'); }
                }
            });

            /* ----------------------------- 我的分享 ----------------------------- */

            var sharesDialog = qs('#sharesDialog');
            var sharesList = qs('#sharesList');
            var sharesBtn = qs('#sharesBtn');

            function escapeHtml(text) {
                return String(text == null ? '' : text).replace(/[&<>"']/g, function (char) {
                    return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[char];
                });
            }

            function loadShares() {
                sharesList.innerHTML = '<p class="my-note">加载中…</p>';

                fetch(API_SHARE + '?action=mine', {
                    credentials: 'same-origin',
                    headers: { 'X-Requested-With': 'XMLHttpRequest' }
                }).then(function (response) {
                    return response.json();
                }).then(function (result) {
                    if (!result.ok) {
                        sharesList.innerHTML = '<p class="my-note">' + escapeHtml(result.error || '加载失败') + '</p>';
                        return;
                    }

                    if (!result.items.length) {
                        sharesList.innerHTML = '<p class="my-note">还没有创建过分享。</p>';
                        return;
                    }

                    sharesList.innerHTML = result.items.map(function (item) {
                        return '<div class="my-share-row" data-id="' + item.id + '">'
                            + '<div class="my-share-row-main">'
                            + '<span class="my-share-row-title">' + escapeHtml(item.title || '未命名分享') + '</span>'
                            + '<span class="my-share-row-meta">' + item.count + ' 个网站 · ' + item.views + ' 次浏览 · ' + escapeHtml(item.created_at) + '</span>'
                            + '</div>'
                            + '<a class="my-btn" href="' + escapeHtml(item.url) + '" target="_blank" rel="noopener noreferrer">打开</a>'
                            + '<button type="button" class="my-btn js-share-delete">删除</button>'
                            + '</div>';
                    }).join('');
                }).catch(function () {
                    sharesList.innerHTML = '<p class="my-note">加载失败，请稍后重试。</p>';
                });
            }

            if (sharesBtn) {
                sharesBtn.addEventListener('click', function () {
                    if (typeof sharesDialog.showModal === 'function') {
                        sharesDialog.showModal();
                    }

                    loadShares();
                });
            }

            qs('#sharesClose') && qs('#sharesClose').addEventListener('click', function () { sharesDialog.close(); });
            qs('#sharesDone') && qs('#sharesDone').addEventListener('click', function () { sharesDialog.close(); });

            sharesList && sharesList.addEventListener('click', function (event) {
                var button = event.target.closest ? event.target.closest('.js-share-delete') : null;

                if (!button) { return; }

                var row = button.closest('.my-share-row');

                if (!row || !window.confirm('确定删除这条分享吗？删除后链接将失效。')) {
                    return;
                }

                postForm(API_SHARE, { action: 'delete', id: row.getAttribute('data-id') }).then(function (result) {
                    if (!result.ok) {
                        window.alert(result.error || '删除失败');
                        return;
                    }

                    loadShares();
                });
            });

            /* ----------------------------- 导入书签 ----------------------------- */

            var importDialog = qs('#importDialog');
            var importMsg = qs('#importMsg');
            var bookmarkFile = qs('#bookmarkFile');
            var importSubmit = qs('#importSubmit');

            qs('#importBtn') && qs('#importBtn').addEventListener('click', function () {
                showMsg(importMsg, '');
                bookmarkFile.value = '';

                if (typeof importDialog.showModal === 'function') {
                    importDialog.showModal();
                }
            });

            qs('#importClose') && qs('#importClose').addEventListener('click', function () { importDialog.close(); });
            qs('#importCancel') && qs('#importCancel').addEventListener('click', function () { importDialog.close(); });

            if (importSubmit) {
                importSubmit.addEventListener('click', function () {
                    if (!bookmarkFile.files || !bookmarkFile.files.length) {
                        showMsg(importMsg, '请先选择书签 HTML 文件');
                        return;
                    }

                    showMsg(importMsg, '');
                    setBusy(importSubmit, true, '导入中…');

                    var body = new FormData();
                    body.append('file', bookmarkFile.files[0]);

                    fetch(API_BOOKMARK, {
                        method: 'POST',
                        body: body,
                        credentials: 'same-origin',
                        headers: { 'X-Requested-With': 'XMLHttpRequest' }
                    }).then(function (response) {
                        return response.json().catch(function () {
                            return { ok: false, error: '服务器返回异常' };
                        });
                    }).then(function (result) {
                        setBusy(importSubmit, false);

                        if (!result.ok) {
                            showMsg(importMsg, result.error || '导入失败');
                            return;
                        }

                        showMsg(importMsg, '导入完成：新增 ' + result.imported + ' 条，跳过重复 ' + result.skipped + ' 条，新建分组 ' + result.categories + ' 个。', 'success');

                        window.setTimeout(function () { window.location.reload(); }, 1200);
                    }).catch(function () {
                        setBusy(importSubmit, false);
                        showMsg(importMsg, '网络异常，请稍后重试');
                    });
                });
            }

            /* ----------------------------- 账号设置 ----------------------------- */

            var accountDialog = qs('#accountDialog');
            var accountMsg = qs('#accountMsg');
            var changePasswordBtn = qs('#changePasswordBtn');

            qs('#accountBtn') && qs('#accountBtn').addEventListener('click', function () {
                showMsg(accountMsg, '');
                qs('#oldPassword').value = '';
                qs('#newPassword').value = '';
                qs('#newPasswordConfirm').value = '';

                if (typeof accountDialog.showModal === 'function') {
                    accountDialog.showModal();
                }
            });

            qs('#accountClose') && qs('#accountClose').addEventListener('click', function () { accountDialog.close(); });

            if (changePasswordBtn) {
                changePasswordBtn.addEventListener('click', function () {
                    var oldPassword = qs('#oldPassword').value;
                    var newPassword = qs('#newPassword').value;
                    var confirmPassword = qs('#newPasswordConfirm').value;

                    if (oldPassword === '' || newPassword === '') {
                        showMsg(accountMsg, '请填写当前密码和新密码');
                        return;
                    }

                    if (newPassword.length < 8) {
                        showMsg(accountMsg, '新密码至少 8 位');
                        return;
                    }

                    if (newPassword !== confirmPassword) {
                        showMsg(accountMsg, '两次输入的新密码不一致');
                        return;
                    }

                    setBusy(changePasswordBtn, true, '提交中…');

                    postForm(API_AUTH, {
                        action: 'change_password',
                        old_password: oldPassword,
                        new_password: newPassword,
                        new_password_confirm: confirmPassword
                    }).then(function (result) {
                        setBusy(changePasswordBtn, false);

                        if (!result.ok) {
                            showMsg(accountMsg, result.error || '修改失败');
                            return;
                        }

                        showMsg(accountMsg, '密码已更新', 'success');
                        qs('#oldPassword').value = '';
                        qs('#newPassword').value = '';
                        qs('#newPasswordConfirm').value = '';
                    });
                });
            }

            toTop();
            updatePickCount();
        })();
    </script>
</body>
</html>
