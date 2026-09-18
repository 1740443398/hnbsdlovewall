<?php
require_once __DIR__ . '/../../config/config.php';

$admin = requireAdmin();
$admin = checkBanned($admin);
if ($admin['is_banned']) {
    jsonError('账号已被封禁');
}

$fs = getFS();

$action = $_POST['action'] ?? '';

if ($action === 'list_operation_logs' || $action === 'operation_logs') {
    if (!checkPermission($admin, 'view_operation_logs')) {
        jsonError('没有权限查看操作日志', 403);
    }
    $page = max(1, intval($_REQUEST['page'] ?? 1));
    $limit = 20;
    $offset = ($page - 1) * $limit;

    $operator = sanitizeInput($_REQUEST['operator'] ?? '');
    $logAction = sanitizeInput($_REQUEST['log_action'] ?? '');
    $date = sanitizeInput($_REQUEST['date'] ?? '');

    $logs = $fs->read('operation_logs');
    $logs = array_values($logs);

    usort($logs, function($a, $b) {
        return strtotime($b['created_at'] ?? '2000-01-01') - strtotime($a['created_at'] ?? '2000-01-01');
    });

    if ($operator) {
        $logs = array_filter($logs, function($l) use ($operator) {
            return stripos($l['operator_qq'] ?? '', $operator) !== false;
        });
    }
    if ($logAction) {
        $logs = array_filter($logs, function($l) use ($logAction) {
            return stripos($l['action'] ?? '', $logAction) !== false;
        });
    }
    if ($date) {
        $logs = array_filter($logs, function($l) use ($date) {
            return strpos($l['created_at'] ?? '', $date) === 0;
        });
    }

    $logs = array_values($logs);
    $total = count($logs);
    $totalPages = ceil($total / $limit);
    $logs = array_slice($logs, $offset, $limit);

    jsonSuccess(['logs' => $logs, 'total' => $total, 'page' => $page, 'total_pages' => $totalPages]);
}

if ($action === 'list_ip_blacklist') {
    if (!checkPermission($admin, 'view_operation_logs')) {
        jsonError('没有权限查看IP黑名单', 403);
    }
    $page = max(1, intval($_REQUEST['page'] ?? 1));
    $limit = 20;
    $offset = ($page - 1) * $limit;

    $ips = $fs->read('ip_blacklist');
    $ips = array_values($ips);
    usort($ips, function($a, $b) {
        return strtotime($b['created_at'] ?? '2000-01-01') - strtotime($a['created_at'] ?? '2000-01-01');
    });

    $total = count($ips);
    $totalPages = ceil($total / $limit);
    $ips = array_slice($ips, $offset, $limit);

    // 归一化输出：WAF 自动封禁的记录 created_at 为数字时间戳且无 id，
    // 统一转为日期字符串并保证 id 存在，避免前端 substring() 抛错导致一直加载。
    foreach ($ips as &$e) {
        if (isset($e['created_at']) && is_numeric($e['created_at'])) {
            $e['created_at'] = date('Y-m-d H:i:s', (int)$e['created_at']);
        }
        $e['id'] = isset($e['id']) ? (int)$e['id'] : 0;
        $e['block_count'] = (int)($e['block_count'] ?? 0);
    }
    unset($e);

    jsonSuccess(['ips' => $ips, 'total' => $total, 'page' => $page, 'total_pages' => $totalPages]);
}

