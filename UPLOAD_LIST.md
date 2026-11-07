# 轩宇-导航 v2.12.0 部署清单

线上地址：http://dh.pxyblog.top/
运行环境：IIS/Apache + PHP 8.1+ + MySQL 5.7+
当前版本：v2.12.0

> **功能补齐版 · 无数据库变更、无 API 契约变更、无新增依赖、无 `web.config` / `.htaccess` 改动**。按用户清单完成「AI 分类配置测试连接」「全站统一添加分类入口」「全站底部入口统一并暴露 API 说明」。本地 Playwright 全量回归 **73/73 通过**，v2.11.0 存量回归 **49/49 通过**，脚本级 JS 错误 **0**。完整根因见 [CHANGELOG.md](CHANGELOG.md)。

## 本次 v2.12.0 新增文件

| 文件 | 说明 |
| --- | --- |
| `api/ai_test.php` | AI 配置真实连接测试：仅管理员 + POST + 同源校验，用当前表单值发真实 `/chat/completions` 请求；密钥留空可复用已保存密钥，密钥不回显；curl / HTTP 错误中文化 |
| `api/category_create.php` | 全站统一「新建分类 / 新建分组」接口：字段与校验的唯一真相源、同名复用、兼容老库、前台强制归属本人 |
| `includes/site_links.php` | 全站统一快捷入口组件 `siteQuickLinksHtml()`：关于本站 / 联系管理员 / 申请收录 / API 说明；样式内联一次输出，不依赖页面 CSS |
| `assets/js/category-quick-add.js` | 统一新建分类弹窗组件 `XYCategoryQuickAdd`：`open()` / `validateName()`，前端校验与后端逐条一致 |
| `assets/css/category-quick-add.css` | 统一弹窗样式：自带配色（含深色主题）、移动端贴底、不依赖 Bootstrap / Tailwind |

## 本次 v2.12.0 变动文件

| 文件 | 说明 |
| --- | --- |
| `admin/settings.php` | AI 分类推荐区新增「测试连接」按钮（`type="button"`，不保存配置）+ 绿色/红色结果区 + `.ai-test-result` 深浅色样式 |
| `admin/nav_links.php` | 筛选下拉旁、编辑表单「所属分类」旁新增「添加分类」；三个分类下拉加 `data-category-options`；创建后不刷新页面直接补选项 |
| `admin/categories.php` | 页头新增「快速添加分类」按钮 |
| `admin/sidebar.php` | 侧栏底部新增后台版快捷入口（含「查看 API 文档」） |
| `assets/css/admin.css` | 侧栏快捷入口样式、折叠态缩写为 `API`、深色主题适配 |
| `index.php` | 分类索引标题栏 + 移动端 tab 栏新增登录可见的「新建分组」；**本人空分组不再被索引过滤掉**；页脚换统一组件 |
| `my.php` | 工具栏新增一级「新建分组」；分组管理弹窗创建切到统一接口与共享校验；页脚补一行小字号快捷入口与版本号 |
| `about.php`、`contact.php`、`apply(1).php`、`share.php` | 页脚接入统一快捷入口（申请页为 Tailwind 版，单独补一个底部区块） |
| 全部 PHP 页面（22 处） | 资源参数 `?v=2.12.0` |
| `VERSION` / `config/version.php` / `includes/bootstrap.php` / `includes/ConfigManager.php` / `api/index.php` | 版本号升至 2.12.0（共同步 27 处） |
| `CHANGELOG.md`、`README.md`、`UPLOAD_LIST.md` | 文档同步 |

## 本次交付的三项需求

- **① AI 测试连接**：用当前填写的地址 / 模型 / 密钥发真实请求，绿色成功（含耗时、HTTP、模型回复）/ 红色失败（401 / 404 / 429 / 超时 / DNS / TLS 中文化）；测试按钮不提交表单、不写数据库；密钥不回显。
- **② 统一添加分类入口**：后台分类管理、后台导航管理筛选下拉、前台首页分类 tab / 抽屉、我的导航页分组管理弹窗，全部复用同一套字段、校验规则与 `api/category_create.php`；前台未登录不显示入口；前台用户无法越权建公共分类。
- **③ 全站底部入口统一**：首页、我的导航、关于、联系、申请收录、分享页、后台 11 个页面统一提供「关于本站 / 联系管理员 / 申请收录 / API 说明」（后台文案为「查看 API 文档」）；`my.php` 底部补小字号一行；移动端底部居中显示、不隐藏。

## 升级要点

