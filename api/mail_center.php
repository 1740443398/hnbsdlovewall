<?php
/**
 * 邮箱中心 API（用户端）
 *
 * 动作：
 *   status     我的邮箱状态（站内地址 / 绑定信息 / 配额 / 封禁状态）
 *   list_inbox 收件箱列表
 *   list_sent  已发送列表
 *   send       发送（from_kind: site | bound）
 *   fetch      拉取收件（kind: bound | site）
 *   bind       绑定自有邮箱（邮箱 + 授权码）
 *   test_bind  绑定连通性自检
 *   unbind     解除绑定
 *   detail     查看单封（自己的邮件）
 *
 * 安全：全部要求登录；写操作要求 CSRF；封禁用户直接拒绝。
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../includes/mail_center.php';

header('Content-Type: application/json; charset=utf-8');

$user = requireLogin();
$user = checkBanned($user);
$uid = (int)$user['id'];

$action = (string)($_REQUEST['action'] ?? 'status');

/* 读操作 */
if ($action === 'status') {
    $acct = lwMcAcct($uid);
    $cfg = lwMcCfg();
    $bound = $acct['bound'] ?? null;
    jsonSuccess([
        'site_addr' => (string)($acct['site_addr'] ?? ''),
        'banned' => !empty($acct['banned']),
        'ban_reason' => (string)($acct['ban_reason'] ?? ''),
        'bound' => $bound ? [
            'email' => (string)$bound['email'],
            'display_name' => (string)($bound['display_name'] ?? ''),
            'smtp' => trim((string)$bound['smtp_host']) . ':' . (int)$bound['smtp_port'],
            'imap' => trim((string)$bound['imap_host']) . ':' . (int)$bound['imap_port'],
            'bound_at' => (string)($bound['bound_at'] ?? ''),
        ] : null,
        'quota' => lwMcQuota($uid),
        'mail_domain' => (string)($cfg['mail_domain'] ?? 'bsd.ct.ws'),
        'catchall_enabled' => !empty($cfg['catchall']['enabled']),
    ]);
}

if ($action === 'list_inbox') {
    if (lwMcBanned($uid)) { jsonError('邮箱功能已被管理员停用', 403); }
    $rows = lwMcStoreGet('inbox', $uid);
    $out = [];
    foreach ($rows as $r) {
        unset($r['risk_hits']);
        $out[] = $r;
    }
    jsonSuccess(['list' => array_slice($out, 0, 100)]);
}

if ($action === 'list_sent') {
    if (lwMcBanned($uid)) { jsonError('邮箱功能已被管理员停用', 403); }
    $rows = lwMcStoreGet('sent', $uid);
    foreach ($rows as &$r) { unset($r['risk_hits']); }
    unset($r);
    jsonSuccess(['list' => array_slice($rows, 0, 100)]);
}

if ($action === 'detail') {
    if (lwMcBanned($uid)) { jsonError('邮箱功能已被管理员停用', 403); }
    $msgid = (string)($_REQUEST['msgid'] ?? '');
    $msg = null;
    foreach (['inbox', 'sent'] as $kind) {
        foreach (lwMcStoreGet($kind, $uid) as $r) {
            if (($r['msgid'] ?? '') === $msgid) { $msg = $r; break 2; }
        }
    }
    if (!$msg) { jsonError('邮件不存在或无权查看', 404); }
    jsonSuccess(['msg' => $msg]);
}

/* 写操作：POST + CSRF */
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonError('该操作仅支持 POST 请求', 405);
}
if (!verifyCSRFToken((string)($_POST['csrf_token'] ?? ''))) {
    jsonError('CSRF验证失败', 403);
}

if ($action === 'send') {
    $r = lwMcSend(
        $uid,
        ($_POST['from_kind'] ?? 'site') === 'bound' ? 'bound' : 'site',
        (string)($_POST['to'] ?? ''),
        (string)($_POST['subject'] ?? ''),
        (string)($_POST['text'] ?? '')
    );
    if (!$r['ok']) {
        jsonError($r['err'], $r['risk'] === 'high' ? 422 : 400);
    }
    jsonSuccess(['risk' => $r['risk']], '发送成功');
}

if ($action === 'fetch') {
    $kind = ($_POST['kind'] ?? 'bound') === 'site' ? 'site' : 'bound';
    $r = lwMcFetch($uid, $kind);
    if (!$r['ok']) { jsonError($r['err']); }
    jsonSuccess(['fetched' => $r['fetched']], $r['fetched'] > 0 ? '新收 ' . $r['fetched'] . ' 封邮件' : '暂无新邮件');
}

if ($action === 'bind') {
    $r = lwMcBind($uid, [
        'email' => (string)($_POST['email'] ?? ''),
        'pass' => (string)($_POST['pass'] ?? ''),
        'smtp_host' => (string)($_POST['smtp_host'] ?? ''),
        'smtp_port' => (string)($_POST['smtp_port'] ?? ''),
        'secure' => (string)($_POST['secure'] ?? ''),
        'imap_host' => (string)($_POST['imap_host'] ?? ''),
        'imap_port' => (string)($_POST['imap_port'] ?? ''),
        'display_name' => (string)($_POST['display_name'] ?? ''),
    ]);
    if (!$r['ok']) { jsonError($r['err']); }
    $t = lwMcBindTest($uid);
    jsonSuccess(['smtp_ok' => $t['ok']], $t['ok'] ? '绑定成功，授权码验证通过' : '绑定已保存，但授权码验证未通过：' . $t['err']);
}

if ($action === 'test_bind') {
    $t = lwMcBindTest($uid);
    jsonSuccess(['smtp_ok' => $t['ok']], $t['ok'] ? '连接正常，授权码有效' : $t['err']);
}

if ($action === 'unbind') {
    lwMcUnbind($uid);
    jsonSuccess([], '已解除绑定');
}

jsonError('未知操作', 400);
