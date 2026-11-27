<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/lottery.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') { jsonError('仅支持 POST', 405); }
$user = requireLogin();
$lotteryId = (int) ($_POST['lottery_id'] ?? $_GET['lottery_id'] ?? 0);
if ($lotteryId <= 0) { jsonError('参数错误', 400); }
if (function_exists('verifyCSRFToken')) {
    $tk = $_POST['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    if ($tk !== '' && !verifyCSRFToken($tk)) { jsonError('令牌失效', 403); }
}
$l = lwLotteryGet($lotteryId);
if (!$l) { jsonError('抽奖不存在', 404); }
if ((string) ($l['status'] ?? 'open') !== 'open') { jsonError('该抽奖已开奖', 400); }

$on = !isset($_POST['on']) || (string) $_POST['on'] !== '0';
$state = lwLotteryRemindSet((int) $user['id'], $lotteryId, $on);
jsonSuccess(['on' => $state]);
