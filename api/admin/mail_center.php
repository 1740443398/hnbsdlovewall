<?php
/**
 * 邮箱中心管理 API（后台审计/封禁/配置）
 *
 * 动作：
 *   stats     本月收发统计
 *   list      审计流水（筛选：月份/方向/用户/关键词）
 *   accounts  全部站内邮箱账号列表（含绑定与封禁状态）
 *   siteboxes 站点官方信箱概览（对外公布的联系地址，永久可收信）
 *   sitebox   某个站点官方信箱的全部来信
 *   ban       封禁某用户邮箱功能
 *   unban     解封
 *   flags     已标记违规列表 key
 *   flag      标记/取消标记违规记录
 *   save_cfg  保存配置（域名/配额/catch-all IMAP）
 *
 * 权限：super_admin 专属（与邮件设置页一致）。
 */
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/security.php';
require_once __DIR__ . '/../../includes/mail_center.php';

header('Content-Type: application/json; charset=utf-8');

$admin = requireAdmin();
$admin = checkBanned($admin);
if (($admin['role'] ?? '') !== 'super_admin') {
    jsonError('权限不足', 403);
}
if (!verifyCSRFToken((string)($_POST['csrf_token'] ?? ''))) {
    jsonError('CSRF验证失败', 403);
}

$action = (string)($_REQUEST['action'] ?? 'stats');

if ($action === 'stats') {
    $rows = lwMcAuditRead((string)($_REQUEST['month'] ?? ''));
    $out = ['total' => count($rows), 'out' => 0, 'in' => 0, 'ok' => 0, 'failed' => 0, 'blocked' => 0, 'flagged' => 0];
    foreach ($rows as $r) {
        if (($r['dir'] ?? '') === 'out') { $out['out']++; }
        elseif (($r['dir'] ?? '') === 'in') { $out['in']++; }
        $st = (string)($r['status'] ?? '');
        if ($st === 'ok') { $out['ok']++; }
        elseif ($st === 'failed') { $out['failed']++; }
        elseif ($st === 'blocked') { $out['blocked']++; }
        if (($r['risk'] ?? 'none') !== 'none') { $out['flagged']++; }
    }
    $accounts = lwMcAcctAll();
    $out['accounts'] = count($accounts);
    $out['bound'] = 0;
    $out['banned'] = 0;
    foreach ($accounts as $a) {
        if (!empty($a['bound']['email'])) { $out['bound']++; }
        if (!empty($a['banned'])) { $out['banned']++; }
    }
    jsonSuccess(['stats' => $out]);
}

if ($action === 'list') {
    $month = preg_match('/^\d{6}$/', (string)($_REQUEST['month'] ?? '')) ? (string)$_REQUEST['month'] : date('Ym');
    $dir = (string)($_REQUEST['dir'] ?? '');
    $q = strtolower(trim((string)($_REQUEST['q'] ?? '')));
    $page = max(1, (int)($_REQUEST['page'] ?? 1));
    $per = 30;
    $rows = lwMcAuditRead($month);

    /* 违规标记集 */
    $flags = lwMcFlagsGet();

    $filtered = [];
    foreach ($rows as $r) {
        if ($dir !== '' && ($r['dir'] ?? '') !== $dir) { continue; }
        if ($q !== '') {
            $hay = strtolower(json_encode([$r['from'] ?? '', $r['to'] ?? '', $r['addr'] ?? '', $r['subject'] ?? '', $r['text'] ?? ''], JSON_UNESCAPED_UNICODE));
            if (strpos((string)$hay, $q) === false) { continue; }
        }
        $filtered[] = $r;
    }
    $total = count($filtered);
    $slice = array_slice($filtered, ($page - 1) * $per, $per);

    /* 补用户昵称 */
    $fs = getFS();
    $nickCache = [];
    foreach ($slice as &$r) {
        $u = (int)($r['uid'] ?? 0);
        if ($u > 0) {
            if (!isset($nickCache[$u])) {
                $uu = $fs->findById('users', $u);
                $nickCache[$u] = $uu ? (string)$uu['nickname'] : ('#' . $u);
            }
            $r['nickname'] = $nickCache[$u];
        }
        $r['flagged'] = isset($flags[$r['ts'] . '-' . $u . '-' . ($r['dir'] ?? '')]) ? 1 : 0;
    }
    unset($r);

    jsonSuccess([
        'list' => $slice,
        'total' => $total,
        'page' => $page,
        'pages' => max(1, (int)ceil($total / $per)),
        'month' => $month,
    ]);
}

