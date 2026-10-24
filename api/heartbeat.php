<?php
/**
 * 在线状态心跳
 *
 * 两个动作（都用 POST）：
 *   （默认）        上报「我在线」，回报当前在线人数；
 *   action=leave   前端在页面关闭/离开时用 navigator.sendBeacon 调用，立即把自己从名单摘掉
 *                  —— 后台看到的在线数因此能实时下降，不必等窗口超时。
 *
 * 写库由 includes/online.php 节流（同一主体 20 秒内不重复写盘），本接口不返回任何隐私信息。
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/online.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonError('仅支持 POST 请求', 405);
}

// 未登录也算在线（游客按浏览器会话区分，登录用户按 user_id 区分，两者都不落 IP）。
// 这里刻意不走 requireLoginOrGuest：那个函数只认「点击先逛逛」的游客模式，
// 会把大多数未登录访客挡在统计之外。
$user = getCurrentUser();
if ($user) {
    $user = checkBanned($user);
    if (!empty($user['is_banned'])) {
        jsonError('账号已被封禁', 403);
    }
}

// 单 IP 限流：心跳是「登录前后都要发」的高频基础请求，校园网/运营商共享出口下
// 几十号人可能共用一个出口 IP —— 阈值必须给足，否则真人被 429 掉、后台在线数反而少人。
// 写盘放大由 includes/online.php 的 20 秒主体节流 + 500 行上限兜住；
// 本限流只在超限时才落盘（见 includes/security.php），不会因为心跳变密而增加写盘。
if (!checkRateLimit(getClientIP(), 'heartbeat', 900, 60)) {
    jsonError('请求过于频繁', 429);
}

// action 允许走 query（sendBeacon 的 body 编码各家实现略有差异，用 query 最稳）
$action = (string)($_REQUEST['action'] ?? 'beat');
if ($action === 'leave') {
    jsonSuccess(lwOnlineLeave($user));
}

jsonSuccess(lwHeartbeat($user));
