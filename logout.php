<?php
/**
 * 轩宇-导航 · 前台用户退出
 * 仅销毁前台用户会话，不影响后台管理员登录状态。
 */
require_once __DIR__ . '/includes/bootstrap.php';
require_once 'includes/functions.php';
require_once 'includes/Services.php';

$functions = new Functions();

// 正常点击链接时 Sec-Fetch-Site 为 same-origin，可放行；跨站发起的退出会被拦截。
$functions->requireSameOrigin();

$users = new UserService($functions);
$users->logout();

$functions->redirect('index.php');
