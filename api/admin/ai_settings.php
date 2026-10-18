<?php
/**
 * 后台「AI 设置」接口（站点默认 AI 的读写与连通性测试）
 *
 * 安全口径（对齐 api/admin/comments.php 范例）：
 *   - 仅 POST；
 *   - CSRF 令牌（表单同源，与其它 admin 接口一致）；
 *   - 仅 super_admin：站点 AI 的 Key 是全站共享资源，改错会让所有人的助手一起瘫；
 *   - 输入白名单由 includes/site_ai_config.php 的 lwSiteAiValidate() 负责（含 SSRF 校验）；
 *   - API Key 只进不出：返回给前端的永远是脱敏值（末 4 位）。
 *
 * 动作：
 *   get_site_ai    读取当前生效配置（脱敏）
 *   save_site_ai   写入后台配置（Key 留空 = 沿用已保存的）
 *   test_site_ai   用当前配置做一次最小连通性测试
 */
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/security.php';
require_once __DIR__ . '/../../includes/ai_client.php';
require_once __DIR__ . '/../../includes/site_ai_config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonError('仅支持 POST 请求', 405);
}

$csrfToken = $_POST['csrf_token'] ?? '';
if (!verifyCSRFToken($csrfToken)) {
    jsonError('CSRF验证失败', 403);
}

$admin = requireAdmin();
$admin = checkBanned($admin);
if ($admin['is_banned']) {
    jsonError('账号已被封禁');
}

// 站点级 AI：只有超级管理员能干。只读也一样 —— 它包含全站 AI 的配额与端点信息。
if ($admin['role'] !== 'super_admin') {
    jsonError('只有超级管理员才能管理站点 AI 设置', 403);
}

$action = (string)($_POST['action'] ?? '');

if ($action === 'get_site_ai') {
    jsonSuccess(lwSiteAiPublic());
}

if ($action === 'save_site_ai') {
    $r = lwSiteAiSave([
        'provider'      => $_POST['provider'] ?? 'custom',
        'base_url'      => $_POST['base_url'] ?? '',
        'model'         => $_POST['model'] ?? '',
        'api_key'       => $_POST['api_key'] ?? '',
        'api_key_extra' => $_POST['api_key_extra'] ?? '',
        'max_tokens'    => $_POST['max_tokens'] ?? 0,
        'enabled'       => isset($_POST['enabled']) && $_POST['enabled'] === '1',
    ]);
    if (!$r['ok']) {
        jsonError($r['error'], 400);
    }
    // 回写一条操作日志，便于审计「谁在什么时候改了站点 AI」
    try {
        getFS()->insert('operation_logs', [
            'user_id'    => (int)$admin['id'],
            'nickname'   => (string)($admin['nickname'] ?? ''),
            'action'     => 'save_site_ai',
            'detail'     => '更新站点默认 AI 配置（模型=' . mb_substr((string)($_POST['model'] ?? ''), 0, 60) . '）',
            'ip'         => getClientIP(),
        ]);
    } catch (Exception $e) {
        // 日志失败不影响主流程
    }
    jsonSuccess(lwSiteAiPublic(), '已保存');
}

if ($action === 'test_site_ai') {
    $r = lwSiteAiTest();
    if (!$r['ok']) {
        jsonError((string)$r['error'], 400);
    }
    jsonSuccess($r, '连通性正常');
}

jsonError('未知操作', 400);
