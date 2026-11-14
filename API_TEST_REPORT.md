# 轩宇-导航 API 可用性与逻辑审计报告

> 对应版本：**v2.13.0** · 测试日期：2026-11-07 · 测试方式：真实 HTTP 请求（Python requests）+ Playwright 浏览器回归
>
> ⚠️ **历史快照**：本报告是 v2.13.0 当时的审计记录，文内版本号、徽章与用例结果不代表当前版本。**当前版本 v2.18.4（2026-11-14）**，现行升级与验证口径见 [UPLOAD_LIST.md](UPLOAD_LIST.md)。

## 一、结论摘要

| 项目 | 结果 |
| --- | --- |
| API 专项审计（修复前基线） | **85 / 94** 通过，9 项失败（7 项真实缺陷 + 2 项测试脚本/环境问题） |
| API 专项审计（修复后） | **106 / 106 通过** |
| v2.12.0 存量回归 | 73 / 73 通过（无回退） |
| v2.11.0 存量回归 | 49 / 49 通过（无回退） |
| 全项目 `php -l` / 内联 JS `node --check` | 全部通过，脚本级 JS 错误 0 |
| 线上只读 + 真实令牌闭环复测 | **38 / 38 通过**（IIS 预检差异见第六节） |

**API 整体可用**：20 个端点全部有正确响应，无 5xx、无空白页、无 PHP 致命错误；对外 REST API 的完整 CRUD、鉴权、越权、错误码均验证通过。修复前发现的问题已全部闭环。

## 二、测试范围

- 本地：`http://127.0.0.1:8088`（PHP 8.1 内置服务 + MySQL 8.0，4 worker）
- 线上：`http://dh.pxyblog.top/`（只读与安全拒绝路径，不写业务数据）
- 身份：匿名、前台登录用户、后台管理员、`read` 令牌、`write` 令牌、用户级令牌、过期令牌、摘要令牌
- 覆盖：GET / POST / PUT / PATCH / DELETE / OPTIONS / TRACE、表单编码与 `application/json`、正常值与边界值

### 端点清单（20 个）

| 端点 | 鉴权 | 修复前实测 | 修复后 |
| --- | --- | --- | --- |
| `index.php`（`/api/` 文档页） | 公开 | 200，文档完整 | 200，新增「跨域与预检」「站内接口一览」 |
| `sites.php` | Bearer 令牌 | 401（无令牌）/ CRUD 可用 | 204 预检 + 完整 CRUD + 摘要令牌 |
| `site_meta.php` | 公开 | 400（缺 url）/ SSRF 拦截有效 | 增加同源校验，跨站 403 |
| `auth.php` | 公开 / 前台会话 | 200 `logged_in:false` | 200，统一 JSON 请求体 |
| `search_index.php` | 公开 | 200，返回站点与分类 | 不变 |
| `track_click.php` | 公开 | 405（GET）/ 可任意刷点击 | 同源校验 + 2 秒去重 |
| `share.php` | 公开读 / 登录写 | 400（无 action）/ 每次 GET 刷浏览量 | 浏览量 60 秒去重 |
| `contact.php` | 公开 | 405（GET）/ 空留言 400 | 不变 |
| `user_sites.php` | 前台登录 | 405（GET）/ 未知 action 400 | 统一 JSON 请求体 |
| `user_categories.php` | 前台登录 | 405（GET）/ create 200 | 统一 JSON 请求体 |
| `user_search_engines.php` | 前台登录 | 200 GET | 统一 JSON 请求体 |
| `bookmark_import.php` | 前台登录 | 405（GET）/ 400（无文件） | 统一 JSON 请求体 |
| `category_create.php` | 前台或后台登录 | 405（GET）/ create 200 | 统一 JSON 请求体 + `invalid_json` |
| `ai_suggest.php` | 后台登录 | 401（未登录） | 统一 JSON 请求体 |
| `ai_test.php` | 后台登录 | 401（未登录）/ 失败只回 message 且 HTTP 200 | 统一 `error` 码与非 2xx |
| `health_check.php` | 后台登录 | 401（未登录）/ 404（不存在 id） | 统一 JSON 请求体 |
| `stats.php` | 后台登录 | 401（未登录）/ POST 也返回数据 | 仅 GET，其余 405 |
| `check_update.php` | 后台登录 | 401（未登录）/ 反射任意 Origin + Credentials | 同主机白名单 CORS |
| `clear_cache.php` | — | 410 已下线 | 不变 |
| `get_cache_status.php` | — | 410 已下线 | 不变 |

