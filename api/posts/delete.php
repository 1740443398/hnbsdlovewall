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
    jsonError('账号已被封禁，无法删除帖子');
}

$postId = intval($_POST['post_id'] ?? 0);
if (!$postId) {
    jsonError('帖子ID无效');
}

$fs = getFS();
$post = $fs->findById('posts', $postId);

if (!$post) {
    jsonError('帖子不存在');
}

$isAuthor = $user['id'] == $post['user_id'];
$isAdmin = in_array($user['role'], ['admin', 'super_admin']);

if (!$isAuthor && !$isAdmin) {
    jsonError('没有权限删除此帖子', 403);
}

$fs->delete('posts', $postId);

$comments = $fs->find('comments', ['post_id' => $postId]);
foreach ($comments as $c) {
    $fs->delete('comments', $c['id']);
}

$likes = $fs->find('post_likes', ['post_id' => $postId]);
foreach ($likes as $l) {
    $fs->delete('post_likes', $l['id']);
}

$favorites = $fs->find('post_favorites', ['post_id' => $postId]);
foreach ($favorites as $f) {
    $fs->delete('post_favorites', $f['id']);
}

if ($isAdmin) {
    logOperation($user['id'], $user['qq'], 'delete_post', 'post', $postId, '删除帖子');
} else {
    logUserActivity($user['id'], 'post_delete', '删除自己的帖子');
}

jsonSuccess([], '删除成功');