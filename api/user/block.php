<?php
/**
 * 屏蔽（拉黑）用户接口。
 *
 * GET  action=list                     我屏蔽的人
 * POST action=block   & user_id=       屏蔽
 * POST action=unblock & user_id=       解除
 *
 * 语义见 includes/user_blocks.php 头部注释（单向发起、双向隔离、不通知对方）。
 */
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/user_blocks.php';

$user = requireMember('屏蔽功能需要注册账号后才能使用');
$user = checkBanned($user);
if (!empty($user['is_banned'])) {
    jsonError('账号已被封禁');
}

$myId = (int)$user['id'];

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $action = $_REQUEST['action'] ?? 'list';
    if ($action !== 'list') {
        jsonError('未知操作');
    }

    $ids = lwBlockedIds($myId);
    $byId = [];
    foreach ((array)getFS()->read('users') as $u) {
        $byId[(int)($u['id'] ?? 0)] = $u;
    }

    $list = [];
    foreach ($ids as $id) {
        $u = $byId[$id] ?? null;
        if (!$u) {
            continue;
        }
        $list[] = [
            'user_id'  => $id,
            'nickname' => $u['nickname'] ?: ('QQ:' . $u['qq']),
            'qq'       => $u['qq'] ?? '',
            'avatar'   => $u['avatar'] ?: getQQAvatar($u['qq'] ?? ''),
            'blocked_at' => '',
        ];
    }

    jsonSuccess(['list' => $list, 'total' => count($list)]);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonError('请求方法不允许', 405);
}

if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
    jsonError('CSRF验证失败', 403);
}

$action  = $_REQUEST['action'] ?? '';
$targetId = (int)($_POST['user_id'] ?? 0);

if (!in_array($action, ['block', 'unblock'], true)) {
    jsonError('未知操作');
}
if ($targetId <= 0) {
    jsonError('缺少目标用户');
}

$target = getFS()->findById('users', $targetId);
if (!$target) {
    jsonError('用户不存在', 404);
}

$result = ($action === 'block')
    ? lwBlockUser($myId, $targetId)
    : lwUnblockUser($myId, $targetId);

if (!$result['ok']) {
    jsonError($result['message']);
}

jsonSuccess([
    'user_id' => $targetId,
    'blocked' => ($action === 'block'),
], $result['message']);
