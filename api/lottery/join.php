<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/lottery.php';

$user = function_exists('getCurrentUser') ? getCurrentUser() : null;
if (!$user) { jsonError('请先登录', 401); }
if ((($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST')) { jsonError('请求方式错误', 405); }

$id = (int) ($_POST['lottery_id'] ?? 0);
if ($id <= 0) { jsonError('缺少抽奖 ID'); }

$r = lwLotteryJoin($id, (int) $user['id'], 'manual');
if (empty($r['ok'])) { jsonError((string) ($r['error'] ?? '参与失败')); }
jsonSuccess(['entry_count' => (int) ($r['entry_count'] ?? 0)]);
