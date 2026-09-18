<?php
require_once __DIR__ . '/../../config/config.php';

$keyword = sanitizeInput($_REQUEST['keyword'] ?? '');
$page = max(1, intval($_REQUEST['page'] ?? 1));
$limit = 10;
$offset = ($page - 1) * $limit;

if (mb_strlen($keyword) < 2) {
    jsonError('搜索关键词至少2个字符');
}

$fs = getFS();
$posts = $fs->read('posts');

$currentUser = getCurrentUser();
$filtered = array_filter($posts, function($p) use ($keyword, $currentUser) {
    if ($p['status'] !== 'published') return false;

    $isAdmin = $currentUser && in_array($currentUser['role'], ['admin', 'super_admin']);
    $isAuthor = $currentUser && $currentUser['id'] == ($p['user_id'] ?? 0);
    if (!$isAdmin && !$isAuthor) {
        $vis = $p['visibility'] ?? 'public';
        if ($vis === 'visible_to') {
            $allowedQQs = array_map('trim', explode(',', $p['visible_to'] ?? ''));
            if (!$currentUser || !in_array($currentUser['qq'], $allowedQQs)) return false;
        } elseif ($vis === 'exclude_to') {
            $excludedQQs = array_map('trim', explode(',', $p['exclude_to'] ?? ''));
            if ($currentUser && in_array($currentUser['qq'], $excludedQQs)) return false;
        }
    }

    $searchText = $p['title'] . ' ' . $p['content'];
    return stripos($searchText, $keyword) !== false;
});

$filtered = array_values($filtered);
usort($filtered, function($a, $b) {
    return strtotime($b['created_at']) - strtotime($a['created_at']);
});

$total = count($filtered);
$posts = array_slice($filtered, $offset, $limit);

$result = [];
foreach ($posts as $post) {
    $isAnonymous = !empty($post['is_anonymous']);
    $postUser = $isAnonymous ? null : $fs->findById('users', $post['user_id']);

    $result[] = [
        'id' => $post['id'],
        'category' => $post['category'],
        'title' => $post['title'],
        'content' => $post['content'],
        'is_anonymous' => $isAnonymous,
        'author_nickname' => $isAnonymous ? '匿名用户' : ($postUser['nickname'] ?? ''),
        'author_avatar' => $isAnonymous ? '/assets/images/default-avatar.svg' : ($postUser['avatar'] ?? ''),
        'likes' => $post['likes'] ?? 0,
        'comments' => $post['comments'] ?? 0,
        'created_at' => $post['created_at']
    ];
}

jsonSuccess([
    'posts' => $result,
    'total' => $total,
    'page' => $page,
    'limit' => $limit,
    'has_more' => $offset + $limit < $total
]);