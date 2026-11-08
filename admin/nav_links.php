<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_once '../includes/functions.php';
// v2.16.0：批量转移/复制归属需要为「未分类」补建分组，故引入 Services
require_once __DIR__ . '/../includes/Services.php';

$functions = new Functions();

if (!$functions->isLoggedIn()) {
    $functions->redirect('login.php');
}

$db = Database::getInstance()->getConnection();
$message = '';
$error = '';

/* ==========================================================================
 * 老库兼容：v2.0.0 新增列（user_id / deleted_at / check_status / latency_ms /
 * last_checked_at / remark）与 users 表可能尚未创建（升级脚本还没执行）。
 * 这里统一探测，缺失时退回旧行为，避免报错。
 * ========================================================================== */
if (!function_exists('nav_links_has_column')) {
    /**
     * 判断表里是否存在某列（用 information_schema，兼容原生预处理）。
     */
    function nav_links_has_column($db, $column, $table = 'nav_links')
    {
        try {
            $stmt = $db->prepare(
                'SELECT COUNT(*) AS total FROM information_schema.columns
                 WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?'
            );
            $stmt->execute([$table, $column]);

            return (int) ($stmt->fetch()['total'] ?? 0) > 0;
        } catch (Throwable $e) {
            return false;
        }
    }
}

if (!function_exists('nav_links_has_table')) {
    /**
     * 判断某表是否存在（用 information_schema，兼容原生预处理）。
     */
    function nav_links_has_table($db, $table)
    {
        try {
            $stmt = $db->prepare(
                'SELECT COUNT(*) AS total FROM information_schema.tables
                 WHERE table_schema = DATABASE() AND table_name = ?'
            );
            $stmt->execute([$table]);

            return (int) ($stmt->fetch()['total'] ?? 0) > 0;
        } catch (Throwable $e) {
            return false;
        }
    }
}

$hasUserId      = nav_links_has_column($db, 'user_id');
$hasDeletedAt   = nav_links_has_column($db, 'deleted_at');
$hasCheckStatus = nav_links_has_column($db, 'check_status');
$hasLatency     = nav_links_has_column($db, 'latency_ms');
$hasRemark      = nav_links_has_column($db, 'remark');
$hasClicks      = nav_links_has_column($db, 'clicks');
$hasAlias       = nav_links_has_column($db, 'alias');
$hasTags        = nav_links_has_column($db, 'tags');
$hasUsersTable  = $hasUserId && nav_links_has_table($db, 'users');

if (!function_exists('navLinkCategoryLabel')) {
    /**
     * 生成后台分类下拉的可读名称：同名分组追加归属后缀。
     * 例如"个人网站（公共）""个人网站（xuanyu）"，避免多个同名选项无法区分。
     */
    function navLinkCategoryLabel(array $category, array $nameCounts)
    {
        $name = trim((string) ($category['name'] ?? ''));

        if (($nameCounts[$name] ?? 0) < 2) {
            return $name;
        }

        $ownerId = (int) ($category['user_id'] ?? 0);

        if ($ownerId <= 0) {
            return $name . '（公共）';
        }

        $owner = trim((string) ($category['owner_name'] ?? ''));

        return $name . '（' . ($owner !== '' ? $owner : '用户#' . $ownerId) . '）';
    }
}

if (!function_exists('navLinkHealthMeta')) {
    /**
     * 健康状态徽章元数据。
     *
     * check_status 是 enum('unknown','ok','slow','down')，为避免数据库结构变更，
     * 这里只在渲染层把 down 细分为「状态码异常」与「超时无响应」：
     * 站点快速返回了 HTTP 错误码，说明它其实是可达的，不该显示"失效 110ms"
     * 这种自相矛盾的状态；真正连不上/超时的才叫超时无响应。
     *
     * @return array{0:string,1:string,2:string} 文案、badge class、说明
     */
    function navLinkHealthMeta($status, $latencyMs, $httpCode = 0)
    {
        $status = (string) $status;
        $latency = ($latencyMs === null || $latencyMs === '') ? null : (int) $latencyMs;
        $code = (int) $httpCode;

        if ($status === 'ok') {
            return ['正常', 'bg-success', '站点响应正常'];
        }

        if ($status === 'slow') {
            return ['较慢', 'bg-warning text-dark', '响应超过 3 秒'];
        }

        if ($status === 'down') {
            $quick = $code > 0 || ($latency !== null && $latency > 0 && $latency < 3000);

            if ($quick) {
                return ['状态码异常', 'bg-warning text-dark', '站点有响应，但返回了错误状态码，不属于无法访问'];
            }

            return ['超时无响应', 'bg-danger', '连接超时或无法建立连接'];
        }

        return ['未检测', 'bg-secondary', '尚未进行健康检测'];
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' || isset($_GET['delete']) || isset($_GET['toggle'])) {
    $functions->requireSameOrigin();
}

/* ==========================================================================
 * 原有功能：新增导航（名称 / 网址 / Logo 上传 / 描述 / 分类 / 排序）
 * 说明：新增了 logo_url 支持（智能抓取回来的图标地址可直接入库），
 *       未使用该字段时行为与原逻辑完全一致。
 * ========================================================================== */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_nav_link'])) {
    $name = trim((string) ($_POST['name'] ?? ''));
    $url = trim((string) ($_POST['url'] ?? ''));
    $description = trim((string) ($_POST['description'] ?? ''));
    $alias = $hasAlias ? trim((string) ($_POST['alias'] ?? '')) : '';
    $tags = $hasTags ? trim((string) ($_POST['tags'] ?? '')) : '';
    $categoryId = intval($_POST['category_id'] ?? 0);
    $sortOrder = intval($_POST['sort_order'] ?? 0);
    
    if (empty($name) || empty($url)) {
        $error = '名称和网址不能为空';
    } elseif (!filter_var($url, FILTER_VALIDATE_URL)) {
        $error = '请输入有效的网址';
    } elseif ($categoryId <= 0) {
        $error = '请选择分类';
    } else {
        // 默认使用智能抓取得到的图标地址（为空则保持空字符串）
        $logo = $functions->sanitizeLogoPath($_POST['logo_url'] ?? '');
        $uploadError = '';
        
        if (isset($_FILES['logo']) && $_FILES['logo']['error'] === UPLOAD_ERR_OK) {
            $uploadResult = $functions->uploadLogo($_FILES['logo']);
            
            if (!empty($uploadResult['success'])) {
                $logo = $uploadResult['relativePath'];
            } else {
                $uploadError = $uploadResult['error'] ?? 'Logo上传失败';
            }
        }
        
        if ($uploadError !== '') {
            $error = $uploadError;
        } else {
            $insertFields = 'name, url, logo, description, category_id, sort_order';
            $insertPlaceholders = '?, ?, ?, ?, ?, ?';
            $insertValues = [$name, $url, $logo, $description, $categoryId, $sortOrder];

            if ($hasAlias) {
                $insertFields .= ', alias';
                $insertPlaceholders .= ', ?';
                $insertValues[] = $alias;
            }

            if ($hasTags) {
                $insertFields .= ', tags';
                $insertPlaceholders .= ', ?';
                $insertValues[] = $tags;
            }

            $stmt = $db->prepare("
                INSERT INTO nav_links ($insertFields)
                VALUES ($insertPlaceholders)
            ");

            if ($stmt->execute($insertValues)) {
                $message = '导航链接添加成功';
                $_POST = [];
            } else {
                $error = '添加失败，请稍后重试';
            }
        }
    }
}

/* ==========================================================================
 * 原有功能：编辑导航
 * ========================================================================== */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['edit_nav_link'])) {
    $id = intval($_POST['id'] ?? 0);
    $name = trim((string) ($_POST['name'] ?? ''));
    $url = trim((string) ($_POST['url'] ?? ''));
    $description = trim((string) ($_POST['description'] ?? ''));
    $alias = $hasAlias ? trim((string) ($_POST['alias'] ?? '')) : '';
    $tags = $hasTags ? trim((string) ($_POST['tags'] ?? '')) : '';
    $categoryId = intval($_POST['category_id'] ?? 0);
    $sortOrder = intval($_POST['sort_order'] ?? 0);
    $isActive = isset($_POST['is_active']) ? 1 : 0;
    
    if (empty($name) || empty($url)) {
        $error = '名称和网址不能为空';
    } elseif (!filter_var($url, FILTER_VALIDATE_URL)) {
        $error = '请输入有效的网址';
    } elseif ($categoryId <= 0) {
        $error = '请选择分类';
    } else {
        $logo = $functions->sanitizeLogoPath($_POST['current_logo'] ?? '');
        
        // 智能抓取得到的新图标地址优先于旧的 Logo
        if ($logo === '') {
            $logo = $functions->sanitizeLogoPath($_POST['logo_url'] ?? '');
        }
        
        $previousLogo = $logo;
        $uploadError = '';
        
        if (isset($_FILES['logo']) && $_FILES['logo']['error'] === UPLOAD_ERR_OK) {
            $uploadResult = $functions->uploadLogo($_FILES['logo']);
            
            if (!empty($uploadResult['success'])) {
                if ($previousLogo !== '') {
                    $functions->deleteLogoFile($previousLogo);
                }
                
                $logo = $uploadResult['relativePath'];
            } else {
                $uploadError = $uploadResult['error'] ?? 'Logo上传失败';
            }
        }
        
        if ($uploadError !== '') {
            $error = $uploadError;
        } else {
            $updateSet = 'name = ?, url = ?, logo = ?, description = ?, category_id = ?, sort_order = ?, is_active = ?';
            $updateValues = [$name, $url, $logo, $description, $categoryId, $sortOrder, $isActive];

            if ($hasAlias) {
                $updateSet .= ', alias = ?';
                $updateValues[] = $alias;
            }

            if ($hasTags) {
                $updateSet .= ', tags = ?';
                $updateValues[] = $tags;
            }

            $stmt = $db->prepare("
                UPDATE nav_links
                SET $updateSet
                WHERE id = ?
            ");

            $updateValues[] = $id;

            if ($stmt->execute($updateValues)) {
                $message = '导航链接更新成功';
            } else {
                $error = '更新失败，请稍后重试';
            }
        }
    }
}

/* ==========================================================================
 * 原有功能：删除（硬删除，保持原行为）
 * ========================================================================== */
if (isset($_GET['delete'])) {
    $id = intval($_GET['delete']);
    
    $stmt = $db->prepare("SELECT logo FROM nav_links WHERE id = ?");
    $stmt->execute([$id]);
    $result = $stmt->fetch();
    
    if ($result && !empty($result['logo'])) {
        $functions->deleteLogoFile($result['logo']);
    }
    
    $deleteStmt = $db->prepare("DELETE FROM nav_links WHERE id = ?");
    if ($deleteStmt->execute([$id])) {
        $message = '导航链接删除成功';
    } else {
        $error = '删除失败，请稍后重试';
    }
}

/* ==========================================================================
 * 原有功能：启用 / 停用切换
 * ========================================================================== */
if (isset($_GET['toggle'])) {
    $id = intval($_GET['toggle']);
    $stmt = $db->prepare("UPDATE nav_links SET is_active = NOT is_active WHERE id = ?");
    if ($stmt->execute([$id])) {
        $message = '状态已更新';
    }
}

/* ==========================================================================
 * 新增：回收站 - 恢复
 * ========================================================================== */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['restore_id'])) {
    $id = intval($_POST['restore_id']);

    if (!$hasDeletedAt) {
        $error = '当前数据库尚未执行 v2.0.0 升级脚本，回收站不可用';
    } elseif ($id <= 0) {
        $error = '记录不存在';
    } else {
        $stmt = $db->prepare("UPDATE nav_links SET deleted_at = NULL WHERE id = ?");
        if ($stmt->execute([$id])) {
            $message = '已恢复到正常列表';
        } else {
            $error = '恢复失败，请稍后重试';
        }
    }
}

