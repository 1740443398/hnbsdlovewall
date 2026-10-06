<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/sort_util.php';

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

    lwSortByCreatedAtDesc($logs);

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

if ($action === 'list_ai_logs') {
    if (!checkPermission($admin, 'view_ai_logs')) {
        jsonError('没有权限查看 AI 调用记录', 403);
    }
    $page = max(1, intval($_REQUEST['page'] ?? 1));
    $limit = 20;
    $offset = ($page - 1) * $limit;

    $keyword = sanitizeInput($_REQUEST['keyword'] ?? '');
    $result = sanitizeInput($_REQUEST['result'] ?? '');   // success / fail / guest
    $date = sanitizeInput($_REQUEST['date'] ?? '');

    $logs = $fs->read('ai_logs');
    if (!is_array($logs)) $logs = [];
    $logs = array_values($logs);
    lwSortByCreatedAtDesc($logs);

    if ($keyword) {
        $logs = array_filter($logs, function($l) use ($keyword) {
            return stripos($l['question'] ?? '', $keyword) !== false
                || stripos($l['nickname'] ?? '', $keyword) !== false
                || stripos($l['ip'] ?? '', $keyword) !== false;
        });
    }
    if ($result === 'success') {
        $logs = array_filter($logs, function($l) { return !empty($l['success']); });
    } elseif ($result === 'fail') {
        $logs = array_filter($logs, function($l) { return empty($l['success']); });
    } elseif ($result === 'guest') {
        $logs = array_filter($logs, function($l) { return !empty($l['is_guest']); });
    }
    if ($date) {
        $logs = array_filter($logs, function($l) use ($date) {
            return strpos($l['created_at'] ?? '', $date) === 0;
        });
    }

    $logs = array_values($logs);
    $total = count($logs);
    $totalPages = (int)ceil($total / $limit);
    $logs = array_slice($logs, $offset, $limit);

    // 概览统计（按筛选后的全量，不受分页影响）
    jsonSuccess([
        'logs' => $logs,
        'total' => $total,
        'page' => $page,
        'total_pages' => $totalPages,
    ]);
}

if ($action === 'ai_logs_stats') {
    if (!checkPermission($admin, 'view_ai_logs')) {
        jsonError('没有权限查看 AI 调用记录', 403);
    }
    $logs = $fs->read('ai_logs');
    if (!is_array($logs)) $logs = [];
    $today = date('Y-m-d');
    $stat = ['total' => 0, 'today' => 0, 'fail' => 0, 'guest' => 0, 'avg_ms' => 0];
    $msSum = 0;
    foreach ($logs as $l) {
        $stat['total']++;
        if (strpos($l['created_at'] ?? '', $today) === 0) $stat['today']++;
        if (empty($l['success'])) $stat['fail']++;
        if (!empty($l['is_guest'])) $stat['guest']++;
        $msSum += (int)($l['elapsed_ms'] ?? 0);
    }
    $stat['avg_ms'] = $stat['total'] > 0 ? (int)round($msSum / $stat['total']) : 0;
    jsonSuccess(['stat' => $stat]);
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
    lwSortByCreatedAtDesc($ips);

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
    if (!checkPermission($admin, 'view_illegal_logs')) {
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

    lwSortByCreatedAtDesc($logs);

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
    if (!checkPermission($admin, 'view_illegal_logs')) {
        jsonError('没有权限操作', 403);
    }
    $id = intval($_POST['id'] ?? 0);
    if (!$id) jsonError('ID无效');
    $fs->delete('illegal_access_logs', $id);
    jsonSuccess([], '已删除');
}

if ($action === 'clear_illegal_logs') {
    if (!checkPermission($admin, 'view_illegal_logs')) {
        jsonError('没有权限操作', 403);
    }
    $fs->write('illegal_access_logs', []);
    logOperation($admin['id'], $admin['qq'], 'clear_illegal_logs', '', '', '清空非法访问日志');
    jsonSuccess([], '已清空');
}

jsonError('未知操作');