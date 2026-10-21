<?php
/**
 * 轩宇-导航 · 系统工具（v2.0.0）
 *
 * 一个页面用 Bootstrap nav-tabs 分成 5 个功能区：
 *   1) 数据备份与恢复  2) API 令牌管理  3) 定时任务  4) 邮件设置与测试  5) 联系留言
 *
 * 所有写操作均要求同源（requireSameOrigin），SQL 一律使用预处理，输出一律经过 escape()。
 */
require_once __DIR__ . '/../includes/bootstrap.php';
require_once 'includes/functions.php';
require_once 'includes/Services.php';

$functions = new Functions();

if (!$functions->isLoggedIn()) {
    $functions->redirect('login.php');
}

$db = Database::getInstance()->getConnection();

$backupService  = new BackupService($functions);
$mailService    = new MailService($functions);
$contactService = new ContactService($functions);
$healthService  = new HealthCheckService($functions);

$message = '';
$error = '';

$newTokenPlain = '';      // 新建令牌时仅本次展示的明文
$importRestored = [];     // 导入恢复结果：表 => 行数
$importDone = false;
$mailResult = null;       // 测试邮件结果
$healthResult = null;     // 立即检测结果

$allowedTabs = ['backup', 'api', 'cron', 'mail', 'messages', 'update'];
$activeTab = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $functions->requireSameOrigin();

    if (isset($_POST['tab']) && in_array((string) $_POST['tab'], $allowedTabs, true)) {
        $activeTab = (string) $_POST['tab'];
    }
} elseif (isset($_GET['tab']) && in_array((string) $_GET['tab'], $allowedTabs, true)) {
    $activeTab = (string) $_GET['tab'];
}

// 健康检测可能耗时较长，放宽脚本时限。
@set_time_limit(600);

/* ==========================================================================
 * 备份导出下载
 * 必须在任何 HTML 输出之前发送 header()，否则下载会失败。
 * ========================================================================== */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['export_backup'])) {
    $withUsers = ((string) $_POST['export_backup'] === 'full');
    $json = $backupService->exportJson($withUsers);
    $filename = 'xuanyu-nav-backup-' . date('Ymd-His') . '.json';

    if (!headers_sent()) {
        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . strlen($json));
        header('Cache-Control: no-store, max-age=0');
    }

    echo $json;
    exit;
}

