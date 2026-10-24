<?php
error_reporting(0);
ini_set('display_errors', '0');

require_once __DIR__ . '/../../config/config.php';
// 落库逻辑抽到内核里，AI 确认卡片走的是同一段代码（见 includes/actions/_lib.php 的说明）。
// 本文件只负责：方法/CSRF/登录/封禁/限流，以及图片上传（要摸 $_FILES）。
require_once __DIR__ . '/../../includes/actions/post_create.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonError('请求方法不允许', 405);
}

$csrfToken = $_POST['csrf_token'] ?? '';
if (!verifyCSRFToken($csrfToken)) {
    jsonError('CSRF验证失败，请刷新页面后重试', 403);
}

$user = requireLogin();

$user = checkBanned($user);
if (!empty($user['is_banned'])) {
    jsonError('账号已被封禁，无法发帖');
}

$ip = getClientIP();
if (!checkRateLimit($ip, 'post_create', 5, 300)) {
    jsonError('发帖过于频繁，请5分钟后再试', 429);
}

// 分类/可见性/标题/正文/投票的校验全部在内核 lw_do_post_create() 里，
// 与 AI 发帖路径共用同一份判定（这里不再重复校验，避免两边口径分叉）。

$imageUrls = [];
$images = '[]';
if (isset($_FILES['images']) && is_array($_FILES['images']['name']) && !empty($_FILES['images']['name'][0])) {
    $uploadDir = defined('UPLOAD_IMAGES_DIR') ? UPLOAD_IMAGES_DIR : (__DIR__ . '/../../uploads/images/');
    // UPLOAD_IMAGES_DIR 结尾没有斜杠，这里统一补上，否则会拼成 uploads/images<文件名>.jpg 的错误路径
    $uploadDir = rtrim($uploadDir, '/\\') . '/';
    if (!is_dir($uploadDir)) {
        if (!mkdir($uploadDir, 0755, true)) {
            jsonError('上传目录创建失败，请联系管理员');
        }
    }
    $allowedExts = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
    $allowedMimes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
    $maxSize = defined('MAX_SINGLE_IMAGE_SIZE') ? MAX_SINGLE_IMAGE_SIZE : 5 * 1024 * 1024;
    $maxTotalSize = defined('MAX_UPLOAD_SIZE') ? MAX_UPLOAD_SIZE : 10 * 1024 * 1024;
    $totalSize = 0;
    $uploadCount = 0;
    foreach ($_FILES['images']['tmp_name'] as $i => $tmpName) {
        if ($uploadCount >= 9) break;
        if (!isset($_FILES['images']['error'][$i]) || $_FILES['images']['error'][$i] !== UPLOAD_ERR_OK) continue;
        if (empty($tmpName)) continue;
        $ext = strtolower(pathinfo($_FILES['images']['name'][$i], PATHINFO_EXTENSION));
        if (!in_array($ext, $allowedExts)) continue;
        if ($_FILES['images']['size'][$i] > $maxSize) continue;
        $totalSize += $_FILES['images']['size'][$i];
        if ($totalSize > $maxTotalSize) break;

        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $detectedMime = $finfo->file($tmpName);
        if (!in_array($detectedMime, $allowedMimes)) continue;

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
        if (isset($magicBytes[$detectedMime])) {
            foreach ($magicBytes[$detectedMime] as $magic) {
                if (strpos($header, $magic) === 0) {
                    $validMagic = true;
                    break;
                }
            }
        }
        if (!$validMagic) continue;

        $newName = bin2hex(random_bytes(16)) . '_' . $i . '.' . $ext;
        $uploadPath = $uploadDir . $newName;
        if (move_uploaded_file($tmpName, $uploadPath)) {
            $imageUrls[] = '/uploads/images/' . $newName;
            $uploadCount++;
        }
    }
    $images = json_encode($imageUrls, JSON_UNESCAPED_UNICODE);
}


// ---- 落库与所有副作用（敏感词判定/@提醒/管理员提醒/审计）统一走内核 ----
// AI 助手的「发布帖子」确认卡片调的是同一个 lw_do_post_create()，两边行为不会分叉。
$result = lw_do_post_create($user, [
    'category'      => $_POST['category'] ?? '',
    'title'         => $_POST['title'] ?? '',
    'content'       => $_POST['content'] ?? '',
    'is_anonymous'  => (($_POST['is_anonymous'] ?? '0') === '1'),
    'visibility'    => $_POST['visibility'] ?? 'public',
    'visible_to'    => $_POST['visible_to'] ?? '',
    'exclude_to'    => $_POST['exclude_to'] ?? '',
    'images'        => $imageUrls,
    'poll_question' => $_POST['poll_question'] ?? '',
    'poll_options'  => $_POST['poll_options'] ?? '',
]);

if (empty($result['ok'])) {
    jsonError((string)($result['message'] ?? '发布失败'), (int)($result['code'] ?? 400));
}

$data = (array)($result['data'] ?? []);
jsonSuccess(['id' => $data['id'] ?? 0, 'status' => $data['status'] ?? 'published'], (string)($result['message'] ?? '发布成功'));
