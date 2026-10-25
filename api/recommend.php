<?php
/**
 * 推荐 + 热门（recommend）
 *
 * GET ?action=for_you&limit=6    个性化推荐（依据浏览/点赞/收藏的标签偏好）
 * GET ?action=hot&limit=10       热度榜（时间衰减 + 互动加权）
 * GET ?action=recent&limit=10    最近浏览（我读过的）
 * GET ?action=trending_tags&limit=8  上升最快的标签
 *
 * 算法说明见 includes/enhance.php 的 lwRecommend / lwHotScore。
 * 这些都是公开可缓存的只读接口（按登录态区分）。
 */
require_once __DIR__ . '/../config/config.php';

// 公开只读数据：推荐/热门 2 分钟。
// 加共享缓存后，重复打开不再查数据文件，也不会重复跑 PHP。
lwCacheShared(120, 600);
require_once __DIR__ . '/../includes/enhance.php';

$action = $_REQUEST['action'] ?? 'hot';
$limit = min(30, max(1, (int)($_GET['limit'] ?? 6)));
$user = getCurrentUser();

$fs = getFS();
$all = [];
foreach (lwEnhRead('posts') as $p) {
    if (($p['status'] ?? 'published') !== 'published') continue;
    if (($p['visibility'] ?? 'public') !== 'public') continue;
    $all[] = $p;
}

if ($action === 'for_you') {
    $picked = lwRecommend($all, $user, $limit);
    jsonSuccess([
        'posts' => lwPostListPayload($picked),
        'personalized' => $user !== null,
        'count' => count($picked),
    ]);
}

if ($action === 'hot') {
    $sorted = lwSortByHot($all);
    jsonSuccess([
        'posts' => lwPostListPayload(array_slice($sorted, 0, $limit)),
        'count' => min($limit, count($sorted)),
    ]);
}

if ($action === 'recent') {
    if ($user === null) {
        jsonSuccess(['posts' => [], 'needLogin' => true]);
    }
    $uid = (int)$user['id'];
    $rows = lwEnhRead('view_log', ['user_id' => $uid]);
    usort($rows, static fn($a, $b) => ((int)($b['viewed_at'] ?? 0)) <=> ((int)($a['viewed_at'] ?? 0)));
    $seen = [];
    $out = [];
    foreach ($rows as $r) {
        $pid = (int)($r['post_id'] ?? 0);
        if ($pid <= 0 || isset($seen[$pid])) continue;
        $seen[$pid] = true;
        $p = $fs->findById('posts', $pid);
        if ($p && ($p['status'] ?? 'published') === 'published') {
            $out[] = $p;
        }
        if (count($out) >= $limit) break;
    }
    jsonSuccess(['posts' => lwPostListPayload($out), 'count' => count($out)]);
}

if ($action === 'trending_tags') {
    // 近 7 天 vs 前 7 天，算增长率
    $now = time();
    $week = 7 * 86400;
    $recent = [];
    $prev = [];
    foreach ($all as $p) {
        $ts = strtotime((string)($p['created_at'] ?? 'now')) ?: 0;
        $ts_ = array_map('lwSlugify', lwExtractTags($p));
        if ($ts > $now - $week) {
            foreach ($ts_ as $t) $recent[$t] = ($recent[$t] ?? 0) + 1;
        } elseif ($ts > $now - 2 * $week) {
            foreach ($ts_ as $t) $prev[$t] = ($prev[$t] ?? 0) + 1;
        }
    }
    $growth = [];
    foreach ($recent as $t => $n) {
        $base = $prev[$t] ?? 0;
        // 新增标签（上期 0）给个高增长率但要标注，避免「只出现一次」的标签霸榜
        $g = $base > 0 ? ($n / $base) : ($n >= 2 ? 5.0 : 0.5);
        $growth[$t] = ['slug' => $t, 'count' => $n, 'prev' => $base, 'growth' => round($g, 2)];
    }
    usort($growth, static fn($a, $b) => $b['growth'] <=> $a['growth']);
    jsonSuccess(['tags' => array_slice(array_values($growth), 0, $limit)]);
}

jsonError('未知操作');
