<?php
/**
 * 每日签到接口。
 *
 * 签到状态以服务端 checkins 表为唯一可信来源，前端不再使用 localStorage，
 * 因此同一账号在任意设备、任意页面看到的签到状态都一致。
 *
 * POST 参数：
 *   action=status   查询当前签到状态（默认）
 *   action=checkin  执行签到
 *
 * 返回：{ checked_today, streak, total, last_date, today }
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/actions/checkin.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonError('请求方法不允许', 405);
}

if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
    jsonError('CSRF验证失败', 403);
}

$user = requireMember('签到需要注册账号后才能使用');
$user = checkBanned($user);
if ($user['is_banned']) {
    jsonError('账号已被封禁，无法签到');
}

$action = $_POST['action'] ?? 'status';
if (!in_array($action, ['status', 'checkin'], true)) {
    jsonError('未知操作');
}

$fs = getFS();
$today = date('Y-m-d');

if ($action === 'status') {
    $rows = $fs->find('checkins', ['user_id' => $user['id']]);
    // 历史重复行兜底：只保留最早的一行，其余清掉，避免状态读取歧义
    if (count($rows) > 1) {
        for ($i = 1; $i < count($rows); $i++) {
            $fs->delete('checkins', $rows[$i]['id']);
        }
        $rows = [$rows[0]];
    }
    jsonSuccess(lw_checkinState($rows ? $rows[0] : null, $today));
}

// 执行签到：业务逻辑统一在 includes/actions/checkin.php，与 AI 确认卡片共用同一份
$result = lw_do_checkin($user);
if (!$result['ok']) {
    jsonError($result['message'], $result['code']);
}
jsonSuccess($result['data'], $result['message']);
