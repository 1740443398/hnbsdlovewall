<?php
/**
 * 用户自助数据备份 / 恢复接口（需求二、6）
 *   action=export  → 下载当前用户的 JSON 备份（附件只留引用）
 *   action=restore → 上传备份文件，写回本人名下数据
 *
 * 限流：导出 5 次/小时、恢复 3 次/小时 —— 这是「低频救命功能」，
 * 不是日常操作，限流是为了防止有人拿它当数据库扫描器反复拉全站。
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../includes/user_backup.php';

$user = requireLogin();
$user = checkBanned($user);
if (!empty($user['is_banned'])) {
    jsonError('账号已被封禁');
}

$uid = (int)$user['id'];
$action = (string)($_REQUEST['action'] ?? '');
$ip = getClientIP();
$fs = getFS();

if ($action === 'export') {
    if (!checkRateLimit($ip, 'user_backup_export', 5, 3600)) {
        jsonError('导出过于频繁，请稍后再试', 429);
    }
    $payload = LWUserBackup::export($fs, $uid);
    $name = 'lovewall-backup-' . preg_replace('/[^0-9A-Za-z_-]/', '', (string)($user['qq'] ?? $uid)) . '-' . date('Ymd_His') . '.json';
    header('Content-Type: application/json; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $name . '"');
    header('Cache-Control: no-store');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit();
}

if ($action === 'restore') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        jsonError('请求方法不允许', 405);
    }
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        jsonError('CSRF验证失败', 403);
    }
    if (!checkRateLimit($ip, 'user_backup_restore', 3, 3600)) {
        jsonError('恢复过于频繁，请稍后再试', 429);
    }
    if (empty($_FILES['file']['tmp_name'])) {
        jsonError('请选择备份文件');
    }
    $size = (int)($_FILES['file']['size'] ?? 0);
    // 个人备份不含附件，正常在几百 KB 量级；20MB 已足够宽松
    if ($size <= 0 || $size > 20 * 1048576) {
        jsonError('备份文件大小异常（上限 20MB）');
    }
    $raw = (string)file_get_contents($_FILES['file']['tmp_name']);
    $data = json_decode($raw, true);
    if (!is_array($data)) {
        jsonError('备份文件格式不正确（应为 .json）');
    }
    $r = LWUserBackup::restore($fs, $uid, $data);
    if (isset($r['error'])) {
        jsonError($r['error']);
    }
    logOperation($uid, (string)($user['qq'] ?? ''), 'user_backup_restore', 'users', (string)$uid,
        '导入 ' . array_sum($r['imported']) . ' 条');
    jsonSuccess($r, '恢复完成');
}

jsonError('未知操作', 400);
