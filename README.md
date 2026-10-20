# 轩宇-导航

网址导航站：前台为原生 HTML + CSS + JS，后台为 PHP 8.1 + MySQL 5.7（Bootstrap 5.3）。
v2.0.0 起支持前台用户注册与「我的导航」，访客未登录时展示后台指定的默认视图。v2.0.1 为 bug 修复版本。v2.1.0 新增私密分组、用户头像、GitHub 版本检测等功能。v2.2.0 新增用户自主分组、忘记密码、搜索引擎跳转与拼音搜索、申请收录入口等。v2.2.1 修复未登录访客越权看到站长私密链接、搜索框按钮重叠、抓取频繁失败、账号设置缺少头像入口、公告提示不明确等问题。v2.2.2 修复版本检测「无法连接版本服务器」故障（新增 raw VERSION 兜底链路）并支持上传更新包在线更新。v2.2.3 修复公告短内容不显示与未登录头像样式异常。

## 运行要求
- PHP 8.1（需 PDO_MySQL，建议启用 curl、mbstring、fileinfo）
- MySQL 5.7（utf8mb4）
- IIS 或 Apache；IIS 使用根目录 `web.config`，Apache 使用根目录 `htaccess`（部署时改名为 `.htaccess`）

> 说明：部分主机未启用 mbstring 时功能仍可运行（内部已做兜底），但中文截断只能按字节近似处理，仍建议开启。

## 当前版本
- `VERSION` / `config/version.php`：**2.2.3**
- 完整变更记录见 [CHANGELOG.md](CHANGELOG.md)

## 从 v1.1.x 升级到 v2.0.0
1. **先备份数据库**（导出 SQL 或整库快照）。
2. 上传 v2.0.0 文件覆盖旧版本（不要上传 `backups/`）。
3. 用 phpMyAdmin / 命令行执行 `upgrade_v2.0.0.sql`。
   - 该脚本是**幂等**的：只新增表、列、索引与设置项，不删除也不改写任何既有数据；
     重复执行不会报错，已存在的内容会自动跳过，因此中断后可以放心再跑一次。
   - 命令行方式：`mysql -u用户 -p 数据库名 < upgrade_v2.0.0.sql`
4. 刷新后台，进入「系统设置 → 前台体验」按需开启公告、注册、联系页等；进入「系统工具 → 定时任务」生成定时密钥。
5. 前台强制刷新（CSS/JS 有更新，浏览器可能仍用旧缓存）。

## 从 v2.0.x 升级到 v2.1.0
1. **先备份数据库**（导出 SQL 或整库快照）。
2. 上传 v2.1.0 文件覆盖旧版本。
3. 用 phpMyAdmin / 命令行执行 `upgrade_v2.1.0.sql`。
   - 该脚本是**幂等**的：仅新增 `users.avatar`、`categories.user_id`、`categories.is_private` 三列及索引，不删除任何数据。
   - 命令行方式：`mysql -u用户 -p 数据库名 < upgrade_v2.1.0.sql`
4. 刷新后台，进入「分类管理」可为分组设置归属用户与私密标记；进入「系统工具 → 版本检测」可检查 GitHub 最新 Release。

## 从 v2.1.x 升级到 v2.2.x
1. **先备份数据库**（导出 SQL 或整库快照）。
2. 上传 v2.2.x 文件覆盖旧版本。
3. 用 phpMyAdmin / 命令行执行 `upgrade_v2.2.0.sql`（v2.2.1 无新增表结构变更，已执行过可跳过）。
   - 该脚本是**幂等**的：仅新增 `users.reset_token_hash`、`users.reset_expires` 两列及索引，不删除任何数据。
   - 命令行方式：`mysql -u用户 -p 数据库名 < upgrade_v2.2.0.sql`
4. 若需「忘记密码」邮件重置，进入「系统工具 → 邮件设置与测试」填写并启用 SMTP 配置。

