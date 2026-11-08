<?php
/**
 * 前台用户自有导航（user_id = 当前登录用户）的增删改排序接口
 *
 * POST action=add     新增（url 必填，name 为空时自动抓取标题）
 * POST action=update  修改
 * POST action=delete  删除
 * POST action=sort    按 ids 顺序重排
 * POST action=fetch   仅抓取站点信息（预填用）
 * POST action=quick_preview 收录预览：抓取标题/描述/图标 + 推荐分组，不入库
 * POST action=quick_add     确认收录：按前端确认后的字段落库
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
$users = new UserService($functions);
$siteInfo = new SiteInfoService($functions);

if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
    $functions->jsonResponse(['ok' => false, 'error' => 'method_not_allowed'], 405);
}

$functions->requireSameOrigin();

$user = $users->currentUser();

if (!$user) {
    $functions->jsonResponse(['ok' => false, 'error' => '请先登录'], 401);
}

$userId = (int) $user['id'];
$db = Database::getInstance()->getConnection();
// 前端既可以用表单编码，也可以直接发 application/json 的整个请求体。
// PHP 不会自动把 JSON body 填进 $_POST，这里合并一次，
// 免得将来前端改成 JSON 时接口静默收不到参数。
$contentType = strtolower((string) ($_SERVER['CONTENT_TYPE'] ?? $_SERVER['HTTP_CONTENT_TYPE'] ?? ''));

if (strpos($contentType, 'application/json') !== false) {
    $rawBody = @file_get_contents('php://input');

    if (is_string($rawBody) && $rawBody !== '') {
        $decodedBody = json_decode($rawBody, true);

        if (is_array($decodedBody)) {
            foreach ($decodedBody as $bodyKey => $bodyValue) {
                if (!array_key_exists($bodyKey, $_POST)) {
                    $_POST[$bodyKey] = $bodyValue;
                }
            }
        }
    }
}

$action = strtolower(trim((string) ($_POST['action'] ?? '')));

/** 每个用户最多保存的导航条数 */
const USER_LINK_LIMIT = 1000;

/** 单次批量导入最多处理多少条（并发抓取有成本，不做无上限的批量） */
const BATCH_IMPORT_LIMIT = 50;

/**
 * 规范化 URL：补全协议并校验。
 */
function userSiteNormalizeUrl($url)
{
    $url = trim((string) $url);

    if ($url === '' || strlen($url) > 500) {
        return '';
    }

    if (!preg_match('#^https?://#i', $url)) {
        $url = 'https://' . ltrim($url, '/');
    }

    if (!filter_var($url, FILTER_VALIDATE_URL)) {
        return '';
    }

    $parts = parse_url($url);

    if (!$parts || empty($parts['host']) || !in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true)) {
        return '';
    }

    return $url;
}

function userSiteTrim($value, $limit)
{
    $value = trim((string) $value);
    $value = str_replace(["\r", "\n", "\t"], ' ', $value);

    if (function_exists('mb_substr')) {
        return mb_substr($value, 0, $limit, 'UTF-8');
    }

    return substr($value, 0, $limit);
}

/**
 * 网址图标上传（v2.16.3）。
 *
 * 与后台 uploadLogo() 的差别：
 *   1. 支持 SVG——但不是原样落盘，而是先把脚本、事件属性、外链引用全部剥掉再存，
 *      避免「图片目录里躺着一个可执行的 SVG」；
 *   2. 位图统一压成 PNG 并限制到 256×256：图标框最大也才显示 48px，
 *      动辄 500KB 的 PNG / 5MB 的 JPG 压完通常只剩几 KB，列表一屏几十张也不卡；
 *   3. 带文件头魔数校验，不只信 $_FILES['type']（那个字段由客户端随便填）。
 *
 * @param Functions $functions
 * @param array     $file  $_FILES 里的单个文件项
 * @return array ['success'=>bool,'error'=>string,'relativePath'=>string,...]
 */

/**
 * v2.16.4：部分 Windows 虚拟主机把 chmod 列入 disable_functions。
 * 直接调用未定义的函数会抛 Error（@ 只能压 warning，压不住异常），
 * 结果是文件已经落盘、接口却在最后一行崩成 500 空响应。
 * 这里先判存在；chmod 失败本身也不影响图标能否被 Web 服务器读取。
 */
function userSiteTryChmod($path)
{
    if (function_exists('chmod')) {
        @chmod($path, 0644);
    }
}

function userSiteUploadIcon(Functions $functions, array $file)
{
    if (!isset($file['error']) || $file['error'] !== UPLOAD_ERR_OK) {
        $messages = [
            UPLOAD_ERR_INI_SIZE => '文件超过服务器允许的大小',
            UPLOAD_ERR_FORM_SIZE => '文件超过允许的大小',
            UPLOAD_ERR_PARTIAL => '文件只上传了一部分，请重试',
            UPLOAD_ERR_NO_FILE => '没有选择文件',
            UPLOAD_ERR_NO_TMP_DIR => '服务器缺少临时目录',
            UPLOAD_ERR_CANT_WRITE => '服务器写入失败',
            UPLOAD_ERR_EXTENSION => '上传被服务器扩展中断',
        ];

        return ['success' => false, 'error' => $messages[(int) $file['error']] ?? '上传失败'];
    }

    $maxSize = 2 * 1024 * 1024;

    if ((int) ($file['size'] ?? 0) > $maxSize) {
        return ['success' => false, 'error' => '图标不能超过 2MB'];
    }

    if ((int) ($file['size'] ?? 0) <= 0) {
        return ['success' => false, 'error' => '文件是空的'];
    }

    $tmpPath = (string) ($file['tmp_name'] ?? '');

    if ($tmpPath === '' || !is_uploaded_file($tmpPath)) {
        return ['success' => false, 'error' => '非法上传'];
    }

    $uploadDir = dirname(__DIR__) . '/uploads/logos';

    if (!is_dir($uploadDir) && !@mkdir($uploadDir, 0755, true) && !is_dir($uploadDir)) {
        return ['success' => false, 'error' => '上传目录不可写'];
    }

    $head = (string) @file_get_contents($tmpPath, false, null, 0, 1024);
    $isSvg = (strpos(ltrim($head), '<') === 0)
        && (stripos($head, '<svg') !== false || stripos($head, '<?xml') !== false)
        && stripos($head, '<svg') !== false;

    try {
        $baseName = bin2hex(random_bytes(8));
    } catch (Throwable $error) {
        $baseName = uniqid('icon', true);
    }

    if ($isSvg) {
        $cleaned = userSiteCleanSvg($tmpPath);

        if ($cleaned === '') {
            return ['success' => false, 'error' => 'SVG 内容为空或无法解析'];
        }

        $fileName = 'icon_' . $baseName . '.svg';
        $target = $uploadDir . '/' . $fileName;

        if (@file_put_contents($target, $cleaned) === false) {
            return ['success' => false, 'error' => '文件保存失败'];
        }

        userSiteTryChmod($target);

        return [
            'success' => true,
            'relativePath' => 'uploads/logos/' . $fileName,
            'width' => 0,
            'height' => 0,
            'bytes' => strlen($cleaned),
        ];
    }

    // ---- 位图：识别格式 → 缩放 → 转 PNG ----
    $info = @getimagesize($tmpPath);
    $mime = is_array($info) && !empty($info['mime']) ? (string) $info['mime'] : '';

    $allowed = ['image/png', 'image/jpeg', 'image/gif', 'image/webp', 'image/bmp'];

    if ($mime === '' || !in_array($mime, $allowed, true)) {
        return ['success' => false, 'error' => '仅支持 PNG / JPG / SVG / ICO / WebP 格式'];
    }

    $pngData = userSiteIconToPng($tmpPath, $mime);

    if ($pngData === '') {
        // GD 不可用或解码失败时退回原文件落盘，至少功能不中断
        $extMap = [
            'image/png' => 'png', 'image/jpeg' => 'jpg', 'image/gif' => 'gif',
            'image/webp' => 'webp', 'image/bmp' => 'bmp',
        ];
        $fileName = 'icon_' . $baseName . '.' . $extMap[$mime];
        $target = $uploadDir . '/' . $fileName;

        if (!@move_uploaded_file($tmpPath, $target)) {
            return ['success' => false, 'error' => '文件保存失败'];
        }

        userSiteTryChmod($target);

        return [
            'success' => true,
            'relativePath' => 'uploads/logos/' . $fileName,
            'width' => (int) ($info[0] ?? 0),
            'height' => (int) ($info[1] ?? 0),
            'bytes' => (int) ($file['size'] ?? 0),
        ];
    }

    $fileName = 'icon_' . $baseName . '.png';
    $target = $uploadDir . '/' . $fileName;

    if (@file_put_contents($target, $pngData) === false) {
        return ['success' => false, 'error' => '文件保存失败'];
    }

    userSiteTryChmod($target);

    return [
        'success' => true,
        'relativePath' => 'uploads/logos/' . $fileName,
        'width' => 256,
        'height' => 256,
        'bytes' => strlen($pngData),
    ];
}

