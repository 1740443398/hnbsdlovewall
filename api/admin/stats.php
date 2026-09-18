<?php
require_once __DIR__ . '/../../config/config.php';

$admin = requireAdmin();
$admin = checkBanned($admin);
if ($admin['is_banned']) {
    jsonError('账号已被封禁');
}

if (!checkPermission($admin, 'view_stats')) {
    jsonError('没有权限查看统计数据', 403);
}

$fs = getFS();

$users = $fs->read('users');
$posts = $fs->read('posts');

$today = date('Y-m-d');

$totalUsers = count($users);
$todayUsers = count(array_filter($users, function($u) use ($today) {
    return strpos($u['created_at'], $today) === 0;
}));

$totalPosts = count($posts);
$todayPosts = count(array_filter($posts, function($p) use ($today) {
    return strpos($p['created_at'], $today) === 0;
}));

$pendingPosts = count(array_filter($posts, function($p) {
    return $p['status'] == 'pending';
}));

$bannedUsers = count(array_filter($users, function($u) {
    return $u['is_banned'] == 1;
}));

$recent7Days = [];
for ($i = 6; $i >= 0; $i--) {
    $date = date('Y-m-d', strtotime("-$i days"));
    $count = count(array_filter($posts, function($p) use ($date) {
        return strpos($p['created_at'], $date) === 0;
    }));
    $recent7Days[] = ['date' => $date, 'count' => $count];
}

jsonSuccess([
    'total_users' => $totalUsers,
    'today_users' => $todayUsers,
    'total_posts' => $totalPosts,
    'today_posts' => $todayPosts,
    'pending_posts' => $pendingPosts,
    'banned_users' => $bannedUsers,
    'recent_7_days' => $recent7Days
]);