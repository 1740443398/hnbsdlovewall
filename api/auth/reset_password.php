<?php
require_once __DIR__ . '/../../config/config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonError('请求方法不允许', 405);
}

$csrfToken = $_POST['csrf_token'] ?? '';
if (!verifyCSRFToken($csrfToken)) {
    jsonError('CSRF验证失败', 403);
}

$ip = getClientIP();
if (!checkRateLimit($ip, 'reset_password', 5, 300)) {
    jsonError('请求过于频繁，请稍后再试', 429);
}

$token = sanitizeInput($_POST['token'] ?? '');
$password = $_POST['password'] ?? '';
$confirmPassword = $_POST['confirm_password'] ?? '';

$pwdCheck = validatePasswordStrength($password);
if ($pwdCheck !== true) {
    jsonError($pwdCheck);
}

if ($password !== $confirmPassword) {
    jsonError('两次输入的密码不一致');
}

$fs = getFS();
$resetToken = $fs->findOne('password_reset_tokens', ['token' => $token]);

if (!$resetToken) {
    jsonError('重置链接无效');
}

if (strtotime($resetToken['expires_at']) < time()) {
    $fs->delete('password_reset_tokens', $resetToken['id']);
    jsonError('重置链接已过期');
}

$user = $fs->findById('users', $resetToken['user_id']);
if (!$user) {
    jsonError('用户不存在');
}

$user = checkBanned($user);

$hashedPassword = hashPassword($password);
$newStamp = generateSecurityStamp();

$fs->update('users', $user['id'], [
    'password_hash' => $hashedPassword,
    'security_stamp' => $newStamp
]);

$fs->delete('password_reset_tokens', $resetToken['id']);

logUserActivity($user['id'], 'password_reset', '密码重置成功');

jsonSuccess([], '密码重置成功，请使用新密码登录');