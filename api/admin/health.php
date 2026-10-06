<?php
/**
 * 后台 —— 系统健康检查接口。
 *
 * 一次返回全站「体检报告」，分四类：
 *   环境   PHP 版本 / 必需扩展 / 磁盘余量 / 目录可写
 *   数据   数据表体积与条数 / 残留临时文件 / 孤儿记录
 *   配置   密钥文件是否就位 / 静态资源压缩是否最新
 *   安全   黑名单规模 / 今日非法访问 / WAF 是否启用
 *
 * 全部为**只读探测**，不会修改任何数据（唯一例外是 action=gc 清理残留临时文件，
 * 且只删自己写入时留下、且超过 1 小时的 .tmp/.lock）。
 */
require_once __DIR__ . '/../../config/config.php';

$admin = requireAdmin();
$admin = checkBanned($admin);
if (!empty($admin['is_banned'])) {
    jsonError('账号已被封禁');
}

if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
    jsonError('CSRF验证失败', 403);
}

if (!checkPermission($admin, 'view_health')) {
    jsonError('无权限查看系统健康状态', 403);
}

$action = $_POST['action'] ?? 'check';
$root   = dirname(__DIR__, 2);
$dataDir = $root . '/data';

/** 把字节数转成好读的字符串 */
function lwFmtBytes(float $b): string
{
    if ($b <= 0) {
        return '0 B';
    }
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $i = 0;
    while ($b >= 1024 && $i < count($units) - 1) {
        $b /= 1024;
        $i++;
    }
    return round($b, $i === 0 ? 0 : 1) . ' ' . $units[$i];
}

// ---------------------------------------------------------------- 清理残留
if ($action === 'gc') {
    // 只清理 1 小时前的临时/锁文件。锁文件本身由 flock 使用，
    // 删除「陈旧」锁是安全的（持有者早已消失），但绝不能删当前正在用的，
    // 所以用 mtime 做年龄门槛，且只删明确的 .tmp / .sync_tmp_* / 空 .lock。
    $removed = 0;
    $freed = 0;
    $cutoff = time() - 3600;

    foreach ((array)glob($dataDir . '/*') as $path) {
        if (!is_file($path)) {
            continue;
        }
        $base = basename($path);
        $isTmp = (strpos($base, '.tmp') !== false) || (strpos($base, '.sync_tmp_') !== false);
        $isLock = substr($base, -5) === '.lock';
        if (!$isTmp && !$isLock) {
            continue;
        }
        if (filemtime($path) > $cutoff) {
            continue;
        }
        // 锁文件只有在大小为 0 时才清（有内容的锁可能正被 flock 使用）
        if ($isLock && filesize($path) > 0) {
            continue;
        }
        $sz = (int)filesize($path);
        if (@unlink($path)) {
            $removed++;
            $freed += $sz;
        }
    }

    logOperation($admin['id'], $admin['qq'], 'health_gc', 'system', '', '清理残留文件 ' . $removed . ' 个');
    jsonSuccess(['removed' => $removed, 'freed' => lwFmtBytes($freed)],
        $removed > 0 ? ('已清理 ' . $removed . ' 个残留文件，释放 ' . lwFmtBytes($freed)) : '没有需要清理的残留文件');
}

if ($action !== 'check') {
    jsonError('未知操作');
}

$fs = getFS();

// ================================================================ 环境
$requiredExt = ['curl', 'mbstring', 'json', 'gd', 'openssl'];
$extStatus = [];
$missingExt = [];
foreach ($requiredExt as $ext) {
    $ok = extension_loaded($ext);
    $extStatus[] = ['name' => $ext, 'ok' => $ok];
    if (!$ok) {
        $missingExt[] = $ext;
    }
}

$diskFree = $diskTotal = 0;
if (function_exists('disk_free_space')) {
    $diskFree  = (float)@disk_free_space($root);
    $diskTotal = (float)@disk_total_space($root);
}
$diskPercent = $diskTotal > 0 ? round($diskFree / $diskTotal * 100, 1) : 0;

$dirs = [
    ['path' => 'data',    'label' => '数据目录 data/'],
    ['path' => 'uploads', 'label' => '上传目录 uploads/'],
];
$dirStatus = [];
foreach ($dirs as $d) {
    $p = $root . '/' . $d['path'];
    $dirStatus[] = [
        'label'   => $d['label'],
        'exists'  => is_dir($p),
        'writable' => is_dir($p) && is_writable($p),
    ];
}

