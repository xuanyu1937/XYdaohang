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

$systemConfig = $functions->getSystemConfig();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $functions->requireSameOrigin();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_basic'])) {
    $siteTitle = trim((string) ($_POST['site_title'] ?? ''));
    $siteDescription = trim((string) ($_POST['site_description'] ?? ''));
    $siteKeywords = trim((string) ($_POST['site_keywords'] ?? ''));

    $functions->updateSetting('site_title', $siteTitle);
    $functions->updateSetting('site_description', $siteDescription);
    $functions->updateSetting('site_keywords', $siteKeywords);

    // 新增配置项：前台监测总开关（默认开启，保持原有行为）与前台默认主题
    $functions->updateSetting('monitor_enabled', isset($_POST['monitor_enabled']) ? '1' : '0');

    $frontendTheme = (string) ($_POST['frontend_theme'] ?? 'system');
    if (!in_array($frontendTheme, ['system', 'light', 'dark'], true)) {
        $frontendTheme = 'system';
    }
    $functions->updateSetting('frontend_theme', $frontendTheme);

    if (isset($_FILES['site_logo']) && $_FILES['site_logo']['error'] === UPLOAD_ERR_OK) {
        $uploadResult = $functions->uploadLogo($_FILES['site_logo']);

        if ($uploadResult['success']) {
            $oldLogo = $functions->getSetting('site_logo');
            if (!empty($oldLogo)) {
                $functions->deleteLogoFile($oldLogo);
            }

            $functions->updateSetting('site_logo', $uploadResult['relativePath']);
            $message = '基础设置和Logo上传成功！';
        } else {
            $error = 'Logo上传失败：' . $uploadResult['error'];
        }
    } else {
        if (isset($_POST['delete_logo']) && $_POST['delete_logo'] == '1') {
            $oldLogo = $functions->getSetting('site_logo');
            if (!empty($oldLogo)) {
                $functions->deleteLogoFile($oldLogo);
                $functions->updateSetting('site_logo', '');
                $message = 'Logo已删除！';
            }
        } else {
            $message = '基础设置已保存！';
        }
    }
}

if (isset($_POST['save_footer'])) {
    $functions->updateSetting('show_icp', isset($_POST['show_icp']) ? '1' : '0');
    $functions->updateSetting('icp_number', $_POST['icp_number'] ?? '');
    $functions->updateSetting('show_gongan', isset($_POST['show_gongan']) ? '1' : '0');
    $functions->updateSetting('gongan_number', $_POST['gongan_number'] ?? '');
    // v2.3.0 新增：全站底部版权文字（支持 {year} / {site} 占位符，留空恢复默认）
    $footerCopyright = trim((string) ($_POST['footer_copyright'] ?? ''));
    $footerCopyright = function_exists('mb_substr') ? mb_substr($footerCopyright, 0, 120, 'UTF-8') : substr($footerCopyright, 0, 360);
    $functions->updateSetting('footer_copyright', $footerCopyright);
    $message = '备案与版权设置已保存';
}

/* v2.0.0 新增：前台体验（公告 / 显示方式 / 跳转 / 皮肤 / 注册 / 联系页 / SEO / 关于内容） */
if (isset($_POST['save_frontend'])) {
    $functions->updateSetting('announcement_enabled', isset($_POST['announcement_enabled']) ? '1' : '0');
    $functions->updateSetting('announcement_content', trim((string) ($_POST['announcement_content'] ?? '')));

    $defaultView = (string) ($_POST['default_view'] ?? 'grid');
    if (!in_array($defaultView, ['grid', 'list', 'compact'], true)) {
        $defaultView = 'grid';
    }
    $functions->updateSetting('default_view', $defaultView);

    $defaultTarget = (string) ($_POST['default_target'] ?? '_blank');
    $functions->updateSetting('default_target', $defaultTarget === '_self' ? '_self' : '_blank');

    $hotCount = (int) ($_POST['hot_count'] ?? 8);
    if ($hotCount < 1 || $hotCount > 24) {
        $hotCount = 8;
    }
    $functions->updateSetting('hot_count', (string) $hotCount);

    $skin = (string) ($_POST['theme_skin'] ?? 'default');
    if (!in_array($skin, ['default', 'purple', 'orange', 'mint'], true)) {
        $skin = 'default';
    }
    $functions->updateSetting('theme_skin', $skin);

    $functions->updateSetting('bg_image', trim((string) ($_POST['bg_image'] ?? '')));
    $functions->updateSetting('register_enabled', isset($_POST['register_enabled']) ? '1' : '0');
    $functions->updateSetting('contact_enabled', isset($_POST['contact_enabled']) ? '1' : '0');
    $functions->updateSetting('show_speed_label', isset($_POST['show_speed_label']) ? '1' : '0');
    $functions->updateSetting('health_alert_enabled', isset($_POST['health_alert_enabled']) ? '1' : '0');
    $functions->updateSetting('seo_title', trim((string) ($_POST['seo_title'] ?? '')));
    $functions->updateSetting('seo_description', trim((string) ($_POST['seo_description'] ?? '')));
    $functions->updateSetting('about_content', trim((string) ($_POST['about_content'] ?? '')));

    $message = '前台体验设置已保存';
}

/* v2.0.0 新增：AI 分类推荐（OpenAI 兼容接口，留空则自动降级为本地关键词匹配） */
if (isset($_POST['save_ai'])) {
    $functions->updateSetting('ai_enabled', isset($_POST['ai_enabled']) ? '1' : '0');
    $functions->updateSetting('ai_api_url', trim((string) ($_POST['ai_api_url'] ?? '')));
    $functions->updateSetting('ai_model', trim((string) ($_POST['ai_model'] ?? '')));

    $apiKey = trim((string) ($_POST['ai_api_key'] ?? ''));
    // 留空表示不修改，避免把已保存的密钥误清空
    if ($apiKey !== '') {
        $functions->updateSetting('ai_api_key', $apiKey);
    }

    $message = 'AI 设置已保存';
}

$siteTitle = $functions->getSetting('site_title');
$siteDescription = $functions->getSetting('site_description');
$siteKeywords = $functions->getSetting('site_keywords');
$siteLogo = $functions->getSetting('site_logo');
$siteLogoUrl = $functions->getLogoUrl($siteLogo);
$showICP = $functions->getSetting('show_icp');
$icpNumber = $functions->getSetting('icp_number');
$showGongan = $functions->getSetting('show_gongan');
$gonganNumber = $functions->getSetting('gongan_number');
$footerCopyright = $functions->getSetting('footer_copyright');

// 新增配置项：未写入过该键时按“开启 / 跟随系统”处理，避免升级后功能被意外关闭
$monitorEnabled = $functions->getSetting('monitor_enabled');
$monitorChecked = ($monitorEnabled !== '0');
$frontendTheme = $functions->getSetting('frontend_theme');
if (!in_array($frontendTheme, ['system', 'light', 'dark'], true)) {
    $frontendTheme = 'system';
}
$adminName = (string) ($_SESSION['admin_username'] ?? '');

/* v2.0.0 新增设置项的当前值（未写入过时使用与前台一致的默认值） */
$announcementEnabled = $functions->getSetting('announcement_enabled') === '1';
$announcementContent = (string) $functions->getSetting('announcement_content');

