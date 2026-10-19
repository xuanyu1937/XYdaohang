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

if ($_SERVER['REQUEST_METHOD'] === 'POST' || isset($_GET['delete'])) {
    $functions->requireSameOrigin();
}

/**
 * 探测 v2 新增列（parent_id / icon / remark）是否已经就绪。
 * 老库尚未执行 upgrade_v2.0.0.sql 时退回旧行为，不显示二级分类相关控件。
 */
$hasV2Columns = true;

try {
    $db->query('SELECT parent_id, icon, remark FROM categories LIMIT 1');
} catch (Throwable $probeError) {
    $hasV2Columns = false;
}

/**
 * 取某分类的子分类数量（老库无 parent_id 时恒为 0）。
 */
function navCategoryChildCount($db, $hasV2Columns, $categoryId)
{
    if (!$hasV2Columns) {
        return 0;
    }

    $stmt = $db->prepare('SELECT COUNT(*) AS total FROM categories WHERE parent_id = ?');
    $stmt->execute([(int) $categoryId]);

    return (int) ($stmt->fetch()['total'] ?? 0);
}

/**
 * 取某分类下的链接数量（排除回收站）。
 */
function navCategoryLinkCount($db, $hasDeletedAt, $categoryId)
{
    $sql = 'SELECT COUNT(*) AS total FROM nav_links WHERE category_id = ?'
        . ($hasDeletedAt ? ' AND deleted_at IS NULL' : '');

    $stmt = $db->prepare($sql);
    $stmt->execute([(int) $categoryId]);

    return (int) ($stmt->fetch()['total'] ?? 0);
}

/**
 * 归一化表单字段（在需要时调用，避免多处重复 trim/类型转换）。
 */
function navCategoryTrim($value, $limit)
{
    $value = trim((string) $value);

    if (function_exists('mb_substr')) {
        return mb_substr($value, 0, (int) $limit, 'UTF-8');
    }

    return substr($value, 0, (int) $limit);
}

$hasDeletedAt = $hasV2Columns;

try {
    $db->query('SELECT deleted_at FROM nav_links LIMIT 1');
} catch (Throwable $probeError) {
    $hasDeletedAt = false;
}

/* ----------------------------- 添加分类 ----------------------------- */

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_category'])) {
    $name = trim((string) ($_POST['name'] ?? ''));
    $sortOrder = (int) ($_POST['sort_order'] ?? 0);
    $icon = $hasV2Columns ? trim((string) ($_POST['icon'] ?? '')) : '';
    $remark = $hasV2Columns ? trim((string) ($_POST['remark'] ?? '')) : '';
    $parentId = $hasV2Columns ? (int) ($_POST['parent_id'] ?? 0) : 0;

    $name = navCategoryTrim($name, 50);
    $icon = navCategoryTrim($icon, 100);
    $remark = navCategoryTrim($remark, 255);

    if ($name === '') {
        $error = '分类名称不能为空';
    } else {
        // 只允许两级：上级必须是已存在的一级分类
        if ($parentId > 0) {
            $parentStmt = $db->prepare('SELECT id, parent_id FROM categories WHERE id = ?');
            $parentStmt->execute([$parentId]);
            $parent = $parentStmt->fetch();

            if (!$parent || (int) ($parent['parent_id'] ?? 0) > 0) {
                $parentId = 0;
            }
        }

        try {
            if ($hasV2Columns) {
                $stmt = $db->prepare(
                    'INSERT INTO categories (parent_id, name, icon, sort_order, remark) VALUES (?, ?, ?, ?, ?)'
                );
                $stmt->execute([$parentId > 0 ? $parentId : null, $name, $icon, $sortOrder, $remark]);
            } else {
                $stmt = $db->prepare('INSERT INTO categories (name, sort_order) VALUES (?, ?)');
                $stmt->execute([$name, $sortOrder]);
            }

            $message = $parentId > 0 ? '二级分类添加成功' : '分类添加成功';
        } catch (Throwable $insertError) {
            $functions->logMessage('[categories] 添加失败: ' . $insertError->getMessage(), 'ERROR');
            $error = '添加失败，请稍后重试';
        }
    }
}