## 目录说明
- `index.php`：前台导航首页（左侧分类索引 + 分组卡片 + 热门区）
- `login.php` / `register.php` / `logout.php` / `my.php`：前台用户登录、注册、退出、个人导航管理
- `share.php`：分享链接预览页（可一键导入他人导航）
- `about.php` / `contact.php`：关于页与联系留言页
- `install.php`：全新服务器安装向导（安装后删除）
- `detail.php`：旧版详情页（保留兼容）
- `apply.php` / `apply(1).php`：收录申请入口（自动抓取已关闭）
- `admin/`：后台管理（含 `users.php` 用户管理、`stats.php` 数据统计、`tools.php` 系统工具）
- `cron/daily_check.php`：链接健康检测定时任务（按 `settings.cron_key` 鉴权）
- `api/`：
  - `site_meta.php`：前台标题异步抓取（公网地址、无跳转、超时、缓存）
  - `track_click.php`：点击量统计（v2 起同时写入 `visit_logs`）
  - `auth.php` / `user_sites.php`：前台用户认证与个人导航增删改排序
  - `share.php` / `bookmark_import.php`：分享生成与导入、浏览器书签导入
  - `contact.php`：联系留言
  - `ai_suggest.php` / `health_check.php` / `stats.php`：后台抓取推荐、健康检测、统计
  - `sites.php`：开放 REST API（Bearer Token 鉴权）
- `assets/css/nav.css` + `assets/js/nav.js`：前台主题、显示方式、索引栏与交互
- `assets/css/auth.css`：前台登录 / 注册页样式
- `assets/css/page.css`：分享 / 关于 / 联系页通用样式
- `assets/`（后台依赖）：jQuery 3.7.1、Bootstrap 5.3.3 bundle、SweetAlert2 11、DataTables 1.13.4（含中文语言包）、Chart.js 4.3.0、bootstrap-datepicker 1.9.0、Font Awesome 6.4（含 webfonts）、qrcode、Tailwind 运行时（供 detail.php/apply(1).php 两个旧页）
  - 后台所需的第三方资源**已全部本地化**，服务器无外网也能正常使用后台；
  - 页面中保留的 `cdn.*` / `cdnjs` 地址只在本地文件加载失败时兜底触发。
- `includes/`：公共函数、配置、模板解析、服务层（`Services.php`）
- `config/`：站点、数据库、邮件、版本配置
- `backups/`：**本地源码备份，不要上传到线上**
- `install/`：安装用 SQL 与锁文件

## 开放 API（v2 新增）
1. 后台「系统工具 → API 令牌管理」创建令牌，选择权限与过期时间（令牌明文只在创建时显示一次）。
2. 调用示例：
   ```bash
   curl -H "Authorization: Bearer <token>" "https://你的域名/api/sites.php?page=1&size=20"
   ```
3. 权限与范围：
   - `read` 只能读取，`write` 可增删改。
   - 令牌的 `user_id` 为 0 时是**管理级**：可读写公共导航与全部条目。
   - 令牌的 `user_id` 大于 0 时：只能读写该用户自己的条目。
4. 接口：`GET` 列表 / 详情（`?id=`）、`POST` 创建、`PUT` 更新、`DELETE` 删除。请求体支持 JSON 与表单编码。
5. Apache/IIS 的 `/api/sites` 短地址重写规则已写在 `htaccess` / `web.config` 中（可选，也可直接用 `/api/sites.php`）。

## 定时任务
- 地址：`/cron/daily_check.php?key=<定时密钥>`（密钥在「系统工具 → 定时任务」生成）
- 宝塔配置：`计划任务 → 访问 URL`，建议每天 1~2 次。
- 已登录后台的管理员直接访问该地址也可执行。
- 检测结果会写入 `nav_links.check_status` / `latency_ms` / `last_checked_at`；存在失效链接且配置了 SMTP 时会发送告警邮件（可在「系统设置 → 前台体验」关闭告警）。

