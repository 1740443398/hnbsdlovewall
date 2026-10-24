<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/fields.php';

$user = requireLogin();

$user = checkBanned($user);
if ($user['is_banned']) {
    jsonError('账号已被封禁');
}

$fs = getFS();

$search = $_REQUEST['search'] ?? '';
$limit = min(100, max(1, intval($_REQUEST['limit'] ?? 50)));

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

// qq 必须返回：它是全站的用户标识（私信会话以 qq 为键、后台选人器也靠它回填目标用户），
// 去掉会让「发起新私信」里一个人都列不出来。资料接口 api/user/profile.php 本来就返回 qq，
// 这里返回不引入新的暴露面。
$formatted = array_map(function($u) {
    return [
        'id' => $u['id'],
        'qq' => $u['qq'] ?? '',
        'nickname' => $u['nickname'] ?? '',
        'avatar' => $u['avatar'] ?? '/assets/images/default-avatar.svg',
        'role' => $u['role'] ?? 'user'
    ];
}, $users);

// 字段投影（E14）：不传 fields/top 时与改造前完全一致。
// 注意 `qq` 是必保字段（发起私信以 qq 为键），即使调用方没在 fields 里点名也要带上。
jsonSuccess(lwProjectTop(
    [
        'users' => lwProjectRows(
            $formatted,
            lwFieldsRequest(),
            ['id', 'qq', 'nickname', 'avatar', 'role'],
            ['id', 'qq']
        ),
        'total' => count($formatted)
    ],
    lwTopRequest(),
    []
));