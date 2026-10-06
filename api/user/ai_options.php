<?php
/**
 * 用户自带的 AI 接入配置（AI 助手「高级选项」）
 * ---------------------------------------------------------------------------
 * GET                   读取当前配置（API Key 只回末 4 位）
 * POST action=save      保存
 * POST action=restore   一键还原：删掉自己的配置，回到站点默认模型
 * POST action=test      用（刚填的或已保存的）配置发一次最小请求，验证能不能通
 *
 * 三条硬规则：
 *   1. **密钥绝不回传明文**。前后端之间只走脱敏值，明文只在服务端内存里存在一瞬。
 *   2. **必须登录**。游客没有「自己的 AI」这回事。
 *   3. **一键还原只删自己的那条记录**，不触碰站点配置，也不影响别人。
 *
 * 落盘见 includes/user_ai_config.php：密文存储 + 端点白名单（防 SSRF）。
 */
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/ai_client.php';
require_once __DIR__ . '/../../includes/user_ai_config.php';

header('Content-Type: application/json; charset=utf-8');

$user = requireLogin();
$user = checkBanned($user);
if (!empty($user['is_banned'])) {
    jsonError('账号已被封禁，无法使用该功能', 403);
}
$uid = (int)$user['id'];

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

// ---------------- 读取 ----------------
if ($method === 'GET') {
    $cfg = lwUserAiConfig($uid);
    $pub = lwUserAiPublic($cfg);

    // 站点默认模型名（用于「已还原为站点默认」的提示文案）
    $siteModel = '';
    $siteCfgFile = dirname(__DIR__, 2) . '/config/ai_config.php';
    if (is_file($siteCfgFile)) {
        $siteCfg = @include $siteCfgFile;
        if (is_array($siteCfg)) {
            $siteModel = (string)($siteCfg['model'] ?? '');
        }
    }

    jsonSuccess([
        'config'       => $pub,
        'providers'    => lwUserAiProviders(),
        'site_model'   => $siteModel,
        'site_enabled' => $siteModel !== '',
    ]);
}

if ($method !== 'POST') {
    jsonError('仅支持 GET / POST', 405);
}

$csrfToken = $_POST['csrf_token'] ?? '';
if (!verifyCSRFToken($csrfToken)) {
    jsonError('CSRF 验证失败，请刷新页面后重试', 403);
}

// 保存/测试都算写操作，做一层节流，避免拿别人的站当压测机
if (!checkRateLimit('u' . $uid, 'ai_cfg_write', 20, 300)) {
    jsonError('操作过于频繁，请稍后再试', 429);
}

$action = trim((string)($_POST['action'] ?? 'save'));

// ---------------- 一键还原 ----------------
if ($action === 'restore') {
    if (!lwUserAiReset($uid)) {
        jsonError('还原失败，请稍后重试', 500);
    }
    logUserActivity($uid, 'ai_config_reset', '还原 AI 高级选项为站点默认');
    jsonSuccess(['config' => ['has' => false, 'enabled' => false]], '已还原为站内默认模型');
}

// ---------------- 测试连接 ----------------
if ($action === 'test') {
    $apiKey = trim((string)($_POST['api_key'] ?? ''));
    $saved  = lwUserAiConfig($uid);
    if ($apiKey === '' && $saved) {
        $apiKey = $saved['api_key'];   // 前端留空 = 沿用已保存的那把
    }
    if ($apiKey === '') {
        jsonError('请先填写 API Key 再测试', 400);
    }

    $v = lwUserAiValidate([
        'provider' => $_POST['provider'] ?? 'custom',
        'base_url' => $_POST['base_url'] ?? '',
        'model'    => $_POST['model'] ?? '',
        'api_key'  => $apiKey,
    ], true);
    if (!$v['ok']) {
        jsonError($v['error'], 400);
    }

    $cfg = [
        'base_url'   => $v['cfg']['base_url'],
        'api_keys'   => [$v['cfg']['api_key']],
        'model'      => $v['cfg']['model'],
        'max_tokens' => 16,
    ];

    $t0 = microtime(true);
    $r  = aiChatCompletion($cfg, [['role' => 'user', 'content' => '只回复两个字：OK']], 30);
    $ms = (int)round((microtime(true) - $t0) * 1000);

    if (!$r['ok']) {
        $err = trim((string)$r['error']);
        jsonError($err !== '' ? '连接失败：' . mb_substr($err, 0, 150) : '连接失败，请检查地址、模型名与 Key', 502);
    }

    jsonSuccess(['elapsed_ms' => $ms], '连接成功（耗时 ' . $ms . 'ms）');
}

// ---------------- 保存 ----------------
if ($action !== 'save') {
    jsonError('未知的操作类型', 400);
}

$res = lwUserAiSave($uid, [
    'provider' => $_POST['provider'] ?? '',
    'base_url' => $_POST['base_url'] ?? '',
    'model'    => $_POST['model'] ?? '',
    'api_key'  => $_POST['api_key'] ?? '',
    'enabled'  => ($_POST['enabled'] ?? '1') === '1',
]);
if (!$res['ok']) {
    jsonError($res['error'], 400);
}

logUserActivity($uid, 'ai_config_save', '更新 AI 高级选项：' . ($_POST['provider'] ?? ''));
jsonSuccess(['config' => lwUserAiPublic(lwUserAiConfig($uid))], '已保存');
