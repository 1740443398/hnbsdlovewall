<?php
/**
 * 接收本地「QQ Cookie 助手」推送的 Cookie（自动续期方案）
 * ---------------------------------------------------------------------------
 * 为什么需要：skey/p_skey 只能由 QQ 登录（扫码）签发，服务器无法凭空算。
 * 所以由站长本机的浏览器保持登录，定时把 Cookie 加密推到本站落盘。
 *
 * 鉴权：复用后台 QQ 空间配置里的 cron_token（16 位十六进制），
 *       不引入新的密钥，生成/重置都在 /admin/qzone.php。
 * 传参：POST JSON { token, uin, cookie }；cookie 支持 Cookie 行/JSON/纯串。
 */
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/includes/qzone_sync.php';

header('Content-Type: application/json; charset=utf-8');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'POST only']); exit;
}

$raw = file_get_contents('php://input');
$j = json_decode($raw, true);
if (!is_array($j)) { http_response_code(400); echo json_encode(['ok' => false, 'error' => 'invalid json']); exit; }

$cfg = lwQzoneConfig();
$token = (string) ($cfg['cron_token'] ?? '');
if ($token === '' || !hash_equals($token, (string) ($j['token'] ?? ''))) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'token 无效，请先在后台 QQ 空间配置里生成 cron_token']);
    exit;
}

$cookie = lwQzoneNormalizeCookieInput((string) ($j['cookie'] ?? ''));
$uin = preg_replace('/[^0-9]/', '', (string) ($j['uin'] ?? ''));
if ($cookie === '') { http_response_code(400); echo json_encode(['ok' => false, 'error' => 'cookie 为空']); exit; }

$pSkey = lwQzoneCookieValue($cookie, 'p_skey');
$skey  = lwQzoneCookieValue($cookie, 'skey');
if ($pSkey === '' || $skey === '') {
    echo json_encode(['ok' => false, 'error' => '缺少 skey/p_skey，请确认已在浏览器登录 QQ 空间后重试']);
    exit;
}
if ($uin === '') { $uin = preg_replace('/[^0-9]/', '', lwQzoneCookieValue($cookie, 'uin')); }

lwQzoneSaveConfig([
    'uin'          => $uin,
    'cookie_enc'   => lwQzoneEncrypt($cookie),
    'cookie_at'    => date('Y-m-d H:i:s'),
    'last_state'   => 'ok',
    'last_error'   => '',
]);

echo json_encode(['ok' => true, 'uin' => $uin, 'saved_at' => date('Y-m-d H:i:s')]);
