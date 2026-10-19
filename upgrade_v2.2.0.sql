-- ==========================================================================
-- 轩宇-导航 · v2.2.0 升级脚本（MySQL 5.7 / utf8mb4）
-- 适用：从 v2.1.0 升级到 v2.2.0
--   - users 表新增 reset_token_hash / reset_expires 字段（忘记密码邮件重置）
--
-- 本脚本是【幂等】的：可反复执行，已存在的列会被自动跳过。
-- 实现方式：information_schema 判断 + PREPARE 动态执行 DDL。
--
-- 执行方式（任选其一）：
--   1) phpMyAdmin：选中目标库 → 导入 → 选择本文件
--   2) 命令行：mysql -u用户 -p 数据库名 < upgrade_v2.2.0.sql
--
-- 建议：执行前先备份数据库。
-- ==========================================================================

SET NAMES utf8mb4;

-- --------------------------------------------------------------------------
-- 1. users 表新增 reset_token_hash 列（密码重置令牌 SHA-256 哈希）
-- --------------------------------------------------------------------------
SET @nav_col_exists = (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'reset_token_hash'
);
SET @nav_sql = IF(@nav_col_exists = 0,
  'ALTER TABLE `users` ADD COLUMN `reset_token_hash` varchar(64) DEFAULT NULL AFTER `avatar`',
  'SET @nav_noop = 1'
);
PREPARE nav_stmt FROM @nav_sql;
EXECUTE nav_stmt;
DEALLOCATE PREPARE nav_stmt;

-- --------------------------------------------------------------------------
-- 2. users 表新增 reset_expires 列（重置令牌过期时间，Unix 时间戳）
-- --------------------------------------------------------------------------
SET @nav_col_exists = (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'reset_expires'
);
SET @nav_sql = IF(@nav_col_exists = 0,
  'ALTER TABLE `users` ADD COLUMN `reset_expires` int(11) DEFAULT NULL AFTER `reset_token_hash`',
  'SET @nav_noop = 1'
);
PREPARE nav_stmt FROM @nav_sql;
EXECUTE nav_stmt;
DEALLOCATE PREPARE nav_stmt;

-- 为 reset_token_hash 建索引，加速按令牌查找
SET @nav_idx_exists = (
  SELECT COUNT(*) FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND INDEX_NAME = 'idx_reset_token'
);
SET @nav_sql = IF(@nav_idx_exists = 0,
  'ALTER TABLE `users` ADD INDEX `idx_reset_token` (`reset_token_hash`)',
  'SET @nav_noop = 1'
);
PREPARE nav_stmt FROM @nav_sql;
EXECUTE nav_stmt;
DEALLOCATE PREPARE nav_stmt;

-- ==========================================================================
-- 升级完成。验证：
--   SELECT COLUMN_NAME FROM information_schema.COLUMNS
--   WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users'
--   AND COLUMN_NAME IN ('reset_token_hash','reset_expires');
-- 应返回 2 行。
-- ==========================================================================
