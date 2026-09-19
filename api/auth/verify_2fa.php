<?php
require_once __DIR__ . '/../../config/config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonError('请求方法不允许', 405);
}

$csrfToken = $_POST['csrf_token'] ?? '';
if (!verifyCSRFToken($csrfToken)) {
    jsonError('CSRF验证失败', 403);
}

if (!isset($_SESSION['login_temp_user_id'])) {
    jsonError('请先完成账号密码验证', 401);
}

$tempToken = $_POST['temp_token'] ?? '';
if (!empty($_SESSION['login_temp_token']) && !hash_equals($_SESSION['login_temp_token'], $tempToken)) {
    jsonError('验证会话已过期，请重新登录', 401);
}

$userId = $_SESSION['login_temp_user_id'];
$code = trim($_POST['code'] ?? '');
$isRecovery = !empty($_POST['is_recovery']);
$recoveryCode = $isRecovery ? $code : '';

$fs = getFS();
$user = $fs->findById('users', $userId);

if (!$user || empty($user['twofa_enabled'])) {
    jsonError('验证失败', 400);
}

$user = checkBanned($user);
if ($user['is_banned']) {
    $banUntil = $user['ban_until'] ?? '';
    $message = '账号已被封禁';
    if ($banUntil) {
        $message .= '，封禁截止时间：' . $banUntil;
    }
    unset($_SESSION['login_temp_user_id']);
    unset($_SESSION['login_temp_qq']);
    unset($_SESSION['login_temp_token']);
    jsonError($message);
}

if (!empty($user['twofa_lockout_until']) && strtotime($user['twofa_lockout_until']) > time()) {
    jsonError('账号已被锁定，请稍后再试', 429);
}

if ($recoveryCode) {
    $recoveryCodes = json_decode($user['twofa_recovery_codes'] ?? '[]', true);
    $recoveryCodeHash = hash('sha256', $recoveryCode);
    $found = false;
    foreach ($recoveryCodes as $storedCode) {
        if (hash_equals(hash('sha256', $storedCode), $recoveryCodeHash)) {
            $found = true;
            $recoveryCodeInput = $storedCode;
            break;
        }
    }
    if (!$found) {
        $failedAttempts = ($user['twofa_failed_attempts'] ?? 0) + 1;
        $lockoutUntil = null;
        if ($failedAttempts >= TWOFA_LOCKOUT_ATTEMPTS) {
            $lockoutUntil = date('Y-m-d H:i:s', time() + (TWOFA_LOCKOUT_MINUTES * 60));
        }
        $fs->update('users', $userId, ['twofa_failed_attempts' => $failedAttempts, 'twofa_lockout_until' => $lockoutUntil]);
        logUserActivity($userId, '2fa_recovery_failed', '恢复码验证失败，第' . $failedAttempts . '次');
        if ($failedAttempts >= TWOFA_LOCKOUT_ATTEMPTS) {
            jsonError('恢复码验证失败次数过多，已锁定' . TWOFA_LOCKOUT_MINUTES . '分钟', 429);
        }
        jsonError('恢复码无效，剩余尝试次数：' . (TWOFA_LOCKOUT_ATTEMPTS - $failedAttempts));
    }
    $newRecoveryCodes = array_filter($recoveryCodes, function($c) use ($recoveryCodeInput) {
        return $c !== $recoveryCodeInput;
    });
    $fs->update('users', $userId, [
        'twofa_recovery_codes' => json_encode(array_values($newRecoveryCodes)),
        'twofa_failed_attempts' => 0,
        'twofa_lockout_until' => null
    ]);
    $_SESSION['user_id'] = $userId;
    $_SESSION['security_stamp'] = $user['security_stamp'];
    $_SESSION['user_role'] = $user['role'];
    session_regenerate_id(true);
    unset($_SESSION['login_temp_user_id']);
    unset($_SESSION['login_temp_qq']);
    unset($_SESSION['login_temp_token']);
    logUserActivity($userId, 'login', '通过恢复码登录成功');
    jsonSuccess(['user' => [
        'id' => $user['id'],
        'qq' => $user['qq'],
        'nickname' => $user['nickname'],
        'avatar' => $user['avatar'],
        'role' => $user['role']
    ]]);
}

if (!preg_match('/^\d{6}$/', $code)) {
    jsonError('验证码格式不正确');
}

require_once __DIR__ . '/../../includes/totp.php';
$totp = new TOTP($user['twofa_secret']);

if (!$totp->verify($code)) {
    $failedAttempts = ($user['twofa_failed_attempts'] ?? 0) + 1;
    $lockoutUntil = null;
    if ($failedAttempts >= TWOFA_LOCKOUT_ATTEMPTS) {
        $lockoutUntil = date('Y-m-d H:i:s', time() + (TWOFA_LOCKOUT_MINUTES * 60));
    }
    $fs->update('users', $userId, ['twofa_failed_attempts' => $failedAttempts, 'twofa_lockout_until' => $lockoutUntil]);
    logUserActivity($userId, '2fa_verify_failed', 'TOTP验证失败，第' . $failedAttempts . '次');
    if ($failedAttempts >= TWOFA_LOCKOUT_ATTEMPTS) {
        jsonError('验证码错误次数过多，已锁定' . TWOFA_LOCKOUT_MINUTES . '分钟', 429);
    }
    jsonError('验证码错误，剩余尝试次数：' . (TWOFA_LOCKOUT_ATTEMPTS - $failedAttempts));
}

$fs->update('users', $userId, [
    'twofa_failed_attempts' => 0,
    'twofa_lockout_until' => null
]);

$_SESSION['user_id'] = $userId;
$_SESSION['security_stamp'] = $user['security_stamp'];
$_SESSION['user_role'] = $user['role'];
session_regenerate_id(true);
unset($_SESSION['login_temp_user_id']);
unset($_SESSION['login_temp_qq']);
unset($_SESSION['login_temp_token']);

logUserActivity($userId, 'login', '通过2FA验证登录成功');

jsonSuccess(['user' => [
    'id' => $user['id'],
    'qq' => $user['qq'],
    'nickname' => $user['nickname'],
    'avatar' => $user['avatar'],
    'role' => $user['role']
]]);
