<?php
/**
 * 相关推荐：同分类 + 共享话题标签 + 热度加权，最多 6 条。
 * GET /api/posts/related.php?id=123&limit=6
 * 只读公开数据；可见性过滤与首页/搜索共用 post_visibility.php。
 */
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/post_visibility.php';
require_once __DIR__ . '/../../includes/enhance.php';
require_once __DIR__ . '/../../includes/fields.php';

$user = requireLoginOrGuest();
if ($user) { $user = checkBanned($user); }

$id = (int) ($_GET['id'] ?? 0);
$limit = min(6, max(3, (int) ($_GET['limit'] ?? 4)));
if ($id <= 0) { jsonError('参数无效', 400); }

$fs = getFS();
$me = $fs->findById('posts', $id);
if (!$me) { jsonError('帖子不存在', 404); }

$baseTags = array_keys((array) lwExtractTags((string)($me['title'] ?? '') . "\n" . (string)($me['content'] ?? '')));
$baseCat = (string) ($me['category'] ?? '');

$cands = [];
foreach (lwFilterVisiblePosts($fs->read('posts'), $user) as $p) {
    if ((int) ($p['id'] ?? 0) === $id) { continue; }
    if ((string) ($p['status'] ?? 'published') !== 'published') { continue; }
    if (!empty($p['is_anonymous'])) { /* 仍可推荐，仅导出标题 */ }
    $score = 0;
    if ($baseCat !== '' && (string) ($p['category'] ?? '') === $baseCat) { $score += 2; }
    $sameAuthor = (int) ($p['user_id'] ?? 0) === (int) ($me['user_id'] ?? 0) && empty($p['is_anonymous']) && empty($me['is_anonymous']);
    if ($sameAuthor) { $score += 2; }
    $pTags = array_keys((array) lwExtractTags((string)($p['title'] ?? '') . "\n" . (string)($p['content'] ?? '')));
    $shared = count(array_intersect($baseTags, $pTags));
    $score += $shared * 3;
    if ($score <= 0) { continue; }
    $score += min(3, ((int) ($p['likes'] ?? 0)) / 50) + min(3, ((int) ($p['comments'] ?? 0)) / 20);
    $cands[] = ['score' => $score, 'p' => $p, 'same_author' => $sameAuthor];
}
usort($cands, function ($a, $b) { return $b['score'] <=> $a['score']; });

$names = [];
foreach ((array) $fs->read('users') as $u) { $names[(int) $u['id']] = (string) ($u['nickname'] ?? ''); }

$out = [];
foreach (array_slice($cands, 0, $limit) as $c) {
    $p = $c['p'];
    $out[] = [
        'id' => (int) $p['id'],
        'title' => (string) ($p['title'] ?? ''),
        'category' => (string) ($p['category'] ?? ''),
        'category_name' => [
            'announcement' => '全站公告', 'lost_found' => '失物招领', 'study_help' => '学习互助',
            'social_chat' => '交友闲聊', 'confession' => '表白', 'school_info' => '校园资讯', 'other' => '其他',
        ][(string) ($p['category'] ?? 'other')] ?? '',
        'author_name' => !empty($p['is_anonymous']) ? '匿名同学' : ($names[(int) ($p['user_id'] ?? 0)] ?? ''),
        'like_count' => (int) ($p['likes'] ?? 0),
        'comment_count' => (int) ($p['comments'] ?? 0),
        'same_author' => !empty($c['same_author']),
    ];
}

jsonSuccess(['posts' => lwProjectRows($out, lwFieldsRequest(), ['id','title','category','category_name','author_name','like_count','comment_count','same_author'], ['id'])]);
