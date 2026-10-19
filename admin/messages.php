<?php
/**
 * 轩宇-导航 · 留言管理
 */
require_once __DIR__ . '/../includes/bootstrap.php';
require_once '../includes/functions.php';

$functions = new Functions();

if (!$functions->isLoggedIn()) {
    $functions->redirect('login.php');
}

$db = Database::getInstance()->getConnection();
$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' || isset($_GET['action'])) {
    $functions->requireSameOrigin();
}

// 标记已读
if (isset($_GET['action']) && $_GET['action'] === 'read' && isset($_GET['id'])) {
    $id = (int) $_GET['id'];
    try {
        $stmt = $db->prepare('UPDATE contact_messages SET is_read = 1 WHERE id = ?');
        $stmt->execute([$id]);
        $message = '留言已标记为已读';
    } catch (Throwable $e) {
        $error = '操作失败，请稍后重试';
    }
}

// 删除
if (isset($_GET['action']) && $_GET['action'] === 'delete' && isset($_GET['id'])) {
    $id = (int) $_GET['id'];
    try {
        $stmt = $db->prepare('DELETE FROM contact_messages WHERE id = ?');
        $stmt->execute([$id]);
        $message = '留言已删除';
    } catch (Throwable $e) {
        $error = '删除失败，请稍后重试';
    }
}

// 全部已读
if (isset($_GET['action']) && $_GET['action'] === 'read_all') {
    try {
        $db->exec('UPDATE contact_messages SET is_read = 1 WHERE is_read = 0');
        $message = '全部留言已标记为已读';
    } catch (Throwable $e) {
        $error = '操作失败，请稍后重试';
    }
}

$page = max(1, (int) ($_GET['page'] ?? 1));
$pageSize = 20;
$offset = ($page - 1) * $pageSize;

$totalMessages = 0;
$messages = [];
$totalPages = 0;

try {
    $totalMessages = (int) $db->query('SELECT COUNT(*) AS total FROM contact_messages')->fetch()['total'];
    $totalPages = max(1, ceil($totalMessages / $pageSize));

    $stmt = $db->prepare('SELECT * FROM contact_messages ORDER BY is_read ASC, created_at DESC LIMIT ? OFFSET ?');
    $stmt->bindValue(1, $pageSize, PDO::PARAM_INT);
    $stmt->bindValue(2, $offset, PDO::PARAM_INT);
    $stmt->execute();
    $messages = $stmt->fetchAll();
} catch (Throwable $e) {
    $error = '留言表不存在，请先执行升级脚本';
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>留言管理 - 后台管理</title>
    <link href="../assets/css/bootstrap.css" rel="stylesheet">
    <link href="../assets/css/font-awesome.min.css" rel="stylesheet" onerror="this.onerror=null;this.href='https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css';">
    <link href="../assets/css/admin.css?v=2.1.0" rel="stylesheet">
</head>
<body>
    <?php include 'sidebar.php'; ?>

    <div class="main-content-wrapper" id="mainContentWrapper">
        <header class="page-header">
            <h1 class="page-title"><i class="fas fa-envelope me-2"></i>留言管理</h1>
        </header>

        <?php if ($message !== ''): ?>
            <div class="alert alert-success alert-dismissible fade show" role="alert">
                <div><?php echo $functions->escape($message); ?></div>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="关闭"></button>
            </div>
        <?php endif; ?>
        <?php if ($error !== ''): ?>
            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                <div><?php echo $functions->escape($error); ?></div>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="关闭"></button>
            </div>
        <?php endif; ?>

        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h5 class="mb-0">留言列表</h5>
                <?php if ($totalMessages > 0): ?>
                    <a href="messages.php?action=read_all" class="btn btn-sm btn-outline-primary">
                        <i class="fas fa-check-double me-1"></i>全部已读
                    </a>
                <?php endif; ?>
            </div>
            <div class="card-body">
                <?php if (empty($messages)): ?>
                    <p class="text-muted text-center py-4">暂无留言</p>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-hover">
                            <thead>
                                <tr>
                                    <th width="60">状态</th>
                                    <th>姓名</th>
                                    <th>邮箱</th>
                                    <th>主题</th>
                                    <th>内容</th>
                                    <th width="150">时间</th>
                                    <th width="100">操作</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($messages as $msg): ?>
                                    <tr class="<?php echo $msg['is_read'] ? '' : 'table-warning'; ?>">
                                        <td>
                                            <?php if ($msg['is_read']): ?>
                                                <span class="badge bg-secondary">已读</span>
                                            <?php else: ?>
                                                <span class="badge bg-warning text-dark">未读</span>
                                            <?php endif; ?>
                                        </td>
                                        <td><?php echo $functions->escape($msg['name']); ?></td>
                                        <td><a href="mailto:<?php echo $functions->escape($msg['email']); ?>"><?php echo $functions->escape($msg['email']); ?></a></td>
                                        <td><?php echo $functions->escape($msg['subject']); ?></td>
                                        <td style="max-width:300px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="<?php echo $functions->escape($msg['message']); ?>">
                                            <?php echo $functions->escape($functions->textCut($msg['message'], 50)); ?>
                                        </td>
                                        <td><?php echo $functions->escape($msg['created_at']); ?></td>
                                        <td>
                                            <?php if (!$msg['is_read']): ?>
                                                <a href="messages.php?action=read&id=<?php echo (int) $msg['id']; ?>" class="btn btn-sm btn-outline-success" title="标记已读">
                                                    <i class="fas fa-check"></i>
                                                </a>
                                            <?php endif; ?>
                                            <a href="messages.php?action=delete&id=<?php echo (int) $msg['id']; ?>" class="btn btn-sm btn-outline-danger" title="删除" onclick="return confirm('确定删除这条留言吗？');">
                                                <i class="fas fa-trash"></i>
                                            </a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                    <?php if ($totalPages > 1): ?>
                        <nav class="mt-3">
                            <ul class="pagination justify-content-center">
                                <?php for ($i = 1; $i <= $totalPages; $i++): ?>
                                    <li class="page-item <?php echo $i === $page ? 'active' : ''; ?>">
                                        <a class="page-link" href="messages.php?page=<?php echo $i; ?>"><?php echo $i; ?></a>
                                    </li>
                                <?php endfor; ?>
                            </ul>
                        </nav>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <script src="../assets/js/bootstrap.bundle.min.js"></script>
</body>
</html>
