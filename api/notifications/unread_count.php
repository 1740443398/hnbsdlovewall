<?php
require_once __DIR__ . '/../../config/config.php';

$user = requireLogin();
$user = checkBanned($user);
if (!empty($user['is_banned'])) {
    jsonError('账号已被封禁');
}

$fs = getFS();
$notifications = $fs->find('notifications', ['user_id' => $user['id']]);
$unreadCount = 0;
foreach ($notifications as $n) {
    if (empty($n['is_read'])) {
        $unreadCount++;
    }
}

jsonSuccess(['count' => $unreadCount]);