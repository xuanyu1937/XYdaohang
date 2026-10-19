-- ==========================================================================
-- 轩宇-导航 · v2.0.0 升级脚本（MySQL 5.7 / utf8mb4）
-- 适用：从 v1.1.x 升级到 v2.0.0（多用户、回收站、健康检测、分享、开放 API 等）
--
-- 本脚本是【幂等】的：可以反复执行，已存在的表 / 列 / 索引会被自动跳过，
-- 不会因为「Duplicate column name」中断，也不会删除或改写任何既有数据。
--
-- 实现方式：MySQL 5.7 没有 ADD COLUMN IF NOT EXISTS，因此先用
-- information_schema 判断，再用 PREPARE 动态执行 DDL；
-- 已存在时执行 SET @nav_noop = 1 作为空操作。
-- 这段写法不依赖 DELIMITER，phpMyAdmin / 命令行 / 各类导入工具都能直接跑。
--
-- 执行方式（任选其一）：
--   1) phpMyAdmin：选中目标库 → 导入 → 选择本文件
--   2) 命令行：mysql -u用户 -p 数据库名 < upgrade_v2.0.0.sql
--
-- 建议：执行前先备份数据库。
-- ==========================================================================

SET NAMES utf8mb4;

-- --------------------------------------------------------------------------
-- 1. 新表（CREATE TABLE IF NOT EXISTS 本身可重复执行）
-- --------------------------------------------------------------------------

