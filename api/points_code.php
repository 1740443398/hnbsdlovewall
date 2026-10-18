<?php
/**
 * 积分兑换码接口（需求二、5）
 *
 * 动作：
 *   redeem       用户兑换一个码（POST，登录 + CSRF）
 *   gen_batch    后台批量生成（POST，CSRF + 权限）
 *   list_batches 后台查看批次（POST，CSRF + 权限）
 *   void_batch   后台作废整批未使用的码（POST，CSRF + 权限）
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../includes/widgets.php';
require_once __DIR__ . '/../includes/points_codes.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonError('仅支持 POST 请求', 405);
}

if (!verifyCSRFToken((string)($_POST['csrf_token'] ?? ''))) {
    jsonError('页面已过期，请刷新后重试', 403);
}

$action = (string)($_POST['action'] ?? '');

if ($action === 'redeem') {
    $user = requireLogin();
    $user = checkBanned($user);
    if (!empty($user['is_banned'])) {
        jsonError('账号已被封禁', 403);
    }
    if (!checkRateLimit('u' . (int)$user['id'], 'code_redeem', 10, 60)) {
        jsonError('尝试过于频繁，请稍后再试', 429);
    }
    $r = lwPointsCodeRedeem((int)$user['id'], (string)($_POST['code'] ?? ''));
    if (!$r['ok']) {
        jsonError($r['error'], 400);
    }
    jsonSuccess(['points' => $r['points'], 'balance' => $r['balance']], '兑换成功，+' . $r['points'] . ' 积分');
}

// ---- 以下为后台操作 ----
$admin = requireAdmin();
$admin = checkBanned($admin);
if ($admin['is_banned']) {
    jsonError('账号已被封禁');
}
if ($admin['role'] !== 'super_admin' && !checkPermission($admin, 'manage_growth')) {
    jsonError('无权限管理兑换码', 403);
}

if ($action === 'gen_batch') {
    $r = lwPointsCodeBatch(
        (int)($_POST['count'] ?? 10),
        (int)($_POST['points'] ?? 100),
        (int)$admin['id'],
        (string)($_POST['note'] ?? '')
    );
    if (!$r['ok']) {
        jsonError($r['error'], 400);
    }
    jsonSuccess(['codes' => $r['codes'], 'batch' => $r['batch'], 'count' => count($r['codes'])],
        '已生成 ' . count($r['codes']) . ' 个兑换码');
}

if ($action === 'list_batches') {
    $rows = (array)getFS()->read('points_codes');
    $byBatch = [];
    foreach ($rows as $r) {
        $b = (string)($r['batch'] ?? '');
        if ($b === '') continue;
        if (!isset($byBatch[$b])) {
            $byBatch[$b] = [
                'batch' => $b,
                'points' => (int)($r['points'] ?? 0),
                'total' => 0,
                'used' => 0,
                'note' => (string)($r['note'] ?? ''),
                'ts' => (int)($r['ts'] ?? 0),
            ];
        }
        $byBatch[$b]['total']++;
        if ((int)($r['redeemed_by'] ?? 0) > 0) {
            $byBatch[$b]['used']++;
        }
    }
    $out = array_values($byBatch);
    usort($out, function ($a, $b) { return $b['ts'] <=> $a['ts']; });
    jsonSuccess(['batches' => $out]);
}

if ($action === 'list_codes') {
    $batch = (string)($_POST['batch'] ?? '');
    $out = [];
    foreach ((array)getFS()->read('points_codes') as $r) {
        if ($batch !== '' && (string)($r['batch'] ?? '') !== $batch) continue;
        $out[] = [
            'code' => (string)($r['code'] ?? ''),
            'points' => (int)($r['points'] ?? 0),
            'used' => (int)($r['redeemed_by'] ?? 0) > 0 ? 1 : 0,
            'redeemed_at' => (int)($r['redeemed_at'] ?? 0),
        ];
    }
    jsonSuccess(['codes' => $out]);
}

if ($action === 'void_batch') {
    $batch = (string)($_POST['batch'] ?? '');
    if ($batch === '') {
        jsonError('请指定批次', 400);
    }
    $fs = getFS();
    $n = 0;
    foreach ((array)$fs->read('points_codes') as $r) {
        if ((string)($r['batch'] ?? '') !== $batch) continue;
        if ((int)($r['redeemed_by'] ?? 0) > 0) continue;   // 已用的不动
        try { $fs->delete('points_codes', (int)$r['id']); $n++; } catch (Exception $e) {}
    }
    jsonSuccess(['removed' => $n], '已作废 ' . $n . ' 个未使用的兑换码');
}

jsonError('未知操作', 400);
