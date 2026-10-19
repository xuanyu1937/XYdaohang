<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_once '../includes/functions.php';

$functions = new Functions();

if (!$functions->isLoggedIn()) {
    $functions->redirect('login.php');
}

$db = Database::getInstance()->getConnection();
$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $functions->requireSameOrigin();
}

// 登录来源：admins 表（默认）或 users 表中被授予管理权限的账号
$adminSource = (string) ($_SESSION['admin_source'] ?? 'admins');
$isUserSource = $adminSource === 'users';
$adminUserId = (int) ($_SESSION['admin_user_id'] ?? 0);

if ($isUserSource && $adminUserId > 0) {
    $stmt = $db->prepare("SELECT * FROM users WHERE id = ?");
    $stmt->execute([$adminUserId]);
    $admin = $stmt->fetch();

    if ($admin) {
        // 统一字段名，后续展示逻辑无需再区分来源
        $admin['password'] = (string) $admin['password_hash'];
    }
} else {
    $stmt = $db->prepare("SELECT * FROM admins WHERE id = ?");
    $stmt->execute([$_SESSION['admin_id']]);
    $admin = $stmt->fetch();
}

if (!$admin) {
    $functions->redirect('logout.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['change_password'])) {
    $currentPassword = (string) ($_POST['current_password'] ?? '');
    $newPassword = (string) ($_POST['new_password'] ?? '');
    $confirmPassword = (string) ($_POST['confirm_password'] ?? '');
    
    $stored = (string) $admin['password'];
    $info = password_get_info($stored);
    $passwordValid = (($info['algo'] ?? 0) !== 0 && password_verify($currentPassword, $stored))
        || (($info['algo'] ?? 0) === 0 && (
            hash_equals($stored, $currentPassword)
            || (strlen($stored) === 32 && hash_equals(strtolower($stored), md5($currentPassword)))
        ));
    
    if (!$passwordValid) {
        $error = '当前密码不正确';
    } elseif (empty($newPassword)) {
        $error = '新密码不能为空';
    } elseif (strlen($newPassword) < 8) {
        $error = '新密码长度至少8位';
    } elseif ($newPassword !== $confirmPassword) {
        $error = '两次输入的新密码不一致';
    } else {
        $saved = false;

        if ($isUserSource) {
            // users 表只有 password_hash 一列，直接用 password_hash 写入
            $newHash = password_hash($newPassword, PASSWORD_DEFAULT);

            if (is_string($newHash) && $newHash !== '') {
                $updateStmt = $db->prepare("UPDATE users SET password_hash = ? WHERE id = ?");
                $saved = $updateStmt->execute([$newHash, $adminUserId]);
            }
        } else {
            $saved = $functions->updateAdminPassword((int) $_SESSION['admin_id'], $newPassword);
        }

        if ($saved) {
            $message = '密码修改成功';
            $stmt->execute([$isUserSource ? $adminUserId : $_SESSION['admin_id']]);
            $admin = $stmt->fetch();

            if ($admin && $isUserSource) {
                $admin['password'] = (string) $admin['password_hash'];
            }
        } else {
            $error = '密码修改失败，请稍后重试（admins 表请确认密码列已扩展为 varchar(255)）';
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_profile'])) {
    $username = trim((string) ($_POST['username'] ?? ''));
    
    if (empty($username)) {
        $error = '用户名不能为空';
    } elseif (strlen($username) < 3) {
        $error = '用户名长度至少3位';
    } else {
        $checkStmt = $isUserSource
            ? $db->prepare("SELECT id FROM users WHERE username = ? AND id != ?")
            : $db->prepare("SELECT id FROM admins WHERE username = ? AND id != ?");
        $checkStmt->execute([$username, $isUserSource ? $adminUserId : $_SESSION['admin_id']]);
        
        if ($checkStmt->fetch()) {
            $error = '用户名已存在';
        } else {
            $updateStmt = $isUserSource
                ? $db->prepare("UPDATE users SET username = ? WHERE id = ?")
                : $db->prepare("UPDATE admins SET username = ? WHERE id = ?");

            $targetId = $isUserSource ? $adminUserId : (int) $_SESSION['admin_id'];
            
            if ($updateStmt->execute([$username, $targetId])) {
                $message = '管理员信息更新成功';
                $_SESSION['admin_username'] = $username;

                if ($isUserSource) {
                    $_SESSION['user_username'] = $username;
                }

                $stmt->execute([$targetId]);
                $admin = $stmt->fetch();

                if ($admin && $isUserSource) {
                    $admin['password'] = (string) $admin['password_hash'];
                }
            } else {
                $error = '更新失败，请稍后重试';
            }
        }
    }
}

$currentIp = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
$loginCountStmt = $db->prepare("SELECT COUNT(*) as count FROM visitor_stats WHERE ip_address = ?");
$loginCountStmt->execute([$currentIp]);
$loginCount = $loginCountStmt->fetch()['count'] ?? 0;

$today = date('Y-m-d');
$todayCountStmt = $db->prepare("SELECT COUNT(*) as count FROM visitor_stats WHERE ip_address = ? AND visit_date = ?");
$todayCountStmt->execute([$currentIp, $today]);
$todayCount = $todayCountStmt->fetch()['count'] ?? 0;
$daysSinceCreation = max(0, (int) floor((time() - strtotime((string) $admin['created_at'])) / 86400));

$recentStmt = $db->prepare("
    SELECT * FROM visitor_stats 
    WHERE ip_address = ? 
    ORDER BY visit_time DESC 
    LIMIT 5
");
$recentStmt->execute([$_SERVER['REMOTE_ADDR']]);
$recentLogins = $recentStmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>管理员信息 - 后台管理</title>
    <link href="../assets/css/bootstrap.css" rel="stylesheet">
    <link href="../assets/css/font-awesome.min.css" rel="stylesheet" onerror="this.onerror=null;this.href='https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css';">
    <link href="../assets/sweetalert2/sweetalert2.min.css" rel="stylesheet">
    <style>
        :root {
            --primary-gradient: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            --sidebar-gradient: linear-gradient(180deg, #1a237e 0%, #311b92 100%);
            --card-shadow: 0 8px 30px rgba(0, 0, 0, 0.08);
            --hover-shadow: 0 12px 40px rgba(0, 0, 0, 0.12);
            --transition-smooth: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }
        
        body {
            background-color: #f8fafc;
            font-family: 'Segoe UI', 'Microsoft YaHei', sans-serif;
            overflow-x: hidden;
        }
        
        .main-content-wrapper {
            margin-left: 260px;
            transition: var(--transition-smooth);
            min-height: 100vh;
            padding: 20px;
        }
        
        .main-content-wrapper.expanded {
            margin-left: 70px;
        }
        
        @media (max-width: 768px) {
            .main-content-wrapper {
                margin-left: 0;
                width: 100%;
                padding: 15px;
            }
        }
        
        .dashboard-card {
            border: none;
            border-radius: 16px;
            box-shadow: var(--card-shadow);
            background: white;
            margin-bottom: 1.5rem;
            overflow: hidden;
        }
        
        .dashboard-card .card-header {
            background: white;
            border-bottom: 1px solid #e2e8f0;
            padding: 1.25rem 1.5rem;
            border-radius: 16px 16px 0 0;
        }
        
        .dashboard-card .card-header h5 {
            color: #1e293b;
            font-weight: 600;
            margin: 0;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .dashboard-card .card-body {
            padding: 1.5rem;
        }
        
        .navbar-top {
            background: white;
            padding: 1rem 1.5rem;
            border-radius: 16px;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.05);
            margin-bottom: 2rem;
            border: 1px solid #e2e8f0;
        }
        
        .navbar-top .navbar-brand {
            font-weight: 600;
            color: #1e293b;
            font-size: 1.25rem;
        }
        
        .user-info {
            display: flex;
            align-items: center;
            gap: 1rem;
        }
        
        .user-avatar {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: var(--primary-gradient);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: 600;
        }
        
        .stat-card {
            border: none;
            border-radius: 16px;
            box-shadow: var(--card-shadow);
            transition: var(--transition-smooth);
            overflow: hidden;
            background: white;
            position: relative;
            height: 100%;
        }
        
        .stat-card:hover {
            transform: translateY(-8px);
            box-shadow: var(--hover-shadow);
        }
        
        .stat-card .card-body {
            padding: 1.5rem;
        }
        
        .stat-card .icon-wrapper {
            width: 56px;
            height: 56px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
            margin-bottom: 1rem;
        }
        
        .stat-card.border-primary .icon-wrapper {
            background: linear-gradient(135deg, #e0e7ff, #c7d2fe);
            color: #4f46e5;
        }
        
        .stat-card.border-success .icon-wrapper {
            background: linear-gradient(135deg, #d1fae5, #a7f3d0);
            color: #10b981;
        }
        
        .stat-card.border-info .icon-wrapper {
            background: linear-gradient(135deg, #cffafe, #a5f3fc);
            color: #06b6d4;
        }
        
        .stat-card.border-warning .icon-wrapper {
            background: linear-gradient(135deg, #fef3c7, #fde68a);
            color: #f59e0b;
        }
        
        .stat-card h3 {
            font-weight: 700;
            margin: 0.5rem 0;
            color: #1e293b;
        }
        
        .stat-card .card-subtitle {
            font-size: 0.875rem;
            color: #64748b;
            font-weight: 500;
        }
        
        .profile-avatar {
            width: 120px;
            height: 120px;
            border-radius: 50%;
            background: var(--primary-gradient);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 3rem;
            font-weight: 600;
            margin: 0 auto 1.5rem;
            box-shadow: 0 8px 25px rgba(59, 130, 246, 0.3);
            border: 4px solid white;
        }
        
        .form-label {
            font-weight: 500;
            color: #475569;
            margin-bottom: 0.5rem;
        }
        
        .form-control {
            border: 2px solid #e2e8f0;
            border-radius: 10px;
            padding: 0.75rem 1rem;
            transition: var(--transition-smooth);
        }
        
        .form-control:focus {
            border-color: #3b82f6;
            box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
        }
        
        .input-group-text {
            background: #f1f5f9;
            border: 2px solid #e2e8f0;
            border-right: none;
            color: #64748b;
        }
        
        .btn-primary {
            background: var(--primary-gradient);
            border: none;
            border-radius: 10px;
            padding: 0.75rem 2rem;
            font-weight: 500;
            transition: var(--transition-smooth);
        }
        
        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(59, 130, 246, 0.3);
        }
        
        .btn-success {
            background: linear-gradient(135deg, #10b981 0%, #34d399 100%);
            border: none;
            border-radius: 10px;
            padding: 0.75rem 2rem;
            font-weight: 500;
            transition: var(--transition-smooth);
        }
        
        .btn-success:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(16, 185, 129, 0.3);
        }
        
        .password-toggle {
            border: 2px solid #e2e8f0;
            border-left: none;
            background: #f8fafc;
            color: #64748b;
            transition: var(--transition-smooth);
        }
        
        .password-toggle:hover {
            background: #e2e8f0;
            color: #475569;
        }
        
        .table-sm th {
            font-weight: 600;
            color: #475569;
            border-bottom-width: 1px;
        }
        
        .table-sm td {
            color: #64748b;
            vertical-align: middle;
        }
        
        .alert-warning {
            background: linear-gradient(135deg, #fef3c7, #fde68a);
            border: none;
            border-radius: 10px;
            border-left: 4px solid #f59e0b;
        }
        
        .badge {
            padding: 0.35rem 0.75rem;
            border-radius: 20px;
            font-weight: 500;
        }
        
        .password-strength {
            height: 4px;
            border-radius: 2px;
            margin-top: 0.5rem;
            overflow: hidden;
        }
        
        .strength-weak { width: 25%; background: #ef4444; }
        .strength-medium { width: 50%; background: #f59e0b; }
        .strength-good { width: 75%; background: #3b82f6; }
        .strength-strong { width: 100%; background: #10b981; }
    </style>
    <link href="../assets/css/admin.css?v=1.1.3" rel="stylesheet">
</head>
<body>
    <?php include 'sidebar.php'; ?>
    
    <div class="main-content-wrapper" id="mainContentWrapper">
        <nav class="navbar-top">
            <div class="container-fluid px-0">
                <span class="navbar-brand">
                    <i class="fas fa-user me-2"></i>管理员信息
                </span>
                <div class="user-info">
                    <span class="text-muted me-3" id="currentTime"></span>
                    <div class="user-avatar" data-bs-toggle="tooltip" title="<?php echo $functions->escape($_SESSION['admin_username'] ?? ''); ?>">
                        <?php echo $functions->escape(strtoupper(substr((string) ($_SESSION['admin_username'] ?? ''), 0, 1))); ?>
                    </div>
                </div>
            </div>
        </nav>
        
        <?php if ($message): ?>
            <div class="alert alert-success alert-dismissible fade show mb-4" role="alert">
                <div class="d-flex align-items-center">
                    <i class="fas fa-check-circle fa-lg me-3"></i>
                    <div>
                        <h6 class="alert-heading mb-1">操作成功！</h6>
                        <p class="mb-0"><?php echo $functions->escape($message); ?></p>
                    </div>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php endif; ?>
        
        <?php if ($error): ?>
            <div class="alert alert-danger alert-dismissible fade show mb-4" role="alert">
                <div class="d-flex align-items-center">
                    <i class="fas fa-exclamation-triangle fa-lg me-3"></i>
                    <div>
                        <h6 class="alert-heading mb-1">操作失败！</h6>
                        <p class="mb-0"><?php echo $functions->escape($error); ?></p>
                    </div>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php endif; ?>
        
        <div class="row g-4">
            <div class="col-lg-6">
                <div class="dashboard-card">
                    <div class="card-header">
                        <h5><i class="fas fa-user-circle me-2"></i>基本信息</h5>
                    </div>
                    <div class="card-body">
                        <div class="text-center mb-4">
                            <div class="profile-avatar">
                                <?php 
                                    $username = (string) ($_SESSION['admin_username'] ?? '');
                                    echo $functions->escape(strtoupper(substr($username, 0, 2))); 
                                ?>
                            </div>
                            <h4 class="mb-1"><?php echo $functions->escape($username); ?></h4>
                            <p class="text-muted">系统管理员</p>
                            <span class="badge bg-primary">
                                <i class="fas fa-shield-alt me-1"></i>超级管理员
                            </span>
                        </div>
                        
                        <form method="POST" id="profileForm">
                            <div class="mb-4">
                                <label class="form-label">用户名 <span class="text-danger">*</span></label>
                                <div class="input-group">
                                    <span class="input-group-text"><i class="fas fa-user"></i></span>
                                    <input type="text" 
                                           name="username" 
                                           class="form-control" 
                                           value="<?php echo $functions->escape($_SESSION['admin_username'] ?? ''); ?>" 
                                           required
                                           minlength="3"
                                           placeholder="请输入用户名">
                                </div>
                                <div class="form-text">用户名长度至少3位</div>
                            </div>
                            
                            <div class="mb-4">
                                <label class="form-label">账户密码</label>
                                <div class="form-text">密码仅以加密形式存储，不在页面显示；如需修改请使用右侧「修改密码」。</div>
                            </div>
                            
                            <div class="d-grid">
                                <button type="submit" 
                                        name="update_profile" 
                                        class="btn btn-primary">
                                    <i class="fas fa-save me-2"></i>更新基本信息
                                </button>
                            </div>
                        </form>
                        
                        <div class="mt-4 pt-4 border-top">
                            <h6 class="fw-semibold mb-3"><i class="fas fa-info-circle me-2"></i>账户信息</h6>
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <div class="p-3 bg-light rounded">
                                        <small class="text-muted d-block mb-1">用户ID</small>
                                        <span class="fw-semibold">#<?php echo (int) $_SESSION['admin_id']; ?></span>
                                    </div>
                                </div>
                                <div class="col-md-6 mb-3">
                                    <div class="p-3 bg-light rounded">
                                        <small class="text-muted d-block mb-1">注册时间</small>
                                        <span class="fw-semibold"><?php echo date('Y年m月d日', strtotime($admin['created_at'])); ?></span>
                                    </div>
                                </div>
                                <div class="col-md-6 mb-3">
                                    <div class="p-3 bg-light rounded">
                                        <small class="text-muted d-block mb-1">登录IP</small>
                                        <span class="fw-semibold"><?php echo $_SERVER['REMOTE_ADDR']; ?></span>
                                    </div>
                                </div>
                                <div class="col-md-6 mb-3">
                                    <div class="p-3 bg-light rounded">
                                        <small class="text-muted d-block mb-1">登录时间</small>
                                        <span class="fw-semibold">
                                            <?php 
                                                if (isset($_SESSION['login_time'])) {
                                                    echo $_SESSION['login_time'];
                                                } else {
                                                    echo date('Y年m月d日 H:i:s');
                                                }
                                            ?>
                                        </span>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="col-lg-6">
                <div class="dashboard-card">
                    <div class="card-header">
                        <h5><i class="fas fa-key me-2"></i>修改密码</h5>
                    </div>
                    <div class="card-body">
                        <form method="POST" id="passwordForm">
                            <div class="mb-4">
                                <label class="form-label">当前密码 <span class="text-danger">*</span></label>
                                <div class="input-group">
                                    <span class="input-group-text"><i class="fas fa-lock"></i></span>
                                    <input type="password" 
                                           name="current_password" 
                                           class="form-control" 
                                           required
                                           id="currentPasswordInput"
                                           placeholder="请输入当前密码">
                                    <button type="button" 
                                            class="btn btn-outline-secondary password-toggle"
                                            onclick="togglePassword('currentPasswordInput')">
                                        <i class="fas fa-eye"></i>
                                    </button>
                                </div>
                            </div>
                            
                            <div class="mb-4">
                                <label class="form-label">新密码 <span class="text-danger">*</span></label>
                                <div class="input-group">
                                    <span class="input-group-text"><i class="fas fa-key"></i></span>
                                    <input type="password" 
                                           name="new_password" 
                                           class="form-control" 
                                           required
                                           minlength="8"
                                           id="newPasswordInput"
                                           placeholder="请输入新密码"
                                           oninput="checkPasswordStrength()">
                                    <button type="button" 
                                            class="btn btn-outline-secondary password-toggle"
                                            onclick="togglePassword('newPasswordInput')">
                                        <i class="fas fa-eye"></i>
                                    </button>
                                </div>
                                <div class="form-text">密码长度至少8位</div>
                                <div class="password-strength d-none" id="passwordStrengthBar"></div>
                                <small class="text-muted" id="passwordStrengthText"></small>
                            </div>
                            
                            <div class="mb-4">
                                <label class="form-label">确认新密码 <span class="text-danger">*</span></label>
                                <div class="input-group">
                                    <span class="input-group-text"><i class="fas fa-key"></i></span>
                                    <input type="password" 
                                           name="confirm_password" 
                                           class="form-control" 
                                           required
                                           minlength="8"
                                           id="confirmPasswordInput"
                                           placeholder="请再次输入新密码">
                                    <button type="button" 
                                            class="btn btn-outline-secondary password-toggle"
                                            onclick="togglePassword('confirmPasswordInput')">
                                        <i class="fas fa-eye"></i>
                                    </button>
                                </div>
                                <small class="text-muted" id="passwordMatchText"></small>
                            </div>
                            
                            <div class="d-grid">
                                <button type="submit" 
                                        name="change_password" 
                                        class="btn btn-success">
                                    <i class="fas fa-key me-2"></i>修改密码
                                </button>
                            </div>
                        </form>
                        
                        <div class="mt-4 pt-4 border-top">
                            <h6 class="fw-semibold mb-3"><i class="fas fa-exclamation-triangle me-2 text-warning"></i>安全警告</h6>
                            <div class="alert alert-warning mb-4">
                                <p class="mb-2"><strong>密码以加密形式存储，页面不会显示明文。</strong></p>
                                <ul class="mb-0 small">
                                    <li>请设置复杂密码以提高安全性</li>
                                    <li>不要在多个网站使用相同密码</li>
                                    <li>定期更换密码</li>
                                    <li>不要将密码告诉他人</li>
                                </ul>
                            </div>
                            
                            <h6 class="fw-semibold mb-3"><i class="fas fa-lightbulb me-2 text-info"></i>密码强度建议</h6>
                            <ul class="small text-muted">
                                <li>使用至少8位字符</li>
                                <li>包含大写字母、小写字母、数字</li>
                                <li>可以添加特殊字符如 !@#$%^&*</li>
                                <li>避免使用生日、电话号码等简单密码</li>
                            </ul>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="col-12">
                <div class="row g-4">
                    <div class="col-md-3">
                        <div class="stat-card border-primary">
                            <div class="card-body text-center">
                                <div class="icon-wrapper">
                                    <i class="fas fa-sign-in-alt"></i>
                                </div>
                                <h3 class="text-primary"><?php echo $loginCount; ?></h3>
                                <p class="card-subtitle mb-0">总登录次数</p>
                            </div>
                        </div>
                    </div>
                    
                    <div class="col-md-3">
                        <div class="stat-card border-success">
                            <div class="card-body text-center">
                                <div class="icon-wrapper">
                                    <i class="fas fa-calendar-day"></i>
                                </div>
                                <h3 class="text-success"><?php echo $todayCount; ?></h3>
                                <p class="card-subtitle mb-0">今日登录</p>
                            </div>
                        </div>
                    </div>
                    
                    <div class="col-md-3">
                        <div class="stat-card border-info">
                            <div class="card-body text-center">
                                <div class="icon-wrapper">
                                    <i class="fas fa-clock"></i>
                                </div>
                                <h3 class="text-info" id="realTime"></h3>
                                <p class="card-subtitle mb-0">当前时间</p>
                            </div>
                        </div>
                    </div>
                    
                    <div class="col-md-3">
                        <div class="stat-card border-warning">
                            <div class="card-body text-center">
                                <div class="icon-wrapper">
                                    <i class="fas fa-shield-alt"></i>
                                </div>
                                <h3 class="text-warning"><?php echo $daysSinceCreation; ?></h3>
                                <p class="card-subtitle mb-0">账户使用天数</p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="col-12">
                <div class="dashboard-card">
                    <div class="card-header">
                        <h5><i class="fas fa-history me-2"></i>最近登录记录</h5>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-sm">
                                <thead>
                                    <tr>
                                        <th>登录时间</th>
                                        <th>IP地址</th>
                                        <th>浏览器/设备</th>
                                        <th>状态</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr>
                                        <td>
                                            <div class="fw-semibold"><?php echo date('Y-m-d'); ?></div>
                                            <small class="text-muted"><?php echo date('H:i:s'); ?></small>
                                        </td>
                                        <td>
                                            <span class="badge bg-primary">
                                                <i class="fas fa-network-wired me-1"></i>
                                                <?php echo $_SERVER['REMOTE_ADDR']; ?>
                                            </span>
                                        </td>
                                        <td>
                                            <small><?php echo htmlspecialchars(substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? '未知'), 0, 50), ENT_QUOTES, 'UTF-8'); ?>...</small>
                                        </td>
                                        <td>
                                            <span class="badge bg-success">
                                                <i class="fas fa-circle me-1"></i>在线
                                            </span>
                                        </td>
                                    </tr>
                                    <?php foreach ($recentLogins as $login): ?>
                                    <tr>
                                        <td>
                                            <div class="fw-semibold"><?php echo date('Y-m-d', strtotime($login['visit_time'])); ?></div>
                                            <small class="text-muted"><?php echo date('H:i:s', strtotime($login['visit_time'])); ?></small>
                                        </td>
                                        <td>
                                            <span class="badge bg-secondary">
                                                <i class="fas fa-network-wired me-1"></i>
                                                <?php echo $login['ip_address']; ?>
                                            </span>
                                        </td>
                                        <td>
                                            <small><?php echo htmlspecialchars(substr((string) ($login['user_agent'] ?? '未知'), 0, 50), ENT_QUOTES, 'UTF-8'); ?>...</small>
                                        </td>
                                        <td>
                                            <span class="badge bg-secondary">
                                                <i class="fas fa-circle me-1"></i>历史
                                            </span>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        
                        <div class="text-center mt-4">
                            <a href="#" class="btn btn-outline-primary btn-sm">
                                <i class="fas fa-list me-1"></i>查看完整登录记录
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <script src="../assets/js/jquery3.7.1.js"></script>
    <script src="../assets/js/bootstrap.bundle.min.js"></script>
    <script src="../assets/sweetalert2/sweetalert2.min.js"></script>
    <?php require_once __DIR__ . '/../includes/asset_fallbacks.php'; ?>
    <script>
    function updateTime() {
        const now = new Date();
        
        const timeStr = now.getFullYear() + '年' + 
                      (now.getMonth() + 1) + '月' + 
                      now.getDate() + '日 ' + 
                      now.getHours().toString().padStart(2, '0') + ':' + 
                      now.getMinutes().toString().padStart(2, '0') + ':' + 
                      now.getSeconds().toString().padStart(2, '0');
        $('#currentTime').text(timeStr);
        
        const realTime = now.getHours().toString().padStart(2, '0') + ':' + 
                        now.getMinutes().toString().padStart(2, '0') + ':' + 
                        now.getSeconds().toString().padStart(2, '0');
        $('#realTime').text(realTime);
    }
    
    function togglePassword(inputId) {
        const input = document.getElementById(inputId);
        const button = input.parentNode.querySelector('.password-toggle');
        const icon = button.querySelector('i');
        
        if (input.type === 'password' || input.type === 'text') {
            input.type = input.type === 'password' ? 'text' : 'password';
            icon.className = input.type === 'text' ? 'fas fa-eye-slash' : 'fas fa-eye';
        }
    }
    
    function checkPasswordStrength() {
        const password = $('#newPasswordInput').val();
        const strengthBar = $('#passwordStrengthBar');
        const strengthText = $('#passwordStrengthText');
        
        if (password.length === 0) {
            strengthBar.addClass('d-none');
            strengthText.text('');
            return;
        }
        
        let strength = 0;
        const messages = [];
        
        if (password.length >= 8) strength += 1;
        else messages.push('长度至少8位');
        
        if (/[a-z]/.test(password)) strength += 1;
        else messages.push('包含小写字母');
        
        if (/[A-Z]/.test(password)) strength += 1;
        else messages.push('包含大写字母');
        
        if (/[0-9]/.test(password)) strength += 1;
        else messages.push('包含数字');
        
        if (/[^A-Za-z0-9]/.test(password)) strength += 1;
        else messages.push('包含特殊字符');
        
        strengthBar.removeClass('d-none');
        strengthBar.removeClass('strength-weak strength-medium strength-good strength-strong');
        
        if (strength <= 2) {
            strengthBar.addClass('strength-weak');
            strengthText.text('密码强度：弱 ' + (messages.length > 0 ? '(' + messages.join(', ') + ')' : '')).addClass('text-danger').removeClass('text-warning text-info text-success');
        } else if (strength === 3) {
            strengthBar.addClass('strength-medium');
            strengthText.text('密码强度：中等').addClass('text-warning').removeClass('text-danger text-info text-success');
        } else if (strength === 4) {
            strengthBar.addClass('strength-good');
            strengthText.text('密码强度：良好').addClass('text-info').removeClass('text-danger text-warning text-success');
        } else {
            strengthBar.addClass('strength-strong');
            strengthText.text('密码强度：优秀').addClass('text-success').removeClass('text-danger text-warning text-info');
        }
        
        checkPasswordMatch();
    }
    
    function checkPasswordMatch() {
        const password = $('#newPasswordInput').val();
        const confirmPassword = $('#confirmPasswordInput').val();
        const matchText = $('#passwordMatchText');
        
        if (confirmPassword.length === 0) {
            matchText.text('');
            return;
        }
        
        if (password === confirmPassword) {
            matchText.text('密码匹配 ✓').addClass('text-success').removeClass('text-danger');
        } else {
            matchText.text('密码不匹配 ✗').addClass('text-danger').removeClass('text-success');
        }
    }
    
    $(document).ready(function() {
        updateTime();
        setInterval(updateTime, 1000);
        
        const tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
        tooltipTriggerList.map(function (tooltipTriggerEl) {
            return new bootstrap.Tooltip(tooltipTriggerEl);
        });
        
        $('#newPasswordInput').on('input', checkPasswordStrength);
        $('#confirmPasswordInput').on('input', checkPasswordMatch);
        
        $('.stat-card, .dashboard-card').hover(
            function() {
                $(this).css('transform', 'translateY(-2px)');
            },
            function() {
                $(this).css('transform', 'translateY(0)');
            }
        );
        
        $('#passwordForm').submit(function(e) {
            const currentPassword = $('#currentPasswordInput').val();
            const newPassword = $('#newPasswordInput').val();
            const confirmPassword = $('#confirmPasswordInput').val();
            
            if (!currentPassword) {
                e.preventDefault();
                Swal.fire({
                    icon: 'error',
                    title: '请填写当前密码',
                    text: '当前密码不能为空'
                });
                return;
            }
            
            if (newPassword.length < 8) {
                e.preventDefault();
                Swal.fire({
                    icon: 'error',
                    title: '密码太短',
                    text: '新密码长度至少8位'
                });
                return;
            }
            
            if (newPassword !== confirmPassword) {
                e.preventDefault();
                Swal.fire({
                    icon: 'error',
                    title: '密码不匹配',
                    text: '两次输入的新密码不一致'
                });
                return;
            }
        });
        
        $('#profileForm').submit(function(e) {
            const username = $('input[name="username"]').val().trim();
            
            if (username.length < 3) {
                e.preventDefault();
                Swal.fire({
                    icon: 'error',
                    title: '用户名太短',
                    text: '用户名长度至少3位'
                });
                return;
            }
        });
    });
    </script>
</body>
</html>