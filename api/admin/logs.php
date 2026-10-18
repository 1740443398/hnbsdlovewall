<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/sort_util.php';

$admin = requireAdmin();
$admin = checkBanned($admin);
if ($admin['is_banned']) {
    jsonError('账号已被封禁');
}

// CSRF 校验（与其它 admin 接口对齐，见 api/admin/comments.php）。
// 本接口此前是后台唯一漏掉 CSRF 的一个，而它带 7 个写操作：
// delete_ai_log / clear_ai_logs / clean_ai_images / add_ip / remove_ip /
// delete_illegal_log / clear_illegal_logs —— 管理员在别处点个链接就能被
// 诱导清空 AI 调用记录或篡改 IP 黑名单。
// 安全性前提：调用方（admin/ai_logs.php、illegal_access.php、ip_blacklist.php、
// operation_logs.php 共 9 处）已在 body 里带 csrf_token，实测 9/9 覆盖。
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonError('请求方法不允许', 405);
}

$csrfToken = $_POST['csrf_token'] ?? '';
if (!verifyCSRFToken($csrfToken)) {
    jsonError('CSRF验证失败', 403);
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
    $userId = intval($_REQUEST['user_id'] ?? 0);          // 分用户查看
    // 时间范围（含端）：date_from ~ date_to，格式 YYYY-MM-DD
    $dateFrom = sanitizeInput($_REQUEST['date_from'] ?? '');
    $dateTo = sanitizeInput($_REQUEST['date_to'] ?? '');
    foreach (['date_from' => $dateFrom, 'date_to' => $dateTo, 'date' => $date] as $k => $v) {
        if ($v !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $v)) {
            $$k = '';
        }
    }

    $logs = $fs->read('ai_logs');
    if (!is_array($logs)) $logs = [];
    $logs = array_values($logs);
    lwSortByCreatedAtDesc($logs);

    if ($keyword) {
        $logs = array_filter($logs, function($l) use ($keyword) {
            return stripos($l['question'] ?? '', $keyword) !== false
                || stripos($l['answer'] ?? '', $keyword) !== false
                || stripos($l['nickname'] ?? '', $keyword) !== false
                || stripos($l['ip'] ?? '', $keyword) !== false;
        });
    }
    if ($userId > 0) {
        $logs = array_filter($logs, function($l) use ($userId) {
            return (int)($l['user_id'] ?? 0) === $userId;
        });
    }
    if ($result === 'success') {
        $logs = array_filter($logs, function($l) { return !empty($l['success']); });
    } elseif ($result === 'fail') {
        $logs = array_filter($logs, function($l) { return empty($l['success']); });
    } elseif ($result === 'guest') {
        $logs = array_filter($logs, function($l) { return !empty($l['is_guest']); });
    }
    // 精确到日：date 是「某一天」，date_from/to 是「区间」，两者可叠加
    if ($date) {
        $logs = array_filter($logs, function($l) use ($date) {
            return strpos($l['created_at'] ?? '', $date) === 0;
        });
    }
    if ($dateFrom) {
        $logs = array_filter($logs, function($l) use ($dateFrom) {
            return strcmp(substr($l['created_at'] ?? '', 0, 10), $dateFrom) >= 0;
        });
    }
    if ($dateTo) {
        $logs = array_filter($logs, function($l) use ($dateTo) {
            return strcmp(substr($l['created_at'] ?? '', 0, 10), $dateTo) <= 0;
        });
    }

    $logs = array_values($logs);
    $total = count($logs);
    $totalPages = (int)ceil($total / $limit);

    // 汇总当前筛选结果的 token 消耗（分页前的全量口径）
    $sumPrompt = 0; $sumCompletion = 0; $sumTotal = 0;
    foreach ($logs as $l) {
        $sumPrompt += (int)($l['prompt_tokens'] ?? 0);
        $sumCompletion += (int)($l['completion_tokens'] ?? 0);
        $sumTotal += (int)($l['total_tokens'] ?? 0);
    }

    $logs = array_slice($logs, $offset, $limit);

    jsonSuccess([
        'logs' => $logs,
        'total' => $total,
        'page' => $page,
        'total_pages' => $totalPages,
        'token_sum' => [
            'prompt' => $sumPrompt,
            'completion' => $sumCompletion,
            'total' => $sumTotal,
        ],
    ]);
}

