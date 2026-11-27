// QQ 空间 Cookie 助手：启动 Edge(独立profile) → 读 Cookie → 推送到站点
import { spawn } from "node:child_process";
import fs from "node:fs";
import path from "node:path";

const DIR = path.dirname(new URL(import.meta.url).pathname.replace(/^\//, ""));
const CFG = JSON.parse(fs.readFileSync(path.join(DIR, "config.json"), "utf8"));
const EDGE = process.env.EDGE_PATH || "C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe";
const PORT = 9223;
const HEADLESS = process.argv.includes("--login") ? false : true;

const proc = spawn(EDGE, [
  HEADLESS ? "--headless=new" : "",
  "--disable-gpu", "--no-first-run", "--no-default-browser-check", "--mute-audio",
  "--user-data-dir=" + CFG.profile,
  "--remote-debugging-port=" + PORT,
  HEADLESS ? "about:blank" : "https://user.qzone.qq.com/" + CFG.uin,
].filter(Boolean), { detached: true, stdio: "ignore" });
const sleep = (ms) => new Promise(r => setTimeout(r, ms));

async function waitPort(t) {
  const d = Date.now() + t;
  while (Date.now() < d) {
    try { const r = await fetch("http://127.0.0.1:" + PORT + "/json/version"); if (r.ok) return; } catch {}
    await sleep(300);
  }
  throw new Error("CDP port not ready");
}

let ws, n = 0; const pend = new Map();
const send = (m, p) => new Promise((res, rej) => { const id = ++n; pend.set(id, { res, rej }); ws.send(JSON.stringify({ id, method: m, params: p || {} })); });

async function main() {
  await waitPort(20000);
  const list = await (await fetch("http://127.0.0.1:" + PORT + "/json")).json();
  ws = new WebSocket(list.find(t => t.type === "page").webSocketDebuggerUrl);
  await new Promise((res, rej) => { ws.onopen = res; ws.onerror = rej; });
  ws.addEventListener("message", e => { const m = JSON.parse(e.data); if (m.id && pend.has(m.id)) { const p = pend.get(m.id); pend.delete(m.id); m.error ? p.rej(new Error(JSON.stringify(m.error))) : p.res(m.result); } });
  await send("Network.enable");
  await send("Page.navigate", { url: "https://user.qzone.qq.com/" + CFG.uin });
  await sleep(HEADLESS ? 12000 : 0);
  if (!HEADLESS) { console.log("登录窗口已打开：请扫码登录，完成后按回车继续…"); await new Promise(r => process.stdin.once("data", r)); }
  const { cookies } = await send("Network.getAllCookies");
  const keep = cookies.filter(c => /qq\.com$/.test(c.domain)).map(c => c.name + "=" + c.value).join("; ");
  const pSkey = (cookies.find(c => c.name === "p_skey") || {}).value || "";
  if (!pSkey) { console.log("❌ 未找到 p_skey：请在 --login 模式下先登录 QQ 空间"); process.exit(1); }
  const resp = await fetch(CFG.site.replace(/\/$/, "") + "/_lw_cookie_push.php", {
    method: "POST",
    headers: { "Content-Type": "application/json" },
    body: JSON.stringify({ token: CFG.token, uin: CFG.uin, cookie: keep }),
  });
  const j = await resp.json();
  console.log(resp.status, JSON.stringify(j));
  try { ws.close(); } catch {}
  try { proc.kill(); } catch {}
  process.exit(j.ok ? 0 : 1);
}
main().catch(e => { console.error(e.message); try { proc.kill(); } catch {}; process.exit(1); });
