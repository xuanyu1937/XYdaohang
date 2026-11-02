-- ============================================================================
-- 轩宇导航 XYdaohang · 数据库升级脚本
-- 适用版本：v2.8.0 / v2.8.1 → v2.9.0（2.8.1 与 2.9.0 之间无表结构差异，可共用）
-- 执行方式：phpMyAdmin 选中数据库 → SQL → 整段粘贴执行
-- 幂等性：全部为 UPDATE / INSERT ... SELECT，重复执行不会产生重复数据
--
-- 【v1 脚本的 bug 说明】
--   旧版脚本误用 `a.password_hash`，而 admins 表的密码列名是 `password`，
--   故 MySQL 报 #1054 Unknown column 'a.password_hash' in 'field list'。
--   本版已修正，并补上「旧库 password 列为 varchar(50) 装不下 bcrypt 结果」的扩宽处理。
-- ============================================================================


-- ----------------------------------------------------------------------------
-- 步骤 1/5 · 修正默认 Logo
-- 原默认值指向仓库里不存在的 logo.png，导致全站 404 与破图。
-- 只在「用户从未自定义过 Logo」时才覆盖，避免冲掉已上传的图片。
-- ----------------------------------------------------------------------------
UPDATE `settings`
   SET `value` = 'assets/img/logo.svg'
 WHERE `key` = 'site_logo'
   AND (`value` IS NULL OR `value` = '' OR `value` = 'logo.png');


-- ----------------------------------------------------------------------------
-- 步骤 2/5 · 扩宽 admins.password
-- 旧库该列是 varchar(50)，而 password_hash() 输出 60 字符，
-- 不扩宽会导致后续密码迁移被截断（严格模式下报 #1406 Data too long）。
-- 已经是 varchar(255) 的库执行本句无副作用。
-- ----------------------------------------------------------------------------
ALTER TABLE `admins` MODIFY `password` VARCHAR(255) NOT NULL;


-- ----------------------------------------------------------------------------
-- 步骤 3/5 · 把「只存在于 admins 表」的管理员补建到 users 表
-- 早期安装程序只写 admins，导致前台无法登录、首页看不到站点。
--
-- 注意：这里只复制「已经是 bcrypt / argon2 哈希」的密码。
-- 若老站存的是明文或 32 位 md5，则跳过不插（插入空密码只会让人更困惑），
-- 改由 PHP 端登录时自动识别并补建（Services::adoptAdminAccount）。
-- ----------------------------------------------------------------------------
INSERT INTO `users` (`email`, `username`, `password_hash`, `is_admin`, `is_default_user`, `status`, `created_at`)
SELECT CONCAT(a.`username`, '@local.invalid'),
       a.`username`,
       a.`password`,
       1,
       0,
       'active',
       NOW()
  FROM `admins` a
  LEFT JOIN `users` u
         ON u.`username` = a.`username`
         OR u.`email` = CONCAT(a.`username`, '@local.invalid')
 WHERE u.`id` IS NULL
   AND a.`password` LIKE '$2y$%';


-- ----------------------------------------------------------------------------
-- 步骤 4/5 · 同步管理员标记
-- users 里已存在同名账号的，强制置 is_admin = 1（后台与前台权限打通）。
-- ----------------------------------------------------------------------------
UPDATE `users` u
  JOIN `admins` a ON a.`username` = u.`username`
   SET u.`is_admin` = 1
 WHERE u.`is_admin` = 0;


-- ----------------------------------------------------------------------------
-- 步骤 5/5 · 纠正 default_user_id
-- 该值指向不存在的用户时，访客首页会空白。
-- 这里重算为「有效链接最多的用户」，保持默认视图有内容可看。
-- ----------------------------------------------------------------------------
UPDATE `settings`
   SET `value` = (
        SELECT CAST(u.`id` AS CHAR)
          FROM `users` u
          LEFT JOIN `nav_links` nl
                 ON nl.`user_id` = u.`id`
                AND nl.`is_active` = 1
         GROUP BY u.`id`
         ORDER BY COUNT(nl.`id`) DESC, u.`id` ASC
         LIMIT 1
   )
 WHERE `key` = 'default_user_id'
   AND EXISTS (SELECT 1 FROM `users`)
   AND NOT EXISTS (
        SELECT 1 FROM `users` u2
         WHERE u2.`id` = CAST(`settings`.`value` AS UNSIGNED)
   );

-- 若 settings 里根本没有 default_user_id 这一行，补一条。
INSERT INTO `settings` (`key`, `value`)
SELECT 'default_user_id', CAST(u.`id` AS CHAR)
  FROM `users` u
  LEFT JOIN `nav_links` nl
         ON nl.`user_id` = u.`id`
        AND nl.`is_active` = 1
 WHERE NOT EXISTS (SELECT 1 FROM `settings` s WHERE s.`key` = 'default_user_id')
 GROUP BY u.`id`
 ORDER BY COUNT(nl.`id`) DESC, u.`id` ASC
 LIMIT 1;


-- ============================================================================
-- 执行完毕后请确认（把下面的查询单独跑一遍即可）：
--   SELECT `key`, `value` FROM `settings` WHERE `key` IN ('site_logo', 'default_user_id');
--   SELECT `id`, `username`, `is_admin`, `is_default_user`, `status` FROM `users`;
-- 期望：site_logo = assets/img/logo.svg
--       default_user_id 指向一个真实存在的 users.id
--       原管理员账号在 users 表中存在且 is_admin = 1
-- ============================================================================
