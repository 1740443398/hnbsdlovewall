#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
把全站的小 CSS / JS 合并成 bundle.css 和 bundle.js（含 .min 版本）。

【为什么必须合并】
线上主机每个 HTTP 请求固定约 2 秒（openresty 网关 + 冷启动），跟文件大小无关 ——
传 1 KB 和传 200 KB 都是差不多的耗时。首页原来要拉 11 个独立资源，
合并后降到 5 个，实测省 12 秒左右。

【这个脚本解决的核心问题：忘记重建 .min】
asset_url() 的规则是「.min 和 source 都存在时选体积更小的那个」。
所以只改 bundle.css 而忘了重建 bundle.min.css，页面会继续加载旧的 min，
表现为「改了样式完全没生效」，甚至（如果旧 min 缺了主样式）全站变成纯文字。

本脚本做完合并后**自动调用 clean-css / terser 重建 .min**，并校验
两份产物的 CSS 变量数量是否一致 —— 不一致就报错退出。
这样就不存在「忘了重建」的可能。

用法：
    python tools/lw_bundle_assets.py            # 合并 + 重建 min + 校验
    python tools/lw_bundle_assets.py --check    # 只检查不写文件
    python tools/lw_bundle_assets.py --no-min   # 只合并，跳过 min 重建

产物：
    assets/css/bundle.css + bundle.min.css
    assets/js/bundle.js   + bundle.min.js