$defaultView = (string) $functions->getSetting('default_view');
if (!in_array($defaultView, ['grid', 'list', 'compact'], true)) {
    $defaultView = 'grid';
}

$defaultTarget = $functions->getSetting('default_target') === '_self' ? '_self' : '_blank';

$hotCount = (int) $functions->getSetting('hot_count');
if ($hotCount < 1 || $hotCount > 24) {
    $hotCount = 8;
}

$themeSkin = (string) $functions->getSetting('theme_skin');
if (!in_array($themeSkin, ['default', 'purple', 'orange', 'mint'], true)) {
    $themeSkin = 'default';
}

$bgImage = (string) $functions->getSetting('bg_image');
$registerEnabled = $functions->getSetting('register_enabled') !== '0';
$contactEnabled = $functions->getSetting('contact_enabled') !== '0';
$showSpeedLabel = $functions->getSetting('show_speed_label') !== '0';
$healthAlertEnabled = $functions->getSetting('health_alert_enabled') !== '0';
$seoTitle = (string) $functions->getSetting('seo_title');
$seoDescription = (string) $functions->getSetting('seo_description');
$aboutContent = (string) $functions->getSetting('about_content');

$aiEnabled = $functions->getSetting('ai_enabled') === '1';
$aiApiUrl = (string) $functions->getSetting('ai_api_url');
$aiModel = (string) $functions->getSetting('ai_model');
$hasAiKey = trim((string) $functions->getSetting('ai_api_key')) !== '';
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>系统设置 - 后台管理</title>
    <link href="../assets/css/bootstrap.css" rel="stylesheet">
    <link href="../assets/css/font-awesome.min.css" rel="stylesheet" onerror="this.onerror=null;this.href='https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css';">
    <link href="../assets/sweetalert2/sweetalert2.min.css" rel="stylesheet">
    <link href="../assets/css/admin.css?v=1.1.3" rel="stylesheet">
    <style>
        /* ==================================================================
           系统设置页（仅本页使用，颜色与间距统一取自 admin.css 变量）
           ================================================================== */

        .page-breadcrumb a {
            color: inherit;
            text-decoration: none;
            transition: color 0.18s ease;
        }

        .page-breadcrumb a:hover {
            color: var(--admin-primary);
        }

        .settings-card + .settings-card {
            margin-top: 20px;
        }

        .settings-card .card-body {
            padding: 22px;
        }

        /* 表单字段 */
        .field {
            margin-bottom: 18px;
        }

        .field:last-child {
            margin-bottom: 0;
        }

        .field-label {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            margin-bottom: 7px;
        }

        .field-label .form-label {
            margin-bottom: 0;
        }

        .field-hint {
            flex: 0 0 auto;
            color: var(--admin-muted);
            font-size: 11.5px;
            font-weight: 400;
        }

        .field .form-text {
            margin-top: 6px;
            font-size: 11.5px;
            line-height: 1.6;
            opacity: 0.92;
        }

        .field-foot {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 12px;
            margin-top: 6px;
        }

        .field-foot .form-text {
            flex: 1 1 auto;
            margin-top: 0;
        }

        .char-count {
            flex: 0 0 auto;
            color: var(--admin-muted);
            font-size: 11.5px;
            font-variant-numeric: tabular-nums;
            line-height: 1.6;
        }

        .char-count.is-limit {
            color: var(--admin-danger);
        }

        .req {
            color: var(--admin-danger);
            font-weight: 600;
        }

        /* 子分区 */
        .settings-subsection {
            margin-top: 24px;
            padding-top: 22px;
            border-top: 1px dashed var(--admin-border-strong);
        }

        .subsection-head {
            display: flex;
            align-items: center;
            gap: 8px;
            margin-bottom: 14px;
            color: var(--admin-text);
            font-size: 13.5px;
            font-weight: 600;
        }

        .subsection-head i {
            color: var(--admin-primary);
            font-size: 13px;
        }

        .subsection-head .hint {
            margin-left: auto;
            color: var(--admin-muted);
            font-size: 11.5px;
            font-weight: 400;
        }

        /* 开关 / 下拉行 */
        .set-row {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 18px;
            padding: 14px 16px;
            background: #fbfcfe;
            border: 1px solid var(--admin-border);
            border-radius: var(--admin-radius-md);
        }

        .set-row + .set-row {
            margin-top: 12px;
        }

        .set-row-main {
            min-width: 0;
        }

        .set-row-title {
            color: var(--admin-text);
            font-size: 13.5px;
            font-weight: 600;
        }

        .set-row-desc {
            margin-top: 3px;
            color: var(--admin-muted);
            font-size: 12px;
            line-height: 1.55;
        }

        .set-select {
            flex: 0 0 168px;
            width: 168px;
            border-radius: 10px;
        }

        .set-switch-wrap {
            display: flex;
            align-items: center;
            gap: 10px;
            flex: 0 0 auto;
        }

        .switch-text {
            color: var(--admin-muted);
            font-size: 12px;
            white-space: nowrap;
        }

        .set-switch {
            margin: 0;
            padding-left: 0;
            min-height: 0;
        }

        .set-switch .form-check-input {
            margin: 0;
            cursor: pointer;
        }

        /* Logo 区域 */
        .logo-panel {
            padding: 14px;
            background: #fbfcfe;
            border: 1px solid var(--admin-border);
            border-radius: var(--admin-radius-md);
        }

        .logo-panel + .logo-panel,
        .logo-panel + .logo-dropzone,
        .logo-dropzone + .logo-panel {
            margin-top: 12px;
        }

        .logo-panel-head {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            margin-bottom: 10px;
        }

        .logo-panel-title {
            color: var(--admin-text);
            font-size: 13px;
            font-weight: 600;
        }

        .logo-preview-body {
            display: flex;
            align-items: center;
            justify-content: center;
            min-height: 96px;
            padding: 12px;
            background: #ffffff;
            border: 1px solid var(--admin-border-soft);
            border-radius: var(--admin-radius-sm);
        }

        .logo-preview-body img {
            max-width: 100%;
            max-height: 84px;
            object-fit: contain;
        }

        .logo-path {
            margin-top: 10px;
            color: var(--admin-muted);
            font-size: 11.5px;
            word-break: break-all;
        }

        .logo-deleted-note {
            margin-top: 10px;
            padding: 8px 10px;
            background: var(--admin-warning-soft);
            border: 1px solid #fef3c7;
            border-radius: 8px;
            color: #92400e;
            font-size: 11.5px;
        }

        .logo-dropzone {
            display: block;
            padding: 22px 16px;
            cursor: pointer;
            text-align: center;
            background: linear-gradient(180deg, #f8faff 0%, #ffffff 100%);
            border: 1.5px dashed #c7d2fe;
            border-radius: var(--admin-radius-md);
            transition: background-color 0.2s ease, border-color 0.2s ease,
                        box-shadow 0.2s ease;
        }

        .logo-dropzone:hover,
        .logo-dropzone.is-dragover {
            background: var(--admin-primary-soft);
            border-color: var(--admin-primary);
            box-shadow: 0 0 0 3px rgba(59, 91, 219, 0.08);
        }

        .dz-icon {
            display: flex;
            align-items: center;
            justify-content: center;
            width: 46px;
            height: 46px;
            margin: 0 auto 10px;
            background: #ffffff;
            border: 1px solid var(--admin-primary-border);
            border-radius: 14px;
            color: var(--admin-primary);
            font-size: 17px;
            transition: transform 0.2s ease;
        }

        .logo-dropzone:hover .dz-icon {
            transform: translateY(-2px);
        }

        .dz-title,
        .dz-sub,
        .dz-meta {
            display: block;
        }

        .dz-title {
            color: var(--admin-text);
            font-size: 13.5px;
            font-weight: 600;
        }

        .dz-sub {
            margin-top: 3px;
            color: var(--admin-muted);
            font-size: 12px;
        }

        .dz-meta {
            margin-top: 8px;
            color: #94a3b8;
            font-size: 11.5px;
        }

        .logo-tips {
            margin: 12px 0 0;
            padding: 0;
            list-style: none;
        }

        .logo-tips li {
            display: flex;
            gap: 8px;
            color: var(--admin-muted);
            font-size: 12px;
            line-height: 1.7;
        }

        .logo-tips li i {
            margin-top: 5px;
            color: var(--admin-success);
            font-size: 10px;
        }

        .logo-file-meta {
            margin-top: 10px;
            color: var(--admin-muted);
            font-size: 11.5px;
            word-break: break-all;
        }

        /* 备案区块 */
        .license-block {
            height: 100%;
            padding: 14px 16px;
            background: #fbfcfe;
            border: 1px solid var(--admin-border);
            border-radius: var(--admin-radius-md);
        }

        .license-switch {
            margin-bottom: 14px;
            padding-bottom: 12px;
            border-bottom: 1px dashed var(--admin-border-soft);
        }

        .license-switch .form-check.form-switch {
            padding-left: 0;
        }

        .license-switch .form-check {
            display: flex;
            align-items: center;
            gap: 10px;
            min-height: 24px;
            margin: 0;
        }

        .license-switch .form-check-input {
            margin: 0;
            cursor: pointer;
        }

        .license-switch .form-check-label {
            font-size: 13px;
            font-weight: 600;
            color: var(--admin-text);
            cursor: pointer;
        }

        .license-block .field {
            margin-bottom: 0;
        }

        /* 底部操作区 */
        .form-actions {
            display: flex;
            align-items: center;
            gap: 10px;
            flex-wrap: wrap;
            margin-top: 24px;
            padding-top: 20px;
            border-top: 1px solid var(--admin-border-soft);
        }

        .form-actions .spacer {
            flex: 1 1 auto;
        }

        .form-actions .btn {
            min-height: 38px;
        }

        .form-actions-note {
            color: var(--admin-muted);
            font-size: 11.5px;
        }

        /* 设置说明 */
        .help-note {
            display: flex;
            gap: 10px;
            padding: 12px 14px;
            margin-bottom: 18px;
            background: var(--admin-info-soft);
            border: 1px solid #dbeafe;
            border-radius: var(--admin-radius-md);
            color: #0369a1;
            font-size: 12.5px;
            line-height: 1.65;
        }

        .help-note i {
            margin-top: 3px;
        }

        .help-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 2px 26px;
        }

        .help-item {
            display: flex;
            align-items: flex-start;
            gap: 10px;
            padding: 9px 0;
            min-width: 0;
        }

        .help-item > div {
            min-width: 0;
        }

        .help-icon {
            display: flex;
            align-items: center;
            justify-content: center;
            width: 26px;
            height: 26px;
            flex: 0 0 26px;
            margin-top: 1px;
            background: var(--admin-primary-soft);
            border-radius: 8px;
            color: var(--admin-primary);
            font-size: 11px;
        }

        .help-item .help-title {
            display: block;
            margin: 0 0 3px;
            color: var(--admin-text-secondary);
            font-size: 12.5px;
            font-weight: 600;
            line-height: 1.5;
        }

        .help-item p {
            margin: 0;
            color: var(--admin-muted);
            font-size: 12px;
            line-height: 1.6;
            overflow-wrap: anywhere;
        }

        /* 按钮交互反馈 */
        .btn {
            transition: background-color 0.18s ease, border-color 0.18s ease,
                        color 0.18s ease, box-shadow 0.18s ease, transform 0.12s ease;
        }

        .btn-primary:hover {
            transform: translateY(-1px);
            box-shadow: 0 8px 18px -10px rgba(59, 91, 219, 0.65);
        }

        .btn-outline-primary {
            border-color: var(--admin-primary-border);
            color: var(--admin-primary);
        }

        .btn-outline-primary:hover {
            background: var(--admin-primary);
            border-color: var(--admin-primary);
            color: #ffffff;
            transform: translateY(-1px);
            box-shadow: 0 8px 18px -10px rgba(59, 91, 219, 0.45);
        }

        .btn-primary:active {
            transform: translateY(0) scale(0.99);
            box-shadow: 0 2px 6px rgba(59, 91, 219, 0.3);
        }

        .btn-outline-secondary:hover,
        .btn-outline-danger:hover {
            transform: translateY(-1px);
        }

        .btn-outline-secondary:active,
        .btn-outline-danger:active {
            transform: translateY(0);
        }

        @media (max-width: 767.98px) {
            .settings-card .card-body {
                padding: 18px;
            }

            .help-grid {
                grid-template-columns: 1fr;
                gap: 0;
            }

            .set-row {
                flex-direction: column;
                align-items: stretch;
                gap: 12px;
            }

            .set-select {
                width: 100%;
                flex: 1 1 auto;
            }

            .set-switch-wrap {
                justify-content: space-between;
            }

            .form-actions {
                flex-direction: column;
                align-items: stretch;
            }

            .form-actions .spacer {
                display: none;
            }

            .form-actions .btn {
                width: 100%;
            }

            .form-actions-note {
                text-align: center;
            }
        }
    </style>
