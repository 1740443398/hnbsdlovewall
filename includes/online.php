<?php
/**
 * 在线状态（心跳）——唯一来源，无定时任务。
 *
 * 表 online_users 每行 = 一个「在线主体」：
 *   登录用户 presence_key = u{user_id}；游客 presence_key = g{会话指纹}（不存原始 session id，也不暴露 IP）
 *
 * 三个硬约束（共享主机上 JSON 存储是整文件重写，必须克制）：
 *   1. 节流：同一 presence_key 在 LW_ONLINE_THROTTLE 秒内重复心跳直接返回，不写文件；
 *   2. 过期：每次写入顺手丢弃超过 LW_ONLINE_WINDOW 秒的行，并按 last_seen 保留最近 LW_ONLINE_CAP 行；
 *   3. 主动离线：页面关闭/跳走时前端用 sendBeacon 打 action=leave，立即摘掉该行（见 lwOnlineLeave）。
 *
 * 在线口径：last_seen 落在 LW_ONLINE_WINDOW 秒内的行都算在线。
 *
 * 2026-10 调优（目标是「后台看到的在线数尽量贴近真实」）：
 *   窗口 300s → 120s、节流 60s → 20s，配合前端 25s 心跳：关掉页面后最多 2 分钟从名单消失，
 *   而正常情况下 sendBeacon 会让它**立刻**消失。窗口不能压得比心跳间隔还小，
 *   否则网络抖动或后台标签页降频时会把仍开着页面的人误判为离线。
 */

if (!defined('LW_ONLINE_WINDOW')) {
    define('LW_ONLINE_WINDOW', 120);      // 2 分钟无心跳即视为离线（安全网；正常靠 leave 立即摘除）
}
if (!defined('LW_ONLINE_THROTTLE')) {
    define('LW_ONLINE_THROTTLE', 20);     // 同一主体 20 秒内不重复写盘（配合前端 25s 心跳）
}
if (!defined('LW_ONLINE_CAP')) {
    define('LW_ONLINE_CAP', 500);         // 最多保留 500 行，防异常客户端刷爆
}
if (!defined('LW_ONLINE_LEAVE_GRACE')) {
    define('LW_ONLINE_LEAVE_GRACE', 25);  // 收到 leave 后，再过这么多秒才从名单消失（见 lwOnlineLeave）
}

/**
 * 读取未过期的在线行（纯读，不写盘）。
 * @return array 行数组
 */
function lwOnlineRows(int $window = LW_ONLINE_WINDOW): array {
    $fs = getFS();
    $rows = $fs->read('online_users');
    if (!is_array($rows)) {
        return [];
    }
    $now = time();
    return array_values(array_filter($rows, function ($r) use ($now, $window) {
        return (int)($r['last_seen'] ?? 0) > $now - $window;
    }));
}

/**
 * 计算「在线主体」的标识键。
 *   登录用户 → u{user_id}（同一账号多设备/多标签只算一个人，这才是"在线人数"该有的口径）
 *   游客     → g{会话指纹前 16 位}（同一浏览器会话算一个人，不落原始 session id，也不暴露 IP）
 */
function lwPresenceKey(?array $user): string {
    if ($user === null) {
        return 'g' . substr(sha1(session_id()), 0, 16);
    }
    return 'u' . (int)$user['id'];
}

/**
 * 记录一次心跳。已在线且未过节流窗口时不写盘。
 * @param array|null $user 当前登录用户，null 表示游客
 * @return array ['total'=>int, 'members'=>int, 'guests'=>int, 'wrote'=>bool]
 */
function lwHeartbeat(?array $user): array {
    $now = time();
    $isGuest = ($user === null);
    $key = lwPresenceKey($user);

    $rows = lwOnlineRows();
    $idx = null;
    foreach ($rows as $i => $r) {
        if (($r['presence_key'] ?? '') === $key) {
            $idx = $i;
            break;
        }
    }

    // 节流：同一主体在窗口内已写过，只回报数量，不落盘
    if ($idx !== null && (int)($rows[$idx]['last_seen'] ?? 0) > $now - LW_ONLINE_THROTTLE) {
        return lwOnlineSummary($rows, false);
    }

    $nickname = $isGuest ? '' : trim((string)($user['nickname'] ?? ''));
    if (!$isGuest && $nickname === '') {
        $nickname = '用户' . (int)$user['id'];
    }
    $row = [
        'presence_key' => $key,
        'user_id' => $isGuest ? 0 : (int)$user['id'],
        'nickname' => mb_substr($nickname, 0, 40),
        'is_guest' => $isGuest ? 1 : 0,
        'role' => $isGuest ? 'guest' : (string)($user['role'] ?? 'user'),
        'last_seen' => $now,
    ];

    try {
        $fs = getFS();
        if ($idx !== null) {
            $rows[$idx] = array_merge($rows[$idx], $row);
        } else {
            $rows[] = $row;
        }
        // 超上限时按 last_seen 保留最近的行
        if (count($rows) > LW_ONLINE_CAP) {
            usort($rows, function ($a, $b) {
                return (int)($b['last_seen'] ?? 0) <=> (int)($a['last_seen'] ?? 0);
            });
            $rows = array_slice($rows, 0, LW_ONLINE_CAP);
        }
        // 直接覆盖写：行少（默认上限 500）且已节流到每人 60 秒一次
        $fs->write('online_users', array_values($rows));
    } catch (Exception $e) {
        error_log('lwHeartbeat failed: ' . $e->getMessage());
    }

    return lwOnlineSummary($rows, true);
}

