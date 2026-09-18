<?php
require_once __DIR__ . '/../../config/config.php';

$user = requireLogin();

$user = checkBanned($user);
if ($user['is_banned']) {
    jsonError('账号已被封禁');
}

$fs = getFS();
$comments = $fs->find('comments', ['user_id' => $user['id']]);

usort($comments, function($a, $b) {
    return strtotime($b['created_at']) - strtotime($a['created_at']);
});

$page = max(1, intval($_REQUEST['page'] ?? 1));
$limit = 10;
$total = count($comments);
$comments = array_slice($comments, ($page - 1) * $limit, $limit);

$result = [];
foreach ($comments as $c) {
    $post = $fs->findById('posts', $c['post_id']);
    if ($post) {
        $result[] = [
            'id' => $c['id'],
            'post_id' => $c['post_id'],
            'post_title' => $post['title'] ?: '(无标题)',
            'content' => $c['content'],
            'timeAgo' => timeAgo($c['created_at']),
            'created_at' => $c['created_at']
        ];
    }
}

jsonSuccess([
    'comments' => $result,
    'pagination' => [
        'total' => $total,
        'page' => $page,
        'limit' => $limit,
        'total_pages' => ceil($total / $limit)
    ]
]);