<?php
/**
 * 轩宇-导航 · 我的导航（前台用户个人导航管理）
 */
require_once __DIR__ . '/includes/bootstrap.php';
require_once 'includes/functions.php';
require_once 'includes/Services.php';
require_once __DIR__ . '/includes/site_links.php';

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
                    // 存盘后立即居中裁成正方形：横幅/竖图都能正确落在 26×26 的头像框里，
                    // 否则 object-fit: cover 会把主体裁到框外，看起来像"头像位置不对"。
                    $functions->normalizeAvatarFile($destPath, $mimeType);

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

// ------------------------------------------------------------------
// 前台用户自助管理 API 令牌（复用 api_tokens 表，user_id 绑定当前用户）
// ------------------------------------------------------------------
$tokenMsg = '';
$tokenError = '';
$newTokenPlain = '';   // 仅创建 / 重置时在本请求内显示一次明文

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['token_action'])) {
    $functions->requireSameOrigin();
    $tokenAction = (string) $_POST['token_action'];

    if ($tokenAction === 'create_user_token') {
        $name = trim((string) ($_POST['token_name'] ?? ''));
        $permissions = (string) ($_POST['token_permissions'] ?? 'read');
        $expiresOption = trim((string) ($_POST['token_expires'] ?? 'never'));

        if ($name === '') {
            $tokenError = '令牌名称不能为空';
        } elseif (!in_array($permissions, ['read', 'write'], true)) {
            $tokenError = '权限参数不合法';
        } else {
            $name = $functions->textCut($name, 100);
            $expiresValue = null;

            if ($expiresOption === '30') {
                $expiresValue = date('Y-m-d H:i:s', strtotime('+30 days'));
            } elseif ($expiresOption === '90') {
                $expiresValue = date('Y-m-d H:i:s', strtotime('+90 days'));
            } elseif ($expiresOption === '365') {
                $expiresValue = date('Y-m-d H:i:s', strtotime('+1 year'));
            }

            try {
                $plainToken = bin2hex(random_bytes(32));
            } catch (Throwable $e) {
                $plainToken = md5(uniqid('', true)) . md5(uniqid('', true));
            }

            // 库里只存 SHA-256 摘要，明文仅本次响应显示一次。
            $tokenDigest = hash('sha256', $plainToken);

            $stmt = $db->prepare(
                'INSERT INTO api_tokens (token, name, permissions, user_id, expires_at, created_at)
                 VALUES (?, ?, ?, ?, ?, NOW())'
            );

            try {
                $stmt->execute([$tokenDigest, $name, $permissions, $userId, $expiresValue]);
                $newTokenPlain = $plainToken;
                $tokenMsg = '令牌创建成功，请立即复制保存（明文仅本次显示）';
            } catch (Throwable $e) {
                $tokenError = '令牌创建失败：' . $e->getMessage();
            }
        }
    } elseif ($tokenAction === 'toggle_user_token_perm') {
        $id = (int) ($_POST['token_id'] ?? 0);
        $stmt = $db->prepare('SELECT permissions FROM api_tokens WHERE id = ? AND user_id = ? LIMIT 1');
        $stmt->execute([$id, $userId]);
        $row = $stmt->fetch();

        if (!$row) {
            $tokenError = '令牌不存在';
        } else {
            $next = strtolower((string) $row['permissions']) === 'write' ? 'read' : 'write';
            $upd = $db->prepare('UPDATE api_tokens SET permissions = ? WHERE id = ? AND user_id = ?');
            $upd->execute([$next, $id, $userId]);
            $tokenMsg = '令牌权限已切换为「' . ($next === 'write' ? '读写' : '只读') . '」';
        }
    } elseif ($tokenAction === 'delete_user_token') {
        $id = (int) ($_POST['token_id'] ?? 0);
        $stmt = $db->prepare('DELETE FROM api_tokens WHERE id = ? AND user_id = ?');
        $stmt->execute([$id, $userId]);
        $tokenMsg = '令牌已删除';
    } elseif ($tokenAction === 'reset_user_token') {
        $id = (int) ($_POST['token_id'] ?? 0);
        $stmt = $db->prepare('SELECT id FROM api_tokens WHERE id = ? AND user_id = ? LIMIT 1');
        $stmt->execute([$id, $userId]);

        if (!$stmt->fetch()) {
            $tokenError = '令牌不存在';
        } else {
            try {
                $plainToken = bin2hex(random_bytes(32));
            } catch (Throwable $e) {
                $plainToken = md5(uniqid('', true)) . md5(uniqid('', true));
            }

            $tokenDigest = hash('sha256', $plainToken);
            $upd = $db->prepare('UPDATE api_tokens SET token = ?, last_used_at = NULL WHERE id = ? AND user_id = ?');
            $upd->execute([$tokenDigest, $id, $userId]);
            $newTokenPlain = $plainToken;
            $tokenMsg = '令牌已重置，旧令牌立即失效，请复制新的 Token（明文仅本次显示）';
        }
    }
}

// ==========================================================================
// v2.16.0 · 回收站（仅本人的网址）
// 全部操作都带 user_id 条件，确保用户只能看到 / 恢复 / 删除自己的记录。
// ==========================================================================
$recycleMsg = '';
$recycleError = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['recycle_action'])) {
    $functions->requireSameOrigin();

    $recycleAction = (string) $_POST['recycle_action'];

    try {
        if ($recycleAction === 'restore') {
            $ids = array_map('intval', (array) ($_POST['recycle_ids'] ?? []));
            $ids = array_values(array_filter($ids, function ($v) { return $v > 0; }));

            if (!$ids) {
                $recycleError = '请先选择要恢复的网址';
            } else {
                $placeholders = implode(',', array_fill(0, count($ids), '?'));
                $stmt = $db->prepare(
                    "UPDATE nav_links SET deleted_at = NULL
                     WHERE user_id = ? AND deleted_at IS NOT NULL AND id IN ($placeholders)"
                );
                $stmt->execute(array_merge([$userId], $ids));
                $recycleMsg = '已恢复 ' . $stmt->rowCount() . ' 个网址';
            }
        } elseif ($recycleAction === 'purge') {
            $ids = array_map('intval', (array) ($_POST['recycle_ids'] ?? []));
            $ids = array_values(array_filter($ids, function ($v) { return $v > 0; }));

            if (!$ids) {
                $recycleError = '请先选择要彻底删除的网址';
            } else {
                $placeholders = implode(',', array_fill(0, count($ids), '?'));
                // 先取 logo，彻底删除后一并清理本地图标文件
                $logoStmt = $db->prepare(
                    "SELECT logo FROM nav_links
                     WHERE user_id = ? AND deleted_at IS NOT NULL AND id IN ($placeholders)"
                );
                $logoStmt->execute(array_merge([$userId], $ids));
                $logos = $logoStmt->fetchAll(PDO::FETCH_COLUMN);

                $stmt = $db->prepare(
                    "DELETE FROM nav_links
                     WHERE user_id = ? AND deleted_at IS NOT NULL AND id IN ($placeholders)"
                );
                $stmt->execute(array_merge([$userId], $ids));
                $purged = $stmt->rowCount();

                foreach ($logos as $logoPath) {
                    if (!empty($logoPath)) {
                        $functions->deleteLogoFile((string) $logoPath);
                    }
                }

                $recycleMsg = '已彻底删除 ' . $purged . ' 个网址';
            }
        } elseif ($recycleAction === 'empty') {
            $logoStmt = $db->prepare('SELECT logo FROM nav_links WHERE user_id = ? AND deleted_at IS NOT NULL');
            $logoStmt->execute([$userId]);
            $logos = $logoStmt->fetchAll(PDO::FETCH_COLUMN);

            $stmt = $db->prepare('DELETE FROM nav_links WHERE user_id = ? AND deleted_at IS NOT NULL');
            $stmt->execute([$userId]);
            $purged = $stmt->rowCount();

            foreach ($logos as $logoPath) {
                if (!empty($logoPath)) {
                    $functions->deleteLogoFile((string) $logoPath);
                }
            }

            $recycleMsg = $purged > 0 ? '回收站已清空，共彻底删除 ' . $purged . ' 个网址' : '回收站已经是空的';
        }
    } catch (Throwable $e) {
        $recycleError = '操作失败：数据库尚未升级到 v2 结构时回收站不可用';
    }
}

/**
 * 读取当前用户回收站里的网址（已软删除）。
 */
function myLoadRecycleItems($db, $userId)
{
    try {
        $stmt = $db->prepare(
            'SELECT nl.id, nl.name, nl.url, nl.logo, nl.description, nl.deleted_at, c.name AS category_name
             FROM nav_links nl
             LEFT JOIN categories c ON nl.category_id = c.id
             WHERE nl.user_id = ? AND nl.deleted_at IS NOT NULL
             ORDER BY nl.deleted_at DESC, nl.id DESC
             LIMIT 500'
        );
        $stmt->execute([$userId]);
        $rows = $stmt->fetchAll();
    } catch (Throwable $e) {
        return [];
    }

    $items = [];

    foreach ($rows as $row) {
        $host = parse_url((string) $row['url'], PHP_URL_HOST);
        $items[] = [
            'id' => (int) $row['id'],
            'name' => (string) $row['name'],
            'url' => (string) $row['url'],
            'host' => $host ? preg_replace('/^www\./i', '', $host) : (string) $row['url'],
            'category' => (string) ($row['category_name'] ?? ''),
            'deleted_at' => (string) $row['deleted_at'],
        ];
    }

    return $items;
}

$recycleItems = myLoadRecycleItems($db, $userId);