/* ==========================================================================
 * 其它写操作
 * ========================================================================== */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? '');

    // ------------------------------------------------------------------
    // ⓪ 上传更新包（zip 覆盖式在线更新）
    // ------------------------------------------------------------------
    if ($action === 'upload_update') {
        $updateResult = null;

        if (!class_exists('ZipArchive')) {
            $error = '当前 PHP 未启用 ZipArchive 扩展，无法在线解压更新包，请改用 FTP 覆盖文件';
        } elseif (!isset($_FILES['update_package']) || !is_array($_FILES['update_package'])) {
            $error = '请选择要上传的更新包（.zip）';
        } else {
            $file = $_FILES['update_package'];
            $uploadError = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);

            if ($uploadError !== UPLOAD_ERR_OK) {
                $error = '文件上传失败，请重试（错误码 ' . $uploadError . '）';
            } elseif (strtolower((string) pathinfo((string) ($file['name'] ?? ''), PATHINFO_EXTENSION)) !== 'zip') {
                $error = '仅支持 .zip 格式的更新包';
            } elseif ((int) ($file['size'] ?? 0) > 50 * 1024 * 1024) {
                $error = '更新包超过 50MB 上限';
            } elseif (!is_uploaded_file((string) ($file['tmp_name'] ?? ''))) {
                $error = '非法上传';
            } else {
                $zip = new ZipArchive();
                $opened = $zip->open((string) $file['tmp_name'], ZipArchive::RDONLY);

                if ($opened !== true) {
                    $error = '无法读取更新包，文件可能已损坏';
                } else {
                    // GitHub 下载的 Source code zip 通常带一层 XYdaohang-x.y.z/ 顶层目录，自动剥掉
                    $prefix = '';
                    $firstName = (string) $zip->getNameIndex(0);

                    if (preg_match('#^[^/]+/#', $firstName, $pm)) {
                        $allSameRoot = true;

                        for ($i = 0; $i < $zip->numFiles; $i++) {
                            $n = (string) $zip->getNameIndex($i);

                            if ($n !== '' && strpos($n, $pm[0]) !== 0) {
                                $allSameRoot = false;
                                break;
                            }
                        }

                        if ($allSameRoot) {
                            $prefix = $pm[0];
                        }
                    }

                    // 读取包内版本号（VERSION 或 config/version.php），必须不低于当前版本
                    $packageVersion = '';
                    $versionEntry = $zip->getFromName($prefix . 'VERSION');

                    if (is_string($versionEntry) && preg_match('/\d+\.\d+\.\d+/', trim($versionEntry), $vm)) {
                        $packageVersion = $vm[0];
                    } else {
                        $vpEntry = $zip->getFromName($prefix . 'config/version.php');

                        if (is_string($vpEntry) && preg_match("/APP_VERSION',\\s*'(\\d+\\.\\d+\\.\\d+)'/", $vpEntry, $vm)) {
                            $packageVersion = $vm[1];
                        }
                    }

                    $currentVersion = defined('APP_VERSION') ? APP_VERSION : '0.0.0';
                    $forceUpdate = !empty($_POST['force_update']);

                    if ($packageVersion === '') {
                        $zip->close();
                        $error = '更新包中未找到 VERSION 或 config/version.php，无法校验版本，已中止';
                    } elseif (!$forceUpdate && version_compare($packageVersion, $currentVersion, '<')) {
                        $zip->close();
                        $error = '更新包版本（v' . $packageVersion . '）低于当前版本（v' . $currentVersion . '），已中止。如需强制覆盖，请勾选「强制覆盖」选项。';
                    } else {
                        // 永不覆盖的本地文件/目录（数据库配置、SMTP、本地配置、上传、备份、缓存、锁文件）
                        $neverOverwrite = [
                            'config/database.php',
                            'config/smtp.php',
                            'config/config.php',
                            'install/install.lock',
                            '.env',
                        ];
                        $neverOverwriteDirs = ['.git/', 'backups/', 'cache/', 'uploads/', 'node_modules/', 'vendor/'];

                        $rootPath = dirname(__DIR__);
                        $backupDir = $rootPath . '/backups/update-v' . $packageVersion . '-' . date('Ymd-His');
                        $entries = [];
                        $skipped = [];

                        for ($i = 0; $i < $zip->numFiles; $i++) {
                            $name = (string) $zip->getNameIndex($i);

                            if ($prefix !== '' && strpos($name, $prefix) === 0) {
                                $name = substr($name, strlen($prefix));
                            }

                            $name = str_replace('\\', '/', $name);

                            if ($name === '' || substr($name, -1) === '/') {
                                continue; // 目录条目
                            }

                            // 防目录穿越：拒绝 ../ 与绝对路径
                            if (strpos($name, '..') !== false || preg_match('#^([a-zA-Z]:)?/#', $name)) {
                                $skipped[] = $name . '（非法路径）';
                                continue;
                            }

                            $blocked = in_array($name, $neverOverwrite, true);

                            if (!$blocked) {
                                foreach ($neverOverwriteDirs as $dir) {
                                    if (strpos($name, $dir) === 0) {
                                        $blocked = true;
                                        break;
                                    }
                                }
                            }

                            if ($blocked) {
                                $skipped[] = $name . '（保留本地文件）';
                                continue;
                            }

                            $entries[] = $name;
                        }

                        if (!$entries) {
                            $zip->close();
                            $error = '更新包内没有可覆盖的文件，已中止';
                        } else {
                            $backedUp = 0;
                            $written = 0;
                            $failed = [];
                            $backupReady = is_dir($backupDir) || @mkdir($backupDir, 0755, true);

                            foreach ($entries as $name) {
                                $content = $zip->getFromName($prefix . $name);

                                if (!is_string($content)) {
                                    $failed[] = $name . '（读取失败）';
                                    continue;
                                }

                                $target = $rootPath . '/' . $name;
                                $targetDir = dirname($target);

                                // 备份原文件（存在才备份）
                                if ($backupReady && is_file($target)) {
                                    $backupTarget = $backupDir . '/' . $name;

                                    if (!is_dir(dirname($backupTarget))) {
                                        @mkdir(dirname($backupTarget), 0755, true);
                                    }

                                    if (@copy($target, $backupTarget)) {
                                        $backedUp++;
                                    }
                                }

                                if (!is_dir($targetDir) && !@mkdir($targetDir, 0755, true)) {
                                    $failed[] = $name . '（无法创建目录）';
                                    continue;
                                }

                                if (@file_put_contents($target, $content, LOCK_EX) === false) {
                                    $failed[] = $name . '（写入失败）';
                                } else {
                                    $written++;
                                }
                            }

                            $zip->close();

                            // 清掉版本检测缓存，让新版本的检测结果立即生效
                            @unlink($rootPath . '/cache/update_check.json');

                            if (function_exists('opcache_reset')) {
                                @opcache_reset();
                            }

                            $updateResult = [
                                'version' => $packageVersion,
                                'written' => $written,
                                'backedUp' => $backedUp,
                                'backupDir' => 'backups/update-v' . $packageVersion . '-' . date('Ymd-His'),
                                'skipped' => $skipped,
                                'failed' => $failed,
                            ];

                            if ($failed) {
                                $error = '更新完成（v' . $packageVersion . '），但有 ' . count($failed) . ' 个文件写入失败，请检查目录权限后重试或手动覆盖';
                            } else {
                                $message = '已成功更新到 v' . $packageVersion . '：覆盖 ' . $written . ' 个文件，备份 ' . $backedUp . ' 个原文件到 ' . $updateResult['backupDir'] . '/。若本次更新包含数据库升级脚本，请按说明执行对应 SQL。';
                            }
                        }
                    }
                }
            }
        }
    }
