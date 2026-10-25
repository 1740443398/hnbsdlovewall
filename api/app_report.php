<?php
/**
 * App 安装 / 活跃上报（公开接口，无需登录）
 * ---------------------------------------------------------------------------
 * 客户端在「首次启动」与「后台定期轮询」时调用本接口，一次性完成两件事：
 *
 *   1. 上报设备信息 → 后台「App 用户」页据此统计装机量与活跃度；
 *   2. 收取待推送   → 返回 id > 本机 last_push_id 的后台推送，App 就地弹系统通知。
 *      合并成一次请求是为了省流量：客户端的每一次往返在移动网络下都要付出电量代价。
 *
 * 参数（POST）：
 *   device_id          客户端首次启动生成并持久化的随机串（16~64 位十六进制）
 *   action             install（仅首次）/ heartbeat（默认）
 *   model / brand / os 机型、厂商、系统版本（仅用于后台展示，服务端会截断）
 *   app_version / app_version_code
 *
 * 返回 data：{ device_id, is_new, push: [{id,title,content,url}], push_cursor, server_time }
 *
 * 隐私：不采集 IMEI、手机号、通讯录；不落 IP 到台账里。device_id 是客户端随机生成的，
 * 卸载重装即变，无法跨设备追踪个人。
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/app_push.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonError('仅支持 POST 请求', 405);
}

// 独立限流：客户端会定期调用，配额给足，但拦住脚本刷量
/* 【放宽】120 次 / 分钟本来够，但校园网常见一个出口 IP
 * 后面站着几十个学生（NAT）。所有人共用同一个配额，
 * 一到课间就集体「请求过于频繁」，心跳和推送全断。
 * 放宽到 600 次 / 分钟，按人均仍然很宽松。
 */
if (!checkRateLimit(getClientIP(), 'app_report', 600, 60)) {
    jsonError('请求过于频繁', 429);
}

/** 截断 + 去控制字符，防止把上报接口当存储滥用 */
$clip = function ($v, int $max = 48): string {
    $s = preg_replace('/[\x00-\x1F\x7F]/u', '', (string)$v);
    $s = trim($s === null ? '' : $s);
    return function_exists('mb_substr') ? mb_substr($s, 0, $max) : substr($s, 0, $max);
};

$deviceId = strtolower($clip($_POST['device_id'] ?? '', 64));
if (!preg_match('/^[a-f0-9]{16,64}$/', $deviceId)) {
    jsonError('设备标识不合法');
}

$action = (($_POST['action'] ?? '') === 'install') ? 'install' : 'heartbeat';
$now    = date('Y-m-d H:i:s');
$user   = getCurrentUser();
$fs     = getFS();

$fields = [
    'model'            => $clip($_POST['model'] ?? ''),
    'brand'            => $clip($_POST['brand'] ?? '', 32),
    'os'               => $clip($_POST['os'] ?? '', 32),
    'app_version'      => $clip($_POST['app_version'] ?? '', 16),
    'app_version_code' => max(0, (int)($_POST['app_version_code'] ?? 0)),
    'last_seen'        => $now,
];
if ($user) {
    $fields['user_id'] = (int)$user['id'];
    $fields['qq']      = (string)($user['qq'] ?? '');
}

$isNew = false;
$row = $fs->findOne('app_devices', ['device_id' => $deviceId]);

/* 【同一台设备被记成多条的问题】
 *
 * 原来只按 device_id 匹配。但 device_id 是客户端首次启动生成并存在
 * SharedPreferences 里的 —— 用户「清除数据」「重装」「换手机迁移」
 * 都会得到一个新 id，于是同一部手机在台账里堆成好几条。
 *
 * 实测过最夸张的情况：站长在同一台 Pixel 8 Pro 上测试，
 * 11 条设备记录里 9 条都是他，实际只有 2 台设备。
 *
 * 所以补一条兜底匹配：**已登录 + 同机型** 也认为是同一台设备，
 * 只更新那条记录（并把 device_id 换成新的，保持后续心跳能命中）。
 *
 * 为什么要求「已登录」才做这个匹配：
 *   游客没有 user_id，同机型可能是完全不同的两个人（比如同宿舍同款手机），
 *   合并他们会让装机量被低估得更多。
 */
if (!$row && !empty($fields['user_id']) && ($fields['model'] ?? '') !== '') {
    foreach ((array)$fs->read('app_devices') as $cand) {
        if ((int)($cand['user_id'] ?? 0) !== (int)$fields['user_id']) {
            continue;
        }
        if ((string)($cand['model'] ?? '') !== (string)$fields['model']) {
            continue;
        }
        $row = $cand;
        // device_id 换成客户端当前这个，后续心跳才能直接命中
        $fields['device_id'] = $deviceId;
        break;
    }
}

if ($row) {
    $fs->update('app_devices', $row['id'], $fields);
    $row = array_merge($row, $fields);
} else {
    $fields['device_id']    = $deviceId;
    $fields['first_seen']   = $now;
    $fields['last_push_id'] = 0;
    $created = $fs->insert('app_devices', $fields);
    if (!$created) {
        jsonError('设备登记失败，请稍后再试', 500);
    }
    $row   = $created;
    $isNew = true;
    lwPruneAppDevices($fs);
}

// 收取未读推送，并把游标推进（客户端下次只拿更新的）
$cursor    = max(0, (int)($row['last_push_id'] ?? 0));
$collected = lwCollectAppPushes($fs, $cursor, (int)($row['user_id'] ?? 0));
if ($collected['cursor'] > $cursor) {
    $fs->update('app_devices', $row['id'], ['last_push_id' => $collected['cursor']]);
}

jsonSuccess([
    'device_id'   => $deviceId,
    'is_new'      => $isNew,
    'push'        => $collected['items'],
    'push_cursor' => $collected['cursor'],
    'server_time' => $now,
]);