## 三、修复前发现的问题（含实测证据）

| # | 用例 | 实测结果 | 判定 |
| --- | --- | --- | --- |
| 1 | REST OPTIONS 预检 | `405 method_not_allowed` | 真实缺陷：浏览器跨域调用在预检阶段即失败 |
| 2 | REST 拒绝不存在的 user_id | `201` 创建成功，写入 `user_id=999999` | 真实缺陷：产生孤儿数据 |
| 3 | REST 用户级令牌挂公共分类 | `201` 创建成功 | 真实缺陷：越权关联分类 |
| 4 | check_update 跨域 Origin | `Access-Control-Allow-Origin: https://evil.example` + `Allow-Credentials: true` | 真实缺陷：CORS 配置错误 |
| 5 | stats.php 拒绝非 GET | `200` 返回统计 JSON | 真实缺陷：方法语义失效 |
| 6 | ai_test.php 失败响应 | HTTP `200`，仅 `message`，无 `error` | 真实缺陷：无稳定机器码 |
| 7 | ai_test.php 空地址 | HTTP `200`，仅 `message` | 真实缺陷：参数错误不是 4xx |
| 8 | REST 非法 JSON | 静默当空参数，报「url 不能为空」 | 真实缺陷：排查方向跑偏（首轮断言过宽未捕获） |
| 9 | REST `q=%` | `total=47`（返回全部） | 真实缺陷：LIKE 通配符未转义（首轮断言过宽未捕获） |
| — | user_sites.php `action=list` | `400 unsupported_action` | 测试脚本用错 action，非缺陷 |
| — | ai_test.php 真实请求 | 连接失败 | 本地 mock 服务因沙箱休眠停止，重启后通过 |

另外通过代码审查发现 3 个未被首轮用例直接触发的隐患，一并修复：

1. **`sites.php` DELETE**：`try` 里任何异常都会被 `catch` 成 `DELETE FROM`，一次数据库抖动就会把本应进回收站的数据永久删除。
2. **令牌明文存库**：`api_tokens.token` 直接存明文，数据库或备份泄漏即交出全部可用令牌。
3. **`expires_at` 脏数据**：`strtotime("0000-00-00")` 返回 `false`，`false < time()` 为真，会把有效令牌误判为过期。

## 四、修复后验证结果（106 项）

| 分组 | 用例数 | 通过 |
| --- | --- | --- |
| 1. 对外 REST API（sites.php） | 37 | 37 |
| 2. 端点基础状态 | 21 | 21 |
| 3. 公开接口防护 | 14 | 14 |
| 4. 登录后站内接口 | 19 | 19 |
| 5. 文档一致性 | 9 | 9 |
| 6. 线上只读验证 | 5 | 5 |
| 7. 其它 | 1 | 1 |
| **合计** | **106** | **106** |

### 关键用例明细