1. **直接覆盖代码文件即可**，不需要执行任何 SQL，不需要改 `web.config` / `.htaccess`。
2. 覆盖后清理 `cache/` 目录，前台强制刷新（Ctrl+F5）——本版新增 2 个 CSS/JS 文件并改了 22 处 `?v=`，清缓存最稳妥。
3. **务必把 5 个新增文件一起上传**：`api/ai_test.php`、`api/category_create.php`、`includes/site_links.php`、`assets/js/category-quick-add.js`、`assets/css/category-quick-add.css`。缺 `site_links.php` 会让所有前台页与后台侧栏直接 500（函数未定义）。
4. **推荐用后台「系统工具 → 在线更新」上传整包**：接口会跳过 `config/database.php`、`config/smtp.php`、`config/config.php`、`install/install.lock`、`.env` 及 `backups/`、`cache/`、`uploads/` 等目录，并自动备份被覆盖的原文件。
5. 最快验证：访问 `/api/index.php`（零依赖，永远可用），徽章应显示 `v2.12.0`；首页资源参数应为 `?v=2.12.0`；任一前台页页脚应出现「API 说明」。
6. AI 测试连接会向管理员填写的地址发一次**真实**请求（消耗极少量 token）；若线上服务器禁止访问外网，请用「保存 AI 设置」后在「一键添加」里验证。

## 验证记录

| 项目 | 结果 |
| --- | --- |
| v2.12.0 Playwright 全量回归 | 73/73 通过 |
| v2.11.0 存量回归（无回退） | 49/49 通过 |
| 脚本级 JS 错误 | 0 |
| AI 成功 / 401 / 404 / 空地址 / 不保存配置 / 复用已保存密钥 | 全部通过（本地 mock 接口真实请求） |
| 四类分类入口 + 越权防护 + 同名复用 | 全部通过 |
| 六页页脚 + 后台侧栏 + 390px 移动端居中可见 | 全部通过 |
| `php -l`（12 文件）/ `node --check`（独立 JS + 20 个内联脚本块） | 全部通过 |

## v2.11.0 回顾

按线上真实检测结果完成 17 项体验修复（P0 严重 1–4、P1 中等 5–11、P2 细节 12–17），并修掉一个隐藏 Bug：`#cardContextMenu` DOM 挂在主交互脚本之后，导致右键 / 长按菜单从未真正可用。本地回归 49/49 通过。无数据库变更。

## v2.10.9 回顾

紧急修复 v2.10.8 在 `web.config` 里重复声明 `<defaultDocument><add value="index.php" /></defaultDocument>` 导致的 `500.19 / 0x800700b7`（主机全局默认文档本已含 `index.php`，子级重复 `<add>` 使整个配置失效，首页 `/` 与 `/api/` 全 500）。该版移除该节点，并同步修正文档与部署清单。**当前 `web.config` 保持 v2.10.9 的修复版，v2.11.0 不再改动它。**

> 若部署后首页出现 500.19，检查线上 `web.config` 是否又被加回了 `<defaultDocument>`；删掉整段即可恢复，不用回滚其它文件、不用动数据库。

## v2.10.8 回顾

新增 `api/index.php` 开放 API 文档页，解决 `/api/` 403（原本目录里没有默认文档、也没有任何说明）。零依赖，内容按 `api/sites.php` 真实实现核对，契约联调 18/18 通过。**但该版在 `web.config` 加了 defaultDocument 节点，部分主机触发 500.19，已由 v2.10.9 移除。**

## v2.10.7 回顾

新增批量导入网址：一次粘贴最多 50 条，`curl_multi` 并发抓取标题/图标/描述并推荐分组，落库前给出可校对清单（识别 → 复核 → 确认）。改 `includes/Services.php`、`api/user_sites.php`、`my.php`。10 条并发首抓 1.08s，缓存 0.16s。无数据库变更。

## v2.10.6 回顾

`my.php` 顶栏修复：窄屏下「轩宇导航」塌成一字一行（标题只剩 16px 宽）、相机图标因整页未引入 `font-awesome.min.css` 而渲染 0px 宽。品牌区加 `flex-shrink: 0` + `nowrap`，3 处图标改内联 SVG，≤640/430/360 三级收敛。无数据库变更。

## v2.10.5 回顾

三种显示模式各做一套卡片版式：网格竖排（标题2行+介绍2~3行→测速→网址）、列表单行四列对齐（`display: contents` 拆掉包装层实现跨行右边缘齐平）、紧凑模式 42px 且操作条改为点击展开（`.is-actions-open`，二次点击才跳转）。无数据库变更。

## v2.10.4 回顾

卡片网址移到测速下方独占一行，标题两行、介绍两行。无数据库变更。

## v2.10.3 回顾

联系留言通知邮件修复：`submit()` 返回落库数据，邮件标题带上用户主题，正文补齐「姓名/邮箱/主题/留言」。无数据库变更。

## v2.10.2 回顾

收录流程重做（识别 → 复核 → 确认）：新增 `quick_preview` 只读识别接口、标题净化、分组智能推荐计分与词边界、两阶段收录工具框。无数据库变更。

## v2.10.1 回顾

搜索框重做：胶囊式输入条、引擎选择器改文字触发器、清空按钮定位修正。无数据库变更。

## v2.10.0 回顾

全站视觉规范梳理：设计令牌去死代码、半像素字号清零、圆角归并 5 档、触控热区统一 40px、移动端卡片标题截断修复。无数据库变更、无功能逻辑改动。

