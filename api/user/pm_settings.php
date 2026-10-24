<?php
/**
 * 私信偏好接口（免打扰 / 会话置顶）。
 *
 * GET  action=get                     我当前的私信偏好（muted / pinned 列表）
 * POST action=toggle & peer_id= & flag=muted|pinned & on=0|1
 *
 * 免打扰只影响「是否写通知中心」，消息本身照常接收 —— 详见 includes/pm_settings.php。
 */
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/pm_settings.php';
require_once __DIR__ . '/../../includes/user_blocks.php';

$user = requireMember('私信设置需要注册账号后才能使用');
$user = checkBanned($user);
if (!empty($user['is_banned'])) {
    jsonError('账号已被封禁');
}

$myId = (int)$user['id'];

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $prefs = lwPmPrefs($myId);

    // 补上对方昵称，前端面板可直接展示「已免打扰：张三」
    $byId = [];
    foreach ((array)getFS()->read('users') as $u) {
        $byId[(int)($u['id'] ?? 0)] = $u;
    }
    $decorate = function (array $map) use ($byId) {
        $out = [];
        foreach ($map as $pid => $ts) {
            $u = $byId[(int)$pid] ?? null;
            $out[] = [
                'peer_id'  => (int)$pid,
                'nickname' => $u ? ($u['nickname'] ?: ('QQ:' . $u['qq'])) : '（已注销）',
                'avatar'   => $u ? ($u['avatar'] ?: getQQAvatar($u['qq'] ?? '')) : '',
                'since'    => (int)$ts,
            ];
        }
        usort($out, function ($a, $b) { return $b['since'] <=> $a['since']; });
        return $out;
    };

    jsonSuccess([
        'muted'  => $decorate($prefs['muted']),
        'pinned' => $decorate($prefs['pinned']),
    ]);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonError('请求方法不允许', 405);
}

if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
    jsonError('CSRF验证失败', 403);
}

$action = $_REQUEST['action'] ?? '';
if ($action !== 'toggle') {
    jsonError('未知操作');
}

$peerId = (int)($_POST['peer_id'] ?? 0);
$flag   = (string)($_POST['flag'] ?? '');
$on     = (string)($_POST['on'] ?? '0') === '1';

if ($peerId <= 0) {
    jsonError('缺少会话对象');
}
if (!in_array($flag, ['muted', 'pinned'], true)) {
    jsonError('不支持的设置项');
}

// 不存在或已注销的用户不允许设置（避免脏数据）
if (!getFS()->findById('users', $peerId)) {
    jsonError('用户不存在', 404);
}

$res = lwPmSetFlag($myId, $peerId, $flag, $on);
if (!$res['ok']) {
    jsonError($res['message']);
}

jsonSuccess([
    'peer_id' => $peerId,
    'flag'    => $flag,
    'on'      => $res['on'],
], $res['message']);