/**
 * 位图转 256×256 的 PNG（等比缩放到框内，居中，透明底）。
 * 返回空字符串表示无法处理，调用方应退回原文件。
 */
function userSiteIconToPng($path, $mime)
{
    if (!extension_loaded('gd') || !function_exists('imagecreatefromstring')) {
        return '';
    }

    $raw = @file_get_contents($path);

    if (!is_string($raw) || $raw === '') {
        return '';
    }

    $src = @imagecreatefromstring($raw);

    if (!$src) {
        return '';
    }

    $srcW = imagesx($src);
    $srcH = imagesy($src);

    if ($srcW <= 0 || $srcH <= 0) {
        imagedestroy($src);
        return '';
    }

    $box = 256;
    $scale = min($box / $srcW, $box / $srcH);

    // 小图不放大，免得糊成一团
    if ($scale > 1) {
        $scale = 1;
    }

    $dstW = max(1, (int) round($srcW * $scale));
    $dstH = max(1, (int) round($srcH * $scale));

    $canvas = imagecreatetruecolor($box, $box);

    if (!$canvas) {
        imagedestroy($src);
        return '';
    }

    imagealphablending($canvas, false);
    imagesavealpha($canvas, true);
    $transparent = imagecolorallocatealpha($canvas, 0, 0, 0, 127);
    imagefilledrectangle($canvas, 0, 0, $box, $box, $transparent);

    imagealphablending($canvas, true);

    $dstX = (int) (($box - $dstW) / 2);
    $dstY = (int) (($box - $dstH) / 2);

    imagecopyresampled($canvas, $src, $dstX, $dstY, 0, 0, $dstW, $dstH, $srcW, $srcH);
    imagedestroy($src);

    ob_start();
    imagepng($canvas, null, 9);
    $png = (string) ob_get_clean();
    imagedestroy($canvas);

    return $png;
}

/**
 * 清洗 SVG：去掉 script / foreignObject / iframe，去掉 on* 事件属性、
 * javascript: 协议、外链 <image> / <use> 引用与 <style> 内的 @import / url()。
 *
 * 只做字符串级清洗，不引入 DOMDocument（部分环境没装 libxml 的 XML 支持，
 * 且这条路径只处理用户自己上传的图标，风险面本来就小）。清洗后仍作为 <img> 引用，
 * <img> 加载的 SVG 浏览器本身就不会执行脚本，这里是第二道保险。
 */
function userSiteCleanSvg($path)
{
    $svg = @file_get_contents($path, false, null, 0, 512 * 1024);

    if (!is_string($svg) || trim($svg) === '') {
        return '';
    }

    // 必须真的是 SVG 根节点
    if (stripos($svg, '<svg') === false) {
        return '';
    }

    // 去掉 XML 声明里的 encoding 之外的杂项，以及 DOCTYPE / ENTITY（XXE 入口）
    $svg = preg_replace('/<!DOCTYPE[^>]*>/is', '', $svg);
    $svg = preg_replace('/<\?xml[^>]*\?>/is', '', $svg);

    // 危险容器整块删除（含内容）
    $svg = preg_replace('#<script\b[^>]*>.*?</script>#is', '', $svg);
    $svg = preg_replace('#<foreignObject\b[^>]*>.*?</foreignObject>#is', '', $svg);
    $svg = preg_replace('#<(iframe|object|embed)\b[^>]*>.*?</\1>#is', '', $svg);
    $svg = preg_replace('#<(iframe|object|embed)\b[^>]*/?>#is', '', $svg);

    // on* 事件属性
    $svg = preg_replace('/\son[a-z]+\s*=\s*"[^"]*"/i', '', $svg);
    $svg = preg_replace("/\\son[a-z]+\\s*=\\s*'[^']*'/i", '', $svg);
    $svg = preg_replace('/\son[a-z]+\s*=\s*[^\s>]+/i', '', $svg);

    // javascript: / data:text/html 协议
    $svg = preg_replace('/\b(href|xlink:href|src)\s*=\s*(["\'])\s*(javascript|data:text\/html)[^"\']*\2/i', '', $svg);

    // <style> 里也藏得下 @import 和 url(javascript:)
    $svg = preg_replace('/@import[^;]*;/i', '', $svg);
    $svg = preg_replace('/url\(\s*["\']?\s*javascript:[^)]*\)/i', '', $svg);

    // 外链 <image> / <use>：图标不需要，直接剥掉属性避免回连第三方
    $svg = preg_replace('/\s(xlink:)?href\s*=\s*(["\'])\s*https?:\/\/[^"\']*\2/i', '', $svg);

    // 折叠成单行，便于存储与调试（不破坏任何标签结构）
    $svg = preg_replace('/>\s+</', '><', trim($svg));

    return trim($svg);
}

/**
 * 删除本次会话里刚上传、但最终没有保存的临时图标。
 * 只允许删 uploads/logos 下 icon_ 开头的文件，杜绝借接口删任意文件。
 */
function userSiteDiscardUpload($relativePath)
{
    $relativePath = str_replace('\\', '/', trim((string) $relativePath));

    if (strpos($relativePath, 'uploads/logos/icon_') !== 0) {
        return false;
    }

    $name = basename($relativePath);

    if ($name === '' || strpos($name, 'icon_') !== 0) {
        return false;
    }

    if (!preg_match('/^icon_[A-Za-z0-9._-]+$/', $name)) {
        return false;
    }

    $full = dirname(__DIR__) . '/uploads/logos/' . $name;

    return is_file($full) ? @unlink($full) : false;
}

/**
 * 校验分类存在且属于当前用户（分组完全私有化，不允许归入他人/公共分组）。
 */
function userSiteCategoryId($db, $value, $userId)
{
    $id = (int) $value;

    if ($id <= 0) {
        return 0;
    }

    $stmt = $db->prepare('SELECT id FROM categories WHERE id = ? AND user_id = ? LIMIT 1');
    $stmt->execute([$id, (int) $userId]);

    return $stmt->fetch() ? $id : 0;
}

/**
 * 把抓到的页面 title 收拾成「能放进卡片」的名字。
 *
 * 站点 title 常见三种形态：
 *   Figma: The collaborative canvas for design, code, and AI   ← 冒号后是一整段宣传语
 *   GitHub - 全球最大的代码托管平台                              ← 横线后是副标题
 *   某某网 | 首页                                              ← 竖线后是频道名
 * 原样入库会让卡片名称被省略号切断，所以按分隔符取主名；
 * 但第一段太短或本身就是「首页」这类通用词时不能取，否则反而丢信息。
 */
function userSiteNiceName($title, $host = '')
{
    $title = trim((string) $title);
    $title = str_replace(["\r", "\n", "\t"], ' ', $title);

    // 压缩连续空白，避免抓到的 title 里带一堆空格
    $title = (string) preg_replace('/\s{2,}/u', ' ', $title);

    if ($title === '') {
        return trim((string) $host);
    }

    $len = function ($value) {
        return function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : strlen($value);
    };

    // 通用词：作为分段名没有辨识度，遇到就跳过这一段
    $generic = ['首页', '主页', '官网', '官方网站', '网站首页', 'home', 'homepage', 'index', 'welcome'];

    $isUsable = static function ($value) use ($generic, $len) {
        $value = trim((string) $value);

        if ($value === '' || $len($value) < 2) {
            return false;
        }

        return !in_array(mb_strtolower($value, 'UTF-8'), $generic, true);
    };

    // 竖线优先级最高：通常是「站点名 | 频道名」结构。
    // 逐段找第一个「不是通用词」的片段，这样「首页 | 某某导航站」能取到后者。
    $candidate = '';

    $parts = preg_split('/\s*[|｜]\s*/u', $title);

    if ($parts && count($parts) > 1) {
        foreach ($parts as $part) {
            if ($isUsable($part)) {
                $candidate = trim((string) $part);
                break;
            }
        }
    }

    // 没有可用的竖线段落，就用完整 title 继续往下判断
    if ($candidate === '') {
        $candidate = $title;
    }

    // 再看冒号 / 破折号：只在整体确实很长时才截，
    // 否则「Vue.js: 渐进式框架」这种本身就够短的名字不该被动
    if ($candidate === $title && $len($candidate) > 24) {
        $split = preg_split('/\s*[:：]\s*|\s+[-–—]\s+|\s*·\s*/u', $candidate, 2);

        if ($split && count($split) > 1) {
            $head = trim((string) $split[0]);

            if ($isUsable($head) && $len($head) <= 24) {
                $candidate = $head;
            }
        }
    }

    $candidate = trim($candidate);

    return $candidate !== '' ? $candidate : trim((string) $host);
}

/**
 * 解析多行输入为待导入条目。
 *
 * 支持三种写法，与「批量粘贴」对话框的提示文案一致：
 *   https://example.com              纯网址
 *   GitHub,https://github.com        名称,网址
 *   GitHub<Tab>https://github.com    名称\t网址（从表格里复制出来时的格式）
 *
 * 单条收录与批量导入共用同一套解析规则，避免「粘贴进来和手输进去结果不一样」。
 *
 * @param string $raw 原始文本
 * @param int $limit 最多解析多少条
 * @return array{items:array, total:int, truncated:bool}
 */
