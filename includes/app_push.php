<?php
/**
 * App 推送与设备台账
 * ==========================================================================
 * 为什么不用 FCM / APNs：本站跑在共享虚拟主机上，拿不到推送服务凭据，
 * 而 FCM 在国内网络条件下本身也不可靠。因此采用「客户端轮询 + 本地系统通知」：
 *
 *    后台写一条推送（app_pushes 表）
 *      → App 的后台任务（NotificationWorker）定期请求 api/app_report.php
 *      → 该接口顺带返回 id > 本机 last_push_id 的推送
 *      → App 就地弹系统通知，并回写 last_push_id 游标
 *
 * 代价：不保证秒达（WorkManager 周期任务最短 15 分钟），定位是「站务公告级」，
 * 不用于实时聊天提醒（实时那条由未读数轮询 + 角标承担）。
 *
 * 隐私：设备台账只收「机型 / 系统 / App 版本 / 首末活跃时间」，不收 IMEI、
 * 手机号、通讯录；device_id 由客户端随机生成，卸载重装即变。
 */

/** 设备台账条数上限（data/*.json 每次写都是全量重写，共享主机上必须封顶） */
const LW_APP_DEVICE_MAX = 3000;
/** 推送记录条数上限 */
const LW_APP_PUSH_MAX = 500;

/**
 * 取出某台设备尚未消费的推送。
 *
 * @param mixed $fs        FileStorage 实例
 * @param int   $cursor    该设备已消费到的最大推送 id
 * @param int   $viewerUid 当前上报者的 user_id（0 = 未登录）
 * @return array{items: array, cursor: int}
 */
function lwCollectAppPushes($fs, int $cursor, int $viewerUid): array
{
    $all = $fs->read('app_pushes');
    if (!is_array($all) || !$all) {
        return ['items' => [], 'cursor' => $cursor];
    }

    $items = [];
    $maxId = $cursor;
    foreach ($all as $p) {
        $pid = (int)($p['id'] ?? 0);
        if ($pid <= $cursor) {
            continue;
        }
        // target 为空 = 全体；否则是 user_id 白名单（逗号分隔，来自后台「发给指定用户」）
        $target = trim((string)($p['target'] ?? ''));
        if ($target !== '') {
            $ids = array_filter(array_map('intval', explode(',', $target)), fn($n) => $n > 0);
            if ($viewerUid <= 0 || !in_array($viewerUid, $ids, true)) {
                continue;
            }
        }
        $items[] = [
            'id'      => $pid,
            'title'   => (string)($p['title'] ?? ''),
            'content' => (string)($p['content'] ?? ''),
            'url'     => (string)($p['url'] ?? ''),
        ];
        if ($pid > $maxId) {
            $maxId = $pid;
        }
    }

    // 一次最多推 5 条，免得客户端一口气弹二十个通知
    if (count($items) > 5) {
        $items = array_slice($items, -5);
        $maxId = (int)$items[count($items) - 1]['id'];
    }

    return ['items' => $items, 'cursor' => $maxId];
}

/**
 * 设备台账封顶：超过上限时按「最后活跃」保留最近的一批。
 */
function lwPruneAppDevices($fs): void
{
    $all = $fs->read('app_devices');
    if (!is_array($all) || count($all) <= LW_APP_DEVICE_MAX) {
        return;
    }
    usort($all, function ($a, $b) {
        return strcmp((string)($b['last_seen'] ?? ''), (string)($a['last_seen'] ?? ''));
    });
    $fs->write('app_devices', array_slice($all, 0, LW_APP_DEVICE_MAX));
}

/**
 * 后台推送列表（按 id 倒序，最新在前）。
 */
function lwAppPushList($fs, int $limit = 100): array
{
    $all = $fs->read('app_pushes');
    if (!is_array($all)) {
        return [];
    }
    usort($all, function ($a, $b) {
        return (int)($b['id'] ?? 0) <=> (int)($a['id'] ?? 0);
    });
    return array_slice($all, 0, $limit);
}

/**
 * 装机概览：总量 / 今日活跃 / 近 7 日活跃 / 已登录绑定数 / 版本分布。
 */
function lwAppStats($fs): array
{
    $all = $fs->read('app_devices');
    $all = is_array($all) ? $all : [];

    $todayStart = strtotime(date('Y-m-d 00:00:00'));
    $weekStart  = $todayStart - 6 * 86400;
    $onlineEdge = time() - 300; // 与设备列表的 online 判定同口径：5 分钟内有过上报

    $stat = [
        'total'    => count($all),
        'online'   => 0,
        'today'    => 0,
        'week'     => 0,
        'bound'    => 0,
        'versions' => [],
    ];
    foreach ($all as $d) {
        $ts = strtotime((string)($d['last_seen'] ?? '')) ?: 0;
        if ($ts >= $onlineEdge) {
            $stat['online']++;
        }
        if ($ts >= $todayStart) {
            $stat['today']++;
        }
        if ($ts >= $weekStart) {
            $stat['week']++;
        }
        if (!empty($d['user_id'])) {
            $stat['bound']++;
        }
        $v = (string)($d['app_version'] ?? '');
        if ($v !== '') {
            $stat['versions'][$v] = ($stat['versions'][$v] ?? 0) + 1;
        }
    }
    arsort($stat['versions']);
    return $stat;
}
