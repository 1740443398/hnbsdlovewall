<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/actions/user_follow.php';

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
    jsonError('账号已被封禁，无法操作');
}

// 业务逻辑统一在 includes/actions/user_follow.php，与 AI 确认卡片共用同一份
$result = lw_do_user_follow($user, [
    'action'    => $_POST['action'] ?? '',
    'target_id' => intval($_POST['target_id'] ?? 0),
]);
if (!$result['ok']) {
    jsonError($result['message'], $result['code']);
}
jsonSuccess($result['data'], $result['message']);
