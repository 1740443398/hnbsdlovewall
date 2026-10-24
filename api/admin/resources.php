<?php
/**
 * 后台：服务器资源占用
 *
 * GET /api/admin/resources.php
 * 返回站点总占用、各目录明细、PHP 进程内存。
 * 仅管理员可访问。
 */
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/security.php';
require_once __DIR__ . '/../../includes/resources.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: private, no-store');

if (empty($user) || !in_array((string)($user['role'] ?? ''), ['admin', 'super_admin'], true)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => '无权限'], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    // 服务器总容量：虚拟主机套餐给的是 5GB
    $usage = lwResourceUsage(5 * 1024 * 1024 * 1024);
    $mem = lwPhpMemory();

    echo json_encode([
        'success' => true,
        'data' => [
            'usage' => $usage,
            'php_memory' => $mem,
        ],
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => '统计失败：' . $e->getMessage(),
    ], JSON_UNESCAPED_UNICODE);
}
