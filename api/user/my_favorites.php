<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/fields.php';

$user = requireLogin();

$user = checkBanned($user);
if ($user['is_banned']) {
    jsonError('账号已被封禁');
}

$fs = getFS();
$page = max(1, intval($_REQUEST['page'] ?? 1));
$limit = 10;

$favorites = $fs->find('post_favorites', ['user_id' => $user['id']]);
usort($favorites, function($a, $b) {
    return strtotime($b['created_at'] ?? '') - strtotime($a['created_at'] ?? '');
});

$total = count($favorites);
$favorites = array_slice($favorites, ($page - 1) * $limit, $limit);

$result = [];
foreach ($favorites as $fav) {
    $post = $fs->findById('posts', $fav['post_id']);
    if ($post) {
        $isAnonymous = !empty($post['is_anonymous']);
        $postUser = $isAnonymous ? null : $fs->findById('users', $post['user_id']);
        $result[] = [
            'id' => $fav['id'],
            'post_id' => $post['id'],
            'title' => $post['title'] ?: '(无标题)',
            'content' => mb_substr(strip_tags($post['content'] ?? ''), 0, 100),
            'is_anonymous' => $isAnonymous,
            'author' => [
                'nickname' => $isAnonymous ? '匿名用户' : ($postUser['nickname'] ?? '')
            ],
            'like_count' => intval($post['likes'] ?? 0),
            'comment_count' => intval($post['comments'] ?? 0),
            'timeAgo' => timeAgo($post['created_at']),
            'created_at' => $post['created_at']
        ];
    }
}

// 字段投影（E14）：不传 fields/top 时与改造前完全一致
// 注意 author 是嵌套对象，投影只到顶层键、不做深投影（与库的约定一致）
jsonSuccess(lwProjectTop([
    'favorites' => lwProjectRows(
        $result,
        lwFieldsRequest(),
        ['id', 'post_id', 'title', 'content', 'is_anonymous', 'author',
         'like_count', 'comment_count', 'timeAgo', 'created_at'],
        ['id', 'post_id']
    ),
    'pagination' => [
        'total' => $total,
        'page' => $page,
        'limit' => $limit,
        'total_pages' => ceil($total / $limit)
    ]
], lwTopRequest(), []));