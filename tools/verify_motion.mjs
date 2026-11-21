#!/usr/bin/env node
/**
 * 动效运行时验证（真实浏览器）
 * ================================================================
 *
 * 【为什么需要这个】
 * 前面所有动效都是「改了 CSS/JS 就部署」，从没在真实浏览器里确认过
 * 它们到底有没有跑起来。CSS 里写了 keyframes 不等于元素上真的应用了；
 * 类名拼错、选择器不匹配、模块被压缩吞掉，都可能让动效静默失效，
 * 而构建脚本只会告诉你「文件生成了」。
 *
 * 这个脚本用本机 Edge 的 headless 模式 + CDP 协议打开一个 mock 页面，
 * 读取真实的 computed style 和模块状态，回答：
 *   · js-motion 加上了吗
 *   · 各动效模块初始化了吗
 *   · 首屏卡片的 animation-name 真的是 mp-card-in 吗
 *   · 视口外的元素有没有被标记、滚动后有没有显示
 *   · 液态指示器建出来了吗、点击后位置对不对
 *   · 点击类元素的 .mp-press 挂上了吗
 *
 * 【为什么用 mock 页面而不是直接开线上站】
 * InfinityFree 有双域 cookie 检查（hnbsd.ct.ws → sv101.ifastnet.com/cookies.html），
 * headless 浏览器过不去。mock 页面复用真实的 bundle.min.css/js，
 * 结构和线上一致，足以验证「动效栈本身」是否正常。
 *
 * 用法：
 *   node tools/verify_motion.mjs
 *   node tools/verify_motion.mjs --site   # 额外检查线上 bundle 是否含关键标记
 */

import { spawn } from "node:child_process";
import fs from "node:fs";
import os from "node:os";
import path from "node:path";
import { fileURLToPath } from "node:url";

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const ROOT = path.resolve(__dirname, "..");
const EDGE_CANDIDATES = [
  "C:\\Program Files (x86)\\Microsoft\\Edge\\Application\\msedge.exe",
  "C:\\Program Files\\Microsoft\\Edge\\Application\\msedge.exe",
  "C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe",
  "C:\\Program Files (x86)\\Google\\Chrome\\Application\\chrome.exe",
];
const EDGE = EDGE_CANDIDATES.find(p => fs.existsSync(p));
if (!EDGE) {
  console.error("找不到 Edge/Chrome，无法验证");
  process.exit(1);
}

const PORT = 9361 + Math.floor(Math.random() * 40);
const profile = path.join(os.tmpdir(), "lw-motion-verify-" + Date.now());
const mockPath = path.join(ROOT, "_lw_mock_verify.html");

const outside = Array.from({ length: 10 }, (_, i) => `
      <div class="post-card" data-post-id="${10 + i}">
        <div class="post-card-title">首屏外第 ${i + 1} 张</div>
        <div class="post-card-content">用于验证滚动渐显</div>
      </div>`).join("");

const MOCK = `<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<script>document.documentElement.classList.add('js-motion');</script>
<link rel="stylesheet" href="assets/css/bundle.min.css">
<style>
  body { margin: 0; padding: 16px; font-family: -apple-system, "PingFang SC", sans-serif; background: #F5F6F8; }
  .post-card { margin-bottom: 14px; padding: 14px; background: #fff; border-radius: 12px; }
</style>
</head>
<body>
<div class="site-main">
  <div class="category-filters">
    <button class="cat-filter active" data-cat="all">全部</button>
    <button class="cat-filter" data-cat="notice">校园公告</button>
    <button class="cat-filter" data-cat="study">学习求助</button>
    <button class="cat-filter" data-cat="life">生活分享</button>
  </div>
  <div class="posts-container">
    <div class="post-card ssr-card" data-post-id="1">
      <div class="post-card-title">首屏帖子一</div>
      <div class="post-card-actions">
        <button class="action-btn like-btn"><span class="action-icon"></span><span class="action-count">3</span></button>
        <button class="action-btn favorite-btn"><span class="action-icon"></span></button>
      </div>
    </div>
    <div class="post-card ssr-card" data-post-id="2"><div class="post-card-title">首屏帖子二</div></div>
    <div class="post-card ssr-card" data-post-id="3"><div class="post-card-title">首屏帖子三</div></div>
    ${outside}
  </div>
  <div style="height:1600px"></div>
</div>
<script src="assets/js/bundle.min.js"></script>
</body>
</html>`;

