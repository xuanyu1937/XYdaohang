-- ==========================================================================
-- 轩宇-导航 · v2.6.0 升级脚本（MySQL 5.7 / utf8mb4）
-- 适用：从 v2.5.0 升级到 v2.6.0
--   - nav_links 表新增 is_starred（收藏标记）字段
--
-- 本脚本是【幂等】的：可反复执行，已存在的列会被自动跳过。
-- 执行方式（任选其一）：
--   1) phpMyAdmin：先在左侧点击选中【目标数据库】，再「导入」本文件
--   2) 命令行：mysql -u用户 -p 数据库名 < upgrade_v2.6.0.sql
--   3) 命令行（已在 mysql> 中）：USE 数据库名; 再 SOURCE upgrade_v2.6.0.sql;
--
-- 建议：执行前先备份数据库。
-- ==========================================================================

SET NAMES utf8mb4;

SET @db = DATABASE();

SET @nav_col_exists = (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'nav_links' AND COLUMN_NAME = 'is_starred'
);
SET @nav_sql = IF(@db IS NULL,
  'SELECT ''请先选择目标数据库后再执行本脚本（phpMyAdmin 左侧点选数据库，或执行 USE 库名;）'' AS error',
  IF(@nav_col_exists = 0,
    CONCAT('ALTER TABLE `', @db, '`.`nav_links` ADD COLUMN `is_starred` tinyint(1) NOT NULL DEFAULT 0 AFTER `tags`'),
    'SET @nav_noop = 1'
  )
);
PREPARE nav_stmt FROM @nav_sql;
EXECUTE nav_stmt;
DEALLOCATE PREPARE nav_stmt;

-- ==========================================================================
-- 升级完成
-- ==========================================================================
