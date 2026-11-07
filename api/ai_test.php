<?php
/**
 * 轩宇-导航 · AI 分类推荐「测试连接」（v2.12.0）
 *
 * 用后台表单当前填写的接口地址 / 模型 / 密钥发起一次真实的
 * OpenAI 兼容 /chat/completions 调用，供管理员在保存之前验证配置。
 *
 * 与业务接口的区别：
 *   - 不读数据库里已保存的 url / model，一律以本次提交的表单值为准；
 *   - 密钥输入框留空时，可用 use_saved_key=1 复用已保存密钥（不回显）；
 *   - 只读，不写入任何设置，测试按钮不会改动配置；
 *   - 仅管理员可调用，且做同源校验与轻量 SSRF 拦截。
 *
 * POST: api_url, model, api_key(可选), use_saved_key(可选)
 * 返回: {ok, message, detail?, http_code?, latency_ms?, model?, reply?}
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

if (!$functions->isLoggedIn()) {
    $functions->jsonResponse(['ok' => false, 'message' => '请先登录后台'], 401);
}

if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
    $functions->jsonResponse(['ok' => false, 'message' => '仅支持 POST 请求'], 405);
}

$functions->requireSameOrigin();

$url = trim((string) ($_POST['api_url'] ?? ''));
$model = trim((string) ($_POST['model'] ?? ''));
$key = trim((string) ($_POST['api_key'] ?? ''));
$useSavedKey = (string) ($_POST['use_saved_key'] ?? '') === '1';

if ($url === '') {
    $functions->jsonResponse(['ok' => false, 'message' => '请先填写接口地址']);
}

if (function_exists('mb_strlen') ? mb_strlen($url, 'UTF-8') > 300 : strlen($url) > 300) {
    $functions->jsonResponse(['ok' => false, 'message' => '接口地址过长（最多 300 字符）']);
}

$parts = parse_url($url);
$scheme = strtolower((string) ($parts['scheme'] ?? ''));
$host = strtolower((string) ($parts['host'] ?? ''));

if (!in_array($scheme, ['http', 'https'], true) || $host === '') {
    $functions->jsonResponse(['ok' => false, 'message' => '接口地址需为完整的 http(s):// 地址']);
}

if (isset($parts['user']) || isset($parts['pass'])) {
    $functions->jsonResponse(['ok' => false, 'message' => '接口地址不能包含用户名或密码']);
}

/* 轻量 SSRF 防护：只拦截云元数据等绝不该被探测的地址。
   这里不禁止 localhost / 内网，因为管理员用本机 Ollama、局域网代理网关
   做兼容接口是常见且合理的场景；接口本身已限制为管理员 + 同源调用。 */
$blockedHosts = ['169.254.169.254', 'metadata.google.internal', 'metadata.goog'];
if (in_array($host, $blockedHosts, true) || preg_match('/(^|\.)metadata\.(internal|local)$/i', $host)) {
    $functions->jsonResponse(['ok' => false, 'message' => '该地址不允许用于接口测试']);
}

if ($model === '') {
    $functions->jsonResponse(['ok' => false, 'message' => '请先填写模型名称']);
}

if (function_exists('mb_strlen') ? mb_strlen($model, 'UTF-8') > 100 : strlen($model) > 100) {
    $functions->jsonResponse(['ok' => false, 'message' => '模型名称过长（最多 100 字符）']);
}

if ($key === '' && $useSavedKey) {
    $key = trim((string) $functions->getSetting('ai_api_key'));
}

if ($key === '') {
    $functions->jsonResponse(['ok' => false, 'message' => '请先填写接口密钥']);
}

$body = json_encode([
    'model' => $model,
    'messages' => [
        ['role' => 'system', 'content' => 'You are a connectivity test. Reply with OK only.'],
        ['role' => 'user', 'content' => '请只回复两个字母：OK'],
    ],
    'temperature' => 0,
    'max_tokens' => 16,
    'stream' => false,
], JSON_UNESCAPED_UNICODE);

$ai = new AiService($functions);

$startedAt = microtime(true);
$response = $ai->postJson($url, $body, $key, 15);
$latencyMs = (int) round((microtime(true) - $startedAt) * 1000);

/**
 * 把 curl / HTTP 错误翻成能直接照着改配置的人话。
 */