/* ----------------------------- 编辑分类 ----------------------------- */

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['edit_category'])) {
    $id = (int) ($_POST['id'] ?? 0);
    $name = trim((string) ($_POST['name'] ?? ''));
    $sortOrder = (int) ($_POST['sort_order'] ?? 0);
    $icon = $hasV2Columns ? trim((string) ($_POST['icon'] ?? '')) : '';
    $remark = $hasV2Columns ? trim((string) ($_POST['remark'] ?? '')) : '';
    $parentId = $hasV2Columns ? (int) ($_POST['parent_id'] ?? 0) : 0;

    $name = navCategoryTrim($name, 50);
    $icon = navCategoryTrim($icon, 100);
    $remark = navCategoryTrim($remark, 255);

    if ($id <= 0) {
        $error = '参数不正确';
    } elseif ($name === '') {
        $error = '分类名称不能为空';
    } else {
        $childCount = navCategoryChildCount($db, $hasV2Columns, $id);

        if ($parentId === $id) {
            $error = '不能把自己设为上级分类';
            $parentId = 0;
        } elseif ($parentId > 0 && $childCount > 0) {
            $error = '该分类下已有子分类，不能再设为二级分类';
            $parentId = 0;
        } elseif ($parentId > 0) {
            // 上级必须存在且本身是一级分类，避免出现三层结构
            $parentStmt = $db->prepare('SELECT id, parent_id FROM categories WHERE id = ?');
            $parentStmt->execute([$parentId]);
            $parent = $parentStmt->fetch();

            if (!$parent || (int) ($parent['parent_id'] ?? 0) > 0) {
                $parentId = 0;
            }
        }

        if ($error === '') {
            try {
                if ($hasV2Columns) {
                    $stmt = $db->prepare(
                        'UPDATE categories SET parent_id = ?, name = ?, icon = ?, sort_order = ?, remark = ? WHERE id = ?'
                    );
                    $stmt->execute([$parentId > 0 ? $parentId : null, $name, $icon, $sortOrder, $remark, $id]);
                } else {
                    $stmt = $db->prepare('UPDATE categories SET name = ?, sort_order = ? WHERE id = ?');
                    $stmt->execute([$name, $sortOrder, $id]);
                }

                $message = '分类更新成功';
            } catch (Throwable $updateError) {
                $functions->logMessage('[categories] 更新失败: ' . $updateError->getMessage(), 'ERROR');
                $error = '更新失败，请稍后重试';
            }
        }
    }
}

/* ----------------------------- 删除分类 ----------------------------- */

if (isset($_GET['delete'])) {
    $id = (int) $_GET['delete'];

    $linkCount = navCategoryLinkCount($db, $hasDeletedAt, $id);
    $childCount = navCategoryChildCount($db, $hasV2Columns, $id);

    if ($linkCount > 0) {
        $error = '该分类下还有 ' . $linkCount . ' 个导航链接，无法删除';
    } elseif ($childCount > 0) {
        $error = '该分类下还有 ' . $childCount . ' 个子分类，请先删除子分类';
    } else {
        try {
            $stmt = $db->prepare('DELETE FROM categories WHERE id = ?');
            $stmt->execute([$id]);
            $message = '分类删除成功';
        } catch (Throwable $deleteError) {
            $functions->logMessage('[categories] 删除失败: ' . $deleteError->getMessage(), 'ERROR');
            $error = '删除失败，请稍后重试';
        }
    }
}

/* ----------------------------- 数据读取 ----------------------------- */

if ($hasV2Columns) {
    $categories = $db->query('SELECT * FROM categories ORDER BY sort_order, id')->fetchAll();
} else {
    $categories = $db->query('SELECT * FROM categories ORDER BY sort_order, id')->fetchAll();

    foreach ($categories as $index => $row) {
        $categories[$index]['parent_id'] = null;
        $categories[$index]['icon'] = null;
        $categories[$index]['remark'] = null;
    }
}

