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
    jsonError('账号已被封禁，无法关闭2FA');
}

$password = $_POST['password'] ?? '';
$code = trim($_POST['code'] ?? '');

if (!verifyPassword($password, $user['password_hash'])) {
    jsonError('密码验证失败');
}

if (empty($user['twofa_enabled'])) {
    jsonError('2FA未启用');
}

require_once __DIR__ . '/../../includes/totp.php';
$totp = new TOTP($user['twofa_secret']);

if (!$totp->verify($code)) {
    jsonError('验证码验证失败');
}

$newStamp = generateSecurityStamp();

$fs = getFS();
$fs->update('users', $user['id'], [
    'twofa_enabled' => 0,
    'twofa_secret' => '',
    'twofa_recovery_codes' => '',
    'security_stamp' => $newStamp
]);

logUserActivity($user['id'], 'twofa_disable', '关闭2FA');

forceLogout($user['id']);

jsonSuccess([], '2FA已关闭');