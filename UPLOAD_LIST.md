# 轩宇-导航 部署清单（当前版本 v2.18.6）

线上地址：http://dh.pxyblog.top/
运行环境：IIS/Apache + PHP 8.1+ + MySQL 5.7+
当前版本：**v2.18.6**（2026-11-14）

> **v2.18.6 三处收尾**：卡片长按/右键菜单两页统一为 7 项并修手机端缺项、手机端 logo 放大、顶部按钮与用户下拉菜单风格跨页统一；顺带修「我的导航」星标状态渲染与暗色主题 3 处对比度遗留。
> **无数据库变更，无需执行 SQL**；已在 v2.18.1 执行过 `upgrade_v2.18.1.sql` 的站点，从 v2.18.2 一路覆盖到 v2.18.6 都不用再跑 SQL。

## ⚠️ 升级须知（三步）

1. **仅当从 v2.18.0 或更早版本升级时**才执行 `upgrade_v2.18.1.sql`（`check_status` 增 `skipped`、新增 `skip_check` 列）；v2.18.2 ~ v2.18.6 全部无 SQL 变更。全新安装用 `install/install.sql`，不要执行升级脚本。
2. **后台「系统工具 → 在线更新」上传 v2.18.6 更新包并勾选「强制覆盖」**。在线更新只覆盖不删除，不会动 `config/database.local.php` 与 `uploads/`。
3. **清一次浏览器缓存**（Ctrl+F5）。18 个 PHP 文件的 33 处资源参数已升到 `?v=2.18.6`；`sw.js` 缓存名 `xy-nav-pwa-v2.18.6` 激活时自动清掉旧 PWA 缓存。

**验证是否生效**

- `/api/index.php` 徽章显示 `v2.18.6`；`VERSION`、`config/version.php`、`includes/bootstrap.php`、`includes/ConfigManager.php` 均为 2.18.6；
- 390px 登录态首页长按：7 项齐全（新标签打开 / 复制链接 / 二维码 / 收藏 / 编辑 / 移动分组 / 删除），编辑、移动、删除分别打开弹窗与二次确认；「加载更多」出来的卡片同样 7 项；
- 访客长按 4 项，点收藏提示「请先登录后再收藏」；登录用户看非本人数据 4 项，不出现编辑/移动/删除；
- `my.php` 长按 7 项，收藏/取消收藏文案与行内星标同步、刷新保持；
- 首页与 `my.php` 顶部各 3 枚图标按钮同高、同圆角、同边框与阴影，工具条无裸文字按钮；
- 360 / 390 / 430 三档：logo ≥38px、标题单行省略、头部不换行不溢出；
- 用户下拉 8 项全部内联 SVG、文字左对齐、3 条分组分隔线、当前页左侧主色竖条、「打开方式」右对齐状态徽标；
- 键盘 Tab 可依次聚焦按钮与菜单项，Esc 关闭下拉/右键菜单并归还焦点；
- axe-core 明暗双主题扫描首页与 `my.php`：`color-contrast=0`；
- `curl -D -` 看首页：四条安全头各一次、`Content-Security-Policy-Report-Only`、匿名 `public, max-age=60`、带 `ETag`、`Vary: Cookie, Accept-Encoding`；登录态仍 `no-store`。

## 本次 v2.18.6 变动文件（5 个功能文件 + 版本同步，无新增文件，无 SQL）

| 文件 | 说明 |
| --- | --- |
| `includes/site_card.php` | `.site-card` 增加 `data-owner`，触屏删除 `.card-actions` 后仍能判断归属 |
| `index.php` | 右键菜单 star 补 `title` / `aria-label`；用户下拉 8 项内联 SVG、4 组分隔线、当前页竖条、打开方式徽标 |
| `my.php` | 右键菜单补 star 项与文案/点击联动；头部改为 3 枚统一图标按钮 + 8 项下拉；`$item` 补 `is_starred` |
| `assets/js/nav.js` | `cardData()` 用 `data-owner` 判归属；`openCtxMenu()` 同步 star 文案；徽标更新；Esc 与焦点归还；打开菜单聚焦首项 |
| `assets/css/nav.css` | 新增 `--ctl-*` 变量组；解 `!important` 锁并放大手机 logo（42/38）；移动端按钮 36→40；用户下拉 208px 统一层；菜单小屏滚动；暗色对比度补强 |
| `VERSION` / `config/version.php` / `includes/bootstrap.php` / `includes/ConfigManager.php` / `api/index.php` / `sw.js` | 版本号升至 2.18.6、PWA 缓存名同步 |
| 18 个 PHP 页面（33 处） | `?v=2.18.6` |
| `CHANGELOG.md` / `README.md` / `UPLOAD_LIST.md` / `FAQ.md` | 文档同步 |

