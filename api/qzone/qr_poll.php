<?php
/**
 * 后台「QQ 扫码登录」：轮询状态
 * waiting / scanned / success / expired / none / error
 * success 时 Cookie 已由 includes/qzone_qr.php 加密写入配置。
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

echo json_encode(lwQzoneQrPoll(), JSON_UNESCAPED_UNICODE);
