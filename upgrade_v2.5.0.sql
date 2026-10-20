-- ==========================================================================
-- 轩宇-导航 · v2.5.0 升级脚本（MySQL 5.7 / utf8mb4）
-- 适用：从 v2.4.x 升级到 v2.5.0
--   - nav_links 表新增 alias（别名）字段
--   - nav_links 表新增 tags（标签）字段
--
-- 说明：
--   * 两个字段均用于全局搜索与搜索联想的检索范围（名称/别名/标签/简介/网址/分类），
--     可在后台「导航管理 → 编辑」中填写；留空不影响任何现有功能。
--
-- 本脚本是【幂等】的：可反复执行，已存在的列会被自动跳过。
-- 实现方式：information_schema 判断 + PREPARE 动态执行 DDL。
--
-- 执行方式（任选其一）：
--   1) phpMyAdmin：选中目标库 → 导入 → 选择本文件
--   2) 命令行：mysql -u用户 -p 数据库名 < upgrade_v2.5.0.sql
--
-- 建议：执行前先备份数据库。
-- ==========================================================================

SET NAMES utf8mb4;

-- --------------------------------------------------------------------------
-- 1. nav_links 表新增 alias 列（网址别名，多个词用空格分隔，用于搜索联想）
-- --------------------------------------------------------------------------
SET @nav_col_exists = (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'nav_links' AND COLUMN_NAME = 'alias'
);
SET @nav_sql = IF(@nav_col_exists = 0,
  'ALTER TABLE `nav_links` ADD COLUMN `alias` varchar(255) DEFAULT NULL AFTER `remark`',
  'SET @nav_noop = 1'
);
PREPARE nav_stmt FROM @nav_sql;
EXECUTE nav_stmt;
DEALLOCATE PREPARE nav_stmt;

-- --------------------------------------------------------------------------
-- 2. nav_links 表新增 tags 列（网址标签，多个标签用空格分隔，用于搜索联想）
-- --------------------------------------------------------------------------
SET @nav_col_exists = (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'nav_links' AND COLUMN_NAME = 'tags'
);
SET @nav_sql = IF(@nav_col_exists = 0,
  'ALTER TABLE `nav_links` ADD COLUMN `tags` varchar(255) DEFAULT NULL AFTER `alias`',
  'SET @nav_noop = 1'
);
PREPARE nav_stmt FROM @nav_sql;
EXECUTE nav_stmt;
DEALLOCATE PREPARE nav_stmt;

-- ==========================================================================
-- 升级完成
-- ==========================================================================
