<?php
/**
 * 轩宇-导航 · 前台用户登录
 */
require_once __DIR__ . '/includes/bootstrap.php';
require_once 'includes/functions.php';
require_once 'includes/Services.php';

$functions = new Functions();
$users = new UserService($functions);

/**
 * 只接受站内相对路径，避免开放重定向。
 */
function authSafeRedirect($target, $fallback = 'my.php')
{
    $target = trim((string) $target);

    if ($target === '' || strpos($target, '//') !== false || preg_match('#^[a-z][a-z0-9+.-]*:#i', $target)) {
        return $fallback;
    }

    if (!preg_match('/^[A-Za-z0-9._\-\/?=&%]+$/', $target)) {
        return $fallback;
    }

    return $target;
}

$redirectTo = authSafeRedirect($_GET['redirect'] ?? $_POST['redirect'] ?? '');

if ($users->isLoggedIn()) {
    $functions->redirect($redirectTo);
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

$registerEnabled = $functions->getSetting('register_enabled') !== '0';
$error = '';
$account = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $functions->requireSameOrigin();

    $account = trim((string) ($_POST['account'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');

    $attempts = (int) ($_SESSION['user_login_attempts'] ?? 0);
    $lastAttempt = (int) ($_SESSION['user_login_last_attempt'] ?? 0);

    if ($attempts >= 5 && (time() - $lastAttempt) < 300) {
        $error = '登录尝试过于频繁，请 5 分钟后再试';
    } else {
        $result = $users->login($account, $password);

        if ($result['ok']) {
            unset($_SESSION['user_login_attempts'], $_SESSION['user_login_last_attempt']);
            $functions->redirect($redirectTo);
        }

        $_SESSION['user_login_attempts'] = $attempts + 1;
        $_SESSION['user_login_last_attempt'] = time();
        $error = $result['error'];
    }
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex,nofollow">
    <title>登录 - <?php echo $functions->escape($brandName); ?></title>
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
                    <p class="auth-tagline">个人导航账号</p>
                </div>
            </div>

            <button type="button" class="auth-theme" id="themeToggle" aria-label="切换深浅色" title="切换深浅色">
                <svg class="icon-moon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                    <path d="M20.5 14.2A8.5 8.5 0 0 1 9.8 3.5 8.5 8.5 0 1 0 20.5 14.2Z"></path>
                </svg>
                <svg class="icon-sun" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                    <circle cx="12" cy="12" r="4"></circle>
                    <path d="M12 2v2M12 20v2M4.9 4.9l1.4 1.4M17.7 17.7l1.4 1.4M2 12h2M20 12h2M4.9 19.1l1.4-1.4M17.7 6.3l1.4-1.4"></path>
                </svg>
            </button>
        </div>

        <h2 class="auth-heading">登录</h2>
        <p class="auth-intro">登录后可保存自己的常用网址，支持书签导入与分享。</p>

        <?php if ($error !== ''): ?>
            <div class="auth-alert" role="alert"><?php echo $functions->escape($error); ?></div>
        <?php endif; ?>

        <form id="loginForm" method="POST" novalidate>
            <input type="hidden" name="redirect" value="<?php echo $functions->escape($redirectTo); ?>">

            <div class="auth-field">
                <label for="account">邮箱或用户名</label>
                <input type="text"
                       id="account"
                       name="account"
                       value="<?php echo $functions->escape($account); ?>"
                       maxlength="190"
                       autocomplete="username"
                       autofocus
                       placeholder="请输入邮箱或用户名">
                <p class="auth-error" id="accountError" aria-live="polite"></p>
            </div>

            <div class="auth-field">
                <label for="password">密码</label>
                <input type="password"
                       id="password"
                       name="password"
                       autocomplete="current-password"
                       placeholder="请输入密码">
                <p class="auth-error" id="passwordError" aria-live="polite"></p>
            </div>

            <button type="submit" class="auth-submit">登录</button>
        </form>

        <?php if ($registerEnabled): ?>
            <p class="auth-switch">还没有账号？<a href="register.php">立即注册</a></p>
        <?php else: ?>
            <p class="auth-switch">当前站点已关闭注册</p>
        <?php endif; ?>

        <p class="auth-foot">
            <span class="auth-foot-links">
                <a href="index.php">返回首页</a>
                <span class="auth-foot-sep">·</span>
                <a href="admin/login.php">管理员入口</a>
            </span>
        </p>
    </main>

    <script>
        (function () {
            var toggle = document.getElementById('themeToggle');

            if (toggle) {
                toggle.addEventListener('click', function () {
                    var next = document.documentElement.getAttribute('data-theme') === 'dark' ? 'light' : 'dark';
                    document.documentElement.setAttribute('data-theme', next);
                    try { localStorage.setItem('xy-nav-theme', next); } catch (e) {}
                });
            }

            var form = document.getElementById('loginForm');
            var account = document.getElementById('account');
            var password = document.getElementById('password');
            var accountError = document.getElementById('accountError');
            var passwordError = document.getElementById('passwordError');

            function setError(input, node, message) {
                input.classList.toggle('is-invalid', message !== '');
                node.textContent = message;
                node.classList.toggle('is-visible', message !== '');
            }

            account.addEventListener('input', function () {
                if (account.value.trim() !== '') { setError(account, accountError, ''); }
            });

            password.addEventListener('input', function () {
                if (password.value !== '') { setError(password, passwordError, ''); }
            });

            form.addEventListener('submit', function (event) {
                var firstInvalid = null;

                if (account.value.trim() === '') {
                    setError(account, accountError, '请输入邮箱或用户名');
                    firstInvalid = firstInvalid || account;
                }

                if (password.value === '') {
                    setError(password, passwordError, '请输入密码');
                    firstInvalid = firstInvalid || password;
                }

                if (firstInvalid) {
                    event.preventDefault();
                    firstInvalid.focus();
                }
            });
        })();
    </script>
</body>
</html>
