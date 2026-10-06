<?php
/**
 * 备份 ZIP 传输端点。
 *
 * 为什么单独拆一个端点：备份流程是「校验邮箱验证码 → 生成 ZIP → 传输大文件」，
 * 如果三步挤在同一个 POST 响应里，共享主机的输出缓冲 / 反向代理很容易在传输中途掐断连接，
 * 浏览器因为收不满 Content-Length 就会卡在某个百分比（例如 75%）一直不动。
 * 拆开后这里只是一个普通的 GET 文件读取，并且支持 Range 断点续传：
 * 客户端（浏览器 / Android DownloadManager）在连接被掐断后能自动从断点接着下，最终拿到完整文件。
 *
 * 访问方式：/api/backup_file.php?t=<32位token>，token 由 backup_download_public.php 生成，
 * 且只允许生成它的那个登录用户下载。
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../includes/backup_export.php';

// 这里不用 requireLogin()：它会把未登录的下载请求重定向到登录页，
// 客户端（特别是下载管理器）会把那个 HTML 当成备份文件存下来，反而更难排查。
$user = getCurrentUser();
if (!$user) {
    http_response_code(401);
    header('Content-Type: text/plain; charset=utf-8');
    die('请先登录后再下载备份');
}
$user = checkBanned($user);
if (!empty($user['is_banned'])) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    die('账号已被封禁，无法下载');
}

$token = isset($_GET['t']) ? (string)$_GET['t'] : '';
if (!preg_match('/^[0-9a-f]{32}$/', $token)) {
    http_response_code(404);
    die('备份不存在或已过期');
}

$zipDir = BackupExporter::tempDir();
if ($zipDir === '') {
    http_response_code(500);
    die('服务器临时目录不可用，请稍后再试');
}
BackupExporter::purgeExpired($zipDir);

$zipPath = $zipDir . '/bk_' . $token . '.zip';
$metaPath = $zipDir . '/bk_' . $token . '.json';
if (!is_file($zipPath) || !is_file($metaPath)) {
    http_response_code(404);
    die('备份不存在或已过期，请重新申请下载');
}

$meta = json_decode((string)@file_get_contents($metaPath), true);
$size = (int)@filesize($zipPath);
if (!is_array($meta)
        || (int)($meta['user_id'] ?? 0) !== (int)$user['id']
        || (int)($meta['expires_at'] ?? 0) < time()
        || $size <= 0) {
    http_response_code(404);
    die('备份不存在或已过期，请重新申请下载');
}

$filename = basename((string)($meta['filename'] ?? 'backup.zip'));
if ($filename === '' || substr($filename, -4) !== '.zip') {
    $filename = 'backup.zip';
}

// ZIP 是二进制：必须关掉 zlib 与所有输出缓冲，否则响应体会被 gzip 二次包装，
// Content-Length 与实际字节不一致，客户端就会一直等剩下的字节（下载卡死的典型原因）。
$zlibConfigured = (bool)ini_get('zlib.output_compression');
@ini_set('zlib.output_compression', '0');
while (ob_get_level() > 0) {
    ob_end_clean();
}
// 少数主机在 php.ini 里强制开启 zlib 输出压缩，它的处理器无法在运行时移除。
// 这种情况下响应体的真实字节数不再等于 Content-Length，所以干脆不下发该头，
// 让客户端按实际收到的字节判断结束（Range 仍能靠 Content-Range 正常工作）。
$noCompression = (!$zlibConfigured && ob_get_level() === 0);

// 解析 Range（断点续传/多段下载）：只处理最常见的 bytes=start-end / bytes=start- / bytes=-suffix
$start = 0;
$end = $size - 1;
$isPartial = false;
$range = trim((string)($_SERVER['HTTP_RANGE'] ?? ''));
if ($range !== '' && preg_match('/^bytes=(\d*)-(\d*)$/i', $range, $m)) {
    if ($m[1] === '' && $m[2] === '') {
        // 语法不合法，忽略 Range，按整文件返回
    } elseif ($m[1] === '') {
        $suffixLen = (int)$m[2];
        $start = $suffixLen >= $size ? 0 : $size - $suffixLen;
        $isPartial = true;
    } else {
        $start = (int)$m[1];
        if ($m[2] !== '') {
            $end = min($end, (int)$m[2]);
        }
        $isPartial = true;
    }
    if ($isPartial && ($start > $end || $start >= $size)) {
        header('Content-Range: bytes */' . $size);
        http_response_code(416);
        exit;
    }
}

header('Content-Type: application/zip');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Accept-Ranges: bytes');
header('Cache-Control: no-store, no-cache, must-revalidate');
// 关掉 nginx / 反向代理的响应缓冲，让分片尽早送到客户端
header('X-Accel-Buffering: no');

if ($isPartial) {
    http_response_code(206);
    header('Content-Range: bytes ' . $start . '-' . $end . '/' . $size);
}
$length = $end - $start + 1;
if ($noCompression) {
    header('Content-Length: ' . $length);
}

$fp = @fopen($zipPath, 'rb');
if ($fp === false) {
    http_response_code(500);
    die('无法读取备份文件，请稍后重试');
}
if ($start > 0) {
    @fseek($fp, $start);
}

// 分块发送并及时 flush：既不让整个文件堆在内存/缓冲里，也方便客户端尽早开始接收
$chunkSize = 256 * 1024;
$remaining = $length;
while ($remaining > 0) {
    $read = @fread($fp, (int)min($chunkSize, $remaining));
    if ($read === false || $read === '') {
        break;
    }
    echo $read;
    $remaining -= strlen($read);
    @flush();
    if (connection_aborted()) {
        break;
    }
}
@fclose($fp);
// 文件不在这里删除：客户端可能紧接着发 Range 请求补齐剩余部分，
// 保留到 TEMP_TTL 到期后由 purgeExpired() 统一清理。
exit;
