<?php
require_once __DIR__ . '/../../config/config.php';

$user = requireLogin();

$user = checkBanned($user);
if ($user['is_banned']) {
    jsonError('账号已被封禁');
}

$fs = getFS();
$posts = $fs->find('posts', ['user_id' => $user['id']]);

usort($posts, function($a, $b) {
    return strtotime($b['created_at']) - strtotime($a['created_at']);
});

$page = max(1, intval($_REQUEST['page'] ?? 1));
$limit = 10;
$total = count($posts);
$posts = array_slice($posts, ($page - 1) * $limit, $limit);

$categoryNames = [
    'announcement' => '全站公告', 'lost_found' => '寻物/失物招领',
    'study_help' => '学习求助', 'social_chat' => '交友闲聊',
    'confession' => '表白', 'school_info' => '校园打听', 'other' => '其他'
];

$result = [];
foreach ($posts as $post) {
    $result[] = [
        'id' => $post['id'],
        'category' => $post['category'],
        'category_name' => $categoryNames[$post['category']] ?? $post['category'],
        'title' => $post['title'] ?: '(无标题)',
        'content' => mb_substr(strip_tags($post['content'] ?? ''), 0, 100),
        'status' => $post['status'] ?? 'published',
        'like_count' => intval($post['likes'] ?? 0),
        'comment_count' => intval($post['comments'] ?? 0),
        'view_count' => intval($post['views'] ?? 0),
        'timeAgo' => timeAgo($post['created_at']),
        'created_at' => $post['created_at']
    ];
}

jsonSuccess([
    'posts' => $result,
    'pagination' => [
        'total' => $total,
        'page' => $page,
        'limit' => $limit,
        'total_pages' => ceil($total / $limit)
    ]
]);