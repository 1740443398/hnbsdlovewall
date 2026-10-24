<?php
/**
 * 统一通知中心
 *
 * 站点已有 notifications 表与「已读即删」语义，但缺一个**聚合视图**：
 * 用户想知道「今天有多少条、分别是哪类、哪些还没看」，
 * 原先进到消息页只能看到一串平铺的条目。
 *
 * GET  ?action=summary         聚合摘要（按类型计数 + 未读数）
 * GET  ?action=list&type=x&page=1
 * POST action=read&id=N
 * POST action=read_all
 * POST action=mute&type=x&on=1  按类型静音（写 user_prefs）
 */
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/enhance.php';

$user = requireMember('通知中心需要注册账号');
$user = checkBanned($user);
if (!empty($user['is_banned'])) {
    jsonError('账号已被封禁');
}
$uid = (int)$user['id'];
$fs = getFS();
$action = $_REQUEST['action'] ?? 'summary';

/** 我的全部通知。 */
function lwMyNotifs(int $uid): array
{
    $out = [];
    foreach (lwEnhRead('notifications') as $n) {
        if ((int)($n['user_id'] ?? 0) === $uid) {
            $out[] = $n;
        }
    }
    return $out;
}

/** 我的静音设置。 */
function lwMyMuted(int $uid): array
{
    foreach (lwEnhRead('user_prefs', ['user_id' => $uid]) as $r) {
        if ((string)($r['key'] ?? '') === 'muted_types') {
            $v = json_decode((string)($r['value'] ?? '[]'), true);
            return is_array($v) ? $v : [];
        }
    }
    return [];
}

if ($action === 'summary') {
    $rows = lwMyNotifs($uid);
    $byType = [];
    $unread = 0;
    foreach ($rows as $n) {
        $t = (string)($n['type'] ?? 'system');
        if (!isset($byType[$t])) {
            $byType[$t] = ['type' => $t, 'total' => 0, 'unread' => 0];
        }
        $byType[$t]['total']++;
        if (empty($n['is_read'])) {
            $byType[$t]['unread']++;
            $unread++;
        }
    }
    // 按未读降序
    $list = array_values($byType);
    usort($list, static fn($a, $b) => $b['unread'] <=> $a['unread'] ?: $b['total'] <=> $a['total']);
    jsonSuccess([
        'total' => count($rows),
        'unread' => $unread,
        'by_type' => $list,
        'muted' => lwMyMuted($uid),
    ]);
}

if ($action === 'list') {
    $type = (string)($_GET['type'] ?? '');
    $muted = lwMyMuted($uid);
    $rows = lwMyNotifs($uid);
    $out = [];
    foreach ($rows as $n) {
        $t = (string)($n['type'] ?? 'system');
        if ($type !== '' && $t !== $type) continue;
        if (in_array($t, $muted, true)) continue;
        $out[] = [
            'id' => (int)$n['id'],
            'type' => $t,
            'content' => (string)($n['content'] ?? ''),
            'post_id' => (int)($n['post_id'] ?? 0),
            'is_read' => !empty($n['is_read']),
            'created_at' => (string)($n['created_at'] ?? ''),
        ];
    }
    usort($out, static fn($a, $b) => $b['id'] <=> $a['id']);
    $page = max(1, (int)($_GET['page'] ?? 1));
    $limit = min(50, max(1, (int)($_GET['limit'] ?? 20)));
    $total = count($out);
    $offset = ($page - 1) * $limit;
    jsonSuccess([
        'notifications' => array_slice($out, $offset, $limit),
        'pagination' => ['total' => $total, 'page' => $page, 'limit' => $limit, 'has_more' => $offset + $limit < $total],
    ]);
}

// ---- 写操作 ----
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonError('请求方法不允许', 405);
}
if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
    jsonError('CSRF验证失败', 403);
}

if ($action === 'read') {
    $id = (int)($_POST['id'] ?? 0);
    $n = $fs->findById('notifications', $id);
    if (!$n || (int)($n['user_id'] ?? 0) !== $uid) {
        jsonError('通知不存在', 404);
    }
    // 与站点既有语义一致：已读即删除
    $fs->delete('notifications', $id);
    jsonSuccess([], '已读');
}

if ($action === 'read_all') {
    $n = 0;
    foreach (lwMyNotifs($uid) as $row) {
        $fs->delete('notifications', $row['id']);
        $n++;
    }
    jsonSuccess(['cleared' => $n], '已清空');
}

if ($action === 'mute') {
    $type = trim((string)($_POST['type'] ?? ''));
    $on = (string)($_POST['on'] ?? '1') === '1';
    if ($type === '') jsonError('请指定类型');
    $muted = lwMyMuted($uid);
    $muted = array_values(array_filter($muted, static fn($x) => $x !== $type));
    if ($on) $muted[] = $type;

    // upsert
    $found = false;
    foreach (lwEnhRead('user_prefs', ['user_id' => $uid]) as $r) {
        if ((string)($r['key'] ?? '') === 'muted_types') {
            $fs->update('user_prefs', $r['id'], [
                'value' => json_encode($muted, JSON_UNESCAPED_UNICODE),
            ]);
            $found = true;
            break;
        }
    }
    if (!$found) {
        lwEnhInsert('user_prefs', [
            'user_id' => $uid,
            'key' => 'muted_types',
            'value' => json_encode($muted, JSON_UNESCAPED_UNICODE),
        ]);
    }
    jsonSuccess(['muted' => $muted], $on ? '已静音该类通知' : '已恢复该类通知');
}

jsonError('未知操作');
