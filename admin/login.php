<?php
/**
 * 轩宇-导航 · 后台登录
 */
require_once __DIR__ . '/../includes/bootstrap.php';
require_once '../includes/functions.php';

$functions = new Functions();

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
    <style>
        * { box-sizing: border-box; }
        :root {
            --primary: #2563eb;
            --primary-dark: #1d4ed8;
            --text: #172033;
            --muted: #64748b;
            --border: #dfe6f1;
            --danger: #dc2626;
            --danger-soft: #fef2f2;
        }
        body {
            margin: 0;
            min-height: 100vh;
            display: grid;
            place-items: center;
            padding: 24px 14px;
            color: var(--text);
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", "PingFang SC", "Microsoft YaHei", sans-serif;
            background:
                radial-gradient(900px 500px at 15% 5%, #dbeafe 0%, transparent 60%),
                radial-gradient(800px 520px at 90% 95%, #e0e7ff 0%, transparent 60%),
                #f4f7fc;
        }
        .login-card {
            width: min(420px, 100%);
            background: #fff;
            border: 1px solid #e6edf7;
            border-radius: 20px;
            box-shadow: 0 30px 80px -40px rgba(15, 23, 42, .45), 0 2px 8px rgba(15, 23, 42, .04);
            padding: 32px 30px 26px;
        }
        .brand {
            display: flex;
            align-items: center;
            gap: 11px;
            margin-bottom: 24px;
        }
        .brand-mark {
            width: 42px;
            height: 42px;
            border-radius: 12px;
            display: grid;
            place-items: center;
            color: #fff;
            font-weight: 800;
            font-size: 18px;
            background: linear-gradient(135deg, #2563eb, #7c3aed);
            box-shadow: 0 8px 20px -8px rgba(37, 99, 235, .65);
        }
        .brand h1 {
            margin: 0;
            font-size: 19px;
            letter-spacing: .3px;
        }
        .brand p {
            margin: 2px 0 0;
            color: var(--muted);
            font-size: 12px;
        }
        .intro {
            margin: 0 0 22px;
            color: var(--muted);
            font-size: 13px;
            line-height: 1.7;
        }
        .alert {
            margin-bottom: 18px;
            padding: 11px 13px;
            border-radius: 10px;
            background: var(--danger-soft);
            border: 1px solid #fecaca;
            color: #b91c1c;
            font-size: 13px;
            line-height: 1.6;
        }
        .field { margin-bottom: 16px; }
        .field label {
            display: block;
            margin-bottom: 7px;
            font-size: 13px;
            font-weight: 600;
            color: #334155;
        }
        .field input {
            width: 100%;
            height: 44px;
            padding: 0 13px;
            border: 1px solid var(--border);
            border-radius: 10px;
            outline: none;
            font-size: 14px;
            color: var(--text);
            background: #fbfdff;
            transition: border-color .18s ease, box-shadow .18s ease, background .18s ease;
        }
        .field input:focus {
            border-color: var(--primary);
            background: #fff;
            box-shadow: 0 0 0 3px rgba(37, 99, 235, .12);
        }
        .field input.invalid {
            border-color: var(--danger);
            box-shadow: 0 0 0 3px rgba(220, 38, 38, .1);
        }
        .error-text {
            display: none;
            margin: 6px 0 0;
            color: var(--danger);
            font-size: 12px;
        }
        .error-text.show { display: block; }
        .submit {
            width: 100%;
            height: 45px;
            margin-top: 6px;
            border: 0;
            border-radius: 10px;
            color: #fff;
            font-size: 15px;
            font-weight: 700;
            letter-spacing: .5px;
            cursor: pointer;
            background: linear-gradient(135deg, #2563eb, #3b82f6);
            box-shadow: 0 12px 24px -12px rgba(37, 99, 235, .8);
            transition: transform .16s ease, box-shadow .16s ease, filter .16s ease;
        }
        .submit:hover { transform: translateY(-1px); filter: brightness(1.04); }
        .submit:active { transform: translateY(0); }
        .foot {
            margin: 22px 0 0;
            padding-top: 16px;
            border-top: 1px solid #eef2f8;
            color: #94a3b8;
            font-size: 11px;
            text-align: center;
        }
        @media (max-width: 420px) {
            .login-card { padding: 26px 20px 22px; border-radius: 16px; }
            .brand h1 { font-size: 17px; }
        }
    </style>
</head>
<body>
    <main class="login-card">
        <div class="brand">
            <div class="brand-mark">轩</div>
            <div>
                <h1>轩宇-导航</h1>
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
                <input type="text" id="username" name="username" maxlength="50" autocomplete="username" autofocus placeholder="请输入管理员用户名">
                <p id="usernameError" class="error-text" aria-live="polite"></p>
            </div>

            <div class="field">
                <label for="password">密码</label>
                <input type="password" id="password" name="password" autocomplete="current-password" placeholder="请输入密码">
                <p id="passwordError" class="error-text" aria-live="polite"></p>
            </div>

            <button type="submit" class="submit">登录后台</button>
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
