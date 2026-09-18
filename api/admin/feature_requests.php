<?php
require_once __DIR__ . '/../../config/config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonError('请求方法不允许', 405);
}

$csrfToken = $_POST['csrf_token'] ?? '';
if (!verifyCSRFToken($csrfToken)) {
    jsonError('CSRF验证失败', 403);
}

$adminUser = requireAdmin();
$adminUser = checkBanned($adminUser);
$fs = getFS();

$action = $_POST['action'] ?? '';
$id = intval($_POST['id'] ?? 0);

if ($action === 'status') {
    $feature = $fs->findById('feature_requests', $id);
    if (!$feature) {
        jsonError('记录不存在');
    }
    $newStatus = ($_POST['status'] ?? '') === 'done' ? 'done' : 'pending';
    $fs->update('feature_requests', $id, ['status' => $newStatus]);
    logUserActivity($adminUser['id'], 'feature_status', '更新功能建议ID:' . $id . ' 状态:' . $newStatus);
    jsonSuccess(['status' => $newStatus], '已更新状态');
}

if ($action === 'delete') {
    $feature = $fs->findById('feature_requests', $id);
    if (!$feature) {
        jsonError('记录不存在');
    }
    foreach ($fs->find('feature_votes', ['feature_id' => $id]) as $v) {
        $fs->delete('feature_votes', $v['id']);
    }
    $fs->delete('feature_requests', $id);
    logUserActivity($adminUser['id'], 'feature_delete', '删除功能建议ID:' . $id);
    jsonSuccess([], '已删除');
}

jsonError('未知操作');