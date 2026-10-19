# 轩宇-导航 · 版本记录

## v2.2.0（2026-10-20）

**新增**
- **用户自主分组**：「我的导航」新增「分组管理」弹窗，登录用户可自建、重命名、删除分组，并可将分组切换为「私密」——私密分组及其下网址仅本人可见（`api/user_categories.php` 仅允许操作 `user_id` 为当前用户的行，删除分组时自动把其下网址归入未分组；老库缺列时给出友好提示）。分组标题旁显示 🔒 私密锁徽标，添加网址的下拉框中私密分组带 🔒 前缀。
- **忘记密码**：登录页新增「忘记密码」入口（`forgot.php`），通过邮件发送 1 小时内有效的一次性重置链接；`reset.php` 校验令牌（DB 仅存 SHA-256 哈希）后重置密码。会话级限流（5 分钟 3 次），无论邮箱是否注册统一返回相同提示，防止邮箱枚举。
- **搜索引擎跳转 + 拼音搜索**：搜索框右侧新增搜索方式选择器（站内 / 百度 / 必应 / Google，记忆上次选择），选中外部引擎时回车直接新标签页跳转；站内搜索升级为空格分隔多关键词 AND 匹配，并支持中文名称的拼音全拼与首字母缩写匹配（集成 pinyin-pro 本地库，惰性按卡片构建缓存）。
- **申请收录入口**：前台页脚新增「申请收录」链接；申请表单仅网址必填，名称、邮箱均可留空（留空时自动抓取标题兜底为域名），并恢复申请页的「自动抓取」能力（委托带 SSRF 防护的 SiteInfoService，按 IP 限流 10 分钟 20 次）。

**修改**
- **版本检测修复**：后台「系统工具 → 版本检测」不再让浏览器直连 api.github.com（国内不可达导致"请检查网络连接"），改为调用本站新接口 `api/check_update.php`——服务器端 cURL 依序尝试官方 API 与 ghfast.top / gh-proxy.com / ghproxy.net 三个镜像，结果缓存 10 分钟，仅后台登录可访问；提示框 HTML 统一用 `<div>` 包裹，修复个别文案竖排。
- **站点信息抓取优化**：图标候选在 `<link rel=icon>` 之外补充 `og:image` / `twitter:image` / `msapplication-TileImage`；新增最多 2 次 3xx 重定向安全跟随（重定向目标重新走 SSRF 公网 IP 校验，绝不自动信任 Location）；保留原有 GBK/BIG5 等编码转换。抓取按钮视觉升级：主色描边 + 抓取中图标旋转动画，`setBusy` 不再抹掉按钮内 SVG 图标。
- **测速常驻显示**：桌面端网址卡片的测速结果改为默认常显，无需悬停即可查看；悬停时切换回点击量（原行为反转）；后台关闭监测时仍只显示点击量。移动端行为保持不变。

**数据库**
- `upgrade_v2.2.0.sql`：`users` 新增 `reset_token_hash`（VARCHAR 64，重置令牌哈希）与 `reset_expires`（INT，过期时间戳）两列及 `idx_reset_token` 索引；幂等写法，重复执行不报错。
- `install/install.sql` 同步为 v2.2.0 全量结构，全新安装无需再跑升级脚本。

## v2.1.0（2026-10-20）

**新增**
- **版本检测更新**：后台「系统工具」新增「版本检测」分区，直接调用 GitHub API（`repos/xuanyu1937/XYdaohang/releases/latest`）在线比对当前版本与最新 Release，逐段数字比较版本号；检测到新版本时展示 Release 说明（前 500 字符）与下载链接，检测过程带加载动画，失败时给出明确提示。
- **私密分组**：`categories` 表新增 `user_id`（归属用户，0 为公共）与 `is_private`（私密标记）两列及 `idx_user_id` 索引。私密分组及其下的网址仅归属用户本人登录后可见，访客与其他用户不可见；后台「分类管理」新增归属用户下拉与私密复选框，列表新增「归属」列与私密徽标。
- **后台未读留言提示**：仪表盘（`admin/index.php`）新增未读留言警示条，存在未读时显示数量并链接到留言管理；新增 `admin/messages.php` 留言管理页（标记已读 / 全部已读 / 删除 / 分页，未读行高亮）；侧边栏新增「留言管理」入口并带未读红色徽章，原挂在「系统工具」上的徽章移至该入口。
- **用户头像设置**：`users` 表新增 `avatar` 列；「我的导航」（`my.php`）新增头像编辑弹窗，支持上传 JPG/PNG/GIF/WebP（finfo MIME 校验，≤2MB，存 `uploads/avatars/`）与清除头像；前台首页用户菜单显示当前登录用户头像，**未登录访客显示默认管理员头像**，头像加载失败时自动回退为首字母圆形占位。

