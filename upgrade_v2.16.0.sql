-- ==========================================================================
-- 轩宇-导航 · v2.16.0 升级脚本
--
-- 本版本不新增任何表和字段，只补两个「高频查询组合」的索引：
--   1) nav_links (user_id, deleted_at, is_active)
--      首页 / 我的导航 / 后台列表都是「某人的、未删除的、已启用」三个条件同时出现，
--      单列索引只能命中其中一个，组合索引可以一次定位，避免回表扫描整张表。
--   2) categories (user_id, is_private)
--      访客视图需要过滤私密分组，用户视图需要「公共 + 自己」，同样是两列一起用。
--
-- 写法与 v2.1.0 保持一致：先查 information_schema，不存在才建，重复执行安全。
-- MySQL 5.6+ / MariaDB 10.x 均可执行。
-- ==========================================================================

-- --------------------------------------------------------------------------
-- 1. nav_links 组合索引
-- --------------------------------------------------------------------------
SET @nav_idx_exists = (
  SELECT COUNT(*) FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'nav_links'
    AND INDEX_NAME = 'idx_user_deleted_active'
);
SET @nav_sql = IF(@nav_idx_exists = 0,
  'ALTER TABLE `nav_links` ADD INDEX `idx_user_deleted_active` (`user_id`, `deleted_at`, `is_active`)',
  'SET @nav_noop = 1'
);
PREPARE nav_stmt FROM @nav_sql;
EXECUTE nav_stmt;
DEALLOCATE PREPARE nav_stmt;

-- --------------------------------------------------------------------------
-- 2. categories 组合索引
-- --------------------------------------------------------------------------
SET @nav_idx_exists = (
  SELECT COUNT(*) FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'categories'
    AND INDEX_NAME = 'idx_user_private'
);
SET @nav_sql = IF(@nav_idx_exists = 0,
  'ALTER TABLE `categories` ADD INDEX `idx_user_private` (`user_id`, `is_private`)',
  'SET @nav_noop = 1'
);
PREPARE nav_stmt FROM @nav_sql;
EXECUTE nav_stmt;
DEALLOCATE PREPARE nav_stmt;

SELECT 'v2.16.0 升级完成：已补齐 nav_links / categories 组合索引' AS result;