// 分用户汇总：按用户聚合调用次数、成功/失败、token 消耗。支持同一套时间范围筛选。
if ($action === 'ai_logs_by_user') {
    if (!checkPermission($admin, 'view_ai_logs')) {
        jsonError('没有权限查看 AI 调用记录', 403);
    }
    $dateFrom = sanitizeInput($_REQUEST['date_from'] ?? '');
    $dateTo = sanitizeInput($_REQUEST['date_to'] ?? '');
    foreach (['date_from' => $dateFrom, 'date_to' => $dateTo] as $k => $v) {
        if ($v !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $v)) {
            $$k = '';
        }
    }

    $logs = $fs->read('ai_logs');
    if (!is_array($logs)) $logs = [];
    if ($dateFrom) {
        $logs = array_filter($logs, function($l) use ($dateFrom) {
            return strcmp(substr($l['created_at'] ?? '', 0, 10), $dateFrom) >= 0;
        });
    }
    if ($dateTo) {
        $logs = array_filter($logs, function($l) use ($dateTo) {
            return strcmp(substr($l['created_at'] ?? '', 0, 10), $dateTo) <= 0;
        });
    }

    $agg = [];
    foreach ($logs as $l) {
        $uid = (int)($l['user_id'] ?? 0);
        // 游客（uid=0）单独归一条，便于看「未登录用户总共消耗了多少」
        $key = $uid > 0 ? ('u' . $uid) : 'guest';
        if (!isset($agg[$key])) {
            $agg[$key] = [
                'user_id' => $uid,
                'nickname' => $uid > 0 ? (string)($l['nickname'] ?? ('用户' . $uid)) : '游客',
                'is_guest' => $uid > 0 ? 0 : 1,
                'calls' => 0,
                'success' => 0,
                'fail' => 0,
                'prompt_tokens' => 0,
                'completion_tokens' => 0,
                'total_tokens' => 0,
                'site_cost' => 0,      // 消耗站点配额的次数
                'own_cost' => 0,       // 用户自带模型的次数
                'last_at' => '',
            ];
        }
        $a = &$agg[$key];
        $a['calls']++;
        if (!empty($l['success'])) { $a['success']++; } else { $a['fail']++; }
        $a['prompt_tokens'] += (int)($l['prompt_tokens'] ?? 0);
        $a['completion_tokens'] += (int)($l['completion_tokens'] ?? 0);
        $a['total_tokens'] += (int)($l['total_tokens'] ?? 0);
        if (($l['key_owner'] ?? 'site') === 'user') { $a['own_cost']++; } else { $a['site_cost']++; }
        $ca = (string)($l['created_at'] ?? '');
        if ($ca > $a['last_at']) { $a['last_at'] = $ca; }
        unset($a);
    }

    $rows = array_values($agg);
    usort($rows, function($x, $y) { return $y['calls'] <=> $x['calls']; });
    jsonSuccess(['users' => $rows, 'count' => count($rows)]);
}

// 分时间汇总：按天聚合调用次数与 token，供「分时间查看」的趋势视图。
if ($action === 'ai_logs_by_day') {
    if (!checkPermission($admin, 'view_ai_logs')) {
        jsonError('没有权限查看 AI 调用记录', 403);
    }
    $dateFrom = sanitizeInput($_REQUEST['date_from'] ?? '');
    $dateTo = sanitizeInput($_REQUEST['date_to'] ?? '');
    foreach (['date_from' => $dateFrom, 'date_to' => $dateTo] as $k => $v) {
        if ($v !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $v)) {
            $$k = '';
        }
    }

    $logs = $fs->read('ai_logs');
    if (!is_array($logs)) $logs = [];
    $agg = [];
    foreach ($logs as $l) {
        $day = substr((string)($l['created_at'] ?? ''), 0, 10);
        if ($day === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $day)) continue;
        if ($dateFrom && strcmp($day, $dateFrom) < 0) continue;
        if ($dateTo && strcmp($day, $dateTo) > 0) continue;
        if (!isset($agg[$day])) {
            $agg[$day] = ['day' => $day, 'calls' => 0, 'success' => 0, 'fail' => 0,
                          'prompt_tokens' => 0, 'completion_tokens' => 0, 'total_tokens' => 0];
        }
        $agg[$day]['calls']++;
        if (!empty($l['success'])) { $agg[$day]['success']++; } else { $agg[$day]['fail']++; }
        $agg[$day]['prompt_tokens'] += (int)($l['prompt_tokens'] ?? 0);
        $agg[$day]['completion_tokens'] += (int)($l['completion_tokens'] ?? 0);
        $agg[$day]['total_tokens'] += (int)($l['total_tokens'] ?? 0);
    }
    $rows = array_values($agg);
    usort($rows, function($x, $y) { return strcmp($x['day'], $y['day']); });
    jsonSuccess(['days' => $rows, 'count' => count($rows)]);
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

