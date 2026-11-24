# 轩宇-导航 v2.19 升级 · 基线测量报告

> 测量时间：2026-11-24 · 站点 http://dh.pxyblog.top · 分支 feat/upgrade-2.19
> 起点版本 2.18.27（确认：资源 URL ?v=2.18.27 / config/version.php / VERSION）
> 本地树与远端 main 一致（tree c08217160b96）

## 1. curl 计时（10 次，无缓存参数）
- HTTP 200 ×10；TTFB 0.18–3.201s（中位 0.203s）；total 0.405–9.033s（中位 1.389s）
- 说明：沙箱到主机链路抖动较大，与题给基线 TTFB 0.28–1.30s 同量级上沿。
- 原始命令：`curl -s -o /dev/null -w '%{http_code} %{time_starttransfer} %{time_total} %{size_download}'`

## 2. 体积（线上实际取回 / 本地文件）
| 资源 | 原始 B | gzip B |
|---|---:|---:|
| 首页 HTML index.php | 113,433 | 20,426 |
| nav.css | 293,682 | — |
| apple-tokens.css | 34,492 | — |
| category-quick-add.css | 6,570 | — |
| **CSS 合计** | **334,744** | **82,615** |
| nav.js | 201,010* | — |
| search.js | 58,772 | — |
| category-quick-add.js | 12,876 | — |
| **JS 核心合计** | **272,658** | **65,308** |
| pinyin-pro.min.js（首次输入注入） | 322,812 | — |

\* 本地 nav.js 201,010B 与题给 178,707B 有差异（题给基线为 2026-11-23，期间可能有改动）；以实测为准。

## 3. 结构统计
- nav.css：9,750 行 / 1,365 规则块 / 104 @media / **630 !important** / :root ×9
- nav.js：5,598 行 / 91 addEventListener / 17 innerHTML / 12 fetch

## 4. 浏览器实测（5 视口 × 明暗，见 shots/）
| 视口 | hOverflow | FCP(ms) | 首屏请求 | 首屏传输 B | 总请求 | 真裂图 |
|---|---|---:|---:|---:|---:|---:|
| 390 light | false | 312 | 10 | 149,841 | 12 | 0* |
| 1440 light | false | 180 | 9 | 149,841 | 10 | 0* |

\* 沙箱无头浏览器不加载外站图标域名，真裂图/第三方域名计数以题给基线（22 域名 / 软404 8 个）为准，此处仅留档。

- 全部 10 视口 hOverflow=false → 印证 A4「是裁切不是溢出，别改 overflow」。
- 1440 检出 3 个 .hot-name 文字被 ellipsis 截断（属设计内单行省略，非硬伤）。

## 5. 权威基线（题给 2026-11-23，改后对比基准）
- 首页 HTML 113,433B/gzip 20,546B；CSS 334,744B/gzip 128KB；JS 250KB + pinyin 323KB
- 1440 FCP/LCP 1,844ms、DCL 2,651ms、load 4,597ms、首屏 9 请求/424KB
- 移动全滚动 52 请求/798KB/22 第三方域名；图标 68 URL 合计 3.54MB
- nav.css 9,749 行/1,365 规则/104 media/630 !important/30 重复块/:root×9
- nav.js 5,597 行/403 函数/91 监听/17 innerHTML/12 fetch

## 目标（E.2）
HTML≤80KB/gz≤16KB · CSS≤180KB/gz≤60KB · JS≤150KB/gz≤50KB · 首屏≤9请求/≤250KB · FCP/LCP≤1200ms · 移动全滚动≤30请求/≤500KB/≤8域名 · 裂图0 · 320裁切0 · 热区<44px 为0