$environment = [
    'php_version'   => PHP_VERSION,
    'php_ok'        => version_compare(PHP_VERSION, '8.0.0', '>='),
    'server'        => $_SERVER['SERVER_SOFTWARE'] ?? '未知',
    'sapi'          => PHP_SAPI,
    'extensions'    => $extStatus,
    'missing_ext'   => $missingExt,
    'disk_free'     => lwFmtBytes($diskFree),
    'disk_total'    => lwFmtBytes($diskTotal),
    'disk_percent'  => $diskPercent,
    'dirs'          => $dirStatus,
    'https'         => defined('IS_SECURE') ? IS_SECURE : false,
];

// ================================================================ 数据
$tables = [];
$totalRows = 0;
$totalBytes = 0;
foreach ((array)glob($dataDir . '/*.json') as $path) {
    $base = basename($path, '.json');
    $size = (int)@filesize($path);
    $rows = 0;
    if ($size > 0) {
        $j = @json_decode((string)@file_get_contents($path), true);
        if (is_array($j)) {
            // 有的表是对象（如 waf_rate_limits），也计为 1 条。
            // 注意：不用 array_is_list()，它要 PHP 8.1+，而本项目要求 8.0+。
            $isList = ($j === [] || array_keys($j) === range(0, count($j) - 1));
            $rows = ($j === []) ? 0 : ($isList ? count($j) : 1);
        }
    }
    $tables[] = ['name' => $base, 'size' => $size, 'size_h' => lwFmtBytes($size), 'rows' => $rows];
    $totalRows += $rows;
    $totalBytes += $size;
}
usort($tables, function ($a, $b) { return $b['size'] <=> $a['size']; });

// 残留临时文件
$leftovers = [];
foreach ((array)glob($dataDir . '/*') as $path) {
    if (!is_file($path)) {
        continue;
    }
    $base = basename($path);
    if (strpos($base, '.tmp') !== false || strpos($base, '.sync_tmp_') !== false) {
        $leftovers[] = ['name' => $base, 'size' => lwFmtBytes((int)filesize($path))];
    }
}

// 孤儿记录：引用了不存在的帖子 / 用户的记录（数据一致性体检）
$postIds = [];
foreach ((array)$fs->read('posts') as $p) {
    $postIds[(int)($p['id'] ?? 0)] = true;
}
$userIds = [];
foreach ((array)$fs->read('users') as $u) {
    $userIds[(int)($u['id'] ?? 0)] = true;
}
$orphanComments = 0;
foreach ((array)$fs->read('comments') as $c) {
    if (!isset($postIds[(int)($c['post_id'] ?? 0)])) {
        $orphanComments++;
    }
}
$orphanLikes = 0;
foreach ((array)$fs->read('post_likes') as $l) {
    if (!isset($postIds[(int)($l['post_id'] ?? 0)])) {
        $orphanLikes++;
    }
}
$orphanFollows = 0;
foreach ((array)$fs->read('follows') as $f) {
    if (!isset($userIds[(int)($f['user_id'] ?? 0)]) || !isset($userIds[(int)($f['target_id'] ?? 0)])) {
        $orphanFollows++;
    }
}

$data = [
    'tables'         => array_slice($tables, 0, 20),
    'table_count'    => count($tables),
    'total_rows'     => $totalRows,
    'total_size'     => lwFmtBytes($totalBytes),
    'leftovers'      => $leftovers,
    'orphan_comments' => $orphanComments,
    'orphan_likes'   => $orphanLikes,
    'orphan_follows' => $orphanFollows,
];

// ================================================================ 配置
$secretFiles = [
    ['path' => 'config/ai_config.php',    'label' => 'AI 密钥配置',   'optional' => true],
    ['path' => 'config/mail_config.php',  'label' => '邮箱配置',      'optional' => true],
    ['path' => 'config/sync_config.php',  'label' => '同步密钥配置',  'optional' => true],
];
$configFiles = [];
foreach ($secretFiles as $f) {
    $exists = is_file($root . '/' . $f['path']);
    $configFiles[] = [
        'label'    => $f['label'],
        'exists'   => $exists,
        'optional' => $f['optional'],
    ];
}

