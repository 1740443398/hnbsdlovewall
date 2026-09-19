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

// 与用户管理/审核同权限：超管恒有，普通管理员需该权限
if (!checkPermission($admin, 'review_qq_change')) {
    jsonError('权限不足', 403);
}

$to = sanitizeInput($_POST['to'] ?? '');
if ($to === '' || strpos($to, '@') === false) {
    $to = '3908368402@qq.com';
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