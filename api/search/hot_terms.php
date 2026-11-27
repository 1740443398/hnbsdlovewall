<?php
/**
 * 搜索热门词：从近期帖子的分类分布 + 标题高频二字词生成。
 * GET /api/search/hot_terms.php?limit=6
 * 只读公开数据，可缓存 10 分钟。
 */
require_once __DIR__ . '/../../config/config.php';
lwCacheShared(600, 1200);
require_once __DIR__ . '/../../includes/enhance.php';

$user = requireLoginOrGuest();
if ($user) { $user = checkBanned($user); }

$limit = min(12, max(3, (int) ($_GET['limit'] ?? 6)));

$catNames = [
    'lost_found' => '失物招领', 'study_help' => '学习互助', 'social_chat' => '交友闲聊',
    'confession' => '表白', 'school_info' => '校园资讯', 'other' => '二手交易',
];

$posts = array_values(array_filter((array) lwEnhRead('posts'), function ($p) {
    return (string) ($p['status'] ?? 'published') === 'published';
}));
usort($posts, function ($a, $b) {
    return strtotime((string) ($b['created_at'] ?? '')) - strtotime((string) ($a['created_at'] ?? ''));
});
$recent = array_slice($posts, 0, 300);

$catCount = [];
$wordCount = [];
$stop = ['的了', '了吗', '我们', '你们', '他们', '一个', '这个', '那个', '什么', '怎么', '可以', '就是', '不是', '没有', '现在', '今天', '大家', '自己', '学校', '同学'];
foreach ($recent as $p) {
    $c = (string) ($p['category'] ?? '');
    if (isset($catNames[$c])) { $catCount[$c] = ($catCount[$c] ?? 0) + 1; }
    $title = trim((string) ($p['title'] ?? ''));
    if ($title === '') { continue; }
    $len = mb_strlen($title, 'UTF-8');
    for ($i = 0; $i + 2 <= $len && $i < 20; $i++) {
        $w = mb_substr($title, $i, 2, 'UTF-8');
        if (!preg_match('/^[\x{4e00}-\x{9fa5}]{2}$/u', $w)) { continue; }
        if (in_array($w, $stop, true)) { continue; }
        $wordCount[$w] = ($wordCount[$w] ?? 0) + 1;
    }
}
arsort($catCount); arsort($wordCount);

$out = [];
foreach (array_slice($catCount, 0, 3, true) as $c => $n) { $out[] = $catNames[$c]; }
foreach ($wordCount as $w => $n) {
    if (count($out) >= $limit) { break; }
    if ($n < 2) { break; }
    if (!in_array($w, $out, true)) { $out[] = $w; }
}
if (count($out) < $limit) {
    foreach (['失物招领', '学习互助', '交友闲聊', '表白', '校园资讯', '二手交易'] as $w) {
        if (!in_array($w, $out, true)) { $out[] = $w; }
        if (count($out) >= $limit) { break; }
    }
}
jsonSuccess(['terms' => array_values(array_slice($out, 0, $limit)), 'source' => 'recent_posts', 'sampled' => count($recent)]);