/**
 * 主动离线：前端在页面关闭/离开时用 `navigator.sendBeacon` 调一次。
 *
 * 为什么必须有它：只靠"窗口超时"，用户关掉标签页后还会在名单里挂到窗口结束，
 * 后台看到的在线数就长期偏高。
 *
 * 实现上**不直接删行**，而是把这一行的 last_seen 提前到「再过 LW_ONLINE_LEAVE_GRACE 秒过期」，
 * 好处是同时解决两个问题：
 *   ① 关掉标签页 → 最多 25 秒后从名单消失（原来最长 5 分钟），接近实时；
 *   ② 同一个人还有别的标签页开着时（同账号多标签很常见），那一页的心跳会在 25 秒内把
 *      last_seen 刷回来，期间他**始终被算作在线** —— 不会出现"关掉一个标签页就被误报离线"。
 *      如果直接删行，多标签用户会反复闪进闪出，那才是真的不准。
 *
 * 另有一道竞态保护：同站跳转会先 pagehide（触发 leave）再由新页面 pageshow 补心跳，
 * 两个请求可能乱序；若发现这一行刚被写过（≤3 秒），说明新页面已经报到，直接跳过本次 leave。
 *
 * @return array 处理后的在线快照 + ['left' => bool 是否摘掉了自己那一行]
 */
function lwOnlineLeave(?array $user): array {
    $key = lwPresenceKey($user);
    $rows = lwOnlineRows();
    $now = time();

    $changed = false;
    foreach ($rows as $i => $r) {
        if (($r['presence_key'] ?? '') !== $key) {
            continue;
        }
        // 刚心跳过 → 视为同站跳转，什么都不做
        if ((int)($r['last_seen'] ?? 0) > $now - 3) {
            break;
        }
        // 把过期时间提前到 LW_ONLINE_LEAVE_GRACE 秒后
        $rows[$i]['last_seen'] = $now - max(0, LW_ONLINE_WINDOW - LW_ONLINE_LEAVE_GRACE);
        $changed = true;
        break;
    }

    if ($changed) {
        try {
            getFS()->write('online_users', array_values($rows));
        } catch (Exception $e) {
            error_log('lwOnlineLeave failed: ' . $e->getMessage());
        }
    }

    // 快照按「离开生效后」的口径算：leave 之后立即统计仍是"在线"（给多标签页留出的宽限），
    // 但 25 秒内没有新心跳就会自然过期 —— 所以这里用修改后的行做汇总。
    $sum = lwOnlineSummary($rows, $changed);
    $sum['left'] = $changed;
    return $sum;
}

/**
 * 汇总在线行：登录成员逐个列出，游客只计数（不暴露 IP / 会话）。
 */
function lwOnlineSummary(array $rows, bool $wrote = false): array {
    $members = [];
    $guests = 0;
    $now = time();
    foreach ($rows as $r) {
        if (!empty($r['is_guest'])) {
            $guests++;
            continue;
        }
        $members[] = [
            'id' => (int)($r['user_id'] ?? 0),
            'nickname' => (string)($r['nickname'] ?? ''),
            'role' => (string)($r['role'] ?? 'user'),
            'seconds_ago' => max(0, $now - (int)($r['last_seen'] ?? $now)),
        ];
    }
    usort($members, function ($a, $b) {
        return $a['seconds_ago'] <=> $b['seconds_ago'];
    });
    return [
        'total' => count($members) + $guests,
        'members' => count($members),
        'guests' => $guests,
        'users' => $members,
        'wrote' => $wrote,
    ];
}

/**
 * 后台仪表盘用：读取当前在线快照（不写盘）。
 * @param int $limit 最多返回多少个登录成员（总数仍按全量统计）
 */
function lwOnlineSnapshot(int $limit = 60): array {
    $snap = lwOnlineSummary(lwOnlineRows(), false);
    $snap['users'] = array_slice($snap['users'], 0, $limit);
    return $snap;
}
