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
if (!checkRateLimit($ip, 'login')) {
    jsonError('请求过于频繁，请稍后再试', 429);
}

// 算术验证码校验，防分布式暴力破解密码
if (!verifyCaptcha('login', $_POST['captcha'] ?? '')) {
    jsonError('验证码错误或已过期，请刷新后重试');
}

$qq = sanitizeInput($_POST['qq'] ?? '');
$password = $_POST['password'] ?? '';
$remember = isset($_POST['remember']);

if (!isValidQQ($qq)) {
    jsonError('QQ号格式不正确');
}

$fs = getFS();
$user = $fs->findOne('users', ['qq' => $qq]);

if (!$user) {
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
    logUserActivity($user['id'], 'login_failed', '密码错误，第' . $failedAttempts . '次');
    jsonError('账号或密码错误');
}

if (($user['login_failed_count'] ?? 0) > 0) {
    $fs->update('users', $user['id'], ['login_failed_count' => 0, 'login_lockout_until' => null]);
}

$user = checkBanned($user);
if ($user['is_banned']) {
    $banUntil = $user['ban_until'] ?? '';
    $message = '账号已被封禁';
    if ($banUntil) {
        $message .= '，封禁截止时间：' . $banUntil;
    }
    jsonError($message);
}

if (!empty($user['twofa_enabled'])) {
    if (!empty($user['twofa_lockout_until']) && strtotime($user['twofa_lockout_until']) > time()) {
        jsonError('账号已被锁定，请稍后再试', 429);
    }
    $_SESSION['login_temp_user_id'] = $user['id'];
    $_SESSION['login_temp_qq'] = $user['qq'];
    $tempToken = bin2hex(random_bytes(32));
    $_SESSION['login_temp_token'] = $tempToken;
    jsonSuccess(['status' => '2fa_required', 'temp_token' => $tempToken]);
}

$_SESSION['user_id'] = $user['id'];
$_SESSION['security_stamp'] = $user['security_stamp'];
$_SESSION['user_role'] = $user['role'];
session_regenerate_id(true);

logUserActivity($user['id'], 'login', '登录成功');

markDeviceKnown();

if ($remember) {
    $token = generateRandomString(64);
    $fs->insert('remember_tokens', [
        'user_id' => $user['id'],
        'token_hash' => hash('sha256', $token),
        'expires_at' => date('Y-m-d H:i:s', time() + COOKIE_REMEMBER_DAYS * 24 * 3600)
    ]);
    $secure = defined('IS_SECURE') ? IS_SECURE : false;
    setcookie('remember_token', $token, time() + COOKIE_REMEMBER_DAYS * 24 * 3600, '/', '', $secure, true);
}

jsonSuccess(['user' => [
    'id' => $user['id'],
    'qq' => $user['qq'],
    'nickname' => $user['nickname'],
    'avatar' => $user['avatar'],
    'role' => $user['role'],
    'must_change_password' => !empty($user['must_change_password']) ? 1 : 0
]]);
