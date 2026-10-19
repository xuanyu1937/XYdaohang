<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_once '../includes/functions.php';
require_once '../includes/TemplateParser.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception;

require_once '../vendor/PHPMailer/src/Exception.php';
require_once '../vendor/PHPMailer/src/PHPMailer.php';
require_once '../vendor/PHPMailer/src/SMTP.php';

$functions = new Functions();

if (!$functions->isLoggedIn()) {
    $functions->redirect('login.php');
}

$db = Database::getInstance()->getConnection();
$message = '';
$error = '';

if (isset($_GET['action']) || $_SERVER['REQUEST_METHOD'] === 'POST') {
    $functions->requireSameOrigin();
}

$mailConfig = [];
$configFile = '../config/smtp.php';
$siteTitle = (string) $functions->getSetting('site_title');

if (file_exists($configFile)) {
    $loadedMailConfig = require $configFile;
    if (is_array($loadedMailConfig)) {
        $mailConfig = $loadedMailConfig;
    }
    
    if ($siteTitle !== '') {
        $mailConfig['from']['name'] = $siteTitle;
    }
} else {
    $mailConfig = ['enabled' => false];
}

$templateParser = new TemplateParser('../template/mail');
$templateErrors = [];

/**
 * 申请可能没有分类；nav_links.category_id 为 NOT NULL。
 * 优先使用申请分类，其次第一个分类，最后创建“未分类”。
 */
function navResolveApplicationCategory($db, $categoryId) {
    $categoryId = (int) $categoryId;
    
    if ($categoryId > 0) {
        $stmt = $db->prepare("SELECT id FROM categories WHERE id = ?");
        $stmt->execute([$categoryId]);
        $row = $stmt->fetch();
        
        if ($row) {
            return (int) $row['id'];
        }
    }
    
    $row = $db->query("SELECT id FROM categories ORDER BY sort_order, id LIMIT 1")->fetch();
    
    if ($row) {
        return (int) $row['id'];
    }
    
    $stmt = $db->prepare("INSERT INTO categories (name, sort_order) VALUES (?, ?)");
    $stmt->execute(['未分类', 9999]);
    
    return (int) $db->lastInsertId();
}

if (!$templateParser->templateExists('application_approved', 'html')) {
    $templateErrors[] = "批准邮件HTML模板不存在 (application_approved.html)";
}
if (!$templateParser->templateExists('application_approved', 'txt')) {
    $templateErrors[] = "批准邮件文本模板不存在 (application_approved.txt)";
}

if (!$templateParser->templateExists('application_rejected', 'html')) {
    $templateErrors[] = "拒绝邮件HTML模板不存在 (application_rejected.html)";
}
if (!$templateParser->templateExists('application_rejected', 'txt')) {
    $templateErrors[] = "拒绝邮件文本模板不存在 (application_rejected.txt)";
}

if (!empty($templateErrors) && !empty($mailConfig['enabled'])) {
    $error = '邮件模板配置不完整：' . implode('；', $templateErrors);
}