// 组织成两级树：一级分类按原顺序，子分类挂在其父级之后
$categoryTree = [];
$childrenByParent = [];

foreach ($categories as $category) {
    $parentId = (int) ($category['parent_id'] ?? 0);

    if ($parentId > 0) {
        $childrenByParent[$parentId][] = $category;
    }
}

foreach ($categories as $category) {
    $id = (int) $category['id'];

    if ((int) ($category['parent_id'] ?? 0) > 0) {
        continue;
    }

    $category['is_child'] = false;
    $categoryTree[] = $category;

    if (isset($childrenByParent[$id])) {
        foreach ($childrenByParent[$id] as $child) {
            $child['is_child'] = true;
            $categoryTree[] = $child;
        }
    }
}

// 异常数据兜底：parent_id 指向的分类已被删除时，这条记录仍要展示出来，不能让记录凭空消失
$shownIds = [];

foreach ($categoryTree as $item) {
    $shownIds[(int) $item['id']] = true;
}

foreach ($categories as $category) {
    if (isset($shownIds[(int) $category['id']])) {
        continue;
    }

    $category['is_child'] = (int) ($category['parent_id'] ?? 0) > 0;
    $categoryTree[] = $category;
}

// 可选上级：只列出一级分类
$parentOptions = [];

foreach ($categories as $category) {
    if ((int) ($category['parent_id'] ?? 0) === 0) {
        $parentOptions[] = $category;
    }
}

$categoryNameById = [];

foreach ($categories as $category) {
    $categoryNameById[(int) $category['id']] = (string) $category['name'];
}

$linkCounts = [];

foreach ($categories as $category) {
    $linkCounts[(int) $category['id']] = navCategoryLinkCount($db, $hasDeletedAt, (int) $category['id']);
}

$childCounts = [];

foreach ($categories as $category) {
    $childCounts[(int) $category['id']] = navCategoryChildCount($db, $hasV2Columns, (int) $category['id']);
}

$editCategory = null;

