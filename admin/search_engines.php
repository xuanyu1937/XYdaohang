<?php
/**
 * 后台：全局默认搜索引擎管理
 * 用户未自定义搜索引擎时，使用此处配置的全局默认列表
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

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $functions->requireSameOrigin();
}

function normalizeUrlTemplate($url)
{
    $url = trim((string) $url);
    if ($url === '' || strlen($url) > 500) {
        return '';
    }
    if (strpos($url, '{q}') === false && strpos($url, '{query}') === false) {
        return '';
    }
    $url = str_replace('{query}', '{q}', $url);
    if (!preg_match('#^https?://#i', $url)) {
        return '';
    }
    return $url;
}

// 处理新增
if (isset($_POST['add_engine'])) {
    $name = trim((string) ($_POST['name'] ?? ''));
    $urlTemplate = normalizeUrlTemplate($_POST['url_template'] ?? '');
    $icon = trim((string) ($_POST['icon'] ?? ''));

    if ($name === '') {
        $error = '请输入搜索引擎名称';
    } elseif ($urlTemplate === '') {
        $error = '搜索URL模板必须以 http(s):// 开头且包含 {q} 占位符';
    } else {
        $stmt = $db->query('SELECT COALESCE(MAX(sort_order), 0) FROM search_engines');
        $maxSort = (int) $stmt->fetchColumn();

        $stmt = $db->prepare(
            'INSERT INTO search_engines (name, url_template, icon, sort_order, is_active)
             VALUES (?, ?, ?, ?, 1)'
        );
        $stmt->execute([$name, $urlTemplate, $icon, $maxSort + 1]);
        $message = '搜索引擎添加成功';
    }
}

// 处理编辑
if (isset($_POST['update_engine'])) {
    $id = (int) ($_POST['id'] ?? 0);
    $name = trim((string) ($_POST['name'] ?? ''));
    $urlTemplate = normalizeUrlTemplate($_POST['url_template'] ?? '');
    $icon = trim((string) ($_POST['icon'] ?? ''));
    $isActive = isset($_POST['is_active']) ? 1 : 0;

    if ($id <= 0) {
        $error = '参数错误';
    } elseif ($name === '') {
        $error = '请输入搜索引擎名称';
    } elseif ($urlTemplate === '') {
        $error = '搜索URL模板必须以 http(s):// 开头且包含 {q} 占位符';
    } else {
        $stmt = $db->prepare(
            'UPDATE search_engines SET name = ?, url_template = ?, icon = ?, is_active = ? WHERE id = ?'
        );
        $stmt->execute([$name, $urlTemplate, $icon, $isActive, $id]);
        $message = '搜索引擎更新成功';
    }
}

// 处理删除
if (isset($_POST['delete_engine'])) {
    $id = (int) ($_POST['id'] ?? 0);
    if ($id > 0) {
        $stmt = $db->prepare('DELETE FROM search_engines WHERE id = ?');
        $stmt->execute([$id]);
        $message = '搜索引擎已删除';
    }
}

// 处理排序
if (isset($_POST['sort_engines'])) {
    $ids = $_POST['ids'] ?? '';
    if (is_string($ids)) {
        $ids = array_filter(explode(',', $ids), 'ctype_digit');
    } elseif (is_array($ids)) {
        $ids = array_filter($ids, 'ctype_digit');
    } else {
        $ids = [];
    }

    if (!empty($ids)) {
        $db->beginTransaction();
        try {
            $order = 1;
            $stmt = $db->prepare('UPDATE search_engines SET sort_order = ? WHERE id = ?');
            foreach ($ids as $id) {
                $stmt->execute([$order, (int) $id]);
                $order++;
            }
            $db->commit();
            $message = '排序已更新';
        } catch (Exception $e) {
            $db->rollBack();
            $error = '排序失败';
        }
    }
}

// 读取列表
$engines = $db->query(
    'SELECT id, name, url_template, icon, sort_order, is_active
     FROM search_engines
     ORDER BY sort_order ASC, id ASC'
)->fetchAll(PDO::FETCH_ASSOC);

$pageTitle = '搜索引擎管理';
include __DIR__ . '/header.php';
?>

<div class="container-fluid">
    <div class="row">
        <?php include __DIR__ . '/sidebar.php'; ?>

        <main class="col-md-9 ms-sm-auto col-lg-10 px-md-4 main-content">
            <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
                <h1 class="h2"><i class="fas fa-search"></i> 搜索引擎管理</h1>
            </div>

            <?php if ($message): ?>
                <div class="alert alert-success alert-dismissible fade show" role="alert">
                    <?= htmlspecialchars($message) ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>

            <?php if ($error): ?>
                <div class="alert alert-danger alert-dismissible fade show" role="alert">
                    <?= htmlspecialchars($error) ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>

            <div class="card mb-4">
                <div class="card-header">
                    <i class="fas fa-info-circle"></i> 说明
                </div>
                <div class="card-body">
                    <p class="mb-0">此处管理站点<strong>全局默认搜索引擎</strong>。当用户未自定义搜索引擎时，搜索框将显示此列表。URL 模板中的 <code>{q}</code> 会被替换为用户输入的搜索词。</p>
                </div>
            </div>

            <!-- 添加表单 -->
            <div class="card mb-4">
                <div class="card-header">
                    <i class="fas fa-plus"></i> 添加搜索引擎
                </div>
                <div class="card-body">
                    <form method="post" class="row g-3">
                        <div class="col-md-3">
                            <label class="form-label">名称 <span class="text-danger">*</span></label>
                            <input type="text" name="name" class="form-control" required maxlength="50" placeholder="如：百度">
                        </div>
                        <div class="col-md-5">
                            <label class="form-label">搜索URL模板 <span class="text-danger">*</span></label>
                            <input type="text" name="url_template" class="form-control" required maxlength="500" placeholder="如：https://www.baidu.com/s?wd={q}">
                            <div class="form-text">必须包含 <code>{q}</code> 占位符</div>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label">图标</label>
                            <input type="text" name="icon" class="form-control" maxlength="500" placeholder="emoji 或 图标URL">
                        </div>
                        <div class="col-md-2 d-flex align-items-end">
                            <button type="submit" name="add_engine" class="btn btn-primary w-100">
                                <i class="fas fa-plus"></i> 添加
                            </button>
                        </div>
                    </form>
                </div>
            </div>

            <!-- 列表 -->
            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <span><i class="fas fa-list"></i> 搜索引擎列表</span>
                    <button type="button" class="btn btn-sm btn-outline-secondary" id="toggleSort">
                        <i class="fas fa-sort"></i> <span id="sortBtnText">排序</span>
                    </button>
                </div>
                <div class="card-body p-0">
                    <?php if (empty($engines)): ?>
                        <div class="text-center text-muted py-5">暂无搜索引擎，请添加</div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-hover mb-0" id="enginesTable">
                                <thead>
                                    <tr>
                                        <th style="width:50px;">序号</th>
                                        <th>图标</th>
                                        <th>名称</th>
                                        <th>URL模板</th>
                                        <th>状态</th>
                                        <th style="width:200px;">操作</th>
                                    </tr>
                                </thead>
                                <tbody id="enginesTbody">
                                    <?php foreach ($engines as $e): ?>
                                        <tr data-id="<?= (int) $e['id'] ?>">
                                            <td class="sort-handle text-center" style="cursor:move;" title="拖拽排序">
                                                <i class="fas fa-grip-vertical text-muted"></i>
                                                <span class="sort-num"><?= (int) $e['sort_order'] ?></span>
                                            </td>
                                            <td><?= htmlspecialchars($e['icon'] ?: '🔍') ?></td>
                                            <td><?= htmlspecialchars($e['name']) ?></td>
                                            <td><code class="small"><?= htmlspecialchars($e['url_template']) ?></code></td>
                                            <td>
                                                <?php if ($e['is_active']): ?>
                                                    <span class="badge bg-success">启用</span>
                                                <?php else: ?>
                                                    <span class="badge bg-secondary">禁用</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <button class="btn btn-sm btn-outline-primary edit-btn"
                                                        data-id="<?= (int) $e['id'] ?>"
                                                        data-name="<?= htmlspecialchars($e['name']) ?>"
                                                        data-url="<?= htmlspecialchars($e['url_template']) ?>"
                                                        data-icon="<?= htmlspecialchars($e['icon']) ?>"
                                                        data-active="<?= (int) $e['is_active'] ?>">
                                                    <i class="fas fa-edit"></i> 编辑
                                                </button>
                                                <form method="post" class="d-inline" onsubmit="return confirm('确定删除此搜索引擎？');">
                                                    <input type="hidden" name="id" value="<?= (int) $e['id'] ?>">
                                                    <button type="submit" name="delete_engine" class="btn btn-sm btn-outline-danger">
                                                        <i class="fas fa-trash"></i> 删除
                                                    </button>
                                                </form>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        <form method="post" id="sortForm" class="p-3 border-top d-none">
                            <input type="hidden" name="ids" id="sortIds">
                            <button type="submit" name="sort_engines" class="btn btn-primary">
                                <i class="fas fa-save"></i> 保存排序
                            </button>
                            <button type="button" class="btn btn-outline-secondary" id="cancelSort">取消</button>
                        </form>
                    <?php endif; ?>
                </div>
            </div>
        </main>
    </div>
</div>

<!-- 编辑模态框 -->
<div class="modal fade" id="editModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="post">
                <div class="modal-header">
                    <h5 class="modal-title">编辑搜索引擎</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="id" id="editId">
                    <div class="mb-3">
                        <label class="form-label">名称 <span class="text-danger">*</span></label>
                        <input type="text" name="name" id="editName" class="form-control" required maxlength="50">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">搜索URL模板 <span class="text-danger">*</span></label>
                        <input type="text" name="url_template" id="editUrl" class="form-control" required maxlength="500">
                        <div class="form-text">必须包含 <code>{q}</code> 占位符</div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">图标</label>
                        <input type="text" name="icon" id="editIcon" class="form-control" maxlength="500" placeholder="emoji 或 图标URL">
                    </div>
                    <div class="form-check">
                        <input type="checkbox" name="is_active" id="editActive" class="form-check-input" value="1">
                        <label class="form-check-label" for="editActive">启用</label>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">取消</button>
                    <button type="submit" name="update_engine" class="btn btn-primary">保存</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
// 编辑按钮
document.querySelectorAll('.edit-btn').forEach(function(btn) {
    btn.addEventListener('click', function() {
        document.getElementById('editId').value = this.dataset.id;
        document.getElementById('editName').value = this.dataset.name;
        document.getElementById('editUrl').value = this.dataset.url;
        document.getElementById('editIcon').value = this.dataset.icon;
        document.getElementById('editActive').checked = this.dataset.active === '1';
        new bootstrap.Modal(document.getElementById('editModal')).show();
    });
});

// 排序模式切换
var sortMode = false;
var toggleSort = document.getElementById('toggleSort');
var sortBtnText = document.getElementById('sortBtnText');
var sortForm = document.getElementById('sortForm');
var sortIds = document.getElementById('sortIds');

if (toggleSort) {
    toggleSort.addEventListener('click', function() {
        sortMode = !sortMode;
        if (sortMode) {
            sortBtnText.textContent = '退出排序';
            toggleSort.classList.remove('btn-outline-secondary');
            toggleSort.classList.add('btn-primary');
            document.querySelectorAll('#enginesTbody tr').forEach(function(tr) {
                tr.draggable = true;
                tr.style.cursor = 'move';
            });
            sortForm.classList.remove('d-none');
        } else {
            sortBtnText.textContent = '排序';
            toggleSort.classList.remove('btn-primary');
            toggleSort.classList.add('btn-outline-secondary');
            document.querySelectorAll('#enginesTbody tr').forEach(function(tr) {
                tr.draggable = false;
                tr.style.cursor = '';
            });
            sortForm.classList.add('d-none');
        }
    });
}

// 拖拽排序
var dragSrcEl = null;
document.querySelectorAll('#enginesTbody tr').forEach(function(tr) {
    tr.addEventListener('dragstart', function(e) {
        dragSrcEl = this;
        e.dataTransfer.effectAllowed = 'move';
    });
    tr.addEventListener('dragover', function(e) {
        e.preventDefault();
        e.dataTransfer.dropEffect = 'move';
    });
    tr.addEventListener('drop', function(e) {
        e.preventDefault();
        if (dragSrcEl !== this) {
            var tbody = document.getElementById('enginesTbody');
            var rows = Array.from(tbody.querySelectorAll('tr'));
            var srcIdx = rows.indexOf(dragSrcEl);
            var dstIdx = rows.indexOf(this);
            if (srcIdx < dstIdx) {
                this.parentNode.insertBefore(dragSrcEl, this.nextSibling);
            } else {
                this.parentNode.insertBefore(dragSrcEl, this);
            }
            // 更新序号显示
            document.querySelectorAll('.sort-num').forEach(function(el, i) {
                el.textContent = i + 1;
            });
        }
    });
});

// 取消排序时重新加载序号
document.getElementById('cancelSort').addEventListener('click', function() {
    location.reload();
});

// 提交排序
sortForm.addEventListener('submit', function() {
    var ids = Array.from(document.querySelectorAll('#enginesTbody tr')).map(function(tr) {
        return tr.dataset.id;
    });
    sortIds.value = ids.join(',');
});
</script>

<?php include __DIR__ . '/footer.php'; ?>
