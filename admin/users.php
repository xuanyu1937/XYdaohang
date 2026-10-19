<?php
/**
 * 轩宇-导航 · 后台用户管理
 * 列表与状态变更复用 includes/Services.php 中的 UserService。
 */
require_once __DIR__ . '/../includes/bootstrap.php';
require_once '../includes/functions.php';
require_once '../includes/Services.php';

$functions = new Functions();

if (!$functions->isLoggedIn()) {
    $functions->redirect('login.php');
}

$db = Database::getInstance()->getConnection();
$userService = new UserService($functions);

$message = '';
$error = '';

/**
 * 当前后台会话对应的前台用户 id。
 * 会话可能来自 users 表（$_SESSION['admin_source'] = 'users'），取不到时
 * 退回「后台账号名 == 前台管理员用户名」的匹配；仍然取不到就返回 0，不做该限制。
 */
$currentUserId = 0;
$adminSource = (string) ($_SESSION['admin_source'] ?? '');

if ($adminSource === 'users' && !empty($_SESSION['user_id'])) {
    $currentUserId = (int) $_SESSION['user_id'];
} elseif ($adminSource === '' && !empty($_SESSION['admin_username'])) {
    // users 表可能还没有（未执行 v2 升级脚本），这里只是安全兜底，取不到就不做限制
    try {
        $selfStmt = $db->prepare('SELECT id FROM users WHERE username = ? AND is_admin = 1 LIMIT 1');
        $selfStmt->execute([(string) $_SESSION['admin_username']]);
        $selfRow = $selfStmt->fetch();

        if ($selfRow) {
            $currentUserId = (int) $selfRow['id'];
        }
    } catch (Throwable $error) {
        $currentUserId = 0;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $functions->requireSameOrigin();

    $action = (string) ($_POST['action'] ?? '');
    $targetId = (int) ($_POST['user_id'] ?? 0);
    $target = $targetId > 0 ? $userService->findById($targetId) : null;
    $targetName = $target ? (string) $target['username'] : '';

    if (!$target) {
        $error = '用户不存在或已被删除';
    } elseif ($currentUserId > 0 && $targetId === $currentUserId && (int) $target['is_admin'] === 1
        && in_array($action, ['ban', 'delete'], true)
    ) {
        $error = '不能封禁或删除当前登录的后台管理员账号';
    } elseif ($action === 'ban') {
        if ($userService->setStatus($targetId, UserService::STATUS_BANNED)) {
            $message = '用户「' . $targetName . '」已封禁';
        } else {
            $error = '封禁失败，请稍后重试';
        }
    } elseif ($action === 'unban') {
        if ($userService->setStatus($targetId, UserService::STATUS_ACTIVE)) {
            $message = '用户「' . $targetName . '」已启用';
        } else {
            $error = '启用失败，请稍后重试';
        }
    } elseif ($action === 'grant_admin') {
        if ($userService->setAdmin($targetId, true)) {
            $message = '用户「' . $targetName . '」已设为管理员';
        } else {
            $error = '操作失败，请稍后重试';
        }
    } elseif ($action === 'revoke_admin') {
        if ($userService->setAdmin($targetId, false)) {
            $message = '用户「' . $targetName . '」已取消管理员';
        } else {
            $error = '操作失败，请稍后重试';
        }
    } elseif ($action === 'set_default') {
        $userService->setDefaultUser($targetId);
        $message = '已将「' . $targetName . '」设为默认访客视图';
    } elseif ($action === 'unset_default') {
        $userService->setDefaultUser(0);
        $message = '已取消默认访客视图，前台恢复为公共导航';
    } elseif ($action === 'reset_password') {
        $result = $userService->resetPassword($targetId, (string) ($_POST['new_password'] ?? ''));

        if (!empty($result['ok'])) {
            $message = '用户「' . $targetName . '」的密码已重置';
        } else {
            $error = (string) ($result['error'] ?? '重置密码失败，请稍后重试');
        }
    } elseif ($action === 'delete') {
        if ($userService->delete($targetId)) {
            $message = '用户「' . $targetName . '」及其私有导航、分享记录已删除';
        } else {
            $error = '删除失败，请稍后重试';
        }
    } else {
        $error = '未知操作，请刷新页面后重试';
    }
}

$keyword = trim((string) ($_GET['q'] ?? ''));
$page = max(1, (int) ($_GET['page'] ?? 1));
$pageSize = 20;

$result = $userService->paginate($keyword, $page, $pageSize);

if ((int) $result['pages'] > 0 && $page > (int) $result['pages']) {
    $page = (int) $result['pages'];
    $result = $userService->paginate($keyword, $page, $pageSize);
}

$totalUsers = $userService->countAll();

// users 表可能还没有（未执行 v2 升级脚本），统计卡片在此情况下显示 0 而不是报错
$statusCounts = [];
$activeUsers = 0;
$bannedUsers = 0;
$adminUsers = 0;

try {
    foreach ($db->query('SELECT status, COUNT(*) AS total FROM users GROUP BY status')->fetchAll() as $row) {
        $statusCounts[(string) $row['status']] = (int) $row['total'];
    }

    $activeUsers = $statusCounts[UserService::STATUS_ACTIVE] ?? 0;
    $bannedUsers = $statusCounts[UserService::STATUS_BANNED] ?? 0;

    $adminStmt = $db->query('SELECT COUNT(*) AS total FROM users WHERE is_admin = 1');
    $adminUsers = (int) ($adminStmt->fetch()['total'] ?? 0);
} catch (Throwable $error) {
    // 老库降级：保持各项为 0
}

$currentDefaultUserId = $userService->defaultViewUserId();
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>用户管理 - 后台管理</title>
    <link href="../assets/css/bootstrap.css" rel="stylesheet">
    <link href="../assets/css/font-awesome.min.css" rel="stylesheet" onerror="this.onerror=null;this.href='https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css';">
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
                    <span>用户管理</span>
                </div>
                <h1 class="page-title">
                    <span class="title-icon"><i class="fas fa-users"></i></span>
                    用户列表
                </h1>
                <p class="page-subtitle">管理前台注册用户的状态、权限与登录凭据。</p>
            </div>
            <div class="page-header-meta">
                <span class="meta-pill">
                    <i class="fas fa-users"></i>
                    共 <?php echo (int) $totalUsers; ?> 个用户
                </span>
            </div>
        </header>

        <div class="admin-page-body">
            <?php if ($message): ?>
                <div class="alert alert-success alert-dismissible fade show" role="alert">
                    <?php echo $functions->escape($message); ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>

            <?php if ($error): ?>
                <div class="alert alert-danger alert-dismissible fade show" role="alert">
                    <?php echo $functions->escape($error); ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>

            <?php if ($currentUserId > 0): ?>
                <div class="alert alert-info alert-dismissible fade show" role="alert">
                    <i class="fas fa-shield-halved me-1"></i>
                    已识别当前登录管理员对应的用户（ID：<?php echo (int) $currentUserId; ?>），该账号不可被封禁或删除。
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>

            <div class="row g-4 mb-4">
                <div class="col-6 col-xl-3">
                    <div class="card stat-card">
                        <div class="card-body">
                            <div class="d-flex justify-content-between align-items-center">
                                <div>
                                    <h6 class="card-subtitle mb-2">总用户数</h6>
                                    <h3 class="card-title mb-0"><?php echo number_format($totalUsers); ?></h3>
                                </div>
                                <div class="stat-icon stat-primary">
                                    <i class="fas fa-users"></i>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="col-6 col-xl-3">
                    <div class="card stat-card">
                        <div class="card-body">
                            <div class="d-flex justify-content-between align-items-center">
                                <div>
                                    <h6 class="card-subtitle mb-2">正常用户</h6>
                                    <h3 class="card-title mb-0"><?php echo number_format($activeUsers); ?></h3>
                                </div>
                                <div class="stat-icon stat-success">
                                    <i class="fas fa-user-check"></i>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="col-6 col-xl-3">
                    <div class="card stat-card">
                        <div class="card-body">
                            <div class="d-flex justify-content-between align-items-center">
                                <div>
                                    <h6 class="card-subtitle mb-2">已封禁</h6>
                                    <h3 class="card-title mb-0"><?php echo number_format($bannedUsers); ?></h3>
                                </div>
                                <div class="stat-icon stat-warning">
                                    <i class="fas fa-user-slash"></i>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="col-6 col-xl-3">
                    <div class="card stat-card">
                        <div class="card-body">
                            <div class="d-flex justify-content-between align-items-center">
                                <div>
                                    <h6 class="card-subtitle mb-2">管理员数量</h6>
                                    <h3 class="card-title mb-0"><?php echo number_format($adminUsers); ?></h3>
                                </div>
                                <div class="stat-icon stat-info">
                                    <i class="fas fa-user-shield"></i>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="card table-card">
                <div class="card-header">
                    <div>
                        <h5 class="mb-0"><i class="fas fa-table-list"></i>用户数据</h5>
                        <span class="card-subtitle">支持按用户名或邮箱搜索，每页展示 20 条</span>
                    </div>
                    <form method="GET" class="d-flex align-items-center gap-2">
                        <input type="text" name="q" class="form-control"
                               value="<?php echo $functions->escape($keyword); ?>"
                               placeholder="搜索用户名 / 邮箱" aria-label="搜索用户">
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-magnifying-glass"></i>
                        </button>
                        <?php if ($keyword !== ''): ?>
                            <a href="users.php" class="btn btn-secondary" title="清空搜索">
                                <i class="fas fa-times"></i>
                            </a>
                        <?php endif; ?>
                    </form>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-hover admin-table align-middle">
                            <thead>
                                <tr>
                                    <th width="60">ID</th>
                                    <th>用户名</th>
                                    <th>邮箱</th>
                                    <th width="110">私有导航</th>
                                    <th width="100">状态</th>
                                    <th width="110">角色</th>
                                    <th width="120">默认视图</th>
                                    <th width="120">注册时间</th>
                                    <th width="150">最后登录</th>
                                    <th width="200">操作</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($result['items'] as $user): ?>
                                    <?php
                                        $isSelf = $currentUserId > 0
                                            && (int) $user['id'] === $currentUserId
                                            && (int) $user['is_admin'] === 1;
                                        $isBanned = (string) ($user['status'] ?? '') === UserService::STATUS_BANNED;
                                        $isDefault = (int) $user['id'] === $currentDefaultUserId;
                                    ?>
                                    <tr>
                                        <td><span class="cell-id"><?php echo (int) $user['id']; ?></span></td>
                                        <td>
                                            <div class="cell-title"><?php echo $functions->escape($user['username']); ?></div>
                                            <?php if ($isSelf): ?>
                                                <span class="badge bg-info mt-1"><i class="fas fa-user-check"></i>当前账号</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-nowrap"><?php echo $functions->escape($user['email']); ?></td>
                                        <td>
                                            <span class="badge badge-soft-primary">
                                                <i class="fas fa-link"></i><?php echo (int) $user['link_count']; ?> 条
                                            </span>
                                        </td>
                                        <td>
                                            <?php if ($isBanned): ?>
                                                <span class="badge bg-danger"><i class="fas fa-ban"></i>已封禁</span>
                                            <?php else: ?>
                                                <span class="badge bg-success"><i class="fas fa-circle-check"></i>正常</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if ((int) $user['is_admin'] === 1): ?>
                                                <span class="badge bg-primary"><i class="fas fa-user-shield"></i>管理员</span>
                                            <?php else: ?>
                                                <span class="badge bg-secondary">普通用户</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if ($isDefault): ?>
                                                <span class="badge bg-warning"><i class="fas fa-house-user"></i>默认视图</span>
                                            <?php else: ?>
                                                <span class="text-muted">—</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <span class="cell-date">
                                                <i class="far fa-calendar"></i><?php echo date('Y-m-d', strtotime((string) $user['created_at'])); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <?php if (!empty($user['last_login'])): ?>
                                                <span class="cell-date">
                                                    <i class="far fa-clock"></i><?php echo date('Y-m-d H:i', strtotime((string) $user['last_login'])); ?>
                                                </span>
                                            <?php else: ?>
                                                <span class="text-muted">从未登录</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <div class="table-actions">
                                                <?php if ($isBanned): ?>
                                                    <button type="button"
                                                            class="btn btn-action btn-action-edit"
                                                            data-user-action="unban"
                                                            data-id="<?php echo (int) $user['id']; ?>"
                                                            data-message="确定要启用用户「<?php echo $functions->escape($user['username']); ?>」吗？"
                                                            title="启用用户">
                                                        <i class="fas fa-circle-check"></i>
                                                    </button>
                                                <?php else: ?>
                                                    <button type="button"
                                                            class="btn btn-action btn-action-delete"
                                                            data-user-action="ban"
                                                            data-id="<?php echo (int) $user['id']; ?>"
                                                            data-message="确定要封禁用户「<?php echo $functions->escape($user['username']); ?>」吗？封禁后该用户将无法登录。"
                                                            title="封禁用户"
                                                            <?php echo $isSelf ? 'disabled' : ''; ?>>
                                                        <i class="fas fa-ban"></i>
                                                    </button>
                                                <?php endif; ?>

                                                <?php if ((int) $user['is_admin'] === 1): ?>
                                                    <button type="button"
                                                            class="btn btn-action btn-action-edit"
                                                            data-user-action="revoke_admin"
                                                            data-id="<?php echo (int) $user['id']; ?>"
                                                            data-message="确定要取消「<?php echo $functions->escape($user['username']); ?>」的管理员权限吗？"
                                                            title="取消管理员">
                                                        <i class="fas fa-user-shield"></i>
                                                    </button>
                                                <?php else: ?>
                                                    <button type="button"
                                                            class="btn btn-action btn-action-edit"
                                                            data-user-action="grant_admin"
                                                            data-id="<?php echo (int) $user['id']; ?>"
                                                            data-message="确定要将「<?php echo $functions->escape($user['username']); ?>」设为管理员吗？"
                                                            title="设为管理员">
                                                        <i class="fas fa-user-plus"></i>
                                                    </button>
                                                <?php endif; ?>

                                                <?php if ($isDefault): ?>
                                                    <button type="button"
                                                            class="btn btn-action btn-action-edit"
                                                            data-user-action="unset_default"
                                                            data-id="<?php echo (int) $user['id']; ?>"
                                                            data-message="确定要取消「<?php echo $functions->escape($user['username']); ?>」的默认访客视图吗？前台将恢复为公共导航。"
                                                            title="取消默认访客视图">
                                                        <i class="fas fa-house"></i>
                                                    </button>
                                                <?php else: ?>
                                                    <button type="button"
                                                            class="btn btn-action btn-action-edit"
                                                            data-user-action="set_default"
                                                            data-id="<?php echo (int) $user['id']; ?>"
                                                            data-message="确定要将「<?php echo $functions->escape($user['username']); ?>」设为默认访客视图吗？该用户的导航将作为未登录访客的首页内容。"
                                                            title="设为默认访客视图">
                                                        <i class="fas fa-house-user"></i>
                                                    </button>
                                                <?php endif; ?>

                                                <button type="button"
                                                        class="btn btn-action btn-action-edit"
                                                        data-open-reset
                                                        data-id="<?php echo (int) $user['id']; ?>"
                                                        data-name="<?php echo $functions->escape($user['username']); ?>"
                                                        title="重置密码">
                                                    <i class="fas fa-key"></i>
                                                </button>

                                                <button type="button"
                                                        class="btn btn-action btn-action-delete"
                                                        data-user-action="delete"
                                                        data-id="<?php echo (int) $user['id']; ?>"
                                                        data-message="确定要删除用户「<?php echo $functions->escape($user['username']); ?>」吗？&#10;该操作会同时删除此用户的私有导航与分享记录，且不可恢复。"
                                                        title="删除用户"
                                                        <?php echo $isSelf ? 'disabled' : ''; ?>>
                                                    <i class="fas fa-trash-can"></i>
                                                </button>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                    <?php if (empty($result['items'])): ?>
                        <div class="text-center py-5 text-muted">
                            <i class="fas fa-users fa-3x mb-3"></i>
                            <p><?php echo $keyword !== '' ? '没有匹配的用户，请更换关键词重试' : '暂无注册用户'; ?></p>
                        </div>
                    <?php endif; ?>

                    <?php if ((int) $result['pages'] > 1): ?>
                        <nav class="mt-4" aria-label="用户分页">
                            <ul class="pagination justify-content-center">
                                <li class="page-item <?php echo $page <= 1 ? 'disabled' : ''; ?>">
                                    <a class="page-link"
                                       href="users.php?<?php echo $functions->escape(http_build_query(['q' => $keyword, 'page' => max(1, $page - 1)])); ?>">
                                        <i class="fas fa-angle-left"></i> 上一页
                                    </a>
                                </li>
                                <li class="page-item disabled">
                                    <span class="page-link">
                                        第 <?php echo (int) $page; ?> / <?php echo (int) $result['pages']; ?> 页
                                        （共 <?php echo (int) $result['total']; ?> 条）
                                    </span>
                                </li>
                                <li class="page-item <?php echo $page >= (int) $result['pages'] ? 'disabled' : ''; ?>">
                                    <a class="page-link"
                                       href="users.php?<?php echo $functions->escape(http_build_query(['q' => $keyword, 'page' => min((int) $result['pages'], $page + 1)])); ?>">
                                        下一页 <i class="fas fa-angle-right"></i>
                                    </a>
                                </li>
                            </ul>
                        </nav>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <form method="POST" id="userActionForm" class="d-none" aria-hidden="true">
        <input type="hidden" name="action" id="actionField" value="">
        <input type="hidden" name="user_id" id="userIdField" value="">
    </form>

    <div class="modal fade" id="resetPasswordModal" tabindex="-1" aria-labelledby="resetPasswordTitle" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <form method="POST">
                    <div class="modal-header">
                        <h5 class="modal-title" id="resetPasswordTitle">
                            <i class="fas fa-key me-1"></i>重置用户密码
                        </h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="关闭"></button>
                    </div>
                    <div class="modal-body">
                        <p class="mb-3">
                            正在为用户 <strong id="resetPasswordUserName"></strong> 重置密码，重置后请及时告知本人。
                        </p>
                        <input type="hidden" name="action" value="reset_password">
                        <input type="hidden" name="user_id" id="resetPasswordUserId" value="">
                        <div class="mb-2">
                            <label class="form-label" for="resetPasswordInput">新密码 *</label>
                            <input type="password"
                                   class="form-control"
                                   id="resetPasswordInput"
                                   name="new_password"
                                   minlength="8"
                                   maxlength="72"
                                   autocomplete="new-password"
                                   required>
                            <div class="form-text">长度需为 8-72 位，建议包含字母与数字。</div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">取消</button>
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-save me-1"></i>确认重置
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="../assets/js/jquery3.7.1.js"></script>
    <script src="../assets/js/bootstrap.bundle.min.js"></script>
    <?php require_once __DIR__ . '/../includes/asset_fallbacks.php'; ?>
    <script>
    (function () {
        var actionForm = document.getElementById('userActionForm');
        var actionField = document.getElementById('actionField');
        var userIdField = document.getElementById('userIdField');
        var resetModalElement = document.getElementById('resetPasswordModal');
        var resetUserIdField = document.getElementById('resetPasswordUserId');
        var resetUserNameField = document.getElementById('resetPasswordUserName');
        var resetPasswordInput = document.getElementById('resetPasswordInput');

        if (!actionForm) {
            return;
        }

        // 需要确认后提交的操作（封禁 / 启用 / 权限 / 默认视图 / 删除）
        Array.prototype.forEach.call(document.querySelectorAll('[data-user-action]'), function (button) {
            button.addEventListener('click', function () {
                var message = button.getAttribute('data-message') || '确定要执行该操作吗？';

                if (!window.confirm(message)) {
                    return;
                }

                actionField.value = button.getAttribute('data-user-action');
                userIdField.value = button.getAttribute('data-id');
                actionForm.submit();
            });
        });

        // 重置密码：弹窗输入新密码
        Array.prototype.forEach.call(document.querySelectorAll('[data-open-reset]'), function (button) {
            button.addEventListener('click', function () {
                if (!resetModalElement || typeof bootstrap === 'undefined') {
                    return;
                }

                resetUserIdField.value = button.getAttribute('data-id');
                resetUserNameField.textContent = button.getAttribute('data-name');
                resetPasswordInput.value = '';
                bootstrap.Modal.getOrCreateInstance(resetModalElement).show();
            });
        });
    })();
    </script>
</body>
</html>
