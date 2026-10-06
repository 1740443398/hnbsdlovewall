<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/post_visibility.php';
require_once __DIR__ . '/../../includes/text_linkify.php';

/**
 * 话题联想（C25）：返回站内高频话题，供发帖/评论框输入 `#` 时即时提示。
 *
 * 为什么单独成一个接口：话题是**从帖子正文里现算**的（没有独立的话题表），
 * 全站唯一真源是 includes/text_linkify.php 的 lwExtractTags()——话题页、排行榜、
 * AI 站点数据都走它。这里复用同一个函数，保证「输入时提示出来的」和
 * 「正文渲染成链接的」「能点进去的」永远是同一批话题。
 *
 * 可见性：与首页信息流一致（走 post_visibility.php），私密/未审核帖子里的话题不会外泄。
 */
$keyword = sanitizeInput($_REQUEST['q'] ?? '');
$currentUser = requireMember('话题联想需要注册账号后才能使用');

$fs = getFS();
$posts = lwFilterVisiblePosts($fs->read('posts'), $currentUser);

$counts = [];
foreach ($posts as $p) {
    $raw = ($p['title'] ?? '') . "\n" . ($p['content'] ?? '');
    foreach (lwExtractTags($raw) as $tag => $n) {
        $counts[$tag] = ($counts[$tag] ?? 0) + $n;
    }
}

if ($keyword !== '') {
    $kw = mb_strtolower($keyword);
    $counts = array_filter($counts, function ($tag) use ($kw) {
        return mb_stripos((string) $tag, $kw) !== false;
    }, ARRAY_FILTER_USE_KEY);
}

arsort($counts);

$limit = min(20, max(1, intval($_REQUEST['limit'] ?? 8)));
$tags = [];
foreach (array_slice($counts, 0, $limit, true) as $tag => $n) {
    $tags[] = ['tag' => (string) $tag, 'count' => (int) $n];
}

jsonSuccess(['tags' => $tags, 'total' => count($tags)]);