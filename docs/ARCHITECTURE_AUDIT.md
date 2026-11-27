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

## 五、UI 设计层（2026-11 全站升级）

- 新增 `assets/css/rareui.css`：覆盖式组件层（卡片/按钮/输入框/导航/弹窗/玻璃/骨架/Toast/焦点环）。
- 打包顺序：`style.css → … → apple-design.css → rareui.css → mobile-app.css → dark-patch.css`；
  必须位于 dark-patch 之前，暗色覆盖仍然最后生效。
- 玻璃材质统一加「实体兜底」（半透明白/深色底 + 文字色），解决「换背景图后文字消失」。
- 卡片聚光灯与全局 Toast 由 `main.js` 的 `initCardSpotlight()` / `window.ruToast()` 提供。

## 六、实用工具页（2026-11 修复）

- **转盘结果与播报不一致**：旧实现只转整数圈（落点恒等于初始角），且按 0 弧度算中奖格，
  指针却在正上方（-90°），两者差 1/4 圈。现改为：先随机定中奖格 → 精确把该格中心转到指针 →
  播报同一格；指针落点数学已用 2200 次随机样本验证 100% 一致。
- 新增「搜索工具 / 最近使用 / 精简视图（隐藏 dice、coin、scratch 等与抽奖转盘重复的小工具）」。
- 转盘新增「语音播报」开关（SpeechSynthesis，浏览器不支持时静默降级）。
