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
    jsonError('账号已被封禁，无法删除评论');
}

$commentId = intval($_POST['comment_id'] ?? 0);

if (!$commentId) {
    jsonError('评论ID无效');
}

$fs = getFS();
$comment = $fs->findById('comments', $commentId);

if (!$comment) {
    jsonError('评论不存在');
}

$isAuthor = $user['id'] == $comment['user_id'];
$isAdmin = in_array($user['role'], ['admin', 'super_admin']);

if (!$isAuthor && !$isAdmin) {
    jsonError('没有权限删除此评论', 403);
}

$fs->delete('comments', $commentId);

$post = $fs->findById('posts', $comment['post_id']);
if ($post) {
    $fs->update('posts', $post['id'], ['comments' => max(0, ($post['comments'] ?? 0) - 1)]);
}

if ($isAdmin) {
    logOperation($user['id'], $user['qq'], 'delete_comment', 'comment', $commentId, '删除评论');
}

jsonSuccess([], '删除成功');