/* ==========================================================================
 * 新增：回收站 - 彻底删除（仅允许删除回收站中的记录）
 * ========================================================================== */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['purge_id'])) {
    $id = intval($_POST['purge_id']);

    if (!$hasDeletedAt) {
        $error = '当前数据库尚未执行 v2.0.0 升级脚本，回收站不可用';
    } elseif ($id <= 0) {
        $error = '记录不存在';
    } else {
        $stmt = $db->prepare("SELECT logo FROM nav_links WHERE id = ? AND deleted_at IS NOT NULL LIMIT 1");
        $stmt->execute([$id]);
        $row = $stmt->fetch();

        if (!$row) {
            $error = '记录不存在或不在回收站中';
        } else {
            if (!empty($row['logo'])) {
                $functions->deleteLogoFile($row['logo']);
            }

            $purgeStmt = $db->prepare("DELETE FROM nav_links WHERE id = ? AND deleted_at IS NOT NULL");
            if ($purgeStmt->execute([$id])) {
                $message = '已彻底删除该导航链接';
            } else {
                $error = '彻底删除失败，请稍后重试';
            }
        }
    }
}

/* ==========================================================================
 * v2.16.0：回收站 - 一键清空（彻底删除回收站内全部记录）
 * ========================================================================== */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['empty_trash'])) {
    if (!$hasDeletedAt) {
        $error = '当前数据库尚未执行 v2.0.0 升级脚本，回收站不可用';
    } else {
        try {
            // 先取全部回收站记录的 logo，物理文件随后一并清理，避免留下孤儿文件
            $logos = $db->query('SELECT logo FROM nav_links WHERE deleted_at IS NOT NULL')
                ->fetchAll(PDO::FETCH_COLUMN);

            $stmt = $db->prepare('DELETE FROM nav_links WHERE deleted_at IS NOT NULL');
            $stmt->execute();
            $purged = $stmt->rowCount();

            foreach ($logos as $logo) {
                if (!empty($logo)) {
                    $functions->deleteLogoFile((string) $logo);
                }
            }

            $message = $purged > 0 ? '回收站已清空，共彻底删除 ' . $purged . ' 条导航链接' : '回收站已经是空的';
        } catch (Throwable $e) {
            $error = '清空回收站失败，请稍后重试';
        }
    }
}

/* ==========================================================================
 * 新增：批量操作（移动到分类 / 移入回收站 / 批量启用 / 批量停用 / 导出 CSV）
 * 全部走 POST + requireSameOrigin，且只处理选中的 id。
 * ========================================================================== */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['batch_action'])) {
    $batchAction = trim((string) $_POST['batch_action']);
    $rawIds = $_POST['ids'] ?? [];
    $ids = [];

    if (is_array($rawIds)) {
        foreach ($rawIds as $rawId) {
            $idValue = intval($rawId);
            if ($idValue > 0) {
                $ids[] = $idValue;
            }
        }
    }

    $ids = array_values(array_unique($ids));

    if (!$ids) {
        $error = '请先选择要操作的记录';
    } else {
        $placeholders = implode(',', array_fill(0, count($ids), '?'));

        if ($batchAction === 'enable' || $batchAction === 'disable') {
            $isActive = $batchAction === 'enable' ? 1 : 0;
            $stmt = $db->prepare("UPDATE nav_links SET is_active = ? WHERE id IN ($placeholders)");
            if ($stmt->execute(array_merge([$isActive], $ids))) {
                $message = ($isActive ? '已启用 ' : '已停用 ') . $stmt->rowCount() . ' 条导航链接';
            } else {
                $error = '状态更新失败，请稍后重试';
            }
        } elseif ($batchAction === 'move_category') {
            $targetCategory = intval($_POST['batch_category_id'] ?? 0);

            if ($targetCategory <= 0) {
                $error = '请选择要移动到的分类';
            } else {
                $checkStmt = $db->prepare("SELECT id FROM categories WHERE id = ? LIMIT 1");
                $checkStmt->execute([$targetCategory]);

                if (!$checkStmt->fetch()) {
                    $error = '目标分类不存在';
                } else {
                    $stmt = $db->prepare("UPDATE nav_links SET category_id = ? WHERE id IN ($placeholders)");
                    if ($stmt->execute(array_merge([$targetCategory], $ids))) {
                        $message = '已移动分类 ' . $stmt->rowCount() . ' 条导航链接';
                    } else {
                        $error = '批量移动分类失败，请稍后重试';
                    }
                }
            }
        } elseif ($batchAction === 'transfer_owner' || $batchAction === 'copy_owner') {
            /* v2.16.0 归属批量管理：
               - transfer_owner：把选中记录整体改挂到目标用户名下（原记录被移动）
               - copy_owner：为目标用户复制一份，原记录保持不动
               目标可以是「公共」（batch_owner_id=0）或某个用户。
               用户被移走时，原分类属于旧用户，新用户无权使用，这里统一改挂到目标用户的「未分类」
               （copy 时只在分类归属与新归属不一致时才调整，避免无谓改动）。 */
            if (!$hasUserId) {
                $error = '当前数据库尚未执行 v2.0.0 升级脚本，暂不支持归属管理';
            } else {
                $targetOwner = intval($_POST['batch_owner_id'] ?? 0);

                if ($targetOwner < 0) {
                    $error = '请选择目标归属';
                } else {
                    $ownerValid = true;

                    if ($targetOwner > 0) {
                        $ownerCheck = $db->prepare('SELECT id FROM users WHERE id = ? LIMIT 1');
                        $ownerCheck->execute([$targetOwner]);
                        $ownerValid = (bool) $ownerCheck->fetch();
                    }

                    if (!$ownerValid) {
                        $error = '目标用户不存在';
                    } else {
                        $engine = new CategoryEngine($functions);
                        $engine->setUserId($targetOwner);
                        $fallbackCategory = $engine->ensure('未分类', 'bi-collection');
                        $affected = 0;

                        try {
                            $db->beginTransaction();

                            // 记录级查询：拿到当前归属与分类，逐条判断是否需要改挂分类
                            $rowStmt = $db->prepare(
                                'SELECT nl.id, nl.user_id, nl.category_id, c.user_id AS category_owner
                                 FROM nav_links nl
                                 LEFT JOIN categories c ON nl.category_id = c.id
                                 WHERE nl.id IN (' . $placeholders . ') AND nl.deleted_at IS NULL'
                            );
                            $rowStmt->execute($ids);
                            $rows = $rowStmt->fetchAll();

                            foreach ($rows as $row) {
                                $categoryOwner = $row['category_owner'] === null ? null : (int) $row['category_owner'];
                                // 分类不属于目标归属（公共分类 user_id=0 允许所有人用）时改挂未分类
                                $needFallback = $categoryOwner !== null
                                    && $categoryOwner !== 0
                                    && $categoryOwner !== $targetOwner;

                                if ($batchAction === 'transfer_owner') {
                                    $update = $db->prepare(
                                        'UPDATE nav_links SET user_id = ?' . ($needFallback ? ', category_id = ?' : '') . '
                                         WHERE id = ? AND deleted_at IS NULL'
                                    );
                                    $params = [$targetOwner];
                                    if ($needFallback) {
                                        $params[] = $fallbackCategory;
                                    }
                                    $params[] = (int) $row['id'];
                                    $update->execute($params);
                                    $affected += $update->rowCount();
                                } else {
                                    // 复制：新增一条，字段与源记录一致，归属改为目标
                                    $srcStmt = $db->prepare('SELECT * FROM nav_links WHERE id = ? LIMIT 1');
                                    $srcStmt->execute([(int) $row['id']]);
                                    $src = $srcStmt->fetch();

                                    if (!$src) {
                                        continue;
                                    }

                                    // 目标归属下排序放到末尾
                                    $sortStmt = $db->prepare('SELECT COALESCE(MAX(sort_order), 0) + 1 AS next_sort FROM nav_links WHERE user_id = ?');
                                    $sortStmt->execute([$targetOwner]);
                                    $nextSort = (int) ($sortStmt->fetch()['next_sort'] ?? 1);

                                    $copyColumns = ['name', 'url', 'logo', 'description', 'category_id', 'user_id',
                                        'sort_order', 'is_active', 'created_at'];
                                    $copyValues = [
                                        (string) $src['name'],
                                        (string) $src['url'],
                                        (string) ($src['logo'] ?? ''),
                                        (string) ($src['description'] ?? ''),
                                        $needFallback ? $fallbackCategory : (int) $src['category_id'],
                                        $targetOwner,
                                        $nextSort,
                                        (int) $src['is_active'],
                                        date('Y-m-d H:i:s'),
                                    ];

                                    $insert = $db->prepare(
                                        'INSERT INTO nav_links (' . implode(', ', $copyColumns) . ')
                                         VALUES (' . implode(', ', array_fill(0, count($copyColumns), '?')) . ')'
                                    );
                                    $insert->execute($copyValues);
                                    $affected++;
                                }
                            }

                            $db->commit();
                        } catch (Throwable $e) {
                            if ($db->inTransaction()) {
                                $db->rollBack();
                            }
                            $affected = -1;
                        }

                        if ($affected < 0) {
                            $error = '归属操作失败，已回滚，请稍后重试';
                        } elseif ($affected === 0) {
                            $error = '选中的记录均已不在正常列表中，未做任何修改';
                        } else {
                            $ownerLabel = '公共';

                            if ($targetOwner > 0) {
                                $ownerLabel = '用户#' . $targetOwner;
                                $nameStmt = $db->prepare('SELECT username FROM users WHERE id = ? LIMIT 1');
                                $nameStmt->execute([$targetOwner]);
                                $ownerName = (string) ($nameStmt->fetch()['username'] ?? '');

                                if ($ownerName !== '') {
                                    $ownerLabel = $ownerName;
                                }
                            }
                            $message = ($batchAction === 'transfer_owner' ? '已转移 ' : '已复制 ') . $affected
                                . ' 条导航链接到「' . $ownerLabel . '」';
                        }
                    }
                }
            }
        } elseif ($batchAction === 'trash') {
            if (!$hasDeletedAt) {
                $error = '当前数据库尚未执行 v2.0.0 升级脚本，回收站不可用';
            } else {
                $stmt = $db->prepare("UPDATE nav_links SET deleted_at = NOW() WHERE id IN ($placeholders) AND deleted_at IS NULL");
                if ($stmt->execute($ids)) {
                    $message = '已移入回收站 ' . $stmt->rowCount() . ' 条导航链接';
                } else {
                    $error = '批量移入回收站失败，请稍后重试';
                }
            }
        } elseif ($batchAction === 'export_csv') {
            // 只导出选中项：名称 / 网址 / 分类 / 描述 / 备注 / 点击量
            $csvFields = "nl.name, nl.url, nl.description, c.name AS category_name";

            if ($hasRemark) {
                $csvFields .= ", nl.remark";
            }

            $csvFields .= $hasClicks ? ", nl.clicks" : ", 0 AS clicks";

            $csvStmt = $db->prepare(
                "SELECT $csvFields FROM nav_links nl
                 LEFT JOIN categories c ON nl.category_id = c.id
                 WHERE nl.id IN ($placeholders)"
            );
            $csvStmt->execute($ids);
            $csvRows = $csvStmt->fetchAll();

            $csvHeader = ['名称', '网址', '分类', '描述'];
            if ($hasRemark) {
                $csvHeader[] = '备注';
            }
            $csvHeader[] = '点击量';

            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename="nav_links_' . date('YmdHis') . '.csv"');
            header('Cache-Control: no-store, max-age=0');

            $output = fopen('php://output', 'w');
            // UTF-8 BOM，避免 Excel 打开中文乱码
            fwrite($output, "\xEF\xBB\xBF");
            fputcsv($output, $csvHeader);

            foreach ($csvRows as $csvRow) {
                $line = [
                    (string) $csvRow['name'],
                    (string) $csvRow['url'],
                    (string) ($csvRow['category_name'] ?? ''),
                    (string) ($csvRow['description'] ?? ''),
                ];

                if ($hasRemark) {
                    $line[] = (string) ($csvRow['remark'] ?? '');
                }

                $line[] = $hasClicks ? (int) $csvRow['clicks'] : 0;

                fputcsv($output, $line);
            }

            fclose($output);
            exit;
        } else {
            $error = '未知的批量操作';
        }
    }
}

