<?php
/**
 * 轩宇-导航 · 全新服务器安装向导
 * 访问 /install.php，填写数据库、站点、管理员信息，一键安装。
 * 安装完成后请删除本文件。
 */
error_reporting(E_ALL);
ini_set('display_errors', '0');

$lockFile = __DIR__ . '/config/installed.lock';
$configLocal = __DIR__ . '/config/database.local.php';
$sqlFile = __DIR__ . '/install/install.sql';
$installed = is_file($lockFile);
$success = false;
$errors = [];
$notice = '';

function nav_e($value) {
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function install_run_sql(PDO $pdo, $file) {
    if (!is_file($file) || !is_readable($file)) {
        throw new RuntimeException('找不到 install/install.sql');
    }
    
    $sql = file_get_contents($file);
    if ($sql === false) {
        throw new RuntimeException('无法读取 install/install.sql');
    }
    
    $sql = preg_replace('/^[ \t]*--.*$/m', '', $sql);
    
    foreach (explode(';', $sql) as $statement) {
        $statement = trim($statement);
        if ($statement === '') {
            continue;
        }
        $pdo->exec($statement);
    }
}

function install_write_database_config($path, array $config) {
    $guard = "<?php\n"
        . "if (basename((string) (\$_SERVER['SCRIPT_FILENAME'] ?? '')) === basename(__FILE__)) {\n"
        . "    http_response_code(403);\n"
        . "    exit;\n"
        . "}\n\n"
        . "return " . var_export($config, true) . ";\n";
    
    if (file_put_contents($path, $guard, LOCK_EX) === false) {
        throw new RuntimeException('无法写入 config/database.local.php，请检查 config/ 目录权限');
    }
    
    if (function_exists('chmod')) {
        @chmod($path, 0640);
    }
}

$defaults = [
    'db_host' => '127.0.0.1',
    'db_port' => '3306',
    'db_name' => '',
    'db_user' => '',
    'db_pass' => '',
    'site_title' => '轩宇-导航',
    'admin_user' => '',
    'admin_pass' => '',
    'admin_confirm' => '',
];
$values = $defaults;

$checks = [
    'PHP 8.1+' => version_compare(PHP_VERSION, '8.1.0', '>='),
    'PDO MySQL' => extension_loaded('pdo_mysql'),
    'config/ 可写' => is_writable(__DIR__ . '/config'),
    'install.sql 存在' => is_file($sqlFile),
    'curl 扩展' => extension_loaded('curl'),
    'fileinfo 扩展' => extension_loaded('fileinfo'),
];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$installed) {
    foreach ($values as $key => $default) {
        if (isset($_POST[$key]) && is_string($_POST[$key])) {
            $values[$key] = trim($_POST[$key]);
        }
    }
    // 密码不做 trim，保留用户输入
    if (isset($_POST['db_pass']) && is_string($_POST['db_pass'])) {
        $values['db_pass'] = $_POST['db_pass'];
    }
    if (isset($_POST['admin_pass']) && is_string($_POST['admin_pass'])) {
        $values['admin_pass'] = $_POST['admin_pass'];
    }
    if (isset($_POST['admin_confirm']) && is_string($_POST['admin_confirm'])) {
        $values['admin_confirm'] = $_POST['admin_confirm'];
    }
    
    $dbHost = $values['db_host'];
    $dbPort = (int) $values['db_port'];
    $dbName = $values['db_name'];
    $dbUser = $values['db_user'];
    $dbPass = $values['db_pass'];
    $siteTitle = $values['site_title'];
    $adminUser = $values['admin_user'];
    $adminPass = $values['admin_pass'];
    $adminConfirm = $values['admin_confirm'];
    
    if (!version_compare(PHP_VERSION, '8.1.0', '>=')) {
        $errors[] = 'PHP 版本需要 8.1 或更高';
    }
    if (!extension_loaded('pdo_mysql')) {
        $errors[] = '缺少 PDO_MySQL 扩展';
    }
    if (!is_writable(__DIR__ . '/config')) {
        $errors[] = 'config/ 目录不可写，无法保存数据库配置';
    }
    if (!is_file($sqlFile)) {
        $errors[] = '找不到 install/install.sql，无法建表';
    }
    if ($dbHost === '' || preg_match('/[\s;]/', $dbHost)) {
        $errors[] = '数据库主机格式不正确';
    }
    if ($dbPort < 1 || $dbPort > 65535) {
        $errors[] = '数据库端口需在 1–65535 之间';
    }
    if (!preg_match('/^[A-Za-z0-9_]{1,64}$/', $dbName)) {
        $errors[] = '数据库名只能包含字母、数字、下划线，长度 1–64';
    }
    if ($dbUser === '') {
        $errors[] = '数据库用户名不能为空';
    }
    if ($siteTitle === '' || strlen($siteTitle) > 100) {
        $errors[] = '站点名称不能为空，且不超过 100 字节';
    }
    if (!preg_match('/^[A-Za-z0-9_\x{4e00}-\x{9fa5}]{3,50}$/u', $adminUser)) {
        $errors[] = '管理员用户名需 3–50 位，仅限中英文、数字、下划线';
    }
    if (strlen($adminPass) < 8) {
        $errors[] = '管理员密码至少 8 位';
    }
    if ($adminPass !== $adminConfirm) {
        $errors[] = '两次输入的管理员密码不一致';
    }
    
    if (empty($errors)) {
        try {
            $pdoOptions = [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::ATTR_TIMEOUT => 8,
            ];
            
            $pdo = null;
            
            try {
                $pdo = new PDO(
                    "mysql:host={$dbHost};port={$dbPort};dbname={$dbName};charset=utf8mb4",
                    $dbUser,
                    $dbPass,
                    $pdoOptions
                );
            } catch (PDOException $firstError) {
                // 数据库不存在时，连接服务器并创建；已有库但无权限 CREATE 时仍可用上面的连接。
                $pdo = new PDO(
                    "mysql:host={$dbHost};port={$dbPort};charset=utf8mb4",
                    $dbUser,
                    $dbPass,
                    $pdoOptions
                );
                $pdo->exec("CREATE DATABASE IF NOT EXISTS `{$dbName}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
                $pdo->exec("USE `{$dbName}`");
            }
            
            install_run_sql($pdo, $sqlFile);
            
            try {
                $pdo->exec("ALTER TABLE admins MODIFY password VARCHAR(255) NOT NULL");
            } catch (Throwable $e) {
                // install.sql 已是 varchar(255)；旧表结构才需要，失败不阻断。
            }
            
            $adminCount = (int) $pdo->query("SELECT COUNT(*) FROM admins")->fetchColumn();
            if ($adminCount > 0) {
                throw new RuntimeException('数据库中已有管理员。请直接登录；如忘记密码，请清空 admins 表后重试。');
            }
            
            install_write_database_config($configLocal, [
                'host' => $dbHost,
                'port' => $dbPort,
                'dbname' => $dbName,
                'username' => $dbUser,
                'password' => $dbPass,
            ]);
            
            foreach ([
                __DIR__ . '/uploads',
                __DIR__ . '/uploads/logos',
                __DIR__ . '/cache',
                __DIR__ . '/logs',
                __DIR__ . '/backups',
            ] as $dir) {
                if (!is_dir($dir) && (!function_exists('mkdir') || !@mkdir($dir, 0755, true)) && !is_dir($dir)) {
                    throw new RuntimeException('无法创建目录：' . basename($dir) . '，请检查根目录权限');
                }
            }
            
            $hash = password_hash($adminPass, PASSWORD_DEFAULT);
            if (!is_string($hash) || $hash === '') {
                throw new RuntimeException('密码加密失败');
            }
            
            $stmt = $pdo->prepare("INSERT INTO admins (username, password) VALUES (?, ?)");
            $stmt->execute([$adminUser, $hash]);
            
            $stmt = $pdo->prepare("INSERT INTO settings (`key`, `value`) VALUES ('site_title', ?) ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)");
            $stmt->execute([$siteTitle]);
            
            $locked = @file_put_contents($lockFile, 'installed ' . date('c') . PHP_EOL, LOCK_EX);
            if ($locked === false) {
                $notice = '已安装，但无法写入 install.lock；请手动删除 install.php。';
            }
            
            $deleted = function_exists('unlink') ? @unlink(__FILE__) : false;
            if ($deleted) {
                $notice = 'install.php 已自动删除。请继续删除 install/ 目录。';
            } elseif ($locked !== false) {
                $notice = '安装完成，但 install.php 未能自动删除，请手动删除。';
            }
            
            $installDir = __DIR__ . '/install';
            $cleanupOk = true;
            if (is_dir($installDir)) {
                $items = function_exists('scandir') ? @scandir($installDir) : false;
                if (is_array($items)) {
                    foreach ($items as $item) {
                        if ($item === '.' || $item === '..') {
                            continue;
                        }
                        $path = $installDir . '/' . $item;
                        if (is_file($path) && (!function_exists('unlink') || !@unlink($path))) {
                            $cleanupOk = false;
                        }
                    }
                } else {
                    $cleanupOk = false;
                }
                if (!function_exists('rmdir') || !@rmdir($installDir)) {
                    $cleanupOk = false;
                }
            }
            if (!$cleanupOk) {
                $notice .= ' install/ 目录未能完全删除，请手动删除。';
            } elseif ($deleted) {
                $notice = 'install.php 和 install/ 已自动清理，站点可直接使用。';
            } else {
                $notice .= ' install/ 目录已自动删除。';
            }
            
            $success = true;
            $installed = true;
        } catch (Throwable $e) {
            error_log('安装失败: ' . $e->getMessage());
            $errors[] = '安装失败：' . $e->getMessage();
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
    <title>安装向导 - 轩宇-导航</title>
    <style>
        * { box-sizing: border-box; }
        body { margin: 0; padding: 28px 14px; background: #eef2f8; color: #1e293b; font-family: -apple-system, "Segoe UI", "Microsoft YaHei", sans-serif; }
        .wrap { width: min(860px, 100%); margin: 0 auto; }
        .head { margin-bottom: 18px; }
        .head h1 { margin: 0 0 6px; font-size: 24px; }
        .head p { margin: 0; color: #64748b; font-size: 13px; }
        .card { background: #fff; border: 1px solid #e3eaf4; border-radius: 16px; box-shadow: 0 18px 40px -28px rgba(15, 23, 42, .35); overflow: hidden; }
        .checks { display: flex; flex-wrap: wrap; gap: 8px; padding: 14px 20px; background: #f8fafc; border-bottom: 1px solid #edf2f8; }
        .check { font-size: 12px; padding: 5px 9px; border-radius: 999px; background: #eef4ff; color: #1d4ed8; }
        .check.bad { background: #fee2e2; color: #b91c1c; }
        form { padding: 22px; }
        h2 { margin: 22px 0 10px; font-size: 15px; color: #0f172a; border-left: 3px solid #2563eb; padding-left: 9px; }
        h2:first-child { margin-top: 0; }
        .grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 14px; }
        .full { grid-column: 1 / -1; }
        label { display: block; margin-bottom: 6px; font-size: 13px; font-weight: 600; }
        input { width: 100%; padding: 10px 12px; border: 1px solid #d8e1ee; border-radius: 9px; font-size: 14px; outline: none; background: #fff; }
        input:focus { border-color: #2563eb; box-shadow: 0 0 0 3px rgba(37, 99, 235, .12); }
        .hint { margin-top: 5px; color: #94a3b8; font-size: 12px; }
        button { width: 100%; margin-top: 24px; padding: 12px; border: 0; border-radius: 10px; background: #2563eb; color: #fff; font-size: 15px; font-weight: 700; cursor: pointer; }
        button:hover { background: #1d4ed8; }
        .msg { margin: 0 22px 0; padding: 11px 13px; border-radius: 10px; font-size: 13px; line-height: 1.7; }
        .msg.err { background: #fdecec; color: #b91c1c; }
        .msg.ok { background: #e8f7ee; color: #166534; }
        .msg.warn { background: #fff7e6; color: #92400e; }
        .msg ul { margin: 6px 0 0 18px; padding: 0; }
        .done { padding: 30px 24px; text-align: center; }
        .done h2 { border: 0; padding: 0; font-size: 21px; }
        .done p { color: #64748b; font-size: 14px; line-height: 1.8; }
        .done a { display: inline-block; margin: 8px 6px; padding: 10px 16px; border-radius: 9px; background: #2563eb; color: #fff; text-decoration: none; font-size: 14px; }
        .done a.alt { background: #eef4ff; color: #1d4ed8; }
        code { background: #f1f5f9; padding: 1px 6px; border-radius: 4px; }
        @media (max-width: 620px) { .grid { grid-template-columns: 1fr; } .full { grid-column: auto; } }
    </style>
</head>
<body>
<div class="wrap">
    <div class="head">
        <h1>轩宇-导航 · 安装向导</h1>
        <p>填写数据库、站点和管理员信息，点击安装即可。安装完成后请删除 <code>install.php</code>。</p>
    </div>

    <div class="card">
        <div class="checks">
            <?php foreach ($checks as $name => $ok): ?>
                <span class="check <?php echo $ok ? '' : 'bad'; ?>"><?php echo $ok ? '✓' : '✗'; ?> <?php echo nav_e($name); ?></span>
            <?php endforeach; ?>
        </div>

        <?php if ($success): ?>
            <div class="done">
                <h2>安装完成</h2>
                <p>管理员和站点已创建，数据库配置已写入。<br>请立即删除 <code>install.php</code> 和 <code>install/</code> 目录，然后登录后台。</p>
                <?php if ($notice !== ''): ?>
                    <p class="msg warn" style="text-align:left;"><?php echo nav_e($notice); ?></p>
                <?php endif; ?>
                <a href="admin/login.php">进入后台登录</a>
                <a class="alt" href="index.php">访问首页</a>
            </div>
        <?php elseif ($installed): ?>
            <div class="done">
                <h2>系统已安装</h2>
                <p>检测到 <code>config/installed.lock</code>，安装向导已锁定。<br>请删除 <code>install.php</code> 和 <code>install/</code> 目录。</p>
                <a href="admin/login.php">进入后台登录</a>
                <a class="alt" href="index.php">访问首页</a>
            </div>
        <?php else: ?>
            <?php if (!empty($errors)): ?>
                <div class="msg err">
                    <strong>请检查以下问题：</strong>
                    <ul>
                        <?php foreach ($errors as $error): ?>
                            <li><?php echo nav_e($error); ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>

            <form method="POST" autocomplete="off">
                <h2>数据库</h2>
                <div class="grid">
                    <div>
                        <label for="db_host">数据库主机</label>
                        <input id="db_host" name="db_host" type="text" required value="<?php echo nav_e($values['db_host']); ?>" placeholder="127.0.0.1">
                    </div>
                    <div>
                        <label for="db_port">端口</label>
                        <input id="db_port" name="db_port" type="number" min="1" max="65535" required value="<?php echo nav_e($values['db_port']); ?>">
                    </div>
                    <div>
                        <label for="db_name">数据库名</label>
                        <input id="db_name" name="db_name" type="text" required value="<?php echo nav_e($values['db_name']); ?>" placeholder="xuanyu_nav">
                        <div class="hint">不存在时会尝试自动创建；无创建权限时请先在主机面板建库。</div>
                    </div>
                    <div>
                        <label for="db_user">数据库用户名</label>
                        <input id="db_user" name="db_user" type="text" required value="<?php echo nav_e($values['db_user']); ?>">
                    </div>
                    <div class="full">
                        <label for="db_pass">数据库密码</label>
                        <input id="db_pass" name="db_pass" type="password" autocomplete="new-password">
                    </div>
                </div>

                <h2>站点</h2>
                <div class="grid">
                    <div class="full">
                        <label for="site_title">站点名称</label>
                        <input id="site_title" name="site_title" type="text" required maxlength="100" value="<?php echo nav_e($values['site_title']); ?>">
                    </div>
                </div>

                <h2>管理员</h2>
                <div class="grid">
                    <div>
                        <label for="admin_user">管理员用户名</label>
                        <input id="admin_user" name="admin_user" type="text" required minlength="3" maxlength="50" value="<?php echo nav_e($values['admin_user']); ?>">
                    </div>
                    <div>
                        <label for="admin_pass">管理员密码</label>
                        <input id="admin_pass" name="admin_pass" type="password" required minlength="8" autocomplete="new-password">
                    </div>
                    <div class="full">
                        <label for="admin_confirm">确认管理员密码</label>
                        <input id="admin_confirm" name="admin_confirm" type="password" required minlength="8" autocomplete="new-password">
                    </div>
                </div>

                <button type="submit">开始安装</button>
            </form>
        <?php endif; ?>
    </div>
</div>
</body>
</html>
