-- ==========================================================================
-- 轩宇-导航 · v2.1.0 升级脚本（MySQL 5.7 / utf8mb4）
-- 适用：从 v2.0.x 升级到 v2.1.0
--   - users 表新增 avatar 字段（用户头像）
--   - categories 表新增 user_id（分组归属用户）、is_private（私密分组）字段
--
-- 本脚本是【幂等】的：可反复执行，已存在的列会被自动跳过。
-- 实现方式：information_schema 判断 + PREPARE 动态执行 DDL。
--
-- 执行方式（任选其一）：
--   1) phpMyAdmin：选中目标库 → 导入 → 选择本文件
--   2) 命令行：mysql -u用户 -p 数据库名 < upgrade_v2.1.0.sql
--
-- 建议：执行前先备份数据库。
-- ==========================================================================

SET NAMES utf8mb4;

-- --------------------------------------------------------------------------
-- 1. users 表新增 avatar 列（用户头像 URL / 路径）
-- --------------------------------------------------------------------------
SET @nav_col_exists = (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'avatar'
);
SET @nav_sql = IF(@nav_col_exists = 0,
  'ALTER TABLE `users` ADD COLUMN `avatar` varchar(500) DEFAULT NULL AFTER `last_login`',
  'SET @nav_noop = 1'
);
PREPARE nav_stmt FROM @nav_sql;
EXECUTE nav_stmt;
DEALLOCATE PREPARE nav_stmt;

-- --------------------------------------------------------------------------
-- 2. categories 表新增 user_id 列（分组归属用户，0 = 公共分组）
-- --------------------------------------------------------------------------
SET @nav_col_exists = (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'categories' AND COLUMN_NAME = 'user_id'
);
SET @nav_sql = IF(@nav_col_exists = 0,
  'ALTER TABLE `categories` ADD COLUMN `user_id` int(11) NOT NULL DEFAULT 0 AFTER `remark`',
  'SET @nav_noop = 1'
);
PREPARE nav_stmt FROM @nav_sql;
EXECUTE nav_stmt;
DEALLOCATE PREPARE nav_stmt;

-- 为 user_id 建索引，加速按用户筛选分组
SET @nav_idx_exists = (
  SELECT COUNT(*) FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'categories' AND INDEX_NAME = 'idx_user_id'
);
SET @nav_sql = IF(@nav_idx_exists = 0,
  'ALTER TABLE `categories` ADD INDEX `idx_user_id` (`user_id`)',
  'SET @nav_noop = 1'
);
PREPARE nav_stmt FROM @nav_sql;
EXECUTE nav_stmt;
DEALLOCATE PREPARE nav_stmt;

-- --------------------------------------------------------------------------
-- 3. categories 表新增 is_private 列（是否私密分组：0=公开 1=仅本人可见）
-- --------------------------------------------------------------------------
SET @nav_col_exists = (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'categories' AND COLUMN_NAME = 'is_private'
);
SET @nav_sql = IF(@nav_col_exists = 0,
  'ALTER TABLE `categories` ADD COLUMN `is_private` tinyint(1) NOT NULL DEFAULT 0 AFTER `user_id`',
  'SET @nav_noop = 1'
);
PREPARE nav_stmt FROM @nav_sql;
EXECUTE nav_stmt;
DEALLOCATE PREPARE nav_stmt;

-- ==========================================================================
-- 升级完成。验证：
--   SELECT COLUMN_NAME FROM information_schema.COLUMNS
--   WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ('users','categories')
--   AND COLUMN_NAME IN ('avatar','user_id','is_private');
-- 应返回 3 行。
-- ==========================================================================