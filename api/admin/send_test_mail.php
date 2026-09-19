<?php
require_once __DIR__ . '/../../config/config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonError('请求方法不允许', 405);
}

$csrfToken = $_POST['csrf_token'] ?? '';
if (!verifyCSRFToken($csrfToken)) {
    jsonError('CSRF验证失败', 403);
}

$admin = requireAdmin();
$admin = checkBanned($admin);
if ($admin['is_banned']) {
    jsonError('账号已被封禁');
}

// 权限收紧为仅超管：后台「SMTP 邮件测试」按钮本就仅超管可见，接口口径需保持一致
if (($admin['role'] ?? '') !== 'super_admin') {
    jsonError('权限不足，仅站长可发送测试邮件', 403);
}

// 收件人注入防护：严格校验为标准邮箱，杜绝把任意含 @ 内容拼进 SMTP 命令/邮件头
$to = trim($_POST['to'] ?? '');
if ($to === '') {
    $to = '3908368402@qq.com';
}
if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
    jsonError('收件邮箱格式不正确', 400);
}

$html = '<div style="font-family:Arial,\'Microsoft YaHei\',sans-serif;line-height:1.8;color:#1B2A3A;">'
    . '<h2 style="color:#1B3A5C;">邮件服务测试</h2>'
    . '<p>这是一封来自「' . htmlspecialchars(SITE_NAME, ENT_QUOTES | ENT_HTML5, 'UTF-8') . '」后台自动发送的测试邮件。</p>'
    . '<p>如果您收到了这封邮件，说明站点的 SMTP 邮件发送功能工作正常。✔</p>'
    . '<p>发送时间：' . date('Y-m-d H:i:s') . '</p>'
    . '</div>';

$ok = QQMailer::send($to, '【校园交流墙】邮件发送测试', $html);

if ($ok) {
    logOperation($admin['id'], $admin['qq'], 'send_test_mail', '', '', '发送测试邮件至 ' . $to);
    jsonSuccess([], '测试邮件已发送');
}
jsonError('发送失败，请检查SMTP配置');