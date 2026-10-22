-- 轩宇导航 v2.8.0 升级脚本：自定义搜索引擎
-- 幂等：可重复执行
-- 新增两张表：
--   search_engines       站点全局默认搜索引擎（用户未自定义时使用）
--   user_search_engines  用户自定义搜索引擎（个人独立保存）

-- 全局默认搜索引擎表
CREATE TABLE IF NOT EXISTS `search_engines` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(50) NOT NULL COMMENT '搜索引擎名称',
  `url_template` varchar(500) NOT NULL COMMENT '搜索URL模板，用 {q} 占位搜索词',
  `icon` varchar(500) DEFAULT NULL COMMENT '图标URL或emoji',
  `sort_order` int(11) NOT NULL DEFAULT 0 COMMENT '排序序号',
  `is_active` tinyint(1) NOT NULL DEFAULT 1 COMMENT '是否启用',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_sort_order` (`sort_order`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 用户自定义搜索引擎表
CREATE TABLE IF NOT EXISTS `user_search_engines` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL COMMENT '所属用户',
  `name` varchar(50) NOT NULL COMMENT '搜索引擎名称',
  `url_template` varchar(500) NOT NULL COMMENT '搜索URL模板，用 {q} 占位搜索词',
  `icon` varchar(500) DEFAULT NULL COMMENT '图标URL或emoji',
  `sort_order` int(11) NOT NULL DEFAULT 0 COMMENT '排序序号',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_user_sort` (`user_id`, `sort_order`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 插入默认搜索引擎（仅在表为空时）
INSERT INTO `search_engines` (`name`, `url_template`, `icon`, `sort_order`)
SELECT * FROM (
  SELECT '百度' AS name, 'https://www.baidu.com/s?wd={q}' AS url_template, '🔍' AS icon, 1 AS sort_order
  UNION ALL
  SELECT '必应', 'https://www.bing.com/search?q={q}', '🅱️', 2
  UNION ALL
  SELECT '谷歌', 'https://www.google.com/search?q={q}', '🌐', 3
) AS defaults
WHERE NOT EXISTS (SELECT 1 FROM `search_engines`);
