#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
QQ 空间动态图片本地 OCR
==========================================================================
用途：把 data/qzone_items.json 里每条动态的图片（基本都是聊天记录/说说截图）
      用本机引擎识别成文字，落 data/qzone_ocr.json，供详情页
      「图片文字（自动识别）」折叠展示。

引擎优先级（不联网、不装巨型依赖）：
  A) Windows.Media.Ocr（Windows 10/11 自带，本机已装中文简体语言包 zh-Hans-CN）
     —— 通过 Windows PowerShell 5.1 + WinRT 调用，无需 pip 包。
  B) tesseract（若 PATH 里有，且装了 chi_sim / eng 语言包）
  C) 都没有：明确报不可用（退出码 2），不擅自安装依赖。

实现要点：
  * 一个 PowerShell 进程批量识别全部图片：逐张起进程会被 WinRT 初始化拖慢
    （单进程批量 22 张约 6 秒，逐张要约 40 秒）。
  * 每张图跑「原图」+「2 倍放大」两档，取识别中文字符更多的一档。
    截图字号小，放大后识别率明显更好（实测 420px 宽截图
    "能不能注意一点" 从缺字变成完整）。
  * Windows OCR 会在汉字之间插空格（"有 没 有"），这里统一清成正常中文排版。
  * 单图失败只记 error，不影响其它图；整个引擎不可用才回退到 tesseract。

输出结构（data/qzone_ocr.json）：
  {
    "<tid>": {
      "text": "整条动态全部图片的识别文本（换行分隔，上限 8000 字）",
      "engine": "windows.media.ocr",
      "generated_at": "2026-11-21 22:40:00",
      "images": [
        {"i": 0, "lines": ["第一行", "第二行"], "chars": 37},
        {"i": 1, "error": "media_missing"}
      ]
    }
  }
  单张图文本上限 2000 字（PAGE_IMAGE_LIMIT）。

用法（在站点根目录，或任意目录，路径自动相对本文件定位）：
  python tools/qzone_ocr.py                 # 全量重跑
  python tools/qzone_ocr.py --keep-existing # 已有文本的 tid 跳过（只补新图）
  python tools/qzone_ocr.py --limit 3       # 只跑前 3 张图（调试用）
  python tools/qzone_ocr.py --engine tesseract
  python tools/qzone_ocr.py --print-samples 2