> 约束核对：功能改动仅限 5 个点名前端文件（版本同步与文档属发版必需）；CSS 净增 7,547 字节 ≤40KB、JS 净增 2,607 字节 ≤5KB；不引第三方库、图标全内联 SVG、系统字体栈；**未改任何接口字段与权限**。

> 本地已用 Chromium + axe-core 4.10.3 全量验收，71 项断言 0 FAIL（含明暗双主题、360/390/430 三档、访客态、加载更多、真实星标持久化、Tab/Esc 焦点），逐项结果见 `CHANGELOG.md` → v2.18.6「本地验收结果」与 `v2.18.6-验收日志.txt`；验收工具只装在本地临时目录，不在交付包内。

## 历史变动文件

### v2.18.5 · 8 项遗留问题集中修复（11 个功能文件，无新增文件，无 SQL）

| 文件 | 说明 |
| --- | --- |
| `api/user_sites.php` | 新增 `action=star_toggle` 与 `navUrlKey()` / `navStarCreateFromUrl()`；`add` / `quick_add` 原样不动 |
| `assets/js/nav.js` | `toggleStar()` 统一走 `star_toggle`；`setStatus()` 补 `.site-speed` 节点；筛选栏 `inert` + 老浏览器 `tabindex` 兜底 |
| `includes/site_card.php` | 新增 `navUrlKey()` 与 `$starOverride`，星标按浏览者计算 |
| `index.php` | 登录非 owner 时查自己名下收藏状态按 key 建映射，两处卡片渲染注入 `$starOverride` |
| `api/home_list.php` | `navHomeListShape()` 补测速两参数并传局部变量；同一套星标映射；`Vary` 合并 |
| `assets/css/nav.css` | 新增 `--success-text`；23 处次要文字统一 `--muted`；`body .my-group-tab .mgt-count` 去透明度 |
| `assets/css/admin.css` | `admin.css` 是本次改动最多的文件：主题开关改变量；两组 `:root` 的 `--admin-muted` 抬到 ≥7:1；新增 5 个语义色「文字专用」变量统一覆盖 badge / `.text-*` / outline 按钮；暗色作用域统一收口写死浅底的 `.batch-toolbar-wrap`、`.meta-pill`、`.btn-light`、`.btn-outline-*`、页头下拉按钮、`.btn-primary` 文字；补齐浅色 surface 变量 |
| `admin/sidebar.php` | 内联 `.user-name` / `.user-time` 改后台主题变量 |
| `admin/nav_links.php` | 7 个 `label` 补 `for`、控件补 `id`、隐藏上传与 5 个 `select` 补 `aria-label` |
| `includes/bootstrap.php` | IIS 判重跳过四条头；CSP Report-Only；两处 `Vary` 合并；`APP_VERSION` 升 2.18.5 |
| `web.config` | 加 `Content-Security-Policy-Report-Only`（与 bootstrap 策略一致），注明 IIS 端独占四条安全头 |
| `VERSION` / `config/version.php` / `includes/ConfigManager.php` / `api/index.php` / `sw.js` | 版本号升至 2.18.5、PWA 缓存名同步 |
| 18 个 PHP 页面（33 处） | `?v=2.18.5` |
| `CHANGELOG.md` / `README.md` / `UPLOAD_LIST.md` / `FAQ.md` | 文档同步 |

> 已确认本次**不要动**：全站 h1、canonical 与 og:image、robots.txt、动态 sitemap、自定义 404/500、JSON-LD、sites.php 全站索引、llms.txt、favicon 外链兜底、内网 `skip_check`、首页匿名 60 秒缓存（仅合并了 `Vary`）。

> 本地已用 Chromium + axe-core 4.10.3 全量验收（含后台明暗双主题、390px 手机端、`curl -D -` 响应头、真实点击「加载更多」与星标），逐项结果见 `CHANGELOG.md` → v2.18.5「本地验收结果」。CSS 净增 6,229 字节、JS 净增 1,742 字节，均在约束内；验收工具只装在本地临时目录，不在交付包内。


### v2.18.2 ~ v2.18.4 变动文件

### v2.18.4 · 国内网络适配（30 个文件修改，无新增文件，无 SQL）

