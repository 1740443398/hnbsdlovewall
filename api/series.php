<?php
/**
 * 专栏 / 连载（series）
 *
 * 商业博客与论坛的标配：把一个主题的多篇帖子组织成**有序**系列，
 * 读者可以从第一篇顺着读下去，而不是在信息流里翻找。
 *
 * GET  ?action=list                列出全部专栏（含篇数、封面、最近更新时间）
 * GET  ?action=detail&slug=xxx     专栏详情 + 有序文章列表
 * GET  ?action=of_post&post_id=N   这篇帖子属于哪些专栏（详情页展示用）
 * POST action=create|update|delete 仅作者本人或管理员
 * POST action=add_item|remove_item|reorder
 */
require_once __DIR__ . '/../config/config.php';

// 公开只读数据：专栏列表 5 分钟。
// 加共享缓存后，重复打开不再查数据文件，也不会重复跑 PHP。
lwCacheShared(300, 3600);
require_once __DIR__ . '/../includes/enhance.php';

$action = $_REQUEST['action'] ?? 'list';
$fs = getFS();

/** 取专栏的文章（按 sort 升序）。 */
function lwSeriesItems(int $seriesId): array
{
    $rows = [];
    foreach (lwEnhRead('series_items') as $r) {
        if ((int)($r['series_id'] ?? 0) === $seriesId) {
            $rows[] = $r;
        }
    }
    usort($rows, static fn($a, $b) => ((int)($a['sort'] ?? 0)) <=> ((int)($b['sort'] ?? 0)));
    return $rows;
}

if ($action === 'list') {
    $series = lwEnhRead('series');
    $out = [];
    foreach ($series as $s) {
        if (($s['status'] ?? 'published') !== 'published') {
            continue;
        }
        $items = lwSeriesItems((int)$s['id']);
        $out[] = [
            'id' => (int)$s['id'],
            'slug' => (string)($s['slug'] ?? ''),
            'title' => (string)($s['title'] ?? ''),
            'description' => (string)($s['description'] ?? ''),
            'cover' => (string)($s['cover'] ?? ''),
            'author' => (string)($s['author_name'] ?? ''),
            'count' => count($items),
            'updated_at' => (string)($s['updated_at'] ?? $s['created_at'] ?? ''),
        ];
    }
    usort($out, static fn($a, $b) => strcmp($b['updated_at'], $a['updated_at']));
    jsonSuccess(['series' => $out, 'total' => count($out)]);
}

if ($action === 'detail') {
    $slug = trim((string)($_GET['slug'] ?? ''));
    $id = (int)($_GET['id'] ?? 0);
    $found = null;
    foreach (lwEnhRead('series') as $s) {
        if (($id > 0 && (int)$s['id'] === $id) || ($slug !== '' && (string)($s['slug'] ?? '') === $slug)) {
            $found = $s;
            break;
        }
    }
    if (!$found) {
        jsonError('专栏不存在', 404);
    }
    $items = lwSeriesItems((int)$found['id']);
    $posts = [];
    foreach ($items as $it) {
        $p = $fs->findById('posts', (int)($it['post_id'] ?? 0));
        if ($p && ($p['status'] ?? 'published') === 'published') {
            $posts[] = [
                'id' => (int)$p['id'],
                'title' => (string)($p['title'] ?? ''),
                'excerpt' => mb_substr(trim((string)($p['content'] ?? '')), 0, 80),
                'created_at' => (string)($p['created_at'] ?? ''),
            ];
        }
    }
    jsonSuccess([
        'series' => [
            'id' => (int)$found['id'],
            'slug' => (string)($found['slug'] ?? ''),
            'title' => (string)($found['title'] ?? ''),
            'description' => (string)($found['description'] ?? ''),
            'cover' => (string)($found['cover'] ?? ''),
            'author' => (string)($found['author_name'] ?? ''),
        ],
        'posts' => $posts,
        'count' => count($posts),
    ]);
}

if ($action === 'of_post') {
    $pid = (int)($_GET['post_id'] ?? 0);
    $hit = [];
    foreach (lwEnhRead('series_items') as $r) {
        if ((int)($r['post_id'] ?? 0) === $pid) {
            foreach (lwEnhRead('series') as $s) {
                if ((int)$s['id'] === (int)$r['series_id']) {
                    $hit[] = ['id' => (int)$s['id'], 'title' => (string)($s['title'] ?? ''), 'slug' => (string)($s['slug'] ?? '')];
                }
            }
        }
    }
    jsonSuccess(['series' => $hit]);
}

// ---- 写操作 ----
$user = requireMember('需要注册账号');
$user = checkBanned($user);
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonError('请求方法不允许', 405);
}
if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
    jsonError('CSRF验证失败', 403);
}
$isAdmin = in_array($user['role'] ?? '', ['admin', 'super_admin'], true);