// 读取当前用户的令牌列表（仅本人，看不到其他用户或管理员的令牌）
$userTokens = [];
try {
    $stmt = $db->prepare(
        'SELECT id, name, permissions, user_id, created_at, expires_at, last_used_at
         FROM api_tokens WHERE user_id = ? ORDER BY id DESC'
    );
    $stmt->execute([$userId]);
    $userTokens = $stmt->fetchAll();
} catch (Throwable $error) {
    $userTokens = [];
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
        'SELECT id, name, url, logo, description, category_id, sort_order, clicks, check_status, latency_ms, is_starred, tags, alias
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
 */$faviconFor = function ($logoPath, $url) use ($functions) {
    $chain = [];
    $logoUrl = $functions->getLogoDisplayUrl((string) $logoPath);

    if ($logoUrl !== '') {
        $chain[] = $logoUrl;
    }

    $host = parse_url((string) $url, PHP_URL_HOST);

    if ($host) {
        $scheme = strtolower((string) parse_url((string) $url, PHP_URL_SCHEME)) === 'http' ? 'http' : 'https';
        $chain[] = $scheme . '://' . $host . '/favicon.ico';
        $chain[] = 'https://api.iowen.cn/favicon/' . $host . '.png';
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
        // v2.16.3：logo 原值必须单独带上——列表的 data-logo 靠它回填编辑弹窗。
        // 此前只给了 favicons（渲染用的兜底链），data-logo 一直是空串，
        // 导致「打开编辑 → 不改图标 → 保存」会把已有的图标覆盖成空。
        'logo' => (string) ($row['logo'] ?? ''),
        'favicons' => $faviconFor($row['logo'] ?? '', $url),
        'tags' => (string) ($row['tags'] ?? ''),
        'alias' => (string) ($row['alias'] ?? ''),
        // v2.18.6：此前重构 $item 漏带 is_starred，data-starred 恒为 0，
        // 导致「我的导航」行内星标与长按菜单文案无法反映真实收藏状态、刷新后回退。
        'is_starred' => !empty($row['is_starred']) ? 1 : 0,
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
    <link rel="stylesheet" href="assets/css/nav.css?v=2.18.6">
    <link rel="stylesheet" href="assets/css/category-quick-add.css?v=2.18.6">
    <script src="assets/js/category-quick-add.js?v=2.18.6" defer></script>
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

        /* v2.14.0 · 顶部分组 tab 栏：与分组管理弹窗、5 个分组下拉共用 OWN_GROUPS 数据源，
           新建 / 重命名 / 私密 / 删除 / 排序后即时重建，不再需要手动刷新页面。 */
        .my-group-tabs {
            display: flex;
            align-items: center;
            gap: 8px;
            margin: -4px 0 20px;
            min-width: 0;
        }

        .my-group-tabs-track {
            display: flex;
            align-items: center;
            gap: 6px;
            overflow-x: auto;
            padding: 3px 2px 6px;
            scrollbar-width: none;
            flex: 1 1 auto;
            min-width: 0;
        }

        .my-group-tabs-track::-webkit-scrollbar { display: none; }

        .my-group-tab {
            flex: 0 0 auto;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            height: 32px;
            padding: 0 13px;
            border: 1px solid var(--border);
            border-radius: var(--radius-pill);
            background: var(--tab-bg);
            color: var(--muted);
            font-size: 12.5px;
            line-height: 1;
            white-space: nowrap;
            cursor: pointer;
            transition: background .16s ease, color .16s ease, border-color .16s ease;
        }

        .my-group-tab:hover { background: var(--tab-bg-hover); color: var(--text); }

        .my-group-tab:focus-visible { outline: 2px solid var(--primary); outline-offset: 2px; }

        .my-group-tab.is-active {
            background: var(--primary);
            border-color: var(--primary);
            color: var(--primary-contrast);
            font-weight: 600;
        }

        .my-group-tab .mgt-count {
            font-size: 11px;
            opacity: .75;
        }

        .my-group-tabs-hint {
            flex: 0 0 auto;
            color: var(--muted);
            font-size: 11.5px;
            white-space: nowrap;
        }

        @media (max-width: 720px) {
            .my-group-tabs { margin-bottom: 14px; }
            .my-group-tabs-hint { display: none; }
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
            /* 与顶部头像统一：正圆形 */
            border-radius: 50%;
            background: linear-gradient(135deg, var(--primary), #7c3aed);
            color: #fff;
            font-size: 14px;
            font-weight: 700;
        }

        .my-avatar-img {
            display: block;
            width: 34px;
            height: 34px;
            min-width: 34px;
            max-width: 34px;
            flex: 0 0 34px;
            /* 与顶部头像统一：正圆形 */
            border-radius: 50%;
            object-fit: cover;
            object-position: center;
            background: var(--surface-2);
            box-shadow: inset 0 0 0 1px var(--hairline, rgba(26, 26, 23, 0.06));
        }

        .my-avatar-edit {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 24px;
            height: 24px;
            padding: 0;
            border: none;
            border-radius: 50%;
            background: var(--surface-2);
            color: var(--text-2);
            cursor: pointer;
            transition: background 0.16s ease, color 0.16s ease;
        }

        /* 内联 SVG 取代 Font Awesome：前台只需 3 个图标，
           不值得为它们引入 102KB 的图标库 CSS + 字体文件 */
        .my-avatar-edit svg {
            width: 13px;
            height: 13px;
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

        .my-item.is-drop-before {
            box-shadow: inset 0 3px 0 var(--primary);
        }

        .my-item.is-drop-after {
            box-shadow: inset 0 -3px 0 var(--primary);
        }

        .my-group.is-drop-before > .my-group-head {
            box-shadow: inset 0 3px 0 var(--primary);
        }

        .my-group.is-drop-after > .my-group-head {
            box-shadow: inset 0 -3px 0 var(--primary);
        }

        .my-group.is-dragging {
            opacity: 0.5;
        }

        .my-inline-input {
            width: 100%;
            min-width: 120px;
            padding: 4px 8px;
            border: 1px solid var(--primary);
            border-radius: 6px;
            background: var(--surface);
            color: var(--text);
            font-size: inherit;
            font-family: inherit;
            outline: none;
        }

        .my-toast {
            position: fixed;
            left: 50%;
            bottom: 48px;
            transform: translateX(-50%) translateY(10px);
            z-index: 10000;
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 10px 18px;
            border-radius: 10px;
            background: rgba(15, 23, 42, 0.92);
            color: #fff;
            font-size: 14px;
            box-shadow: 0 8px 24px rgba(0, 0, 0, 0.25);
            opacity: 0;
            transition: opacity 0.2s, transform 0.2s;
            pointer-events: none;
        }

        .my-toast.show {
            opacity: 1;
            transform: translateX(-50%) translateY(0);
            pointer-events: auto;
        }

        .my-toast-undo {
            border: 1px solid rgba(255, 255, 255, 0.4);
            background: transparent;
            color: #fff;
            font-size: 13px;
            padding: 3px 12px;
            border-radius: 6px;
            cursor: pointer;
            white-space: nowrap;
        }

        .my-toast-undo:hover {
            background: rgba(255, 255, 255, 0.15);
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

        /* 操作按钮默认不占布局空间：列表只剩「名称 + 域名」。
           桌面端 hover / 键盘聚焦才浮现；触屏与窄屏完全隐藏，改走长按菜单。
           以前 7 个 34px 按钮常驻，390px 下把 .my-info 挤到只剩 16px，
           名称与域名都被截成一两个字 —— 问题 1、2 其实是同一个根因。 */
        .my-item { position: relative; }

        .my-item-actions {
            position: absolute;
            right: 8px;
            top: 50%;
            transform: translateY(-50%);
            display: flex;
            align-items: center;
            gap: 4px;
            padding-left: 22px;
            background: linear-gradient(90deg, transparent, var(--surface) 24%);
            opacity: 0;
            visibility: hidden;
            pointer-events: none;
            transition: opacity 0.16s ease, visibility 0.16s ease;
        }

        /* 键盘聚焦始终可用，不依赖设备是否支持 hover（WCAG 键盘可达） */
        .my-item:focus-within .my-item-actions {
            opacity: 1;
            visibility: visible;
            pointer-events: auto;
        }

        @media (hover: hover) and (pointer: fine) {
            .my-item:hover .my-item-actions {
                opacity: 1;
                visibility: visible;
                pointer-events: auto;
            }
        }

        /* 按「输入能力」而不是「屏幕宽度」区分：触屏没有 hover，
           按钮一律隐藏走长按菜单；桌面浏览器即使缩到窄窗口仍可 hover。 */
        @media (hover: none) {
            .my-item-actions { display: none !important; }
        }

        /* 窄屏排版：名称最多两行，域名完整换行，不再压缩到无法识别 */
        @media (max-width: 720px) {
            .my-link-name {
                display: -webkit-box;
                -webkit-line-clamp: 2;
                -webkit-box-orient: vertical;
                overflow: hidden;
                white-space: normal;
                line-height: 1.4;
            }

            .my-link-url {
                white-space: normal;
                overflow-wrap: anywhere;
                line-height: 1.35;
            }

            .my-item { padding-right: 12px; }
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
            justify-content: center;
            flex-wrap: wrap;
            gap: 10px 14px;
            max-width: calc(100vw - 32px);
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

        #batchBar {
            display: none;
        }

        body[data-batch="1"] #batchBar {
            display: flex;
        }

        body[data-batch="1"] .my-picker {
            display: grid;
        }

        /* 整理模式：显示拖拽手柄 */
        body[data-organize="1"] .my-handle {
            display: grid;
        }

        body:not([data-organize="1"]) .my-handle {
            display: none;
        }

        /* 标签 */
        .my-tags {
            display: block;
            margin-top: 4px;
            line-height: 1.4;
        }
        .my-tag {
            display: inline-block;
            font-size: 11px;
            padding: 1px 6px;
            margin: 0 3px 3px 0;
            border-radius: 3px;
            background: rgba(0,0,0,0.06);
            color: #555;
        }
        [data-theme="dark"] .my-tag {
            background: rgba(255,255,255,0.1);
            color: #bbb;
        }

        /* 整理态下卡片可拖拽的视觉提示 */
        body[data-organize="1"] .my-item {
            cursor: grab;
        }

        body[data-organize="1"] .my-item:active {
            cursor: grabbing;
        }

        .my-radio {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            margin-right: 16px;
            color: var(--text-2);
            font-size: 13px;
            cursor: pointer;
        }

        .my-radio input {
            width: 15px;
            height: 15px;
            accent-color: var(--primary);
        }

        .my-batch-all {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            color: var(--text-2);
            font-size: 13px;
            cursor: pointer;
            user-select: none;
        }

        .my-batch-all input {
            width: 16px;
            height: 16px;
            accent-color: var(--primary);
        }

        .my-batch-info {
            margin: 0 0 14px;
            color: var(--text-2);
            font-size: 13px;
        }

        .my-batch-info strong {
            color: var(--primary);
        }

        .my-btn.is-danger {
            border-color: rgba(220, 38, 38, 0.35);
            background: var(--danger);
            color: #fff;
        }

        .my-btn.is-danger:hover {
            border-color: var(--danger);
            background: #b91c1c;
            color: #fff;
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

        /* 弹窗底部按钮栏：统一右对齐，主按钮在最右 */
        .my-dialog-actions {
            display: flex;
            flex-wrap: wrap;
            justify-content: flex-end;
            align-items: center;
            gap: 10px;
        }

        .my-dialog-body {
            padding: 20px;
            max-height: min(70vh, 560px);
            overflow-y: auto;
            overscroll-behavior: contain;
        }

        /* API 令牌管理 */
        .my-token-create {
            display: block;
            margin-bottom: 16px;
        }

        .my-token-create > label {
            display: block;
            font-size: 13px;
            font-weight: 600;
            margin-bottom: 6px;
            color: var(--text);
        }

        .my-token-new {
            background: var(--primary-soft, rgba(102,126,234,0.12));
            border: 1px solid var(--border);
            border-radius: 10px;
            padding: 12px 14px;
            margin-bottom: 16px;
        }

        .my-token-row {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-top: 8px;
        }

        .my-token-row code {
            flex: 1;
            min-width: 0;
            font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
            font-size: 12.5px;
            background: var(--surface-2, #f4f5f7);
            border-radius: 7px;
            padding: 8px 10px;
            word-break: break-all;
            color: var(--text);
        }

        .my-subtitle {
            font-size: 14px;
            font-weight: 700;
            margin: 18px 0 10px;
            color: var(--text);
        }

        .my-token-list {
            display: flex;
            flex-direction: column;
            gap: 10px;
        }

        .my-token-item {
            border: 1px solid var(--border);
            border-radius: 10px;
            padding: 12px 14px;
            background: var(--surface, #fff);
        }

        .my-token-meta {
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .my-token-name {
            font-weight: 600;
            font-size: 14px;
            color: var(--text);
        }

        .my-badge {
            font-size: 11px;
            padding: 2px 8px;
            border-radius: 999px;
            font-weight: 600;
        }

        .my-badge.is-read {
            background: var(--surface-2, #eef0f4);
            color: var(--muted, #6b7280);
        }

        .my-badge.is-write {
            background: var(--primary-soft, rgba(102,126,234,0.15));
            color: var(--primary, #667eea);
        }

        .my-token-info {
            font-size: 12.5px;
            color: var(--muted, #6b7280);
            margin: 6px 0 10px;
        }

        .my-token-actions {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
        }

        .my-btn.is-small {
            padding: 5px 12px;
            font-size: 12.5px;
            border-radius: 8px;
        }

        .my-btn.is-danger {
            border-color: var(--danger-border, #f3b4b4);
            color: var(--danger, #d64545);
        }

        .my-btn.is-danger:hover {
            background: var(--danger-soft, #fdeaea);
        }

        .my-api-docs pre {
            background: var(--surface-2, #f4f5f7);
            border-radius: 8px;
            padding: 10px 12px;
            overflow-x: auto;
            font-size: 12.5px;
            line-height: 1.6;
            margin: 6px 0 12px;
        }

        .my-api-docs code {
            font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
        }

        .my-api-docs p {
            font-size: 13px;
            color: var(--text);
            margin: 4px 0;
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

        /* 批量导入 · 校对列表 */
        .bp-hint {
            margin: 6px 0 0;
            font-size: 12px;
            line-height: 1.5;
            color: var(--muted);
        }

        .bp-toolbar {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 10px;
            margin-bottom: 8px;
        }

        .bp-check {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            min-height: 24px;
            font-size: 13px;
            color: var(--text);
            cursor: pointer;
        }

        .bp-check input {
            width: 15px;
            height: 15px;
            margin: 0;
            accent-color: var(--primary);
        }

        .bp-count {
            font-size: 12px;
            color: var(--muted);
        }

        .bp-list {
            display: flex;
            flex-direction: column;
            gap: 8px;
            max-height: min(52vh, 420px);
            overflow-y: auto;
            padding: 2px 2px 8px;
            scrollbar-gutter: stable;
            /* inset 阴影固定在滚动容器视口（不随内容滚动），
               底部渐隐提示下面还有内容，不需要额外的包裹层 */
            box-shadow: inset 0 -14px 12px -12px rgba(0, 0, 0, 0.16);
        }

        .bp-item {
            display: grid;
            grid-template-columns: auto 34px minmax(0, 1fr) 132px;
            align-items: center;
            gap: 10px;
            padding: 8px 10px;
            border: 1px solid var(--border);
            border-radius: 10px;
            background: var(--surface-2);
        }

        .bp-item.is-off {
            opacity: 0.55;
        }

        .bp-item.is-dup {
            border-style: dashed;
        }

        .bp-item input[type="checkbox"] {
            width: 15px;
            height: 15px;
            margin: 0;
            accent-color: var(--primary);
        }

        .bp-logo {
            width: 34px;
            height: 34px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 8px;
            background: var(--surface);
            border: 1px solid var(--border);
            overflow: hidden;
            font-size: 13px;
            font-weight: 600;
            color: var(--muted);
        }

        .bp-logo img {
            width: 100%;
            height: 100%;
            object-fit: contain;
        }

        .bp-main {
            min-width: 0;
        }

        .bp-main input {
            width: 100%;
            padding: 6px 9px;
            border: 1px solid var(--border-strong);
            border-radius: 8px;
            background: var(--surface);
            color: var(--text);
            font-size: 13px;
            font-family: inherit;
            outline: none;
        }

        .bp-main input:focus {
            border-color: var(--primary);
            box-shadow: 0 0 0 3px var(--primary-soft);
        }

        .bp-main input.is-weak {
            border-color: var(--warning, #d9a03a);
        }

        .bp-url {
            display: block;
            margin-top: 3px;
            font-size: 12px;
            line-height: 1.4;
            color: var(--muted);
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        .bp-note {
            display: block;
            margin-top: 3px;
            font-size: 11.5px;
            color: var(--warning, #b5811f);
        }

        .bp-item select {
            width: 100%;
            padding: 6px 8px;
            border: 1px solid var(--border-strong);
            border-radius: 8px;
            background: var(--surface);
            color: var(--text);
            font-size: 12.5px;
            font-family: inherit;
            outline: none;
        }

        @media (max-width: 560px) {
            .bp-item {
                grid-template-columns: auto 30px minmax(0, 1fr);
                row-gap: 8px;
            }

            .bp-item select {
                grid-column: 2 / -1;
            }

            .bp-logo {
                width: 30px;
                height: 30px;
            }
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
            position: relative;
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 8px 10px;
            border: 1px solid var(--border);
            border-radius: 10px;
            background: var(--surface-2);
        }

        .my-group-item.is-dragging { opacity: 0.5; }
        .my-group-item.is-drop-target { border-color: var(--primary); background: var(--primary-soft); }
        /* v2.14.0：新建成功的分组项短暂高亮，立即显示且肉眼可辨 */
        .my-group-item.is-new { animation: myGroupFlash 1.4s ease-out; }
        @keyframes myGroupFlash {
            0%, 55% { background: var(--primary-soft); border-color: var(--primary); }
            100% { background: transparent; }
        }
        @media (prefers-reduced-motion: reduce) {
            .my-group-item.is-new { animation: none; }
        }

        /* 名称平时像纯文本，聚焦或悬浮才显示输入态，改完失焦自动保存 */
        .my-group-item .my-group-name {
            flex: 1 1 auto;
            min-width: 0;
            padding: 6px 10px;
            border: 1px solid transparent;
            border-radius: 8px;
            background: transparent;
            color: var(--text);
            font-size: 13px;
            font-weight: 600;
            outline: none;
            transition: border-color 0.15s ease, background-color 0.15s ease;
        }

        .my-group-item .my-group-name:hover,
        .my-group-item .my-group-name:focus {
            border-color: var(--border-strong);
            background: var(--surface);
        }

        .my-group-item .my-group-name:focus { border-color: var(--primary); }

        .my-group-private-toggle {
            display: inline-flex;
            flex: 0 0 auto;
            align-items: center;
            gap: 4px;
            color: var(--muted);
            font-size: 12px;
            white-space: nowrap;
        }

        /* 排序手柄 + 更多操作：两个 26px 图标，替代原来 7 个挤在一行的控件 */
        .my-group-handle,
        .my-group-more {
            display: inline-grid;
            flex: 0 0 auto;
            place-items: center;
            width: 26px;
            height: 26px;
            padding: 0;
            border: 1px solid transparent;
            border-radius: 7px;
            background: transparent;
            color: var(--muted);
            cursor: pointer;
            transition: border-color 0.15s ease, color 0.15s ease, background-color 0.15s ease;
        }

        .my-group-handle { cursor: grab; }
        .my-group-handle:active { cursor: grabbing; }

        .my-group-handle:hover,
        .my-group-more:hover,
        .my-group-more[aria-expanded="true"] {
            border-color: var(--primary-border);
            background: var(--primary-soft);
            color: var(--primary);
        }

        .my-group-handle svg,
        .my-group-more svg { width: 15px; height: 15px; }

        .my-group-handle:focus-visible,
        .my-group-more:focus-visible { outline: 2px solid var(--primary); outline-offset: 1px; }

        .my-group-menu {
            position: absolute;
            right: 8px;
            top: calc(100% - 4px);
            z-index: 5;
            min-width: 118px;
            padding: 5px;
            border: 1px solid var(--border);
            border-radius: 10px;
            background: var(--surface);
            box-shadow: 0 10px 28px -12px rgba(15, 23, 42, 0.35);
        }

        .my-group-menu[hidden] { display: none; }

        .my-group-menu-item {
            display: block;
            width: 100%;
            padding: 7px 10px;
            border: 0;
            border-radius: 7px;
            background: transparent;
            color: var(--text);
            font-size: 12.5px;
            text-align: left;
            cursor: pointer;
        }

        .my-group-menu-item:hover { background: var(--surface-2); }
        .my-group-menu-item.is-danger { color: #dc2626; }
        .my-group-menu-item.is-danger:hover { background: rgba(220, 38, 38, 0.08); }

        /* 抓取按钮：主色渐变实心 + 白图标，悬浮上浮、点击下压，进行中图标旋转 */
        #fetchBtn {
            position: relative;
            /* v2.16.3：按钮不许被输入框挤窄，否则「抓取」两个字会折成两行 */
            flex: none;
            white-space: nowrap;
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

            /* 保留文字标签：此前移动端把 label 全隐藏，只剩一排看不懂的图标。
               改为小字号 + 工具栏横向滚动，标签始终可见。 */
            .my-btn:not(.my-dialog .my-btn):not(.my-field-row .my-btn) .my-btn-label {
                display: inline;
                font-size: 11.5px;
                white-space: nowrap;
            }

            .my-btn {
                padding: 0 11px;
                flex: 0 0 auto;
            }

            .my-actions {
                flex-wrap: nowrap;
                overflow-x: auto;
                scrollbar-width: none;
                padding-bottom: 2px;
            }

            .my-actions::-webkit-scrollbar { display: none; }

            .my-sharebar {
                left: 12px;
                right: 12px;
                bottom: 12px;
                transform: none;
                justify-content: space-between;
            }
        }

        /* ===== v2.16.2 · AI 一键分类 ===== */
        dialog.my-dialog-wide { width: min(640px, calc(100vw - 32px)); }

        .ai-cat-intro { margin: 0 0 14px; line-height: 1.65; }

        .ai-cat-scope { display: grid; gap: 8px; margin-bottom: 14px; }

        .ai-cat-option {
            display: flex;
            align-items: flex-start;
            gap: 10px;
            padding: 11px 13px;
            border: 1px solid var(--my-border, #e5e7eb);
            border-radius: 10px;
            cursor: pointer;
            transition: border-color .16s ease, background .16s ease;
        }

        .ai-cat-option:hover { border-color: var(--my-primary-border, #bfdbfe); }

        .ai-cat-option:has(input:checked) {
            border-color: var(--my-primary, #2563eb);
            background: var(--my-primary-soft, #eff6ff);
        }

        .ai-cat-option input { margin: 3px 0 0; flex: none; }

        .ai-cat-option-body { display: flex; flex-direction: column; gap: 3px; }

        .ai-cat-option-body strong { font-size: 13.5px; font-weight: 600; }

        .ai-cat-option-body em {
            font-size: 12px;
            font-style: normal;
            color: var(--my-muted, #6b7280);
        }

        .ai-cat-progress {
            height: 8px;
            border-radius: 4px;
            background: var(--my-border, #e5e7eb);
            overflow: hidden;
        }

        .ai-cat-progress span {
            display: block;
            width: 0;
            height: 100%;
            background: var(--my-primary, #2563eb);
            transition: width .25s ease;
        }

        .ai-cat-progress-text { margin: 10px 0 0; }

        .ai-cat-toolbar {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            align-items: center;
            justify-content: space-between;
            padding-bottom: 10px;
            margin-bottom: 8px;
            border-bottom: 1px solid var(--my-border, #e5e7eb);
        }

        .ai-cat-list {
            display: grid;
            gap: 6px;
            max-height: min(52vh, 420px);
            overflow-y: auto;
            /* 滚动时给上下留一点呼吸，避免首尾行贴着边框被切 */
            padding: 2px 2px 6px;
            margin: -2px -2px 0;
            overscroll-behavior: contain;
            scrollbar-width: thin;
        }

        .ai-cat-list::-webkit-scrollbar { width: 8px; }
        .ai-cat-list::-webkit-scrollbar-thumb {
            background: var(--my-border, #d1d5db);
            border-radius: 999px;
            border: 2px solid transparent;
            background-clip: content-box;
        }

        .ai-cat-row {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 9px 11px;
            border-radius: 9px;
            background: var(--my-soft, #f9fafb);
            font-size: 13px;
        }

        .ai-cat-row input { flex: none; }

        /* AI 认为无需调整的行：复选框禁用 + 整行淡化，避免误操作 */
        .ai-cat-row:has(input:disabled) {
            opacity: .62;
            background: transparent;
            box-shadow: inset 0 0 0 1px var(--my-border, #e5e7eb);
        }

        .ai-cat-row:has(input:disabled) .ai-cat-row-name { font-weight: 500; }

        .ai-cat-row-main {
            display: flex;
            flex: 1;
            min-width: 0;
            flex-direction: column;
            gap: 3px;
        }

        .ai-cat-row-name {
            font-weight: 600;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        .ai-cat-row-path {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            gap: 6px;
            font-size: 12px;
            color: var(--my-muted, #6b7280);
        }

        .ai-cat-tag {
            display: inline-flex;
            align-items: center;
            max-width: 46%;
            padding: 1px 7px;
            border-radius: 5px;
            background: var(--my-border, #e5e7eb);
            color: var(--my-text-2, #374151);
            font-size: 11.5px;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        .ai-cat-tag.is-new {
            background: rgba(37, 99, 235, .12);
            color: var(--my-primary, #2563eb);
        }

        .ai-cat-arrow { flex: none; color: var(--my-muted, #9ca3af); }

        /* 列表滚动区与底部按钮之间加一道分隔，滚动时不会糊在一起 */
        .ai-cat-actions {
            padding-top: 12px;
            margin-top: 10px;
            border-top: 1px solid var(--my-border, #e5e7eb);
        }

        /* 列表底部渐隐：提示「下面还有」，避免半截行被硬切出条状残影 */
        .ai-cat-list {
            -webkit-mask-image: linear-gradient(to bottom, #000 calc(100% - 22px), transparent 100%);
            mask-image: linear-gradient(to bottom, #000 calc(100% - 22px), transparent 100%);
        }

        /* 内容没超出时不需要渐隐，交给 JS 打标记关掉 */
        .ai-cat-list.is-short {
            -webkit-mask-image: none;
            mask-image: none;
        }

        @media (max-width: 575.98px) {
            .ai-cat-list { max-height: 46vh; }
            .ai-cat-row { padding: 8px 9px; }
            .ai-cat-row-path { font-size: 11.5px; }
            .ai-cat-tag { max-width: 100%; }
            .ai-cat-option { padding: 10px 11px; }
        }

        /* ===== v2.16.0 · 回收站 ===== */
        .my-btn-badge {
            margin-left: 4px;
            min-width: 18px;
            height: 18px;
            padding: 0 5px;
            border-radius: 9px;
            background: var(--my-danger, #ef4444);
            color: #fff;
            font-size: 11px;
            line-height: 18px;
            text-align: center;
        }
        .my-recycle-toolbar {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            align-items: center;
            justify-content: space-between;
            padding-bottom: 10px;
            border-bottom: 1px solid var(--my-border, #e5e7eb);
            margin-bottom: 10px;
        }
        .my-recycle-actions {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
        }
        .my-recycle-list {
            list-style: none;
            margin: 0;
            padding: 0;
            max-height: 46vh;
            overflow-y: auto;
        }
        .my-recycle-item {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 10px 4px;
            border-bottom: 1px dashed var(--my-border, #e5e7eb);
        }
        .my-recycle-main {
            flex: 1 1 auto;
            min-width: 0;
        }
        .my-recycle-name {
            font-weight: 600;
            font-size: 14px;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }
        .my-recycle-meta {
            display: flex;
            flex-wrap: wrap;
            gap: 6px 10px;
            margin-top: 3px;
            font-size: 12px;
            color: var(--my-muted, #6b7280);
        }
        .my-recycle-host {
            overflow: hidden;
            text-overflow: ellipsis;
            max-width: 220px;
            white-space: nowrap;
        }
        .my-recycle-tag {
            padding: 0 6px;
            border-radius: 6px;
            background: rgba(99, 102, 241, .12);
            color: #4f46e5;
        }
        .my-recycle-item-actions {
            display: flex;
            gap: 6px;
            flex: 0 0 auto;
        }
        .my-empty.is-compact {
            padding: 28px 16px;
        }
        .my-empty.is-compact h3 {
            font-size: 15px;
            margin: 0 0 6px;
        }
        @media (max-width: 575.98px) {
            .my-recycle-item {
                flex-wrap: wrap;
            }
            .my-recycle-main {
                flex: 1 1 calc(100% - 40px);
            }
            .my-recycle-item-actions {
                flex: 1 1 100%;
                justify-content: flex-end;
            }
            .my-recycle-host {
                max-width: 160px;
            }
            .my-recycle-actions .my-btn {
                flex: 1 1 auto;
            }
        }
    </style>
</head>
<body data-picking="0" data-organize="0">
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
                <?php
                $userAvatar = trim((string) ($user['avatar'] ?? ''));
                $avatarUrl = $userAvatar !== '' ? $functions->getLogoDisplayUrl($userAvatar) : '';
                $isAdmin = (int) ($user['is_admin'] ?? 0) === 1;
                ?>
                <!-- v2.18.6：工具条与 index.php 对齐为 3 枚 --ctl-* 图标按钮，
                     「返回首页 / 退出登录」等裸文字按钮全部收进用户下拉。 -->
                <div class="skin-picker" id="skinPicker">
                    <button type="button" class="tool-btn" id="skinToggle" aria-label="选择配色" title="选择配色" aria-expanded="false">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="9" cy="9.5" r="5"></circle><circle cx="15" cy="9.5" r="5"></circle><circle cx="12" cy="14.5" r="5"></circle></svg>
                    </button>
                    <div class="skin-menu" id="skinMenu" role="menu" aria-label="配色方案">
                        <button type="button" class="skin-option is-active" role="menuitemradio" aria-checked="true" data-skin="default"><span class="skin-dot" data-skin="default" aria-hidden="true"></span>经典蓝</button>
                        <button type="button" class="skin-option" role="menuitemradio" aria-checked="false" data-skin="purple"><span class="skin-dot" data-skin="purple" aria-hidden="true"></span>静谧紫</button>
                        <button type="button" class="skin-option" role="menuitemradio" aria-checked="false" data-skin="orange"><span class="skin-dot" data-skin="orange" aria-hidden="true"></span>暖阳橙</button>
                        <button type="button" class="skin-option" role="menuitemradio" aria-checked="false" data-skin="mint"><span class="skin-dot" data-skin="mint" aria-hidden="true"></span>薄荷青</button>
                    </div>
                </div>

                <button type="button" class="tool-btn theme-toggle" id="themeToggle" aria-label="切换深浅色" title="切换深浅色">
                    <svg class="icon-moon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20.5 14.2A8.5 8.5 0 0 1 9.8 3.5 8.5 8.5 0 1 0 20.5 14.2Z"></path></svg>
                    <svg class="icon-sun" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="4"></circle><path d="M12 2v2M12 20v2M4.9 4.9l1.4 1.4M17.7 17.7l1.4 1.4M2 12h2M20 12h2M4.9 19.1l1.4-1.4M17.7 6.3l1.4-1.4"></path></svg>
                </button>

                <details class="user-menu" id="userMenu">
                    <summary class="tool-btn user-summary" aria-label="账号菜单：<?php echo $functions->escape($user['username']); ?>">
                        <?php if ($avatarUrl !== ''): ?>
                            <img class="user-avatar-img" src="<?php echo $functions->escape($avatarUrl); ?>" alt="<?php echo $functions->escape($user['username']); ?> 头像" width="28" height="28" loading="eager" decoding="async" onerror="this.onerror=null;this.src='assets/img/avatar-default.svg';">
                        <?php else: ?>
                            <span class="user-avatar" aria-hidden="true"><?php echo $functions->escape($brandInitial); ?></span>
                        <?php endif; ?>
                        <span class="user-name" translate="no"><?php echo $functions->escape($user['username']); ?></span>
                        <svg class="user-caret" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m6 9 6 6 6-6"></path></svg>
                    </summary>
                    <div class="user-dropdown">
                        <div class="user-account">
                            <?php if ($avatarUrl !== ''): ?>
                                <img class="user-avatar-img" src="<?php echo $functions->escape($avatarUrl); ?>" alt="" width="24" height="24" onerror="this.onerror=null;this.src='assets/img/avatar-default.svg';">
                            <?php else: ?>
                                <span class="user-avatar" aria-hidden="true"><?php echo $functions->escape($brandInitial); ?></span>
                            <?php endif; ?>
                            <span class="user-account-name"><?php echo $functions->escape($user['username']); ?></span>
                            <?php if ($isAdmin): ?><span class="user-account-tag">管理员</span><?php endif; ?>
                        </div>
                        <a class="user-item" href="index.php">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="3" width="7" height="7" rx="1.5"></rect><rect x="14" y="3" width="7" height="7" rx="1.5"></rect><rect x="3" y="14" width="7" height="7" rx="1.5"></rect><rect x="14" y="14" width="7" height="7" rx="1.5"></rect></svg>
                            <span>全部导航</span>
                        </a>
                        <a class="user-item is-current" href="my.php">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M6 4h12v17l-6-4-6 4z"></path></svg>
                            <span>我的导航</span>
                        </a>
                        <div class="user-sep" aria-hidden="true"></div>
                        <a class="user-item" href="my.php?open=account" title="头像、昵称、密码与页脚版权">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="8" r="4"></circle><path d="M4 21c0-4 3.6-6.5 8-6.5s8 2.5 8 6.5"></path></svg>
                            <span>账号设置</span>
                        </a>
                        <button type="button" class="user-item user-item-btn" id="quickAddTriggerMy">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"></polygon></svg>
                            <span>快捷收藏</span>
                        </button>
                        <button type="button" class="user-item user-item-btn" id="openModeToggleMy">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"></path><path d="M15 3h6v6"></path><path d="M10 14 21 3"></path></svg>
                            <span>打开方式</span>
                            <span class="user-mode-badge" id="openModeBadgeMy">默认</span>
                        </button>
                        <div class="user-sep" aria-hidden="true"></div>
                        <?php if ($isAdmin): ?>
                            <a class="user-item" href="admin/index.php">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 7h10M18 7h2M4 17h2M10 17h10"></path><circle cx="16" cy="7" r="2"></circle><circle cx="8" cy="17" r="2"></circle></svg>
                                <span>管理后台</span>
                            </a>
                        <?php endif; ?>
                        <a class="user-item" href="about.php">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="9"></circle><path d="M12 11v6M12 7.6v.2"></path></svg>
                            <span>关于本站</span>
                        </a>
                        <div class="user-sep" aria-hidden="true"></div>
                        <a class="user-item is-danger" href="logout.php">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M9 3H5.5A2.5 2.5 0 0 0 3 5.5v13A2.5 2.5 0 0 0 5.5 21H9"></path><path d="m16 16 4-4-4-4M20 12H9"></path></svg>
                            <span>退出登录</span>
                        </a>
                    </div>
                </details>
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
                    <button type="button" class="my-btn" id="batchImportBtn" title="一次粘贴多个网址，自动抓取标题图标并推荐分组">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                            <path d="M9 6h12M9 12h12M9 18h12"></path>
                            <path d="M4 6h.01M4 12h.01M4 18h.01"></path>
                        </svg>
                        <span class="my-btn-label">批量导入</span>
                    </button>
                    <button type="button" class="my-btn" id="organizeBtn" <?php echo $hasLinks ? '' : 'aria-disabled="true"'; ?>>
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                            <path d="M3 6h18M3 12h18M3 18h18"></path>
                            <path d="M9 6l3-3 3 3M9 18l3 3 3-3"></path>
                        </svg>
                        <span class="my-btn-label" id="organizeBtnLabel">整理</span>
                    </button>
                    <!-- v2.16.2：AI 一键分类。先分析出建议，确认后再改数据 -->
                    <button type="button" class="my-btn" id="aiCategorizeBtn" <?php echo $hasLinks ? '' : 'aria-disabled="true"'; ?> title="AI 按网址内容自动归类，可先预览再确认">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                            <path d="m12 3 1.9 4.6 4.6 1.9-4.6 1.9L12 16l-1.9-4.6L5.5 9.5l4.6-1.9Z"></path>
                            <path d="M18.5 15.5l.9 2.1 2.1.9-2.1.9-.9 2.1-.9-2.1-2.1-.9 2.1-.9Z"></path>
                        </svg>
                        <span class="my-btn-label">AI 分类</span>
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
                    <!-- v2.12.0：一级「新建分组」入口，与后台/首页共用同一组件和接口 -->
                    <button type="button" class="my-btn is-ghost js-category-create" id="quickGroupBtn">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true">
                            <path d="M12 5v14M5 12h14"></path>
                        </svg>
                        <span class="my-btn-label">新建分组</span>
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
                    <button type="button" class="my-btn is-ghost" id="recycleBtn" title="回收站">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                            <path d="M3 6h18"></path>
                            <path d="M8 6V4h8v2"></path>
                            <path d="M19 6l-1 14H6L5 6"></path>
                            <path d="M10 11v6M14 11v6"></path>
                        </svg>
                        <span class="my-btn-label">回收站</span>
                        <?php if (!empty($recycleItems)): ?>
                            <span class="my-btn-badge"><?php echo count($recycleItems); ?></span>
                        <?php endif; ?>
                    </button>
                    <button type="button" class="my-btn is-ghost" id="apiTokenBtn">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                            <circle cx="7.5" cy="15.5" r="4.5"></circle>
                            <path d="M10.7 12.3 21 2l-3 1-4 4 2 2 4-4 1 3z"></path>
                        </svg>
                        <span class="my-btn-label">API 令牌</span>
                    </button>
                </div>
            </div>

            <!-- v2.14.0：顶部分组 tab 栏。服务端首屏渲染，前端分组变更后由 renderGroupTabs() 即时重建。 -->
            <nav class="my-group-tabs" aria-label="分组导航">
                <div class="my-group-tabs-track" id="groupTabsTrack" role="tablist">
                    <button type="button" class="my-group-tab is-active" data-group="all" role="tab" aria-selected="true">全部网站</button>
                    <?php foreach ($ownGroups as $group): ?>
                        <?php $tabCount = isset($groups[(int) $group['id']]['items']) ? count($groups[(int) $group['id']]['items']) : 0; ?>
                        <button type="button" class="my-group-tab" data-group="<?php echo (int) $group['id']; ?>" role="tab" aria-selected="false"><?php echo $functions->escape(($group['is_private'] ? '🔒 ' : '') . $group['name']); ?><span class="mgt-count"><?php echo (int) $tabCount; ?></span></button>
                    <?php endforeach; ?>
                    <button type="button" class="my-group-tab" data-group="0" role="tab" aria-selected="false">未分类<span class="mgt-count"><?php echo count($ungrouped['items']); ?></span></button>
                </div>
                <span class="my-group-tabs-hint">点击分组快速定位</span>
            </nav>

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
                                     data-tags="<?php echo $functions->escape($item['tags']); ?>"
                                     data-alias="<?php echo $functions->escape($item['alias']); ?>"
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
                                        <?php if (!empty($item['tags'])): ?>
                                            <span class="my-tags">
                                                <?php foreach (array_filter(array_map('trim', explode(',', $item['tags']))) as $tag): ?>
                                                    <span class="my-tag"><?php echo $functions->escape($tag); ?></span>
                                                <?php endforeach; ?>
                                            </span>
                                        <?php endif; ?>
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
            <p class="copyright"><?php echo $functions->escape($functions->footerCopyrightText((string) ($user['footer_copyright'] ?? ''), $brandName)); ?> · 我的导航 · v<?php echo $functions->escape(defined('APP_VERSION') ? APP_VERSION : '0.0.0'); ?></p>
            <?php echo siteQuickLinksHtml(); ?>
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

    <!-- 批量操作栏 -->
    <div class="my-sharebar" id="batchBar" role="region" aria-label="批量操作">
        <label class="my-batch-all">
            <input type="checkbox" id="batchSelectAll">
            <span>全选</span>
        </label>
        <span class="my-sharebar-text">已选 <strong id="batchCount" aria-live="polite">0</strong> 项</span>
        <button type="button" class="my-btn" id="batchMoveBtn">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" width="15" height="15"><path d="M5 4h14M5 12h14M5 20h14"></path><path d="M9 8l3-3 3 3M9 16l3 3 3-3"></path></svg>
            <span>批量移动</span>
        </button>
        <button type="button" class="my-btn" id="batchTagBtn">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" width="15" height="15"><path d="M20.59 13.41l-7.17 7.17a2 2 0 0 1-2.83 0L2 12V2h10l8.59 8.59a2 2 0 0 1 0 2.82z"></path><circle cx="7" cy="7" r="1.5"></circle></svg>
            <span>批量打标签</span>
        </button>
        <button type="button" class="my-btn is-danger" id="batchDeleteBtn">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" width="15" height="15"><path d="M4 7h16M9 7V5h6v2M6 7l1 13h10l1-13"></path></svg>
            <span>批量删除</span>
        </button>
        <button type="button" class="my-btn is-ghost" id="batchCancelBtn">取消</button>
    </div>

    <!-- 批量移动分组弹窗 -->
    <dialog class="my-dialog" id="batchMoveDialog">
        <div class="my-dialog-head">
            <h3>批量移动到分组</h3>
            <button type="button" class="my-iconbtn" id="batchMoveClose" aria-label="关闭">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="m6 6 12 12M18 6 6 18"></path></svg>
            </button>
        </div>
        <div class="my-dialog-body">
            <p class="my-msg" id="batchMoveMsg" role="alert"></p>
            <p class="my-batch-info">将移动 <strong id="batchMoveCount">0</strong> 个网址到：</p>
            <div class="my-field">
                <label for="batchMoveCategory">选择目标分组</label>
                <select id="batchMoveCategory">
                    <option value="0">未分类</option>
                    <?php foreach ($ownGroups as $group): ?>
                        <option value="<?php echo (int) $group['id']; ?>">
                            <?php echo $functions->escape(($group['is_private'] ? '🔒 ' : '') . $group['name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
        <div class="my-dialog-foot">
            <button type="button" class="my-btn" id="batchMoveCancel">取消</button>
            <button type="button" class="my-btn is-primary" id="batchMoveSubmit">移动</button>
        </div>
    </dialog>

    <!-- 批量打标签弹窗 -->
    <dialog class="my-dialog" id="batchTagDialog">
        <div class="my-dialog-head">
            <h3>批量打标签</h3>
            <button type="button" class="my-iconbtn" id="batchTagClose" aria-label="关闭">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="m6 6 12 12M18 6 6 18"></path></svg>
            </button>
        </div>
        <div class="my-dialog-body">
            <p class="my-msg" id="batchTagMsg" role="alert"></p>
            <p class="my-batch-info">为 <strong id="batchTagCount">0</strong> 个网址设置标签：</p>
            <div class="my-field">
                <label for="batchTagInput">标签（多个用英文逗号分隔）</label>
                <input type="text" id="batchTagInput" placeholder="例如：设计,工具,参考" autocomplete="off">
            </div>
            <div class="my-field">
                <label>写入方式</label>
                <label class="my-radio"><input type="radio" name="batchTagMode" value="append" checked> 追加（合并去重）</label>
                <label class="my-radio"><input type="radio" name="batchTagMode" value="overwrite"> 覆盖（替换现有标签）</label>
            </div>
        </div>
        <div class="my-dialog-foot">
            <button type="button" class="my-btn" id="batchTagCancel">取消</button>
            <button type="button" class="my-btn is-primary" id="batchTagSubmit">应用</button>
        </div>
    </dialog>

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

                <!-- v2.16.3：网站图标编辑区。放在「名称」上方，视觉上先看到站点样子 -->
                <div class="my-field my-icon-field">
                    <label>网站图标</label>

                    <div class="icon-editor">
                        <span class="icon-preview" id="linkIconPreview" aria-hidden="true">
                            <span class="icon-preview-letter" id="linkIconLetter">?</span>
                            <img id="linkIconImg" alt="" width="48" height="48" hidden referrerpolicy="no-referrer" decoding="async">
                        </span>

                        <div class="icon-editor-main">
                            <div class="icon-editor-actions">
                                <button type="button" class="my-btn is-small" id="linkIconUploadBtn">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                        <path d="M12 16V4"></path><path d="m7 9 5-5 5 5"></path>
                                        <path d="M20 16v2a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2v-2"></path>
                                    </svg>
                                    <span class="my-btn-label">上传图标</span>
                                </button>

                                <button type="button" class="my-btn is-small" id="linkIconUrlBtn">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                        <path d="M10 13a5 5 0 0 0 7.1 0l3-3a5 5 0 0 0-7.1-7.1l-1.7 1.7"></path>
                                        <path d="M14 11a5 5 0 0 0-7.1 0l-3 3a5 5 0 0 0 7.1 7.1l1.7-1.7"></path>
                                    </svg>
                                    <span class="my-btn-label">输入图标URL</span>
                                </button>

                                <button type="button" class="my-btn is-small is-ghost" id="linkIconResetBtn" hidden>恢复默认</button>
                            </div>

                            <input type="file" id="linkIconFile" accept=".png,.jpg,.jpeg,.svg,.ico,image/png,image/jpeg,image/svg+xml,image/x-icon,image/vnd.microsoft.icon" hidden>

                            <!-- 输入 URL 时就地展开，比再弹一层弹窗更轻 -->
                            <div class="icon-url-row" id="linkIconUrlRow" hidden>
                                <input type="url" id="linkIconUrlInput" maxlength="500" autocomplete="off" spellcheck="false" placeholder="https://example.com/icon.png">
                                <button type="button" class="my-btn is-small is-primary" id="linkIconUrlOk">确定</button>
                            </div>

                            <p class="my-note icon-note" id="linkIconNote" aria-live="polite">支持 PNG / JPG / SVG / ICO，超过 256px 会自动压缩。留空则自动抓取网站图标。</p>
                        </div>
                    </div>

                    <!-- 真正提交的字段：图标路径或外链 URL -->
                    <input type="hidden" id="linkLogo" name="logo" value="">
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
                    <label for="linkTags">标签</label>
                    <input type="text" id="linkTags" name="tags" maxlength="255" autocomplete="off" placeholder="多个标签用英文逗号分隔，例如：设计,工具">
                </div>

                <div class="my-field">
                    <label for="linkAlias">别名</label>
                    <input type="text" id="linkAlias" name="alias" maxlength="255" autocomplete="off" placeholder="可留空，用于搜索时的别称">
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

            <!-- v2.16.3：与编辑弹窗保持一致的图标编辑区 -->
            <div class="my-field my-icon-field">
                <label>网站图标</label>

                <div class="icon-editor">
                    <span class="icon-preview" id="qaIconPreview" aria-hidden="true">
                        <span class="icon-preview-letter" id="qaIconLetter">?</span>
                        <img id="qaIconImg" alt="" width="48" height="48" hidden referrerpolicy="no-referrer" decoding="async">
                    </span>

                    <div class="icon-editor-main">
                        <div class="icon-editor-actions">
                            <button type="button" class="my-btn is-small" id="qaIconUploadBtn">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                    <path d="M12 16V4"></path><path d="m7 9 5-5 5 5"></path>
                                    <path d="M20 16v2a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2v-2"></path>
                                </svg>
                                <span class="my-btn-label">上传图标</span>
                            </button>

                            <button type="button" class="my-btn is-small" id="qaIconUrlBtn">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                    <path d="M10 13a5 5 0 0 0 7.1 0l3-3a5 5 0 0 0-7.1-7.1l-1.7 1.7"></path>
                                    <path d="M14 11a5 5 0 0 0-7.1 0l-3 3a5 5 0 0 0 7.1 7.1l1.7-1.7"></path>
                                </svg>
                                <span class="my-btn-label">输入图标URL</span>
                            </button>

                            <button type="button" class="my-btn is-small is-ghost" id="qaIconResetBtn" hidden>恢复默认</button>
                        </div>

                        <input type="file" id="qaIconFile" accept=".png,.jpg,.jpeg,.svg,.ico,image/png,image/jpeg,image/svg+xml,image/x-icon,image/vnd.microsoft.icon" hidden>

                        <div class="icon-url-row" id="qaIconUrlRow" hidden>
                            <input type="url" id="qaIconUrlInput" maxlength="500" autocomplete="off" spellcheck="false" placeholder="https://example.com/icon.png">
                            <button type="button" class="my-btn is-small is-primary" id="qaIconUrlOk">确定</button>
                        </div>

                        <p class="my-note icon-note" id="qaIconNote" aria-live="polite">支持 PNG / JPG / SVG / ICO，超过 256px 会自动压缩。留空则自动抓取网站图标。</p>
                    </div>
                </div>

                <input type="hidden" id="qaLogo" value="">
            </div>

            <!-- v2.16.5：收录时可由 AI 生成标签，结果可改可清空 -->
            <div class="my-field">
                <label for="qaTags">标签</label>
                <div class="tag-input-row">
                    <input type="text" id="qaTags" maxlength="255" autocomplete="off" placeholder="如：AI 工具 效率">
                    <button type="button" class="my-btn is-small" id="qaTagAiBtn">AI 生成</button>
                </div>
                <div class="tag-preview" id="qaTagPreview"></div>
                <p class="my-note tag-note" id="qaTagNote">多个标签用空格分隔；留空也可。</p>
            </div>
        </div>
        <div class="my-dialog-foot">
            <button type="button" class="my-btn" id="quickAddCancel">取消</button>
            <button type="button" class="my-btn" id="quickAddBatchBtn">批量粘贴</button>
            <button type="button" class="my-btn is-primary" id="quickAddSubmit">抓取并收藏</button>
        </div>
    </dialog>

    <!-- 批量粘贴导入：先识别、再校对、最后落库 -->
    <dialog class="my-dialog" id="batchPasteDialog">
        <div class="my-dialog-head">
            <h3>批量导入网址</h3>
            <button type="button" class="my-iconbtn" id="batchPasteClose" aria-label="关闭">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="m6 6 12 12M18 6 6 18"></path></svg>
            </button>
        </div>
        <div class="my-dialog-body">
            <p class="my-msg" id="batchPasteMsg" role="alert"></p>

            <!-- 阶段一：粘贴 -->
            <div id="batchPasteStep1">
                <div class="my-field">
                    <label for="batchPasteText">粘贴网址（每行一个，支持「名称,网址」格式）</label>
                    <textarea id="batchPasteText" rows="8" placeholder="https://example.com&#10;GitHub,https://github.com&#10;..."></textarea>
                </div>
                <div class="my-field">
                    <label for="batchPasteCategory">默认分组</label>
                    <select id="batchPasteCategory">
                        <option value="0">未分类</option>
                        <?php foreach ($ownGroups as $group): ?>
                            <option value="<?php echo (int) $group['id']; ?>"><?php echo $functions->escape(($group['is_private'] ? '🔒 ' : '') . $group['name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <p class="bp-hint">识别不出分类的网址会落到这里，其余按网站信息自动归类，下面还能逐条改。</p>
                </div>
            </div>

            <!-- 阶段二：逐条校对 -->
            <div id="batchPasteStep2" hidden>
                <div class="bp-toolbar">
                    <label class="bp-check">
                        <input type="checkbox" id="bpToggleAll" checked>
                        <span>全选</span>
                    </label>
                    <span class="bp-count" id="bpCount"></span>
                </div>
                <div class="bp-list" id="bpList"></div>
            </div>
        </div>
        <div class="my-dialog-foot">
            <button type="button" class="my-btn" id="batchPasteCancel">取消</button>
            <button type="button" class="my-btn" id="batchPasteBack" hidden>返回修改</button>
            <button type="button" class="my-btn is-primary" id="batchPasteSubmit">识别网址</button>
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
                <label for="nickname">昵称</label>
                <div class="my-field-row">
                    <input type="text" id="nickname" maxlength="30" autocomplete="off"
                           value="<?php echo $functions->escape($user['username']); ?>">
                    <button type="button" class="my-btn" id="saveNicknameBtn">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                            <path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"></path>
                            <polyline points="17 21 17 13 7 13 7 21"></polyline>
                            <polyline points="7 3 7 8 15 8"></polyline>
                        </svg>
                        <span class="my-btn-label">保存</span>
                    </button>
                </div>
                <p class="my-note">昵称同时是登录账号名，修改后请用新名称登录；2 ~ 30 个字符，不可与其他用户重名。</p>
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

    <!-- API 令牌管理（前台用户自助） -->
    <dialog class="my-dialog" id="apiTokenDialog"<?php echo ($tokenMsg || $tokenError || $newTokenPlain) ? ' data-autoopen="1"' : ''; ?>>
        <div class="my-dialog-head">
            <h3>API 令牌</h3>
            <button type="button" class="my-iconbtn" id="apiTokenClose" aria-label="关闭">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="m6 6 12 12M18 6 6 18"></path></svg>
            </button>
        </div>

        <div class="my-dialog-body">
            <p class="my-msg" id="apiTokenMsg" role="alert"></p>

            <form method="POST" class="my-token-create">
                <input type="hidden" name="token_action" value="create_user_token">
                <label for="tokenName">新建令牌</label>
                <input type="text" id="tokenName" name="token_name" maxlength="100" placeholder="如：我的手机 / 同步脚本" autocomplete="off" required>
                <div class="my-field-row" style="margin-top:8px;">
                    <select name="token_permissions" aria-label="权限">
                        <option value="read">只读</option>
                        <option value="write">读写</option>
                    </select>
                    <select name="token_expires" aria-label="过期时间">
                        <option value="never">永不过期</option>
                        <option value="30">30 天</option>
                        <option value="90">90 天</option>
                        <option value="365">1 年</option>
                    </select>
                    <button type="submit" class="my-btn is-primary">生成令牌</button>
                </div>
            </form>

            <?php if ($newTokenPlain !== ''): ?>
            <div class="my-token-new">
                <p class="my-note">这是完整 Token，<strong>仅显示一次</strong>，请立即复制保存：</p>
                <div class="my-token-row">
                    <code id="newTokenValue"><?php echo $functions->escape($newTokenPlain); ?></code>
                    <button type="button" class="my-btn" id="copyNewTokenBtn">复制</button>
                </div>
            </div>
            <?php endif; ?>

            <h4 class="my-subtitle">我的令牌</h4>
            <?php if (empty($userTokens)): ?>
                <p class="my-note">还没有令牌。创建一个，即可用脚本或第三方客户端访问你自己的导航数据。</p>
            <?php else: ?>
                <div class="my-token-list">
                    <?php foreach ($userTokens as $t): ?>
                        <div class="my-token-item">
                            <div class="my-token-meta">
                                <span class="my-token-name"><?php echo $functions->escape($t['name']); ?></span>
                                <span class="my-badge <?php echo $t['permissions'] === 'write' ? 'is-write' : 'is-read'; ?>"><?php echo $t['permissions'] === 'write' ? '读写' : '只读'; ?></span>
                            </div>
                            <div class="my-token-info">
                                创建 <?php echo $functions->escape(substr((string) $t['created_at'], 0, 10)); ?>
                                · 过期 <?php echo $t['expires_at'] ? $functions->escape(substr((string) $t['expires_at'], 0, 10)) : '永不过期'; ?>
                                · 最近使用 <?php echo $t['last_used_at'] ? $functions->escape(substr((string) $t['last_used_at'], 0, 16)) : '从未'; ?>
                            </div>
                            <div class="my-token-actions">
                                <form method="POST" style="display:inline;">
                                    <input type="hidden" name="token_action" value="toggle_user_token_perm">
                                    <input type="hidden" name="token_id" value="<?php echo (int) $t['id']; ?>">
                                    <button type="submit" class="my-btn is-small">切换权限</button>
                                </form>
                                <form method="POST" style="display:inline;" onsubmit="return confirm('确定重置该令牌？旧令牌将立即失效');">
                                    <input type="hidden" name="token_action" value="reset_user_token">
                                    <input type="hidden" name="token_id" value="<?php echo (int) $t['id']; ?>">
                                    <button type="submit" class="my-btn is-small">重置</button>
                                </form>
                                <form method="POST" style="display:inline;" onsubmit="return confirm('确定删除该令牌？此操作不可恢复');">
                                    <input type="hidden" name="token_action" value="delete_user_token">
                                    <input type="hidden" name="token_id" value="<?php echo (int) $t['id']; ?>">
                                    <button type="submit" class="my-btn is-small is-danger">删除</button>
                                </form>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <h4 class="my-subtitle">使用说明</h4>
            <div class="my-api-docs">
                <p>在请求头携带令牌：</p>
                <pre><code>Authorization: Bearer &lt;你的 Token&gt;</code></pre>
                <p>可用接口（数据范围：仅你本人导航，不能访问公共或其他用户数据）：</p>
                <pre><code>GET    /api/sites.php       分页列表（含 categories 字段）
POST   /api/sites.php       新建网址
PUT    /api/sites.php       更新网址
DELETE /api/sites.php       删除网址</code></pre>
                <p>示例：</p>
                <pre><code>curl -H "Authorization: Bearer &lt;TOKEN&gt;" \
     "https://你的域名/api/sites.php?page=1&size=20"</code></pre>
            </div>
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
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                        <path d="M12 16V4M7 9l5-5 5 5"></path>
                        <path d="M4 16v2.5A1.5 1.5 0 0 0 5.5 20h13a1.5 1.5 0 0 0 1.5-1.5V16"></path>
                    </svg>
                    上传头像
                </button>
                <?php if ($avatarUrl !== ''): ?>
                    <button type="submit" name="update_avatar" value="1" formaction="" class="my-btn" onclick="document.getElementById('avatarAction').value='remove';">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                            <path d="M4 7h16M9.5 7V5.2A1.2 1.2 0 0 1 10.7 4h2.6a1.2 1.2 0 0 1 1.2 1.2V7"></path>
                            <path d="M6.5 7 7.4 19a1.4 1.4 0 0 0 1.4 1.3h6.4A1.4 1.4 0 0 0 16.6 19L17.5 7"></path>
                            <path d="M10 11v5M14 11v5"></path>
                        </svg>
                        清除头像
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

    <!-- v2.16.0 · 回收站：列出本人已删除的网址，可恢复 / 彻底删除 / 一键清空 -->
    <!-- v2.16.2：AI 一键分类 -->
    <dialog class="my-dialog my-dialog-wide" id="aiCategorizeDialog">
        <form method="dialog" class="my-dialog-head">
            <h2>AI 智能分类</h2>
            <button type="button" class="my-iconbtn" data-close aria-label="关闭">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="m6 6 12 12M18 6 6 18"></path></svg>
            </button>
        </form>

        <div class="my-dialog-body">
            <div id="aiCatSetup">
                <p class="my-hint ai-cat-intro">AI 会读取每条网址的名称、地址和描述，判断它该放进哪个分组。分组不存在时自动新建，归属你自己，不会动公共分组。</p>

                <div class="ai-cat-scope">
                    <label class="ai-cat-option">
                        <input type="radio" name="aiCatScope" value="ungrouped" checked>
                        <span class="ai-cat-option-body">
                            <strong>只整理未分类</strong>
                            <em>已有分组的保持不动，最安全</em>
                        </span>
                    </label>
                    <label class="ai-cat-option">
                        <input type="radio" name="aiCatScope" value="all">
                        <span class="ai-cat-option-body">
                            <strong>整理全部网址</strong>
                            <em>已分组的也可能被重新归类</em>
                        </span>
                    </label>
                </div>

                <p class="my-msg is-error" id="aiCatSetupError" hidden></p>

                <div class="my-dialog-actions">
                    <button type="button" class="my-btn is-ghost" data-close>取消</button>
                    <button type="button" class="my-btn is-primary" id="aiCatStartBtn">开始分析</button>
                </div>
            </div>

            <div id="aiCatProgress" hidden>
                <div class="ai-cat-progress"><span id="aiCatProgressBar"></span></div>
                <p class="my-hint ai-cat-progress-text" id="aiCatProgressText">正在分析…</p>
                <div class="my-dialog-actions">
                    <button type="button" class="my-btn is-ghost" id="aiCatCancelBtn">中断</button>
                </div>
            </div>

            <div id="aiCatResult" hidden>
                <div class="ai-cat-toolbar">
                    <label class="my-check">
                        <input type="checkbox" id="aiCatSelectAll" checked>
                        <span>全选（已选 <span id="aiCatCount">0</span> / 共 <span id="aiCatCountAll">0</span> 项）</span>
                    </label>
                    <span class="my-hint" id="aiCatSummary"></span>
                </div>

                <div class="ai-cat-list" id="aiCatList"></div>

                <div class="my-dialog-actions ai-cat-actions">
                    <button type="button" class="my-btn is-ghost" id="aiCatBackBtn">重新分析</button>
                    <button type="button" class="my-btn is-primary" id="aiCatApplyBtn">应用分类</button>
                </div>
            </div>
        </div>
    </dialog>

    <dialog class="my-dialog" id="recycleDialog">
        <form method="dialog" class="my-dialog-head">
            <h2>回收站</h2>
            <button type="button" class="my-iconbtn" data-close aria-label="关闭">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="m6 6 12 12M18 6 6 18"></path></svg>
            </button>
        </form>

        <div class="my-dialog-body">
            <?php if ($recycleMsg !== ''): ?>
                <p class="my-msg is-ok" role="status"><?php echo $functions->escape($recycleMsg); ?></p>
            <?php endif; ?>
            <?php if ($recycleError !== ''): ?>
                <p class="my-msg is-error" role="alert"><?php echo $functions->escape($recycleError); ?></p>
            <?php endif; ?>

            <?php if (empty($recycleItems)): ?>
                <div class="my-empty is-compact">
                    <h3>回收站是空的</h3>
                    <p>删除的网址会先放到这里，可随时恢复。</p>
                </div>
            <?php else: ?>
                <div class="my-recycle-toolbar">
                    <label class="my-check">
                        <input type="checkbox" id="recycleSelectAll">
                        <span>全选（<span id="recycleCount"><?php echo count($recycleItems); ?></span> 项）</span>
                    </label>
                    <div class="my-recycle-actions">
                        <button type="button" class="my-btn is-small is-primary" id="recycleRestoreBtn">恢复所选</button>
                        <button type="button" class="my-btn is-small is-danger" id="recyclePurgeBtn">彻底删除所选</button>
                        <button type="button" class="my-btn is-small is-danger" id="recycleEmptyBtn">一键清空</button>
                    </div>
                </div>

                <ul class="my-recycle-list" id="recycleList">
                    <?php foreach ($recycleItems as $item): ?>
                        <li class="my-recycle-item">
                            <label class="my-picker">
                                <input type="checkbox" class="recycle-check" value="<?php echo (int) $item['id']; ?>" aria-label="选择 <?php echo $functions->escape($item['name']); ?>">
                            </label>
                            <div class="my-recycle-main">
                                <div class="my-recycle-name"><?php echo $functions->escape($item['name']); ?></div>
                                <div class="my-recycle-meta">
                                    <span class="my-recycle-host"><?php echo $functions->escape($item['host']); ?></span>
                                    <?php if ($item['category'] !== ''): ?>
                                        <span class="my-recycle-tag"><?php echo $functions->escape($item['category']); ?></span>
                                    <?php endif; ?>
                                    <span class="my-recycle-time">删除于 <?php echo $functions->escape(date('Y-m-d H:i', strtotime($item['deleted_at']))); ?></span>
                                </div>
                            </div>
                            <div class="my-recycle-item-actions">
                                <button type="button" class="my-btn is-small" data-recycle-restore="<?php echo (int) $item['id']; ?>">恢复</button>
                                <button type="button" class="my-btn is-small is-danger" data-recycle-purge="<?php echo (int) $item['id']; ?>">彻底删除</button>
                            </div>
                        </li>
                    <?php endforeach; ?>
                </ul>

                <p class="my-note">彻底删除后不可恢复。回收站最多显示最近 500 条。</p>
            <?php endif; ?>
        </div>

        <!-- 回收站表单：所有操作都通过 POST 提交并带 user_id 校验 -->
        <form method="POST" id="recycleForm" hidden>
            <input type="hidden" name="recycle_action" id="recycleActionInput" value="">
            <div id="recycleIdsHolder"></div>
        </form>
    </dialog>

    <!-- 卡片右键 / 长按菜单。
         v2.11.0：必须放在下方主交互脚本之前。此前它挂在 </body> 前，
         脚本同步执行时该节点尚未解析，qs('#cardContextMenu') 恒为 null，
         导致右键菜单和移动端长按菜单都弹不出来。 -->
    <div class="card-context-menu" id="cardContextMenu" hidden>
        <button type="button" class="ccm-item" data-action="open"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M14 4h6v6M20 4l-9 9M18 14v5a1 1 0 0 1-1 1H5a1 1 0 0 1-1-1V7a1 1 0 0 1 1-1h5"></path></svg>新标签打开</button>
        <button type="button" class="ccm-item" data-action="copy"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="9" y="9" width="13" height="13" rx="2"></rect><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"></path></svg>复制链接</button>
        <button type="button" class="ccm-item" data-action="qrcode"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="3" width="7" height="7"></rect><rect x="14" y="3" width="7" height="7"></rect><rect x="3" y="14" width="7" height="7"></rect><path d="M14 14h3v3M21 14v7M14 21h3M17 17h4"></path></svg>二维码</button>
        <!-- v2.18.6 · 与首页右键菜单统一为 7 项；文案、title、aria-label 由 syncCtxStar() 按 data-starred 同步 -->
        <button type="button" class="ccm-item" data-action="star" id="ctxMenuStar" title="收藏" aria-label="收藏"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 2l3.09 6.26L22 9.27l-5 4.87 1.18 6.88L12 17.77l-6.18 3.25L7 14.14 2 9.27l6.91-1.01L12 2z"></path></svg><span>收藏</span></button>
        <button type="button" class="ccm-item" data-action="edit"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 20h4l10-10-4-4L4 16v4z"></path><path d="M14 6l4 4"></path></svg>编辑</button>
        <button type="button" class="ccm-item" data-action="move"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 4h14M5 12h14M5 20h14"></path><path d="M9 8l3-3 3 3M9 16l3 3 3-3"></path></svg>移动分组</button>
        <button type="button" class="ccm-item ccm-danger" data-action="delete"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M4 7h16M9 7V5h6v2M6 7l1 13h10l1-13"></path></svg>删除</button>
    </div>

    <script>
        (function () {
            'use strict';

            var API_SITES = 'api/user_sites.php';
            var API_AUTH = 'api/auth.php';
            var API_SHARE = 'api/share.php';
            var API_BOOKMARK = 'api/bookmark_import.php';
            var API_CATEGORIES = 'api/user_categories.php';
            // v2.12.0：全站统一的新建分类/分组接口（后台、首页、分组管理弹窗共用）
            var API_CATEGORY_CREATE = 'api/category_create.php';
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

            /* ------------------------- v2.16.3 · 网址图标编辑 -------------------------
             * 弹窗里的图标区只管「当前选中了什么图标」，真正的落库完全交给保存时提交的
             * 隐藏字段（linkLogo / qaLogo）：
             *   ''                      → 不设图标，保存后由后端自动抓取
             *   uploads/logos/xxx.png   → 本地上传的图标
             *   https://...             → 外链图标
             * 用户没动过图标时保持原值，所以「编辑时不改图标 = 图标不变」是天然的。
             *
             * 做成工厂是因为编辑弹窗和快捷收藏弹窗各有一套，逻辑完全一样。
             */
            function createIconEditor(prefix, options) {
                options = options || {};

                var box = qs('#' + prefix + 'Preview');
                var img = qs('#' + prefix + 'Img');
                var letter = qs('#' + prefix + 'Letter');
                var fileInput = qs('#' + prefix + 'File');
                var uploadBtn = qs('#' + prefix + 'UploadBtn');
                var urlBtn = qs('#' + prefix + 'UrlBtn');
                var urlRow = qs('#' + prefix + 'UrlRow');
                var urlInput = qs('#' + prefix + 'UrlInput');
                var urlOk = qs('#' + prefix + 'UrlOk');
                var resetBtn = qs('#' + prefix + 'ResetBtn');
                var note = qs('#' + prefix + 'Note');
                var hidden = options.hiddenField ? qs(options.hiddenField) : null;
                var hint = note ? note.textContent : '';

                if (!box || !img) {
                    return null;
                }

                // 本次会话里上传但还没保存成功的临时文件，换图 / 关闭弹窗时要清掉
                var pendingUpload = '';
                var value = '';
                var uploading = false;
                var currentUrl = '';

                function setNote(text, type) {
                    if (!note) { return; }

                    note.textContent = text || hint;
                    note.className = 'my-note icon-note' + (type ? ' is-' + type : '');
                }

                function showImage(src) {
                    img.onerror = null;
                    img.hidden = false;
                    img.src = src;

                    if (letter) { letter.style.display = 'none'; }
                }

                function showLetter(text) {
                    img.hidden = true;
                    img.removeAttribute('src');

                    if (letter) {
                        letter.style.display = '';
                        letter.textContent = text || '?';
                    }
                }

                function guessIconSrc(logo) {
                    // 上传接口返回的是绝对地址；从列表数据回填时才需要补根路径
                    if (/^(https?:)?\/\//i.test(logo) || logo.charAt(0) === '/') {
                        return logo;
                    }

                    return '/' + logo;
                }

                function initialOf(url) {
                    var text = String(url || '').replace(/^https?:\/\//i, '').replace(/^www\./i, '');

                    if (!text) { return '?'; }

                    return text.charAt(0).toUpperCase();
                }

                function setValue(next) {
                    value = next || '';

                    if (hidden) { hidden.value = value; }

                    if (resetBtn) { resetBtn.hidden = value === ''; }
                }

                function busy(on, text) {
                    uploading = on;
                    box.classList.toggle('is-busy', on);

                    if (uploadBtn) { uploadBtn.disabled = on; }
                    if (urlBtn) { urlBtn.disabled = on; }
                    if (urlOk) { urlOk.disabled = on; }

                    if (text) { setNote(text); }
                }

                /** 丢弃上一次未保存的上传文件（失败静默，不打断主流程） */
                function discardPending() {
                    if (!pendingUpload) { return; }

                    var target = pendingUpload;
                    pendingUpload = '';

                    postForm(API_SITES, { action: 'upload_logo', discard: target });
                }

                /**
                 * 根据 logo 值渲染预览。
                 * @param {string} logo       图标路径 / 外链 / 空
                 * @param {string} siteUrl    网址，决定占位字母
                 * @param {string} [renderedSrc] 列表里已渲染好的图标地址（编辑时复用）
                 */
                function preview(logo, siteUrl, renderedSrc) {
                    if (logo) {
                        var src = renderedSrc || guessIconSrc(logo);
                        showImage(src);
                        img.onerror = function () {
                            img.onerror = null;
                            showLetter(initialOf(siteUrl));
                        };
                        return;
                    }

                    showLetter(initialOf(siteUrl));
                }

                /** 打开弹窗时重置到初始状态 */
                function reset(logo, siteUrl, renderedSrc) {
                    discardPending();
                    currentUrl = siteUrl || '';

                    if (urlRow) { urlRow.hidden = true; }
                    if (urlInput) { urlInput.value = ''; }

                    setNote('');
                    busy(false);
                    setValue(logo || '');
                    preview(value, currentUrl, renderedSrc);
                }

                if (uploadBtn && fileInput) {
                    uploadBtn.addEventListener('click', function () {
                        if (uploading) { return; }
                        fileInput.value = '';
                        fileInput.click();
                    });

                    fileInput.addEventListener('change', function () {
                        var file = fileInput.files && fileInput.files[0];

                        if (!file) { return; }

                        var name = (file.name || '').toLowerCase();

                        if (!/\.(png|jpe?g|svg|ico|webp)$/.test(name)) {
                            setNote('只支持 PNG / JPG / SVG / ICO 格式', 'error');
                            return;
                        }

                        if (file.size > 2 * 1024 * 1024) {
                            setNote('图标不能超过 2MB', 'error');
                            return;
                        }

                        busy(true, '正在上传…');

                        var body = new FormData();
                        body.append('action', 'upload_logo');
                        body.append('logo', file);

                        if (pendingUpload) {
                            body.append('discard', pendingUpload);
                            pendingUpload = '';
                        }

                        fetch(API_SITES, {
                            method: 'POST',
                            body: body,
                            credentials: 'same-origin',
                            headers: { 'X-Requested-With': 'XMLHttpRequest' }
                        }).then(function (response) {
                            return response.json().catch(function () {
                                return { ok: false, error: '服务器返回异常' };
                            });
                        }).then(function (result) {
                            busy(false);

                            if (!result || !result.ok) {
                                setNote((result && result.error) || '上传失败，请重试', 'error');
                                preview(value, currentUrl);
                                return;
                            }

                            pendingUpload = result.path;
                            setValue(result.path);
                            showImage(result.url || guessIconSrc(result.path));

                            var size = result.bytes ? Math.max(1, Math.round(result.bytes / 1024)) : 0;
                            setNote('图标已上传' + (size ? '（' + size + 'KB）' : '') + '，保存后生效', 'ok');
                        }).catch(function () {
                            busy(false);
                            setNote('网络异常，上传失败', 'error');
                        });
                    });
                }

                if (urlBtn && urlRow) {
                    urlBtn.addEventListener('click', function () {
                        var willOpen = urlRow.hidden;
                        urlRow.hidden = !willOpen;

                        if (willOpen && urlInput) {
                            urlInput.value = /^https?:\/\//i.test(value) ? value : '';
                            window.setTimeout(function () { urlInput.focus(); }, 40);
                        }
                    });
                }

                function applyUrl() {
                    if (!urlInput) { return; }

                    var url = urlInput.value.trim();

                    if (url === '') {
                        setNote('请填写图标 URL', 'error');
                        urlInput.focus();
                        return;
                    }

                    if (!/^https:\/\//i.test(url)) {
                        setNote('图标 URL 需要以 https:// 开头', 'error');
                        urlInput.focus();
                        return;
                    }

                    discardPending();
                    setValue(url);
                    showImage(url);
                    img.onerror = function () {
                        img.onerror = null;
                        showLetter(initialOf(currentUrl));
                        setNote('这个地址加载不出图片，请检查链接', 'error');
                    };
                    setNote('已使用外链图标，保存后生效', 'ok');

                    if (urlRow) { urlRow.hidden = true; }
                }

                if (urlOk) { urlOk.addEventListener('click', applyUrl); }

                if (urlInput) {
                    urlInput.addEventListener('keydown', function (event) {
                        if (event.key === 'Enter') {
                            event.preventDefault();
                            applyUrl();
                        }
                    });
                }

                if (resetBtn) {
                    resetBtn.addEventListener('click', function () {
                        discardPending();
                        setValue('');
                        preview('', currentUrl);
                        setNote('已恢复默认，保存后自动抓取网站图标', 'ok');
                    });
                }

                return {
                    reset: reset,
                    value: function () { return value; },
                    discardPending: discardPending,
                    setSiteUrl: function (url) { currentUrl = url || ''; },
                    /** 外部（比如「抓取」拿到站点图标后）直接设定图标 */
                    set: function (logo) {
                        setValue(logo || '');
                        preview(value, currentUrl);
                    }
                };
            }

            var linkIcon = createIconEditor('linkIcon', { hiddenField: '#linkLogo' });
            var quickIcon = createIconEditor('qaIcon', { hiddenField: '#qaLogo' });

            /* v2.16.5 · 标签编辑（收录弹窗）
               与首页 index.php 同一套行为：AI 生成 → 预填成可改的文本，
               用户清空即表示不要标签，绝不静默写库。 */
            function parseTags(text) {
                var raw = String(text || '').split(/[\s,，、;；]+/);
                var seen = {};
                var out = [];

                raw.forEach(function (item) {
                    var tag = String(item || '')
                        .replace(/^#+/, '')
                        .replace(/["'「」《》]/g, '')
                        .trim();

                    if (tag === '' || tag.length > 12) {
                        return;
                    }

                    var key = tag.toLowerCase();

                    if (seen[key]) {
                        return;
                    }

                    seen[key] = 1;
                    out.push(tag);
                });

                return out.slice(0, 8);
            }

            function createTagEditor(prefix, apiUrl) {
                var input = qs('#' + prefix + 'Tags');
                var wrap = qs('#' + prefix + 'TagPreview');
                var note = qs('#' + prefix + 'TagNote');
                var aiBtn = qs('#' + prefix + 'TagAiBtn');

                if (!input) {
                    return null;
                }

                var generating = false;

                function setNote(html) {
                    if (note) {
                        note.innerHTML = html || '多个标签用空格分隔；留空也可。';
                    }
                }

                function render() {
                    var tags = parseTags(input.value);

                    if (wrap) {
                        wrap.innerHTML = '';

                        tags.forEach(function (tag) {
                            var chip = document.createElement('span');
                            chip.className = 'tag-chip';
                            chip.appendChild(document.createTextNode(tag));

                            var del = document.createElement('button');
                            del.type = 'button';
                            del.setAttribute('aria-label', '移除标签 ' + tag);
                            del.textContent = '×';
                            del.addEventListener('click', function () {
                                input.value = parseTags(input.value).filter(function (item) {
                                    return item !== tag;
                                }).join(' ');
                                render();
                            });

                            chip.appendChild(del);
                            wrap.appendChild(chip);
                        });
                    }

                    return tags;
                }

                input.addEventListener('input', render);
                input.addEventListener('blur', function () {
                    input.value = parseTags(input.value).join(' ');
                    render();
                });

                if (aiBtn) {
                    aiBtn.addEventListener('click', function () {
                        if (generating) {
                            return;
                        }

                        var url = qs('#quickAddUrl') ? qs('#quickAddUrl').value.trim() : '';

                        if (url === '') {
                            setNote('先填写网址，AI 才知道该给什么标签。');
                            return;
                        }

                        generating = true;
                        aiBtn.textContent = '生成中…';
                        aiBtn.disabled = true;

                        postForm(apiUrl, {
                            action: 'suggest_tags',
                            url: url
                        }).then(function (res) {
                            generating = false;
                            aiBtn.textContent = 'AI 生成';

                            if (res && res.ok && res.tags && res.tags.length) {
                                input.value = res.tags.join(' ');
                                render();
                                setNote('<span class="is-ai">'
                                    + (res.source === 'ai' ? 'AI' : '规则')
                                    + ' 建议</span>：可直接改用，也可自己增删。');
                            } else {
                                setNote((res && res.error) || '暂时没想出合适的标签，可手动填写。');
                            }
                        }).catch(function () {
                            generating = false;
                            aiBtn.textContent = 'AI 生成';
                            setNote('网络错误，标签生成失败。');
                        });
                    });
                }

                return {
                    reset: function () {
                        input.value = '';
                        setNote('');
                        render();
                    },
                    set: function (tags) {
                        input.value = parseTags(tags).join(' ');
                        render();
                    },
                    value: function () {
                        return parseTags(input.value).join(' ');
                    }
                };
            }

            var quickTags = createTagEditor('qa', API_SITES);

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

                var sourceItem = null;

                if (item && item.id) {
                    sourceItem = document.querySelector('.my-item[data-id="' + item.id + '"]');
                }

                if (item) {
                    linkDialogTitle.textContent = '编辑网址';
                    linkId.value = item.id;
                    linkUrl.value = item.url;
                    linkName.value = item.name;
                    linkCategory.value = item.categoryId;
                    linkDescription.value = item.description || '';
                    linkLogo.value = item.logo || '';
                    if (qs('#linkTags')) { qs('#linkTags').value = item.tags || ''; }
                    if (qs('#linkAlias')) { qs('#linkAlias').value = item.alias || ''; }
                } else {
                    linkDialogTitle.textContent = '添加网址';
                    linkId.value = '';
                    linkUrl.value = '';
                    linkName.value = '';
                    linkDescription.value = '';
                    linkLogo.value = '';
                    if (qs('#linkTags')) { qs('#linkTags').value = ''; }
                    if (qs('#linkAlias')) { qs('#linkAlias').value = ''; }

                    if (linkCategory.options.length) {
                        linkCategory.selectedIndex = 0;
                    }
                }

                // 图标区跟着本次打开的数据重置；编辑时复用列表里已渲染好的图标
                if (linkIcon) {
                    var renderedIcon = null;

                    if (sourceItem) {
                        var renderedImg = sourceItem.querySelector('.my-icon img');

                        if (renderedImg) {
                            renderedIcon = renderedImg.getAttribute('src') || null;
                        }
                    }

                    linkIcon.reset(item ? (item.logo || '') : '', linkUrl.value, renderedIcon);

                    if (item && !item.logo && sourceItem) {
                        var previewImg = qs('#linkIconImg');
                        var previewLetter = qs('#linkIconLetter');

                        // 没设 logo 时列表用的是站点 favicon，这里沿用同一张图更贴近「当前样子」
                        if (renderedIcon && previewImg) {
                            previewImg.hidden = false;
                            previewImg.src = renderedIcon;
                            previewImg.onerror = function () {
                                previewImg.onerror = null;
                                previewImg.hidden = true;
                                if (previewLetter) {
                                    previewLetter.style.display = '';
                                    previewLetter.textContent = String(item.name || item.url || '?').charAt(0).toUpperCase();
                                }
                            };
                            if (previewLetter) { previewLetter.style.display = 'none'; }
                        }
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
                // 换了图标又取消：把还没落库的临时文件删掉，别在 uploads 里留孤儿
                if (linkIcon) { linkIcon.discardPending(); }

                if (typeof linkDialog.close === 'function') {
                    linkDialog.close();
                } else {
                    linkDialog.removeAttribute('open');
                }
            }

            // Esc 关闭走的是原生 dialog 事件，不经过 closeLinkDialog，这里补一次清理
            linkDialog && linkDialog.addEventListener('close', function () {
                if (linkIcon) { linkIcon.discardPending(); }
            });

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
                        logo: item.getAttribute('data-logo') || '',
                        tags: item.getAttribute('data-tags') || '',
                        alias: item.getAttribute('data-alias') || ''
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

            // API 令牌管理弹窗
            (function () {
                var dialog = qs('#apiTokenDialog');
                var openBtn = qs('#apiTokenBtn');
                var closeBtn = qs('#apiTokenClose');
                var copyBtn = qs('#copyNewTokenBtn');
                var msg = qs('#apiTokenMsg');

                if (openBtn && dialog) {
                    openBtn.addEventListener('click', function () {
                        dialog.showModal();
                    });
                }
                if (closeBtn && dialog) {
                    closeBtn.addEventListener('click', function () {
                        dialog.close();
                    });
                }

                // 创建 / 重置后页面刷新，自动重新打开弹窗并展示结果
                if (dialog && dialog.getAttribute('data-autoopen')) {
                    if (typeof dialog.showModal === 'function') {
                        dialog.showModal();
                    }
                    <?php if ($tokenMsg !== ''): ?>
                    if (msg) {
                        msg.textContent = <?php echo json_encode($tokenMsg); ?>;
                        msg.className = 'my-msg is-success';
                        msg.style.display = 'block';
                    }
                    <?php elseif ($tokenError !== ''): ?>
                    if (msg) {
                        msg.textContent = <?php echo json_encode($tokenError); ?>;
                        msg.className = 'my-msg is-error';
                        msg.style.display = 'block';
                    }
                    <?php endif; ?>
                }

                if (copyBtn) {
                    copyBtn.addEventListener('click', function () {
                        var text = qs('#newTokenValue') ? qs('#newTokenValue').textContent : '';
                        if (!text) { return; }
                        var done = function () {
                            copyBtn.textContent = '已复制';
                            setTimeout(function () { copyBtn.textContent = '复制'; }, 1500);
                        };
                        if (navigator.clipboard && navigator.clipboard.writeText) {
                            navigator.clipboard.writeText(text).then(done, function () {
                                window.prompt('复制下面的 Token：', text);
                            });
                        } else {
                            window.prompt('复制下面的 Token：', text);
                        }
                    });
                }
            })();

            // v2.16.0 · 回收站：恢复 / 彻底删除 / 清空
            (function () {
                var dialog = qs('#recycleDialog');
                var openBtn = qs('#recycleBtn');
                var form = qs('#recycleForm');
                var actionInput = qs('#recycleActionInput');
                var holder = qs('#recycleIdsHolder');
                var selectAll = qs('#recycleSelectAll');

                if (openBtn && dialog) {
                    openBtn.addEventListener('click', function () {
                        if (typeof dialog.showModal === 'function') {
                            dialog.showModal();
                        } else {
                            dialog.setAttribute('open', 'open');
                        }
                    });
                }

                if (dialog) {
                    var closeBtn = dialog.querySelector('[data-close]');
                    if (closeBtn) {
                        closeBtn.addEventListener('click', function () {
                            if (typeof dialog.close === 'function') { dialog.close(); }
                            else { dialog.removeAttribute('open'); }
                        });
                    }
                    // 操作完成后带 ?recycle=1 刷新回来，自动重新打开弹窗展示结果
                    if (window.location.search.indexOf('recycle=1') !== -1 && typeof dialog.showModal === 'function') {
                        dialog.showModal();
                    }
                }

                function checkedIds() {
                    var boxes = dialog ? dialog.querySelectorAll('.recycle-check') : [];
                    var ids = [];
                    for (var i = 0; i < boxes.length; i++) {
                        if (boxes[i].checked) { ids.push(boxes[i].value); }
                    }
                    return ids;
                }

                if (selectAll) {
                    selectAll.addEventListener('change', function () {
                        var boxes = dialog ? dialog.querySelectorAll('.recycle-check') : [];
                        for (var i = 0; i < boxes.length; i++) { boxes[i].checked = selectAll.checked; }
                    });
                }

                function submit(action, ids) {
                    if (!form || !actionInput || !holder) { return; }
                    holder.innerHTML = '';
                    ids = ids || [];
                    for (var i = 0; i < ids.length; i++) {
                        var input = document.createElement('input');
                        input.type = 'hidden';
                        input.name = 'recycle_ids[]';
                        input.value = ids[i];
                        holder.appendChild(input);
                    }
                    actionInput.value = action;
                    // 带上 recycle=1，服务端处理完跳回本页后自动重新打开弹窗展示结果
                    form.action = 'my.php?recycle=1';
                    form.submit();
                }

                // 单条恢复 / 彻底删除
                if (dialog) {
                    dialog.addEventListener('click', function (event) {
                        var target = event.target;
                        if (!target || !target.getAttribute) { return; }
                        var restoreId = target.getAttribute('data-recycle-restore');
                        var purgeId = target.getAttribute('data-recycle-purge');

                        if (restoreId) {
                            submit('restore', [restoreId]);
                        } else if (purgeId) {
                            if (confirm('彻底删除后不可恢复，确定继续吗？')) {
                                submit('purge', [purgeId]);
                            }
                        }
                    });
                }

                var restoreBtn = qs('#recycleRestoreBtn');
                if (restoreBtn) {
                    restoreBtn.addEventListener('click', function () {
                        var ids = checkedIds();
                        if (!ids.length) { alert('请先勾选要恢复的网址'); return; }
                        submit('restore', ids);
                    });
                }

                var purgeBtn = qs('#recyclePurgeBtn');
                if (purgeBtn) {
                    purgeBtn.addEventListener('click', function () {
                        var ids = checkedIds();
                        if (!ids.length) { alert('请先勾选要彻底删除的网址'); return; }
                        if (confirm('彻底删除所选 ' + ids.length + ' 个网址？此操作不可恢复。')) {
                            submit('purge', ids);
                        }
                    });
                }

                var emptyBtn = qs('#recycleEmptyBtn');
                if (emptyBtn) {
                    emptyBtn.addEventListener('click', function () {
                        if (confirm('确定清空回收站？站内所有已删除网址将被彻底删除，不可恢复。')) {
                            submit('empty', []);
                        }
                    });
                }
            })();

            // v2.16.2 · AI 一键分类：分批分析 → 预览清单 → 确认后落库
            (function () {
                var dialog = qs('#aiCategorizeDialog');
                var openBtn = qs('#aiCategorizeBtn');

                if (!dialog || !openBtn) { return; }

                var BATCH = 20;

                var setup = qs('#aiCatSetup');
                var progress = qs('#aiCatProgress');
                var resultBox = qs('#aiCatResult');
                var progressBar = qs('#aiCatProgressBar');
                var progressText = qs('#aiCatProgressText');
                var setupError = qs('#aiCatSetupError');
                var listBox = qs('#aiCatList');
                var countEl = qs('#aiCatCount');
                var countAllEl = qs('#aiCatCountAll');
                var summaryEl = qs('#aiCatSummary');
                var selectAll = qs('#aiCatSelectAll');
                var startBtn = qs('#aiCatStartBtn');
                var cancelBtn = qs('#aiCatCancelBtn');
                var backBtn = qs('#aiCatBackBtn');
                var applyBtn = qs('#aiCatApplyBtn');

                var rows = [];
                var aborted = false;
                var started = false;

                function showModal() {
                    if (typeof dialog.showModal === 'function') {
                        dialog.showModal();
                    } else {
                        dialog.setAttribute('open', 'open');
                    }
                }

                function hideModal() {
                    if (typeof dialog.close === 'function') {
                        dialog.close();
                    } else {
                        dialog.removeAttribute('open');
                    }
                }

                function resetUI() {
                    aborted = true;
                    started = false;
                    rows = [];
                    setup.hidden = false;
                    progress.hidden = true;
                    resultBox.hidden = true;
                    setupError.hidden = true;
                    setupError.textContent = '';
                    progressBar.style.width = '0%';
                    progressText.textContent = '正在分析…';
                    listBox.innerHTML = '';
                    countEl.textContent = '0';
                    if (countAllEl) { countAllEl.textContent = '0'; }
                    summaryEl.textContent = '';
                    applyBtn.disabled = false;
                    applyBtn.textContent = '应用分类';
                    startBtn.disabled = false;
                    startBtn.textContent = '开始分析';
                }

                function currentScope() {
                    var checked = dialog.querySelector('input[name="aiCatScope"]:checked');
                    return checked ? checked.value : 'ungrouped';
                }

                function fail(message) {
                    progress.hidden = true;
                    setup.hidden = false;
                    setupError.textContent = message;
                    setupError.hidden = false;
                }

                if (openBtn.getAttribute('aria-disabled') === 'true') {
                    openBtn.addEventListener('click', function () {
                        alert('还没有收藏任何网址，先添加几条再来分类吧');
                    });
                    return;
                }

                openBtn.addEventListener('click', function () {
                    resetUI();
                    showModal();
                });

                qsa('[data-close]', dialog).forEach(function (btn) {
                    btn.addEventListener('click', function () {
                        aborted = true;
                        hideModal();
                    });
                });

                // Esc 关闭时也要把状态复位，否则下次打开会停在半截
                dialog.addEventListener('close', function () {
                    aborted = true;
                });

                startBtn.addEventListener('click', function () {
                    if (started) { return; }

                    started = true;
                    aborted = false;
                    rows = [];

                    var scopeValue = currentScope();
                    var offset = 0;
                    var total = 0;

                    setup.hidden = true;
                    setupError.hidden = true;
                    resultBox.hidden = true;
                    progress.hidden = false;
                    progressBar.style.width = '0%';
                    progressText.textContent = '正在分析…';
                    startBtn.disabled = true;

                    function step() {
                        if (aborted) { return Promise.resolve(false); }

                        return postForm(API_SITES, {
                            action: 'ai_categorize_preview',
                            scope: scopeValue,
                            offset: offset,
                            limit: BATCH
                        }).then(function (data) {
                            if (aborted) { return false; }

                            if (!data || !data.ok) {
                                throw new Error((data && data.error) || '分析失败，请稍后重试');
                            }

                            total = data.total || 0;

                            if (!data.items || !data.items.length) { return false; }

                            rows = rows.concat(data.items);
                            offset = data.next || (offset + data.items.length);

                            var pct = total > 0 ? Math.min(100, Math.round(offset / total * 100)) : 100;
                            progressBar.style.width = pct + '%';
                            progressText.textContent = '已分析 ' + offset + ' / ' + total + ' 条…';

                            if (data.done || offset >= total) { return false; }

                            return step();
                        });
                    }

                    step().then(function () {
                        started = false;
                        startBtn.disabled = false;

                        if (aborted) { return; }

                        progress.hidden = true;

                        if (!rows.length) {
                            fail(scopeValue === 'ungrouped'
                                ? '没有未分类的网址，已经很整齐了'
                                : '没有可分析的网址');
                            return;
                        }

                        render();
                    }).catch(function (error) {
                        started = false;
                        startBtn.disabled = false;

                        if (aborted) { return; }

                        fail(error && error.message ? error.message : '分析失败，请稍后重试');
                    });
                });

                cancelBtn.addEventListener('click', function () {
                    aborted = true;
                    started = false;
                    startBtn.disabled = false;
                    resetUI();
                });

                backBtn.addEventListener('click', function () {
                    resetUI();
                });

                // 勾选状态变了就同步三处：已选计数、全选框、应用按钮可用性
                function syncSelection() {
                    var boxes = qsa('.ai-cat-check', dialog).filter(function (box) {
                        return !box.disabled;
                    });
                    var checked = boxes.filter(function (box) {
                        return box.checked;
                    }).length;

                    countEl.textContent = String(checked);

                    if (!boxes.length) {
                        selectAll.checked = false;
                        selectAll.indeterminate = false;
                        selectAll.disabled = true;
                    } else {
                        selectAll.disabled = false;
                        selectAll.checked = checked === boxes.length;
                        selectAll.indeterminate = checked > 0 && checked < boxes.length;
                    }

                    applyBtn.disabled = checked === 0;
                }

                selectAll.addEventListener('change', function () {
                    qsa('.ai-cat-check', dialog).forEach(function (box) {
                        if (!box.disabled) { box.checked = selectAll.checked; }
                    });
                    syncSelection();
                });

                // 单条勾选：事件委托到列表容器，省得每次渲染都重绑
                listBox.addEventListener('change', function (event) {
                    var target = event.target;
                    if (!target || target.className.indexOf('ai-cat-check') === -1) { return; }
                    syncSelection();
                });

                function render() {
                    listBox.innerHTML = '';

                    var changeable = 0;
                    var newGroups = {};
                    var shown = 0;

                    rows.forEach(function (item, index) {
                        if (!item.changed && !item.suggest_name) { return; }

                        shown++;

                        if (item.changed) {
                            changeable++;
                            if (item.is_new) { newGroups[item.suggest_name] = true; }
                        }

                        var row = document.createElement('label');
                        row.className = 'ai-cat-row';

                        var box = document.createElement('input');
                        box.type = 'checkbox';
                        box.className = 'ai-cat-check';
                        box.checked = !!item.changed;
                        box.disabled = !item.changed;
                        box.setAttribute('data-index', String(index));
                        row.appendChild(box);

                        var main = document.createElement('span');
                        main.className = 'ai-cat-row-main';

                        var name = document.createElement('span');
                        name.className = 'ai-cat-row-name';
                        name.textContent = item.name || item.url;
                        name.title = item.name || item.url;
                        main.appendChild(name);

                        var path = document.createElement('span');
                        path.className = 'ai-cat-row-path';

                        var from = document.createElement('span');
                        from.className = 'ai-cat-tag';
                        from.textContent = item.current_category_name || '未分类';
                        path.appendChild(from);

                        var arrow = document.createElement('span');
                        arrow.className = 'ai-cat-arrow';
                        arrow.textContent = '→';
                        path.appendChild(arrow);

                        var to = document.createElement('span');
                        to.className = 'ai-cat-tag' + (item.is_new ? ' is-new' : '');
                        to.textContent = item.suggest_name || '保持不变';
                        if (item.is_new) { to.title = '该分组不存在，应用后会新建'; }
                        path.appendChild(to);

                        main.appendChild(path);
                        row.appendChild(main);
                        listBox.appendChild(row);
                    });

                    resultBox.hidden = false;

                    var newCount = Object.keys(newGroups).length;

                    if (countAllEl) { countAllEl.textContent = String(changeable); }
                    summaryEl.textContent = changeable > 0
                        ? '共 ' + shown + ' 条，'
                            + changeable + ' 条建议调整'
                            + (newCount > 0 ? '，将新建 ' + newCount + ' 个分组' : '')
                        : 'AI 认为当前分类已经合理，无需调整';

                    syncSelection();
                    syncFade();
                }

                // 列表没溢出或已滚到底时，不需要底部渐隐
                function syncFade() {
                    if (!listBox) { return; }
                    var remains = listBox.scrollHeight - listBox.scrollTop - listBox.clientHeight;
                    listBox.classList.toggle('is-short', remains <= 2);
                }

                listBox.addEventListener('scroll', syncFade);

                applyBtn.addEventListener('click', function () {
                    var payload = [];

                    qsa('.ai-cat-check', dialog).forEach(function (box) {
                        if (!box.checked || box.disabled) { return; }

                        var index = parseInt(box.getAttribute('data-index'), 10);
                        var item = rows[index];

                        if (!item || !item.suggest_name) { return; }

                        payload.push({ id: item.id, category: item.suggest_name });
                    });

                    if (!payload.length) {
                        alert('请先勾选要应用的分类');
                        return;
                    }

                    applyBtn.disabled = true;
                    applyBtn.textContent = '应用中…';

                    postForm(API_SITES, {
                        action: 'ai_categorize_apply',
                        payload: JSON.stringify(payload)
                    }).then(function (data) {
                        if (!data || !data.ok) {
                            throw new Error((data && data.error) || '应用失败，请稍后重试');
                        }

                        var parts = ['分类成功：已调整 ' + (data.moved || 0) + ' 条网址'];

                        if (data.created && data.created.length) {
                            parts.push('新建分组 ' + data.created.length + ' 个：' + data.created.join('、'));
                        }

                        if (data.failed) {
                            parts.push(data.failed + ' 条未能更新');
                        }

                        if (data.skipped) {
                            parts.push(data.skipped + ' 条已在目标分组，已跳过');
                        }

                        applyBtn.textContent = '完成';
                        alert(parts.join('\n'));
                        window.location.reload();
                    }).catch(function (error) {
                        applyBtn.disabled = false;
                        applyBtn.textContent = '应用分类';
                        alert(error && error.message ? error.message : '应用失败，请稍后重试');
                    });
                });
            })();

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
                            if (linkIcon) {
                                linkIcon.set(meta.icons[0]);
                            } else {
                                linkLogo.value = meta.icons[0];
                            }
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
                    // 图标取自图标区的当前值；用户没动过就是原值，保存后保持不变
                    logo: linkIcon ? linkIcon.value() : linkLogo.value.trim(),
                    tags: (qs('#linkTags') ? qs('#linkTags').value.trim() : ''),
                    alias: (qs('#linkAlias') ? qs('#linkAlias').value.trim() : '')
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
                            logo: item.getAttribute('data-logo') || '',
                            tags: item.getAttribute('data-tags') || '',
                            alias: item.getAttribute('data-alias') || ''
                        });
                    }

                    return;
                }

                var deleteBtn = event.target.closest ? event.target.closest('.js-delete') : null;

                if (deleteBtn) {
                    var row = deleteBtn.closest('.my-item');

                    if (!row) { return; }

                    var snap = {
                        id: row.getAttribute('data-id'),
                        name: row.getAttribute('data-name'),
                        url: row.getAttribute('data-url'),
                        category: row.getAttribute('data-category'),
                        description: row.getAttribute('data-description') || '',
                        logo: row.getAttribute('data-logo') || ''
                    };

                    /* v2.11.0：列表删除按钮与右键/长按菜单保持同一套规则，
                       先二次确认，确认后仍保留 5 秒可撤销的提示条。 */
                    if (!window.confirm('确定删除「' + snap.name + '」吗？删除后可在提示条中撤销。')) {
                        return;
                    }

                    postForm(API_SITES, { action: 'delete', id: snap.id }).then(function (result) {
                        if (!result.ok) {
                            window.alert(result.error || '删除失败');
                            return;
                        }

                        row.style.display = 'none';

                        pushUndo({
                            type: 'delete',
                            undo: function () {
                                postForm(API_SITES, {
                                    action: 'add',
                                    url: snap.url,
                                    name: snap.name,
                                    category_id: snap.category,
                                    description: snap.description,
                                    logo: snap.logo
                                }).then(function (res) {
                                    if (res && res.ok) {
                                        row.style.display = '';
                                        row.setAttribute('data-id', res.id);
                                        showToast('已恢复');
                                    } else {
                                        showToast('恢复失败');
                                    }
                                });
                            }
                        });

                        showToast('已删除「' + snap.name + '」', function () {
                            var entry = popUndo();
                            if (entry && typeof entry.undo === 'function') { entry.undo(); }
                        });
                    });
                }
            });

            /* ----------------------------- 拖拽排序 ----------------------------- */

            var dragging = null;
            var dragOriginCategory = null;
            var autoScrollTimer = null;

            qsa('.my-list[data-sortable]').forEach(function (list) {
                list.addEventListener('dragstart', function (event) {
                    if (document.body.getAttribute('data-picking') === '1' || document.body.getAttribute('data-organize') !== '1') {
                        event.preventDefault();
                        return;
                    }

                    var item = event.target.closest ? event.target.closest('.my-item') : null;

                    if (!item) { return; }

                    dragging = item;
                    dragOriginCategory = item.getAttribute('data-category');
                    item.classList.add('is-dragging');

                    if (event.dataTransfer) {
                        event.dataTransfer.effectAllowed = 'move';
                        event.dataTransfer.setData('text/plain', item.getAttribute('data-id'));
                    }
                });

                list.addEventListener('dragover', function (event) {
                    if (!dragging) { return; }

                    event.preventDefault();
                    event.dataTransfer.dropEffect = 'move';

                    var target = event.target.closest ? event.target.closest('.my-item') : null;

                    qsa('.my-item.is-drop-before, .my-item.is-drop-after').forEach(function (node) {
                        node.classList.remove('is-drop-before', 'is-drop-after');
                    });

                    // 自动滚动
                    startAutoScroll(event);

                    if (!target || target === dragging) { return; }

                    var rect = target.getBoundingClientRect();
                    var after = event.clientY > rect.top + rect.height / 2;

                    target.classList.add(after ? 'is-drop-after' : 'is-drop-before');

                    if (after) {
                        list.insertBefore(dragging, target.nextSibling);
                    } else {
                        list.insertBefore(dragging, target);
                    }
                });

                list.addEventListener('drop', function (event) {
                    event.preventDefault();
                    stopAutoScroll();
                });

                list.addEventListener('dragleave', function (event) {
                    if (!event.relatedTarget || !list.contains(event.relatedTarget)) {
                        qsa('.my-item.is-drop-before, .my-item.is-drop-after').forEach(function (node) {
                            node.classList.remove('is-drop-before', 'is-drop-after');
                        });
                    }
                });

                list.addEventListener('dragend', function () {
                    if (!dragging) { return; }

                    stopAutoScroll();
                    dragging.classList.remove('is-dragging');
                    qsa('.my-item.is-drop-before, .my-item.is-drop-after').forEach(function (node) {
                        node.classList.remove('is-drop-before', 'is-drop-after');
                    });

                    var newCategory = dragging.getAttribute('data-category');
                    var currentList = dragging.closest('.my-group');
                    var newCatId = currentList ? currentList.getAttribute('data-group') : '0';
                    var crossGroup = dragOriginCategory !== newCatId;

                    var itemId = dragging.getAttribute('data-id');
                    var finishedItem = dragging;
                    var originCat = dragOriginCategory;
                    dragging = null;
                    dragOriginCategory = null;

                    if (crossGroup) {
                        // 跨分组：先 move 更新 category_id，再按分组重排
                        postForm(API_SITES, { action: 'move', id: itemId, category_id: newCatId }).then(function (res) {
                            if (!res || !res.ok) {
                                window.alert((res && res.error) || '移动失败');
                                window.location.reload();
                                return;
                            }
                            finishedItem.setAttribute('data-category', newCatId);
                            persistGroupOrder(newCatId);
                            if (originCat) { persistGroupOrder(originCat); }

                            pushUndo({
                                type: 'move',
                                undo: function () {
                                    postForm(API_SITES, { action: 'move', id: itemId, category_id: originCat }).then(function (r) {
                                        if (r && r.ok) {
                                            finishedItem.setAttribute('data-category', originCat);
                                            var origGroup = document.querySelector('.my-group[data-group="' + originCat + '"] .my-list');
                                            if (origGroup) { origGroup.appendChild(finishedItem); }
                                            persistGroupOrder(originCat);
                                            persistGroupOrder(newCatId);
                                            showToast('已移回原分组');
                                        }
                                    });
                                }
                            });

                            showToast('已移动到「' + getGroupName(newCatId) + '」', function () {
                                var entry = popUndo();
                                if (entry && typeof entry.undo === 'function') { entry.undo(); }
                            });
                        });
                    } else {
                        persistGroupOrder(newCatId);
                    }
                });
            });

            function getGroupName(catId) {
                var group = document.querySelector('.my-group[data-group="' + catId + '"] h3');
                return group ? group.textContent.trim().replace(/^./, '') : '分组';
            }

            function startAutoScroll(event) {
                stopAutoScroll();
                var threshold = 80;
                var y = event.clientY;
                var vh = window.innerHeight;

                autoScrollTimer = setInterval(function () {
                    if (y < threshold) {
                        window.scrollBy(0, -8);
                    } else if (y > vh - threshold) {
                        window.scrollBy(0, 8);
                    }
                }, 16);
            }

            function stopAutoScroll() {
                if (autoScrollTimer) {
                    clearInterval(autoScrollTimer);
                    autoScrollTimer = null;
                }
            }

            document.addEventListener('dragover', function (event) {
                if (dragging) {
                    startAutoScroll(event);
                }
            });

            document.addEventListener('drop', function () {
                stopAutoScroll();
            });

            var reorderStatus = qs('#reorderStatus');

            function persistGroupOrder(catId) {
                var group = document.querySelector('.my-group[data-group="' + catId + '"]');
                if (!group) { return; }
                var items = qsa('.my-item', group);
                var ids = items.map(function (node) { return node.getAttribute('data-id'); });
                if (!ids.length) { return; }

                postForm(API_SITES, { action: 'sort', ids: ids }).then(function (result) {
                    if (!result.ok) {
                        window.alert(result.error || '排序保存失败');
                        window.location.reload();
                    }
                });
            }

            function persistOrder() {
                // 兼容旧调用：按所有分组逐个重排
                qsa('.my-group').forEach(function (group) {
                    persistGroupOrder(group.getAttribute('data-group'));
                });
            }

            /* ---------- 分组拖拽排序 ---------- */
            var draggingGroup = null;

            qsa('.my-group-head').forEach(function (head) {
                head.setAttribute('draggable', 'true');

                head.addEventListener('dragstart', function (event) {
                    if (document.body.getAttribute('data-organize') !== '1') {
                        event.preventDefault();
                        return;
                    }
                    draggingGroup = head.closest('.my-group');
                    draggingGroup.classList.add('is-dragging');
                    if (event.dataTransfer) {
                        event.dataTransfer.effectAllowed = 'move';
                        event.dataTransfer.setData('text/plain', 'group');
                    }
                });

                head.addEventListener('dragover', function (event) {
                    if (!draggingGroup) { return; }
                    event.preventDefault();
                    var group = head.closest('.my-group');
                    if (group === draggingGroup) { return; }
                    qsa('.my-group.is-drop-before, .my-group.is-drop-after').forEach(function (g) {
                        g.classList.remove('is-drop-before', 'is-drop-after');
                    });
                    var rect = head.getBoundingClientRect();
                    var after = event.clientY > rect.top + rect.height / 2;
                    group.classList.add(after ? 'is-drop-after' : 'is-drop-before');
                    if (after) {
                        group.parentNode.insertBefore(draggingGroup, group.nextSibling);
                    } else {
                        group.parentNode.insertBefore(draggingGroup, group);
                    }
                });

                head.addEventListener('dragend', function () {
                    if (!draggingGroup) { return; }
                    draggingGroup.classList.remove('is-dragging');
                    qsa('.my-group.is-drop-before, .my-group.is-drop-after').forEach(function (g) {
                        g.classList.remove('is-drop-before', 'is-drop-after');
                    });
                    var groups = qsa('.my-group');
                    var ids = groups.map(function (g) { return g.getAttribute('data-group'); }).filter(function (id) { return id && id !== '0'; });
                    draggingGroup = null;
                    if (ids.length > 1) {
                        postForm(API_CATEGORIES, { action: 'reorder', ids: ids }).then(function (res) {
                            if (!res || !res.ok) {
                                window.alert((res && res.error) || '分组排序保存失败');
                            }
                        });
                    }
                });
            });

            /* ---------- 内联编辑：网址名称 / 分组标题 ---------- */
            function startInlineEdit(el, onSave, onCancel) {
                var original = el.textContent.trim();
                var input = document.createElement('input');
                input.type = 'text';
                input.value = original;
                input.className = 'my-inline-input';
                el.textContent = '';
                el.appendChild(input);
                input.focus();
                input.select();

                function finish(save) {
                    var val = input.value.trim();
                    input.remove();
                    if (save && val !== '' && val !== original) {
                        onSave(val, original);
                    } else {
                        el.textContent = original;
                        if (onCancel) { onCancel(); }
                    }
                }

                input.addEventListener('keydown', function (e) {
                    if (e.key === 'Enter') { e.preventDefault(); finish(true); }
                    else if (e.key === 'Escape') { e.preventDefault(); finish(false); }
                });
                input.addEventListener('blur', function () { finish(true); });
            }

            // 网址名称双击编辑
            qsa('.my-link-name').forEach(function (nameEl) {
                nameEl.style.cursor = 'text';
                nameEl.addEventListener('dblclick', function () {
                    var item = nameEl.closest('.my-item');
                    if (!item) { return; }
                    var id = item.getAttribute('data-id');
                    startInlineEdit(nameEl, function (newName, oldName) {
                        postForm(API_SITES, {
                            action: 'update',
                            id: id,
                            name: newName,
                            url: item.getAttribute('data-url'),
                            category_id: item.getAttribute('data-category'),
                            description: item.getAttribute('data-description') || ''
                        }).then(function (res) {
                            if (res && res.ok) {
                                item.setAttribute('data-name', newName);
                                nameEl.textContent = newName;

                                pushUndo({
                                    type: 'edit',
                                    undo: function () {
                                        postForm(API_SITES, {
                                            action: 'update',
                                            id: id,
                                            name: oldName,
                                            url: item.getAttribute('data-url'),
                                            category_id: item.getAttribute('data-category'),
                                            description: item.getAttribute('data-description') || ''
                                        }).then(function (r) {
                                            if (r && r.ok) {
                                                item.setAttribute('data-name', oldName);
                                                nameEl.textContent = oldName;
                                                showToast('已恢复原名');
                                            }
                                        });
                                    }
                                });

                                showToast('名称已更新', function () {
                                    var entry = popUndo();
                                    if (entry && typeof entry.undo === 'function') { entry.undo(); }
                                });
                            } else {
                                nameEl.textContent = oldName;
                                showToast((res && res.error) || '保存失败');
                            }
                        }).catch(function () {
                            nameEl.textContent = oldName;
                            showToast('网络错误');
                        });
                    });
                });
            });

            // 分组标题双击编辑（仅整理态）
            qsa('.my-group-head h3').forEach(function (h3) {
                h3.style.cursor = 'text';
                h3.addEventListener('dblclick', function () {
                    if (document.body.getAttribute('data-organize') !== '1') { return; }
                    var group = h3.closest('.my-group');
                    if (!group) { return; }
                    var catId = group.getAttribute('data-group');
                    if (!catId || catId === '0') { return; } // 未分类不可改名
                    var privacyEl = h3.querySelector('.my-group-privacy');
                    var privacyHtml = privacyEl ? privacyEl.outerHTML : '';
                    startInlineEdit(h3, function (newName, oldName) {
                        postForm(API_CATEGORIES, { action: 'rename', id: catId, name: newName }).then(function (res) {
                            if (res && res.ok) {
                                h3.innerHTML = newName + privacyHtml;
                                showToast('分组已重命名');
                            } else {
                                h3.innerHTML = oldName + privacyHtml;
                                showToast((res && res.error) || '保存失败');
                            }
                        }).catch(function () {
                            h3.innerHTML = oldName + privacyHtml;
                            showToast('网络错误');
                        });
                    });
                });
            });

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

            /* ===== 批量操作 ===== */
            var organizeBtn = qs('#organizeBtn');
            var organizeBtnLabel = qs('#organizeBtnLabel');
            var batchBar = qs('#batchBar');
            var batchCancelBtn = qs('#batchCancelBtn');
            var batchSelectAll = qs('#batchSelectAll');
            var batchCountNode = qs('#batchCount');
            var batchMoveBtn = qs('#batchMoveBtn');
            var batchTagBtn = qs('#batchTagBtn');
            var batchDeleteBtn = qs('#batchDeleteBtn');
            var batchMoveDialog = qs('#batchMoveDialog');
            var batchMoveCategory = qs('#batchMoveCategory');
            var batchMoveCount = qs('#batchMoveCount');
            var batchMoveMsg = qs('#batchMoveMsg');
            var batchMoveSubmit = qs('#batchMoveSubmit');
            var batchMoveClose = qs('#batchMoveClose');
            var batchMoveCancel = qs('#batchMoveCancel');
            var batchTagDialog = qs('#batchTagDialog');
            var batchTagInput = qs('#batchTagInput');
            var batchTagCount = qs('#batchTagCount');
            var batchTagMsg = qs('#batchTagMsg');
            var batchTagSubmit = qs('#batchTagSubmit');
            var batchTagClose = qs('#batchTagClose');
            var batchTagCancel = qs('#batchTagCancel');

            function isOrganizeMode() {
                return document.body.getAttribute('data-organize') === '1';
            }

            function enterOrganizeMode() {
                document.body.setAttribute('data-organize', '1');
                document.body.setAttribute('data-batch', '1');
                if (organizeBtnLabel) { organizeBtnLabel.textContent = '完成'; }
                updateBatchCount();
            }

            function exitOrganizeMode() {
                document.body.setAttribute('data-organize', '0');
                document.body.setAttribute('data-batch', '0');
                qsa('.my-picker input').forEach(function (input) { input.checked = false; });
                updateBatchCount();
                window.location.reload();
            }

            function getBatchIds() {
                return qsa('.my-picker input:checked').map(function (input) { return input.value; });
            }

            function updateBatchCount() {
                var ids = getBatchIds();
                if (batchCountNode) { batchCountNode.textContent = ids.length; }
                if (batchSelectAll) {
                    var total = qsa('.my-picker input').length;
                    batchSelectAll.checked = total > 0 && ids.length === total;
                    batchSelectAll.indeterminate = ids.length > 0 && ids.length < total;
                }
            }

            if (organizeBtn) {
                organizeBtn.addEventListener('click', function () {
                    if (isOrganizeMode()) {
                        exitOrganizeMode();
                    } else {
                        enterOrganizeMode();
                    }
                });
            }

            if (batchCancelBtn) {
                batchCancelBtn.addEventListener('click', exitOrganizeMode);
            }

            if (batchSelectAll) {
                batchSelectAll.addEventListener('change', function () {
                    var checked = batchSelectAll.checked;
                    qsa('.my-picker input').forEach(function (input) { input.checked = checked; });
                    updateBatchCount();
                });
            }

            if (batchMoveBtn) {
                batchMoveBtn.addEventListener('click', function () {
                    var ids = getBatchIds();
                    if (!ids.length) { window.alert('请先勾选要移动的网址'); return; }
                    if (batchMoveCount) { batchMoveCount.textContent = ids.length; }
                    if (batchMoveMsg) { batchMoveMsg.textContent = ''; batchMoveMsg.className = 'my-msg'; }
                    if (batchMoveDialog && typeof batchMoveDialog.showModal === 'function') {
                        batchMoveDialog.showModal();
                    }
                });
            }

            if (batchMoveClose) { batchMoveClose.addEventListener('click', function () { batchMoveDialog.close(); }); }
            if (batchMoveCancel) { batchMoveCancel.addEventListener('click', function () { batchMoveDialog.close(); }); }

            if (batchMoveSubmit) {
                batchMoveSubmit.addEventListener('click', function () {
                    var ids = getBatchIds();
                    if (!ids.length) { return; }
                    var catId = batchMoveCategory ? batchMoveCategory.value : '0';
                    setBusy(batchMoveSubmit, true, '移动中…');
                    postForm(API_SITES, { action: 'batch_move', ids: ids.join(','), category_id: catId }).then(function (res) {
                        setBusy(batchMoveSubmit, false);
                        if (res && res.ok) {
                            if (batchMoveDialog) { batchMoveDialog.close(); }
                            exitOrganizeMode();
                            window.location.reload();
                        } else if (batchMoveMsg) {
                            batchMoveMsg.textContent = (res && res.error) || '移动失败';
                            batchMoveMsg.className = 'my-msg is-error';
                        }
                    }).catch(function () {
                        setBusy(batchMoveSubmit, false);
                        if (batchMoveMsg) {
                            batchMoveMsg.textContent = '网络错误，请稍后重试';
                            batchMoveMsg.className = 'my-msg is-error';
                        }
                    });
                });
            }

            if (batchTagBtn) {
                batchTagBtn.addEventListener('click', function () {
                    var ids = getBatchIds();
                    if (!ids.length) { window.alert('请先勾选网址'); return; }
                    if (batchTagCount) { batchTagCount.textContent = ids.length; }
                    if (batchTagInput) { batchTagInput.value = ''; }
                    if (batchTagMsg) { batchTagMsg.textContent = ''; batchTagMsg.className = 'my-msg'; }
                    var radios = document.getElementsByName('batchTagMode');
                    radios.forEach(function (r) { r.checked = r.value === 'append'; });
                    if (batchTagDialog && typeof batchTagDialog.showModal === 'function') {
                        batchTagDialog.showModal();
                    }
                });
            }

            if (batchTagClose) { batchTagClose.addEventListener('click', function () { batchTagDialog.close(); }); }
            if (batchTagCancel) { batchTagCancel.addEventListener('click', function () { batchTagDialog.close(); }); }

            if (batchTagSubmit) {
                batchTagSubmit.addEventListener('click', function () {
                    var ids = getBatchIds();
                    if (!ids.length) { return; }
                    var tags = batchTagInput ? batchTagInput.value.trim() : '';
                    if (!tags) { window.alert('请输入标签'); return; }
                    var mode = 'append';
                    document.getElementsByName('batchTagMode').forEach(function (r) { if (r.checked) mode = r.value; });
                    setBusy(batchTagSubmit, true, '应用中…');
                    postForm(API_SITES, { action: 'batch_tag', ids: ids.join(','), tags: tags, mode: mode }).then(function (res) {
                        setBusy(batchTagSubmit, false);
                        if (res && res.ok) {
                            if (batchTagDialog) { batchTagDialog.close(); }
                            showToast('已为 ' + ids.length + ' 个网址更新标签');
                            exitOrganizeMode();
                        } else if (batchTagMsg) {
                            batchTagMsg.textContent = (res && res.error) || '操作失败';
                            batchTagMsg.className = 'my-msg is-error';
                        }
                    }).catch(function () {
                        setBusy(batchTagSubmit, false);
                        if (batchTagMsg) {
                            batchTagMsg.textContent = '网络错误，请稍后重试';
                            batchTagMsg.className = 'my-msg is-error';
                        }
                    });
                });
            }

            if (batchDeleteBtn) {
                batchDeleteBtn.addEventListener('click', function () {
                    var ids = getBatchIds();
                    if (!ids.length) { window.alert('请先勾选要删除的网址'); return; }
                    if (!window.confirm('确定删除选中的 ' + ids.length + ' 个网址吗？此操作不可撤销。')) { return; }
                    setBusy(batchDeleteBtn, true, '删除中…');
                    postForm(API_SITES, { action: 'batch_delete', ids: ids.join(',') }).then(function (res) {
                        setBusy(batchDeleteBtn, false);
                        if (res && res.ok) {
                            exitBatchMode();
                            window.location.reload();
                        } else {
                            window.alert((res && res.error) || '删除失败');
                        }
                    }).catch(function () {
                        setBusy(batchDeleteBtn, false);
                        window.alert('网络错误，请稍后重试');
                    });
                });
            }

            document.addEventListener('change', function (event) {
                if (event.target && event.target.closest && event.target.closest('.my-picker')) {
                    if (document.body.getAttribute('data-batch') === '1') {
                        updateBatchCount();
                    }
                }
            });

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

            /* ----------------------------- 昵称 ----------------------------- */

            var saveNicknameBtn = qs('#saveNicknameBtn');
            var nicknameInput = qs('#nickname');

            if (saveNicknameBtn && nicknameInput) {
                saveNicknameBtn.addEventListener('click', function () {
                    setBusy(saveNicknameBtn, true, '保存中…');

                    postForm(API_AUTH, {
                        action: 'update_profile',
                        nickname: nicknameInput.value.trim()
                    }).then(function (result) {
                        setBusy(saveNicknameBtn, false);

                        if (!result.ok) {
                            showMsg(accountMsg, result.error || '保存失败');
                            return;
                        }

                        showMsg(accountMsg, '昵称已保存', 'success');
                        window.setTimeout(function () { window.location.reload(); }, 700);
                    });
                });
            }

            // 前台头像下拉「账号设置」会带 ?open=account 跳过来，直接展开弹窗
            if (/(?:^|[?&])open=account(?:&|$)/.test(window.location.search)) {
                if (window.history && window.history.replaceState) {
                    try {
                        var cleanUrl = new URL(window.location.href);
                        cleanUrl.searchParams.delete('open');
                        window.history.replaceState({}, '', cleanUrl.toString());
                    } catch (urlError) { /* 老浏览器忽略即可 */ }
                }

                var accountEntry = qs('#accountBtn');
                if (accountEntry) { accountEntry.click(); }
            }

            /* ----------------------------- 分组管理 ----------------------------- */

            var groupDialog = qs('#groupDialog');
            var groupMsg = qs('#groupMsg');
            var groupList = qs('#groupList');
            var groupListEmpty = qs('#groupListEmpty');
            var groupNameNew = qs('#groupNameNew');
            var groupPrivateNew = qs('#groupPrivateNew');
            var groupCreateBtn = qs('#groupCreateBtn');
            var groupTabsTrack = qs('#groupTabsTrack');
            // 分组结构在弹窗内被改动过（新建 / 重命名 / 私密 / 删除 / 排序）：关闭弹窗时刷新页面，
            // 让服务端重新渲染 tab、分组区块与计数，作为前端即时同步之外的最终一致性兜底。
            var groupStructureDirty = false;

            /* v2.14.0：页面上所有需要跟随 OWN_GROUPS 变化的分组下拉。
               以前只重建 #linkCategory，导致新建分组后其余 4 个弹窗仍是旧列表。 */
            var CATEGORY_SELECT_IDS = [
                '#linkCategory',       // 添加网址
                '#moveCategory',       // 单个移动
                '#batchMoveCategory',  // 批量移动
                '#quickAddCategory',   // 快捷收藏
                '#batchPasteCategory'  // 批量粘贴默认分组
            ];

            function normalizeGroup(raw) {
                return {
                    id: parseInt(raw && raw.id, 10) || 0,
                    name: String((raw && raw.name) || ''),
                    is_private: raw && raw.is_private ? 1 : 0
                };
            }

            // 统一 id 为数字：避免接口返回字符串 id 时，重命名 / 私密 / 删除里的 === 比较全部落空。
            OWN_GROUPS = OWN_GROUPS.map(normalizeGroup).filter(function (group) { return group.id > 0; });

            function groupTabLabel(group) {
                return (group.is_private ? '\ud83d\udd12 ' : '') + group.name;
            }

            function groupSectionCount(id) {
                var count = qs('.my-group[data-group="' + id + '"] .my-group-count');
                return count ? (count.textContent || '0').trim() : '0';
            }

            /**
             * 重建顶部 tab 栏里的本人分组按钮。
             * 「全部网站」「未分类」由服务端渲染且保持不变，这里只重建中间的动态项，
             * 保证新建 / 重命名 / 私密切换 / 删除 / 排序后 tab 与 OWN_GROUPS 完全一致。
             */
            function renderGroupTabs(activeId) {
                if (!groupTabsTrack) { return; }

                var track = groupTabsTrack;
                var active = activeId === undefined || activeId === null
                    ? (function () {
                        var current = qs('.my-group-tab.is-active', track);
                        return current ? current.getAttribute('data-group') : 'all';
                    })()
                    : String(activeId);

                qsa('.my-group-tab[data-group]', track).forEach(function (tab) {
                    var id = tab.getAttribute('data-group');
                    if (id !== 'all' && id !== '0') { tab.remove(); }
                });

                var ungroupedTab = qs('.my-group-tab[data-group="0"]', track);

                OWN_GROUPS.forEach(function (group) {
                    var tab = document.createElement('button');
                    tab.type = 'button';
                    tab.className = 'my-group-tab';
                    tab.setAttribute('data-group', String(group.id));
                    tab.setAttribute('role', 'tab');
                    tab.setAttribute('aria-selected', 'false');
                    tab.textContent = groupTabLabel(group);

                    var count = document.createElement('span');
                    count.className = 'mgt-count';
                    count.textContent = groupSectionCount(group.id);
                    tab.appendChild(count);

                    track.insertBefore(tab, ungroupedTab || null);
                });

                var activeTab = null;
                qsa('.my-group-tab', track).forEach(function (tab) {
                    if (tab.getAttribute('data-group') === active) { activeTab = tab; }
                });

                if (!activeTab) { activeTab = qs('.my-group-tab[data-group="all"]', track); }

                qsa('.my-group-tab', track).forEach(function (tab) {
                    var on = tab === activeTab;
                    tab.classList.toggle('is-active', on);
                    tab.setAttribute('aria-selected', on ? 'true' : 'false');
                });
            }

            // tab 点击：事件委托，重建按钮后无需重新绑定
            if (groupTabsTrack) {
                groupTabsTrack.addEventListener('click', function (event) {
                    var tab = event.target.closest ? event.target.closest('.my-group-tab') : null;
                    if (!tab) { return; }

                    qsa('.my-group-tab', groupTabsTrack).forEach(function (node) {
                        var on = node === tab;
                        node.classList.toggle('is-active', on);
                        node.setAttribute('aria-selected', on ? 'true' : 'false');
                    });

                    var id = tab.getAttribute('data-group');
                    if (id === 'all') {
                        window.scrollTo({ top: 0, behavior: 'smooth' });
                        return;
                    }

                    var section = null;
                    qsa('.my-group').forEach(function (node) {
                        if (node.getAttribute('data-group') === id) { section = node; }
                    });

                    if (section && section.scrollIntoView) {
                        section.scrollIntoView({ behavior: 'smooth', block: 'start' });
                    }
                });

                // 首屏也按 OWN_GROUPS 校准一次，保证服务端渲染与前端重建结果完全一致
                renderGroupTabs('all');
            }

            /**
             * 同步页面主体里的分组区块：新建的空分组立刻出现，重命名 / 私密改标题与锁标，
             * 删除时先把该组网址挪回「未分类」再移除空壳（800ms 后的整页刷新会做最终对齐）。
             */
            function syncGroupSections() {
                var container = qs('#myMain');
                if (!container) { return; }

                var sections = qsa('.my-group', container);
                var lastSection = sections.length ? sections[sections.length - 1] : null;
                var ungroupedSection = null;

                sections.forEach(function (section) {
                    if (section.getAttribute('data-group') === '0') { ungroupedSection = section; }
                });

                var known = {};
                OWN_GROUPS.forEach(function (group) { known[group.id] = group; });

                sections.forEach(function (section) {
                    var id = parseInt(section.getAttribute('data-group'), 10) || 0;
                    if (id === 0 || known[id]) { return; }

                    var list = qs('.my-list', section);
                    var target = ungroupedSection ? qs('.my-list', ungroupedSection) : null;

                    if (list && target) {
                        while (list.firstChild) { target.appendChild(list.firstChild); }
                    }

                    section.remove();
                });

                OWN_GROUPS.forEach(function (group) {
                    var section = null;
                    qsa('.my-group', container).forEach(function (node) {
                        if (parseInt(node.getAttribute('data-group'), 10) === group.id) { section = node; }
                    });

                    if (!section) {
                        section = document.createElement('section');
                        section.className = 'my-group';
                        section.setAttribute('data-group', String(group.id));
                        section.innerHTML = '<div class="my-group-head"><h3></h3>' +
                            '<span class="my-group-count">0</span></div>' +
                            '<div class="my-list" data-sortable="1"></div>';

                        if (lastSection && lastSection.parentNode) {
                            lastSection.parentNode.insertBefore(section, lastSection.nextSibling);
                        } else {
                            var tabs = qs('.my-group-tabs');
                            if (tabs && tabs.parentNode) {
                                tabs.parentNode.insertBefore(section, tabs.nextSibling);
                            } else {
                                container.appendChild(section);
                            }
                        }

                        lastSection = section;
                    }

                    var head = qs('.my-group-head h3', section);
                    if (!head) { return; }

                    head.textContent = group.name;

                    var privacy = qs('.my-group-privacy', head);
                    if (group.is_private && !privacy) {
                        privacy = document.createElement('span');
                        privacy.className = 'my-group-privacy';
                        privacy.title = '私密分组：仅本人可见';
                        privacy.textContent = '\ud83d\udd12';
                        head.appendChild(privacy);
                    } else if (!group.is_private && privacy) {
                        privacy.remove();
                    }
                });
            }

            /** 分组数据变化后的唯一同步入口：下拉、弹窗列表、主体区块、顶部 tab 一次补齐。 */
            function syncGroupUI(options) {
                var opts = options || {};
                renderGroupList(opts.highlightId || 0);
                refreshCategorySelects();
                syncGroupSections();
                renderGroupTabs(opts.activeId);
                groupStructureDirty = true;
            }

            function renderGroupList(highlightId) {
                if (!groupList) { return; }

                groupList.innerHTML = '';

                if (!OWN_GROUPS.length) {
                    if (groupListEmpty) { groupListEmpty.hidden = false; }
                    return;
                }

                if (groupListEmpty) { groupListEmpty.hidden = true; }

                OWN_GROUPS.forEach(function (group) {
                    var li = document.createElement('li');
                    li.className = 'my-group-item';
                    li.setAttribute('data-id', group.id);

                    /* v2.11.0：一行只留「排序手柄 + 名称 + 私密 + 更多」四个元素。
                       以前上移/下移/输入框/锁/私密/保存/删除挤成一排，窄屏极易误触。
                       重命名改为失焦或回车自动保存，编辑与删除收进「更多」菜单。 */
                    li.innerHTML =
                        '<button type="button" class="my-group-handle" data-id="' + group.id + '" draggable="true"' +
                            ' title="拖动排序；聚焦后可用上下方向键"' +
                            ' aria-label="调整「' + escapeHtml(group.name) + '」的顺序">' +
                            '<svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">' +
                                '<circle cx="9" cy="6" r="1.4"></circle><circle cx="15" cy="6" r="1.4"></circle>' +
                                '<circle cx="9" cy="12" r="1.4"></circle><circle cx="15" cy="12" r="1.4"></circle>' +
                                '<circle cx="9" cy="18" r="1.4"></circle><circle cx="15" cy="18" r="1.4"></circle>' +
                            '</svg>' +
                        '</button>' +
                        '<input type="text" class="my-group-name" value="' + escapeHtml(group.name) + '" maxlength="30"' +
                            ' aria-label="分组名称，修改后按回车或失焦自动保存">' +
                        '<label class="my-check my-group-private-toggle" title="私密分组仅本人可见"><input type="checkbox" class="my-group-private" ' + (group.is_private ? 'checked' : '') + '> 私密</label>' +
                        '<button type="button" class="my-group-more" aria-label="更多操作" aria-expanded="false" title="更多操作">' +
                            '<svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><circle cx="5" cy="12" r="1.6"></circle><circle cx="12" cy="12" r="1.6"></circle><circle cx="19" cy="12" r="1.6"></circle></svg>' +
                        '</button>' +
                        '<div class="my-group-menu" hidden>' +
                            '<button type="button" class="my-group-menu-item my-group-rename">重命名</button>' +
                            '<button type="button" class="my-group-menu-item is-danger my-group-del">删除分组</button>' +
                        '</div>';

                    // 新建成功的项做一次短暂高亮，确保「立即显示」肉眼可见
                    if (highlightId && group.id === highlightId) {
                        li.classList.add('is-new');
                    }

                    groupList.appendChild(li);
                });

                // 分组多时新项可能落在滚动区外，主动滚进可视范围
                var fresh = highlightId
                    ? groupList.querySelector('.my-group-item[data-id="' + highlightId + '"]')
                    : null;
                if (fresh && fresh.scrollIntoView) {
                    fresh.scrollIntoView({ block: 'nearest' });
                }
            }

            function refreshCategorySelects() {
                // v2.14.0：5 个下拉全部按 OWN_GROUPS 当前顺序重建，各自保留仍存在的选中值
                CATEGORY_SELECT_IDS.forEach(function (selector) {
                    var select = qs(selector);
                    if (!select) { return; }

                    var current = select.value;
                    select.innerHTML = '';

                    var emptyOpt = document.createElement('option');
                    emptyOpt.value = '0';
                    emptyOpt.textContent = '未分类';
                    select.appendChild(emptyOpt);

                    OWN_GROUPS.forEach(function (group) {
                        var opt = document.createElement('option');
                        opt.value = String(group.id);
                        opt.textContent = groupTabLabel(group);
                        select.appendChild(opt);
                    });

                    // 原选项仍存在则保持选中，否则回落到「未分类」
                    select.value = Array.prototype.some.call(select.options, function (opt) {
                        return opt.value === current;
                    }) ? current : '0';
                });

                /* 批量粘贴「逐条校对」里的下拉是运行时生成的，做增量同步：
                   只补新增分组、更新已有本人分组文案，不动接口返回的公共分类选项。
                   删除的分组会在 800ms 后整页刷新时由服务端重新下发候选列表。 */
                qsa('.bp-item select[aria-label="保存到分组"]').forEach(function (select) {
                    var current = select.value;

                    OWN_GROUPS.forEach(function (group) {
                        var opt = qs('option[value="' + group.id + '"]', select);
                        if (!opt) {
                            opt = document.createElement('option');
                            opt.value = String(group.id);
                            select.appendChild(opt);
                        }
                        opt.textContent = groupTabLabel(group);
                    });

                    if (current && qs('option[value="' + current + '"]', select)) {
                        select.value = current;
                    }
                });
            }

            function submitGroupOrder() {
                var ids = OWN_GROUPS.map(function (group) { return group.id; });

                postForm(API_CATEGORIES, { action: 'reorder', ids: ids }).then(function (result) {
                    if (!result.ok) {
                        showMsg(groupMsg, result.error || '排序保存失败');
                        return;
                    }

                    groupStructureDirty = true;
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

            // 分组结构有变动后，关闭弹窗时整页刷新：服务端重新渲染 tab / 分组区块 / 计数，
            // 这是前端即时同步之外的最终一致性兜底（也是需求里允许的 reload 方案）。
            if (groupDialog) {
                groupDialog.addEventListener('close', function () {
                    if (groupStructureDirty) {
                        window.location.reload();
                    }
                });
            }

            if (groupCreateBtn) {
                groupCreateBtn.addEventListener('click', function () {
                    /* v2.12.0：改用全站统一的名称清洗/校验规则 + 统一创建接口，
                       与后台分类管理、导航管理、首页分类栏完全一致。 */
                    var checked = window.XYCategoryQuickAdd && window.XYCategoryQuickAdd.validateName
                        ? window.XYCategoryQuickAdd.validateName(groupNameNew.value)
                        : { ok: false, value: groupNameNew.value.trim(), error: '统一分类组件未加载，请刷新页面重试' };

                    if (!checked.ok) {
                        showMsg(groupMsg, checked.error);
                        return;
                    }

                    setBusy(groupCreateBtn, true, '创建中…');

                    postForm(API_CATEGORY_CREATE, {
                        scope: 'front', // v2.14.0：前台入口永远建成本人分组，即使同时登录了后台
                        name: checked.value,
                        is_private: groupPrivateNew.checked ? '1' : '0'
                    }).then(function (result) {
                        setBusy(groupCreateBtn, false);

                        if (!result.ok) {
                            showMsg(groupMsg, result.error || '创建失败');
                            return;
                        }

                        var created = normalizeGroup(result);

                        if (!result.reused) {
                            OWN_GROUPS.push(created);
                        }

                        showMsg(groupMsg, result.reused ? '已存在同名分组，直接复用' : '分组已创建', 'success');
                        groupNameNew.value = '';
                        groupPrivateNew.checked = false;
                        // 列表、5 个下拉、页面 tab、分组区块一次同步
                        syncGroupUI({ highlightId: created.id });
                    });
                });
            }

            if (groupList) {
                function closeGroupMenus() {
                    qsa('.my-group-menu').forEach(function (menu) { menu.hidden = true; });
                    qsa('.my-group-more').forEach(function (btn) { btn.setAttribute('aria-expanded', 'false'); });
                }

                function moveGroupTo(id, to) {
                    var from = -1;
                    OWN_GROUPS.forEach(function (group, idx) { if (group.id === id) { from = idx; } });

                    if (from < 0 || to < 0 || to >= OWN_GROUPS.length || from === to) { return false; }

                    var moved = OWN_GROUPS.splice(from, 1)[0];
                    OWN_GROUPS.splice(to, 0, moved);

                    syncGroupUI();
                    submitGroupOrder();

                    var handle = groupList.querySelector('.my-group-handle[data-id="' + id + '"]');
                    if (handle) { handle.focus(); }

                    return true;
                }

                function renameGroup(id, input) {
                    var name = input ? input.value.trim() : '';

                    if (!id || name === '') {
                        showMsg(groupMsg, '请输入分组名称');
                        return;
                    }

                    var unchanged = false;
                    OWN_GROUPS.forEach(function (group) { if (group.id === id && group.name === name) { unchanged = true; } });
                    if (unchanged) { return; }

                    postForm(API_CATEGORIES, { action: 'rename', id: id, name: name }).then(function (result) {
                        if (!result.ok) {
                            showMsg(groupMsg, result.error || '重命名失败');
                            return;
                        }

                        OWN_GROUPS.forEach(function (group) { if (group.id === id) { group.name = name; } });
                        syncGroupUI();
                        showMsg(groupMsg, '分组名称已保存', 'success');
                    });
                }

                function deleteGroup(id) {
                    if (!window.confirm('删除该分组？分组下的网址会移到「未分类」，不会被删除。')) { return; }

                    postForm(API_CATEGORIES, { action: 'delete', id: id }).then(function (result) {
                        if (!result.ok) {
                            showMsg(groupMsg, result.error || '删除失败');
                            return;
                        }

                        OWN_GROUPS = OWN_GROUPS.filter(function (group) { return group.id !== id; });
                        syncGroupUI();
                        showMsg(groupMsg, '分组已删除', 'success');
                        window.setTimeout(function () { window.location.reload(); }, 800);
                    });
                }

                groupList.addEventListener('click', function (event) {
                    var target = event.target;
                    if (!target.closest) { return; }

                    var item = target.closest('.my-group-item');
                    if (!item) { return; }

                    var id = parseInt(item.getAttribute('data-id'), 10) || 0;

                    if (target.closest('.my-group-more')) {
                        var menu = item.querySelector('.my-group-menu');
                        var willOpen = menu ? menu.hidden : false;
                        closeGroupMenus();
                        if (menu) { menu.hidden = !willOpen; }
                        target.closest('.my-group-more').setAttribute('aria-expanded', willOpen ? 'true' : 'false');
                        return;
                    }

                    if (target.closest('.my-group-rename')) {
                        var input = item.querySelector('.my-group-name');
                        if (input) { input.focus(); input.select(); }
                        closeGroupMenus();
                        return;
                    }

                    if (target.closest('.my-group-del')) {
                        closeGroupMenus();
                        deleteGroup(id);
                    }
                });

                // 点击行外收起「更多」菜单
                document.addEventListener('click', function (event) {
                    if (event.target.closest && event.target.closest('.my-group-item')) { return; }
                    closeGroupMenus();
                });

                groupList.addEventListener('keydown', function (event) {
                    var target = event.target;

                    if (target.classList.contains('my-group-handle') && (event.key === 'ArrowUp' || event.key === 'ArrowDown')) {
                        event.preventDefault();
                        var item = target.closest('.my-group-item');
                        var id = parseInt(item.getAttribute('data-id'), 10) || 0;
                        var from = -1;
                        OWN_GROUPS.forEach(function (group, idx) { if (group.id === id) { from = idx; } });
                        moveGroupTo(id, from + (event.key === 'ArrowUp' ? -1 : 1));
                        return;
                    }

                    if (target.classList.contains('my-group-name') && event.key === 'Enter') {
                        event.preventDefault();
                        target.blur();
                    }
                });

                /* 拖拽排序：只有手柄可拖动，输入框仍可正常选中文字 */
                var dragGroupId = 0;

                groupList.addEventListener('dragstart', function (event) {
                    var item = event.target.closest ? event.target.closest('.my-group-item') : null;
                    if (!item) { return; }

                    dragGroupId = parseInt(item.getAttribute('data-id'), 10) || 0;
                    item.classList.add('is-dragging');

                    if (event.dataTransfer) {
                        event.dataTransfer.effectAllowed = 'move';
                        event.dataTransfer.setData('text/plain', String(dragGroupId));
                    }
                });

                groupList.addEventListener('dragover', function (event) {
                    event.preventDefault();
                    var item = event.target.closest ? event.target.closest('.my-group-item') : null;
                    qsa('.my-group-item').forEach(function (node) { node.classList.remove('is-drop-target'); });
                    if (item) { item.classList.add('is-drop-target'); }
                });

                groupList.addEventListener('drop', function (event) {
                    event.preventDefault();
                    var item = event.target.closest ? event.target.closest('.my-group-item') : null;
                    qsa('.my-group-item').forEach(function (node) { node.classList.remove('is-drop-target', 'is-dragging'); });

                    if (!item || !dragGroupId) { return; }

                    var targetId = parseInt(item.getAttribute('data-id'), 10) || 0;
                    var to = -1;
                    OWN_GROUPS.forEach(function (group, idx) { if (group.id === targetId) { to = idx; } });
                    moveGroupTo(dragGroupId, to);
                    dragGroupId = 0;
                });

                groupList.addEventListener('dragend', function () {
                    qsa('.my-group-item').forEach(function (node) { node.classList.remove('is-drop-target', 'is-dragging'); });
                    dragGroupId = 0;
                });

                groupList.addEventListener('change', function (event) {
                    var item = event.target.closest ? event.target.closest('.my-group-item') : null;
                    var id = item ? parseInt(item.getAttribute('data-id'), 10) : 0;

                    // 名称改完失焦 / 回车即自动保存，不再需要单独的「保存」按钮
                    if (event.target.classList.contains('my-group-name')) {
                        renameGroup(id, event.target);
                        return;
                    }

                    if (!event.target.classList.contains('my-group-private') || !id) { return; }

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

                        OWN_GROUPS.forEach(function (group) { if (group.id === id) { group.is_private = event.target.checked ? 1 : 0; } });
                        syncGroupUI();
                        showMsg(groupMsg, event.target.checked ? '已设为私密分组' : '已取消私密', 'success');
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
            var longPressFired = false;
            var longPressUntil = 0;

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

            /* ---------- 撤销机制 ---------- */
            var undoStack = [];
            var UNDO_MAX = 20;

            function pushUndo(entry) {
                undoStack.push(entry);
                if (undoStack.length > UNDO_MAX) { undoStack.shift(); }
            }

            function popUndo() {
                return undoStack.pop();
            }

            function showToast(text, undoCallback) {
                var toast = document.createElement('div');
                toast.className = 'my-toast';
                var textSpan = document.createElement('span');
                textSpan.textContent = text;
                toast.appendChild(textSpan);

                if (typeof undoCallback === 'function') {
                    var undoBtn = document.createElement('button');
                    undoBtn.type = 'button';
                    undoBtn.className = 'my-toast-undo';
                    undoBtn.textContent = '撤销';
                    undoBtn.addEventListener('click', function () {
                        undoCallback();
                        toast.remove();
                    });
                    toast.appendChild(undoBtn);
                }

                document.body.appendChild(toast);
                requestAnimationFrame(function () { toast.classList.add('show'); });

                var timer = setTimeout(function () {
                    toast.classList.remove('show');
                    setTimeout(function () { toast.remove(); }, 200);
                }, 5000);

                toast._timer = timer;
                return toast;
            }

            // Ctrl+Z / Cmd+Z 撤销
            document.addEventListener('keydown', function (event) {
                var isUndo = (event.ctrlKey || event.metaKey) && event.key.toLowerCase() === 'z' && !event.shiftKey;
                if (!isUndo) { return; }
                var input = event.target;
                if (input && (input.tagName === 'INPUT' || input.tagName === 'TEXTAREA')) { return; }
                event.preventDefault();
                var entry = popUndo();
                if (entry && typeof entry.undo === 'function') {
                    entry.undo();
                }
            });

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
                syncCtxStar(item);
            }

            /* v2.18.6：右键/长按菜单的收藏项与行内星标共用 data-starred。
               菜单挂在 body 末尾、不在 .my-item 内，需单独同步文案、title、aria-label。 */
            function syncCtxStar(item) {
                var starItem = qs('#ctxMenuStar');
                if (!starItem) { return; }
                var label = getItemData(item).starred ? '取消收藏' : '收藏';
                var text = starItem.querySelector('span');
                if (text) { text.textContent = label; }
                starItem.setAttribute('title', label);
                starItem.setAttribute('aria-label', label);
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
                syncCtxStar(item);

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

            /* ---------- 长按 / 短按（v2.11.0 重写） ----------
               触屏上不再有常驻按钮：长按 ~500ms 弹操作菜单，短按打开网址，
               滚动不触发。短按改由 touchend 直接处理，不再依赖浏览器补发的
               合成 click，也不用媒体查询猜设备，避免「长按后残留标记吞掉
               后续点击」以及桌面端点击名称误开新标签。 */
            var touchRow = null;
            var touchStartX = 0;
            var touchStartY = 0;
            var tapHandledRow = null;
            var tapHandledUntil = 0;

            function isInteractiveTarget(node) {
                return !!(node && node.closest && node.closest('a, button, input, select, textarea, label'));
            }

            function openRowUrl(item) {
                var url = item.getAttribute('data-url');

                if (url) { window.open(url, '_blank', 'noopener,noreferrer'); }
            }

            function showRowMenu(item, x, y) {
                if (!contextMenu) { return; }

                syncCtxStar(item);
                contextMenu.hidden = false;
                var menuRect = contextMenu.getBoundingClientRect();

                if (x + menuRect.width > window.innerWidth) { x = window.innerWidth - menuRect.width - 8; }
                if (y + menuRect.height > window.innerHeight) { y = window.innerHeight - menuRect.height - 8; }

                contextMenu.style.left = Math.max(8, x) + 'px';
                contextMenu.style.top = Math.max(8, y) + 'px';
            }

            qsa('.my-item').forEach(function (item) {
                item.addEventListener('touchstart', function (event) {
                    longPressFired = false;

                    /* 点的是行内按钮/复选框/链接时交给它们自己处理，不启动长按 */
                    if (isInteractiveTarget(event.target)) {
                        touchRow = null;
                        return;
                    }

                    touchRow = item;
                    var t = event.touches && event.touches[0];
                    touchStartX = t ? t.clientX : 0;
                    touchStartY = t ? t.clientY : 0;

                    longPressTimer = setTimeout(function () {
                        longPressTimer = null;
                        longPressFired = true;
                        longPressUntil = Date.now() + 800;
                        touchRow = null;              // 已成长按，不再当短按处理
                        contextTarget = item;

                        var rect = item.getBoundingClientRect();
                        showRowMenu(item, rect.left + rect.width / 2, rect.top + rect.height / 2);

                        if (navigator.vibrate) { navigator.vibrate(12); }
                    }, 500);
                }, { passive: true });

                item.addEventListener('touchmove', function (event) {
                    var t = event.touches && event.touches[0];

                    if (t && (Math.abs(t.clientX - touchStartX) > 10 || Math.abs(t.clientY - touchStartY) > 10)) {
                        if (longPressTimer) { clearTimeout(longPressTimer); longPressTimer = null; }
                        touchRow = null;              // 滚动浏览，不视为短按
                    }
                }, { passive: true });

                item.addEventListener('touchend', function () {
                    if (longPressTimer) { clearTimeout(longPressTimer); longPressTimer = null; }

                    if (touchRow === item) {
                        /* 短按：立刻打开网址，并记下这一行，
                           让随后补发的 click 不再重复打开一个标签。 */
                        tapHandledRow = item;
                        tapHandledUntil = Date.now() + 700;
                        openRowUrl(item);
                    }

                    touchRow = null;
                });

                item.addEventListener('touchcancel', function () {
                    if (longPressTimer) { clearTimeout(longPressTimer); longPressTimer = null; }
                    touchRow = null;
                });
            });

            // 捕获阶段处理两类需要拦掉的补发 click：
            // 1) 短按已由 touchend 打开过网址 → 吞掉同一行上的这次 click，避免开两个标签；
            // 2) 长按触发后 → 只吞「800ms 内、落在被长按行上」的那一次。
            // 菜单在 body 末尾，点菜单项必须放行；超时残留标记也不误吞正常点击。
            document.addEventListener('click', function (event) {
                var row = event.target.closest ? event.target.closest('.my-item') : null;

                if (tapHandledRow && Date.now() < tapHandledUntil && row === tapHandledRow) {
                    tapHandledRow = null;
                    event.preventDefault();
                    event.stopPropagation();
                    return;
                }

                if (!longPressFired) { return; }
                longPressFired = false;

                if (Date.now() > longPressUntil || !row) { return; }

                event.preventDefault();
                event.stopPropagation();
            }, true);

            // 短按兜底：只在无 hover 能力的触屏设备启用，覆盖没有 touch 事件的
            // 合成 click（辅助技术等）。桌面端有 hover 按钮，点击名称不应误开标签。
            var isTouchOnly = window.matchMedia && window.matchMedia('(hover: none)').matches;
            if (isTouchOnly) {
                document.addEventListener('click', function (event) {
                    if (event.defaultPrevented || Date.now() < tapHandledUntil) { return; }
                    var info = event.target.closest ? event.target.closest('.my-info') : null;
                    if (!info) { return; }
                    var row = info.closest('.my-item');
                    if (!row || isInteractiveTarget(event.target)) { return; }
                    openRowUrl(row);
                });
            }

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
                        case 'star':
                            toggleStar(target);
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
                            // 二次确认 + 可撤销（与列表删除按钮同一套 pushUndo 机制）
                            if (!window.confirm('确定删除「' + data.name + '」吗？删除后可在提示条中撤销。')) { break; }

                            var delSnap = {
                                id: data.id,
                                name: data.name,
                                url: data.url,
                                category: data.category,
                                description: target.getAttribute('data-description') || '',
                                logo: target.getAttribute('data-logo') || ''
                            };

                            postForm(API_SITES, { action: 'delete', id: delSnap.id }).then(function (result) {
                                if (!result.ok) { window.alert(result.error || '删除失败'); return; }

                                target.style.display = 'none';

                                pushUndo({
                                    type: 'delete',
                                    undo: function () {
                                        postForm(API_SITES, {
                                            action: 'add',
                                            url: delSnap.url,
                                            name: delSnap.name,
                                            category_id: delSnap.category,
                                            description: delSnap.description,
                                            logo: delSnap.logo
                                        }).then(function (res) {
                                            if (res && res.ok) {
                                                target.style.display = '';
                                                target.setAttribute('data-id', res.id);
                                                showToast('已恢复');
                                            } else {
                                                showToast('恢复失败');
                                            }
                                        });
                                    }
                                });

                                showToast('已删除「' + delSnap.name + '」', function () {
                                    var entry = popUndo();
                                    if (entry && typeof entry.undo === 'function') { entry.undo(); }
                                });
                            });
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

            /* v2.18.6 跨页统一：皮肤 / 用户下拉 / 徽标 / Esc 焦点 */
            var vMenu=qs('#userMenu'),vSkin=qs('#skinPicker'),vSkinBtn=qs('#skinToggle');
            function closeVMenu(f){
                if(!vMenu||!vMenu.hasAttribute('open'))return;
                vMenu.removeAttribute('open');
                if(f)vMenu.querySelector('summary').focus();
            }
            function applyV(k){
                if(['default','purple','orange','mint'].indexOf(k)<0)k='default';
                document.documentElement.setAttribute('data-skin',k);
                qsa('.skin-option').forEach(function(o){
                    var on=o.getAttribute('data-skin')===k;
                    o.classList.toggle('is-active',on);
                    o.setAttribute('aria-checked',on?'true':'false');
                });
            }
            if(vSkin&&vSkinBtn){
                var sk='default';
                try{sk=localStorage.getItem('xy-nav-skin')||'default';}catch(e){}
                applyV(sk);
                vSkinBtn.addEventListener('click',function(e){
                    e.stopPropagation();
                    vSkinBtn.setAttribute('aria-expanded',vSkin.classList.toggle('is-open')?'true':'false');
                });
                qsa('.skin-option').forEach(function(o){
                    o.addEventListener('click',function(){
                        var k=o.getAttribute('data-skin');
                        applyV(k);
                        try{localStorage.setItem('xy-nav-skin',k);}catch(e){}
                        vSkin.classList.remove('is-open');
                        vSkinBtn.setAttribute('aria-expanded','false');
                    });
                });
            }
            function readMode(){try{return localStorage.getItem('nav_open_mode')||'default';}catch(e){return 'default';}}
            function badgeV(){
                var b=qs('#openModeBadgeMy');
                if(b){var m=readMode();b.textContent=m==='self'?'当前页':(m==='blank'?'新标签':'默认');}
            }
            badgeV();
            var modeV=qs('#openModeToggleMy');
            if(modeV)modeV.addEventListener('click',function(){
                var m=readMode(),n=m==='default'?'self':(m==='self'?'blank':'default');
                try{localStorage.setItem('nav_open_mode',n);}catch(e){}
                badgeV();
            });
            var qk=qs('#quickAddTriggerMy');
            if(qk)qk.addEventListener('click',function(){
                closeVMenu(false);
                var q=qs('#quickAddBtn');
                if(q)q.click();
            });
            document.addEventListener('click',function(e){
                if(vMenu&&vMenu.hasAttribute('open')&&!vMenu.contains(e.target))vMenu.removeAttribute('open');
                if(vSkin&&vSkin.classList.contains('is-open')&&!vSkin.contains(e.target)){
                    vSkin.classList.remove('is-open');
                    vSkinBtn.setAttribute('aria-expanded','false');
                }
            });
            document.addEventListener('keydown',function(e){
                if(e.key!=='Escape')return;
                closeVMenu(true);
                if(vSkin&&vSkin.classList.contains('is-open')){
                    vSkin.classList.remove('is-open');
                    vSkinBtn.setAttribute('aria-expanded','false');
                    vSkinBtn.focus();
                }
                if(contextMenu&&!contextMenu.hidden){
                    contextMenu.hidden=true;
                    if(contextTarget&&typeof contextTarget.focus==='function'){
                        if(!contextTarget.hasAttribute('tabindex'))contextTarget.setAttribute('tabindex','-1');
                        contextTarget.focus({preventScroll:true});
                    }
                }
            });

            // 快捷收藏弹窗
            var quickAddBtn = qs('#quickAddBtn');

            if (quickAddBtn) {
                quickAddBtn.addEventListener('click', function () {
                    if (quickAddUrl) { quickAddUrl.value = ''; }
                    if (quickAddPreview) { quickAddPreview.hidden = true; }
                    if (quickAddMsg) { showMsg(quickAddMsg, '', ''); }

                    // 每次打开都清空图标，避免沿用上一轮的临时上传
                    if (quickIcon) { quickIcon.reset('', ''); }

                    if (quickTags) { quickTags.reset(); }

                    openDialog2(quickAddDialog);
                    setTimeout(function () { if (quickAddUrl) { quickAddUrl.focus(); } }, 50);
                });
            }

            // Esc / 关闭按钮关掉时，清掉还没保存的临时图标文件
            if (quickAddDialog) {
                quickAddDialog.addEventListener('close', function () {
                    if (quickIcon) { quickIcon.discardPending(); }
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
                    category_id: quickAddCategory ? quickAddCategory.value : 0,
                    // v2.16.3：用户手动指定的图标（上传 / 外链）；没指定就留空由后端抓取
                    logo: quickIcon ? quickIcon.value() : '',
                    // v2.16.5：AI 生成或手填的标签
                    tags: quickTags ? quickTags.value() : ''
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
                var qaBatchBtn = qs('#quickAddBatchBtn');

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

                    // 换了网址，占位字母跟着变，预览框不至于一直挂着上一个站点的首字母
                    quickAddUrl.addEventListener('input', function () {
                        if (quickIcon) {
                            quickIcon.setSiteUrl(quickAddUrl.value.trim());

                            if (!quickIcon.value()) {
                                quickIcon.reset('', quickAddUrl.value.trim());
                            }
                        }
                    });
                }

            }

            // 批量导入的入口：工具栏按钮与收藏弹窗里的「批量粘贴」共用。
            // 放在块外定义，下面的弹窗逻辑才能调用（严格模式下块级函数不跨块可见）。
            var batchPasteDialog = qs('#batchPasteDialog');

            function bpResetStage() {
                var step1 = qs('#batchPasteStep1');
                var step2 = qs('#batchPasteStep2');
                var list = qs('#bpList');
                var back = qs('#batchPasteBack');
                var submit = qs('#batchPasteSubmit');

                // bpStage / bpItems 在下面的弹窗逻辑里声明（var 提升到本函数作用域）
                bpStage = 1;
                bpItems = [];

                if (step1) { step1.hidden = false; }
                if (step2) { step2.hidden = true; }
                if (list) { list.textContent = ''; }
                if (back) { back.hidden = true; }
                if (submit) { submit.textContent = '识别网址'; submit.disabled = false; }
            }

            function bpOpenDialog() {
                var text = qs('#batchPasteText');
                var msg = qs('#batchPasteMsg');

                if (text) { text.value = ''; }
                if (msg) { showMsg(msg, ''); }
                bpResetStage();
                openDialog2(batchPasteDialog);
                setTimeout(function () { if (text) { text.focus(); } }, 50);
            }

            if (qaBatchBtn && batchPasteDialog) {
                qaBatchBtn.addEventListener('click', function () {
                    closeDialog2(quickAddDialog);
                    bpOpenDialog();
                });
            }

            var toolbarBatchBtn = qs('#batchImportBtn');

            if (toolbarBatchBtn && batchPasteDialog) {
                toolbarBatchBtn.addEventListener('click', function () {
                    bpOpenDialog();
                });
            }

            // 批量粘贴导入弹窗
            if (batchPasteDialog) {
                var bpClose = qs('#batchPasteClose');
                var bpCancel = qs('#batchPasteCancel');
                var bpBack = qs('#batchPasteBack');
                var bpSubmit = qs('#batchPasteSubmit');
                var bpText = qs('#batchPasteText');
                var bpMsg = qs('#batchPasteMsg');
                var bpCategory = qs('#batchPasteCategory');
                var bpStep1 = qs('#batchPasteStep1');
                var bpStep2 = qs('#batchPasteStep2');
                var bpList = qs('#bpList');
                var bpCount = qs('#bpCount');
                var bpToggleAll = qs('#bpToggleAll');

                var bpItems = [];        // 识别结果，用户校对的就是这份数据
                var bpCategories = [];   // 候选分组
                var bpStage = 1;         // 1=粘贴  2=校对

                function bpDefaultCategory() {
                    return bpCategory ? (parseInt(bpCategory.value, 10) || 0) : 0;
                }

                function bpSelected() {
                    return bpItems.filter(function (item) { return item.selected; });
                }

                function bpUpdateCount() {
                    if (!bpCount) { return; }

                    var picked = bpSelected().length;
                    var dup = bpItems.filter(function (item) { return item.duplicate; }).length;
                    var parts = ['已选 ' + picked + ' / ' + bpItems.length + ' 条'];

                    if (dup) { parts.push(dup + ' 条已在导航中'); }

                    bpCount.textContent = parts.join('，');

                    if (bpSubmit) {
                        bpSubmit.textContent = picked ? ('导入 ' + picked + ' 条') : '请选择要导入的网址';
                        bpSubmit.disabled = picked === 0;
                    }

                    if (bpToggleAll) {
                        var selectable = bpItems.filter(function (item) { return !item.duplicate; });
                        bpToggleAll.checked = selectable.length > 0
                            && selectable.every(function (item) { return item.selected; });
                        bpToggleAll.disabled = selectable.length === 0;
                    }
                }

                function bpRender() {
                    if (!bpList) { return; }

                    bpList.textContent = '';
                    var allowed = {};
                    bpCategories.forEach(function (cat) { allowed[cat.id] = true; });
                    var defaultId = bpDefaultCategory();

                    bpItems.forEach(function (item) {
                        var row = document.createElement('div');
                        row.className = 'bp-item'
                            + (item.duplicate ? ' is-dup' : '')
                            + (item.selected ? '' : ' is-off');

                        // 勾选
                        var check = document.createElement('input');
                        check.type = 'checkbox';
                        check.checked = item.selected;
                        check.disabled = item.duplicate;
                        check.setAttribute('aria-label', '是否导入 ' + item.url);
                        check.addEventListener('change', function () {
                            item.selected = check.checked;
                            row.classList.toggle('is-off', !check.checked);
                            bpUpdateCount();
                        });
                        row.appendChild(check);

                        // 图标：抓不到就用域名首字母占位
                        var logo = document.createElement('div');
                        logo.className = 'bp-logo';
                        var initial = (item.host || '?').charAt(0).toUpperCase();

                        if (item.logo) {
                            var img = document.createElement('img');
                            img.src = item.logo;
                            img.alt = '';
                            img.loading = 'lazy';
                            img.addEventListener('error', function () {
                                if (img.parentNode) { img.parentNode.removeChild(img); }
                                logo.textContent = initial;
                            });
                            logo.appendChild(img);
                        } else {
                            logo.textContent = initial;
                        }

                        row.appendChild(logo);

                        // 名称 + 网址 + 状态提示
                        var main = document.createElement('div');
                        main.className = 'bp-main';

                        var nameInput = document.createElement('input');
                        nameInput.type = 'text';
                        nameInput.value = item.name || '';
                        nameInput.disabled = item.duplicate;
                        nameInput.setAttribute('aria-label', '网站名称');

                        if (!item.fetch_ok && !item.duplicate) {
                            nameInput.classList.add('is-weak');
                        }

                        nameInput.addEventListener('input', function () {
                            item.name = nameInput.value;
                        });
                        main.appendChild(nameInput);

                        var urlSpan = document.createElement('span');
                        urlSpan.className = 'bp-url';
                        urlSpan.textContent = item.url;
                        urlSpan.title = item.url;
                        main.appendChild(urlSpan);

                        if (item.note) {
                            var note = document.createElement('span');
                            note.className = 'bp-note';
                            note.textContent = item.note;
                            main.appendChild(note);
                        }

                        row.appendChild(main);

                        // 分组：推荐命中就用推荐值，没命中就退回阶段一选的默认分组
                        var select = document.createElement('select');
                        select.disabled = item.duplicate;
                        select.setAttribute('aria-label', '保存到分组');

                        bpCategories.forEach(function (cat) {
                            var option = document.createElement('option');
                            option.value = String(cat.id);
                            option.textContent = (cat.is_private ? '🔒 ' : '') + cat.name;
                            select.appendChild(option);
                        });

                        var wantId = item.suggest_reason === 'keyword'
                            ? item.suggested_category_id
                            : (defaultId || item.suggested_category_id);

                        if (wantId && !allowed[wantId]) { wantId = 0; }
                        if (!wantId && bpCategories.length) { wantId = bpCategories[0].id; }

                        select.value = String(wantId || 0);
                        item.category_id = parseInt(select.value, 10) || 0;

                        select.addEventListener('change', function () {
                            item.category_id = parseInt(select.value, 10) || 0;
                        });

                        row.appendChild(select);
                        bpList.appendChild(row);
                    });
                }

                if (bpClose) { bpClose.addEventListener('click', function () { bpResetStage(); closeDialog2(batchPasteDialog); }); }
                if (bpCancel) { bpCancel.addEventListener('click', function () { bpResetStage(); closeDialog2(batchPasteDialog); }); }

                if (bpBack) {
                    bpBack.addEventListener('click', function () {
                        bpResetStage();
                        showMsg(bpMsg, '');
                    });
                }

                if (bpToggleAll) {
                    bpToggleAll.addEventListener('change', function () {
                        bpItems.forEach(function (item) {
                            if (!item.duplicate) { item.selected = bpToggleAll.checked; }
                        });
                        bpRender();
                        bpUpdateCount();
                    });
                }

                if (bpSubmit) {
                    bpSubmit.addEventListener('click', function () {
                        // 阶段一：识别
                        if (bpStage === 1) {
                            var text = bpText ? bpText.value.trim() : '';

                            if (!text) { showMsg(bpMsg, '请粘贴网址'); return; }

                            setBusy(bpSubmit, true, '识别中…');
                            showMsg(bpMsg, '');

                            postForm(API_SITES, { action: 'batch_preview', urls: text })
                                .then(function (res) {
                                    setBusy(bpSubmit, false);

                                    if (!res || !res.ok) {
                                        showMsg(bpMsg, (res && res.error) || '识别失败');
                                        return;
                                    }

                                    bpItems = (res.items || []).map(function (it) {
                                        return {
                                            url: it.url,
                                            host: it.host || '',
                                            name: it.name || '',
                                            description: it.description || '',
                                            logo: it.logo || '',
                                            duplicate: !!it.duplicate,
                                            fetch_ok: !!it.fetch_ok,
                                            note: it.note || '',
                                            suggest_reason: it.suggest_reason || '',
                                            suggested_category_id: it.suggested_category_id || 0,
                                            selected: !it.duplicate,
                                            category_id: 0,
                                        };
                                    });

                                    bpCategories = res.categories || [];

                                    if (!bpItems.length) {
                                        showMsg(bpMsg, '没识别出可导入的网址');
                                        return;
                                    }

                                    bpStage = 2;
                                    if (bpStep1) { bpStep1.hidden = true; }
                                    if (bpStep2) { bpStep2.hidden = false; }
                                    if (bpBack) { bpBack.hidden = false; }

                                    bpRender();
                                    bpUpdateCount();

                                    if (res.truncated) {
                                        showMsg(bpMsg, '网址超过 ' + res.limit + ' 条，本次只识别前 ' + res.limit + ' 条。', 'warn');
                                    }
                                })
                                .catch(function () {
                                    setBusy(bpSubmit, false);
                                    showMsg(bpMsg, '网络错误，请稍后重试');
                                });

                            return;
                        }

                        // 阶段二：按校对结果落库
                        var picked = bpSelected();

                        if (!picked.length) { showMsg(bpMsg, '请至少选择一条'); return; }

                        var payload = picked.map(function (item) {
                            return {
                                url: item.url,
                                name: item.name,
                                description: item.description,
                                logo: item.logo,
                                category_id: item.category_id,
                            };
                        });

                        setBusy(bpSubmit, true, '导入中…');

                        postForm(API_SITES, {
                            action: 'batch_add',
                            items: JSON.stringify(payload),
                            category_id: String(bpDefaultCategory())
                        }).then(function (res) {
                            setBusy(bpSubmit, false);

                            if (res && res.ok) {
                                showMsg(bpMsg, '导入完成：新增 ' + res.imported + ' 条，跳过重复 ' + res.skipped + ' 条。', 'success');
                                setTimeout(function () { bpResetStage(); closeDialog2(batchPasteDialog); window.location.reload(); }, 1200);
                            } else {
                                showMsg(bpMsg, (res && res.error) || '导入失败');
                            }
                        }).catch(function () {
                            setBusy(bpSubmit, false);
                            showMsg(bpMsg, '网络错误，请稍后重试');
                        });
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

            /* ------------------- v2.12.0 统一的「新建分组」弹窗入口 ------------------- */
            document.addEventListener('click', function (event) {
                var target = event.target;
                var btn = target && target.closest ? target.closest('.js-category-create') : null;

                if (!btn || !window.XYCategoryQuickAdd) {
                    return;
                }

                XYCategoryQuickAdd.open({
                    mode: 'front',
                    term: '分组',
                    title: '新建分组',
                    apiUrl: API_CATEGORY_CREATE,
                    onCreated: function (item) {
                        var created = normalizeGroup(item);

                        if (!item.reused) {
                            OWN_GROUPS.push(created);
                        }

                        syncGroupUI({ highlightId: created.id });
                    }
                });
            });
        })();
    </script>
    <script src="assets/js/pinyin-pro.min.js" defer></script>
<script src="assets/js/search.js?v=2.18.6" defer></script>
</body>
</html>