| 文件 | 说明 |
| --- | --- |
| `assets/js/search.js` / `index.php` | 搜索引擎菜单与默认引擎去 Google，改搜狗 + `cn.bing.com`；引擎文案从后台配置读取，自定义引擎不再错标 |
| `install/install.sql` / `upgrade_v2.8.0.sql` | 安装与跨版本升级的初始搜索引擎数据同步为国内可达的三个引擎 |
| `my.php` | 图标兜底链去 DuckDuckGo / Google s2，改「站点自身 favicon + `api.iowen.cn`」 |
| `admin/*.php`（12 个后台页） / `includes/asset_fallbacks.php` | CDN 兜底从 cdnjs / jsdelivr 改为 `cdn.bootcdn.net` / `cdn.staticfile.org` |
| `api/check_update.php` | 更新检查改国内镜像优先、官方端点最后兜底，避免国内主机等十几秒超时 |
| `VERSION` / `config/version.php` / `includes/ConfigManager.php` / `includes/bootstrap.php` / `api/index.php` | 版本号升至 2.18.4 |
| 全部 PHP 页面（33 处） | 资源参数 `?v=2.18.4` |
| `CHANGELOG.md` | 追加 v2.18.4 记录 |

### v2.18.3 · 网速展示 + SEO（**新增 5 个文件**，无 SQL）

| 文件 | 说明 |
| --- | --- |
| `sites.php` | **新增**，全部站点索引页（按分类归档，页脚「全部站点」入口） |
| `sitemap-sites.php` | **新增**，数据库实时生成详情页 sitemap |
| `sitemap-pages.xml` | **新增**，静态页 sitemap |
| `includes/site_index_data.php` | **新增**，站点索引 / JSON-LD 共用数据层 |
| `llms.txt` | **新增**，给 AI 搜索看的站点说明 |
| `sitemap.xml` | 改为 sitemap 索引，指向上面两个子 sitemap |
| `index.php` | 首页 `WebSite` + `SearchAction` + `Organization` + `CollectionPage` / `ItemList` JSON-LD；长 meta description；`index.php?q=` 直达搜索；首图 `loading="eager" fetchpriority="high"` |
| `detail.php` | canonical / og / `WebPage` + `BreadcrumbList` JSON-LD；描述补长；标题带分类；图片补 `width` / `height` |
| `includes/site_card.php` / `includes/site_card_build.php` / `assets/js/nav.js` | 卡片显示真实延迟（毫秒 + 快/一般/慢），优先用服务端 `latency_ms` |
| `includes/site_links.php` | 页脚「全部站点」入口 |
| 其余 PHP 页面 / `VERSION` / 版本常量 | 版本号与 `?v=` 升至 2.18.3 |

### v2.18.2 · 全站卡片发灰修复（30 个文件修改，无新增文件，无 SQL）

| 文件 | 说明 |
| --- | --- |
| `assets/js/nav.js` | 探测地址改 `data-url \|\| href`；服务端 `ok` / `slow` 直接采用，仅 `unknown` / `down` 走浏览器实测；`data-state` 映射到 CSS 认可的取值 |
| `assets/css/nav.css` | 状态圆点颜色与 `data-state` 对齐 |
| `includes/Services.php` / `includes/bootstrap.php` / `includes/site_card.php` / `index.php` | 卡片状态属性与渲染配套修正 |
| `api/home_list.php` | 按需加载接口与首页状态口径一致 |
| 全部 PHP 页面 / `VERSION` / 版本常量 | 版本号与 `?v=` 升至 2.18.2 |

### v2.18.4 修订（本次同步，版本号不变，无 SQL）

| 文件 | 说明 |
| --- | --- |
| `install/install.sql` | **补 `skip_check` 列**：此前全新安装的库缺列，写「跳过检测」会报 SQL 错误；`check_status` 的 `skipped` 枚举原本已在 |
| `sw.js` | PWA 缓存名从 `xy-nav-pwa-v1` 改为 `xy-nav-pwa-v2.18.4`，发版时不再沿用旧缓存 |
| `config/version.php` | `APP_RELEASE` / `APP_UPDATE_TIME` 从 2026-11-07 / 11-08 同步到 v2.18.4 的 2026-11-14 |
| `template/mail/*.html`（3 个） / `api/check_update.php` | 邮件模板里遗漏的 `cdnjs.cloudflare.com` 换成 `cdn.bootcdn.net`；更新检查里残留的两条 `jsdelivr` 换成 `ghproxy.net` 镜像，与 v2.18.4 国内适配口径一致 |
| `upgrade_v2.18.1.sql` | 头部注明：v2.18.2 ~ v2.18.4 无需再执行 SQL、全新安装不要执行本脚本 |
| `README.md` / `UPLOAD_LIST.md` / `CHANGELOG.md` | 当前版本、升级规则、版本序列、v2.17.0 回滚说明同步 |
| `tag` / GitHub Releases | 补齐 v2.18.3 / v2.18.4 标签，补发 v2.18.0 ~ v2.18.4 五个 Release 与安装包 |

## 历史：v2.18.1 变动文件（本系列唯一需执行 SQL 的版本）

