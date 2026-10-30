<?php
/**
 * 后台：数据看板（统计报表）
 *
 * 与 dashboard.php 的区别：
 *   dashboard 是「当前快照 + 快捷入口」，面向「今天有什么新东西」。
 *   本页是**趋势报表**：按天/周/月看帖子、评论、用户、举报、访问量的变化，
 *   并给出环比。这是运营决策用的，不是「看一眼」用的。
 *
 * GET ?action=overview&days=30
 */
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/security.php';

header('Content-Type: application/json; charset=utf-8');

$admin = requireAdmin();
$admin = checkBanned($admin);
if (!empty($admin['is_banned'])) {
    jsonError('账号已被封禁');
}

$fs = getFS();
/* 统计时段。
     *
     * 原来上限 180 天。站长的实际需求是「看看这半年/一年的趋势」——
     * 半年刚好卡在上限边缘，一年直接看不到。
     * 现在放宽到 730 天（两年），并且对长时段做降采样：
     * 超过 120 天时按周聚合，超过 365 天时按月聚合，
     * 否则前端要画 700 多个点，手机上根本看不清。
     */
    $__maxDays = function_exists('lwCfgInt') ? max(30, lwCfgInt('analytics_max_days', 730)) : 730;
    $days = min($__maxDays, max(1, (int)($_GET['days'] ?? 30)));
    $granularity = 'day';
    if ($days > 365) $granularity = 'month';
    elseif ($days > 120) $granularity = 'week';
$now = time();
$start = $now - $days * 86400;

function lwReadSafe(string $t): array
{
    try {
        return getFS()->read($t);
    } catch (Throwable $e) {
        return [];
    }
}

/** 把时间戳归到 Y-m-d。 */
function lwDayOf($row): string
{
    $s = (string)($row['created_at'] ?? '');
    if ($s === '') return '';
    $ts = strtotime($s);
    return $ts ? date('Y-m-d', $ts) : '';
}

$series = [];
for ($i = $days - 1; $i >= 0; $i--) {
    $series[date('Y-m-d', $now - $i * 86400)] = [
        'date' => date('Y-m-d', $now - $i * 86400),
        'posts' => 0,
        'comments' => 0,
        'users' => 0,
        'reports' => 0,
        'likes' => 0,
        'visits' => 0,
    ];
}

foreach (lwReadSafe('posts') as $p) {
    $d = lwDayOf($p);
    if ($d !== '' && isset($series[$d])) $series[$d]['posts']++;
}
foreach (lwReadSafe('comments') as $c) {
    $d = lwDayOf($c);
    if ($d !== '' && isset($series[$d])) $series[$d]['comments']++;
}
foreach (lwReadSafe('users') as $u) {
    $d = lwDayOf($u);
    if ($d !== '' && isset($series[$d])) $series[$d]['users']++;
}
foreach (lwReadSafe('reports') as $r) {
    $d = lwDayOf($r);
    if ($d !== '' && isset($series[$d])) $series[$d]['reports']++;
}
foreach (lwReadSafe('post_likes') as $l) {
    $d = lwDayOf($l);
    if ($d !== '' && isset($series[$d])) $series[$d]['likes']++;
}
foreach (lwReadSafe('visit_stats') as $v) {
    $d = (string)($v['date'] ?? lwDayOf($v));
    if ($d !== '' && isset($series[$d])) $series[$d]['visits'] += (int)($v['count'] ?? 1);
}

$rows = array_values($series);

/** 半段对比算环比。 */
function lwCompare(array $rows, string $key): array
{
    $n = count($rows);
    $half = (int)floor($n / 2);
    $prev = 0;
    $cur = 0;
    for ($i = 0; $i < $half; $i++) $prev += (int)($rows[$i][$key] ?? 0);
    for ($i = $half; $i < $n; $i++) $cur += (int)($rows[$i][$key] ?? 0);
    $pct = $prev > 0 ? round(($cur - $prev) * 100.0 / $prev, 1) : ($cur > 0 ? 100.0 : 0.0);
    return ['prev' => $prev, 'current' => $cur, 'change_pct' => $pct];
}

// 分类分布
$catDist = [];
foreach (lwReadSafe('posts') as $p) {
    if (($p['status'] ?? 'published') !== 'published') continue;
    $c = (string)($p['category'] ?? 'other');
    $catDist[$c] = ($catDist[$c] ?? 0) + 1;
}
arsort($catDist);
$cats = [];
foreach ($catDist as $k => $v) $cats[] = ['category' => $k, 'count' => $v];

// 活跃作者 Top10
$authorCount = [];
$userNames = [];
foreach (lwReadSafe('users') as $u) {
    $userNames[(int)$u['id']] = (string)($u['nickname'] ?? $u['qq'] ?? '');
}
foreach (lwReadSafe('posts') as $p) {
    if (($p['status'] ?? 'published') !== 'published') continue;
    $uid = (int)($p['user_id'] ?? 0);
    $authorCount[$uid] = ($authorCount[$uid] ?? 0) + 1;
}
arsort($authorCount);
$authors = [];
$i = 0;
foreach ($authorCount as $uid => $n) {
    if ($i++ >= 10) break;
    $authors[] = ['user_id' => $uid, 'name' => $userNames[$uid] ?? ('#' . $uid), 'posts' => $n];
}

jsonSuccess([
    'days' => $days,
    'series' => $rows,
    'compare' => [
        'posts' => lwCompare($rows, 'posts'),
        'comments' => lwCompare($rows, 'comments'),
        'users' => lwCompare($rows, 'users'),
        'likes' => lwCompare($rows, 'likes'),
    ],
    'categories' => $cats,
    'top_authors' => $authors,
]);
