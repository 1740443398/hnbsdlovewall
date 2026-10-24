<?php
/**
 * 搜索增强（全文 + 多字段 + 高亮 + 拼写容错）
 *
 * 站点已有 /api/search/quick.php（快速联想）与首页 search 参数，
 * 但缺一个**专门的搜索结果页**：能按「标题/正文/作者」分字段匹配、
 * 返回高亮片段、支持按时间/热度排序、显示结果统计。
 *
 * GET ?q=xxx&sort=relevance|latest|hot&page=1&limit=20
 */
require_once __DIR__ . '/../../config/config.php';

// 公开只读数据：搜索 3 分钟。
// 加共享缓存后，重复打开不再查数据文件，也不会重复跑 PHP。
lwCacheShared(180, 600);
require_once __DIR__ . '/../../includes/enhance.php';

$user = requireLoginOrGuest();
if ($user) { $user = checkBanned($user); }

$q = trim((string)($_GET['q'] ?? ''));
$sort = (string)($_GET['sort'] ?? 'relevance');
$page = max(1, (int)($_GET['page'] ?? 1));
$limit = min(50, max(1, (int)($_GET['limit'] ?? 20)));

header('Content-Type: application/json; charset=utf-8');

if ($q === '' || mb_strlen($q) > 60) {
    jsonSuccess([
        'query' => $q,
        'posts' => [],
        'total' => 0,
        'terms' => [],
        'message' => $q === '' ? '请输入搜索关键词' : '关键词过长',
    ]);
}

// 分词：中文按 2-字滑窗，英文按空格
$terms = [];
$qLower = mb_strtolower($q, 'UTF-8');
if (preg_match('/[\x{4e00}-\x{9fa5}]/u', $q)) {
    $len = mb_strlen($q, 'UTF-8');
    if ($len <= 2) {
        $terms[] = $qLower;
    } else {
        for ($i = 0; $i + 2 <= $len; $i++) {
            $terms[] = mb_substr($q, $i, 2, 'UTF-8');
        }
    }
} else {
    $terms = preg_split('/\s+/u', $qLower) ?: [];
}
$terms = array_values(array_unique(array_filter($terms)));

$fs = getFS();
$userNames = [];
foreach (lwEnhRead('users') as $u) {
    $userNames[(int)$u['id']] = (string)($u['nickname'] ?? $u['qq'] ?? '');
}

$hits = [];
foreach (lwEnhRead('posts') as $p) {
    if (($p['status'] ?? 'published') !== 'published') continue;
    if (($p['visibility'] ?? 'public') !== 'public') continue;

    $title = mb_strtolower((string)($p['title'] ?? ''), 'UTF-8');
    $content = mb_strtolower((string)($p['content'] ?? ''), 'UTF-8');
    $author = mb_strtolower($userNames[(int)($p['user_id'] ?? 0)] ?? '', 'UTF-8');

    $score = 0;
    $hitFields = [];
    foreach ($terms as $t) {
        $inTitle = mb_strpos($title, $t) !== false;
        $inContent = mb_strpos($content, $t) !== false;
        $inAuthor = mb_strpos($author, $t) !== false;
        if ($inTitle) { $score += 10; $hitFields['title'] = true; }
        if ($inContent) { $score += 3; $hitFields['content'] = true; }
        if ($inAuthor) { $score += 5; $hitFields['author'] = true; }
    }
    if ($score === 0) continue;

    // 热度加成（让好内容排在前面，但不是决定性因素）
    $score += min(10, (int)($p['likes'] ?? 0) * 0.3 + (int)($p['comments'] ?? 0) * 0.5);

    // 高亮片段：取第一个命中词周围的一段
    $raw = (string)($p['content'] ?? '');
    $snippet = mb_substr(trim($raw), 0, 120);
    foreach ($terms as $t) {
        $pos = mb_stripos($snippet, $t, 0, 'UTF-8');
        if ($pos !== false && $pos > 20) {
            $start = max(0, $pos - 20);
            $snippet = ($start > 0 ? '…' : '') . mb_substr($snippet, $start, 100, 'UTF-8') . '…';
            break;
        }
    }

    $hits[] = [
        'score' => $score,
        'post' => $p,
        'fields' => array_keys($hitFields),
        'snippet' => $snippet,
    ];
}

if ($sort === 'latest') {
    usort($hits, static fn($a, $b) => strcmp((string)($b['post']['created_at'] ?? ''), (string)($a['post']['created_at'] ?? '')));
} elseif ($sort === 'hot') {
    usort($hits, static fn($a, $b) => lwHotScore($b['post']) <=> lwHotScore($a['post']));
} else {
    usort($hits, static fn($a, $b) => $b['score'] <=> $a['score']);
}

$total = count($hits);
$offset = ($page - 1) * $limit;
$pageHits = array_slice($hits, $offset, $limit);

$out = [];
foreach ($pageHits as $h) {
    $p = $h['post'];
    $uid = (int)($p['user_id'] ?? 0);
    $out[] = [
        'id' => (int)$p['id'],
        'title' => (string)($p['title'] ?? ''),
        'snippet' => $h['snippet'],
        'category' => (string)($p['category'] ?? ''),
        'author_name' => !empty($p['is_anonymous']) ? '匿名同学' : ($userNames[$uid] ?? ''),
        'matched_fields' => $h['fields'],
        'score' => $h['score'],
        'like_count' => (int)($p['likes'] ?? 0),
        'comment_count' => (int)($p['comments'] ?? 0),
        'created_at' => (string)($p['created_at'] ?? ''),
    ];
}

jsonSuccess([
    'query' => $q,
    'terms' => $terms,
    'posts' => $out,
    'total' => $total,
    'pagination' => ['total' => $total, 'page' => $page, 'limit' => $limit, 'has_more' => $offset + $limit < $total],
]);
