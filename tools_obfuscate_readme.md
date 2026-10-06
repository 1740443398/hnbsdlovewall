# 静态资源混淆加固流水线（批次 I）

本文档说明 `tools_build_assets.sh` 的混淆加固设计：terser 参数、域名锁、
防篡改包装、回归方式，以及移动端 APK 已有的 R8 加固（不在本仓库范围内）。

> 范围边界：本次只改构建脚本 `tools_build_assets.sh` 与新增本文档。
> 绝不修改应用逻辑、配置、安全模块、布局、Service Worker 与 `.htaccess`。

---

## 1. 核心约束（为什么不会破坏站点）

| 约束 | 原因 | 实现 |
| --- | --- | --- |
| **不 mangle 属性名** (`mangle.properties=false`) | 保护 PHP 后端约定的 JSON 字段名、`DOM` 的 `class`/`id`，以及 `window.X` 跨文件全局 | terser 参数显式 `properties=false` |
| **所有入口 JS 均为顶层 IIFE 包裹** | 本仓库 `main.js / enhancements.js / ai_widget.js / polish.js / ai_core.js / ...` 全部以 `(function(){ 'use strict'; ... })();` 包裹；程序顶层**零声明** | 实测：7 个源文件顶层声明计数均为 0 |
| **`toplevel` mangle 对 IIFE 包裹文件无副作用** | 局部变量本就在 IIFE 作用域内、已被默认 mangle；`toplevel` 只影响「程序顶层未声明」的裸全局，而本仓库没有此类裸全局 | 开启安全；对未来裸顶层全局生效 |
| **压缩后过 `node --check` 语法闸门** | 任何语法坏产物都会告警，且因 `asset_url()` 回退源文件而不会发上线 | 脚本内置校验 |

> 实证：`mangle: {toplevel:true, properties:false}` 不会破坏 `window.App`、
> `window.IS_LOGGED_IN`、`window.CSRF_TOKEN` 等跨文件契约——它们走的是
> `window` **属性**访问，属性名不参与 mangle。

---

## 2. terser 参数

### 2.1 默认（SAFE，日常 `--force` 使用）
```
-c passes=3,hoist_vars=true,toplevel=true,keep_fargs=false,unsafe=false
-m toplevel=true,properties=false
--comments false
--format ecma=2020
```
- `passes=3`：多轮压缩，进一步折叠。
- `hoist_vars=true` / `toplevel=true`（compress）：更激进的变量提升与顶层丢弃。
- `keep_fargs=false`：允许丢弃未使用的函数参数名。
- `unsafe=false`：**保持安全**，unsafe 系留给 `--obfuscate` 模式。
- `mangle.toplevel=true`：见上文「为何安全」。
- `mangle.properties=false`：**关键**，绝不动属性名。
- `ecma=2020`：面向现代浏览器输出，保留高效语法。

### 2.2 混淆（`--obfuscate` 使用，默认关闭）
在 SAFE 基础上开启：
```
-c ...,unsafe=true,unsafe_comps=true,unsafe_math=true
```
- 进一步缩短/混淆（如合并比较、数值化简）。
- 仍保持 `properties=false`——**绝不 mangle 属性名**。

---

## 3. 域名锁 + 防篡改包装（仅 `--obfuscate`，默认关闭）

仅作用于 `assets/js` 下明确列出的入口文件：
`main.js` `enhancements.js` `ai_widget.js` `polish.js`

### 3.1 域名锁（Domain Lock）
- **触发条件**：环境变量 `LW_ALLOWED_HOSTS`（逗号分隔，由协调者构建时注入）。
  默认留空 = **不启用域名锁**，行为与未包装完全一致。
- **逻辑**：校验 `location.hostname`，若不在白名单（支持精确匹配与子域 `*.host`）
  则清空 `document.documentElement.innerHTML=''`、调用 `window.stop()` 并跳转
  `about:blank`。
- **可关闭/可配置**：仅当 `LW_ALLOWED_HOSTS` 非空才生成前置代码；不注入则零影响。

### 3.2 防篡改（Tamper Check，轻量自检）
- 对每个入口文件后置一段：`window.__lw_ok = true`，并
  `setInterval(()=>{ if(window.__lw_ok!==true) location.reload(); }, 3000)`。
- **不写裸 `debugger`**（避免卡住正常用户）。

### 3.3 弱反调试（仅 `LW_ANTIDEBUG=1` 注入，默认不注入）
- 形式：`(()=>{setInterval(()=>{if(!(window.__lw_ok)){}},2000)})()`
- 默认不注入；仅调试/对抗场景手动开启。

### 3.4 包装默认关闭
- 日常 `--force` 压缩**不会**套用任何包装。
- 必须显式 `--obfuscate`（或 `--force --obfuscate`）才生成包装版 `.min`。
- 线上 `hnbsd.ct.ws` 当前未使用 `--obfuscate`，因此**域名锁与防篡改对其无影响**。

---

## 4. clean-css
维持 `-O2`（已含 level 2 优化：高级合并、重写、去重等）。当前无更优稳定可加项，
保持 `--force` 行为不变。

---

## 5. 回归验证

### 5.1 语法闸门（必做）
每个 JS 产物压缩后脚本自动跑：
```
node --check <file>.min.js
```
不过则告警（运行时回退源文件，不会发坏代码）。

### 5.2 人工冒烟回归
1. 对 `assets/js/main.js` 复制为临时文件，使用加强后的 SAFE 参数压缩，
   `node --check` 确认无语法错误。
2. 浏览器渲染冒烟：确认登录态、发帖、点赞、通知、主题切换、AI 浮窗等核心
   功能正常（属性名未 mangle，DOM class/id 与 JSON 字段完好）。
3. 若启用 `--obfuscate`：在白名单域名下渲染正常；非白名单域名下页面被清空/跳转
   （域名锁生效）；篡改 `window.__lw_ok` 后 ~3s 自动重载（防篡改生效）。

### 5.3 一键重跑（由协调者统一执行）
```
bash tools_build_assets.sh --force
# 上线前可选加固：
LW_ALLOWED_HOSTS=hnbsd.ct.ws,www.hnbsd.ct.ws bash tools_build_assets.sh --force --obfuscate
```

---

## 6. 移动端 APK（R8 加固，不在本仓库范围内）

APK 侧已有 R8/ProGuard 加固，规则在移动工程的 `app/proguard-rules.pro`。
本文档只做引用说明，**不修改 APK**。Web 侧的混淆（本文档）与 APK 侧 R8 是两套
独立流水线，互不影响：

- Web：terser + clean-css（本仓库 `tools_build_assets.sh`）。
- APK：R8 压缩/混淆/优化（`app/proguard-rules.pro`，移动工程维护）。

两者目标一致——提升逆向成本，但作用于不同产物形态，各自独立回归。

---

## 7. 风险与备注
- **域名锁默认行为**：`LW_ALLOWED_HOSTS` 为空 = 完全不启用，零影响线上站点。
- **`--obfuscate` 默认关闭**：不影响日常 `--force` 全量压缩与协作编辑。
- **`unsafe_math` 风险**：仅在 `--obfuscate` 下启用，可能重写数值表达式；
  上线前务必走 5.2 渲染冒烟；日常构建不受影响。
- **不要 `--force` 覆盖全库后跳过回归**：最终统一重跑与渲染冒烟由协调者负责。
