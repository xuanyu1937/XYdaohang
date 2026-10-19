-- 轩宇-导航 v1.1.3 数据库升级
-- MySQL 5.7 兼容。仅需执行一次。
-- 作用：把管理员密码列扩宽到可存放 password_hash（bcrypt 60 字符）。
-- 如果后台登录后提示密码列升级失败，请在 phpMyAdmin 中执行本文件。

ALTER TABLE `admins`
    MODIFY `password` VARCHAR(255) NOT NULL;

-- 可选：确认 nav_links 存在 clicks 列（旧库若缺失则补上）
-- ALTER TABLE `nav_links` ADD COLUMN `clicks` INT(11) DEFAULT 0;
-- ALTER TABLE `nav_links` ADD KEY `idx_clicks` (`clicks`);
