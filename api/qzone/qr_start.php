<?php
/**
 * 后台「QQ 扫码登录」：取二维码
 * 管理员专属；二维码本身和 qrsig 存在 PHP session 里，不落盘、不进日志。
 */
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/security.php';
require_once __DIR__ . '/../../includes/qzone_sync.php';
require_once __DIR__ . '/../../includes/qzone_qr.php';

header('Content-Type: application/json; charset=utf-8');
header('X-Robots-Tag: noindex');
header('Cache-Control: no-store');

$adminUser = requireAdmin();
if (!checkPermission($adminUser, 'manage_settings')) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'err' => 'forbidden'], JSON_UNESCAPED_UNICODE);
    exit;
}
if (!verifyCSRFToken((string) ($_POST['csrf_token'] ?? ''))) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'err' => 'CSRF 验证失败'], JSON_UNESCAPED_UNICODE);
    exit;
}

$r = lwQzoneQrStart();
if (!$r['ok']) {
    http_response_code(502);
}
echo json_encode($r, JSON_UNESCAPED_UNICODE);
