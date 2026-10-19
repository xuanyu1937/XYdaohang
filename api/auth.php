<?php
/**
 * 前台用户认证接口
 *
 * GET  ?action=me                      当前登录用户信息
 * POST  action=register                注册（register_enabled 关闭时拒绝）
 * POST  action=login                   登录（支持邮箱或用户名）
 * POST  action=logout                  退出
 * POST  action=change_password         修改密码（需登录）
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

$method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
$action = strtolower(trim((string) ($_POST['action'] ?? $_GET['action'] ?? '')));

if ($method === 'GET') {
    if ($action === '' || $action === 'me') {
        $user = $users->currentUser();

        $functions->jsonResponse([
            'ok' => true,
            'logged_in' => $user !== null,
            'user' => $user ? [
                'id' => (int) $user['id'],
                'username' => (string) $user['username'],
                'email' => (string) $user['email'],
                'is_admin' => (int) $user['is_admin'] === 1,
            ] : null,
            'register_enabled' => $functions->getSetting('register_enabled') !== '0',
        ]);
    }

    $functions->jsonResponse(['ok' => false, 'error' => 'unsupported_action'], 400);
}

if ($method !== 'POST') {
    $functions->jsonResponse(['ok' => false, 'error' => 'method_not_allowed'], 405);
}

$functions->requireSameOrigin();

switch ($action) {
    case 'register':
        // 注册频率限制：同一会话 60 秒内最多 3 次尝试。
        $recent = (int) ($_SESSION['register_attempts'] ?? 0);
        $lastAt = (int) ($_SESSION['register_last_at'] ?? 0);

        if ($recent >= 3 && (time() - $lastAt) < 60) {
            $functions->jsonResponse(['ok' => false, 'error' => '请求过于频繁，请稍后再试'], 429);
        }

        $_SESSION['register_attempts'] = $recent + 1;
        $_SESSION['register_last_at'] = time();

        $result = $users->register(
            (string) ($_POST['email'] ?? ''),
            (string) ($_POST['username'] ?? ''),
            (string) ($_POST['password'] ?? ''),
            isset($_POST['password_confirm']) ? (string) $_POST['password_confirm'] : null
        );

        if (!$result['ok']) {
            $functions->jsonResponse(['ok' => false, 'error' => $result['error']], 400);
        }

        unset($_SESSION['register_attempts'], $_SESSION['register_last_at']);
        $users->startSession($result['user']);

        $functions->jsonResponse([
            'ok' => true,
            'redirect' => 'my.php',
            'user' => [
                'id' => (int) $result['user']['id'],
                'username' => (string) $result['user']['username'],
            ],
        ]);
        break;

    case 'login':
        $attempts = (int) ($_SESSION['user_login_attempts'] ?? 0);
        $lastAttempt = (int) ($_SESSION['user_login_last_attempt'] ?? 0);

        if ($attempts >= 5 && (time() - $lastAttempt) < 300) {
            $functions->jsonResponse(['ok' => false, 'error' => '登录尝试过于频繁，请 5 分钟后再试'], 429);
        }

        $result = $users->login(
            (string) ($_POST['account'] ?? ''),
            (string) ($_POST['password'] ?? '')
        );

        if (!$result['ok']) {
            $_SESSION['user_login_attempts'] = $attempts + 1;
            $_SESSION['user_login_last_attempt'] = time();
            $functions->jsonResponse(['ok' => false, 'error' => $result['error']], 401);
        }

        unset($_SESSION['user_login_attempts'], $_SESSION['user_login_last_attempt']);

        $functions->jsonResponse([
            'ok' => true,
            'redirect' => 'my.php',
            'user' => [
                'id' => (int) $result['user']['id'],
                'username' => (string) $result['user']['username'],
            ],
        ]);
        break;

    case 'logout':
        $users->logout();
        $functions->jsonResponse(['ok' => true]);
        break;

    case 'change_password':
        $user = $users->currentUser();

        if (!$user) {
            $functions->jsonResponse(['ok' => false, 'error' => '请先登录'], 401);
        }

        $old = (string) ($_POST['old_password'] ?? '');
        $new = (string) ($_POST['new_password'] ?? '');
        $confirm = (string) ($_POST['new_password_confirm'] ?? '');

        if ($confirm !== '' && !hash_equals($confirm, $new)) {
            $functions->jsonResponse(['ok' => false, 'error' => '两次输入的新密码不一致'], 400);
        }

        $result = $users->changePassword((int) $user['id'], $old, $new);

        if (!$result['ok']) {
            $functions->jsonResponse(['ok' => false, 'error' => $result['error']], 400);
        }

        $functions->jsonResponse(['ok' => true]);
        break;

    default:
        $functions->jsonResponse(['ok' => false, 'error' => 'unsupported_action'], 400);
}
