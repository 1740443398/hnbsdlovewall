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

/* ==========================================================================
 * 今日访问用户
 * ==========================================================================
 * 数据来自 visit_stats 表（由 api/heartbeat.php 写入）。
 *
 * 【为什么不用 users.last_visit】
 * last_visit 只存「最后一次访问时间」，一个人今天和昨天都来过，
 * 它只剩今天那一条；跨天边界还会把「昨天 23:59 来过、今天没来」
 * 的人算成今天。visit_stats 按 (日期, 用户) 聚合，去重准确。
 *
 * 返回三块：
 *   today_visit   今日汇总（总人数 / 登录用户 / 游客 / 新访客 / 总访问次数）
 *   visit_users   今日来访的登录用户列表（按最后访问时间倒序）
 *   visit_trend   最近 7 天趋势，用于画小柱状图
 * ========================================================================== */
try {
    $vis = lwVisitToday();
    $data['today_visit'] = [
        'total'     => (int)$vis['total'],
        'members'   => (int)$vis['members'],
        'guests'    => (int)$vis['guests'],
        'new_users' => (int)$vis['new_users'],
        'visits'    => (int)$vis['visits'],
    ];
    // 列表最多给 60 个，够仪表盘滚动看；要全量去「用户」页
    $data['visit_users'] = array_slice($vis['users'], 0, 60);
    $data['visit_users_total'] = count($vis['users']);
    $data['visit_trend'] = lwVisitTrend(7);
} catch (Throwable $e) {
    $data['today_visit'] = [
        'total' => 0, 'members' => 0, 'guests' => 0, 'new_users' => 0, 'visits' => 0,
    ];
    $data['visit_users'] = [];
    $data['visit_users_total'] = 0;
    $data['visit_trend'] = [];
}

jsonSuccess($data);