| 用例 | 结果 | 响应摘要 |
| --- | --- | --- |
| REST 新版摘要令牌可用 | PASS | `200 {'ok': True, 'total': 47, 'items': 20}` |
| REST 旧版明文令牌仍兼容 | PASS | `200 {'ok': True, 'total': 47, 'items': 20}` |
| REST OPTIONS 预检可用 | PASS | `204 ` |
| REST q 中 % 按字面匹配，不再返回全部 | PASS | `total=0` |
| REST 非对象 JSON 返回 invalid_json | PASS | `400 {'ok': False, 'error': 'invalid_json', 'message': '请求体不是合法的 JSON，请检查 Content-Type 与内容'}` |
| REST 残缺 JSON 返回 invalid_json | PASS | `400 {'ok': False, 'error': 'invalid_json', 'message': '请求体不是合法的 JSON，请检查 Content-Type 与内容'}` |
| REST POST 表单编码创建 201 | PASS | `201 {'ok': True, 'message': '创建成功', 'id': 134}` |
| REST POST JSON 创建 201 | PASS | `201 {'ok': True, 'message': '创建成功', 'id': 135}` |
| REST PUT 更新 200 | PASS | `200 {'ok': True, 'message': '更新成功', 'id': 134}` |
| REST PATCH 更新 200 | PASS | `200 {'ok': True, 'message': '更新成功', 'id': 134}` |
| REST DELETE 为软删除（记录仍可恢复） | PASS | `deleted_at=2026-11-07 10:26:47` |
| REST 拒绝不存在的 user_id（孤儿数据防护） | PASS | `400 {'ok': False, 'error': 'bad_request', 'message': 'user_id 对应的用户不存在'} \| 若 201 则说明可写入孤儿数据` |
| REST 用户级令牌不可挂到公共分类 | PASS | `403 {'ok': False, 'error': 'forbidden', 'message': '无权使用该分类，请改用自己名下的分类'} \| 若 201 则说明可越权关联分类` |
| check_update 跨域 Origin 不被信任 | PASS | `{'Access-Control-Allow-Methods': 'GET, OPTIONS', 'Access-Control-Allow-Headers': 'Accept, Content-Type'}` |
| check_update 同源 Origin 才允许携带凭证 | PASS | `{'Access-Control-Allow-Origin': 'http://127.0.0.1:8088', 'Access-Control-Allow-Credentials': 'true', 'Access-Control-Allow-Methods': 'GET, OPTIONS', 'Access-Control-Allow-Headers': 'Accept, ` |
| track_click 拒绝跨站刷点击 | PASS | `403 {'ok': False, 'error': 'forbidden', 'message': '非法来源请求'}` |
| site_meta 拒绝跨站调用（防抓取代理） | PASS | `403 {'ok': False, 'error': 'forbidden', 'message': '非法来源请求'}` |
| track_click 2 秒内重复上报去重 | PASS | `200 {'ok': True} -> 200 {'ok': True}` |
| stats.php 拒绝非 GET 方法 | PASS | `405 {'ok': False, 'error': 'method_not_allowed', 'message': '统计接口仅支持 GET'}` |
| 站内接口支持 application/json 请求体 | PASS | `200 {'ok': True, 'id': 45}` |
| 站内接口非法 JSON 返回 invalid_json | PASS | `400 {'ok': False, 'error': 'invalid_json', 'message': '请求体不是合法的 JSON'}` |
| ai_test.php 真实请求成功 | PASS | `200 {'ok': True, 'message': '连接成功，接口配置可用'}` |
| ai_test.php 上游失败带稳定 error 码与非 2xx | PASS | `400 {'ok': False, 'error': 'upstream_error', 'message': '接口密钥无效或已过期（401）'}` |
| 文档新增跨域与预检章节 | PASS | `` |
| 文档新增站内接口一览 | PASS | `` |

<details>
<summary>展开完整 106 项用例清单</summary>

