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
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/Services.php';

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

                    if ($packageVersion === '') {
                        $zip->close();
                        $error = '更新包中未找到 VERSION 或 config/version.php，无法校验版本，已中止';
                    } elseif (version_compare($packageVersion, $currentVersion, '<')) {
                        $zip->close();
                        $error = '更新包版本（v' . $packageVersion . '）低于当前版本（v' . $currentVersion . '），已中止';
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

    // ------------------------------------------------------------------
    // ① 备份：保存到服务器 backups/
    // ------------------------------------------------------------------
    if ($action === 'save_backup_file') {
        $json = $backupService->exportJson(true);
        $path = $backupService->writeFile($json);

        if ($path !== null) {
            $message = '备份已保存到服务器：backups/' . basename($path);
        } else {
            $error = '备份写入失败，请检查 backups/ 目录是否可写';
        }
    }

    // ------------------------------------------------------------------
    // ② 备份：导入恢复（JSON 上传，二次确认后执行）
    // ------------------------------------------------------------------
    if ($action === 'import_backup') {
        $confirm = (string) ($_POST['confirm_import'] ?? '');

        if ($confirm !== '1') {
            $error = '请先勾选「我已确认」再执行导入';
        } elseif (!isset($_FILES['backup_file']) || !is_array($_FILES['backup_file'])) {
            $error = '请选择要导入的备份文件';
        } else {
            $file = $_FILES['backup_file'];
            $uploadError = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);

            if ($uploadError !== UPLOAD_ERR_OK) {
                $error = '文件上传失败，请重试（错误码 ' . $uploadError . '）';
            } elseif (strtolower((string) pathinfo((string) ($file['name'] ?? ''), PATHINFO_EXTENSION)) !== 'json') {
                $error = '仅支持 .json 格式的备份文件';
            } elseif ((int) ($file['size'] ?? 0) > 5 * 1024 * 1024) {
                $error = '备份文件超过 5MB 上限';
            } elseif (!is_uploaded_file((string) ($file['tmp_name'] ?? ''))) {
                $error = '非法上传';
            } else {
                $raw = (string) file_get_contents((string) $file['tmp_name']);
                $decoded = json_decode($raw, true);

                if (!is_array($decoded) || !isset($decoded['tables']) || !is_array($decoded['tables'])) {
                    $error = '备份文件格式不正确（缺少 tables 数据）';
                } else {
                    $result = $backupService->importJson($raw);

                    if (!empty($result['ok'])) {
                        $importRestored = $result['restored'];
                        $importDone = true;
                        $message = '数据导入完成，共恢复 ' . array_sum(array_map('intval', $result['restored'])) . ' 行';
                    } else {
                        $importRestored = $result['restored'] ?? [];
                        $importDone = true;
                        $error = '导入失败：备份格式不合法或数据库执行出错，已回滚';
                    }
                }
            }
        }
    }

    // ------------------------------------------------------------------
    // ③ 定时任务：生成 / 重置 cron_key
    // ------------------------------------------------------------------
    if ($action === 'generate_cron_key') {
        try {
            $newKey = bin2hex(random_bytes(16));
        } catch (Throwable $e) {
            $newKey = md5(uniqid('', true));
        }

        if ($functions->updateSetting('cron_key', $newKey)) {
            $message = '定时密钥已生成，请复制下方访问地址配置到计划任务';
        } else {
            $error = '定时密钥保存失败，请稍后重试';
        }
    }

    // ------------------------------------------------------------------
    // ④ 定时任务：立即检测（手动触发，与 cron 无关）
    // ------------------------------------------------------------------
    if ($action === 'run_health_check') {
        try {
            $healthResult = $healthService->checkAll(0);
            $message = sprintf(
                '检测完成：公共导航共 %d 条，正常 %d，较慢 %d，失败 %d',
                $healthResult['total'],
                $healthResult['ok_count'],
                $healthResult['slow'],
                $healthResult['down']
            );
            $functions->logMessage(sprintf(
                '[admin:tools] 手动健康检测 共 %d 条，失败 %d 条',
                $healthResult['total'],
                $healthResult['down']
            ));
        } catch (Throwable $e) {
            $error = '检测执行失败：' . $e->getMessage();
        }
    }

    // ------------------------------------------------------------------
    // ⑤ 邮件：发送测试邮件
    // ------------------------------------------------------------------
    if ($action === 'send_test_mail') {
        $to = trim((string) ($_POST['test_email'] ?? ''));

        if ($to === '') {
            $to = $mailService->adminEmail();
        }

        if ($to === '') {
            $mailResult = ['ok' => false, 'error' => 'invalid_recipient'];
        } else {
            $subject = '邮件配置测试';
            $html = '<div style="font-family:Arial,Helvetica,sans-serif;font-size:14px;color:#333;">'
                . '<p>这是一封来自「轩宇-导航」后台的测试邮件。</p>'
                . '<p>收到此邮件说明 <strong>config/smtp.php</strong> 的 SMTP 配置可以正常发信。</p>'
                . '<p>发送时间：' . date('Y-m-d H:i:s') . '</p>'
                . '</div>';
            $mailResult = $mailService->send($to, $subject, $html, '邮件配置测试：收到此邮件说明 SMTP 配置可正常发信。');
        }

        if (!empty($mailResult['ok'])) {
            $message = '测试邮件已发送至 ' . $to . '，请查收（含垃圾箱）';
        } else {
            $map = [
                'mail_disabled'     => '邮件功能未启用：请先在左侧填写 SMTP 配置并勾选「启用邮件功能」后保存',
                'phpmailer_missing' => 'PHPMailer 未安装：缺少 vendor/PHPMailer/src 目录',
                'invalid_recipient' => '收件邮箱为空或格式不正确，且未配置 admin_email',
                'send_failed'       => '发送失败：SMTP 连接或认证出错，请检查主机、端口、加密方式与账号密码',
            ];
            $code = (string) ($mailResult['error'] ?? 'send_failed');
            $error = '测试邮件发送失败：' . ($map[$code] ?? ('未知错误（' . $code . '）'));
        }
    }

    // ------------------------------------------------------------------
    // ⑤+ 邮件：保存 SMTP 配置（写入 config/smtp.php，无需手动改文件）
    // ------------------------------------------------------------------
    if ($action === 'save_mail_config') {
        $mailEnabledInput = isset($_POST['mail_enabled']);
        $host = trim((string) ($_POST['mail_host'] ?? ''));
        $port = (int) ($_POST['mail_port'] ?? 0);
        $encryption = strtolower(trim((string) ($_POST['mail_encryption'] ?? '')));
        $username = trim((string) ($_POST['mail_username'] ?? ''));
        $password = (string) ($_POST['mail_password'] ?? '');   // 密码不做 trim，避免改动合法空格
        $fromAddress = trim((string) ($_POST['mail_from_address'] ?? ''));
        $adminEmail = trim((string) ($_POST['mail_admin_email'] ?? ''));

        if (!in_array($encryption, ['', 'ssl', 'tls'], true)) {
            $encryption = '';
        }

        if ($port < 1 || $port > 65535) {
            $error = 'SMTP 端口不合法（1-65535）';
        } elseif ($mailEnabledInput && $host === '') {
            $error = '启用邮件功能时 SMTP 主机不能为空';
        } elseif ($mailEnabledInput && $fromAddress === '') {
            $error = '启用邮件功能时发件地址不能为空';
        } elseif ($fromAddress !== '' && !filter_var($fromAddress, FILTER_VALIDATE_EMAIL)) {
            $error = '发件地址格式不正确';
        } elseif ($adminEmail !== '' && !filter_var($adminEmail, FILTER_VALIDATE_EMAIL)) {
            $error = '管理员收件邮箱格式不正确';
        } else {
            $smtpFile = dirname(__DIR__) . '/config/smtp.php';

            // 直接读文件而不是走 MailService::config()，
            // 避免其静态缓存让下方展示区读到保存前的旧值。
            $existing = [];
            if (is_file($smtpFile)) {
                $loaded = require $smtpFile;
                if (is_array($loaded)) {
                    $existing = $loaded;
                }
            }

            $smtpDefaults = [
                'host' => '', 'port' => 465, 'username' => '', 'password' => '',
                'encryption' => 'ssl', 'timeout' => 30, 'auth' => true, 'debug' => false,
            ];
            $existingSmtp = is_array($existing['smtp'] ?? null) ? $existing['smtp'] : [];
            $newSmtp = array_merge($smtpDefaults, $existingSmtp);
            $newSmtp['host'] = $host;
            $newSmtp['port'] = $port;
            $newSmtp['username'] = $username;
            $newSmtp['encryption'] = $encryption;
            if ($password !== '') {
                // 留空表示保持已保存的密码不变
                $newSmtp['password'] = $password;
            }

            $existingFrom = is_array($existing['from'] ?? null) ? $existing['from'] : [];
            $newFrom = array_merge(['address' => '', 'name' => '轩宇-导航'], $existingFrom);
            $newFrom['address'] = $fromAddress;

            $new = $existing;
            $new['enabled'] = $mailEnabledInput;
            $new['admin_email'] = $adminEmail;
            $new['smtp'] = $newSmtp;
            $new['from'] = $newFrom;

            $fileHeader = '<?php' . "\n"
                . '/**' . "\n"
                . ' * 轩宇-导航 · 邮件配置' . "\n"
                . ' * 本文件由后台「系统工具 → 邮件设置与测试」保存生成，也可手动编辑。' . "\n"
                . ' */' . "\n\n"
                . "if (basename((string) (\$_SERVER['SCRIPT_FILENAME'] ?? '')) === basename(__FILE__)) {" . "\n"
                . '    http_response_code(403);' . "\n"
                . '    exit;' . "\n"
                . '}' . "\n\n"
                . 'return ';

            if (@file_put_contents($smtpFile, $fileHeader . var_export($new, true) . ';' . "\n", LOCK_EX) !== false) {
                // OPcache 默认 2 秒内不重查文件时间戳，主动失效避免本次请求读到旧配置
                if (function_exists('opcache_invalidate')) {
                    @opcache_invalidate($smtpFile, true);
                }
                $message = '邮件配置已保存到 config/smtp.php' . ($mailEnabledInput ? '' : '（当前为未启用状态）');
            } else {
                $error = '配置保存失败：config/smtp.php 不可写，请检查目录权限';
            }
        }
    }

    // ------------------------------------------------------------------
    // ⑥ API 令牌：新建
    // ------------------------------------------------------------------
    if ($action === 'create_token') {
        $name = trim((string) ($_POST['token_name'] ?? ''));
        $permissions = (string) ($_POST['token_permissions'] ?? 'read');
        $expiresAt = trim((string) ($_POST['token_expires'] ?? ''));

        if ($name === '') {
            $error = '令牌名称不能为空';
        } elseif (!in_array($permissions, ['read', 'write'], true)) {
            $error = '权限参数不合法';
        } else {
            $name = $functions->textCut($name, 100);
            $expiresValue = null;

            if ($expiresAt !== '') {
                if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $expiresAt)) {
                    $expiresValue = $expiresAt . ' 23:59:59';
                } else {
                    $error = '过期时间格式不正确（应为 YYYY-MM-DD）';
                }
            }

            if ($error === '') {
                try {
                    $token = bin2hex(random_bytes(32));
                } catch (Throwable $e) {
                    $token = md5(uniqid('', true)) . md5(uniqid('', true));
                }

                // user_id = 0 表示管理级令牌。
                $stmt = $db->prepare(
                    'INSERT INTO api_tokens (token, name, permissions, user_id, expires_at, created_at)
                     VALUES (?, ?, ?, 0, ?, NOW())'
                );

                try {
                    $stmt->execute([$token, $name, $permissions, $expiresValue]);
                    $newTokenPlain = $token;
                    $activeTab = 'api';
                    $message = '令牌创建成功，请立即复制保存（明文仅本次显示）';
                } catch (Throwable $e) {
                    $error = '令牌创建失败：' . $e->getMessage();
                }
            }
        }
    }

    // ------------------------------------------------------------------
    // ⑦ API 令牌：切换权限 / 删除
    // ------------------------------------------------------------------
    if ($action === 'toggle_token_perm') {
        $id = (int) ($_POST['token_id'] ?? 0);
        $stmt = $db->prepare('SELECT permissions FROM api_tokens WHERE id = ? LIMIT 1');
        $stmt->execute([$id]);
        $row = $stmt->fetch();

        if (!$row) {
            $error = '令牌不存在';
        } else {
            $next = strtolower((string) $row['permissions']) === 'write' ? 'read' : 'write';
            $update = $db->prepare('UPDATE api_tokens SET permissions = ? WHERE id = ?');
            $update->execute([$next, $id]);
            $message = '令牌权限已切换为「' . ($next === 'write' ? 'write（读写）' : 'read（只读）') . '」';
        }
    }

    if ($action === 'delete_token') {
        $id = (int) ($_POST['token_id'] ?? 0);
        $stmt = $db->prepare('DELETE FROM api_tokens WHERE id = ?');
        $stmt->execute([$id]);
        $message = '令牌已删除';
    }

    // ------------------------------------------------------------------
    // ⑧ 联系留言：标记已读 / 未读 / 删除
    // ------------------------------------------------------------------
    if (in_array($action, ['msg_read', 'msg_unread', 'msg_delete'], true)) {
        $id = (int) ($_POST['message_id'] ?? 0);

        if ($id <= 0) {
            $error = '留言不存在';
        } elseif ($action === 'msg_delete') {
            $contactService->delete($id);
            $message = '留言已删除';
        } else {
            $contactService->markRead($id, $action === 'msg_read');
            $message = $action === 'msg_read' ? '已标记为已读' : '已标记为未读';
        }
    }
}

