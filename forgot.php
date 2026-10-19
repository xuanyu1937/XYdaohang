<?php
/**
 * 轩宇-导航 · 忘记密码（通过邮箱发送重置链接）
 */
require_once __DIR__ . '/includes/bootstrap.php';
require_once 'includes/functions.php';
require_once 'includes/Services.php';

$functions = new Functions();
$users = new UserService($functions);
$mailer = new MailService($functions);

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

$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $functions->requireSameOrigin();

    $email = trim((string) ($_POST['email'] ?? ''));

    // 限流：同一会话 5 分钟内最多 3 次
    $attempts = (int) ($_SESSION['forgot_attempts'] ?? 0);
    $firstAt = (int) ($_SESSION['forgot_first_at'] ?? 0);

    if (time() - $firstAt > 300) {
        $attempts = 0;
        $firstAt = time();
    }

    if ($attempts >= 3) {
        $error = '操作过于频繁，请 5 分钟后再试';
    } elseif ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = '请输入有效的邮箱地址';
    } else {
        $_SESSION['forgot_attempts'] = $attempts + 1;
        $_SESSION['forgot_first_at'] = $firstAt;

        $result = $users->createPasswordReset($email);

        if (!$result['ok']) {
            $error = $result['error'];
        } else {
            // 无论邮箱是否注册都提示已发送，避免暴露注册状态
            $message = '如果该邮箱已注册，重置密码的链接已发送到您的邮箱，请查收（1 小时内有效）。';

            if (!empty($result['token']) && !empty($result['user'])) {
                if (!$mailer->enabled()) {
                    // 站点未配置邮件时，不误导用户
                    $message = '';
                    $error = '站点尚未配置邮件服务，无法发送重置邮件，请联系管理员重置密码。';
                } else {
                    $scheme = (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off') ? 'https' : 'http';
                    $host = (string) ($_SERVER['HTTP_HOST'] ?? '');
                    $resetUrl = $scheme . '://' . $host . rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? '/'), '/') . '/reset.php?token=' . $result['token'];

                    $safeName = $functions->escape($brandName);
                    $html = '<div style="font-family:sans-serif;max-width:520px;margin:0 auto;">'
                        . '<h2 style="color:#333;">重置密码</h2>'
                        . '<p>您好，' . $functions->escape((string) $result['user']['username']) . '：</p>'
                        . '<p>我们收到了您在 <strong>' . $safeName . '</strong> 的重置密码请求。请点击下面的按钮设置新密码（1 小时内有效）：</p>'
                        . '<p style="text-align:center;margin:28px 0;"><a href="' . $functions->escape($resetUrl) . '" style="background:#4f6df5;color:#fff;padding:12px 28px;border-radius:8px;text-decoration:none;display:inline-block;">重置密码</a></p>'
                        . '<p style="color:#888;font-size:13px;">如果按钮无法点击，请复制此链接到浏览器打开：<br>' . $functions->escape($resetUrl) . '</p>'
                        . '<p style="color:#888;font-size:13px;">如果您没有申请重置密码，请忽略本邮件，您的密码不会改变。</p>'
                        . '</div>';

                    $text = "重置密码\n\n请打开以下链接设置新密码（1 小时内有效）：\n" . $resetUrl . "\n\n如果您没有申请重置密码，请忽略本邮件。";

                    $send = $mailer->send($email, '[' . $brandName . '] 重置密码', $html, $text);

                    if (!$send['ok']) {
                        $message = '';
                        $error = '邮件发送失败，请稍后重试或联系管理员。';
                    }
                }
            }
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
    <title>忘记密码 - <?php echo $functions->escape($brandName); ?></title>
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
                    <p class="auth-tagline">找回账号密码</p>
                </div>
            </div>
        </div>

        <h2 class="auth-heading">忘记密码</h2>
        <p class="auth-intro">输入注册时使用的邮箱，我们会发送一封包含重置链接的邮件。</p>

        <?php if ($error !== ''): ?>
            <div class="auth-alert" role="alert"><?php echo $functions->escape($error); ?></div>
        <?php endif; ?>
        <?php if ($message !== ''): ?>
            <div class="auth-alert" role="status" style="color: var(--success, #16a34a);"><?php echo $functions->escape($message); ?></div>
        <?php endif; ?>

        <form method="POST" novalidate>
            <div class="auth-field">
                <label for="email">注册邮箱</label>
                <input type="email"
                       id="email"
                       name="email"
                       maxlength="190"
                       autocomplete="email"
                       autofocus
                       placeholder="your-email@example.com">
            </div>

            <button type="submit" class="auth-submit">发送重置邮件</button>
        </form>

        <p class="auth-switch">想起来了？<a href="login.php">返回登录</a></p>

        <p class="auth-foot">
            <span class="auth-foot-links">
                <a href="index.php">返回首页</a>
            </span>
        </p>
    </main>
</body>
</html>
