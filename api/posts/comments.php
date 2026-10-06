<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/fields.php';

$postId = intval($_REQUEST['id'] ?? 0);
if (!$postId) {
    jsonError('帖子ID无效');
}

$fs = getFS();
$post = $fs->findById('posts', $postId);

if (!$post || $post['status'] !== 'published') {
    jsonError('帖子不存在或未发布');
}

$user = requireMember('评论需要注册账号后才能查看');
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

// 屏蔽过滤：隐藏被屏蔽用户发的评论。
// 匿名评论同样按**真实 user_id** 过滤 —— 否则对方只要勾上匿名就能绕过屏蔽，
// 屏蔽形同虚设（匿名是对其他用户隐藏身份，不是对系统隐藏归属）。
require_once __DIR__ . '/../../includes/user_blocks.php';
$comments = lwFilterBlocked($comments, (int)$user['id']);

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

// 字段投影（E14）：不传 fields/top 时与改造前完全一致
jsonSuccess(lwProjectTop(
    ['comments' => lwProjectRows(
        $result,
        lwFieldsRequest(),
        ['id', 'content', 'is_anonymous', 'author', 'created_at'],
        ['id']
    )],
    lwTopRequest(),
    [] // top 的语义就是「我只要这几个顶层键」，不强制保留任何一个
));
