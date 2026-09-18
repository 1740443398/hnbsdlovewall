<?php
require_once __DIR__ . '/../../config/config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonError('请求方法不允许', 405);
}

$csrfToken = $_POST['csrf_token'] ?? '';
if (!verifyCSRFToken($csrfToken)) {
    jsonError('CSRF验证失败', 403);
}

$admin = requireAdmin();
$admin = checkBanned($admin);
if ($admin['is_banned']) {
    jsonError('账号已被封禁');
}

if (!checkPermission($admin, 'reset_user_2fa')) {
    jsonError('无权限执行此操作', 403);
}

$userId = intval($_POST['user_id'] ?? 0);

if (!$userId) {
    jsonError('用户ID无效');
}

$fs = getFS();
$user = $fs->findById('users', $userId);

if (!$user) {
    jsonError('用户不存在');
}

$newStamp = generateSecurityStamp();

$fs->update('users', $userId, [
    'twofa_enabled' => 0,
    'twofa_secret' => '',
    'twofa_recovery_codes' => '',
    'security_stamp' => $newStamp
]);

logOperation($admin['id'], $admin['qq'], 'reset_user_2fa', 'user', $userId, '重置用户2FA');

jsonSuccess([], '2FA已重置');