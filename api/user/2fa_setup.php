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
    jsonError('账号已被封禁，无法设置2FA');
}

$password = $_POST['password'] ?? '';
$code = trim($_POST['code'] ?? '');
$action = $_POST['action'] ?? '';

if (!verifyPassword($password, $user['password_hash'])) {
    jsonError('密码验证失败');
}

if (!empty($user['twofa_enabled'])) {
    jsonError('2FA已启用');
}

require_once __DIR__ . '/../../includes/totp.php';

if ($action === 'generate' || empty($code)) {
    $secret = TOTP::generateSecret();
    $_SESSION['twofa_temp_secret'] = $secret;
    jsonSuccess(['secret' => $secret]);
}

$secret = $_SESSION['twofa_temp_secret'] ?? '';
if (!$secret) {
    jsonError('请先生成密钥');
}

$totp = new TOTP($secret);

if (!$totp->verify($code)) {
    jsonError('验证码验证失败');
}

$recoveryCodes = TOTP::generateRecoveryCodes();
$newStamp = generateSecurityStamp();

$fs = getFS();
$fs->update('users', $user['id'], [
    'twofa_enabled' => 1,
    'twofa_secret' => $secret,
    'twofa_recovery_codes' => json_encode($recoveryCodes),
    'security_stamp' => $newStamp
]);

unset($_SESSION['twofa_temp_secret']);

logUserActivity($user['id'], 'twofa_enable', '开启2FA');

jsonSuccess([
    'recovery_codes' => $recoveryCodes
], '2FA开启成功，请保存恢复码');