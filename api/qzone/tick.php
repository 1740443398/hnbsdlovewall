<?php
/**
 * 访问触发同步（免费主机没有 cron 时的兜底）
 * ---------------------------------------------------------------------------
 * 公开端点，但**只在「该同步了」的时候才真正出网**：
 *   · 必须 enabled=1 且 auto_sync=1
 *   · 距上次尝试 ≥ sync_interval（默认 86400 秒）
 *   · 用锁文件保证同一时间只有一个请求在跑
 * 所以就算有人狂刷这个地址，24 小时内也只会真正同步一次。
 *
 * 为什么不在页面渲染时同步：
 *   同步要出网十几次（列表 + 逐条点赞），在共享主机上可能几秒到十几秒。
 *   放在页面里会直接拖慢首屏。这里由页面加载完之后的 fetch 异步触发，互不影响。
 */
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/qzone_sync.php';

header('Content-Type: application/json; charset=utf-8');
header('X-Robots-Tag: noindex');
header('Cache-Control: no-store');

$cfg = lwQzoneConfig();
if ((int) $cfg['enabled'] !== 1 || (int) $cfg['auto_sync'] !== 1) {
    echo json_encode(['ok' => false, 'skipped' => 'disabled'], JSON_UNESCAPED_UNICODE);
    exit;
}
$now = time();
if ($now - (int) $cfg['last_attempt_at'] < max(60, (int) $cfg['sync_interval'])) {
    echo json_encode(['ok' => false, 'skipped' => 'not_due'], JSON_UNESCAPED_UNICODE);
    exit;
}

/* 锁：60 秒内只允许一个请求真正执行，避免多标签/多人同时把对方接口打爆 */
$lock = dirname(__DIR__, 2) . '/data/qzone_sync.lock';
$fp = @fopen($lock, 'c');
if (!$fp) {
    echo json_encode(['ok' => false, 'skipped' => 'no_lock'], JSON_UNESCAPED_UNICODE);
    exit;
}
if (!flock($fp, LOCK_EX | LOCK_NB)) {
    fclose($fp);
    echo json_encode(['ok' => false, 'skipped' => 'busy'], JSON_UNESCAPED_UNICODE);
    exit;
}

/* 先写尝试时间，避免并发请求重复进入 */
lwQzoneSaveConfig(['last_attempt_at' => $now]);

$res = ['ok' => false, 'skipped' => 'error'];
try {
    $res = lwQzoneSync(['pages' => 1]);
} catch (Throwable $e) {
    lwQzoneSaveConfig(['last_state' => 'error', 'last_error' => '异常：' . $e->getMessage()]);
    $res = ['ok' => false, 'state' => 'error', 'err' => $e->getMessage()];
}

flock($fp, LOCK_UN);
fclose($fp);
echo json_encode($res, JSON_UNESCAPED_UNICODE);
