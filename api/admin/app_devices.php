<?php
/**
 * 后台：App 设备台账（谁装了、谁在用、用的哪个版本）
 * ---------------------------------------------------------------------------
 * action = list（默认，GET）
 *   page / limit   分页
 *   keyword        机型 / 厂商 / 系统 / App 版本 / QQ 模糊匹配
 *   only_bound     只看已绑定账号的设备
 *   sort           last（默认，按最后活跃倒序）/ first（按首次安装倒序）
 *
 * 返回 data：{ devices, pagination, stats }
 *   —— 概览与列表合并成一次请求，后台每翻一页不必再打一次接口。
 *
 * 说明：device_id 在返回时截断为前 12 位 —— 后台只需要能区分不同设备，
 * 没有理由把完整标识展示（甚至导出）出去。
 */
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/security.php';
require_once __DIR__ . '/../../includes/app_push.php';

header('Content-Type: application/json; charset=utf-8');

$admin = requireAdmin();
$admin = checkBanned($admin);
if (!empty($admin['is_banned'])) {
    jsonError('账号已被封禁');
}

if (!checkPermission($admin, 'view_app_devices')) {
    jsonError('没有权限查看 App 设备台账', 403);
}

$fs    = getFS();
$all   = $fs->read('app_devices');
$all   = is_array($all) ? $all : [];

// ---- 筛选 ----
$keyword = trim((string)($_REQUEST['keyword'] ?? ''));
if ($keyword !== '' && function_exists('sanitizeInput')) {
    $keyword = (string)sanitizeInput($keyword);
}
$onlyBound = !empty($_REQUEST['only_bound']) && $_REQUEST['only_bound'] !== '0' && $_REQUEST['only_bound'] !== 'false';

$rows = $all;
if ($onlyBound) {
    $rows = array_values(array_filter($rows, fn($d) => !empty($d['user_id'])));
}
if ($keyword !== '') {
    $kw = mb_strtolower($keyword);
    $rows = array_values(array_filter($rows, function ($d) use ($kw) {
        foreach (['model', 'brand', 'os', 'app_version', 'qq', 'device_id'] as $k) {
            if (mb_stripos((string)($d[$k] ?? ''), $kw) !== false) {
                return true;
            }
        }
        return false;
    }));
}

// ---- 排序 ----
$sort = ($_REQUEST['sort'] ?? 'last') === 'first' ? 'first_seen' : 'last_seen';
usort($rows, function ($a, $b) use ($sort) {
    return strcmp((string)($b[$sort] ?? ''), (string)($a[$sort] ?? ''));
});

// ---- 分页 ----
$total      = count($rows);
$limit      = min(100, max(10, (int)($_REQUEST['limit'] ?? 30)));
$page       = max(1, (int)($_REQUEST['page'] ?? 1));
$totalPages = max(1, (int)ceil($total / $limit));
if ($page > $totalPages) {
    $page = $totalPages;
}
$rows = array_slice($rows, ($page - 1) * $limit, $limit);

// ---- 输出（device_id 截断 + 用户名补全）----
$users = [];
foreach ((array)$fs->read('users') as $u) {
    if (isset($u['id'])) {
        $users[(int)$u['id']] = $u;
    }
}

$now = time();
$devices = [];
foreach ($rows as $d) {
    $uid  = (int)($d['user_id'] ?? 0);
    $u    = $uid > 0 ? ($users[$uid] ?? null) : null;
    $last = strtotime((string)($d['last_seen'] ?? '')) ?: 0;
    $devices[] = [
        'device_id'        => mb_substr((string)($d['device_id'] ?? ''), 0, 12),
        'user_id'          => $uid,
        'nickname'         => $u ? (string)($u['nickname'] ?? '') : '',
        'qq'               => (string)($d['qq'] ?? ($u['qq'] ?? '')),
        'model'            => (string)($d['model'] ?? ''),
        'brand'            => (string)($d['brand'] ?? ''),
        'os'               => (string)($d['os'] ?? ''),
        'app_version'      => (string)($d['app_version'] ?? ''),
        'app_version_code' => (int)($d['app_version_code'] ?? 0),
        'first_seen'       => (string)($d['first_seen'] ?? ''),
        'last_seen'        => (string)($d['last_seen'] ?? ''),
        // 在线判定与前台在线统计口径一致：5 分钟内有过上报
        'online'           => ($last > 0 && ($now - $last) <= 300) ? 1 : 0,
    ];
}

jsonSuccess([
    'devices'    => $devices,
    'pagination' => [
        'total'       => $total,
        'page'        => $page,
        'limit'       => $limit,
        'total_pages' => $totalPages,
    ],
    'stats'      => lwAppStats($fs),
]);