**修改**
- **分组逻辑改为用户个人独立**：`CategoryEngine::all()` / `ensure()` / `findByName()` / `nameToId()` 全部带用户上下文（新增 `setUserId()`），登录用户可见「公共非私密分组 + 自己的全部分组」，访客仅见公共非私密分组；自动建分类时在用户自己的分组空间内查重，不同用户可拥有同名分组互不干扰。未执行 `upgrade_v2.1.0.sql` 时自动降级为旧查询，不会报错。
- **收录申请逻辑核实**：经核实 `apply(1).php` 原本就未做登录限制，未登录访客本就可以提交收录申请，无需改动，特此说明。

**数据库**
- `upgrade_v2.1.0.sql`：`users` 新增 `avatar`，`categories` 新增 `user_id` / `is_private` 及 `idx_user_id` 索引；沿用幂等写法（information_schema 判断 + PREPARE 动态 DDL），重复执行不报错。
- `install/install.sql` 同步为 v2.1.0 全量结构，全新安装无需再跑升级脚本。

## v2.0.1（2026-10-20）

**修复**
- **修复「系统工具」页所有提示框文字竖排 bug**：`assets/css/admin.css` 的 `.alert` 采用 `display:flex` 布局，提示框内并排的图标、裸文本与 `<strong>/<code>` 被拆成多个 flex item，在窄列中逐字换行呈竖排。现统一用 `<div>` 包裹提示内容使其成为单一 flex item，恢复横向正常换行，**文案一字未改**。涉及：数据备份与恢复（导出安全提示）、API 令牌管理（权限说明、缺表提示、调用示例）、定时任务、邮件设置与测试（发送失败提示）四个分区共 5 处提示框。
- **「邮件设置与测试」改为在线配置，无需手动改 PHP 文件**：原页面仅只读展示 `config/smtp.php`，现新增可编辑表单，包含 SMTP 主机、端口、加密方式（SSL/TLS/不加密）、账号、密码（留空保持已保存密码不变）、发件地址、管理员收件邮箱，以及「启用邮件功能」开关。保存时校验端口范围与邮箱格式，启用状态下主机/发件地址必填；以 `var_export` + `LOCK_EX` 原子写入 `config/smtp.php`，保留 403 防直读守卫与 templates/backup/timeout 等其余配置键，写后调用 `opcache_invalidate` 避免旧缓存。「发送测试邮件」功能保持不变；`mail_disabled` 错误提示改为引导在页面内填写并启用。

## v2.0.0（2026-10-19）
本次升级按《轩宇导航系统产品设计计划书》实现，在保留 v1.1.3 全部功能的前提下扩展为「多用户 + 个人导航」形态。

**多用户与前台**
- 新增前台用户体系：注册 / 登录 / 退出（`users` 表，`password_hash` 存储，支持邮箱或用户名登录），可在后台开关注册。
- 新增「我的导航」（`my.php`）：个人网址收藏，支持添加（自动抓取标题）、编辑、删除、拖拽排序（并提供上下方向键的键盘替代）、修改密码。
- 新增个人导航的 JSON 接口 `api/user_sites.php`（增删改 / 排序 / 抓取），全部校验条目归属。
- 新增书签导入 `api/bookmark_import.php`：解析浏览器导出的 NETSCAPE 书签 HTML，按文件夹自动建分类、按 URL 去重。解析改为按标签顺序扫描，兼容压缩导出（同一行含 `<DL>` 与 `<A>`）的文件。
- 新增分享能力：`api/share.php` 生成分享链接，`share.php?token=` 预览 + 一键导入他人导航，带浏览计数。
- 访客视图：登录用户看自己的导航；未登录看后台指定的默认访客视图（`settings.default_user_id`，显式设为 0 时使用公共导航）。

