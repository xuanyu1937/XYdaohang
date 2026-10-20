<?php
/**
 * 轩宇-导航 · 全局搜索索引（v2.5.0）
 * 输出当前视图可见的站点与分类 JSON，供非首页搜索框联想使用。
 *
 * 可见性与 index.php 完全一致：
 *   - 登录用户 → 自己的全部数据（含私密分组）
 *   - 访客     → 默认访客视图，且排除私密分组下的链接
 *
 * 老库缺少 alias/tags 列时自动降级为不输出这两个字段，不报错。
 */

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/Services.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, max-age=0');
header('X-Content-Type-Options: nosniff');
header('X-Robots-Tag: noindex, nofollow');
@ini_set('display_errors', '0');

$functions = new Functions();
$users = new UserService($functions);
$engine = new CategoryEngine($functions);

$currentUser = $users->currentUser();
$viewUserId = $currentUser ? (int) $currentUser['id'] : $users->defaultViewUserId();
$isOwner = $currentUser && (int) $currentUser['id'] === $viewUserId;
$engine->setUserId($viewUserId);

$db = Database::getInstance()->getConnection();

$extraColumns = ', nl.alias, nl.tags';
$hasExtra = false;

try {
    $check = $db->query(
        "SELECT COUNT(*) AS total FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'nav_links' AND COLUMN_NAME = 'tags'"
    );
    $hasExtra = $check ? (int) ($check->fetch()['total'] ?? 0) > 0 : false;
} catch (Throwable $error) {
    $hasExtra = false;
}

if (!$hasExtra) {
    $extraColumns = '';
}

$sites = [];
$categories = [];
$categoryNames = [];

try {
    foreach ($engine->all() as $category) {
        $categoryId = (int) $category['id'];
        $categoryNames[$categoryId] = (string) $category['name'];

        $categories[] = [
            'id' => $categoryId,
            'name' => (string) $category['name'],
            'parent' => (int) ($category['parent_id'] ?? 0),
        ];
    }

    if ($isOwner) {
        $stmt = $db->prepare(
            "SELECT nl.id, nl.name, nl.url, nl.logo, nl.description, nl.category_id, nl.clicks{$extraColumns}
             FROM nav_links nl
             WHERE nl.user_id = ? AND nl.is_active = 1 AND nl.deleted_at IS NULL
             ORDER BY COALESCE(nl.clicks, 0) DESC, nl.sort_order ASC, nl.id ASC"
        );
        $stmt->execute([$viewUserId]);
    } else {
        $stmt = $db->prepare(
            "SELECT nl.id, nl.name, nl.url, nl.logo, nl.description, nl.category_id, nl.clicks{$extraColumns}
             FROM nav_links nl
             LEFT JOIN categories c ON c.id = nl.category_id
             WHERE nl.user_id = ? AND nl.is_active = 1 AND nl.deleted_at IS NULL
               AND COALESCE(c.is_private, 0) = 0
             ORDER BY COALESCE(nl.clicks, 0) DESC, nl.sort_order ASC, nl.id ASC"
        );
        $stmt->execute([$viewUserId]);
    }

    foreach ($stmt->fetchAll() as $row) {
        $rawUrl = trim((string) $row['url']);

        if ($rawUrl === '' || !preg_match('#^https?://#i', $rawUrl)) {
            continue;
        }

        $parts = parse_url($rawUrl);

        if (!$parts || empty($parts['host'])) {
            continue;
        }

        $host = $parts['host'];
        $name = trim((string) $row['name']);
        $displayName = $name !== '' ? $name : $host;

        $item = [
            'id' => (int) $row['id'],
            'name' => $displayName,
            'url' => $rawUrl,
            'host' => $host,
            'domain' => preg_replace('/^www\./i', '', $host),
            'logo' => $functions->getLogoDisplayUrl((string) ($row['logo'] ?? '')),
            'desc' => trim((string) ($row['description'] ?? '')),
            'cat' => (int) $row['category_id'],
            'catName' => $categoryNames[(int) $row['category_id']] ?? '',
            'clicks' => max(0, (int) ($row['clicks'] ?? 0)),
        ];

        if ($hasExtra) {
            $item['alias'] = trim((string) ($row['alias'] ?? ''));
            $item['tags'] = trim((string) ($row['tags'] ?? ''));
        }

        $sites[] = $item;
    }
} catch (Throwable $error) {
    // 查询失败（老库缺列等）时输出空索引，前端静默降级
    $sites = [];
    $categories = [];
}

$functions->jsonResponse([
    'ok' => true,
    'sites' => $sites,
    'categories' => $categories,
]);