| 文件 | 说明 |
| --- | --- |
| `404.php` / `500.php` | **新增**，自定义错误页（真实状态码、无敏感信息） |
| `web.config` / `.htaccess` | `httpErrors` / `ErrorDocument` 配置；移除 `X-Powered-By` |
| `robots.txt` / `sitemap.xml` | **新增**，根目录静态 SEO 文件 |
| `api/home_list.php` | **新增**，首页分组按需加载 / 搜索接口（只读、公开字段、`limit` 上限 50、公共缓存 + ETag） |
| `includes/site_card.php` | **新增**，卡片 HTML 模板（首页与 `home_list.php` 共用） |
| `includes/site_card_build.php` | **新增**，`navBuildCard()` / `navStatusLabel()` 从 `index.php` 抽出复用 |
| `includes/Services.php` | 新增 `IconCacheService`（图标本地化、64KB 上限、类型校验、批量补齐）；`HealthCheckService::classifyHost()` 内网识别与 `skipped` 状态 |
| `includes/bootstrap.php` | `navSiteBaseUrl()`、`navPublicCache()`（匿名短缓存 + ETag + 304）、整页空白压缩 `navMinifyHtml()` |
| `index.php` | 每分组首屏 12 条 + 「加载更多」；卡片模板改为 `require`；canonical / og:image；`h1` 与 `alt` / `aria-label` 修正；图标兜底链收敛 |
| `assets/js/nav.js` | 「加载更多」分页；搜索改走 `api/home_list.php`（300ms 防抖）并在清空时还原分组视图 |
| `assets/css/nav.css` / `assets/css/admin.css` | 「加载更多」样式；统一 `:focus-visible` 焦点环；`skipped` 灰色状态 |
| `admin/tools.php` | 新增「本地图标」标签页（批量补齐，进度 / 失败清单） |
| `admin/icon_backfill.php` | **新增**，批量补齐的 AJAX 接口 |
| `admin/nav_links.php` | 「跳过可用性检测」开关；内网条目灰色徽章；图标按钮补 `aria-label` |
| `admin/*.php` | 弹窗关闭、密码显隐等纯图标按钮补 `aria-label`；资源版本号 |
| `api/sites.php` | 返回 / 接受 `skip_check` |
| `api/index.php` | 版本号与公共缓存头 |
| `about.php` / `contact.php` / `apply.php` / `detail.php` / `share.php` / `my.php` | canonical；`alt` / `aria-label`；二维码 `alt` |
| `upgrade_v2.18.1.sql` | **新增**，本版必须执行的 SQL |
| `install/install.sql` | `check_status` 枚举补 `skipped` |
| `cron/daily_check.php` | 汇总增加「跳过 N」 |
| `VERSION` / `config/version.php` / `includes/bootstrap.php` / `includes/ConfigManager.php` | 版本号升至 2.18.1 |
| `CHANGELOG.md` / `README.md` / `UPLOAD_LIST.md` | 文档同步 |

## 上一版 v2.18.0 变动文件（供参考）

| 文件 | 说明 |
| --- | --- |
| `includes/Services.php` | `parseHtml()` 重写：link 标签属性顺序无关、三种引号、rel 多值、协议相对路径、data URI 校验、根路径兜底、按分辨率排序；新增 `htmlAttr()` / `iconSizeScore()` / `iconHref()` / `pushIcon()`；抓取缓存加 `CACHE_PARSER_VERSION` |
| `api/sites.php` | 新增 `navApiTags()` / `navApiFetchLogo()` / `navApiKeywordCategory()`；新增关键词分组层；logo 自动补抓；tags / alias 入库与返回 |
| `assets/js/nav.js` | favicon 改 IntersectionObserver 懒加载；状态探测改「可见才探测」、并发 2、间隔 150–250ms、页面隐藏暂停；新卡片自动接管 |
| `assets/css/nav.css` | 站点卡片 `content-visibility: auto` + 三视图占位高度 |
| `index.php` | PWA 的 manifest / apple-touch-icon 接入与 SW 注册；品牌 Logo 补 `loading="lazy" decoding="async"` |
| `manifest.php` | **新增**，动态 Web App Manifest |
| `sw.js` | **新增**，Service Worker（页面网络优先、静态资源缓存优先、后台与接口不缓存） |
| `offline.html` | **新增**，离线兜底页 |
| `assets/img/icons/icon-192.png`、`icon-512.png`、`maskable-512.png` | **新增**，PWA 图标 |
| `VERSION` / `config/version.php` / `includes/bootstrap.php` / `includes/ConfigManager.php` | 版本号升至 2.18.0 |
| `api/index.php` | 文档页版本徽章 2.18.0 |
| 全部 PHP 页面（16 个文件） | 资源参数 `?v=2.18.0` |
| `CHANGELOG.md`、`README.md`、`UPLOAD_LIST.md` | 文档同步 |