if ($action === 'create' || $action === 'update') {
    $id = (int)($_POST['id'] ?? 0);
    $title = trim((string)($_POST['title'] ?? ''));
    if ($title === '' || mb_strlen($title) > 60) {
        jsonError('标题需为 1~60 个字');
    }
    $desc = mb_substr(trim((string)($_POST['description'] ?? '')), 0, 300);
    $cover = trim((string)($_POST['cover'] ?? ''));

    if ($action === 'update') {
        $s = $fs->findById('series', $id);
        if (!$s) {
            jsonError('专栏不存在', 404);
        }
        if ((int)($s['user_id'] ?? 0) !== (int)$user['id'] && !$isAdmin) {
            jsonError('只能编辑自己的专栏', 403);
        }
        $fs->update('series', $id, [
            'title' => $title,
            'description' => $desc,
            'cover' => $cover,
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
        jsonSuccess(['id' => $id], '已保存');
    }

    // 新专栏：slug 唯一
    $slug = lwSlugify($title) ?: ('series-' . time());
    $exists = lwEnhRead('series');
    $used = array_map(static fn($s) => (string)($s['slug'] ?? ''), $exists);
    $base = $slug;
    $i = 2;
    while (in_array($slug, $used, true)) {
        $slug = $base . '-' . $i++;
    }
    lwEnhInsert('series', [
        'slug' => $slug,
        'title' => $title,
        'description' => $desc,
        'cover' => $cover,
        'user_id' => (int)$user['id'],
        'author_name' => (string)($user['nickname'] ?? ''),
        'status' => 'published',
    ]);
    $new = lwEnhRead('series');
    $last = end($new);
    jsonSuccess(['id' => (int)($last['id'] ?? 0), 'slug' => $slug], '已创建专栏');
}

if ($action === 'delete') {
    $id = (int)($_POST['id'] ?? 0);
    $s = $fs->findById('series', $id);
    if (!$s) {
        jsonError('专栏不存在', 404);
    }
    if ((int)($s['user_id'] ?? 0) !== (int)$user['id'] && !$isAdmin) {
        jsonError('只能删除自己的专栏', 403);
    }
    foreach (lwEnhRead('series_items') as $r) {
        if ((int)($r['series_id'] ?? 0) === $id) {
            $fs->delete('series_items', $r['id']);
        }
    }
    $fs->delete('series', $id);
    jsonSuccess([], '已删除');
}

if ($action === 'add_item' || $action === 'remove_item') {
    $sid = (int)($_POST['series_id'] ?? 0);
    $pid = (int)($_POST['post_id'] ?? 0);
    $s = $fs->findById('series', $sid);
    if (!$s) {
        jsonError('专栏不存在', 404);
    }
    if ((int)($s['user_id'] ?? 0) !== (int)$user['id'] && !$isAdmin) {
        jsonError('只能编辑自己的专栏', 403);
    }
    $items = lwSeriesItems($sid);
    foreach ($items as $it) {
        if ((int)($it['post_id'] ?? 0) === $pid) {
            if ($action === 'remove_item') {
                $fs->delete('series_items', $it['id']);
                jsonSuccess([], '已移出专栏');
            }
            jsonSuccess([], '已在专栏中');
        }
    }
    if ($action === 'remove_item') {
        jsonSuccess([], '本就不在专栏中');
    }
    if ($pid <= 0) {
        jsonError('帖子不存在');
    }
    lwEnhInsert('series_items', [
        'series_id' => $sid,
        'post_id' => $pid,
        'sort' => count($items) + 1,
    ]);
    $fs->update('series', $sid, ['updated_at' => date('Y-m-d H:i:s')]);
    jsonSuccess([], '已加入专栏');
}

if ($action === 'reorder') {
    $sid = (int)($_POST['series_id'] ?? 0);
    $order = $_POST['order'] ?? '';
    $ids = is_array($order) ? $order : explode(',', (string)$order);
    $ids = array_values(array_filter(array_map('intval', $ids)));
    if ($sid <= 0 || empty($ids)) {
        jsonError('参数不正确');
    }
    $s = $fs->findById('series', $sid);
    if (!$s || ((int)($s['user_id'] ?? 0) !== (int)$user['id'] && !$isAdmin)) {
        jsonError('无权操作', 403);
    }
    $map = [];
    foreach (lwSeriesItems($sid) as $it) {
        $map[(int)($it['post_id'] ?? 0)] = $it['id'];
    }
    foreach ($ids as $i => $pid) {
        if (isset($map[$pid])) {
            $fs->update('series_items', $map[$pid], ['sort' => $i + 1]);
        }
    }
    jsonSuccess([], '顺序已更新');
}

jsonError('未知操作');
