<?php
require_once __DIR__ . '/../../config/config.php';

$postId = intval($_REQUEST['id'] ?? 0);
if (!$postId) {
    jsonError('帖子ID无效');
}

$user = getCurrentUser();
$fs = getFS();
$post = $fs->findById('posts', $postId);

if (!$post) {
    jsonError('帖子不存在');
}

$isAuthor = $user && $user['id'] == $post['user_id'];
$userIsAdmin = $user && in_array($user['role'], ['admin', 'super_admin']);

if ($post['status'] !== 'published' && !$isAuthor && !$userIsAdmin) {
    jsonError('帖子尚未发布');
}

if (!$userIsAdmin && !$isAuthor) {
    $vis = $post['visibility'] ?? 'public';
    if ($vis === 'visible_to') {
        $allowedQQs = array_map('trim', explode(',', $post['visible_to'] ?? ''));
        if (!$user || !in_array($user['qq'], $allowedQQs)) {
            jsonError('无权查看该帖子', 403);
        }
    } elseif ($vis === 'exclude_to') {
        $excludedQQs = array_map('trim', explode(',', $post['exclude_to'] ?? ''));
        if ($user && in_array($user['qq'], $excludedQQs)) {
            jsonError('无权查看该帖子', 403);
        }
    }
}

$isAnonymous = !empty($post['is_anonymous']);
$postUser = $isAnonymous ? null : $fs->findById('users', $post['user_id']);

$liked = false;
if ($user) {
    $likes = $fs->find('post_likes', ['user_id' => $user['id'], 'post_id' => $post['id']]);
    $liked = !empty($likes);
}

$favorited = false;
if ($user) {
    $favs = $fs->find('post_favorites', ['user_id' => $user['id'], 'post_id' => $post['id']]);
    $favorited = !empty($favs);
}

$comments = $fs->find('comments', ['post_id' => $post['id']]);
usort($comments, function($a, $b) {
    return strtotime($a['created_at']) - strtotime($b['created_at']);
});

$commentList = [];

$allUsers = $fs->read('users');
$userIndex = [];
foreach ($allUsers as $u) {
    if (isset($u['id'])) {
        $userIndex[(int)$u['id']] = $u;
    }
}
foreach ($comments as $c) {
    $cIsAnonymous = !empty($c['is_anonymous']);
    $cUser = $cIsAnonymous ? null : ($userIndex[(int)$c['user_id']] ?? null);
    $commentList[] = [
        'id' => $c['id'],
        'content' => $c['content'],
        'parent_id' => intval($c['parent_id'] ?? 0),
        'is_anonymous' => $cIsAnonymous,
        'author_nickname' => $cIsAnonymous ? '匿名用户' : ($cUser['nickname'] ?? ''),
        'author_avatar' => $cIsAnonymous ? '/assets/images/default-avatar.svg' : ($cUser['avatar'] ?? ''),
        'created_at' => $c['created_at'],
        'is_author' => $user && $user['id'] == $c['user_id']
    ];
}

jsonSuccess([
    'post' => [
        'id' => $post['id'],
        'category' => $post['category'],
        'title' => $post['title'],
        'content' => $post['content'],
        'images' => json_decode($post['images'] ?? '[]', true),
        'is_anonymous' => $isAnonymous,
        'author_nickname' => $isAnonymous ? '匿名用户' : ($postUser['nickname'] ?? ''),
        'author_avatar' => $isAnonymous ? '/assets/images/default-avatar.svg' : ($postUser['avatar'] ?? ''),
        'author_title_text' => $isAnonymous ? '' : ($postUser['title_text'] ?? ''),
        'author_title_color' => $isAnonymous ? '' : ($postUser['title_color'] ?? ''),
        'author_title_bg_color' => $isAnonymous ? '' : ($postUser['title_bg_color'] ?? ''),
        'author_title_rainbow' => $isAnonymous ? 0 : intval($postUser['title_rainbow'] ?? 0),
        'author_title_gradient_start' => $isAnonymous ? '' : ($postUser['title_gradient_start'] ?? ''),
        'author_title_gradient_end' => $isAnonymous ? '' : ($postUser['title_gradient_end'] ?? ''),
        'is_author' => $isAuthor,
        'likes' => $post['likes'] ?? 0,
        'comments' => $post['comments'] ?? 0,
        'is_liked' => $liked,
        'is_favorited' => $favorited,
        'created_at' => $post['created_at'],
        'status' => $post['status']
    ],
    'comments' => $commentList
]);