function xyAiTestFriendlyError($error, $rawBody, $httpCode)
{
    $error = (string) $error;
    $detail = '';

    if (is_string($rawBody) && $rawBody !== '') {
        $decoded = json_decode($rawBody, true);
        if (is_array($decoded)) {
            $detail = (string) ($decoded['error']['message'] ?? $decoded['message'] ?? $decoded['error'] ?? '');
            if (!is_string($detail)) {
                $detail = '';
            }
        }
        if ($detail === '') {
            $detail = trim(preg_replace('/\s+/', ' ', strip_tags($rawBody)) ?? '');
        }
        if (function_exists('mb_substr')) {
            $detail = mb_substr($detail, 0, 200, 'UTF-8');
        } else {
            $detail = substr($detail, 0, 200);
        }
    }

    $messages = [
        'curl_missing' => '服务器未启用 cURL 扩展，无法发起接口请求',
        'http_400' => '接口拒绝了请求（400）：请检查模型名称与该接口是否支持 chat/completions',
        'http_401' => '接口密钥无效或已过期（401）',
        'http_403' => '密钥无权限访问该模型或接口（403）',
        'http_404' => '接口地址不存在（404）：请填写包含 /chat/completions 的完整地址',
        'http_408' => '接口响应超时（408）',
        'http_422' => '请求参数不被接受（422）：请检查模型名称',
        'http_429' => '触发限流或额度不足（429）',
        'http_500' => '上游服务内部错误（500）',
        'http_502' => '上游网关错误（502）',
        'http_503' => '上游服务暂不可用（503）',
        'http_504' => '上游网关超时（504）',
    ];

    if (isset($messages[$error])) {
        return [$messages[$error], $detail];
    }

    if (preg_match('/^http_(\d{3})$/', $error, $m)) {
        return ['接口返回 HTTP ' . $m[1] . '，连接未通过', $detail];
    }

    $lower = strtolower($error);

    if (strpos($lower, 'timed out') !== false || strpos($lower, 'timeout') !== false) {
        return ['连接超时（15 秒内未响应）：请检查接口地址或网络是否可达', $detail];
    }

    if (strpos($lower, 'could not resolve') !== false || strpos($lower, 'name or service not known') !== false) {
        return ['域名解析失败：请检查接口地址中的主机名', $detail];
    }

    if (strpos($lower, 'ssl') !== false || strpos($lower, 'certificate') !== false) {
        return ['TLS / 证书校验失败：请检查接口是否使用有效 HTTPS 证书', $detail];
    }

    if (strpos($lower, 'couldn\'t connect') !== false || strpos($lower, 'connection refused') !== false) {
        return ['无法建立连接：请检查接口地址与端口', $detail];
    }

    if ($error === '' || $error === 'request_failed') {
        return ['请求失败，未收到接口响应', $detail];
    }

    return ['连接失败：' . $error, $detail];
}

if (empty($response['ok'])) {
    [$message, $detail] = xyAiTestFriendlyError(
        $response['error'] ?? 'request_failed',
        $response['body'] ?? '',
        (int) ($response['code'] ?? 0)
    );

    $httpCode = 0;
    if (preg_match('/^http_(\d{3})$/', (string) ($response['error'] ?? ''), $m)) {
        $httpCode = (int) $m[1];
    }

    $functions->jsonResponse([
        'ok' => false,
        'message' => $message,
        'detail' => $detail,
        'http_code' => $httpCode,
        'latency_ms' => $latencyMs,
        'model' => $model,
    ]);
}

$raw = (string) ($response['body'] ?? '');
$data = json_decode($raw, true);
$reply = '';
$warning = '';

if (is_array($data)) {
    $reply = (string) ($data['choices'][0]['message']['content']
        ?? $data['choices'][0]['text']
        ?? $data['reply']
        ?? $data['output']['text']
        ?? '');

    // 兼容接口把错误放进 200 响应体的情况
    if ($reply === '' && isset($data['error']['message'])) {
        [$message, $detail] = xyAiTestFriendlyError('', (string) $data['error']['message'], 200);
        $functions->jsonResponse([
            'ok' => false,
            'message' => '接口返回错误：' . (string) $data['error']['message'],
            'detail' => $detail,
            'http_code' => (int) ($response['code'] ?? 200),
            'latency_ms' => $latencyMs,
            'model' => $model,
        ]);
    }
} else {
    $warning = '接口响应不是标准 JSON，可能不是 chat/completions 地址';
}

$reply = trim($reply);
if (function_exists('mb_substr')) {
    $reply = mb_substr($reply, 0, 80, 'UTF-8');
} else {
    $reply = substr($reply, 0, 80);
}

if ($reply === '' && $warning === '') {
    $warning = '接口已连通，但没有返回模型文本内容，请确认模型名称是否正确';
}

$functions->jsonResponse([
    'ok' => true,
    'message' => '连接成功，接口配置可用',
    'reply' => $reply,
    'warning' => $warning,
    'http_code' => (int) ($response['code'] ?? 200),
    'latency_ms' => $latencyMs,
    'model' => $model,
]);
