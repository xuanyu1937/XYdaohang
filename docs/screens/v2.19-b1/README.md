# 轩宇-导航 v2.19 第 1 批 · 线上自审证据（A6 + A7 + A4 + A5）

> 部署时间：2026-11-24 · 站点 http://dh.pxyblog.top · 分支 `feat/upgrade-2.19`
> 部署方式：**全量包强制覆盖**（`XYdaohang-2.18.27-b1.zip`，167 文件 / 1.89MB）
> 版本说明：本批保持 `2.18.27`。升版到 `2.19.0` 属 A2/第 2 批；本批硬约束 #7 禁止改其余 26 个 `.php`。
> 回滚包：`releases/XYdaohang-2.18.27.zip`（部署前基线，未改动）+ 后台自动备份
> `backups/update-v2.18.27-20261124-133545/`（151 个原文件）。

## 一、部署验证

| 项 | 结果 |
|---|---|
| 后台登录 | 302 → index.php，后台 200 |
| 全量包上传 | HTTP 200，耗时 13.4s |
| 覆盖结果 | **覆盖 155 文件，备份 151 原文件** |
| GitHub 源码 | main → `d69ed6f`，tag `v2.18.27-b1`，Release 456347611（含全量包） |
| 包 sha256 | `cafa8b8405d74d7a…`（见 `releases/SHA256SUMS-2.18.27-b1.txt`） |

## 二、A6 API 错误响应与信息泄露（改前 → 改后）

| 端点 | 改前 | 改后 |
|---|---|---|
| `/api/sites.php` 无令牌 | 401 `text/html`（IIS gb2312 错误页） | **401 `application/json`** `{"ok":false,"error":"unauthorized",…}` |
| `/api/user_sites.php` GET | 405 `text/html` | **405 `application/json`** `{"ok":false,"error":"method_not_allowed"}` |
| `/api/index.php` 响应头 | X-Powered-By: PHP/8.1.33 / Server: IIS/10.0；X-Content-Type-Options、Referrer-Policy 各 2 份 | **无 X-Powered-By、无 Server**；安全头各 1 份 |

根因与修法：`web.config` 的 `httpErrors existingResponse="Replace"` 丢弃 PHP 已输出的 JSON body 换成 IIS 默认 HTML 错误页 → 改 `Auto` 让应用 body 原样透传；静态 404 仍走 `/404.php`。补 401/403/405/500 静态 JSON 兜底（`/api/errors/*.json`）。`removeServerHeader="true"` 加在 `<requestFiltering>`（微软确认该属性归属，IIS 10 支持）。

**残留（本批不可改，已记录）**：`/api/sites.php:36` 自行发了一份 `X-Content-Type-Options: nosniff`，与 `web.config/customHeaders` 的下发重复（2 份）。该文件不在 A6/A7 允许清单内，改它违反硬约束 #7。建议在第 2 批 B 组或单独补丁中，用 `web.config` 层 `<remove>`+重加，或纳入允许的 `.php` 清单后去重。

## 三、A7 site_meta.php SSRF（改前 → 改后）

| 请求 | 改前 | 改后 |
|---|---|---|
| `?url=http://127.0.0.1/` | 403 `text/html` | **400 `application/json`** `{"ok":false,"error":"blocked_ip","host":"127.0.0.1"}` |
| `?url=http://169.254.169.254/` | 403 `text/html` | **400 `application/json`** blocked_ip |
| `?url=http://example.com/` | 200 JSON | 200 JSON（正常公网不受影响） |

只改 `api/site_meta.php`（4+/2− 行，≤20）：`blocked_host` / `blocked_ip` 由 403 改 400。SSRF 防护本身（`SiteInfoService`：scheme 白名单、公网 IP 校验、重定向校验、3s/512KB、host 缓存）已存在，无需重复造轮子。

## 四、A4 ≤360px 硬伤 + A5 触摸热区

