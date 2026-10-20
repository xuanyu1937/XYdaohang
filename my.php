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
        'SELECT id, name, url, logo, description, category_id, sort_order, clicks, check_status, latency_ms, is_starred
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

// 当前用户自己的分组（用于「分组管理」弹窗：重命名 / 私密切换 / 删除）
$ownGroups = [];

foreach ($categories as $category) {
    $catUserId = isset($category['user_id']) ? (int) $category['user_id'] : 0;
    $catPrivate = isset($category['is_private']) ? (int) $category['is_private'] : 0;

    $groups[(int) $category['id']] = [
        'id' => (int) $category['id'],
        'name' => (string) $category['name'],
        'icon' => (string) ($category['icon'] ?? ''),
        'is_private' => $catPrivate,
        'own' => $catUserId === $userId,
        'items' => [],
    ];

    if ($catUserId === $userId) {
        $ownGroups[] = [
            'id' => (int) $category['id'],
            'name' => (string) $category['name'],
            'is_private' => $catPrivate,
        ];
    }
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
    <link rel="stylesheet" href="assets/css/nav.css?v=2.5.0">
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

        .my-item[data-starred="1"] .my-item-star {
            color: var(--warning);
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

        /* 分组管理 */
        .my-group-privacy {
            margin-left: 6px;
            font-size: 12px;
            vertical-align: 1px;
        }

        .my-group-list {
            display: flex;
            flex-direction: column;
            gap: 8px;
            margin: 0;
            padding: 0;
            list-style: none;
        }

        .my-group-item {
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 8px 10px;
            border: 1px solid var(--border);
            border-radius: 10px;
            background: var(--surface-2);
        }

        .my-group-item .my-group-name {
            flex: 1 1 auto;
            min-width: 0;
            padding: 6px 10px;
            border: 1px solid var(--border-strong);
            border-radius: 8px;
            background: var(--surface);
            color: var(--text);
            font-size: 13px;
            outline: none;
        }

        .my-group-item .my-group-name:focus {
            border-color: var(--primary);
        }

        .my-group-private-toggle {
            display: inline-flex;
            flex: 0 0 auto;
            align-items: center;
            gap: 4px;
            color: var(--muted);
            font-size: 12px;
            white-space: nowrap;
        }

        .my-group-item .my-btn {
            flex: 0 0 auto;
            padding: 6px 10px;
            font-size: 12px;
        }

        /* 分组排序：上移 / 下移小图标按钮 */
        .my-group-order {
            display: inline-flex;
            flex: 0 0 auto;
            align-items: center;
            gap: 4px;
        }

        .my-group-move {
            display: inline-grid;
            flex: 0 0 auto;
            place-items: center;
            width: 26px;
            height: 26px;
            padding: 0;
            border: 1px solid var(--border);
            border-radius: 7px;
            background: var(--surface);
            color: var(--muted);
            cursor: pointer;
            transition: border-color 0.15s ease, color 0.15s ease, background-color 0.15s ease;
        }

        .my-group-move:hover:not(:disabled) {
            border-color: var(--primary-border);
            background: var(--primary-soft);
            color: var(--primary);
        }

        .my-group-move:disabled {
            opacity: 0.35;
            cursor: default;
        }

        .my-group-move svg {
            width: 13px;
            height: 13px;
        }

        /* 抓取按钮：主色渐变实心 + 白图标，悬浮上浮、点击下压，进行中图标旋转 */
        #fetchBtn {
            position: relative;
            border-color: transparent;
            background: linear-gradient(135deg, var(--primary) 0%, var(--primary-filled-hover) 100%);
            color: var(--primary-contrast);
            font-weight: 600;
            letter-spacing: 0.02em;
            box-shadow: 0 6px 14px -6px rgba(29, 78, 216, 0.55), inset 0 1px 0 rgba(255, 255, 255, 0.18);
            transition: transform 0.16s ease, box-shadow 0.16s ease, filter 0.16s ease;
        }

        #fetchBtn:hover {
            background: linear-gradient(135deg, var(--primary-filled-hover) 0%, var(--primary) 100%);
            color: var(--primary-contrast);
            transform: translateY(-1px);
            filter: brightness(1.06);
            box-shadow: 0 10px 20px -8px rgba(29, 78, 216, 0.6), inset 0 1px 0 rgba(255, 255, 255, 0.2);
        }

        #fetchBtn:active {
            transform: translateY(0);
            box-shadow: 0 3px 8px -4px rgba(29, 78, 216, 0.5);
        }

        #fetchBtn svg {
            width: 15px;
            height: 15px;
        }

        #fetchBtn.is-busy {
            filter: saturate(0.9);
        }

        #fetchBtn.is-busy svg {
            animation: my-spin 0.9s linear infinite;
        }

        @keyframes my-spin {
            to { transform: rotate(360deg); }
        }

        .my-check {
            display: flex;
            align-items: center;
            gap: 8px;
            color: var(--text-2);
            font-size: 12.5px;
            cursor: pointer;
        }

        .my-check input {
            width: 15px;
            height: 15px;
            margin: 0;
            accent-color: var(--primary);
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

            /* 仅隐藏外层工具栏按钮的 label，对话框/表单项内按钮保留文字语义 */
            .my-btn:not(.my-dialog .my-btn):not(.my-field-row .my-btn) .my-btn-label {
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
                    <button type="button" class="my-btn" id="quickAddBtn" title="粘贴网址一键收藏，自动抓取标题和图标">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                            <polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"></polygon>
                        </svg>
                        <span class="my-btn-label">快捷收藏</span>
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
                    <button type="button" class="my-btn is-ghost" id="groupManageBtn">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true">
                            <path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"></path>
                            <path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z"></path>
                        </svg>
                        <span class="my-btn-label">分组管理</span>
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
                            <h3>
                                <?php echo $functions->escape($group['name']); ?>
                                <?php if (!empty($group['is_private'])): ?>
                                    <span class="my-group-privacy" title="私密分组：仅本人可见">&#x1f512;</span>
                                <?php endif; ?>
                            </h3>
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
                                     data-logo="<?php echo $functions->escape((string) ($item['logo'] ?? '')); ?>"
                                     data-starred="<?php echo !empty($item['is_starred']) ? '1' : '0'; ?>">
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
                                        <button type="button" class="my-iconbtn my-item-star" data-action="star" aria-label="<?php echo !empty($item['is_starred']) ? '取消收藏' : '收藏'; ?>" title="<?php echo !empty($item['is_starred']) ? '取消收藏' : '收藏'; ?>">
                                            <svg viewBox="0 0 24 24" fill="<?php echo !empty($item['is_starred']) ? 'currentColor' : 'none'; ?>" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                                <polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"></polygon>
                                            </svg>
                                        </button>
                                        <button type="button" class="my-iconbtn" data-action="copy" aria-label="复制链接" title="复制链接">
                                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="9" y="9" width="13" height="13" rx="2"></rect><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"></path></svg>
                                        </button>
                                        <button type="button" class="my-iconbtn" data-action="qrcode" aria-label="二维码" title="二维码">
                                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="3" width="7" height="7"></rect><rect x="14" y="3" width="7" height="7"></rect><rect x="3" y="14" width="7" height="7"></rect><path d="M14 14h3v3M21 14v7M14 21h3M17 17h4"></path></svg>
                                        </button>
                                        <button type="button" class="my-iconbtn" data-action="move" aria-label="移动分组" title="移动分组">
                                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 4h14M5 12h14M5 20h14"></path><path d="M9 8l3-3 3 3M9 16l3 3 3-3"></path></svg>
                                        </button>
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
            <p class="copyright"><?php echo $functions->escape($functions->footerCopyrightText((string) ($user['footer_copyright'] ?? ''), $brandName)); ?> · 我的导航</p>
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
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                <path d="M12 3l1.7 4.6L18 9.2l-4.3 1.6L12 15.5l-1.7-4.7L6 9.2l4.3-1.6Z"></path>
                                <path d="M18.5 14.5l.8 2.2 2.2.8-2.2.8-.8 2.2-.8-2.2-2.2-.8 2.2-.8Z"></path>
                                <path d="M5 16l.6 1.7 1.7.6-1.7.6L5 20.6l-.6-1.7-1.7-.6 1.7-.6Z"></path>
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
                        <option value="0">未分类</option>
                        <?php foreach ($ownGroups as $group): ?>
                            <option value="<?php echo (int) $group['id']; ?>">
                                <?php echo $functions->escape(($group['is_private'] ? '🔒 ' : '') . $group['name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <p class="my-note">仅显示你自己创建的分组，分组数据完全私有。</p>
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

    <!-- 快捷收藏 -->
    <dialog class="my-dialog" id="quickAddDialog">
        <div class="my-dialog-head">
            <h3>快捷收藏</h3>
            <button type="button" class="my-iconbtn" id="quickAddClose" aria-label="关闭">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="m6 6 12 12M18 6 6 18"></path></svg>
            </button>
        </div>
        <div class="my-dialog-body">
            <p class="my-msg" id="quickAddMsg" role="alert"></p>
            <div class="my-field">
                <label for="quickAddUrl">网址</label>
                <input type="url" id="quickAddUrl" placeholder="粘贴网址，回车或点击按钮自动抓取并收藏" autocomplete="off" spellcheck="false">
            </div>
            <div class="my-field" id="quickAddPreview" hidden>
                <label>抓取预览</label>
                <div class="quick-add-preview">
                    <span class="qa-icon" aria-hidden="true">
                        <span class="qa-icon-letter"></span>
                        <img class="qa-favicon" alt="" width="40" height="40">
                    </span>
                    <span class="qa-info">
                        <span class="qa-name"></span>
                        <span class="qa-desc"></span>
                    </span>
                </div>
            </div>
            <div class="my-field" id="quickAddGroupField">
                <label for="quickAddCategory">保存到分组</label>
                <select id="quickAddCategory">
                    <option value="0">未分类</option>
                    <?php foreach ($ownGroups as $group): ?>
                        <option value="<?php echo (int) $group['id']; ?>"><?php echo $functions->escape(($group['is_private'] ? '🔒 ' : '') . $group['name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
        <div class="my-dialog-foot">
            <button type="button" class="my-btn" id="quickAddCancel">取消</button>
            <button type="button" class="my-btn is-primary" id="quickAddSubmit">抓取并收藏</button>
        </div>
    </dialog>

    <!-- 二维码 -->
    <dialog class="my-dialog" id="qrDialog">
        <div class="my-dialog-head">
            <h3>网址二维码</h3>
            <button type="button" class="my-iconbtn" id="qrClose" aria-label="关闭">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="m6 6 12 12M18 6 6 18"></path></svg>
            </button>
        </div>
        <div class="my-dialog-body">
            <div class="qr-box">
                <img id="qrImage" alt="网址二维码" width="220" height="220">
            </div>
            <p class="qr-url" id="qrUrl"></p>
        </div>
        <div class="my-dialog-foot">
            <button type="button" class="my-btn" id="qrCopyBtn">复制链接</button>
            <button type="button" class="my-btn is-primary" id="qrClose2">关闭</button>
        </div>
    </dialog>

    <!-- 移动分组 -->
    <dialog class="my-dialog" id="moveDialog">
        <div class="my-dialog-head">
            <h3>移动到分组</h3>
            <button type="button" class="my-iconbtn" id="moveClose" aria-label="关闭">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="m6 6 12 12M18 6 6 18"></path></svg>
            </button>
        </div>
        <div class="my-dialog-body">
            <p class="my-msg" id="moveMsg" role="alert"></p>
            <div class="my-field">
                <label for="moveCategory">选择目标分组</label>
                <select id="moveCategory">
                    <option value="0">未分类</option>
                    <?php foreach ($ownGroups as $group): ?>
                        <option value="<?php echo (int) $group['id']; ?>"><?php echo $functions->escape(($group['is_private'] ? '🔒 ' : '') . $group['name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
        <div class="my-dialog-foot">
            <button type="button" class="my-btn" id="moveCancel">取消</button>
            <button type="button" class="my-btn is-primary" id="moveSubmit">移动</button>
        </div>
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
                <label>头像</label>
                <div style="display:flex;align-items:center;gap:12px;">
                    <?php if ($avatarUrl !== ''): ?>
                        <img src="<?php echo $functions->escape($avatarUrl); ?>" alt="当前头像" style="width:44px;height:44px;border-radius:50%;object-fit:cover;">
                    <?php else: ?>
                        <span style="width:44px;height:44px;border-radius:50%;background:var(--primary,#667eea);color:#fff;display:inline-flex;align-items:center;justify-content:center;font-size:18px;flex:0 0 auto;"><?php echo $functions->escape($brandInitial); ?></span>
                    <?php endif; ?>
                    <button type="button" class="my-btn" id="accountAvatarBtn">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                            <path d="M14.5 4h-5L7 7H4a2 2 0 0 0-2 2v9a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2V9a2 2 0 0 0-2-2h-3l-2.5-3z"></path>
                            <circle cx="12" cy="13" r="4"></circle>
                        </svg>
                        <span class="my-btn-label">设置头像</span>
                    </button>
                </div>
            </div>

            <div class="my-field">
                <label>邮箱</label>
                <input type="text" value="<?php echo $functions->escape($user['email']); ?>" readonly>
            </div>

            <div class="my-field">
                <label for="footerCopyright">底部版权</label>
                <div class="my-field-row">
                    <input type="text" id="footerCopyright" maxlength="120" autocomplete="off"
                           value="<?php echo $functions->escape((string) ($user['footer_copyright'] ?? '')); ?>"
                           placeholder="默认使用站点版权，支持 {year} 与 {site} 占位符">
                    <button type="button" class="my-btn" id="saveCopyrightBtn">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                            <path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"></path>
                            <polyline points="17 21 17 13 7 13 7 21"></polyline>
                            <polyline points="7 3 7 8 15 8"></polyline>
                        </svg>
                        <span class="my-btn-label">保存</span>
                    </button>
                </div>
                <p class="my-note">仅影响你自己页面的页脚版权展示；留空则使用站点默认版权。</p>
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
            <button type="button" class="my-btn is-primary" id="changePasswordBtn">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                    <rect x="3" y="11" width="18" height="11" rx="2" ry="2"></rect>
                    <path d="M7 11V7a5 5 0 0 1 10 0v4"></path>
                </svg>
                <span class="my-btn-label">修改密码</span>
            </button>
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

    <!-- 分组管理 -->
    <dialog class="my-dialog" id="groupDialog">
        <div class="my-dialog-head">
            <h3>分组管理</h3>
            <button type="button" class="my-iconbtn" id="groupDialogClose" aria-label="关闭">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="m6 6 12 12M18 6 6 18"></path></svg>
            </button>
        </div>

        <div class="my-dialog-body">
            <p class="my-msg" id="groupMsg" role="alert"></p>

            <div class="my-field">
                <label>新建分组</label>
                <div class="my-field-row">
                    <input type="text" id="groupNameNew" maxlength="30" autocomplete="off" placeholder="分组名称，如：工作">
                    <button type="button" class="my-btn is-primary" id="groupCreateBtn">创建</button>
                </div>
                <label class="my-check" style="margin-top:8px;">
                    <input type="checkbox" id="groupPrivateNew">
                    <span>私密分组（仅本人可见，分享时不包含）</span>
                </label>
                <p class="my-note">分组仅属于自己；公共分组请由管理员在后台维护。</p>
            </div>

            <div class="my-field">
                <label>我的分组</label>
                <ul class="my-group-list" id="groupList"></ul>
                <p class="my-note" id="groupListEmpty" hidden>还没有自己的分组，可先在上面创建一个。</p>
            </div>
        </div>
    </dialog>

    <script>
        (function () {
            'use strict';

            var API_SITES = 'api/user_sites.php';
            var API_AUTH = 'api/auth.php';
            var API_SHARE = 'api/share.php';
            var API_BOOKMARK = 'api/bookmark_import.php';
            var API_CATEGORIES = 'api/user_categories.php';
            var OWN_GROUPS = <?php echo json_encode(array_values($ownGroups), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;

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

                var label = button.querySelector('.my-btn-label');

                if (busy) {
                    button.dataset.idleLabel = label ? label.textContent : button.textContent;

                    if (label) {
                        label.textContent = busyLabel || '处理中…';
                    } else {
                        button.textContent = busyLabel || '处理中…';
                    }

                    button.disabled = true;
                    button.classList.add('is-busy');
                    return;
                }

                if (button.dataset.idleLabel) {
                    if (label) {
                        label.textContent = button.dataset.idleLabel;
                    } else {
                        button.textContent = button.dataset.idleLabel;
                    }

                    delete button.dataset.idleLabel;
                }

                button.disabled = false;
                button.classList.remove('is-busy');
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

            // v2.5.0：全局搜索「添加为网址」入口 —— ?add_url=网址 / ?add_url_name=名称 预填打开弹窗
            (function () {
                if (typeof URLSearchParams === 'undefined') {
                    return;
                }

                var params = new URLSearchParams(window.location.search);
                var addUrl = params.get('add_url');
                var addName = params.get('add_url_name');

                if (!addUrl && !addName) {
                    return;
                }

                try {
                    window.history.replaceState({}, '', window.location.pathname);
                } catch (error) {
                    /* 旧浏览器忽略 */

                }

                openLinkDialog(null);

                if (addUrl) {
                    linkUrl.value = addUrl;
                }

                if (addName) {
                    linkName.value = addName;
                }
            })();

            // v2.6.0：首页卡片「编辑」跳转入口 —— ?edit=网址ID 自动打开编辑弹窗
            (function () {
                if (typeof URLSearchParams === 'undefined') {
                    return;
                }

                var params = new URLSearchParams(window.location.search);
                var editId = params.get('edit');

                if (!editId) {
                    return;
                }

                try {
                    window.history.replaceState({}, '', window.location.pathname);
                } catch (error) {
                    /* 旧浏览器忽略 */
                }

                var item = document.querySelector('.my-item[data-id="' + editId + '"]');

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
            })();

            // 头像设置弹窗
            var avatarDialog = qs('#avatarDialog');
            var avatarEditBtn = qs('#avatarEditBtn');
            var avatarDialogClose = qs('#avatarDialogClose');
            var accountAvatarBtn = qs('#accountAvatarBtn');
            var accountDialog = qs('#accountDialog');

            if (avatarEditBtn && avatarDialog) {
                avatarEditBtn.addEventListener('click', function () {
                    avatarDialog.showModal();
                });
            }
            if (accountAvatarBtn && avatarDialog) {
                accountAvatarBtn.addEventListener('click', function () {
                    if (accountDialog && typeof accountDialog.close === 'function') {
                        accountDialog.close();
                    }
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

            /* ----------------------------- 底部版权（个人设置） ----------------------------- */

            var saveCopyrightBtn = qs('#saveCopyrightBtn');
            var footerCopyrightInput = qs('#footerCopyright');

            if (saveCopyrightBtn && footerCopyrightInput) {
                saveCopyrightBtn.addEventListener('click', function () {
                    setBusy(saveCopyrightBtn, true, '保存中…');

                    postForm(API_AUTH, {
                        action: 'update_profile',
                        footer_copyright: footerCopyrightInput.value.trim()
                    }).then(function (result) {
                        setBusy(saveCopyrightBtn, false);

                        if (!result.ok) {
                            showMsg(accountMsg, result.error || '保存失败');
                            return;
                        }

                        showMsg(accountMsg, '底部版权已保存', 'success');
                        // 刷新页面，让页脚立即展示新文案
                        window.setTimeout(function () { window.location.reload(); }, 700);
                    });
                });
            }

            /* ----------------------------- 分组管理 ----------------------------- */

            var groupDialog = qs('#groupDialog');
            var groupMsg = qs('#groupMsg');
            var groupList = qs('#groupList');
            var groupListEmpty = qs('#groupListEmpty');
            var groupNameNew = qs('#groupNameNew');
            var groupPrivateNew = qs('#groupPrivateNew');
            var groupCreateBtn = qs('#groupCreateBtn');
            // 分组顺序在弹窗内被调整过：关闭弹窗时刷新页面，让前台分组顺序即时同步
            var groupOrderDirty = false;

            function renderGroupList() {
                if (!groupList) { return; }

                groupList.innerHTML = '';

                if (!OWN_GROUPS.length) {
                    groupListEmpty.hidden = false;
                    return;
                }

                groupListEmpty.hidden = true;

                OWN_GROUPS.forEach(function (group, index) {
                    var li = document.createElement('li');
                    li.className = 'my-group-item';
                    li.setAttribute('data-id', group.id);

                    var lock = group.is_private ? '<span class="my-group-privacy" title="私密分组">&#x1f512;</span>' : '';

                    li.innerHTML =
                        '<span class="my-group-order" aria-label="调整顺序">' +
                            '<button type="button" class="my-group-move my-group-up" title="上移"' + (index === 0 ? ' disabled' : '') + '>' +
                                '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"><path d="m6 14 6-6 6 6"></path></svg>' +
                            '</button>' +
                            '<button type="button" class="my-group-move my-group-down" title="下移"' + (index === OWN_GROUPS.length - 1 ? ' disabled' : '') + '>' +
                                '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"><path d="m6 10 6 6 6-6"></path></svg>' +
                            '</button>' +
                        '</span>' +
                        '<input type="text" class="my-group-name" value="' + escapeHtml(group.name) + '" maxlength="30" aria-label="分组名称">' +
                        lock +
                        '<label class="my-check my-group-private-toggle" title="私密分组仅本人可见"><input type="checkbox" class="my-group-private" ' + (group.is_private ? 'checked' : '') + '> 私密</label>' +
                        '<button type="button" class="my-btn my-group-save">保存</button>' +
                        '<button type="button" class="my-btn is-ghost my-group-del">删除</button>';

                    groupList.appendChild(li);
                });
            }

            function refreshCategorySelects() {
                // 按当前顺序重建「添加网址」弹窗的分组下拉，保证与分组管理一致
                var select = qs('#linkCategory');

                if (!select) { return; }

                var current = select.value;
                select.innerHTML = '';

                var emptyOpt = document.createElement('option');
                emptyOpt.value = '0';
                emptyOpt.textContent = '未分类';
                select.appendChild(emptyOpt);

                OWN_GROUPS.forEach(function (group) {
                    var opt = document.createElement('option');
                    opt.value = group.id;
                    opt.textContent = (group.is_private ? '\ud83d\udd12 ' : '') + group.name;
                    select.appendChild(opt);
                });

                // 原选项仍存在则保持选中，否则回落到「未分类」
                select.value = Array.prototype.some.call(select.options, function (opt) { return opt.value === current; }) ? current : '0';
            }

            function submitGroupOrder() {
                var ids = OWN_GROUPS.map(function (group) { return group.id; });

                postForm(API_CATEGORIES, { action: 'reorder', ids: ids }).then(function (result) {
                    if (!result.ok) {
                        showMsg(groupMsg, result.error || '排序保存失败');
                        return;
                    }

                    groupOrderDirty = true;
                    showMsg(groupMsg, '分组顺序已保存', 'success');
                });
            }

            if (qs('#groupManageBtn') && groupDialog) {
                qs('#groupManageBtn').addEventListener('click', function () {
                    showMsg(groupMsg, '');
                    groupNameNew.value = '';
                    groupPrivateNew.checked = false;
                    renderGroupList();

                    if (typeof groupDialog.showModal === 'function') {
                        groupDialog.showModal();
                    }
                });
            }

            if (qs('#groupDialogClose') && groupDialog) {
                qs('#groupDialogClose').addEventListener('click', function () { groupDialog.close(); });
            }

            // 调整过顺序后关闭弹窗时刷新页面，前台分组展示顺序即时同步
            if (groupDialog) {
                groupDialog.addEventListener('close', function () {
                    if (groupOrderDirty) {
                        window.location.reload();
                    }
                });
            }

            if (groupCreateBtn) {
                groupCreateBtn.addEventListener('click', function () {
                    var name = groupNameNew.value.trim();

                    if (name === '') {
                        showMsg(groupMsg, '请输入分组名称');
                        return;
                    }

                    setBusy(groupCreateBtn, true, '创建中…');

                    postForm(API_CATEGORIES, {
                        action: 'create',
                        name: name,
                        is_private: groupPrivateNew.checked ? '1' : '0'
                    }).then(function (result) {
                        setBusy(groupCreateBtn, false);

                        if (!result.ok) {
                            showMsg(groupMsg, result.error || '创建失败');
                            return;
                        }

                        if (!result.reused) {
                            OWN_GROUPS.push({ id: result.id, name: result.name, is_private: result.is_private ? 1 : 0 });
                        }

                        showMsg(groupMsg, result.reused ? '已存在同名分组，直接复用' : '分组已创建', 'success');
                        groupNameNew.value = '';
                        groupPrivateNew.checked = false;
                        renderGroupList();
                        refreshCategorySelects();
                    });
                });
            }

            if (groupList) {
                groupList.addEventListener('click', function (event) {
                    var moveBtn = event.target.closest ? event.target.closest('.my-group-move') : null;

                    if (moveBtn && !moveBtn.disabled) {
                        var moveItem = moveBtn.closest('.my-group-item');
                        var moveId = moveItem ? parseInt(moveItem.getAttribute('data-id'), 10) : 0;
                        var from = -1;

                        OWN_GROUPS.forEach(function (group, idx) { if (group.id === moveId) { from = idx; } });

                        if (from >= 0) {
                            var to = moveBtn.classList.contains('my-group-up') ? from - 1 : from + 1;

                            if (to >= 0 && to < OWN_GROUPS.length) {
                                var temp = OWN_GROUPS[from];
                                OWN_GROUPS[from] = OWN_GROUPS[to];
                                OWN_GROUPS[to] = temp;

                                renderGroupList();
                                refreshCategorySelects();
                                submitGroupOrder();
                            }
                        }

                        return;
                    }

                    var item = event.target.closest ? event.target.closest('.my-group-item') : null;

                    if (!item) { return; }

                    var id = parseInt(item.getAttribute('data-id'), 10);

                    if (event.target.classList.contains('my-group-del')) {
                        if (!window.confirm('删除该分组？分组下的网址会移到「未分类」，不会被删除。')) {
                            return;
                        }

                        postForm(API_CATEGORIES, { action: 'delete', id: id }).then(function (result) {
                            if (!result.ok) {
                                showMsg(groupMsg, result.error || '删除失败');
                                return;
                            }

                            showMsg(groupMsg, '分组已删除', 'success');
                            window.setTimeout(function () { window.location.reload(); }, 800);
                        });
                    }
                });

                groupList.addEventListener('change', function (event) {
                    if (!event.target.classList.contains('my-group-private')) { return; }

                    var item = event.target.closest ? event.target.closest('.my-group-item') : null;
                    var id = item ? parseInt(item.getAttribute('data-id'), 10) : 0;

                    if (!id) { return; }

                    postForm(API_CATEGORIES, {
                        action: 'set_private',
                        id: id,
                        is_private: event.target.checked ? '1' : '0'
                    }).then(function (result) {
                        if (!result.ok) {
                            showMsg(groupMsg, result.error || '设置失败');
                            event.target.checked = !event.target.checked;
                            return;
                        }

                        var found = null;
                        OWN_GROUPS.forEach(function (group) { if (group.id === id) { found = group; } });
                        if (found) { found.is_private = event.target.checked ? 1 : 0; }

                        renderGroupList();
                        refreshCategorySelects();
                        showMsg(groupMsg, event.target.checked ? '已设为私密分组' : '已取消私密', 'success');
                    });
                });

                groupList.addEventListener('click', function (event) {
                    if (!event.target.classList.contains('my-group-save')) { return; }

                    var item = event.target.closest ? event.target.closest('.my-group-item') : null;
                    var input = item ? item.querySelector('.my-group-name') : null;
                    var id = item ? parseInt(item.getAttribute('data-id'), 10) : 0;
                    var name = input ? input.value.trim() : '';

                    if (!id || name === '') {
                        showMsg(groupMsg, '请输入分组名称');
                        return;
                    }

                    postForm(API_CATEGORIES, { action: 'rename', id: id, name: name }).then(function (result) {
                        if (!result.ok) {
                            showMsg(groupMsg, result.error || '重命名失败');
                            return;
                        }

                        OWN_GROUPS.forEach(function (group) { if (group.id === id) { group.name = name; } });
                        renderGroupList();
                        refreshCategorySelects();
                        showMsg(groupMsg, '已保存', 'success');
                        window.setTimeout(function () { window.location.reload(); }, 800);
                    });
                });
            }

            toTop();
            updatePickCount();

            /* ----------------------------------------------------------------------
               v2.6.0 · 卡片交互：收藏 / 快捷收藏 / 复制 / 二维码 / 移动 / 菜单
               ---------------------------------------------------------------------- */

            var quickAddDialog = qs('#quickAddDialog');
            var quickAddUrl = qs('#quickAddUrl');
            var quickAddMsg = qs('#quickAddMsg');
            var quickAddPreview = qs('#quickAddPreview');
            var quickAddCategory = qs('#quickAddCategory');
            var qrDialog = qs('#qrDialog');
            var qrImage = qs('#qrImage');
            var qrUrl = qs('#qrUrl');
            var moveDialog = qs('#moveDialog');
            var moveCategory = qs('#moveCategory');
            var moveMsg = qs('#moveMsg');
            var contextMenu = qs('#cardContextMenu');
            var contextTarget = null;
            var longPressTimer = null;

            function openDialog2(dialog) {
                if (!dialog) { return; }

                if (typeof dialog.showModal === 'function') {
                    dialog.showModal();
                } else {
                    dialog.setAttribute('open', 'open');
                }
            }

            function closeDialog2(dialog) {
                if (!dialog) { return; }

                if (typeof dialog.close === 'function') {
                    dialog.close();
                } else {
                    dialog.removeAttribute('open');
                }
            }

            function copyText(text) {
                if (navigator.clipboard && navigator.clipboard.writeText) {
                    return navigator.clipboard.writeText(text);
                }

                var ta = document.createElement('textarea');
                ta.value = text;
                ta.style.position = 'fixed';
                ta.style.opacity = '0';
                document.body.appendChild(ta);
                ta.select();

                try {
                    document.execCommand('copy');
                } catch (error) {
                    /* 忽略 */
                }

                document.body.removeChild(ta);
                return Promise.resolve();
            }

            function showToast(text) {
                var toast = document.createElement('div');
                toast.textContent = text;
                toast.style.cssText = 'position:fixed;left:50%;bottom:48px;transform:translateX(-50%);z-index:10000;padding:10px 20px;border-radius:10px;background:rgba(15,23,42,0.9);color:#fff;font-size:14px;box-shadow:0 8px 24px rgba(0,0,0,0.2);opacity:0;transition:opacity 0.2s;';
                document.body.appendChild(toast);
                requestAnimationFrame(function () { toast.style.opacity = '1'; });
                setTimeout(function () {
                    toast.style.opacity = '0';
                    setTimeout(function () { toast.remove(); }, 200);
                }, 1600);
            }

            function getItemData(item) {
                return {
                    id: item.getAttribute('data-id'),
                    url: item.getAttribute('data-url'),
                    name: item.getAttribute('data-name'),
                    category: item.getAttribute('data-category'),
                    starred: item.getAttribute('data-starred') === '1'
                };
            }

            function updateStarIcon(item, starred) {
                var star = item.querySelector('[data-action="star"]');

                if (!star) { return; }

                var svg = star.querySelector('svg');

                if (svg) {
                    svg.setAttribute('fill', starred ? 'currentColor' : 'none');
                }

                var label = starred ? '取消收藏' : '收藏';
                star.setAttribute('aria-label', label);
                star.setAttribute('title', label);
            }

            function toggleStar(item) {
                var data = getItemData(item);
                var next = data.starred ? 0 : 1;

                postForm(API_SITES, { action: 'star', id: data.id, star: next }).then(function (result) {
                    if (result.ok) {
                        item.setAttribute('data-starred', String(result.is_starred));
                        updateStarIcon(item, result.is_starred);
                    } else {
                        showToast(result.error || '操作失败');
                    }
                });
            }

            function showQrDialog(url) {
                if (!qrDialog || !qrImage) { return; }

                qrImage.src = 'https://api.qrserver.com/v1/create-qr-code/?size=220x220&margin=8&data=' + encodeURIComponent(url);

                if (qrUrl) {
                    qrUrl.textContent = url;
                }

                openDialog2(qrDialog);
            }

            function openMoveDialog(item) {
                if (!moveDialog) { return; }

                contextTarget = item;
                var data = getItemData(item);

                if (moveCategory) {
                    moveCategory.value = data.category || '0';
                }

                if (moveMsg) {
                    showMsg(moveMsg, '', '');
                }

                openDialog2(moveDialog);
            }

            // 卡片操作按钮（事件委托）
            document.addEventListener('click', function (event) {
                var actionEl = event.target.closest ? event.target.closest('[data-action]') : null;

                if (!actionEl) { return; }

                var item = actionEl.closest('.my-item');

                if (!item) { return; }

                event.preventDefault();
                event.stopPropagation();

                var action = actionEl.getAttribute('data-action');
                var data = getItemData(item);

                switch (action) {
                    case 'star':
                        toggleStar(item);
                        break;
                    case 'copy':
                        copyText(data.url).then(function () { showToast('链接已复制'); });
                        break;
                    case 'qrcode':
                        showQrDialog(data.url);
                        break;
                    case 'move':
                        openMoveDialog(item);
                        break;
                }
            });

            // 右键菜单
            document.addEventListener('contextmenu', function (event) {
                var item = event.target.closest ? event.target.closest('.my-item') : null;

                if (!item) { return; }

                event.preventDefault();
                contextTarget = item;

                if (contextMenu) {
                    contextMenu.hidden = false;
                    var x = event.clientX;
                    var y = event.clientY;
                    var rect = contextMenu.getBoundingClientRect();

                    if (x + rect.width > window.innerWidth) { x = window.innerWidth - rect.width - 8; }
                    if (y + rect.height > window.innerHeight) { y = window.innerHeight - rect.height - 8; }

                    contextMenu.style.left = Math.max(8, x) + 'px';
                    contextMenu.style.top = Math.max(8, y) + 'px';
                }
            });

            // 长按菜单（移动端）
            qsa('.my-item').forEach(function (item) {
                item.addEventListener('touchstart', function () {
                    longPressTimer = setTimeout(function () {
                        contextTarget = item;

                        if (contextMenu) {
                            contextMenu.hidden = false;
                            var rect = item.getBoundingClientRect();
                            var x = rect.left + rect.width / 2;
                            var y = rect.top + rect.height / 2;
                            var menuRect = contextMenu.getBoundingClientRect();

                            if (x + menuRect.width > window.innerWidth) { x = window.innerWidth - menuRect.width - 8; }
                            if (y + menuRect.height > window.innerHeight) { y = window.innerHeight - menuRect.height - 8; }

                            contextMenu.style.left = Math.max(8, x) + 'px';
                            contextMenu.style.top = Math.max(8, y) + 'px';
                        }
                    }, 500);
                }, { passive: true });

                item.addEventListener('touchmove', function () {
                    if (longPressTimer) { clearTimeout(longPressTimer); longPressTimer = null; }
                }, { passive: true });

                item.addEventListener('touchend', function () {
                    if (longPressTimer) { clearTimeout(longPressTimer); longPressTimer = null; }
                });
            });

            // 菜单点击
            if (contextMenu) {
                contextMenu.addEventListener('click', function (event) {
                    var btn = event.target.closest ? event.target.closest('.ccm-item') : null;

                    if (!btn || !contextTarget) { return; }

                    var action = btn.getAttribute('data-action');
                    var data = getItemData(contextTarget);
                    var target = contextTarget;

                    contextMenu.hidden = true;

                    switch (action) {
                        case 'open':
                            window.open(data.url, '_blank', 'noopener,noreferrer');
                            break;
                        case 'copy':
                            copyText(data.url).then(function () { showToast('链接已复制'); });
                            break;
                        case 'qrcode':
                            showQrDialog(data.url);
                            break;
                        case 'edit':
                            openLinkDialog({
                                id: data.id,
                                url: data.url,
                                name: data.name,
                                description: target.getAttribute('data-description'),
                                categoryId: data.category,
                                logo: target.getAttribute('data-logo') || ''
                            });
                            break;
                        case 'move':
                            openMoveDialog(target);
                            break;
                        case 'delete':
                            if (confirm('确定删除「' + data.name + '」吗？')) {
                                postForm(API_SITES, { action: 'delete', id: data.id }).then(function (result) {
                                    if (result.ok) {
                                        target.remove();
                                    } else {
                                        alert(result.error || '删除失败');
                                    }
                                });
                            }
                            break;
                    }
                });
            }

            // 点击空白关闭菜单
            document.addEventListener('click', function (event) {
                if (contextMenu && !contextMenu.hidden && !contextMenu.contains(event.target)) {
                    contextMenu.hidden = true;
                }
            });

            document.addEventListener('scroll', function () {
                if (contextMenu) {
                    contextMenu.hidden = true;
                }
            }, true);

            // 快捷收藏弹窗
            var quickAddBtn = qs('#quickAddBtn');

            if (quickAddBtn) {
                quickAddBtn.addEventListener('click', function () {
                    if (quickAddUrl) { quickAddUrl.value = ''; }
                    if (quickAddPreview) { quickAddPreview.hidden = true; }
                    if (quickAddMsg) { showMsg(quickAddMsg, '', ''); }

                    openDialog2(quickAddDialog);
                    setTimeout(function () { if (quickAddUrl) { quickAddUrl.focus(); } }, 50);
                });
            }

            function doQuickAdd() {
                var url = quickAddUrl ? quickAddUrl.value.trim() : '';

                if (url === '') {
                    showMsg(quickAddMsg, '请输入网址');
                    return;
                }

                showMsg(quickAddMsg, '正在抓取并收藏…', 'success');

                postForm(API_SITES, {
                    action: 'quick_add',
                    url: url,
                    category_id: quickAddCategory ? quickAddCategory.value : 0
                }).then(function (result) {
                    if (!result.ok) {
                        showMsg(quickAddMsg, result.error || '收藏失败');
                        return;
                    }

                    if (quickAddPreview) {
                        var nameEl = quickAddPreview.querySelector('.qa-name');
                        var descEl = quickAddPreview.querySelector('.qa-desc');
                        var favEl = quickAddPreview.querySelector('.qa-favicon');
                        var letterEl = quickAddPreview.querySelector('.qa-icon-letter');

                        if (nameEl) { nameEl.textContent = result.name || url; }
                        if (descEl) { descEl.textContent = (result.meta && result.meta.description) ? result.meta.description : ''; }

                        if (favEl && result.meta && result.meta.icons && result.meta.icons[0]) {
                            favEl.src = result.meta.icons[0];
                            favEl.style.display = '';
                            if (letterEl) { letterEl.style.display = 'none'; }
                        } else if (favEl) {
                            favEl.style.display = 'none';
                            if (letterEl) {
                                letterEl.style.display = '';
                                letterEl.textContent = (result.name || url).charAt(0).toUpperCase();
                            }
                        }

                        quickAddPreview.hidden = false;
                    }

                    showMsg(quickAddMsg, result.message || ('已收藏：' + (result.name || url)), 'success');
                    setTimeout(function () { closeDialog2(quickAddDialog); window.location.reload(); }, 1200);
                });
            }

            if (quickAddDialog) {
                var qaClose = qs('#quickAddClose');
                var qaCancel = qs('#quickAddCancel');
                var qaSubmit = qs('#quickAddSubmit');

                if (qaClose) { qaClose.addEventListener('click', function () { closeDialog2(quickAddDialog); }); }
                if (qaCancel) { qaCancel.addEventListener('click', function () { closeDialog2(quickAddDialog); }); }
                if (qaSubmit) { qaSubmit.addEventListener('click', doQuickAdd); }

                if (quickAddUrl) {
                    quickAddUrl.addEventListener('keydown', function (e) {
                        if (e.key === 'Enter') {
                            e.preventDefault();
                            doQuickAdd();
                        }
                    });
                }
            }

            // 二维码弹窗
            if (qrDialog) {
                var qc1 = qs('#qrClose');
                var qc2 = qs('#qrClose2');
                var qCopy = qs('#qrCopyBtn');

                if (qc1) { qc1.addEventListener('click', function () { closeDialog2(qrDialog); }); }
                if (qc2) { qc2.addEventListener('click', function () { closeDialog2(qrDialog); }); }
                if (qCopy) {
                    qCopy.addEventListener('click', function () {
                        if (qrUrl) {
                            copyText(qrUrl.textContent).then(function () { showToast('链接已复制'); });
                        }
                    });
                }
            }

            // 移动分组弹窗
            if (moveDialog) {
                var mc1 = qs('#moveClose');
                var mCancel = qs('#moveCancel');
                var mSubmit = qs('#moveSubmit');

                if (mc1) { mc1.addEventListener('click', function () { closeDialog2(moveDialog); }); }
                if (mCancel) { mCancel.addEventListener('click', function () { closeDialog2(moveDialog); }); }

                if (mSubmit) {
                    mSubmit.addEventListener('click', function () {
                        if (!contextTarget) { return; }

                        var data = getItemData(contextTarget);

                        postForm(API_SITES, {
                            action: 'move',
                            id: data.id,
                            category_id: moveCategory ? moveCategory.value : 0
                        }).then(function (result) {
                            if (result.ok) {
                                showMsg(moveMsg, '已移动', 'success');
                                setTimeout(function () { closeDialog2(moveDialog); window.location.reload(); }, 600);
                            } else {
                                showMsg(moveMsg, result.error || '移动失败');
                            }
                        });
                    });
                }
            }
        })();
    </script>
<script src="assets/js/search.js?v=2.6.1" defer></script>

    <!-- 卡片右键 / 长按菜单 -->
    <div class="card-context-menu" id="cardContextMenu" hidden>
        <button type="button" class="ccm-item" data-action="open"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M14 4h6v6M20 4l-9 9M18 14v5a1 1 0 0 1-1 1H5a1 1 0 0 1-1-1V7a1 1 0 0 1 1-1h5"></path></svg>新标签打开</button>
        <button type="button" class="ccm-item" data-action="copy"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="9" y="9" width="13" height="13" rx="2"></rect><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"></path></svg>复制链接</button>
        <button type="button" class="ccm-item" data-action="qrcode"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="3" width="7" height="7"></rect><rect x="14" y="3" width="7" height="7"></rect><rect x="3" y="14" width="7" height="7"></rect><path d="M14 14h3v3M21 14v7M14 21h3M17 17h4"></path></svg>二维码</button>
        <button type="button" class="ccm-item" data-action="edit"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 20h4l10-10-4-4L4 16v4z"></path><path d="M14 6l4 4"></path></svg>编辑</button>
        <button type="button" class="ccm-item" data-action="move"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 4h14M5 12h14M5 20h14"></path><path d="M9 8l3-3 3 3M9 16l3 3 3-3"></path></svg>移动分组</button>
        <button type="button" class="ccm-item ccm-danger" data-action="delete"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M4 7h16M9 7V5h6v2M6 7l1 13h10l1-13"></path></svg>删除</button>
    </div>
</body>
</html>
