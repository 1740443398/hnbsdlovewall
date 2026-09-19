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
    jsonError('账号已被封禁，无法点赞');
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

// 公告为官方通知，只读展示，不支持点赞
if (($post['category'] ?? '') === 'announcement') {
    jsonError('公告为官方通知，暂不支持点赞', 403);
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

// 单一可信来源：点赞状态一律以 post_likes 表为准。
// 切换后回查该帖真实点赞行数并同步 posts.likes，避免并发/重复请求导致计数只增不减或漂移。
$likeRows = $fs->find('post_likes', ['user_id' => $user['id'], 'post_id' => $postId]);
if (!empty($likeRows)) {
    // 取消点赞：清掉该用户在此帖下的全部点赞行（含历史重复行），回查真实数
    foreach ($likeRows as $row) {
        $fs->delete('post_likes', $row['id']);
    }
    $likedNow = false;
} else {
    $fs->insert('post_likes', [
        'user_id' => $user['id'],
        'post_id' => $postId
    ]);
    $likedNow = true;
}

$newLikes = count($fs->find('post_likes', ['post_id' => $postId]));
$fs->update('posts', $postId, ['likes' => $newLikes]);

if ($likedNow && $post['user_id'] != $user['id']) {
    $postAuthor = $fs->findById('users', $post['user_id']);
    if ($postAuthor) {
        $postTitle = $post['title'] ?? '无标题';
        $notifyContent = ($user['nickname'] ?? '用户') . ' 赞了你的帖子「' . mb_substr($postTitle, 0, 30) . (mb_strlen($postTitle) > 30 ? '...' : '') . '」';
        $fs->insert('notifications', [
            'user_id' => $post['user_id'],
            'type' => 'like',
            'content' => $notifyContent,
            'post_id' => $postId,
            'post_title' => $postTitle,
            'from_user' => $user['nickname'] ?? '用户',
            'is_read' => false
        ]);
    }
}

jsonSuccess(['is_liked' => $likedNow, 'like_count' => $newLikes]);