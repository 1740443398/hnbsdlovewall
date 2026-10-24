<?php
// 公开备份下载：校验申请者邮箱验证码后，输出「我的数据备份」ZIP（全站代码 + 脱敏后的本人数据）
// 由前端隐藏表单（携带 csrf_token）POST 提交，成功时以附件形式返回，失败时重定向回申请页。
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../includes/backup_export.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    die('请求方法不允许，请使用POST请求');
}

$csrfToken = $_POST['csrf_token'] ?? '';
if (!verifyCSRFToken($csrfToken)) {
    http_response_code(403);
    die('CSRF验证失败，请刷新页面后重试');
}

$backTo = SITE_URL . '/pages/download_backup.php';

// 必须为已注册登录用户
$user = requireLogin();
$user = checkBanned($user);
if (!empty($user['is_banned'])) {
    header('Location: ' . $backTo . '?err=' . rawurlencode('账号已被封禁，无法下载'));
    exit();
}

$fs = getFS();

// 取该用户最近一条「待验证」且未过期的申请
$code = trim($_POST['code'] ?? '');
if ($code === '') {
    header('Location: ' . $backTo . '?err=' . rawurlencode('请输入邮箱收到的验证码'));
    exit();
}

$pending = $fs->find('backup_applications', ['user_id' => $user['id'], 'status' => 'pending']);
$app = null;
foreach (array_reverse($pending) as $rec) {
    if (($rec['expires_at'] ?? 0) >= time()) {
        $app = $rec;
        break;
    }
}

if (!$app) {
    header('Location: ' . $backTo . '?err=' . rawurlencode('没有有效的下载申请，请先申请并获取验证码'));
    exit();
}

if (!hash_equals((string)$app['code'], $code)) {
    header('Location: ' . $backTo . '?err=' . rawurlencode('验证码不正确'));
    exit();
}

// 验证通过：标记为已使用并累计下载次数
$downloadCount = (int)($app['download_count'] ?? 0) + 1;
$fs->update('backup_applications', $app['id'], [
    'status' => 'approved',
    'approved_at' => date('Y-m-d H:i:s'),
    'download_count' => $downloadCount,
]);

if (!class_exists('ZipArchive')) {
    http_response_code(500);
    die('服务器未启用 ZIP 扩展，无法生成备份');
}

// 生成 ZIP 可能涉及全站文件，放宽执行时间限制
@set_time_limit(300);

if (function_exists('logUserActivity')) {
    logUserActivity($user['id'], 'backup_download', '已完成数据验证并下载我的数据备份 ZIP（第 ' . $downloadCount . ' 次）');
}

// 关闭 config.php 可能开启的 ob_gzhandler 等输出缓冲，确保下面能干净地发出 302
while (ob_get_level() > 0) {
    ob_end_clean();
}

// 「生成」与「传输」拆成两个请求：
// 本请求只把 ZIP 落到临时目录，然后 302 跳到 /api/backup_file.php 去读文件。
// 之前是在同一个响应里边生成边 readfile 推送大二进制，共享主机的代理/输出缓冲很容易在传输中途
// 掐断连接，浏览器因为收不满 Content-Length 就卡在某个百分比（例如 75%）不动。
// 拆开后：生成请求只返回一个几十字节的跳转，传输请求是纯文件读取，且支持 Range 断点续传，
// 客户端在连接被掐断后能自动从断点接上，最终拿到完整文件。
$zipDir = BackupExporter::tempDir();
if ($zipDir === '') {
    http_response_code(500);
    die('无法创建临时文件，请稍后重试');
}
BackupExporter::purgeExpired($zipDir);

$token = bin2hex(random_bytes(16));
$tmpZip = $zipDir . '/bk_' . $token . '.zip';
$metaPath = $zipDir . '/bk_' . $token . '.json';

if (!BackupExporter::createZip($tmpZip, $fs, $user)) {
    @unlink($tmpZip);
    http_response_code(500);
    die('备份生成失败，请稍后重试或联系管理员');
}

// meta 里记录归属用户与过期时间：下载端点据此校验「只能下载自己生成的那份」
$metaJson = json_encode([
    'user_id' => (int)$user['id'],
    'filename' => 'backup_' . date('Ymd_His') . '.zip',
    'expires_at' => time() + BackupExporter::TEMP_TTL,
], JSON_UNESCAPED_UNICODE);
if ($metaJson === false || @file_put_contents($metaPath, $metaJson, LOCK_EX) === false) {
    @unlink($tmpZip);
    http_response_code(500);
    die('无法创建临时文件，请稍后重试');
}

// 注意：这里不能 unlink 生成的 ZIP —— 它还要靠下一次请求送出，由 TTL 到期后统一清理
header('Location: ' . SITE_URL . '/api/backup_file.php?t=' . $token, true, 302);
exit;
