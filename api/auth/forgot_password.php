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
if (!checkRateLimit($ip, 'forgot_password', 5, 300)) {
    jsonError('请求过于频繁，请稍后再试', 429);
}

$qq = sanitizeInput($_POST['qq'] ?? '');

if (!isValidQQ($qq)) {
    jsonError('QQ号格式不正确');
}

$fs = getFS();
$user = $fs->findOne('users', ['qq' => $qq]);

if (!$user) {
    jsonSuccess([], '如果该QQ号已注册，重置链接已发送');
    exit;
}

$user = checkBanned($user);

$token = generateRandomString(64);
$existing = $fs->find('password_reset_tokens', ['user_id' => $user['id']]);
foreach ($existing as $t) {
    $fs->delete('password_reset_tokens', $t['id']);
}

$fs->insert('password_reset_tokens', [
    'user_id' => $user['id'],
    'token' => $token,
    'expires_at' => date('Y-m-d H:i:s', time() + 3600)
]);

$fs->insert('notifications', [
    'user_id' => $user['id'],
    'type' => 'password_reset',
    'content' => '密码重置链接：' . SITE_URL . '/pages/forgot_password.php?token=' . $token,
    'is_read' => false
]);

jsonSuccess([], '如果该QQ号已注册，重置链接已发送至站内消息');