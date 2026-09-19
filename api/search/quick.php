<?php
require_once __DIR__ . '/../../config/config.php';

// 快捷搜索（Ctrl+K）：轻量返回帖子 id/title/category，供搜索框实时联想。
$keyword = sanitizeInput($_REQUEST['q'] ?? '');
if ($keyword === '') {
    jsonSuccess(['posts' => []]);
}

$fs = getFS();
$posts = $fs->read('posts');

$currentUser = getCurrentUser();
$filtered = array_filter($posts, function($p) use ($keyword, $currentUser) {
    if (($p['status'] ?? 'published') !== 'published') return false;

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

    $searchText = ($p['title'] ?? '') . ' ' . ($p['content'] ?? '');
    return mb_stripos($searchText, $keyword) !== false;
});

$filtered = array_values($filtered);
usort($filtered, function($a, $b) {
    return strtotime($b['created_at'] ?? '2000-01-01') - strtotime($a['created_at'] ?? '2000-01-01');
});

$limit = 8;
$result = [];
foreach (array_slice($filtered, 0, $limit) as $p) {
    $result[] = [
        'id' => $p['id'],
        'title' => $p['title'] ?? '',
        'category' => $p['category'] ?? '',
        'category_name' => [
            'announcement' => '全站公告', 'lost_found' => '寻物/失物招领',
            'study_help' => '学习求助', 'social_chat' => '交友闲聊',
            'confession' => '表白', 'school_info' => '校园打听', 'other' => '其他',
        ][$p['category'] ?? 'other'] ?? $p['category'] ?? ''
    ];
}

jsonSuccess(['posts' => $result, 'total' => count($filtered)]);