/* ==========================================================================
 * 读取展示数据
 * ========================================================================== */
$backupFiles = $backupService->listFiles(20);

$tokenRows = [];
$hasTokenTable = $backupService->tableExists('api_tokens');

if ($hasTokenTable) {
    $tokenRows = $db->query(
        'SELECT t.id, t.token, t.name, t.permissions, t.user_id, t.created_at, t.expires_at, t.last_used_at,
                u.username
         FROM api_tokens t
         LEFT JOIN users u ON t.user_id = u.id
         ORDER BY t.id DESC'
    )->fetchAll();
}

$cronKey = trim((string) $functions->getSetting('cron_key'));

// 站点根地址（与 Functions::getLogoUrl 相同思路：协议 + 主机 + 去掉 admin/api/install 的基础路径）
$navIsHttps = (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off')
    || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower((string) $_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https')
    || (int) ($_SERVER['SERVER_PORT'] ?? 0) === 443;
$navBasePath = str_replace('\\', '/', dirname((string) ($_SERVER['SCRIPT_NAME'] ?? '')));
$navBasePath = preg_replace('#/(admin|api|install)$#', '', $navBasePath);

if ($navBasePath === '/' || $navBasePath === '.' || $navBasePath === '\\') {
    $navBasePath = '';
}

$cronBaseUrl = ($navIsHttps ? 'https://' : 'http://') . ($_SERVER['HTTP_HOST'] ?? 'localhost') . $navBasePath;
$cronUrl = $cronBaseUrl . '/cron/daily_check.php' . ($cronKey !== '' ? '?key=' . urlencode($cronKey) : '');

$healthSummary = $healthService->summary(0);

$mailConfig = $mailService->config();
$mailEnabled = $mailService->enabled();
$mailHost = (string) ($mailConfig['smtp']['host'] ?? '');
$mailPort = (string) ($mailConfig['smtp']['port'] ?? '');
$mailEncryption = (string) ($mailConfig['smtp']['encryption'] ?? '');
$mailUsername = (string) ($mailConfig['smtp']['username'] ?? '');
$mailPasswordSet = trim((string) ($mailConfig['smtp']['password'] ?? '')) !== '';
$mailFromAddress = (string) ($mailConfig['from']['address'] ?? '');
$mailAdminEmail = $mailService->adminEmail();

$unreadMessages = $contactService->countUnread();

$msgPage = max(1, (int) ($_GET['page'] ?? 1));
$msgUnreadOnly = ((string) ($_GET['unread'] ?? '') === '1');
$msgSize = 15;
$msgList = $contactService->paginate($msgPage, $msgSize, $msgUnreadOnly);
$msgPages = max(1, (int) $msgList['pages']);

// 备份表名 → 中文标签
$tableLabels = [
    'categories'       => '分类',
    'nav_links'        => '导航链接',
    'settings'         => '系统设置',
    'users'            => '用户',
    'applications'     => '申请',
    'shares'           => '分享',
    'api_tokens'       => 'API 令牌',
    'contact_messages' => '联系留言',
];
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>系统工具 - 后台管理</title>
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
                    <span>系统工具</span>
                </div>
                <h1 class="page-title">
                    <span class="title-icon"><i class="fas fa-toolbox"></i></span>
                    系统工具
                </h1>
                <p class="page-subtitle">数据备份与恢复、开放 API 令牌、定时任务、邮件测试与联系留言，集中在一处管理。</p>
            </div>
            <div class="page-header-meta">
                <span class="meta-pill">
                    <i class="fas fa-envelope-open-text"></i>
                    未读留言 <?php echo (int) $unreadMessages; ?> 条
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

            <ul class="nav nav-tabs mb-3" id="toolsTabs" role="tablist">
                <li class="nav-item" role="presentation">
                    <button class="nav-link active" id="tabBtn-backup" data-bs-toggle="tab" data-bs-target="#tab-backup" type="button" role="tab">
                        <i class="fas fa-database me-1"></i>数据备份与恢复
                    </button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link" id="tabBtn-api" data-bs-toggle="tab" data-bs-target="#tab-api" type="button" role="tab">
                        <i class="fas fa-key me-1"></i>API 令牌管理
                    </button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link" id="tabBtn-cron" data-bs-toggle="tab" data-bs-target="#tab-cron" type="button" role="tab">
                        <i class="fas fa-clock-rotate-left me-1"></i>定时任务
                    </button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link" id="tabBtn-mail" data-bs-toggle="tab" data-bs-target="#tab-mail" type="button" role="tab">
                        <i class="fas fa-envelope me-1"></i>邮件设置与测试
                    </button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link" id="tabBtn-messages" data-bs-toggle="tab" data-bs-target="#tab-messages" type="button" role="tab">
                        <i class="fas fa-comment-dots me-1"></i>联系留言
                        <?php if ($unreadMessages > 0): ?>
                            <span class="badge bg-danger ms-1"><?php echo (int) $unreadMessages; ?></span>
                        <?php endif; ?>
                    </button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link" id="tabBtn-update" data-bs-toggle="tab" data-bs-target="#tab-update" type="button" role="tab">
                        <i class="fas fa-arrow-up me-1"></i>版本检测
                    </button>
                </li>
            </ul>

            <div class="tab-content" id="toolsTabContent">

                <!-- ============================================================
                     1) 数据备份与恢复
                     ============================================================ -->
                <div class="tab-pane fade show active" id="tab-backup" role="tabpanel">
                    <div class="row g-4 align-items-start">
                        <div class="col-xl-5 col-lg-6">
                            <div class="card form-card mb-4">
                                <div class="card-header">
                                    <div>
                                        <h5 class="mb-0"><i class="fas fa-file-export"></i>导出备份</h5>
                                        <span class="card-subtitle">导出为 JSON 文件，无需 mysqldump</span>
                                    </div>
                                </div>
                                <div class="card-body">
                                    <div class="alert alert-warning small">
                                        <i class="fas fa-triangle-exclamation"></i>
                                        <div>
                                            备份文件包含<strong>用户密码哈希与 API 令牌原文</strong>，导入时会被原样还原，
                                            因此可以把账号与令牌完整恢复出来。但也正因为如此：
                                            <strong>请勿把备份文件放到网站根目录或任何可公开访问的位置</strong>，
                                            也不要在聊天工具里随意发送。
                                            出于「一份备份外泄即可直接调用付费接口」的考虑，
                                            <code>settings.ai_api_key</code>（AI 接口密钥）<strong>不会导出</strong>，恢复后需重新填写。
                                        </div>
                                    </div>

                                    <form method="POST" class="mb-2">
                                        <input type="hidden" name="tab" value="backup">
                                        <input type="hidden" name="export_backup" value="full">
                                        <button type="submit" class="btn btn-primary w-100">
                                            <i class="fas fa-download me-2"></i>导出全部数据（含用户表）
                                        </button>
                                    </form>

                                    <form method="POST" class="mb-2">
                                        <input type="hidden" name="tab" value="backup">
                                        <input type="hidden" name="export_backup" value="no_users">
                                        <button type="submit" class="btn btn-outline-primary w-100">
                                            <i class="fas fa-download me-2"></i>导出但排除用户表
                                        </button>
                                    </form>

                                    <form method="POST">
                                        <input type="hidden" name="tab" value="backup">
                                        <input type="hidden" name="action" value="save_backup_file">
                                        <button type="submit" class="btn btn-outline-secondary w-100">
                                            <i class="fas fa-hard-drive me-2"></i>保存到服务器 backups/
                                        </button>
                                    </form>
                                </div>
                            </div>

                            <div class="card form-card">
                                <div class="card-header">
                                    <div>
                                        <h5 class="mb-0"><i class="fas fa-file-import"></i>导入恢复</h5>
                                        <span class="card-subtitle">上传 JSON 备份，按主键覆盖合并（不删除现有数据）</span>
                                    </div>
                                </div>
                                <div class="card-body">
                                    <form method="POST" enctype="multipart/form-data"
                                          onsubmit="return confirmTools('导入会按主键覆盖写入现有数据，且不可撤销。\n确定要继续吗？');">
                                        <input type="hidden" name="tab" value="backup">
                                        <input type="hidden" name="action" value="import_backup">

                                        <div class="mb-3">
                                            <label class="form-label">选择备份文件（.json，≤ 5MB）*</label>
                                            <input type="file" name="backup_file" class="form-control" accept=".json,application/json" required>
                                        </div>

                                        <div class="form-check mb-3">
                                            <input class="form-check-input" type="checkbox" name="confirm_import" id="confirmImport" value="1" required>
                                            <label class="form-check-label" for="confirmImport">
                                                我已确认要覆盖导入，并已知晓：备份文件里的用户密码与 API 令牌会原样还原
                                            </label>
                                        </div>

                                        <button type="submit" class="btn btn-danger w-100">
                                            <i class="fas fa-upload me-2"></i>开始导入恢复
                                        </button>
                                    </form>
                                </div>
                            </div>
                        </div>

                        <div class="col-xl-7 col-lg-6">
                            <?php if ($importDone): ?>
                                <div class="card table-card mb-4">
                                    <div class="card-header">
                                        <div>
                                            <h5 class="mb-0"><i class="fas fa-clipboard-check"></i>导入结果</h5>
                                            <span class="card-subtitle">各表实际恢复的行数</span>
                                        </div>
                                    </div>
                                    <div class="card-body">
                                        <div class="table-responsive">
                                            <table class="table admin-table align-middle">
                                                <thead>
                                                    <tr>
                                                        <th>数据表</th>
                                                        <th width="140">恢复行数</th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    <?php foreach ($importRestored as $table => $count): ?>
                                                        <tr>
                                                            <td>
                                                                <span class="cell-title"><?php echo $functions->escape($table); ?></span>
                                                                <span class="badge badge-soft-info mt-1"><?php echo $functions->escape($tableLabels[$table] ?? '未知表'); ?></span>
                                                            </td>
                                                            <td><span class="sort-chip"><?php echo (int) $count; ?></span></td>
                                                        </tr>
                                                    <?php endforeach; ?>
                                                    <?php if (empty($importRestored)): ?>
                                                        <tr><td colspan="2" class="text-center text-muted">没有可恢复的数据</td></tr>
                                                    <?php endif; ?>
                                                </tbody>
                                            </table>
                                        </div>
                                    </div>
                                </div>
                            <?php endif; ?>

                            <div class="card table-card">
                                <div class="card-header">
                                    <div>
                                        <h5 class="mb-0"><i class="fas fa-box-archive"></i>服务器已有备份</h5>
                                        <span class="card-subtitle">目录：backups/（最多显示最近 20 个）</span>
                                    </div>
                                    <span class="count-badge">
                                        <i class="fas fa-file-code"></i>
                                        <?php echo count($backupFiles); ?> 个文件
                                    </span>
                                </div>
                                <div class="card-body">
                                    <div class="table-responsive">
                                        <table class="table table-hover admin-table align-middle">
                                            <thead>
                                                <tr>
                                                    <th>文件名</th>
                                                    <th width="110">大小</th>
                                                    <th width="180">生成时间</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach ($backupFiles as $file): ?>
                                                    <tr>
                                                        <td><span class="cell-title"><?php echo $functions->escape($file['name']); ?></span></td>
                                                        <td>
                                                            <?php
                                                                $sizeKb = $file['size'] / 1024;
                                                                echo $sizeKb >= 1024
                                                                    ? number_format($sizeKb / 1024, 2) . ' MB'
                                                                    : number_format($sizeKb, 1) . ' KB';
                                                            ?>
                                                        </td>
                                                        <td><span class="cell-date"><i class="far fa-calendar"></i><?php echo $functions->escape($file['time']); ?></span></td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>

                                    <?php if (empty($backupFiles)): ?>
                                        <div class="text-center py-5 text-muted">
                                            <i class="fas fa-box-open fa-3x mb-3"></i>
                                            <p>暂无服务器备份文件，可点击左侧「保存到服务器」生成。</p>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- ============================================================
                     2) API 令牌管理
                     ============================================================ -->
                <div class="tab-pane fade" id="tab-api" role="tabpanel">
                    <?php if ($newTokenPlain !== ''): ?>
                        <div class="card form-card mb-4 border-success">
                            <div class="card-header">
                                <div>
                                    <h5 class="mb-0 text-success"><i class="fas fa-circle-check"></i>令牌创建成功</h5>
                                    <span class="card-subtitle">明文令牌仅在本次显示，离开页面后无法再次查看</span>
                                </div>
                            </div>
                            <div class="card-body">
                                <label class="form-label">令牌明文</label>
                                <div class="input-group mb-3">
                                    <input type="text" class="form-control" id="newTokenValue" value="<?php echo $functions->escape($newTokenPlain); ?>" readonly>
                                    <button class="btn btn-outline-primary" type="button"
                                            onclick="copyToolsText(<?php echo $functions->escape(json_encode($newTokenPlain, JSON_UNESCAPED_UNICODE | JSON_HEX_APOS | JSON_HEX_QUOT)); ?>, this)">
                                        <i class="fas fa-copy"></i> 复制
                                    </button>
                                </div>
                                <label class="form-label">调用示例</label>
                                <pre class="bg-light border rounded p-3 mb-0" style="white-space:pre-wrap;word-break:break-all;">curl -H "Authorization: Bearer <?php echo $functions->escape($newTokenPlain); ?>" "<?php echo $functions->escape($cronBaseUrl); ?>/api/sites.php?page=1&amp;size=20"</pre>
                            </div>
                        </div>
                    <?php endif; ?>

                    <div class="row g-4 align-items-start">
                        <div class="col-xl-4 col-lg-5">
                            <div class="card form-card">
                                <div class="card-header">
                                    <div>
                                        <h5 class="mb-0"><i class="fas fa-plus"></i>新建令牌</h5>
                                        <span class="card-subtitle">生成一个开放 API 访问令牌</span>
                                    </div>
                                </div>
                                <div class="card-body">
                                    <form method="POST">
                                        <input type="hidden" name="tab" value="api">
                                        <input type="hidden" name="action" value="create_token">

                                        <div class="mb-3">
                                            <label class="form-label">令牌名称 *</label>
                                            <input type="text" name="token_name" class="form-control" maxlength="100" placeholder="例如：同步脚本" required>
                                        </div>

                                        <div class="mb-3">
                                            <label class="form-label">权限 *</label>
                                            <select name="token_permissions" class="form-select">
                                                <option value="read">read（只读）</option>
                                                <option value="write">write（读写）</option>
                                            </select>
                                        </div>

                                        <div class="mb-3">
                                            <label class="form-label">过期时间（可选）</label>
                                            <input type="date" name="token_expires" class="form-control">
                                            <small class="text-muted">留空表示永久有效</small>
                                        </div>

                                        <div class="d-grid">
                                            <button type="submit" class="btn btn-primary">
                                                <i class="fas fa-key me-2"></i>生成令牌
                                            </button>
                                        </div>
                                    </form>

                                    <div class="alert alert-info small mt-3 mb-0">
                                        <i class="fas fa-circle-info"></i>
                                        <div>
                                            令牌的 <code>user_id</code> 为 <strong>0</strong> 表示管理级（可读写公共与全部条目）；
                                            大于 0 时仅能读写该用户自己的条目。接口实现见 <code>api/sites.php</code>。
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="col-xl-8 col-lg-7">
                            <div class="card table-card">
                                <div class="card-header">
                                    <div>
                                        <h5 class="mb-0"><i class="fas fa-list"></i>令牌列表</h5>
                                        <span class="card-subtitle">列表只显示令牌前 8 位，完整明文仅在创建时显示一次</span>
                                    </div>
                                    <span class="count-badge">
                                        <i class="fas fa-key"></i>
                                        <?php echo count($tokenRows); ?> 个令牌
                                    </span>
                                </div>
                                <div class="card-body">
                                    <div class="table-responsive">
                                        <table class="table table-hover admin-table align-middle">
                                            <thead>
                                                <tr>
                                                    <th width="60">ID</th>
                                                    <th>名称 / 令牌</th>
                                                    <th width="80">权限</th>
                                                    <th width="110">作用范围</th>
                                                    <th width="120">创建时间</th>
                                                    <th width="120">过期时间</th>
                                                    <th width="120">最后使用</th>
                                                    <th width="120">操作</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach ($tokenRows as $row): ?>
                                                    <?php
                                                        $rowUserId = (int) $row['user_id'];
                                                        $scopeText = $rowUserId === 0
                                                            ? '管理级'
                                                            : (($row['username'] ?? '') !== '' ? $row['username'] : '用户#' . $rowUserId);
                                                        $rowPerm = strtolower((string) $row['permissions']) === 'write' ? 'write' : 'read';
                                                        $expired = !empty($row['expires_at']) && strtotime((string) $row['expires_at']) < time();
                                                    ?>
                                                    <tr>
                                                        <td><span class="cell-id"><?php echo (int) $row['id']; ?></span></td>
                                                        <td>
                                                            <div class="cell-title"><?php echo $functions->escape($row['name']); ?></div>
                                                            <code class="small"><?php echo $functions->escape(substr((string) $row['token'], 0, 8)); ?>…</code>
                                                        </td>
                                                        <td>
                                                            <span class="badge <?php echo $rowPerm === 'write' ? 'bg-success' : 'bg-info'; ?>">
                                                                <?php echo $functions->escape($rowPerm); ?>
                                                            </span>
                                                        </td>
                                                        <td><span class="badge badge-soft-primary"><?php echo $functions->escape($scopeText); ?></span></td>
                                                        <td><span class="cell-date"><i class="far fa-calendar"></i><?php echo $row['created_at'] ? $functions->escape(date('Y-m-d', strtotime((string) $row['created_at']))) : '-'; ?></span></td>
                                                        <td>
                                                            <?php if (empty($row['expires_at'])): ?>
                                                                <span class="text-muted">永久</span>
                                                            <?php else: ?>
                                                                <span class="cell-date <?php echo $expired ? 'text-danger' : ''; ?>">
                                                                    <i class="far fa-clock"></i><?php echo $functions->escape(date('Y-m-d', strtotime((string) $row['expires_at']))); ?>
                                                                </span>
                                                                <?php if ($expired): ?><span class="badge bg-danger mt-1">已过期</span><?php endif; ?>
                                                            <?php endif; ?>
                                                        </td>
                                                        <td>
                                                            <span class="cell-date">
                                                                <?php echo $row['last_used_at'] ? $functions->escape(date('Y-m-d H:i', strtotime((string) $row['last_used_at']))) : '从未使用'; ?>
                                                            </span>
                                                        </td>
                                                        <td>
                                                            <div class="table-actions">
                                                                <form method="POST" class="d-inline" onsubmit="return confirmTools('确定要切换该令牌的权限吗？');">
                                                                    <input type="hidden" name="tab" value="api">
                                                                    <input type="hidden" name="action" value="toggle_token_perm">
                                                                    <input type="hidden" name="token_id" value="<?php echo (int) $row['id']; ?>">
                                                                    <button type="submit" class="btn btn-action btn-action-edit" title="切换权限">
                                                                        <i class="fas fa-arrows-rotate"></i>
                                                                    </button>
                                                                </form>
                                                                <form method="POST" class="d-inline" onsubmit="return confirmTools('删除后使用该令牌的接口调用会立即失效，确定删除吗？');">
                                                                    <input type="hidden" name="tab" value="api">
                                                                    <input type="hidden" name="action" value="delete_token">
                                                                    <input type="hidden" name="token_id" value="<?php echo (int) $row['id']; ?>">
                                                                    <button type="submit" class="btn btn-action btn-action-delete" title="删除令牌">
                                                                        <i class="fas fa-trash-can"></i>
                                                                    </button>
                                                                </form>
                                                            </div>
                                                        </td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>

                                    <?php if (!$hasTokenTable): ?>
                                        <div class="alert alert-warning mb-0">
                                            <i class="fas fa-triangle-exclamation"></i>
                                            <div>
                                                未检测到 <code>api_tokens</code> 表，请先执行 <code>upgrade_v2.0.0.sql</code> 完成升级。
                                            </div>
                                        </div>
                                    <?php elseif (empty($tokenRows)): ?>
                                        <div class="text-center py-5 text-muted">
                                            <i class="fas fa-key fa-3x mb-3"></i>
                                            <p>暂无令牌，请在左侧新建。</p>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <div class="alert alert-light small mt-3 mb-0">
                                <div>
                                    <div class="mb-1"><strong>调用示例：</strong></div>
                                    <pre class="mb-0" style="white-space:pre-wrap;word-break:break-all;">curl -H "Authorization: Bearer &lt;token&gt;" "<?php echo $functions->escape($cronBaseUrl); ?>/api/sites.php?page=1&amp;size=20"</pre>
                                    <div class="mt-2 text-muted">
                                        支持请求头 <code>Authorization: Bearer &lt;token&gt;</code>、<code>X-Api-Token</code>，也兼容 URL 参数 <code>?token=</code>。
                                        列表接口可用参数：<code>category</code>、<code>user_id</code>、<code>q</code>、<code>page</code>、<code>size</code>。
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- ============================================================
                     3) 定时任务
                     ============================================================ -->
                <div class="tab-pane fade" id="tab-cron" role="tabpanel">
                    <div class="row g-4 align-items-start">
                        <div class="col-xl-6 col-lg-6">
                            <div class="card form-card mb-4">
                                <div class="card-header">
                                    <div>
                                        <h5 class="mb-0"><i class="fas fa-shield-halved"></i>定时密钥</h5>
                                        <span class="card-subtitle">用于让计划任务在未登录时访问 cron 脚本</span>
                                    </div>
                                </div>
                                <div class="card-body">
                                    <?php if ($cronKey !== ''): ?>
                                        <label class="form-label">当前密钥</label>
                                        <div class="input-group mb-3">
                                            <input type="text" class="form-control" id="cronKeyValue" value="<?php echo $functions->escape($cronKey); ?>" readonly>
                                            <button class="btn btn-outline-primary" type="button"
                                                    onclick="copyToolsText(<?php echo $functions->escape(json_encode($cronKey, JSON_UNESCAPED_UNICODE | JSON_HEX_APOS | JSON_HEX_QUOT)); ?>, this)">
                                                <i class="fas fa-copy"></i> 复制
                                            </button>
                                        </div>
                                    <?php else: ?>
                                        <div class="alert alert-warning">
                                            <i class="fas fa-triangle-exclamation"></i>
                                            尚未生成定时密钥，计划任务无法在未登录状态下访问。
                                        </div>
                                    <?php endif; ?>

                                    <form method="POST" onsubmit="return confirmTools('<?php echo $cronKey !== '' ? '重新生成后旧密钥立即失效，' : ''; ?>确定要生成新的定时密钥吗？');">
                                        <input type="hidden" name="tab" value="cron">
                                        <input type="hidden" name="action" value="generate_cron_key">
                                        <div class="d-grid">
                                            <button type="submit" class="btn btn-primary">
                                                <i class="fas fa-rotate me-2"></i><?php echo $cronKey !== '' ? '重新生成密钥' : '一键生成密钥'; ?>
                                            </button>
                                        </div>
                                    </form>
                                </div>
                            </div>

                            <div class="card form-card">
                                <div class="card-header">
                                    <div>
                                        <h5 class="mb-0"><i class="fas fa-link"></i>计划任务访问地址</h5>
                                        <span class="card-subtitle">复制到宝塔「计划任务 → 访问 URL」</span>
                                    </div>
                                </div>
                                <div class="card-body">
                                    <?php if ($cronKey !== ''): ?>
                                        <div class="input-group mb-3">
                                            <input type="text" class="form-control" id="cronUrlValue" value="<?php echo $functions->escape($cronUrl); ?>" readonly>
                                            <button class="btn btn-outline-primary" type="button"
                                                    onclick="copyToolsText(<?php echo $functions->escape(json_encode($cronUrl, JSON_UNESCAPED_UNICODE | JSON_HEX_APOS | JSON_HEX_QUOT)); ?>, this)">
                                                <i class="fas fa-copy"></i> 复制
                                            </button>
                                        </div>
                                    <?php else: ?>
                                        <div class="input-group mb-3">
                                            <input type="text" class="form-control" value="<?php echo $functions->escape($cronBaseUrl); ?>/cron/daily_check.php?key=未生成" readonly>
                                        </div>
                                    <?php endif; ?>

                                    <ul class="small text-muted mb-0 ps-3">
                                        <li><strong>建议频率：</strong>每天 1-2 次（例如 03:00 与 15:00）。</li>
                                        <li>宝塔面板：<strong>计划任务 → 添加任务 → 任务类型选「访问 URL」</strong>，粘贴上面的完整地址，执行周期选择每天固定时间。</li>
                                        <li>脚本输出为纯文本，方便在计划任务日志中查看检测结果；失效链接会按配置发送告警邮件。</li>
                                        <li>该脚本读取设置项 <code>cron_key</code>，也支持已登录管理员直接访问。</li>
                                    </ul>
                                </div>
                            </div>
                        </div>

                        <div class="col-xl-6 col-lg-6">
                            <div class="card table-card">
                                <div class="card-header">
                                    <div>
                                        <h5 class="mb-0"><i class="fas fa-heart-pulse"></i>链接健康检测</h5>
                                        <span class="card-subtitle">手动立即检测（与定时任务无关），检测公共导航</span>
                                    </div>
                                </div>
                                <div class="card-body">
                                    <div class="row g-3 mb-3">
                                        <div class="col-6 col-md-3">
                                            <div class="stat-card text-center border rounded p-3">
                                                <div class="stat-icon stat-primary"><i class="fas fa-list"></i></div>
                                                <div class="cell-title">正常</div>
                                                <div class="h4 mb-0"><?php echo (int) $healthSummary['ok']; ?></div>
                                            </div>
                                        </div>
                                        <div class="col-6 col-md-3">
                                            <div class="stat-card text-center border rounded p-3">
                                                <div class="stat-icon stat-warning"><i class="fas fa-gauge-high"></i></div>
                                                <div class="cell-title">较慢</div>
                                                <div class="h4 mb-0"><?php echo (int) $healthSummary['slow']; ?></div>
                                            </div>
                                        </div>
                                        <div class="col-6 col-md-3">
                                            <div class="stat-card text-center border rounded p-3">
                                                <div class="stat-icon stat-success"><i class="fas fa-circle-xmark"></i></div>
                                                <div class="cell-title">失败</div>
                                                <div class="h4 mb-0"><?php echo (int) $healthSummary['down']; ?></div>
                                            </div>
                                        </div>
                                        <div class="col-6 col-md-3">
                                            <div class="stat-card text-center border rounded p-3">
                                                <div class="stat-icon stat-info"><i class="fas fa-question"></i></div>
                                                <div class="cell-title">未检测</div>
                                                <div class="h4 mb-0"><?php echo (int) $healthSummary['unknown']; ?></div>
                                            </div>
                                        </div>
                                    </div>

                                    <form method="POST" onsubmit="this.querySelector('button[type=submit]').disabled = true;">
                                        <input type="hidden" name="tab" value="cron">
                                        <input type="hidden" name="action" value="run_health_check">
                                        <div class="d-grid">
                                            <button type="submit" class="btn btn-outline-primary">
                                                <i class="fas fa-play me-2"></i>立即检测公共导航
                                            </button>
                                        </div>
                                    </form>
                                    <small class="text-muted d-block mt-2">检测会逐条发起网络请求，链接较多时可能需要数十秒，请耐心等待。</small>

                                    <?php if ($healthResult !== null): ?>
                                        <hr>
                                        <div class="mb-2">
                                            <strong>本次结果：</strong>共 <?php echo (int) $healthResult['total']; ?> 条，
                                            正常 <?php echo (int) $healthResult['ok_count']; ?>，
                                            较慢 <?php echo (int) $healthResult['slow']; ?>，
                                            失败 <?php echo (int) $healthResult['down']; ?>。
                                        </div>
                                        <?php
                                            $downItems = [];
                                            foreach ($healthResult['items'] as $item) {
                                                if (($item['status'] ?? '') === 'down') {
                                                    $downItems[] = $item;
                                                }
                                            }
                                        ?>
                                        <?php if ($downItems): ?>
                                            <div class="table-responsive">
                                                <table class="table admin-table align-middle mb-0">
                                                    <thead>
                                                        <tr><th>失败链接</th><th width="120">状态码</th></tr>
                                                    </thead>
                                                    <tbody>
                                                        <?php foreach (array_slice($downItems, 0, 30) as $item): ?>
                                                            <tr>
                                                                <td>
                                                                    <span class="cell-title"><?php echo $functions->escape($item['name']); ?></span>
                                                                    <div class="small text-muted text-break"><?php echo $functions->escape($item['url']); ?></div>
                                                                </td>
                                                                <td><span class="badge bg-danger"><?php echo (int) ($item['http_code'] ?? 0); ?></span></td>
                                                            </tr>
                                                        <?php endforeach; ?>
                                                    </tbody>
                                                </table>
                                            </div>
                                            <?php if (count($downItems) > 30): ?>
                                                <small class="text-muted">仅列出前 30 条，共 <?php echo count($downItems); ?> 条失败。</small>
                                            <?php endif; ?>
                                        <?php else: ?>
                                            <div class="text-muted small">本次没有检测到失效链接。</div>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- ============================================================
                     4) 邮件设置与测试
                     ============================================================ -->
                <div class="tab-pane fade" id="tab-mail" role="tabpanel">
                    <div class="row g-4 align-items-start">
                        <div class="col-xl-6 col-lg-6">
                            <div class="card form-card">
                                <div class="card-header">
                                    <div>
                                        <h5 class="mb-0"><i class="fas fa-envelope-circle-check"></i>邮件配置</h5>
                                        <span class="card-subtitle">在线填写并保存到 config/smtp.php，无需手动改文件</span>
                                    </div>
                                    <span class="badge <?php echo $mailEnabled ? 'bg-success' : 'bg-secondary'; ?>">
                                        <?php echo $mailEnabled ? '已启用' : '未启用'; ?>
                                    </span>
                                </div>
                                <div class="card-body">
                                    <form method="POST">
                                        <input type="hidden" name="tab" value="mail">
                                        <input type="hidden" name="action" value="save_mail_config">

                                        <div class="form-check form-switch mb-3">
                                            <input class="form-check-input" type="checkbox" name="mail_enabled" id="mailEnabledSwitch" value="1" <?php echo $mailEnabled ? 'checked' : ''; ?>>
                                            <label class="form-check-label" for="mailEnabledSwitch">启用邮件功能</label>
                                        </div>

                                        <div class="mb-3">
                                            <label class="form-label">SMTP 主机 <span class="text-danger">*</span></label>
                                            <input type="text" name="mail_host" class="form-control" maxlength="100"
                                                   value="<?php echo $functions->escape($mailHost); ?>" placeholder="如 smtp.qiye.aliyun.com">
                                        </div>

                                        <div class="row g-3 mb-3">
                                            <div class="col-sm-6">
                                                <label class="form-label">端口 <span class="text-danger">*</span></label>
                                                <input type="number" name="mail_port" class="form-control" min="1" max="65535"
                                                       value="<?php echo $mailPort !== '' ? $functions->escape($mailPort) : '465'; ?>" placeholder="465">
                                            </div>
                                            <div class="col-sm-6">
                                                <label class="form-label">加密方式</label>
                                                <select name="mail_encryption" class="form-select">
                                                    <option value="ssl" <?php echo $mailEncryption === 'ssl' ? 'selected' : ''; ?>>SSL</option>
                                                    <option value="tls" <?php echo $mailEncryption === 'tls' ? 'selected' : ''; ?>>TLS</option>
                                                    <option value="" <?php echo $mailEncryption === '' ? 'selected' : ''; ?>>不加密</option>
                                                </select>
                                            </div>
                                        </div>

                                        <div class="mb-3">
                                            <label class="form-label">SMTP 账号</label>
                                            <input type="text" name="mail_username" class="form-control" maxlength="150" autocomplete="off"
                                                   value="<?php echo $functions->escape($mailUsername); ?>" placeholder="通常是完整邮箱地址">
                                        </div>

                                        <div class="mb-3">
                                            <label class="form-label">SMTP 密码</label>
                                            <input type="password" name="mail_password" class="form-control" maxlength="150" autocomplete="new-password"
                                                   placeholder="<?php echo $mailPasswordSet ? '已设置，留空则保持不变' : '邮箱密码或客户端授权码'; ?>">
                                            <small class="text-muted">留空则保持当前已保存的密码不变；QQ/163 等邮箱请填客户端授权码。</small>
                                        </div>

                                        <div class="mb-3">
                                            <label class="form-label">发件地址 <span class="text-danger">*</span></label>
                                            <input type="email" name="mail_from_address" class="form-control" maxlength="150"
                                                   value="<?php echo $functions->escape($mailFromAddress); ?>" placeholder="对外显示的发件人邮箱">
                                        </div>

                                        <div class="mb-3">
                                            <label class="form-label">管理员收件邮箱</label>
                                            <input type="email" name="mail_admin_email" class="form-control" maxlength="150"
                                                   value="<?php echo $functions->escape($mailAdminEmail); ?>" placeholder="接收申请通知与告警邮件">
                                            <small class="text-muted">留空则测试邮件无法使用默认收件人；发件人名称沿用站点标题。</small>
                                        </div>

                                        <div class="d-grid">
                                            <button type="submit" class="btn btn-primary">
                                                <i class="fas fa-floppy-disk me-2"></i>保存邮件配置
                                            </button>
                                        </div>
                                    </form>
                                </div>
                            </div>
                        </div>

                        <div class="col-xl-6 col-lg-6">
                            <div class="card form-card">
                                <div class="card-header">
                                    <div>
                                        <h5 class="mb-0"><i class="fas fa-paper-plane"></i>发送测试邮件</h5>
                                        <span class="card-subtitle">验证 SMTP 配置是否可以正常发信</span>
                                    </div>
                                </div>
                                <div class="card-body">
                                    <form method="POST">
                                        <input type="hidden" name="tab" value="mail">
                                        <input type="hidden" name="action" value="send_test_mail">

                                        <div class="mb-3">
                                            <label class="form-label">收件邮箱</label>
                                            <input type="email" name="test_email" class="form-control" placeholder="留空则使用管理员收件邮箱">
                                            <small class="text-muted">
                                                管理员收件邮箱：<?php echo $mailAdminEmail !== '' ? $functions->escape($mailAdminEmail) : '未配置'; ?>
                                            </small>
                                        </div>

                                        <div class="d-grid">
                                            <button type="submit" class="btn btn-primary">
                                                <i class="fas fa-paper-plane me-2"></i>发送测试邮件
                                            </button>
                                        </div>
                                    </form>

                                    <?php if ($mailResult !== null): ?>
                                        <hr>
                                        <?php if (!empty($mailResult['ok'])): ?>
                                            <div class="alert alert-success mb-0">
                                                <i class="fas fa-circle-check"></i> 测试邮件已发送成功，请查收（若未收到请检查垃圾邮件）。
                                            </div>
                                        <?php else: ?>
                                            <div class="alert alert-danger mb-0">
                                                <i class="fas fa-circle-xmark"></i>
                                                <div>
                                                    发送失败：错误代码 <code><?php echo $functions->escape((string) ($mailResult['error'] ?? 'unknown')); ?></code>，
                                                    具体原因见页面顶部提示。
                                                </div>
                                            </div>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- ============================================================
                     5) 联系留言
                     ============================================================ -->
                <div class="tab-pane fade" id="tab-messages" role="tabpanel">
                    <div class="card table-card">
                        <div class="card-header">
                            <div>
                                <h5 class="mb-0"><i class="fas fa-comment-dots"></i>留言列表</h5>
                                <span class="card-subtitle">来自前台「联系管理员」表单的留言</span>
                            </div>
                            <div class="d-flex gap-2">
                                <a href="tools.php?tab=messages<?php echo $msgUnreadOnly ? '' : '&unread=1'; ?>" class="btn btn-sm <?php echo $msgUnreadOnly ? 'btn-primary' : 'btn-outline-secondary'; ?>">
                                    <i class="fas fa-filter"></i> 只看未读
                                </a>
                                <?php if ($msgUnreadOnly): ?>
                                    <a href="tools.php?tab=messages" class="btn btn-sm btn-outline-secondary">
                                        <i class="fas fa-list"></i> 查看全部
                                    </a>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="card-body">
                            <div class="table-responsive">
                                <table class="table table-hover admin-table align-middle">
                                    <thead>
                                        <tr>
                                            <th width="60">ID</th>
                                            <th>称呼 / 邮箱</th>
                                            <th>主题与内容</th>
                                            <th width="130">IP</th>
                                            <th width="150">时间</th>
                                            <th width="150">操作</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($msgList['items'] as $msg): ?>
                                            <?php
                                                $fullText = (string) $msg['message'];
                                                $shortText = $functions->textCut($fullText, 60);
                                                $isLong = strlen($shortText) < strlen($fullText);
                                                $isRead = (int) $msg['is_read'] === 1;
                                            ?>
                                            <tr class="<?php echo $isRead ? '' : 'fw-semibold'; ?>">
                                                <td>
                                                    <span class="cell-id"><?php echo (int) $msg['id']; ?></span>
                                                    <?php if (!$isRead): ?><span class="badge bg-danger mt-1">未读</span><?php else: ?><span class="badge bg-secondary mt-1">已读</span><?php endif; ?>
                                                </td>
                                                <td>
                                                    <div class="cell-title"><?php echo $functions->escape($msg['name']); ?></div>
                                                    <a href="mailto:<?php echo $functions->escape($msg['email']); ?>" class="small"><?php echo $functions->escape($msg['email']); ?></a>
                                                </td>
                                                <td>
                                                    <div class="cell-title"><?php echo $functions->escape($msg['subject']); ?></div>
                                                    <div class="small text-muted text-break">
                                                        <?php echo nl2br($functions->escape($isLong ? $shortText : $fullText)); ?><?php echo $isLong ? '…' : ''; ?>
                                                    </div>
                                                    <?php if ($isLong): ?>
                                                        <a href="javascript:;" class="small" data-bs-toggle="collapse" data-bs-target="#msgFull<?php echo (int) $msg['id']; ?>">
                                                            <i class="fas fa-chevron-down"></i> 展开全文
                                                        </a>
                                                        <div class="collapse mt-2" id="msgFull<?php echo (int) $msg['id']; ?>">
                                                            <div class="bg-light border rounded p-2 small text-break"><?php echo nl2br($functions->escape($fullText)); ?></div>
                                                        </div>
                                                    <?php endif; ?>
                                                </td>
                                                <td><span class="small text-muted"><?php echo $functions->escape((string) ($msg['ip'] ?? '')); ?></span></td>
                                                <td><span class="cell-date"><i class="far fa-clock"></i><?php echo $functions->escape(date('Y-m-d H:i', strtotime((string) $msg['created_at']))); ?></span></td>
                                                <td>
                                                    <div class="table-actions">
                                                        <?php if ($isRead): ?>
                                                            <form method="POST" class="d-inline">
                                                                <input type="hidden" name="tab" value="messages">
                                                                <input type="hidden" name="action" value="msg_unread">
                                                                <input type="hidden" name="message_id" value="<?php echo (int) $msg['id']; ?>">
                                                                <button type="submit" class="btn btn-action btn-action-edit" title="标记为未读">
                                                                    <i class="fas fa-envelope"></i>
                                                                </button>
                                                            </form>
                                                        <?php else: ?>
                                                            <form method="POST" class="d-inline">
                                                                <input type="hidden" name="tab" value="messages">
                                                                <input type="hidden" name="action" value="msg_read">
                                                                <input type="hidden" name="message_id" value="<?php echo (int) $msg['id']; ?>">
                                                                <button type="submit" class="btn btn-action btn-action-edit" title="标记为已读">
                                                                    <i class="fas fa-envelope-open"></i>
                                                                </button>
                                                            </form>
                                                        <?php endif; ?>

                                                        <form method="POST" class="d-inline" onsubmit="return confirmTools('确定要删除这条留言吗？此操作不可恢复。');">
                                                            <input type="hidden" name="tab" value="messages">
                                                            <input type="hidden" name="action" value="msg_delete">
                                                            <input type="hidden" name="message_id" value="<?php echo (int) $msg['id']; ?>">
                                                            <button type="submit" class="btn btn-action btn-action-delete" title="删除留言">
                                                                <i class="fas fa-trash-can"></i>
                                                            </button>
                                                        </form>
                                                    </div>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>

                            <?php if (empty($msgList['items'])): ?>
                                <div class="text-center py-5 text-muted">
                                    <i class="fas fa-inbox fa-3x mb-3"></i>
                                    <p><?php echo $msgUnreadOnly ? '没有未读留言。' : '暂无留言。'; ?></p>
                                </div>
                            <?php endif; ?>

                            <?php if ((int) $msgList['total'] > 0): ?>
                                <div class="d-flex flex-wrap justify-content-between align-items-center mt-2">
                                    <span class="text-muted small">
                                        共 <?php echo (int) $msgList['total']; ?> 条留言，第 <?php echo (int) $msgList['page']; ?> / <?php echo $msgPages; ?> 页
                                    </span>
                                    <nav>
                                        <ul class="pagination pagination-sm mb-0">
                                            <?php
                                                $baseQuery = 'tools.php?tab=messages' . ($msgUnreadOnly ? '&unread=1' : '');
                                                $prevDisabled = $msgPage <= 1;
                                                $nextDisabled = $msgPage >= $msgPages;
                                            ?>
                                            <li class="page-item <?php echo $prevDisabled ? 'disabled' : ''; ?>">
                                                <a class="page-link" href="<?php echo $functions->escape($baseQuery . '&page=' . max(1, $msgPage - 1)); ?>">上一页</a>
                                            </li>
                                            <?php
                                                $startPage = max(1, $msgPage - 2);
                                                $endPage = min($msgPages, $startPage + 4);
                                                $startPage = max(1, $endPage - 4);
                                            ?>
                                            <?php for ($p = $startPage; $p <= $endPage; $p++): ?>
                                                <li class="page-item <?php echo $p === $msgPage ? 'active' : ''; ?>">
                                                    <a class="page-link" href="<?php echo $functions->escape($baseQuery . '&page=' . $p); ?>"><?php echo (int) $p; ?></a>
                                                </li>
                                            <?php endfor; ?>
                                            <li class="page-item <?php echo $nextDisabled ? 'disabled' : ''; ?>">
                                                <a class="page-link" href="<?php echo $functions->escape($baseQuery . '&page=' . min($msgPages, $msgPage + 1)); ?>">下一页</a>
                                            </li>
                                        </ul>
                                    </nav>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- ============================================================
                     6) 版本检测（GitHub Release 在线更新）
                     ============================================================ -->
                <div class="tab-pane fade" id="tab-update" role="tabpanel">
                    <div class="card">
                        <div class="card-header">
                            <div>
                                <h5 class="mb-0"><i class="fas fa-arrow-up"></i>版本检测</h5>
                                <span class="card-subtitle">通过 GitHub API 检测最新 Release 版本（API 不可达时自动回退读取仓库 VERSION 文件）</span>
                            </div>
                        </div>
                        <div class="card-body">
                            <div class="mb-3">
                                <label class="form-label">当前版本</label>
                                <input type="text" class="form-control" value="<?php echo $functions->escape(defined('APP_VERSION') ? APP_VERSION : '未知'); ?>" readonly>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">仓库地址</label>
                                <input type="text" class="form-control" value="https://github.com/xuanyu1937/XYdaohang" readonly>
                            </div>
                            <div id="updateCheckResult" class="mb-3" style="display:none;"></div>
                            <button type="button" class="btn btn-primary" id="checkUpdateBtn" onclick="checkForUpdate()">
                                <i class="fas fa-sync-alt me-1"></i>检测新版本
                            </button>
                            <div id="updateSpinner" class="mt-3" style="display:none;">
                                <span class="spinner-border spinner-border-sm me-2"></span>正在检测...
                            </div>
                        </div>
                    </div>

                    <div class="card mt-4">
                        <div class="card-header">
                            <div>
                                <h5 class="mb-0"><i class="fas fa-file-zipper"></i>上传更新包更新</h5>
                                <span class="card-subtitle">下载 GitHub Release 的 Source code (zip) 后上传，服务器自动完成覆盖更新</span>
                            </div>
                        </div>
                        <div class="card-body">
                            <div class="alert alert-info">
                                <div>
                                    <i class="fas fa-circle-info me-1"></i>更新流程：从
                                    <a href="https://github.com/xuanyu1937/XYdaohang/releases" target="_blank" rel="noopener">Releases 页面</a>
                                    下载新版 Source code (zip) → 在下方上传 → 系统自动校验版本、备份被覆盖的原文件到 <code>backups/</code> 并解压覆盖。
                                    <br><i class="fas fa-shield-halved me-1"></i>数据库配置（config/database.php）、SMTP 配置、本地配置、uploads/、backups/ 等本地文件永远不会被更新包覆盖。
                                </div>
                            </div>
                            <form method="post" enctype="multipart/form-data" onsubmit="return confirm('确认上传并执行更新？被覆盖的原文件会自动备份到 backups/ 目录。');">
                                <input type="hidden" name="tab" value="update">
                                <input type="hidden" name="action" value="upload_update">
                                <div class="mb-3">
                                    <label class="form-label" for="updatePackage">更新包（.zip，≤ 50MB）</label>
                                    <input class="form-control" type="file" id="updatePackage" name="update_package" accept=".zip,application/zip" required>
                                </div>
                                <button type="submit" class="btn btn-warning">
                                    <i class="fas fa-upload me-1"></i>上传并执行更新
                                </button>
                            </form>
                        </div>
                    </div>
                </div>

            </div>
        </div>
    </div>

    <script src="../assets/js/jquery3.7.1.js"></script>
    <script src="../assets/js/bootstrap.bundle.min.js"></script>
    <?php require_once __DIR__ . '/../includes/asset_fallbacks.php'; ?>
    <script>
    // 通用确认框
    function confirmTools(msg) {
        return window.confirm(msg);
    }

    // 版本检测：通过 GitHub API 获取最新 Release
    function checkForUpdate() {
        var btn = document.getElementById('checkUpdateBtn');
        var spinner = document.getElementById('updateSpinner');
        var result = document.getElementById('updateCheckResult');

        btn.disabled = true;
        spinner.style.display = 'block';
        result.style.display = 'none';

        var currentVersion = '<?php echo $functions->escape(defined('APP_VERSION') ? APP_VERSION : '0.0.0'); ?>';

        // 通过本站接口检测（服务器端抓取 GitHub，避免浏览器直连 api.github.com 被阻断）
        fetch('../api/check_update.php', {
            headers: { 'Accept': 'application/json' },
            credentials: 'same-origin'
        })
        .then(function (res) { return res.json(); })
        .then(function (data) {
            spinner.style.display = 'none';
            btn.disabled = false;
            result.style.display = 'block';

            if (!data.ok || !data.tag_name) {
                var reason = data.error === 'unauthorized' ? '登录状态已失效，请刷新页面后重试' : '暂时无法连接版本服务器，请稍后重试';
                result.innerHTML = '<div class="alert alert-warning"><div><i class="fas fa-exclamation-triangle me-1"></i>' + reason + '</div></div>';
                return;
            }

            var latestVersion = data.tag_name.replace(/^v/, '');
            var isNew = compareVersions(latestVersion, currentVersion) > 0;

            if (isNew) {
                result.innerHTML =
                    '<div class="alert alert-success"><div>' +
                    '<h6><i class="fas fa-arrow-up me-1"></i>发现新版本：v' + latestVersion + '</h6>' +
                    '<p class="mb-2">' + (data.body || '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/\n/g, '<br>').substring(0, 500) + '</p>' +
                    '<a href="' + data.html_url + '" target="_blank" class="btn btn-sm btn-success me-2"><i class="fas fa-download me-1"></i>前往下载</a>' +
                    '<a href="https://github.com/xuanyu1937/XYdaohang/releases" target="_blank" class="btn btn-sm btn-outline-secondary">查看全部版本</a>' +
                    '</div></div>';
            } else {
                result.innerHTML = '<div class="alert alert-info"><div><i class="fas fa-check-circle me-1"></i>当前已是最新版本（v' + currentVersion + '）</div></div>';
            }
        })
        .catch(function () {
            spinner.style.display = 'none';
            btn.disabled = false;
            result.style.display = 'block';
            result.innerHTML = '<div class="alert alert-danger"><div><i class="fas fa-times-circle me-1"></i>检测失败，本站接口无响应，请稍后重试</div></div>';
        });
    }

    // 版本号比较：返回 -1/0/1
    function compareVersions(a, b) {
        var pa = a.split('.').map(Number);
        var pb = b.split('.').map(Number);
        for (var i = 0; i < Math.max(pa.length, pb.length); i++) {
            var na = pa[i] || 0;
            var nb = pb[i] || 0;
            if (na > nb) return 1;
            if (na < nb) return -1;
        }
        return 0;
    }

    // 复制文本（优先使用 Clipboard API，失败时回退到临时 textarea）
    function fallbackCopyText(text, callback) {
        var area = document.createElement('textarea');
        area.value = text;
        area.style.position = 'fixed';
        area.style.top = '-9999px';
        area.style.opacity = '0';
        document.body.appendChild(area);
        area.focus();
        area.select();

        try {
            document.execCommand('copy');
            if (typeof callback === 'function') {
                callback();
            }
        } catch (err) {
            window.prompt('请手动复制以下内容：', text);
        }

        document.body.removeChild(area);
    }

    function copyToolsText(text, button) {
        var done = function () {
            if (!button) {
                return;
            }
            var original = button.innerHTML;
            button.innerHTML = '<i class="fas fa-check"></i> 已复制';
            setTimeout(function () {
                button.innerHTML = original;
            }, 1500);
        };

        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(text).then(done, function () {
                fallbackCopyText(text, done);
            });
        } else {
            fallbackCopyText(text, done);
        }
    }

    // 记住上次停留的 tab：优先使用服务端指定的 tab（来自表单/链接），否则用 localStorage
    (function () {
        var serverTab = <?php echo json_encode($activeTab !== '' ? $activeTab : null); ?>;
        var storedTab = null;

        try {
            storedTab = localStorage.getItem('toolsActiveTab');
        } catch (err) {
            storedTab = null;
        }

        var valid = ['backup', 'api', 'cron', 'mail', 'messages', 'update'];
        var target = serverTab || storedTab;

        if (valid.indexOf(target) === -1) {
            target = 'backup';
        }

        var trigger = document.querySelector('#toolsTabs button[data-bs-target="#tab-' + target + '"]');

        if (trigger && window.bootstrap && window.bootstrap.Tab) {
            new window.bootstrap.Tab(trigger).show();
        }

        try {
            localStorage.setItem('toolsActiveTab', target);
        } catch (err) {
            // 隐私模式下 localStorage 不可用时忽略
        }

        document.querySelectorAll('#toolsTabs button[data-bs-toggle="tab"]').forEach(function (btn) {
            btn.addEventListener('shown.bs.tab', function (event) {
                var paneId = (event.target.getAttribute('data-bs-target') || '').replace('#tab-', '');

                if (valid.indexOf(paneId) !== -1) {
                    try {
                        localStorage.setItem('toolsActiveTab', paneId);
                    } catch (err) {
                        // 忽略
                    }
                }
            });
        });
    })();
    </script>
</body>
</html>
