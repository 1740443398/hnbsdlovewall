<?php
/**
 * QQ 空间图片代理
 * ---------------------------------------------------------------------------
 * 现在这份文件只负责三件事：定位、鉴权校验、输出。
 * 真正的「抓取 + 压缩 + 落盘」统一在 includes/qzone_sync.php 的 lwQzoneCacheImage()，
 * 同步时预热、页面访问时兜底，两条路径共用一份缓存。
 *
 * 为什么不能直接 <img src="QQ地址">：
 *   QQ 图片有防盗链 —— 带本站 Referer 直连返回的是 3.7KB 占位图（真图 ~48KB）。
 */
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/qzone_sync.php';

$tid = preg_replace('/[^a-f0-9]/i', '', (string) ($_GET['tid'] ?? ''));
$idx = max(0, min(8, (int) ($_GET['i'] ?? 0)));
/* 宽度变体：首页卡片传 w=420，详情/原图不传。0 表示用全尺寸。 */
$wantW = (int) ($_GET['w'] ?? 0);
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

/* 安全：只允许 QQ 图片域名，且只允许我们库里存过的链接（不接受任意 URL 入参）。
   否则本站就成了任意 URL 抓取器（SSRF：内网、云元数据地址都会被探测）。 */
$host = strtolower((string) parse_url($url, PHP_URL_HOST));
$hostOk = false;
foreach (['photo.store.qq.com', 'qlogo.cn', 'qpic.cn', 'gtimg.cn'] as $suffix) {
    if ($host === $suffix || substr($host, -strlen('.' . $suffix)) === '.' . $suffix) { $hostOk = true; break; }
}
if (!$hostOk || strpos($url, 'https://') !== 0) {
    http_response_code(403);
    exit;
}

/* 命中缓存直接出；没命中则现场抓取+压缩（同步时已尽量预热，这里只是兜底） */
$file = $wantW > 0 ? lwQzoneCacheImageVariant($url, $wantW) : lwQzoneCacheImage($url);
if ($file !== '' && is_file($file) && filesize($file) > 1024) {
    /* 缓存里可能存的是原图（原图更小时会保留原样），
       所以类型必须按文件头判断，不能一律当 JPEG 输出。 */
    $head = (string) @file_get_contents($file, false, null, 0, 12);
    $type = 'image/jpeg';
    if (strncmp($head, "\x89PNG", 4) === 0) { $type = 'image/png'; }
    elseif (strncmp($head, "GIF8", 4) === 0) { $type = 'image/gif'; }
    elseif (strncmp($head, 'RIFF', 4) === 0 && substr($head, 8, 4) === 'WEBP') { $type = 'image/webp'; }
    header('Content-Type: ' . $type);
    header('Content-Length: ' . filesize($file));
    /* 图片地址带 QQ 签名、内容基本不变；缓存一周，别每次都回来问 */
    header('Cache-Control: public, max-age=604800, immutable');
    header('X-Content-Type-Options: nosniff');
    readfile($file);
    exit;
}

http_response_code(502);
header('Content-Type: text/plain; charset=utf-8');
echo 'image unavailable';
