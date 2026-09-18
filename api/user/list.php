<?php
require_once __DIR__ . '/../../config/config.php';

$user = requireLogin();

$user = checkBanned($user);
if ($user['is_banned']) {
    jsonError('账号已被封禁');
}

$fs = getFS();

$search = $_REQUEST['search'] ?? '';
$limit = intval($_REQUEST['limit'] ?? 50);

$users = $fs->getAll('users');

$users = array_filter($users, function($u) {
    return empty($u['is_banned']);
});

if (!empty($search)) {
    $searchLower = mb_strtolower($search);
    $users = array_filter($users, function($u) use ($searchLower) {
        $nickname = mb_strtolower($u['nickname'] ?? '');
        $qq = $u['qq'] ?? '';
        return strpos($nickname, $searchLower) !== false || strpos($qq, $searchLower) !== false;
    });
}

$users = array_slice(array_values($users), 0, $limit);

$formatted = array_map(function($u) {
    return [
        'id' => $u['id'],
        'qq' => $u['qq'] ?? '',
        'nickname' => $u['nickname'] ?? '',
        'avatar' => $u['avatar'] ?? '/assets/images/default-avatar.svg',
        'role' => $u['role'] ?? 'user'
    ];
}, $users);

jsonSuccess([
    'users' => $formatted,
    'total' => count($formatted)
]);