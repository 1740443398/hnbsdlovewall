<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/lottery.php';

$user = function_exists('getCurrentUser') ? getCurrentUser() : null;
if (!$user) { jsonError('请先登录', 401); }
if ((($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST')) { jsonError('请求方式错误', 405); }

$id = (int) ($_POST['lottery_id'] ?? 0);
if ($id <= 0) { jsonError('缺少抽奖 ID'); }

$r = lwLotteryJoin($id, (int) $user['id'], 'manual');
if (empty($r['ok'])) {
    /* 需要先关注发起人时，把 owner_id 一并回传，前端可直接给「去关注」入口 */
    if (!empty($r['need_follow'])) {
        jsonError((string) ($r['error'] ?? '请先关注发起人'), 403, ['need_follow' => (int) $r['need_follow']]);
    }
    jsonError((string) ($r['error'] ?? '参与失败'));
}
jsonSuccess(['entry_count' => (int) ($r['entry_count'] ?? 0)]);
