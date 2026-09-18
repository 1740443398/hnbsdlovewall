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

if (!checkPermission($admin, 'unban_user')) {
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

$fs->update('users', $userId, [
    'is_banned' => 0,
    'ban_reason' => '',
    'ban_until' => null
]);

logOperation($admin['id'], $admin['qq'], 'unban_user', 'user', $userId, '解除封禁');

jsonSuccess([], '解除封禁成功');