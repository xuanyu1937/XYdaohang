<?php
/**
 * 轩宇-导航 · 后台登录
 */
require_once __DIR__ . '/../includes/bootstrap.php';
require_once '../includes/functions.php';

$functions = new Functions();

// v2.18.11 P0-2：与前台登录 / 关于页复用同一 logo 资源与同一套品牌块尺寸
$adminBrandName = trim((string) $functions->getSetting('site_title')) ?: '轩宇-导航';
$adminSiteLogoUrl = $functions->getLogoUrl($functions->getSetting('site_logo'));

if ($functions->isLoggedIn()) {
    $functions->redirect('index.php');
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $functions->requireSameOrigin();
    
    $username = trim((string) ($_POST['username'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');
    
    $attempts = (int) ($_SESSION['login_attempts'] ?? 0);
    $lastAttempt = (int) ($_SESSION['login_last_attempt'] ?? 0);
    
    if ($attempts >= 5 && (time() - $lastAttempt) < 300) {
        $error = '登录尝试过于频繁，请 5 分钟后再试';
    } else {
        // 双源登录：优先 admins 表，其次 users 表中 is_admin = 1 的账号
        $admin = $functions->verifyAdmin($username, $password);
        $source = $admin ? 'admins' : '';

        if (!$admin) {
            $userAccount = navVerifyAdminUser($functions, $username, $password);

            if ($userAccount) {
                $admin = $userAccount;
                $source = 'users';
            }
        }
        
        if ($admin) {
            session_regenerate_id(true);
            
            $_SESSION['admin_id'] = (int) $admin['id'];
            $_SESSION['admin_username'] = (string) $admin['username'];
            $_SESSION['admin_source'] = $source;
            $_SESSION['admin_user_id'] = $source === 'users' ? (int) $admin['id'] : 0;
            $_SESSION['login_time'] = date('Y-m-d H:i:s');
            $_SESSION['last_activity'] = time();
            unset($_SESSION['login_attempts'], $_SESSION['login_last_attempt']);

            // 通过 users 表登录时，同时建立前台身份，便于直接访问「我的导航」
            if ($source === 'users') {
                $_SESSION['user_id'] = (int) $admin['id'];
                $_SESSION['user_username'] = (string) $admin['username'];
                $_SESSION['user_is_admin'] = 1;
            }
            
            $functions->redirect('index.php');
        }
        
        $_SESSION['login_attempts'] = $attempts + 1;
        $_SESSION['login_last_attempt'] = time();
        $error = '用户名或密码错误';
    }
}

/**
 * 第二登录来源：users 表中被授予管理员权限的账号（v2 新增）。
 * 支持用邮箱或用户名登录；封禁账号与普通用户一律拒绝。
 * 老库尚未执行升级脚本（没有 users 表）时静默跳过。
 */
function navVerifyAdminUser($functions, $account, $password)
{
    if (!class_exists('Database')) {
        return false;
    }

    try {
        $db = Database::getInstance()->getConnection();

        $stmt = $db->prepare(
            'SELECT id, username, password_hash, is_admin, status
             FROM users WHERE email = ? OR username = ? LIMIT 1'
        );
        $stmt->execute([$account, $account]);
        $user = $stmt->fetch();
    } catch (Throwable $dbError) {
        return false;
    }

    if (!$user || (int) $user['is_admin'] !== 1) {
        return false;
    }

    if ((string) $user['status'] === 'banned') {
        return false;
    }

    if (!password_verify($password, (string) $user['password_hash'])) {
        return false;
    }

    return ['id' => (int) $user['id'], 'username' => (string) $user['username']];
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex,nofollow">
    <title>后台登录 - 轩宇-导航</title>
    <!-- v2.18.10：此前本页未引入任何样式表，内联 style 里的 var(--a-*) 全部失效，
         导致按钮无蓝底、圆角归零、品牌块退化为 20px 方块。按序补引入。 -->
    <link rel="stylesheet" href="../assets/css/apple-tokens.css?v=2.18.16">
    <link rel="stylesheet" href="../assets/css/admin.css?v=2.18.16">
    <style>
        /* v2.18.10：本页只保留布局与尺寸。
           颜色 / 圆角 / 阴影 / 字重一律走 apple-tokens.css 与 admin.css，
           不再在此重复声明，避免两处口径漂移。 */
        * { box-sizing: border-box; }

        body {
            margin: 0;
            min-height: 100vh;
            display: grid;
            place-items: center;
            padding: 24px 14px;
        }

        .login-card {
            width: min(420px, 100%);
            padding: 32px 30px 26px;
        }

        /* 品牌块：与前台 login.php 同构，52 见方 / 18 圆角 / 纯蓝 / 唯一阴影 */
        .brand {
            display: flex;
            align-items: center;
            gap: 12px;
            margin-bottom: 24px;
        }
        .brand-mark {
            flex: 0 0 52px;
            width: 52px;
            height: 52px;
        }
        .brand h1 {
            margin: 0;
            font-size: 25px;
            letter-spacing: -0.035em;
        }
        .brand p {
            margin: 3px 0 0;
            font-size: 12px;
        }

        .intro {
            margin: 0 0 22px;
            font-size: 14px;
            line-height: 1.47;
        }

        .alert {
            margin-bottom: 18px;
            padding: 11px 13px;
            font-size: 13px;
            line-height: 1.5;
        }

        .field { margin-bottom: 16px; }
        .field label {
            display: block;
            margin-bottom: 7px;
            font-size: 13px;
        }
        /* 输入框：高 44 / 圆角 11 / 1px 描边 —— 其余由 admin.css .form-control 提供 */
        .field .form-control {
            width: 100%;
            height: 44px;
            min-height: 44px;
            padding: 0 13px;
            border-radius: var(--a-radius-md);
        }
        .field .form-control:focus {
            outline: 2px solid var(--a-focus);
            outline-offset: 1px;
        }
        .field .form-control.invalid {
            border-color: var(--a-danger);
            outline: 2px solid var(--a-danger);
            outline-offset: 1px;
        }

        .error-text {
            display: none;
            margin: 6px 0 0;
            font-size: 12px;
        }
        .error-text.show { display: block; }

        /* 主按钮：宽 100% / 高 46 / 圆角 11 —— 颜色与字重由 .btn-primary 提供 */
        .submit {
            width: 100%;
            height: 46px;
            margin-top: 6px;
            border-radius: var(--a-radius-md);
            font-size: 15px;
        }

        .foot {
            margin: 22px 0 0;
            padding-top: 16px;
            font-size: 12px;
            text-align: center;
        }

        @media (max-width: 420px) {
            .login-card { padding: 26px 20px 22px; }
            .brand h1 { font-size: 21px; }
            .brand-mark { flex-basis: 44px; width: 44px; height: 44px; }
        }
    </style>
</head>
<body>
    <main class="login-card">
        <div class="brand">
            <div class="brand-mark" aria-hidden="true">轩<?php if ($adminSiteLogoUrl !== ''): ?><img src="<?php echo $functions->escape($adminSiteLogoUrl); ?>" alt="" onerror="this.remove();"><?php endif; ?></div>
            <div>
                <h1><?php echo $functions->escape($adminBrandName); ?></h1>
                <p>后台管理系统</p>
            </div>
        </div>

        <p class="intro">请使用管理员账号登录，登录后可管理导航、分类和收录申请。</p>

        <?php if ($error !== ''): ?>
            <div class="alert" role="alert"><?php echo $functions->escape($error); ?></div>
        <?php endif; ?>

        <form id="loginForm" method="POST" novalidate>
            <div class="field">
                <label for="username">用户名</label>
                <input type="text" id="username" name="username" class="form-control" maxlength="50" autocomplete="username" autofocus placeholder="请输入管理员用户名">
                <p id="usernameError" class="error-text" aria-live="polite"></p>
            </div>

            <div class="field">
                <label for="password">密码</label>
                <input type="password" id="password" name="password" class="form-control" autocomplete="current-password" placeholder="请输入密码">
                <p id="passwordError" class="error-text" aria-live="polite"></p>
            </div>

            <button type="submit" class="btn btn-primary btn-block submit">登录后台</button>
        </form>

        <p class="foot">轩宇-导航 · 管理入口</p>
    </main>

    <script>
        (function () {
            var form = document.getElementById('loginForm');
            var username = document.getElementById('username');
            var password = document.getElementById('password');
            var usernameError = document.getElementById('usernameError');
            var passwordError = document.getElementById('passwordError');

            function setError(input, errorNode, message) {
                input.classList.toggle('invalid', message !== '');
                errorNode.textContent = message;
                errorNode.classList.toggle('show', message !== '');
            }

            function validate() {
                var ok = true;

                if (username.value.trim() === '') {
                    setError(username, usernameError, '请输入用户名');
                    ok = false;
                } else {
                    setError(username, usernameError, '');
                }

                if (password.value === '') {
                    setError(password, passwordError, '请输入密码');
                    ok = false;
                } else {
                    setError(password, passwordError, '');
                }

                return ok;
            }

            username.addEventListener('input', function () {
                if (username.value.trim() !== '') {
                    setError(username, usernameError, '');
                }
            });

            password.addEventListener('input', function () {
                if (password.value !== '') {
                    setError(password, passwordError, '');
                }
            });

            form.addEventListener('submit', function (event) {
                if (!validate()) {
                    event.preventDefault();
                }
            });
        })();
    </script>
</body>
</html>
