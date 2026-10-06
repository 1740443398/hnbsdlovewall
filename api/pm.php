<?php
require_once __DIR__ . '/../config/config.php';
// 发私信的落库与通知逻辑抽到内核，AI 确认卡片走同一段代码。
// pm_key 也一并收进内核文件（两边都用 function_exists 守卫，不会重复声明）。
require_once __DIR__ . '/../includes/actions/pm_send.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrfToken = $_POST['csrf_token'] ?? '';
    if (!verifyCSRFToken($csrfToken)) {
        jsonError('CSRF验证失败', 403);
    }
}

$user = requireLogin();
$user = checkBanned($user);
if ($user['is_banned']) {
    jsonError('账号已被封禁，无法使用私信');
}

$fs = getFS();
$action = $_REQUEST['action'] ?? '';
$meQq = $user['qq'];

// 与前端 _pairKey 一致：min|max 排序。
// 实际定义在 includes/actions/pm_send.php（页面与 AI 共用），这里保留守卫式声明，
// 以免该文件没被加载时下面的其它分支拿不到函数。
if (!function_exists('pm_key')) {
    function pm_key($a, $b) {
        return $a < $b ? $a . '|' . $b : $b . '|' . $a;
    }
}

// 根据 QQ 构造会话信息（对方昵称/头像）
function pm_peer($fs, $qq, $meQq) {
    $peerQq = ($qq === $meQq ? '' : $qq);
    $other = $fs->findOne('users', ['qq' => $peerQq]);
    return [
        'id' => (int)($other['id'] ?? 0),          // 用户 id：私信偏好（免打扰/置顶）按 id 存
        'qq' => $peerQq,
        'nickname' => $other['nickname'] ?? $peerQq,
        'avatar' => $other['avatar'] ?? ('https://q.qlogo.cn/headimg_dl?dst_uin=' . $peerQq . '&spec=100'),
        'is_banned' => !empty($other['is_banned']) ? 1 : 0,
    ];
}

// 读取某会话的消息（按时间升序，最多取最近 500 条）
function pm_messages($fs, $key, $meQq) {
    $all = $fs->read('pm_messages');
    $list = [];
    foreach ($all as $m) {
        if (($m['key'] ?? '') !== $key) continue;
        $list[] = $m;
    }
    usort($list, function ($a, $b) { return (int)$a['ts'] - (int)$b['ts']; });
    if (count($list) > 500) {
        $list = array_slice($list, -500);
    }
    return array_map(function ($m) {
        return ['who' => $m['who'], 'text' => $m['text'], 'ts' => (int)$m['ts']];
    }, $list);
}

// 读取某会话的已读时间戳（返回 {qq: lastReadTs}）
function pm_read_map($fs, $key) {
    $all = $fs->read('pm_read');
    $map = [];
    foreach ($all as $r) {
        if (($r['key'] ?? '') !== $key) continue;
        $map[$r['qq']] = (int)$r['last_read_ts'];
    }
    return $map;
}

switch ($action) {

    // 同步当前用户的所有会话
    case 'sync':
        $all = $fs->read('pm_messages');
        $conversations = [];
        $hasConvo = [];
        foreach ($all as $m) {
            if (($m['key'] ?? '') === '') continue;
            $parts = explode('|', $m['key']);
            if (!in_array($meQq, $parts)) continue;
            if ($hasConvo[$m['key']] ?? false) continue;
            $hasConvo[$m['key']] = true;
            $peerQq = $parts[0] === $meQq ? $parts[1] : $parts[0];
            $conversations[$m['key']] = [
                'peer' => pm_peer($fs, $peerQq, $meQq),
                'read' => pm_read_map($fs, $m['key']),
                'messages' => pm_messages($fs, $m['key'], $meQq),
            ];
        }

        // 附加「免打扰 / 置顶 / 是否屏蔽」三个状态，并把置顶会话排到最前。
        // 会话本身**不隐藏**（哪怕已屏蔽）—— 直接让历史消息消失会让用户以为丢了数据，
        // 正确做法是保留可读、仅禁止继续发送（发送闸门在 includes/actions/pm_send.php）。
        $prefs = lwPmPrefs((int)$user['id']);
        $hidden = lwHiddenUserIds((int)$user['id']);
        foreach ($conversations as $k => &$cv) {
            $pid = (string)($cv['peer']['id'] ?? 0);
            $cv['muted']   = isset($prefs['muted'][$pid]);
            $cv['pinned']  = isset($prefs['pinned'][$pid]);
            $cv['blocked'] = isset($hidden[(int)$pid]);
        }
        unset($cv);

        // 置顶优先排序（稳定排序，同组内保持原有插入顺序）
        $idx = 0;
        $order = [];
        foreach ($conversations as $k => $cv) {
            $order[$k] = ['pinned' => !empty($cv['pinned']), 'i' => $idx++];
        }
        uksort($conversations, function ($a, $b) use ($order) {
            $pa = $order[$a]['pinned'] ? 0 : 1;
            $pb = $order[$b]['pinned'] ? 0 : 1;
            return $pa !== $pb ? ($pa <=> $pb) : ($order[$a]['i'] <=> $order[$b]['i']);
        });

        // 仅返回本用户参与且会话中至少有消息的会话
        jsonSuccess(['conversations' => $conversations]);
        break;

    // 发送一条私信
    case 'send':
        $ip = getClientIP();
        if (!checkRateLimit($ip, 'pm_send', 20, 60)) {
            jsonError('发送过于频繁，请稍后再试', 429);
        }

        // 校验、落库、通知全部由内核完成（AI 发私信走的是同一个 lw_do_pm_send()）
        $r = lw_do_pm_send($user, [
            'to_qq' => $_POST['to_qq'] ?? '',
            'text'  => (string)($_POST['text'] ?? ''),
        ]);
        if (empty($r['ok'])) {
            jsonError((string)($r['message'] ?? '发送失败'), (int)($r['code'] ?? 400));
        }

        $toQq = (string)($r['data']['to_qq'] ?? '');
        $key = pm_key($meQq, $toQq);
        jsonSuccess([
            'key' => $key,
            'peer' => pm_peer($fs, $toQq, $meQq),
            'read' => pm_read_map($fs, $key),
            'messages' => pm_messages($fs, $key, $meQq),
        ], '私信已发送');
        break;

    // 标记会话为已读
    case 'read':
        $peerQq = sanitizeInput($_POST['to_qq'] ?? '');
        if (!isValidQQ($peerQq)) {
            jsonError('接收方QQ号格式不正确');
        }
        $key = pm_key($meQq, $peerQq);
        // 有会话才写已读
        $has = pm_messages($fs, $key, $meQq);
        if ($has) {
            $existing = $fs->findOne('pm_read', ['key' => $key, 'qq' => $meQq]);
            if ($existing) {
                $fs->update('pm_read', $existing['id'], ['last_read_ts' => (int)round(microtime(true) * 1000)]);
            } else {
                $fs->insert('pm_read', [
                    'key' => $key,
                    'qq' => $meQq,
                    'last_read_ts' => (int)round(microtime(true) * 1000),
                ]);
            }
        }
        jsonSuccess([], '已标记为已读');
        break;

    default:
        jsonError('未知操作', 400);
}