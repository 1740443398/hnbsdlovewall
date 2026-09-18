<?php
require_once __DIR__ . '/../../config/config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonError('请求方法不允许', 405);
}

$csrfToken = $_POST['csrf_token'] ?? '';
if (!verifyCSRFToken($csrfToken)) {
    jsonError('CSRF验证失败', 403);
}

$user = requireLogin();
$user = checkBanned($user);
if ($user['is_banned']) {
    jsonError('账号已被封禁');
}

$theme = sanitizeInput($_POST['theme'] ?? '');

if (!in_array($theme, ['light', 'dark'])) {
    jsonError('主题值无效');
}

$fs = getFS();
$fs->update('users', $user['id'], ['theme' => $theme]);

jsonSuccess([], '主题更新成功');