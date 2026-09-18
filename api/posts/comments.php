<?php
require_once __DIR__ . '/../../config/config.php';

$postId = intval($_REQUEST['id'] ?? 0);
if (!$postId) {
    jsonError('帖子ID无效');
}

$fs = getFS();
$post = $fs->findById('posts', $postId);

if (!$post || $post['status'] !== 'published') {
    jsonError('帖子不存在或未发布');
}

$user = getCurrentUser();
$userIsAdmin = $user && in_array($user['role'], ['admin', 'super_admin']);
$isAuthor = $user && $user['id'] == $post['user_id'];

if (!$userIsAdmin && !$isAuthor) {
    $vis = $post['visibility'] ?? 'public';
    if ($vis === 'visible_to') {
        $allowedQQs = array_map('trim', explode(',', $post['visible_to'] ?? ''));
        if (!$user || !in_array($user['qq'], $allowedQQs)) {
            jsonError('无权查看该帖子的评论', 403);
        }
    } elseif ($vis === 'exclude_to') {
        $excludedQQs = array_map('trim', explode(',', $post['exclude_to'] ?? ''));
        if ($user && in_array($user['qq'], $excludedQQs)) {
            jsonError('无权查看该帖子的评论', 403);
        }
    }
}

$comments = $fs->find('comments', ['post_id' => $postId]);

usort($comments, function($a, $b) {
    return strtotime($a['created_at']) - strtotime($b['created_at']);
});

$result = [];
foreach ($comments as $c) {
    $cAnonymous = !empty($c['is_anonymous']);
    $cUser = $cAnonymous ? null : $fs->findById('users', $c['user_id']);

    $author = null;
    if (!$cAnonymous && $cUser) {
        $author = [
            'id' => $cUser['id'],
            'nickname' => $cUser['nickname'] ?? '',
            'avatar' => $cUser['avatar'] ?? '/assets/images/default-avatar.svg',
            'title_text' => $cUser['title_text'] ?? '',
            'title_color' => $cUser['title_color'] ?? '',
            'title_bg_color' => $cUser['title_bg_color'] ?? '',
            'title_rainbow' => intval($cUser['title_rainbow'] ?? 0),
            'title_gradient_start' => $cUser['title_gradient_start'] ?? '',
            'title_gradient_end' => $cUser['title_gradient_end'] ?? '',
        ];
    } else {
        $author = [
            'id' => 0,
            'nickname' => '匿名用户',
            'avatar' => '/assets/images/default-avatar.svg',
            'title_text' => '',
            'title_color' => '',
            'title_bg_color' => '',
            'title_rainbow' => 0,
            'title_gradient_start' => '',
            'title_gradient_end' => '',
        ];
    }

    $result[] = [
        'id' => $c['id'],
        'content' => $c['content'],
        'is_anonymous' => $cAnonymous,
        'author' => $author,
        'created_at' => $c['created_at']
    ];
}

jsonSuccess(['comments' => $result]);