**前台界面（v2）**
- 首页重构为「左侧分类索引 + 右侧分组卡片」两栏布局；索引栏支持二级分类折叠，移动端自动切换为抽屉。
- 新增热门访问 TopN（按点击量）、顶部公告栏（滚动，悬停或键盘聚焦暂停）。
- 新增三种显示方式：网格 / 列表 / 紧凑，顶栏分段控件与右下悬浮按钮双向同步，选择保存在浏览器本地。
- 新增四套配色皮肤：经典蓝 / 静谧紫 / 暖阳橙 / 薄荷青，与深浅色模式可自由组合。
- 卡片新增 3D 悬浮倾斜（上限 6°，仅在精细指针且未开启减少动效时启用）与错落入场动画。
- 新增用户菜单（全部导航 / 我的导航 / 管理后台 / 关于 / 退出）。
- 新增内容页：`about.php`（关于，内容可在后台配置，留空用默认文案）、`contact.php`（联系留言，无 JS 也可提交）。
- 前台接口：`api/auth.php`（注册 / 登录 / 退出 / 当前用户 / 改密）、`api/contact.php`（留言，与联系页共用 `ContactService`，带会话级与 IP 级限流）。

**后台**
- 「系统设置」新增「前台体验」与「AI 分类推荐」两个分区：公告、默认显示方式、跳转方式、热门数、皮肤、背景图、注册开关、联系页开关、卡片测速标签、失效告警开关、SEO 标题/描述、关于页内容、AI 接口地址/模型/密钥（密钥留空不回显、不覆盖）。
- 「分类管理」支持二级分类（仅允许两级，防循环）、分类图标与备注。
- 「导航管理」增强：回收站（移入 / 恢复 / 彻底删除）、批量操作（移动分类 / 移入回收站 / 启用停用 / 导出 CSV）、一键添加（抓取标题描述图标 + 分类推荐）、健康状态列与单条/全量检测、拖拽排序（含键盘替代）、按归属筛选。
- 新增「用户管理」（`admin/users.php`）：列表、搜索、封禁/启用、设为管理员、设为默认访客视图、重置密码、删除（连带清理其私有导航与分享）。
- 新增「数据统计」（`admin/stats.php`）：概览卡片 + 点击趋势 / 热门 Top10 / 健康状态 / 用户增长 / 分类分布图表，数据来自 `api/stats.php`。
- 新增「系统工具」（`admin/tools.php`）：数据备份与恢复（JSON 导出 / 导入 / 服务器留存）、API 令牌管理、定时任务配置与手动检测、邮件配置状态与测试发送、联系留言管理。
- 后台登录改为双源：`admins` 表优先，其次 `users` 表中 `is_admin = 1` 的账号（会话记录 `admin_source`，资料页据此操作对应数据表）。
- 侧边栏新增用户管理 / 数据统计 / 系统工具入口，系统工具带未读留言角标。

**接口与定时任务**
- 开放 REST API `api/sites.php`：Bearer Token 鉴权（兼容 `X-Api-Token` 与 `?token=`），支持列表 / 详情 / 创建 / 更新 / 删除，令牌权限分 read / write，作用范围分管理级（`user_id = 0`）与用户级。
- 新增 `api/ai_suggest.php`（抓取 + 分类推荐）、`api/health_check.php`（单条 / 全量检测）、`api/ai_suggest` 与健康检测结果统一回写 `check_status` / `latency_ms` / `last_checked_at`。
- 新增 `cron/daily_check.php`：按 `settings.cron_key` 鉴权，检测全部导航并可选发送失效告警邮件（告警可在设置中关闭）。
- `api/site_meta.php` 改为委托 `SiteInfoService`：除标题外同时返回 `description` 与 `icons` 候选，并复用同一份 SSRF 防护与 `cache/site_meta` 缓存，去掉原先重复的一份抓取实现（响应结构与 HTTP 状态语义保持向后兼容）。