if (isset($_GET['action']) && isset($_GET['id'])) {
    $id = intval($_GET['id']);
    $action = $_GET['action'];
    
    if ($action === 'approve') {
        $stmt = $db->prepare("SELECT * FROM applications WHERE id = ?");
        $stmt->execute([$id]);
        $application = $stmt->fetch();
        
        if ($application) {
            $resolvedCategoryId = navResolveApplicationCategory($db, $application['category_id'] ?? 0);
            
            $insertStmt = $db->prepare("
                INSERT INTO nav_links (name, url, logo, description, category_id) 
                VALUES (?, ?, ?, ?, ?)
            ");
            
            if ($insertStmt->execute([
                $application['site_name'],
                $application['site_url'],
                $application['site_logo'],
                $application['description'],
                $resolvedCategoryId
            ])) {
                $updateStmt = $db->prepare("UPDATE applications SET status = 'approved' WHERE id = ?");
                $updateStmt->execute([$id]);
                
                if (!empty($mailConfig['enabled']) && !empty($application['email'])) {
                    try {
                        $mailSent = sendApprovalEmail([
                            'site_name' => $application['site_name'],
                            'site_url' => $application['site_url'],
                            'email' => $application['email'],
                            'site_title' => $siteTitle,
                            'mail_config' => $mailConfig
                        ]);
                        
                        if ($mailSent) {
                            $message = '申请已批准并添加到导航，邮件通知已发送。';
                        } else {
                            $message = '申请已批准并添加到导航，但邮件通知发送失败。';
                        }
                    } catch (Exception $e) {
                        $message = '申请已批准并添加到导航，但邮件发送失败：' . $e->getMessage();
                        error_log("邮件发送失败: " . $e->getMessage());
                    }
                } else {
                    $message = '申请已批准并添加到导航。';
                }
            } else {
                $error = '批准失败，请稍后重试';
            }
        }
    } elseif ($action === 'reject') {
        $stmt = $db->prepare("SELECT email, site_name FROM applications WHERE id = ?");
        $stmt->execute([$id]);
        $application = $stmt->fetch();
        
        if ($application) {
            $updateStmt = $db->prepare("UPDATE applications SET status = 'rejected' WHERE id = ?");
            if ($updateStmt->execute([$id])) {
                if (!empty($mailConfig['enabled']) && !empty($application['email'])) {
                    try {
                        $mailSent = sendRejectionEmail([
                            'site_name' => $application['site_name'],
                            'email' => $application['email'],
                            'site_title' => $siteTitle,
                            'mail_config' => $mailConfig
                        ]);
                        
                        if ($mailSent) {
                            $message = '申请已拒绝，邮件通知已发送。';
                        } else {
                            $message = '申请已拒绝，但邮件通知发送失败。';
                        }
                    } catch (Exception $e) {
                        $message = '申请已拒绝，但邮件发送失败：' . $e->getMessage();
                        error_log("邮件发送失败: " . $e->getMessage());
                    }
                } else {
                    $message = '申请已拒绝';
                }
            } else {
                $error = '操作失败，请稍后重试';
            }
        }
    } elseif ($action === 'delete') {
        $stmt = $db->prepare("SELECT site_logo FROM applications WHERE id = ?");
        $stmt->execute([$id]);
        $result = $stmt->fetch();
        
        if ($result && !empty($result['site_logo'])) {
            $functions->deleteLogoFile($result['site_logo']);
        }
        
        $deleteStmt = $db->prepare("DELETE FROM applications WHERE id = ?");
        if ($deleteStmt->execute([$id])) {
            $message = '申请记录已删除';
        } else {
            $error = '删除失败，请稍后重试';
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['batch_action'])) {
        $batchAction = (string) $_POST['batch_action'];
        $selectedIds = $_POST['selected_applications'] ?? [];
        
        if (!is_array($selectedIds)) {
            $selectedIds = [];
        }
        
        $selectedIds = array_values(array_unique(array_filter(array_map('intval', $selectedIds), function ($id) {
            return $id > 0;
        })));
        
        if (empty($selectedIds) || !in_array($batchAction, ['approve_all', 'reject_all', 'delete_all'], true)) {
            $error = '请选择要操作的申请';
        } else {
            $placeholders = implode(',', array_fill(0, count($selectedIds), '?'));
            
            if ($batchAction === 'approve_all') {
                $successCount = 0;
                $errorCount = 0;
                $mailSentCount = 0;
                $mailErrorCount = 0;
                $mailErrorDetails = [];
                
                foreach ($selectedIds as $id) {
                    $stmt = $db->prepare("SELECT * FROM applications WHERE id = ? AND status = 'pending'");
                    $stmt->execute([$id]);
                    $application = $stmt->fetch();
                    
                    if ($application) {
                        $resolvedCategoryId = navResolveApplicationCategory($db, $application['category_id'] ?? 0);
                        
                        $insertStmt = $db->prepare("
                            INSERT INTO nav_links (name, url, logo, description, category_id) 
                            VALUES (?, ?, ?, ?, ?)
                        ");
                        
                        if ($insertStmt->execute([
                            $application['site_name'],
                            $application['site_url'],
                            $application['site_logo'],
                            $application['description'],
                            $resolvedCategoryId
                        ])) {
                            $updateStmt = $db->prepare("UPDATE applications SET status = 'approved' WHERE id = ?");
                            $updateStmt->execute([$id]);
                            $successCount++;
                            
                            if (!empty($mailConfig['enabled']) && !empty($application['email'])) {
                                try {
                                    $mailSent = sendApprovalEmail([
                                        'site_name' => $application['site_name'],
                                        'site_url' => $application['site_url'],
                                        'email' => $application['email'],
                                        'site_title' => $siteTitle,
                                        'mail_config' => $mailConfig
                                    ]);
                                    
                                    if ($mailSent) {
                                        $mailSentCount++;
                                    } else {
                                        $mailErrorCount++;
                                        $mailErrorDetails[] = "ID {$id}: 邮件发送失败";
                                    }
                                } catch (Exception $e) {
                                    $mailErrorCount++;
                                    $mailErrorDetails[] = "ID {$id}: " . $e->getMessage();
                                    error_log("邮件发送失败 ID {$id}: " . $e->getMessage());
                                }
                            }
                        } else {
                            $errorCount++;
                        }
                    }
                }
                
                $message = "批量批准完成：成功 {$successCount} 个，失败 {$errorCount} 个";
                if ($mailSentCount > 0) {
                    $message .= "，邮件发送成功 {$mailSentCount} 个";
                }
                if ($mailErrorCount > 0) {
                    $message .= "，邮件发送失败 {$mailErrorCount} 个";
                }
                
            } elseif ($batchAction === 'reject_all') {
                $mailSentCount = 0;
                $mailErrorCount = 0;
                $mailErrorDetails = [];
                
                $stmt = $db->prepare("SELECT id, email, site_name FROM applications WHERE id IN ($placeholders) AND status = 'pending'");
                $stmt->execute($selectedIds);
                $applications = $stmt->fetchAll();
                
                $updateStmt = $db->prepare("UPDATE applications SET status = 'rejected' WHERE id IN ($placeholders)");
                if ($updateStmt->execute($selectedIds)) {
                    foreach ($applications as $app) {
                        if (!empty($mailConfig['enabled']) && !empty($app['email'])) {
                            try {
                                $mailSent = sendRejectionEmail([
                                    'site_name' => $app['site_name'],
                                    'email' => $app['email'],
                                    'site_title' => $siteTitle,
                                    'mail_config' => $mailConfig
                                ]);
                                
                                if ($mailSent) {
                                    $mailSentCount++;
                                } else {
                                    $mailErrorCount++;
                                    $mailErrorDetails[] = "ID {$app['id']}: 邮件发送失败";
                                }
                            } catch (Exception $e) {
                                $mailErrorCount++;
                                $mailErrorDetails[] = "ID {$app['id']}: " . $e->getMessage();
                                error_log("邮件发送失败 ID {$app['id']}: " . $e->getMessage());
                            }
                        }
                    }
                    
                    $message = '批量拒绝完成：' . count($selectedIds) . ' 个申请已拒绝';
                    if ($mailSentCount > 0) {
                        $message .= "，邮件发送成功 {$mailSentCount} 个";
                    }
                    if ($mailErrorCount > 0) {
                        $message .= "，邮件发送失败 {$mailErrorCount} 个";
                    }
                } else {
                    $error = '批量拒绝失败';
                }
                
            } elseif ($batchAction === 'delete_all') {
                $stmt = $db->prepare("SELECT site_logo FROM applications WHERE id IN ($placeholders)");
                $stmt->execute($selectedIds);
                $logos = $stmt->fetchAll();
                
                foreach ($logos as $logo) {
                    if (!empty($logo['site_logo'])) {
                        $functions->deleteLogoFile($logo['site_logo']);
                    }
                }
                
                $deleteStmt = $db->prepare("DELETE FROM applications WHERE id IN ($placeholders)");
                if ($deleteStmt->execute($selectedIds)) {
                    $message = '批量删除完成：' . count($selectedIds) . ' 个申请已删除';
                } else {
                    $error = '批量删除失败';
                }
            }
        }
    }
}

$statusFilter = isset($_GET['status']) && is_string($_GET['status']) ? $_GET['status'] : 'pending';
$search = isset($_GET['search']) && is_string($_GET['search']) ? $_GET['search'] : '';
$dateFrom = isset($_GET['date_from']) && is_string($_GET['date_from']) ? $_GET['date_from'] : '';
$dateTo = isset($_GET['date_to']) && is_string($_GET['date_to']) ? $_GET['date_to'] : '';

$query = "SELECT a.*, c.name as category_name FROM applications a 
          LEFT JOIN categories c ON a.category_id = c.id 
          WHERE 1=1";
$params = [];

if ($statusFilter !== 'all') {
    $query .= " AND a.status = ?";
    $params[] = $statusFilter;
}

if (!empty($search)) {
    $query .= " AND (a.site_name LIKE ? OR a.site_url LIKE ? OR a.description LIKE ?)";
    $searchTerm = "%$search%";
    $params[] = $searchTerm;
    $params[] = $searchTerm;
    $params[] = $searchTerm;
}

if (!empty($dateFrom)) {
    $query .= " AND DATE(a.created_at) >= ?";
    $params[] = $dateFrom;
}

if (!empty($dateTo)) {
    $query .= " AND DATE(a.created_at) <= ?";
    $params[] = $dateTo;
}

$query .= " ORDER BY a.created_at DESC";

$stmt = $db->prepare($query);
$stmt->execute($params);
$applications = $stmt->fetchAll();

$pendingCount = $db->query("SELECT COUNT(*) as count FROM applications WHERE status = 'pending'")->fetch()['count'];
$approvedCount = $db->query("SELECT COUNT(*) as count FROM applications WHERE status = 'approved'")->fetch()['count'];
$rejectedCount = $db->query("SELECT COUNT(*) as count FROM applications WHERE status = 'rejected'")->fetch()['count'];
$totalCount = $db->query("SELECT COUNT(*) as count FROM applications")->fetch()['count'];

function sendApprovalEmail($data) {
    $config = $data['mail_config'];
    
    try {
        $templateParser = new TemplateParser('../template/mail');
        
        $isHttps = !empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off';
        $host = (string) ($_SERVER['HTTP_HOST'] ?? 'localhost');
        
        $templateData = [
            'site_name' => $data['site_name'],
            'site_url' => $data['site_url'],
            'site_title' => $data['site_title'],
            'current_year' => date('Y'),
            'approval_date' => date('Y-m-d H:i:s'),
            'index_url' => ($isHttps ? 'https://' : 'http://') . $host . '/index.php'
        ];
        
        $templateContent = $templateParser->parseAll('application_approved', $templateData);
        
        if (empty($templateContent['html'])) {
            throw new Exception("批准邮件HTML模板内容为空");
        }
        if (empty($templateContent['text'])) {
            throw new Exception("批准邮件文本模板内容为空");
        }
        
        $mail = new PHPMailer(true);
        
        $mail->isSMTP();
        $mail->Host = $config['smtp']['host'];
        $mail->SMTPAuth = $config['smtp']['auth'];
        $mail->Username = $config['smtp']['username'];
        $mail->Password = $config['smtp']['password'];
        $mail->SMTPSecure = $config['smtp']['encryption'];
        $mail->Port = $config['smtp']['port'];
        $mail->Timeout = $config['smtp']['timeout'];
        
        $mail->CharSet = $config['charset'] ?? 'UTF-8';
        
        if (!empty($config['smtp']['debug'])) {
            $mail->SMTPDebug = $config['smtp']['debug'];
        }
        
        $mail->setFrom($config['from']['address'], $config['from']['name']);
        $mail->addAddress($data['email']);
        
        $mail->isHTML(true);
        $subjectPrefix = $config['subject_prefix'] ?? '【网站导航】';
        $mail->Subject = $subjectPrefix . "恭喜！您的网站申请已通过审核";
        
        $mail->Body = $templateContent['html'];
        $mail->AltBody = $templateContent['text'];
        
        if ($mail->send()) {
            error_log("批准通知邮件已发送给: " . $data['email']);
            return true;
        } else {
            error_log("批准邮件发送失败: " . $mail->ErrorInfo);
            throw new Exception("邮件发送失败: " . $mail->ErrorInfo);
        }
        
    } catch (Exception $e) {
        error_log("批准邮件发送异常: " . $e->getMessage());
        throw new Exception("发送批准邮件失败: " . $e->getMessage());
    }
}

function sendRejectionEmail($data) {
    $config = $data['mail_config'];
    
    try {
        $templateParser = new TemplateParser('../template/mail');
        
        $templateData = [
            'site_name' => $data['site_name'],
            'site_title' => $data['site_title'],
            'current_year' => date('Y'),
            'rejection_date' => date('Y-m-d H:i:s')
        ];
        
        $templateContent = $templateParser->parseAll('application_rejected', $templateData);
        
        if (empty($templateContent['html'])) {
            throw new Exception("拒绝邮件HTML模板内容为空");
        }
        if (empty($templateContent['text'])) {
            throw new Exception("拒绝邮件文本模板内容为空");
        }
        
        $mail = new PHPMailer(true);
        
        $mail->isSMTP();
        $mail->Host = $config['smtp']['host'];
        $mail->SMTPAuth = $config['smtp']['auth'];
        $mail->Username = $config['smtp']['username'];
        $mail->Password = $config['smtp']['password'];
        $mail->SMTPSecure = $config['smtp']['encryption'];
        $mail->Port = $config['smtp']['port'];
        $mail->Timeout = $config['smtp']['timeout'];
        
        $mail->CharSet = $config['charset'] ?? 'UTF-8';
        
        if (!empty($config['smtp']['debug'])) {
            $mail->SMTPDebug = $config['smtp']['debug'];
        }
        
        $mail->setFrom($config['from']['address'], $config['from']['name']);
        $mail->addAddress($data['email']);
        
        $mail->isHTML(true);
        $subjectPrefix = $config['subject_prefix'] ?? '【网站导航】';
        $mail->Subject = $subjectPrefix . "您的网站申请审核结果";
        
        $mail->Body = $templateContent['html'];
        $mail->AltBody = $templateContent['text'];
        
        if ($mail->send()) {
            error_log("拒绝通知邮件已发送给: " . $data['email']);
            return true;
        } else {
            error_log("拒绝邮件发送失败: " . $mail->ErrorInfo);
            throw new Exception("邮件发送失败: " . $mail->ErrorInfo);
        }
        
    } catch (Exception $e) {
        error_log("拒绝邮件发送异常: " . $e->getMessage());
        throw new Exception("发送拒绝邮件失败: " . $e->getMessage());
    }
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>申请管理 - 后台管理</title>
    <link href="../assets/css/bootstrap.css" rel="stylesheet">
    <link href="../assets/css/font-awesome.min.css" rel="stylesheet" onerror="this.onerror=null;this.href='https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css';">
    <link href="../assets/css/bootstrap-datepicker.min.css" rel="stylesheet" onerror="this.onerror=null;this.href='https://cdn.jsdelivr.net/npm/bootstrap-datepicker@1.9.0/dist/css/bootstrap-datepicker.min.css';">
    <style>
        .logo-preview {
            width: 60px;
            height: 60px;
            border-radius: 8px;
            object-fit: cover;
            border: 1px solid #dee2e6;
        }
        .logo-preview i {
            font-size: 24px;
        }
        .btn-group-sm > .btn {
            padding: 0.25rem 0.5rem;
            font-size: 0.875rem;
        }
        .table th {
            background-color: #f8f9fa;
        }
        .template-status {
            font-size: 0.85rem;
            padding: 5px 10px;
            border-radius: 4px;
        }
        .template-status.ok {
            background-color: #d4edda;
            color: #155724;
        }
        .template-status.error {
            background-color: #f8d7da;
            color: #721c24;
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
                    <span>申请收录管理</span>
                </div>
                <h1 class="page-title">
                    <span class="title-icon"><i class="fas fa-inbox"></i></span>
                    申请收录管理
                </h1>
                <p class="page-subtitle">审核用户提交的站点收录申请，并跟踪处理状态。</p>
            </div>
            <div class="page-header-meta">
                <?php if (!empty($mailConfig['enabled'])): ?>
                    <span class="meta-pill is-success">
                        <i class="fas fa-envelope-circle-check"></i>
                        邮件通知已启用
                    </span>
                <?php else: ?>
                    <span class="meta-pill is-warning">
                        <i class="fas fa-envelope"></i>
                        邮件通知未启用
                    </span>
                <?php endif; ?>

                <?php if ($templateParser->templateExists('application_approved', 'html') && $templateParser->templateExists('application_approved', 'txt')): ?>
                    <span class="meta-pill is-success">
                        <i class="fas fa-circle-check"></i>
                        批准模板就绪
                    </span>
                <?php else: ?>
                    <span class="meta-pill is-danger">
                        <i class="fas fa-circle-xmark"></i>
                        批准模板缺失
                    </span>
                <?php endif; ?>

                <?php if ($templateParser->templateExists('application_rejected', 'html') && $templateParser->templateExists('application_rejected', 'txt')): ?>
                    <span class="meta-pill is-success">
                        <i class="fas fa-circle-check"></i>
                        拒绝模板就绪
                    </span>
                <?php else: ?>
                    <span class="meta-pill is-danger">
                        <i class="fas fa-circle-xmark"></i>
                        拒绝模板缺失
                    </span>
                <?php endif; ?>
            </div>
        </header>

        <div class="admin-page-body">
                    <?php if ($message): ?>
                        <div class="alert alert-success alert-dismissible fade show" role="alert">
                            <i class="fas fa-check-circle me-2"></i> <?php echo $functions->escape($message); ?>
                            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                        </div>
                    <?php endif; ?>
                    
                    <?php if ($error): ?>
                        <div class="alert alert-danger alert-dismissible fade show" role="alert">
                            <i class="fas fa-exclamation-circle me-2"></i> <?php echo $functions->escape($error); ?>
                            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                        </div>
                    <?php endif; ?>
                    
                    <?php if (!empty($mailConfig['enabled']) && !empty($templateErrors)): ?>
                        <div class="alert alert-warning alert-dismissible fade show" role="alert">
                            <i class="fas fa-exclamation-triangle me-2"></i>
                            <strong>邮件模板问题：</strong>
                            <ul class="mb-0">
                                <?php foreach ($templateErrors as $templateError): ?>
                                    <li><?php echo $templateError; ?></li>
                                <?php endforeach; ?>
                            </ul>
                            <p class="mb-0 mt-2">
                                请确保以下文件存在于 templates/email/ 目录：
                                <br>• application_approved.html
                                <br>• application_approved.txt
                                <br>• application_rejected.html
                                <br>• application_rejected.txt
                            </p>
                            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                        </div>
                    <?php endif; ?>
                    
                    <div class="row mb-4">
                        <div class="col-md-3 mb-3">
                            <div class="card border-warning">
                                <div class="card-body text-center">
                                    <h3 class="text-warning"><?php echo $pendingCount; ?></h3>
                                    <p class="mb-0">待审核</p>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-3 mb-3">
                            <div class="card border-success">
                                <div class="card-body text-center">
                                    <h3 class="text-success"><?php echo $approvedCount; ?></h3>
                                    <p class="mb-0">已批准</p>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-3 mb-3">
                            <div class="card border-danger">
                                <div class="card-body text-center">
                                    <h3 class="text-danger"><?php echo $rejectedCount; ?></h3>
                                    <p class="mb-0">已拒绝</p>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-3 mb-3">
                            <div class="card border-info">
                                <div class="card-body text-center">
                                    <h3 class="text-info"><?php echo $totalCount; ?></h3>
                                    <p class="mb-0">总计</p>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="card mb-4">
                        <div class="card-header">
                            <h5 class="mb-0"><i class="fas fa-filter me-2"></i>筛选条件</h5>
                        </div>
                        <div class="card-body">
                            <form method="GET" class="row g-3">
                                <div class="col-md-3">
                                    <label class="form-label">状态筛选</label>
                                    <select name="status" class="form-select">
                                        <option value="all" <?php echo $statusFilter == 'all' ? 'selected' : ''; ?>>全部状态</option>
                                        <option value="pending" <?php echo $statusFilter == 'pending' ? 'selected' : ''; ?>>待审核</option>
                                        <option value="approved" <?php echo $statusFilter == 'approved' ? 'selected' : ''; ?>>已批准</option>
                                        <option value="rejected" <?php echo $statusFilter == 'rejected' ? 'selected' : ''; ?>>已拒绝</option>
                                    </select>
                                </div>
                                
                                <div class="col-md-3">
                                    <label class="form-label">提交时间从</label>
                                    <input type="text" 
                                           name="date_from" 
                                           class="form-control datepicker" 
                                           value="<?php echo $functions->escape($dateFrom); ?>"
                                           placeholder="开始日期">
                                </div>
                                
                                <div class="col-md-3">
                                    <label class="form-label">提交时间到</label>
                                    <input type="text" 
                                           name="date_to" 
                                           class="form-control datepicker" 
                                           value="<?php echo $functions->escape($dateTo); ?>"
                                           placeholder="结束日期">
                                </div>
                                
                                <div class="col-md-3">
                                    <label class="form-label">搜索</label>
                                    <div class="input-group">
                                        <input type="text" 
                                               name="search" 
                                               class="form-control" 
                                               value="<?php echo $functions->escape($search); ?>"
                                               placeholder="网站名称或网址">
                                        <button type="submit" class="btn btn-primary">
                                            <i class="fas fa-search"></i>
                                        </button>
                                        <a href="applications.php" class="btn btn-outline-secondary">
                                            <i class="fas fa-redo"></i>
                                        </a>
                                    </div>
                                </div>
                            </form>
                        </div>
                    </div>
                    
                    <div class="card">
                        <div class="card-header">
                            <div class="d-flex justify-content-between align-items-center">
                                <h5 class="mb-0"><i class="fas fa-list me-2"></i>申请列表</h5>
                                <div>
                                    <button class="btn btn-sm btn-success" onclick="batchApprove()">
                                        <i class="fas fa-check me-1"></i>批量批准
                                    </button>
                                    <button class="btn btn-sm btn-warning ms-2" onclick="batchReject()">
                                        <i class="fas fa-ban me-1"></i>批量拒绝
                                    </button>
                                    <button class="btn btn-sm btn-danger ms-2" onclick="batchDelete()">
                                        <i class="fas fa-trash me-1"></i>批量删除
                                    </button>
                                </div>
                            </div>
                        </div>
                        <div class="card-body">
                            <form method="POST" id="batchForm">
                                <div class="table-responsive">
                                    <table id="applicationsTable" class="table table-hover admin-table align-middle">
                                        <thead>
                                            <tr>
                                                <th width="50">
                                                    <input type="checkbox" id="selectAll">
                                                </th>
                                                <th width="60">ID</th>
                                                <th>网站信息</th>
                                                <th width="100">分类</th>
                                                <th width="100">状态</th>
                                                <th width="120">提交时间</th>
                                                <th width="120">联系方式</th>
                                                <th width="180">操作</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($applications as $app): ?>
                                                <tr>
                                                    <td>
                                                        <input type="checkbox" 
                                                               name="selected_applications[]" 
                                                               value="<?php echo $app['id']; ?>"
                                                               class="app-checkbox">
                                                    </td>
                                                    <td><?php echo $app['id']; ?></td>
                                                    <td>
                                                        <div class="d-flex align-items-center">
                                                            <?php if (!empty($app['site_logo'])): ?>
                                                                <img src="<?php echo $functions->escape($functions->getLogoUrl($app['site_logo'])); ?>" 
                                                                     alt="Logo" 
                                                                     class="logo-preview me-3">
                                                            <?php else: ?>
                                                                <div class="logo-preview me-3 bg-light d-flex align-items-center justify-content-center">
                                                                    <i class="fas fa-globe text-muted"></i>
                                                                </div>
                                                            <?php endif; ?>
                                                            <div>
                                                                <strong><?php echo $functions->escape($app['site_name']); ?></strong>
                                                                <br>
                                                                <a href="<?php echo $functions->escape($app['site_url']); ?>" 
                                                                   target="_blank" 
                                                                   class="text-primary small">
                                                                    <?php echo parse_url($app['site_url'], PHP_URL_HOST); ?>
                                                                </a>
                                                                <br>
                                                                <small class="text-muted"><?php echo $functions->escape($app['description']); ?></small>
                                                            </div>
                                                        </div>
                                                    </td>
                                                    <td>
                                                        <?php if ($app['category_name']): ?>
                                                            <span class="badge bg-info"><?php echo $functions->escape($app['category_name']); ?></span>
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
                                                        <?php echo date('Y-m-d', strtotime($app['created_at'])); ?><br>
                                                        <small class="text-muted"><?php echo date('H:i:s', strtotime($app['created_at'])); ?></small>
                                                    </td>
                                                    <td>
                                                        <a href="mailto:<?php echo $functions->escape($app['email']); ?>" 
                                                           class="d-block text-primary small mb-1">
                                                            <i class="fas fa-envelope me-1"></i>
                                                            <?php echo $functions->escape($app['email']); ?>
                                                        </a>
                                                        <small class="text-muted">
                                                            <i class="fas fa-globe me-1"></i>
                                                            <?php echo $functions->escape($app['ip_address']); ?>
                                                        </small>
                                                    </td>
                                                    <td>
                                                        <div class="btn-group btn-group-sm">
                                                            <?php if ($app['status'] == 'pending'): ?>
                                                                <a href="applications.php?action=approve&id=<?php echo $app['id']; ?>" 
                                                                   class="btn btn-outline-success" 
                                                                   title="批准"
                                                                   onclick="return confirm('确定要批准这个申请吗？将立即加入导航列表。')">
                                                                    <i class="fas fa-check"></i>
                                                                </a>
                                                                <a href="applications.php?action=reject&id=<?php echo $app['id']; ?>" 
                                                                   class="btn btn-outline-warning" 
                                                                   title="拒绝"
                                                                   onclick="return confirm('确定要拒绝这个申请吗？')">
                                                                    <i class="fas fa-ban"></i>
                                                                </a>
                                                            <?php endif; ?>
                                                            <a href="applications.php?action=delete&id=<?php echo $app['id']; ?>" 
                                                               class="btn btn-outline-danger" 
                                                               title="删除"
                                                               onclick="return confirm('确定要删除这个申请记录吗？此操作不可恢复！')">
                                                                <i class="fas fa-trash"></i>
                                                            </a>
                                                            <a href="<?php echo $functions->escape($app['site_url']); ?>" 
                                                               target="_blank" 
                                                               class="btn btn-outline-info" 
                                                               title="访问网站">
                                                                <i class="fas fa-external-link-alt"></i>
                                                            </a>
                                                        </div>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                                
                                <?php if (empty($applications)): ?>
                                    <div class="text-center py-5 text-muted">
                                        <i class="fas fa-inbox fa-3x mb-3"></i>
                                        <p>暂无申请记录</p>
                                    </div>
                                <?php endif; ?>
                            </form>
                        </div>
                    </div>
        </div>
    </div>
    
    <script src="../assets/js/jquery3.7.1.js"></script>
    <script src="../assets/js/bootstrap.bundle.min.js"></script>
    <?php require_once __DIR__ . '/../includes/asset_fallbacks.php'; ?>
    <script src="../assets/js/jquery.dataTables.min.js"></script>
    <script src="../assets/js/dataTables.bootstrap5.min.js"></script>
    <script>window.jQuery && jQuery.fn.DataTable || document.write('<script src="https://cdn.datatables.net/1.13.4/js/jquery.dataTables.min.js"><\/script><script src="https://cdn.datatables.net/1.13.4/js/dataTables.bootstrap5.min.js"><\/script>');</script>
    <script src="../assets/js/bootstrap-datepicker.min.js"></script>
    <script src="../assets/js/bootstrap-datepicker.zh-CN.min.js"></script>
    <script>window.jQuery && jQuery.fn.datepicker || document.write('<script src="https://cdn.jsdelivr.net/npm/bootstrap-datepicker@1.9.0/dist/js/bootstrap-datepicker.min.js"><\/script><script src="https://cdn.jsdelivr.net/npm/bootstrap-datepicker@1.9.0/dist/locales/bootstrap-datepicker.zh-CN.min.js"><\/script>');</script>
    
    <script>
    $(document).ready(function() {
        $('#applicationsTable').DataTable({
            language: {
                url: '../assets/js/dataTables.zh-CN.json'
            },
            order: [[5, 'desc']],
            pageLength: 20,
            lengthMenu: [10, 20, 50, 100],
            columnDefs: [
                { orderable: false, targets: [0, 7] }
            ]
        });
        
        $('.datepicker').datepicker({
            format: 'yyyy-mm-dd',
            language: 'zh-CN',
            autoclose: true,
            todayHighlight: true
        });
        
        $('#selectAll').click(function() {
            $('.app-checkbox').prop('checked', this.checked);
        });
        
        $('.app-checkbox').click(function() {
            if ($('.app-checkbox:checked').length == $('.app-checkbox').length) {
                $('#selectAll').prop('checked', true);
            } else {
                $('#selectAll').prop('checked', false);
            }
        });
    });
    
    function batchApprove() {
        if ($('.app-checkbox:checked').length == 0) {
            alert('请至少选择一个申请进行操作');
            return;
        }
        
        if (confirm('确定要批准选中的申请吗？这些网站将被添加到导航中，系统将发送邮件通知申请人。')) {
            $('#batchForm').append('<input type="hidden" name="batch_action" value="approve_all">');
            $('#batchForm').submit();
        }
    }
    
    function batchReject() {
        if ($('.app-checkbox:checked').length == 0) {
            alert('请至少选择一个申请进行操作');
            return;
        }
        
        if (confirm('确定要拒绝选中的申请吗？系统将发送邮件通知申请人。')) {
            $('#batchForm').append('<input type="hidden" name="batch_action" value="reject_all">');
            $('#batchForm').submit();
        }
    }
    
    function batchDelete() {
        if ($('.app-checkbox:checked').length == 0) {
            alert('请至少选择一个申请进行操作');
            return;
        }
        
        if (confirm('确定要删除选中的申请记录吗？此操作不可恢复！')) {
            $('#batchForm').append('<input type="hidden" name="batch_action" value="delete_all">');
            $('#batchForm').submit();
        }
    }
    </script>
</body>
</html>