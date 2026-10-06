<?php
/**
 * 个人数据看板 —— 一次请求算完个人中心的全部统计数字
 *
 * 为什么单独开接口而不是复用 profile.php：
 *   profile.php 面向「看别人主页」，只输出公开计数；数据看板要的是「被点赞 / 被阅读 / 被收藏 /
 *   被提及 / 近 7 天动态」这些只有本人关心的数，混在一起会让公开接口意外多出一堆私有统计。
 *
 * 计数口径（改动请一并维护）：
 *   - 帖子按 status 拆开。作者本人看自己的帖子不受 lwFilterVisiblePosts 限制（见
 *     includes/post_visibility.php 第 2 条），因此这里直接按 user_id 取全量再分类。
 *   - 获赞 / 阅读 / 被收藏 只统计 status=published 的帖子：未过审的帖子对外不可见，
 *     不可能有真实读者给它点赞或收藏，把它们算进来只会让数字虚高。
 *   - 被提及次数只数 type=mention 的站内通知，与 includes/mention_notify.php 写入的类型一一对应。
 */
require_once __DIR__ . '/../../config/config.php';

$user = requireLogin();
$user = checkBanned($user);
if ($user['is_banned']) {
    jsonError('账号已被封禁');
}

$fs = getFS();
$uid = (int)$user['id'];

// ---- 我的帖子：按状态分类，并汇总公开指标 ----------------------------------
$statusCount = ['published' => 0, 'pending' => 0, 'rejected' => 0];
$likeTotal = 0;      // 帖子获赞
$viewTotal = 0;      // 帖子阅读
$myPostIds = [];     // published 的帖子 ID，用于算「被收藏 / 收到评论」
$postCreated = [];   // 近 7 天发文日期计数

foreach ($fs->find('posts', ['user_id' => $uid]) as $p) {
    $st = (string)($p['status'] ?? 'published');
    if (!isset($statusCount[$st])) {
        $st = 'published';
    }
    $statusCount[$st]++;

    if ($st === 'published') {
        $pid = (int)($p['id'] ?? 0);
        if ($pid > 0) {
            $myPostIds[$pid] = true;
        }
        $likeTotal += (int)($p['likes'] ?? 0);
        $viewTotal += (int)($p['views'] ?? 0);
    }
}

// ---- 我的评论：条数 + 评论获赞 ----------------------------------------------
$myComments = $fs->find('comments', ['user_id' => $uid]);
$commentTotal = count($myComments);
$commentLikeTotal = 0;
foreach ($myComments as $c) {
    $commentLikeTotal += (int)($c['likes'] ?? 0);
}

// ---- 被收藏 / 收到评论：都要先确认帖子还在（帖子被删后残留的记录不计） -------
$favoriteTotal = 0;
foreach ($fs->find('post_favorites') as $f) {
    if (isset($myPostIds[(int)($f['post_id'] ?? 0)])) {
        $favoriteTotal++;
    }
}
$receivedCommentTotal = 0;
$receivedCommentDays = [];
foreach ($fs->find('comments') as $c) {
    if (!isset($myPostIds[(int)($c['post_id'] ?? 0)]) || (int)($c['user_id'] ?? 0) === $uid) {
        continue;
    }
    $receivedCommentTotal++;
    $day = substr((string)($c['created_at'] ?? ''), 0, 10);
    if ($day !== '') {
        $receivedCommentDays[$day] = ($receivedCommentDays[$day] ?? 0) + 1;
    }
}

// ---- 被提及（站内通知） -----------------------------------------------------
$mentionTotal = 0;
$mentionUnread = 0;
foreach ($fs->find('notifications', ['user_id' => $uid, 'type' => 'mention']) as $n) {
    $mentionTotal++;
    if (empty($n['is_read'])) {
        $mentionUnread++;
    }
}

// ---- 近 7 天动态：发文 + 评论，按自然日归集 ---------------------------------
foreach ($fs->find('posts', ['user_id' => $uid]) as $p) {
    $day = substr((string)($p['created_at'] ?? ''), 0, 10);
    if ($day !== '') {
        $postCreated[$day] = ($postCreated[$day] ?? 0) + 1;
    }
}
$myCommentDays = [];
foreach ($myComments as $c) {
    $day = substr((string)($c['created_at'] ?? ''), 0, 10);
    if ($day !== '') {
        $myCommentDays[$day] = ($myCommentDays[$day] ?? 0) + 1;
    }
}

$trend = [];
$weekTotal = 0;
$weekReceived = 0;
for ($i = 6; $i >= 0; $i--) {
    $day = date('Y-m-d', strtotime('-' . $i . ' day'));
    $mine = ($postCreated[$day] ?? 0) + ($myCommentDays[$day] ?? 0);
    $got  = $receivedCommentDays[$day] ?? 0;
    $weekTotal += $mine;
    $weekReceived += $got;
    $trend[] = [
        'date'     => $day,
        'label'    => date('n/j', strtotime($day)),
        'mine'     => $mine,
        'received' => $got,
    ];
}

// ---- 我的热门帖子 Top 5（按获赞，其次阅读） ---------------------------------
$topPosts = [];
foreach ($fs->find('posts', ['user_id' => $uid]) as $p) {
    if ((string)($p['status'] ?? 'published') !== 'published') {
        continue;
    }
    $topPosts[] = $p;
}
usort($topPosts, function ($a, $b) {
    $la = (int)($a['likes'] ?? 0);
    $lb = (int)($b['likes'] ?? 0);
    if ($la !== $lb) {
        return $lb - $la;
    }
    return (int)($b['views'] ?? 0) - (int)($a['views'] ?? 0);
});
$topPosts = array_slice($topPosts, 0, 5);
$topResult = [];
foreach ($topPosts as $p) {
    $topResult[] = [
        'id'            => (int)$p['id'],
        'title'         => (string)($p['title'] ?? ''),
        'like_count'    => (int)($p['likes'] ?? 0),
        'view_count'    => (int)($p['views'] ?? 0),
        'comment_count' => (int)($p['comments'] ?? 0),
        'created_at'    => (string)($p['created_at'] ?? ''),
        'timeAgo'       => timeAgo((string)($p['created_at'] ?? '')),
    ];
}

jsonSuccess([
    'cards' => [
        'post_published' => $statusCount['published'],
        'post_pending'   => $statusCount['pending'],
        'post_rejected'  => $statusCount['rejected'],
        'likes'          => $likeTotal,
        'comment_likes'  => $commentLikeTotal,
        'comments'       => $commentTotal,
        'views'          => $viewTotal,
        'favorites'      => $favoriteTotal,
        'received_comments' => $receivedCommentTotal,
        'mentions'       => $mentionTotal,
        'mentions_unread' => $mentionUnread,
    ],
    'trend' => $trend,
    'week' => [
        'mine'     => $weekTotal,
        'received' => $weekReceived,
    ],
    'top_posts' => $topResult,
]);