/*
 * 视觉/布局巡检（无头 Edge）
 * 用法：
 *   1) 起本地服务：php -S 127.0.0.1:8000 -t love_wall
 *   2) BASE=http://127.0.0.1:8000 OUT_DIR=./_vis_out node tools/lw_visual_check.mjs
 * 输出：每页 亮/暗 两张移动端截图 + report.json（背景色/溢出/控制台错误）
 */

/* 视觉与布局巡检：无头 Edge 打开关键页面，采集截图 + 计算样式/溢出/报错 */
import { spawn } from "node:child_process";
import fs from "node:fs";
import os from "node:os";
import path from "node:path";
const EDGE = "C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe";
const PORT = 10151;
const OUT = process.env.OUT_DIR;
fs.mkdirSync(OUT, { recursive: true });
const profile = path.join(os.tmpdir(), "lw-vis-" + Date.now());
const proc = spawn(EDGE, ["--headless=new","--disable-gpu","--no-first-run","--no-default-browser-check",
  "--mute-audio","--disable-extensions","--user-data-dir=" + profile,
  "--remote-debugging-port=" + PORT, "about:blank"], { stdio: "ignore" });
const sleep = (ms) => new Promise(r => setTimeout(r, ms));
async function waitPort(t) { const d = Date.now() + t; while (Date.now() < d) { try { const r = await fetch("http://127.0.0.1:" + PORT + "/json/version"); if (r.ok) return; } catch (e) {} await sleep(300); } throw new Error("port"); }
let ws, n = 0; const pend = new Map();
function send(m, p) { return new Promise((res, rej) => { const id = ++n; pend.set(id, { res, rej }); ws.send(JSON.stringify({ id, method: m, params: p || {} })); }); }
async function ev(x) { const r = await send("Runtime.evaluate", { expression: x, returnByValue: true, awaitPromise: true }); if (r.exceptionDetails) return "EXC"; return r.result && r.result.value; }
async function main() {
  await waitPort(20000);
  const list = await (await fetch("http://127.0.0.1:" + PORT + "/json")).json();
  ws = new WebSocket(list.find(t => t.type === "page").webSocketDebuggerUrl);
  await new Promise((res, rej) => { ws.onopen = res; ws.onerror = rej; });
  const errors = [];
  ws.addEventListener("message", (e) => {
    const m = JSON.parse(e.data);
    if (m.id && pend.has(m.id)) { const p = pend.get(m.id); pend.delete(m.id); if (m.error) p.rej(new Error(JSON.stringify(m.error))); else p.res(m.result); }
    if (m.method === "Log.entryAdded" && m.params.entry.level === "error") errors.push(m.params.entry.text);
    if (m.method === "Runtime.exceptionThrown") errors.push("EXC:" + (m.params.exceptionDetails.text || ""));
  });
  await send("Page.enable"); await send("Runtime.enable"); await send("Log.enable");
  await send("Network.enable");
  await send("Network.setUserAgentOverride", { userAgent: "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36", acceptLanguage: "zh-CN,zh;q=0.9" });
  await send("Network.setExtraHTTPHeaders", { headers: { "Accept-Language": "zh-CN,zh;q=0.9" } });
  await send("Emulation.setDeviceMetricsOverride", { width: 390, height: 844, deviceScaleFactor: 2, mobile: true });

  const pages = [
    ["home", ""],
    ["lottery", "pages/lottery.php"],
    ["changelog", "changelog.php"],
    ["login", "pages/login.php"],
  ];
  const report = [];
  for (const [name, rel] of pages) {
    await sleep(2500);
    errors.length = 0;
    await send("Page.navigate", { url: process.env.BASE + "/" + rel });
    await sleep(9000);
    const info = await ev("(function(){var cs=getComputedStyle(document.body);var h=document.querySelector('header,.header,.topbar');var hs=h?getComputedStyle(h):null;return JSON.stringify({url:location.pathname,title:document.title.slice(0,30),bodyBg:cs.backgroundColor,bodyColor:cs.color,scrollW:document.documentElement.scrollWidth,innerW:innerWidth,overflow:document.documentElement.scrollWidth>innerWidth+2,headerBackdrop:hs?(hs.backdropFilter||hs.webkitBackdropFilter||'none'):'no-header',cssLoaded:document.styleSheets.length>0})})()");
    const dark = await ev("(function(){document.documentElement.classList.add('dark-theme');return 1;})()");
    await sleep(700);
    const darkInfo = await ev("(function(){var cs=getComputedStyle(document.body);return JSON.stringify({bodyBg:cs.backgroundColor,bodyColor:cs.color})})()");
    const shot = await send("Page.captureScreenshot", { format: "png", captureBeyondViewport: true });
    fs.writeFileSync(path.join(OUT, name + "-mobile-dark.png"), Buffer.from(shot.data, "base64"));
    const shot2 = await ev("(function(){document.documentElement.classList.remove('dark-theme');return 1;})()");
    await sleep(500);
    const shot3 = await send("Page.captureScreenshot", { format: "png", captureBeyondViewport: true });
    fs.writeFileSync(path.join(OUT, name + "-mobile-light.png"), Buffer.from(shot3.data, "base64"));
    report.push({ name, info: JSON.parse(info), dark: JSON.parse(darkInfo || "{}"), errors: [...errors] });
    console.log(name, "=>", info);
    console.log("   dark:", darkInfo);
    if (errors.length) console.log("   errors:", errors.slice(0, 3));
  }
  fs.writeFileSync(path.join(OUT, "report.json"), JSON.stringify(report, null, 2));
  await send("Browser.close").catch(() => {});
}
main().catch(e => console.log("失败:", e.message)).finally(async () => {
  try { ws && ws.close(); } catch (e) {}
  await sleep(200); try { proc.kill(); } catch (e) {}
  try { fs.rmSync(profile, { recursive: true, force: true }); } catch (e) {}
});
