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
            $categoryId = $engine->ensure('未分类', 'bi-collection');
        }

        $stmt = $db->prepare('UPDATE nav_links SET category_id = ?, updated_at = NOW() WHERE id = ? AND user_id = ?');
        $stmt->execute([$categoryId, $id, $userId]);

        $functions->jsonResponse(['ok' => true, 'id' => $id, 'category_id' => $categoryId]);
        break;

    /* 收录前预览：只抓取、不入库，把结果连同「推荐分组」一起交给前端，
       等用户在收录框里确认（可改标题/描述/分组）后再调 quick_add 落库。 */
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

        $suggestedId = 0;
        $suggestReason = 'none';

        if ($suggestedName !== null) {
            foreach ($categories as $category) {
                if ($category['name'] === $suggestedName) {
                    $suggestedId = $category['id'];
                    $suggestReason = 'keyword';
                    break;
                }
            }
        }

        // 没匹配上就退回「未分类」，再退回第一个分组
        if ($suggestedId === 0) {
            foreach ($categories as $category) {
                if ($category['name'] === '未分类') {
                    $suggestedId = $category['id'];
                    $suggestReason = 'fallback';
                    break;
                }
            }
        }

        if ($suggestedId === 0 && $categories) {
            $suggestedId = $categories[0]['id'];
            $suggestReason = 'fallback';
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
            'suggest_reason' => $suggestReason,
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
            $engine = new CategoryEngine($functions);
            $categoryId = $engine->ensure('未分类', 'bi-collection');
        }

        $sortStmt = $db->prepare('SELECT COALESCE(MAX(sort_order), 0) + 1 AS next_sort FROM nav_links WHERE user_id = ?');
        $sortStmt->execute([$userId]);
        $sort = (int) ($sortStmt->fetch()['next_sort'] ?? 1);

        $stmt = $db->prepare(
            'INSERT INTO nav_links (name, url, logo, description, category_id, user_id, sort_order, is_active, is_starred, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, 1, 1, NOW())'
        );
        $stmt->execute([$name, $url, $logo, $description, $categoryId, $userId, $sort]);

        $functions->jsonResponse([
            'ok' => true,
            'id' => (int) $db->lastInsertId(),
            'name' => $name,
            'url' => $url,
            'logo' => $logo,
            'description' => $description,
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