| # | 用例 | 结果 | 响应摘要 |
| --- | --- | --- | --- |
| 1 | REST 无令牌返回 401 | PASS | `401 {'ok': False, 'error': 'unauthorized', 'message': '缺少访问令牌，请在请求头中提供 Authorization: Bearer <token>'}` |
| 2 | REST 令牌格式错误返回 401 | PASS | `401 {'ok': False, 'error': 'unauthorized', 'message': '令牌格式不正确'}` |
| 3 | REST 无效令牌返回 401 | PASS | `401 {'ok': False, 'error': 'unauthorized', 'message': '令牌无效'}` |
| 4 | REST 过期令牌返回 401 token_expired | PASS | `401 {'ok': False, 'error': 'token_expired', 'message': '令牌已过期'}` |
| 5 | REST 过期令牌 error=token_expired | PASS | `{"ok":false,"error":"token_expired","message":"令牌已过期"}` |
| 6 | REST X-Api-Token 头可用 | PASS | `200 {'ok': True, 'total': 47, 'items': 20}` |
| 7 | REST ?token= 参数可用 | PASS | `200 {'ok': True, 'total': 47, 'items': 20}` |
| 8 | REST 新版摘要令牌可用 | PASS | `200 {'ok': True, 'total': 47, 'items': 20}` |
| 9 | REST 旧版明文令牌仍兼容 | PASS | `200 {'ok': True, 'total': 47, 'items': 20}` |
| 10 | REST read 令牌 GET 列表 200 | PASS | `200 {'ok': True, 'total': 47, 'items': 20}` |
| 11 | REST read 令牌 POST 返回 403 | PASS | `403 {'ok': False, 'error': 'forbidden', 'message': '当前令牌只有只读权限'}` |
| 12 | REST 不支持方法返回 405 | PASS | `405 {'ok': False, 'error': 'method_not_allowed', 'message': '不支持的请求方法'}` |
| 13 | REST OPTIONS 预检可用 | PASS | `204 ` |
| 14 | REST size 上限收敛到 100 | PASS | `100` |
| 15 | REST page/size 下限收敛到 1 | PASS | `(1, 1)` |
| 16 | REST q 中 % 按字面匹配，不再返回全部 | PASS | `total=0` |
| 17 | REST 非对象 JSON 返回 invalid_json | PASS | `400 {'ok': False, 'error': 'invalid_json', 'message': '请求体不是合法的 JSON，请检查 Content-Type 与内容'}` |
| 18 | REST 残缺 JSON 返回 invalid_json | PASS | `400 {'ok': False, 'error': 'invalid_json', 'message': '请求体不是合法的 JSON，请检查 Content-Type 与内容'}` |
| 19 | REST POST 表单编码创建 201 | PASS | `201 {'ok': True, 'message': '创建成功', 'id': 134}` |
| 20 | REST POST JSON 创建 201 | PASS | `201 {'ok': True, 'message': '创建成功', 'id': 135}` |
| 21 | REST GET 单条详情 200 | PASS | `200 {'ok': True}` |
| 22 | REST 创建时自动补 https 协议 | PASS | `https://api-audit.test/form` |
| 23 | REST PUT 更新 200 | PASS | `200 {'ok': True, 'message': '更新成功', 'id': 134}` |
| 24 | REST PATCH 更新 200 | PASS | `200 {'ok': True, 'message': '更新成功', 'id': 134}` |
| 25 | REST DELETE 200 | PASS | `200 {'ok': True, 'message': '已移入回收站', 'id': 134}` |
| 26 | REST DELETE 为软删除（记录仍可恢复） | PASS | `deleted_at=2026-11-07 10:26:47` |
| 27 | REST 软删后 GET 返回 404 | PASS | `404 {'ok': False, 'error': 'not_found', 'message': '记录不存在'}` |
| 28 | REST POST 缺 url 返回 400 | PASS | `400 {'ok': False, 'error': 'bad_request', 'message': 'url 不能为空且必须是合法的 http/https 地址'}` |
| 29 | REST POST 缺 name 返回 400 | PASS | `400 {'ok': False, 'error': 'bad_request', 'message': 'name 不能为空'}` |
| 30 | REST POST 不存在的 category_id 返回 400 | PASS | `400 {'ok': False, 'error': 'bad_request', 'message': 'category_id 不存在'}` |
| 31 | REST PUT 缺 id 返回 400 | PASS | `400 {'ok': False, 'error': 'bad_request', 'message': '缺少 id'}` |
| 32 | REST PUT 不存在的 id 返回 404 | PASS | `404 {'ok': False, 'error': 'not_found', 'message': '记录不存在'}` |
| 33 | REST DELETE 不存在的 id 返回 404 | PASS | `404 {'ok': False, 'error': 'not_found', 'message': '记录不存在'}` |
| 34 | REST 拒绝不存在的 user_id（孤儿数据防护） | PASS | `400 {'ok': False, 'error': 'bad_request', 'message': 'user_id 对应的用户不存在'} \| 若 201 则说明可写入孤儿数据` |
| 35 | REST 用户级令牌不可挂到公共分类 | PASS | `403 {'ok': False, 'error': 'forbidden', 'message': '无权使用该分类，请改用自己名下的分类'} \| 若 201 则说明可越权关联分类` |
| 36 | REST 成功响应含 ok | PASS | `['ok', 'page', 'size', 'total', 'pages', 'items']` |
| 37 | REST 失败响应含 error+message | PASS | `['ok', 'error', 'message']` |
| 38 | ai_suggest.php             GET 返回 JSON 且非 5xx | PASS | `401 application/json {"ok":false,"error":"请先登录后台"}` |
| 39 | ai_test.php                GET 返回 JSON 且非 5xx | PASS | `401 application/json {"ok":false,"error":"unauthorized","message":"请先登录后台"}` |
| 40 | auth.php                   GET 返回 JSON 且非 5xx | PASS | `200 application/json {"ok":true,"logged_in":false,"user":null,"register_enabled":true}` |
| 41 | bookmark_import.php        GET 返回 JSON 且非 5xx | PASS | `405 application/json {"ok":false,"error":"method_not_allowed"}` |
| 42 | category_create.php        GET 返回 JSON 且非 5xx | PASS | `405 application/json {"ok":false,"error":"method_not_allowed"}` |
| 43 | check_update.php           GET 返回 JSON 且非 5xx | PASS | `401 application/json {"ok":false,"error":"unauthorized"}` |
| 44 | clear_cache.php            GET 返回 JSON 且非 5xx | PASS | `410 application/json {"ok":false,"error":"update_removed"}` |
| 45 | contact.php                GET 返回 JSON 且非 5xx | PASS | `405 application/json {"ok":false,"error":"method_not_allowed"}` |
| 46 | get_cache_status.php       GET 返回 JSON 且非 5xx | PASS | `410 application/json {"ok":false,"error":"update_removed"}` |
| 47 | health_check.php           GET 返回 JSON 且非 5xx | PASS | `401 application/json {"ok":false,"error":"请先登录后台"}` |
| 48 | search_index.php           GET 返回 JSON 且非 5xx | PASS | `200 application/json {"ok":true,"sites":[{"id":1,"name":"百度","url":"https:\/\/www.baidu.com","host":"` |
| 49 | share.php                  GET 返回 JSON 且非 5xx | PASS | `400 application/json {"ok":false,"error":"invalid_request"}` |
| 50 | site_meta.php              GET 返回 JSON 且非 5xx | PASS | `400 application/json {"ok":false,"error":"invalid_url","message":"请提供合法的 url 参数"}` |
| 51 | stats.php                  GET 返回 JSON 且非 5xx | PASS | `401 application/json {"ok":false,"error":"请先登录后台"}` |
| 52 | track_click.php            GET 返回 JSON 且非 5xx | PASS | `405 application/json {"ok":false,"error":"method_not_allowed","message":"仅支持 POST"}` |
| 53 | user_categories.php        GET 返回 JSON 且非 5xx | PASS | `405 application/json {"ok":false,"error":"method_not_allowed"}` |
| 54 | user_search_engines.php    GET 返回 JSON 且非 5xx | PASS | `200 application/json {"ok":true,"engines":[{"id":"site","type":"site","name":"站内","url_template":"","` |
| 55 | user_sites.php             GET 返回 JSON 且非 5xx | PASS | `405 application/json {"ok":false,"error":"method_not_allowed"}` |
| 56 | clear_cache.php 明确返回 410 已下线 | PASS | `410 {'ok': False, 'error': 'update_removed'}` |
| 57 | get_cache_status.php 明确返回 410 已下线 | PASS | `410 {'ok': False, 'error': 'update_removed'}` |
| 58 | site_meta 拦截内网地址（SSRF） | PASS | `403 {'ok': False, 'error': 'blocked_ip'}` |
| 59 | site_meta 拦截 file:// 协议 | PASS | `400 {'ok': False, 'error': 'invalid_url'}` |
| 60 | site_meta 缺 url 返回 400 | PASS | `400 {'ok': False, 'error': 'invalid_url', 'message': '请提供合法的 url 参数'}` |
| 61 | track_click 不存在的 id 返回 404 | PASS | `404 {'ok': False, 'error': 'not_found', 'message': '记录不存在'}` |
| 62 | track_click 非法 id 返回 400 | PASS | `400 {'ok': False, 'error': 'invalid_id', 'message': 'id 不合法'}` |
| 63 | check_update 未登录返回 401 | PASS | `401 {'ok': False, 'error': 'unauthorized'}` |
| 64 | check_update 不反射任意 Origin + Credentials | PASS | `{'Access-Control-Allow-Methods': 'GET, OPTIONS', 'Access-Control-Allow-Headers': 'Accept, Content-Type'}` |
| 65 | check_update 跨域 Origin 不被信任 | PASS | `{'Access-Control-Allow-Methods': 'GET, OPTIONS', 'Access-Control-Allow-Headers': 'Accept, Content-Type'}` |
| 66 | check_update 同源 Origin 才允许携带凭证 | PASS | `{'Access-Control-Allow-Origin': 'http://127.0.0.1:8088', 'Access-Control-Allow-Credentials': 'true', 'Access-Control-Allow-Methods': 'GET, OPTIONS', 'Access-Control-Allow` |
| 67 | track_click 拒绝跨站刷点击 | PASS | `403 {'ok': False, 'error': 'forbidden', 'message': '非法来源请求'}` |
| 68 | site_meta 拒绝跨站调用（防抓取代理） | PASS | `403 {'ok': False, 'error': 'forbidden', 'message': '非法来源请求'}` |
| 69 | site_meta 无浏览器头时仍放行服务端调用 | PASS | `400 {'ok': False, 'error': 'invalid_url'}` |
| 70 | track_click 2 秒内重复上报去重 | PASS | `200 {'ok': True} -> 200 {'ok': True}` |
| 71 | 后台管理员登录成功 | PASS | `200 len=62611` |
| 72 | 前台用户登录成功 | PASS | `200 <!DOCTYPE html> <html lang="zh-CN"> <head>     <meta charset="UTF-8">     <meta name="viewport" content="width` |
| 73 | auth.php me 返回已登录用户 | PASS | `200 {'ok': True}` |
| 74 | stats.php 管理员可访问 | PASS | `200 {'ok': True}` |
| 75 | stats.php 拒绝非 GET 方法 | PASS | `405 {'ok': False, 'error': 'method_not_allowed', 'message': '统计接口仅支持 GET'}` |
| 76 | search_index.php 公开索引可用 | PASS | `200 {'ok': True}` |
| 77 | user_categories.php 创建分组 | PASS | `200 {'ok': True, 'id': 43}` |
| 78 | category_create.php 前台创建分组 | PASS | `200 {'ok': True, 'id': 44}` |
| 79 | user_sites.php 未知 action 明确拒绝（接口可达） | PASS | `400 {'ok': False, 'error': 'unsupported_action'}` |
| 80 | 站内接口支持 application/json 请求体 | PASS | `200 {'ok': True, 'id': 45}` |
| 81 | 站内接口非法 JSON 返回 invalid_json | PASS | `400 {'ok': False, 'error': 'invalid_json', 'message': '请求体不是合法的 JSON'}` |
| 82 | ai_test.php 未登录返回 401 | PASS | `401 {'ok': False, 'error': 'unauthorized', 'message': '请先登录后台'}` |
| 83 | ai_test.php 真实请求成功 | PASS | `200 {'ok': True, 'message': '连接成功，接口配置可用'}` |
| 84 | ai_test.php 上游失败带稳定 error 码与非 2xx | PASS | `400 {'ok': False, 'error': 'upstream_error', 'message': '接口密钥无效或已过期（401）'}` |
| 85 | ai_test.php 参数错误 400 且字段统一 | PASS | `400 {'ok': False, 'error': 'missing_api_url', 'message': '请先填写接口地址'}` |
| 86 | share.php create 参数校验有效 | PASS | `400 {'ok': False, 'error': '请至少选择一个网站'}` |
| 87 | share.php mine 可用 | PASS | `200 {'ok': True, 'items': 0}` |
| 88 | user_search_engines.php GET 可用 | PASS | `200 {'ok': True}` |
| 89 | health_check.php 不存在记录返回 404 | PASS | `404 {'ok': False, 'error': '记录不存在'}` |
| 90 | contact.php 校验拒绝空留言 | PASS | `400 {'ok': False, 'error': '请填写有效的邮箱地址'}` |
| 91 | check_update.php 管理员可调用（不超时不 500） | PASS | `200 {'ok': True}` |
| 92 | /api/ 文档页 200 | PASS | `35255` |
| 93 | 文档标注当前版本 | PASS | `['v2.12.0', 'v2.12.0']` |
| 94 | 文档列出 GET/POST/PUT/DELETE | PASS | `` |
| 95 | 文档说明站内接口与开放 API 的区别 | PASS | `` |
| 96 | 文档包含错误码表 | PASS | `` |
| 97 | 文档新增跨域与预检章节 | PASS | `` |
| 98 | 文档新增站内接口一览 | PASS | `` |
| 99 | 文档说明摘要存储与去重 | PASS | `` |
| 100 | 线上 /api/ -> 200 | PASS | `200 <!DOCTYPE html> <html lang="zh-CN"> <head> <meta charset="UTF-8"> <meta name="viewport" content="width=device-` |
| 101 | 线上 /api/index.php -> 200 | PASS | `200 <!DOCTYPE html> <html lang="zh-CN"> <head> <meta charset="UTF-8"> <meta name="viewport" content="width=device-` |
| 102 | 线上 /api/sites.php -> 401 | PASS | `401 {'ok': False, 'error': 'unauthorized', 'message': '缺少访问令牌，请在请求头中提供 Authorization: Bearer <token>'}` |
| 103 | 线上 /api/site_meta.php -> 400 | PASS | `400 {'ok': False, 'error': 'invalid_url'}` |
| 104 | 线上 /api/auth.php -> 200 | PASS | `200 {'ok': True}` |
| 105 | 线上无效令牌 401 | PASS | `401 {'ok': False, 'error': 'unauthorized', 'message': '令牌无效'}` |
| 106 | 线上文档徽章为 v2.12.0 | PASS | `['v2.12.0', 'v2.12.0']` |

