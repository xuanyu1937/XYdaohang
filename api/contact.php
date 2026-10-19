<?php
/**
 * 联系留言接口
 *
 * POST（JSON 或表单）：name / email / subject / message
 * 与 contact.php 共用 ContactService，保证校验与限流规则一致。
 */

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/Services.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, max-age=0');
header('X-Content-Type-Options: nosniff');
header('X-Robots-Tag: noindex, nofollow');
@ini_set('display_errors', '0');

$functions = new Functions();
$contacts = new ContactService($functions);
$mailer = new MailService($functions);

if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
    $functions->jsonResponse(['ok' => false, 'error' => 'method_not_allowed'], 405);
}

$functions->requireSameOrigin();

if ($functions->getSetting('contact_enabled') === '0') {
    $functions->jsonResponse(['ok' => false, 'error' => '本站已关闭在线留言'], 403);
}

$payload = $_POST;

// 支持 application/json 请求体
if (!$payload) {
    $raw = file_get_contents('php://input');

    if (is_string($raw) && $raw !== '') {
        $decoded = json_decode($raw, true);

        if (is_array($decoded)) {
            $payload = $decoded;
        }
    }
}

$result = $contacts->submit(
    (string) ($payload['name'] ?? ''),
    (string) ($payload['email'] ?? ''),
    (string) ($payload['subject'] ?? ''),
    (string) ($payload['message'] ?? ''),
    $functions->getClientIp()
);

if (!$result['ok']) {
    $functions->jsonResponse(['ok' => false, 'error' => $result['error']], 400);
}

$functions->logMessage('[contact] 收到新留言，来自 ' . $functions->getClientIp());

$mailer->notifyAdmin(
    '收到新的联系留言',
    "姓名：" . trim((string) ($payload['name'] ?? '访客'))
        . "\n邮箱：" . trim((string) ($payload['email'] ?? ''))
        . "\n主题：" . trim((string) ($payload['subject'] ?? ''))
        . "\n内容：" . trim((string) ($payload['message'] ?? ''))
);

$functions->jsonResponse(['ok' => true, 'message' => '留言已提交，我们会尽快回复你。']);
