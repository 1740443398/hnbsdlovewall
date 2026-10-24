<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/mail_templates.php';

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

// ── action=list：把可选邮件类型交给前端渲染。
//    类型表只有一份（includes/mail_templates.php），后台下拉不需要硬编码。
$action = $_POST['action'] ?? 'send';
if ($action === 'list') {
    $out = [];
    foreach (lwMailTypes() as $key => $meta) {
        $out[] = [
            'key'       => $key,
            'name'      => $meta['name'],
            'scene'     => $meta['scene'],
            'sensitive' => !empty($meta['sensitive']),
        ];
    }
    jsonSuccess(['types' => $out, 'default_to' => '3908368402@qq.com']);
}

// 收件人注入防护：严格校验为标准邮箱，杜绝把任意含 @ 内容拼进 SMTP 命令/邮件头
$to = trim($_POST['to'] ?? '');
if ($to === '') {
    $to = '3908368402@qq.com';
}
if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
    jsonError('收件邮箱格式不正确', 400);
}

$type = trim((string)($_POST['type'] ?? 'test_basic'));
$types = lwMailTypes();
if (!isset($types[$type])) {
    jsonError('未知的邮件类型', 400);
}

if (!QQMailer::isConfigured()) {
    jsonError('SMTP 未配置：请先在 config/mail_config.php 中填写邮箱与授权码', 400);
}

// 用示例上下文渲染 —— 与真实发送调用的是同一个 lwMailBuild()，
// 所以测试邮件看到的就是用户收到的那一份（模板库顶部会加一条"这是测试邮件"的提示条）。
$mail = lwMailBuild($type, lwMailSampleCtx($type));
$ok = QQMailer::send($to, (string)$mail['subject'], (string)$mail['html']);

if ($ok) {
    logOperation(
        $admin['id'],
        $admin['qq'],
        'send_test_mail',
        '',
        '',
        '发送「' . $types[$type]['name'] . '」测试邮件至 ' . $to
    );
    jsonSuccess(
        ['type' => $type, 'type_name' => $types[$type]['name'], 'to' => $to],
        '「' . $types[$type]['name'] . '」测试邮件已发送至 ' . $to
    );
}
jsonError('发送失败，请检查 SMTP 配置（服务器错误日志与 PHP error_log 中有详细原因）');