-- 前台用户表
CREATE TABLE IF NOT EXISTS `users` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `email` varchar(190) NOT NULL,
  `username` varchar(50) NOT NULL,
  `password_hash` varchar(255) NOT NULL,
  `is_admin` tinyint(1) DEFAULT '0',
  `is_default_user` tinyint(1) DEFAULT '0',
  `status` enum('active','banned') NOT NULL DEFAULT 'active',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `last_login` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `email` (`email`),
  UNIQUE KEY `username` (`username`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 点击日志（热门排行 / 访问趋势）
CREATE TABLE IF NOT EXISTS `visit_logs` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `site_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL DEFAULT '0',
  `ip` varchar(45) DEFAULT NULL,
  `visited_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_site_id` (`site_id`),
  KEY `idx_visited_at` (`visited_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 开放 API 令牌
CREATE TABLE IF NOT EXISTS `api_tokens` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `token` varchar(64) NOT NULL,
  `name` varchar(100) NOT NULL,
  `permissions` varchar(20) NOT NULL DEFAULT 'read',
  `user_id` int(11) NOT NULL DEFAULT '0',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `expires_at` datetime DEFAULT NULL,
  `last_used_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `token` (`token`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 分享链接
CREATE TABLE IF NOT EXISTS `shares` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `token` varchar(32) NOT NULL,
  `user_id` int(11) NOT NULL,
  `title` varchar(200) DEFAULT '',
  `site_ids` text,
  `views` int(11) NOT NULL DEFAULT '0',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `token` (`token`),
  KEY `idx_user_id` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 联系管理员留言
CREATE TABLE IF NOT EXISTS `contact_messages` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL DEFAULT '',
  `email` varchar(190) NOT NULL DEFAULT '',
  `subject` varchar(200) NOT NULL DEFAULT '',
  `message` text,
  `ip` varchar(45) DEFAULT NULL,
  `is_read` tinyint(1) NOT NULL DEFAULT '0',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------------------------
-- 2. categories：二级分类 / 图标 / 备注
-- --------------------------------------------------------------------------

SET @nav_ddl = (SELECT IF(COUNT(*) = 0,
  'ALTER TABLE `categories` ADD COLUMN `parent_id` int(11) DEFAULT NULL AFTER `id`',
  'SET @nav_noop = 1')
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'categories' AND COLUMN_NAME = 'parent_id');
PREPARE nav_stmt FROM @nav_ddl;
EXECUTE nav_stmt;
DEALLOCATE PREPARE nav_stmt;

SET @nav_ddl = (SELECT IF(COUNT(*) = 0,
  'ALTER TABLE `categories` ADD COLUMN `icon` varchar(100) DEFAULT NULL AFTER `name`',
  'SET @nav_noop = 1')
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'categories' AND COLUMN_NAME = 'icon');
PREPARE nav_stmt FROM @nav_ddl;
EXECUTE nav_stmt;
DEALLOCATE PREPARE nav_stmt;

SET @nav_ddl = (SELECT IF(COUNT(*) = 0,
  'ALTER TABLE `categories` ADD COLUMN `remark` varchar(255) DEFAULT NULL AFTER `sort_order`',
  'SET @nav_noop = 1')
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'categories' AND COLUMN_NAME = 'remark');
PREPARE nav_stmt FROM @nav_ddl;
EXECUTE nav_stmt;
DEALLOCATE PREPARE nav_stmt;

-- --------------------------------------------------------------------------
-- 3. nav_links：多用户归属 / 备注 / 健康检测 / 回收站
--    user_id = 0 表示公共导航，> 0 表示用户私有导航
-- --------------------------------------------------------------------------

SET @nav_ddl = (SELECT IF(COUNT(*) = 0,
  'ALTER TABLE `nav_links` ADD COLUMN `user_id` int(11) NOT NULL DEFAULT 0 AFTER `category_id`',
  'SET @nav_noop = 1')
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'nav_links' AND COLUMN_NAME = 'user_id');
PREPARE nav_stmt FROM @nav_ddl;
EXECUTE nav_stmt;
DEALLOCATE PREPARE nav_stmt;

SET @nav_ddl = (SELECT IF(COUNT(*) = 0,
  'ALTER TABLE `nav_links` ADD COLUMN `remark` varchar(255) DEFAULT NULL AFTER `description`',
  'SET @nav_noop = 1')
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'nav_links' AND COLUMN_NAME = 'remark');
PREPARE nav_stmt FROM @nav_ddl;
EXECUTE nav_stmt;
DEALLOCATE PREPARE nav_stmt;

SET @nav_ddl = (SELECT IF(COUNT(*) = 0,
  'ALTER TABLE `nav_links` ADD COLUMN `check_status` enum(''unknown'',''ok'',''slow'',''down'') NOT NULL DEFAULT ''unknown'' AFTER `is_active`',
  'SET @nav_noop = 1')
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'nav_links' AND COLUMN_NAME = 'check_status');
PREPARE nav_stmt FROM @nav_ddl;
EXECUTE nav_stmt;
DEALLOCATE PREPARE nav_stmt;

SET @nav_ddl = (SELECT IF(COUNT(*) = 0,
  'ALTER TABLE `nav_links` ADD COLUMN `latency_ms` int(11) DEFAULT NULL AFTER `check_status`',
  'SET @nav_noop = 1')
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'nav_links' AND COLUMN_NAME = 'latency_ms');
PREPARE nav_stmt FROM @nav_ddl;
EXECUTE nav_stmt;
DEALLOCATE PREPARE nav_stmt;

SET @nav_ddl = (SELECT IF(COUNT(*) = 0,
  'ALTER TABLE `nav_links` ADD COLUMN `last_checked_at` timestamp NULL DEFAULT NULL AFTER `latency_ms`',
  'SET @nav_noop = 1')
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'nav_links' AND COLUMN_NAME = 'last_checked_at');
PREPARE nav_stmt FROM @nav_ddl;
EXECUTE nav_stmt;
DEALLOCATE PREPARE nav_stmt;

SET @nav_ddl = (SELECT IF(COUNT(*) = 0,
  'ALTER TABLE `nav_links` ADD COLUMN `deleted_at` timestamp NULL DEFAULT NULL AFTER `updated_at`',
  'SET @nav_noop = 1')
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'nav_links' AND COLUMN_NAME = 'deleted_at');
PREPARE nav_stmt FROM @nav_ddl;
EXECUTE nav_stmt;
DEALLOCATE PREPARE nav_stmt;

-- --------------------------------------------------------------------------
-- 4. 索引（同样先判断再创建）
-- --------------------------------------------------------------------------

SET @nav_ddl = (SELECT IF(COUNT(*) = 0,
  'ALTER TABLE `nav_links` ADD KEY `idx_user_id` (`user_id`)',
  'SET @nav_noop = 1')
  FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'nav_links' AND INDEX_NAME = 'idx_user_id');
PREPARE nav_stmt FROM @nav_ddl;
EXECUTE nav_stmt;
DEALLOCATE PREPARE nav_stmt;

SET @nav_ddl = (SELECT IF(COUNT(*) = 0,
  'ALTER TABLE `nav_links` ADD KEY `idx_deleted_at` (`deleted_at`)',
  'SET @nav_noop = 1')
  FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'nav_links' AND INDEX_NAME = 'idx_deleted_at');
PREPARE nav_stmt FROM @nav_ddl;
EXECUTE nav_stmt;
DEALLOCATE PREPARE nav_stmt;

-- --------------------------------------------------------------------------
-- 5. 新增设置项（INSERT IGNORE：已存在则跳过，可重复执行）
-- --------------------------------------------------------------------------

INSERT IGNORE INTO `settings` (`key`, `value`) VALUES
('announcement_enabled', '0'),
('announcement_content', ''),
('default_view', 'grid'),
('default_target', '_blank'),
('hot_count', '8'),
('theme_skin', 'default'),
('bg_image', ''),
('register_enabled', '1'),
('site_domain', ''),
('seo_title', ''),
('seo_description', ''),
('about_content', ''),
('default_user_id', '0'),
('ai_enabled', '0'),
('ai_api_url', ''),
('ai_api_key', ''),
('ai_model', ''),
('show_speed_label', '1'),
('contact_enabled', '1'),
('cron_key', ''),
('health_alert_enabled', '1');

-- --------------------------------------------------------------------------
-- 6. 收尾（每个 PREPARE 都已 DEALLOCATE，这里只清理临时变量）
-- --------------------------------------------------------------------------

SET @nav_noop = NULL;
SET @nav_ddl = NULL;
