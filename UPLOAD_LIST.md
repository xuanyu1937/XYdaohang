# 轩宇-导航 v2.2.3 部署清单

线上地址：http://dh.pxyblog.top/
运行环境：IIS/Apache + PHP 8.1 + MySQL 5.7
当前版本：v2.2.3

> 升级顺序：**先备份数据库 → 上传文件 → （仅 v2.1.x 及更早）执行 upgrade_v2.2.0.sql → 清缓存刷新**；v2.2.x 之间升级为纯代码修复/增强，**无数据库结构变更**，不需要跑升级脚本。可直接在后台「系统工具 → 版本检测 → 上传更新包更新」上传新版 zip 完成在线更新。

## 一、必须覆盖上传

### 根目录
- index.php（前台首页：搜索引擎选择器、申请收录入口）
- login.php、register.php、logout.php、my.php（v2 新增：前台用户与个人导航）
- forgot.php、reset.php（v2.2.0 新增：忘记密码、重置密码）
- share.php（v2 新增：分享预览与导入）
- about.php、contact.php（v2 新增：关于页、联系留言页）
- detail.php、apply.php、apply(1).php（apply(1).php 已恢复自动抓取，仅网址必填）
- install.php（全新服务器安装向导，安装后立即删除）
- 全新服务器部署.txt
- VERSION、CHANGELOG.md、README.md、UPLOAD_LIST.md、copyright.txt
- upgrade_v2.0.0.sql（旧站升级用；全新安装由 install.php 自动建表，不需要）
- upgrade_v2.1.0.sql（从 v2.0.x 升级用；全新安装不需要）
- upgrade_v2.2.0.sql（从 v2.1.x 升级用；全新安装不需要）
- upgrade_v1.1.3.sql（仅当仍停留在更早版本时使用）
- web.config（IIS 安全规则 + /api/sites 重写；主机锁定 rewrite 节点导致 500.19 时删除该节点）
- htaccess（Apache 环境重命名为 .htaccess）

### cron/（v2 新增目录）
- daily_check.php（链接健康检测定时任务，按 settings.cron_key 鉴权）

### admin/
- index.php、login.php、logout.php、sidebar.php
- users.php、stats.php、tools.php（v2 新增；v2.2.0 版本检测改为服务器端代理；
  v2.2.2 新增上传更新包在线更新，版本检测 tab 已纳入 $allowedTabs）
- categories.php、nav_links.php（v2 增强）
- settings.php（v2 新增「前台体验」与「AI 分类推荐」分区）
- applications.php、profile.php
- messages.php（v2.1.0 新增：留言管理）
- check_update.php、update_progress.php、test.php（已改为 410，可直接删除这三个文件）

### api/
- site_meta.php、track_click.php（track_click 增加 visit_logs 写入）
- auth.php、user_sites.php、share.php、bookmark_import.php（v2 新增：前台用户与个人导航）
- contact.php（v2 新增：联系留言）
- ai_suggest.php、health_check.php、stats.php（v2 新增：后台抓取推荐、健康检测、统计）
- sites.php（v2 新增：开放 REST API）
- user_categories.php（v2.2.0 新增：用户自主分组管理）
- check_update.php（v2.2.0 新增：服务器端版本检测代理，替代浏览器直连 GitHub；
  v2.2.2 增强：新增 raw VERSION 兜底链路 + jsdelivr 镜像、超时延长、cURL 缺失时 stream 回退、CORS 兼容头）
- clear_cache.php、get_cache_status.php（已改为 410，可直接删除）

### includes/
- bootstrap.php（v2.2.2 兜底版本号更新）
- asset_fallbacks.php
- functions.php（新增 textCut 兜底）
- Services.php（v2 新增：UserService / SiteInfoService / AiService / CategoryEngine /
  HealthCheckService / BookmarkService / ShareService / MailService / BackupService / ContactService；
  v2.2.0 增强：SiteInfoService 重定向安全跟随、og:image 兜底；UserService 新增密码重置三方法；
  v2.2.1 增强：SiteInfoService 下载超时放宽（连接 5s/总 10s）+ 失败宽松重试一次（连接 8s/总 15s/跳过证书校验），
  补全 Accept / Accept-Language / Cache-Control 请求头）
- ConfigManager.php（v2.2.2 版本号更新）
- TemplateParser.php
- UpdateManager.php（已改为 410，可直接删除）
- .htaccess

### config/
- config.php、database.php、smtp.php、version.php（版本号已改为 2.2.2）
- .htaccess

### install/
- config.php、index.html、install.lock
- install.sql（已重写为 v2.2.0 全量结构）
- 全新服务器安装完成后建议删除整个 install/ 目录
- .htaccess

### template/mail/
- application_notification.html/.txt
- application_approved.html/.txt
- application_rejected.html/.txt

### assets/（v2 起后台依赖已全部本地化，可离线自足）
- 前台自有样式与脚本：
  - css/nav.css（v2.2.1：搜索框清除按钮与搜索方式选择器间距调整，避免重叠误触）
  - css/auth.css（v2.2.0：忘记密码/重置密码页样式）
  - css/page.css（v2 新增：分享/关于/联系页样式）
  - js/nav.js（v2.2.0：搜索引擎跳转、多关键词 AND、拼音搜索）
  - js/pinyin-pro.min.js（v2.2.0 新增：拼音搜索本地库，322KB，暴露 window.pinyinPro）
