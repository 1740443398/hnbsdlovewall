<?php
require_once __DIR__ . '/../../config/config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonError('请求方法不允许', 405);
}

$csrfToken = $_POST['csrf_token'] ?? '';
if (!verifyCSRFToken($csrfToken)) {
    jsonError('CSRF验证失败', 403);
}

$user = requireLogin();
$user = checkBanned($user);
if ($user['is_banned']) {
    jsonError('账号已被封禁');
}

$fs = getFS();
$action = $_POST['action'] ?? '';

if ($action === 'request') {
    $newQq = sanitizeInput($_POST['new_qq'] ?? '');
    $reason = trim($_POST['reason'] ?? '');

    if (!preg_match('/^[1-9][0-9]{4,14}$/', $newQq)) {
        jsonError('QQ号格式不正确');
    }
    if ($newQq === $user['qq']) {
        jsonError('新QQ号与当前QQ号相同');
    }
    if ($reason && mb_strlen($reason) > 200) {
        jsonError('备注不能超过200个字符');
    }

    $occupied = $fs->findOne('users', ['qq' => $newQq]);
    if ($occupied) {
        jsonError('该QQ号已被其他账号注册');
    }

    // 同一用户只能有一条待审核申请
    $pending = $fs->findOne('qq_change_requests', ['user_id' => $user['id'], 'status' => 'pending']);
    if ($pending) {
        jsonError('已有待审核申请，请等待管理员审核');
    }

    $fs->insert('qq_change_requests', [
        'user_id' => $user['id'],
        'old_qq' => $user['qq'],
        'new_qq' => $newQq,
        'status' => 'pending',
        'reason' => $reason,
        'created_at' => date('Y-m-d H:i:s'),
        'reviewed_at' => '',
    ]);
    logUserActivity($user['id'], 'change_qq_request', '申请修改QQ：' . $user['qq'] . ' → ' . $newQq);
    jsonSuccess([], '申请已提交，请等待管理员审核');
}

jsonError('未知操作');