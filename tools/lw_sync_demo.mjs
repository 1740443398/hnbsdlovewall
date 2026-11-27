#!/usr/bin/env node
/*
 * 把主仓库的「代码」同步到比赛演示副本（love_wall_competition），按内容哈希只拷差异文件。
 *
 * 用法：
 *   node tools/lw_sync_demo.mjs            # 执行同步
 *   node tools/lw_sync_demo.mjs --dry      # 只列出将要同步的文件
 *
 * 设计：
 *   - 不同步 config/（演示副本有自己的密钥/站点配置）
 *   - 不同步 data/、uploads/（演示数据独立）
 *   - 跳过以下划线开头的临时文件/目录
 *   - 只拷贝内容哈希不同的文件，避免覆盖时间戳
 */
import fs from "node:fs";
import path from "node:path";
import crypto from "node:crypto";

const MAIN = process.env.MAIN_DIR || path.resolve(process.cwd(), "love_wall");
const DEMO = process.env.DEMO_DIR || path.resolve(process.cwd(), "love_wall_competition");
const DRY = process.argv.includes("--dry");

const SKIP_DIRS = new Set(["config", "data", "uploads", ".git", "node_modules", "_probe"]);
const hash = (p) => crypto.createHash("md5").update(fs.readFileSync(p)).digest("hex");

function walk(dir, rel = "") {
  const out = [];
  for (const ent of fs.readdirSync(dir, { withFileTypes: true })) {
    const r = rel ? rel + "/" + ent.name : ent.name;
    if (ent.name.startsWith("_")) continue;
    if (ent.isDirectory()) {
      if (SKIP_DIRS.has(ent.name)) continue;
      out.push(...walk(path.join(dir, ent.name), r));
    } else {
      if (/.(zip|log|png|jpg|jpeg|gif|webp)$/i.test(ent.name)) continue;
      out.push(r);
    }
  }
  return out;
}

if (!fs.existsSync(MAIN) || !fs.existsSync(DEMO)) {
  console.error("目录不存在：", MAIN, "或", DEMO);
  process.exit(1);
}

const files = walk(MAIN);
let copied = 0, same = 0, missing = 0;
for (const rel of files) {
  const src = path.join(MAIN, rel);
  const dst = path.join(DEMO, rel);
  if (!fs.existsSync(dst)) {
    missing++;
  } else if (hash(src) === hash(dst)) {
    same++;
    continue;
  }
  if (DRY) {
    console.log("[同步]", rel);
    copied++;
  } else {
    fs.mkdirSync(path.dirname(dst), { recursive: true });
    fs.copyFileSync(src, dst);
    copied++;
  }
}
console.log(`${DRY ? "[dry] " : ""}同步 ${copied} 个，已一致 ${same} 个，副本缺失 ${missing} 个`);
