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
    jsonError('该QQ号未注册');
}

$user = checkBanned($user);

$email = $qq . '@qq.com';

// 生成 6 位数字验证码，5 分钟有效
$code = (string)random_int(100000, 999999);
$expiresAt = time() + 300;

// 写入验证码表（FileStorage 首次 insert 自动建表）
$fs->insert('password_reset_codes', [
    'qq' => $qq,
    'code' => $code,
    'expires_at' => $expiresAt,
    'created_at' => date('Y-m-d H:i:s'),
    'used' => 0,
]);

// 先为本次请求生成一次性重置 token（1 小时有效），供邮件/站内通知携带重置链接
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
// 重置页同时需要 token 与 qq 才展示重置表单，故链接中携带两者
$resetUrl = SITE_URL . '/pages/forgot_password.php?token=' . $token . '&qq=' . $qq;
$resetUrlEsc = htmlspecialchars($resetUrl, ENT_QUOTES | ENT_HTML5, 'UTF-8');

// 尝试发送邮件验证码
$mailOk = false;
if (QQMailer::isConfigured()) {
    $html = '<div style="font-family:Arial,\'Microsoft YaHei\',sans-serif;line-height:1.7;color:#1B2A3A;">'
        . '<h2 style="color:#1B3A5C;">' . htmlspecialchars(SITE_NAME, ENT_QUOTES | ENT_HTML5, 'UTF-8') . ' —— 密码重置验证码</h2>'
        . '<p>您好：</p>'
        . '<p>您正在为账号（QQ：' . htmlspecialchars($qq) . '）申请重置密码。请点击以下链接前往重置页面：</p>'
        . '<p style="background:#F6F8FA;border:1px solid #E2E8F0;border-radius:6px;padding:12px;word-break:break-all;"><a href="' . $resetUrlEsc . '">' . $resetUrlEsc . '</a></p>'
        . '<p>如需手动填写，您的验证码为：<strong style="font-size:24px;color:#C9A96E;">' . htmlspecialchars($code) . '</strong></p>'
        . '<p>该验证码 <strong>5 分钟内有效</strong>，重置链接 <strong>1 小时内有效</strong>，请尽快完成设置。</p>'
        . '<p>若非您本人操作，请忽略此邮件，您的账号不会受影响。</p>'
        . '<hr style="border:none;border-top:1px solid #eee;">'
        . '<p style="color:#888;font-size:13px;">此邮件由系统自动发送，请勿直接回复。</p>'
        . '</div>';
    $mailOk = QQMailer::send($email, '密码重置验证码', $html);
}

if (!$mailOk) {
    // 兜底：邮件不可用时退回站内通知（同时携带重置链接与验证码，用户才能完成重置）
    $fs->insert('notifications', [
        'user_id' => $user['id'],
        'type' => 'password_reset',
        'content' => '密码重置链接：' . $resetUrl . '，验证码：' . $code,
        'is_read' => false
    ]);
    jsonSuccess([], '邮件发送失败，已改用站内通知');
}

// 邮件发送成功
jsonSuccess([], '验证码已发送至 ' . $email . '，5分钟内有效');