</details>

## 五、线上状态（发布前基线）

| 路径 | 状态 | 说明 |
| --- | --- | --- |
| `/api/` | 200 | 文档页可读，徽章 v2.12.0 |
| `/api/index.php` | 200 | 零依赖兜底页可用 |
| `/api/sites.php` | 401 | 无令牌正确拒绝；无效令牌 401 |
| `/api/auth.php` | 200 | 匿名返回 `logged_in:false` |
| 首页 | 200 | 无 500.19 |

## 六、上线后复测（v2.13.0 已部署）

线上通过后台「系统工具 → 在线更新」上传整包完成部署：**覆盖 131 个文件**，备份目录 `backups/update-v2.13.0-20261107-104249/`（最终包）。首页、`/api/`、`/api/index.php` 均 200，徽章与资源参数为 `v2.13.0`，无 500.19。

### 真实令牌闭环（本次最重要的一项）

| 步骤 | 结果 |
| --- | --- |
| 登录线上后台 → 系统工具 → API 令牌管理 | 成功，`api_tokens` 表存在 |
| 创建临时只读令牌 | 成功，明文长度 64 |
| `GET /api/sites.php`（Bearer 令牌） | **200**，`ok:true`，`total=59`，返回 20 条 |
| `GET ?size=1` / `GET ?id=xx` | 200，分页与单条详情正常 |
| `POST`（read 令牌） | 403 `forbidden`，只读权限生效 |
| `GET ?q=%25` | `total=0`（全量 59），通配符转义生效 |
| 后台列表显示的前缀 | `95c66d58` ≠ 明文前 8 位 `28805c21`，**确认线上只存摘要** |
| 撤销令牌后原令牌再请求 | 401 `unauthorized`，立即失效；列表已无测试令牌 |

