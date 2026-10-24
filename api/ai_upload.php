<?php
/**
 * AI 助手多模态图片上传端点
 * ---------------------------------------------------------------------------
 * 浏览器把用户挑的图片以 multipart 上传到这里，服务端做完整校验后落到
 * uploads/ai/，只返回一个站内相对地址（/uploads/ai/xxx.jpg）。
 * 前端把该地址随消息一起发给 api/ai_stream.php / api/ai_assistant.php，
 * 服务端再把它交给多模态模型。
 *
 * 安全要点：
 *   1. 必须登录或合法游客（与对话入口同口径），并过同源 + CSRF。
 *   2. MIME + 魔数双重校验，拒绝伪装成图片的可执行文件。
 *   3. 单张 ≤ MAX_SINGLE_IMAGE_SIZE、单次 ≤ 4 张、独立限流，防刷盘。
 *   4. 文件名随机生成，绝不采用用户上传的原始文件名（防目录穿越/覆盖）。
 *
 * 保留策略：这些图片在服务器只保留 30 天，由 includes/security.php 的
 * pruneAiImages() 清理；后台「AI 调用记录」也可手动删除。
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/security.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonError('仅支持 POST 请求', 405);
}

// ---- 同源校验（与 ai_chat_flow 同规则）----
$reqHost = $_SERVER['HTTP_HOST'] ?? '';
$origin  = $_SERVER['HTTP_ORIGIN'] ?? '';
if ($origin !== '') {
    $originHost = parse_url($origin, PHP_URL_HOST);
    $reqHostOnly = parse_url('http://' . $reqHost, PHP_URL_HOST);
    if ($originHost === false || $originHost !== $reqHostOnly) {
        error_log('AI 上传：拒绝跨源请求，origin=' . $origin);
        jsonError('请求来源不合法', 403);
    }
}

// ---- CSRF（multipart 表单字段）----
if (!verifyCSRFTokenEnhanced((string)($_POST['csrf_token'] ?? ''))) {
    jsonError('页面已过期，请刷新后重试', 403);
}

$user = requireLoginOrGuest();
$isGuest = ($user === null);
if ($user) {
    $user = checkBanned($user);
    if ($user['is_banned']) {
        jsonError('账号已被封禁，无法上传图片', 403);
    }
}

// ---- 限流：游客按会话、登录用户按 uid，独立于文本提问的额度 ----
if ($isGuest) {
    if (!checkRateLimit('sess_' . session_id(), 'ai_upload_guest', 10, 60)) {
        jsonError('上传有点频繁，请稍后再试', 429);
    }
} else {
    if (!checkRateLimit('u' . (int)$user['id'], 'ai_upload_user', 30, 60)) {
        jsonError('上传有点频繁，请稍后再试', 429);
    }
}

if (!isset($_FILES['image']) || !is_array($_FILES['image'])) {
    jsonError('没有收到图片');
}
// 统一成「多文件数组」形态，单张也能走同一段逻辑
$file = $_FILES['image'];
$names = is_array($file['name']) ? $file['name'] : [$file['name']];

$allowedMimes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
$allowedExts  = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
$maxSize = defined('MAX_SINGLE_IMAGE_SIZE') ? MAX_SINGLE_IMAGE_SIZE : 5 * 1024 * 1024;

$uploadDir = UPLOAD_DIR . '/ai/';
if (!is_dir($uploadDir)) {
    if (!@mkdir($uploadDir, 0755, true) && !is_dir($uploadDir)) {
        jsonError('服务器无法创建上传目录', 500);
    }
}
// 防目录列出/执行脚本：目录内禁一切动态执行
$htaccess = $uploadDir . '.htaccess';
if (!is_file($htaccess)) {
    @file_put_contents($htaccess, "php_flag engine off\nOptions -Indexes\n<FilesMatch \"\\.(php|phtml|phar)$\">\nRequire all denied\n</FilesMatch>\n");
}

$urls = [];
$count = 0;
foreach ($names as $i => $origName) {
    if ($count >= 4) break;
    $err = is_array($file['error']) ? ($file['error'][$i] ?? UPLOAD_ERR_NO_FILE) : $file['error'];
    if ($err !== UPLOAD_ERR_OK) continue;
    $tmpName = is_array($file['tmp_name']) ? ($file['tmp_name'][$i] ?? '') : $file['tmp_name'];
    $size    = is_array($file['size']) ? ($file['size'][$i] ?? 0) : $file['size'];
    if ($tmpName === '' || !is_uploaded_file($tmpName)) continue;
    if ($size <= 0 || $size > $maxSize) continue;

    $ext = strtolower(pathinfo((string)$origName, PATHINFO_EXTENSION));
    if (!in_array($ext, $allowedExts, true)) continue;

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $detectedMime = $finfo->file($tmpName);
    if (!in_array($detectedMime, $allowedMimes, true)) continue;

    // 魔数校验：防止把脚本改名成 .jpg 混进来
    $handle = fopen($tmpName, 'rb');
    $header = fread($handle, 8);
    fclose($handle);
    $magicBytes = [
        'image/jpeg' => ["\xFF\xD8\xFF"],
        'image/png'  => ["\x89PNG"],
        'image/gif'  => ["GIF87a", "GIF89a"],
        'image/webp' => ["RIFF"],
    ];
    $validMagic = false;
    foreach ($magicBytes[$detectedMime] ?? [] as $magic) {
        if (strpos($header, $magic) === 0) { $validMagic = true; break; }
    }
    if (!$validMagic) continue;

    $newName = bin2hex(random_bytes(16)) . '_' . time() . '.' . $ext;
    if (@move_uploaded_file($tmpName, $uploadDir . $newName)) {
        $urls[] = '/uploads/ai/' . $newName;
        $count++;
    }
}

if (empty($urls)) {
    jsonError('图片校验未通过（仅支持 JPG/PNG/GIF/WEBP，单张不超过 '
        . round($maxSize / 1048576, 1) . 'MB）');
}

error_log('AI 上传：用户=' . ($user ? (int)$user['id'] : 'guest') . '，成功 ' . count($urls) . ' 张');
jsonSuccess(['urls' => $urls], '上传成功');
