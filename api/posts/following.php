<?php
/**
 * 关注动态：当前用户关注的作者发布的帖子（分页）。
 * GET /api/posts/following.php?page=1&limit=10
 * 需登录；返回结构与 /api/posts/list.php 兼容（posts/total/page/has_more）。
 */
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/post_visibility.php';
require_once __DIR__ . '/../../includes/translate.php';
require_once __DIR__ . '/../../includes/fields.php';

$user = requireLogin();
$user = checkBanned($user);

$page = max(1, (int) ($_GET['page'] ?? 1));
$limit = min(50, max(1, (int) ($_GET['limit'] ?? 10)));
$offset = ($page - 1) * $limit;

$fs = getFS();
$rows = $fs->find('follows', ['user_id' => (int) $user['id']]);
$ids = [];
foreach ((array) $rows as $r) {
    $tid = (int) ($r['target_id'] ?? 0);
    if ($tid > 0) { $ids[$tid] = true; }
}
if (empty($ids)) {
    jsonSuccess(['posts' => [], 'total' => 0, 'page' => $page, 'has_more' => false, 'following_count' => 0]);
}

$posts = [];
foreach ((array) $fs->read('posts') as $p) {
    if (!isset($ids[(int) ($p['user_id'] ?? 0)])) { continue; }
    $posts[] = $p;
}
$posts = lwFilterVisiblePosts($posts, $user);
usort($posts, function ($a, $b) {
    return strtotime((string) ($b['created_at'] ?? '')) - strtotime((string) ($a['created_at'] ?? ''));
});

$total = count($posts);
$pagePosts = array_slice($posts, $offset, $limit);

$names = [];
foreach ((array) $fs->read('users') as $u) {
    $names[(int) $u['id']] = (string) ($u['nickname'] ?? $u['username'] ?? '');
}

$out = [];
foreach ($pagePosts as $p) {
    $out[] = [
        'id' => (int) $p['id'],
        'title' => (string) ($p['title'] ?? ''),
        'content' => mb_substr((string) ($p['content'] ?? ''), 0, 120, 'UTF-8'),
        'category' => (string) ($p['category'] ?? ''),
        'author_name' => !empty($p['is_anonymous']) ? '匿名同学' : ($names[(int) ($p['user_id'] ?? 0)] ?? ''),
        'like_count' => (int) ($p['likes'] ?? 0),
        'comment_count' => (int) ($p['comments'] ?? 0),
        'created_at' => (string) ($p['created_at'] ?? ''),
    ];
}

jsonSuccess([
    'posts' => $out,
    'total' => $total,
    'page' => $page,
    'has_more' => $offset + $limit < $total,
    'following_count' => count($ids),
]);
