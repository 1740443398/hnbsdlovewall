<?php
require_once __DIR__ . '/../../config/config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonError('请求方法不允许', 405);
}

$csrfToken = $_POST['csrf_token'] ?? '';
if (!verifyCSRFToken($csrfToken)) {
    jsonError('CSRF验证失败', 403);
}

$user = requireLogin();

$user = checkBanned($user);
if ($user['is_banned']) {
    jsonError('账号已被封禁，无法修改密码');
}

$oldPassword = $_POST['current_password'] ?? $_POST['old_password'] ?? '';
$newPassword = $_POST['new_password'] ?? '';
$confirmPassword = $_POST['confirm_password'] ?? '';

if (!verifyPassword($oldPassword, $user['password_hash'])) {
    jsonError('旧密码不正确');
}

$pwdCheck = validatePasswordStrength($newPassword);
if ($pwdCheck !== true) {
    jsonError($pwdCheck);
}

if ($newPassword !== $confirmPassword) {
    jsonError('两次输入的新密码不一致');
}

$hashedPassword = hashPassword($newPassword);

$fs = getFS();
$result = $fs->update('users', $user['id'], [
    'password_hash' => $hashedPassword,
    'must_change_password' => 0
]);

if (!$result) {
    jsonError('密码修改失败：数据写入错误，请联系管理员检查服务器文件权限');
}

logUserActivity($user['id'], 'password_change', '修改密码');

forceLogout($user['id']);

jsonSuccess([], '密码修改成功，请使用新密码重新登录');