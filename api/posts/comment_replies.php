<?php
require_once __DIR__ . '/../../config/config.php';

$postId = intval($_REQUEST['post_id'] ?? 0);
$parentId = intval($_REQUEST['parent_id'] ?? 0);
if (!$postId || !$parentId) {
    jsonError('参数无效');
}

$user = getCurrentUser();
$fs = getFS();

$post = $fs->findById('posts', $postId);
if (!$post) {
    jsonError('帖子不存在');
}

// 可见性 / 状态校验：与帖子详情一致
$isAuthor = $user && $user['id'] == $post['user_id'];
$userIsAdmin = $user && in_array($user['role'], ['admin', 'super_admin']);
if ($post['status'] !== 'published' && !$isAuthor && !$userIsAdmin) {
    jsonError('帖子尚未发布');
}
if (!$userIsAdmin && !$isAuthor) {
    $vis = $post['visibility'] ?? 'public';
    if ($vis === 'visible_to') {
        $allowed = array_map('trim', explode(',', $post['visible_to'] ?? ''));
        if (!$user || !in_array($user['qq'], $allowed)) {
            jsonError('无权查看该帖子', 403);
        }
    } elseif ($vis === 'exclude_to') {
        $excluded = array_map('trim', explode(',', $post['exclude_to'] ?? ''));
        if ($user && in_array($user['qq'], $excluded)) {
            jsonError('无权查看该帖子', 403);
        }
    }
}

$parent = $fs->findById('comments', $parentId);
if (!$parent || (int)($parent['post_id'] ?? 0) !== $postId) {
    jsonError('回复的评论不存在');
}

// 被回复者昵称（顶层评论作者），用于前端 @ 展示
$pAnon = !empty($parent['is_anonymous']);
$pUser = $pAnon ? null : $fs->findById('users', $parent['user_id']);
$replyToName = $pAnon ? '匿名用户' : ($pUser['nickname'] ?? '匿名用户');

$replies = $fs->find('comments', ['post_id' => $postId, 'parent_id' => $parentId]);
usort($replies, function($a, $b) {
    return strtotime($a['created_at']) - strtotime($b['created_at']);
});

$allUsers = $fs->read('users');
$userIndex = [];
foreach ($allUsers as $u) {
    if (isset($u['id'])) {
        $userIndex[(int)$u['id']] = $u;
    }
}

$list = [];
foreach ($replies as $rc) {
    $rAnon = !empty($rc['is_anonymous']);
    $rUser = $rAnon ? null : ($userIndex[(int)$rc['user_id']] ?? null);
    $list[] = [
        'id' => $rc['id'],
        'content' => $rc['content'],
        'parent_id' => $parentId,
        'is_anonymous' => $rAnon,
        'author_nickname' => $rAnon ? '匿名用户' : ($rUser['nickname'] ?? ''),
        'author_avatar' => $rAnon ? '/assets/images/default-avatar.svg' : ($rUser['avatar'] ?? ''),
        'author_title_text' => $rAnon ? '' : ($rUser['title_text'] ?? ''),
        'author_title_color' => $rAnon ? '' : ($rUser['title_color'] ?? ''),
        'author_title_bg_color' => $rAnon ? '' : ($rUser['title_bg_color'] ?? ''),
        'created_at' => $rc['created_at'],
        'time_ago' => timeAgo($rc['created_at']),
        'is_author' => $user && $user['id'] == $rc['user_id'],
        'reply_to_name' => $replyToName
    ];
}

jsonSuccess(['comments' => $list]);