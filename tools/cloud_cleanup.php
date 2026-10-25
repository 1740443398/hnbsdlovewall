<?php
/**
 * 网盘待删队列清理
 *
 * 用法：
 *   php tools/cloud_cleanup.php           # 强制重试一次
 *   php tools/cloud_cleanup.php --stats   # 只看还剩多少个
 *
 * 背景：
 *   小飞机的删除接口会被一个卡住的任务锁死整个账号，
 *   返回「任务进行中，请稍后操作」，连不存在的 ID 也一样。
 *   锁住期间删不掉的文件全部记在 data/filehelper/pending_cloud.json，
 *   这个脚本就是用来补删的。
 *
 *   有 cron 的部署方可以每 10 分钟跑一次：
 *     * /10 * * * * php /path/to/tools/cloud_cleanup.php > /dev/null 2>&1
 *
 *   没有 cron 也没关系：API 里 create / big_prepare 时会概率触发，
 *   用户正常用着就会把积压清掉。
 */
if (PHP_SAPI !== 'cli') {
    require_once __DIR__ . '/../config/config.php';
    $t = (string)($_GET['token'] ?? '');
    $expect = defined('SITE_URL') ? md5(SITE_URL . 'cloud_cleanup') : '';
    if ($expect === '' || !hash_equals($expect, $t)) {
        http_response_code(403);
        exit('forbidden');
    }
} else {
    require_once __DIR__ . '/../config/config.php';
}

require_once __DIR__ . '/../includes/filehelper.php';

$__argv = $_SERVER['argv'] ?? $argv ?? [];
$statsOnly = in_array('--stats', is_array($__argv) ? $__argv : [], true);

function fmtMb($kb)
{
    $b = $kb * 1024;
    if ($b < 1048576) {
        return round($b / 1024, 1) . ' KB';
    }
    if ($b < 1073741824) {
        return round($b / 1048576, 1) . ' MB';
    }
    return round($b / 1073741824, 2) . ' GB';
}

$pending = lwFhPendingRead();

if ($statsOnly) {
    printf("待删队列: %d 个文件\n", count($pending));
    if ($pending) {
        echo "  最老的 10 个:\n";
        foreach (array_slice($pending, 0, 10) as $id) {
            echo "    · " . $id . "\n";
        }
    }
    exit(0);
}

if (!$pending) {
    echo "待删队列是空的，没有需要清理的文件。\n";
    exit(0);
}

printf("待删队列: %d 个文件，开始重试…\n", count($pending));

$r = lwFhPendingFlush(true);

printf(
    "结果：成功 %d，失败 %d，跳过 %d，剩余 %d\n",
    $r['ok'],
    $r['fail'],
    $r['skipped'],
    $r['pending']
);

if ($r['skipped'] > 0) {
    echo "\n提示：遇到「任务进行中」，说明网盘的删除队列还被锁着。\n";
    echo "这是小飞机服务端的问题，跟本工具无关 —— 等它自己恢复后重跑即可。\n";
    echo "剩余文件已保留在队列里，不会丢。\n";
}
