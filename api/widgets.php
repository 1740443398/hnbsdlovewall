<?php
/**
 * 装扮商城 / 积分接口（需求二）
 *
 * 动作（GET/POST 混用，写操作强制 CSRF）：
 *   catalog   商品列表 + 我的积分 + 我已拥有（GET）
 *   buy       积分兑换（POST）—— 管理员免积分
 *   equip     装备/卸下（POST）—— 同槽位互斥
 *   mine      我的挂件（GET）
 *
 * 安全：登录 + CSRF；兑换全程走 lwWidgetPurchase（内核负责积分扣减与退款）。
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../includes/widgets.php';

header('Content-Type: application/json; charset=utf-8');

$user = requireLogin();
$user = checkBanned($user);
if (!empty($user['is_banned'])) {
    jsonError('账号已被封禁', 403);
}

$action = (string)($_REQUEST['action'] ?? 'catalog');
$uid = (int)$user['id'];
$isAdmin = in_array($user['role'] ?? '', ['admin', 'super_admin'], true);

if ($action === 'catalog') {
    $catalog = lwWidgetCatalog(true);
    // 管理员显示「免费」：前端据此渲染，避免用户以为是 0 积分折扣
    foreach ($catalog as &$c) {
        $c['effective_price'] = $isAdmin ? 0 : $c['price'];
        $c['free_for_admin'] = $isAdmin ? 1 : 0;
    }
    unset($c);

    // 我已拥有（含过期时间）
    $owned = [];
    foreach ((array)getFS()->read('user_widgets') as $r) {
        if ((int)($r['user_id'] ?? 0) !== $uid) continue;
        $owned[(int)$r['widget_id']] = [
            'expires_at' => (int)($r['expires_at'] ?? 0),
            'equipped' => (int)($r['equipped'] ?? 0),
            'expired' => ((int)($r['expires_at'] ?? 0) > 0 && (int)$r['expires_at'] < time()),
        ];
    }

    jsonSuccess([
        'widgets' => $catalog,
        'owned' => $owned,
        'points' => lwUserPoints($uid),
        'is_admin' => $isAdmin ? 1 : 0,
        'equipped' => lwUserWidgets($uid),
        'slots' => LW_WIDGET_SLOTS,
    ]);
}

if ($action === 'mine') {
    jsonSuccess([
        'equipped' => lwUserWidgets($uid),
        'points' => lwUserPoints($uid),
    ]);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonError('该操作仅支持 POST 请求', 405);
}

if (!verifyCSRFToken((string)($_POST['csrf_token'] ?? ''))) {
    jsonError('页面已过期，请刷新后重试', 403);
}

if ($action === 'buy') {
    if (!checkRateLimit('u' . $uid, 'widget_buy', 10, 60)) {
        jsonError('操作过于频繁，请稍后再试', 429);
    }
    $wid = (int)($_POST['widget_id'] ?? 0);
    $r = lwWidgetPurchase($user, $wid);
    if (!$r['ok']) {
        jsonError($r['error'], 400);
    }
    jsonSuccess([
        'widget' => $r['widget'],
        'points' => lwUserPoints($uid),
        'equipped' => lwUserWidgets($uid),
    ], $r['widget']['free'] ? '管理员已免费解锁' : '兑换成功');
}

if ($action === 'equip') {
    $wid = (int)($_POST['widget_id'] ?? 0);
    $equip = (string)($_POST['equip'] ?? '1') === '1';
    $r = lwWidgetEquip($uid, $wid, $equip);
    if (!$r['ok']) {
        jsonError($r['error'], 400);
    }
    jsonSuccess(['equipped' => lwUserWidgets($uid)], $equip ? '已装备' : '已卸下');
}

jsonError('未知操作', 400);
