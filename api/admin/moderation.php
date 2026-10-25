<?php
/**
 * 后台：内容审核流水线面板
 *
 * 与 review_queue.php 的分工：
 *   review_queue  处理「用户举报」，是事后的人工申诉通道
 *   本页面        处理「发布时自动审核」的结果，是事前的机器预审
 * 两者都汇入同一套违规类型标签，运营在同一个心智模型下处理。
 *
 * GET  ?action=list&status=pending&type=porn&page=1
 * GET  ?action=stats
 * POST action=approve&id=N    通过（状态改 published）
 * POST action=reject&id=N     拒绝（状态改 hidden，通知作者）
 * POST action=batch&ids=1,2,3&op=approve|reject
 */
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/security.php';
require_once __DIR__ . '/../../includes/moderation.php';

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

/** 读帖子（容错）。 */
function lwPostsSafe(): array
{
    try {
        return getFS()->read('posts');
    } catch (Throwable $e) {
        return [];
    }
}

if ($action === 'stats') {
    $rows = lwPostsSafe();
    $pending = $review = $rejected = 0;
    $byType = [];
    foreach ($rows as $p) {
        $st = (string)($p['mod_status'] ?? 'pass');
        if ($st === 'pending') $pending++;
        elseif ($st === 'review') $review++;
        elseif ($st === 'reject') $rejected++;
        foreach ((array)($p['mod_types'] ?? []) as $t) {
            if (($p['mod_status'] ?? 'pass') === 'pending' || ($p['mod_status'] ?? 'pass') === 'review') {
                $byType[$t] = ($byType[$t] ?? 0) + 1;
            }
        }
    }
    $defs = lwViolationTypes();
    $typeRows = [];
    foreach ($byType as $t => $n) {
        $typeRows[] = [
            'type' => $t,
            'label' => $defs[$t]['label'] ?? $t,
            'color' => $defs[$t]['color'] ?? '#718096',
            'count' => $n,
        ];
    }
    usort($typeRows, static fn($a, $b) => $b['count'] <=> $a['count']);

    jsonSuccess([
        'pending' => $pending,
        'review' => $review,
        'rejected' => $rejected,
        'total_flagged' => $pending + $review,
        'by_type' => $typeRows,
        'types' => $defs,
    ]);
}

if ($action === 'list') {
    $status = (string)($_GET['status'] ?? 'pending');
    $type = (string)($_GET['type'] ?? '');
    $page = max(1, (int)($_GET['page'] ?? 1));
    $limit = min(50, max(1, (int)($_GET['limit'] ?? 20)));

    $rows = lwPostsSafe();
    $userNames = [];
    try {
        foreach ($fs->read('users') as $u) {
            $userNames[(int)$u['id']] = (string)($u['nickname'] ?? $u['qq'] ?? '');
        }
    } catch (Throwable $e) {
    }

    $filtered = [];
    foreach ($rows as $p) {
        $st = (string)($p['mod_status'] ?? 'pass');
        if ($status === 'flagged') {
            if ($st !== 'pending' && $st !== 'review') continue;
        } elseif ($status !== 'all' && $st !== $status) {
            continue;
        }
        if ($type !== '' && !in_array($type, (array)($p['mod_types'] ?? []), true)) {
            continue;
        }
        $filtered[] = $p;
    }

    // 高分在前（最危险的先处理），同分按时间倒序
    usort($filtered, static function ($a, $b) {
        $sa = (int)($a['mod_score'] ?? 0);
        $sb = (int)($b['mod_score'] ?? 0);
        if ($sa !== $sb) return $sb <=> $sa;
        return strcmp((string)($b['created_at'] ?? ''), (string)($a['created_at'] ?? ''));
    });

    $total = count($filtered);
    $offset = ($page - 1) * $limit;
    $defs = lwViolationTypes();
    $out = [];
    foreach (array_slice($filtered, $offset, $limit) as $p) {
        $types = [];
        foreach ((array)($p['mod_types'] ?? []) as $t) {
            $types[] = [
                'key' => $t,
                'label' => $defs[$t]['label'] ?? $t,
                'color' => $defs[$t]['color'] ?? '#718096',
            ];
        }
        $out[] = [
            'id' => (int)($p['id'] ?? 0),
            'title' => (string)($p['title'] ?? ''),
            'content' => mb_substr((string)($p['content'] ?? ''), 0, 160),
            'category' => (string)($p['category'] ?? ''),
            'author' => $userNames[(int)($p['user_id'] ?? 0)] ?? '',
            'status' => (string)($p['status'] ?? ''),
            'mod_status' => (string)($p['mod_status'] ?? 'pass'),
            'mod_score' => (int)($p['mod_score'] ?? 0),
            'types' => $types,
            'reasons' => array_slice((array)($p['mod_reasons'] ?? []), 0, 6),
            'layers' => (array)($p['mod_layers'] ?? []),
            'created_at' => (string)($p['created_at'] ?? ''),
            'mod_at' => (string)($p['mod_at'] ?? ''),
        ];
    }

    jsonSuccess([
        'items' => $out,
        'pagination' => ['total' => $total, 'page' => $page, 'limit' => $limit, 'has_more' => $offset + $limit < $total],
    ]);
}

// ---- 写操作 ----
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonError('请求方法不允许', 405);
}
if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
    jsonError('CSRF验证失败', 403);
}

if ($action === 'approve' || $action === 'reject' || $action === 'batch') {
    $op = ($action === 'batch') ? (string)($_POST['op'] ?? '') : $action;
    if (!in_array($op, ['approve', 'reject'], true)) {
        jsonError('操作不正确');
    }
    $ids = [];
    if ($action === 'batch') {
        $ids = array_values(array_filter(array_map('intval', explode(',', (string)($_POST['ids'] ?? '')))));
    } else {
        $ids = [(int)($_POST['id'] ?? 0)];
    }
    $ids = array_filter($ids);
    if (empty($ids)) jsonError('未指定内容');

    $n = 0;
    foreach ($ids as $id) {
        $p = $fs->findById('posts', $id);
        if (!$p) continue;
        if ($op === 'approve') {
            $fs->update('posts', $id, [
                'status' => 'published',
                'mod_status' => 'pass',
                'mod_reviewed_by' => (int)$admin['id'],
                'mod_reviewed_at' => date('Y-m-d H:i:s'),
            ]);
        } else {
            $fs->update('posts', $id, [
                'status' => 'hidden',
                'hidden_reason' => '自动审核未通过，人工复核拒绝',
                'mod_status' => 'reject',
                'mod_reviewed_by' => (int)$admin['id'],
                'mod_reviewed_at' => date('Y-m-d H:i:s'),
            ]);
        }
        $n++;
    }
    logOperation($admin['id'], $admin['qq'], 'moderation_' . $op, 'posts', implode(',', $ids),
        '审核' . ($op === 'approve' ? '通过' : '拒绝') . ' ' . $n . ' 条');
    jsonSuccess(['handled' => $n], '已处理 ' . $n . ' 条');
}

jsonError('未知操作');
