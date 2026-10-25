<?php
/**
 * 后台：内容审核队列
 *
 * 商业社区与普通论坛最大的差别就在「审核闭环」：
 * 举报进来后必须有人处理、有结论、有记录、能追踪。本站原先只有「举报列表」，
 * 缺一个把「待处理 → 处理中 → 已处理」串起来的队列视图。
 *
 * GET  ?action=list&status=pending&type=all&page=1
 * GET  ?action=stats
 * POST action=assign&id=N        认领（把处理人设为自己）
 * POST action=resolve&id=N&result=deleted|ignored|warned
 * POST action=batch&ids=1,2,3&result=ignored
 */
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/security.php';

header('Content-Type: application/json; charset=utf-8');

$admin = requireAdmin();
$admin = checkBanned($admin);
if (!empty($admin['is_banned'])) {
    jsonError('账号已被封禁');
}
if (!checkPermission($admin, 'view_reports')) {
    jsonError('没有审核权限', 403);
}

$fs = getFS();
$action = $_REQUEST['action'] ?? 'list';

/** 所有举报（含已处理），同一份表加状态字段。 */
function lwAllReports(): array
{
    return lwSafeRead('reports');
}

function lwSafeRead(string $t): array
{
    try {
        return getFS()->read($t);
    } catch (Throwable $e) {
        return [];
    }
}

if ($action === 'stats') {
    $rows = lwAllReports();
    $pending = 0;
    $handled = 0;
    $today = 0;
    $todayStr = date('Y-m-d');
    foreach ($rows as $r) {
        $st = (string)($r['review_status'] ?? 'pending');
        if ($st === 'pending') $pending++; else $handled++;
        if (strpos((string)($r['created_at'] ?? ''), $todayStr) === 0) $today++;
    }
    jsonSuccess([
        'pending' => $pending,
        'handled' => $handled,
        'today' => $today,
        'total' => count($rows),
    ]);
}

if ($action === 'list') {
    $status = (string)($_GET['status'] ?? 'pending');
    $type = (string)($_GET['type'] ?? 'all');
    $page = max(1, (int)($_GET['page'] ?? 1));
    $limit = min(50, max(1, (int)($_GET['limit'] ?? 20)));
    $kw = trim((string)($_GET['keyword'] ?? ''));

    $rows = lwAllReports();
    $filtered = [];
    foreach ($rows as $r) {
        $st = (string)($r['review_status'] ?? 'pending');
        if ($status !== 'all' && $st !== $status) continue;
        if ($type !== 'all' && (string)($r['target_type'] ?? '') !== $type) continue;
        if ($kw !== '') {
            $hay = (string)($r['reason'] ?? '') . (string)($r['detail'] ?? '');
            if (mb_stripos($hay, $kw) === false) continue;
        }
        $filtered[] = $r;
    }
    // 待处理的按时间正序（先来先处理），已处理的按倒序（最近的在上）
    usort($filtered, static function ($a, $b) use ($status) {
        $ta = strtotime((string)($a['created_at'] ?? 'now')) ?: 0;
        $tb = strtotime((string)($b['created_at'] ?? 'now')) ?: 0;
        return $status === 'pending' ? ($ta <=> $tb) : ($tb <=> $ta);
    });

    $total = count($filtered);
    $offset = ($page - 1) * $limit;
    $pageRows = array_slice($filtered, $offset, $limit);

    // 补充举报对象的摘要，让审核员不用点进每个帖子
    $posts = [];
    try {
        foreach ($fs->read('posts') as $p) {
            $posts[(int)$p['id']] = $p;
        }
    } catch (Throwable $e) {
    }

    $out = [];
    foreach ($pageRows as $r) {
        $tid = (int)($r['target_id'] ?? 0);
        $p = $posts[$tid] ?? null;
        $excerpt = '';
        if ($p) {
            $excerpt = mb_substr(trim((string)($p['title'] ?? '') . ' ' . (string)($p['content'] ?? '')), 0, 100);
        }
        $out[] = [
            'id' => (int)$r['id'],
            'type' => (string)($r['target_type'] ?? ''),
            'target_id' => $tid,
            'reason' => (string)($r['reason'] ?? ''),
            'detail' => (string)($r['detail'] ?? ''),
            'reporter' => (string)($r['reporter_name'] ?? $r['qq'] ?? ''),
            'status' => (string)($r['review_status'] ?? 'pending'),
            'handler' => (string)($r['handler_name'] ?? ''),
            'result' => (string)($r['review_result'] ?? ''),
            'created_at' => (string)($r['created_at'] ?? ''),
            'handled_at' => (string)($r['handled_at'] ?? ''),
            'excerpt' => $excerpt,
        ];
    }

    jsonSuccess([
        'items' => $out,
        'pagination' => [
            'total' => $total,
            'page' => $page,
            'limit' => $limit,
            'has_more' => $offset + $limit < $total,
        ],
    ]);
}

// ---- 写操作 ----
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonError('请求方法不允许', 405);
}
if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
    jsonError('CSRF验证失败', 403);
}

if ($action === 'assign') {
    $id = (int)($_POST['id'] ?? 0);
    $r = $fs->findById('reports', $id);
    if (!$r) jsonError('记录不存在', 404);
    $fs->update('reports', $id, [
        'review_status' => 'processing',
        'handler_id' => (int)$admin['id'],
        'handler_name' => (string)($admin['nickname'] ?? $admin['qq'] ?? ''),
    ]);
    logOperation($admin['id'], $admin['qq'], 'review_assign', 'reports', (string)$id, '认领举报');
    jsonSuccess([], '已认领');
}

if ($action === 'resolve' || $action === 'batch') {
    $result = (string)($_POST['result'] ?? '');
    if (!in_array($result, ['deleted', 'ignored', 'warned'], true)) {
        jsonError('处理结果不正确');
    }
    $ids = [];
    if ($action === 'batch') {
        $raw = (string)($_POST['ids'] ?? '');
        $ids = array_values(array_filter(array_map('intval', explode(',', $raw))));
    } else {
        $ids = [(int)($_POST['id'] ?? 0)];
    }
    $ids = array_filter($ids);
    if (empty($ids)) jsonError('未指定记录');

    $n = 0;
    foreach ($ids as $id) {
        $r = $fs->findById('reports', $id);
        if (!$r) continue;
        $fs->update('reports', $id, [
            'review_status' => 'handled',
            'review_result' => $result,
            'handler_id' => (int)$admin['id'],
            'handler_name' => (string)($admin['nickname'] ?? $admin['qq'] ?? ''),
            'handled_at' => date('Y-m-d H:i:s'),
        ]);
        // 结果=deleted：顺带把目标帖下线（软删除，保留数据可恢复）
        if ($result === 'deleted' && (string)($r['target_type'] ?? '') === 'post') {
            $pid = (int)($r['target_id'] ?? 0);
            $p = $fs->findById('posts', $pid);
            if ($p) {
                $fs->update('posts', $pid, ['status' => 'hidden', 'hidden_reason' => '举报审核：已删除']);
            }
        }
        $n++;
    }
    logOperation($admin['id'], $admin['qq'], 'review_resolve', 'reports', implode(',', $ids),
        '批量/单个处理举报，结果=' . $result . '，共 ' . $n . ' 条');
    jsonSuccess(['handled' => $n], '已处理 ' . $n . ' 条');
}

jsonError('未知操作');
