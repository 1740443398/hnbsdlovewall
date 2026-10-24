<?php
/**
 * 文件传输助手：过期文件清理
 *
 * 用法：
 *   php tools/fh_cleanup.php          # 清理一次
 *   php tools/fh_cleanup.php --stats  # 只看统计，不删
 *
 * 有 cron 权限的部署方建议每分钟跑一次：
 *   * * * * * php /path/to/tools/fh_cleanup.php > /dev/null 2>&1
 *
 * 没有 cron 也没关系：API 里每次 create/upload 会概率触发清理，
 * 而且下载前会校验过期时间，过期文件不会被发出去。
 */
if (PHP_SAPI !== 'cli') {
    // 允许通过 URL 触发（带 token 防滥用）
    require_once __DIR__ . '/../config/config.php';
    $t = (string)($_GET['token'] ?? '');
    $expect = defined('SITE_URL') ? md5(SITE_URL . 'fh_cleanup') : '';
    if ($expect === '' || !hash_equals($expect, $t)) {
        http_response_code(403);
        exit('forbidden');
    }
} else {
    require_once __DIR__ . '/../config/config.php';
}

require_once __DIR__ . '/../includes/filehelper.php';

// register_argc_argv 关闭时 $argv 不存在，用 $_SERVER 兜底
$__argv = $_SERVER['argv'] ?? $argv ?? [];
$statsOnly = in_array('--stats', is_array($__argv) ? $__argv : [], true);

if ($statsOnly) {
    $relay = glob(LW_FH_DIR . '/relay/*') ?: [];
    $rooms = glob(LW_FH_DIR . '/rooms/*.json') ?: [];
    $bytes = 0;
    foreach ($relay as $f) {
        $bytes += @filesize($f) ?: 0;
    }
    printf("中转文件: %d 个，共 %s\n", count($relay), lwFhFmt($bytes));
    printf("房间: %d 个\n", count($rooms));
    exit(0);
}

$r = lwFhCleanup(true);
printf(
    "清理完成：删除文件 %d 个，删除房间 %d 个%s\n",
    $r['files'],
    $r['rooms'],
    !empty($r['skipped']) ? '（跳过）' : ''
);

function lwFhFmt($b)
{
    if ($b < 1024) return $b . ' B';
    if ($b < 1048576) return round($b / 1024, 1) . ' KB';
    if ($b < 1073741824) return round($b / 1048576, 1) . ' MB';
    return round($b / 1073741824, 2) . ' GB';
}
