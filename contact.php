<?php
/**
 * 轩宇-导航 · 联系管理员
 * 表单直接在本页处理（无 JS 也可用），与 api/contact.php 共用 ContactService。
 */
require_once __DIR__ . '/includes/bootstrap.php';
require_once 'includes/functions.php';
require_once 'includes/Services.php';

$functions = new Functions();
$users = new UserService($functions);
$contacts = new ContactService($functions);
$mailer = new MailService($functions);

$brandName = trim((string) $functions->getSetting('site_title'));
$brandName = $brandName !== '' ? $brandName : '轩宇-导航';
$siteDescription = trim((string) $functions->getSetting('site_description'));
$siteLogoUrl = $functions->getLogoUrl($functions->getSetting('site_logo'));
$frontendTheme = (string) $functions->getSetting('frontend_theme');

if (!in_array($frontendTheme, ['system', 'light', 'dark'], true)) {
    $frontendTheme = 'system';
}

$contactEnabled = $functions->getSetting('contact_enabled') !== '0';
$currentUser = $users->currentUser();
$adminEmail = $mailer->adminEmail();

$form = [
    'name' => $currentUser ? (string) $currentUser['username'] : '',
    'email' => $currentUser ? (string) $currentUser['email'] : '',
    'subject' => '',
    'message' => '',
];

