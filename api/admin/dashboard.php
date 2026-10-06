<?php
require_once __DIR__ . '/../../config/config.php';

$adminUser = requireAdmin();
$adminUser = checkBanned($adminUser);

if ($adminUser['is_banned']) {
    jsonError('账号已被封禁');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrfToken = $_POST['csrf_token'] ?? '';
    if (!verifyCSRFToken($csrfToken)) {
        jsonError('CSRF验证失败', 403);
    }
}

$fs = getFS();

// 当前在线快照（仪表盘「当前在线」卡片每 30 秒轮询；只读，不写盘）
if (($_POST['action'] ?? '') === 'online_users') {
    if (!checkPermission($adminUser, 'view_stats')) {
        jsonError('无权限查看站点统计', 403);
    }
    require_once __DIR__ . '/../../includes/online.php';
    jsonSuccess(lwOnlineSnapshot(60));
}

// 全站统计（用户数/帖子数/待审核/封禁/趋势）按 view_stats 授权
if (!checkPermission($adminUser, 'view_stats')) {
    jsonError('无权限查看站点统计', 403);
}

$filter = $_POST['filter'] ?? 'today';

$users = $fs->read('users');
$posts = $fs->read('posts');

$today = date('Y-m-d');
$yesterday = date('Y-m-d', strtotime('-1 day'));

$dateToCheck = $filter === 'yesterday' ? $yesterday : $today;

$data = [];

$data['total_users'] = count($users);

$data['new_users'] = count(array_filter($users, function($u) use ($dateToCheck) {
    return strpos($u['created_at'], $dateToCheck) === 0;
}));

$data['total_posts'] = count($posts);

$data['today_posts'] = count(array_filter($posts, function($p) use ($dateToCheck) {
    return strpos($p['created_at'], $dateToCheck) === 0;
}));

$data['pending_audit'] = count(array_filter($posts, function($p) {
    return $p['status'] == 'pending';
}));

$data['banned_users'] = count(array_filter($users, function($u) {
    return $u['is_banned'] == 1;
}));

$trend = [];
for ($i = 6; $i >= 0; $i--) {
    $day = date('Y-m-d', strtotime("-{$i} days"));
    $count = count(array_filter($posts, function($p) use ($day) {
        return strpos($p['created_at'], $day) === 0;
    }));
    $trend[] = [
        'date' => date('m/d', strtotime($day)),
        'count' => $count
    ];
}
$data['trend'] = $trend;

$maxCount = max(array_column($trend, 'count'));
$data['max_count'] = $maxCount > 0 ? $maxCount : 1;

jsonSuccess($data);