- 后台依赖（**新增，请一并上传，否则后台要靠 CDN 兜底**）：
  - js/jquery3.7.1.js
  - js/bootstrap.bundle.min.js（Bootstrap 5.3.3，含 Popper）
  - sweetalert2/sweetalert2.min.js、sweetalert2/sweetalert2.min.css
  - js/jquery.dataTables.min.js、js/dataTables.bootstrap5.min.js、js/dataTables.zh-CN.json
  - css/dataTables.bootstrap5.min.css
  - js/chart.umd.min.js（Chart.js 4.3.0）
  - js/bootstrap-datepicker.min.js、js/bootstrap-datepicker.zh-CN.min.js、css/bootstrap-datepicker.min.css
  - css/font-awesome.min.css + webfonts/fa-solid-900.woff2/.ttf、fa-regular-400.woff2/.ttf、fa-brands-400.woff2/.ttf
  - js/qrcode.min.js（detail.php 用）、js/tailwind.min.js（detail.php 与 apply(1).php 两个旧页用）
- css/admin.css、css/bootstrap.css（后台自有样式）
- 说明：页面里仍保留少量 `cdn.*` / `cdnjs` 地址，但**只在本地文件加载失败时才触发**（`includes/asset_fallbacks.php` 及各页的 `onerror`），因此服务器无外网也能正常使用后台。
- 注意：不要用本地 assets 整目录覆盖线上，只上传上面列出的文件，避免覆盖掉线上可能存在的其它资源。

### 目录保护
- uploads/web.config、uploads/.htaccess
- logs/.htaccess、cache/.htaccess、backups/.htaccess、vendor/.htaccess

## 二、不要上传
- backups/ 整个目录（本地回滚备份，含旧版源码和旧配置，禁止放到网站根目录）
- 任何 .log、.sql、.lock、.zip、.md 文件请不要放入可公开访问目录（web.config/htaccess 已拦截）

## 三、数据库升级
1. 备份数据库。
2. 执行 `upgrade_v2.0.0.sql`（phpMyAdmin 导入，或命令行 `mysql -u用户 -p 数据库名 < upgrade_v2.0.0.sql`）。
   - 只新增表、列、索引与设置项，不会删除或改写既有数据。
   - 脚本是**幂等**的：重复执行不会报错，已存在的内容自动跳过，中断后可以放心重跑。
3. 若从 v2.0.x 升级，继续执行 `upgrade_v2.1.0.sql`（新增 users.avatar / categories.user_id / categories.is_private）。
4. 若从 v2.1.x 升级，继续执行 `upgrade_v2.2.0.sql`（新增 users.reset_token_hash / reset_expires 及索引）。
5. 新增结构：
   - 新表：`users`、`visit_logs`、`api_tokens`、`shares`、`contact_messages`
   - `categories` 新增：`parent_id`、`icon`、`remark`、`user_id`、`is_private`
   - `nav_links` 新增：`user_id`、`remark`、`check_status`、`latency_ms`、`last_checked_at`、`deleted_at`
   - `users` 新增：`avatar`、`reset_token_hash`、`reset_expires`
   - 新增设置项：公告、默认视图、跳转方式、热门数、皮肤、背景图、注册开关、SEO、关于内容、
     默认访客视图、AI 配置、测速标签、联系页开关、定时密钥、失效告警开关

## 四、上线后检查
1. 访问 http://dh.pxyblog.top/ 应显示新版界面：左侧分类索引、右侧分组卡片、右下角悬浮按钮。
2. 前台顶部/右上角可切换显示方式、配色皮肤与深浅色；刷新后选择应保留。
3. 注册一个测试账号 → 进入「我的导航」→ 添加网址（会自动抓取标题）→ 拖拽排序 → 生成分享链接 → 打开分享页确认可一键导入。
4. 访问 /install/ 应返回 404/410，不能出现安装向导。
5. 后台首次登录：旧明文或旧 md5 密码会自动迁移为 password_hash；`users` 表中被设为管理员的账号也可登录后台。
6. 后台「系统设置 → 前台体验」保存一次，确认公告、皮肤、注册开关生效。
7. 后台「导航管理」确认回收站、批量操作、健康检测按钮可用；未执行升级脚本时这些按钮会退化为提示而不是报错。
8. 后台「系统工具」确认可导出备份、创建 API 令牌、发送测试邮件、查看联系留言。
9. 后台「数据统计」确认图表有数据（若显示「统计不可用」说明 visit_logs 表尚未建好）。
10. 在「系统工具 → 定时任务」生成密钥，用浏览器访问
    `/cron/daily_check.php?key=生成的密钥`，应返回检测统计文本。
11. 确认 /config/、/includes/、/backups/、/logs/、/cache/ 无法直接访问。

## 五、数据库口令
config/database.php 支持 NAV_DB_HOST/NAV_DB_NAME/NAV_DB_USER/NAV_DB_PASS 环境变量。
建议在主机面板轮换原数据库口令，并设置环境变量，随后移除源码中的回退值。

## 六、安全提醒
- 备份导出的 JSON **包含用户密码哈希与 API 令牌原文**（导入时可完整还原账号与令牌），
  属于敏感文件：不要放在网站根目录或可公开访问的位置，也不要随手转发。
  只有 `settings.ai_api_key`（AI 接口密钥）不会被导出，恢复后需重新填写。
- API 令牌明文只在创建时显示一次，请立即保存。
- 定时任务密钥等同于后台检测权限，泄露后请到「系统工具」重新生成。
