<?php
/**
 * 后台：安全告警查看（PHP2Shell OOM 探测日志）
 *
 * GET  ?action=list&limit=50
 * POST ?action=clear
 *
 * 只能超级管理员看：日志里有攻击者 IP。
 */
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/security.php';

header('Content-Type: application/json; charset=utf-8');

$admin = requireAdmin();
$admin = checkBanned($admin);
if (!empty($admin['is_banned'])) {
    jsonError('账号已被封禁');
}
if (!in_array($admin['role'] ?? '', ['super_admin'], true)) {
    jsonError('仅超级管理员可查看安全告警', 403);
}

$logFile = __DIR__ . '/../../data/_oom_alerts.log';
$action = $_REQUEST['action'] ?? 'list';

if ($action === 'clear') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        jsonError('请求方法不允许', 405);
    }
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        jsonError('CSRF验证失败', 403);
    }
    if (is_file($logFile)) {
        @unlink($logFile);
    }
    logOperation($admin['id'], $admin['qq'], 'clear_security_alerts', 'security', '', '清空安全告警日志');
    jsonSuccess([], '已清空');
}

// list
$limit = min(200, max(1, (int)($_REQUEST['limit'] ?? 50)));
$rows = [];
if (is_file($logFile)) {
    $lines = @file($logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
    // 最新的在前
    $lines = array_slice(array_reverse($lines), 0, $limit);
    foreach ($lines as $ln) {
        // 格式：[2026-10-24 01:00:00] OOM POST /api/xxx #1.2.3.4 message
        if (preg_match('/^\[([^\]]+)\]\s+(\S+)\s+(\S+)\s+#(\S+)\s+(.*)$/', $ln, $m)) {
            $rows[] = [
                'time' => $m[1],
                'method' => $m[2],
                'uri' => $m[3],
                'ip' => $m[4],
                'message' => $m[5],
            ];
        } else {
            $rows[] = ['time' => '', 'method' => '', 'uri' => '', 'ip' => '', 'message' => $ln];
        }
    }
}

jsonSuccess([
    'alerts' => $rows,
    'total' => count($rows),
    'file_exists' => is_file($logFile),
    'note' => '正常业务不会出现内存耗尽。这里出现记录即意味着有人在试探 CVE-2026-109618。',
]);
