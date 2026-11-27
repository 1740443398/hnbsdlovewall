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

/* 【保活优先】外部 cron 每 5~24 小时打一次这个地址：先让 QQ 会话续期、吸收新密钥，
 * 再跑增量同步。这样即使没人访问、即使自动同步判断为「无需同步」，
 * Cookie 也会被持续刷新，避免「隔几周打开一看 Cookie 已过期」。
 * 节流：6 小时内不重复保活，避免把外部定时打得太频繁。 */
$keep = ['ok' => false, 'skipped' => true];
try {
    $ck = lwQzoneGetCookie();
    $lastKeep = (int) ($cfg['last_keepalive_at'] ?? 0);
    if ($ck !== '' && (time() - $lastKeep) > 6 * 3600) {
        $keep = lwQzoneKeepAlive();
        lwQzoneSaveConfig([
            'last_keepalive_at' => time(),
            'last_state'        => ($keep['state'] ?? 'idle'),
            'last_error'        => (string) ($keep['err'] ?? ''),
        ]);
    }
} catch (Throwable $e) {
    error_log('qzone keepalive cron: ' . $e->getMessage());
}

$res = lwQzoneSync(['pages' => 1]);
$res['keepalive'] = $keep;
echo json_encode($res, JSON_UNESCAPED_UNICODE);
