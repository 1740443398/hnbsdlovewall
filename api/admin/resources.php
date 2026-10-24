<?php
/**
 * 后台：服务器资源占用
 *
 * GET /api/admin/resources.php
 * 返回站点总占用、各目录明细、PHP 进程内存。仅管理员可访问。
 *
 * 【鉴权为什么不用 requireAdmin()】
 * requireAdmin() 内部调 requireLogin()，而未登录时它会 302 跳到登录页 ——
 * 对页面没毛病，但接口这样返回的是一整页 HTML，前端 fetch().json() 直接解析失败，
 * 后台卡片就会显示「加载失败」而不是「无权限」。
 *
 * 所以接口自己判：未登录 / 非管理员都返回 403 + JSON，前端能正确提示。
 */
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/security.php';
require_once __DIR__ . '/../../includes/resources.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: private, no-store');

// 统一以 JSON 返回错误，绝不重定向
$__fail = function (int $code, string $msg) {
    http_response_code($code);
    echo json_encode(['success' => false, 'message' => $msg], JSON_UNESCAPED_UNICODE);
    exit;
};

if (empty($_SESSION['user_id'])) {
    $__fail(403, '请先登录');
}

// getCurrentUser() 在 includes/security.php 里（第 917 行），不需要单独 include
$__me = function_exists('getCurrentUser') ? getCurrentUser() : null;
if (!$__me) {
    $__fail(403, '登录已失效，请重新登录');
}

if (!in_array((string)($__me['role'] ?? ''), ['admin', 'super_admin'], true)) {
    $__fail(403, '需要管理员权限');
}

if (!empty($__me['is_banned'])) {
    $__fail(403, '账号已被封禁');
}

try {
    // 服务器总容量：虚拟主机套餐 5GB
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
    $__fail(500, '统计失败：' . $e->getMessage());
}
