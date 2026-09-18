<?php
require_once __DIR__ . '/../../config/config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonError('请求方法不允许', 405);
}

$csrfToken = $_POST['csrf_token'] ?? '';
if (!verifyCSRFToken($csrfToken)) {
    jsonError('CSRF验证失败', 403);
}

$user = requireLogin();

$user = checkBanned($user);
if ($user['is_banned']) {
    jsonError('账号已被封禁，无法上传图片');
}

if (!isset($_FILES['image'])) {
    jsonError('请选择图片');
}

$file = $_FILES['image'];

if ($file['error'] !== UPLOAD_ERR_OK) {
    jsonError('上传失败');
}

$allowedMimes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
$finfo = new finfo(FILEINFO_MIME_TYPE);
$detectedMime = $finfo->file($file['tmp_name']);
if (!in_array($detectedMime, $allowedMimes)) {
    jsonError('不支持的图片格式');
}

$handle = fopen($file['tmp_name'], 'rb');
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
if (!$validMagic) {
    jsonError('文件内容与声明类型不匹配，可能是恶意文件');
}

$imageInfo = @getimagesize($file['tmp_name']);
if ($imageInfo === false) {
    jsonError('文件不是有效图片');
}

$maxSize = defined('MAX_SINGLE_IMAGE_SIZE') ? MAX_SINGLE_IMAGE_SIZE : 5 * 1024 * 1024;
if ($file['size'] > $maxSize) {
    jsonError('图片大小不能超过5MB');
}

$extMap = [
    'image/jpeg' => '.jpg',
    'image/png'  => '.png',
    'image/gif'  => '.gif',
    'image/webp' => '.webp',
];
$ext = $extMap[$detectedMime] ?? '.jpg';

$uploadDir = defined('UPLOAD_IMAGES_DIR') ? UPLOAD_IMAGES_DIR : (__DIR__ . '/../../uploads/images/');
if (!is_dir($uploadDir)) {
    mkdir($uploadDir, 0755, true);
}

$newName = bin2hex(random_bytes(16)) . '_' . time() . $ext;
$uploadPath = $uploadDir . '/' . $newName;

if (!move_uploaded_file($file['tmp_name'], $uploadPath)) {
    jsonError('保存失败');
}

$url = '/uploads/images/' . $newName;

jsonSuccess(['url' => $url], '上传成功');