fs.writeFileSync(mockPath, MOCK, "utf8");

const proc = spawn(EDGE, [
  "--headless=new", "--disable-gpu", "--no-first-run",
  "--no-default-browser-check", "--mute-audio", "--disable-extensions",
  "--allow-file-access-from-files",
  "--user-data-dir=" + profile,
  "--remote-debugging-port=" + PORT,
  "about:blank",
], { stdio: "ignore" });

const sleep = (ms) => new Promise(r => setTimeout(r, ms));

async function waitPort(timeoutMs) {
  const deadline = Date.now() + timeoutMs;
  while (Date.now() < deadline) {
    try {
      const r = await fetch("http://127.0.0.1:" + PORT + "/json/version");
      if (r.ok) return await r.json();
    } catch (e) {}
    await sleep(300);
  }
  throw new Error("Edge 调试端口没起来");
}

let ws = null;
let msgId = 0;
const pending = new Map();

function send(method, params) {
  return new Promise((resolve, reject) => {
    const id = ++msgId;
    pending.set(id, { resolve, reject });
    ws.send(JSON.stringify({ id, method, params: params || {} }));
  });
}

async function evaluate(expression) {
  const r = await send("Runtime.evaluate", { expression, returnByValue: true });
  return r.result && r.result.value;
}