**数据库与部署**
- `upgrade_v2.0.0.sql`：新增 `users` / `visit_logs` / `api_tokens` / `shares` / `contact_messages` 五张表，为 `categories` 增加 `parent_id` / `icon` / `remark`，为 `nav_links` 增加 `user_id` / `remark` / `check_status` / `latency_ms` / `last_checked_at` / `deleted_at`，补充全部新设置项，并为 `nav_links` 补上 `idx_user_id` / `idx_deleted_at` 索引。
- 升级脚本写成**幂等**形式：MySQL 5.7 没有 `ADD COLUMN IF NOT EXISTS`，因此先用 `information_schema` 判断、再用 `PREPARE` 动态执行 DDL（不需要 `DELIMITER`，phpMyAdmin 与命令行都能直接跑）。这样重复执行不会在 `Duplicate column name` 处中断，中断后重跑也安全。
- `install/install.sql` 重写为 v2 全量结构，供全新服务器安装使用；`install.php` 无需改动。
- 记录一处实现取舍：本次未引入 Vue/Tailwind 等构建链（部署目标是虚拟主机/宝塔，无 Node 环境），前台继续使用服务端渲染 + 原生 JS + CSS 变量主题。
- 计划书中「右下角显示方式切换」与「顶栏显示方式切换」两处描述同时实现：顶栏为三选一分段控件，右下为单键循环切换，两者状态同步。
- 邮件沿用既有 `config/smtp.php` 文件配置（未新增数据库设置键），保持与 v1.1.3 一致的配置方式。

**兼容性与修复**
- 后台第三方前端资源**全部本地化**：jQuery 3.7.1、Bootstrap 5.3.3 bundle、SweetAlert2 11（含样式）、DataTables 1.13.4（含 `dataTables.zh-CN.json` 中文语言包）、Chart.js 4.3.0、bootstrap-datepicker 1.9.0、Font Awesome 6.4（含 webfonts 字体）、qrcode，以及 detail.php / apply(1).php 两个旧页用到的 Tailwind 运行时。此前这些文件只存在于线上或 CDN，本地包中缺失，导致新服务器若无外网则后台交互（标签页、弹窗、表格排序、图表）失效；现在改为本地优先，页面里保留的 CDN 地址仅在本地文件加载失败时兜底。顺带修掉 `admin/index.php` 指向不存在的 `assets/js/bootstrap.js` 的旧引用。
- 全面补上 mbstring 缺失时的兜底（`Functions::textCut` 与 `BaseService` 的 `textLower` / `textCut` / `textHas`），避免在未启用 mbstring 的主机上出现致命错误。
- 未执行升级脚本时整体降级而不是白屏：`nav_links` 新列缺失时首页与后台退回旧查询；`users` / `shares` / `visit_logs` / `contact_messages` 等新表缺失时对应用户系统、分享、统计、留言功能会给出明确提示或显示 0，前台不会抛 500。
- 修复默认访客视图：`settings.default_user_id` 显式写入 `0` 时应使用公共导航，此前会被 `users.is_default_user` 标记覆盖。
- 修复备份恢复：导出时被剔除的 `users.password_hash` 与 `api_tokens.token` 在 MySQL 严格模式下会导致 1364 报错并让整次恢复回滚（等于备份无法恢复）。现在这两列按**原值导出**，导入时完整还原账号与令牌；同时保留一层兼容兜底 —— 若备份文件里缺少这类 NOT NULL 列（早期版本导出的文件），会自动补入安全占位值（密码为不可登录值、令牌重新随机生成），保证恢复不会因为单个字段而整体失败。
- 备份文件的 `meta` 增加 `contains_secrets` 标记；页面与文档均提示备份含敏感信息，需妥善保管。仅 `settings.ai_api_key` 不导出，恢复后需重新填写。
- 修复书签导入：改为按标签顺序扫描而不是逐行处理，兼容把 `<DL>` 与书签挤在同一行（甚至整份文件不换行）的导出文件；同时忽略 `data:` 形式的 ICON。
- 移除未被任何页面使用的 `CategoryEngine::tree()`（其内部的父级计数逻辑有缺陷，且首页与后台各自内联建树），避免留下隐患。
- `api/site_meta.php` 的输入校验与 SSRF 拦截在改为委托 `SiteInfoService` 后保持原有行为（非法地址 400、内网与 localhost 403）。

