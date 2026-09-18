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
if (!checkRateLimit($ip, 'admin_login', 10, 300)) {
    jsonError('请求过于频繁，请稍后再试', 429);
}

$action = $_POST['action'] ?? '';

if ($action === 'verify_2fa') {
    if (!isset($_SESSION['admin_login_temp_user_id'])) {
        jsonError('请先完成账号密码验证', 401);
    }

    $tempToken = $_POST['temp_token'] ?? '';
    if (!empty($_SESSION['admin_login_temp_token']) && !hash_equals($_SESSION['admin_login_temp_token'], $tempToken)) {
        jsonError('验证会话已过期，请重新登录', 401);
    }

    $userId = $_SESSION['admin_login_temp_user_id'];
    $code = trim($_POST['code'] ?? '');

    if (!preg_match('/^\d{6}$/', $code)) {
        jsonError('验证码格式不正确');
    }

    $fs = getFS();
    $user = $fs->findById('users', $userId);

    if (!$user || empty($user['twofa_enabled'])) {
        jsonError('验证失败', 400);
    }

    require_once __DIR__ . '/../../includes/totp.php';
    $totp = new TOTP($user['twofa_secret']);

    if (!$totp->verify($code)) {
        jsonError('验证码错误');
    }

    $_SESSION['user_id'] = $userId;
    $_SESSION['security_stamp'] = $user['security_stamp'];
    $_SESSION['user_role'] = $user['role'];
    session_regenerate_id(true);
    unset($_SESSION['admin_login_temp_user_id'], $_SESSION['admin_login_temp_token']);

    logUserActivity($userId, 'admin_login', '后台登录成功（2FA验证）');

    jsonSuccess(['user' => [
        'id' => $user['id'],
        'qq' => $user['qq'],
        'nickname' => $user['nickname'],
        'role' => $user['role']
    ]]);
}

$qq = sanitizeInput($_POST['qq'] ?? '');
$password = $_POST['password'] ?? '';

if (!isValidQQ($qq)) {
    jsonError('QQ号格式不正确');
}

$fs = getFS();
$user = $fs->findOne('users', ['qq' => $qq]);

if (!$user || !in_array($user['role'], ['admin', 'super_admin'])) {
    jsonError('账号或密码错误');
}

if (!empty($user['login_lockout_until']) && strtotime($user['login_lockout_until']) > time()) {
    jsonError('账号已被临时锁定，请15分钟后再试', 429);
}

if (!verifyPassword($password, $user['password_hash'])) {
    $failedAttempts = ($user['login_failed_count'] ?? 0) + 1;
    $lockoutUntil = null;
    if ($failedAttempts >= 10) {
        $lockoutUntil = date('Y-m-d H:i:s', time() + 900);
    }
    $fs->update('users', $user['id'], [
        'login_failed_count' => $failedAttempts,
        'login_lockout_until' => $lockoutUntil
    ]);
    logUserActivity($user['id'], 'admin_login_failed', '后台登录密码错误，第' . $failedAttempts . '次');
    jsonError('账号或密码错误');
}

if (($user['login_failed_count'] ?? 0) > 0) {
    $fs->update('users', $user['id'], ['login_failed_count' => 0, 'login_lockout_until' => null]);
}

if (!empty($user['twofa_enabled'])) {
    $_SESSION['admin_login_temp_user_id'] = $user['id'];
    $tempToken = bin2hex(random_bytes(32));
    $_SESSION['admin_login_temp_token'] = $tempToken;
    jsonSuccess(['status' => '2fa_required', 'temp_token' => $tempToken]);
}

$_SESSION['user_id'] = $user['id'];
$_SESSION['security_stamp'] = $user['security_stamp'];
$_SESSION['user_role'] = $user['role'];
session_regenerate_id(true);

logUserActivity($user['id'], 'admin_login', '后台登录成功');

jsonSuccess(['user' => [
    'id' => $user['id'],
    'qq' => $user['qq'],
    'nickname' => $user['nickname'],
    'role' => $user['role']
]]);