**本版没有 SQL**；新增 6 个文件，其余为既有文件修改。

## 上一版 v2.16.4 变动文件（供跳版本参考）

| 文件 | 说明 |
| --- | --- |
| `api/user_sites.php` | 新增 `userSiteTryChmod()`；三处 `@chmod($target, 0644)` 改为先判 `function_exists('chmod')`，修复受限主机上「文件已落盘但接口返回 500」 |
| `admin/tools.php` | `$neverOverwriteDirs` 移除 `uploads/`，改为精确放行 `uploads/.htaccess` / `uploads/web.config`，其余用户上传文件继续保留；同步修正页面说明文案 |
| `VERSION` / `config/version.php` / `includes/bootstrap.php` / `includes/ConfigManager.php` | 版本号升至 2.16.4 |
| `api/index.php` | 文档页版本徽章 2.16.4 |
| 全部 PHP 页面（31 处） | 资源参数 `?v=2.16.5` |
| `uploads/.htaccess`、`uploads/web.config` | 内容与 v2.16.3 相同，但**必须随本包重新覆盖一次** |
| `CHANGELOG.md`、`README.md`、`UPLOAD_LIST.md` | 文档同步 |

**本版没有新增文件、没有 SQL**，全部为既有文件修改，直接覆盖即可。

## 上一版 v2.16.3 变动文件（供跳版本参考）

| 文件 | 说明 |
| --- | --- |
| `my.php` | 编辑 / 添加 / 快捷收藏三个弹窗新增图标编辑区（预览 + 上传 + URL + 恢复默认）；新增 `createIconEditor()` 工厂函数与配套 CSS；窄屏改上下结构；修复 `data-logo` 为空（漏了 `logo` 键）与「抓取」按钮折行 |
| `api/user_sites.php` | 新增 `upload_logo` 接口：魔数校验、压 256px 转 PNG、SVG 清洗、孤儿文件清理；新增 `userSiteUploadIcon()` / `userSiteIconToPng()` / `userSiteCleanSvg()` / `userSiteDiscardUpload()` |
| `uploads/.htaccess` | 放开 `.svg` 访问并加 `nosniff` + CSP sandbox，其余可执行后缀仍拒绝 |
| `uploads/web.config` | 同上（IIS）：`.svg` 从禁止列表移出，补 `mimeMap` |
| `VERSION` / `config/version.php` / `includes/bootstrap.php` / `includes/ConfigManager.php` | 版本号升至 2.16.3 |
| 全部 PHP 页面（31 处） | 资源参数 `?v=2.16.3` |
| `CHANGELOG.md`、`README.md`、`UPLOAD_LIST.md` | 文档同步 |

**本版没有新增文件、没有 SQL**，全部为既有文件修改，直接覆盖即可。

## 上一版 v2.16.2 变动文件（供跳版本参考）