## v2.9.3 回顾

顶部右上角控件重做 + `header-tools` 显示优化：圆形头像、头像与箭头合并为整体按钮、太阳/月亮主题图标随状态切换、未登录与加载失败走默认头像兜底。无数据库变更。

## v2.9.2 回顾

体验修复 + 后台 UI 升级：修好「自动获取头像过小 / 导航栏头像文字错乱」「主页搜索框快速添加网址」，后台 11 个页面统一视觉规范。无数据库变更。

## 历史版本

## 本次 v2.5.0 变动文件

| 文件 | 说明 |
| --- | --- |
| `index.php` | 卡片新增 `data-tags` / `data-alias` 属性；引入 `search.js` |
| `my.php` | 支持 `?add_url=` / `?add_url_name=` 预填打开添加网址弹窗；引入 `search.js` |
| `share.php`、`about.php`、`contact.php` | header 注入全局搜索按钮（search.js 自动完成）；引入 `search.js` |
| `apply(1).php` | 收录申请网址输入框支持 `?url=` 预填 |
| `admin/nav_links.php` | 编辑/新增表单新增「网站别名」「网站标签」字段（老库缺列自动隐藏） |
| `api/search_index.php` | 新增：按当前视图可见性输出搜索索引 JSON（访客排除私密分组） |
| `assets/js/search.js` | 新增：全局搜索组件（联想 / 最近搜索 / 隐私模式 / 意图词典 / 无匹配引导 / 页内高亮） |
| `assets/js/nav.js` | 页内过滤扩展（网址/标签/别名/分类名 + 语义扩展）；暴露 `XYNavSearch` 桥接；支持 `?s=` / `?cat=` 直达 |
| `assets/css/nav.css` | 联想面板 / 历史面板 / 紧凑搜索按钮与弹出面板样式（含移动端断点） |
| `upgrade_v2.5.0.sql` | 新增：`nav_links` 表新增 `alias`、`tags` 列（幂等） |
| `install/install.sql` | nav_links 表同步 `alias`、`tags` 列 |
| `config/version.php`、`VERSION`、`includes/bootstrap.php`、`includes/ConfigManager.php` | 版本号升至 2.5.0 |
| `CHANGELOG.md`、`README.md`、`UPLOAD_LIST.md` | 文档更新 |

## 本次 v2.3.0 变动文件

| 文件 | 说明 |
| --- | --- |
| `index.php` | 搜索框移出头部改为公告栏下方居中三段式（引擎选择 + 输入框 + 搜索按钮）；配色按钮图标换为三色交叠圆；页脚版权按「用户自定义 > 全局设置 > 默认」输出 |
| `my.php` | 分组管理新增上移/下移排序；添加/编辑网址分组选择器仅显示本人分组（分组私有化）；账号弹窗新增底部版权设置；抓取按钮美化（渐变实心 + 火花图标）；页脚版权接入 |
| `about.php` | 新增「更新日志」板块（自动解析 CHANGELOG.md，最多 5 个版本）；页脚版权接入 |
| `api/user_categories.php` | 新增 `action=reorder` 分组排序接口（按 user_id 过滤，越权 ID 静默丢弃） |
| `api/user_sites.php` | 修复 IDOR：`userSiteCategoryId()` 追加 `AND user_id = ?` 归属校验，禁止把网址归入他人/公共分组 |
| `api/auth.php` | 新增 `action=update_profile`：保存个人底部版权 |
| `includes/Services.php` | `UserService` 新增 `footer_copyright` 读取（findById 兼容老库）与 `updateFooterCopyright()` / `footerCopyrightOf()` |
| `includes/functions.php` | 新增 `footerCopyrightText()`：版权文案三级回落 + `{year}`/`{site}` 占位符替换 |
| `admin/settings.php` | 「备案信息」卡片扩展为「备案与版权」，新增底部版权输入框与保存 |
| `assets/css/nav.css` | 搜索区新样式（search-section/bar/box/submit）、引擎选择器改为左侧描边芯片、LOGO 52px 美化、各断点移动端适配 |
| `assets/css/page.css` | 更新日志板块样式 |
| `assets/js/nav.js` | 新增搜索按钮点击处理（站内筛选并滚动到列表，外部引擎新标签打开） |
| `upgrade_v2.3.0.sql` | 新增：`users.footer_copyright` 列 + `settings.footer_copyright` 键（幂等） |
| `install/install.sql` | users 表同步 `footer_copyright` 列 |
| `config/version.php`、`VERSION`、`includes/bootstrap.php`、`includes/ConfigManager.php` | 版本号升至 2.3.0 |
| `CHANGELOG.md`、`README.md`、`UPLOAD_LIST.md` | 文档更新 |

> 提示：若线上版本检测仍失败，请在后台重新检测查看提示——若提示「SSL 证书校验失败」，说明服务器需要配置 `php.ini` 的 `curl.cainfo`。

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
- upgrade_v2.3.0.sql（从 v2.2.x 升级用，本次必须执行；全新安装不需要）
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