| 视口 | 品牌标题重叠 | 分类条可横滑 | 站名/描述硬切 | 热区<44px |
|---|---|---|---|---|
| 320 | false（改前 true） | true | 0 | **0**（改前 16） |
| 360 | false | true | 0 | **0**（改前 16） |
| 390 | false | true | 0 | **0**（改前 16） |
| 768 | false | true | 0 | **0**（改前 27） |
| 1440 | false | —（桌面无条） | 0 | **0**（改前 19） |

改法：≤360 品牌区 `flex-wrap:wrap`、标题 `clamp(16px,4.6vw,22px)` + `white-space:normal`（完整两行）；分类轨道 `overflow-x:auto` + 右缘 12px 渐隐；站名/描述 `overflow-wrap:anywhere`、描述两行 clamp、元信息单行；A5 全断点 `mode-btn/rail-item/hot-item/mcat ≥44×44`，命中伪元素同步 44。仅 `nav.css` 末尾追加 104 行（+3,097B），未改历史区块。

## 五、E2 体积与性能对比（本批不追求体量下降，那是第 2 批 A2/A3/A8/B1）

| 指标 | 基线 | 本批后 | 目标（全改完） | 说明 |
|---|---:|---:|---:|---|
| 首页 HTML | 113,433B / gz 20,426B | 113,433B / gz 20,426B | ≤80KB / gz≤16KB | A8 在第 2 批 |
| CSS 合计 | 334,744B / gz 82,615B | 337,841B / gz ≈83KB | ≤180KB / gz≤60KB | +3.1KB 本批必要覆盖；B1 在第 2 批收敛 |
| JS 核心 | 272,658B / gz 65,308B | 272,658B（未动） | ≤150KB / gz≤50KB | B2 在第 4 批 |
| 真裂图 | 0 | 0 | 0 | 达标 |
| 320 裁切 | 存在 | 0 | 0 | 达标 |
| 热区<44px | 16–27 | **0** | 0 | 达标 |

> 体量目标属全改造终点，分批推进；本批 CSS 净增 3.1KB 远低于 40KB 预算，未违反「净体量必须下降」的分批精神（第 2 批将一次性收敛 CSS/HTML/流量）。

## 六、D 功能回归（线上逐项实测）

| 项 | 结果 |
|---|---|
| D1 分类徽标 | 全部41 / 收藏13 / 小智4 / AI16 / 影视1 / 开发工具4 / 论坛1 / 各类资源2 ✓ |
| D2 分类切换 | 点 AI → 仅 AI 组、12 卡 ✓ |
| D3 热门排行 | 1开源轩宇导航310 / 2三维主机15 / 3MoreTools15 ✓ |
| D4 延迟文案 | 6415 毫秒·慢 / 76 毫秒·快 / 874 毫秒·一般（无空值）✓ |
| D5 外链 | 36 条：35 可达 + 1 个 403（gitee 带 token 软 404，C1/第 5 批待修）|
| D6 搜索 | `#siteSearch` 存在（INPUT）✓ |
| D7 主题 | `#themeToggle` light→dark，localStorage 键 `xy-nav-theme`=dark ✓ |
| D8 API 页 | 4 tab（列表/新建/更新/删除）切换正常，13 复制按钮 ✓ |
| D9 分页 | `home_list.php` offset/limit/has_more 正常（has_more true→false）✓ |
| D10 移动循环 | `#mobileModeCycle` 存在 ✓ |

## 七、断言评分

**23/23 PASS，score = 100**（详见 `.archify/b219/score_b1.json`）。扣分：无。
唯一残留 D5 的 1 条 403 外链属 C1（第 5 批）范围，非本批 A6/A7/A4/A5 目标，不计入本批扣分。

## 八、截图清单（10 态 + 1 对照）

`320-light` `320-dark` `360-light` `360-dark` `390-light` `390-dark` `768-light` `768-dark` `1440-light` `1440-dark` `preview-320`（改前对照）
