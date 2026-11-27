
/*
 * 视觉/布局巡检（无头 Edge）
 * 用法：BASE=http://127.0.0.1:8000 OUT_DIR=./_vis_out node tools/lw_visual_check.mjs
 *      PAGES=home,lottery 只跑指定页（逗号分隔）
 * 输出：每页 亮/暗 移动端截图 + report.json
 * 说明：本站反爬对 headless 有指纹，连续导航可能 403；脚本内置重试与间隔。
 */
import { spawn } from "node:child_process";
import fs from "node:fs";
import os from "node:os";
import path from "node:path";

const EDGE = process.env.EDGE_BIN || "C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe";
const PORT = Number(process.env.CDP_PORT || 10151);
const OUT = process.env.OUT_DIR || "./_vis_out";
const BASE = process.env.BASE || "http://127.0.0.1:8000";
fs.mkdirSync(OUT, { recursive: true });

const ALL = [
  ["home", ""],
  ["lottery", "pages/lottery.php"],
  ["lottery-detail", process.env.LOTTERY_URL_REL || "pages/lottery.php"],
  ["changelog", "changelog.php"],
  ["login", "pages/login.php"],
];
const want = (process.env.PAGES || "").split(",").filter(Boolean);
const pages = want.length ? ALL.filter(p => want.includes(p[0])) : ALL;

const profile = path.join(os.tmpdir(), "lw-vis-" + Date.now());
const proc = spawn(EDGE, ["--headless=new", "--disable-gpu", "--no-first-run", "--no-default-browser-check",
  "--mute-audio", "--disable-extensions", "--user-data-dir=" + profile,
  "--remote-debugging-port=" + PORT, "about:blank"], { stdio: "ignore" });
const sleep = (ms) => new Promise(r => setTimeout(r, ms));
async function waitPort(t) { const d = Date.now() + t; while (Date.now() < d) { try { const r = await fetch("http://127.0.0.1:" + PORT + "/json/version"); if (r.ok) return; } catch (e) {} await sleep(300); } throw new Error("port"); }

let ws, n = 0; const pend = new Map();
function send(m, p) { return new Promise((res, rej) => { const id = ++n; pend.set(id, { res, rej }); ws.send(JSON.stringify({ id, method: m, params: p || {} })); }); }
async function ev(x) { const r = await send("Runtime.evaluate", { expression: x, returnByValue: true, awaitPromise: true }); if (r.exceptionDetails) return "EXC"; return r.result && r.result.value; }
async function gotoWithRetry(url) {
  for (let i = 0; i < 4; i++) {
    await send("Page.navigate", { url });
    await sleep(7000 + i * 3000);
    const st = await ev("document.readyState + '|' + (document.body ? document.body.innerText.length : 0) + '|' + document.styleSheets.length");
    if (st && String(st).split("|")[2] !== "0") return st;
    await sleep(6000);
  }
  return "FAILED";
}

async function main() {
  await waitPort(20000);
  const list = await (await fetch("http://127.0.0.1:" + PORT + "/json")).json();
  ws = new WebSocket(list.find(t => t.type === "page").webSocketDebuggerUrl);
  await new Promise((res, rej) => { ws.onopen = res; ws.onerror = rej; });
  const errors = [];
  ws.addEventListener("message", (e) => {
    const m = JSON.parse(e.data);
    if (m.id && pend.has(m.id)) { const p = pend.get(m.id); pend.delete(m.id); if (m.error) p.rej(new Error(JSON.stringify(m.error))); else p.res(m.result); }
    if (m.method === "Runtime.exceptionThrown") errors.push("EXC:" + (m.params.exceptionDetails.text || ""));
  });
  await send("Page.enable"); await send("Runtime.enable"); await send("Network.enable");
  await send("Network.setUserAgentOverride", { userAgent: "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36", acceptLanguage: "zh-CN,zh;q=0.9" });
  await send("Emulation.setDeviceMetricsOverride", { width: 390, height: 844, deviceScaleFactor: 2, mobile: true });

  const report = [];
  for (const [name, rel] of pages) {
    errors.length = 0;
    const url = BASE + "/" + rel;
    const st = await gotoWithRetry(url);
    if (st === "FAILED") { console.log(name, "=> 加载失败（WAF 403？）"); report.push({ name, failed: true }); continue; }
    const info = await ev("(function(){var cs=getComputedStyle(document.body);return JSON.stringify({title:document.title.slice(0,40),bodyBg:cs.backgroundColor,bodyColor:cs.color,scrollW:document.documentElement.scrollWidth,innerW:innerWidth,overflow:document.documentElement.scrollWidth>innerWidth+2,cssLoaded:document.styleSheets.length>0,dark:document.documentElement.classList.contains('dark-theme')})})()");
    const shotLight = await send("Page.captureScreenshot", { format: "png", captureBeyondViewport: true });
    fs.writeFileSync(path.join(OUT, name + "-light.png"), Buffer.from(shotLight.data, "base64"));
    await ev("document.documentElement.classList.add('dark-theme');1");
    await sleep(700);
    const darkInfo = await ev("(function(){var cs=getComputedStyle(document.body);return JSON.stringify({bodyBg:cs.backgroundColor,bodyColor:cs.color})})()");
    const shotDark = await send("Page.captureScreenshot", { format: "png", captureBeyondViewport: true });
    fs.writeFileSync(path.join(OUT, name + "-dark.png"), Buffer.from(shotDark.data, "base64"));
    await ev("document.documentElement.classList.remove('dark-theme');1");
    report.push({ name, info: JSON.parse(info), dark: JSON.parse(darkInfo || "{}"), errors: [...errors] });
    console.log(name, "=>", info);
    console.log("   dark:", darkInfo);
    await sleep(4000);
  }
  fs.writeFileSync(path.join(OUT, "report.json"), JSON.stringify(report, null, 2));
  console.log("完成，截图目录:", OUT);
  await send("Browser.close").catch(() => {});
}
main().catch(e => console.log("失败:", e.message)).finally(async () => {
  try { ws && ws.close(); } catch (e) {}
  await sleep(200); try { proc.kill(); } catch (e) {}
  try { fs.rmSync(profile, { recursive: true, force: true }); } catch (e) {}
});