/* ==========================================================================
 * 新增：拖拽排序保存（fetch 调用，返回 JSON）
 * ========================================================================== */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['sort_ids'])) {
    $rawIds = $_POST['sort_ids'];
    $ids = [];

    if (is_array($rawIds)) {
        foreach ($rawIds as $rawId) {
            $idValue = intval($rawId);
            if ($idValue > 0) {
                $ids[] = $idValue;
            }
        }
    }

    $ids = array_values(array_unique($ids));

    if (!$ids) {
        $functions->jsonResponse(['ok' => false, 'error' => 'empty_selection']);
    }

    try {
        $db->beginTransaction();
        $stmt = $db->prepare("UPDATE nav_links SET sort_order = ? WHERE id = ?");
        $position = 1;

        foreach ($ids as $idValue) {
            $stmt->execute([$position, $idValue]);
            $position++;
        }

        $db->commit();
        $functions->jsonResponse(['ok' => true, 'count' => count($ids)]);
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }

        $functions->logMessage('[nav_links] 排序保存失败: ' . $e->getMessage(), 'ERROR');
        $functions->jsonResponse(['ok' => false, 'error' => 'save_failed'], 500);
    }
}

// 同名分类（不同用户各自建了"个人网站""我的收藏"）在下拉里无法区分，
// 这里把归属用户名一并取出，为后续生成带后缀的显示名做准备。
// 老库没有 users 表 / categories.user_id 时退回原始查询，不影响使用。
try {
    $categories = $db->query(
        'SELECT c.*, u.username AS owner_name
         FROM categories c
         LEFT JOIN users u ON c.user_id = u.id
         ORDER BY c.sort_order, c.id'
    )->fetchAll();
} catch (Throwable $categoryJoinError) {
    $categories = $db->query('SELECT * FROM categories ORDER BY sort_order, id')->fetchAll();
}

$categoryNameCounts = [];
foreach ($categories as $category) {
    $categoryName = (string) ($category['name'] ?? '');
    $categoryNameCounts[$categoryName] = ($categoryNameCounts[$categoryName] ?? 0) + 1;
}

foreach ($categories as $index => $category) {
    $categories[$index]['display_name'] = navLinkCategoryLabel($category, $categoryNameCounts);
}

$editNavLink = null;
if (isset($_GET['edit'])) {
    $id = intval($_GET['edit']);
    $stmt = $db->prepare("SELECT * FROM nav_links WHERE id = ?");
    $stmt->execute([$id]);
    $editNavLink = $stmt->fetch();
}

$search = isset($_GET['search']) && is_string($_GET['search']) ? $_GET['search'] : '';
$categoryFilter = isset($_GET['category']) && is_string($_GET['category']) ? $_GET['category'] : 'all';
$statusFilter = isset($_GET['status']) && is_string($_GET['status']) ? $_GET['status'] : 'all';

// Tab：正常列表 / 回收站（数据库缺少 deleted_at 时强制回到正常列表）
$tab = (isset($_GET['tab']) && $_GET['tab'] === 'recycle') ? 'recycle' : 'normal';
if (!$hasDeletedAt) {
    $tab = 'normal';
}

// 归属筛选：全部 / 公共(user_id=0) / 指定用户
$ownerFilter = isset($_GET['owner']) && is_string($_GET['owner']) ? $_GET['owner'] : 'all';
$ownerUserId = 0;

if (!$hasUserId) {
    $ownerFilter = 'all';
} elseif ($ownerFilter !== 'all' && $ownerFilter !== 'public') {
    $ownerUserId = intval($ownerFilter);
    $ownerFilter = $ownerUserId > 0 ? (string) $ownerUserId : 'all';
}

$selectFields = "nl.*, c.name as category_name";
if ($hasUsersTable) {
    $selectFields .= ", u.username as owner_username";
}

$query = "SELECT $selectFields FROM nav_links nl 
          LEFT JOIN categories c ON nl.category_id = c.id";

if ($hasUsersTable) {
    $query .= " LEFT JOIN users u ON nl.user_id = u.id";
}

$query .= " WHERE 1=1";
$params = [];

if ($hasDeletedAt) {
    $query .= ($tab === 'recycle') ? " AND nl.deleted_at IS NOT NULL" : " AND nl.deleted_at IS NULL";
}

if (!empty($search)) {
    $query .= " AND (nl.name LIKE ? OR nl.url LIKE ? OR nl.description LIKE ?)";
    $searchTerm = "%$search%";
    $params[] = $searchTerm;
    $params[] = $searchTerm;
    $params[] = $searchTerm;
}

if ($categoryFilter !== 'all') {
    $query .= " AND nl.category_id = ?";
    $params[] = $categoryFilter;
}

if ($statusFilter === 'active') {
    $query .= " AND nl.is_active = 1";
} elseif ($statusFilter === 'inactive') {
    $query .= " AND nl.is_active = 0";
}

if ($hasUserId && $ownerFilter !== 'all') {
    if ($ownerFilter === 'public') {
        $query .= " AND nl.user_id = 0";
    } else {
        $query .= " AND nl.user_id = ?";
        $params[] = $ownerUserId;
    }
}

if ($tab === 'recycle') {
    $query .= " ORDER BY nl.deleted_at DESC, nl.id DESC";
} else {
    $query .= " ORDER BY nl.sort_order, nl.id";
}

$stmt = $db->prepare($query);
$stmt->execute($params);
$navLinks = $stmt->fetchAll();

// 归属下拉可选项（users 表存在时）
$userOptions = [];
if ($hasUsersTable) {
    try {
        $userOptions = $db->query("SELECT id, username FROM users ORDER BY username, id")->fetchAll();
    } catch (Throwable $e) {
        $userOptions = [];
    }
}

// 计数（排除回收站记录）
$countSuffix = $hasDeletedAt ? " AND deleted_at IS NULL" : "";
$totalCount = (int) $db->query("SELECT COUNT(*) as count FROM nav_links WHERE 1=1" . $countSuffix)->fetch()['count'];
$activeCount = (int) $db->query("SELECT COUNT(*) as count FROM nav_links WHERE is_active = 1" . $countSuffix)->fetch()['count'];
$trashCount = $hasDeletedAt
    ? (int) $db->query("SELECT COUNT(*) as count FROM nav_links WHERE deleted_at IS NOT NULL")->fetch()['count']
    : 0;