function userSiteParseLines($raw, $limit = 50)
{
    $lines = preg_split('/\r\n|\r|\n/', (string) $raw);
    $items = [];
    $seen = [];
    $total = 0;

    foreach ($lines as $line) {
        $line = trim((string) $line);

        if ($line === '' || strpos($line, '#') === 0) {
            continue;
        }

        $name = '';
        $url = '';

        if (strpos($line, "\t") !== false) {
            $parts = explode("\t", $line, 2);
            $name = trim((string) $parts[0]);
            $url = userSiteNormalizeUrl($parts[1] ?? '');
        } elseif (strpos($line, ',') !== false && strpos($line, 'http') !== 0) {
            $parts = explode(',', $line, 2);
            $name = trim((string) $parts[0]);
            $url = userSiteNormalizeUrl($parts[1] ?? '');
        } else {
            $url = userSiteNormalizeUrl($line);
        }

        if ($url === '') {
            continue;
        }

        $total++;

        // 同一批次内去重：重复的只保留第一次出现的位置
        if (isset($seen[$url])) {
            continue;
        }

        $seen[$url] = true;

        if (count($items) >= $limit) {
            continue;
        }

        $items[] = ['name' => $name, 'url' => $url];
    }

    return [
        'items' => $items,
        'total' => $total,
        'truncated' => $total > count($items),
    ];
}

/**
 * 按「标题 + 描述 + 域名」推荐分组。
 *
 * 单条收录与批量导入共用：两者看到的候选分组和匹配规则必须一致，
 * 否则会出现「一条条加是 AI 工具、批量导入却全落到未分类」。
 *
 * @return array{id:int, name:string, reason:string}
 */
function userSiteSuggestCategory(CategoryEngine $engine, array $categories, $text)
{
    $names = array_column($categories, 'name');
    $matched = $engine->smartMatch($text, $names);

    if ($matched !== null) {
        foreach ($categories as $category) {
            if ($category['name'] === $matched) {
                return ['id' => (int) $category['id'], 'name' => $matched, 'reason' => 'keyword'];
            }
        }
    }

    // 没匹配上就退回「未分类」，再退回第一个分组
    foreach ($categories as $category) {
        if ($category['name'] === '未分类') {
            return ['id' => (int) $category['id'], 'name' => '未分类', 'reason' => 'fallback'];
        }
    }

    if ($categories) {
        return ['id' => (int) $categories[0]['id'], 'name' => (string) $categories[0]['name'], 'reason' => 'fallback'];
    }

    return ['id' => 0, 'name' => '', 'reason' => 'none'];
}

/**
 * 当前用户可见的候选分组（公共 + 自己的），供收录 / 批量导入下拉使用。
 */
function userSiteCategories(CategoryEngine $engine, $userId)
{
    $rows = $engine->all($userId, true);
    $categories = [];

    foreach ($rows as $row) {
        $categories[] = [
            'id' => (int) $row['id'],
            'name' => (string) $row['name'],
            'is_private' => (int) ($row['is_private'] ?? 0),
            'link_count' => (int) ($row['link_count'] ?? 0),
        ];
    }

    return $categories;
}

/**
 * 确认目标条目属于当前用户。
 */
function userSiteOwned($db, $id, $userId)
{
    $stmt = $db->prepare('SELECT id FROM nav_links WHERE id = ? AND user_id = ? LIMIT 1');
    $stmt->execute([(int) $id, (int) $userId]);

    return $stmt->fetch() ? true : false;
}

/**
 * 取分组名，供前端就地渲染新卡片的分组标签。
 */
function userSiteCategoryName($db, $categoryId)
{
    $categoryId = (int) $categoryId;

    if ($categoryId <= 0) {
        return '';
    }

    try {
        $stmt = $db->prepare('SELECT name FROM categories WHERE id = ? LIMIT 1');
        $stmt->execute([$categoryId]);
        $row = $stmt->fetch();

        return $row ? (string) $row['name'] : '';
    } catch (Throwable $error) {
        return '';
    }
}