| 文件 | 说明 |
| --- | --- |
| `my.php` | 新增「AI 分类」按钮 + 三步式弹窗（选范围 / 进度条 / 结果确认）与配套 CSS、JS；补 `.my-dialog-actions` 右对齐样式；`is-new` 标记、列表底部渐隐、全选半选态同步、汇总文案 |
| `api/user_sites.php` | 新增 `ai_categorize_preview`（分页分析，AI 漏答走本地关键词兜底）与 `ai_categorize_apply`（事务落库、越权 403、自动建分组归属调用者、单次上限 300 条）；修复重名分组导致「未分类」误判；兜底建议只采纳真正命中关键词的结果，避免「AI → 未分类」这类空建议 |
| `includes/Services.php` | `AiService` 新增 `categorizeBatch()`：一次请求返回 `id → 分类名` 数组映射，兼容 ```` ```json ```` 包裹与多余文字 |
| `VERSION` / `config/version.php` / `includes/bootstrap.php` / `includes/ConfigManager.php` | 版本号升至 2.16.2 |
| 全部 PHP 页面（31 处） | 资源参数 `?v=2.16.2` |
| `CHANGELOG.md`、`README.md`、`UPLOAD_LIST.md` | 文档同步 |

**本版没有新增文件、没有 SQL**，全部为既有文件修改，直接覆盖即可。

## 上一版 v2.16.0 变动文件（供从 v2.15.2 跳版本时参考）

| 文件 | 说明 |
| --- | --- |
| `upgrade_v2.16.0.sql` | **新增文件**，两个复合索引，幂等 |
| `admin/nav_links.php` | 批量栏移到列表顶部并 sticky；新增「批量转移归属」「批量复制归属」（事务 + 跨归属分类重挂到目标用户「未分类」）；新增回收站视图、逐条恢复 / 彻底删除 / 一键清空（彻底删除连带清理 logo 文件）；补 `Services.php` 引入修复 `CategoryEngine not found` |
| `my.php` | 前台回收站：顶部按钮 + 数量角标 + 弹窗（全选 / 批量恢复 / 批量彻底删除 / 一键清空 / 逐条操作），所有操作强制 `WHERE user_id = ?` |
| `apply(1).php`（`apply.php` 引用它） | 手机端适配：输入框 `min-width:0`、logo 文件选择行重排、卡片内边距与按钮 44px；横向溢出 55px → 0 |
| `assets/css/page.css` | `max-width: 620px` 断点：`.page-actions` 纵向排列、按钮全宽、长串 `word-break`、输入框 `min-width:0` |
| `includes/functions.php` | `isLoggedIn()` 打通前后台会话；新增 `findAdminUserById()`，只认数据库 `is_admin=1` 且未封禁 |
| `logout.php` | 修复登出残留：前台打通的会话需一并清理 `admin_id / admin_username / last_activity` |
| `includes/Services.php` | 新增 `AiService::recommendCategory()`：允许 AI 给出候选外的新分类名并标记 `is_new` |
| `api/sites.php` | POST 新增 `category_name` / `ai_recommend` / `allow_ai_fallback`；优先级 `category_id > category_name > ai_recommend > 未分类`；返回新增 `category_id` / `category_name` / `category_source` |
| `api/user_sites.php` | `quick_preview` 支持 AI 新分类建议（只建议不建）；`quick_add` 支持 `category_name`，采纳时才建 |
| `api/index.php` | 文档新增「快速入门」「方法伪装」「分类管理」三大章节；参数表补三个新参数；错误码补 `400 ai_disabled` |
| `index.php` | pinyin-pro 改按需加载（`window.NAV_LOAD_PINYIN` + 首次交互预热）；快捷收录新增内联「新建分组」输入框；卡片 `--enter-index` 收窄 |
| `assets/js/nav.js` | 拼音检测改运行时（`pinyinSupportedNow()`）；监听 `xy:pinyin-ready` 清缓存并重算过滤 |
| `assets/js/search.js` | 复用首页按需加载函数；新增就绪回调机制，库到达后自动重算搜索 |
| `assets/css/nav.css` | `@media (hover: none) and (pointer: coarse)` 下操作按钮彻底隐藏；卡片入场动画降级；`prefers-reduced-motion` 全关 |
| `includes/bootstrap.php` | 新增 `navMaybeGzip()` 输出压缩兜底 |
| `install/install.sql` | 建表语句同步两个新索引（全新安装用） |
| 全部 PHP 页面（31 处） | 资源参数 `?v=2.16.0` |
| `VERSION` / `config/version.php` / `includes/bootstrap.php` / `includes/ConfigManager.php` | 版本号升至 2.16.0 |
| `CHANGELOG.md`、`UPLOAD_LIST.md` | 文档同步 |

**本版仅新增 1 个文件**（`upgrade_v2.16.0.sql`），其余为既有文件覆盖。无 `web.config` / `.htaccess` 改动，对外 API 契约向后兼容。

## 本次实测数据（本地 PHP 8.3 + MySQL 8.0 + Chromium）

| 项目 | 优化前 | 优化后 |
| --- | --- | --- |
| 首屏 JS（3G 节流） | 533KB | **217KB** |
| 首页响应体 | 341,606 字节 | **23,224 字节（gzip，省 93%）** |
| 卡片入场动画 | 最多 24 张 × 26ms | 最多 6 张 × 22ms |
| 布局偏移 CLS | 0 | **0**（卡片本就服务端直出，无需骨架屏） |
| 手机端卡片操作按钮 | 可见 | **grid / list / compact 三视图 240 个按钮全部隐藏** |
| 拼音搜索 | 可用 | **可用**（全拼 `tianqiyubao`、简拼 `tqyb` 均命中） |

## 历史：v2.15.2 变动文件（回顾）

| 文件 | 说明 |
| --- | --- |
| `includes/Services.php` | `postJson`、站点健康检测（HEAD/GET）、站点下载重试统一 `CURLOPT_SSL_VERIFYPEER=false` / `CURLOPT_SSL_VERIFYHOST=0`，与站点抓取一致 |
| `my.php` | 新增前台用户 API 令牌管理：顶部「API 令牌」按钮 + 弹窗；后端创建/切换权限/删除/重置，复用 `api_tokens` 表、绑定 `user_id`；明文仅显示一次、库内存 SHA-256；列表/操作/过期选项/API 说明/复制按钮 |
| `api/index.php` | API 文档 fallback 版本 2.15.2 |
| 全部 PHP 页面（30 处） | 资源参数 `?v=2.15.2` |
| `VERSION` / `config/version.php` / `includes/bootstrap.php` / `includes/ConfigManager.php` | 版本号升至 2.15.2 |
| `CHANGELOG.md`、`README.md`、`UPLOAD_LIST.md` | 文档同步 |

**v2.15.2 没有新增文件**，全部为既有文件修改，直接覆盖即可。

## 历史：v2.14.1 变动文件（回顾）

| 文件 | 说明 |
| --- | --- |
| `api/category_create.php` | 新增 `scope=admin\|front`；前台强制归属当前用户、忽略传入 `user_id`；管理员分支需后台会话 + 后台来源；兼容不带 `scope` 的旧脚本 |
| `assets/js/category-quick-add.js` | 提交按 `mode` 自动携带 `scope` |
| `api/user_sites.php`（6 处） | 自动补建分组前 `setUserId($userId)` |
| `includes/Services.php`（2 处） | 书签导入 / 分享导入按导入者归属 |
| `api/sites.php`（2 处） | 用户级令牌补建分类归属令牌用户 |
| `my.php` | `refreshCategorySelects()` 覆盖 5 个下拉；新增顶部分组 tab 栏与 `syncGroupUI()`；`renderGroupList()` 高亮新项；数字 id 归一化；弹窗显式 `scope=front` |
| `assets/css/nav.css` | 手机端 `.card-actions` / `.site-card.is-actions-open .card-actions` 强制隐藏 |
| `index.php` | 长按菜单新增非 `ccm-owner` 的「收藏」按钮 |
| `assets/js/nav.js` | `openCtxMenu()` 按 `data-starred` 切换收藏文案 |
| `api/index.php` | 文档补 `scope` 说明；fallback 版本 2.14.0 |
| 全部 PHP 页面（30 处） | 资源参数 `?v=2.14.0` |
| `VERSION` / `config/version.php` / `includes/bootstrap.php` / `includes/ConfigManager.php` | 版本号升至 2.14.0 |
| `CHANGELOG.md`、`README.md`、`UPLOAD_LIST.md` | 文档同步 |

**本版没有新增文件**，全部为既有文件修改，直接覆盖即可。

### v2.14.0 升级要点

1. 覆盖代码文件 → 清理 `cache/` → 前台强制刷新（Ctrl+F5）；**不需要执行任何 SQL**。
2. 推荐用后台「系统工具 → 在线更新」上传 `XYdaohang-2.14.0.zip`（自动备份被覆盖文件，跳过数据库配置 / uploads / cache / backups）。
3. **历史误建为公共的分组需手动修正**：后台 → 分类管理 → 编辑 → 「归属用户」改回对应用户。本版不会自动改动已有分类数据。
4. 最快验证：`/api/index.php` 徽章显示 `v2.14.0`；前台登录后在「我的导航」新建分组，确认 5 个弹窗下拉与顶部 tab 立即出现该分组；手机端长按卡片菜单含「收藏 / 取消收藏」且卡片无常驻操作条。

### v2.14.0 验证记录

| 项目 | 结果 |
| --- | --- |
| 分组归属隔离（双会话 / 伪造 scope / 自动补建 / 书签导入） | 12/12 通过 |
| `my.php` 5 个下拉 + tab + 列表 + 分组区即时同步 | 全通过（含新建 / 改名 / 私密 / 删除 / 快捷入口） |
| 手机端操作条隐藏 + 长按菜单星标 + 桌面端 hover 回归 | 全通过 |
| v2.13.0 / v2.12.0 / v2.11.0 存量回归 | 106 / 73 / 49 全通过 |
| `php -l` 全项目 / 内联 JS `node --check` | 全部通过 |

---

## v2.13.0 回顾（以下为历史部署清单）

### v2.13.0 变动文件

| 文件 | 说明 |
| --- | --- |
| `api/sites.php` | 跨域预检与 CORS、令牌摘要校验（兼容明文）、DELETE 不再异常硬删、`user_id` 存在性校验、分类归属校验、LIKE 通配符转义、非法 JSON `invalid_json`、权限值与过期时间健壮性 |
| `admin/tools.php` | 新建令牌只存 SHA-256 摘要；列表显示摘要前缀；过期判断不再误判脏数据 |
| `api/check_update.php` | CORS 由「反射任意 Origin + Credentials」改为同主机白名单 |
| `api/stats.php` | 仅允许 GET，其余 405 |
| `api/ai_test.php` | 失败响应统一 `{ok,error,message}` 与合理 HTTP 状态码 |
| `includes/functions.php` | 新增 `mergeJsonBody()`，站内 POST 接口统一支持 JSON 请求体 |
| `api/track_click.php` | 同源校验 + 同会话 2 秒去重 |
| `api/site_meta.php` | 同源校验，防止被当作第三方抓取代理 |
| `api/share.php` | 浏览量按访客 60 秒去重 |
| `api/ai_suggest.php`、`api/auth.php`、`api/bookmark_import.php`、`api/category_create.php`、`api/health_check.php`、`api/user_categories.php`、`api/user_search_engines.php` | 接入统一 JSON 请求体解析 |
| `api/index.php` | 文档新增「跨域与预检」「站内接口一览」，补错误码与注意事项；fallback 版本 2.13.0 |
| 全部 PHP 页面（30 处） | 资源参数 `?v=2.13.0` |
| `VERSION` / `config/version.php` / `includes/bootstrap.php` / `includes/ConfigManager.php` | 版本号升至 2.13.0 |
| `CHANGELOG.md`、`README.md`、`UPLOAD_LIST.md` | 文档同步 |

**本版没有新增文件**，全部是对既有文件的修改，直接覆盖即可。

## 修复的 9 个真实缺陷

| # | 缺陷 | 影响 |
| --- | --- | --- |
| 1 | `OPTIONS` 预检返回 405 | 浏览器跨域调用 API 直接不可用 |
| 2 | `DELETE` 任何异常都退化为 `DELETE FROM` | 数据库抖动会永久删除本应可恢复的数据 |
| 3 | 管理级令牌可写任意 `user_id` | 产生永远查不到的孤儿数据 |
| 4 | 用户级令牌可挂到公共/他人分类 | 越权关联，详情接口会带出分类名 |
| 5 | `check_update.php` 反射任意 Origin + Credentials | 任意第三方站点可带管理员会话读取结果 |
| 6 | `q` 中 `%` / `_` 未转义 | `q=%` 返回全部记录，搜索结果失真 |
| 7 | 非法 JSON 静默当空参数 | 报错「url 不能为空」，排查方向跑偏 |
| 8 | `ai_test.php` 失败只回 `message` 且 HTTP 200 | 无稳定机器码，调用方只能匹配文案 |
| 9 | `track_click` / `site_meta` 无来源校验、浏览量无去重 | 可被跨站刷点击量、当免费抓取代理、刷分享浏览量 |

另修：令牌明文存库（改摘要）、`stats.php` 不校验方法、`permissions` 未白名单、`strtotime` 脏数据误判过期。

## 升级要点

1. **直接覆盖代码文件即可**，不需要执行任何 SQL，不需要改 `web.config` / `.htaccess`。
2. `api_tokens` 表结构不变：**旧版明文令牌继续可用**，新版创建的令牌只存摘要。数据库/备份泄漏不再直接暴露可用令牌。
3. 覆盖后清理 `cache/` 目录，前台强制刷新（Ctrl+F5）——本版改了 30 处 `?v=`。
4. **推荐用后台「系统工具 → 在线更新」上传整包**：接口会跳过 `config/database.php`、`config/smtp.php`、`config/config.php`、`install/install.lock`、`.env` 及 `backups/`、`cache/`、`uploads/` 等目录，并自动备份被覆盖的原文件。
5. 最快验证：访问 `/api/index.php`（零依赖，永远可用），徽章应显示 `v2.13.0`；`curl -X OPTIONS -i /api/sites.php` 应返回 `204` 且带 `Access-Control-Allow-Origin`；`sites.php` 无令牌仍返回 `401 unauthorized`。
6. 若线上有第三方前端跨域调用，现在可直连，无需代理；令牌仍须由调用方自己保管。
7. **IIS 10 主机**：`OPTIONS` 预检可能被 `OPTIONSVerbHandler` 接管，此时跨域简单 GET（令牌放 `?token=`）与服务端调用仍可用，带 `Authorization` 头的浏览器写请求需在主机面板把 `OPTIONS` 交给 PHP handler。`/api/` 文档页已写明；未改动根 `web.config`，避免重蹈 500.19。

## 验证记录

| 项目 | 结果 |
| --- | --- |
| API 专项审计（本地 20 端点 + 线上只读） | 106/106 通过 |
| v2.12.0 存量回归 | 73/73 通过 |
| v2.11.0 存量回归 | 49/49 通过 |
| 完整 REST CRUD（表单 + JSON、GET/POST/PUT/PATCH/DELETE） | 全部通过 |
| 越权 / 非法参数 / 非法 JSON / 过期令牌 / CORS 预检 | 全部通过 |
| `php -l` 全项目 / 内联 JS `node --check` | 全部通过，脚本级 JS 错误 0 |

## v2.12.0 回顾

三项功能补齐 + 一处体验修正：AI 分类配置「测试连接」、全站统一「添加分类 / 新建分组」入口、全站底部快捷入口与 API 说明；顺带修复首页分类索引过滤掉本人空分组的问题。本地回归 73/73。无数据库变更。

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
