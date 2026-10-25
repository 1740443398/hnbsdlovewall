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
/**
 * App 装机统计。
 *
 * 【两个口径必须分开看】
 *
 *   devices   —— 设备台账条数（一台手机一条）
 *   users     —— **去重后的人数**
 *
 * 为什么要去重：同一台手机卸载重装会换 device_id，同一个人换手机也会多一条。
 * 如果只报「设备数」，装机量会被系统性高估。
 *
 * 【去重规则：同机型 + 同账号 = 同一个人】
 *   判断键 = user_id + '|' + 机型指纹
 *
 *   - 两个字段都有值 → 按组合去重（同一个人在同一台机器上重装，算一个人）
 *   - 只有 user_id（游客没登录 / 机型没上报）→ 按 user_id 去重
 *   - 只有机型 → 按机型去重
 *   - 两者都没有 → 每条单独算一个（device_id 兜底，不合并）
 *
 * 为什么用「机型」而不是 device_id 做人的标识：
 *   device_id 是客户端随机生成、卸载重装即换，它标识的是「这次安装」而不是「人」。
 *   机型 + 账号才稳定地指向「某个人在用某台机器」。
 *
 * 注意这是一条**启发式**规则，不是精确判定：
 *   同一台机器上换账号登录会被算成两个人（这符合直觉 —— 确实换人了）；
 *   同一个人在两台机器上登录会被算成两个人（也符合直觉 —— 确实是两台设备）。
 */
function lwAppUserKey(array $d): string
{
    $uid   = (int)($d['user_id'] ?? 0);
    $model = mb_strtolower(trim((string)($d['model'] ?? '')));
    $brand = mb_strtolower(trim((string)($d['brand'] ?? '')));
    $fp    = trim($brand . '|' . $model, '|');

    if ($uid > 0 && $fp !== '') {
        return 'u' . $uid . '|m' . $fp;      // 同一个人 + 同一台机器
    }
    if ($uid > 0) {
        return 'u' . $uid;                    // 只知道是谁
    }
    if ($fp !== '') {
        return 'm' . $fp;                     // 只知道什么机器
    }
    return 'd' . (string)($d['device_id'] ?? '');   // 都没有，按安装算
}

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
        // 去重后的人数（同机型 + 同账号算一个）
        'users'          => 0,
        'users_online'   => 0,
        'users_today'    => 0,
        'users_week'     => 0,
        'users_bound'    => 0,
    ];

    // 用「判断键 -> 该用户最近活跃时间」做去重
    $seen = [];
    $seenBound = [];

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

        // ---- 人去重 ----
        $key = lwAppUserKey($d);
        if (!isset($seen[$key]) || $ts > $seen[$key]) {
            $seen[$key] = $ts;
        }
        if (!empty($d['user_id'])) {
            $seenBound[$key] = true;
        }
    }

    $stat['users'] = count($seen);
    foreach ($seen as $key => $ts) {
        if ($ts >= $onlineEdge) {
            $stat['users_online']++;
        }
        if ($ts >= $todayStart) {
            $stat['users_today']++;
        }
        if ($ts >= $weekStart) {
            $stat['users_week']++;
        }
    }
    $stat['users_bound'] = count($seenBound);

    arsort($stat['versions']);
    return $stat;
}

/**
 * 把设备台账按「同机型 + 同账号」合并成人。
 *
 * 后台列表用这个函数展示：同一台机器装了两遍只显示一行，
 * 但保留「安装次数」让管理员知道发生过重装。
 *
 * @return array 每个人一条，按最后活跃时间倒序
 */
function lwAppUsers(array $devices): array
{
    $groups = [];

    foreach ($devices as $d) {
        $key = lwAppUserKey($d);
        $ts  = strtotime((string)($d['last_seen'] ?? '')) ?: 0;

        if (!isset($groups[$key])) {
            $groups[$key] = [
                'key'         => $key,
                'user_id'     => (int)($d['user_id'] ?? 0),
                'nickname'    => '',
                'qq'          => (string)($d['qq'] ?? ''),
                'model'       => (string)($d['model'] ?? ''),
                'brand'       => (string)($d['brand'] ?? ''),
                'os'          => (string)($d['os'] ?? ''),
                'app_version' => (string)($d['app_version'] ?? ''),
                'app_version_code' => (int)($d['app_version_code'] ?? 0),
                'first_seen'  => (string)($d['first_seen'] ?? ''),
                'last_seen'   => (string)($d['last_seen'] ?? ''),
                'installs'    => 0,          // 这个「人」对应几条设备记录
                'device_ids'  => [],
                '_ts'         => 0,
            ];
        }

        $g = &$groups[$key];
        $g['installs']++;
        $g['device_ids'][] = mb_substr((string)($d['device_id'] ?? ''), 0, 12);

        // 取最近一次活跃的信息
        if ($ts > $g['_ts']) {
            $g['_ts'] = $ts;
            $g['last_seen'] = (string)($d['last_seen'] ?? '');
            $g['app_version'] = (string)($d['app_version'] ?? '');
            $g['app_version_code'] = (int)($d['app_version_code'] ?? 0);
            if (!empty($d['model'])) {
                $g['model'] = (string)$d['model'];
            }
            if (!empty($d['os'])) {
                $g['os'] = (string)$d['os'];
            }
        }
        // 首次安装取最早
        $fs0 = strtotime((string)($d['first_seen'] ?? '')) ?: PHP_INT_MAX;
        $cur0 = strtotime($g['first_seen']) ?: PHP_INT_MAX;
        if ($fs0 < $cur0) {
            $g['first_seen'] = (string)($d['first_seen'] ?? '');
        }
        // 昵称 / QQ 取有值的
        if ($g['nickname'] === '' && !empty($d['nickname'])) {
            $g['nickname'] = (string)$d['nickname'];
        }
        if ($g['qq'] === '' && !empty($d['qq'])) {
            $g['qq'] = (string)$d['qq'];
        }
        unset($g);
    }

    $out = [];
    $now = time();
    foreach ($groups as $g) {
        unset($g['_ts']);
        $g['online'] = (strtotime($g['last_seen']) ?: 0) >= $now - 300 ? 1 : 0;
        $g['device_ids'] = array_values(array_unique($g['device_ids']));
        $out[] = $g;
    }

    usort($out, function ($a, $b) {
        return strcmp((string)$b['last_seen'], (string)$a['last_seen']);
    });

    return $out;
}
