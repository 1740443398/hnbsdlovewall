<?php
require_once __DIR__ . '/../../config/config.php';

$postId = intval($_REQUEST['post_id'] ?? 0);
if (!$postId) {
    jsonError('帖子ID无效');
}

$fs = getFS();
$post = $fs->findById('posts', $postId);

if (!$post) {
    jsonError('帖子不存在');
}

$user = getCurrentUser();
$userIsAdmin = $user && in_array($user['role'], ['admin', 'super_admin']);
if (!$userIsAdmin) {
    if ($post['status'] !== 'published') {
        if (!$user || $post['user_id'] != $user['id']) {
            jsonError('帖子不存在');
        }
    }
    $vis = $post['visibility'] ?? 'public';
    if ($vis === 'visible_to') {
        $allowedQQs = array_map('trim', explode(',', $post['visible_to'] ?? ''));
        if (!$user || !in_array($user['qq'], $allowedQQs)) {
            jsonError('帖子不存在');
        }
    } elseif ($vis === 'exclude_to') {
        $excludedQQs = array_map('trim', explode(',', $post['exclude_to'] ?? ''));
        if ($user && in_array($user['qq'], $excludedQQs)) {
            jsonError('帖子不存在');
        }
    }
}

$sessionKey = 'viewed_post_' . $postId;
$lastView = $_SESSION[$sessionKey] ?? 0;
$now = time();

if ($now - $lastView > 300) {
    $views = intval($post['views'] ?? 0) + 1;
    $fs->update('posts', $postId, ['views' => $views]);
    $_SESSION[$sessionKey] = $now;
    jsonSuccess(['views' => $views]);
}

jsonSuccess(['views' => intval($post['views'] ?? 0), 'deduplicated' => true]);