if (isset($_GET['edit'])) {
    $editId = (int) $_GET['edit'];

    foreach ($categories as $category) {
        if ((int) $category['id'] === $editId) {
            $editCategory = $category;
            break;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>分类管理 - 后台管理</title>
    <link href="../assets/css/bootstrap.css" rel="stylesheet">
    <link href="../assets/css/font-awesome.min.css" rel="stylesheet" onerror="this.onerror=null;this.href='https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css';">
    <link href="../assets/css/dataTables.bootstrap5.min.css" rel="stylesheet" onerror="this.onerror=null;this.href='https://cdn.datatables.net/1.13.4/css/dataTables.bootstrap5.min.css';">
    <link href="../assets/css/admin.css?v=1.1.3" rel="stylesheet">
    <style>
        /* 二级分类行的缩进与连接线，让层级关系一眼可见 */
        .cell-tree {
            display: flex;
            align-items: center;
            gap: 8px;
            min-width: 0;
        }

        .tree-indent {
            display: inline-block;
            width: 20px;
            height: 18px;
            flex: 0 0 20px;
            margin-left: 14px;
            border-left: 1px solid var(--bs-border-color, #dee2e6);
            border-bottom: 1px solid var(--bs-border-color, #dee2e6);
            border-bottom-left-radius: 6px;
        }

        .category-icon-chip {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-width: 26px;
            height: 24px;
            padding: 0 6px;
            border-radius: 6px;
            background: rgba(79, 109, 245, 0.1);
            color: #4f6df5;
            font-size: 12px;
        }

        .category-remark {
            display: block;
            margin-top: 4px;
            color: #94a3b8;
            font-size: 12px;
            line-height: 1.5;
            word-break: break-word;
        }

        .upgrade-hint {
            margin-bottom: 20px;
            padding: 12px 14px;
            border: 1px solid #fde68a;
            border-radius: 10px;
            background: #fffbeb;
            color: #92400e;
            font-size: 13px;
            line-height: 1.7;
        }
    </style>
</head>
<body>
    <?php include 'sidebar.php'; ?>

    <div class="main-content-wrapper" id="mainContentWrapper">
            
        <header class="page-header">
            <div class="page-heading">
                <div class="page-breadcrumb">
                    <span>后台管理</span>
                    <i class="fas fa-angle-right"></i>
                    <span>分类管理</span>
                </div>
                <h1 class="page-title">
                    <span class="title-icon"><i class="fas fa-folder-open"></i></span>
                    分类列表
                </h1>
                <p class="page-subtitle">管理导航分类与排序，支持两级分类、图标与备注，让站点内容结构更加清晰有序。</p>
            </div>
            <div class="page-header-meta">
                <span class="meta-pill">
                    <i class="fas fa-layer-group"></i>
                    共 <?php echo count($categories); ?> 个分类
                </span>
            </div>
        </header>
                
        <div class="admin-page-body">
                    <?php if (!$hasV2Columns): ?>
                        <div class="upgrade-hint">
                            <i class="fas fa-triangle-exclamation"></i>
                            检测到数据库还没有二级分类相关字段。请先执行 <code>upgrade_v2.0.0.sql</code>，
                            升级后即可使用「上级分类 / 图标 / 备注」。当前页面已自动退回到旧版行为，不影响新增、编辑与删除。
                        </div>
                    <?php endif; ?>

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
                    
            <div class="row g-4 align-items-start">
                <div class="col-xl-4 col-lg-5">
                    <div class="card form-card">
                        <div class="card-header">
                            <div>
                                <h5 class="mb-0">
                                    <i class="fas <?php echo $editCategory ? 'fa-edit' : 'fa-plus'; ?>"></i>
                                    <?php echo $editCategory ? '编辑分类' : '添加分类'; ?>
                                </h5>
                                <span class="card-subtitle"><?php echo $editCategory ? '修改分类信息后保存' : '创建一个新的导航分类'; ?></span>
                            </div>
                        </div>
                                <div class="card-body">
                                    <form method="POST">
                                        <?php if ($editCategory): ?>
                                            <input type="hidden" name="id" value="<?php echo (int) $editCategory['id']; ?>">
                                        <?php endif; ?>
                                        
                                        <div class="mb-3">
                                            <label class="form-label" for="name">分类名称 *</label>
                                            <input type="text" 
                                                   id="name"
                                                   name="name" 
                                                   class="form-control" 
                                                   value="<?php echo $editCategory ? $functions->escape($editCategory['name']) : ''; ?>" 
                                                   maxlength="50"
                                                   required>
                                        </div>

                                        <?php if ($hasV2Columns): ?>
                                            <div class="mb-3">
                                                <label class="form-label" for="parent_id">上级分类</label>
                                                <select id="parent_id" name="parent_id" class="form-select">
                                                    <option value="0">作为一级分类</option>
                                                    <?php foreach ($parentOptions as $option): ?>
                                                        <?php
                                                        $optionId = (int) $option['id'];
                                                        $isSelf = $editCategory && (int) $editCategory['id'] === $optionId;
                                                        $hasChildren = ($childCounts[$optionId] ?? 0) > 0 && $editCategory && (int) $editCategory['id'] === $optionId;
                                                        ?>
                                                        <?php if ($isSelf || $hasChildren) { continue; } ?>
                                                        <option value="<?php echo $optionId; ?>"
                                                            <?php echo $editCategory && (int) ($editCategory['parent_id'] ?? 0) === $optionId ? 'selected' : ''; ?>>
                                                            <?php echo $functions->escape($option['name']); ?>
                                                        </option>
                                                    <?php endforeach; ?>
                                                </select>
                                                <small class="text-muted">最多支持两级；已有子分类的分类不能再设为二级分类</small>
                                            </div>

                                            <div class="mb-3">
                                                <label class="form-label" for="icon">分类图标</label>
                                                <input type="text"
                                                       id="icon"
                                                       name="icon"
                                                       class="form-control"
                                                       value="<?php echo $editCategory ? $functions->escape((string) ($editCategory['icon'] ?? '')) : ''; ?>"
                                                       maxlength="100"
                                                       placeholder="如 bi-code、fa-folder 或 emoji">
                                                <small class="text-muted">仅作为标识保存，前台不强制渲染</small>
                                            </div>
                                        <?php endif; ?>
                                        
                                        <div class="mb-3">
                                            <label class="form-label" for="sort_order">排序顺序</label>
                                            <input type="number" 
                                                   id="sort_order"
                                                   name="sort_order" 
                                                   class="form-control" 
                                                   value="<?php echo $editCategory ? (int) $editCategory['sort_order'] : '0'; ?>" 
                                                   min="0">
                                            <small class="text-muted">数字越小越靠前</small>
                                        </div>

                                        <?php if ($hasV2Columns): ?>
                                            <div class="mb-3">
                                                <label class="form-label" for="remark">备注</label>
                                                <textarea id="remark"
                                                          name="remark"
                                                          class="form-control"
                                                          rows="2"
                                                          maxlength="255"
                                                          placeholder="可留空，例如：该分类的收录范围"><?php echo $editCategory ? $functions->escape((string) ($editCategory['remark'] ?? '')) : ''; ?></textarea>
                                            </div>
                                        <?php endif; ?>
                                        
                                        <div class="d-grid gap-2">
                                            <?php if ($editCategory): ?>
                                                <button type="submit" name="edit_category" class="btn btn-primary">
                                                    <i class="fas fa-save me-2"></i>更新分类
                                                </button>
                                                <a href="categories.php" class="btn btn-secondary">
                                                    <i class="fas fa-times me-2"></i>取消编辑
                                                </a>
                                            <?php else: ?>
                                                <button type="submit" name="add_category" class="btn btn-primary">
                                                    <i class="fas fa-plus"></i>添加分类
                                                </button>
                                            <?php endif; ?>
                                        </div>
                                    </form>
                                </div>
                            </div>
                        </div>
                        
                        <div class="col-xl-8 col-lg-7">
                            <div class="card table-card">
                                <div class="card-header">
                                    <div>
                                        <h5 class="mb-0"><i class="fas fa-table-list"></i>分类数据</h5>
                                        <span class="card-subtitle">按层级顺序展示，支持搜索与分页浏览</span>
                                    </div>
                                    <span class="count-badge">
                                        <i class="fas fa-layer-group"></i>
                                        <?php echo count($categories); ?> 条记录
                                    </span>
                                </div>
                                <div class="card-body">
                                    <div class="table-responsive">
                                        <table id="categoriesTable" class="table table-hover admin-table align-middle">
                                            <thead>
                                                <tr>
                                                    <th width="60">ID</th>
                                                    <th>分类名称</th>
                                                    <?php if ($hasV2Columns): ?>
                                                        <th width="110">上级分类</th>
                                                    <?php endif; ?>
                                                    <th width="90">排序</th>
                                                    <th width="90">链接数</th>
                                                    <th width="120">创建时间</th>
                                                    <th width="150">操作</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach ($categoryTree as $category): ?>
                                                    <?php
                                                    $categoryId = (int) $category['id'];
                                                    $isChild = !empty($category['is_child']);
                                                    $parentId = (int) ($category['parent_id'] ?? 0);
                                                    $parentName = $parentId > 0 && isset($categoryNameById[$parentId]) ? $categoryNameById[$parentId] : '';
                                                    $linkCount = $linkCounts[$categoryId] ?? 0;
                                                    $childCount = $childCounts[$categoryId] ?? 0;
                                                    $icon = trim((string) ($category['icon'] ?? ''));
                                                    $remark = trim((string) ($category['remark'] ?? ''));
                                                    ?>
                                                    <tr>
                                                        <td><span class="cell-id"><?php echo $categoryId; ?></span></td>
                                                        <td>
                                                            <div class="cell-tree">
                                                                <?php if ($isChild): ?>
                                                                    <span class="tree-indent" aria-hidden="true"></span>
                                                                <?php endif; ?>
                                                                <div class="flex-grow-1" style="min-width:0;">
                                                                    <div class="d-flex align-items-center gap-2 flex-wrap">
                                                                        <?php if ($icon !== ''): ?>
                                                                            <span class="category-icon-chip"><?php echo $functions->escape($icon); ?></span>
                                                                        <?php endif; ?>
                                                                        <span class="cell-title"><?php echo $functions->escape($category['name']); ?></span>
                                                                        <?php if (!$isChild && $childCount > 0): ?>
                                                                            <span class="badge badge-soft-info"><?php echo $childCount; ?> 个子分类</span>
                                                                        <?php endif; ?>
                                                                    </div>
                                                                    <?php if ($remark !== ''): ?>
                                                                        <span class="category-remark"><?php echo $functions->escape($remark); ?></span>
                                                                    <?php endif; ?>
                                                                </div>
                                                            </div>
                                                        </td>
                                                        <?php if ($hasV2Columns): ?>
                                                            <td>
                                                                <?php if ($isChild && $parentName !== ''): ?>
                                                                    <span class="badge badge-soft-secondary"><?php echo $functions->escape($parentName); ?></span>
                                                                <?php elseif ($isChild): ?>
                                                                    <span class="text-muted">上级已删除</span>
                                                                <?php else: ?>
                                                                    <span class="text-muted">一级分类</span>
                                                                <?php endif; ?>
                                                            </td>
                                                        <?php endif; ?>
                                                        <td><span class="sort-chip"><?php echo (int) $category['sort_order']; ?></span></td>
                                                        <td>
                                                            <span class="badge badge-soft-info"><?php echo $linkCount; ?> 个链接</span>
                                                        </td>
                                                        <td><span class="cell-date"><i class="far fa-calendar"></i><?php echo date('Y-m-d', strtotime((string) $category['created_at'])); ?></span></td>
                                                        <td>
                                                            <div class="table-actions">
                                                                <a href="categories.php?edit=<?php echo $categoryId; ?>" 
                                                                   class="btn btn-action btn-action-edit"
                                                                   title="编辑分类">
                                                                    <i class="fas fa-pen"></i>
                                                                </a>
                                                                <a href="javascript:;" 
                                                                   onclick="confirmDelete(<?php echo $categoryId; ?>, <?php echo $functions->escape(json_encode((string) $category['name'], JSON_UNESCAPED_UNICODE | JSON_HEX_APOS | JSON_HEX_QUOT)); ?>)"
                                                                   class="btn btn-action btn-action-delete"
                                                                   title="删除分类">
                                                                    <i class="fas fa-trash-can"></i>
                                                                </a>
                                                            </div>
                                                        </td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                    
                                    <?php if (empty($categories)): ?>
                                        <div class="text-center py-5 text-muted">
                                            <i class="fas fa-folder-open fa-3x mb-3"></i>
                                            <p>暂无分类，请先添加分类</p>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
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
    <script>
    $(document).ready(function() {
        // 表格数据已按「一级分类 + 其子分类」的顺序输出，因此关闭 DataTables 的自动排序，
        // 否则客户端排序会把子分类从父分类下拆开。搜索与分页仍然可用。
        $('#categoriesTable').DataTable({
            language: {
                url: '../assets/js/dataTables.zh-CN.json'
            },
            ordering: false,
            pageLength: 10,
            columnDefs: [
                { targets: 'no-sort', orderable: false }
            ]
        });
    });
    
    function confirmDelete(id, name) {
        if (confirm('确定要删除分类 "' + name + '" 吗？\n注意：删除分类不会删除该分类下的导航链接，但需要重新为这些链接指定分类。')) {
            window.location.href = 'categories.php?delete=' + id;
        }
    }
    </script>
</body>
</html>
