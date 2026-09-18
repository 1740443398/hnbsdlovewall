<?php
require_once __DIR__ . '/../../config/config.php';

$user = requireSuperAdmin();
$user = checkBanned($user);
if ($user['is_banned']) {
    jsonError('账号已被封禁');
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonError('请求方法不允许，请使用POST请求', 405);
}
$csrfToken = $_POST['csrf_token'] ?? '';
if (!verifyCSRFToken($csrfToken)) {
    jsonError('CSRF验证失败', 403);
}

// 完整备份导入：覆盖式写入指定数据表（高风险，仅限超级管理员）
$payload = $_POST['data'] ?? '';
if ($payload === '') {
    jsonError('请提供要导入的数据');
}
$importData = json_decode($payload, true);
if (!is_array($importData)) {
    jsonError('导入数据格式不正确，请使用由“完整备份”导出的 JSON 文件内容');
}

$fs = getFS();

// 允许导入的数据表（白名单，防止写入任意文件）
$allowedTables = [
    'users', 'posts', 'comments', 'post_likes', 'post_favorites',
    'notifications', 'settings', 'sensitive_words', 'sponsor',
    'operation_logs', 'user_activity_logs', 'ip_blacklist',
    'admin_permissions', 'pm_messages', 'pm_read', 'pm_reports',
    'feature_requests', 'checkins', 'checkin_records', 'remember_tokens',
];

$errors = [];
$done = 0;

foreach ($importData as $table => $rows) {
    if ($table === '_meta') continue;
    if (!in_array($table, $allowedTables) || !is_array($rows)) {
        $errors[] = $table;
        continue;
    }
    if ($fs->write($table, $rows)) {
        $done++;
    } else {
        $errors[] = $table;
    }
}

logOperation($user['id'], $user['qq'], 'import_data', 'full_backup', '', '导入网站数据，成功表数 ' . $done . (empty($errors) ? '' : '，失败: ' . implode(',', $errors)));

jsonSuccess([
    'imported_tables' => $done,
    'failed_tables' => $errors,
], $done > 0 ? '数据导入完成' : '数据导入失败，未写入任何表');