<?php
require_once __DIR__ . '/../config/config.php';

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

// 与前端 _pairKey 一致：min|max 排序
function pm_key($a, $b) {
    return $a < $b ? $a . '|' . $b : $b . '|' . $a;
}

// 根据 QQ 构造会话信息（对方昵称/头像）
function pm_peer($fs, $qq, $meQq) {
    $peerQq = ($qq === $meQq ? '' : $qq);
    $other = $fs->findOne('users', ['qq' => $peerQq]);
    return [
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
        // 仅返回本用户参与且会话中至少有消息的会话
        jsonSuccess(['conversations' => $conversations]);
        break;

    // 发送一条私信
    case 'send':
        $ip = getClientIP();
        if (!checkRateLimit($ip, 'pm_send', 20, 60)) {
            jsonError('发送过于频繁，请稍后再试', 429);
        }

        $toQq = sanitizeInput($_POST['to_qq'] ?? '');
        $text = (string)($_POST['text'] ?? '');
        if (!isValidQQ($toQq)) {
            jsonError('接收方QQ号格式不正确');
        }
        if ($toQq === $meQq) {
            jsonError('不能给自己发送私信');
        }
        $msgLen = mb_strlen($text);
        if ($msgLen < 1 || $msgLen > 1000) {
            jsonError('私信内容需在1-1000字之间');
        }

        // 接收方必须存在且未被封禁
        $target = $fs->findOne('users', ['qq' => $toQq]);
        if (!$target) {
            jsonError('接收方不存在');
        }
        if (!empty($target['is_banned'])) {
            jsonError('对方账号已被封禁，无法发送私信');
        }

        $ts = (int)round(microtime(true) * 1000);
        $fs->insert('pm_messages', [
            'key' => pm_key($meQq, $toQq),
            'who' => $meQq,
            'text' => mb_substr($text, 0, 1000),
            'ts' => $ts,
        ]);

        logUserActivity($user['id'], 'send_pm', '向 ' . $toQq . ' 发送私信');
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
