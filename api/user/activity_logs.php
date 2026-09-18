<?php
require_once __DIR__ . '/../../config/config.php';

$user = requireLogin();

$user = checkBanned($user);
if ($user['is_banned']) {
    jsonError('账号已被封禁');
}

$fs = getFS();
$logs = $fs->find('user_activity_logs', ['user_id' => $user['id']]);
usort($logs, function($a, $b) {
    return strtotime($b['created_at']) - strtotime($a['created_at']);
});

$page = max(1, intval($_REQUEST['page'] ?? 1));
$limit = 20;
$total = count($logs);
$logs = array_slice($logs, ($page - 1) * $limit, $limit);

$result = [];
$actionMap = [
    'login' => '登录',
    'register' => '注册',
    'password_change' => '修改密码',
    'password_reset' => '重置密码',
    'twofa_enable' => '开启2FA',
    'twofa_disable' => '关闭2FA',
    'twofa_reset' => '重置2FA',
    'post_create' => '发布帖子',
    'post_delete' => '删除帖子',
    'comment_create' => '发布评论',
    'profile_update' => '更新资料'
];

foreach ($logs as $log) {
    $result[] = [
        'action' => $actionMap[$log['action']] ?? $log['action'],
        'details' => $log['details'] ?? '',
        'ip' => $log['ip'] ?? '',
        'created_at' => $log['created_at'] ?? ''
    ];
}

jsonSuccess([
    'logs' => $result,
    'pagination' => [
        'total' => $total,
        'page' => $page,
        'limit' => $limit,
        'total_pages' => ceil($total / $limit)
    ]
]);