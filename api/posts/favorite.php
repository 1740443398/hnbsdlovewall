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
    jsonError('账号已被封禁，无法收藏');
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

// 公告为官方通知，只读展示，不支持收藏
if (($post['category'] ?? '') === 'announcement') {
    jsonError('公告为官方通知，暂不支持收藏', 403);
}

$userIsAdmin = in_array($user['role'], ['admin', 'super_admin']);
$isAuthor = $user['id'] == $post['user_id'];

if (!$userIsAdmin && !$isAuthor) {
    if ($post['status'] !== 'published') {
        jsonError('帖子不存在或未发布');
    }
    $vis = $post['visibility'] ?? 'public';
    if ($vis === 'visible_to') {
        $allowedQQs = array_map('trim', explode(',', $post['visible_to'] ?? ''));
        if (!in_array($user['qq'], $allowedQQs)) {
            jsonError('无权操作该帖子', 403);
        }
    } elseif ($vis === 'exclude_to') {
        $excludedQQs = array_map('trim', explode(',', $post['exclude_to'] ?? ''));
        if (in_array($user['qq'], $excludedQQs)) {
            jsonError('无权操作该帖子', 403);
        }
    }
}

$existingFav = $fs->findOne('post_favorites', ['user_id' => $user['id'], 'post_id' => $postId]);

if ($existingFav) {
    $fs->delete('post_favorites', $existingFav['id']);
    jsonSuccess(['is_favorited' => false]);
} else {
    $fs->insert('post_favorites', [
        'user_id' => $user['id'],
        'post_id' => $postId
    ]);
    jsonSuccess(['is_favorited' => true]);
}