$success = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $functions->requireSameOrigin();

    if (!$contactEnabled) {
        $error = '本站已关闭在线留言';
    } else {
        foreach (array_keys($form) as $key) {
            $form[$key] = trim((string) ($_POST[$key] ?? ''));
        }

        $result = $contacts->submit(
            $form['name'],
            $form['email'],
            $form['subject'],
            $form['message'],
            $functions->getClientIp()
        );

        if ($result['ok']) {
            $success = '留言已提交，我们会尽快回复你。';
            $form['subject'] = '';
            $form['message'] = '';

            $mailer->notifyAdmin(
                '收到新的联系留言',
                "姓名：{$form['name']}\n邮箱：{$form['email']}\n留言：" . $form['message']
            );
        } else {
            $error = $result['error'];
        }
    }
}
?>
<!DOCTYPE html>
<html lang="zh-CN" data-skin="<?php echo $functions->escape($functions->getSetting('theme_skin')); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <meta name="robots" content="noindex,follow">
    <title>联系管理员 - <?php echo $functions->escape($brandName); ?></title>
    <meta name="description" content="<?php echo $functions->escape('向 ' . $brandName . ' 提交问题反馈或站点收录建议。'); ?>">
    <meta name="theme-color" content="#f4f6fa" id="themeColor">

    <?php if ($siteLogoUrl !== ''): ?>
        <link rel="icon" href="<?php echo $functions->escape($siteLogoUrl); ?>">
    <?php endif; ?>

    <script>
        (function () {
            try {
                var root = document.documentElement;
                var saved = localStorage.getItem('xy-nav-theme');
                var fallback = <?php echo json_encode($frontendTheme, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
                var theme = (saved === 'dark' || saved === 'light')
                    ? saved
                    : ((fallback === 'dark' || fallback === 'light')
                        ? fallback
                        : (window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light'));
                root.setAttribute('data-theme', theme);

                var meta = document.querySelector('meta[name="theme-color"]');
                if (meta) {
                    meta.setAttribute('content', theme === 'dark' ? '#0a1120' : '#f4f6fa');
                }
            } catch (e) {
                document.documentElement.setAttribute('data-theme', 'light');
            }
        })();
    </script>

    <link rel="stylesheet" href="assets/css/nav.css">
    <link rel="stylesheet" href="assets/css/page.css">
</head>
<body data-monitor="0">
    <a class="skip-link" href="#contactMain">跳到表单</a>

    <div class="page-shell">
        <header class="site-header">
            <div class="header-brand">
                <a class="brand" href="index.php" aria-label="返回首页">
                    <span class="brand-mark" aria-hidden="true">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                            <circle cx="12" cy="12" r="9"></circle>
                            <path d="m15.4 8.6-2.1 4.7-4.7 2.1 2.1-4.7z"></path>
                        </svg>
                        <?php if ($siteLogoUrl !== ''): ?>
                            <img src="<?php echo $functions->escape($siteLogoUrl); ?>" alt="" width="48" height="48" onerror="this.remove();">
                        <?php endif; ?>
                    </span>
                    <span class="brand-info">
                        <span class="brand-title" translate="no"><?php echo $functions->escape($brandName); ?></span>
                        <span class="brand-subtitle"><?php echo $functions->escape($siteDescription !== '' ? $siteDescription : '联系管理员'); ?></span>
                    </span>
                </a>
            </div>

            <div class="header-tools">
                <button type="button" class="tool-btn theme-toggle" id="themeToggle" aria-label="切换到深色模式" title="切换到深色模式" aria-pressed="false">
                    <svg class="icon-moon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                        <path d="M20.5 14.2A8.5 8.5 0 0 1 9.8 3.5 8.5 8.5 0 1 0 20.5 14.2Z"></path>
                    </svg>
                    <svg class="icon-sun" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                        <circle cx="12" cy="12" r="4"></circle>
                        <path d="M12 2v2M12 20v2M4.9 4.9l1.4 1.4M17.7 17.7l1.4 1.4M2 12h2M20 12h2M4.9 19.1l1.4-1.4M17.7 6.3l1.4-1.4"></path>
                    </svg>
                </button>
                <a class="tool-btn tool-text" href="about.php">关于本站</a>
                <a class="tool-btn tool-text" href="index.php">返回首页</a>
            </div>
        </header>

        <main class="page-main" id="contactMain">
            <div class="page-card">
                <h1 class="page-title">联系管理员</h1>
                <p class="page-desc">
                    想推荐好用的网站、反馈失效链接，或遇到使用问题，都可以在这里留言。
                    <?php if ($adminEmail !== '' && $mailer->enabled()): ?>
                        也可以直接发邮件到 <a href="mailto:<?php echo $functions->escape($adminEmail); ?>"><?php echo $functions->escape($adminEmail); ?></a>。
                    <?php endif; ?>
                </p>

                <?php if (!$contactEnabled): ?>
                    <p class="page-alert is-error">本站当前已关闭在线留言<?php echo $adminEmail !== '' ? '，请通过邮件联系我们' : ''; ?>。</p>
                <?php else: ?>
                    <?php if ($success !== ''): ?>
                        <p class="page-alert is-success" role="status" aria-live="polite"><?php echo $functions->escape($success); ?></p>
                    <?php endif; ?>

                    <?php if ($error !== ''): ?>
                        <p class="page-alert is-error" role="alert"><?php echo $functions->escape($error); ?></p>
                    <?php endif; ?>

                    <form id="contactForm" method="POST" novalidate>
                        <div class="page-field">
                            <label for="name">称呼</label>
                            <input type="text"
                                   id="name"
                                   name="name"
                                   value="<?php echo $functions->escape($form['name']); ?>"
                                   maxlength="50"
                                   autocomplete="nickname"
                                   placeholder="怎么称呼你？">
                        </div>

                        <div class="page-field">
                            <label for="email">邮箱</label>
                            <input type="email"
                                   id="email"
                                   name="email"
                                   value="<?php echo $functions->escape($form['email']); ?>"
                                   maxlength="190"
                                   autocomplete="email"
                                   spellcheck="false"
                                   required
                                   placeholder="name@example.com">
                            <p class="page-hint" id="emailError" hidden></p>
                        </div>

                        <div class="page-field">
                            <label for="subject">主题</label>
                            <input type="text"
                                   id="subject"
                                   name="subject"
                                   value="<?php echo $functions->escape($form['subject']); ?>"
                                   maxlength="100"
                                   autocomplete="off"
                                   placeholder="例如：推荐一个前端工具站">
                        </div>

                        <div class="page-field">
                            <label for="message">留言内容</label>
                            <textarea id="message"
                                      name="message"
                                      maxlength="2000"
                                      required
                                      placeholder="请具体描述你的问题或建议，至少 5 个字。"><?php echo $functions->escape($form['message']); ?></textarea>
                            <p class="page-hint" id="messageError" hidden></p>
                        </div>

                        <div class="page-actions">
                            <button type="submit" class="page-btn is-primary" id="submitBtn">提交留言</button>
                            <a class="page-btn" href="index.php">返回首页</a>
                        </div>
                    </form>
                <?php endif; ?>
            </div>
        </main>

        <footer class="site-footer">
            <p class="copyright">
                <?php echo $functions->escape($brandName); ?>
                <span class="footer-sep">·</span>
                <a href="index.php">首页</a>
                <span class="footer-sep">·</span>
                <a href="about.php">关于本站</a>
            </p>
        </footer>
    </div>

    <script>
        (function () {
            'use strict';

            var toggle = document.getElementById('themeToggle');

            if (toggle) {
                toggle.addEventListener('click', function () {
                    var next = document.documentElement.getAttribute('data-theme') === 'dark' ? 'light' : 'dark';
                    document.documentElement.setAttribute('data-theme', next);
                    toggle.setAttribute('aria-pressed', next === 'dark' ? 'true' : 'false');
                    try { localStorage.setItem('xy-nav-theme', next); } catch (e) {}
                });
            }

            var form = document.getElementById('contactForm');

            if (!form) {
                return;
            }

            var email = document.getElementById('email');
            var message = document.getElementById('message');
            var submitBtn = document.getElementById('submitBtn');
            var emailError = document.getElementById('emailError');
            var messageError = document.getElementById('messageError');

            function setFieldError(input, node, text) {
                if (!node) {
                    return;
                }

                node.textContent = text;
                node.hidden = text === '';
                input.setAttribute('aria-invalid', text === '' ? 'false' : 'true');
            }

            [email, message].forEach(function (input) {
                input.addEventListener('input', function () {
                    setFieldError(input, input === email ? emailError : messageError, '');
                });
            });

            form.addEventListener('submit', function (event) {
                var firstInvalid = null;

                if (email.value.trim() === '' || !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email.value.trim())) {
                    setFieldError(email, emailError, '请填写有效的邮箱地址');
                    firstInvalid = firstInvalid || email;
                } else {
                    setFieldError(email, emailError, '');
                }

                if (message.value.trim().length < 5) {
                    setFieldError(message, messageError, '留言内容至少 5 个字');
                    firstInvalid = firstInvalid || message;
                } else {
                    setFieldError(message, messageError, '');
                }

                if (firstInvalid) {
                    event.preventDefault();
                    firstInvalid.focus();
                    return;
                }

                // 请求期间禁用按钮，避免重复提交
                submitBtn.disabled = true;
                submitBtn.textContent = '提交中…';
            });
        })();
    </script>
</body>
</html>
