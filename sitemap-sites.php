<?php
/**
 * 轩宇-导航 · 站点详情页的 XML sitemap（v2.18.3）
 *
 * 为什么单独一份：首页卡片直接跳外站，detail.php 在站内没有任何入口，
 * 只靠根目录那份静态 sitemap.xml 收录不到这几十上百个页面。
 * 这份由数据库实时生成，robots.txt 通过 sitemap 索引文件引用。
 *
 * 缓存 1 小时：内容变动对收录不敏感，但要避免每次爬虫都打库。
 */
require_once __DIR__ . '/includes/bootstrap.php';
require_once 'includes/functions.php';
require_once 'includes/Services.php';
require_once __DIR__ . '/includes/site_index_data.php';

$functions = new Functions();

header('Content-Type: application/xml; charset=utf-8');
header('Cache-Control: public, max-age=3600');

$etag = '"sitemap-sites-' . (defined('APP_VERSION') ? APP_VERSION : '0') . '"';

if (isset($_SERVER['HTTP_IF_NONE_MATCH']) && trim($_SERVER['HTTP_IF_NONE_MATCH']) === $etag) {
    http_response_code(304);
    exit;
}

header('ETag: ' . $etag);

$baseUrl = navSiteBaseUrl();
$rows = navPublicIndexLinks($functions);

echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";

foreach ($rows as $row) {
    $lastmod = trim((string) ($row['updated_at'] ?? ''));

    if ($lastmod !== '') {
        $lastmod = substr(str_replace(' ', 'T', $lastmod), 0, 10);
    }

    echo "  <url>\n";
    echo '    <loc>' . htmlspecialchars($baseUrl . '/detail.php?id=' . (int) $row['id'], ENT_XML1 | ENT_QUOTES, 'UTF-8') . "</loc>\n";

    if ($lastmod !== '') {
        echo '    <lastmod>' . $lastmod . "</lastmod>\n";
    }

    echo "    <changefreq>weekly</changefreq>\n";
    echo "    <priority>0.6</priority>\n";
    echo "  </url>\n";
}

echo "</urlset>\n";