"""

from __future__ import annotations

import argparse
import hashlib
import io
import os
import re
import subprocess
import sys

# 项目根（本文件在 tools/ 下）
ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
SRC = ROOT

# ============================================================================
# 合并顺序
# ============================================================================
#
# 【CSS 顺序不能随便调】
#   style.css 必须第一 —— 它定义 :root 的全部设计令牌，
#   后面的文件全都依赖这些变量。
#
#   最后三段是「覆盖层」，顺序有严格语义：
#     mobile-pro.css    移动端基础覆盖（桌面端也是它先兜底）
#     apple-design.css  Apple 设计统一层（要覆盖前面所有散乱的材质/动效写法）
#     mobile-app.css    手机端 App 化（要覆盖 mobile-pro 的旧移动端规则）
#     dark-patch.css    暗色补丁（必须最后，它按选择器覆盖写死的颜色）
#
CSS_ORDER = [
    'assets/css/style.css',
    'assets/css/enhancements.css',
    'assets/css/icon-motion.css',
    'assets/css/ux-polish.css',
    'assets/css/commercial.css',
    'assets/css/easter-eggs.css',
    'assets/css/polish.css',
    'assets/css/liquid-glass.css',
    'assets/css/ai_widget.css',
    'assets/css/markdown.css',
    'assets/css/detail_polish.css',
    'assets/css/motion.css',
    'assets/css/mobile-pro.css',
    'assets/css/apple-design.css',
    'assets/css/motion-plus.css',
    'assets/css/motion-interact.css',
    'assets/css/mobile-app.css',
    'assets/css/dark-patch.css',
]

# 【JS 顺序同样不能随便调】
#
#   panel-manager.js 必须第一 —— 它是浮层互斥层，
#   用 MutationObserver 监听全站 class 变化，越早装上越好。
#
#   中间是基础设施（storage / cache / errorguard / anti_debug），
#   然后是增强模块，main.js 必须靠后（它依赖前面那些全局对象），
#   enhancements.js 在 main.js 之后（要给 main 的对象打补丁），
#   mobile-app.js 最后（纯手机端交互，不依赖其他模块）。

JS_ORDER = [
    'assets/js/panel-manager.js',
    'assets/js/lw_storage.js',
    'assets/js/lw_request_cache.js',
    'assets/js/lw_errorguard.js',
    'assets/js/anti_debug.js',
    'assets/js/ux-polish.js',
    'assets/js/commercial.js',
    'assets/js/easter-eggs.js',
    'assets/js/polish.js',
    'assets/js/liquid-glass.js',
    'assets/js/signature.js',
    'assets/js/motion.js',
    'assets/js/editor_md.js',
    'assets/js/ux_enhance2.js',
    'assets/js/ux_enhance.js',
    'assets/js/main.js',
    'assets/js/enhancements.js',
    'assets/js/mobile-app.js',
    'assets/js/motion-plus.js',
    'assets/js/motion-interact.js',
]


def pick(rel):
    """优先用压缩版：.min.js / .min.css，没有就用源文件。

    注意不比 mtime —— FTP 上传会重置时间戳，比时间反而不可靠。
    源文件改了必须重新生成 .min（本脚本的 --no-min 会跳过，慎用）。
    """
    base, ext = os.path.splitext(rel)
    for cand in (base + ".min" + ext, rel):
        p = os.path.join(SRC, cand)
        if os.path.isfile(p):
            return p
    return None


def strip_sourcemap(text):
    """去掉 sourceMappingURL 注释 —— 合并后那个路径会指向错误的文件。"""
    return re.sub(r"//[#@]\s*sourceMappingURL=.*$", "", text, flags=re.M)


def build(kind, order):
    parts = []
    used = []
    missing = []
    total = 0

    for rel in order:
        p = pick(rel)
        if not p:
            missing.append(rel)
            continue

        raw = io.open(p, encoding="utf-8", errors="replace").read()
        raw = strip_sourcemap(raw)
        total += len(raw.encode("utf-8"))
        used.append(os.path.basename(p))

        name = os.path.basename(p)
        if kind == "css":
            parts.append("/* ==== %s ==== */\n%s" % (name, raw))
        else:
            # 每个文件包在 IIFE 里：避免顶层 const/let 重名互相冲突。
            # 前置分号：防止上一个文件结尾没有分号时被拼成语法错误。
            parts.append("/* ==== %s ==== */\n;(function(){\n%s\n})();" % (name, raw))

    banner = ("/* LoveWall bundle (%s) - 由 tools/lw_bundle_assets.py 生成，请勿手改 */\n" % kind)
    return banner + "\n".join(parts) + "\n", total, missing, used


def run(cmd, cwd):
    """跑外部命令，返回 (成功, 输出)。"""
    try:
        r = subprocess.run(cmd, cwd=cwd, capture_output=True, text=True, timeout=180)
        return r.returncode == 0, (r.stdout or "") + (r.stderr or "")
    except FileNotFoundError:
        return False, "命令不存在"
    except Exception as e:
        return False, str(e)


def find_tool(name, kind):
    """找 clean-css / terser 的可执行入口。"""
    appdata = os.environ.get("APPDATA", "")
    if kind == "css":
        cands = [os.path.join(appdata, "npm", "node_modules", "clean-css-cli", "bin", "cleancss")]
    else:
        cands = [os.path.join(appdata, "npm", "node_modules", "terser", "bin", "terser")]
    for c in cands:
        if os.path.isfile(c):
            return c
    return None


def rebuild_min(kind):
    """用 clean-css / terser 重建 .min。返回 (成功, 说明)。"""
    css_dir = os.path.join(SRC, "assets", "css")
    js_dir = os.path.join(SRC, "assets", "js")

    if kind == "css":
        tool = find_tool("cleancss", "css")
        if not tool:
            return False, "找不到 clean-css-cli（npm i -g clean-css-cli）"
        ok, out = run(["node", tool, "-O2", "-o", "bundle.min.css", "bundle.css"], css_dir)
        if not ok:
            return False, "clean-css 失败: " + out[:200]
        # 有 WARNING 也要报出来 —— 上一轮就是被静默的警告坑过
        warns = [l for l in out.splitlines() if "WARNING" in l.upper()]
        if warns:
            return True, "clean-css 有 %d 条警告: %s" % (len(warns), " | ".join(warns[:5]))
        return True, "clean-css 通过，无警告"

    tool = find_tool("terser", "js")
    if not tool:
        return False, "找不到 terser（npm i -g terser）"
    ok, out = run(["node", tool, "bundle.js", "-c", "passes=1", "-m", "--ecma", "2020",
                   "-o", "bundle.min.js"], js_dir)
    if not ok:
        return False, "terser 失败: " + out[:200]
    return True, "terser 通过"


def count_vars(path):
    """数 CSS 里 --xxx: 声明了多少个不同的变量。

    用途：校验 bundle.css 与 bundle.min.css 是否同源。
    full 有 181 个、min 只有 34 个 = min 是旧版（页面会加载它 → 样式全丢）。
    """
    if not os.path.isfile(path):
        return -1
    text = io.open(path, encoding="utf-8", errors="replace").read()
    return len(set(re.findall(r"--([a-z0-9-]+)\s*:", text)))


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--check", action="store_true", help="只检查，不写文件")
    ap.add_argument("--no-min", action="store_true", help="跳过 .min 重建（不推荐）")
    args = ap.parse_args()

    ok = True
    jobs = [
        ("css", CSS_ORDER, "assets/css/bundle.css"),
        ("js", JS_ORDER, "assets/js/bundle.js"),
    ]

    for kind, order, out_rel in jobs:
        content, src_bytes, missing, used = build(kind, order)
        data = content.encode("utf-8")
        digest = hashlib.sha256(data).hexdigest()[:12]

        if missing:
            print("[%s] 缺少源文件: %s" % (kind.upper(), ", ".join(missing)))
            ok = False

        out_path = os.path.join(SRC, out_rel)
        if not args.check:
            with io.open(out_path, "w", encoding="utf-8", newline="\n") as f:
                f.write(content)

        print("[%s] %-22s %7d B  (源 %d B, %.0f 段)  sha=%s"
              % (kind.upper(), os.path.basename(out_rel), len(data), src_bytes, len(used), digest))

    # ---- 重建 min ----
    if not args.check and not args.no_min:
        print()
        for kind in ("css", "js"):
            good, msg = rebuild_min(kind)
            print("[MIN-%s] %s" % (kind.upper(), msg))
            if not good:
                ok = False

    # ---- 校验 full 与 min 是否同源 ----
    if not args.check:
        print()
        f = count_vars(os.path.join(SRC, "assets/css/bundle.css"))
        m = count_vars(os.path.join(SRC, "assets/css/bundle.min.css"))
        if f < 0 or m < 0:
            print("[VERIFY] 找不到 bundle.css 或 bundle.min.css")
            ok = False
        elif f != m:
            print("[VERIFY] ✗ CSS 变量数不一致: bundle.css=%d  bundle.min.css=%d" % (f, m))
            print("         页面加载的是 .min，说明 .min 是旧版 → 样式会出错")
            ok = False
        else:
            print("[VERIFY] ✓ CSS 变量一致 (%d 个)，bundle.min.css 与 bundle.css 同源" % f)

        # JS 也做一次存在性 + 体积合理性检查
        jf = os.path.getsize(os.path.join(SRC, "assets/js/bundle.js"))
        jm = os.path.getsize(os.path.join(SRC, "assets/js/bundle.min.js"))
        if jm < jf * 0.3 or jm > jf * 1.2:
            print("[VERIFY] ✗ bundle.min.js 体积异常: full=%d B  min=%d B" % (jf, jm))
            ok = False
        else:
            print("[VERIFY] ✓ bundle.min.js 体积合理 (%d B → %d B)" % (jf, jm))

    print()
    if ok:
        print("完成。别忘了把 bundle.css / bundle.min.css / bundle.js / bundle.min.js 四个文件都部署上去。")
    else:
        print("有失败项，见上方输出。")
    return 0 if ok else 1


if __name__ == "__main__":
    sys.exit(main())