## 安全说明
- 远程更新、调试页已移除；后台入口仅保留登录页。
- 后台密码使用 `password_hash`；首次用旧明文密码登录会自动迁移，并尝试将 `admins.password` 扩宽为 `varchar(255)`。
- 后台登录支持双源：`admins` 表优先，其次 `users` 表中 `is_admin = 1` 的账号（会话记录 `admin_source`）。
- 前台用户密码同样使用 `password_hash`，长度 8-72 位；封禁用户无法登录且会被自动登出。
- 所有写操作（后台、前台接口）均有同源校验；Session 使用 HttpOnly + SameSite=Lax。
- 上传仅允许 JPG/PNG/GIF/WEBP/ICO，并校验 MIME 与图片魔数；禁止 SVG。
- 数据库口令支持 `NAV_DB_HOST`、`NAV_DB_NAME`、`NAV_DB_USER`、`NAV_DB_PASS` 环境变量覆盖；建议轮换原口令后移除源码中的回退值。
- `config/`、`includes/`、`install/`、`backups/`、`logs/`、`cache/`、`vendor/` 已通过 IIS/Apache 规则禁止直访。
- 备份文件（`系统工具 → 数据备份` 导出的 JSON）**包含用户密码哈希与 API 令牌原文**，导入时原样还原，
  因此账号与令牌可以完整恢复；请把备份文件当作敏感资料保管，不要放在网站根目录或可公开访问的位置。
- 唯一不导出的是 `settings.ai_api_key`（AI 接口密钥），恢复后需重新填写 —— 一份备份外泄不至于直接被调用付费接口。

## 部署提示
1. 不要上传 `backups/` 目录。
2. 覆盖上传后访问 `http://dh.pxyblog.top/` 并强制刷新。
3. 首次登录后台后确认密码可正常登录（会自动迁移旧密码）。
4. 若站点运行在 IIS，确认根目录 `web.config` 生效；若 IIS 报 500.19（主机锁定了 requestFiltering/customHeaders/rewrite），请删除对应节点或整个 web.config。若为 Apache，将 `htaccess` 重命名为 `.htaccess`。
5. 邮件通知默认关闭；需要时填写 `config/smtp.php` 的账号、发件地址和收件邮箱，并把 `enabled` 改为 `true`（v2 未把 SMTP 搬进数据库设置，沿用文件配置）。
6. 未执行 `upgrade_v2.0.0.sql` 时站点仍可访问，但用户系统、回收站、统计与分享相关功能会不可用或降级。

## 全新服务器
1. 上传部署包并解压到网站根目录。
2. 浏览器访问 `install.php`，在界面填写数据库、站点名称、管理员账号。
3. 安装程序自动建表（已是 v2 全量结构）、写 `config/database.local.php`、创建管理员并锁定安装。
4. 安装完成后删除 `install.php` 和 `install/` 目录。
5. 确认 `uploads/`、`cache/`、`logs/` 可写。后台用到的 jQuery / Bootstrap / DataTables / Chart.js / SweetAlert2 / Font Awesome / datepicker 已随包放在 `assets/` 下，服务器无外网也能正常使用；页面里保留的 CDN 地址仅在本地文件缺失时兜底。

## 相对产品计划书的实现说明
- 前台未引入 Vue / Tailwind 构建链：部署目标是虚拟主机与宝塔面板，没有 Node 环境，因此继续使用服务端渲染 + 原生 JS + CSS 变量主题系统。功能与计划书一致，只是实现方式不同。
- 计划书中同时提到「顶栏」与「右下角」的显示方式切换，两者均已实现：顶栏是 grid / list / compact 三选一分段控件，右下角是单键循环切换的悬浮按钮，状态双向同步。
- 计划书要求的多皮肤、日夜模式、3D 卡片、热门 TopN、公告、回顶等均已实现，其中 3D 倾斜仅在精细指针设备且用户未开启「减少动效」时启用。


