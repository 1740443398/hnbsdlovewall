<?php
/**
 * 成长中心聚合接口 —— 一次请求拿齐「等级 + 成就 + 每日任务 + 数据概览」。
 *
 * 为什么要聚合：成长中心页面原本要打 4~5 个接口才能拼出完整画面，
 * 在移动网络下串行等待很明显。合并成一个只读接口后，首屏只需一次往返。
 *
 * 每日任务的判定**全部由既有表实时推导**（不新增计数器、不新增写路径）：
 *   签到   → checkins.last_date == 今天
 *   发帖   → posts 中今天创建的条数
 *   评论   → comments 中今天创建的条数
 *   点赞   → post_likes / comment_likes 中今天产生的条数
 * 这样任务进度天然可信，也不会因为某处漏埋点而永远停在 0。
 */
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/level.php';
require_once __DIR__ . '/../../includes/achievements.php';

$user = requireLogin();
$user = checkBanned($user);
if (!empty($user['is_banned'])) {
    jsonError('账号已被封禁');
}

$uid   = (int)$user['id'];
$fs    = getFS();
$today = date('Y-m-d');

// ---------- 等级 ----------
$mine = lwLevelRow($uid);
[$rank, $rankTotal] = lwLevelRank($uid);

// ---------- 成就 ----------
$ach = lwAchievementSummary($uid);

// 取「最接近解锁」的 3 个，作为「下一步目标」引导
$closest = array_values(array_filter($ach['items'], function ($i) {
    return empty($i['unlocked']);
}));
usort($closest, function ($a, $b) {
    return $b['percent'] <=> $a['percent'];
});
$closest = array_slice($closest, 0, 3);

// ---------- 每日任务 ----------
$todayPrefix = $today;
/** 统计「我今天产生的」条数：按 user_id 归属 + created_at 落在今天 */
$countToday = function (array $rows) use ($uid, $todayPrefix) {
    $n = 0;
    foreach ($rows as $r) {
        if ((int)($r['user_id'] ?? 0) !== $uid) {
            continue;
        }
        $ts = (string)($r['created_at'] ?? '');
        if ($ts !== '' && substr($ts, 0, 10) === $todayPrefix) {
            $n++;
        }
    }
    return $n;
};

$todayPosts    = $countToday((array)$fs->read('posts'));
$todayComments = $countToday((array)$fs->read('comments'));
$todayLikes    = $countToday((array)$fs->read('post_likes'))
               + $countToday((array)$fs->read('comment_likes'));

$ck = $fs->findOne('checkins', ['user_id' => $uid]);
$checkedToday = $ck && (string)($ck['last_date'] ?? '') === $today;

$tasks = [
    ['key' => 'checkin', 'name' => '每日签到',   'desc' => '签到一次',        'target' => 1, 'current' => $checkedToday ? 1 : 0, 'exp' => 8],
    ['key' => 'post',    'name' => '发布动态',   'desc' => '发布 1 条动态',   'target' => 1, 'current' => min(1, $todayPosts),    'exp' => 10],
    ['key' => 'comment', 'name' => '参与讨论',   'desc' => '发表 1 条评论',   'target' => 1, 'current' => min(1, $todayComments), 'exp' => 5],
    ['key' => 'like',    'name' => '为他人点赞', 'desc' => '点赞 1 次',       'target' => 1, 'current' => min(1, $todayLikes),    'exp' => 2],
];
foreach ($tasks as &$t) {
    $t['done']    = $t['current'] >= $t['target'];
    $t['percent'] = $t['target'] > 0 ? round(min(100, $t['current'] / $t['target'] * 100), 1) : 0.0;
}
unset($t);
$taskDone  = count(array_filter($tasks, function ($t) { return $t['done']; }));
$taskTotal = count($tasks);

// ---------- 数据概览 ----------
$stats = lwAchievementStats($uid);
$overview = [
    'post_count'     => (int)($stats['post_count'] ?? 0),
    'comment_count'  => (int)($stats['comment_count'] ?? 0),
    'likes_received' => (int)($stats['likes_received'] ?? 0),
    'fans'           => (int)($stats['fans'] ?? 0),
    'checkin_streak' => (int)($stats['checkin_streak'] ?? 0),
    'checkin_total'  => (int)($stats['checkin_total'] ?? 0),
    'days_since_reg' => (int)($stats['days_since_reg'] ?? 0),
];

jsonSuccess([
    'enabled'    => lwLevelEnabled(),
    'level'      => [
        'level'       => $mine['level'],
        'title'       => $mine['title'],
        'exp'         => $mine['exp'],
        'level_start' => $mine['level_start'],
        'level_end'   => $mine['level_end'],
        'progress'    => $mine['progress'],
        'to_next'     => $mine['to_next'],
        'percent'     => $mine['percent'],
        'is_max'      => $mine['is_max'],
        'today_exp'   => $mine['today_exp'],
        'daily_cap'   => lwLevelDailyCap(),
        'rank'        => $rank,
        'rank_total'  => $rankTotal,
    ],
    'achievements' => [
        'unlocked' => $ach['unlocked'],
        'total'    => $ach['total'],
        'percent'  => $ach['percent'],
        'closest'  => $closest,
    ],
    'tasks'      => [
        'items' => $tasks,
        'done'  => $taskDone,
        'total' => $taskTotal,
    ],
    'overview'   => $overview,
]);
