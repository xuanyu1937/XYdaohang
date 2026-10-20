-- ==========================================================================
-- 轩宇-导航 · v2.3.0 升级脚本（MySQL 5.7 / utf8mb4）
-- 适用：从 v2.2.x 升级到 v2.3.0
--   - users 表新增 footer_copyright 字段（用户自定义底部版权）
--
-- 说明：
--   * 分组排序复用 categories.sort_order 既有字段，无需改表；
--   * 全站底部版权使用 settings 表的 footer_copyright 键，保存时自动写入，无需改表。
--
-- 本脚本是【幂等】的：可反复执行，已存在的列会被自动跳过。
-- 实现方式：information_schema 判断 + PREPARE 动态执行 DDL。
--
-- 执行方式（任选其一）：
--   1) phpMyAdmin：选中目标库 → 导入 → 选择本文件
--   2) 命令行：mysql -u用户 -p 数据库名 < upgrade_v2.3.0.sql
--
-- 建议：执行前先备份数据库。
-- ==========================================================================

SET NAMES utf8mb4;

-- --------------------------------------------------------------------------
-- 1. users 表新增 footer_copyright 列（用户自定义底部版权文字，支持 {year}/{site} 占位符）
-- --------------------------------------------------------------------------
SET @nav_col_exists = (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'footer_copyright'
);
SET @nav_sql = IF(@nav_col_exists = 0,
  'ALTER TABLE `users` ADD COLUMN `footer_copyright` varchar(120) DEFAULT NULL AFTER `avatar`',
  'SET @nav_noop = 1'
);
PREPARE nav_stmt FROM @nav_sql;
EXECUTE nav_stmt;
DEALLOCATE PREPARE nav_stmt;

-- --------------------------------------------------------------------------
-- 2. settings 表预置 footer_copyright 键（空值 = 使用默认「© {year} {site}」）
-- --------------------------------------------------------------------------
INSERT INTO `settings` (`key`, `value`)
VALUES ('footer_copyright', '')
ON DUPLICATE KEY UPDATE `value` = `value`;

-- ==========================================================================
-- 升级完成
-- ==========================================================================
