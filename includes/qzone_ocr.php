<?php
/**
 * QQ 空间动态图片 OCR 文本 —— 只读读取层
 * ---------------------------------------------------------------------------
 * 数据由 tools/qzone_ocr.py 在本地离线生成（Windows 自带 OCR，不联网）：
 *   data/qzone_ocr.json = {
 *     "<tid>": {
 *       "text": "整条动态全部图片的识别文本",
 *       "engine": "windows.media.ocr",
 *       "generated_at": "2026-11-21 22:40:00",
 *       "images": [ {"i":0, "lines":[...], "chars":57}, {"i":1, "error":"..."} ]
 *     }
 *   }
 *
 * 三条约定：
 *  1) **只读**。这里不写文件、不改数据；OCR 结果是离线产物，随部署一起上传即可。
 *  2) **永不抛错**。JSON 缺文件、坏格式、缺字段，一律返回空串/空数组，
 *     因为详情页不能因为「没有 OCR 数据」而白屏。
 *  3) **进程内缓存**。一份 JSON 只读一次，详情页/信息流多处调用不会反复 IO。
 *
 * 典型用法（详情页）：
 *   require_once __DIR__ . '/../includes/qzone_ocr.php';
 *   $text = lwQzoneOcrText($tid);          // '' 表示没有可展示的文本
 *   $imgs = lwQzoneOcrImages($tid);        // 每张图的行数组（含 error）
 */

if (!function_exists('lwQzoneOcrFile')) {
    /** OCR 结果文件路径（固定相对站点根目录，避免受 cwd 影响）。 */
    function lwQzoneOcrFile(): string
    {
        return dirname(__DIR__) . '/data/qzone_ocr.json';
    }
}

if (!function_exists('lwQzoneOcrData')) {
    /**
     * 读取整份 OCR JSON（进程内缓存）。
     * 文件缺失 / 内容非法 / 编码异常 → 返回空数组，绝不抛错。
     */
    function lwQzoneOcrData(): array
    {
        static $cache = null;
        if ($cache !== null) {
            return $cache;
        }
        $cache = [];
        $file = lwQzoneOcrFile();
        if (!is_file($file)) {
            return $cache;
        }
        $raw = @file_get_contents($file);
        if (!is_string($raw) || $raw === '') {
            return $cache;
        }
        $json = json_decode($raw, true);
        if (!is_array($json)) {
            return $cache;
        }
        $cache = $json;
        return $cache;
    }
}

if (!function_exists('lwQzoneOcrRecord')) {
    /** 取单条动态的完整 OCR 记录；没有返回 null。 */
    function lwQzoneOcrRecord(string $tid): ?array
    {
        $tid = trim($tid);
        if ($tid === '') {
            return null;
        }
        $all = lwQzoneOcrData();
        if (!isset($all[$tid]) || !is_array($all[$tid])) {
            return null;
        }
        return $all[$tid];
    }
}

if (!function_exists('lwQzoneOcrText')) {
    /**
     * 单条动态的识别文本。没有数据 / 文本为空 → ''。
     * （详情页用它判断是否渲染「图片文字（自动识别）」折叠块。）
     */
    function lwQzoneOcrText(string $tid): string
    {
        $rec = lwQzoneOcrRecord($tid);
        if ($rec === null) {
            return '';
        }
        $text = $rec['text'] ?? '';
        return is_string($text) ? $text : '';
    }
}

if (!function_exists('lwQzoneOcrImages')) {
    /**
     * 单条动态每张图的识别结果：
     *   [ ['i'=>0, 'lines'=>[...], 'chars'=>57, 'scale'=>2], ['i'=>1, 'error'=>'media_missing'] ]
     * 没有数据 → []。
     */
    function lwQzoneOcrImages(string $tid): array
    {
        $rec = lwQzoneOcrRecord($tid);
        if ($rec === null) {
            return [];
        }
        $imgs = $rec['images'] ?? [];
        if (!is_array($imgs)) {
            return [];
        }
        /* 按 i 排序，保证展示顺序稳定（JSON 里通常已是顺序的，这里防御性排序） */
        usort($imgs, function ($a, $b) {
            return (int) ($a['i'] ?? 0) <=> (int) ($b['i'] ?? 0);
        });
        return $imgs;
    }
}

