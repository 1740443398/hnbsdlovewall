<?php
/**
 * QQ 空间图片代理 + 本地缓存
 * ---------------------------------------------------------------------------
 * 为什么必须有这一层：
 *   QQ 空间的图片有防盗链。实测同一个图片地址：
 *     · 无 Referer           → 200 image/jpeg 48881B（真图）
 *     · 带 https://user.qzone.qq.com/ Referer → 同上（真图）
 *     · 带本站 Referer        → 200 image/png 3691B（防盗链占位图）
 *   所以前台**不能**直接 <img src="https://photo.store.qq.com/...">，
 *   否则用户看到的是一张灰色占位图。
 *
 * 安全边界（这是个把外部内容带回本站的入口，必须收紧）：
 *   1) 不接受任意 URL 参数 —— 只接受本站数据库里的 tid + 图片序号，
 *      URL 由服务端自己查出来，杜绝拿本站当任意 URL 抓取器（SSRF）。
 *   2) 域名白名单：只允许 QQ 图片域名。
 *   3) 只按 image/* 落盘，单张上限 8MB。
 *   4) 缓存文件名用 sha1(URL)，不反射任何外部字符串进路径。
 */

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/qzone_sync.php';

$tid = preg_replace('/[^a-f0-9]/i', '', (string) ($_GET['tid'] ?? ''));
$idx = max(0, min(8, (int) ($_GET['i'] ?? 0)));
if ($tid === '' || strlen($tid) < 8) {
    http_response_code(400);
    exit;
}

$fs  = getFS();
$row = $fs->findOne('qzone_items', ['tid' => $tid]);
if (!$row) {
    http_response_code(404);
    exit;
}
$imgs = json_decode((string) ($row['images'] ?? '[]'), true);
if (!is_array($imgs) || !isset($imgs[$idx])) {
    http_response_code(404);
    exit;
}
$url = (string) $imgs[$idx];

$host = strtolower((string) parse_url($url, PHP_URL_HOST));
$hostOk = false;
foreach (['photo.store.qq.com', 'qlogo.cn', 'qpic.cn', 'gtimg.cn'] as $suffix) {
    if ($host === $suffix || substr($host, -strlen('.' . $suffix)) === '.' . $suffix) {
        $hostOk = true;
        break;
    }
}
if (!$hostOk || strpos($url, 'https://') !== 0) {
    http_response_code(403);
    exit;
}

$dir = dirname(__DIR__, 2) . '/data/qzone_media';
if (!is_dir($dir)) {
    @mkdir($dir, 0755, true);
}
$file = $dir . '/' . sha1($url) . '.img';

$serve = function (string $path) {
    $size = (int) @filesize($path);
    $type = 'image/jpeg';
    $head = (string) @file_get_contents($path, false, null, 0, 12);
    if (strncmp($head, "\x89PNG", 4) === 0) { $type = 'image/png'; }
    elseif (strncmp($head, "GIF8", 4) === 0) { $type = 'image/gif'; }
    elseif (strncmp($head, 'RIFF', 4) === 0 && substr($head, 8, 4) === 'WEBP') { $type = 'image/webp'; }
    header('Content-Type: ' . $type);
    header('Content-Length: ' . $size);
    /* 缓存一周：图片地址里有 QQ 的签名，内容基本不变 */
    header('Cache-Control: public, max-age=604800, immutable');
    header('X-Content-Type-Options: nosniff');
    readfile($path);
};

if (is_file($file) && filesize($file) > 512) {
    $serve($file);
    exit;
}

/* 回源：带 QQ 的 Referer（实测这个 Referer 拿到的是真图） */
$ch = curl_init($url);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_TIMEOUT        => 15,
    CURLOPT_CONNECTTIMEOUT => 6,
    CURLOPT_ENCODING       => '',
    CURLOPT_HTTPHEADER     => [
        'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36',
        'Referer: https://user.qzone.qq.com/',
        'Accept: image/avif,image/webp,image/apng,image/*,*/*;q=0.8',
    ],
]);
$body = curl_exec($ch);
$code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
$type = (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
curl_close($ch);

if (!is_string($body) || $code !== 200 || strpos($type, 'image/') !== 0 || strlen($body) < 512 || strlen($body) > 8 * 1024 * 1024) {
    http_response_code(502);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'image fetch failed';
    exit;
}
/* 原子写，避免并发读到一个写了一半的文件 */
$tmp = $file . '.' . bin2hex(random_bytes(4)) . '.tmp';
if (@file_put_contents($tmp, $body) !== false) {
    @rename($tmp, $file);
} else {
    @unlink($tmp);
    header('Content-Type: ' . $type);
    header('Cache-Control: public, max-age=3600');
    echo $body;
    exit;
}
$serve($file);
