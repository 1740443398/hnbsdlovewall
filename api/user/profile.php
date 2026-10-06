<?php
require_once __DIR__ . '/../../config/config.php';

$viewer = requireLogin();
$viewer = checkBanned($viewer);
if ($viewer['is_banned']) {
    jsonError('账号已被封禁');
}

$fs = getFS();

// 目标用户：默认查看自己；若带 id/user_id 且非本人，则查看指定用户（仅公共信息）
$targetId = intval($_GET['id'] ?? $_GET['user_id'] ?? 0);
if ($targetId <= 0) {
    $targetId = $viewer['id'];
}
$target = $fs->findById('users', $targetId);
if (!$target) {
    jsonError('用户不存在');
}

$entranceYear = (int)($target['entrance_year'] ?? 0);
$isSelf = (int)$target['id'] === (int)$viewer['id'];

// 公共计数动态统计
$postCount = 0;
foreach ($fs->find('posts', ['user_id' => $target['id']]) as $p) {
    if (($p['status'] ?? 'published') === 'published') {
        $postCount++;
    }
}
$commentCount = count($fs->find('comments', ['user_id' => $target['id']]));
$followerCount = count($fs->find('follows', ['target_id' => $target['id']]));
$followingCount = count($fs->find('follows', ['user_id' => $target['id']]));
$isFollowing = !$isSelf && (bool)$fs->findOne('follows', ['user_id' => $viewer['id'], 'target_id' => $target['id']]);

// 公共部分（本人与他人均可见）
$publicData = [
    'id' => $target['id'],
    'nickname' => $target['nickname'],
    'avatar' => $target['avatar'],
    'bio' => $target['bio'] ?? '',
    'role' => $target['role'],
    'is_banned' => $target['is_banned'],
    'grade_index' => $entranceYear > 0 ? currentGradeIndex($entranceYear) : -1,
    'grade_label' => gradeLabel($entranceYear),
    'class_num' => (int)($target['class_num'] ?? 0),
    'position' => $target['position'] ?? '',
    'is_self' => $isSelf,
    'is_following' => $isFollowing,
    'follower_count' => $followerCount,
    'following_count' => $followingCount,
    'post_count' => $postCount,
    'comment_count' => $commentCount,
];

if ($isSelf) {
    // 本人可见私有字段
    jsonSuccess(array_merge($publicData, [
        'qq' => $target['qq'],
        'twofa_enabled' => !empty($target['twofa_enabled']),
        'subject_first' => $target['subject_first'] ?? '',
        'subject_second' => $target['subject_second'] ?? '',
        'real_name' => $target['real_name'] ?? '',
        'password_set' => !empty($target['password_hash']),
    ]));
}

jsonSuccess($publicData);