switch ($action) {
    case 'fetch':
        $url = userSiteNormalizeUrl($_POST['url'] ?? '');

        if ($url === '') {
            $functions->jsonResponse(['ok' => false, 'error' => '网址格式不正确'], 400);
        }

        $functions->jsonResponse(['ok' => true, 'url' => $url, 'meta' => $siteInfo->fetch($url)]);
        break;

    case 'add':
        $url = userSiteNormalizeUrl($_POST['url'] ?? '');

        if ($url === '') {
            $functions->jsonResponse(['ok' => false, 'error' => '请输入有效的网址'], 400);
        }

        $countStmt = $db->prepare('SELECT COUNT(*) AS total FROM nav_links WHERE user_id = ?');
        $countStmt->execute([$userId]);

        if ((int) ($countStmt->fetch()['total'] ?? 0) >= USER_LINK_LIMIT) {
            $functions->jsonResponse(['ok' => false, 'error' => '已达收藏上限（' . USER_LINK_LIMIT . ' 条）'], 400);
        }

        $dupStmt = $db->prepare('SELECT id FROM nav_links WHERE user_id = ? AND url = ? LIMIT 1');
        $dupStmt->execute([$userId, $url]);

        if ($dupStmt->fetch()) {
            $functions->jsonResponse(['ok' => false, 'error' => '该网址已在你的导航中'], 400);
        }

        $name = userSiteTrim($_POST['name'] ?? '', 100);
        $description = userSiteTrim($_POST['description'] ?? '', 200);
        $logo = userSiteTrim($_POST['logo'] ?? '', 500);
        $tags = userSiteTrim($_POST['tags'] ?? '', 255);
        $alias = userSiteTrim($_POST['alias'] ?? '', 255);
        $categoryId = userSiteCategoryId($db, $_POST['category_id'] ?? 0, $userId);

        // 名称为空时抓取标题，顺带补齐描述与图标。
        if ($name === '') {
            $meta = $siteInfo->fetch($url);
            $name = $meta['ok'] ? (string) $meta['title'] : '';

            if ($description === '' && !empty($meta['description'])) {
                $description = userSiteTrim($meta['description'], 200);
            }

            if ($logo === '' && !empty($meta['icons'][0])) {
                $logo = userSiteTrim($meta['icons'][0], 500);
            }

            if ($name === '') {
                $host = parse_url($url, PHP_URL_HOST);
                $name = $host ? preg_replace('/^www\./i', '', $host) : $url;
            }
        }

        if ($categoryId <= 0) {
            $engine = new CategoryEngine($functions);
            // v2.14.0：自动补建的「未分类」也必须归属当前用户，不能落成公共分类
            $engine->setUserId($userId);
            $categoryId = $engine->ensure('未分类', 'bi-collection');
        }

        $sortStmt = $db->prepare('SELECT COALESCE(MAX(sort_order), 0) + 1 AS next_sort FROM nav_links WHERE user_id = ?');
        $sortStmt->execute([$userId]);
        $sort = (int) ($sortStmt->fetch()['next_sort'] ?? 1);

        $isStarred = !empty($_POST['is_starred']) ? 1 : 0;

        $stmt = $db->prepare(
            'INSERT INTO nav_links (name, url, logo, description, category_id, user_id, sort_order, is_active, is_starred, tags, alias, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, 1, ?, ?, ?, NOW())'
        );
        $stmt->execute([$name, $url, $logo, $description, $categoryId, $userId, $sort, $isStarred, $tags, $alias]);

        $functions->jsonResponse([
            'ok' => true,
            'id' => (int) $db->lastInsertId(),
            'name' => $name,
            'url' => $url,
            'category_id' => $categoryId,
            'is_starred' => $isStarred,
        ]);
        break;

    case 'star':
        $id = (int) ($_POST['id'] ?? 0);
        $star = !empty($_POST['star']) ? 1 : 0;

        if ($id <= 0 || !userSiteOwned($db, $id, $userId)) {
            $functions->jsonResponse(['ok' => false, 'error' => '记录不存在或无权操作'], 404);
        }

        $stmt = $db->prepare('UPDATE nav_links SET is_starred = ?, updated_at = NOW() WHERE id = ? AND user_id = ?');
        $stmt->execute([$star, $id, $userId]);

        $functions->jsonResponse(['ok' => true, 'id' => $id, 'is_starred' => $star]);
        break;

    case 'move':
        $id = (int) ($_POST['id'] ?? 0);

        if ($id <= 0 || !userSiteOwned($db, $id, $userId)) {
            $functions->jsonResponse(['ok' => false, 'error' => '记录不存在或无权操作'], 404);
        }

        $categoryId = userSiteCategoryId($db, $_POST['category_id'] ?? 0, $userId);

        if ($categoryId <= 0) {
            $engine = new CategoryEngine($functions);
            // v2.14.0：自动补建的「未分类」也必须归属当前用户，不能落成公共分类
            $engine->setUserId($userId);
            $categoryId = $engine->ensure('未分类', 'bi-collection');
        }

        $stmt = $db->prepare('UPDATE nav_links SET category_id = ?, updated_at = NOW() WHERE id = ? AND user_id = ?');
        $stmt->execute([$categoryId, $id, $userId]);

        $functions->jsonResponse(['ok' => true, 'id' => $id, 'category_id' => $categoryId]);
        break;

    /* 收录前预览：只抓取、不入库，把结果连同「推荐分组」一起交给前端，
       等用户在收录框里确认（可改标题/描述/分组）后再调 quick_add 落库。 */
    case 'suggest_tags':
        // v2.16.5 · 生成标签建议（只读，不写库）。
        // 前端在收录框里点「AI 生成」触发，也可以在编辑已有网址时重新生成。
        $url = userSiteNormalizeUrl($_POST['url'] ?? '');
        $name = userSiteTrim($_POST['name'] ?? '', 100);
        $description = userSiteTrim($_POST['description'] ?? '', 200);

        if ($url === '' && $name === '') {
            $functions->jsonResponse(['ok' => false, 'error' => '缺少网址或名称', 'tags' => []], 400);
        }

        try {
            $tagService = new AiService($functions);
            $tagResult = $tagService->suggestTags($name, $url, $description);
        } catch (Throwable $e) {
            $tagResult = [
                'ok' => false,
                'source' => 'none',
                'tags' => [],
                'error' => '标签生成失败，可手动填写',
            ];
        }

        $functions->jsonResponse([
            'ok' => !empty($tagResult['ok']),
            'source' => (string) ($tagResult['source'] ?? 'none'),
            'tags' => array_values($tagResult['tags'] ?? []),
            'error' => (string) ($tagResult['error'] ?? ''),
        ]);
        break;

    case 'quick_preview':
        $url = userSiteNormalizeUrl($_POST['url'] ?? '');

        if ($url === '') {
            $functions->jsonResponse(['ok' => false, 'error' => '请输入有效的网址'], 400);
        }

        // 已在导航里就不必再走收录流程，直接告诉前端去标记收藏
        $dupStmt = $db->prepare('SELECT id, name FROM nav_links WHERE user_id = ? AND url = ? LIMIT 1');
        $dupStmt->execute([$userId, $url]);
        $existing = $dupStmt->fetch();

        if ($existing) {
            $functions->jsonResponse([
                'ok' => true,
                'url' => $url,
                'duplicate' => true,
                'id' => (int) $existing['id'],
                'name' => (string) $existing['name'],
                'message' => '该网址已在你的导航中',
            ]);
        }

        $meta = $siteInfo->fetch($url);

        $host = (string) parse_url($url, PHP_URL_HOST);
        $hostLabel = $host !== '' ? preg_replace('/^www\./i', '', $host) : $url;

        $name = !empty($meta['ok']) ? userSiteNiceName((string) ($meta['title'] ?? ''), $hostLabel) : '';

        if ($name === '') {
            $name = $hostLabel;
        }

        $description = !empty($meta['description']) ? userSiteTrim($meta['description'], 200) : '';
        $icons = !empty($meta['icons']) && is_array($meta['icons']) ? $meta['icons'] : [];
        $logo = !empty($icons[0]) ? userSiteTrim($icons[0], 500) : '';

        // 候选分组：当前用户可见的分组（公共 + 自己的）
        $engine = new CategoryEngine($functions);
        $engine->setUserId($userId);
        $rows = $engine->all($userId, true);

        $categories = [];

        foreach ($rows as $row) {
            $categories[] = [
                'id' => (int) $row['id'],
                'name' => (string) $row['name'],
                'is_private' => (int) ($row['is_private'] ?? 0),
                'link_count' => (int) ($row['link_count'] ?? 0),
            ];
        }

        // 推荐分组：拿标题 + 描述 + 域名去模糊匹配
        $names = array_column($categories, 'name');
        $suggestedName = $engine->smartMatch($name . ' ' . $description . ' ' . $hostLabel, $names);
        $suggestSource = 'keyword';

        // v2.16.0 · AI 推荐分组
        // 关键词没命中时才问 AI，避免多一次付费调用；AI 推荐的分类若尚不存在，
        // 这里只把名字带回前端（suggested_category_name），由「确认收录」时再创建。
        // 这样用户改选了别的分组就不会凭空多出一个空分类。
        $aiSuggestion = null;

        if ($suggestedName === null) {
            try {
                $aiService = new AiService($functions);

                if ($aiService->enabled() && $names) {
                    // v2.16.0：允许 AI 建议一个列表里没有的新分类（is_new），
                    // 由「确认收录」时再创建，用户改选已有分组则不会新建。
                    $aiResult = $aiService->recommendCategory($name, $description, $names);

                    if (!empty($aiResult['ok']) && !empty($aiResult['category'])) {
                        $suggestedName = (string) $aiResult['category'];
                        $suggestSource = 'ai';
                        $aiSuggestion = $suggestedName;

                        // AI 可能给出一个列表里没有的新名称，补进候选供前端展示
                        if (!in_array($suggestedName, $names, true)) {
                            $categories[] = [
                                'id' => 0,
                                'name' => $suggestedName,
                                'is_private' => 0,
                                'link_count' => 0,
                                'is_new' => 1,
                            ];
                        }
                    }
                }
            } catch (Throwable $e) {
                // AI 不可用时静默回落，收录流程不能因此中断
                $aiSuggestion = null;
            }
        }

        $suggestedId = 0;
        $suggestReason = 'none';

        if ($suggestedName !== null) {
            foreach ($categories as $category) {
                if ($category['name'] === $suggestedName) {
                    $suggestedId = $category['id'];
                    $suggestReason = $suggestSource;
                    break;
                }
            }

            // AI 推荐了本地尚不存在的分类：id 记 0，前端按名称显示「新建」态
            if ($suggestedId === 0 && $suggestSource === 'ai') {
                $suggestReason = 'ai_new';
            }
        }

        // 没匹配上就退回「未分类」，再退回第一个分组
        if ($suggestedId === 0 && $suggestReason !== 'ai_new') {
            foreach ($categories as $category) {
                if ($category['name'] === '未分类') {
                    $suggestedId = $category['id'];
                    $suggestReason = 'fallback';
                    break;
                }
            }
        }

        if ($suggestedId === 0 && $suggestReason !== 'ai_new' && $categories) {
            $suggestedId = $categories[0]['id'];
            $suggestReason = 'fallback';
        }

        // v2.16.5 · 收录时顺手给一版标签建议。
        // 只是预填，用户在弹窗里可以改；AI 未配置时退回关键词规则，不会阻断收录。
        $suggestedTags = [];
        $tagSource = 'none';

        try {
            $tagService = new AiService($functions);
            $tagResult = $tagService->suggestTags($name, $url, $description);

            if (!empty($tagResult['ok'])) {
                $suggestedTags = array_values($tagResult['tags'] ?? []);
                $tagSource = (string) ($tagResult['source'] ?? 'none');
            }
        } catch (Throwable $e) {
            $suggestedTags = [];
            $tagSource = 'none';
        }

        $functions->jsonResponse([
            'ok' => true,
            'url' => $url,
            'duplicate' => false,
            'name' => $name,
            'description' => $description,
            'logo' => $logo,
            'icons' => array_slice($icons, 0, 5),
            'host' => $hostLabel,
            'fetch_ok' => !empty($meta['ok']),
            'categories' => $categories,
            'suggested_category_id' => $suggestedId,
            // v2.16.0：AI 推荐的新分组名（不存在时才非空），供「确认收录」时创建
            'suggested_category_name' => ($suggestReason === 'ai_new') ? $suggestedName : '',
            'suggest_reason' => $suggestReason,
            // v2.16.5：标签建议 + 来源（ai / rules），前端据此标注
            'suggested_tags' => $suggestedTags,
            'tag_source' => $tagSource,
        ]);
        break;

    case 'quick_add':
        $url = userSiteNormalizeUrl($_POST['url'] ?? '');

        if ($url === '') {
            $functions->jsonResponse(['ok' => false, 'error' => '请输入有效的网址'], 400);
        }

        $countStmt = $db->prepare('SELECT COUNT(*) AS total FROM nav_links WHERE user_id = ?');
        $countStmt->execute([$userId]);

        if ((int) ($countStmt->fetch()['total'] ?? 0) >= USER_LINK_LIMIT) {
            $functions->jsonResponse(['ok' => false, 'error' => '已达收藏上限（' . USER_LINK_LIMIT . ' 条）'], 400);
        }

        $dupStmt = $db->prepare('SELECT id FROM nav_links WHERE user_id = ? AND url = ? LIMIT 1');
        $dupStmt->execute([$userId, $url]);
        $existing = $dupStmt->fetch();

        if ($existing) {
            // 已存在则直接标记为收藏
            $starStmt = $db->prepare('UPDATE nav_links SET is_starred = 1, updated_at = NOW() WHERE id = ? AND user_id = ?');
            $starStmt->execute([(int) $existing['id'], $userId]);

            $functions->jsonResponse([
                'ok' => true,
                'id' => (int) $existing['id'],
                'duplicate' => true,
                'is_starred' => 1,
                'message' => '该网址已在你的导航中，已标记为收藏',
            ]);
        }

        // 用户在收录框里确认过的字段优先；没传（或传空）时才现场抓取，
        // 这样 my.php 里「只传 url」的老调用仍然可用。
        $nameGiven = userSiteTrim($_POST['name'] ?? '', 100);
        $descriptionGiven = userSiteTrim($_POST['description'] ?? '', 200);
        $logoGiven = userSiteTrim($_POST['logo'] ?? '', 500);
        $hasConfirmed = $nameGiven !== '';

        $meta = [];
        $name = $nameGiven;
        $description = $descriptionGiven;
        $logo = $logoGiven;

        if (!$hasConfirmed) {
            $meta = $siteInfo->fetch($url);
            $metaHost = (string) parse_url($url, PHP_URL_HOST);
            $metaHost = $metaHost !== '' ? preg_replace('/^www\./i', '', $metaHost) : '';
            $name = !empty($meta['ok']) ? userSiteNiceName((string) ($meta['title'] ?? ''), $metaHost) : '';
            $description = $descriptionGiven !== '' ? $descriptionGiven
                : (!empty($meta['description']) ? userSiteTrim($meta['description'], 200) : '');
            $logo = $logoGiven !== '' ? $logoGiven
                : (!empty($meta['icons'][0]) ? userSiteTrim($meta['icons'][0], 500) : '');
        }

        if ($name === '') {
            $host = parse_url($url, PHP_URL_HOST);
            $name = $host ? preg_replace('/^www\./i', '', $host) : $url;
        }

        $categoryId = userSiteCategoryId($db, $_POST['category_id'] ?? 0, $userId);

        // 前端传的是公共分组（user_id = 0）时上面那条查不到，这里补一次：
        // 收录框里的候选分组来自 CategoryEngine::all()，包含公共分组。
        if ($categoryId <= 0) {
            $requested = (int) ($_POST['category_id'] ?? 0);

            if ($requested > 0) {
                $pubStmt = $db->prepare('SELECT id FROM categories WHERE id = ? AND (user_id = ? OR (user_id = 0 AND is_private = 0)) LIMIT 1');
                $pubStmt->execute([$requested, $userId]);

                if ($pubStmt->fetch()) {
                    $categoryId = $requested;
                }
            }
        }

        if ($categoryId <= 0) {
            // v2.16.0 · 按名称建分类：
            // 前端传入 category_name 表示用户「主动新建」或「采纳了 AI 推荐的新分组」。
            // 只有这两种情况才会真正建分类，用户改选已有分组时 category_name 为空，
            // 因此不会凭空多出空分类。
            $newCategoryName = userSiteTrim($_POST['category_name'] ?? '', 30);

            if ($newCategoryName !== '') {
                $engine = new CategoryEngine($functions);
                $engine->setUserId($userId);
                $categoryId = $engine->ensure($newCategoryName, 'bi-collection');
            }
        }

        if ($categoryId <= 0) {
            $engine = new CategoryEngine($functions);
            // v2.14.0：自动补建的「未分类」也必须归属当前用户，不能落成公共分类
            $engine->setUserId($userId);
            $categoryId = $engine->ensure('未分类', 'bi-collection');
        }

        $sortStmt = $db->prepare('SELECT COALESCE(MAX(sort_order), 0) + 1 AS next_sort FROM nav_links WHERE user_id = ?');
        $sortStmt->execute([$userId]);
        $sort = (int) ($sortStmt->fetch()['next_sort'] ?? 1);

        // v2.16.5：收录时带上 AI 建议过的标签（用户可以在弹窗里改掉或清空）
        $tags = userSiteTrim($_POST['tags'] ?? '', 255);

        $stmt = $db->prepare(
            'INSERT INTO nav_links (name, url, logo, description, category_id, user_id, sort_order, is_active, is_starred, tags, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, 1, 1, ?, NOW())'
        );
        $stmt->execute([$name, $url, $logo, $description, $categoryId, $userId, $sort, $tags]);

        $functions->jsonResponse([
            'ok' => true,
            'id' => (int) $db->lastInsertId(),
            'name' => $name,
            'url' => $url,
            'logo' => $logo,
            'description' => $description,
            'tags' => $tags,
            'category_id' => $categoryId,
            'category_name' => userSiteCategoryName($db, $categoryId),
            'is_starred' => 1,
            'meta' => $meta,
        ]);
        break;

    case 'batch_preview':
        // 批量识别：只抓取、不入库，把每条的标题 / 图标 / 描述 / 推荐分组
        // 一次性返回给前端做成可校对的列表，确认后再调 batch_add 落库。
        $raw = (string) ($_POST['urls'] ?? '');

        if ($raw === '') {
            $functions->jsonResponse(['ok' => false, 'error' => '请粘贴至少一个网址'], 400);
        }

        $parsed = userSiteParseLines($raw, BATCH_IMPORT_LIMIT);
        $items = $parsed['items'];

        if (!$items) {
            $functions->jsonResponse(['ok' => false, 'error' => '未解析到有效网址'], 400);
        }

        $engine = new CategoryEngine($functions);
        $engine->setUserId($userId);
        $categories = userSiteCategories($engine, $userId);

        // 查重：已在库里的直接标出来，不再重复抓取
        $dupStmt = $db->prepare('SELECT id, name FROM nav_links WHERE user_id = ? AND url = ? LIMIT 1');
        $urls = [];

        foreach ($items as $index => $item) {
            $dupStmt->execute([$userId, $item['url']]);
            $existing = $dupStmt->fetch();

            $items[$index]['duplicate'] = $existing ? true : false;
            $items[$index]['existing_id'] = $existing ? (int) $existing['id'] : 0;
            $items[$index]['existing_name'] = $existing ? (string) $existing['name'] : '';

            // 已存在的就不用抓了，省一次请求
            if (!$existing) {
                $urls[] = $item['url'];
            }
        }

        $metas = $urls ? $siteInfo->fetchMany($urls) : [];
        $result = [];

        foreach ($items as $item) {
            $url = $item['url'];
            $host = (string) parse_url($url, PHP_URL_HOST);
            $hostLabel = $host !== '' ? preg_replace('/^www\./i', '', $host) : $url;

            if ($item['duplicate']) {
                $result[] = [
                    'url' => $url,
                    'host' => $hostLabel,
                    'duplicate' => true,
                    'existing_id' => $item['existing_id'],
                    'name' => $item['existing_name'],
                    'fetch_ok' => false,
                    'selected' => false,
                    'note' => '已在你的导航中',
                ];
                continue;
            }

            $meta = $metas[$url] ?? ['ok' => false];
            $title = !empty($meta['ok']) ? (string) ($meta['title'] ?? '') : '';

            // 用户手写的名称优先级最高，其次是净化后的标题，最后才是域名
            $name = $item['name'] !== '' ? $item['name']
                : ($title !== '' ? userSiteNiceName($title, $hostLabel) : $hostLabel);

            $description = !empty($meta['description']) ? userSiteTrim($meta['description'], 200) : '';
            $icons = !empty($meta['icons']) && is_array($meta['icons']) ? $meta['icons'] : [];
            $logo = !empty($icons[0]) ? userSiteTrim($icons[0], 500) : '';

            $suggest = userSiteSuggestCategory($engine, $categories, $name . ' ' . $description . ' ' . $hostLabel);

            $result[] = [
                'url' => $url,
                'host' => $hostLabel,
                'duplicate' => false,
                'name' => $name,
                'description' => $description,
                'logo' => $logo,
                'icons' => array_slice($icons, 0, 5),
                'fetch_ok' => !empty($meta['ok']),
                'fetch_error' => empty($meta['ok']) ? (string) ($meta['error'] ?? 'fetch_failed') : '',
                'suggested_category_id' => $suggest['id'],
                'suggested_category_name' => $suggest['name'],
                'suggest_reason' => $suggest['reason'],
                'selected' => true,
                'note' => empty($meta['ok']) ? '抓不到标题，请手动补充' : '',
            ];
        }

        // 剩余容量：批量导入也要受总条数上限约束
        $countStmt = $db->prepare('SELECT COUNT(*) AS total FROM nav_links WHERE user_id = ?');
        $countStmt->execute([$userId]);
        $remaining = max(0, USER_LINK_LIMIT - (int) ($countStmt->fetch()['total'] ?? 0));

        $functions->jsonResponse([
            'ok' => true,
            'items' => $result,
            'categories' => $categories,
            'total' => $parsed['total'],
            'truncated' => $parsed['truncated'],
            'limit' => BATCH_IMPORT_LIMIT,
            'remaining' => $remaining,
            'fetched' => count($urls),
        ]);
        break;

    case 'update':
        $id = (int) ($_POST['id'] ?? 0);

        if ($id <= 0 || !userSiteOwned($db, $id, $userId)) {
            $functions->jsonResponse(['ok' => false, 'error' => '记录不存在或无权操作'], 404);
        }

        $url = userSiteNormalizeUrl($_POST['url'] ?? '');

        if ($url === '') {
            $functions->jsonResponse(['ok' => false, 'error' => '请输入有效的网址'], 400);
        }

        $dupStmt = $db->prepare('SELECT id FROM nav_links WHERE user_id = ? AND url = ? AND id != ? LIMIT 1');
        $dupStmt->execute([$userId, $url, $id]);

        if ($dupStmt->fetch()) {
            $functions->jsonResponse(['ok' => false, 'error' => '该网址已在你的导航中'], 400);
        }

        $name = userSiteTrim($_POST['name'] ?? '', 100);

        if ($name === '') {
            $functions->jsonResponse(['ok' => false, 'error' => '请输入网站名称'], 400);
        }

        $description = userSiteTrim($_POST['description'] ?? '', 200);
        $logo = userSiteTrim($_POST['logo'] ?? '', 500);
        $tags = userSiteTrim($_POST['tags'] ?? '', 255);
        $alias = userSiteTrim($_POST['alias'] ?? '', 255);
        $categoryId = userSiteCategoryId($db, $_POST['category_id'] ?? 0, $userId);

        if ($categoryId <= 0) {
            $engine = new CategoryEngine($functions);
            // v2.14.0：自动补建的「未分类」也必须归属当前用户，不能落成公共分类
            $engine->setUserId($userId);
            $categoryId = $engine->ensure('未分类', 'bi-collection');
        }

        $stmt = $db->prepare(
            'UPDATE nav_links SET name = ?, url = ?, logo = ?, description = ?, category_id = ?, tags = ?, alias = ?, updated_at = NOW()
             WHERE id = ? AND user_id = ?'
        );
        $stmt->execute([$name, $url, $logo, $description, $categoryId, $tags, $alias, $id, $userId]);

        $functions->jsonResponse([
            'ok' => true,
            'id' => $id,
            'name' => $name,
            'url' => $url,
            'category_id' => $categoryId,
        ]);
        break;

    case 'delete':
        $id = (int) ($_POST['id'] ?? 0);

        if ($id <= 0 || !userSiteOwned($db, $id, $userId)) {
            $functions->jsonResponse(['ok' => false, 'error' => '记录不存在或无权操作'], 404);
        }

        $stmt = $db->prepare('DELETE FROM nav_links WHERE id = ? AND user_id = ?');
        $stmt->execute([$id, $userId]);

        $functions->jsonResponse(['ok' => true]);
        break;

    case 'ai_categorize_preview':
        // v2.16.2：AI 一键分类 · 分析阶段
        // 前端按批调用（默认 20 条/批），避免单个请求跑太久、也让进度条有反馈。
        // AI 未启用时自动降级为本地关键词规则，功能不至于直接不可用。
        $scope = strtolower(trim((string) ($_POST['scope'] ?? 'ungrouped')));

        if (!in_array($scope, ['ungrouped', 'all'], true)) {
            $scope = 'ungrouped';
        }

        $limit = (int) ($_POST['limit'] ?? 20);
        $limit = max(5, min(30, $limit));
        $offset = max(0, (int) ($_POST['offset'] ?? 0));

        $engine = new CategoryEngine($functions);
        $engine->setUserId($userId);
        $categories = userSiteCategories($engine, $userId);

        // 名字 → 分类 id：只取第一个同名的，用于判断 AI 建议是否已有对应分组
        // 注意 id 列表必须单独收集，不能从 nameToId 反推 —— 分类允许重名，
        // 反推会漏掉重名的那几个 id，导致它们的链接被误判成「未分类」
        $nameToId = [];
        $visibleNames = [];
        $visibleIds = [];
        $classifiedIds = [];

        foreach ($categories as $category) {
            $categoryName = (string) $category['name'];
            $categoryId = (int) $category['id'];

            $visibleIds[] = $categoryId;

            if (!isset($nameToId[$categoryName])) {
                $nameToId[$categoryName] = $categoryId;
                $visibleNames[] = $categoryName;
            }

            // 「未分类」这个分组本身就是待整理容器，不算已归类
            $trimmed = trim($categoryName);

            if ($trimmed !== '' && $trimmed !== '未分类') {
                $classifiedIds[] = $categoryId;
            }
        }

        $where = 'user_id = ? AND deleted_at IS NULL AND is_active = 1';
        $params = [$userId];

        // 「未分类」的口径：category_id 为 0，或指向一个自己看不到 / 不存在的分类
        if ($scope === 'ungrouped') {
            if ($classifiedIds) {
                $placeholders = implode(',', array_fill(0, count($classifiedIds), '?'));
                $where .= " AND (category_id = 0 OR category_id NOT IN ($placeholders))";

                foreach ($classifiedIds as $cid) {
                    $params[] = $cid;
                }
            }
            // 没有任何真实分类时，全部链接都算待整理，不加额外条件
        }

        $countStmt = $db->prepare("SELECT COUNT(*) AS total FROM nav_links WHERE $where");
        $countStmt->execute($params);
        $total = (int) ($countStmt->fetch()['total'] ?? 0);

        if ($total === 0) {
            $functions->jsonResponse([
                'ok' => true,
                'ai' => false,
                'items' => [],
                'categories' => $categories,
                'total' => 0,
                'offset' => 0,
                'next' => 0,
                'done' => true,
            ]);
        }

        $sql = "SELECT id, name, url, description, category_id FROM nav_links WHERE $where"
            . " ORDER BY id DESC LIMIT $limit OFFSET $offset";

        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $batch = $stmt->fetchAll();

        $groupNames = [];

        foreach ($categories as $category) {
            $groupNames[(int) $category['id']] = (string) $category['name'];
        }

        $ai = new AiService($functions);
        $aiEnabled = $ai->enabled();
        $suggestMap = [];
        $source = 'local';

        if ($aiEnabled) {
            $result = $ai->categorizeBatch($batch, $visibleNames);

            if (!empty($result['ok']) && !empty($result['map'])) {
                // AI 偶尔会把「未分类」当成一个正经分组名返回，等同于没给建议，剔掉
                foreach ($result['map'] as $mapId => $mapName) {
                    if (trim((string) $mapName) === '' || (string) $mapName === '未分类') {
                        continue;
                    }

                    $suggestMap[(int) $mapId] = (string) $mapName;
                }

                if ($suggestMap) {
                    $source = 'ai';
                }
            }
        }

        // AI 未启用 / 调用失败 / 有漏项 → 本地关键词规则兜底补齐
        foreach ($batch as $row) {
            $id = (int) $row['id'];

            if (isset($suggestMap[$id])) {
                continue;
            }

            $text = trim((string) $row['name'] . ' ' . (string) $row['description'] . ' ' . (string) $row['url']);
            $suggest = userSiteSuggestCategory($engine, $categories, $text);

            // v2.16.2 修复：userSiteSuggestCategory() 在关键词匹配不上时会退回「未分类」
            // 或列表第一个分组（那是给「快捷收录时给个默认值」用的）。
            // 放到「整理」场景里就成了无意义建议——「AI → 未分类」等于什么都没说，
            // 「AI → 第一个分组」更是瞎猜。这里只采纳真正命中关键词的结果（reason=keyword），
            // 其余留空，前端会显示为「保持不变」且不可勾选。
            if (!empty($suggest['name'])
                && (string) $suggest['name'] !== '未分类'
                && (string) ($suggest['reason'] ?? '') === 'keyword') {
                $suggestMap[$id] = (string) $suggest['name'];
            }
        }

        $items = [];

        foreach ($batch as $row) {
            $id = (int) $row['id'];
            $categoryId = (int) $row['category_id'];
            $currentName = isset($groupNames[$categoryId]) ? $groupNames[$categoryId] : '未分类';
            $suggestName = isset($suggestMap[$id]) ? (string) $suggestMap[$id] : '';

            $items[] = [
                'id' => $id,
                'name' => (string) $row['name'],
                'url' => (string) $row['url'],
                'current_category_id' => $categoryId,
                'current_category_name' => $currentName,
                'suggest_name' => $suggestName,
                'suggest_id' => isset($nameToId[$suggestName]) ? $nameToId[$suggestName] : 0,
                'is_new' => $suggestName !== '' && !isset($nameToId[$suggestName]),
                'changed' => $suggestName !== '' && $suggestName !== $currentName,
            ];
        }

        $next = $offset + count($batch);

        $functions->jsonResponse([
            'ok' => true,
            'ai' => $source === 'ai',
            'items' => $items,
            'categories' => $categories,
            'total' => $total,
            'offset' => $offset,
            'next' => $next,
            'done' => $next >= $total,
        ]);
        break;

    case 'ai_categorize_apply':
        // v2.16.2：AI 一键分类 · 落库阶段
        // 前端把用户确认过的 [{id, category}] 整体发过来，一次事务写完。
        $raw = (string) ($_POST['payload'] ?? '');
        $rows = json_decode($raw, true);

        if (!is_array($rows) || !$rows) {
            $functions->jsonResponse(['ok' => false, 'error' => '没有可应用的分类结果'], 400);
        }

        // 单次上限，防止一次改动过大
        $rows = array_slice($rows, 0, 300);

        $pairs = [];
        $idList = [];

        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $id = (int) ($row['id'] ?? 0);
            $name = trim((string) ($row['category'] ?? ''));

            if ($id <= 0 || $name === '') {
                continue;
            }

            $name = preg_replace('/["\'「」《》。，,、:：\r\n]+/u', '', $name);
            $name = trim((string) $name);

            if ($name === '') {
                continue;
            }

            $pairs[$id] = mb_substr($name, 0, 30, 'UTF-8');
            $idList[] = $id;
        }

        if (!$pairs) {
            $functions->jsonResponse(['ok' => false, 'error' => '分类结果为空'], 400);
        }

        // 校验归属：一个都不能越权
        $placeholders = implode(',', array_fill(0, count($idList), '?'));
        $checkStmt = $db->prepare("SELECT COUNT(*) AS total FROM nav_links WHERE id IN ($placeholders) AND user_id = ? AND deleted_at IS NULL");
        $checkParams = array_merge($idList, [$userId]);
        $checkStmt->execute($checkParams);
        $owned = (int) ($checkStmt->fetch()['total'] ?? 0);

        if ($owned !== count($idList)) {
            $functions->jsonResponse(['ok' => false, 'error' => '部分网址无权操作'], 403);
        }

        // 名字 → 分类 id：优先匹配自己名下的，其次公共的，都没有才新建
        $catStmt = $db->prepare(
            'SELECT id, name, user_id FROM categories'
            . ' WHERE (user_id = 0 AND is_private = 0) OR user_id = ?'
            . ' ORDER BY user_id DESC, sort_order, id'
        );
        $catStmt->execute([$userId]);
        $nameToId = [];

        foreach ($catStmt->fetchAll() as $category) {
            $categoryName = (string) $category['name'];

            if (!isset($nameToId[$categoryName])) {
                $nameToId[$categoryName] = (int) $category['id'];
            }
        }

        // 当前分类快照，用于判断「哪些是真新建的」
        $beforeIds = array_values($nameToId);

        $engine = new CategoryEngine($functions);
        $engine->setUserId($userId);

        $moved = 0;
        $created = [];
        $failed = 0;

        try {
            $db->beginTransaction();

            $updateStmt = $db->prepare('UPDATE nav_links SET category_id = ?, updated_at = NOW() WHERE id = ? AND user_id = ?');
            $currentStmt = $db->prepare('SELECT category_id FROM nav_links WHERE id = ? AND user_id = ? LIMIT 1');

            foreach ($pairs as $id => $name) {
                if (!isset($nameToId[$name])) {
                    $nameToId[$name] = $engine->ensure($name, 'bi-collection', null, $userId);
                    $created[] = $name;
                }

                $targetId = $nameToId[$name];

                if ($targetId <= 0) {
                    $failed++;
                    continue;
                }

                // 已经在目标分类里的就跳过，别刷 updated_at
                $currentStmt->execute([$id, $userId]);
                $current = $currentStmt->fetch();

                if ($current && (int) $current['category_id'] === $targetId) {
                    continue;
                }

                $updateStmt->execute([$targetId, $id, $userId]);

                if ($updateStmt->rowCount() > 0) {
                    $moved++;
                } else {
                    $failed++;
                }
            }

            $db->commit();
        } catch (Throwable $error) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }

            $functions->jsonResponse(['ok' => false, 'error' => '应用分类失败：' . $error->getMessage()], 500);
        }

        // 只回报真正新建的分类
        $createdNames = [];

        foreach ($created as $name) {
            if (!in_array($nameToId[$name] ?? 0, $beforeIds, true)) {
                $createdNames[] = $name;
            }
        }

        $functions->jsonResponse([
            'ok' => true,
            'moved' => $moved,
            'skipped' => count($pairs) - $moved - $failed,
            'failed' => $failed,
            'created' => array_values(array_unique($createdNames)),
            'total' => count($pairs),
        ]);
        break;

    case 'upload_logo':
        // v2.16.3：编辑网址弹窗里的「上传图标」。
        // 只落盘、不写库——返回相对路径给前端预览，用户点保存时才随 logo 字段一并提交。
        $file = $_FILES['logo'] ?? ($_FILES['file'] ?? null);

        // 换图标 / 取消弹窗时把上一次刚上传、还没落库的临时文件删掉，避免攒垃圾。
        // 必须放在「有没有新文件」判断之前：只带 discard、不带文件的请求也要生效。
        if (!empty($_POST['discard'])) {
            userSiteDiscardUpload((string) $_POST['discard']);
        }

        if (!is_array($file)) {
            $functions->jsonResponse(['ok' => false, 'error' => '没有收到文件'], 400);
        }

        $result = userSiteUploadIcon($functions, $file);

        if (empty($result['success'])) {
            $functions->jsonResponse(['ok' => false, 'error' => $result['error'] ?? '上传失败'], 400);
        }

        $functions->jsonResponse([
            'ok' => true,
            'path' => $result['relativePath'],
            'url' => $functions->getLogoDisplayUrl($result['relativePath']),
            'width' => $result['width'] ?? 0,
            'height' => $result['height'] ?? 0,
            'bytes' => $result['bytes'] ?? 0,
        ]);
        break;

    case 'batch_move':
        $ids = $_POST['ids'] ?? [];

        if (is_string($ids)) {
            $decoded = json_decode($ids, true);
            $ids = is_array($decoded) ? $decoded : array_filter(array_map('trim', explode(',', $ids)));
        }

        if (!is_array($ids) || empty($ids)) {
            $functions->jsonResponse(['ok' => false, 'error' => '请选择要移动的网址'], 400);
        }

        $idList = array_values(array_filter(array_map('intval', $ids), function ($v) { return $v > 0; }));

        if (empty($idList)) {
            $functions->jsonResponse(['ok' => false, 'error' => '请选择有效的网址'], 400);
        }

        // 校验所有权
        $placeholders = implode(',', array_fill(0, count($idList), '?'));
        $checkStmt = $db->prepare("SELECT COUNT(*) AS total FROM nav_links WHERE id IN ($placeholders) AND user_id = ?");
        $params = $idList;
        $params[] = $userId;
        $checkStmt->execute($params);
        $owned = (int) ($checkStmt->fetch()['total'] ?? 0);

        if ($owned !== count($idList)) {
            $functions->jsonResponse(['ok' => false, 'error' => '部分网址无权操作'], 403);
        }

        $categoryId = userSiteCategoryId($db, $_POST['category_id'] ?? 0, $userId);

        if ($categoryId <= 0) {
            $engine = new CategoryEngine($functions);
            // v2.14.0：自动补建的「未分类」也必须归属当前用户，不能落成公共分类
            $engine->setUserId($userId);
            $categoryId = $engine->ensure('未分类', 'bi-collection');
        }

        $updateStmt = $db->prepare("UPDATE nav_links SET category_id = ?, updated_at = NOW() WHERE id IN ($placeholders) AND user_id = ?");
        $params2 = array_merge([$categoryId], $idList, [$userId]);
        $updateStmt->execute($params2);

        $functions->jsonResponse(['ok' => true, 'count' => count($idList), 'category_id' => $categoryId]);
        break;

    case 'batch_delete':
        $ids = $_POST['ids'] ?? [];

        if (is_string($ids)) {
            $decoded = json_decode($ids, true);
            $ids = is_array($decoded) ? $decoded : array_filter(array_map('trim', explode(',', $ids)));
        }

        if (!is_array($ids) || empty($ids)) {
            $functions->jsonResponse(['ok' => false, 'error' => '请选择要删除的网址'], 400);
        }

        $idList = array_values(array_filter(array_map('intval', $ids), function ($v) { return $v > 0; }));

        if (empty($idList)) {
            $functions->jsonResponse(['ok' => false, 'error' => '请选择有效的网址'], 400);
        }

        $placeholders = implode(',', array_fill(0, count($idList), '?'));
        $checkStmt = $db->prepare("SELECT COUNT(*) AS total FROM nav_links WHERE id IN ($placeholders) AND user_id = ?");
        $params = $idList;
        $params[] = $userId;
        $checkStmt->execute($params);
        $owned = (int) ($checkStmt->fetch()['total'] ?? 0);

        if ($owned !== count($idList)) {
            $functions->jsonResponse(['ok' => false, 'error' => '部分网址无权操作'], 403);
        }

        $delStmt = $db->prepare("DELETE FROM nav_links WHERE id IN ($placeholders) AND user_id = ?");
        $delStmt->execute($params);

        $functions->jsonResponse(['ok' => true, 'count' => count($idList)]);
        break;

    case 'batch_tag':
        $ids = $_POST['ids'] ?? [];

        if (is_string($ids)) {
            $decoded = json_decode($ids, true);
            $ids = is_array($decoded) ? $decoded : array_filter(array_map('trim', explode(',', $ids)));
        }

        if (!is_array($ids) || empty($ids)) {
            $functions->jsonResponse(['ok' => false, 'error' => '请选择网址'], 400);
        }

        $idList = array_values(array_filter(array_map('intval', $ids), function ($v) { return $v > 0; }));

        if (empty($idList)) {
            $functions->jsonResponse(['ok' => false, 'error' => '请选择有效的网址'], 400);
        }

        $placeholders = implode(',', array_fill(0, count($idList), '?'));
        $checkStmt = $db->prepare("SELECT COUNT(*) AS total FROM nav_links WHERE id IN ($placeholders) AND user_id = ?");
        $params = $idList;
        $params[] = $userId;
        $checkStmt->execute($params);
        $owned = (int) ($checkStmt->fetch()['total'] ?? 0);

        if ($owned !== count($idList)) {
            $functions->jsonResponse(['ok' => false, 'error' => '部分网址无权操作'], 403);
        }

        $newTags = userSiteTrim($_POST['tags'] ?? '', 255);
        $mode = ($_POST['mode'] ?? 'append') === 'overwrite' ? 'overwrite' : 'append';
        $newTagArr = array_values(array_filter(array_map('trim', explode(',', $newTags))));

        if ($mode === 'append') {
            // 逐条读取现有 tags 并合并去重
            $selectStmt = $db->prepare("SELECT id, tags FROM nav_links WHERE id IN ($placeholders) AND user_id = ?");
            $selectStmt->execute($params);
            $rows = $selectStmt->fetchAll();
            $updateStmt = $db->prepare('UPDATE nav_links SET tags = ?, updated_at = NOW() WHERE id = ? AND user_id = ?');

            foreach ($rows as $row) {
                $existing = array_values(array_filter(array_map('trim', explode(',', (string) ($row['tags'] ?? '')))));
                $merged = array_values(array_unique(array_merge($existing, $newTagArr)));
                $mergedStr = implode(',', array_slice($merged, 0, 20)); // 最多 20 个标签
                $updateStmt->execute([$mergedStr, (int) $row['id'], $userId]);
            }
        } else {
            $mergedStr = implode(',', array_slice($newTagArr, 0, 20));
            $updateStmt = $db->prepare("UPDATE nav_links SET tags = ?, updated_at = NOW() WHERE id IN ($placeholders) AND user_id = ?");
            $updParams = array_merge([$mergedStr], $idList, [$userId]);
            $updateStmt->execute($updParams);
        }

        $functions->jsonResponse(['ok' => true, 'count' => count($idList)]);
        break;

    case 'batch_add':
        // 两条入参路径：
        //   items（JSON）— 前端在批量预览里校对过的结果，按用户确认的字段直接落库，不再抓取
        //   urls（纯文本）— 老调用，仍然走「解析 + 用域名兜底名称」的行为，不抓取
        $rawItems = $_POST['items'] ?? null;

        if (is_string($rawItems)) {
            $rawItems = json_decode($rawItems, true);
        }

        $items = [];

        if (is_array($rawItems) && $rawItems) {
            foreach ($rawItems as $row) {
                if (!is_array($row)) {
                    continue;
                }

                $url = userSiteNormalizeUrl($row['url'] ?? '');

                if ($url === '') {
                    continue;
                }

                $items[] = [
                    'url' => $url,
                    'name' => userSiteTrim($row['name'] ?? '', 100),
                    'description' => userSiteTrim($row['description'] ?? '', 200),
                    'logo' => userSiteTrim($row['logo'] ?? '', 500),
                    'category_id' => (int) ($row['category_id'] ?? 0),
                ];
            }
        } else {
            // 老调用：只有 urls 文本，分组统一取 POST 里的 category_id
            $parsed = userSiteParseLines((string) ($_POST['urls'] ?? ''), BATCH_IMPORT_LIMIT);
            $fallbackCategory = (int) ($_POST['category_id'] ?? 0);

            foreach ($parsed['items'] as $row) {
                $items[] = [
                    'url' => $row['url'],
                    'name' => $row['name'],
                    'description' => '',
                    'logo' => '',
                    'category_id' => $fallbackCategory,
                ];
            }
        }

        if (!$items) {
            $functions->jsonResponse(['ok' => false, 'error' => '未解析到有效网址'], 400);
        }

        // 上限校验
        $countStmt = $db->prepare('SELECT COUNT(*) AS total FROM nav_links WHERE user_id = ?');
        $countStmt->execute([$userId]);
        $current = (int) ($countStmt->fetch()['total'] ?? 0);

        // 分组兜底：单条没指定就用 POST 的，再没有就落到「未分类」
        $defaultCategoryId = userSiteCategoryId($db, $_POST['category_id'] ?? 0, $userId);

        if ($defaultCategoryId <= 0) {
            $requested = (int) ($_POST['category_id'] ?? 0);

            if ($requested > 0) {
                // 公共分组（user_id = 0）在上面的查询里取不到，这里补一次
                $pubStmt = $db->prepare('SELECT id FROM categories WHERE id = ? AND (user_id = ? OR (user_id = 0 AND is_private = 0)) LIMIT 1');
                $pubStmt->execute([$requested, $userId]);

                if ($pubStmt->fetch()) {
                    $defaultCategoryId = $requested;
                }
            }
        }

        $engine = new CategoryEngine($functions);
        // v2.14.0：书签批量导入时补建的分组归属当前用户
        $engine->setUserId($userId);

        if ($defaultCategoryId <= 0) {
            $defaultCategoryId = $engine->ensure('未分类', 'bi-collection');
        }

        $sortStmt = $db->prepare('SELECT COALESCE(MAX(sort_order), 0) AS max_sort FROM nav_links WHERE user_id = ?');
        $sortStmt->execute([$userId]);
        $sort = (int) ($sortStmt->fetch()['max_sort'] ?? 0);

        $imported = 0;
        $skipped = 0;
        $added = [];
        $dupStmt = $db->prepare('SELECT id FROM nav_links WHERE user_id = ? AND url = ? LIMIT 1');
        $ownCategoryStmt = $db->prepare('SELECT id FROM categories WHERE id = ? AND user_id = ? LIMIT 1');
        $pubCategoryStmt = $db->prepare('SELECT id FROM categories WHERE id = ? AND (user_id = ? OR (user_id = 0 AND is_private = 0)) LIMIT 1');
        $insertStmt = $db->prepare(
            'INSERT INTO nav_links (name, url, logo, description, category_id, user_id, sort_order, is_active, is_starred, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, 1, 0, NOW())'
        );

        $seen = [];

        foreach ($items as $item) {
            if (isset($seen[$item['url']])) { $skipped++; continue; }
            $seen[$item['url']] = true;

            $dupStmt->execute([$userId, $item['url']]);
            if ($dupStmt->fetch()) { $skipped++; continue; }

            if ($current + $imported >= USER_LINK_LIMIT) { break; }

            // 分组归属校验：确认项里的分组必须是自己的或公共的，否则回退到默认分组
            $categoryId = $item['category_id'];

            if ($categoryId > 0) {
                $ownCategoryStmt->execute([$categoryId, $userId]);
                $owned = $ownCategoryStmt->fetch() ? true : false;

                if (!$owned) {
                    $pubCategoryStmt->execute([$categoryId, $userId]);
                    $owned = $pubCategoryStmt->fetch() ? true : false;
                }

                if (!$owned) {
                    $categoryId = 0;
                }
            }

            if ($categoryId <= 0) {
                $categoryId = $defaultCategoryId;
            }

            $name = $item['name'];

            if ($name === '') {
                $host = parse_url($item['url'], PHP_URL_HOST);
                $name = $host ? preg_replace('/^www\./i', '', $host) : $item['url'];
            }

            $sort++;
            $insertStmt->execute([
                $name,
                $item['url'],
                $item['logo'],
                $item['description'],
                $categoryId,
                $userId,
                $sort,
            ]);

            $newId = (int) $db->lastInsertId();
            $imported++;

            $added[] = [
                'id' => $newId,
                'name' => $name,
                'url' => $item['url'],
                'logo' => $item['logo'],
                'description' => $item['description'],
                'category_id' => $categoryId,
                'category_name' => userSiteCategoryName($db, $categoryId),
            ];
        }

        $functions->jsonResponse([
            'ok' => true,
            'imported' => $imported,
            'skipped' => $skipped,
            'items' => $added,
            'category_id' => $defaultCategoryId,
            'limit' => USER_LINK_LIMIT,
        ]);
        break;

    case 'sort':
        $ids = $_POST['ids'] ?? [];

        if (is_string($ids)) {
            $decoded = json_decode($ids, true);
            $ids = is_array($decoded) ? $decoded : explode(',', $ids);
        }

        if (!is_array($ids)) {
            $functions->jsonResponse(['ok' => false, 'error' => '参数格式不正确'], 400);
        }

        $update = $db->prepare('UPDATE nav_links SET sort_order = ? WHERE id = ? AND user_id = ?');
        $order = 0;

        try {
            $db->beginTransaction();

            foreach ($ids as $rawId) {
                $id = (int) $rawId;

                if ($id <= 0) {
                    continue;
                }

                $order++;
                $update->execute([$order, $id, $userId]);
            }

            $db->commit();
        } catch (Throwable $error) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }

            $functions->logMessage('[user_sites] 排序失败: ' . $error->getMessage(), 'ERROR');
            $functions->jsonResponse(['ok' => false, 'error' => '保存排序失败'], 500);
        }

        $functions->jsonResponse(['ok' => true, 'count' => $order]);
        break;

    default:
        $functions->jsonResponse(['ok' => false, 'error' => 'unsupported_action'], 400);
}
