<?php
require_once __DIR__ . '/../../config/config.php';

/**
 * 管理员「图片备份」——把 uploads/ 打包成 ZIP 流式下载。
 *
 * 为什么要单独一个端点：
 *   JSON 备份里只记录图片的**文件名**，图片实体在 uploads/，两处是分离的。
 *   于是「完整留档」= JSON（数据）+ 本 ZIP（图片），缺一不可。
 *   把图片塞进 JSON 会 base64 膨胀 33% 且无法增量；放进同一个 ZIP 又会拖慢高频的数据备份。
 *
 * ZIP 结构：
 *   media/<uploads 下的原始相对路径>   （保留子目录，便于按原路径还原）
 *   manifest.json                      （每个文件的相对路径 / 字节数 / SHA-1 / 修改时间）
 *
 * manifest 的用途：可通过它校验下载完整性；也可与 JSON 里的图片文件名清单做差集，
 * 找出「数据里引用了但备份包里没有」的图片（即历史丢失文件）。
 * 哈希用 SHA-1：这里是**完整性比对**不是防篡改签名，SHA-1 足够且比 SHA-256 明显更快。
 */

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    die('请求方法不允许，请使用POST请求');
}

if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
    http_response_code(403);
    die('CSRF验证失败');
}

// 与 JSON 备份同等级：仅超级管理员
$admin = requireSuperAdmin();
$admin = checkBanned($admin);
if (!empty($admin['is_banned'])) {
    http_response_code(403);
    die('账号已被封禁，无法下载备份');
}

// 二次校验：当前登录密码
$pwd = trim($_POST['password'] ?? '');
if ($pwd === '' || !verifyPassword($pwd, $admin['password_hash'])) {
    header('Location: /admin/backup.php?bkerr=' . rawurlencode('当前登录密码不正确，无法下载备份'));
    exit();
}

// 已启用 2FA 的账号需再输一次 TOTP
if (!empty($admin['twofa_secret'])) {
    require_once __DIR__ . '/../../includes/totp.php';
    $code = trim($_POST['twofa'] ?? '');
    if ($code === '') {
        header('Location: /admin/backup.php?bkerr=' . rawurlencode('该账号已启用双重验证，请输入Authenticator中的6位验证码'));
        exit();
    }
    $totp = new TOTP($admin['twofa_secret']);
    if (!$totp->verify($code)) {
        header('Location: /admin/backup.php?bkerr=' . rawurlencode('双重验证码不正确，请重试'));
        exit();
    }
}

if (!class_exists('ZipArchive')) {
    header('Location: /admin/backup.php?bkerr=' . rawurlencode('服务器未启用 ZipArchive 扩展，无法打包图片'));
    exit();
}

$uploadsDir = realpath(__DIR__ . '/../../uploads');
if ($uploadsDir === false || !is_dir($uploadsDir)) {
    header('Location: /admin/backup.php?bkerr=' . rawurlencode('uploads/ 目录不存在，暂无可备份的图片'));
    exit();
}

@set_time_limit(0);

/** 递归收集 uploads/ 下的文件（返回 [绝对路径, 相对路径] 列表） */
$collect = function ($dir, $base = '') use (&$collect) {
    $out = [];
    $entries = @scandir($dir);
    if ($entries === false) {
        return $out;
    }
    foreach ($entries as $name) {
        if ($name === '.' || $name === '..') {
            continue;
        }
        $path = $dir . DIRECTORY_SEPARATOR . $name;
        $rel = ($base === '') ? $name : $base . '/' . $name;
        if (is_dir($path)) {
            $out = array_merge($out, $collect($path, $rel));
        } elseif (is_file($path)) {
            $out[] = ['path' => $path, 'rel' => $rel];
        }
    }
    return $out;
};

$files = $collect($uploadsDir);
if (empty($files)) {
    header('Location: /admin/backup.php?bkerr=' . rawurlencode('uploads/ 目录为空，暂无可备份的图片'));
    exit();
}

