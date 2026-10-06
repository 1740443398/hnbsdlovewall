<?php
require_once __DIR__ . '/../../config/config.php';

$user = requireLogin();
$user = checkBanned($user);

$userId = intval($_GET['user_id'] ?? 0);
$type = $_GET['type'] ?? 'followers';
if ($userId <= 0) {
    jsonError('用户ID无效');
}
if (!in_array($type, ['followers', 'following'], true)) {
    jsonError('类型无效');
}

$fs = getFS();

// 只暴露公开信息（昵称/昵称签名/签名），避免泄露 qq 号与真实姓名等隐私字段
$list = [];
if ($type === 'followers') {
    $rows = $fs->find('follows', ['target_id' => $userId]);
    foreach ($rows as $row) {
        $u = $fs->findById('users', $row['user_id']);
        if ($u) {
            $list[] = [
                'id' => $u['id'],
                'username' => $u['nickname'] ?? '用户',
                'bio' => $u['bio'] ?? '',
            ];
        }
    }
} else {
    $rows = $fs->find('follows', ['user_id' => $userId]);
    foreach ($rows as $row) {
        $u = $fs->findById('users', $row['target_id']);
        if ($u) {
            $list[] = [
                'id' => $u['id'],
                'username' => $u['nickname'] ?? '用户',
                'bio' => $u['bio'] ?? '',
            ];
        }
    }
}

jsonSuccess(['data' => $list]);