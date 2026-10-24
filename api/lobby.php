<?php
/**
 * 公共聊天室接口（room）
 * ---------------------------------------------------------------------------
 * 为什么文件名不叫 chat：站点硬约束「接口名/URL 参数禁 chat」，
 * 且分类 social_chat 在 URL 里一律用别名 social。这里统一用 room / lobby。
 *
 * 动作：
 *   list   拉最近消息（GET，登录后可用；带 since 参数可只取增量）
 *   send   发文本消息（POST）
 *   upload 发图片/GIF（POST multipart；每日 20 张上限）
 *   quota  查今天的图片剩余额度
 *
 * 保留策略（需求一、5）：
 *   - 消息只留 1 天（每次请求顺手清理过期行）
 *   - 单用户每日最多 20 张图
 *   - 服务端只持久化最新 20 张图片资源
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../includes/lobby.php';

header('Content-Type: application/json; charset=utf-8');

$user = requireLogin();
$user = checkBanned($user);
if (!empty($user['is_banned'])) {
    jsonError('账号已被封禁，无法使用聊天室', 403);
}

$action = (string)($_REQUEST['action'] ?? 'list');

// 每次请求顺手做过期清理（超过 24h 的消息行 + 磁盘图片）
// 节流：同一会话 60 秒内只真正扫一次，避免每条消息都整表遍历
function lwLobbyPruneThrottled() {
    $key = 'lw_lobby_prune_ts';
    $last = isset($_SESSION[$key]) ? (int)$_SESSION[$key] : 0;
    if (time() - $last < 60) {
        return;
    }
    $_SESSION[$key] = time();
    lwLobbyPrune();
    lwLobbyEnforceImageCap();
}

if ($action === 'list') {
    $since = (int)($_GET['since'] ?? 0);
    lwLobbyPruneThrottled();

    $limit = (int)($_GET['limit'] ?? 50);
    if ($limit < 1) $limit = 50;
    if ($limit > 100) $limit = 100;

    $msgs = lwLobbyMessages($limit);
    if ($since > 0) {
        $msgs = array_values(array_filter($msgs, function ($m) use ($since) {
            return (int)$m['ts'] > $since;
        }));
    }

    $lastTs = 0;
    foreach ($msgs as $m) {
        if ((int)$m['ts'] > $lastTs) $lastTs = (int)$m['ts'];
    }

    jsonSuccess([
        'messages' => $msgs,
        'last_ts' => $lastTs,
        'quota_left' => lwLobbyImageQuotaLeft((int)$user['id']),
        'retain_hours' => LW_LOBBY_RETAIN_HOURS,
        'daily_images' => LW_LOBBY_DAILY_IMAGES,
        'keep_images' => LW_LOBBY_KEEP_IMAGES,
    ]);
}

if ($action === 'quota') {
    jsonSuccess([
        'quota_left' => lwLobbyImageQuotaLeft((int)$user['id']),
        'daily_images' => LW_LOBBY_DAILY_IMAGES,
    ]);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonError('该操作仅支持 POST 请求', 405);
}

// 写操作统一校验 CSRF（表单字段；上传也走同一字段）
if (!verifyCSRFToken((string)($_POST['csrf_token'] ?? ''))) {
    jsonError('页面已过期，请刷新后重试', 403);
}

if ($action === 'send') {
    if (!checkRateLimit('u' . (int)$user['id'], 'lobby_send', 20, 60)) {
        jsonError('发送过于频繁，请稍后再试', 429);
    }
    $r = lwLobbyAppend($user, 'text', (string)($_POST['text'] ?? ''));
    if (!$r['ok']) {
        jsonError($r['error'], 400);
    }
    jsonSuccess(['id' => $r['id']], '已发送');
}

if ($action === 'upload') {
    // 双闸门：
    //   ① 每分钟 20 张（手稿口径，防刷主闸门，按用户计数）
    //   ② 每日 20 张（兜底上限，按 (user,date) 落库计数）
    if (!checkRateLimit('u' . (int)$user['id'], 'lobby_upload_min', LW_LOBBY_MINUTE_IMAGES, 60)) {
        jsonError('每分钟最多上传 ' . LW_LOBBY_MINUTE_IMAGES . ' 张图片，请稍后再试', 429);
    }
    if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
        jsonError('没有收到文件，或上传失败', 400);
    }
    if (lwLobbyImageQuotaLeft((int)$user['id']) <= 0) {
        jsonError('今天的图片额度已用完（每日 ' . LW_LOBBY_DAILY_IMAGES . ' 张）', 429);
    }

    $tmp = $_FILES['file']['tmp_name'];
    $size = (int)$_FILES['file']['size'];
    $maxSize = defined('MAX_SINGLE_IMAGE_SIZE') ? MAX_SINGLE_IMAGE_SIZE : 5 * 1024 * 1024;
    if ($size <= 0 || $size > $maxSize) {
        jsonError('图片大小超出限制（单张最大 ' . round($maxSize / 1048576, 1) . ' MB）', 400);
    }

    // MIME + 魔数双重校验：GIF 动图也在允许列表内（需求一、5 明确支持 GIF）
    $allowed = [
        'image/jpeg' => ['jpg', ["\xFF\xD8\xFF"]],
        'image/png'  => ['png', ["\x89PNG"]],
        'image/gif'  => ['gif', ["GIF87a", "GIF89a"]],
        'image/webp' => ['webp', ["RIFF"]],
    ];
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = $finfo->file($tmp);
    if (!isset($allowed[$mime])) {
        jsonError('只支持 JPG / PNG / GIF / WebP 图片', 400);
    }
    $ext = $allowed[$mime][0];
    $fh = fopen($tmp, 'rb');
    $head = $fh ? fread($fh, 8) : '';
    if ($fh) fclose($fh);
    $okMagic = false;
    foreach ($allowed[$mime][1] as $magic) {
        if (strpos($head, $magic) === 0) { $okMagic = true; break; }
    }
    if (!$okMagic) {
        jsonError('文件内容不是有效图片', 400);
    }

    $dir = lwLobbyDir();
    $name = bin2hex(random_bytes(16)) . '_0.' . $ext;
    if (!@move_uploaded_file($tmp, $dir . '/' . $name)) {
        jsonError('保存失败，请稍后重试', 500);
    }

    // 扣额度（并发下以 DB 行为准，扣失败就回滚文件）
    if (!lwLobbyConsumeImageQuota((int)$user['id'])) {
        @unlink($dir . '/' . $name);
        jsonError('今天的图片额度已用完（每日 ' . LW_LOBBY_DAILY_IMAGES . ' 张）', 429);
    }

    $rel = '/uploads/lobby/' . $name;
    $r = lwLobbyAppend($user, 'image', (string)($_POST['text'] ?? ''), $rel);
    if (!$r['ok']) {
        @unlink($dir . '/' . $name);
        jsonError($r['error'], 400);
    }

    // 只保留最新 20 张：超出的删文件 + 抹掉消息里的图片字段
    lwLobbyEnforceImageCap();

    jsonSuccess([
        'id' => $r['id'],
        'image' => $rel,
        'quota_left' => lwLobbyImageQuotaLeft((int)$user['id']),
    ], '已发送');
}

jsonError('未知操作', 400);
