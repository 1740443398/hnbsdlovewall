<?php
error_reporting(0);
ini_set('display_errors', '0');

require_once __DIR__ . '/../../config/config.php';

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

$category = sanitizeInput($_POST['category'] ?? '');
$title = trim($_POST['title'] ?? '');
$content = trim($_POST['content'] ?? '');
$isAnonymous = ($_POST['is_anonymous'] ?? '0') === '1';
$visibility = sanitizeInput($_POST['visibility'] ?? 'public');
$visibleTo = sanitizeInput($_POST['visible_to'] ?? '');
$excludeTo = sanitizeInput($_POST['exclude_to'] ?? '');

$categories = ['lost_found', 'study_help', 'social_chat', 'confession', 'school_info', 'other'];
$isAdmin = in_array($user['role'] ?? '', ['admin', 'super_admin']);
if ($isAdmin) {
    $categories[] = 'announcement';
}
if (!in_array($category, $categories)) {
    jsonError('分类选择无效');
}

if (!in_array($visibility, ['public', 'visible_to', 'exclude_to'])) {
    jsonError('可见权限设置无效');
}

if ($title !== '' && (mb_strlen($title) < 2 || mb_strlen($title) > 200)) {
    jsonError('标题长度应在2-200字符之间');
}

if (mb_strlen($content) < 5 || mb_strlen($content) > 5000) {
    jsonError('内容长度应在5-5000字符之间');
}

$imageUrls = [];
$images = '[]';
if (isset($_FILES['images']) && is_array($_FILES['images']['name']) && !empty($_FILES['images']['name'][0])) {
    $uploadDir = defined('UPLOAD_IMAGES_DIR') ? UPLOAD_IMAGES_DIR : (__DIR__ . '/../../uploads/images/');
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

$fs = getFS();

// 投票字段（可选）：question + options
$poll = null;
$pollQuestion = trim($_POST['poll_question'] ?? '');
$pollOptionsRaw = trim($_POST['poll_options'] ?? '');
if ($pollQuestion !== '' || $pollOptionsRaw !== '') {
    if ($pollQuestion === '' ) {
        jsonError('请填写投票标题');
    }
    if (mb_strlen($pollQuestion) > 200) {
        jsonError('投票标题不能超过200字符');
    }
    $pollOptions = array_values(array_filter(array_map('trim', preg_split('/[\r\n,，]/u', $pollOptionsRaw)), function ($o) {
        return $o !== '';
    }));
    if (count($pollOptions) < 2) {
        jsonError('投票至少需要2个选项');
    }
    if (count($pollOptions) > 10) {
        jsonError('投票最多支持10个选项');
    }
    foreach ($pollOptions as $opt) {
        if (mb_strlen($opt) > 50) {
            jsonError('单个选项不能超过50字符');
        }
    }
    $poll = [
        'question' => $pollQuestion,
        'options' => array_slice($pollOptions, 0, 10),
        'votes' => new stdClass() // JSON 空对象，避免被当成数组
    ];
}

$hasSensitive = !empty(checkSensitiveWords($title . ' ' . $content));

$status = 'published';
if ($hasSensitive && $category !== 'announcement') {
    $status = 'pending';
}

$postData = [
    'user_id' => $user['id'],
    'category' => $category,
    'title' => $title ?: '',
    'content' => $content,
    'images' => $images,
    'is_anonymous' => $isAnonymous ? 1 : 0,
    'visibility' => $visibility,
    'visible_to' => $visibleTo,
    'exclude_to' => $excludeTo,
    'status' => $status,
    'likes' => 0,
    'comments' => 0,
    'poll' => $poll
];

$dataDir = __DIR__ . '/../../data/';
if (!is_dir($dataDir)) {
    if (!@mkdir($dataDir, 0755, true)) {
        jsonError('发布失败：data目录创建失败，请联系管理员检查文件权限');
    }
}
if (!is_writable($dataDir)) {
    jsonError('发布失败：data目录不可写，请联系管理员检查文件权限');
}

try {
    $post = $fs->insert('posts', $postData);
} catch (Exception $e) {
    error_log('Post creation failed: ' . $e->getMessage());
    jsonError('发布失败，请稍后重试');
}

if (!$post) {
    $freeSpace = @disk_free_space($dataDir);
    $errorMsg = '发布失败：数据写入失败';
    if ($freeSpace !== false && $freeSpace < 1048576) {
        $errorMsg .= '（磁盘空间不足）';
    } else {
        $errorMsg .= '，请稍后重试或联系管理员';
    }
    jsonError($errorMsg);
}

logUserActivity($user['id'], 'post_create', '发布帖子ID:' . $post['id']);

jsonSuccess([
    'id' => $post['id'],
    'status' => $status
], $hasSensitive ? '发布成功，内容需审核' : '发布成功');