// 健康状态徽章映射统一由 navLinkHealthMeta() 提供（见文件头部）。
// down 会按响应快慢细分为「状态码异常」/「超时无响应」，避免"失效 110ms"这类矛盾显示。

// Tab 链接（保留当前筛选条件）
$filterQuery = [];
if (!empty($search)) {
    $filterQuery['search'] = $search;
}
if ($categoryFilter !== 'all') {
    $filterQuery['category'] = $categoryFilter;
}
if ($statusFilter !== 'all') {
    $filterQuery['status'] = $statusFilter;
}
if ($ownerFilter !== 'all') {
    $filterQuery['owner'] = $ownerFilter;
}

$normalTabUrl = 'nav_links.php?' . http_build_query(array_merge($filterQuery, ['tab' => 'normal']));
$recycleTabUrl = 'nav_links.php?' . http_build_query(array_merge($filterQuery, ['tab' => 'recycle']));

$baseUrl = dirname($_SERVER['PHP_SELF']);
if ($baseUrl == '/admin' || $baseUrl == '\\admin') {
    $basePath = '../';
} else {
    $basePath = '';
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>导航管理 - 后台管理</title>
    <link href="../assets/css/bootstrap.css" rel="stylesheet">
    <link href="../assets/css/font-awesome.min.css" rel="stylesheet" onerror="this.onerror=null;this.href='https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css';">
    <link href="../assets/css/dataTables.bootstrap5.min.css" rel="stylesheet" onerror="this.onerror=null;this.href='https://cdn.datatables.net/1.13.4/css/dataTables.bootstrap5.min.css';">
    <style>
        .logo-preview {
            width: 60px;
            height: 60px;
            object-fit: contain;
            background: #f8f9fa;
            border-radius: 8px;
            padding: 4px;
        }
        .logo-placeholder {
            width: 60px;
            height: 60px;
            background: #f8f9fa;
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #6c757d;
            font-size: 24px;
        }
        .broken-image {
            width: 60px;
            height: 60px;
            background: #ffeaea;
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #dc3545;
            font-size: 24px;
        }
        .smart-logo-preview {
            width: 60px;
            height: 60px;
            object-fit: contain;
            background: #f8f9fa;
            border-radius: 8px;
            padding: 4px;
        }
        .nav-drag-handle {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 28px;
            height: 28px;
            color: #adb5bd;
            cursor: grab;
            border-radius: 6px;
        }
        .nav-drag-handle:hover,
        .nav-drag-handle:focus {
            color: #0d6efd;
            background: #eef4ff;
            outline: none;
        }
        tr.dragging {
            opacity: .55;
            background: #fff8e1;
        }
        .health-latency {
            font-size: 12px;
            color: #6c757d;
            white-space: nowrap;
        }
        .batch-toolbar {
            gap: .5rem;
        }
        /* v2.16.0：批量操作栏置顶 + 分组分隔线；窄屏下按钮自动换行、不溢出 */
        .batch-toolbar-wrap {
            position: sticky;
            top: 0;
            z-index: 5;
            padding: .6rem .75rem;
            background: #fff;
            border: 1px solid #e9ecef;
            border-radius: .5rem;
            box-shadow: 0 1px 3px rgba(0, 0, 0, .04);
        }
        .batch-toolbar .batch-divider {
            width: 1px;
            height: 22px;
            background: #dee2e6;
        }
        .batch-toolbar .btn,
        .batch-toolbar .form-select {
            white-space: nowrap;
        }
        @media (max-width: 575.98px) {
            .batch-toolbar .batch-divider {
                display: none;
            }
            .batch-toolbar .btn,
            .batch-toolbar .form-select {
                flex: 1 1 auto;
                min-width: 0;
            }
        }
    </style>
    <link href="../assets/css/admin.css?v=2.16.5" rel="stylesheet">
    <link href="../assets/css/category-quick-add.css?v=2.16.5" rel="stylesheet">
</head>
<body>
    <?php include 'sidebar.php'; ?>

    <div class="main-content-wrapper" id="mainContentWrapper">
        <header class="page-header">
            <div class="page-heading">
                <div class="page-breadcrumb">
                    <span>后台管理</span>
                    <i class="fas fa-angle-right"></i>
                    <span>导航管理</span>
                </div>
                <h1 class="page-title">
                    <span class="title-icon"><i class="fas fa-link"></i></span>
                    导航管理
                </h1>
                <p class="page-subtitle">维护导航站点信息、所属分类、启用状态与链接健康情况。</p>
            </div>
            <div class="page-header-meta">
                <span class="meta-pill">
                    <i class="fas fa-link"></i>
                    当前 <?php echo count($navLinks); ?> 条链接
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
                    
                    <?php if (!$hasDeletedAt): ?>
                        <div class="alert alert-warning" role="alert">
                            <i class="fas fa-triangle-exclamation me-1"></i>
                            当前数据库尚未执行 v2.0.0 升级脚本，回收站、归属与健康检测功能暂不可用，页面已自动退回旧版行为。
                        </div>
                    <?php endif; ?>
                    
                    <div class="row">
                        <div class="col-md-4 mb-4">
                            <div class="card">
                                <div class="card-header">
                                    <h5 class="mb-0">
                                        <i class="fas <?php echo $editNavLink ? 'fa-edit' : 'fa-plus'; ?> me-2"></i>
                                        <?php echo $editNavLink ? '编辑导航' : '添加导航'; ?>
                                    </h5>
                                </div>
                                <div class="card-body">
                                    <form method="POST" enctype="multipart/form-data">
                                        <?php if ($editNavLink): ?>
                                            <input type="hidden" name="id" value="<?php echo $editNavLink['id']; ?>">
                                            <?php if (!empty($editNavLink['logo'])): ?>
                                                <input type="hidden" name="current_logo" value="<?php echo $functions->escape($editNavLink['logo']); ?>">
                                            <?php endif; ?>
                                        <?php endif; ?>
                                        
                                        <div class="mb-3">
                                            <label class="form-label">网站名称 *</label>
                                            <input type="text" 
                                                   id="navNameInput"
                                                   name="name" 
                                                   class="form-control" 
                                                   value="<?php echo $editNavLink ? $functions->escape($editNavLink['name']) : $functions->escape($_POST['name'] ?? ''); ?>" 
                                                   required>
                                        </div>
                                        
                                        <div class="mb-3">
                                            <label class="form-label">网站网址 *</label>
                                            <div class="input-group">
                                                <input type="url" 
                                                       id="navUrlInput"
                                                       name="url" 
                                                       class="form-control" 
                                                       value="<?php echo $editNavLink ? $functions->escape($editNavLink['url']) : $functions->escape($_POST['url'] ?? ''); ?>" 
                                                       placeholder="https://"
                                                       required>
                                                <button type="button" class="btn btn-outline-primary" id="smartFetchBtn" onclick="smartFetch()">
                                                    <i class="fas fa-wand-magic-sparkles me-1"></i>智能抓取
                                                </button>
                                            </div>
                                            <small class="text-muted d-block mt-1" id="smartFetchTip"></small>
                                        </div>
                                        
                                        <div class="mb-3">
                                            <label class="form-label">网站Logo</label>
                                            <?php if ($editNavLink && !empty($editNavLink['logo'])): ?>
                                                <div class="mb-2">
                                                    <?php $logoUrl = $functions->getLogoUrl($editNavLink['logo']); ?>
                                                    <?php if ($logoUrl !== ''): ?>
                                                        <img src="<?php echo $functions->escape($logoUrl); ?>" 
                                                             alt="Logo" 
                                                             class="logo-preview"
                                                             onerror="this.style.display='none';this.parentNode.innerHTML='<div class=\'broken-image\'><i class=\'fas fa-exclamation-triangle\'></i></div>';">
                                                    <?php else: ?>
                                                        <div class="broken-image">
                                                            <i class="fas fa-exclamation-triangle"></i>
                                                        </div>
                                                    <?php endif; ?>
                                                    <br>
                                                    <small class="text-muted">当前Logo</small>
                                                </div>
                                            <?php endif; ?>
                                            <div class="mb-2" id="navLogoUrlPreviewWrap" style="display:none;">
                                                <img id="navLogoUrlPreview" src="" alt="图标预览" class="smart-logo-preview"
                                                     onerror="this.style.display='none';">
                                                <br>
                                                <small class="text-muted">智能抓取到的图标</small>
                                            </div>
                                            <input type="hidden" name="logo_url" id="navLogoUrlInput" value="<?php echo $functions->escape($_POST['logo_url'] ?? ''); ?>">
                                            <input type="file" 
                                                   name="logo" 
                                                   class="form-control" 
                                                   accept="image/*">
                                            <small class="text-muted">建议尺寸：120x120 像素，支持 JPG/PNG/GIF/ICO 格式</small>
                                        </div>
                                        
                                        <div class="mb-3">
                                            <label class="form-label">网站描述</label>
                                            <textarea name="description"
                                                      id="navDescInput"
                                                      class="form-control"
                                                      rows="3"><?php echo $editNavLink ? $functions->escape($editNavLink['description']) : $functions->escape($_POST['description'] ?? ''); ?></textarea>
                                        </div>

                                        <?php if ($hasAlias): ?>
                                        <div class="mb-3">
                                            <label class="form-label">网站别名</label>
                                            <input type="text"
                                                   name="alias"
                                                   class="form-control"
                                                   value="<?php echo $editNavLink ? $functions->escape($editNavLink['alias'] ?? '') : $functions->escape($_POST['alias'] ?? ''); ?>"
                                                   placeholder="多个别名用空格分隔，如：视频网站 追剧"
                                                   maxlength="255">
                                            <small class="text-muted d-block mt-1">用于站内搜索与搜索联想，可留空</small>
                                        </div>
                                        <?php endif; ?>

                                        <?php if ($hasTags): ?>
                                        <div class="mb-3">
                                            <label class="form-label">网站标签</label>
                                            <input type="text"
                                                   name="tags"
                                                   class="form-control"
                                                   value="<?php echo $editNavLink ? $functions->escape($editNavLink['tags'] ?? '') : $functions->escape($_POST['tags'] ?? ''); ?>"
                                                   placeholder="多个标签用空格分隔，如：AI 工具 办公"
                                                   maxlength="255">
                                            <small class="text-muted d-block mt-1">用于站内搜索与搜索联想，可留空</small>
                                        </div>
                                        <?php endif; ?>
                                        
                                        <div class="mb-3">
                                            <label class="form-label d-flex align-items-center justify-content-between" for="navCategorySelect">
                                                <span>所属分类 *</span>
                                                <button type="button" class="xycq-trigger xycq-trigger-compact js-category-create"
                                                        title="不离开当前页面，快速新建一个分类">
                                                    <i class="fas fa-plus" aria-hidden="true"></i>新建分类
                                                </button>
                                            </label>
                                            <select name="category_id" id="navCategorySelect" class="form-select" data-category-options required>
                                                <option value="">请选择分类</option>
                                                <?php foreach ($categories as $category): ?>
                                                    <option value="<?php echo $category['id']; ?>"
                                                            <?php echo ($editNavLink && $editNavLink['category_id'] == $category['id']) ? 'selected' : ''; ?>>
                                                        <?php echo $functions->escape($category['display_name'] ?? $category['name']); ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                        
                                        <div class="mb-3">
                                            <label class="form-label">排序顺序</label>
                                            <input type="number" 
                                                   name="sort_order" 
                                                   class="form-control" 
                                                   value="<?php echo $editNavLink ? $editNavLink['sort_order'] : '0'; ?>" 
                                                   min="0">
                                            <small class="text-muted">数字越小越靠前，也可在列表中拖拽调整</small>
                                        </div>
                                        
                                        <?php if ($editNavLink): ?>
                                            <div class="mb-3">
                                                <div class="form-check form-switch">
                                                    <input class="form-check-input" 
                                                           type="checkbox" 
                                                           name="is_active" 
                                                           id="is_active" 
                                                           value="1" 
                                                           <?php echo $editNavLink['is_active'] ? 'checked' : ''; ?>>
                                                    <label class="form-check-label" for="is_active">
                                                        是否启用
                                                    </label>
                                                </div>
                                            </div>
                                        <?php endif; ?>
                                        
                                        <div class="d-grid gap-2">
                                            <?php if ($editNavLink): ?>
                                                <button type="submit" name="edit_nav_link" class="btn btn-primary">
                                                    <i class="fas fa-save me-2"></i>更新导航
                                                </button>
                                                <a href="nav_links.php" class="btn btn-secondary">
                                                    <i class="fas fa-times me-2"></i>取消编辑
                                                </a>
                                            <?php else: ?>
                                                <button type="submit" name="add_nav_link" class="btn btn-primary">
                                                    <i class="fas fa-plus me-2"></i>添加导航
                                                </button>
                                            <?php endif; ?>
                                        </div>
                                    </form>
                                </div>
                            </div>
                        </div>
                        
                        <div class="col-md-8 mb-4">
                            <div class="card">
                                <div class="card-header">
                                    <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
                                        <h5 class="mb-0">导航列表</h5>
                                        <div>
                                            <span class="badge bg-success">启用：<?php echo $activeCount; ?></span>
                                            <span class="badge bg-secondary ms-2">总计：<?php echo $totalCount; ?></span>
                                            <?php if ($hasDeletedAt): ?>
                                                <span class="badge bg-danger ms-2">回收站：<?php echo $trashCount; ?></span>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                    <?php if ($hasDeletedAt): ?>
                                        <ul class="nav nav-tabs mt-3">
                                            <li class="nav-item">
                                                <a class="nav-link <?php echo $tab === 'normal' ? 'active' : ''; ?>" href="<?php echo $functions->escape($normalTabUrl); ?>">
                                                    <i class="fas fa-list me-1"></i>正常列表
                                                </a>
                                            </li>
                                            <li class="nav-item">
                                                <a class="nav-link <?php echo $tab === 'recycle' ? 'active' : ''; ?>" href="<?php echo $functions->escape($recycleTabUrl); ?>">
                                                    <i class="fas fa-trash-can me-1"></i>回收站
                                                    <span class="badge bg-danger ms-1"><?php echo $trashCount; ?></span>
                                                </a>
                                            </li>
                                        </ul>
                                    <?php endif; ?>
                                </div>
                                <div class="card-body">
                                    <?php if (!empty($navLinks)): ?>
                                        <!-- v2.16.0：批量操作栏移到列表顶部，勾选后即可操作 -->
                                        <div class="batch-toolbar-wrap mb-3">
                                            <form method="POST" id="batchForm">
                                                <div class="d-flex flex-wrap justify-content-between align-items-center batch-toolbar">
                                                    <div class="text-muted small">
                                                        <i class="fas fa-check-square me-1"></i>已选 <span id="selectedCount">0</span> 项
                                                    </div>
                                                    <div class="d-flex flex-wrap align-items-center gap-2">
                                                        <select name="batch_category_id" class="form-select form-select-sm" data-category-options style="width:auto;">
                                                            <option value="">移动到分类…</option>
                                                            <?php foreach ($categories as $category): ?>
                                                                <option value="<?php echo $category['id']; ?>"><?php echo $functions->escape($category['display_name'] ?? $category['name']); ?></option>
                                                            <?php endforeach; ?>
                                                        </select>
                                                        <button type="button" class="btn btn-sm btn-outline-primary" onclick="submitBatch('move_category')">
                                                            <i class="fas fa-folder-tree me-1"></i>移动
                                                        </button>
                                                        <?php if ($hasUserId): ?>
                                                            <span class="batch-divider"></span>
                                                            <select name="batch_owner_id" id="batchOwnerSelect" class="form-select form-select-sm" style="width:auto;">
                                                                <option value="">归属用户…</option>
                                                                <option value="0">公共（所有人可见）</option>
                                                                <?php foreach ($userOptions as $userOption): ?>
                                                                    <option value="<?php echo (int) $userOption['id']; ?>"><?php echo $functions->escape((string) $userOption['username']); ?></option>
                                                                <?php endforeach; ?>
                                                            </select>
                                                            <button type="button" class="btn btn-sm btn-outline-info" onclick="submitBatch('transfer_owner')" title="把选中网址整体改挂到目标用户名下">
                                                                <i class="fas fa-right-left me-1"></i>批量转移归属
                                                            </button>
                                                            <button type="button" class="btn btn-sm btn-outline-info" onclick="submitBatch('copy_owner')" title="为目标用户复制一份，原网址保留">
                                                                <i class="fas fa-clone me-1"></i>批量复制归属
                                                            </button>
                                                            <span class="batch-divider"></span>
                                                        <?php endif; ?>
                                                        <button type="button" class="btn btn-sm btn-outline-success" onclick="submitBatch('enable')">
                                                            <i class="fas fa-check me-1"></i>批量启用
                                                        </button>
                                                        <button type="button" class="btn btn-sm btn-outline-warning" onclick="submitBatch('disable')">
                                                            <i class="fas fa-ban me-1"></i>批量停用
                                                        </button>
                                                        <?php if ($hasDeletedAt): ?>
                                                            <button type="button" class="btn btn-sm btn-outline-danger" onclick="submitBatch('trash')">
                                                                <i class="fas fa-trash-can me-1"></i>批量删除
                                                            </button>
                                                        <?php endif; ?>
                                                        <button type="button" class="btn btn-sm btn-outline-dark" onclick="submitBatch('export_csv')">
                                                            <i class="fas fa-file-csv me-1"></i>导出 CSV
                                                        </button>
                                                    </div>
                                                </div>
                                                <div id="batchFormFields"></div>
                                            </form>
                                        </div>
                                    <?php endif; ?>

                                    <?php if ($tab === 'normal'): ?>
                                    <form method="GET" class="row g-3 mb-4 align-items-end">
                                        <input type="hidden" name="tab" value="normal">
                                        <div class="col-md-3">
                                            <input type="text" 
                                                   name="search" 
                                                   class="form-control" 
                                                   placeholder="搜索名称、网址或描述..." 
                                                   value="<?php echo $functions->escape($search); ?>">
                                        </div>
                                        <div class="col-md-3">
                                            <div class="d-flex align-items-stretch gap-1">
                                                <select name="category" class="form-select" data-category-options>
                                                    <option value="all">所有分类</option>
                                                    <?php foreach ($categories as $category): ?>
                                                        <option value="<?php echo $category['id']; ?>"
                                                                <?php echo $categoryFilter == $category['id'] ? 'selected' : ''; ?>>
                                                            <?php echo $functions->escape($category['display_name'] ?? $category['name']); ?>
                                                        </option>
                                                    <?php endforeach; ?>
                                                </select>
                                                <button type="button" class="xycq-trigger xycq-trigger-compact js-category-create"
                                                        title="添加分类（与分类管理使用同一套字段和校验规则）">
                                                    <i class="fas fa-plus" aria-hidden="true"></i>添加分类
                                                </button>
                                            </div>
                                        </div>
                                        <div class="col-md-2">
                                            <select name="status" class="form-select">
                                                <option value="all">所有状态</option>
                                                <option value="active" <?php echo $statusFilter == 'active' ? 'selected' : ''; ?>>已启用</option>
                                                <option value="inactive" <?php echo $statusFilter == 'inactive' ? 'selected' : ''; ?>>已禁用</option>
                                            </select>
                                        </div>
                                        <?php if ($hasUserId): ?>
                                        <div class="col-md-2">
                                            <select name="owner" class="form-select">
                                                <option value="all">全部归属</option>
                                                <option value="public" <?php echo $ownerFilter === 'public' ? 'selected' : ''; ?>>公共</option>
                                                <?php foreach ($userOptions as $userOption): ?>
                                                    <option value="<?php echo (int) $userOption['id']; ?>"
                                                            <?php echo $ownerFilter === (string) $userOption['id'] ? 'selected' : ''; ?>>
                                                        <?php echo $functions->escape($userOption['username']); ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                        <?php endif; ?>
                                        <div class="col-md-<?php echo $hasUserId ? '2' : '4'; ?>">
                                            <button type="submit" class="btn btn-primary w-100">
                                                <i class="fas fa-search me-2"></i>筛选
                                            </button>
                                        </div>
                                        <div class="col-12">
                                            <?php if ($hasCheckStatus): ?>
                                                <button type="button" class="btn btn-sm btn-outline-info" id="checkAllBtn"
                                                        data-user-id="<?php echo ($ownerFilter !== 'all' && $ownerFilter !== 'public') ? (int) $ownerUserId : 0; ?>"
                                                        onclick="checkAllLinks()">
                                                    <i class="fas fa-heart-pulse me-1"></i>全部检测
                                                </button>
                                                <span class="text-muted small ms-2" id="checkAllTip"></span>
                                            <?php endif; ?>
                                        </div>
                                    </form>
                                    
                                    <div class="table-responsive">
                                        <table id="navLinksTable" class="table table-hover admin-table align-middle">
                                            <thead>
                                                <tr>
                                                    <th width="40">
                                                        <input class="form-check-input" type="checkbox" id="selectAll" aria-label="全选">
                                                    </th>
                                                    <th width="40" aria-label="排序"></th>
                                                    <th width="60">ID</th>
                                                    <th>网站信息</th>
                                                    <th width="100">分类</th>
                                                    <th width="110">归属</th>
                                                    <th width="70">排序</th>
                                                    <th width="80">状态</th>
                                                    <th width="180">健康状态</th>
                                                    <th width="170">操作</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach ($navLinks as $link): ?>
                                                    <?php
                                                        $ownerText = '公共';
                                                        if ($hasUserId && (int) $link['user_id'] > 0) {
                                                            $ownerText = !empty($link['owner_username'])
                                                                ? (string) $link['owner_username']
                                                                : ('用户#' . (int) $link['user_id']);
                                                        }
                                                    ?>
                                                    <tr class="nav-sort-row" data-id="<?php echo (int) $link['id']; ?>">
                                                        <td>
                                                            <input class="form-check-input nav-checkbox" type="checkbox" value="<?php echo (int) $link['id']; ?>" aria-label="选择该行">
                                                        </td>
                                                        <td>
                                                            <span class="nav-drag-handle" draggable="true" tabindex="0" role="button"
                                                                  aria-label="拖拽排序，聚焦后可用上下方向键调整"
                                                                  title="拖拽或按上下方向键调整顺序">
                                                                <i class="fas fa-grip-vertical"></i>
                                                            </span>
                                                        </td>
                                                        <td><?php echo $link['id']; ?></td>
                                                        <td>
                                                            <div class="d-flex align-items-center">
                                                                <?php if (!empty($link['logo'])): ?>
                                                                    <?php $logoUrl = $functions->getLogoUrl($link['logo']); ?>
                                                                    <?php if ($logoUrl !== ''): ?>
                                                                        <img src="<?php echo $functions->escape($logoUrl); ?>" 
                                                                             alt="Logo" 
                                                                             class="logo-preview me-3"
                                                                             onerror="this.style.display='none';this.parentNode.innerHTML='<div class=\'broken-image me-3\'><i class=\'fas fa-exclamation-triangle\'></i></div>' + this.parentNode.innerHTML;">
                                                                    <?php else: ?>
                                                                        <div class="broken-image me-3">
                                                                            <i class="fas fa-exclamation-triangle"></i>
                                                                        </div>
                                                                    <?php endif; ?>
                                                                <?php else: ?>
                                                                    <div class="logo-placeholder me-3">
                                                                        <i class="fas fa-globe"></i>
                                                                    </div>
                                                                <?php endif; ?>
                                                                <div>
                                                                    <strong><?php echo $functions->escape($link['name']); ?></strong>
                                                                    <br>
                                                                    <small class="text-muted"><?php echo $functions->escape($link['description']); ?></small>
                                                                    <br>
                                                                    <small class="text-primary">
                                                                        <?php 
                                                                        $host = parse_url($link['url'], PHP_URL_HOST);
                                                                        echo $functions->escape($host ?: $link['url']);
                                                                        ?>
                                                                    </small>
                                                                </div>
                                                            </div>
                                                        </td>
                                                        <td>
                                                            <span class="badge bg-info"><?php echo $functions->escape($link['category_name']); ?></span>
                                                        </td>
                                                        <td>
                                                            <?php if ($ownerText === '公共'): ?>
                                                                <span class="badge bg-light text-dark border">公共</span>
                                                            <?php else: ?>
                                                                <span class="badge bg-primary"><?php echo $functions->escape($ownerText); ?></span>
                                                            <?php endif; ?>
                                                        </td>
                                                        <td><?php echo $link['sort_order']; ?></td>
                                                        <td>
                                                            <?php if ($link['is_active']): ?>
                                                                <span class="badge bg-success">启用</span>
                                                            <?php else: ?>
                                                                <span class="badge bg-secondary">禁用</span>
                                                            <?php endif; ?>
                                                        </td>
                                                        <td class="health-cell">
                                                            <?php if ($hasCheckStatus): ?>
                                                                <?php
                                                                    $checkStatusValue = (string) ($link['check_status'] ?? 'unknown');
                                                                    $latencyValue = ($hasLatency && isset($link['latency_ms']) && $link['latency_ms'] !== null)
                                                                        ? (int) $link['latency_ms'] : null;
                                                                    $healthMeta = navLinkHealthMeta($checkStatusValue, $latencyValue);
                                                                ?>
                                                                <span class="badge health-badge <?php echo $healthMeta[1]; ?>"
                                                                      title="<?php echo $functions->escape($healthMeta[2]); ?>"><?php echo $functions->escape($healthMeta[0]); ?></span>
                                                                <span class="health-latency ms-1"><?php echo $latencyValue !== null && $latencyValue > 0 ? (int) $latencyValue . ' ms' : ''; ?></span>
                                                                <button type="button" class="btn btn-sm btn-outline-secondary ms-1"
                                                                        onclick="checkOne(<?php echo (int) $link['id']; ?>, this)"
                                                                        title="检测该链接">
                                                                    <i class="fas fa-heart-pulse"></i>
                                                                </button>
                                                            <?php else: ?>
                                                                <span class="text-muted small">不可用</span>
                                                            <?php endif; ?>
                                                        </td>
                                                        <td>
                                                            <div class="btn-group btn-group-sm">
                                                                <a href="nav_links.php?edit=<?php echo $link['id']; ?>" 
                                                                   class="btn btn-outline-primary"
                                                                   title="编辑">
                                                                    <i class="fas fa-edit"></i>
                                                                </a>
                                                                <a href="nav_links.php?toggle=<?php echo $link['id']; ?>" 
                                                                   class="btn btn-outline-<?php echo $link['is_active'] ? 'warning' : 'success'; ?>"
                                                                   title="启用/停用">
                                                                    <i class="fas fa-power-off"></i>
                                                                </a>
                                                                <?php if ($hasDeletedAt): ?>
                                                                    <button type="button"
                                                                            class="btn btn-outline-secondary"
                                                                            title="移入回收站"
                                                                            onclick="moveToTrash(<?php echo (int) $link['id']; ?>, <?php echo $functions->escape(json_encode((string) $link['name'], JSON_UNESCAPED_UNICODE | JSON_HEX_APOS | JSON_HEX_QUOT)); ?>)">
                                                                        <i class="fas fa-trash-can"></i>
                                                                    </button>
                                                                <?php endif; ?>
                                                                <a href="javascript:;" 
                                                                   onclick="confirmDelete(<?php echo (int) $link['id']; ?>, <?php echo $functions->escape(json_encode((string) $link['name'], JSON_UNESCAPED_UNICODE | JSON_HEX_APOS | JSON_HEX_QUOT)); ?>)"
                                                                   class="btn btn-outline-danger"
                                                                   title="彻底删除">
                                                                    <i class="fas fa-trash"></i>
                                                                </a>
                                                            </div>
                                                        </td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                    
                                    <?php if (empty($navLinks)): ?>
                                        <div class="text-center py-5 text-muted">
                                            <i class="fas fa-link fa-3x mb-3"></i>
                                            <p>暂无导航链接</p>
                                            <a href="nav_links.php" class="btn btn-primary mt-2">
                                                <i class="fas fa-plus me-2"></i>添加导航链接
                                            </a>
                                        </div>
                                    <?php endif; ?>
                                    
                                    <?php else: ?>
                                    <div class="d-flex flex-wrap justify-content-between align-items-center mb-3">
                                        <div class="text-muted small">
                                            <i class="fas fa-circle-info me-1"></i>回收站中的网站不会在前台显示，可随时恢复。
                                        </div>
                                        <?php if (!empty($navLinks)): ?>
                                            <form method="POST" class="d-inline" onsubmit="return confirm('将彻底删除回收站中的全部 <?php echo count($navLinks); ?> 条记录，删除后不可恢复，确定继续吗？');">
                                                <input type="hidden" name="empty_trash" value="1">
                                                <button type="submit" class="btn btn-sm btn-danger">
                                                    <i class="fas fa-broom me-1"></i>一键清空回收站
                                                </button>
                                            </form>
                                        <?php endif; ?>
                                    </div>
                                    <div class="table-responsive">
                                        <table id="navRecycleTable" class="table table-hover admin-table align-middle">
                                            <thead>
                                                <tr>
                                                    <th width="60">ID</th>
                                                    <th>网站信息</th>
                                                    <th width="100">分类</th>
                                                    <th width="110">归属</th>
                                                    <th width="150">删除时间</th>
                                                    <th width="150">操作</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach ($navLinks as $link): ?>
                                                    <?php
                                                        $ownerText = '公共';
                                                        if ($hasUserId && (int) $link['user_id'] > 0) {
                                                            $ownerText = !empty($link['owner_username'])
                                                                ? (string) $link['owner_username']
                                                                : ('用户#' . (int) $link['user_id']);
                                                        }
                                                    ?>
                                                    <tr>
                                                        <td><?php echo $link['id']; ?></td>
                                                        <td>
                                                            <strong><?php echo $functions->escape($link['name']); ?></strong>
                                                            <br>
                                                            <small class="text-primary">
                                                                <?php 
                                                                $host = parse_url($link['url'], PHP_URL_HOST);
                                                                echo $functions->escape($host ?: $link['url']);
                                                                ?>
                                                            </small>
                                                        </td>
                                                        <td>
                                                            <span class="badge bg-info"><?php echo $functions->escape($link['category_name']); ?></span>
                                                        </td>
                                                        <td>
                                                            <?php if ($ownerText === '公共'): ?>
                                                                <span class="badge bg-light text-dark border">公共</span>
                                                            <?php else: ?>
                                                                <span class="badge bg-primary"><?php echo $functions->escape($ownerText); ?></span>
                                                            <?php endif; ?>
                                                        </td>
                                                        <td>
                                                            <span class="text-muted small">
                                                                <?php echo $functions->escape(date('Y-m-d H:i', strtotime((string) $link['deleted_at']))); ?>
                                                            </span>
                                                        </td>
                                                        <td>
                                                            <form method="POST" class="d-inline" onsubmit="return confirm('确定要恢复该导航链接吗？');">
                                                                <input type="hidden" name="restore_id" value="<?php echo (int) $link['id']; ?>">
                                                                <button type="submit" class="btn btn-sm btn-outline-success" title="恢复">
                                                                    <i class="fas fa-rotate-left"></i>
                                                                </button>
                                                            </form>
                                                            <form method="POST" class="d-inline" onsubmit="return confirm('彻底删除后不可恢复，确定要删除吗？');">
                                                                <input type="hidden" name="purge_id" value="<?php echo (int) $link['id']; ?>">
                                                                <button type="submit" class="btn btn-sm btn-outline-danger" title="彻底删除">
                                                                    <i class="fas fa-trash"></i>
                                                                </button>
                                                            </form>
                                                        </td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                    
                                    <?php if (empty($navLinks)): ?>
                                        <div class="text-center py-5 text-muted">
                                            <i class="fas fa-trash-can fa-3x mb-3"></i>
                                            <p>回收站是空的</p>
                                        </div>
                                    <?php endif; ?>
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
    (function () {
        'use strict';

        // 与后端 navLinkHealthMeta() 保持一致：down 细分为「状态码异常」/「超时无响应」。
        function healthMeta(item) {
            var status = (item && item.status) || 'unknown';
            var ms = parseInt(item && item.latency_ms, 10) || 0;
            var code = parseInt(item && item.http_code, 10) || 0;

            if (status === 'ok') {
                return ['正常', 'bg-success', '站点响应正常'];
            }
            if (status === 'slow') {
                return ['较慢', 'bg-warning text-dark', '响应超过 3 秒'];
            }
            if (status === 'down') {
                if (code > 0 || (ms > 0 && ms < 3000)) {
                    return ['状态码异常', 'bg-warning text-dark', '站点有响应，但返回了错误状态码，不属于无法访问'];
                }
                return ['超时无响应', 'bg-danger', '连接超时或无法建立连接'];
            }
            return ['未检测', 'bg-secondary', '尚未进行健康检测'];
        }

        // 列表初始化：正常列表与回收站各初始化一次 DataTables
        $(document).ready(function () {
            if (document.getElementById('navLinksTable')) {
                // 为保证拖拽排序覆盖完整列表并避免与手动排序冲突：
                // 关闭数据表分页与列排序，保留客户端搜索与信息栏。
                $('#navLinksTable').DataTable({
                    language: {
                        url: '../assets/js/dataTables.zh-CN.json'
                    },
                    paging: false,
                    ordering: false
                });
            }

            if (document.getElementById('navRecycleTable')) {
                $('#navRecycleTable').DataTable({
                    language: {
                        url: '../assets/js/dataTables.zh-CN.json'
                    },
                    order: [[4, 'desc']],
                    pageLength: 10,
                    columnDefs: [
                        { orderable: false, targets: [5] }
                    ]
                });
            }

            $('.logo-preview').on('error', function () {
                $(this).hide();
                $(this).parent().prepend('<div class="broken-image me-3"><i class="fas fa-exclamation-triangle"></i></div>');
            });
        });

        /* -------------------- 通用工具 -------------------- */

        function collectCheckedIds() {
            var ids = [];
            var nodes = document.querySelectorAll('.nav-checkbox:checked');
            for (var i = 0; i < nodes.length; i++) {
                var value = parseInt(nodes[i].value, 10);
                if (value > 0) {
                    ids.push(value);
                }
            }
            return ids;
        }

        function updateSelectedCount() {
            var el = document.getElementById('selectedCount');
            if (el) {
                el.textContent = String(collectCheckedIds().length);
            }
        }

        window.confirmDelete = function (id, name) {
            if (confirm('确定要删除 "' + name + '" 吗？此操作不可恢复！')) {
                window.location.href = 'nav_links.php?delete=' + encodeURIComponent(id);
            }
        };

        /* -------------------- 复选框与批量操作 -------------------- */

        document.addEventListener('change', function (event) {
            var target = event.target;
            if (!target) {
                return;
            }
            if (target.id === 'selectAll') {
                var checked = target.checked;
                var boxes = document.querySelectorAll('.nav-checkbox');
                for (var i = 0; i < boxes.length; i++) {
                    boxes[i].checked = checked;
                }
                updateSelectedCount();
            } else if (target.classList && target.classList.contains('nav-checkbox')) {
                updateSelectedCount();
            }
        });

        window.submitBatch = function (action) {
            var ids = collectCheckedIds();

            if (!ids.length) {
                alert('请先选择要操作的记录');
                return;
            }

            if (action === 'trash' && !confirm('确定要把选中的 ' + ids.length + ' 条链接移入回收站吗？')) {
                return;
            }
            if (action === 'move_category' && !confirm('确定要把选中的 ' + ids.length + ' 条链接移动到所选的分类吗？')) {
                return;
            }
            if (action === 'export_csv' && !confirm('确定要导出选中的 ' + ids.length + ' 条链接吗？')) {
                return;
            }

            // v2.16.0 归属管理：必须先在「归属用户…」里选一个目标，未选时拦住，
            // 避免把记录误挂到「公共」（值为 0，和「未选择」的空串要区分开）。
            if (action === 'transfer_owner' || action === 'copy_owner') {
                var ownerSelect = document.getElementById('batchOwnerSelect');
                var ownerValue = ownerSelect ? ownerSelect.value : '';

                if (ownerValue === '') {
                    alert('请先在「归属用户…」中选择目标用户');
                    if (ownerSelect) {
                        ownerSelect.focus();
                    }
                    return;
                }

                var ownerLabel = ownerSelect.options[ownerSelect.selectedIndex].text;
                var tip = action === 'transfer_owner'
                    ? '确定把选中的 ' + ids.length + ' 条链接转移到「' + ownerLabel + '」名下吗？原归属将不再拥有这些链接。'
                    : '确定为「' + ownerLabel + '」复制这 ' + ids.length + ' 条链接吗？原链接会保留。';

                if (!confirm(tip)) {
                    return;
                }
            }

            var form = document.getElementById('batchForm');
            if (!form) {
                return;
            }

            var container = document.getElementById('batchFormFields') || form;
            container.innerHTML = '';

            for (var i = 0; i < ids.length; i++) {
                var input = document.createElement('input');
                input.type = 'hidden';
                input.name = 'ids[]';
                input.value = ids[i];
                container.appendChild(input);
            }

            var actionInput = document.createElement('input');
            actionInput.type = 'hidden';
            actionInput.name = 'batch_action';
            actionInput.value = action;
            container.appendChild(actionInput);

            // 归属类操作：把下拉选中的目标用户随表单一起提交
            if (action === 'transfer_owner' || action === 'copy_owner') {
                var ownerSel = document.getElementById('batchOwnerSelect');
                if (ownerSel) {
                    var ownerInput = document.createElement('input');
                    ownerInput.type = 'hidden';
                    ownerInput.name = 'batch_owner_id';
                    ownerInput.value = ownerSel.value;
                    container.appendChild(ownerInput);
                }
            }

            form.submit();
        };

        /* 单行「移入回收站」：复用批量接口，只提交一个 id */
        window.moveToTrash = function (id, name) {
            if (!confirm('确定要把 "' + name + '" 移入回收站吗？')) {
                return;
            }

            var params = new URLSearchParams();
            params.append('batch_action', 'trash');
            params.append('ids[]', String(id));

            fetch('nav_links.php', {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: params.toString()
            }).then(function () {
                window.location.reload();
            }).catch(function () {
                alert('操作失败，请稍后重试');
            });
        };

        /* -------------------- 健康检测 -------------------- */

        function applyHealth(id, item) {
            var row = document.querySelector('tr[data-id="' + id + '"]');
            if (!row) {
                return;
            }
            var meta = healthMeta(item);
            var badge = row.querySelector('.health-badge');
            if (badge) {
                badge.textContent = meta[0];
                badge.className = 'badge health-badge ' + meta[1];
                badge.title = meta[2];
            }
            var latency = row.querySelector('.health-latency');
            if (latency) {
                latency.textContent = item.latency_ms ? (item.latency_ms + ' ms') : '';
            }
        }

        window.checkOne = function (id, btn) {
            var original = btn.innerHTML;
            btn.disabled = true;
            btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span>';

            var params = new URLSearchParams();
            params.append('id', String(id));

            fetch('../api/health_check.php', {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: params.toString()
            }).then(function (response) {
                return response.json();
            }).then(function (data) {
                btn.disabled = false;
                btn.innerHTML = original;

                if (!data || !data.ok) {
                    alert((data && (data.error || data.message)) ? (data.error || data.message) : '检测失败，请稍后重试');
                    return;
                }

                applyHealth(id, data.item);
            }).catch(function () {
                btn.disabled = false;
                btn.innerHTML = original;
                alert('检测请求失败，请稍后重试');
            });
        };

        window.checkAllLinks = function () {
            var btn = document.getElementById('checkAllBtn');
            var tip = document.getElementById('checkAllTip');
            if (!btn) {
                return;
            }

            if (!confirm('确定要检测全部启用中的导航链接吗？链接较多时耗时较长。')) {
                return;
            }

            var original = btn.innerHTML;
            var userId = parseInt(btn.getAttribute('data-user-id') || '0', 10) || 0;
            btn.disabled = true;
            btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>检测中…';
            if (tip) {
                tip.textContent = '正在逐个检测，请勿关闭页面…';
            }

            var params = new URLSearchParams();
            params.append('all', '1');
            if (userId > 0) {
                params.append('user_id', String(userId));
            }

            fetch('../api/health_check.php', {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: params.toString()
            }).then(function (response) {
                return response.json();
            }).then(function (data) {
                btn.disabled = false;
                btn.innerHTML = original;

                if (!data || !data.ok) {
                    if (tip) {
                        tip.textContent = (data && data.error) ? data.error : '检测失败，请稍后重试';
                    }
                    return;
                }

                var summary = data.summary || { total: 0, ok_count: 0, slow: 0, down: 0, items: [] };
                if (tip) {
                    tip.textContent = '检测完成：共 ' + summary.total + ' 条 / 正常 ' + summary.ok_count
                        + ' / 较慢 ' + summary.slow + ' / 失败 ' + summary.down;
                }

                var items = summary.items || [];
                for (var i = 0; i < items.length; i++) {
                    applyHealth(items[i].id, items[i]);
                }
            }).catch(function () {
                btn.disabled = false;
                btn.innerHTML = original;
                if (tip) {
                    tip.textContent = '检测请求失败，请稍后重试';
                }
            });
        };

        /* -------------------- 智能抓取（抓取站点信息 + 分类推荐） -------------------- */

        window.smartFetch = function () {
            var urlInput = document.getElementById('navUrlInput');
            var tip = document.getElementById('smartFetchTip');
            var btn = document.getElementById('smartFetchBtn');

            if (!urlInput || !btn) {
                return;
            }

            var url = urlInput.value.trim();

            if (!url) {
                if (tip) {
                    tip.textContent = '请先填写网站网址';
                }
                return;
            }

            var original = btn.innerHTML;
            btn.disabled = true;
            btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>抓取中';
            if (tip) {
                tip.textContent = '正在抓取网站信息…';
            }

            var params = new URLSearchParams();
            params.append('url', url);

            fetch('../api/ai_suggest.php', {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: params.toString()
            }).then(function (response) {
                return response.json();
            }).then(function (data) {
                btn.disabled = false;
                btn.innerHTML = original;

                if (!data || !data.ok) {
                    if (tip) {
                        tip.textContent = (data && (data.message || data.error)) ? (data.message || data.error) : '抓取失败，请手动填写';
                    }
                    return;
                }

                var nameInput = document.getElementById('navNameInput');
                var descInput = document.getElementById('navDescInput');
                var logoUrlInput = document.getElementById('navLogoUrlInput');
                var logoPreview = document.getElementById('navLogoUrlPreview');
                var logoWrap = document.getElementById('navLogoUrlPreviewWrap');

                if (nameInput && data.title) {
                    nameInput.value = data.title;
                }
                if (descInput && data.description) {
                    descInput.value = data.description;
                }

                var icons = data.icons || [];
                if (logoUrlInput && logoPreview && logoWrap && icons.length && icons[0]) {
                    logoUrlInput.value = icons[0];
                    logoPreview.src = icons[0];
                    logoPreview.style.display = '';
                    logoWrap.style.display = '';
                }

                var resultText = '已抓取站点信息';
                var suggestion = data.suggestion;

                if (suggestion && suggestion.ok) {
                    var categorySelect = document.getElementById('navCategorySelect');
                    if (categorySelect && suggestion.category_id) {
                        categorySelect.value = String(suggestion.category_id);
                    }

                    if (suggestion.source === 'ai' && data.ai_enabled) {
                        resultText += '；分类推荐：AI 推荐';
                    } else if (suggestion.source === 'rules') {
                        resultText += '；分类推荐：关键词匹配';
                    } else if (suggestion.category) {
                        resultText += '；分类推荐：' + suggestion.category;
                    }
                }

                if (tip) {
                    tip.textContent = resultText;
                }
            }).catch(function () {
                btn.disabled = false;
                btn.innerHTML = original;
                if (tip) {
                    tip.textContent = '抓取请求失败，请手动填写';
                }
            });
        };

        /* -------------------- 拖拽排序 + 键盘替代 -------------------- */

        (function () {
            var table = document.getElementById('navLinksTable');
            if (!table) {
                return;
            }

            var draggingId = null;
            var saving = false;
            var pending = false;

            function body() {
                return table.querySelector('tbody');
            }

            function rowById(id) {
                var tbody = body();
                return tbody ? tbody.querySelector('tr[data-id="' + id + '"]') : null;
            }

            function saveOrder() {
                if (saving) {
                    // 上一次保存尚未完成，标记为待保存，完成后补发一次
                    pending = true;
                    return;
                }

                var tbody = body();
                if (!tbody) {
                    return;
                }

                var nodes = tbody.querySelectorAll('tr[data-id]');
                var ids = [];
                for (var i = 0; i < nodes.length; i++) {
                    ids.push(parseInt(nodes[i].getAttribute('data-id'), 10));
                }
                if (!ids.length) {
                    return;
                }

                saving = true;
                var params = new URLSearchParams();
                for (var j = 0; j < ids.length; j++) {
                    params.append('sort_ids[]', String(ids[j]));
                }

                function done(ok) {
                    saving = false;
                    if (!ok) {
                        alert('排序保存失败，请刷新页面后重试');
                    } else if (pending) {
                        pending = false;
                        saveOrder();
                    }
                }

                fetch('nav_links.php', {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: params.toString()
                }).then(function (response) {
                    return response.json();
                }).then(function (data) {
                    done(!!(data && data.ok));
                }).catch(function () {
                    done(false);
                });
            }

            function finishDrag() {
                if (draggingId === null) {
                    return;
                }
                var row = rowById(draggingId);
                if (row) {
                    row.classList.remove('dragging');
                }
                draggingId = null;
                saveOrder();
            }

            table.addEventListener('dragstart', function (event) {
                var handle = event.target.closest ? event.target.closest('.nav-drag-handle') : null;
                if (!handle) {
                    return;
                }
                var row = handle.closest('tr[data-id]');
                if (!row) {
                    return;
                }
                draggingId = row.getAttribute('data-id');
                row.classList.add('dragging');
                if (event.dataTransfer) {
                    event.dataTransfer.effectAllowed = 'move';
                    try {
                        event.dataTransfer.setData('text/plain', draggingId);
                    } catch (err) {
                        // 部分浏览器限制了 setData，忽略即可
                    }
                }
            });

            table.addEventListener('dragover', function (event) {
                if (draggingId === null) {
                    return;
                }
                event.preventDefault();
                if (event.dataTransfer) {
                    event.dataTransfer.dropEffect = 'move';
                }
                var target = event.target.closest ? event.target.closest('tr[data-id]') : null;
                var draggingRow = rowById(draggingId);
                if (!target || !draggingRow || target === draggingRow) {
                    return;
                }
                var rect = target.getBoundingClientRect();
                var after = (event.clientY - rect.top) > (rect.height / 2);
                if (after) {
                    target.parentNode.insertBefore(draggingRow, target.nextSibling);
                } else {
                    target.parentNode.insertBefore(draggingRow, target);
                }
            });

            table.addEventListener('drop', function (event) {
                event.preventDefault();
                finishDrag();
            });

            table.addEventListener('dragend', function () {
                finishDrag();
            });

            // 键盘替代方案：手柄聚焦后用上下方向键移动，并同样保存顺序
            table.addEventListener('keydown', function (event) {
                var handle = event.target.closest ? event.target.closest('.nav-drag-handle') : null;
                if (!handle) {
                    return;
                }
                if (event.key !== 'ArrowUp' && event.key !== 'ArrowDown') {
                    return;
                }
                event.preventDefault();

                var row = handle.closest('tr[data-id]');
                var tbody = body();
                if (!row || !tbody) {
                    return;
                }

                if (event.key === 'ArrowUp') {
                    var prev = row.previousElementSibling;
                    if (prev && prev.matches('tr[data-id]')) {
                        tbody.insertBefore(row, prev);
                    }
                } else {
                    var next = row.nextElementSibling;
                    if (next && next.matches('tr[data-id]')) {
                        tbody.insertBefore(next, row);
                    }
                }

                saveOrder();
                handle.focus();
            });
        })();
    })();
    </script>

    <script src="../assets/js/category-quick-add.js?v=2.16.5"></script>
    <script>
    /* v2.12.0 · 导航管理页「添加分类」：与分类管理用同一个组件、同一套字段和校验 */
    (function () {
        'use strict';

        var parents = <?php
            $quickAddParents = [];
            foreach ($categories as $quickAddCategory) {
                if ((int) ($quickAddCategory['parent_id'] ?? 0) === 0) {
                    $quickAddParents[] = [
                        'id' => (int) $quickAddCategory['id'],
                        'name' => (string) ($quickAddCategory['display_name'] ?? $quickAddCategory['name']),
                    ];
                }
            }
            echo json_encode($quickAddParents, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
        ?>;

        var users = <?php
            $quickAddUsers = [];
            foreach ($userOptions as $quickAddUser) {
                $quickAddUsers[] = [
                    'id' => (int) $quickAddUser['id'],
                    'username' => (string) $quickAddUser['username'],
                ];
            }
            echo json_encode($quickAddUsers, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
        ?>;

        document.addEventListener('click', function (event) {
            var target = event.target;
            var btn = target && target.closest ? target.closest('.js-category-create') : null;

            if (!btn || !window.XYCategoryQuickAdd) {
                return;
            }

            XYCategoryQuickAdd.open({
                mode: 'admin',
                term: '分类',
                title: '新建分类',
                apiUrl: '../api/category_create.php',
                parents: parents,
                users: users,
                onCreated: function (item) {
                    /* 不刷新页面，直接把新分类补进本页所有分类下拉，保留当前筛选与滚动位置 */
                    Array.prototype.forEach.call(
                        document.querySelectorAll('select[data-category-options]'),
                        function (select) {
                            if (select.querySelector('option[value="' + item.id + '"]')) {
                                return;
                            }

                            var option = document.createElement('option');
                            option.value = String(item.id);
                            option.textContent = item.label || item.name;

                            var first = select.options[0];
                            if (first && (first.value === '' || first.value === 'all') && first.nextSibling) {
                                select.insertBefore(option, first.nextSibling);
                            } else {
                                select.appendChild(option);
                            }
                        }
                    );
                }
            });
        });
    })();
    </script>
</body>
</html>
