<?php
/**
 * 附件上传接口（需求一、6：任意非可读类文件资源）
 *
 * 动作：
 *   upload  上传一个文件（multipart，字段名 file）
 *   mine    我上传过的附件列表
 *   del     删除我自己的附件（管理员可删任意）
 *
 * 安全：登录 + CSRF + 每日配额 + 扩展名白名单 + 魔数拦截（见 includes/attachments.php）。
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../includes/attachments.php';

header('Content-Type: application/json; charset=utf-8');

$user = requireLogin();
$user = checkBanned($user);
if (!empty($user['is_banned'])) {
    jsonError('账号已被封禁，无法上传文件', 403);
}

$action = (string)($_REQUEST['action'] ?? '');

// 每次请求先清扫过期文件（服务器只保留 1 小时）。
// 免费共享主机没有 cron，只能靠「有人来访问」这条路径驱动清理；
// 放在这里比放在下载页更早生效 —— 列表里不会再出现已经该消失的文件。
lwAttachPurgeExpired();

if ($action === 'mine') {
    $rows = getFS()->read('attachments');
    $mine = [];
    foreach ((array)$rows as $r) {
        if ((int)($r['user_id'] ?? 0) !== (int)$user['id']) continue;
        $left = lwAttachSecondsLeft($r);
        if ($left <= 0) {
            continue; // 理论上已被清扫，这里再兜一层
        }
        $mine[] = [
            'id' => (int)$r['id'],
            'name' => (string)($r['orig_name'] ?? ''),
            'ext' => (string)($r['ext'] ?? ''),
            'size' => (int)($r['size'] ?? 0),
            'note' => (string)($r['note'] ?? ''),
            'downloads' => (int)($r['downloads'] ?? 0),
            'ts' => (int)($r['ts'] ?? 0),
            'expires_in' => $left,
            'url' => '/pages/file_download.php?id=' . (int)$r['id'],
        ];
    }
    usort($mine, function ($a, $b) { return $b['ts'] <=> $a['ts']; });
    jsonSuccess([
        'files' => array_slice($mine, 0, 100),
        'quota_left' => lwAttachQuotaLeft((int)$user['id']),
        'quota_used' => lwAttachQuotaUsed((int)$user['id']),
        'daily_bytes' => LW_FILE_DAILY_BYTES,
        'ttl' => LW_FILE_TTL,
        'max_size' => LW_FILE_MAX_SIZE,
        'exts' => array_keys(lwAttachExtMap()),
    ]);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonError('该操作仅支持 POST 请求', 405);
}

if (!verifyCSRFToken((string)($_POST['csrf_token'] ?? ''))) {
    jsonError('页面已过期，请刷新后重试', 403);
}

if ($action === 'upload') {
    if (!checkRateLimit('u' . (int)$user['id'], 'attach_upload', 15, 60)) {
        jsonError('上传过于频繁，请稍后再试', 429);
    }
    if (!isset($_FILES['file'])) {
        jsonError('没有收到文件', 400);
    }
    $r = lwAttachStore($user, $_FILES['file'], (string)($_POST['note'] ?? ''));
    if (!$r['ok']) {
        jsonError($r['error'], 400);
    }
    jsonSuccess([
        'id' => $r['id'],
        'url' => $r['url'],
        'name' => $r['name'],
        'size' => $r['size'],
        'ttl' => LW_FILE_TTL,
        'quota_left' => lwAttachQuotaLeft((int)$user['id']),
    ], '上传成功，服务器将保留 1 小时');
}

if ($action === 'del') {
    $id = (int)($_POST['id'] ?? 0);
    $row = $id > 0 ? getFS()->findOne('attachments', ['id' => $id]) : null;
    if (!$row) {
        jsonError('附件不存在', 404);
    }
    $isOwner = (int)($row['user_id'] ?? 0) === (int)$user['id'];
    $isAdmin = in_array($user['role'] ?? '', ['admin', 'super_admin'], true);
    if (!$isOwner && !$isAdmin) {
        jsonError('无权删除该附件', 403);
    }
    $abs = lwAttachDir() . '/' . basename((string)($row['stored'] ?? ''));
    if (is_file($abs)) {
        @unlink($abs);
    }
    getFS()->delete('attachments', $id);
    jsonSuccess([], '已删除');
}

jsonError('未知操作', 400);
