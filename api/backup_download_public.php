<?php
// 公开备份下载：校验申请者邮箱验证码后，输出整站数据 JSON 附件
// 由前端隐藏表单（携带 csrf_token）POST 提交，成功时以附件形式返回，失败时重定向回申请页。
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/security.php';

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

// 组装全站完整备份
$dataDir = __DIR__ . '/../data';
$backup = [];
if (is_dir($dataDir)) {
    foreach (glob($dataDir . '/*.json') ?: [] as $path) {
        $name = basename($path, '.json');
        $backup[$name] = $fs->getAll($name);
    }
}
$backup['_meta'] = [
    'app' => 'love_wall',
    'exported_at' => date('Y-m-d H:i:s'),
    'version' => 1,
    'generator' => 'public_backup_download',
    'exported_by' => 'user_' . $user['id'],
];

$json = json_encode($backup, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
if ($json === false) {
    http_response_code(500);
    die('数据序列化失败：' . json_last_error_msg());
}

if (function_exists('logUserActivity')) {
    logUserActivity($user['id'], 'backup_download', '已完成数据验证并下载完整备份（第 ' . $downloadCount . ' 次）');
}

$filename = 'backup_' . date('Ymd_His') . '.json';
header('Content-Type: application/json; charset=UTF-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Content-Length: ' . strlen($json));
header('Cache-Control: no-store, no-cache, must-revalidate');
echo $json;
exit;
