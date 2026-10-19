<?php
/**
 * 轩宇-导航 · 站点配置
 * 远程更新 / API 配置已移除。
 */

if (basename((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) === basename(__FILE__)) {
    http_response_code(403);
    exit;
}

return [
    'system' => [
        'name' => '轩宇-导航',
        'version' => '2.0.0',
        'debug' => false,
        'maintenance' => false,
        'timezone' => 'Asia/Shanghai',
        'language' => 'zh-CN',
    ],
    'paths' => [
        'uploads' => 'uploads/',
        'logos' => 'uploads/logos/',
        'cache' => 'cache/',
        'logs' => 'logs/',
        'temp' => 'temp/',
        'backup' => 'backups/',
    ],
    'security' => [
        'max_upload_size' => 5242880,
        'allowed_file_types' => ['jpg', 'jpeg', 'png', 'gif', 'webp', 'ico'],
        'session_lifetime' => 7200,
        'csrf_protection' => true,
        'xss_protection' => true,
        'sql_injection_protection' => true,
    ],
    'cache' => [
        'enable_cache' => true,
    ],
];
