<?php
require_once __DIR__ . '/../../config/config.php';

$admin = requireAdmin();
$admin = checkBanned($admin);
if ($admin['is_banned']) {
    jsonError('账号已被封禁');
}

$fs = getFS();
$action = $_POST['action'] ?? 'list';

if ($action === 'list') {
    if (!checkPermission($admin, 'view_reports')) {
        jsonError('无权限查看举报', 403);
    }
    $type = $_REQUEST['type'] === 'post' ? 'post' : 'pm';
    $page = max(1, intval($_REQUEST['page'] ?? 1));
    $limit = 15;
    $keyword = sanitizeInput($_REQUEST['keyword'] ?? '');

    $table = $type === 'post' ? 'post_reports' : 'pm_reports';
    $items = $fs->read($table);
    if (!is_array($items)) $items = [];

    usort($items, function ($a, $b) {
        return strtotime($b['created_at'] ?? '2000-01-01') - strtotime($a['created_at'] ?? '2000-01-01');
    });

    if ($keyword !== '') {
        $items = array_values(array_filter($items, function ($r) use ($keyword) {
            $hay = ($r['reason'] ?? '') . ' ' . ($r['target_qq'] ?? '') . ' '
                . ($r['target_nickname'] ?? '') . ' ' . ($r['reporter_qq'] ?? '');
            return stripos($hay, $keyword) !== false;
        }));
    }

    $total = count($items);
    $totalPages = max(1, ceil($total / $limit));
    $items = array_slice($items, ($page - 1) * $limit, $limit);

    $result = [];
    foreach ($items as $r) {
        if ($type === 'post') {
            $post = $fs->findById('posts', intval($r['post_id'] ?? 0));
            $result[] = [
                'id' => $r['id'],
                'target_name' => '帖子#' . ($r['post_id'] ?? ''),
                'target_sub' => $post ? ($post['title'] ?? '') : '（帖子已删除）',
                'target_qq' => '',
                'reporter_qq' => $r['reporter_qq'] ?? '',
                'reason' => $r['reason'] ?? '',
                'records' => null,
                'created_at' => $r['created_at'] ?? ''
            ];
        } else {
            $result[] = [
                'id' => $r['id'],
                'target_name' => $r['target_nickname'] ? $r['target_nickname'] . '（QQ:' . $r['target_qq'] . '）' : 'QQ:' . $r['target_qq'],
                'target_sub' => '',
                'target_qq' => $r['target_qq'] ?? '',
                'reporter_qq' => $r['reporter_qq'] ?? '',
                'reason' => $r['reason'] ?? '',
                'records' => $r['records'] ?? [],
                'created_at' => $r['created_at'] ?? ''
            ];
        }
    }

    jsonSuccess([
        'items' => $result,
        'total' => $total,
        'page' => $page,
        'total_pages' => $totalPages,
        'type' => $type
    ]);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonError('请求方法不允许', 405);
}

$csrfToken = $_POST['csrf_token'] ?? '';
if (!verifyCSRFToken($csrfToken)) {
    jsonError('CSRF验证失败', 403);
}

if ($action === 'delete') {
    if (!checkPermission($admin, 'manage_reports')) {
        jsonError('无权限删除举报', 403);
    }
    $type = $_POST['type'] === 'post' ? 'post' : 'pm';
    $id = intval($_POST['id'] ?? 0);
    if (!$id) {
        jsonError('举报ID无效');
    }
    $table = $type === 'post' ? 'post_reports' : 'pm_reports';
    $item = $fs->findById($table, $id);
    if (!$item) {
        jsonError('举报不存在');
    }
    $fs->delete($table, $id);
    logOperation($admin['id'], $admin['qq'], 'delete_report', ($type === 'post' ? 'post' : 'pm') . '_report', $id, '删除举报（' . ($item['reason'] ?? '') . '）');
    jsonSuccess([], '删除成功');
}

jsonError('未知操作');