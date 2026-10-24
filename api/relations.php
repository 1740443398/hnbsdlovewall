<?php
/**
 * 用户关系绑定接口（需求二、5）
 *
 * 动作：
 *   list      查看某用户的关系（受可见性过滤）
 *   pending   我的待确认申请
 *   apply     发起申请
 *   respond   同意 / 拒绝
 *   remove    解除
 *   visibility 设置我对某人的关系可见性
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../includes/relations.php';

header('Content-Type: application/json; charset=utf-8');

$user = requireLogin();
$user = checkBanned($user);
if (!empty($user['is_banned'])) {
    jsonError('账号已被封禁', 403);
}

$action = (string)($_REQUEST['action'] ?? 'list');
$uid = (int)$user['id'];

if ($action === 'list') {
    $pid = (int)($_REQUEST['user_id'] ?? 0);
    if ($pid <= 0) {
        jsonError('缺少 user_id', 400);
    }
    jsonSuccess([
        'relations' => lwUserRelations($pid, $uid, $pid === $uid),
        'types' => LW_RELATION_TYPES,
        'visibilities' => array_map(function ($v) {
            return ['value' => $v, 'label' => lwRelationVisibilityLabel($v)];
        }, LW_RELATION_VISIBILITY),
    ]);
}

if ($action === 'pending') {
    jsonSuccess(['pending' => lwRelationPending($uid)]);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonError('该操作仅支持 POST 请求', 405);
}

if (!verifyCSRFToken((string)($_POST['csrf_token'] ?? ''))) {
    jsonError('页面已过期，请刷新后重试', 403);
}

if ($action === 'apply') {
    if (!checkRateLimit('u' . $uid, 'relation_apply', 10, 60)) {
        jsonError('操作过于频繁，请稍后再试', 429);
    }
    $r = lwRelationApply($uid, (int)($_POST['target_id'] ?? 0), (string)($_POST['type'] ?? ''));
    if (!$r['ok']) {
        jsonError($r['error'], 400);
    }
    jsonSuccess(['status' => $r['status']],
        $r['status'] === 'accepted' ? '已建立关系' : '申请已发送');
}

if ($action === 'respond') {
    $from = (int)($_POST['from_user_id'] ?? 0);
    $accept = (string)($_POST['accept'] ?? '1') === '1';
    $r = lwRelationRespond($uid, $from, $accept);
    if (!$r['ok']) {
        jsonError($r['error'], 400);
    }
    jsonSuccess([], $accept ? '已同意' : '已拒绝');
}

if ($action === 'remove') {
    if (!lwRelationRemove($uid, (int)($_POST['target_id'] ?? 0))) {
        jsonError('解除失败', 400);
    }
    jsonSuccess([], '已解除关系');
}

if ($action === 'visibility') {
    $ok = lwRelationSetVisibility(
        $uid,
        (int)($_POST['target_id'] ?? 0),
        (string)($_POST['visibility'] ?? 'public')
    );
    if (!$ok) {
        jsonError('设置失败（可见性取值不合法或关系不存在）', 400);
    }
    jsonSuccess([], '已更新可见性');
}

jsonError('未知操作', 400);
