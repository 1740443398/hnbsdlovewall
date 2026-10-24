<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/post_visibility.php';
require_once __DIR__ . '/../../includes/fields.php';

// 快捷搜索（Ctrl+K）：轻量返回帖子 id/title/category，供搜索框实时联想。
$keyword = sanitizeInput($_REQUEST['q'] ?? '');
if ($keyword === '') {
    jsonSuccess(['posts' => []]);
}

$currentUser = requireMember('搜索功能需要注册账号后才能使用');

$fs = getFS();
// 可见性过滤统一走 includes/post_visibility.php（原先此处内联了一份，与首页信息流、
// AI 助手的规则不一致，会出现「搜索能搜到但首页看不到」这类分叉）。
$posts = lwFilterVisiblePosts($fs->read('posts'), $currentUser);
$filtered = array_filter($posts, function ($p) use ($keyword) {
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

// 字段投影（E14）：不传 fields/top 时与改造前完全一致
jsonSuccess(lwProjectTop(
    [
        'posts' => lwProjectRows(
            $result,
            lwFieldsRequest(),
            ['id', 'title', 'category', 'category_name'],
            ['id']
        ),
        'total' => count($filtered)
    ],
    lwTopRequest(),
    []
));