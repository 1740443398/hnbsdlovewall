# 第三方组件与许可声明 / Third-Party Notices

本项目（Love Wall / 校园交流墙）**本体**的授权见根目录 [LICENSE](LICENSE) ——
那是 Licensor「余灏明（網名 蕭遞，QQ 1740443398）」的自定义 Source-Available 许可，
**不是**下面任何一条第三方许可。

本文件列出项目内实际包含的第三方组件、各自的许可，以及为满足其许可条款所必须随分发提供的文本。

---

## 1. 网站端（PHP / JavaScript）

### 1.1 SheetJS Community Edition（`xlsx`）

| 项 | 内容 |
|---|---|
| 文件 | `assets/js/vendor/xlsx.full.min.js` |
| 用途 | 仅 `pages/rollcall.php`（随机点名）用于导入/导出 `.xlsx` 名单 |
| 版权 | `xlsx.js (C) 2013-present SheetJS -- http://sheetjs.com` |
| 许可 | Apache License 2.0 |
| 许可全文 | [licenses/Apache-2.0.txt](licenses/Apache-2.0.txt) |

> 说明：该文件的文件头只有一行版权声明，**不含** Apache-2.0 全文。
> Apache-2.0 第 4 条要求向接收者提供许可副本，因此本仓库把它放在 `licenses/Apache-2.0.txt`
> 并在此处指向它。二次分发时请一并带走这两个文件。

### 1.2 其余前端脚本

`assets/js/` 下的 `main.js`、`enhancements.js`、`anti_hijack.js`、`easter-eggs.js`、
`ai_chat_core.js`、`ai_widget.js` 均为本项目自行编写，无第三方许可义务。

### 1.3 字体

本项目**不引入任何外部字体**：`assets/css/style.css` 中的字体族全部是系统字体栈
（`Noto Serif SC` / `Songti SC` / `PingFang SC` / `Microsoft YaHei` 等），
由访问者的操作系统提供，不随本项目分发，因此无字体授权义务。

### 1.4 图标与图片

`icon.ico`、`assets/images/icons/*`（PWA 图标）、`assets/images/default-avatar.svg`
以及站点 logo 均为项目作者原创，随本项目按根目录 LICENSE 授权。

---

## 2. 服务端外部服务（不是随本项目分发的代码）

这些是运行期调用的外部 HTTP 接口，**其代码不在本仓库内**，此处列出是为了让部署者知道
站点会向哪些第三方发起请求（便于自行评估隐私与可用性）：

| 服务 | 用途 | 触发位置 |
|---|---|---|
| 智谱 AI（GLM，`open.bigmodel.cn`） | 站内 AI 助手的模型推理 | `api/ai_assistant.php` |
| wttr.in | 天气（工具页与 AI 助手） | `api/weather_proxy.php`、`includes/ai_site_data.php` |
| hitokoto.cn | 一言 / 句子 | `includes/ai_site_data.php` |
| 今日诗词（jinrishici.com） | 每日诗词 | `assets/js/enhancements.js` 等 |
| ipapi.co / ipify / ip.sb / ipapi.is | 公网 IP 查询（工具页） | 工具页前端 |
| api.qrserver.com | 二维码生成 | 工具页前端 |

上述服务各自的条款与隐私政策由其提供方决定，本项目不对其内容与可用性作任何担保。

---

## 3. 安卓客户端（`love_wall_app` 仓库）

> 安卓客户端是一个**独立仓库／目录**（`<安卓端目录>`，包名 `com.hnbsd.lovewall`），
> 它把本站点的移动版页面装在 WebView 里。它的许可声明放在它自己的仓库内，
> 但按根 LICENSE 第 2.b 条「二次分发必须显著保留署名并随分发包含原始 LICENSE」，
> 该仓库**必须**同时包含：
>
> 1. 一份与本站根目录**完全一致**的 `LICENSE`（不得改动一字）；
> 2. 一份 `NOTICE`，写明「本项目是校园交流墙的安卓客户端，著作权人 余灏明（蕭遞）QQ 1740443398」；
> 3. 一份第三方声明，至少覆盖下列 AndroidX 依赖：
>
> | 组件 | 许可 |
> |---|---|
> | `androidx.appcompat:appcompat` | Apache License 2.0 |
> | `androidx.work:work-runtime` | Apache License 2.0 |
>
> 并附上 [licenses/Apache-2.0.txt](licenses/Apache-2.0.txt) 的副本。
>
> 另外建议（非许可要求，属供应链安全）：安卓 release 构建目前使用 debug 签名，
> 任何人都能重新签名并分发同名 APK。请在 `NOTICE` 中说明
> 「官方安装包的 SHA-256 见站点 `/api/app_version.php`，安装前请核对」。

---

## 4. 关于用户数据（不属于第三方许可，但同样重要）

以下目录是**用户产生的内容与隐私数据**，不属于本项目源码，`.gitignore` 已排除，
**任何打包、备份或分发都必须排除它们**：

- `data/`（站点数据文件）
- `uploads/`（用户上传的图片等）

分发本项目源码时若不慎带上这两个目录，等于把部署实例的真实用户数据一并公开。
