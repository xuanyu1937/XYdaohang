#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
轩宇-导航 · 发版同步自检

每次更新项目，以下 8 项必须同步，缺一不可：

  1. 代码        改动文件本身、33 处静态资源 ?v=、sw.js 的 PWA 缓存名
  2. 版本号      VERSION、config/version.php(APP_VERSION/APP_RELEASE/APP_UPDATE_TIME)、
                includes/bootstrap.php、includes/ConfigManager.php、api/index.php
  3. CHANGELOG   新增 "## vX.Y.Z（YYYY-MM-DD）" 段落，明确写清是否有数据库变更
  4. SQL         有结构变更才新增 upgrade_vX.Y.Z.sql，并同步改 install/install.sql；
                无变更则在 CHANGELOG / 部署清单 / README 三处写明「无需执行 SQL」
  5. 部署清单    UPLOAD_LIST.md 头部版本、升级须知、验证项、逐文件变动表
  6. README      当前版本、升级矩阵、vX.Y.Z 版本序列、国内网络适配说明
  7. tag         本地与远端 vX.Y.Z 一致，tag 内 VERSION 与当前版本一致
  8. Release     vX.Y.Z 已发布、至少 1 个安装包资产、说明取自 CHANGELOG 对应段落

另：新增功能不得引入 Google / DuckDuckGo / GitHub raw / cdnjs / jsdelivr 等
境外服务依赖（国内主机访问不了），脚本会给出 WARN。

用法：
    python3 scripts/release_sync_check.py              # 以 VERSION 文件为准
    python3 scripts/release_sync_check.py 2.18.5      # 指定待发布版本
退出码：0 = 无 FAIL；1 = 存在 FAIL，禁止发版。