// 打包到 data/ 下的临时文件（现场流式生成 ZIP 不可能，ZipArchive 只能写文件）
$tmpZip = __DIR__ . '/../../data/bk_media_' . date('Ymd_His') . '.zip';
$zip = new ZipArchive();
if ($zip->open($tmpZip, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
    header('Location: /admin/backup.php?bkerr=' . rawurlencode('无法创建备份临时文件，请检查 data/ 目录是否可写'));
    exit();
}

$manifestFiles = [];
$totalBytes = 0;
foreach ($files as $f) {
    $entry = 'media/' . $f['rel'];
    if (!$zip->addFile($f['path'], $entry)) {
        continue;
    }
    $size = (int)@filesize($f['path']);
    $totalBytes += $size;
    $manifestFiles[] = [
        'path' => $f['rel'],
        'bytes' => $size,
        'sha1' => @sha1_file($f['path']) ?: '',
        'mtime' => date('Y-m-d H:i:s', (int)@filemtime($f['path'])),
    ];
}

$manifest = [
    'app' => 'love_wall',
    'generator' => 'admin_backup_media',
    'exported_at' => date('Y-m-d H:i:s'),
    'exported_by' => 'super_admin',
    'hash_algorithm' => 'sha1',
    'total_files' => count($manifestFiles),
    'total_bytes' => $totalBytes,
    'files' => $manifestFiles,
];
$zip->addFromString('manifest.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

$readme = 'README_图片备份说明.txt';
$zip->addFromString($readme, "图片备份（uploads/）\n=====================\n\n"
    . "1) media/         —— 帖子图片原始文件，保留 uploads/ 下的子目录结构。\n"
    . "2) manifest.json  —— 每个文件的相对路径、字节数、SHA-1、修改时间。\n\n"
    . "还原方法：把 media/ 下的内容按原相对路径覆盖回服务器的 uploads/ 目录即可。\n"
    . "完整性校验：解压后逐个文件算 SHA-1，与 manifest.json 对比。\n"
    . "缺失排查：把本清单的文件名集合，与数据备份 JSON 里各帖 images 字段引用的文件名做差集，\n"
    . "          差集即「数据里引用但实际不存在」的历史丢失图片。\n");
if (method_exists($zip, 'setExternalAttributesName')) {
    $zip->setExternalAttributesName($readme, ZipArchive::OPSYS_UNIX, 0100644 << 16, ZipArchive::FL_ENC_UTF_8);
}

if (!$zip->close()) {
    @unlink($tmpZip);
    header('Location: /admin/backup.php?bkerr=' . rawurlencode('图片打包失败（可能超出服务器磁盘或执行时长限制）'));
    exit();
}

if (function_exists('logUserActivity')) {
    logUserActivity($admin['id'], 'backup_media_download', '超级管理员下载图片备份：' . count($manifestFiles) . ' 个文件 / ' . $totalBytes . ' 字节');
}

// ── 流式输出：与 JSON 备份同样的坑，必须关掉 zlib 与输出缓冲，
//    否则 Content-Length 报的是未压缩长度，客户端会一直等不满字节数。
$zlibConfigured = (bool)ini_get('zlib.output_compression');
@ini_set('zlib.output_compression', '0');
while (ob_get_level() > 0) {
    ob_end_clean();
}

$filename = 'media_' . date('Ymd_His') . '.zip';
header('Content-Type: application/zip');
header('Content-Disposition: attachment; filename="' . $filename . '"');
if (!$zlibConfigured && ob_get_level() === 0) {
    header('Content-Length: ' . (string)@filesize($tmpZip));
}
header('Cache-Control: no-store, no-cache, must-revalidate');
header('X-Accel-Buffering: no');

$fp = @fopen($tmpZip, 'rb');
if ($fp === false) {
    @unlink($tmpZip);
    http_response_code(500);
    die('读取备份文件失败');
}
while (!feof($fp)) {
    echo fread($fp, 256 * 1024);
    @flush();
    if (connection_aborted()) {
        break;
    }
}
fclose($fp);
@unlink($tmpZip);
exit;