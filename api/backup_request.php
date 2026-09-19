<?php
// 公开备份下载申请：向申请人注册 QQ 邮箱发送 6 位验证码
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/security.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonError('请求方法不允许', 405);
}

$csrfToken = $_POST['csrf_token'] ?? '';
if (!verifyCSRFToken($csrfToken)) {
    jsonError('CSRF验证失败', 403);
}

// 限流：同一 IP 每 10 分钟最多 5 次申请
$ip = getClientIP();
if (!checkRateLimit($ip, 'backup_request', 5, 600)) {
    jsonError('申请过于频繁，请稍后再试', 429);
}

// 必须为已注册登录用户
$user = requireLogin();
$user = checkBanned($user);
if (!empty($user['is_banned'])) {
    jsonError('账号已被封禁，无法申请下载', 403);
}

$qq = trim((string)($user['qq'] ?? ''));
if (!isValidQQ($qq)) {
    jsonError('账号QQ号无效，无法接收验证码');
}
$email = $qq . '@qq.com';

// 邮件必须已配置，否则验证码无法送达
if (!QQMailer::isConfigured()) {
    jsonError('邮件服务暂不可用，请联系管理员');
}

$fs = getFS();

// 生成 6 位验证码，10 分钟有效
$code = (string)random_int(100000, 999999);
$expiresAt = time() + 600;

// 将该用户旧的「待验证」申请全部作废为已过期，避免旧验证码二次使用
$old = $fs->find('backup_applications', ['user_id' => $user['id'], 'status' => 'pending']);
foreach ($old as $rec) {
    $fs->update('backup_applications', $rec['id'], ['status' => 'expired']);
}

$fs->insert('backup_applications', [
    'user_id' => $user['id'],
    'qq' => $qq,
    'code' => $code,
    'expires_at' => $expiresAt,
    'status' => 'pending',
    'download_count' => 0,
    'ip' => $ip,
]);

// 发送验证码邮件
$html = '<div style="font-family:Arial,\'Microsoft YaHei\',sans-serif;line-height:1.7;color:#1B2A3A;">'
    . '<h2 style="color:#1B3A5C;">' . htmlspecialchars(SITE_NAME, ENT_QUOTES | ENT_HTML5, 'UTF-8') . ' —— 数据下载验证码</h2>'
    . '<p>您好：</p>'
    . '<p>您正在申请下载本站的完整数据备份。为验证身份，请在下载页输入以下验证码：</p>'
    . '<p style="text-align:center;"><strong style="font-size:30px;letter-spacing:6px;color:#C9A96E;">' . htmlspecialchars($code) . '</strong></p>'
    . '<p>该验证码 <strong>10 分钟内有效</strong>，请尽快完成下载。</p>'
    . '<p>若您未申请过下载，请忽略此邮件。</p>'
    . '<hr style="border:none;border-top:1px solid #eee;">'
    . '<p style="color:#888;font-size:13px;">此邮件由系统自动发送，请勿直接回复。</p>'
    . '</div>';
$mailOk = QQMailer::send($email, '数据下载验证码', $html);

if (!$mailOk) {
    jsonError('验证码邮件发送失败，请稍后重试或联系管理员');
}

if (function_exists('logUserActivity')) {
    logUserActivity($user['id'], 'backup_request', '申请完整数据下载，验证码已发送至 ' . $email);
}

jsonSuccess([], '验证码已发送至 ' . $email . '，10分钟内有效');
