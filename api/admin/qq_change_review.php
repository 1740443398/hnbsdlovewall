<?php
require_once __DIR__ . '/../../config/config.php';

$action = $_REQUEST['action'] ?? '';

// 快速返回待审核/全部 QQ 修改申请清单（只读，GET）
if ($action === 'list_qq') {
    $admin = requireAdmin();
    $admin = checkBanned($admin);
    if ($admin['is_banned']) {
        jsonError('账号已被封禁', 403);
    }
    if (!checkPermission($admin, 'review_qq_change')) {
        jsonError('权限不足', 403);
    }
    $fs = getFS();
    $list = $fs->getAll('qq_change_requests');
    usort($list, function ($a, $b) {
        return strtotime($b['created_at']) - strtotime($a['created_at']);
    });
    foreach ($list as $k => $r) {
        // 关联申请人昵称（以当前 users 表为准）
        $u = $fs->findById('users', $r['user_id']);
        $list[$k]['user_nickname'] = (!empty($u) && !empty($u['nickname'])) ? $u['nickname'] : '';
    }
    jsonSuccess($list);
}

// 审批等写操作必须为 POST 并校验 CSRF
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonError('请求方法不允许', 405);
}

$csrfToken = $_POST['csrf_token'] ?? '';
if (!verifyCSRFToken($csrfToken)) {
    jsonError('CSRF验证失败', 403);
}

$admin = requireAdmin();
$admin = checkBanned($admin);
if ($admin['is_banned']) {
    jsonError('账号已被封禁');
}

if (!checkPermission($admin, 'review_qq_change')) {
    jsonError('权限不足', 403);
}

$fs = getFS();

if ($action === 'review_qq') {
    $reqId = intval($_POST['id'] ?? 0);
    $act = $_POST['review'] ?? '';
    if (!$reqId) jsonError('请求ID无效');
    $req = $fs->findById('qq_change_requests', $reqId);
    if (!$req) jsonError('申请不存在');
    if (($req['status'] ?? '') !== 'pending') jsonError('该申请已处理过');

    if ($act === 'approve') {
        $userId = intval($req['user_id']);
        $newQq = trim($req['new_qq']);
        // 并发防重：approve 前再查一次 new_qq 唯一性
        $dup = $fs->findOne('users', ['qq' => $newQq]);
        if ($dup && (int)$dup['id'] !== $userId) {
            jsonError('新QQ号已被其他用户占用，无法通过');
        }
        $fs->update('users', $userId, ['qq' => $newQq]);
        $fs->update('qq_change_requests', $reqId, [
            'status' => 'approved',
            'reviewed_at' => date('Y-m-d H:i:s'),
        ]);
        // 站内通知申请人
        $fs->insert('notifications', [
            'user_id' => $userId,
            'type' => 'qq_change',
            'content' => '您的QQ号已成功由 ' . $req['old_qq'] . ' 修改为 ' . $newQq,
            'is_read' => false,
        ]);
        logOperation($admin['id'], $admin['qq'], 'approve_qq_change', 'user', $userId, '通过QQ修改申请：' . $req['old_qq'] . ' → ' . $newQq);
        jsonSuccess([], '已通过，QQ号已更新');
    }

    if ($act === 'reject') {
        $fs->update('qq_change_requests', $reqId, [
            'status' => 'rejected',
            'reviewed_at' => date('Y-m-d H:i:s'),
        ]);
        $fs->insert('notifications', [
            'user_id' => intval($req['user_id']),
            'type' => 'qq_change',
            'content' => '您的QQ号修改申请（' . $req['old_qq'] . ' → ' . $req['new_qq'] . '）已被驳回',
            'is_read' => false,
        ]);
        logOperation($admin['id'], $admin['qq'], 'reject_qq_change', 'user', $req['user_id'], '驳回QQ修改申请');
        jsonSuccess([], '已驳回');
    }

    jsonError('未知操作');
}

jsonError('未知操作');