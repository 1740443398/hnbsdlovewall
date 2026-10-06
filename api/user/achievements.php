<?php
/**
 * 成就徽章接口。
 *
 * GET  默认           我的成就墙（全部成就 + 进度 + 是否解锁）
 * GET  action=user&id= 查看他人成就墙（只读，不触发检测）
 * POST action=check   立即评估并解锁（幂等，前端刷新时调用）
 *
 * 安全：POST 需要 CSRF；GET 只读公开成就信息，不含隐私。
 */
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/achievements.php';
require_once __DIR__ . '/../../includes/level.php';

$user = requireLogin();
$user = checkBanned($user);
if (!empty($user['is_banned'])) {
    jsonError('账号已被封禁');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        jsonError('CSRF验证失败', 403);
    }
    $action = $_REQUEST['action'] ?? 'check';
    if ($action !== 'check') {
        jsonError('未知操作');
    }

    // 幂等：已解锁的不重复写、不重复发通知
    $newly = lwCheckAchievements((int)$user['id'], true);
    $summary = lwAchievementSummary((int)$user['id']);

    jsonSuccess([
        'newly_unlocked' => array_map(function ($d) {
            return ['key' => $d['key'], 'name' => $d['name'], 'icon' => $d['icon'], 'desc' => $d['desc']];
        }, $newly),
        'unlocked' => $summary['unlocked'],
        'total'    => $summary['total'],
        'percent'  => $summary['percent'],
    ], $newly ? ('解锁了 ' . count($newly) . ' 个新成就') : '暂无新成就');
}

// GET
$targetId = (int)($user['id']);
if (($_REQUEST['action'] ?? '') === 'user') {
    $targetId = (int)($_REQUEST['id'] ?? 0);
    if ($targetId <= 0) {
        jsonError('缺少用户 ID');
    }
    // 只有能看得到的用户才能看成就墙；被封禁账号直接拒绝
    $target = getFS()->findById('users', $targetId);
    if (!$target || !empty($target['is_banned'])) {
        jsonError('用户不存在', 404);
    }
}

$summary = lwAchievementSummary($targetId);

// 按分组归类，便于前端分块展示
$groups = [];
foreach ($summary['items'] as $it) {
    $groups[$it['group']][] = $it;
}

jsonSuccess([
    'items'    => $summary['items'],
    'groups'   => $groups,
    'unlocked' => $summary['unlocked'],
    'total'    => $summary['total'],
    'percent'  => $summary['percent'],
    'is_me'    => ($_REQUEST['action'] ?? '') !== 'user' || $targetId === (int)$user['id'],
]);
