<?php
/**
 * 轩宇-导航 · 网站收录申请
 */
require_once __DIR__ . '/includes/bootstrap.php';
require_once 'includes/functions.php';
require_once 'includes/TemplateParser.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception;

require_once 'vendor/PHPMailer/src/Exception.php';
require_once 'vendor/PHPMailer/src/PHPMailer.php';
require_once 'vendor/PHPMailer/src/SMTP.php';

$functions = new Functions();
$db = Database::getInstance()->getConnection();

$siteTitle = $functions->getSetting('site_title');
$message = '';
$error = '';

$mailConfig = [];
$configFile = 'config/smtp.php';

if (file_exists($configFile)) {
    $mailConfig = require $configFile;
    if ($siteTitle) {
        $mailConfig['from']['name'] = $siteTitle;
    }
} else {
    $mailConfig = [
        'enabled' => false,
        'admin_email' => '',
        'smtp' => [
            'host' => '',
            'port' => 587,
            'username' => '',
            'password' => '',
            'encryption' => 'tls',
            'timeout' => 30,
            'auth' => true,
            'debug' => false,
        ],
        'from' => [
            'address' => '',
            'name' => $siteTitle,
        ],
    ];
    error_log("SMTP配置文件不存在: " . realpath($configFile));
}

// 自动抓取走 SiteInfoService（带 SSRF 防护与缓存），并按 IP 限流防止滥用。
if (isset($_GET['action']) && $_GET['action'] === 'fetch_site_info') {
    header('Content-Type: application/json; charset=utf-8');

    // v2.2.0 起恢复自动抓取：委托带 SSRF 防护的 SiteInfoService，限流防止滥用
    $rateKey = 'apply_fetch_' . (string) ($_SERVER['REMOTE_ADDR'] ?? '');
    $rateCount = (int) ($_SESSION[$rateKey] ?? 0);
    $rateStart = (int) ($_SESSION[$rateKey . '_start'] ?? 0);

    if (time() - $rateStart > 600) {
        $rateCount = 0;
        $rateStart = time();
    }

    if ($rateCount >= 20) {
        echo json_encode(['success' => false, 'error' => '操作过于频繁，请 10 分钟后再试'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $_SESSION[$rateKey] = $rateCount + 1;
    $_SESSION[$rateKey . '_start'] = $rateStart;

    $fetchUrl = isset($_GET['url']) && is_string($_GET['url']) ? trim($_GET['url']) : '';

    if ($fetchUrl === '') {
        echo json_encode(['success' => false, 'error' => '请输入网址'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if (!preg_match('#^https?://#i', $fetchUrl)) {
        $fetchUrl = 'https://' . ltrim($fetchUrl, '/');
    }

    require_once dirname(__FILE__) . '/includes/Services.php';
    $metaService = new SiteInfoService($functions);
    $meta = $metaService->fetch($fetchUrl);

    if (empty($meta['ok'])) {
        $errorMap = [
            'invalid_url' => '网址格式不正确',
            'invalid_scheme' => '仅支持 http/https 网址',
            'blocked_host' => '该地址不允许抓取',
            'blocked_ip' => '该地址不允许抓取',
            'fetch_failed' => '抓取失败，请检查网址能否正常访问',
            'no_title' => '未获取到网站标题，请手动填写',
        ];
        $errorText = isset($errorMap[$meta['error'] ?? '']) ? $errorMap[$meta['error']] : '抓取失败，请手动填写';
        echo json_encode(['success' => false, 'error' => $errorText], JSON_UNESCAPED_UNICODE);
        exit;
    }

    echo json_encode([
        'success' => true,
        'title' => $meta['title'] ?? '',
        'description' => $meta['description'] ?? '',
        'favicon' => !empty($meta['icons'][0]) ? $meta['icons'][0] : '',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $functions->requireSameOrigin();
    
    $siteName = isset($_POST['site_name']) && is_string($_POST['site_name']) ? trim($_POST['site_name']) : '';
    $siteUrl = isset($_POST['site_url']) && is_string($_POST['site_url']) ? trim($_POST['site_url']) : '';
    $email = isset($_POST['email']) && is_string($_POST['email']) ? trim($_POST['email']) : '';
    $description = isset($_POST['description']) && is_string($_POST['description']) ? trim($_POST['description']) : '';
    $categoryId = isset($_POST['category_id']) && is_scalar($_POST['category_id']) ? $_POST['category_id'] : null;
    
    if (empty($siteUrl)) {
        $error = '请输入网站网址';
    } elseif (!filter_var($siteUrl, FILTER_VALIDATE_URL)) {
        $error = '请输入有效的网址';
    } elseif ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = '邮箱格式不正确，也可以留空';
    } else {
        // 名称未填写时自动抓取标题，抓不到就用域名
        if ($siteName === '') {
            require_once dirname(__FILE__) . '/includes/Services.php';
            $metaService = new SiteInfoService($functions);
            $meta = $metaService->fetch($siteUrl);
            if (!empty($meta['ok']) && !empty($meta['title'])) {
                $siteName = $meta['title'];
            }
            if ($siteName === '') {
                $host = parse_url($siteUrl, PHP_URL_HOST);
                $siteName = $host ? preg_replace('/^www\./i', '', $host) : $siteUrl;
            }
            // 名称抓到后顺带补描述（用户未填时）
            if ($description === '' && !empty($meta['ok']) && !empty($meta['description'])) {
                $description = $meta['description'];
            }
        }

        $checkStmt = $db->prepare("SELECT id FROM applications WHERE site_url = ? AND status = 'pending'");
        $checkStmt->execute([$siteUrl]);
        
        if ($checkStmt->fetch()) {
            $error = '该网址已提交申请，请勿重复提交';
        } else {
            $siteLogo = '';
            if (isset($_FILES['site_logo']) && $_FILES['site_logo']['error'] === UPLOAD_ERR_OK) {
                $uploadDir = 'uploads/logos/';
                $maxUpload = 5 * 1024 * 1024;
                $allowedMime = [
                    'image/jpeg' => 'jpg',
                    'image/png' => 'png',
                    'image/gif' => 'gif',
                    'image/webp' => 'webp',
                ];
                
                if ((int) ($_FILES['site_logo']['size'] ?? 0) > $maxUpload) {
                    $error = 'Logo 文件不能超过 5MB';
                } elseif (!is_uploaded_file($_FILES['site_logo']['tmp_name'])) {
                    $error = 'Logo 上传失败';
                } else {
                    $tmpPath = $_FILES['site_logo']['tmp_name'];
                    $mime = '';
                    
                    if (class_exists('finfo')) {
                        $finfo = new \finfo(FILEINFO_MIME_TYPE);
                        $mime = (string) $finfo->file($tmpPath);
                    }
                    
                    if (!isset($allowedMime[$mime]) || @getimagesize($tmpPath) === false) {
                        $error = 'Logo 仅支持 JPG、PNG、GIF、WEBP 图片';
                    } else {
                        if (!is_dir($uploadDir)) {
                            mkdir($uploadDir, 0755, true);
                        }
                        
                        try {
                            $fileName = bin2hex(random_bytes(8)) . '.' . $allowedMime[$mime];
                        } catch (Throwable $uploadError) {
                            $fileName = uniqid('', true) . '.' . $allowedMime[$mime];
                        }
                        
                        $filePath = $uploadDir . $fileName;
                        
                        if (move_uploaded_file($tmpPath, $filePath)) {
                            if (function_exists('chmod')) {
                                @chmod($filePath, 0644);
                            }
                            $siteLogo = $filePath;
                        } else {
                            $error = 'Logo 保存失败';
                        }
                    }
                }
            }
            
            if ($error === '') {
                $ipAddress = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
            
            $categoryName = '未分类';
            if (!empty($categoryId) && is_numeric($categoryId)) {
                $categoryId = (int)$categoryId;
                $catStmt = $db->prepare("SELECT name FROM categories WHERE id = ?");
                $catStmt->execute([$categoryId]);
                $category = $catStmt->fetch();
                if ($category) {
                    $categoryName = $category['name'];
                }
            } else {
                $categoryId = null;
            }
            
            $stmt = $db->prepare("
                INSERT INTO applications (site_name, site_url, email, site_logo, description, category_id, ip_address, status) 
                VALUES (?, ?, ?, ?, ?, ?, ?, 'pending')
            ");
            
            $params = [
                $siteName, 
                $siteUrl, 
                $email, 
                $siteLogo, 
                $description, 
                $categoryId, 
                $ipAddress
            ];
            
            if ($stmt->execute($params)) {
                $applicationId = $db->lastInsertId();
                
                $mailSent = false;
                if (!empty($mailConfig['enabled']) && !empty($mailConfig['admin_email'])) {
                    $mailSent = sendApplicationEmailToAdmin([
                        'application_id' => $applicationId,
                        'site_name' => $siteName,
                        'site_url' => $siteUrl,
                        'email' => $email,
                        'description' => $description,
                        'category_name' => $categoryName,
                        'ip_address' => $ipAddress,
                        'site_logo' => $siteLogo,
                        'site_title' => $siteTitle,
                        'mail_config' => $mailConfig
                    ]);
                }
                
                $message = '提交成功！我们会尽快审核您的申请。';
                if (!empty($mailConfig['enabled']) && !empty($mailConfig['admin_email'])) {
                    $message .= ' 审核结果将发送到您的邮箱。';
                }
                if (!empty($mailConfig['enabled']) && !empty($mailConfig['admin_email']) && $mailSent) {
                    $message .= ' 管理员已收到通知。';
                } elseif (!empty($mailConfig['enabled']) && !empty($mailConfig['admin_email']) && !$mailSent) {
                    $message .= ' (邮件通知发送失败，但申请已提交成功)';
                }
                
                $_POST = [];
            } else {
                $error = '提交失败，请稍后重试';
                error_log("数据库插入失败: " . implode(", ", $stmt->errorInfo()));
            }
        }
    }
}
}

/**
 * 获取网站信息（标题、描述、关键词等）
 */
function fetchSiteInfo($url) {
    // 自动抓取已关闭：避免未登录用户触发服务端请求（SSRF）。
    return [
        'success' => false,
        'title' => '',
        'description' => '',
        'keywords' => '',
        'favicon' => '',
        'error' => '自动抓取功能已关闭，请手动填写'
    ];
    
    $result = [
        'success' => false,
        'title' => '',
        'description' => '',
        'keywords' => '',
        'favicon' => ''
    ];
    
    try {
        // 确保URL有协议
        if (!preg_match('/^https?:\/\//', $url)) {
            $url = 'https://' . $url;
        }
        
        // 使用cURL获取网页内容
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_MAXREDIRS, 5);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36');
        curl_setopt($ch, CURLOPT_ENCODING, 'gzip, deflate');
        
        $html = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $contentType = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        $effectiveUrl = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
        curl_close($ch);
        
        if ($httpCode !== 200 || empty($html)) {
            $result['error'] = "无法访问该网站 (HTTP $httpCode)";
            return $result;
        }
        
        // 解析HTML
        $dom = new DOMDocument();
        @$dom->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NOWARNING | LIBXML_NOERROR);
        $xpath = new DOMXPath($dom);
        
        // 获取标题
        $titleNodes = $xpath->query('//title');
        if ($titleNodes->length > 0) {
            $result['title'] = trim($titleNodes->item(0)->nodeValue);
        }
        
        // 获取meta描述
        $descNodes = $xpath->query('//meta[@name="description"]/@content');
        if ($descNodes->length > 0) {
            $result['description'] = trim($descNodes->item(0)->nodeValue);
        }
        
        // 获取meta关键词
        $keywordsNodes = $xpath->query('//meta[@name="keywords"]/@content');
        if ($keywordsNodes->length > 0) {
            $result['keywords'] = trim($keywordsNodes->item(0)->nodeValue);
        }
        
        // 获取Open Graph标题（备用）
        if (empty($result['title'])) {
            $ogTitleNodes = $xpath->query('//meta[@property="og:title"]/@content');
            if ($ogTitleNodes->length > 0) {
                $result['title'] = trim($ogTitleNodes->item(0)->nodeValue);
            }
        }
        
        // 获取Open Graph描述（备用）
        if (empty($result['description'])) {
            $ogDescNodes = $xpath->query('//meta[@property="og:description"]/@content');
            if ($ogDescNodes->length > 0) {
                $result['description'] = trim($ogDescNodes->item(0)->nodeValue);
            }
        }
        
        // 获取favicon
        $faviconUrl = fetchFaviconFromHtml($dom, $xpath, $effectiveUrl);
        $result['favicon'] = $faviconUrl;
        
        $result['success'] = true;
        
    } catch (Exception $e) {
        $result['error'] = '获取网站信息失败: ' . $e->getMessage();
        error_log('fetchSiteInfo error: ' . $e->getMessage());
    }
    
    return $result;
}

/**
 * 从HTML中提取favicon URL
 */
function fetchFaviconFromHtml($dom, $xpath, $baseUrl) {
    $faviconUrl = '';
    
    // 解析基础URL
    $parsedBase = parse_url($baseUrl);
    $baseOrigin = $parsedBase['scheme'] . '://' . $parsedBase['host'];
    if (isset($parsedBase['port'])) {
        $baseOrigin .= ':' . $parsedBase['port'];
    }
    
    // 查找各种favicon链接
    $iconSelectors = [
        '//link[@rel="icon"]/@href',
        '//link[@rel="shortcut icon"]/@href',
        '//link[@rel="apple-touch-icon"]/@href',
        '//link[@rel="apple-touch-icon-precomposed"]/@href',
        '//link[@rel="mask-icon"]/@href',
        '//meta[@property="og:image"]/@content'
    ];
    
    foreach ($iconSelectors as $selector) {
        $nodes = $xpath->query($selector);
        if ($nodes->length > 0) {
            $faviconUrl = trim($nodes->item(0)->nodeValue);
            if (!empty($faviconUrl)) {
                break;
            }
        }
    }
    
    // 处理相对路径
    if (!empty($faviconUrl)) {
        if (strpos($faviconUrl, '//') === 0) {
            $faviconUrl = $parsedBase['scheme'] . ':' . $faviconUrl;
        } elseif (strpos($faviconUrl, '/') === 0) {
            $faviconUrl = $baseOrigin . $faviconUrl;
        } elseif (!preg_match('/^https?:\/\//', $faviconUrl)) {
            $faviconUrl = $baseOrigin . '/' . $faviconUrl;
        }
    } else {
        // 使用默认的favicon.ico位置
        $faviconUrl = $baseOrigin . '/favicon.ico';
    }
    
    return $faviconUrl;
}

/**
 * 下载并保存favicon
 */
function fetchFavicon($url) {
    // 自动抓取已关闭：不再由前台请求远程图片并写入上传目录。
    return '';
    
    try {
        if (!preg_match('/^https?:\/\//', $url)) {
            $url = 'https://' . $url;
        }
        
        $parsedUrl = parse_url($url);
        $baseUrl = $parsedUrl['scheme'] . '://' . $parsedUrl['host'];
        $faviconUrl = $baseUrl . '/favicon.ico';
        
        // 尝试获取网页内容以查找自定义favicon
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36');
        
        $html = curl_exec($ch);
        curl_close($ch);
        
        if ($html) {
            $dom = new DOMDocument();
            @$dom->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NOWARNING | LIBXML_NOERROR);
            $xpath = new DOMXPath($dom);
            
            $foundUrl = fetchFaviconFromHtml($dom, $xpath, $url);
            if (!empty($foundUrl)) {
                $faviconUrl = $foundUrl;
            }
        }
        
        // 下载favicon
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $faviconUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0');
        
        $imageData = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $contentType = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        curl_close($ch);
        
        if ($httpCode === 200 && !empty($imageData)) {
            $uploadDir = 'uploads/logos/';
            if (!is_dir($uploadDir)) {
                mkdir($uploadDir, 0755, true);
            }
            
            // 根据Content-Type确定文件扩展名
            $ext = 'ico';
            if (strpos($contentType, 'png') !== false) {
                $ext = 'png';
            } elseif (strpos($contentType, 'jpeg') !== false || strpos($contentType, 'jpg') !== false) {
                $ext = 'jpg';
            } elseif (strpos($contentType, 'gif') !== false) {
                $ext = 'gif';
            } elseif (strpos($contentType, 'svg') !== false) {
                $ext = 'svg';
            }
            
            $fileName = uniqid() . '.' . $ext;
            $filePath = $uploadDir . $fileName;
            
            if (file_put_contents($filePath, $imageData)) {
                return $filePath;
            }
        }
        
    } catch (Exception $e) {
        error_log('fetchFavicon error: ' . $e->getMessage());
    }
    
    return '';
}

function sendApplicationEmailToAdmin($data) {
    $config = $data['mail_config'];
    
    try {
        $templateParser = new TemplateParser();
        
        $templateData = [
            'application_id' => $data['application_id'],
            'site_name' => $data['site_name'],
            'site_url' => $data['site_url'],
            'email' => $data['email'],
            'description' => $data['description'] ?: "未填写描述",
            'category_name' => $data['category_name'],
            'ip_address' => $data['ip_address'],
            'site_logo' => $data['site_logo'],
            'site_title' => $data['site_title'],
            'submit_time' => date('Y-m-d H:i:s'),
            'current_year' => date('Y'),
            'admin_url' => generateAdminUrl()
        ];
        
        $templateContent = $templateParser->parseAll('application_notification', $templateData);
        
        if (empty($templateContent['html'])) {
            error_log("邮件模板解析失败，使用默认模板");
            $templateContent = [
                'html' => createDefaultEmailTemplate($templateData),
                'text' => createDefaultPlainTextEmail($templateData)
            ];
        }
        
        $mail = new PHPMailer(true);
        
        if (isset($config['backup']['enabled']) && $config['backup']['enabled']) {
            if ($config['backup']['method'] === 'sendmail') {
                $mail->isSendmail();
                $mail->Sendmail = $config['backup']['sendmail_path'];
            } else {
                $mail->isMail();
            }
        } else {
            $mail->isSMTP();
            $mail->Host = $config['smtp']['host'];
            $mail->SMTPAuth = $config['smtp']['auth'];
            $mail->Username = $config['smtp']['username'];
            $mail->Password = $config['smtp']['password'];
            $mail->SMTPSecure = $config['smtp']['encryption'];
            $mail->Port = $config['smtp']['port'];
            $mail->Timeout = $config['smtp']['timeout'];
        }
        
        $mail->CharSet = $config['charset'] ?? 'UTF-8';
        
        if (!empty($config['smtp']['debug'])) {
            $mail->SMTPDebug = $config['smtp']['debug'];
        }
        
        $mail->setFrom($config['from']['address'], $config['from']['name']);
        $mail->addAddress($config['admin_email']);
        $mail->addReplyTo($data['email'], $data['site_name']);
        
        $mail->isHTML(true);
        $subjectPrefix = $config['subject_prefix'] ?? '【网站导航】';
        $mail->Subject = $subjectPrefix . "新网站申请 - {$data['site_name']}";
        
        $mail->Body = $templateContent['html'];
        $mail->AltBody = $templateContent['text'];
        
        if ($mail->send()) {
            error_log("申请 #{$data['application_id']} 的邮件通知已发送给管理员: " . $config['admin_email']);
            return true;
        } else {
            error_log("邮件发送失败: " . $mail->ErrorInfo);
            return false;
        }
        
    } catch (Exception $e) {
        error_log("邮件发送异常: " . $e->getMessage());
        return false;
    }
}

function generateAdminUrl() {
    $isHttps = !empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off';
    $protocol = $isHttps ? 'https://' : 'http://';
    $domain = (string) ($_SERVER['HTTP_HOST'] ?? 'localhost');
    return $protocol . $domain . '/admin/applications.php';
}

function createDefaultEmailTemplate($data) {
    $logoInfo = $data['site_logo'] ? "已上传Logo" : "未上传Logo";
    $description = $data['description'] ?: "未填写描述";
    
    return "
    <!DOCTYPE html>
    <html>
    <head>
        <meta charset=\"UTF-8\">
        <title>新网站申请通知</title>
    </head>
    <body style=\"font-family: Arial, sans-serif; line-height: 1.6; color: #333; margin: 0; padding: 0;\">
        <div style=\"max-width: 600px; margin: 0 auto; background-color: #ffffff; padding: 20px;\">
            <div style=\"background: #667eea; color: white; padding: 20px; text-align: center; border-radius: 5px 5px 0 0;\">
                <h1 style=\"margin: 0;\">新网站申请通知</h1>
                <p style=\"margin: 5px 0 0 0; opacity: 0.9;\">{$data['site_title']} 导航站</p>
            </div>
            
            <div style=\"padding: 20px;\">
                <p>您好管理员，收到一个新的网站收录申请，请及时审核。</p>
                
                <table style=\"width: 100%; border-collapse: collapse; margin: 20px 0;\">
                    <tr>
                        <td style=\"padding: 10px; border-bottom: 1px solid #eee; font-weight: bold; width: 30%;\">申请ID</td>
                        <td style=\"padding: 10px; border-bottom: 1px solid #eee;\">#{$data['application_id']}</td>
                    </tr>
                    <tr>
                        <td style=\"padding: 10px; border-bottom: 1px solid #eee; font-weight: bold;\">网站名称</td>
                        <td style=\"padding: 10px; border-bottom: 1px solid #eee;\">{$data['site_name']}</td>
                    </tr>
                    <tr>
                        <td style=\"padding: 10px; border-bottom: 1px solid #eee; font-weight: bold;\">网站地址</td>
                        <td style=\"padding: 10px; border-bottom: 1px solid #eee;\"><a href=\"{$data['site_url']}\" target=\"_blank\">{$data['site_url']}</a></td>
                    </tr>
                    <tr>
                        <td style=\"padding: 10px; border-bottom: 1px solid #eee; font-weight: bold;\">申请人邮箱</td>
                        <td style=\"padding: 10px; border-bottom: 1px solid #eee;\"><a href=\"mailto:{$data['email']}\">{$data['email']}</a></td>
                    </tr>
                    <tr>
                        <td style=\"padding: 10px; border-bottom: 1px solid #eee; font-weight: bold;\">网站分类</td>
                        <td style=\"padding: 10px; border-bottom: 1px solid #eee;\">{$data['category_name']}</td>
                    </tr>
                    <tr>
                        <td style=\"padding: 10px; border-bottom: 1px solid #eee; font-weight: bold;\">Logo状态</td>
                        <td style=\"padding: 10px; border-bottom: 1px solid #eee;\">{$logoInfo}</td>
                    </tr>
                    <tr>
                        <td style=\"padding: 10px; border-bottom: 1px solid #eee; font-weight: bold;\">IP地址</td>
                        <td style=\"padding: 10px; border-bottom: 1px solid #eee;\">{$data['ip_address']}</td>
                    </tr>
                    <tr>
                        <td style=\"padding: 10px; border-bottom: 1px solid #eee; font-weight: bold;\">提交时间</td>
                        <td style=\"padding: 10px; border-bottom: 1px solid #eee;\">{$data['submit_time']}</td>
                    </tr>
                </table>
                
                <div style=\"background-color: #f0f8ff; padding: 15px; border-left: 4px solid #2196F3; margin: 20px 0;\">
                    <h3 style=\"margin-top: 0; color: #2196F3;\">网站描述：</h3>
                    <p>" . nl2br(htmlspecialchars($description)) . "</p>
                </div>
                
                <div style=\"text-align: center; margin: 30px 0;\">
                    <a href=\"{$data['admin_url']}\" style=\"background: #667eea; color: white; padding: 12px 30px; text-decoration: none; border-radius: 5px; font-weight: bold; display: inline-block;\">
                        前往后台审核
                    </a>
                </div>
                
                <p style=\"color: #666; font-size: 14px; text-align: center;\">
                    请及时处理此申请，申请者将在 1-3 个工作日内收到审核结果通知。
                </p>
            </div>
            
            <div style=\"text-align: center; padding: 20px; color: #666; font-size: 14px; border-top: 1px solid #eee; margin-top: 20px;\">
                <p>此邮件由系统自动发送，请勿直接回复。</p>
                <p>{$data['site_title']} &copy; {$data['current_year']} 版权所有</p>
            </div>
        </div>
    </body>
    </html>
    ";
}

function createDefaultPlainTextEmail($data) {
    $logoInfo = $data['site_logo'] ? "已上传Logo" : "未上传Logo";
    $description = $data['description'] ?: "未填写描述";
    
    return "新网站申请通知\n\n" .
           "{$data['site_title']} 导航站\n\n" .
           "您好管理员，收到一个新的网站收录申请，请及时审核。\n\n" .
           "申请信息：\n" .
           "----------------------------------------\n" .
           "申请ID:      #{$data['application_id']}\n" .
           "网站名称:    {$data['site_name']}\n" .
           "网站地址:    {$data['site_url']}\n" .
           "申请人邮箱:  {$data['email']}\n" .
           "网站分类:    {$data['category_name']}\n" .
           "Logo状态:    {$logoInfo}\n" .
           "IP地址:      {$data['ip_address']}\n" .
           "提交时间:    {$data['submit_time']}\n\n" .
           "网站描述：\n" .
           "----------------------------------------\n" .
           "{$description}\n\n" .
           "审核链接：\n" .
           "----------------------------------------\n" .
           "{$data['admin_url']}\n\n" .
           "请及时处理此申请。\n\n" .
           "此邮件由系统自动发送，请勿直接回复。\n" .
           "{$data['site_title']} &copy; {$data['current_year']} 版权所有";
}

$categories = $db->query("SELECT * FROM categories ORDER BY sort_order, id")->fetchAll();
?>

<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>申请收录 - <?php echo $functions->escape($siteTitle); ?></title>
    <script src="assets/js/tailwind.min.js"></script>
    <link href="assets/css/font-awesome.min.css" rel="stylesheet">
    <script src="assets/js/jquery3.7.1.js"></script>
    <?php require_once __DIR__ . '/includes/asset_fallbacks.php'; ?>
    <style>
        .fetching {
            position: relative;
            pointer-events: none;
            opacity: 0.7;
        }
        .fetching::after {
            content: '';
            position: absolute;
            right: 12px;
            top: 50%;
            transform: translateY(-50%);
            width: 20px;
            height: 20px;
            border: 2px solid #3b82f6;
            border-top-color: transparent;
            border-radius: 50%;
            animation: spin 0.8s linear infinite;
        }
        @keyframes spin {
            to { transform: translateY(-50%) rotate(360deg); }
        }
        .url-input-wrapper {
            position: relative;
        }
        .url-input-wrapper input {
            padding-right: 120px;
        }
        .fetch-btn {
            position: absolute;
            right: 4px;
            top: 50%;
            transform: translateY(-50%);
        }
        .favicon-preview {
            display: inline-block;
            margin-left: 10px;
            vertical-align: middle;
        }
        .favicon-preview img {
            width: 20px;
            height: 20px;
            object-fit: contain;
        }
    </style>
</head>
<body class="bg-gray-50">
    <div class="container mx-auto px-4 py-12 max-w-4xl">
        <div class="bg-white rounded-xl shadow-lg p-8">
            <div class="text-center mb-8">
                <h1 class="text-3xl font-bold text-gray-800 mb-2">申请网站收录</h1>
                <p class="text-gray-600">提交您的网站，审核通过后将会展示在导航页中</p>
            </div>
            
            <?php if ($message): ?>
                <div class="mb-6 p-4 bg-green-100 text-green-700 rounded-lg">
                    <i class="fas fa-check-circle mr-2"></i> <?php echo $functions->escape($message); ?>
                </div>
            <?php endif; ?>
            
            <?php if ($error): ?>
                <div class="mb-6 p-4 bg-red-100 text-red-700 rounded-lg">
                    <i class="fas fa-exclamation-circle mr-2"></i> <?php echo $functions->escape($error); ?>
                </div>
            <?php endif; ?>
            
            <form method="POST" enctype="multipart/form-data" class="space-y-6">
                <!-- 隐藏字段：是否自动获取logo -->
                <input type="hidden" name="auto_fetch_logo" id="auto_fetch_logo" value="0">
                
                <div>
                    <label class="block text-gray-700 mb-2 font-medium">
                        <i class="fas fa-link mr-2"></i>网站网址 *
                    </label>
                    <div class="url-input-wrapper">
                        <input type="url" 
                               name="site_url" 
                               id="site_url"
                               value="<?php echo htmlspecialchars($_POST['site_url'] ?? ''); ?>" 
                               required 
                               placeholder="https://example.com"
                               class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                        <button type="button" 
                                id="fetchSiteInfoBtn"
                                class="fetch-btn bg-blue-500 hover:bg-blue-600 text-white px-4 py-1.5 rounded-md text-sm font-medium transition duration-200 mr-1">
                            <i class="fas fa-magic mr-1"></i>自动获取
                        </button>
                    </div>
                    <p class="text-gray-500 text-sm mt-1">输入网址后点击"自动获取"可自动填充网站信息</p>
                </div>
                
                <div>
                    <label class="block text-gray-700 mb-2 font-medium">
                        <i class="fas fa-globe mr-2"></i>网站名称 <span class="text-gray-400 text-sm font-normal">（选填，留空自动获取）</span>
                    </label>
                    <div class="relative">
                        <input type="text"
                               name="site_name"
                               id="site_name"
                               value="<?php echo htmlspecialchars($_POST['site_name'] ?? ''); ?>"
                               class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                        <span id="faviconPreview" class="favicon-preview"></span>
                    </div>
                </div>
                
                <div>
                    <label class="block text-gray-700 mb-2 font-medium">
                        <i class="fas fa-envelope mr-2"></i>联系邮箱 <span class="text-gray-400 text-sm font-normal">（选填）</span>
                    </label>
                    <input type="email"
                           name="email"
                           id="email"
                           value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>"
                           placeholder="your-email@example.com"
                           class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                    <p class="text-gray-500 text-sm mt-1">填写后审核结果将通过此邮箱通知您；不填写则无法接收通知</p>
                </div>
                
                <div>
                    <label class="block text-gray-700 mb-2 font-medium">
                        <i class="fas fa-image mr-2"></i>网站Logo
                    </label>
                    <div class="flex items-center space-x-4">
                        <input type="file" 
                               name="site_logo" 
                               id="site_logo"
                               accept="image/*"
                               class="flex-1 px-4 py-3 border border-gray-300 rounded-lg">
                        <label class="flex items-center cursor-pointer">
                            <input type="checkbox" id="autoFetchLogoCheck" class="mr-2">
                            <span class="text-sm text-gray-600">自动获取网站图标</span>
                        </label>
                    </div>
                    <p class="text-gray-500 text-sm mt-1">支持 JPG, PNG, GIF, WebP 格式，建议尺寸 120x120 像素</p>
                </div>
                
                <div>
                    <label class="block text-gray-700 mb-2 font-medium">
                        <i class="fas fa-align-left mr-2"></i>网站描述
                    </label>
                    <textarea name="description" 
                              id="description"
                              rows="3"
                              class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500"><?php echo htmlspecialchars($_POST['description'] ?? ''); ?></textarea>
                    <p class="text-gray-500 text-sm mt-1">
                        <span id="descCount">0</span>/200 字符
                    </p>
                </div>
                
                <div>
                    <label class="block text-gray-700 mb-2 font-medium">
                        <i class="fas fa-folder mr-2"></i>网站分类
                    </label>
                    <select name="category_id" 
                            id="category_id"
                            class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                        <option value="">请选择分类（可选）</option>
                        <?php foreach ($categories as $category): ?>
                            <option value="<?php echo $category['id']; ?>" 
                                    <?php echo ($_POST['category_id'] ?? '') == $category['id'] ? 'selected' : ''; ?>>
                                <?php echo $functions->escape($category['name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <!-- 获取信息状态提示 -->
                <div id="fetchStatus" class="hidden p-3 rounded-lg"></div>
                
                <div class="pt-4">
                    <button type="submit" 
                            class="w-full bg-blue-600 hover:bg-blue-700 text-white font-bold py-3 px-4 rounded-lg transition duration-300">
                        <i class="fas fa-paper-plane mr-2"></i>提交申请
                    </button>
                </div>
            </form>
            
            <div class="mt-8 pt-6 border-t border-gray-200">
                <h3 class="text-lg font-medium text-gray-800 mb-3">申请须知：</h3>
                <ul class="text-gray-600 space-y-2">
                    <li><i class="fas fa-check text-green-500 mr-2"></i>网站内容必须合法合规</li>
                    <li><i class="fas fa-check text-green-500 mr-2"></i>网站能够正常访问</li>
                    <li><i class="fas fa-check text-green-500 mr-2"></i>申请后一般会在1-3个工作日内审核</li>
                    <li><i class="fas fa-check text-green-500 mr-2"></i>审核结果将通过邮件通知（请确保邮箱正确）</li>
                    <li><i class="fas fa-check text-green-500 mr-2"></i>提交后管理员将收到邮件通知</li>
                    <li><i class="fas fa-magic text-blue-500 mr-2"></i>点击"自动获取"可智能识别网站信息</li>
                </ul>
            </div>
            
            <div class="mt-6 text-center">
                <a href="index.php" class="text-blue-600 hover:text-blue-800">
                    <i class="fas fa-arrow-left mr-2"></i>返回首页
                </a>
            </div>
        </div>
    </div>
    
    <script>
    $(document).ready(function() {
        // 描述字数统计
        $('#description').on('input', function() {
            var len = $(this).val().length;
            $('#descCount').text(len);
            if (len > 200) {
                $('#descCount').addClass('text-red-500');
            } else {
                $('#descCount').removeClass('text-red-500');
            }
        }).trigger('input');
        
        // 自动获取Logo复选框
        $('#autoFetchLogoCheck').on('change', function() {
            $('#auto_fetch_logo').val(this.checked ? '1' : '0');
        });
        
        // 自动获取网站信息
        $('#fetchSiteInfoBtn').on('click', function() {
            var url = $('#site_url').val().trim();
            
            if (!url) {
                showFetchStatus('error', '请先输入网站网址');
                return;
            }
            
            // 验证URL格式
            var urlPattern = /^(https?:\/\/)?([\da-z\.-]+)\.([a-z\.]{2,6})([\/\w \.-]*)*\/?$/i;
            if (!urlPattern.test(url)) {
                showFetchStatus('error', '请输入有效的网址');
                return;
            }
            
            // 显示加载状态
            var $btn = $(this);
            $btn.prop('disabled', true).html('<i class="fas fa-spinner fa-spin mr-1"></i>获取中...');
            $('#site_url').addClass('fetching');
            showFetchStatus('info', '<i class="fas fa-spinner fa-spin mr-2"></i>正在获取网站信息，请稍候...');
            
            $.ajax({
                url: window.location.pathname,
                type: 'GET',
                data: {
                    action: 'fetch_site_info',
                    url: url
                },
                dataType: 'json',
                timeout: 15000,
                success: function(response) {
                    if (response.success) {
                        // 填充表单
                        if (response.title) {
                            $('#site_name').val(response.title);
                        }
                        
                        if (response.description) {
                            var desc = response.description;
                            if (desc.length > 200) {
                                desc = desc.substring(0, 197) + '...';
                            }
                            $('#description').val(desc).trigger('input');
                        }
                        
                        // 显示favicon预览
                        if (response.favicon) {
                            $('#faviconPreview').html('<img src="' + response.favicon + '" alt="favicon" class="w-5 h-5">');
                        }
                        
                        // 构建成功消息
                        var fetchedInfo = [];
                        if (response.title) fetchedInfo.push('标题');
                        if (response.description) fetchedInfo.push('描述');
                        if (response.favicon) fetchedInfo.push('图标');
                        
                        if (fetchedInfo.length > 0) {
                            showFetchStatus('success', '<i class="fas fa-check-circle mr-2"></i>成功获取：' + fetchedInfo.join('、'));
                            
                            // 如果成功获取到favicon，自动勾选自动获取logo选项
                            if (response.favicon) {
                                $('#autoFetchLogoCheck').prop('checked', true).trigger('change');
                            }
                        } else {
                            showFetchStatus('warning', '<i class="fas fa-exclamation-triangle mr-2"></i>部分信息获取失败，请手动填写');
                        }
                    } else {
                        showFetchStatus('error', '<i class="fas fa-times-circle mr-2"></i>' + (response.error || '获取网站信息失败，请手动填写'));
                    }
                },
                error: function(xhr, status, error) {
                    var errorMsg = '请求失败';
                    if (status === 'timeout') {
                        errorMsg = '请求超时，请检查网络后重试';
                    } else if (xhr.responseJSON && xhr.responseJSON.error) {
                        errorMsg = xhr.responseJSON.error;
                    }
                    showFetchStatus('error', '<i class="fas fa-times-circle mr-2"></i>' + errorMsg);
                },
                complete: function() {
                    $btn.prop('disabled', false).html('<i class="fas fa-magic mr-1"></i>自动获取');
                    $('#site_url').removeClass('fetching');
                }
            });
        });
        
        function showFetchStatus(type, message) {
            var $status = $('#fetchStatus');
            $status.removeClass('hidden bg-green-100 text-green-700 bg-red-100 text-red-700 bg-blue-100 text-blue-700 bg-yellow-100 text-yellow-700');
            
            switch(type) {
                case 'success':
                    $status.addClass('bg-green-100 text-green-700');
                    break;
                case 'error':
                    $status.addClass('bg-red-100 text-red-700');
                    break;
                case 'warning':
                    $status.addClass('bg-yellow-100 text-yellow-700');
                    break;
                default:
                    $status.addClass('bg-blue-100 text-blue-700');
            }
            
            $status.html(message).removeClass('hidden');
            
            // 5秒后自动隐藏成功/信息提示
            if (type === 'success' || type === 'info') {
                setTimeout(function() {
                    $status.fadeOut(300, function() {
                        $(this).addClass('hidden').show();
                    });
                }, 5000);
            }
        }
        
        // 当网址输入框失去焦点时，如果内容有变化且不为空，可以提示用户使用自动获取
        var lastUrlValue = '';
        $('#site_url').on('focus', function() {
            lastUrlValue = $(this).val();
        }).on('blur', function() {
            var currentValue = $(this).val().trim();
            if (currentValue && currentValue !== lastUrlValue && !$('#site_name').val()) {
                // 如果网址变化了且网站名称为空，显示提示
                var $status = $('#fetchStatus');
                if ($status.hasClass('hidden') || $status.text().indexOf('点击') === -1) {
                    showFetchStatus('info', '<i class="fas fa-lightbulb mr-2"></i>点击"自动获取"按钮可智能识别网站信息');
                }
            }
        });
    });
    </script>
</body>
</html>