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
    jsonError('账号已被封禁，无法重置2FA');
}

$password = $_POST['password'] ?? '';

if (!verifyPassword($password, $user['password_hash'])) {
    jsonError('密码验证失败');
}

require_once __DIR__ . '/../../includes/totp.php';
$secret = TOTP::generateSecret();

$recoveryCodes = TOTP::generateRecoveryCodes();
$newStamp = generateSecurityStamp();

$fs = getFS();
$fs->update('users', $user['id'], [
    'twofa_secret' => $secret,
    'twofa_recovery_codes' => json_encode($recoveryCodes),
    'security_stamp' => $newStamp
]);

logUserActivity($user['id'], 'twofa_reset', '重置2FA密钥');

forceLogout($user['id']);

jsonSuccess([
    'secret' => $secret,
    'recovery_codes' => $recoveryCodes
], '2FA已重置，请使用新密钥重新绑定');