<?php
require_once __DIR__ . '/../config/config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonError('请求方法不允许', 405);
}

$csrfToken = $_POST['csrf_token'] ?? '';
if (!verifyCSRFToken($csrfToken)) {
    jsonError('CSRF验证失败', 403);
}

$user = requireLogin();
$user = checkBanned($user);
if ($user['is_banned']) {
    jsonError('账号已被封禁，无法举报');
}

$ip = getClientIP();
if (!checkRateLimit($ip, 'pm_report', 10, 300)) {
    jsonError('操作过于频繁，请稍后再试', 429);
}

$targetQq = sanitizeInput($_POST['target_qq'] ?? '');
$targetNickname = sanitizeInput($_POST['target_nickname'] ?? $targetQq);
$reason = sanitizeInput($_POST['reason'] ?? '');
$recordsJson = $_POST['records'] ?? '';

if ($targetQq === '') {
    jsonError('被举报人信息缺失');
}
if ($targetQq === $user['qq']) {
    jsonError('不能举报自己');
}
if (mb_strlen($reason) < 2 || mb_strlen($reason) > 500) {
    jsonError('举报理由需在2-500字之间');
}
if (!empty(checkSensitiveWords($reason))) {
    jsonError('理由包含敏感词，请修改后提交');
}

// 解析聊天记录（可选）：最多50条，每条正文截断保护，防止超大请求
$records = [];
if ($recordsJson !== '') {
    $decoded = json_decode($recordsJson, true);
    if (is_array($decoded)) {
        $count = 0;
        foreach ($decoded as $r) {
            if ($count >= 50) break;
            $who = sanitizeInput($r['who'] ?? '');
            $text = (string)($r['text'] ?? '');
            if (mb_strlen($text) > 200) {
                $text = mb_substr($text, 0, 200) . '…';
            }
            $records[] = [
                'who' => $who,
                'text' => $text,
                'ts' => (int)($r['ts'] ?? round(microtime(true) * 1000))
            ];
            $count++;
        }
    }
}

$fs = getFS();

// 防刷：同一举报人针对同一目标每天最多3次
$today = date('Y-m-d');
$reports = $fs->read('pm_reports');
if (!is_array($reports)) $reports = [];
$todayCount = 0;
foreach ($reports as $r) {
    if (($r['reporter_qq'] ?? '') === $user['qq']
        && ($r['target_qq'] ?? '') === $targetQq
        && strpos($r['created_at'] ?? '', $today) === 0) {
        $todayCount++;
    }
}
if ($todayCount >= 3) {
    jsonError('今天对该用户的举报次数已达上限，请勿重复提交');
}

$fs->insert('pm_reports', [
    'target_qq' => $targetQq,
    'target_nickname' => $targetNickname,
    'reporter_id' => $user['id'],
    'reporter_qq' => $user['qq'],
    'reason' => $reason,
    'records' => $records,
    'status' => 'pending'
]);

logUserActivity($user['id'], 'report_pm', '举报私信用户 ' . $targetQq . ' 原因: ' . $reason);
jsonSuccess([], '举报已提交，管理员将尽快处理');
