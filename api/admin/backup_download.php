<?php
require_once __DIR__ . '/../../config/config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    die('请求方法不允许，请使用POST请求');
}

// POST 必须校验 CSRF
$csrfToken = $_POST['csrf_token'] ?? '';
if (!verifyCSRFToken($csrfToken)) {
    http_response_code(403);
    die('CSRF验证失败');
}

// 全量备份含私信/用户等敏感数据，仅超级管理员可下载
$admin = requireSuperAdmin();
$admin = checkBanned($admin);
if (!empty($admin['is_banned'])) {
    http_response_code(403);
    die('账号已被封禁，无法下载备份');
}

// 二次校验：再输一次当前登录密码
$pwd = trim($_POST['password'] ?? '');
if ($pwd === '' || !verifyPassword($pwd, $admin['password_hash'])) {
    header('Location: /admin/backup.php?bkerr=' . rawurlencode('当前登录密码不正确，无法下载备份'));
    exit();
}

// 若账号已启用2FA，还需输入本次TOTP验证码
if (!empty($admin['twofa_secret'])) {
    require_once __DIR__ . '/../../includes/totp.php';
    $code = trim($_POST['twofa'] ?? '');
    if ($code === '') {
        header('Location: /admin/backup.php?bkerr=' . rawurlencode('该账号已启用双重验证，请输入Authenticator中的6位验证码'));
        exit();
    }
    $totp = new TOTP($admin['twofa_secret']);
    if (!$totp->verify($code)) {
        header('Location: /admin/backup.php?bkerr=' . rawurlencode('双重验证码不正确，请重试'));
        exit();
    }
}

// 扫描 data 目录实际存在的所有 .json 表，全表导出；图片实体一并进包（同一套 BackupPack）
require_once __DIR__ . '/../../includes/backup_pack.php';
$backup = BackupPack::collectTables(getFS(), 'admin_backup_download');

// 记录操作日志
if (function_exists('logUserActivity')) {
    logUserActivity($admin['id'], 'backup_download', '超级管理员下载完整数据备份');
}

// 默认输出 ZIP 包（与「导出完整备份」同一套结构，便于统一还原）；
// 传 format=json 时保留旧的单文件 JSON 行为，兼容既有的脚本/习惯。
$wantJson = (strtolower(trim((string)($_POST['format'] ?? 'zip'))) === 'json');

// 与公开备份 ZIP 同样的注意点：必须关掉 zlib / 输出缓冲。
// 否则响应体会被 gzip 二次包装，而 Content-Length 报的是压缩前的长度，
// 客户端收不满声明的字节数，下载就会卡在中途不动。
$zlibConfigured = (bool)ini_get('zlib.output_compression');
@ini_set('zlib.output_compression', '0');
while (ob_get_level() > 0) {
    ob_end_clean();
}

if ($wantJson) {
    $json = json_encode($backup, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        http_response_code(500);
        die('数据序列化失败：' . json_last_error_msg());
    }
    $filename = 'backup_' . date('Ymd_His') . '.json';
    header('Content-Type: application/json; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    if (!$zlibConfigured && ob_get_level() === 0) {
        header('Content-Length: ' . strlen($json));
    }
    header('Cache-Control: no-store, no-cache, must-revalidate');
    header('X-Accel-Buffering: no');

    $chunkSize = 256 * 1024;
    $total = strlen($json);
    for ($offset = 0; $offset < $total; $offset += $chunkSize) {
        echo substr($json, $offset, $chunkSize);
        @flush();
        if (connection_aborted()) {
            break;
        }
    }
    exit;
}

// ── ZIP 分支 ─────────────────────────────────────────────────────────────
require_once __DIR__ . '/../../includes/backup_pack.php';

if (!class_exists('ZipArchive')) {
    http_response_code(500);
    die('服务器未启用 ZipArchive 扩展，无法打包备份');
}

@set_time_limit(0);
$tmpZip = __DIR__ . '/../../data/bk_data_' . date('Ymd_His') . '.zip';
// 与「导出完整备份」同一套结构：数据表 + uploads 图片，一个包即可完整还原
if (!BackupPack::writeFullZip($tmpZip, $backup)) {
    @unlink($tmpZip);
    http_response_code(500);
    die('备份打包失败，请检查 data/ 目录是否可写及磁盘空间');
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