## v1.1.3（2026-10-19）
- PHP/MySQL 兼容：MySQL 5.7 下 `LIMIT` 参数改为 `PDO::PARAM_INT` 绑定。
- PHP 8.1 兼容：`escape()` 空值处理、`TemplateParser` 扩展名兜底、申请页编码处理。
- 安全：登录密码改为 `password_hash/password_verify`，旧明文密码登录后自动迁移；`admins.password` 自动扩宽到 `varchar(255)`。
- 安全：Session 统一 HttpOnly/SameSite=Lax/Secure(HTTPS)、登录后重新生成 Session ID。
- 安全：新增同源校验，配合 SameSite 降低 CSRF；删除/启停/审核等写操作增加同源检查。
- 安全：上传改为 MIME + 图片魔数校验，禁止 SVG，随机文件名；删除 Logo 时限定在 `uploads/` 内。
- 安全：关闭申请页未登录的服务端自动抓取（SSRF），保留手动填写与上传。
- 安全：更新器、安装器、调试接口全部下线；新增 IIS `web.config` 与 Apache `.htaccess` 防护。
- 修复：审核未分类申请时自动归入第一个分类或创建“未分类”，避免 `category_id NOT NULL` 失败。
- 修复：申请入口加固后的括号错误，避免 `apply.php` 500；统一后台改密最小长度为 8 位。
- 修复：移除后台“SVG 可上传”的旧文案；Logo 预览在无当前 Logo 时不再因空元素报错。
- 修复：部分主机禁用 `chmod()` 时安装/上传报 `Call to undefined function chmod()`；文件权限调用改为存在才执行。
- 修复：后台登录页改为自包含样式，不再依赖 Tailwind CDN；后台 CSS 增加版本参数避免旧缓存。
- 修复：后台页面 include 守卫可能造成页面截断；jQuery/Bootstrap/SweetAlert2/DataTables/Chart.js 增加多 CDN 兜底。
- 后台系统设置页重构：面包屑、卡片/表单间距、按钮主次与 hover、Logo 拖拽预览、备案字数提示、设置说明分点帮助；移除说明模块异常 `h6` 标题。
- 新增前台监测总开关与默认主题设置：关闭监测后前台不请求在线/速度；默认主题支持跟随系统/浅色/深色。
- 新增：全新服务器安装向导 `install.php`，界面填写数据库/站点/管理员，自动建表、写配置、创建管理员并锁定安装。
- 新增：`config/database.local.php` 支持，安装向导会自动生成，无需手改数据库配置。
- 新增：`includes/asset_fallbacks.php`，jQuery/Bootstrap/SweetAlert2 缺失时自动 CDN 兜底。
- 修复：分类统计显式 GROUP BY，兼容 MySQL 5.7 `ONLY_FULL_GROUP_BY`。
- 修复：后台用户名、申请异常消息、Logo URL 等输出转义；申请邮件 HTML 模板变量转义。

## v1.1.2（2026-10-19）
- 品牌统一为“轩宇-导航”，移除旧品牌、作者邮箱、旧域名、旧工作室等第一方可见标志。
- 更新页脚、登录页、程序信息、邮件模板、版权文件与安装 SQL 默认站名。
- 邮件配置默认关闭，移除固定收件邮箱；审批按钮不再依赖邮件模板。
- 企业邮箱/发件人改为留空，由站点所有者自行配置。

## v1.1.1（2026-10-19）
- 删除远程更新链路：`UpdateManager`、`admin/check_update.php`、`admin/update_progress.php`、`api/check_update.php`、`api/clear_cache.php`、`api/get_cache_status.php`。
- 删除后台“检查更新”入口与相关 JS；移除 `config` 中的 `api` 配置。
- 删除调试页 `admin/test.php`。
- 安装目录 `install/` 停用，安装入口返回 410。
- 清空更新日志与更新缓存。

## v1.1.0（2026-10-19）
- 完整第一方源码基线备份：`backups/v1.1.0-baseline-20261019/`（44 个文件）。
- 备份范围：根目录、admin、api、includes、config、install、template、assets 的第一方文本源码；不含 vendor、uploads、cache、logs、二进制图片。