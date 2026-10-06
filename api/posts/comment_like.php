<?php
require_once __DIR__ . '/../../config/config.php';

$fs = getFS();

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $commentId = $_REQUEST['comment_id'] ?? '';
    if (!$commentId) {
        jsonError('缺少评论ID');
    }

    $comment = $fs->findById('comments', $commentId);
    if (!$comment) {
        jsonError('评论不存在');
    }

    $likeCount = intval($comment['likes'] ?? 0);
    $isLiked = false;

    $user = getCurrentUser();
    if ($user) {
        $existing = $fs->findOne('comment_likes', ['user_id' => $user['id'], 'comment_id' => $commentId]);
        $isLiked = !empty($existing);
    }

    jsonSuccess([
        'comment_id' => $commentId,
        'like_count' => $likeCount,
        'is_liked' => $isLiked
    ]);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrfToken = $_POST['csrf_token'] ?? '';
    if (!verifyCSRFToken($csrfToken)) {
        jsonError('CSRF验证失败', 403);
    }

    $user = requireLogin();

    $user = checkBanned($user);
    if ($user['is_banned']) {
        jsonError('账号已被封禁，无法点赞');
    }

    $commentId = $_REQUEST['comment_id'] ?? '';
    if (!$commentId) {
        jsonError('缺少评论ID');
    }

    $comment = $fs->findById('comments', $commentId);
    if (!$comment) {
        jsonError('评论不存在');
    }

    $existingLike = $fs->findOne('comment_likes', ['user_id' => $user['id'], 'comment_id' => $commentId]);

    if ($existingLike) {
        $fs->delete('comment_likes', $existingLike['id']);
        $newLikes = max(0, intval($comment['likes'] ?? 0) - 1);
        $fs->update('comments', $commentId, ['likes' => $newLikes]);
        jsonSuccess(['is_liked' => false, 'like_count' => $newLikes]);
    } else {
        $fs->insert('comment_likes', [
            'user_id' => $user['id'],
            'comment_id' => $commentId
        ]);
        $newLikes = intval($comment['likes'] ?? 0) + 1;
        $fs->update('comments', $commentId, ['likes' => $newLikes]);

        if ($comment['user_id'] != $user['id']) {
            $commentAuthor = $fs->findById('users', $comment['user_id']);
            if ($commentAuthor) {
                $commentSnippet = mb_substr($comment['content'] ?? '', 0, 30);
                $notifyContent = ($user['nickname'] ?? '用户') . ' 赞了你的评论「' . $commentSnippet . (mb_strlen($comment['content'] ?? '') > 30 ? '...' : '') . '」';
                $postInfo = $fs->findById('posts', $comment['post_id'] ?? 0);
                $fs->insert('notifications', [
                    'user_id' => $comment['user_id'],
                    'type' => 'like',
                    'content' => $notifyContent,
                    'post_id' => $comment['post_id'] ?? '',
                    'post_title' => $postInfo ? ($postInfo['title'] ?? '无标题') : '无标题',
                    'from_user' => $user['nickname'] ?? '用户',
                    'is_read' => false
                ]);
            }
        }

        jsonSuccess(['is_liked' => true, 'like_count' => $newLikes]);
    }
}