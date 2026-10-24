<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/actions/post_comment.php';

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
    jsonError('账号已被封禁，无法评论');
}

// 业务逻辑统一在 includes/actions/post_comment.php，与 AI 确认卡片共用同一份
$result = lw_do_post_comment($user, [
    'post_id'      => intval($_POST['post_id'] ?? 0),
    'content'      => $_POST['content'] ?? '',
    'is_anonymous' => ($_POST['is_anonymous'] ?? '0') === '1',
    'parent_id'    => intval($_POST['parent_id'] ?? 0),
]);
if (!$result['ok']) {
    jsonError($result['message'], $result['code']);
}
jsonSuccess($result['data'], $result['message']);
