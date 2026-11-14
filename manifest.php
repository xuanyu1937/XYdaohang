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

$logoUrl = $functions->getLogoUrl($functions->getSetting('site_logo'));
$logoIsRaster = $logoUrl !== '' && preg_match('/\.(png|jpe?g|gif|webp)(\?|$)/i', $logoUrl) === 1;

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

if ($logoIsRaster) {
    // 后台上传了位图 logo 时，把它排在最前作为应用图标来源
    array_unshift($icons, [
        'src' => $logoUrl,
        'sizes' => '192x192 512x512',
        'type' => 'image/png',
        'purpose' => 'any',
    ]);
}

$manifest = [
    'name' => $name,
    'short_name' => mb_substr($name, 0, 12),
    'description' => $description,
    'lang' => 'zh-CN',
    'start_url' => iconUrl($base, 'index.php?utm_source=pwa') . '&pwa=1',
    'scope' => $base . '/',
    'display' => 'standalone',
    'orientation' => 'portrait-primary',
    'background_color' => '#f4f6fa',
    'theme_color' => '#3b82f6',
    'icons' => $icons,
];

echo json_encode($manifest, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
