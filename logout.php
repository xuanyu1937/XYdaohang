<?php
/**
 * 轩宇-导航 · 前台用户退出
 * 默认仅销毁前台用户会话；若后台身份是由前台管理员会话打通而来，则一并清除。
 */
require_once __DIR__ . '/includes/bootstrap.php';
require_once 'includes/functions.php';
require_once 'includes/Services.php';

$functions = new Functions();

// 正常点击链接时 Sec-Fetch-Site 为 same-origin，可放行；跨站发起的退出会被拦截。
$functions->requireSameOrigin();

$users = new UserService($functions);

// v2.16.0 · 登录态打通后，前台会话可能已经把 admin_id 写进了同一个 session
// （管理员在前台登录 → 进后台时由 Functions::isLoggedIn() 补齐）。
// 这种情况下前台退出必须连后台身份一起清掉，否则「退出登录」后仍能直接打开后台。
$linkedAdminId = 0;

if (isset($_SESSION['admin_id'], $_SESSION['user_id'])
    && (int) $_SESSION['admin_id'] === (int) $_SESSION['user_id']
) {
    $linkedAdminId = (int) $_SESSION['admin_id'];
}

$users->logout();

if ($linkedAdminId > 0) {
    unset($_SESSION['admin_id'], $_SESSION['admin_username'], $_SESSION['last_activity']);
}

$functions->redirect('index.php');
