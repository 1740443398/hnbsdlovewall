<?php
require_once __DIR__ . '/../../config/config.php';

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

if (!checkPermission($admin, 'view_users')) {
    jsonError('权限不足', 403);
}

$fs = getFS();
$action = $_POST['action'] ?? '';

if ($action === 'list') {
    $list = $fs->getAll('title_requests');
    usort($list, function ($a, $b) {
        return strtotime($b['created_at']) - strtotime($a['created_at']);
    });
    foreach ($list as $k => $r) {
        $u = $fs->findById('users', $r['user_id']);
        $list[$k]['nickname'] = $u['nickname'] ?? $r['nickname'];
        $list[$k]['avatar'] = $u['avatar'] ?? '';
        $list[$k]['cur_title'] = $u['title_text'] ?? '';
    }
    jsonSuccess($list);
}

if ($action === 'approve') {
    $reqId = intval($_POST['id'] ?? 0);
    if (!$reqId) jsonError('请求ID无效');
    $req = $fs->findById('title_requests', $reqId);
    if (!$req) jsonError('申请不存在');
    if ($req['status'] !== 'pending') jsonError('该申请已处理过');

    $userId = intval($req['user_id']);
    $user = $fs->findById('users', $userId);
    if (!$user) jsonError('申请用户不存在');

    $fs->update('users', $userId, [
        'title_text' => $req['title_text'],
        'title_color' => '',
        'title_bg_color' => '',
        'title_rainbow' => 0,
        'title_gradient_start' => '',
        'title_gradient_end' => '',
    ]);
    $fs->update('title_requests', $reqId, ['status' => 'approved']);
    logOperation($admin['id'], $admin['qq'], 'approve_title_request', 'user', $userId, '通过头衔申请: ' . $req['title_text']);
    jsonSuccess([], '已通过，头衔已生效');
}

if ($action === 'reject') {
    $reqId = intval($_POST['id'] ?? 0);
    if (!$reqId) jsonError('请求ID无效');
    $req = $fs->findById('title_requests', $reqId);
    if (!$req) jsonError('申请不存在');
    if ($req['status'] !== 'pending') jsonError('该申请已处理过');

    $fs->update('title_requests', $reqId, ['status' => 'rejected']);
    logOperation($admin['id'], $admin['qq'], 'reject_title_request', 'user', $req['user_id'], '驳回头衔申请: ' . $req['title_text']);
    jsonSuccess([], '已驳回');
}

jsonError('未知操作');