if ($action === 'ai_log_detail') {
    if (!checkPermission($admin, 'view_ai_logs')) {
        jsonError('没有权限查看 AI 调用记录', 403);
    }
    $id = intval($_REQUEST['id'] ?? ($_POST['id'] ?? 0));
    if (!$id) jsonError('ID无效');

    $row = $fs->findById('ai_logs', $id);
    if (!$row) jsonError('记录不存在', 404);

    // 兼容旧记录（只有 question、没有 conversation）：把单条提问兜底成一轮对话
    $conv = isset($row['conversation']) && is_array($row['conversation']) ? $row['conversation'] : [];
    if (empty($conv) && !empty($row['question'])) {
        $conv = [['role' => 'user', 'content' => (string)$row['question'], 'images' => []]];
    }
    // 标注每张图片是否仍在服务器上（超 30 天会被清理），前端显示「已过期」占位
    $imgDir = UPLOAD_DIR . '/ai';
    foreach ($conv as &$turn) {
        if (!empty($turn['images']) && is_array($turn['images'])) {
            $meta = [];
            foreach ($turn['images'] as $rel) {
                $name = basename((string)$rel);
                $full = $imgDir . '/' . $name;
                $exists = ($name !== '' && is_file($full));
                $meta[] = [
                    'url'      => $rel,
                    'exists'   => $exists ? 1 : 0,
                    'size'     => $exists ? (int)@filesize($full) : 0,
                ];
            }
            $turn['image_meta'] = $meta;
        }
    }
    unset($turn);

    jsonSuccess(['log' => $row, 'conversation' => $conv]);
}

if ($action === 'delete_ai_log') {
    if (!checkPermission($admin, 'manage_ai_logs')) {
        jsonError('没有权限删除 AI 调用记录', 403);
    }
    $id = intval($_REQUEST['id'] ?? ($_POST['id'] ?? 0));
    if (!$id) jsonError('ID无效');
    $row = $fs->findById('ai_logs', $id);
    if (!$row) jsonError('记录不存在', 404);

    // 一并删除该条记录关联的图片实体（手动删除 = 图文一起清）
    $imgDir = UPLOAD_DIR . '/ai';
    $imgs = isset($row['images']) && is_array($row['images']) ? $row['images'] : [];
    $purged = 0;
    foreach ($imgs as $rel) {
        $name = basename((string)$rel);
        $full = $imgDir . '/' . $name;
        if ($name !== '' && is_file($full) && @unlink($full)) $purged++;
    }
    $fs->delete('ai_logs', $id);
    logOperation($admin['id'], $admin['qq'], 'delete_ai_log', 'ai_log', (string)$id,
        '删除AI调用记录' . ($purged > 0 ? "（含{$purged}张图片）" : ''));
    jsonSuccess(['purged_images' => $purged], '已删除该记录及其图片');
}

if ($action === 'clear_ai_logs') {
    if (!checkPermission($admin, 'manage_ai_logs')) {
        jsonError('没有权限清空 AI 调用记录', 403);
    }
    $rowCount = count($fs->read('ai_logs') ?: []);
    // 清空日志的同时回收全部 AI 图片目录，避免残留孤儿文件占用空间
    $imgDir = UPLOAD_DIR . '/ai';
    $purged = 0;
    if (is_dir($imgDir)) {
        foreach ((array)@scandir($imgDir) as $name) {
            if ($name === '.' || $name === '..') continue;
            $full = $imgDir . '/' . $name;
            if (is_file($full) && @unlink($full)) $purged++;
        }
    }
    $fs->write('ai_logs', []);
    logOperation($admin['id'], $admin['qq'], 'clear_ai_logs', '', '',
        "清空AI调用记录（{$rowCount}条，{$purged}张图片）");
    jsonSuccess(['purged_images' => $purged], '已清空 AI 调用记录');
}

if ($action === 'clean_ai_images') {
    if (!checkPermission($admin, 'manage_ai_logs')) {
        jsonError('没有权限清理 AI 图片', 403);
    }
    $days = max(1, intval($_REQUEST['days'] ?? 30));
    $purged = pruneAiImages($days);
    logOperation($admin['id'], $admin['qq'], 'clean_ai_images', '', (string)$days,
        "清理 {$days} 天前的 AI 图片（{$purged} 张）");
    jsonSuccess(['purged_images' => $purged], "已清理 {$purged} 张过期图片");
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