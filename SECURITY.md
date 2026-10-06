# 安全预案（Security Policy）

本项目为校园交流墙 `love_wall`（PHP 8.4 纯手写 + JSON 文件存储，无框架/无数据库）。
本文档说明安全边界、已落地的防护、运维须知与应急响应流程。

## 1. 安全响应头（已落地）

由 `config/config.php` 统一输出：

- `X-Content-Type-Options: nosniff`
- `X-Frame-Options: SAMEORIGIN`
- `X-XSS-Protection: 1; mode=block`
- `Referrer-Policy: strict-origin-when-cross-origin`
- `Permissions-Policy: camera/microphone/geolocation=()`（禁用敏感能力）
- `Cross-Origin-Opener-Policy / Cross-Origin-Resource-Policy: same-origin`
- `Content-Security-Policy`（见第 3 节）
- HTTPS 下 `Strict-Transport-Security: max-age=31536000; includeSubDomains`

> 生产环境已关闭 `display_errors`（F14），错误仅入服务器日志，不回显给访客。

## 2. 会话与认证

- 登录成功即 `session_regenerate_id(true)` 防会话固定（F8）。
- Cookie 设 `HttpOnly` + `SameSite=Lax`；HTTPS 下 `Secure`。
- 登录失败累计 ≥10 次锁定 15 分钟；每次登录还需算术验证码 + 按 IP 频率限制（F9/F10）。
- 2FA（TOTP）可开启；敏感操作（改密码、改绑 QQ、开关 2FA、管理员操作）一律禁止 AI 代办。

## 3. CSP 策略说明

```
default-src 'self';
script-src 'self' 'unsafe-inline';
style-src 'self' 'unsafe-inline';
img-src 'self' data: https:;
font-src 'self' https://fonts.gstatic.com;
connect-src 'self' https://v1.hitokoto.cn https://ipapi.co https://api.ipify.org https://api.qrserver.com https://api.ip.sb https://api.ipapi.is;
frame-ancestors 'self'; base-uri 'self'; form-action 'self';
object-src 'none'; media-src 'self' blob:; frame-src 'self' https:; upgrade-insecure-requests;
```

`script-src` 含 `'unsafe-inline'` 是**已知权衡**：历史代码大量使用内联事件/脚本，全量改造 nonce 成本过高。
后续若要做严格 CSP，需先把内联脚本迁到外部文件并改用 nonce（见 `tools_obfuscate_readme.md` 的反篡改思路）。
`connect-src` 是**第三方域名白名单**（F16）：新增外部 API 调用必须同步在此放行，否则被浏览器拦截。

## 4. 上传安全（F6/F7）

- 图片上传校验：扩展名白名单 + `finfo` MIME + 魔数（magic bytes）三重校验 + 体积上限 + 随机文件名（防目录遍历/执行）。
- `uploads/` 目录**禁止执行脚本**：根 `.htaccess` 与 `uploads/.htaccess` 双重 `RewriteRule ... - [F,L]` 拦截 `.php/phtml/phar/...` 访问（F7）。

## 5. 输入与输出

- XSS：输出统一经 `xss_clean()` / `esc()` 转义（F4）。
- 注入：无 SQL（JSON 文件存储），且无 `eval`/`exec`/`system` 等危险调用（F5，已 grep 核验）。
- CSRF：所有写操作 POST 接口校验 `csrf_token`（JSON 体字段，F3）；配置类文件命名避开 WAF 敏感串（F 系列硬约束）。
- 日志脱敏：运营日志经 `maskSensitive()` 对手机号/邮箱/QQ 打码（F13/F15）；AI 日志不记录回复全文、提示词、密钥。

## 6. WAF 与反爬（F10/F17）

- `includes/waf.php`：请求频率限制、扫描特征自动封禁 IP（1 小时）、缺失 UA/指纹触发 JS 挑战、反爬高频检测。
- 反调试探针 `assets/js/anti_debug.js` 全站引入（轻量、非阻断）。
- 测试注意：反复打探测 URL 会触发本地 IP 封禁；解封见运维须知。

## 7. 运维须知

- **命名铁律**：任何文件名/URL 参数不得出现 `chat`（免费主机 403）；接口文件名避开 `config/database/env/sql/bak`。
- **Service Worker 必须网络优先**（`sw.js` 缓存名 `lovewall-static-v3`），改 sw.js 记得升版本号。
- **切勿改回** `session.use_strict_mode=1`（CSRF 会永远失败）。
- **后台 IP 白名单（可选，F12）**：在 `config/config.php` 设 `ADMIN_ALLOWED_IPS='1.2.3.4,5.6.7.8'` 启用；默认空=不启用。

## 8. 应急响应流程

1. **疑似被入侵/篡改**：立即 `data/ip_blacklist.json` 置 `[]` 解除误封；检查 `operation_logs` 与 `waf_logs`。
2. **可疑上传/Webshell**：查 `uploads/` 下非图片文件，复核 `.htaccess` 的 F7 规则是否生效。
3. **数据泄露**：rotate 密钥文件（`config/ai_config.php` / `mail_config.php` / `sync_config.php`，不入仓库）；用 `maskSensitive()` 确认日志无明文隐私。
4. **封禁误伤**：解封 IP 后复测；必要时收紧 WAF 规则（勿改回已收窄的 SQL/XSS 规则，否则自然语言 URL 会 403）。
5. **安全更新**：优先在隔离环境用 `tools_build_assets.sh --force` 重新压缩，再灰度上线；前端混淆走 `tools_obfuscate_readme.md` 的 `--obfuscate` 流程。
