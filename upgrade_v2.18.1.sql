-- ==========================================================================
-- 轩宇-导航 · v2.18.1 升级脚本
-- 内容：
--   1. check_status 增加 'skipped' 取值（内网 / 跳过检测）
--   2. 新增 skip_check 标记列
--   3. 既有内网地址一次性标记为跳过
-- 说明：可重复执行（列/枚举已是目标状态时对应语句会报错或空转，可忽略）。
-- 只加 skip_check 而不改枚举的话，写 'skipped' 会被 MySQL 截断成空串，
-- 前台就退化成「未知状态」而不是灰色「内网」，所以两步都要执行。
-- ==========================================================================

ALTER TABLE `nav_links`
    MODIFY COLUMN `check_status` ENUM('unknown','ok','slow','down','skipped') NOT NULL DEFAULT 'unknown';

ALTER TABLE `nav_links`
    ADD COLUMN `skip_check` TINYINT(1) NOT NULL DEFAULT 0 COMMENT '1=跳过可用性检测（内网/常关设备）';

-- 既有站点的内网地址一次性标记为跳过，避免升级后仍被刷成 down
UPDATE `nav_links`
SET `skip_check` = 1, `check_status` = 'skipped', `latency_ms` = NULL
WHERE `url` REGEXP '^https?://(localhost|127\\.|10\\.|192\\.168\\.|169\\.254\\.|172\\.(1[6-9]|2[0-9]|3[01])\\.)';
