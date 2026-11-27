<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/lottery.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') { jsonError('仅支持 POST', 405); }
$user = requireLogin();
if (function_exists('verifyCSRFToken')) {
    $tk = $_POST['csrf_token'] ?? '';
    if ($tk !== '' && !verifyCSRFToken($tk)) { jsonError('令牌失效', 403); }
}
$on = !isset($_POST['on']) || (string) $_POST['on'] !== '0';
$state = lwLotteryPushSet((int) $user['id'], $on);
jsonSuccess(['push_on' => $state]);