</head>
<body>
    <?php include 'sidebar.php'; ?>

    <div class="main-content-wrapper" id="mainContentWrapper">
        <header class="page-header">
            <div class="page-heading">
                <div class="page-breadcrumb">
                    <a href="index.php">首页</a>
                    <i class="fas fa-angle-right"></i>
                    <span>系统设置</span>
                </div>
                <h1 class="page-title">
                    <span class="title-icon"><i class="fas fa-sliders"></i></span>
                    系统设置
                </h1>
                <p class="page-subtitle">管理站点基础信息、前台展示策略与备案信息，修改后点击对应按钮保存即可生效。</p>
            </div>
            <div class="page-header-meta">
                <span class="meta-pill">
                    <i class="far fa-clock"></i>
                    <span id="currentTime"><?php echo date('Y-m-d H:i:s'); ?></span>
                </span>
                <span class="meta-pill">
                    <i class="fas fa-user-circle"></i>
                    <?php echo $functions->escape($adminName); ?>
                </span>
            </div>
        </header>

        <div class="admin-page-body">
            <?php if ($message): ?>
                <div class="alert alert-success alert-dismissible fade show" role="alert">
                    <i class="fas fa-circle-check"></i>
                    <span><?php echo $functions->escape($message); ?></span>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="关闭"></button>
                </div>
            <?php endif; ?>

            <?php if ($error): ?>
                <div class="alert alert-danger alert-dismissible fade show" role="alert">
                    <i class="fas fa-circle-exclamation"></i>
                    <span><?php echo $functions->escape($error); ?></span>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="关闭"></button>
                </div>
            <?php endif; ?>

            <section class="card settings-card">
                <div class="card-header">
                    <div>
                        <h5><i class="fas fa-gear"></i>基础设置</h5>
                        <span class="card-subtitle">站点基础信息、Logo 与前台展示策略</span>
                    </div>
                </div>
                <div class="card-body">
                    <form method="POST" enctype="multipart/form-data" id="basicSettingsForm">
                        <div class="row g-4">
                            <div class="col-lg-6">
                                <div class="field">
                                    <div class="field-label">
                                        <label class="form-label" for="site_title">网站标题 <span class="req">*</span></label>
                                        <span class="field-hint">建议 20 字以内</span>
                                    </div>
                                    <input type="text"
                                           id="site_title"
                                           name="site_title"
                                           class="form-control"
                                           value="<?php echo $functions->escape($siteTitle); ?>"
                                           maxlength="60"
                                           autocomplete="off"
                                           required
                                           placeholder="例如：轩宇导航">
                                    <div class="form-text">显示在浏览器标签页、搜索结果标题与分享卡片中</div>
                                </div>

                                <div class="field">
                                    <div class="field-label">
                                        <label class="form-label" for="site_description">网站描述</label>
                                        <span class="field-hint">建议 80 字以内</span>
                                    </div>
                                    <textarea id="site_description"
                                              name="site_description"
                                              class="form-control"
                                              rows="3"
                                              maxlength="200"
                                              placeholder="用一句话介绍你的导航站"><?php echo $functions->escape($siteDescription); ?></textarea>
                                    <div class="form-text">用于 SEO 描述与链接分享摘要，简洁清晰即可</div>
                                </div>

                                <div class="field">
                                    <label class="form-label" for="site_keywords">网站关键词</label>
                                    <textarea id="site_keywords"
                                              name="site_keywords"
                                              class="form-control"
                                              rows="2"
                                              maxlength="200"
                                              placeholder="例如：导航网站,网址导航,实用工具,学习资源"><?php echo $functions->escape($siteKeywords); ?></textarea>
                                    <div class="form-text">多个关键词用英文逗号分隔，建议 5 ~ 8 个</div>
                                </div>
                            </div>

                            <div class="col-lg-6">
                                <div class="field">
                                    <div class="field-label">
                                        <label class="form-label">网站 Logo</label>
                                        <span class="field-hint">建议 200 × 60 像素</span>
                                    </div>

                                    <?php if (!empty($siteLogoUrl)): ?>
                                        <div class="logo-panel" id="currentLogoBlock">
                                            <div class="logo-panel-head">
                                                <span class="logo-panel-title">当前 Logo</span>
                                                <button type="button" class="btn btn-sm btn-outline-danger" onclick="deleteLogo()">
                                                    <i class="fas fa-trash-can"></i>删除
                                                </button>
                                            </div>
                                            <div class="logo-preview-body">
                                                <img id="currentLogoImage"
                                                     src="<?php echo $functions->escape($siteLogoUrl); ?>"
                                                     alt="当前 Logo"
                                                     onerror="this.style.display='none';">
                                            </div>
                                            <input type="hidden" name="delete_logo" id="deleteLogoInput" value="0">
                                            <?php if (!empty($siteLogo)): ?>
                                                <div class="logo-path">
                                                    <i class="fas fa-link me-1"></i><?php echo $functions->escape($siteLogo); ?>
                                                </div>
                                            <?php endif; ?>
                                            <div class="logo-deleted-note" id="logoDeletedNote" hidden>
                                                <i class="fas fa-triangle-exclamation me-1"></i>已标记删除，点击「保存基础设置」后生效
                                            </div>
                                        </div>
                                    <?php endif; ?>

                                    <label class="logo-dropzone" id="logoDropzone" for="logoFileInput">
                                        <span class="dz-icon"><i class="fas fa-cloud-arrow-up"></i></span>
                                        <span class="dz-title">点击选择图片，或拖拽到此处</span>
                                        <span class="dz-sub">上传后将替换当前 Logo，保存设置后生效</span>
                                        <span class="dz-meta">JPG / PNG / GIF / WebP / ICO · 不超过 5MB</span>
                                    </label>
                                    <input type="file"
                                           name="site_logo"
                                           id="logoFileInput"
                                           class="d-none"
                                           accept="image/*"
                                           onchange="previewLogo(this)">

                                    <div class="logo-panel" id="newLogoPreview" hidden>
                                        <div class="logo-panel-head">
                                            <span class="logo-panel-title">新 Logo 预览</span>
                                            <button type="button" class="btn btn-sm btn-outline-secondary" onclick="clearNewLogo()">
                                                <i class="fas fa-xmark"></i>取消
                                            </button>
                                        </div>
                                        <div class="logo-preview-body">
                                            <img id="previewImage" src="" alt="新 Logo 预览">
                                        </div>
                                        <div class="logo-file-meta" id="logoFileMeta"></div>
                                    </div>

                                    <ul class="logo-tips">
                                        <li><i class="fas fa-circle"></i>推荐使用透明背景 PNG，显示效果更干净</li>
                                        <li><i class="fas fa-circle"></i>宽高比例接近 200 × 60 时不易变形</li>
                                        <li><i class="fas fa-circle"></i>单个文件不超过 5MB，过大将无法上传</li>
                                    </ul>
                                </div>
                            </div>
                        </div>

                        <div class="settings-subsection">
                            <div class="subsection-head">
                                <i class="fas fa-sliders"></i>前台展示与监测
                                <span class="hint">仅影响前台导航页</span>
                            </div>

                            <div class="set-row">
                                <div class="set-row-main">
                                    <div class="set-row-title">开启网址可用性 &amp; 速度检测</div>
                                    <div class="set-row-desc">开启后前台卡片会检测站点的在线状态与响应速度；关闭后不再发起检测请求，可减少访问时的网络与服务器开销。</div>
                                </div>
                                <div class="set-switch-wrap">
                                    <span class="switch-text" id="monitorStateText"><?php echo $monitorChecked ? '已开启' : '已关闭'; ?></span>
                                    <div class="form-check form-switch set-switch">
                                        <input class="form-check-input"
                                               type="checkbox"
                                               role="switch"
                                               name="monitor_enabled"
                                               id="monitor_enabled"
                                               value="1"
                                               <?php echo $monitorChecked ? 'checked' : ''; ?>>
                                        <label class="form-check-label visually-hidden" for="monitor_enabled">开启网址可用性与速度检测</label>
                                    </div>
                                </div>
                            </div>

                            <div class="set-row">
                                <div class="set-row-main">
                                    <div class="set-row-title">前台深色模式默认设置</div>
                                    <div class="set-row-desc">控制访客首次打开前台时使用的颜色模式，访客仍可在前台手动切换。</div>
                                </div>
                                <select class="form-select set-select" name="frontend_theme" id="frontend_theme" aria-label="前台默认主题">
                                    <option value="system" <?php echo $frontendTheme === 'system' ? 'selected' : ''; ?>>跟随系统</option>
                                    <option value="light" <?php echo $frontendTheme === 'light' ? 'selected' : ''; ?>>默认浅色</option>
                                    <option value="dark" <?php echo $frontendTheme === 'dark' ? 'selected' : ''; ?>>默认深色</option>
                                </select>
                            </div>
                        </div>

                        <div class="form-actions">
                            <button type="button" class="btn btn-outline-secondary" onclick="testLogoDisplay()">
                                <i class="fas fa-eye"></i>测试 Logo 显示
                            </button>
                            <span class="spacer"></span>
                            <button type="submit" name="save_basic" class="btn btn-primary px-4">
                                <i class="fas fa-floppy-disk"></i>保存基础设置
                            </button>
                        </div>
                    </form>
                </div>
            </section>

            <section class="card settings-card">
                <div class="card-header">
                    <div>
                        <h5><i class="fas fa-shield-halved"></i>备案与版权</h5>
                        <span class="card-subtitle">控制前台页脚展示的 ICP、公安备案与版权信息</span>
                    </div>
                </div>
                <div class="card-body">
                    <form method="POST" id="footerSettingsForm">
                        <div class="row g-4">
                            <div class="col-lg-6">
                                <div class="license-block">
                                    <div class="license-switch">
                                        <div class="form-check form-switch">
                                            <input class="form-check-input"
                                                   type="checkbox"
                                                   role="switch"
                                                   name="show_icp"
                                                   id="show_icp"
                                                   value="1"
                                                   <?php echo $showICP == '1' ? 'checked' : ''; ?>>
                                            <label class="form-check-label" for="show_icp">在页脚显示 ICP 备案号</label>
                                        </div>
                                    </div>
                                    <div class="field">
                                        <label class="form-label" for="icp_number">ICP 备案号</label>
                                        <input type="text"
                                               id="icp_number"
                                               name="icp_number"
                                               class="form-control"
                                               value="<?php echo $functions->escape($icpNumber); ?>"
                                               maxlength="50"
                                               placeholder="例如：京ICP备12345678号"
                                               <?php echo $showICP == '1' ? 'required' : ''; ?>>
                                        <div class="field-foot">
                                            <span class="form-text">与工信部备案系统中的编号保持一致</span>
                                            <span class="char-count" id="icpCount"><?php echo strlen((string) $icpNumber); ?> / 50</span>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div class="col-lg-6">
                                <div class="license-block">
                                    <div class="license-switch">
                                        <div class="form-check form-switch">
                                            <input class="form-check-input"
                                                   type="checkbox"
                                                   role="switch"
                                                   name="show_gongan"
                                                   id="show_gongan"
                                                   value="1"
                                                   <?php echo $showGongan == '1' ? 'checked' : ''; ?>>
                                            <label class="form-check-label" for="show_gongan">在页脚显示公安备案号</label>
                                        </div>
                                    </div>
                                    <div class="field">
                                        <label class="form-label" for="gongan_number">公安备案号</label>
                                        <input type="text"
                                               id="gongan_number"
                                               name="gongan_number"
                                               class="form-control"
                                               value="<?php echo $functions->escape($gonganNumber); ?>"
                                               maxlength="50"
                                               placeholder="例如：京公网安备11010502030123号"
                                               <?php echo $showGongan == '1' ? 'required' : ''; ?>>
                                        <div class="field-foot">
                                            <span class="form-text">须与公安备案平台登记的编号一致</span>
                                            <span class="char-count" id="gonganCount"><?php echo strlen((string) $gonganNumber); ?> / 50</span>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="row g-4" style="margin-top:0;">
                            <div class="col-12">
                                <div class="license-block">
                                    <div class="field">
                                        <label class="form-label" for="footer_copyright">底部版权信息</label>
                                        <input type="text"
                                               id="footer_copyright"
                                               name="footer_copyright"
                                               class="form-control"
                                               value="<?php echo $functions->escape((string) $footerCopyright); ?>"
                                               maxlength="120"
                                               placeholder="默认：© {year} {site}，例如：© {year} 我的导航 · 保留所有权利">
                                        <div class="field-foot">
                                            <span class="form-text">支持 {year}（当前年份）与 {site}（站点名称）占位符；留空恢复默认。登录用户还可在「我的导航 → 账号」中自定义自己页面的版权。</span>
                                            <span class="char-count" id="copyrightCount"><?php echo strlen((string) $footerCopyright); ?> / 120</span>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="form-actions">
                            <span class="form-actions-note">备案与版权信息仅在前台页脚展示，不影响站点其他内容。</span>
                            <span class="spacer"></span>
                            <button type="submit" name="save_footer" class="btn btn-outline-primary px-4">
                                <i class="fas fa-floppy-disk"></i>保存备案与版权设置
                            </button>
                        </div>
                    </form>
                </div>
            </section>

            <section class="card settings-card">
                <div class="card-header">
                    <div>
                        <h5><i class="fas fa-wand-magic-sparkles"></i>前台体验</h5>
                        <span class="card-subtitle">公告、显示方式、跳转行为、配色皮肤、注册与联系页、SEO 与关于内容</span>
                    </div>
                </div>
                <div class="card-body">
                    <form method="POST" id="frontendSettingsForm">
                        <div class="settings-subsection">
                            <div class="subsection-head">
                                <i class="fas fa-bullhorn"></i>
                                <div>
                                    <strong>公告栏</strong>
                                    <span class="hint">开启后前台顶部会出现滚动公告，鼠标悬停或键盘聚焦时自动暂停。</span>
                                </div>
                            </div>

                            <div class="form-check form-switch mb-3">
                                <input class="form-check-input"
                                       type="checkbox"
                                       role="switch"
                                       name="announcement_enabled"
                                       id="announcement_enabled"
                                       value="1"
                                       <?php echo $announcementEnabled ? 'checked' : ''; ?>>
                                <label class="form-check-label" for="announcement_enabled">启用顶部公告栏</label>
                            </div>

                            <?php if (!$announcementEnabled || trim((string) $announcementContent) === ''): ?>
                                <div class="alert alert-info" style="margin-bottom:14px;padding:10px 14px;font-size:13px;border-radius:8px;">
                                    <i class="fas fa-circle-info"></i>
                                    当前公告未在前台展示：请勾选「启用顶部公告栏」并填写公告内容，保存后刷新前台首页即可看到滚动公告条。
                                </div>
                            <?php endif; ?>

                            <div class="field">
                                <label class="form-label" for="announcement_content">公告内容</label>
                                <textarea id="announcement_content"
                                          name="announcement_content"
                                          class="form-control"
                                          rows="2"
                                          maxlength="200"
                                          placeholder="例如：本站已升级到 v2.0，新增个人导航与书签导入功能。"><?php echo $functions->escape($announcementContent); ?></textarea>
                                <div class="field-foot">
                                    <span class="form-text">建议控制在 60 字以内，过长会滚动较久</span>
                                    <span class="char-count" id="announcementCount"><?php echo function_exists('mb_strlen') ? mb_strlen($announcementContent, 'UTF-8') : strlen($announcementContent); ?> / 200</span>
                                </div>
                            </div>
                        </div>

                        <div class="settings-subsection">
                            <div class="subsection-head">
                                <i class="fas fa-table-cells-large"></i>
                                <div>
                                    <strong>展示与跳转</strong>
                                    <span class="hint">访客未手动切换时会使用这里的默认值，登录用户的个人选择保存在其浏览器本地。</span>
                                </div>
                            </div>

                            <div class="row g-3">
                                <div class="col-lg-4 col-md-6">
                                    <div class="field mb-0">
                                        <label class="form-label" for="default_view">默认显示方式</label>
                                        <select class="form-select set-select" name="default_view" id="default_view">
                                            <option value="grid" <?php echo $defaultView === 'grid' ? 'selected' : ''; ?>>网格（卡片）</option>
                                            <option value="list" <?php echo $defaultView === 'list' ? 'selected' : ''; ?>>列表（含描述）</option>
                                            <option value="compact" <?php echo $defaultView === 'compact' ? 'selected' : ''; ?>>紧凑（密集）</option>
                                        </select>
                                    </div>
                                </div>

                                <div class="col-lg-4 col-md-6">
                                    <div class="field mb-0">
                                        <label class="form-label" for="default_target">链接打开方式</label>
                                        <select class="form-select set-select" name="default_target" id="default_target">
                                            <option value="_blank" <?php echo $defaultTarget === '_blank' ? 'selected' : ''; ?>>新标签页打开</option>
                                            <option value="_self" <?php echo $defaultTarget === '_self' ? 'selected' : ''; ?>>当前页打开</option>
                                        </select>
                                    </div>
                                </div>

                                <div class="col-lg-4 col-md-6">
                                    <div class="field mb-0">
                                        <label class="form-label" for="hot_count">热门区显示数量</label>
                                        <input type="number"
                                               id="hot_count"
                                               name="hot_count"
                                               class="form-control"
                                               value="<?php echo (int) $hotCount; ?>"
                                               min="1"
                                               max="24">
                                        <span class="form-text">按累计点击量排序，1 ~ 24</span>
                                    </div>
                                </div>

                                <div class="col-lg-4 col-md-6">
                                    <div class="field mb-0">
                                        <label class="form-label" for="theme_skin">配色皮肤</label>
                                        <select class="form-select set-select" name="theme_skin" id="theme_skin">
                                            <option value="default" <?php echo $themeSkin === 'default' ? 'selected' : ''; ?>>经典蓝</option>
                                            <option value="purple" <?php echo $themeSkin === 'purple' ? 'selected' : ''; ?>>静谧紫</option>
                                            <option value="orange" <?php echo $themeSkin === 'orange' ? 'selected' : ''; ?>>暖阳橙</option>
                                            <option value="mint" <?php echo $themeSkin === 'mint' ? 'selected' : ''; ?>>薄荷青</option>
                                        </select>
                                    </div>
                                </div>

                                <div class="col-lg-8 col-md-6">
                                    <div class="field mb-0">
                                        <label class="form-label" for="bg_image">自定义背景图地址</label>
                                        <input type="text"
                                               id="bg_image"
                                               name="bg_image"
                                               class="form-control"
                                               value="<?php echo $functions->escape($bgImage); ?>"
                                               maxlength="500"
                                               placeholder="https://example.com/bg.jpg（留空使用默认渐变背景）">
                                    </div>
                                </div>
                            </div>

                            <div class="row g-3 mt-1">
                                <div class="col-lg-4 col-md-6">
                                    <div class="form-check form-switch">
                                        <input class="form-check-input"
                                               type="checkbox"
                                               role="switch"
                                               name="register_enabled"
                                               id="register_enabled"
                                               value="1"
                                               <?php echo $registerEnabled ? 'checked' : ''; ?>>
                                        <label class="form-check-label" for="register_enabled">开放前台注册</label>
                                    </div>
                                </div>
                                <div class="col-lg-4 col-md-6">
                                    <div class="form-check form-switch">
                                        <input class="form-check-input"
                                               type="checkbox"
                                               role="switch"
                                               name="contact_enabled"
                                               id="contact_enabled"
                                               value="1"
                                               <?php echo $contactEnabled ? 'checked' : ''; ?>>
                                        <label class="form-check-label" for="contact_enabled">开放联系留言</label>
                                    </div>
                                </div>
                                <div class="col-lg-4 col-md-6">
                                    <div class="form-check form-switch">
                                        <input class="form-check-input"
                                               type="checkbox"
                                               role="switch"
                                               name="show_speed_label"
                                               id="show_speed_label"
                                               value="1"
                                               <?php echo $showSpeedLabel ? 'checked' : ''; ?>>
                                        <label class="form-check-label" for="show_speed_label">卡片显示响应速度</label>
                                    </div>
                                </div>
                                <div class="col-lg-4 col-md-6">
                                    <div class="form-check form-switch">
                                        <input class="form-check-input"
                                               type="checkbox"
                                               role="switch"
                                               name="health_alert_enabled"
                                               id="health_alert_enabled"
                                               value="1"
                                               <?php echo $healthAlertEnabled ? 'checked' : ''; ?>>
                                        <label class="form-check-label" for="health_alert_enabled">失效链接邮件告警</label>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="settings-subsection">
                            <div class="subsection-head">
                                <i class="fas fa-magnifying-glass"></i>
                                <div>
                                    <strong>SEO 与内容页</strong>
                                    <span class="hint">留空时前台会退回使用「网站标题 / 网站描述」，关于页会展示默认文案。</span>
                                </div>
                            </div>

                            <div class="field">
                                <label class="form-label" for="seo_title">SEO 标题</label>
                                <input type="text"
                                       id="seo_title"
                                       name="seo_title"
                                       class="form-control"
                                       value="<?php echo $functions->escape($seoTitle); ?>"
                                       maxlength="120"
                                       placeholder="留空则使用「网站标题 - 网站描述」">
                            </div>

                            <div class="field">
                                <label class="form-label" for="seo_description">SEO 描述</label>
                                <textarea id="seo_description"
                                          name="seo_description"
                                          class="form-control"
                                          rows="2"
                                          maxlength="200"
                                          placeholder="留空则使用「网站描述」"><?php echo $functions->escape($seoDescription); ?></textarea>
                            </div>

                            <div class="field mb-0">
                                <label class="form-label" for="about_content">关于页内容</label>
                                <textarea id="about_content"
                                          name="about_content"
                                          class="form-control"
                                          rows="6"
                                          maxlength="5000"
                                          placeholder="留空则展示内置的默认文案（功能介绍与数据说明）。"><?php echo $functions->escape($aboutContent); ?></textarea>
                                <span class="form-text">支持换行，纯文本展示，不需要写 HTML</span>
                            </div>
                        </div>

                        <div class="form-actions">
                            <span class="form-actions-note">这些设置会立即影响前台展示，访客刷新后生效。</span>
                            <span class="spacer"></span>
                            <button type="submit" name="save_frontend" class="btn btn-primary px-4">
                                <i class="fas fa-floppy-disk"></i>保存前台设置
                            </button>
                        </div>
                    </form>
                </div>
            </section>

            <section class="card settings-card">
                <div class="card-header">
                    <div>
                        <h5><i class="fas fa-robot"></i>AI 分类推荐</h5>
                        <span class="card-subtitle">用于后台「导航管理 → 一键添加」时自动推荐分类</span>
                    </div>
                </div>
                <div class="card-body">
                    <form method="POST" id="aiSettingsForm">
                        <div class="help-note">
                            <i class="fas fa-lightbulb"></i>
                            <span>
                                填写任意 OpenAI 兼容接口即可。未配置或调用失败时，系统会自动退回内置的关键词匹配规则，
                                该功能不会因为没配 AI 而失效。
                            </span>
                        </div>

                        <div class="form-check form-switch mb-3">
                            <input class="form-check-input"
                                   type="checkbox"
                                   role="switch"
                                   name="ai_enabled"
                                   id="ai_enabled"
                                   value="1"
                                   <?php echo $aiEnabled ? 'checked' : ''; ?>>
                            <label class="form-check-label" for="ai_enabled">启用 AI 分类推荐</label>
                        </div>

                        <div class="field">
                            <label class="form-label" for="ai_api_url">接口地址</label>
                            <input type="text"
                                   id="ai_api_url"
                                   name="ai_api_url"
                                   class="form-control"
                                   value="<?php echo $functions->escape($aiApiUrl); ?>"
                                   maxlength="300"
                                   placeholder="https://api.openai.com/v1/chat/completions">
                            <span class="form-text">需为兼容 /chat/completions 的完整地址</span>
                        </div>

                        <div class="field">
                            <label class="form-label" for="ai_model">模型名称</label>
                            <input type="text"
                                   id="ai_model"
                                   name="ai_model"
                                   class="form-control"
                                   value="<?php echo $functions->escape($aiModel); ?>"
                                   maxlength="100"
                                   placeholder="例如：gpt-4o-mini、qwen-plus、glm-4-flash">
                        </div>

                        <div class="field mb-0">
                            <label class="form-label" for="ai_api_key">接口密钥</label>
                            <input type="password"
                                   id="ai_api_key"
                                   name="ai_api_key"
                                   class="form-control"
                                   autocomplete="new-password"
                                   spellcheck="false"
                                   placeholder="<?php echo $hasAiKey ? '已配置，留空表示不修改' : '粘贴 API Key'; ?>">
                            <span class="form-text">
                                <?php echo $hasAiKey ? '当前已保存密钥，出于安全考虑不回显；留空提交不会覆盖。' : '密钥仅保存在本站数据库中，不会输出到前台。'; ?>
                            </span>
                        </div>

                        <div class="form-actions">
                            <span class="form-actions-note">AI 接口密钥不会写入备份文件，导出备份时可以放心分享给协作者排查问题。</span>
                            <span class="spacer"></span>
                            <button type="submit" name="save_ai" class="btn btn-primary px-4">
                                <i class="fas fa-floppy-disk"></i>保存 AI 设置
                            </button>
                        </div>
                    </form>
                </div>
            </section>

            <section class="card settings-card">
                <div class="card-header">
                    <div>
                        <h5><i class="fas fa-circle-info"></i>设置说明</h5>
                        <span class="card-subtitle">各项参数的作用与填写建议</span>
                    </div>
                </div>
                <div class="card-body">
                    <div class="help-note">
                        <i class="fas fa-lightbulb"></i>
                        <span>带 <span class="req">*</span> 的为必填项。基础设置与备案信息分别保存，修改完对应模块后请点击该模块底部的保存按钮。</span>
                    </div>

                    <div class="help-grid">
                        <div class="help-item">
                            <span class="help-icon"><i class="fas fa-tag"></i></span>
                            <div>
                                <strong class="help-title">网站标题</strong>
                                <p>显示在浏览器标签页与搜索结果标题中，也会用于分享卡片，建议控制在 20 字以内。</p>
                            </div>
                        </div>
                        <div class="help-item">
                            <span class="help-icon"><i class="fas fa-align-left"></i></span>
                            <div>
                                <strong class="help-title">网站描述</strong>
                                <p>用于搜索引擎摘要和链接分享，一句话说清站点定位即可，过长会被截断。</p>
                            </div>
                        </div>
                        <div class="help-item">
                            <span class="help-icon"><i class="fas fa-key"></i></span>
                            <div>
                                <strong class="help-title">网站关键词</strong>
                                <p>多个关键词用英文逗号分隔，建议 5 ~ 8 个，堆砌过多反而影响收录。</p>
                            </div>
                        </div>
                        <div class="help-item">
                            <span class="help-icon"><i class="fas fa-image"></i></span>
                            <div>
                                <strong class="help-title">网站 Logo</strong>
                                <p>建议使用透明背景的 PNG（约 200 × 60 像素），单个文件不超过 5MB，上传后需保存才生效。</p>
                            </div>
                        </div>
                        <div class="help-item">
                            <span class="help-icon"><i class="fas fa-gauge-high"></i></span>
                            <div>
                                <strong class="help-title">监测总开关</strong>
                                <p>关闭后前台不再检测站点在线状态与响应速度，卡片只展示基础信息，适合流量较大或源站不稳定的场景。</p>
                            </div>
                        </div>
                        <div class="help-item">
                            <span class="help-icon"><i class="fas fa-circle-half-stroke"></i></span>
                            <div>
                                <strong class="help-title">前台深色模式默认设置</strong>
                                <p>「跟随系统」会读取访客设备的深浅色偏好；也可以强制默认浅色或默认深色。</p>
                            </div>
                        </div>
                        <div class="help-item">
                            <span class="help-icon"><i class="fas fa-id-card"></i></span>
                            <div>
                                <strong class="help-title">ICP 备案号</strong>
                                <p>开启开关后显示在前台页脚，编号需与工信部备案系统一致，中国大陆站点建议如实填写。</p>
                            </div>
                        </div>
                        <div class="help-item">
                            <span class="help-icon"><i class="fas fa-building-shield"></i></span>
                            <div>
                                <strong class="help-title">公安备案号</strong>
                                <p>开启开关后显示在前台页脚，填写公安备案平台核发的完整编号，通常包含「公网安备」字样。</p>
                            </div>
                        </div>
                    </div>
                </div>
            </section>
        </div>
    </div>

    <script src="../assets/js/jquery3.7.1.js"></script>
    <script src="../assets/js/bootstrap.bundle.min.js"></script>
    <script src="../assets/sweetalert2/sweetalert2.min.js"></script>
    <?php require_once __DIR__ . '/../includes/asset_fallbacks.php'; ?>
    <script>
    var MAX_LOGO_SIZE = 5 * 1024 * 1024;
    var ALLOWED_LOGO_TYPES = ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/x-icon'];

    function formatBytes(bytes) {
        if (!bytes) {
            return '0 B';
        }
        var units = ['B', 'KB', 'MB'];
        var index = 0;
        var value = bytes;
        while (value >= 1024 && index < units.length - 1) {
            value = value / 1024;
            index++;
        }
        return value.toFixed(index === 0 ? 0 : 2) + ' ' + units[index];
    }

    function updateTime() {
        var now = new Date();
        var pad = function (value) {
            return value < 10 ? '0' + value : '' + value;
        };
        var text = now.getFullYear() + '年' + (now.getMonth() + 1) + '月' + now.getDate() + '日 '
            + pad(now.getHours()) + ':' + pad(now.getMinutes()) + ':' + pad(now.getSeconds());
        var target = document.getElementById('currentTime');
        if (target) {
            target.textContent = text;
        }
    }

    function previewLogo(input) {
        var wrap = document.getElementById('newLogoPreview');
        var image = document.getElementById('previewImage');
        var meta = document.getElementById('logoFileMeta');

        if (!input.files || !input.files[0]) {
            if (wrap) {
                wrap.hidden = true;
            }
            return;
        }

        var file = input.files[0];

        if (ALLOWED_LOGO_TYPES.indexOf(file.type) === -1) {
            Swal.fire({
                icon: 'error',
                title: '文件类型错误',
                text: '请选择支持的图片格式：JPG、PNG、GIF、WebP、ICO'
            });
            input.value = '';
            if (wrap) {
                wrap.hidden = true;
            }
            return;
        }

        if (file.size > MAX_LOGO_SIZE) {
            Swal.fire({
                icon: 'error',
                title: '文件过大',
                text: '文件大小不能超过 5MB'
            });
            input.value = '';
            if (wrap) {
                wrap.hidden = true;
            }
            return;
        }

        var reader = new FileReader();
        reader.onload = function (event) {
            if (image) {
                image.src = event.target.result;
            }
            if (wrap) {
                wrap.hidden = false;
            }
            if (meta) {
                meta.textContent = '已选择：' + file.name + ' · ' + formatBytes(file.size) + ' · 保存后生效';
            }

            var deleteInput = document.getElementById('deleteLogoInput');
            if (deleteInput) {
                deleteInput.value = '0';
            }

            var note = document.getElementById('logoDeletedNote');
            if (note) {
                note.hidden = true;
            }
        };
        reader.readAsDataURL(file);
    }

    function clearNewLogo() {
        var input = document.getElementById('logoFileInput');
        var wrap = document.getElementById('newLogoPreview');
        var image = document.getElementById('previewImage');
        var meta = document.getElementById('logoFileMeta');

        if (input) {
            input.value = '';
        }
        if (wrap) {
            wrap.hidden = true;
        }
        if (image) {
            image.removeAttribute('src');
        }
        if (meta) {
            meta.textContent = '';
        }
    }

    function deleteLogo() {
        Swal.fire({
            title: '确定要删除 Logo 吗？',
            text: '删除后将无法恢复，但可以重新上传新的 Logo',
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#dc2626',
            cancelButtonColor: '#64748b',
            confirmButtonText: '确认删除',
            cancelButtonText: '取消',
            reverseButtons: true
        }).then(function (result) {
            if (!result.isConfirmed) {
                return;
            }

            var deleteInput = document.getElementById('deleteLogoInput');
            if (deleteInput) {
                deleteInput.value = '1';
            }

            var fileInput = document.getElementById('logoFileInput');
            if (fileInput) {
                fileInput.value = '';
            }

            var wrap = document.getElementById('newLogoPreview');
            if (wrap) {
                wrap.hidden = true;
            }

            var current = document.getElementById('currentLogoImage');
            if (current) {
                current.style.display = 'none';
            }

            var note = document.getElementById('logoDeletedNote');
            if (note) {
                note.hidden = false;
            }

            Swal.fire({
                icon: 'success',
                title: '已标记删除',
                text: '点击「保存基础设置」后生效',
                timer: 2000,
                showConfirmButton: false
            });
        });
    }

    function testLogoDisplay() {
        var logoUrl = <?php echo json_encode((string) $siteLogoUrl, JSON_UNESCAPED_UNICODE | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG); ?>;

        if (!logoUrl) {
            Swal.fire({
                icon: 'info',
                title: '还没有设置 Logo',
                text: '请先上传 Logo 图片',
                timer: 2000,
                showConfirmButton: false
            });
            return;
        }

        Swal.fire({
            title: 'Logo 预览',
            imageUrl: logoUrl,
            imageAlt: '当前 Logo',
            imageHeight: 120,
            html: '<p class="mb-0 text-muted small">以上为前台实际加载的 Logo</p>',
            confirmButtonText: '关闭',
            confirmButtonColor: '#3b5bdb'
        });
    }

    function bindCounter(inputId, counterId, maxLength) {
        var input = document.getElementById(inputId);
        var counter = document.getElementById(counterId);
        if (!input || !counter) {
            return;
        }

        var sync = function () {
            var length = input.value.length;
            counter.textContent = length + ' / ' + maxLength;
            counter.classList.toggle('is-limit', length >= maxLength);
        };

        input.addEventListener('input', sync);
        sync();
    }

    function syncMonitorState() {
        var toggle = document.getElementById('monitor_enabled');
        var text = document.getElementById('monitorStateText');
        if (toggle && text) {
            text.textContent = toggle.checked ? '已开启' : '已关闭';
        }
    }

    document.addEventListener('DOMContentLoaded', function () {
        updateTime();
        setInterval(updateTime, 1000);

        bindCounter('icp_number', 'icpCount', 50);
        bindCounter('gongan_number', 'gonganCount', 50);
        bindCounter('footer_copyright', 'copyrightCount', 120);

        var monitorToggle = document.getElementById('monitor_enabled');
        if (monitorToggle) {
            monitorToggle.addEventListener('change', syncMonitorState);
            syncMonitorState();
        }

        var icpToggle = document.getElementById('show_icp');
        var icpInput = document.getElementById('icp_number');
        if (icpToggle && icpInput) {
            icpToggle.addEventListener('change', function () {
                icpInput.required = this.checked;
            });
        }

        var gonganToggle = document.getElementById('show_gongan');
        var gonganInput = document.getElementById('gongan_number');
        if (gonganToggle && gonganInput) {
            gonganToggle.addEventListener('change', function () {
                gonganInput.required = this.checked;
            });
        }

        var dropzone = document.getElementById('logoDropzone');
        var fileInput = document.getElementById('logoFileInput');

        if (dropzone && fileInput) {
            ['dragenter', 'dragover'].forEach(function (eventName) {
                dropzone.addEventListener(eventName, function (event) {
                    event.preventDefault();
                    event.stopPropagation();
                    dropzone.classList.add('is-dragover');
                });
            });

            ['dragleave', 'drop'].forEach(function (eventName) {
                dropzone.addEventListener(eventName, function (event) {
                    event.preventDefault();
                    event.stopPropagation();
                    dropzone.classList.remove('is-dragover');
                });
            });

            dropzone.addEventListener('drop', function (event) {
                var files = event.dataTransfer ? event.dataTransfer.files : null;
                if (files && files.length) {
                    fileInput.files = files;
                    previewLogo(fileInput);
                }
            });
        }

        var basicForm = document.getElementById('basicSettingsForm');
        if (basicForm) {
            basicForm.addEventListener('submit', function (event) {
                var titleInput = document.getElementById('site_title');
                if (!titleInput || !titleInput.value.trim()) {
                    event.preventDefault();
                    Swal.fire({
                        icon: 'error',
                        title: '请填写网站标题',
                        text: '网站标题是必填项'
                    });
                }
            });
        }

        var footerForm = document.getElementById('footerSettingsForm');
        if (footerForm) {
            footerForm.addEventListener('submit', function (event) {
                if (!icpToggle || !icpInput || !gonganToggle || !gonganInput) {
                    return;
                }

                if (icpToggle.checked && !icpInput.value.trim()) {
                    event.preventDefault();
                    Swal.fire({
                        icon: 'error',
                        title: '请填写 ICP 备案号',
                        text: '开启 ICP 备案显示后需要填写备案号'
                    });
                    return;
                }

                if (gonganToggle.checked && !gonganInput.value.trim()) {
                    event.preventDefault();
                    Swal.fire({
                        icon: 'error',
                        title: '请填写公安备案号',
                        text: '开启公安备案显示后需要填写备案号'
                    });
                }
            });
        }
    });
    </script>
</body>
</html>
