<?php
/**
 * 轩宇-导航 · CLI：把历史外链 logo 本地化（v2.19.4）
 *
 * 跑法：php bin/localize-icons.php
 *      Windows：php.exe bin\localize-icons.php
 *
 * 前置：nav_links 需已有 logo_remote 列（见 CHANGELOG v2.19.4 的 ALTER）。
 * 幂等：已是本地路径的行不会被 backfillExternalLogos 选中，重复跑不会重复处理。
 * 只增不改：仅把外链 logo 换成 downloads 到本地后的路径，原值存入 logo_remote 供回滚。
 */
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/Services.php';

$functions = new Functions();
$service = new IconCacheService($functions);
$total = 0; $ok = 0; $fail = 0;
$skipIds = [];   // v2.19.4：失败条目本轮跳过，避免队头卡死反复重试

while (true) {
    $res = $service->backfillExternalLogos(10, 0, $skipIds);
    $total += (int) $res['processed'];
    $ok    += (int) $res['success'];
    $fail  += count($res['failed']);
    foreach ($res['failed'] as $f) {
        fwrite(STDERR, sprintf("FAIL id=%d host=%s err=%s\n", $f['id'], $f['host'], $f['error']));
        if (!empty($f['id'])) {
            $skipIds[] = (int) $f['id'];
        }
    }
    if ($res['done'] || $res['processed'] === 0) { break; }
    usleep(500000);
}

echo "processed={$total} success={$ok} fail={$fail}\n";
