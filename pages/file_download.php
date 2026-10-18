<?php
/**
 * 附件下载（带鉴权，不走目录直读）
 *
 * 为什么要这一层：uploads/files/ 若允许目录直读，等于把所有人的附件
 * 暴露成可枚举的公开 URL（文件名随机但能被分享/爬取）。走这里可以：
 *   1. 校验登录态（游客不可下载，避免被当成免费文件中转站）；
 *   2. 统计下载次数；
 *   3. 用 Content-Disposition 强制附件下载，绝不让浏览器内联渲染;
 *   4. 文件名来自数据库，不信任 URL 参数。
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../includes/attachments.php';

$user = requireLogin();
$user = checkBanned($user);
if (!empty($user['is_banned'])) {
    http_response_code(403);
    die('账号已被封禁');
}

$id = (int)($_GET['id'] ?? 0);

// 先清扫，再查表：这样「刚刚过期的文件」不会因为清扫没触发而被下载到
lwAttachPurgeExpired();

$row = $id > 0 ? getFS()->findOne('attachments', ['id' => $id]) : null;
if (!$row) {
    http_response_code(404);
    die('文件已过期或不存在（服务器只保留 1 小时，请让上传者重新发送）');
}

// 双保险：即使清扫还没跑到，也绝不允许越过 1 小时仍然可下载
if (lwAttachSecondsLeft($row) <= 0) {
    http_response_code(410);
    die('文件已过期（服务器只保留 1 小时），请让上传者重新发送');
}

$stored = (string)($row['stored'] ?? '');
$abs = lwAttachDir() . '/' . basename($stored);
if ($stored === '' || !is_file($abs)) {
    http_response_code(404);
    die('文件已不存在');
}

// 下载计数（失败不影响下载）
try {
    getFS()->update('attachments', $id, ['downloads' => (int)($row['downloads'] ?? 0) + 1]);
} catch (Exception $e) {
    // ignore
}

$orig = (string)($row['orig_name'] ?? ('file.' . ($row['ext'] ?? 'bin')));
// 去掉控制字符与引号，避免头注入
$orig = preg_replace('/[\x00-\x1F\x7F"\r\n]/', '', $orig) ?? 'download';
if ($orig === '') {
    $orig = 'download.' . ($row['ext'] ?? 'bin');
}

$mimeMap = [
    'pdf' => 'application/pdf',
    'zip' => 'application/zip',
    'rar' => 'application/vnd.rar',
    '7z'  => 'application/x-7z-compressed',
    'mp3' => 'audio/mpeg',
    'mp4' => 'video/mp4',
    'txt' => 'text/plain; charset=utf-8',
    'csv' => 'text/csv; charset=utf-8',
];
$ext = strtolower((string)($row['ext'] ?? ''));
$mime = $mimeMap[$ext] ?? 'application/octet-stream';

header('Content-Type: ' . $mime);
header('Content-Length: ' . filesize($abs));
header('Content-Disposition: attachment; filename="' . $orig . '"; filename*=UTF-8\'\'' . rawurlencode($orig));
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, max-age=0, no-store');
header('Pragma: no-cache');

readfile($abs);
exit();
