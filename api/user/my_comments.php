<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/fields.php';

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

$qFilter = trim(sanitizeInput($_REQUEST['q'] ?? ''));
$page = max(1, intval($_REQUEST['page'] ?? 1));
$limit = 10;
if ($qFilter !== '') {
    $__inPost = [];
    foreach ((array) $fs->read('posts') as $__p) { $__inPost[(int)($__p['id'] ?? 0)] = (string)($__p['title'] ?? ''); }
    $comments = array_values(array_filter($comments, function ($cm) use ($qFilter, $__inPost) {
        return mb_stripos((string) ($cm['content'] ?? ''), $qFilter) !== false
            || mb_stripos($__inPost[(int) ($cm['post_id'] ?? 0)] ?? '', $qFilter) !== false;
    }));
}
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

// 字段投影（E14）：不传 fields/top 时与改造前完全一致
jsonSuccess(lwProjectTop([
    'comments' => lwProjectRows(
        $result,
        lwFieldsRequest(),
        ['id', 'post_id', 'post_title', 'content', 'timeAgo', 'created_at'],
        ['id', 'post_id']
    ),
    'pagination' => [
        'total' => $total,
        'page' => $page,
        'limit' => $limit,
        'total_pages' => ceil($total / $limit)
    ]
], lwTopRequest(), []));