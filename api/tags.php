<?php
/**
 * 标签系统（tags）
 *
 * GET  ?action=cloud&limit=50     标签云（按使用次数降序）
 * GET  ?action=posts&tag=xxx      某标签下的帖子
 * GET  ?action=of_post&post_id=N  某帖的标签
 * GET  ?action=related&tag=xxx    相关标签（同现矩阵，轻量版）
 *
 * 标签在发帖时由 lwExtractTags() 自动抽取（见 includes/enhance.php），
 * 也支持作者手填。这里只负责读与聚合。
 */
require_once __DIR__ . '/../config/config.php';

// 公开只读数据：标签云 5 分钟。
// 加共享缓存后，重复打开不再查数据文件，也不会重复跑 PHP。
lwCacheShared(300, 3600);
require_once __DIR__ . '/../includes/enhance.php';

$action = $_REQUEST['action'] ?? 'cloud';
$fs = getFS();

/** 全部帖子（已发布） */
function lwAllPublicPosts(): array
{
    $out = [];
    foreach (lwEnhRead('posts') as $p) {
        if (($p['status'] ?? 'published') !== 'published') continue;
        if (($p['visibility'] ?? 'public') !== 'public') continue;
        $out[] = $p;
    }
    return $out;
}

if ($action === 'cloud') {
    $limit = min(200, max(1, (int)($_GET['limit'] ?? 50)));
    $count = [];
    $lastUsed = [];
    foreach (lwAllPublicPosts() as $p) {
        foreach (lwExtractTags($p) as $t) {
            $k = lwSlugify($t);
            $count[$k] = ($count[$k] ?? 0) + 1;
            $ts = strtotime((string)($p['created_at'] ?? 'now')) ?: 0;
            if ($ts > ($lastUsed[$k] ?? 0)) $lastUsed[$k] = $ts;
        }
    }
    arsort($count);
    $out = [];
    foreach (array_slice($count, 0, $limit, true) as $slug => $n) {
        $out[] = ['slug' => $slug, 'count' => $n, 'last_used' => $lastUsed[$slug] ?? 0];
    }
    jsonSuccess(['tags' => $out, 'total' => count($count)]);
}

if ($action === 'posts') {
    $tag = lwSlugify((string)($_GET['tag'] ?? ''));
    if ($tag === '') jsonError('请指定标签');
    $hit = [];
    foreach (lwAllPublicPosts() as $p) {
        foreach (lwExtractTags($p) as $t) {
            if (lwSlugify($t) === $tag) { $hit[] = $p; break; }
        }
    }
    usort($hit, static fn($a, $b) => ((int)($b['id'] ?? 0)) <=> ((int)($a['id'] ?? 0)));
    jsonSuccess(['tag' => $tag, 'posts' => lwPostListPayload($hit), 'count' => count($hit)]);
}

if ($action === 'of_post') {
    $pid = (int)($_GET['post_id'] ?? 0);
    $p = $fs->findById('posts', $pid);
    if (!$p) jsonError('帖子不存在', 404);
    $tags = [];
    foreach (lwExtractTags($p) as $t) {
        $tags[] = ['name' => $t, 'slug' => lwSlugify($t)];
    }
    jsonSuccess(['tags' => $tags]);
}

if ($action === 'related') {
    $tag = lwSlugify((string)($_GET['tag'] ?? ''));
    if ($tag === '') jsonError('请指定标签');
    // 共现统计：同一帖里与该标签一起出现的其它标签
    $co = [];
    foreach (lwAllPublicPosts() as $p) {
        $ts = array_map('lwSlugify', lwExtractTags($p));
        if (!in_array($tag, $ts, true)) continue;
        foreach ($ts as $t) {
            if ($t !== $tag) $co[$t] = ($co[$t] ?? 0) + 1;
        }
    }
    arsort($co);
    $out = [];
    foreach (array_slice($co, 0, 10, true) as $slug => $n) {
        $out[] = ['slug' => $slug, 'count' => $n];
    }
    jsonSuccess(['tag' => $tag, 'related' => $out]);
}

jsonError('未知操作');
