<?php
/**
 * 轩宇-导航 · 重置密码（通过邮件里的令牌链接进入）
 */
require_once __DIR__ . '/includes/bootstrap.php';
require_once 'includes/functions.php';
require_once 'includes/Services.php';

$functions = new Functions();
$users = new UserService($functions);

if ($users->isLoggedIn()) {
    $functions->redirect('my.php');
}

$brandName = trim((string) $functions->getSetting('site_title'));
$brandName = $brandName !== '' ? $brandName : '轩宇-导航';
$siteLogoUrl = $functions->getLogoUrl($functions->getSetting('site_logo'));
$frontendTheme = (string) $functions->getSetting('frontend_theme');

if (!in_array($frontendTheme, ['system', 'light', 'dark'], true)) {
    $frontendTheme = 'system';
}

$brandInitial = function_exists('mb_substr')
    ? mb_substr($brandName, 0, 1, 'UTF-8')
    : strtoupper(substr($brandName, 0, 1));

$token = trim((string) ($_GET['token'] ?? $_POST['token'] ?? ''));
$tokenUser = $token !== '' ? $users->findByResetToken($token) : null;

$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $functions->requireSameOrigin();

    $password = (string) ($_POST['password'] ?? '');
    $confirm = (string) ($_POST['password_confirm'] ?? '');

    if (!$tokenUser) {
        $error = '重置链接无效或已过期，请重新申请';
    } elseif (strlen($password) < 8 || strlen($password) > 72) {
        $error = '密码长度需为 8-72 位';
    } elseif ($password !== $confirm) {
        $error = '两次输入的密码不一致';
    } else {
        $result = $users->resetPasswordByToken($token, $password);

        if ($result['ok']) {
            $message = '密码已重置成功，请使用新密码登录。';
            $tokenUser = null; // 重置成功后不再显示表单
        } else {
            $error = $result['error'];
        }
    }
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex,nofollow">
    <title>重置密码 - <?php echo $functions->escape($brandName); ?></title>
    <script>
        (function () {
            try {
                var saved = localStorage.getItem('xy-nav-theme');
                var fallback = <?php echo json_encode($frontendTheme, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
                var theme = (saved === 'dark' || saved === 'light')
                    ? saved
                    : ((fallback === 'dark' || fallback === 'light')
                        ? fallback
                        : (window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light'));
                document.documentElement.setAttribute('data-theme', theme);
            } catch (e) {
                document.documentElement.setAttribute('data-theme', 'light');
            }
        })();
    </script>
    <link rel="stylesheet" href="assets/css/auth.css">
</head>
<body>
    <main class="auth-card">
        <div class="auth-top">
            <div class="auth-brand">
                <span class="auth-mark" aria-hidden="true"><?php echo $functions->escape($brandInitial); ?><?php if ($siteLogoUrl !== ''): ?><img src="<?php echo $functions->escape($siteLogoUrl); ?>" alt="" onerror="this.remove();"><?php endif; ?></span>
                <div>
                    <h1 class="auth-name"><?php echo $functions->escape($brandName); ?></h1>
                    <p class="auth-tagline">设置新密码</p>
                </div>
            </div>
        </div>

        <h2 class="auth-heading">重置密码</h2>

        <?php if ($error !== ''): ?>
            <div class="auth-alert" role="alert"><?php echo $functions->escape($error); ?></div>
        <?php endif; ?>
        <?php if ($message !== ''): ?>
            <div class="auth-alert" role="status" style="color: var(--success, #16a34a);"><?php echo $functions->escape($message); ?></div>
            <p class="auth-switch"><a href="login.php">前往登录</a></p>
        <?php elseif (!$tokenUser): ?>
            <div class="auth-alert" role="alert">重置链接无效或已过期，请重新申请。</div>
            <p class="auth-switch"><a href="forgot.php">重新申请重置</a></p>
        <?php else: ?>
            <p class="auth-intro">为账号 <strong><?php echo $functions->escape((string) $tokenUser['username']); ?></strong> 设置新密码。</p>

            <form method="POST" novalidate>
                <input type="hidden" name="token" value="<?php echo $functions->escape($token); ?>">

                <div class="auth-field">
                    <label for="password">新密码</label>
                    <input type="password"
                           id="password"
                           name="password"
                           autocomplete="new-password"
                           autofocus
                           placeholder="8-72 位">
                </div>

                <div class="auth-field">
                    <label for="password_confirm">确认新密码</label>
                    <input type="password"
                           id="password_confirm"
                           name="password_confirm"
                           autocomplete="new-password"
                           placeholder="再次输入新密码">
                </div>

                <button type="submit" class="auth-submit">保存新密码</button>
            </form>
        <?php endif; ?>

        <p class="auth-foot">
            <span class="auth-foot-links">
                <a href="login.php">返回登录</a>
                <span class="auth-foot-sep">·</span>
                <a href="index.php">返回首页</a>
            </span>
        </p>
    </main>
</body>
</html>
