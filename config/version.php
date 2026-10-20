<?php
/**
 * 轩宇-导航 · 版本信息
 */

if (basename((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) === basename(__FILE__)) {
    http_response_code(403);
    exit;
}

define('APP_VERSION', '2.4.2');
define('APP_RELEASE', '2026-10-20');
define('APP_UPDATE_TIME', '2026-10-21 02:00:00');
