# 架构审计与优化清单（线上版）

## 一、已发现的重复/冗余

| 重复项 | 现状 | 处理 |
|---|---|---|
| 内容审核 | moderation.php / review_queue.php / pending.php 三套 | 已删前两套，保留「待审核」 |
| 数据看板 | dashboard.php / analytics.php | 已删 analytics，保留 dashboard |
| 安全日志 | illegal_access.php / security_alerts.php / operation_logs.php | 已删 illegal_access，保留后两者 |
| 开发者文档 | data_dictionary.php | 已删（开发文档放 docs/） |

## 二、跳转与 URL 定向

| 入口 | 原行为 | 现状 |
|---|---|---|
| gateway 确认后 → login.php?once=1 | WAF 把 `once=` 误判为 `on*=` 事件属性 → 403 | 已修 WAF 正则，`?once=1` 正常 302 |
| 登录页首访 → register.php?from_login=1 | register.php 又因 session 残留 lw_intent=login 弹回登录页 | 已修：只认 from_login，其余修正意图为 register |
| 游客访问受限页 → register.php | 正常 | 保留，统一走 lwSafeRedirect() |

## 三、统一改造

- 新增 `lwSafeRedirect($url, $fallback)`：只允许站内相对路径/本站域名，防 open redirect
- 后续所有 `?to=` / `?redirect=` 入口统一走该 helper

## 四、仍建议（按收益排序）

1. API 响应统一（jsonSuccess/jsonError 已基本统一，剩余内联 echo 收敛）
2. 前端 inline JS 收敛到 bundle（收益中、风险中）
3. 数据层替换可插拔（当前 JSON 足够，比赛/小站无需数据库）