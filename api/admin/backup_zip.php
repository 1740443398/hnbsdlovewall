<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/backup_pack.php';

/**
 * 「导出完整备份」——输出 ZIP 包（数据表 + uploads 图片，一个包就是完整留档）。
 *
 * 与 api/admin/backup_download.php 的区别（两者都产出同一个完整包，但门槛不同）：
 *   本端点：持 export_data 权限即可，免密码 —— 面向"日常留档"，可以高频跑。
 *   backup_download.php：仅超级管理员 + 再次输入密码 + 可选 2FA —— 面向"带敏感数据外带"。
 */

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    die('请求方法不允许，请使用POST请求');
}

if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
    http_response_code(403);
    die('CSRF验证失败');
}

$admin = requireAdmin();
$admin = checkBanned($admin);
if (!empty($admin['is_banned'])) {
    http_response_code(403);
    die('账号已被封禁');
}
if (!checkPermission($admin, 'export_data')) {
    http_response_code(403);
    die('无导出权限');
}

if (!class_exists('ZipArchive')) {
    http_response_code(500);
    die('服务器未启用 ZipArchive 扩展，无法打包备份');
}

@set_time_limit(0);

$fs = getFS();
$tables = BackupPack::collectTables($fs, 'admin_backup_zip');

// 先写临时文件再流式吐出：ZipArchive 只能写文件，不能直接生成到输出流
$tmpZip = __DIR__ . '/../../data/bk_data_' . date('Ymd_His') . '.zip';
if (!BackupPack::writeFullZip($tmpZip, $tables)) {
    @unlink($tmpZip);
    http_response_code(500);
    die('备份打包失败，请检查 data/ 目录是否可写及磁盘空间');
}

$uploadCount = (int)($tables['_meta']['uploads']['total_files'] ?? 0);
logOperation($admin['id'], $admin['qq'], 'export_backup_zip', 'full_backup', '', '导出完整备份 ZIP（' . count($tables) . ' 张表 + ' . $uploadCount . ' 个图片文件）');

// 与其它下载端点同样的坑：必须关掉 zlib 与输出缓冲，
// 否则 Content-Length 报的是未压缩长度，客户端会一直等不满字节数。
$zlibConfigured = (bool)ini_get('zlib.output_compression');
@ini_set('zlib.output_compression', '0');
while (ob_get_level() > 0) {
    ob_end_clean();
}

$filename = 'backup_' . date('Ymd_His') . '.zip';
header('Content-Type: application/zip');
header('Content-Disposition: attachment; filename="' . $filename . '"');
if (!$zlibConfigured && ob_get_level() === 0) {
    header('Content-Length: ' . (string)@filesize($tmpZip));
}
header('Cache-Control: no-store, no-cache, must-revalidate');
header('X-Accel-Buffering: no');

$fp = @fopen($tmpZip, 'rb');
if ($fp === false) {
    @unlink($tmpZip);
    http_response_code(500);
    die('读取备份文件失败');
}
while (!feof($fp)) {
    echo fread($fp, 256 * 1024);
    @flush();
    if (connection_aborted()) {
        break;
    }
}
fclose($fp);
@unlink($tmpZip);
exit;