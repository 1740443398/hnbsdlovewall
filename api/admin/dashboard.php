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