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

// 防账号枚举：无论该QQ是否注册，对外都返回同一文案与同一 200 状态码；
// 仅当账号存在时才真正写入验证码/重置 token 并发送邮件。
$genericMessage = '若该QQ号已注册，我们已发送验证邮件';

if (!$user) {
    // 账号不存在：补一段与后续发信开销量级相近的耗时，弱化时间侧信道差异
    usleep(random_int(200000, 450000));
    jsonSuccess([], $genericMessage);
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
    // 邮件正文统一由 includes/mail_templates.php 提供（后台「测试邮件」发的是同一份）
    require_once __DIR__ . '/../../includes/mail_templates.php';
    $mail = lwMailBuild('verify_reset', [
        'qq'        => $qq,
        'code'      => $code,
        'reset_url' => $resetUrl,
    ]);
    $mailOk = QQMailer::send($email, $mail['subject'], $mail['html']);
}

if (!$mailOk) {
    // 兜底：邮件不可用时退回站内通知（同时携带重置链接与验证码，用户才能完成重置）
    $fs->insert('notifications', [
        'user_id' => $user['id'],
        'type' => 'password_reset',
        'content' => '密码重置链接：' . $resetUrl . '，验证码：' . $code,
        'is_read' => false
    ]);
    jsonSuccess([], $genericMessage);
}

// 邮件发送成功（对外文案与账号不存在时保持一致，避免枚举）
jsonSuccess([], $genericMessage);