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
    jsonError('账号已被封禁，无法操作');
}

$action = $_POST['action'] ?? '';
$targetId = intval($_POST['target_id'] ?? 0);
if (!in_array($action, ['follow', 'unfollow'], true)) {
    jsonError('操作无效');
}
if ($targetId <= 0) {
    jsonError('目标用户无效');
}
if ($targetId == $user['id']) {
    jsonError('不能关注自己', 400);
}

$fs = getFS();
$target = $fs->findById('users', $targetId);
if (!$target) {
    jsonError('目标用户不存在');
}

$existing = $fs->findOne('follows', ['user_id' => $user['id'], 'target_id' => $targetId]);
$message = '已关注';
$followed = false;

if ($action === 'follow') {
    if (!$existing) {
        $followed = true;
        $fs->insert('follows', ['user_id' => $user['id'], 'target_id' => $targetId]);
        // 通知被关注者
        $fs->insert('notifications', [
            'user_id' => $targetId,
            'type' => 'follow',
            'content' => ($user['nickname'] ?? '用户') . ' 关注了你',
            'from_user' => $user['nickname'] ?? '用户',
            'is_read' => false
        ]);
        $message = '关注成功';
    }
} else {
    if ($existing) {
        $followed = true;
        $fs->delete('follows', $existing['id']);
        $message = '已取消关注';
    }
}

// 动态计算目标用户的粉丝数，避免计数不一致
$followerCount = count($fs->find('follows', ['target_id' => $targetId]));

jsonSuccess(['follower_count' => $followerCount, 'followed' => $followed, 'is_following' => $action === 'follow' && $followed], $message);