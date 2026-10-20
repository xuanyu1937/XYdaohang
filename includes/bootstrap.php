<?php
/**
 * 轩宇-导航 · 公共启动文件
 * 统一处理版本、时区、Session 安全参数与基础安全响应头。
 * 只允许通过 require_once 引入。
 */

if (defined('NAV_BOOTSTRAPPED')) {
    return;
}

define('NAV_BOOTSTRAPPED', true);

error_reporting(E_ALL);
@ini_set('display_errors', '0');
@ini_set('log_errors', '1');

date_default_timezone_set('Asia/Shanghai');

$navVersionFile = __DIR__ . '/../config/version.php';
if (is_file($navVersionFile)) {
    require_once $navVersionFile;
}

if (!defined('APP_VERSION')) {
    define('APP_VERSION', '2.4.2');
}

if (!defined('APP_RELEASE')) {
    define('APP_RELEASE', date('Y-m-d'));
}

$navIsHttps = (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off')
    || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower((string) $_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https')
    || (isset($_SERVER['SERVER_PORT']) && (int) $_SERVER['SERVER_PORT'] === 443);

if (session_status() === PHP_SESSION_NONE) {
    if (PHP_VERSION_ID >= 70300) {
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => '/',
            'domain' => '',
            'secure' => $navIsHttps,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    } else {
        session_set_cookie_params(0, '/; samesite=Lax', '', $navIsHttps, true);
    }

    @ini_set('session.use_strict_mode', '1');
    @ini_set('session.use_only_cookies', '1');
    @ini_set('session.cookie_httponly', '1');

    session_start();
}

if (!headers_sent()) {
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: SAMEORIGIN');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Permissions-Policy: geolocation=(), microphone=(), camera=()');

    if ($navIsHttps) {
        header('Strict-Transport-Security: max-age=31536000');
    }
}