if ($action === 'list_illegal_logs') {
    if (!checkPermission($admin, 'view_operation_logs')) {
        jsonError('没有权限查看非法访问日志', 403);
    }
    $page = max(1, intval($_REQUEST['page'] ?? 1));
    $limit = 20;
    $offset = ($page - 1) * $limit;

    $filterIp = sanitizeInput($_REQUEST['ip'] ?? '');
    $filterType = sanitizeInput($_REQUEST['type'] ?? '');
    $filterDate = sanitizeInput($_REQUEST['date'] ?? '');

    $logs = $fs->read('illegal_access_logs');
    $logs = array_values($logs);

    usort($logs, function($a, $b) {
        return strtotime($b['created_at'] ?? '2000-01-01') - strtotime($a['created_at'] ?? '2000-01-01');
    });

    if ($filterIp) {
        $logs = array_filter($logs, function($l) use ($filterIp) {
            return stripos($l['ip'] ?? '', $filterIp) !== false;
        });
    }
    if ($filterType) {
        $logs = array_filter($logs, function($l) use ($filterType) {
            return stripos($l['type'] ?? '', $filterType) !== false;
        });
    }
    if ($filterDate) {
        $logs = array_filter($logs, function($l) use ($filterDate) {
            return strpos($l['created_at'] ?? '', $filterDate) === 0;
        });
    }

    $logs = array_values($logs);
    $total = count($logs);
    $totalPages = ceil($total / $limit);
    $logs = array_slice($logs, $offset, $limit);

    jsonSuccess(['logs' => $logs, 'total' => $total, 'page' => $page, 'total_pages' => $totalPages]);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonError('请求方法不允许', 405);
}

$csrfToken = $_POST['csrf_token'] ?? '';
if (!verifyCSRFToken($csrfToken)) {
    jsonError('CSRF验证失败', 403);
}

$action = $_POST['action'] ?? '';

if ($action === 'add_ip') {
    if (!checkPermission($admin, 'manage_ip_blacklist')) {
        jsonError('没有权限操作IP黑名单', 403);
    }
    $ip = sanitizeInput($_POST['ip'] ?? '');
    $reason = sanitizeInput($_POST['reason'] ?? '');

    if (!$ip) jsonError('IP地址不能为空');
    if (!filter_var($ip, FILTER_VALIDATE_IP)) {
        jsonError('IP地址格式无效');
    }

    $existing = $fs->find('ip_blacklist', ['ip' => $ip]);
    if (!empty($existing)) {
        jsonError('该IP已在黑名单中');
    }

    $fs->insert('ip_blacklist', [
        'ip' => $ip,
        'reason' => $reason,
        'block_count' => 0
    ]);

    logOperation($admin['id'], $admin['qq'], 'add_ip_blacklist', 'ip', $ip, '添加IP黑名单');
    jsonSuccess([], 'IP已加入黑名单');
}

if ($action === 'remove_ip') {
    if (!checkPermission($admin, 'manage_ip_blacklist')) {
        jsonError('没有权限操作IP黑名单', 403);
    }
    $id = intval($_POST['id'] ?? ($_POST['ip_id'] ?? 0));
    $ip = sanitizeInput($_POST['ip'] ?? '');
    if (!$id && $ip === '') jsonError('参数无效');

    // 支持按 id 或按 IP 移除：WAF 自动封禁的记录可能没有 id（只有 ip）
    $data = $fs->read('ip_blacklist');
    if (!is_array($data)) $data = [];
    $removed = '';
    foreach ($data as $i => $item) {
        $matchId = $id && (int)($item['id'] ?? 0) === $id;
        $matchIp = $ip !== '' && ($item['ip'] ?? '') === $ip;
        if ($matchId || $matchIp) {
            $removed = $item['ip'] ?? ($ip ?: $id);
            unset($data[$i]);
        }
    }
    if ($removed === '') jsonError('IP不存在');

    $fs->write('ip_blacklist', array_values($data));
    logOperation($admin['id'], $admin['qq'], 'remove_ip_blacklist', 'ip', $removed, '移除IP黑名单');
    jsonSuccess([], 'IP已从黑名单移除');
}

if ($action === 'delete_illegal_log') {
    if (!checkPermission($admin, 'view_operation_logs')) {
        jsonError('没有权限操作', 403);
    }
    $id = intval($_POST['id'] ?? 0);
    if (!$id) jsonError('ID无效');
    $fs->delete('illegal_access_logs', $id);
    jsonSuccess([], '已删除');
}

if ($action === 'clear_illegal_logs') {
    if (!checkPermission($admin, 'view_operation_logs')) {
        jsonError('没有权限操作', 403);
    }
    $fs->write('illegal_access_logs', []);
    logOperation($admin['id'], $admin['qq'], 'clear_illegal_logs', '', '', '清空非法访问日志');
    jsonSuccess([], '已清空');
}

jsonError('未知操作');