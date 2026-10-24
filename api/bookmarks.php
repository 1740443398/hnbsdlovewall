<?php
/**
 * 书签 / 收藏夹（多集合）
 *
 * 与 post_favorites 的区别：
 *   post_favorites 「点赞式收藏」—— 一个帖子要么收藏要么没有，只有一个列表。
 *   本模块是**多集合收藏夹**（像浏览器书签文件夹）：用户可以建「稍后读」「教程」
 *   「参考」等任意集合，同一帖子可进多个集合。这是商业博客/论坛的标配。
 *
 * GET  ?action=list                     列出我的集合（含每个集合的数量）
 * GET  ?action=items&id=N               某集合里的帖子
 * GET  ?action=all                      全部书签（不分集合）
 * POST action=create&name=X             新建集合
 * POST action=rename&id=N&name=X        重命名
 * POST action=delete&id=N               删除集合（书签本身保留）
 * POST action=add&post_id=N&collection=M 把帖子加入集合（M 为空则进默认集合）
 * POST action=remove&post_id=N&collection=M 移出
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/enhance.php';

$user = requireMember('书签功能需要注册账号');
$user = checkBanned($user);
if (!empty($user['is_banned'])) {
    jsonError('账号已被封禁');
}

$uid = (int)$user['id'];
$action = $_REQUEST['action'] ?? 'list';

/** 取我的全部书签行。 */
function lwMyBookmarks(int $uid): array
{
    $out = [];
    foreach (lwEnhRead('bookmarks') as $r) {
        if ((int)($r['user_id'] ?? 0) === $uid) {
            $out[] = $r;
        }
    }
    return $out;
}

if ($action === 'list') {
    $mine = lwMyBookmarks($uid);
    // 按集合聚合
    $byCol = [];
    foreach ($mine as $r) {
        $c = (string)($r['collection'] ?? '默认');
        $byCol[$c] = ($byCol[$c] ?? 0) + 1;
    }
    $cols = [];
    foreach ($byCol as $name => $count) {
        $cols[] = ['name' => $name, 'count' => $count];
    }
    // 默认集合永远排第一
    usort($cols, static function ($a, $b) {
        if ($a['name'] === '默认') return -1;
        if ($b['name'] === '默认') return 1;
        return strcmp($a['name'], $b['name']);
    });
    jsonSuccess(['collections' => $cols, 'total' => count($mine)]);
}

if ($action === 'items' || $action === 'all') {
    $col = $_GET['id'] ?? '';
    $mine = lwMyBookmarks($uid);
    $ids = [];
    foreach ($mine as $r) {
        if ($action === 'all' || (string)($r['collection'] ?? '默认') === (string)$col) {
            $ids[] = (int)($r['post_id'] ?? 0);
        }
    }
    $ids = array_values(array_unique(array_filter($ids)));
    $fs = getFS();
    $posts = [];
    foreach ($ids as $pid) {
        $p = $fs->findById('posts', $pid);
        if ($p && ($p['status'] ?? 'published') === 'published') {
            $posts[] = $p;
        }
    }
    // 保持「最近加入的在前」
    usort($posts, static fn($a, $b) => ((int)($b['id'] ?? 0)) <=> ((int)($a['id'] ?? 0)));
    jsonSuccess(['posts' => lwPostListPayload($posts), 'count' => count($posts)]);
}

// 以下都是写操作
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonError('请求方法不允许', 405);
}
if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
    jsonError('CSRF验证失败', 403);
}

$fs = getFS();

if ($action === 'create') {
    $name = trim((string)($_POST['name'] ?? ''));
    if ($name === '' || mb_strlen($name) > 24) {
        jsonError('集合名称需为 1~24 个字');
    }
    $mine = lwMyBookmarks($uid);
    $cols = array_unique(array_map(static fn($r) => (string)($r['collection'] ?? '默认'), $mine));
    if (in_array($name, $cols, true)) {
        jsonError('同名集合已存在');
    }
    if (count($cols) >= 20) {
        jsonError('最多 20 个集合');
    }
    // 用一个哨兵行占位，保证空集合也能出现在列表里
    lwEnhInsert('bookmarks', [
        'user_id' => $uid,
        'post_id' => 0,
        'collection' => $name,
    ]);
    jsonSuccess(['name' => $name], '已创建');
}

if ($action === 'rename') {
    $old = trim((string)($_POST['id'] ?? ''));
    $new = trim((string)($_POST['name'] ?? ''));
    if ($old === '' || $new === '' || mb_strlen($new) > 24) {
        jsonError('参数不正确');
    }
    $n = 0;
    foreach (lwMyBookmarks($uid) as $r) {
        if ((string)($r['collection'] ?? '默认') === $old) {
            $fs->update('bookmarks', $r['id'], ['collection' => $new]);
            $n++;
        }
    }
    jsonSuccess(['moved' => $n], '已重命名');
}

if ($action === 'delete') {
    $col = trim((string)($_POST['id'] ?? ''));
    if ($col === '默认') {
        jsonError('默认集合不能删除');
    }
    $n = 0;
    foreach (lwMyBookmarks($uid) as $r) {
        if ((string)($r['collection'] ?? '默认') === $col) {
            $fs->delete('bookmarks', $r['id']);
            $n++;
        }
    }
    jsonSuccess(['removed' => $n], '已删除集合');
}

if ($action === 'add' || $action === 'remove') {
    $pid = (int)($_POST['post_id'] ?? 0);
    $col = trim((string)($_POST['collection'] ?? '默认')) ?: '默认';
    if ($pid <= 0) {
        jsonError('帖子不存在');
    }
    $mine = lwMyBookmarks($uid);
    foreach ($mine as $r) {
        if ((int)($r['post_id'] ?? 0) === $pid && (string)($r['collection'] ?? '默认') === $col) {
            if ($action === 'remove') {
                $fs->delete('bookmarks', $r['id']);
                jsonSuccess(['bookmarked' => false], '已移出');
            }
            jsonSuccess(['bookmarked' => true], '已在集合中');
        }
    }
    if ($action === 'remove') {
        jsonSuccess(['bookmarked' => false], '本就不在集合中');
    }
    lwEnhInsert('bookmarks', [
        'user_id' => $uid,
        'post_id' => $pid,
        'collection' => $col,
    ]);
    jsonSuccess(['bookmarked' => true], '已加入「' . $col . '」');
}

jsonError('未知操作');