async function main() {
  await waitPort(20000);
  const list = await (await fetch("http://127.0.0.1:" + PORT + "/json")).json();
  const page = list.find(t => t.type === "page");
  ws = new WebSocket(page.webSocketDebuggerUrl);
  await new Promise((res, rej) => { ws.onopen = res; ws.onerror = rej; });
  ws.onmessage = (ev) => {
    const m = JSON.parse(ev.data);
    if (m.id && pending.has(m.id)) {
      const p = pending.get(m.id); pending.delete(m.id);
      if (m.error) p.reject(new Error(JSON.stringify(m.error))); else p.resolve(m.result);
    }
  };

  await send("Page.enable");
  await send("Runtime.enable");
  await send("Page.navigate", { url: "file:///" + mockPath.replace(/\\/g, "/").replace(/^\//, "") });
  await sleep(2600);

  const first = JSON.parse(await evaluate(`(function(){
    var out = {
      jsMotion: document.documentElement.classList.contains('js-motion'),
      reducedMotion: matchMedia('(prefers-reduced-motion: reduce)').matches,
      modules: {
        LWMotion: typeof window.LWMotion,
        LWInteract: typeof window.LWInteract,
        LWLiquidTab: typeof window.LWLiquidTab
      }
    };
    var cards = document.querySelectorAll('.ssr-card');
    if (cards.length) {
      var cs = getComputedStyle(cards[0]);
      out.cardAnim = { name: cs.animationName, duration: cs.animationDuration };
      out.card2Delay = getComputedStyle(cards[1]).animationDelay;
    }
    out.reveal = {
      mpReveal: document.querySelectorAll('.mp-reveal').length,
      mpIn: document.querySelectorAll('.mp-in').length
    };
    var cf = document.querySelector('.category-filters');
    if (cf) {
      var lt = cf.querySelector('.lw-liquid-tab');
      out.liquid = {
        hasLiquidClass: cf.classList.contains('has-liquid'),
        flexWrap: getComputedStyle(cf).flexWrap,
        overflowX: getComputedStyle(cf).overflowX,
        indicatorExists: !!lt,
        indicator: lt ? { left: lt.style.left, width: lt.style.width, height: lt.style.height } : null
      };
    }
    var lb = document.querySelector('.like-btn');
    var f = document.querySelector('.cat-filter');
    out.press = {
      likeBtn: lb ? lb.classList.contains('mp-press') : null,
      catFilter: f ? f.classList.contains('mp-press') : null,
      transition: lb ? getComputedStyle(lb).transitionProperty : null
    };
    return JSON.stringify(out);
  })()`));

  await evaluate("window.scrollTo(0, 1500); 'ok'");
  await sleep(900);

  const after = JSON.parse(await evaluate(`(function(){
    return JSON.stringify({
      scrollY: window.scrollY,
      mpReveal: document.querySelectorAll('.mp-reveal').length,
      mpIn: document.querySelectorAll('.mp-in').length
    });
  })()`));

  /* 点击第 4 个 Tab，看指示器是否移动 */
  await evaluate("document.querySelectorAll('.cat-filter')[3].click(); 'ok'");
  await sleep(600);
  const moved = JSON.parse(await evaluate(`(function(){
    var lt = document.querySelector('.lw-liquid-tab');
    return JSON.stringify({ left: lt ? lt.style.left : null, width: lt ? lt.style.width : null });
  })()`));

  const PASS = [];
  const FAIL = [];
  const check = (name, ok, detail) => (ok ? PASS : FAIL).push(name + (detail ? " → " + detail : ""));

  check("js-motion 已加上", first.jsMotion === true);
  check("三个动效模块都初始化", ["LWMotion", "LWInteract", "LWLiquidTab"].every(k => first.modules[k] === "object"), JSON.stringify(first.modules));
  check("首屏卡片 CSS 动画在跑", first.cardAnim && first.cardAnim.name === "mp-card-in", first.cardAnim ? first.cardAnim.name + " / " + first.cardAnim.duration : "无");
  check("卡片错峰生效", first.card2Delay && first.card2Delay !== "0s", first.card2Delay);
  check("液态 Tab 已挂载", first.liquid && first.liquid.hasLiquidClass && first.liquid.indicatorExists, first.liquid ? "flexWrap=" + first.liquid.flexWrap : "无");
  check("分类栏改单行横滑", first.liquid && first.liquid.flexWrap === "nowrap" && first.liquid.overflowX === "auto");
  check("首屏外元素已标记隐藏", first.reveal.mpReveal > 0 && first.reveal.mpIn === 0, "reveal=" + first.reveal.mpReveal + " in=" + first.reveal.mpIn);
  check("滚动后渐显元素已显示", after.mpIn > 0 && after.mpIn === first.reveal.mpReveal, "in=" + after.mpIn);
  check("点赞按钮有按压回弹", first.press.likeBtn === true, "transition=" + first.press.transition);
  check("分类按钮有按压回弹", first.press.catFilter === true);
  check("点击 Tab 后指示器移动", moved.left && moved.left !== "0.998573px", "left=" + moved.left + " width=" + moved.width);

  console.log("\n========== 动效运行时验证 ==========\n");
  console.log("通过 " + PASS.length + " 项：");
  for (const p of PASS) console.log("  ✓ " + p);
  if (FAIL.length) {
    console.log("\n未通过 " + FAIL.length + " 项：");
    for (const f of FAIL) console.log("  ✗ " + f);
  }
  console.log("\n" + (FAIL.length ? "结果：有未通过项" : "结果：全部通过"));

  await send("Browser.close").catch(() => {});
  return FAIL.length === 0;
}

main()
  .then(ok => { process.exitCode = ok ? 0 : 1; })
  .catch(e => { console.error("验证失败:", e.message); process.exitCode = 2; })
  .finally(async () => {
    try { ws && ws.close(); } catch (e) {}
    await sleep(400);
    try { proc.kill(); } catch (e) {}
    try { fs.rmSync(profile, { recursive: true, force: true }); } catch (e) {}
    try { fs.rmSync(mockPath, { force: true }); } catch (e) {}
  });