if (!function_exists('lwQzoneOcrLines')) {
    /**
     * 所有图片的非空文本行（去掉单图 error），供页面逐行渲染。
     * 没有数据 → []。
     */
    function lwQzoneOcrLines(string $tid): array
    {
        $out = [];
        foreach (lwQzoneOcrImages($tid) as $img) {
            $lines = $img['lines'] ?? [];
            if (!is_array($lines)) {
                continue;
            }
            foreach ($lines as $ln) {
                $ln = trim((string) $ln);
                if ($ln !== '') {
                    $out[] = $ln;
                }
            }
        }
        return $out;
    }
}

if (!function_exists('lwQzoneOcrHas')) {
    /** 是否有可展示的识别文本。 */
    function lwQzoneOcrHas(string $tid): bool
    {
        return trim(lwQzoneOcrText($tid)) !== '';
    }
}

if (!function_exists('lwQzoneOcrEngine')) {
    /** 识别引擎名（展示「由 … 识别」用）；没有数据返回 ''。 */
    function lwQzoneOcrEngine(string $tid): string
    {
        $rec = lwQzoneOcrRecord($tid);
        if ($rec === null) {
            return '';
        }
        $engine = $rec['engine'] ?? '';
        return is_string($engine) ? $engine : '';
    }
}


/* ============================================================================
 * 线上 OCR（站长要求：必须在服务器上自动跑，不能依赖本地机器）
 * ============================================================================
 * 共享主机没有 tesseract、也没有 Windows OCR —— 所以走**云端 OCR API**：
 *   · OCR.space 免费档：25k 次/月，支持中文（language=chs）
 *   · 同步时对「还没识别过」的图片自动调用，结果写进 data/qzone_ocr.json
 *   · 每轮最多识别 N 张（ocr_per_run），避免单次同步被拖太久
 *   · 任何失败都只记录、不抛错；详情页没文本就不显示折叠块
 *
 * 安全：API key 存在设置里，只在服务端使用，不下发到前端。
 */

if (!function_exists('lwQzoneOcrSaveRecord')) {
    /** 把一条 tid 的识别结果合并写回 data/qzone_ocr.json（带锁，原子写）。 */
    function lwQzoneOcrSaveRecord(string $tid, array $record): bool
    {
        if ($tid === '') { return false; }
        $file = lwQzoneOcrFile();
        $dir = dirname($file);
        if (!is_dir($dir)) { @mkdir($dir, 0755, true); }
        $lock = $file . '.lock';
        $fp = @fopen($lock, 'c');
        if (!$fp) { return false; }
        if (!flock($fp, LOCK_EX)) { fclose($fp); return false; }
        $all = [];
        if (is_file($file)) {
            $raw = @file_get_contents($file);
            $j = json_decode((string) $raw, true);
            if (is_array($j)) { $all = $j; }
        }
        $all[$tid] = $record;
        $tmp = $file . '.' . bin2hex(random_bytes(4)) . '.tmp';
        $ok = @file_put_contents($tmp, json_encode($all, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT)) !== false;
        if ($ok) { $ok = @rename($tmp, $file); }
        if (!$ok) { @unlink($tmp); }
        flock($fp, LOCK_UN);
        fclose($fp);
        return $ok;
    }
}

if (!function_exists('lwQzoneOcrRemote')) {
    /**
     * 调 OCR.space 识别一张图。成功返回文本，失败返回 ''。
     * $localFile 是本地缓存图路径（避免让 API 去抓 QQ 图片）。
     */
    function lwQzoneOcrRemote(string $localFile, string $apiKey = ''): array
    {
        if ($apiKey === '') { $cfg = lwQzoneConfig(); $apiKey = (string) ($cfg['ocr_api_key'] ?? ''); }
        if ($apiKey === '') { return ['ok' => false, 'err' => '未配置 OCR API Key']; }
        if (!is_file($localFile)) { return ['ok' => false, 'err' => '图片不存在']; }
        $bytes = @file_get_contents($localFile);
        if (!is_string($bytes) || $bytes === '') { return ['ok' => false, 'err' => '图片读取失败']; }
        if (strlen($bytes) > 1000000) {
            /* 免费档单文件上限 1MB：超了就跳过（压缩后一般远低于此） */
            return ['ok' => false, 'err' => '图片超过 1MB'];
        }
        $payload = [
            'apikey'          => $apiKey,
            'language'        => 'chs',
            'isOverlayRequired' => 'false',
            'OCREngine'       => '2',
            'scale'           => 'true',
            'isTable'         => 'false',
            'base64Image'     => 'data:image/jpeg;base64,' . base64_encode($bytes),
        ];
        $ch = curl_init('https://api.ocr.space/parse/image');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => http_build_query($payload),
            CURLOPT_TIMEOUT        => 45,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/x-www-form-urlencoded'],
        ]);
        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = (string) curl_error($ch);
        curl_close($ch);
        if (!is_string($body) || $body === '') {
            return ['ok' => false, 'err' => 'OCR 请求失败 ' . ($err ?: ('HTTP ' . $code))];
        }
        $j = json_decode($body, true);
        if (!is_array($j)) { return ['ok' => false, 'err' => 'OCR 响应不是 JSON']; }
        if (!empty($j['IsErroredOnProcessing'])) {
            $msg = $j['ErrorMessage'] ?? '未知错误';
            if (is_array($msg)) { $msg = implode('; ', array_map('strval', $msg)); }
            return ['ok' => false, 'err' => 'OCR 返回错误：' . $msg];
        }
        $text = '';
        if (!empty($j['ParsedResults']) && is_array($j['ParsedResults'])) {
            foreach ($j['ParsedResults'] as $r) {
                if (!empty($r['ParsedText'])) { $text .= (string) $r['ParsedText']; }
            }
        }
        $text = trim($text);
        if ($text === '') { return ['ok' => false, 'err' => 'OCR 没识别出文字']; }
        return ['ok' => true, 'text' => $text, 'err' => ''];
    }
}

