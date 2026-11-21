<?php
/**
 * QQ 空间同步的定时触发端点
 * ---------------------------------------------------------------------------
 * 免费主机没有真正的 cron，用外部定时服务（如 cron-job.org）定时 GET 这个地址。
 * 鉴权靠配置里的 cron_token（在后台可重置）；令牌只能触发同步，读不到 Cookie。
 *
 * 建议间隔：24 小时（对应后台「最小同步间隔」默认 86400 秒）。
 */
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/qzone_sync.php';

header('Content-Type: application/json; charset=utf-8');
header('X-Robots-Tag: noindex');

$cfg   = lwQzoneConfig();
$token = isset($_GET['token']) ? (string) $_GET['token'] : '';
$want  = (string) ($cfg['cron_token'] ?? '');

if ($want === '' || $token === '' || !hash_equals($want, $token)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'err' => 'invalid token'], JSON_UNESCAPED_UNICODE);
    exit;
}
if ((int) $cfg['enabled'] !== 1) {
    echo json_encode(['ok' => false, 'err' => 'sync disabled'], JSON_UNESCAPED_UNICODE);
    exit;
}
if ((int) $cfg['auto_sync'] !== 1) {
    echo json_encode(['ok' => false, 'err' => 'auto_sync disabled (用后台「立即同步一次」)'], JSON_UNESCAPED_UNICODE);
    exit;
}

$res = lwQzoneSync(['pages' => 1]);
echo json_encode($res, JSON_UNESCAPED_UNICODE);
