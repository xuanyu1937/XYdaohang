<?php
/**
 * 轩宇-导航 · 定时任务：链接健康检测 + 失效告警
 *
 * 触发方式（二选一）：
 *   1) 浏览器/服务器计划任务访问：/cron/daily_check.php?key=<后台生成的定时密钥>
 *   2) 已登录后台的管理员直接访问该地址
 *
 * 建议频率：每天 1-2 次。宝塔「计划任务 → 访问 URL」即可。
 * 输出为纯文本，方便写入 cron 日志。
 */

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/Services.php';

@ini_set('display_errors', '0');
@set_time_limit(900);

// v2.19 任务2：visit_logs 只增不删，最迟要有个兜底清理窗口。
// 保留天数集中在这里，改这一个常量即可。仅清 visit_logs，不动 visitor_stats 汇总表。
$logKeepDays = 90;

header('Content-Type: text/plain; charset=utf-8');
header('Cache-Control: no-store, max-age=0');
header('X-Robots-Tag: noindex, nofollow');

$functions = new Functions();
$db = Database::getInstance()->getConnection();

$isAdmin = $functions->isLoggedIn();
$providedKey = trim((string) ($_GET['key'] ?? ''));
$storedKey = trim((string) $functions->getSetting('cron_key'));

if (!$isAdmin) {
    if ($storedKey === '' || $providedKey === '' || !hash_equals($storedKey, $providedKey)) {
        http_response_code(403);
        echo "禁止访问：需要管理员登录，或在后台生成定时密钥后以 ?key= 方式访问。\n";
        exit;
    }
}

$startedAt = microtime(true);
echo '[' . date('Y-m-d H:i:s') . "] 开始链接健康检测\n";

$health = new HealthCheckService($functions);
$mailer = new MailService($functions);

// 需要检测的主体：公共导航（0）+ 所有有私有导航的用户
$owners = [0];

try {
    $stmt = $db->query(
        'SELECT DISTINCT user_id FROM nav_links
         WHERE user_id > 0 AND is_active = 1 AND deleted_at IS NULL ORDER BY user_id'
    );

    foreach ($stmt->fetchAll() as $row) {
        $owners[] = (int) $row['user_id'];
    }
} catch (Throwable $error) {
    echo "读取用户列表失败，仅检测公共导航：" . $error->getMessage() . "\n";
}

$totalChecked = 0;
$totalDown = 0;
$downList = [];

foreach ($owners as $owner) {
    $summary = $health->checkAll($owner);

    $totalChecked += $summary['total'];
    $totalDown += $summary['down'];

    // v2.18.1 · 「跳过」单独计数：内网 / 手动 skip_check 的条目不计入失败
    echo sprintf(
        "  导航 #%d：共 %d 条，正常 %d，较慢 %d，失败 %d，跳过 %d\n",
        $owner,
        $summary['total'],
        $summary['ok_count'],
        $summary['slow'],
        $summary['down'],
        $summary['skipped'] ?? 0
    );

    foreach ($summary['items'] as $item) {
        if ($item['status'] === 'down') {
            $downList[] = sprintf('[#%d] %s (%s)', $owner, $item['name'], $item['url']);
        }
    }
}

echo "检测完成：共 {$totalChecked} 条，失败 {$totalDown} 条\n";

$functions->logMessage(sprintf(
    '[cron:daily_check] 共检测 %d 条，失败 %d 条',
    $totalChecked,
    $totalDown
));

// 失效告警邮件（需 config/smtp.php 已启用，且未关闭告警）
$alertEnabled = $functions->getSetting('health_alert_enabled') !== '0';

if ($downList && $alertEnabled) {
    if ($mailer->enabled() && $mailer->adminEmail() !== '') {
        $body = "以下链接当前无法访问：\n\n" . implode("\n", array_slice($downList, 0, 100));

        if (count($downList) > 100) {
            $body .= "\n\n（仅列出前 100 条，共 " . count($downList) . " 条）";
        }

        $sent = $mailer->notifyAdmin('有 ' . count($downList) . ' 个链接无法访问', $body);
        echo $sent ? "已发送失效告警邮件\n" : "告警邮件发送失败，详见 logs/system.log\n";
    } else {
        echo "存在失效链接，但未配置 SMTP，跳过邮件告警\n";
    }
} elseif ($downList) {
    echo "存在失效链接，但已关闭告警邮件\n";
}

// v2.19 任务2：清理 visit_logs 中超过保留期的旧记录。
// visited_at 已有 idx_visited_at 索引，WHERE visited_at < ? 走索引删除。
// 清理失败只写日志、继续返回，绝不中断上面的检测与告警主流程。
try {
    $cutoff = date('Y-m-d H:i:s', time() - 86400 * $logKeepDays);
    $cleanupStmt = $db->prepare('DELETE FROM visit_logs WHERE visited_at < ?');
    $cleanupStmt->execute([$cutoff]);
    $cleaned = $cleanupStmt->rowCount();
    echo "访问日志清理：删除 {$logKeepDays} 天前的记录 {$cleaned} 条\n";
    $functions->logMessage(sprintf(
        '[cron:daily_check] 清理 visit_logs：%d 天前 %d 条（cutoff=%s）',
        $logKeepDays,
        $cleaned,
        $cutoff
    ));
} catch (Throwable $cleanupError) {
    // 老库可能没有 visit_logs 表；清理失败不影响检测主流程
    echo "访问日志清理失败（已忽略，不影响检测）：" . $cleanupError->getMessage() . "\n";
    $functions->logMessage('[cron:daily_check] 清理 visit_logs 失败：' . $cleanupError->getMessage());
}

echo '耗时 ' . round(microtime(true) - $startedAt, 2) . " 秒\n";