**结论：线上 API 真实可用**，且测试数据（临时令牌）已全部清理，未残留业务数据。

### 线上验证汇总

| 项目 | 结果 |
| --- | --- |
| 线上只读 + 安全拒绝 + 令牌闭环 | **38 / 38 通过** |
| 无令牌 / 无效令牌 401、内网 SSRF 403、跨站刷量 403、CORS 不反射恶意 Origin | 全部通过 |
| 过期/下线端点、`auth.php`、`search_index.php` 等公开端点 | 全部通过 |

### 一处部署层差异（非代码缺陷）

线上为 **IIS 10.0**，`OPTIONS /api/sites.php` 被 IIS 自带的 `OPTIONSVerbHandler` 接管，返回 `200` 且不带 CORS 头，请求没有到达 PHP（PHP 的 204 分支在 Apache / Nginx / PHP 内置服务下已验证正常）。

- **不受影响**：同源调用、服务端脚本、curl、`GET` 带令牌（真实令牌闭环即为此类）。
- **简单 GET 跨域仍可用**：把令牌放在 `?token=` 且不添加自定义请求头时，浏览器不会发预检；而 GET 响应本身带 `Access-Control-Allow-Origin: *`（线上已实测）。
- **带 `Authorization` 头的浏览器写请求**：在 IIS 上需要主机面板把 `OPTIONS` 动词交给 PHP handler（或移除 `OPTIONSVerbHandler` 映射）。
- **没有直接改根 `web.config`**：v2.10.8 曾因重复 `<add>` 节点触发 500.19 导致全站不可用，因此这次只在 `/api/` 文档页写明配置路径，由主机侧按需调整，不拿线上稳定性冒险。

## 七、结论与建议

- **可用性**：API 全部端点可用；线上真实令牌闭环（创建 → 读写 → 撤销）全部通过，错误码、鉴权、越权防护均已验证。浏览器跨域写请求在 IIS 上受 `OPTIONSVerbHandler` 限制，需主机侧放行 OPTIONS，详见第六节。
- **兼容性**：`api_tokens` 表结构未变，旧明文令牌继续可用；前端调用不依赖 `ai_test.php` 的 HTTP 状态码，行为不变。
- **部署**：无 SQL、无新增文件、无 Web 服务器配置改动，直接覆盖并清缓存即可。
- **后续建议**：① 若线上确有浏览器跨域写需求，在主机面板把 `OPTIONS` 交给 PHP handler；② 令牌目前无失败次数限制，建议在反向代理层加 IP 限流；③ `api_tokens` 现只存摘要，撤销令牌请在后台删除对应行。