if ($action === 'accounts') {
    $fs = getFS();
    $out = [];
    foreach (lwMcAcctAll() as $uidS => $a) {
        $u = (int)$uidS;
        $uu = $fs->findById('users', $u);
        $out[] = [
            'uid' => $u,
            'nickname' => $uu ? (string)$uu['nickname'] : ('#' . $u),
            'qq' => (string)($uu['qq'] ?? ''),
            'site_addr' => (string)($a['site_addr'] ?? ''),
            'bound_email' => (string)($a['bound']['email'] ?? ''),
            'bound_at' => (string)($a['bound']['bound_at'] ?? ''),
            'banned' => !empty($a['banned']),
            'ban_reason' => (string)($a['ban_reason'] ?? ''),
            'created_at' => (string)($a['created_at'] ?? ''),
        ];
    }
    usort($out, function ($a, $b) { return strcmp($a['site_addr'], $b['site_addr']); });
    jsonSuccess(['list' => $out]);
}

/* 站点官方信箱：对外公布的联系地址，不依赖任何注册账号 */
if ($action === 'siteboxes') {
    jsonSuccess([
        'list'  => lwMcSiteBoxOverview(),
        'domain' => (string)(lwMcCfg()['mail_domain'] ?? ''),
    ]);
}

if ($action === 'sitebox') {
    $addr = strtolower(trim((string)($_REQUEST['addr'] ?? '')));
    if (!lwMcIsSiteBox($addr)) { jsonError('不是站点官方信箱', 404); }
    $rows = lwMcSiteBoxInbox($addr);
    // 正文最多保留 2000 字，直接在后台列表里展开，无需二次请求
    jsonSuccess(['addr' => $addr, 'list' => $rows, 'total' => count($rows)]);
}

/* 写操作：POST 请求（CSRF 已在入口统一校验） */
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonError('该操作仅支持 POST 请求', 405);
}

if ($action === 'ban') {
    $uid = (int)($_POST['uid'] ?? 0);
    $reason = trim((string)($_POST['reason'] ?? ''));
    if ($uid <= 0) { jsonError('缺少用户'); }
    if (!lwMcAcct($uid)) { jsonError('该用户未开通邮箱', 404); }
    lwMcSetBan($uid, true, $reason !== '' ? $reason : '违反邮件使用规范', (string)$admin['nickname']);
    jsonSuccess([], '已停用该用户的邮箱功能');
}

if ($action === 'unban') {
    $uid = (int)($_POST['uid'] ?? 0);
    if ($uid <= 0) { jsonError('缺少用户'); }
    lwMcSetBan($uid, false);
    jsonSuccess([], '已恢复该用户的邮箱功能');
}

if ($action === 'flags') {
    jsonSuccess(['flags' => lwMcFlagsGet()]);
}

if ($action === 'flag') {
    $key = (string)($_POST['key'] ?? '');
    if ($key === '') { jsonError('缺少记录标识'); }
    $flags = lwMcFlagsGet();
    if (isset($flags[$key])) {
        unset($flags[$key]);
        lwMcFlagsPut($flags);
        jsonSuccess(['flagged' => 0], '已取消违规标记');
    }
    $flags[$key] = ['reason' => trim((string)($_POST['reason'] ?? '')), 'by' => (string)$admin['nickname'], 'ts' => time()];
    lwMcFlagsPut($flags);
    jsonSuccess(['flagged' => 1], '已标记为违规记录');
}

if ($action === 'save_cfg') {
    $cfg = lwMcCfg();
    $domain = strtolower(trim((string)($_POST['mail_domain'] ?? '')));
    if ($domain !== '' && !preg_match('/^([a-z0-9\-]+\.)+[a-z]{2,}$/', $domain)) {
        jsonError('域名格式不正确');
    }
    if ($domain !== '') { $cfg['mail_domain'] = $domain; }
    $limit = (int)($_POST['daily_limit'] ?? 0);
    if ($limit >= 1 && $limit <= 200) { $cfg['daily_limit'] = $limit; }

    $ca = $cfg['catchall'];
    $ca['enabled'] = ($_POST['catchall_enabled'] ?? '0') === '1';
    $ca['imap_host'] = trim((string)($_POST['catchall_imap_host'] ?? ''));
    $ca['imap_user'] = trim((string)($_POST['catchall_imap_user'] ?? ''));
    $passRaw = (string)($_POST['catchall_imap_pass'] ?? '');
    if ($passRaw !== '') { $ca['imap_pass_enc'] = lwMcEnc($passRaw); }  // 留空 = 不改
    $cfg['catchall'] = $ca;

    /* 站点官方信箱：每行一个地址，支持「地址 | 说明」写法 */
    if (isset($_POST['site_mailboxes'])) {
        $boxes = [];
        foreach (preg_split('/[\r\n,;]+/', (string)$_POST['site_mailboxes']) as $ln) {
            $ln = trim($ln);
            if ($ln === '') { continue; }
            $parts = array_map('trim', explode('|', $ln, 2));
            $addr = strtolower($parts[0]);
            if (!filter_var($addr, FILTER_VALIDATE_EMAIL)) { continue; }
            $boxes[] = ['addr' => $addr, 'label' => (string)($parts[1] ?? '')];
        }
        $cfg['site_mailboxes'] = $boxes;
    }

    lwMcCfgSave($cfg);
    jsonSuccess([], '配置已保存');
}

jsonError('未知操作', 400);
