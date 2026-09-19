<?php
require_once __DIR__ . '/../../config/config.php';

$user = requireLogin();
$user = checkBanned($user);
if ($user['is_banned']) {
    jsonError('账号已被封禁');
}

$fs = getFS();

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $page = intval($_REQUEST['page'] ?? 1);
    $limit = intval($_REQUEST['limit'] ?? 20);
    $unreadOnly = ($_REQUEST['unread'] ?? '0') === '1';

    $notifications = $fs->find('notifications', ['user_id' => $user['id']]);

    usort($notifications, function($a, $b) {
        return strtotime($b['created_at']) - strtotime($a['created_at']);
    });

    if ($unreadOnly) {
        $notifications = array_filter($notifications, function($n) { return empty($n['is_read']); });
    }

    $notifications = array_values($notifications);
    $total = count($notifications);
    $unreadCount = count(array_filter($notifications, function($n) { return empty($n['is_read']); }));
    $notifications = array_slice($notifications, ($page - 1) * $limit, $limit);

    $formatted = array_map(function($n) {
        return [
            'id' => $n['id'],
            'type' => $n['type'] ?? 'comment',
            'content' => $n['content'] ?? '',
            'post_id' => $n['post_id'] ?? '',
            'post_title' => $n['post_title'] ?? '',
            'from_user' => $n['from_user'] ?? '',
            'is_read' => !empty($n['is_read']),
            'created_at' => $n['created_at'] ?? '',
            'timeAgo' => timeAgo($n['created_at'] ?? '')
        ];
    }, $notifications);

    jsonSuccess([
        'notifications' => $formatted,
        'unread_count' => $unreadCount,
        'total' => $total,
        'page' => $page,
        'has_more' => ($page * $limit) < $total
    ]);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrfToken = $_POST['csrf_token'] ?? '';
    if (!verifyCSRFToken($csrfToken)) {
        jsonError('CSRF验证失败', 403);
    }

    $action = $_REQUEST['action'] ?? 'read';

    if ($action === 'read_all') {
        $all = $fs->find('notifications', ['user_id' => $user['id']]);
        foreach ($all as $n) {
            $n['is_read'] = true;
            $fs->update('notifications', $n['id'], $n);
        }
        jsonSuccess(['message' => '已全部标记为已读']);
    }

    if ($action === 'read') {
        $id = $_REQUEST['id'] ?? '';
        if (!$id) jsonError('缺少通知ID');
        $n = $fs->findById('notifications', $id);
        if (!$n || $n['user_id'] !== $user['id']) jsonError('通知不存在');
        $n['is_read'] = true;
        $fs->update('notifications', $id, $n);
        jsonSuccess(['message' => '已标记为已读']);
    }

    jsonError('无效操作');
}
