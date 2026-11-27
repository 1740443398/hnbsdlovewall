<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/fields.php';

$user = requireLogin();

$user = checkBanned($user);
if ($user['is_banned']) {
    jsonError('账号已被封禁');
}

$fs = getFS();
$qFilter = trim(sanitizeInput($_REQUEST['q'] ?? ''));
$catFilter = sanitizeInput($_REQUEST['category'] ?? '');
$page = max(1, intval($_REQUEST['page'] ?? 1));
$limit = 10;

$favorites = $fs->find('post_favorites', ['user_id' => $user['id']]);
usort($favorites, function($a, $b) {
    return strtotime($b['created_at'] ?? '') - strtotime($a['created_at'] ?? '');
});

if ($catFilter !== '') {
    $favorites = array_values(array_filter($favorites, function ($fav) use ($catFilter, $fs) {
        $p = $fs->findById('posts', (int) ($fav['post_id'] ?? 0));
        return $p && (string) ($p['category'] ?? '') === $catFilter;
    }));
}
if ($qFilter !== '') {
    $favorites = array_values(array_filter($favorites, function ($fav) use ($qFilter, $fs) {
        $p = $fs->findById('posts', (int) ($fav['post_id'] ?? 0));
        if (!$p) { return false; }
        return mb_stripos((string) ($p['title'] ?? ''), $qFilter) !== false
            || mb_stripos((string) ($p['content'] ?? ''), $qFilter) !== false;
    }));
}
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
            'cover' => (string) ($post['images'][0] ?? ''),
            'category' => (string) ($post['category'] ?? ''),
            'category_name' => [
                'announcement' => '全站公告', 'lost_found' => '失物招领', 'study_help' => '学习互助',
                'social_chat' => '交友闲聊', 'confession' => '表白', 'school_info' => '校园资讯', 'other' => '其他',
            ][(string) ($post['category'] ?? 'other')] ?? '',
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
        ['id', 'post_id', 'title', 'cover', 'category', 'category_name', 'content', 'is_anonymous', 'author',
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