// 静态资源压缩新鲜度：源文件比 .min 新 = 需要重新压缩
$staleAssets = [];
foreach ((array)glob($root . '/assets/{js,css}/*.{js,css}', GLOB_BRACE) as $src) {
    if (strpos(basename($src), '.min.') !== false) {
        continue;
    }
    $min = preg_replace('/\.(js|css)$/i', '.min.$1', $src);
    if (is_file($min) && filemtime($min) < filemtime($src)) {
        $staleAssets[] = basename($src);
    }
}

$config = [
    'files'          => $configFiles,
    'stale_assets'   => $staleAssets,
    'maintenance'    => getSetting('maintenance_mode', '0') === '1',
    'register_open'  => getSetting('register_enabled', '1') === '1',
    'growth_enabled' => getSetting('level_enabled', '1') !== '0',
];

// ================================================================ 安全
$blacklistCount = count((array)$fs->read('ip_blacklist'));

$today = date('Y-m-d');
$illegalToday = 0;
foreach ((array)$fs->read('illegal_access_logs') as $l) {
    if (substr((string)($l['created_at'] ?? ''), 0, 10) === $today) {
        $illegalToday++;
    }
}
$wafLogs = count((array)$fs->read('waf_logs'));

$security = [
    'blacklist_count'  => $blacklistCount,
    'illegal_today'    => $illegalToday,
    'illegal_total'    => count((array)$fs->read('illegal_access_logs')),
    'waf_logs'         => $wafLogs,
    'https'            => defined('IS_SECURE') ? IS_SECURE : false,
];

// ================================================================ 综合评分
$issues = [];
if (!$environment['php_ok']) {
    $issues[] = ['level' => 'error', 'text' => 'PHP 版本低于 8.0，建议升级'];
}
if ($missingExt) {
    $issues[] = ['level' => 'warn', 'text' => '缺少扩展：' . implode('、', $missingExt)];
}
foreach ($dirStatus as $d) {
    if (!$d['exists']) {
        $issues[] = ['level' => 'error', 'text' => $d['label'] . ' 不存在'];
    } elseif (!$d['writable']) {
        $issues[] = ['level' => 'error', 'text' => $d['label'] . ' 不可写'];
    }
}
if ($diskTotal > 0 && $diskPercent < 10) {
    $issues[] = ['level' => 'warn', 'text' => '磁盘剩余空间不足 10%（剩 ' . lwFmtBytes($diskFree) . '）'];
}
if ($orphanComments > 0) {
    $issues[] = ['level' => 'warn', 'text' => '存在 ' . $orphanComments . ' 条孤儿评论（所属帖子已删除）'];
}
if ($orphanLikes > 0) {
    $issues[] = ['level' => 'info', 'text' => '存在 ' . $orphanLikes . ' 条孤儿点赞记录'];
}
if ($orphanFollows > 0) {
    $issues[] = ['level' => 'info', 'text' => '存在 ' . $orphanFollows . ' 条失效的关注关系'];
}
if ($leftovers) {
    $issues[] = ['level' => 'warn', 'text' => '存在 ' . count($leftovers) . ' 个残留临时文件，可点击清理'];
}
if ($staleAssets) {
    $issues[] = ['level' => 'info', 'text' => count($staleAssets) . ' 个静态资源需要重新压缩'];
}
if (!$security['https']) {
    $issues[] = ['level' => 'warn', 'text' => '当前非 HTTPS 访问，建议开启 HTTPS'];
}
foreach ($configFiles as $f) {
    if (!$f['exists']) {
        $issues[] = ['level' => 'info', 'text' => $f['label'] . ' 未配置（' . $f['label'] . '相关功能会降级）'];
    }
}
if ($totalBytes > 8 * 1024 * 1024) {
    $issues[] = ['level' => 'info', 'text' => 'JSON 数据总量已达 ' . lwFmtBytes($totalBytes) . '，建议关注增长速度'];
}

$errorCount = 0;
foreach ($issues as $i) {
    if ($i['level'] === 'error') {
        $errorCount++;
    }
}
$score = max(0, 100 - $errorCount * 25 - (count($issues) - $errorCount) * 5);

jsonSuccess([
    'environment' => $environment,
    'data'        => $data,
    'config'      => $config,
    'security'    => $security,
    'issues'      => $issues,
    'score'       => $score,
    'checked_at'  => date('Y-m-d H:i:s'),
]);
