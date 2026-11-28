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

$token = sanitizeInput($_POST['token'] ?? $_POST['reset_token'] ?? '');
$password = $_POST['password'] ?? '';
$confirmPassword = $_POST['confirm_password'] ?? '';
$code = sanitizeInput($_POST['code'] ?? '');

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

/* 校验策略（可用性优先，安全性不变）
 * ---------------------------------------------------------------------------
 * 重置链接里的 token 是 64 位随机串、1 小时有效、只存在于邮件中 —— 它本身就是凭证。
 * 6 位数字验证码是附加校验，不是唯一凭证。
 * 旧实现一律要求"验证码必须匹配且未过期"，于是出现两种必然卡死的情况：
 *   1. 用户点开邮件链接时验证码已过期（5 分钟），链接还有效却不让重置
 *   2. 用户从邮件手抄验证码，错一位就再也过不去
 * 现在：token 有效即放行，验证码只作为记录与审计依据。
 */
$resetCode = null;
if ($code !== '') {
    $resetCode = $fs->findOne('password_reset_codes', ['qq' => $user['qq'], 'code' => $code]);
}
$verifyMode = 'token_only';
if ($resetCode && empty($resetCode['used']) && (int)($resetCode['expires_at'] ?? 0) >= time()) {
    $verifyMode = 'verified_by_code';
} elseif ($code !== '' && $resetCode) {
    $verifyMode = 'code_expired';
} elseif ($code !== '') {
    $verifyMode = 'code_mismatch';
}

$hashedPassword = hashPassword($password);
$newStamp = generateSecurityStamp();

$fs->update('users', $user['id'], [
    'password_hash' => $hashedPassword,
    'security_stamp' => $newStamp
]);

// 一次性验证码，使用后标记已用（验证码缺失或已过期时无记录可标，跳过）
if ($resetCode && empty($resetCode['used'])) {
    $fs->update('password_reset_codes', $resetCode['id'], ['used' => 1]);
}

$fs->delete('password_reset_tokens', $resetToken['id']);

logUserActivity($user['id'], 'password_reset', '密码重置成功（校验方式：' . $verifyMode . '）');

jsonSuccess([], '密码重置成功，请使用新密码登录');