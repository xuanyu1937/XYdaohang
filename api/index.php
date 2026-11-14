<?php
/**
 * 轩宇-导航 · 开放 API 文档
 *
 * 放在 api/index.php，让 /api/ 由「403 禁止列目录」变成一个可读的说明页。
 * 刻意不 require bootstrap.php：不连数据库、不开会话、不依赖站点设置，
 * 即使数据库暂时不可用，文档依然能打开。
 *
 * 本页只输出静态说明，不读取也不展示任何令牌。
 */

/* v2.18.1 · 静态文档页给一份公共短缓存：不依赖会话，缓存 5 分钟足够，
   也避免每次刷新都重新走一遍 PHP。 */
header('Cache-Control: public, max-age=300');

$version = '2.18.3';
$versionFile = __DIR__ . '/../VERSION';

if (is_readable($versionFile)) {
    $rawVersion = trim((string) @file_get_contents($versionFile));

    if (preg_match('/^\d+\.\d+\.\d+$/', $rawVersion)) {
        $version = $rawVersion;
    }
}

// 只用于生成 curl 示例里的绝对地址；严格校验 Host，避免响应头注入
$host = (string) ($_SERVER['HTTP_HOST'] ?? '');
if (!preg_match('/^[A-Za-z0-9.\-]+(:\d{1,5})?$/', $host)) {
    $host = 'your-domain.com';
}
$scheme = (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off') ? 'https' : 'http';
$baseUrl = $scheme . '://' . $host . '/api/';
$esc = static function ($value) {
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
};

header('Content-Type: text/html; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: strict-origin-when-cross-origin');
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="robots" content="noindex, nofollow">
<link rel="icon" href="data:image/svg+xml,%3Csvg%20xmlns%3D%27http%3A%2F%2Fwww.w3.org%2F2000%2Fsvg%27%20viewBox%3D%270%200%2064%2064%27%3E%3Crect%20width%3D%2764%27%20height%3D%2764%27%20rx%3D%2714%27%20fill%3D%27%231d4ed8%27%2F%3E%3Cpath%20d%3D%27M25%2020c-6%200-8%203-8%208v2c0%203-1%204-4%204%203%200%204%201%204%204v2c0%205%202%208%208%208M39%2020c6%200%208%203%208%208v2c0%203%201%204%204%204-3%200-4%201-4%204v2c0%205-2%208-8%208%27%20fill%3D%27none%27%20stroke%3D%27white%27%20stroke-width%3D%274%27%20stroke-linecap%3D%27round%27%2F%3E%3C%2Fsvg%3E">
<title>开放 API 文档 · 轩宇导航</title>
<?php /* v2.18.1 · canonical（doc 页只有这一个地址，无查询参数变体） */ ?>
<link rel="canonical" href="<?php echo $esc(str_replace('/api/', '/api/index.php', $baseUrl)); ?>">
<style>
:root {
    --primary: #1d4ed8;
    --primary-soft: rgba(29, 78, 216, .09);
    --primary-border: rgba(29, 78, 216, .22);
    --bg: #f7f8fa;
    --surface: #ffffff;
    --surface-2: #f2f4f7;
    --text: #1b1f27;
    --muted: #61656e;
    --faint: #8a8f99;
    --border: #e5e7ec;
    --border-strong: #d3d7df;
    --code-bg: #111827;
    --code-text: #e5e7eb;
    --shadow: 0 1px 2px rgba(16, 24, 40, .04), 0 8px 24px rgba(16, 24, 40, .05);
    --radius: 12px;
}
:root[data-theme="dark"] {
    --primary: #a78bfa;
    --primary-soft: rgba(167, 139, 250, .12);
    --primary-border: rgba(167, 139, 250, .3);
    --bg: #0e1117;
    --surface: #161b23;
    --surface-2: #1d232d;
    --text: #e7e9ee;
    --muted: #a0a6b2;
    --faint: #767d8a;
    --border: #272e3a;
    --border-strong: #363f4d;
    --code-bg: #0a0d12;
    --code-text: #dfe3ea;
    --shadow: 0 1px 2px rgba(0, 0, 0, .3), 0 8px 24px rgba(0, 0, 0, .24);
}
@media (prefers-color-scheme: dark) {
    :root:not([data-theme]) {
        --primary: #a78bfa;
        --primary-soft: rgba(167, 139, 250, .12);
        --primary-border: rgba(167, 139, 250, .3);
        --bg: #0e1117;
        --surface: #161b23;
        --surface-2: #1d232d;
        --text: #e7e9ee;
        --muted: #a0a6b2;
        --faint: #767d8a;
        --border: #272e3a;
        --border-strong: #363f4d;
        --code-bg: #0a0d12;
        --code-text: #dfe3ea;
        --shadow: 0 1px 2px rgba(0, 0, 0, .3), 0 8px 24px rgba(0, 0, 0, .24);
    }
}
* { box-sizing: border-box; }
html { scroll-behavior: smooth; scroll-padding-top: 84px; }
body {
    margin: 0;
    background: var(--bg);
    color: var(--text);
    font: 15px/1.7 -apple-system, BlinkMacSystemFont, "Segoe UI", "PingFang SC", "Hiragino Sans GB", "Microsoft YaHei", sans-serif;
    -webkit-font-smoothing: antialiased;
}
a { color: var(--primary); text-decoration: none; }
a:hover { text-decoration: underline; }
code, pre, .mono { font-family: ui-monospace, SFMono-Regular, "SF Mono", Menlo, Consolas, "Liberation Mono", monospace; }

.topbar {
    position: sticky; top: 0; z-index: 20;
    background: color-mix(in srgb, var(--surface) 88%, transparent);
    backdrop-filter: saturate(180%) blur(12px);
    border-bottom: 1px solid var(--border);
}
.topbar-inner {
    max-width: 1120px; margin: 0 auto; padding: 12px 24px;
    display: flex; align-items: center; gap: 12px;
}
.mark {
    width: 30px; height: 30px; flex: 0 0 30px; border-radius: 9px;
    background: linear-gradient(135deg, #1e3a8a, #1d4ed8);
    display: grid; place-items: center; color: #fff; font-size: 15px; font-weight: 700;
}
.brand-text { font-weight: 650; letter-spacing: -.01em; white-space: nowrap; }
.brand-sub { color: var(--faint); font-size: 12.5px; border-left: 1px solid var(--border-strong); padding-left: 12px; }
.ver {
    font-size: 11.5px; padding: 2px 8px; border-radius: 999px;
    background: var(--primary-soft); color: var(--primary);
    border: 1px solid var(--primary-border); font-weight: 600;
}
.topbar-spacer { flex: 1 1 auto; }
.icon-btn {
    width: 34px; height: 34px; display: grid; place-items: center;
    border: 1px solid var(--border-strong); border-radius: 9px; background: var(--surface);
    color: var(--text); cursor: pointer; line-height: 1;
}
.icon-btn:hover { color: var(--primary); border-color: var(--primary-border); background: var(--primary-soft); }
.icon-btn svg { display: none; }
:root[data-theme="light"] .icon-btn .i-moon,
:root:not([data-theme]) .icon-btn .i-moon { display: block; }
:root[data-theme="dark"] .icon-btn .i-sun { display: block; }
@media (prefers-color-scheme: dark) {
    :root:not([data-theme]) .icon-btn .i-moon { display: none; }
    :root:not([data-theme]) .icon-btn .i-sun { display: block; }
}
.back-link { font-size: 13px; white-space: nowrap; }

.layout { max-width: 1120px; margin: 0 auto; padding: 0 24px 80px; display: grid; grid-template-columns: 194px minmax(0, 1fr); gap: 40px; }
.toc { position: sticky; top: 76px; align-self: start; padding-top: 40px; }
.toc-title { font-size: 11px; letter-spacing: .1em; text-transform: uppercase; color: var(--faint); font-weight: 700; margin: 0 0 10px 12px; }
.toc a {
    display: block; padding: 5px 12px; font-size: 13.5px; color: var(--muted);
    border-left: 2px solid transparent; border-radius: 0 6px 6px 0;
}
.toc a:hover { color: var(--text); background: var(--surface-2); text-decoration: none; }
.toc a.is-active { color: var(--primary); border-left-color: var(--primary); font-weight: 600; background: var(--primary-soft); }

.main { min-width: 0; padding-top: 40px; }
.hero h1 { font-size: clamp(27px, 4vw, 36px); line-height: 1.25; letter-spacing: -.025em; margin: 0 0 10px; }
.hero p { color: var(--muted); margin: 0 0 22px; max-width: 660px; font-size: 15.5px; }
.basebar {
    display: flex; align-items: center; gap: 10px; flex-wrap: wrap;
    background: var(--surface); border: 1px solid var(--border); border-radius: var(--radius);
    padding: 10px 12px 10px 16px; box-shadow: var(--shadow); margin-bottom: 22px;
}
.basebar-label { font-size: 11px; font-weight: 700; color: var(--faint); letter-spacing: .08em; text-transform: uppercase; }
.basebar code { font-size: 13px; color: var(--text); word-break: break-all; flex: 1 1 240px; }
.copy-btn {
    border: 1px solid var(--border-strong); background: var(--surface); color: var(--text);
    border-radius: 8px; padding: 5px 11px; font-size: 12px; cursor: pointer; white-space: nowrap;
    font-weight: 600;
}
.copy-btn:hover { color: var(--primary); border-color: var(--primary-border); background: var(--primary-soft); }
.copy-btn.is-ok { color: #15803d; border-color: rgba(21, 128, 61, .35); }

.notice {
    display: flex; gap: 12px; padding: 14px 16px; border-radius: var(--radius);
    background: var(--primary-soft); border: 1px solid var(--primary-border);
    color: var(--text); font-size: 14px; margin-bottom: 40px;
}
.notice-icon { flex: 0 0 auto; font-size: 16px; line-height: 1.5; }
.notice strong { color: var(--primary); }

section { scroll-margin-top: 84px; margin-bottom: 44px; }
h2 { font-size: 20px; letter-spacing: -.015em; margin: 0 0 6px; padding-bottom: 10px; border-bottom: 1px solid var(--border); }
.sec-desc { color: var(--muted); font-size: 14px; margin: 14px 0 18px; }
h3 { font-size: 15px; margin: 24px 0 10px; }
p { margin: 10px 0; }

.steps { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 12px; }
.step { background: var(--surface); border: 1px solid var(--border); border-radius: var(--radius); padding: 16px; box-shadow: var(--shadow); }
.step-num { width: 24px; height: 24px; border-radius: 7px; background: var(--primary-soft); color: var(--primary); display: grid; place-items: center; font-size: 12px; font-weight: 700; margin-bottom: 10px; }
.step h4 { margin: 0 0 6px; font-size: 14px; }
.step p { margin: 0; font-size: 13px; color: var(--muted); }
.step code { font-size: 12px; background: var(--surface-2); padding: 1px 5px; border-radius: 4px; white-space: nowrap; }

.table-wrap { overflow-x: auto; border: 1px solid var(--border); border-radius: var(--radius); background: var(--surface); }
table { width: 100%; border-collapse: collapse; font-size: 13.5px; }
th, td { text-align: left; padding: 10px 14px; border-bottom: 1px solid var(--border); vertical-align: top; }
th { background: var(--surface-2); font-size: 12px; letter-spacing: .03em; color: var(--muted); font-weight: 650; white-space: nowrap; }
tr:last-child td { border-bottom: 0; }
td code { font-size: 12.5px; color: var(--primary); white-space: nowrap; }
.method { display: inline-block; min-width: 52px; text-align: center; padding: 2px 7px; border-radius: 5px; font: 700 11px/1.6 ui-monospace, monospace; letter-spacing: .03em; }
.m-get { background: rgba(22, 128, 61, .12); color: #15803d; }
.m-post { background: rgba(29, 78, 216, .12); color: #1d4ed8; }
.m-put { background: rgba(180, 83, 9, .13); color: #b45309; }
.m-del { background: rgba(190, 24, 60, .12); color: #be123c; }
:root[data-theme="dark"] .m-get, :root[data-theme="dark"] .m-post, :root[data-theme="dark"] .m-put, :root[data-theme="dark"] .m-del { filter: brightness(1.5); }

.tabs { display: flex; gap: 4px; margin-bottom: 10px; flex-wrap: wrap; }
.tab {
    border: 1px solid var(--border); background: var(--surface); color: var(--muted);
    border-radius: 8px; padding: 5px 12px; font-size: 12.5px; cursor: pointer; font-weight: 600;
}
.tab:hover { color: var(--text); }
.tab.is-active { background: var(--primary-soft); color: var(--primary); border-color: var(--primary-border); }
.codebox { position: relative; background: var(--code-bg); border-radius: var(--radius); overflow: hidden; }
.codebox pre { margin: 0; padding: 16px 18px; overflow-x: auto; color: var(--code-text); font-size: 12.5px; line-height: 1.75; }
.codebox .copy-btn { position: absolute; top: 10px; right: 10px; background: rgba(255, 255, 255, .08); border-color: rgba(255, 255, 255, .16); color: #d1d5db; }
.codebox .copy-btn:hover { color: #fff; }
.codebox .copy-btn.is-ok { color: #86efac; }
.c-key { color: #93c5fd; }
.c-str { color: #a7f3d0; }
.c-com { color: #7d8595; }
.c-cmd { color: #fcd34d; }

.callout { border-left: 3px solid var(--primary); background: var(--surface); border-radius: 0 var(--radius) var(--radius) 0; padding: 12px 16px; font-size: 13.5px; color: var(--muted); margin: 16px 0; }
/* v2.16.1：行内 code 里的长 JSON / URL 强制断行，避免撑破手机端布局 */
.callout code,
p code,
li code,
dd code,
td code,
th code { word-break: break-word; overflow-wrap: anywhere; }
.callout strong { color: var(--text); }
ul { padding-left: 20px; margin: 10px 0; }
li { margin: 5px 0; color: var(--muted); font-size: 14px; }
li strong { color: var(--text); }
footer { border-top: 1px solid var(--border); padding-top: 18px; color: var(--faint); font-size: 12.5px; display: flex; justify-content: space-between; gap: 12px; flex-wrap: wrap; }

@media (max-width: 880px) {
    .layout { grid-template-columns: 1fr; gap: 0; padding: 0 18px 60px; }
    .toc { display: none; }
    .steps { grid-template-columns: 1fr; }
    .topbar-inner { padding: 10px 18px; }
    .brand-sub { display: none; }
}
@media (max-width: 480px) {
    .hero h1 { font-size: 26px; }
    .ver { display: none; }
    .back-link { display: none; }
    th, td { padding: 9px 10px; }
}
@media (prefers-reduced-motion: reduce) {
    html { scroll-behavior: auto; }
}
</style>
</head>
<body>

<div class="topbar">
    <div class="topbar-inner">
        <div class="mark" aria-hidden="true">轩</div>
        <span class="brand-text">轩宇导航</span>
        <span class="brand-sub">开放 API</span>
        <span class="ver">v<?php echo $esc($version); ?></span>
        <span class="topbar-spacer"></span>
        <a class="back-link" href="../index.php">← 返回首页</a>
        <button class="icon-btn" id="themeBtn" type="button" aria-label="切换深浅色主题" title="切换深浅色主题">
            <svg class="i-moon" viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 12.8A9 9 0 1 1 11.2 3a7 7 0 0 0 9.8 9.8z"/></svg>
            <svg class="i-sun" viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M4.9 4.9l1.4 1.4M17.7 17.7l1.4 1.4M2 12h2M20 12h2M4.9 19.1l1.4-1.4M17.7 6.3l1.4-1.4"/></svg>
        </button>
    </div>
</div>

<div class="layout">
    <nav class="toc" aria-label="页内目录">
        <p class="toc-title">目录</p>
        <a href="#start" class="is-active">快速入门</a>
        <a href="#auth">鉴权方式</a>
        <a href="#method">方法伪装</a>
        <a href="#endpoints">端点一览</a>
        <a href="#params">请求参数</a>
        <a href="#category">分类管理</a>
        <a href="#examples">调用示例</a>
        <a href="#response">响应格式</a>
        <a href="#errors">错误码</a>
        <a href="#cors">跨域与预检</a>
        <a href="#internal">站内接口</a>
        <a href="#notes">注意事项</a>
    </nav>

    <main class="main">
        <div class="hero">
            <h1>开放 API</h1>
            <p>用 HTTP 请求读写导航条目，适合脚本批量导入、第三方客户端同步、自动化运维。所有响应均为 JSON，UTF-8 编码。</p>
        </div>

        <div class="basebar">
            <span class="basebar-label">Base URL</span>
            <code id="baseUrl"><?php echo $esc($baseUrl); ?>sites.php</code>
            <button class="copy-btn" type="button" data-copy="#baseUrl">复制</button>
        </div>

        <div class="notice">
            <span class="notice-icon" aria-hidden="true">ⓘ</span>
            <div>这个目录以前访问会返回 <strong>403</strong> —— 因为服务器禁止列出目录，而这里没有默认文档。现在 <code>/api/</code> 会显示本页；如果仍打不开，直接访问 <code>/api/index.php</code> 即可。<strong>带令牌的请求</strong>要发到 <code>sites.php</code>，不是本页。</div>
        </div>

        <section id="start">
            <h2>快速入门</h2>
            <p class="sec-desc">三步拿到第一条数据：获取令牌 → 带上鉴权 → 发出请求。</p>
            <div class="steps">
                <div class="step">
                    <div class="step-num">1</div>
                    <h4>获取令牌</h4>
                    <p>两种入口任选：<strong>后台 → 系统工具 → API 令牌管理</strong>（<code>admin/tools.php?tab=api</code>），或<strong>前台登录后「我的导航 → API 令牌」</strong>。选择 <code>read</code>（只读）或 <code>write</code>（可写）。<br>后台生成的令牌是<strong>管理级</strong>（可读写全部条目）；前台生成的是<strong>用户级</strong>（只能读写你自己名下的条目）。</p>
                </div>
                <div class="step">
                    <div class="step-num">2</div>
                    <h4>带上鉴权</h4>
                    <p>请求头加 <code>Authorization: Bearer &lt;token&gt;</code>。令牌只在创建时完整显示一次，丢失只能重置，请立即保存。</p>
                </div>
                <div class="step">
                    <div class="step-num">3</div>
                    <h4>发出第一个请求</h4>
                    <p>先用 GET 列表验证连通性，看到 <code>"ok": true</code> 即说明令牌有效。</p>
                </div>
            </div>

            <div class="codebox">
                <button class="copy-btn" type="button" data-copy="#code-first">复制</button>
<pre id="code-first"><span class="c-com"># 第一个请求：读取 1 条导航，验证令牌是否可用</span>
<span class="c-cmd">curl</span> <span class="c-str">"<?php echo $esc($baseUrl); ?>sites.php?size=1"</span> \
  -H <span class="c-str">"Authorization: Bearer YOUR_TOKEN"</span></pre>
            </div>

            <div class="codebox">
                <button class="copy-btn" type="button" data-copy="#code-first-resp">复制</button>
<pre id="code-first-resp">{
  <span class="c-key">"ok"</span>: true,
  <span class="c-key">"page"</span>: 1,
  <span class="c-key">"size"</span>: 1,
  <span class="c-key">"total"</span>: 128,
  <span class="c-key">"pages"</span>: 128,
  <span class="c-key">"items"</span>: [ { <span class="c-key">"id"</span>: 42, <span class="c-key">"name"</span>: <span class="c-str">"智谱开放平台"</span>, <span class="c-key">"category_id"</span>: 3, <span class="c-key">"category_name"</span>: <span class="c-str">"AI 工具"</span> } ],
  <span class="c-key">"categories"</span>: [ { <span class="c-key">"id"</span>: 3, <span class="c-key">"name"</span>: <span class="c-str">"AI 工具"</span>, <span class="c-key">"is_private"</span>: 0 } ]
}</pre>
            </div>

            <div class="callout"><strong>IIS 主机必读。</strong>IIS 默认会拦截原生 <code>PUT</code> / <code>DELETE</code>（返回 405），所以<strong>更新和删除请统一用 <code>POST</code> + <code>_method</code> 伪装</strong>，见<a href="#method">方法伪装</a>。</div>
        </section>

        <section id="auth">
            <h2>鉴权方式</h2>
            <p class="sec-desc">三种传令牌的方式等价，任选其一。令牌为 16–64 位字母数字。</p>
            <div class="table-wrap">
                <table>
                    <thead><tr><th>方式</th><th>写法</th><th>说明</th></tr></thead>
                    <tbody>
                        <tr><td>请求头（推荐）</td><td><code>Authorization: Bearer &lt;token&gt;</code></td><td>标准做法，令牌不进日志与浏览器历史</td></tr>
                        <tr><td>自定义头</td><td><code>X-Api-Token: &lt;token&gt;</code></td><td>反向代理不透传 Authorization 时用</td></tr>
                        <tr><td>URL 参数</td><td><code>?token=&lt;token&gt;</code></td><td>仅用于临时调试，会留在访问日志里</td></tr>
                    </tbody>
                </table>
            </div>
            <h3>权限范围</h3>
            <div class="table-wrap">
                <table>
                    <thead><tr><th>维度</th><th>取值</th><th>影响</th></tr></thead>
                    <tbody>
                        <tr><td>操作权限</td><td><code>read</code></td><td>仅 GET；写操作返回 403</td></tr>
                        <tr><td>操作权限</td><td><code>write</code></td><td>GET / POST / PUT / PATCH / DELETE 全部可用</td></tr>
                        <tr><td>数据范围</td><td>管理级（<code>user_id = 0</code>）</td><td>可读写公共与全部用户条目，列表可用 <code>user_id</code> 过滤</td></tr>
                        <tr><td>数据范围</td><td>用户级</td><td>强制限定为该令牌所属用户的条目，越权访问返回 403</td></tr>
                    </tbody>
                </table>
            </div>
            <div class="callout"><strong>令牌有有效期。</strong>创建时可设置过期时间，过期后返回 <code>401 token_expired</code>，到后台重新生成即可。</div>
        </section>

        <section id="method">
            <h2>方法伪装（IIS 兼容）</h2>
            <p class="sec-desc">由于服务器 IIS 环境限制，<strong>更新和删除统一用 <code>POST</code> + <code>_method</code> 调用，不要使用原生 <code>PUT</code> / <code>DELETE</code></strong>——它们在 IIS 上会被直接拦截成 405，请求根本到不了 PHP。</p>
            <div class="table-wrap">
                <table>
                    <thead><tr><th>想要的动作</th><th>推荐写法</th><th>说明</th></tr></thead>
                    <tbody>
                        <tr><td>新建</td><td><code>POST</code> + body</td><td>本来就是 POST，无需伪装</td></tr>
                        <tr><td>更新</td><td><code>POST</code> + <code>"_method":"PUT"</code></td><td>等价于原生 PUT；<code>PATCH</code> 同理</td></tr>
                        <tr><td>删除</td><td><code>POST</code> + <code>"_method":"DELETE"</code></td><td>等价于原生 DELETE（进回收站）</td></tr>
                    </tbody>
                </table>
            </div>
            <p class="sec-desc"><code>_method</code> 可以放在三个位置，任选其一（同时存在时按 JSON → 表单 → URL 的顺序取第一个）：</p>
            <div class="table-wrap">
                <table>
                    <thead><tr><th>位置</th><th>写法</th></tr></thead>
                    <tbody>
                        <tr><td>JSON 请求体</td><td><code>{"_method":"DELETE","id":42}</code></td></tr>
                        <tr><td>表单字段</td><td><code>_method=DELETE&amp;id=42</code></td></tr>
                        <tr><td>URL 查询参数</td><td><code>sites.php?_method=DELETE&amp;id=42</code></td></tr>
                    </tbody>
                </table>
            </div>
            <div class="codebox">
                <button class="copy-btn" type="button" data-copy="#code-method">复制</button>
<pre id="code-method"><span class="c-com"># 更新：POST + _method=PUT</span>
<span class="c-cmd">curl</span> -X POST <span class="c-str">"<?php echo $esc($baseUrl); ?>sites.php"</span> \
  -H <span class="c-str">"Authorization: Bearer YOUR_TOKEN"</span> \
  -H <span class="c-str">"Content-Type: application/json"</span> \
  -d <span class="c-str">'{"_method":"PUT","id":42,"name":"新名称"}'</span>

<span class="c-com"># 删除：POST + _method=DELETE</span>
<span class="c-cmd">curl</span> -X POST <span class="c-str">"<?php echo $esc($baseUrl); ?>sites.php"</span> \
  -H <span class="c-str">"Authorization: Bearer YOUR_TOKEN"</span> \
  -H <span class="c-str">"Content-Type: application/json"</span> \
  -d <span class="c-str">'{"_method":"DELETE","id":42}'</span></pre>
            </div>
            <div class="callout"><strong>兼容性说明。</strong>原生 <code>PUT</code> / <code>PATCH</code> / <code>DELETE</code> 仍然支持（Apache、Nginx、PHP 内置服务器下可正常使用），<code>_method</code> 只在原始方法为 <code>POST</code> 且取值为 PUT / PATCH / DELETE 时生效；其他取值一律忽略，不会改变 POST 本身的语义。</div>
        </section>

        <section id="endpoints">
            <h2>端点一览</h2>
            <p class="sec-desc">所有操作都指向 <code>sites.php</code>，用 HTTP 方法区分动作。安装了重写模块时也可用简写 <code>/api/sites</code>。<strong>IIS 环境请把 PUT / DELETE 换成 <code>POST + _method</code></strong>。</p>
            <div class="table-wrap">
                <table>
                    <thead><tr><th>方法</th><th>路径</th><th>权限</th><th>说明</th></tr></thead>
                    <tbody>
                        <tr><td><span class="method m-get">GET</span></td><td><code>sites.php</code></td><td>read / write</td><td>分页列表，支持分类、关键词、用户过滤</td></tr>
                        <tr><td><span class="method m-get">GET</span></td><td><code>sites.php?id=1</code></td><td>read / write</td><td>单条详情</td></tr>
                        <tr><td><span class="method m-post">POST</span></td><td><code>sites.php</code></td><td>write</td><td>新建条目，支持 <code>category_name</code> / <code>ai_recommend</code> 自动建分类，成功返回 201</td></tr>
                        <tr><td><span class="method m-post">POST</span></td><td><code>sites.php</code> + <code>_method=PUT</code></td><td>write</td><td>按 <code>id</code> 更新字段（IIS 下推荐写法，PATCH 同理）</td></tr>
                        <tr><td><span class="method m-post">POST</span></td><td><code>sites.php</code> + <code>_method=DELETE</code></td><td>write</td><td>移入回收站，可在后台恢复（IIS 下推荐写法）</td></tr>
                        <tr><td><span class="method m-put">PUT</span></td><td><code>sites.php</code></td><td>write</td><td>原生更新，IIS 上会被拦截，非 IIS 可用</td></tr>
                        <tr><td><span class="method m-del">DELETE</span></td><td><code>sites.php?id=1</code></td><td>write</td><td>原生删除，IIS 上会被拦截，非 IIS 可用</td></tr>
                    </tbody>
                </table>
            </div>
            <p class="sec-desc">其他 HTTP 方法返回 <code>405 method_not_allowed</code>。<code>OPTIONS</code> 预检例外，见<a href="#cors">跨域与预检</a>。</p>
        </section>

        <section id="params">
            <h2>请求参数</h2>
            <h3>列表查询（GET，URL 参数）</h3>
            <div class="table-wrap">
                <table>
                    <thead><tr><th>参数</th><th>默认</th><th>说明</th></tr></thead>
                    <tbody>
                        <tr><td><code>page</code></td><td>1</td><td>页码，最小 1</td></tr>
                        <tr><td><code>size</code></td><td>20</td><td>每页条数，1–100，超出自动收敛</td></tr>
                        <tr><td><code>category</code></td><td>0</td><td>按分类 ID 过滤</td></tr>
                        <tr><td><code>q</code></td><td>空</td><td>关键词，匹配名称 / 网址 / 描述</td></tr>
                        <tr><td><code>user_id</code></td><td>不过滤</td><td><strong>仅管理级令牌有效</strong>；用户级令牌忽略此参数</td></tr>
                        <tr><td><code>id</code></td><td>—</td><td>传了就返回单条详情，不再走列表</td></tr>
                    </tbody>
                </table>
            </div>
            <div class="callout">列表响应除 <code>items</code> 外，还额外返回 <code>categories</code> 数组：当前令牌可见的全部分类分组（含尚未挂任何网址的空分组），结构为 <code>{"id","name","is_private"}</code>。管理级令牌返回全部分类，用户级令牌返回「公共分类 + 自己的分类（含私密）」，均按 <code>sort_order</code> 排序。从此不必单独轮询分类，新建分组也会即时出现在列表响应里。</div>
            <h3>新建 / 更新（POST、PUT、PATCH）</h3>
            <div class="table-wrap">
                <table>
                    <thead><tr><th>字段</th><th>类型</th><th>说明</th></tr></thead>
                    <tbody>
                        <tr><td><code>name</code></td><td>string</td><td>网站名称，<strong>新建必填</strong>，最长 100 字</td></tr>
                        <tr><td><code>url</code></td><td>string</td><td><strong>新建必填</strong>，http/https；省略协议时自动补 https</td></tr>
                        <tr><td><code>description</code></td><td>string</td><td>介绍，最长 200 字</td></tr>
                        <tr><td><code>logo</code></td><td>string</td><td>图标地址，最长 500 字</td></tr>
                        <tr><td><code>category_id</code></td><td>int</td><td>分类 ID；传 0 或省略时归入「未分类」。<strong>用户级令牌只能传自己名下的分类</strong>，否则 403</td></tr>
                        <tr><td><code>category_name</code></td><td>string</td><td><strong>v2.16.0 新增</strong>：按名称指定分类，不存在则自动创建（最长 50 字）。优先级低于 <code>category_id</code>，见<a href="#category">分类管理与自动创建</a></td></tr>
                        <tr><td><code>ai_recommend</code></td><td>bool/int</td><td><strong>v2.16.0 新增</strong>：设为 1 / true 时由 AI 根据名称+描述推荐分类，推荐分类不存在则自动创建</td></tr>
                        <tr><td><code>allow_ai_fallback</code></td><td>bool</td><td><strong>v2.16.0 新增</strong>：默认 true（AI 不可用时回落到未分类）；传 false 则 AI 未启用时返回 <code>400 ai_disabled</code></td></tr>
                        <tr><td><code>is_active</code></td><td>bool</td><td>是否启用，默认 true</td></tr>
                        <tr><td><code>sort_order</code></td><td>int</td><td>排序值（仅更新时有效）</td></tr>
                        <tr><td><code>user_id</code></td><td>int</td><td>归属用户，<strong>仅管理级令牌</strong>创建时可指定，且用户必须存在（否则 400）</td></tr>
                        <tr><td><code>id</code></td><td>int</td><td><strong>更新 / 删除必填</strong>，可放 body 或 URL</td></tr>
                    </tbody>
                </table>
            </div>
            <div class="callout">请求体支持 <code>application/json</code>、<code>application/x-www-form-urlencoded</code> 两种；PUT / DELETE 请放 body，不要只放在查询串里。</div>
        </section>

        <section id="category">
            <h2>分类管理与自动创建</h2>
            <p class="sec-desc">新建条目时可以按 ID、按名称或交给 AI 决定分类，命中规则按优先级从左到右，<strong>命中第一个即停止</strong>。</p>
            <div class="table-wrap">
                <table>
                    <thead><tr><th>优先级</th><th>参数</th><th>类型</th><th>行为</th></tr></thead>
                    <tbody>
                        <tr><td>1</td><td><code>category_id</code></td><td>int</td><td>使用已有分类；不存在返回 400，用户级令牌用了别人的分类返回 403</td></tr>
                        <tr><td>2</td><td><code>category_name</code></td><td>string</td><td>按名称指定，<strong>不存在则自动创建</strong>；同名分类直接复用，最长 50 字</td></tr>
                        <tr><td>3</td><td><code>ai_recommend</code></td><td>bool/int</td><td>设为 <code>1</code> / <code>true</code> 时，把名称+描述交给 AI 推荐；<strong>推荐的分类不存在则自动创建</strong></td></tr>
                        <tr><td>4</td><td>（都不传）</td><td>—</td><td>归入「未分类」</td></tr>
                    </tbody>
                </table>
            </div>

            <h3>自动创建的分类归属谁？</h3>
            <div class="table-wrap">
                <table>
                    <thead><tr><th>令牌类型</th><th>自动创建的分类归属</th></tr></thead>
                    <tbody>
                        <tr><td>用户级令牌（<code>user_id &gt; 0</code>）</td><td>归属该令牌对应的用户，只有他能看到和管理</td></tr>
                        <tr><td>管理级令牌（<code>user_id = 0</code>）</td><td>归属<strong>公共分类</strong>（<code>user_id = 0</code>），全站可见</td></tr>
                    </tbody>
                </table>
            </div>

            <h3>响应里如何知道最终用了哪个分类</h3>
            <p class="sec-desc">新建成功（201）返回 <code>category_id</code>、<code>category_name</code> 与 <code>category_source</code>，调用方无需再查一次。</p>
            <div class="table-wrap">
                <table>
                    <thead><tr><th><code>category_source</code></th><th>含义</th></tr></thead>
                    <tbody>
                        <tr><td><code>category_id</code></td><td>使用了调用方指定的分类 ID</td></tr>
                        <tr><td><code>category_name</code></td><td>按名称命中已有分类，或按名称新建</td></tr>
                        <tr><td><code>ai_recommend</code></td><td>AI 推荐成功，分类已存在或按推荐名新建</td></tr>
                        <tr><td><code>ai_no_match</code></td><td>AI 未能给出可用分类，已回落到「未分类」</td></tr>
                        <tr><td><code>default</code></td><td>未指定分类，默认归入「未分类」</td></tr>
                    </tbody>
                </table>
            </div>

            <h3>示例 A：按名称指定分类（不存在自动创建）</h3>
            <div class="codebox">
                <button class="copy-btn" type="button" data-copy="#code-cat-name">复制</button>
<pre id="code-cat-name"><span class="c-cmd">curl</span> -X POST <span class="c-str">"<?php echo $esc($baseUrl); ?>sites.php"</span> \
  -H <span class="c-str">"Authorization: Bearer YOUR_TOKEN"</span> \
  -H <span class="c-str">"Content-Type: application/json"</span> \
  -d <span class="c-str">'{"name":"Midjourney","url":"https://midjourney.com","category_name":"AI 绘画"}'</span></pre>
            </div>
            <div class="codebox">
                <button class="copy-btn" type="button" data-copy="#code-cat-name-resp">复制</button>
<pre id="code-cat-name-resp">{
  <span class="c-key">"ok"</span>: true,
  <span class="c-key">"message"</span>: <span class="c-str">"创建成功"</span>,
  <span class="c-key">"id"</span>: 43,
  <span class="c-key">"category_id"</span>: 15,
  <span class="c-key">"category_name"</span>: <span class="c-str">"AI 绘画"</span>,
  <span class="c-key">"category_source"</span>: <span class="c-str">"category_name"</span>
}</pre>
            </div>

            <h3>示例 B：AI 自动推荐分类</h3>
            <p class="sec-desc">AI 从当前令牌可见的分类里挑选；都不合适时会建议一个新分类名并自动创建。前提是后台已启用 AI 并配置了模型与密钥。</p>
            <div class="codebox">
                <button class="copy-btn" type="button" data-copy="#code-cat-ai">复制</button>
<pre id="code-cat-ai"><span class="c-cmd">curl</span> -X POST <span class="c-str">"<?php echo $esc($baseUrl); ?>sites.php"</span> \
  -H <span class="c-str">"Authorization: Bearer YOUR_TOKEN"</span> \
  -H <span class="c-str">"Content-Type: application/json"</span> \
  -d <span class="c-str">'{"name":"Figma","url":"https://figma.com","description":"在线协作设计工具","ai_recommend":1}'</span></pre>
            </div>
            <div class="codebox">
                <button class="copy-btn" type="button" data-copy="#code-cat-ai-resp">复制</button>
<pre id="code-cat-ai-resp">{
  <span class="c-key">"ok"</span>: true,
  <span class="c-key">"message"</span>: <span class="c-str">"创建成功"</span>,
  <span class="c-key">"id"</span>: 44,
  <span class="c-key">"category_id"</span>: 16,
  <span class="c-key">"category_name"</span>: <span class="c-str">"设计工具"</span>,
  <span class="c-key">"category_source"</span>: <span class="c-str">"ai_recommend"</span>
}</pre>
            </div>

            <h3>AI 不可用时怎么办</h3>
            <div class="table-wrap">
                <table>
                    <thead><tr><th>场景</th><th>默认行为（<code>allow_ai_fallback</code> 未传）</th><th>传 <code>allow_ai_fallback:false</code></th></tr></thead>
                    <tbody>
                        <tr><td>AI 未启用 / 无密钥</td><td>静默回落到「未分类」，<code>category_source</code> 为 <code>default</code></td><td>返回 <code>400 ai_disabled</code>，不创建条目</td></tr>
                        <tr><td>AI 调用失败或无合适分类</td><td>回落到「未分类」，<code>category_source</code> 为 <code>ai_no_match</code></td><td>同左（AI 已启用，只是没给结果）</td></tr>
                    </tbody>
                </table>
            </div>
            <div class="callout">需要「要么 AI 分类成功、要么报错」的严格场景，请同时传 <code>{"ai_recommend":1,"allow_ai_fallback":false}</code>。</div>
        </section>

        <section id="examples">
            <h2>调用示例</h2>
            <p class="sec-desc">把 <code>YOUR_TOKEN</code> 换成后台生成的令牌。点右上角可复制。</p>
            <div class="tabs" role="tablist" aria-label="示例类型">
                <button class="tab is-active" type="button" role="tab" aria-selected="true" data-pane="ex-list">列表</button>
                <button class="tab" type="button" role="tab" aria-selected="false" data-pane="ex-create">新建</button>
                <button class="tab" type="button" role="tab" aria-selected="false" data-pane="ex-update">更新</button>
                <button class="tab" type="button" role="tab" aria-selected="false" data-pane="ex-delete">删除</button>
            </div>

            <div class="codebox" id="ex-list" data-pane-body>
                <button class="copy-btn" type="button" data-copy="#code-list">复制</button>
<pre id="code-list"><span class="c-com"># 按分类 + 关键词分页查询</span>
<span class="c-cmd">curl</span> -G <span class="c-str">"<?php echo $esc($baseUrl); ?>sites.php"</span> \
  -H <span class="c-str">"Authorization: Bearer YOUR_TOKEN"</span> \
  --data-urlencode <span class="c-key">"q=AI"</span> \
  --data-urlencode <span class="c-key">"page=1"</span> \
  --data-urlencode <span class="c-key">"size=20"</span></pre>
            </div>

            <div class="codebox" id="ex-create" data-pane-body hidden>
                <button class="copy-btn" type="button" data-copy="#code-create">复制</button>
<pre id="code-create"><span class="c-cmd">curl</span> -X POST <span class="c-str">"<?php echo $esc($baseUrl); ?>sites.php"</span> \
  -H <span class="c-str">"Authorization: Bearer YOUR_TOKEN"</span> \
  -H <span class="c-str">"Content-Type: application/json"</span> \
  -d <span class="c-str">'{"name":"智谱开放平台","url":"https://bigmodel.cn","category_id":3,"description":"大模型开放平台"}'</span></pre>
            </div>

            <div class="codebox" id="ex-update" data-pane-body hidden>
                <button class="copy-btn" type="button" data-copy="#code-update">复制</button>
<pre id="code-update"><span class="c-com"># IIS 环境：POST + _method=PUT（原生 PUT 会被 IIS 拦截成 405）</span>
<span class="c-cmd">curl</span> -X POST <span class="c-str">"<?php echo $esc($baseUrl); ?>sites.php"</span> \
  -H <span class="c-str">"Authorization: Bearer YOUR_TOKEN"</span> \
  -H <span class="c-str">"Content-Type: application/json"</span> \
  -d <span class="c-str">'{"_method":"PUT","id":42,"name":"新名称","is_active":true}'</span></pre>
            </div>

            <div class="codebox" id="ex-delete" data-pane-body hidden>
                <button class="copy-btn" type="button" data-copy="#code-delete">复制</button>
<pre id="code-delete"><span class="c-com"># 软删除，条目进入回收站，可在后台恢复</span>
<span class="c-com"># IIS 环境：POST + _method=DELETE</span>
<span class="c-cmd">curl</span> -X POST <span class="c-str">"<?php echo $esc($baseUrl); ?>sites.php"</span> \
  -H <span class="c-str">"Authorization: Bearer YOUR_TOKEN"</span> \
  -H <span class="c-str">"Content-Type: application/json"</span> \
  -d <span class="c-str">'{"_method":"DELETE","id":42}'</span></pre>
            </div>
        </section>

        <section id="response">
            <h2>响应格式</h2>
            <p class="sec-desc">列表响应示例。</p>
            <div class="codebox">
                <button class="copy-btn" type="button" data-copy="#code-resp">复制</button>
<pre id="code-resp">{
  <span class="c-key">"ok"</span>: true,
  <span class="c-key">"page"</span>: 1,
  <span class="c-key">"size"</span>: 20,
  <span class="c-key">"total"</span>: 128,
  <span class="c-key">"pages"</span>: 7,
  <span class="c-key">"items"</span>: [
    {
      <span class="c-key">"id"</span>: 42,
      <span class="c-key">"name"</span>: <span class="c-str">"智谱开放平台"</span>,
      <span class="c-key">"url"</span>: <span class="c-str">"https://bigmodel.cn"</span>,
      <span class="c-key">"logo"</span>: <span class="c-str">""</span>,
      <span class="c-key">"description"</span>: <span class="c-str">"大模型开放平台"</span>,
      <span class="c-key">"category_id"</span>: 3,
      <span class="c-key">"category_name"</span>: <span class="c-str">"AI 工具"</span>,
      <span class="c-key">"user_id"</span>: 0,
      <span class="c-key">"clicks"</span>: 12,
      <span class="c-key">"is_active"</span>: true,
      <span class="c-key">"check_status"</span>: <span class="c-str">"ok"</span>,
      <span class="c-key">"latency_ms"</span>: 86,
      <span class="c-key">"created_at"</span>: <span class="c-str">"2026-11-06 12:00:00"</span>
    }
  ],
  <span class="c-key">"categories"</span>: [
    { <span class="c-key">"id"</span>: 1,  <span class="c-key">"name"</span>: <span class="c-str">"全部网站"</span>, <span class="c-key">"is_private"</span>: 0 },
    { <span class="c-key">"id"</span>: 5,  <span class="c-key">"name"</span>: <span class="c-str">"我的收藏"</span>, <span class="c-key">"is_private"</span>: 0 },
    { <span class="c-key">"id"</span>: 12, <span class="c-key">"name"</span>: <span class="c-str">"工作分组"</span>, <span class="c-key">"is_private"</span>: 1 }
  ]
}</pre>
            </div>
            <p class="sec-desc">写操作返回 <code>{"ok":true,"message":"创建成功","id":43}</code>；新建的 HTTP 状态码为 <strong>201</strong>。</p>
        </section>

        <section id="errors">
            <h2>错误码</h2>
            <div class="table-wrap">
                <table>
                    <thead><tr><th>HTTP</th><th>error</th><th>常见原因</th></tr></thead>
                    <tbody>
                        <tr><td>400</td><td><code>bad_request</code></td><td>缺 <code>name</code> / <code>url</code> / <code>id</code>，或字段不合法、分类不存在</td></tr>
                        <tr><td>400</td><td><code>ai_disabled</code></td><td>传了 <code>ai_recommend</code> 且 <code>allow_ai_fallback:false</code>，但后台未启用 AI 或无密钥</td></tr>
                        <tr><td>401</td><td><code>unauthorized</code></td><td>没带令牌、令牌格式不对、令牌无效</td></tr>
                        <tr><td>401</td><td><code>token_expired</code></td><td>令牌已过期</td></tr>
                        <tr><td>403</td><td><code>forbidden</code></td><td>只读令牌做了写操作，或访问了他人条目</td></tr>
                        <tr><td>404</td><td><code>not_found</code></td><td>记录不存在或已进回收站</td></tr>
                        <tr><td>405</td><td><code>method_not_allowed</code></td><td>用了不支持的 HTTP 方法</td></tr>
                        <tr><td>400</td><td><code>invalid_json</code></td><td>声明了 <code>Content-Type: application/json</code>，但请求体不是合法 JSON</td></tr>
                        <tr><td>403</td><td><code>forbidden</code></td><td>用户级令牌使用了不属于自己的分类，或令牌权限值非法</td></tr>
                        <tr><td>500</td><td><code>server_error</code></td><td>服务端写入失败；数据不会被删除，可稍后重试</td></tr>
                    </tbody>
                </table>
            </div>
            <div class="callout">错误响应里 <code>error</code> 是稳定的机器可读标识，<code>message</code> 是给人看的中文说明。<strong>判断失败请先看 HTTP 码和 error，别去匹配 message 文案。</strong></div>
        </section>

        <section id="cors">
            <h2>跨域与预检</h2>
            <p class="sec-desc">浏览器里的第三方客户端可以直接调用本站 API，不需要自建代理。</p>
            <div class="table-wrap">
                <table>
                    <thead><tr><th>项目</th><th>值 / 行为</th></tr></thead>
                    <tbody>
                        <tr><td><code>OPTIONS</code> 预检</td><td>返回 <strong>204</strong>（IIS 例外见下方），在令牌校验之前完成，不需要带令牌</td></tr>
                        <tr><td><code>Access-Control-Allow-Origin</code></td><td><code>*</code></td></tr>
                        <tr><td><code>Access-Control-Allow-Methods</code></td><td><code>GET, POST, PUT, PATCH, DELETE, OPTIONS</code></td></tr>
                        <tr><td><code>Access-Control-Allow-Headers</code></td><td><code>Authorization, X-Api-Token, Content-Type, Accept, X-Requested-With</code></td></tr>
                        <tr><td><code>Access-Control-Allow-Credentials</code></td><td><strong>不发送</strong>。鉴权靠请求头里的令牌，不依赖 Cookie，因此不会造成跨站带上登录态</td></tr>
                    </tbody>
                </table>
            </div>
            <div class="callout"><strong>令牌由调用方自己保管。</strong>允许跨域不等于允许匿名访问：没有有效令牌，任何来源都只会拿到 <code>401 unauthorized</code>。请勿把令牌写进公开的前端代码或公开仓库。</div>
            <div class="callout"><strong>IIS 10 主机请注意。</strong>部分 Windows 主机由 <code>OPTIONSVerbHandler</code> 接管 <code>OPTIONS</code> 请求，预检不会到达 PHP，响应是 <code>200</code> 且不带 CORS 头。此时：① <strong>跨域简单 GET 仍然可用</strong>——把令牌放在 <code>?token=</code> 且不添加自定义请求头，浏览器不会发预检；② 服务端脚本、curl、同源调用完全不受影响；③ 若要让浏览器直接发带 <code>Authorization</code> 头的写请求，请到<strong>主机面板</strong>把 <code>OPTIONS</code> 动词交给 PHP handler，或移除 <code>OPTIONSVerbHandler</code> 映射。不要在根 <code>web.config</code> 里重复 <code>&lt;add&gt;</code> 节点，那会触发 500.19 导致全站不可用。</div>
        </section>

        <section id="internal">
            <h2>站内接口一览</h2>
            <p class="sec-desc">下列接口供本站页面使用，<strong>不接受开放令牌</strong>，靠浏览器会话鉴权，并做同源校验。它们不是对外 REST API，响应结构随各自业务而定，但失败时统一带 <code>ok:false</code> 与 <code>error</code>。</p>
            <div class="table-wrap">
                <table>
                    <thead><tr><th>端点</th><th>鉴权</th><th>用途</th></tr></thead>
                    <tbody>
                        <tr><td><code>auth.php</code></td><td>公开 / 前台会话</td><td>登录、注册、退出、当前用户、资料修改</td></tr>
                        <tr><td><code>search_index.php</code></td><td>公开</td><td>首页搜索索引（站点 + 分类）</td></tr>
                        <tr><td><code>site_meta.php?url=</code></td><td>公开 + 同源</td><td>抓取站点标题 / 描述 / 图标，带 SSRF 防护与缓存</td></tr>
                        <tr><td><code>track_click.php</code></td><td>公开 + 同源</td><td>点击量统计，同会话 2 秒内重复上报去重</td></tr>
                        <tr><td><code>contact.php</code></td><td>公开 + 同源</td><td>在线留言，受后台开关与频率限制约束</td></tr>
                        <tr><td><code>share.php</code></td><td>公开读取 / 登录写入</td><td>分享创建、导入、读取，浏览量按访客 60 秒去重</td></tr>
                        <tr><td><code>user_sites.php</code></td><td>前台登录</td><td>我的导航增删改查、批量识别与批量导入</td></tr>
                        <tr><td><code>user_categories.php</code></td><td>前台登录</td><td>我的分组创建 / 改名 / 私密 / 排序 / 删除</td></tr>
                        <tr><td><code>user_search_engines.php</code></td><td>前台登录</td><td>自定义搜索引擎管理</td></tr>
                        <tr><td><code>bookmark_import.php</code></td><td>前台登录</td><td>浏览器书签文件导入</td></tr>
                        <tr><td><code>category_create.php</code></td><td>前台登录或后台登录</td><td>全站统一新建分类 / 分组接口。<strong>v2.14.0 起需带 <code>scope=front|admin</code></strong>：前台调用强制归属当前登录用户、忽略传入的 <code>user_id</code>；只有后台会话 + <code>scope=admin</code> 才能创建公共分类或指定归属用户</td></tr>
                        <tr><td><code>ai_suggest.php</code>、<code>ai_test.php</code></td><td>后台登录</td><td>AI 分类推荐、AI 配置测试连接</td></tr>
                        <tr><td><code>health_check.php</code>、<code>stats.php</code></td><td>后台登录</td><td>链接健康检测、仪表盘统计</td></tr>
                        <tr><td><code>check_update.php</code></td><td>后台登录</td><td>版本检测（服务端抓取 GitHub，带 10 分钟缓存）</td></tr>
                        <tr><td><code>clear_cache.php</code>、<code>get_cache_status.php</code></td><td>—</td><td><strong>已下线</strong>，固定返回 <code>410 update_removed</code></td></tr>
                    </tbody>
                </table>
            </div>
            <div class="callout">站内 POST 接口统一接受 <code>application/x-www-form-urlencoded</code> 与 <code>application/json</code>；JSON 非法时返回 <code>400 invalid_json</code>，不会再被静默当成「没传参数」。</div>
        </section>

        <section id="notes">
            <h2>注意事项</h2>
            <ul>
                <li><strong>只有 <code>sites.php</code> 是对外 REST API。</strong><code>api/</code> 目录下其余 PHP（如 <code>auth.php</code>、<code>user_sites.php</code>、<code>site_meta.php</code>）是站内功能接口，靠登录会话鉴权，不提供开放令牌访问，直接打开返回 401 属正常现象。</li>
                <li><strong>令牌等同密码。</strong>不要写进前端代码、公开仓库或截图；优先用请求头传递，避免 <code>?token=</code> 留在访问日志里。</li>
                <li><strong>DELETE 是软删除</strong>，条目进回收站且停用，可在后台「导航管理 → 回收站」恢复。用户也可以在「我的导航 → 回收站」里自助恢复或彻底删除自己的条目。</li>
                <li><strong>IIS 上必须用 <code>POST + _method</code></strong> 做更新/删除，原生 <code>PUT</code>/<code>DELETE</code> 会被服务器直接拦截成 405，详见<a href="#method">方法伪装</a>。</li>
                <li><strong><code>category_name</code> / <code>ai_recommend</code> 会自动建分类</strong>：用户级令牌建的是你自己的分类，管理级令牌建的是公共分类。批量导入前建议先用 GET 的 <code>categories</code> 字段核对现有分类名，避免同义不同名（如「AI 工具」和「AI工具」）产生重复分组。</li>
                <li><strong>写入会自动归一化网址</strong>：缺协议补 <code>https://</code>，非法地址直接拒绝。</li>
                <li><strong>令牌在数据库里只存 SHA-256 摘要</strong>，完整明文仅在创建时显示一次；备份文件泄漏也不会直接交出可用令牌。旧版本创建的明文令牌仍可正常使用。</li>
                <li><strong>列表的 <code>q</code> 按字面匹配</strong>：<code>%</code> 和 <code>_</code> 不再被当作 SQL 通配符，搜索结果与你输入的关键词一致。</li>
                <li><strong>写入会校验归属</strong>：管理级令牌指定 <code>user_id</code> 时用户必须存在；用户级令牌的 <code>category_id</code> 只能是自己名下的分类，越权返回 403。</li>
                <li><strong>DELETE 只在老库确实缺少 <code>deleted_at</code> 列时才退化为硬删除</strong>；数据库异常会返回 500 而不是把数据永久删掉。</li>
                <li><strong>统计类接口有去重</strong>：点击量按会话 2 秒去重，分享浏览量按访客 60 秒去重；<code>track_click.php</code> / <code>site_meta.php</code> 只接受同源请求。</li>
                <li>响应带 <code>Cache-Control: no-store</code> 与 <code>noindex</code>，不会被搜索引擎收录。</li>
                <li>若 <code>/api/</code> 仍显示 403，说明主机默认文档里缺少 <code>index.php</code>，请到<strong>主机面板</strong>添加。不要在 <code>web.config</code> 里重复 <code>&lt;add value="index.php" /&gt;</code> —— 多数 PHP 主机全局已配置，重复声明会触发 500.19 导致全站不可用。Apache 的 <code>.htaccess</code> 已配置 <code>DirectoryIndex</code>。<strong>直接访问 <code>/api/index.php</code> 永远可用。</strong></li>
            </ul>
        </section>

        <footer>
            <span>轩宇导航 · 开放 API 文档 · v<?php echo $esc($version); ?></span>
            <a href="../index.php">返回首页</a>
        </footer>
    </main>
</div>

<script>
(function () {
    'use strict';

    var themeBtn = document.getElementById('themeBtn');
    var root = document.documentElement;

    function currentTheme() {
        var saved = root.getAttribute('data-theme');
        if (saved) { return saved; }
        return window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
    }

    if (themeBtn) {
        themeBtn.addEventListener('click', function () {
            var next = currentTheme() === 'dark' ? 'light' : 'dark';
            root.setAttribute('data-theme', next);
            try { window.localStorage.setItem('xy_api_theme', next); } catch (e) { /* 隐私模式忽略 */ }
        });
    }

    try {
        var savedTheme = window.localStorage.getItem('xy_api_theme');
        if (savedTheme === 'dark' || savedTheme === 'light') {
            root.setAttribute('data-theme', savedTheme);
        }
    } catch (e) { /* 隐私模式忽略 */ }

    // 复制：优先 Clipboard API，失败回退到临时 textarea
    function copyText(text) {
        if (navigator.clipboard && window.isSecureContext) {
            return navigator.clipboard.writeText(text);
        }
        return new Promise(function (resolve, reject) {
            var ta = document.createElement('textarea');
            ta.value = text;
            ta.style.position = 'fixed';
            ta.style.opacity = '0';
            document.body.appendChild(ta);
            ta.select();
            try {
                document.execCommand('copy') ? resolve() : reject(new Error('copy failed'));
            } catch (err) {
                reject(err);
            } finally {
                document.body.removeChild(ta);
            }
        });
    }

    Array.prototype.forEach.call(document.querySelectorAll('[data-copy]'), function (btn) {
        btn.addEventListener('click', function () {
            var target = document.querySelector(btn.getAttribute('data-copy'));
            if (!target) { return; }
            var original = btn.textContent;
            copyText(target.textContent.trim()).then(function () {
                btn.textContent = '已复制';
                btn.classList.add('is-ok');
                window.setTimeout(function () {
                    btn.textContent = original;
                    btn.classList.remove('is-ok');
                }, 1600);
            }).catch(function () {
                btn.textContent = '请手动复制';
                window.setTimeout(function () { btn.textContent = original; }, 1600);
            });
        });
    });

    // 示例切换
    var tabs = Array.prototype.slice.call(document.querySelectorAll('.tab'));
    var panes = Array.prototype.slice.call(document.querySelectorAll('[data-pane-body]'));

    function activate(paneId) {
        tabs.forEach(function (tab) {
            var on = tab.getAttribute('data-pane') === paneId;
            tab.classList.toggle('is-active', on);
            tab.setAttribute('aria-selected', on ? 'true' : 'false');
        });
        panes.forEach(function (pane) {
            pane.hidden = pane.id !== paneId;
        });
    }

    tabs.forEach(function (tab) {
        tab.addEventListener('click', function () { activate(tab.getAttribute('data-pane')); });
    });

    // 目录高亮
    var links = Array.prototype.slice.call(document.querySelectorAll('.toc a'));
    var sections = links.map(function (a) { return document.querySelector(a.getAttribute('href')); });

    if ('IntersectionObserver' in window) {
        var observer = new IntersectionObserver(function (entries) {
            entries.forEach(function (entry) {
                if (!entry.isIntersecting) { return; }
                links.forEach(function (a) {
                    a.classList.toggle('is-active', a.getAttribute('href') === '#' + entry.target.id);
                });
            });
        }, { rootMargin: '-80px 0px -70% 0px', threshold: 0 });
        sections.forEach(function (s) { if (s) { observer.observe(s); } });
    }
})();
</script>
</body>
</html>
