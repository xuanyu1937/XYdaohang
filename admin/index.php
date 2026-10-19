<?php
/**
 * 轩宇-导航 · 后台仪表盘
 */
require_once __DIR__ . '/../includes/bootstrap.php';
require_once '../includes/functions.php';

$functions = new Functions();

if (!$functions->isLoggedIn()) {
    $functions->redirect('login.php');
}

$db = Database::getInstance()->getConnection();

$stats = $functions->getSystemStats();

$todayVisits = $functions->getTodayVisits();
$todayVisitors = $functions->getTodayVisitors();

$weekStart = date('Y-m-d', strtotime('-7 days'));
$weekStmt = $db->prepare("SELECT DATE(visit_date) as date, COUNT(*) as visits, COUNT(DISTINCT ip_address) as visitors 
                          FROM visitor_stats 
                          WHERE visit_date >= ? 
                          GROUP BY DATE(visit_date) 
                          ORDER BY date");
$weekStmt->execute([$weekStart]);
$weekStats = $weekStmt->fetchAll();

$recentApplications = $functions->getLatestApplications(5);

$popularCategories = $functions->getPopularCategories(5);

// 未读留言数量
$unreadMessages = 0;
try {
    $msgStmt = $db->query("SELECT COUNT(*) AS total FROM contact_messages WHERE is_read = 0");
    $unreadMessages = (int) ($msgStmt->fetch()['total'] ?? 0);
} catch (Throwable $error) {
    // contact_messages 表不存在时为 0
}

$phpVersion = phpversion();
$mysqlVersion = $db->query("SELECT VERSION() as version")->fetch()['version'];
$serverSoftware = $_SERVER['SERVER_SOFTWARE'] ?? 'Unknown';
$serverIp = $_SERVER['SERVER_ADDR'] ?? 'Unknown';
$serverOs = PHP_OS;
$maxExecutionTime = ini_get('max_execution_time');
$memoryLimit = ini_get('memory_limit');
$uploadMaxFilesize = ini_get('upload_max_filesize');
$postMaxSize = ini_get('post_max_size');

$loginTime = $_SESSION['login_time'] ?? date('Y-m-d H:i:s');

$currentVersion = defined('APP_VERSION') ? APP_VERSION : '1.1.3';
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>后台管理 - 仪表盘</title>
    <link href="../assets/css/bootstrap.css" rel="stylesheet">
    <link href="../assets/css/font-awesome.min.css" rel="stylesheet" onerror="this.onerror=null;this.href='https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css';">
    <link href="../assets/sweetalert2/sweetalert2.min.css" rel="stylesheet">
    <script src="../assets/js/chart.umd.min.js"></script>
    <script>window.Chart || document.write('<script src="https://cdn.staticfile.org/Chart.js/4.3.0/chart.umd.min.js"><\/script>');</script>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            background: #f0f2f5;
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;
        }
        
        .stat-card {
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            border: none;
            border-radius: 15px;
            overflow: hidden;
        }
        
        .stat-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 30px rgba(0,0,0,0.15);
        }
        
        .stat-card .card-body {
            padding: 1.5rem;
        }
        
        .stat-icon {
            width: 60px;
            height: 60px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 28px;
        }
        
        .stat-primary { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); }
        .stat-success { background: linear-gradient(135deg, #11998e 0%, #38ef7d 100%); }
        .stat-warning { background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%); }
        .stat-info { background: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%); }
        
        .card {
            border: none;
            border-radius: 15px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
            transition: all 0.3s ease;
        }
        
        .card:hover {
            box-shadow: 0 5px 20px rgba(0,0,0,0.1);
        }
        
        .card-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border-radius: 15px 15px 0 0 !important;
            padding: 1rem 1.5rem;
            border: none;
        }
        
        .card-header h5 {
            margin: 0;
            font-weight: 600;
        }
        
        .table-hover tbody tr:hover {
            background-color: rgba(102, 126, 234, 0.05);
            cursor: pointer;
        }
        
        .badge {
            padding: 0.4rem 0.8rem;
            border-radius: 20px;
            font-weight: 500;
        }
        
        .quick-action-btn {
            padding: 0.8rem;
            border-radius: 10px;
            transition: all 0.3s ease;
        }
        
        .quick-action-btn:hover {
            transform: translateY(-3px);
        }
        
        .system-info-item {
            padding: 0.5rem 0;
            border-bottom: 1px solid #e9ecef;
        }
        
        .system-info-item:last-child {
            border-bottom: none;
        }
        
        .system-info-label {
            font-weight: 600;
            color: #495057;
        }
        
        .system-info-value {
            color: #6c757d;
        }
        
        .progress {
            height: 8px;
            border-radius: 4px;
        }
        
        .welcome-text {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }
        
        @keyframes fadeInUp {
            from {
                opacity: 0;
                transform: translateY(20px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        .animate-fade-in {
            animation: fadeInUp 0.5s ease-out;
        }
        
        .swal2-popup {
            font-size: 1rem !important;
        }
    </style>
    <link href="../assets/css/admin.css?v=1.1.3" rel="stylesheet">
</head>
<body>
    <?php include 'sidebar.php'; ?>

    <div class="main-content-wrapper" id="mainContentWrapper">
        <header class="page-header">
            <div class="page-heading">
                <div class="page-breadcrumb">
                    <span>后台管理</span>
                    <i class="fas fa-angle-right"></i>
                    <span>仪表盘</span>
                </div>
                <h1 class="page-title">
                    <span class="title-icon"><i class="fas fa-gauge-high"></i></span>
                    仪表盘
                </h1>
                <p class="page-subtitle">站点数据与运行状态一览，祝你今天工作顺利。</p>
            </div>
            <div class="page-header-meta">
                <div class="d-flex align-items-center">
                            <div class="dropdown">
                                <button class="btn btn-light dropdown-toggle" type="button" id="dropdownMenuButton" data-bs-toggle="dropdown">
                                    <i class="fas fa-user-circle me-1"></i>
                                    <?php echo htmlspecialchars((string) ($_SESSION['admin_username'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>
                                </button>
                                <ul class="dropdown-menu dropdown-menu-end">
                                    <li><a class="dropdown-item" href="profile.php"><i class="fas fa-user me-2"></i>个人信息</a></li>
                                    <li><a class="dropdown-item" href="stats.php"><i class="fas fa-chart-line me-2"></i>数据统计</a></li>
                                    <li><a class="dropdown-item" href="settings.php"><i class="fas fa-cog me-2"></i>系统设置</a></li>
                                    <li><hr class="dropdown-divider"></li>
                                    <li><a class="dropdown-item text-danger" href="logout.php"><i class="fas fa-sign-out-alt me-2"></i>退出登录</a></li>
                                </ul>
                            </div>
                </div>
            </div>
        </header>

        <div class="admin-page-body">
                    <div class="alert alert-light border-0 shadow-sm mb-4 animate-fade-in">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <h4 class="mb-1 welcome-text">欢迎回来，<?php echo htmlspecialchars((string) ($_SESSION['admin_username'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>！</h4>
                                <p class="text-muted mb-0">今天是 <?php echo date('Y年m月d日 l'); ?>，祝你工作愉快！</p>
                            </div>
                            <div class="text-end">
                                <div class="text-muted small">
                                    <i class="fas fa-clock me-1"></i>
                                    <span id="currentDateTime"></span>
                                </div>
                                <div class="text-muted small mt-1">
                                    <i class="fas fa-sign-in-alt me-1"></i>
                                    上次登录: <?php echo date('H:i:s', strtotime($loginTime)); ?>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="row mb-4">
                        <div class="col-md-3 mb-3">
                            <div class="card stat-card">
                                <div class="card-body">
                                    <div class="d-flex justify-content-between align-items-center">
                                        <div>
                                            <h6 class="card-subtitle mb-2 text-muted">今日访问量</h6>
                                            <h3 class="card-title mb-0"><?php echo number_format($todayVisits); ?></h3>
                                            <small class="text-success">
                                                <i class="fas fa-arrow-up"></i> +12%
                                            </small>
                                        </div>
                                        <div class="stat-icon stat-primary">
                                            <i class="fas fa-eye text-white"></i>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <div class="col-md-3 mb-3">
                            <div class="card stat-card">
                                <div class="card-body">
                                    <div class="d-flex justify-content-between align-items-center">
                                        <div>
                                            <h6 class="card-subtitle mb-2 text-muted">今日访客</h6>
                                            <h3 class="card-title mb-0"><?php echo number_format($todayVisitors); ?></h3>
                                            <small class="text-success">
                                                <i class="fas fa-arrow-up"></i> +8%
                                            </small>
                                        </div>
                                        <div class="stat-icon stat-success">
                                            <i class="fas fa-users text-white"></i>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <div class="col-md-3 mb-3">
                            <div class="card stat-card">
                                <div class="card-body">
                                    <div class="d-flex justify-content-between align-items-center">
                                        <div>
                                            <h6 class="card-subtitle mb-2 text-muted">待审核申请</h6>
                                            <h3 class="card-title mb-0"><?php echo number_format($stats['pending_applications']); ?></h3>
                                            <?php if ($stats['pending_applications'] > 0): ?>
                                                <small class="text-warning">
                                                    <i class="fas fa-exclamation-circle"></i> 需要处理
                                                </small>
                                            <?php endif; ?>
                                        </div>
                                        <div class="stat-icon stat-warning">
                                            <i class="fas fa-inbox text-white"></i>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <div class="col-md-3 mb-3">
                            <div class="card stat-card">
                                <div class="card-body">
                                    <div class="d-flex justify-content-between align-items-center">
                                        <div>
                                            <h6 class="card-subtitle mb-2 text-muted">导航总数</h6>
                                            <h3 class="card-title mb-0"><?php echo number_format($stats['total_links']); ?></h3>
                                            <small class="text-info">
                                                <i class="fas fa-link"></i> <?php echo $stats['active_links']; ?> 个活跃
                                            </small>
                                        </div>
                                        <div class="stat-icon stat-info">
                                            <i class="fas fa-link text-white"></i>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- 未读留言提示 -->
                    <?php if ($unreadMessages > 0): ?>
                        <div class="alert alert-warning alert-dismissible fade show mb-4" role="alert">
                            <div>
                                <i class="fas fa-envelope me-2"></i>
                                你有 <strong><?php echo $unreadMessages; ?></strong> 条未读留言，
                                <a href="messages.php" class="alert-link">点击查看</a>
                            </div>
                            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="关闭"></button>
                        </div>
                    <?php endif; ?>
                    
                    <div class="row">
                        <div class="col-md-8 mb-4">
                            <div class="card">
                                <div class="card-header">
                                    <h5 class="mb-0">
                                        <i class="fas fa-chart-line me-2"></i>近7天访问趋势
                                    </h5>
                                </div>
                                <div class="card-body">
                                    <canvas id="visitChart" style="max-height: 300px;"></canvas>
                                </div>
                            </div>
                        </div>
                        
                        <div class="col-md-4 mb-4">
                            <div class="card">
                                <div class="card-header">
                                    <h5 class="mb-0">
                                        <i class="fas fa-bolt me-2"></i>快速操作
                                    </h5>
                                </div>
                                <div class="card-body">
                                    <div class="row g-3">
                                        <div class="col-6">
                                            <a href="categories.php?action=add" class="btn btn-outline-primary quick-action-btn w-100">
                                                <i class="fas fa-plus-circle fa-2x mb-2 d-block"></i>
                                                添加分类
                                            </a>
                                        </div>
                                        <div class="col-6">
                                            <a href="nav_links.php?action=add" class="btn btn-outline-success quick-action-btn w-100">
                                                <i class="fas fa-plus-circle fa-2x mb-2 d-block"></i>
                                                添加导航
                                            </a>
                                        </div>
                                        <div class="col-6">
                                            <a href="applications.php" class="btn btn-outline-warning quick-action-btn w-100">
                                                <i class="fas fa-inbox fa-2x mb-2 d-block"></i>
                                                审核申请
                                            </a>
                                        </div>
                                        <div class="col-6">
                                            <a href="settings.php" class="btn btn-outline-info quick-action-btn w-100">
                                                <i class="fas fa-cog fa-2x mb-2 d-block"></i>
                                                系统设置
                                            </a>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6 mb-4">
                            <div class="card">
                                <div class="card-header">
                                    <h5 class="mb-0">
                                        <i class="fas fa-server me-2"></i>系统信息
                                    </h5>
                                </div>
                                <div class="card-body">
                                    <div class="system-info-item d-flex justify-content-between">
                                        <span class="system-info-label">PHP版本</span>
                                        <span class="system-info-value">
                                            <i class="fab fa-php me-1"></i><?php echo $phpVersion; ?>
                                        </span>
                                    </div>
                                    <div class="system-info-item d-flex justify-content-between">
                                        <span class="system-info-label">MySQL版本</span>
                                        <span class="system-info-value">
                                            <i class="fas fa-database me-1"></i><?php echo $mysqlVersion; ?>
                                        </span>
                                    </div>
                                    <div class="system-info-item d-flex justify-content-between">
                                        <span class="system-info-label">服务器软件</span>
                                        <span class="system-info-value">
                                            <i class="fas fa-server me-1"></i><?php echo $serverSoftware; ?>
                                        </span>
                                    </div>
                                    <div class="system-info-item d-flex justify-content-between">
                                        <span class="system-info-label">服务器IP</span>
                                        <span class="system-info-value">
                                            <i class="fas fa-network-wired me-1"></i><?php echo $serverIp; ?>
                                        </span>
                                    </div>
                                    <div class="system-info-item d-flex justify-content-between">
                                        <span class="system-info-label">操作系统</span>
                                        <span class="system-info-value">
                                            <i class="fab fa-linux me-1"></i><?php echo $serverOs; ?>
                                        </span>
                                    </div>
                                    <div class="system-info-item d-flex justify-content-between">
                                        <span class="system-info-label">最大执行时间</span>
                                        <span class="system-info-value">
                                            <i class="fas fa-hourglass-half me-1"></i><?php echo $maxExecutionTime; ?>秒
                                        </span>
                                    </div>
                                    <div class="system-info-item d-flex justify-content-between">
                                        <span class="system-info-label">内存限制</span>
                                        <span class="system-info-value">
                                            <i class="fas fa-memory me-1"></i><?php echo $memoryLimit; ?>
                                        </span>
                                    </div>
                                    <div class="system-info-item d-flex justify-content-between">
                                        <span class="system-info-label">上传限制</span>
                                        <span class="system-info-value">
                                            <i class="fas fa-upload me-1"></i><?php echo $uploadMaxFilesize; ?>
                                        </span>
                                    </div>
                                    <div class="system-info-item d-flex justify-content-between">
                                        <span class="system-info-label">POST限制</span>
                                        <span class="system-info-value">
                                            <i class="fas fa-envelope me-1"></i><?php echo $postMaxSize; ?>
                                        </span>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <div class="col-md-6 mb-4">
                            <div class="card">
                                <div class="card-header">
                                    <h5 class="mb-0">
                                        <i class="fas fa-info-circle me-2"></i>程序信息
                                    </h5>
                                </div>
                                <div class="card-body">
                                    <div class="text-center mb-4">
                                        <i class="fas fa-star fa-3x text-primary mb-3"></i>
                                        <h4>轩宇-导航</h4>
                                        <p class="text-muted">简洁好看的导航页</p>
                                    </div>
                                    
                                    <div class="system-info-item d-flex justify-content-between">
                                        <span class="system-info-label">程序名称</span>
                                        <span class="system-info-value">轩宇-导航</span>
                                    </div>
                                    <div class="system-info-item d-flex justify-content-between">
                                        <span class="system-info-label">版本号</span>
                                        <span class="system-info-value">v<?php echo $functions->escape($currentVersion); ?></span>
                                    </div>
                                    <div class="system-info-item d-flex justify-content-between">
                                        <span class="system-info-label">维护者</span>
                                        <span class="system-info-value">
                                            <i class="fas fa-code me-1"></i>轩宇
                                        </span>
                                    </div>
                                    <div class="system-info-item d-flex justify-content-between">
                                        <span class="system-info-label">发布日期</span>
                                        <span class="system-info-value"><?php echo defined('APP_RELEASE') ? $functions->escape(APP_RELEASE) : '2026-10-19'; ?></span>
                                    </div>
                                    <div class="system-info-item d-flex justify-content-between">
                                    
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6 mb-4">
                            <div class="card">
                                <div class="card-header d-flex justify-content-between align-items-center">
                                    <h5 class="mb-0">
                                        <i class="fas fa-inbox me-2"></i>最新申请
                                    </h5>
                                    <a href="applications.php" class="card-header-link text-decoration-none">查看全部 &raquo;</a>
                                </div>
                                <div class="card-body p-0">
                                    <div class="table-responsive">
                                        <table class="table table-hover mb-0">
                                            <thead>
                                                <tr>
                                                    <th>网站名称</th>
                                                    <th>分类</th>
                                                    <th>状态</th>
                                                    <th>申请时间</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php if (empty($recentApplications)): ?>
                                                    <tr>
                                                        <td colspan="4" class="text-center text-muted py-4">
                                                            <i class="fas fa-inbox fa-2x mb-2 d-block"></i>
                                                            暂无申请记录
                                                        </td>
                                                    </tr>
                                                <?php else: ?>
                                                    <?php foreach ($recentApplications as $app): ?>
                                                        <tr>
                                                            <td>
                                                                <a href="applications.php?edit=<?php echo $app['id']; ?>" class="text-decoration-none">
                                                                    <?php echo htmlspecialchars((string) $app['site_name'], ENT_QUOTES, 'UTF-8'); ?>
                                                                </a>
                                                             </td>
                                                            <td>
                                                                <?php if ($app['category_name']): ?>
                                                                    <span class="badge bg-info"><?php echo htmlspecialchars((string) $app['category_name'], ENT_QUOTES, 'UTF-8'); ?></span>
                                                                <?php else: ?>
                                                                    <span class="badge bg-secondary">未分类</span>
                                                                <?php endif; ?>
                                                             </td>
                                                            <td>
                                                                <?php if ($app['status'] == 'pending'): ?>
                                                                    <span class="badge bg-warning">待审核</span>
                                                                <?php elseif ($app['status'] == 'approved'): ?>
                                                                    <span class="badge bg-success">已批准</span>
                                                                <?php else: ?>
                                                                    <span class="badge bg-danger">已拒绝</span>
                                                                <?php endif; ?>
                                                             </td>
                                                            <td>
                                                                <small><?php echo date('m-d H:i', strtotime($app['created_at'])); ?></small>
                                                             </td>
                                                        </tr>
                                                    <?php endforeach; ?>
                                                <?php endif; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <div class="col-md-6 mb-4">
                            <div class="card">
                                <div class="card-header d-flex justify-content-between align-items-center">
                                    <h5 class="mb-0">
                                        <i class="fas fa-fire me-2"></i>热门分类
                                    </h5>
                                    <a href="categories.php" class="card-header-link text-decoration-none">管理分类 &raquo;</a>
                                </div>
                                <div class="card-body">
                                    <?php if (empty($popularCategories)): ?>
                                        <div class="text-center text-muted py-4">
                                            <i class="fas fa-folder-open fa-2x mb-2 d-block"></i>
                                            暂无分类数据
                                        </div>
                                    <?php else: ?>
                                        <?php foreach ($popularCategories as $category): ?>
                                            <div class="mb-3">
                                                <div class="d-flex justify-content-between mb-1">
                                                    <span>
                                                        <i class="fas fa-folder text-primary me-2"></i>
                                                        <?php echo htmlspecialchars((string) $category['name'], ENT_QUOTES, 'UTF-8'); ?>
                                                    </span>
                                                    <span class="badge bg-primary"><?php echo $category['link_count']; ?> 个链接</span>
                                                </div>
                                                <div class="progress">
                                                    <?php 
                                                        $maxCount = $popularCategories[0]['link_count'];
                                                        $percentage = ($category['link_count'] / max($maxCount, 1)) * 100;
                                                    ?>
                                                    <div class="progress-bar bg-primary" style="width: <?php echo $percentage; ?>%"></div>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-12 mb-4">
                            <div class="card">
                                <div class="card-header">
                                    <h5 class="mb-0">
                                        <i class="fas fa-chart-bar me-2"></i>访问统计摘要
                                    </h5>
                                </div>
                                <div class="card-body">
                                    <div class="row text-center">
                                        <div class="col-md-3 mb-3">
                                            <div class="border rounded p-3">
                                                <i class="fas fa-chart-line fa-2x text-primary mb-2 d-block"></i>
                                                <h4><?php echo number_format($stats['total_visits']); ?></h4>
                                                <p class="text-muted mb-0">总访问量</p>
                                            </div>
                                        </div>
                                        <div class="col-md-3 mb-3">
                                            <div class="border rounded p-3">
                                                <i class="fas fa-users fa-2x text-success mb-2 d-block"></i>
                                                <h4><?php echo number_format($stats['unique_visitors']); ?></h4>
                                                <p class="text-muted mb-0">独立访客</p>
                                            </div>
                                        </div>
                                        <div class="col-md-3 mb-3">
                                            <div class="border rounded p-3">
                                                <i class="fas fa-folder fa-2x text-info mb-2 d-block"></i>
                                                <h4><?php echo number_format($stats['total_categories']); ?></h4>
                                                <p class="text-muted mb-0">分类总数</p>
                                            </div>
                                        </div>
                                        <div class="col-md-3 mb-3">
                                            <div class="border rounded p-3">
                                                <i class="fas fa-link fa-2x text-warning mb-2 d-block"></i>
                                                <h4><?php echo number_format($stats['total_links']); ?></h4>
                                                <p class="text-muted mb-0">导航总数</p>
                                            </div>
                                        </div>
                                    </div>
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
        function updateDateTime() {
            const now = new Date();
            const year = now.getFullYear();
            const month = String(now.getMonth() + 1).padStart(2, '0');
            const day = String(now.getDate()).padStart(2, '0');
            const hours = String(now.getHours()).padStart(2, '0');
            const minutes = String(now.getMinutes()).padStart(2, '0');
            const seconds = String(now.getSeconds()).padStart(2, '0');
            const weekdays = ['星期日', '星期一', '星期二', '星期三', '星期四', '星期五', '星期六'];
            const weekday = weekdays[now.getDay()];
            
            const dateTimeStr = `${year}年${month}月${day}日 ${weekday} ${hours}:${minutes}:${seconds}`;
            $('#currentDateTime').text(dateTimeStr);
        }
        
        function showToast(message, type = 'info') {
            const iconMap = {
                'success': 'success',
                'error': 'error',
                'warning': 'warning',
                'info': 'info'
            };
            
            Swal.fire({
                text: message,
                icon: iconMap[type] || 'info',
                toast: true,
                position: 'top-end',
                showConfirmButton: false,
                timer: 3000,
                timerProgressBar: true,
                didOpen: (toast) => {
                    toast.addEventListener('mouseenter', Swal.stopTimer);
                    toast.addEventListener('mouseleave', Swal.resumeTimer);
                }
            });
        }
        
        async function showConfirm(title, message, confirmText = '确定', cancelText = '取消') {
            const result = await Swal.fire({
                title: title,
                text: message,
                icon: 'question',
                showCancelButton: true,
                confirmButtonText: confirmText,
                cancelButtonText: cancelText,
                confirmButtonColor: '#667eea',
                cancelButtonColor: '#6c757d'
            });
            
            return result.isConfirmed;
        }
        
        function initChart() {
            const canvas = document.getElementById('visitChart');
            if (!canvas) return;
            
            const ctx = canvas.getContext('2d');
            const weekData = <?php echo json_encode($weekStats); ?>;
            
            const dates = weekData.map(item => item.date);
            const visits = weekData.map(item => parseInt(item.visits) || 0);
            const visitors = weekData.map(item => parseInt(item.visitors) || 0);
            
            new Chart(ctx, {
                type: 'line',
                data: {
                    labels: dates,
                    datasets: [{
                        label: '访问量',
                        data: visits,
                        borderColor: '#667eea',
                        backgroundColor: 'rgba(102, 126, 234, 0.1)',
                        borderWidth: 2,
                        fill: true,
                        tension: 0.4
                    }, {
                        label: '访客数',
                        data: visitors,
                        borderColor: '#f093fb',
                        backgroundColor: 'rgba(240, 147, 251, 0.1)',
                        borderWidth: 2,
                        fill: true,
                        tension: 0.4
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: true,
                    plugins: {
                        legend: {
                            position: 'top',
                        },
                        tooltip: {
                            mode: 'index',
                            intersect: false
                        }
                    },
                    scales: {
                        y: {
                            beginAtZero: true,
                            ticks: {
                                stepSize: 1
                            }
                        }
                    }
                }
            });
        }
        
        $(document).ready(function() {
            updateDateTime();
            setInterval(updateDateTime, 1000);
            initChart();
            
            $('.stat-card, .card').each(function(index) {
                $(this).css('animation-delay', index * 0.1 + 's');
                $(this).addClass('animate-fade-in');
            });
        });
    </script>
</body>
</html>