if (!function_exists('lwQzoneOcrAutoRun')) {
    /**
     * 对「还没有识别结果」的说说自动跑 OCR。返回 ['done'=>n,'failed'=>n,'skipped'=>n,'err'=>...]
     * 每次最多 ocr_per_run 张图，避免同步被拖太久。
     */
    function lwQzoneOcrAutoRun(int $limit = 0): array
    {
        $cfg = lwQzoneConfig();
        if ((int) ($cfg['ocr_enabled'] ?? 0) !== 1) {
            return ['done' => 0, 'failed' => 0, 'skipped' => 0, 'err' => '未启用'];
        }
        $limit = $limit > 0 ? $limit : max(1, (int) ($cfg['ocr_per_run'] ?? 3));
        $items = getFS()->read('qzone_items');
        if (!is_array($items)) { return ['done' => 0, 'failed' => 0, 'skipped' => 0, 'err' => '无数据']; }
        usort($items, function ($a, $b) { return ((int) ($b['posted_ts'] ?? 0)) <=> ((int) ($a['posted_ts'] ?? 0)); });

        $done = 0; $failed = 0; $skipped = 0;
        foreach ($items as $it) {
            if ($done + $failed >= $limit) { break; }
            $tid  = (string) ($it['tid'] ?? '');
            $imgs = json_decode((string) ($it['images'] ?? '[]'), true);
            if ($tid === '' || !is_array($imgs) || !$imgs) { $skipped++; continue; }
            if (lwQzoneOcrHas($tid)) { $skipped++; continue; }

            $lines = []; $text = ''; $lastErr = '';
            foreach (array_values($imgs) as $idx => $u) {
                $u = (string) $u;
                if ($u === '') { continue; }
                $file = lwQzoneCacheImage($u);
                if ($file === '') { $lastErr = '图片缓存失败'; $lines[] = ['i' => $idx, 'lines' => [], 'error' => 'cache failed']; continue; }
                $r = lwQzoneOcrRemote($file);
                if ($r['ok']) {
                    $text .= ($text === '' ? '' : "\n") . $r['text'];
                    $lines[] = ['i' => $idx, 'lines' => preg_split('/\r?\n/', $r['text']), 'error' => ''];
                } else {
                    $lastErr = $r['err'];
                    $lines[] = ['i' => $idx, 'lines' => [], 'error' => $r['err']];
                }
                usleep(300000);
            }
            $text = trim($text);
            if ($text === '') {
                $failed++;
                lwQzoneOcrSaveRecord($tid, [
                    'text' => '', 'engine' => 'ocr.space', 'generated_at' => date('Y-m-d H:i:s'),
                    'images' => $lines, 'error' => $lastErr,
                ]);
                continue;
            }
            $ok = lwQzoneOcrSaveRecord($tid, [
                'text' => mb_substr($text, 0, 8000), 'engine' => 'ocr.space',
                'generated_at' => date('Y-m-d H:i:s'), 'images' => $lines, 'error' => '',
            ]);
            if ($ok) { $done++; } else { $failed++; }
        }
        return ['done' => $done, 'failed' => $failed, 'skipped' => $skipped, 'err' => ''];
    }
}
