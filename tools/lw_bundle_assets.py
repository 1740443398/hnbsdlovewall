#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
把首页的十几个小 CSS / JS 合并成 bundle.css 和 bundle.js。

【为什么必须合并】
线上主机的每个 HTTP 请求固定要 ~950ms（openresty 网关限流 + 冷启动），
跟文件大小无关 —— 传 300 B 和传 30 KB 都是将近 1 秒。
首页原来要拉 17 个独立资源，光排队就 17 秒，
用户感受到的就是「网站卡，发帖都发不出去」。

合并之后请求数从 17 降到 3（bundle.css + bundle.js + 主样式），
实测能省 13 秒左右。

用法：
    python tools/lw_bundle_assets.py            # 生成
    python tools/lw_bundle_assets.py --check    # 只检查

产物：
    assets/css/bundle.css
    assets/js/bundle.js
"""

from __future__ import annotations

import argparse
import hashlib
import io
import os
import re
import sys

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
SRC = os.path.join(ROOT, 'love_wall')

# 合并顺序很重要：基础库 → 功能 → 修饰
CSS_ORDER = [
    'assets/css/icon-motion.css',
    'assets/css/ux-polish.css',
    'assets/css/commercial.css',
    'assets/css/easter-eggs.css',
    'assets/css/polish.css',
    'assets/css/liquid-glass.css',
]

JS_ORDER = [
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
]


def pick(rel: str) -> str | None:
    """优先用压缩版：.obf.js > .min.js > .js，CSS 同理。"""
    base, ext = os.path.splitext(rel)
    cands = []
    if ext == '.js':
        cands.append(base + '.obf' + ext)
    cands.append(base + '.min' + ext)
    cands.append(rel)
    for c in cands:
        p = os.path.join(SRC, c)
        if os.path.isfile(p):
            return p
    return None


def strip_sourcemap(text: str) -> str:
    """去掉 sourceMappingURL 注释，避免合并后指向错的文件。"""
    return re.sub(r'//[#@]\s*sourceMappingURL=.*$', '', text, flags=re.M)


def build(kind: str, order: list[str]) -> tuple[str, int, list[str]]:
    parts = []
    used = []
    missing = []
    total = 0

    for rel in order:
        p = pick(rel)
        if not p:
            missing.append(rel)
            continue
        raw = io.open(p, encoding='utf-8', errors='replace').read()
        raw = strip_sourcemap(raw)
        total += len(raw.encode('utf-8'))
        used.append(os.path.basename(p))

        name = os.path.basename(p)
        if kind == 'css':
            parts.append('/* ==== %s ==== */\n%s' % (name, raw))
        else:
            # 每个文件包在 IIFE 里，避免顶层 const/let 互相冲突
            parts.append('/* ==== %s ==== */\n;(function(){\n%s\n})();' % (name, raw))

    banner = (
        '/* LoveWall bundle (%s) - 由 tools/lw_bundle_assets.py 生成，请勿手改 */\n'
        % kind
    )
    return banner + '\n'.join(parts) + '\n', total, missing


def main() -> int:
    ap = argparse.ArgumentParser()
    ap.add_argument('--check', action='store_true', help='只检查不写文件')
    args = ap.parse_args()

    ok = True
    for kind, order, out_rel in [
        ('css', CSS_ORDER, 'assets/css/bundle.css'),
        ('js', JS_ORDER, 'assets/js/bundle.js'),
    ]:
        content, src_bytes, missing = build(kind, order)
        data = content.encode('utf-8')
        digest = hashlib.sha256(data).hexdigest()[:12]

        if missing:
            print('[%s] 缺少源文件: %s' % (kind.upper(), ', '.join(missing)))
            ok = False

        out_path = os.path.join(SRC, out_rel)
        if not args.check:
            os.makedirs(os.path.dirname(out_path), exist_ok=True)
            with io.open(out_path, 'w', encoding='utf-8', newline='\n') as f:
                f.write(content)

        ratio = len(data) / max(src_bytes, 1) * 100
        print('[%s] %-28s %7d B  (源 %d B, 比 %.0f%%)  sha=%s'
              % (kind.upper(), os.path.basename(out_rel), len(data), src_bytes, ratio, digest))

    print()
    print('请求数: 17 -> 3，按每次 ~950ms 估算，约省 %.0f 秒' % ((17 - 3) * 0.95))
    return 0 if ok else 1


if __name__ == '__main__':
    sys.exit(main())
