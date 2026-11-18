<?php
/**
 * PWA Web App Manifest（动态生成）
 * 站点名 / 描述 / Logo 跟随后台设置变化；图标使用静态生成的 PNG（assets/img/icons/）。
 */
require_once __DIR__ . '/includes/bootstrap.php';
require_once 'includes/functions.php';

header('Content-Type: application/manifest+json; charset=utf-8');

$functions = new Functions();

$siteTitle = trim((string) $functions->getSetting('site_title'));
$name = $siteTitle !== '' ? $siteTitle : '轩宇-导航';
$siteDescription = trim((string) $functions->getSetting('site_description'));
$description = $siteDescription !== '' ? $siteDescription : '一个简洁实用的导航网站';

/* v2.18.18 · 全站标识统一：移除把后台上传的白底横版 Logo 置顶为应用图标的逻辑。
   添加到桌面时改用 assets/img/icons 下的令牌蓝盾牌图标，与站内品牌块/favicon 同一图形语言。 */

$base = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? '/'), '/\\');
function iconUrl($base, $rel) {
    return $base . '/' . $rel;
}

$icons = [
    [
        'src' => iconUrl($base, 'assets/img/icons/icon-192.png'),
        'sizes' => '192x192',
        'type' => 'image/png',
        'purpose' => 'any',
    ],
    [
        'src' => iconUrl($base, 'assets/img/icons/icon-512.png'),
        'sizes' => '512x512',
        'type' => 'image/png',
        'purpose' => 'any',
    ],
    [
        'src' => iconUrl($base, 'assets/img/icons/maskable-512.png'),
        'sizes' => '512x512',
        'type' => 'image/png',
        'purpose' => 'maskable',
    ],
];

$manifest = [
    'name' => $name,
    'short_name' => mb_substr($name, 0, 12),
    'description' => $description,
    'lang' => 'zh-CN',
    'start_url' => iconUrl($base, 'index.php?utm_source=pwa') . '&pwa=1',
    'scope' => $base . '/',
    'display' => 'standalone',
    'orientation' => 'portrait-primary',
    'background_color' => '#ffffff',
    'theme_color' => '#ffffff',
    'icons' => $icons,
];

echo json_encode($manifest, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
