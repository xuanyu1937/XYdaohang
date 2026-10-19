<?php
/**
 * 轩宇-导航 · 前台用户注册
 */
require_once __DIR__ . '/includes/bootstrap.php';
require_once 'includes/functions.php';
require_once 'includes/Services.php';

$functions = new Functions();
$users = new UserService($functions);

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

if ($users->isLoggedIn()) {
    $functions->redirect('my.php');
}

$error = '';
$form = ['email' => '', 'username' => ''];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $functions->requireSameOrigin();

    if (!$registerEnabled) {
        $error = '当前站点已关闭注册';
    } else {
        $recent = (int) ($_SESSION['register_attempts'] ?? 0);
        $lastAt = (int) ($_SESSION['register_last_at'] ?? 0);

        if ($recent >= 3 && (time() - $lastAt) < 60) {
            $error = '请求过于频繁，请稍后再试';
        } else {
            $_SESSION['register_attempts'] = $recent + 1;
            $_SESSION['register_last_at'] = time();

            $form['email'] = trim((string) ($_POST['email'] ?? ''));
            $form['username'] = trim((string) ($_POST['username'] ?? ''));

            $result = $users->register(
                $form['email'],
                $form['username'],
                (string) ($_POST['password'] ?? ''),
                (string) ($_POST['password_confirm'] ?? '')
            );

            if ($result['ok']) {
                unset($_SESSION['register_attempts'], $_SESSION['register_last_at']);
                $users->startSession($result['user']);
                $functions->redirect('my.php');
            }

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
    <title>注册 - <?php echo $functions->escape($brandName); ?></title>
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

        <h2 class="auth-heading">创建账号</h2>
        <p class="auth-intro">注册后即可收藏自己的常用网址，也可以导入浏览器书签。</p>

        <?php if (!$registerEnabled): ?>
            <div class="auth-alert" role="alert">当前站点已关闭注册，请联系管理员开通账号。</div>
        <?php elseif ($error !== ''): ?>
            <div class="auth-alert" role="alert"><?php echo $functions->escape($error); ?></div>
        <?php endif; ?>

        <form id="registerForm" method="POST" novalidate>
            <div class="auth-field">
                <label for="email">邮箱</label>
                <input type="email"
                       id="email"
                       name="email"
                       value="<?php echo $functions->escape($form['email']); ?>"
                       maxlength="190"
                       autocomplete="email"
                       placeholder="用于登录，不会公开"
                       <?php echo $registerEnabled ? 'autofocus' : 'disabled'; ?>>
                <p class="auth-error" id="emailError" aria-live="polite"></p>
            </div>

            <div class="auth-field">
                <label for="username">用户名</label>
                <input type="text"
                       id="username"
                       name="username"
                       value="<?php echo $functions->escape($form['username']); ?>"
                       maxlength="20"
                       autocomplete="username"
                       placeholder="2-20 个字符"
                       <?php echo $registerEnabled ? '' : 'disabled'; ?>>
                <p class="auth-error" id="usernameError" aria-live="polite"></p>
            </div>

            <div class="auth-field">
                <label for="password">密码</label>
                <input type="password"
                       id="password"
                       name="password"
                       autocomplete="new-password"
                       placeholder="至少 8 位"
                       <?php echo $registerEnabled ? '' : 'disabled'; ?>>
                <p class="auth-hint">建议使用字母、数字与符号的组合。</p>
                <p class="auth-error" id="passwordError" aria-live="polite"></p>
            </div>

            <div class="auth-field">
                <label for="password_confirm">确认密码</label>
                <input type="password"
                       id="password_confirm"
                       name="password_confirm"
                       autocomplete="new-password"
                       placeholder="再次输入密码"
                       <?php echo $registerEnabled ? '' : 'disabled'; ?>>
                <p class="auth-error" id="passwordConfirmError" aria-live="polite"></p>
            </div>

            <button type="submit" class="auth-submit" <?php echo $registerEnabled ? '' : 'disabled'; ?>>注册</button>
        </form>

        <p class="auth-switch">已有账号？<a href="login.php">直接登录</a></p>

        <p class="auth-foot">
            <span class="auth-foot-links">
                <a href="index.php">返回首页</a>
                <span class="auth-foot-sep">·</span>
                <a href="about.php">关于本站</a>
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

            var form = document.getElementById('registerForm');

            if (!form || form.querySelector('button[type="submit"]').disabled) {
                return;
            }

            var email = document.getElementById('email');
            var username = document.getElementById('username');
            var password = document.getElementById('password');
            var passwordConfirm = document.getElementById('password_confirm');

            var nodes = {
                email: document.getElementById('emailError'),
                username: document.getElementById('usernameError'),
                password: document.getElementById('passwordError'),
                passwordConfirm: document.getElementById('passwordConfirmError')
            };

            function setError(input, node, message) {
                input.classList.toggle('is-invalid', message !== '');
                node.textContent = message;
                node.classList.toggle('is-visible', message !== '');
            }

            function validate() {
                var firstInvalid = null;
                var value;

                value = email.value.trim();
                if (value === '') {
                    setError(email, nodes.email, '请输入邮箱'); firstInvalid = firstInvalid || email;
                } else if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(value)) {
                    setError(email, nodes.email, '邮箱格式不正确'); firstInvalid = firstInvalid || email;
                } else {
                    setError(email, nodes.email, '');
                }

                value = username.value.trim();
                if (value.length < 2 || value.length > 20) {
                    setError(username, nodes.username, '用户名需为 2-20 个字符'); firstInvalid = firstInvalid || username;
                } else if (!/^[\u4e00-\u9fa5A-Za-z0-9_.-]+$/.test(value)) {
                    setError(username, nodes.username, '仅支持中英文、数字、下划线、点和短横线'); firstInvalid = firstInvalid || username;
                } else {
                    setError(username, nodes.username, '');
                }

                if (password.value.length < 8) {
                    setError(password, nodes.password, '密码至少 8 位'); firstInvalid = firstInvalid || password;
                } else {
                    setError(password, nodes.password, '');
                }

                if (passwordConfirm.value !== password.value) {
                    setError(passwordConfirm, nodes.passwordConfirm, '两次输入的密码不一致'); firstInvalid = firstInvalid || passwordConfirm;
                } else {
                    setError(passwordConfirm, nodes.passwordConfirm, '');
                }

                return firstInvalid;
            }

            [email, username, password, passwordConfirm].forEach(function (input) {
                input.addEventListener('input', function () {
                    var key = input.id === 'password_confirm' ? 'passwordConfirm' : input.id;
                    setError(input, nodes[key], '');
                });
            });

            form.addEventListener('submit', function (event) {
                var firstInvalid = validate();

                if (firstInvalid) {
                    event.preventDefault();
                    firstInvalid.focus();
                }
            });
        })();
    </script>
</body>
</html>
