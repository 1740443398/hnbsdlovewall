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

if (!checkPermission($admin, 'ban_user')) {
    jsonError('无权限执行此操作', 403);
}

$userId = intval($_POST['user_id'] ?? 0);
$days = intval($_POST['days'] ?? 0);
$reason = sanitizeInput($_POST['reason'] ?? '');

if (!$userId) {
    jsonError('用户ID无效');
}

if ($days < 0) {
    $days = 0;
}
if ($days > 365) {
    $days = 365;
}

$fs = getFS();
$user = $fs->findById('users', $userId);

if (!$user) {
    jsonError('用户不存在');
}

if ($user['role'] === 'super_admin') {
    jsonError('无法封禁超级管理员', 403);
}

$banUntil = $days > 0 ? date('Y-m-d H:i:s', time() + ($days * 24 * 3600)) : null;

$fs->update('users', $userId, [
    'is_banned' => 1,
    'ban_reason' => $reason,
    'ban_until' => $banUntil
]);

logOperation($admin['id'], $admin['qq'], 'ban_user', 'user', $userId, '封禁用户');

jsonSuccess([], '封禁成功');