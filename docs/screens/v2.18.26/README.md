# 轩宇-导航 v2.18.26 · 线上自审证据

> 细节收口 5 项，全部只在 ≤640 / ≤480 生效，桌面不动。沿用 v2.18.25 三段式布局，不重排、不改宽度基准。
> 证据取自部署后的**线上真实页面**（http://dh.pxyblog.top），非本地注入。

## 部署验证
- 版本徽章：v2.18.26（api/index.php）
- 资源参数：?v=2.18.26（index.php）
- sw.js 缓存名：xy-nav-pwa-v2.18.26
- 后台升级：覆盖 151 文件，备份 backups/update-v2.18.26-20261122-215856/
- tag：v2.18.26；Release：454486090；全量包 XYdaohang-2.18.26.zip（163 文件 / 1.89MB）

## 五项新指标实测（390）
1. **回顶**：静止 opacity 0.35 + visible + pointer-events:none + 透明底；滚动四态 opacity (0.35, 0.35, 1, 1)（0/1屏淡显、1.2/2屏实心、单调不减、矩形 276–320 恒定 44×44）。
2. **chip 收紧**：padding 12→9、gap 8→6；前 3 枚右缘误差 ≤1.8px（全部 18–83.9 / 89.9–181.8 / 187.8–246.2）；chip3 计数右缘 236.2 ≤242 且在渐隐起点 248 之前；chip4 左缘 252.2 落在 248–268 渐隐带。
3. **热门条**：strip 右缘 372、内边距 12→list 内容右缘 359；渐隐 32px、起点 327（listRight-32），文字在边框前 13px 淡出，第 2 项不再硬切。
4. **吸顶条**：background color-mix 96%（alpha 0.96≥0.94）、backdrop blur(16px) saturate(180%)、条底 1px inset 分隔线；亮 rgba(0,0,0,.10) / 暗 rgba(255,255,255,.14)；滚动后无文字残影。
5. **模式按钮**：≤480 #mobileModeCycle 固定显示 2×2 方块（grid=block、list/compact=none）；全页可见三横图标 = 1（仅分类索引 ☰）。

## 回归基线（全部保持）
- 页边 18/18；分组卡与热门条同宽 354 / 右缘 372、边框同源；卡片 352。
- 星标右留白 9–10px、中心偏差 0.01px、无 hover 灰底。
- 行高 72–75、无 0px 轨道、无横滚、两处计数一致（42/42）、favicon 可辨。
- 三段式 gap 8/8、chip×按钮零相交、滑到最右末项距轨道右缘 20.2px、at-end 渐隐取消。
- 桌面 1440：group/hot 同宽 988、右缘对齐、无横滚；.mobile-cats 由历史规则 display:none。

## 断言结果
`assert-result.json`：10/10 PASS，score=100（100 起；第三节 5 项任一未命中 -5；回归任一回退 -5）。

## 截图清单（21 张）
- 390 明/暗 ×（静止 still / 滚 1 屏 scroll1 / 滚 2 屏 scroll2）：6 张专项
- 390 明/暗 × 五页（index/sites/detail/login/404）：10 张
- 390 明登录态 index：1 张
- 360 明静止、1440 明首页/sites/登录页：4 张

## 复现
```
cd /workspace/.archify
python3 audit_v21826.py http://dh.pxyblog.top
python3 assert_v21826.py
```