"""

import argparse
import hashlib
import json
import os
import re
import shutil
import subprocess
import sys
import tempfile
import time
from datetime import datetime

TOOLS_DIR = os.path.dirname(os.path.abspath(__file__))
ROOT = os.path.dirname(TOOLS_DIR)

DEFAULT_ITEMS = os.path.join(ROOT, 'data', 'qzone_items.json')
DEFAULT_MEDIA_DIR = os.path.join(ROOT, 'data', 'qzone_media')
DEFAULT_OUT = os.path.join(ROOT, 'data', 'qzone_ocr.json')

IMAGE_CHAR_LIMIT = 2000
TEXT_CHAR_LIMIT = 8000
LANG_TAG = 'zh-Hans-CN'
ENGINE_WIN = 'windows.media.ocr'
ENGINE_TESS = 'tesseract'

# ---------------------------------------------------------------------------
# 文本清理：Windows OCR 的中文结果每字之间带空格，这里还原成正常排版
# ---------------------------------------------------------------------------
CJK_CLASS = (r'\u3400-\u4dbf\u4e00-\u9fff\uf900-\ufaff'
             r'\u3000-\u303f\uff00-\uffef')  # 汉字 + 中文标点 + 全角字符
RE_CJK = re.compile('[' + r'\u3400-\u4dbf\u4e00-\u9fff\uf900-\ufaff' + ']')
RE_SPACE_LEFT_WIDE = re.compile(r'(?<=[' + CJK_CLASS + r']) ')
RE_SPACE_RIGHT_WIDE = re.compile(r' (?=[' + CJK_CLASS + r'])')
RE_DIGIT_GAP = re.compile(r'(?<=\d) (?=\d)')
RE_MULTI_SPACE = re.compile(r'[ \t\u00a0\u3000]+')
RE_JUNK_LINE = re.compile(r'^[0OoQ@*.#~+=_\-—·•・|/\\]{1,3}$')


def normalize_line(text):
    """把一行 OCR 原始结果整理成可读文本。"""
    if text is None:
        return ''
    s = str(text).replace('\u00a0', ' ').replace('\u3000', ' ')
    s = RE_MULTI_SPACE.sub(' ', s).strip()
    # 汉字/中文标点两侧的空格去掉（"有 没 有" -> "有没有"），数字之间的空格也去掉
    for _ in range(3):
        before = s
        s = RE_SPACE_LEFT_WIDE.sub('', s)
        s = RE_SPACE_RIGHT_WIDE.sub('', s)
        s = RE_DIGIT_GAP.sub('', s)
        if s == before:
            break
    return s.strip()


def clean_lines(raw_lines):
    """清理整页行：去空行、去纯符号噪声行（头像圆圈被识别成 O/0 很常见）。"""
    out = []
    for raw in raw_lines or []:
        line = normalize_line(raw)
        if not line:
            continue
        if RE_JUNK_LINE.match(line):
            continue
        out.append(line)
    return out


def cap_lines(lines, limit=IMAGE_CHAR_LIMIT):
    """单张图文本上限，超了按行截断。"""
    out = []
    used = 0
    for line in lines:
        if used >= limit:
            break
        room = limit - used
        if len(line) + 1 > room:
            piece = line[:max(0, room)]
            if piece:
                out.append(piece)
            break
        out.append(line)
        used += len(line) + 1
    return out


def score_lines(lines):
    """挑选更优识别档位：中文字符数优先，其次总长度。"""
    joined = '\n'.join(lines)
    return (len(RE_CJK.findall(joined)), len(joined))


# ---------------------------------------------------------------------------
# Windows.Media.Ocr（WinRT）—— 通过 Windows PowerShell 5.1 调用
# ---------------------------------------------------------------------------
PS_SCRIPT = r'''param([string]$JobPath, [string]$OutPath, [string]$LanguageTag = 'zh-Hans-CN')
$ErrorActionPreference = 'Stop'
$ProgressPreference = 'SilentlyContinue'

Add-Type -AssemblyName System.Runtime.WindowsRuntime
$script:asTaskGeneric = ([System.WindowsRuntimeSystemExtensions].GetMethods() | Where-Object {
    $_.Name -eq 'AsTask' -and $_.GetParameters().Count -eq 1 -and
    $_.GetParameters()[0].ParameterType.Name -eq 'IAsyncOperation' + [char]96 + '1'
})[0]

function Await($WinRtTask, $ResultType) {
    $asTask = $script:asTaskGeneric.MakeGenericMethod($ResultType)
    $netTask = $asTask.Invoke($null, @($WinRtTask))
    try { $netTask.Wait(-1) | Out-Null } catch {
        $ie = $_.Exception.InnerException
        $msgs = @()
        while ($null -ne $ie) { $msgs += ($ie.GetType().Name + ': ' + $ie.Message); $ie = $ie.InnerException }
        throw (($msgs -join ' | '))
    }
    Write-Output $netTask.Result
}

[void][Windows.Storage.StorageFile,Windows.Storage,ContentType=WindowsRuntime]
[void][Windows.Graphics.Imaging.BitmapDecoder,Windows.Graphics,ContentType=WindowsRuntime]
[void][Windows.Media.Ocr.OcrEngine,Windows.Media,ContentType=WindowsRuntime]
[void][Windows.Globalization.Language,Windows.Globalization,ContentType=WindowsRuntime]

$engine = $null
if ($LanguageTag -ne '') {
    try {
        $lang = New-Object Windows.Globalization.Language $LanguageTag
        $engine = [Windows.Media.Ocr.OcrEngine]::TryCreateFromLanguage($lang)
    } catch { $engine = $null }
}
if ($null -eq $engine) { $engine = [Windows.Media.Ocr.OcrEngine]::TryCreateFromUserProfileLanguages() }

if ($null -eq $engine) {
    $payload = @{ ok = $false; error = 'no_ocr_engine'; files = @() }
    $json = $payload | ConvertTo-Json -Depth 6 -Compress
    [IO.File]::WriteAllText($OutPath, $json, (New-Object System.Text.UTF8Encoding $false))
    exit 3
}

$job = (Get-Content -Raw -Encoding UTF8 $JobPath) | ConvertFrom-Json
$results = New-Object System.Collections.ArrayList
foreach ($f in $job.files) {
    $rec = [ordered]@{
        id = [string]$f.id; ok = $false; ms = 0; width = 0; height = 0;
        lines = @(); error = ''
    }
    $sw = [Diagnostics.Stopwatch]::StartNew()
    try {
        $sf = Await ([Windows.Storage.StorageFile]::GetFileFromPathAsync([string]$f.path)) ([Windows.Storage.StorageFile])
        $stream = Await ($sf.OpenAsync([Windows.Storage.FileAccessMode]::Read)) ([Windows.Storage.Streams.IRandomAccessStream])
        $decoder = Await ([Windows.Graphics.Imaging.BitmapDecoder]::CreateAsync($stream)) ([Windows.Graphics.Imaging.BitmapDecoder])
        $rec.width = [int]$decoder.PixelWidth
        $rec.height = [int]$decoder.PixelHeight
        $bmp = Await ($decoder.GetSoftwareBitmapAsync()) ([Windows.Graphics.Imaging.SoftwareBitmap])
        $ocr = Await ($engine.RecognizeAsync($bmp)) ([Windows.Media.Ocr.OcrResult])
        $lines = New-Object System.Collections.ArrayList
        foreach ($ln in $ocr.Lines) {
            $t = [string]$ln.Text
            if ($t.Trim() -ne '') { [void]$lines.Add($t) }
        }
        $rec.lines = @($lines)
        $rec.ok = $true
        $bmp.Dispose()
        $stream.Dispose()
    } catch {
        $rec.error = [string]$_.Exception.Message
    }
    $sw.Stop()
    $rec.ms = [int]$sw.ElapsedMilliseconds
    [void]$results.Add($rec)
}

$payload = @{
    ok = $true;
    engine = 'windows.media.ocr';
    language = [string]$engine.RecognizerLanguage.LanguageTag;
    max_dimension = [int][Windows.Media.Ocr.OcrEngine]::MaxImageDimension;
    files = @($results)
}
$json = $payload | ConvertTo-Json -Depth 6 -Compress
[IO.File]::WriteAllText($OutPath, $json, (New-Object System.Text.UTF8Encoding $false))
exit 0
'''


class EngineUnavailable(Exception):
    pass


def _powershell_exe():
    env = os.environ.get('LW_OCR_POWERSHELL', '')
    if env and os.path.isfile(env):
        return env
    for name in ('powershell.exe', 'powershell'):
        p = shutil.which(name)
        if p:
            return p
    windir = os.environ.get('SystemRoot') or os.environ.get('WINDIR') or r'C:\Windows'
    p = os.path.join(windir, 'System32', 'WindowsPowerShell', 'v1.0', 'powershell.exe')
    return p if os.path.isfile(p) else ''


def _win_path(path):
    """WinRT 只认反斜杠路径（正斜杠会 FileNotFoundException）。"""
    return os.path.abspath(path).replace('/', '\\')


def run_windows_ocr(jobs, workdir, timeout=600):
    """jobs: [{"id": ..., "path": 本地绝对路径}] -> {id: rec}

    一次 PowerShell 进程识别全部图片。找不到引擎抛 EngineUnavailable。
    """
    exe = _powershell_exe()
    if not exe:
        raise EngineUnavailable('找不到 powershell.exe')

    script_path = os.path.join(workdir, 'ocr_windows.ps1')
    job_path = os.path.join(workdir, 'ocr_job.json')
    out_path = os.path.join(workdir, 'ocr_out.json')
    with open(script_path, 'w', encoding='utf-8') as fh:
        fh.write(PS_SCRIPT)
    payload = {'files': [{'id': j['id'], 'path': _win_path(j['path'])} for j in jobs]}
    with open(job_path, 'w', encoding='utf-8') as fh:
        json.dump(payload, fh, ensure_ascii=False)

    cmd = [exe, '-NoProfile', '-NonInteractive', '-ExecutionPolicy', 'Bypass',
           '-File', script_path, '-JobPath', job_path, '-OutPath', out_path,
           '-LanguageTag', LANG_TAG]
    try:
        proc = subprocess.run(cmd, capture_output=True, timeout=timeout)
    except subprocess.TimeoutExpired:
        raise EngineUnavailable('PowerShell OCR 超时（%ss）' % timeout)
    err = (proc.stderr or b'').decode('utf-8', 'replace').strip()

    if not os.path.isfile(out_path):
        raise EngineUnavailable('PowerShell OCR 无输出：' + (err[:400] or 'exit=%s' % proc.returncode))
    try:
        with open(out_path, 'r', encoding='utf-8') as fh:
            data = json.load(fh)
    except Exception as exc:
        raise EngineUnavailable('PowerShell OCR 输出无法解析：%s' % exc)

    if not data.get('ok'):
        raise EngineUnavailable('系统 OCR 引擎不可用：%s' % data.get('error', 'unknown'))

    result = {}
    for rec in data.get('files') or []:
        if not isinstance(rec, dict):
            continue
        rid = str(rec.get('id', ''))
        if not rid:
            continue
        result[rid] = {
            'ok': bool(rec.get('ok')),
            'lines': [str(x) for x in (rec.get('lines') or [])],
            'ms': int(rec.get('ms') or 0),
            'width': int(rec.get('width') or 0),
            'height': int(rec.get('height') or 0),
            'error': str(rec.get('error') or ''),
        }
    return result


# ---------------------------------------------------------------------------
# tesseract 兜底
# ---------------------------------------------------------------------------
class TesseractEngine(object):
    name = ENGINE_TESS

    def __init__(self):
        self.exe = shutil.which('tesseract') or ''
        self.langs = ''
        if self.exe:
            try:
                proc = subprocess.run([self.exe, '--list-langs'], capture_output=True, timeout=20)
                text = (proc.stdout or b'').decode('utf-8', 'replace')
                text += (proc.stderr or b'').decode('utf-8', 'replace')
                found = [ln.strip() for ln in text.splitlines()
                         if ln.strip() and ' ' not in ln.strip() and ':' not in ln.strip()]
                usable = [l for l in ('chi_sim', 'eng') if l in found]
                self.langs = '+'.join(usable) if usable else ('eng' if 'eng' in found else '')
            except Exception:
                self.langs = ''

    def available(self):
        return bool(self.exe and self.langs)

    def describe(self):
        if not self.exe:
            return 'tesseract 未安装（PATH 无 tesseract）'
        if not self.langs:
            return 'tesseract 存在但缺少 eng / chi_sim 语言包'
        return 'tesseract %s (%s)' % (self.exe, self.langs)

    def recognize(self, path):
        cmd = [self.exe, path, 'stdout', '-l', self.langs, '--psm', '6']
        proc = subprocess.run(cmd, capture_output=True, timeout=60)
        text = (proc.stdout or b'').decode('utf-8', 'replace')
        return {'ok': True, 'lines': [ln for ln in text.splitlines() if ln.strip()],
                'ms': 0, 'width': 0, 'height': 0, 'error': ''}


# ---------------------------------------------------------------------------
# 图片准备：原图 + 2 倍放大（截图字号小，放大能显著提升召回）
# ---------------------------------------------------------------------------
def upscale_image(src, dst, scale=2):
    """用 Pillow 最近邻/连乘放大。Pillow 缺失或图太大返回 False。"""
    try:
        from PIL import Image
    except Exception:
        return False
    try:
        with Image.open(src) as im:
            im = im.convert('RGB')
            w, h = im.size
            if w <= 0 or h <= 0 or (w * h) * (scale * scale) > 40000000:
                return False
            im = im.resize((int(w * scale), int(h * scale)), Image.LANCZOS)
            im.save(dst, 'PNG')
        return True
    except Exception:
        return False


# ---------------------------------------------------------------------------
# 数据装载
# ---------------------------------------------------------------------------
def sha1_text(text):
    return hashlib.sha1(text.encode('utf-8')).hexdigest()


def resolve_media(media_dir, url):
    """按 includes/qzone_sync.php 的 lwQzoneMediaFile() 规则找缓存图。

    先找全尺寸缓存 <sha1(url)>.jpg，退而求其次找 420px 变体 <sha1(url)>_w420.jpg。
    """
    h = sha1_text(url)
    candidates = [os.path.join(media_dir, h + '.jpg'),
                  os.path.join(media_dir, h + '_w420.jpg')]
    for path in candidates:
        if os.path.isfile(path) and os.path.getsize(path) > 512:
            return path
    return ''


def load_items(path):
    with open(path, 'r', encoding='utf-8') as fh:
        data = json.load(fh)
    if isinstance(data, dict):
        data = data.get('items') or []
    return data if isinstance(data, list) else []


def load_existing(path):
    try:
        with open(path, 'r', encoding='utf-8') as fh:
            data = json.load(fh)
        return data if isinstance(data, dict) else {}
    except Exception:
        return {}


# ---------------------------------------------------------------------------
# 主流程
# ---------------------------------------------------------------------------
def main(argv=None):
    # Windows 控制台默认 GBK，这里统一 UTF-8 输出，避免样例文本乱码
    for stream in (sys.stdout, sys.stderr):
        try:
            stream.reconfigure(encoding='utf-8', errors='replace')
        except Exception:
            pass
    ap = argparse.ArgumentParser(description='QQ 空间图片本地 OCR（Windows 系统 OCR / tesseract）')
    ap.add_argument('--items', default=DEFAULT_ITEMS, help='qzone_items.json 路径')
    ap.add_argument('--media', default=DEFAULT_MEDIA_DIR, help='qzone_media 缓存目录')
    ap.add_argument('--out', default=DEFAULT_OUT, help='输出 JSON 路径')
    ap.add_argument('--engine', default='auto', choices=['auto', 'windows', 'tesseract'])
    ap.add_argument('--keep-existing', action='store_true',
                    help='已有非空 text 的 tid 直接跳过（只补新增动态）')
    ap.add_argument('--limit', type=int, default=0, help='最多识别多少张图（调试）')
    ap.add_argument('--no-upscale', action='store_true', help='不做 2 倍放大对比')
    ap.add_argument('--print-samples', type=int, default=0, help='打印前 N 条非空样例')
    args = ap.parse_args(argv)

    t0 = time.time()
    items = load_items(args.items)
    existing = load_existing(args.out)

    # 1) 组装待识别图片清单
    tasks = []           # [(tid, i, media_path)]
    image_errors = {}    # {(tid, i): 'media_missing'}
    for item in items:
        tid = str(item.get('tid') or '')
        if not tid:
            continue
        try:
            urls = json.loads(item.get('images') or '[]')
        except Exception:
            urls = []
        if not isinstance(urls, list):
            urls = []
        for idx, url in enumerate(urls):
            media = resolve_media(args.media, str(url))
            if media:
                tasks.append((tid, idx, media))
            else:
                image_errors[(tid, idx)] = 'media_missing'

    if args.keep_existing:
        keep_tids = {tid for tid, rec in existing.items()
                     if isinstance(rec, dict) and str(rec.get('text') or '').strip()}
        before = len(tasks)
        tasks = [t for t in tasks if t[0] not in keep_tids]
        skipped = before - len(tasks)
    else:
        skipped = 0
    if args.limit > 0:
        tasks = tasks[:args.limit]

    if not tasks:
        if not image_errors and not skipped:
            print('没有找到任何图片，检查 data/qzone_items.json 与 data/qzone_media。')
            return 2
        if skipped:
            # --keep-existing 命中全部条目的图：沿用已有结果，不再重新识别
            out = {k: v for k, v in existing.items() if isinstance(v, dict)}
            non_empty = sum(1 for v in out.values() if str(v.get('text') or '').strip())
            size = os.path.getsize(args.out) if os.path.isfile(args.out) else 0
            first = next(iter(out.values()), {})
            print('引擎: %s（本轮无需重新识别）' % (str(first.get('engine') or '') or 'unknown'))
            print('动态条数: %d（有文本 %d），识别图片: 成功 0 / 失败 0，跳过 %d（--keep-existing）'
                  % (len(out), non_empty, skipped))
            print('输出: %s（%.1f KB）' % (args.out, size / 1024.0))
            return 0 if non_empty else 2

    # 2) 选引擎 + 批量识别
    workdir = tempfile.mkdtemp(prefix='lw_qzone_ocr_')
    engine_name = ''
    raw_results = {}
    warnings = []
    variant_map = {}   # variant_id -> (tid, i, scale, 本地图片路径)
    key_of = {}        # (tid, i) -> 原图档位的 variant_id
    for n, (tid, idx, media) in enumerate(tasks):
        key = 'n%d' % n
        key_of[(tid, idx)] = key
        variant_map[key] = (tid, idx, 1, media)

    if tasks and args.engine in ('auto', 'windows'):
        jobs = [{'id': k, 'path': v[3]} for k, v in variant_map.items()]
        if not args.no_upscale:
            for key in list(variant_map.keys()):
                tid, idx, scale, media = variant_map[key]
                up_path = os.path.join(workdir, key + '_x2.png')
                if upscale_image(media, up_path, 2):
                    variant_map[key + '_x2'] = (tid, idx, 2, up_path)
                    jobs.append({'id': key + '_x2', 'path': up_path})
        try:
            raw_results = run_windows_ocr(jobs, workdir)
            engine_name = ENGINE_WIN
        except EngineUnavailable as exc:
            warnings.append('Windows OCR 不可用：%s' % exc)
            raw_results = {}

    if tasks and not raw_results and args.engine in ('auto', 'tesseract'):
        tess = TesseractEngine()
        if tess.available():
            engine_name = ENGINE_TESS
            for n, (tid, idx, media) in enumerate(tasks):
                try:
                    raw_results['n%d' % n] = tess.recognize(media)
                except Exception as exc:
                    raw_results['n%d' % n] = {'ok': False, 'lines': [], 'error': str(exc)}
        else:
            warnings.append('tesseract 不可用：%s' % tess.describe())

        if tasks and not raw_results:
            print('OCR 引擎不可用：')
            for w in warnings:
                print('  - ' + w)
            print('')
            print('替代方案（任选其一即可，不改本脚本逻辑）：')
            print("  1) 安装 Tesseract 5 并勾选中文简体语言包：")
            print('     winget install UB-Mannheim.TesseractOCR')
            print('     （或 https://github.com/UB-Mannheim/tesseract/wiki 下载安装包，')
            print('       安装时勾选 Chinese Simplified，再把 tesseract.exe 所在目录加入 PATH）')
            print('  2) 用 pip 安装免安装的 OCR 方案（体积较大，需另行评估）：')
            print('     pip install rapidocr-onnxruntime   # 中文效果较好，模型约 15MB')
            print("     然后把本脚本 run_windows_ocr() 改为调用 rapidocr 即可，输出结构不变。")
            return 2

    # 3) 合并识别结果：每张图在原图 / 2 倍放大两档里选一档
    #    规则：放大档的汉字数不低于原图档就用放大档（截图字号小，放大后更准；
    #    实测 892px 宽聊天图 "墙墙投" -> "墙墙投稿"）。放大档失败/变差则用原图。
    generated_at = datetime.now().strftime('%Y-%m-%d %H:%M:%S')
    records = {}
    stat_ok = stat_fail = 0
    for tid, idx, media in tasks:
        key = key_of.get((tid, idx))
        if not key:
            continue
        errors = []
        prepared = {}
        for suffix in ('', '_x2'):
            rec = raw_results.get(key + suffix)
            if rec is None:
                continue
            if not rec.get('ok'):
                if rec.get('error'):
                    errors.append(rec['error'])
                continue
            prepared[suffix] = cap_lines(clean_lines(rec.get('lines')))
        rec_out = {'i': idx}
        if not prepared:
            rec_out['error'] = errors[0] if errors else 'ocr_failed'
            stat_fail += 1
        else:
            base = prepared.get('')
            up = prepared.get('_x2')
            chosen, chosen_suffix = base, ''
            if up is not None and (base is None or score_lines(up)[0] >= score_lines(base)[0]):
                chosen, chosen_suffix = up, '_x2'
            if chosen is None:
                rec_out['error'] = errors[0] if errors else 'ocr_failed'
                stat_fail += 1
            else:
                rec_out['lines'] = chosen
                rec_out['chars'] = score_lines(chosen)[1]
                rec_out['scale'] = 2 if chosen_suffix == '_x2' else 1
                stat_ok += 1
        records.setdefault(tid, {'images': []})['images'].append(rec_out)

    # 临时放大图用完即删（OCR 结果已经拿到）
    shutil.rmtree(workdir, ignore_errors=True)

    # 4) 补 media_missing 的图 + 生成 text
    for (tid, idx), err in image_errors.items():
        records.setdefault(tid, {'images': []})['images'].append({'i': idx, 'error': err})
        stat_fail += 1

    # 每个 tid 的 images 按 i 排序；text = 各图文本换行拼接（总上限 TEXT_CHAR_LIMIT）
    out = {}
    covered = 0
    non_empty = 0
    for item in items:
        tid = str(item.get('tid') or '')
        if not tid:
            continue
        rec = records.get(tid)
        if rec is None:
            continue
        images = sorted(rec['images'], key=lambda x: int(x.get('i') or 0))
        parts = []
        used = 0
        for img in images:
            if not img.get('lines'):
                continue
            piece = '\n'.join(img['lines'])
            if used >= TEXT_CHAR_LIMIT:
                break
            room = TEXT_CHAR_LIMIT - used
            if len(piece) > room:
                piece = piece[:room]
            if piece:
                parts.append(piece)
                used += len(piece) + 1
        text = '\n'.join(parts)[:TEXT_CHAR_LIMIT]
        out[tid] = {
            'text': text,
            'engine': engine_name,
            'generated_at': generated_at,
            'images': images,
        }
        covered += 1
        if text.strip():
            non_empty += 1

    # 保留本次没覆盖到的历史 tid（例如已从 qzone_items 移除但已识别过的）
    for tid, rec in existing.items():
        if tid not in out and isinstance(rec, dict):
            out[tid] = rec

    # 5) 落盘（UTF-8 无 BOM，原子替换）
    os.makedirs(os.path.dirname(os.path.abspath(args.out)), exist_ok=True)
    tmp_out = args.out + '.tmp'
    with open(tmp_out, 'w', encoding='utf-8') as fh:
        json.dump(out, fh, ensure_ascii=False, indent=1)
        fh.write('\n')
    os.replace(tmp_out, args.out)

    elapsed = time.time() - t0
    size = os.path.getsize(args.out)
    print('引擎: %s' % engine_name)
    for w in warnings:
        print('  警告: ' + w)
    print('动态条数: %d（有文本 %d），识别图片: 成功 %d / 失败 %d，跳过 %d（--keep-existing）'
          % (len([1 for i in items if str(i.get('tid') or '') in out]), non_empty,
             stat_ok, stat_fail, skipped))
    print('总耗时: %.1f 秒，输出: %s（%.1f KB）' % (elapsed, args.out, size / 1024.0))
    if warnings and not non_empty:
        return 2

    n_show = args.print_samples
    if n_show <= 0:
        n_show = 2
    shown = 0
    for tid, rec in out.items():
        if shown >= n_show:
            break
        text = str(rec.get('text') or '').strip()
        if not text:
            continue
        shown += 1
        print('')
        print('样例 %d（tid=%s，%d 字）:' % (shown, tid, len(text)))
        print(text if len(text) <= 400 else text[:400] + ' …')
    return 0 if non_empty > 0 else 2


if __name__ == '__main__':
    try:
        sys.exit(main())
    except KeyboardInterrupt:
        sys.exit(130)
