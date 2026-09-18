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
    jsonError('账号已被封禁，无法举报');
}

$postId = intval($_POST['post_id'] ?? 0);
$reason = sanitizeInput($_POST['reason'] ?? '');
$description = sanitizeInput($_POST['description'] ?? '');

if (!$postId) {
    jsonError('帖子ID无效');
}

if (empty($reason)) {
    jsonError('请选择举报原因');
}

$validReasons = ['色情低俗', '辱骂攻击', '垃圾广告', '违规内容', '泄露隐私', '其他', 'spam', 'abuse', 'illegal', 'privacy', 'other'];
if (!in_array($reason, $validReasons)) {
    jsonError('无效的举报原因');
}

$fs = getFS();
$post = $fs->findById('posts', $postId);

if (!$post) {
    jsonError('帖子不存在');
}

$existing = $fs->find('post_reports', ['user_id' => $user['id'], 'post_id' => $postId]);
if (!empty($existing)) {
    jsonError('您已经举报过此帖子');
}

$fs->insert('post_reports', [
    'post_id' => $postId,
    'user_id' => $user['id'],
    'reporter_qq' => $user['qq'],
    'reason' => $reason,
    'reason_text' => $reason,
    'description' => $description
]);

logUserActivity($user['id'], 'report_post', '举报帖子 #' . $postId . ' 原因: ' . $reason);

jsonSuccess([], '举报已提交，管理员将尽快处理');