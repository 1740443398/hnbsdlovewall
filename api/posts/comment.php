<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/actions/post_comment.php';

/* 评论频率限制（后台「内容与发帖 → 评论频率上限」可调）。
     * 按账号而不是按 IP —— 校园网全校共用一个出口 IP，按 IP 限会误伤别人。
     */
    $__commentLimit = function_exists('lwCfgInt') ? max(1, lwCfgInt('post_comment_rate_limit', 30)) : 30;
    $__commentUser = function_exists('getCurrentUser') ? getCurrentUser() : null;
    if ($__commentUser && !checkRateLimit('c' . (int)$__commentUser['id'], 'comment', $__commentLimit, 60)) {
        jsonError('评论太频繁了，请稍后再试（每分钟最多 ' . $__commentLimit . ' 条）', 429);
    }

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