适用范围：以上 8 项针对「版本发布」。仅新增工具、修错别字、改流程说明等
不产生新版本号的提交，不需要 bump 版本号、不需要打 tag、不需要发 Release，
但提交信息里必须写明「非版本发布」，避免与发版流程混淆。
"""

from __future__ import annotations

import json
import re
import subprocess
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent
RESULTS: list[tuple[str, str, str]] = []


def record(name: str, ok: bool, detail: str, warn_only: bool = False) -> None:
    status = 'PASS' if ok else ('WARN' if warn_only else 'FAIL')
    RESULTS.append((status, name, detail))


def read(rel: str) -> str:
    p = ROOT / rel
    return p.read_text(encoding='utf-8') if p.is_file() else ''


def git(*args: str) -> str:
    r = subprocess.run(['git', '-C', str(ROOT), *args], capture_output=True, text=True)
    return r.stdout.strip() if r.returncode == 0 else ''


def gh(*args: str) -> str:
    try:
        r = subprocess.run(['gh', *args], capture_output=True, text=True, timeout=30)
        return r.stdout.strip() if r.returncode == 0 else ''
    except (FileNotFoundError, subprocess.TimeoutExpired):
        return ''


def changelog_section(changelog: str, ver: str) -> str:
    m = re.search(r'(?ms)^## v' + re.escape(ver) + r'（.*?\n(?=^## |\Z)', changelog)
    return m.group(0) if m else ''


def main() -> int:
    ver = sys.argv[1].strip() if len(sys.argv) > 1 else read('VERSION').strip()
    if not re.fullmatch(r'\d+\.\d+\.\d+', ver):
        print('版本号不合法（应形如 2.18.4）：', repr(ver))
        return 1

    changelog = read('CHANGELOG.md')
    section = changelog_section(changelog, ver)
    release_date = ''
    m = re.search(r'^## v' + re.escape(ver) + r'（(\d{4}-\d{2}-\d{2})）', changelog, re.M)
    if m:
        release_date = m.group(1)

    # 1. 代码：静态资源版本串 + PWA 缓存名
    asset_versions: set[str] = set()
    asset_count = 0
    for p in ROOT.rglob('*.php'):
        rel = p.relative_to(ROOT).as_posix()
        if rel.startswith(('vendor/', 'backups/', 'install/', 'docs/', '.git/')):
            continue
        for v in re.findall(r'\?v=(\d+\.\d+\.\d+)', p.read_text(encoding='utf-8', errors='ignore')):
            asset_versions.add(v)
            asset_count += 1
    record('代码 · 静态资源 ?v=', asset_count > 0 and asset_versions == {ver},
           f'{asset_count} 处，取值 {sorted(asset_versions) or "无"}')
    sw = read('sw.js')
    record('代码 · sw.js 缓存名', f'v{ver}' in sw or ver in sw,
           (re.search(r"var CACHE = '([^']+)'", sw) or [None, '未找到'])[1]
           if "var CACHE = '" in sw else '未找到 CACHE')

    # 2. 版本号：5 个位置 + 发布日期
    checks = {
        'VERSION': (read('VERSION').strip() == ver, read('VERSION').strip()),
        'config/version.php · APP_VERSION': (
            f"define('APP_VERSION', '{ver}')" in read('config/version.php'), '常量'),
        'config/version.php · APP_RELEASE': (
            bool(release_date) and f"define('APP_RELEASE', '{release_date}')" in read('config/version.php'),
            f'应为 {release_date or "CHANGELOG 中未找到该版本日期"}'),
        'config/version.php · APP_UPDATE_TIME': (
            bool(release_date) and bool(re.search(
                r"define\('APP_UPDATE_TIME', '" + re.escape(release_date) + r" ",
                read('config/version.php'))), f'日期应为 {release_date or "?"}'),
        'includes/bootstrap.php': (
            f"define('APP_VERSION', '{ver}')" in read('includes/bootstrap.php'), '常量'),
        'includes/ConfigManager.php': (
            f"'version' => '{ver}'" in read('includes/ConfigManager.php'), '数组项'),
        'api/index.php': (f"$version = '{ver}';" in read('api/index.php'), '文档页徽章'),
    }
    for name, (ok, detail) in checks.items():
        record('版本号 · ' + name, ok, detail)

    # 3. CHANGELOG
    record('CHANGELOG · 版本段落', bool(section),
           f'## v{ver}（{release_date}）' if section else f'缺少 ## v{ver}（日期）段落')
    sql_free = bool(re.search(r'无(需执行 )?(数据库|SQL)(结构)?变更|无需执行 SQL|无数据库变更', section))
    record('CHANGELOG · SQL 声明', bool(re.search(r'SQL|数据库', section, re.I)),
           '已写明无需执行 SQL' if sql_free else '已说明数据库变更')

    # 4. SQL
    sql_scripts = sorted(set(re.findall(r'upgrade_[\w.]+\.sql', section)))
    missing = [s for s in sql_scripts if not (ROOT / s).is_file()]
    record('SQL · 脚本与说明一致', not missing,
           ('引用 ' + ', '.join(sql_scripts) if sql_scripts else '本版未引用升级脚本')
           + ('；缺失: ' + ', '.join(missing) if missing else ''))
    install = read('install/install.sql')
    record('SQL · 安装脚本基线', 'check_status' in install and 'skip_check' in install,
           'check_status / skip_check 均存在')

    # 5. 部署清单
    upload = read('UPLOAD_LIST.md')
    first = upload.splitlines()[0] if upload else ''
    cur_line = next((l for l in upload.splitlines() if l.startswith('当前版本：')), '')
    record('部署清单 · 头部与当前版本', f'v{ver}' in first and ver in cur_line,
           first.strip()[:60])
    record('部署清单 · 本版变动表', f'v{ver}' in '\n'.join(upload.splitlines()[:120]),
           '前 120 行含本版变动说明')

    # 6. README
    readme = read('README.md')
    cur = next((l for l in readme.splitlines() if l.startswith('当前版本：')), '')
    record('README · 当前版本', ver in cur, cur.strip()[:70])
    up = re.search(r'(?ms)^## 🔄 版本升级\s*\n(.*?)(?=^## |\Z)', readme)
    up_sec = up.group(1) if up else ''
    record('README · 升级章节', f'v{ver}' in up_sec, '升级矩阵/版本序列含本版')
    record('README · 国内网络说明', '国内' in readme and 'bootcdn' in readme,
           '默认引擎/CDN 兜底口径')

    # 7. tag（本地 + 远端）
    tag = 'v' + ver
    tag_exists = bool(git('rev-parse', '--verify', f'refs/tags/{tag}'))
    tag_ver = git('show', f'{tag}:VERSION') if tag_exists else ''
    record('tag · 本地', tag_exists and tag_ver == ver,
           f'{tag} -> {git("rev-parse", "--short", tag) or "不存在"}，包内 VERSION={tag_ver or "?"}')
    remote = git('remote', 'get-url', 'origin')
    mm = re.search(r'github\.com[/:]([^/]+)/([^/.]+)', remote)
    repo = f'{mm.group(1)}/{mm.group(2)}' if mm else ''
    if repo and gh:
        ref = gh('api', f'repos/{repo}/git/ref/tags/{tag}', '--jq', '.object.sha')
        record('tag · 远端', bool(ref), f'{repo} {ref[:12]}' if ref else f'{repo} 上未找到 {tag}')
    else:
        record('tag · 远端', True, '跳过（无 gh 或非 GitHub 远端）', warn_only=True)

    # 8. Release
    if repo:
        out = gh('release', 'view', tag, '-R', repo, '--json', 'assets', '--jq', '.assets | length')
        n = int(out) if out.isdigit() else 0
        record('Release · 发布与资产', n > 0, f'{tag} 含 {n} 个资产' if n else f'{tag} 未发布或无资产')
    else:
        record('Release · 发布与资产', True, '跳过（无 gh 或非 GitHub 远端）', warn_only=True)

    # 附加：境外依赖（WARN，不阻断）
    # 只查真实 URL 引用；注释里「google/duckduckgo 已移除」这类说明文字不算依赖
    bad = re.compile(
        r'https?://[^\s"\'<>]*(google\.com|googleapis|gstatic|duckduckgo|cdnjs|jsdelivr|unpkg|fonts\.google)',
        re.I)
    hits: list[str] = []
    for ext in ('*.php', '*.js', '*.css', '*.html'):
        for p in ROOT.rglob(ext):
            rel = p.relative_to(ROOT).as_posix()
            if rel.startswith(('vendor/', 'docs/', 'backups/', 'install/', '.git/', 'cache/')):
                continue
            for i, line in enumerate(p.read_text(encoding='utf-8', errors='ignore').splitlines(), 1):
                s = line.strip()
                if s.startswith(('//', '/*', '*', '#', '<!--')):
                    continue
                if bad.search(s):
                    hits.append(f'{rel}:{i}')
    record('国内网络 · 境外依赖', not hits,
           '无境外服务直连' if not hits else '命中 ' + ', '.join(hits[:5]), warn_only=True)

    # 输出
    print(f'发版同步自检 · v{ver} · 仓库 {repo or "本地"}')
    print('-' * 76)
    for status, name, detail in RESULTS:
        print(f'{status:<5} {name:<38} {detail}')
    print('-' * 76)
    fails = sum(1 for s, _, _ in RESULTS if s == 'FAIL')
    warns = sum(1 for s, _, _ in RESULTS if s == 'WARN')
    print(f'汇总：PASS {len(RESULTS) - fails - warns} / WARN {warns} / FAIL {fails}'
          + ('  → 可以发版' if fails == 0 else '  → 禁止发版，先修 FAIL'))
    return 1 if fails else 0


if __name__ == '__main__':
    sys.exit(main())
