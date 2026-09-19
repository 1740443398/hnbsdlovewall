<?php
/**
 * AI / 开发者 数据同步端点（秘密通道）
 * ----------------------------------------
 * 用途：供站长（含 AI 助手）在「已在本机部署并可直连本服务」的前提下，
 * 不经过邮件验证码申请流程，直接用密钥拉取 / 写回全站 JSON 数据，用于更新或同步本地数据。
 *
 * 鉴权：
 *   密钥来自 config/sync_config.php 的 $SYNC_CFG['secret_key']（该文件已被 .gitignore 忽略，
 *   开源版不含密钥值）。可通过请求头 X-Sync-Key: 或查询参数 ?key= 传递，使用 hash_equals 恒时间比较。
 *
 * 接口：
 *   GET  /api/ai_sync.php                → 返回全站完整备份 JSON（等同「导出完整备份」）
 *   POST /api/ai_sync.php                → 用请求体中的 { 表名: [记录,...] } 全量覆盖写回 data/ 各 JSON 表
 *
 * 注意事项：
 *   - POST 到 /api/ 下受 WAF 的 Referer 空校验影响：非 XHR 且无 CSRF 的 POST 会被 403 拦截。
 *     因此本端点的写回请求必须带请求头：X-Requested-With: XMLHttpRequest（见下方用法示例）。
 *   - 请求体单次上限 2MB（网关统一限制），数据较大时可分表多次写回。
 *   - 本端点不校验登录会话，完全以密钥为准；请勿在公网暴露或泄露密钥。
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/security.php';

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, no-cache, must-revalidate');

// ---------- 读取并校验同步密钥 ----------
$SYNC_CFG = [];
$syncFile = __DIR__ . '/../config/sync_config.php';
if (file_exists($syncFile)) {
    include $syncFile;
}
$secret = is_array($SYNC_CFG) ? ($SYNC_CFG['secret_key'] ?? '') : '';

$headerKey  = trim($_SERVER['HTTP_X_SYNC_KEY'] ?? '');
$queryKey   = trim($_GET['key'] ?? '');
$provided   = $headerKey !== '' ? $headerKey : $queryKey;

function sync_auth_ok($provided, $secret) {
    if ($provided === '' || $secret === '') {
        return false;
    }
    return hash_equals($secret, $provided);
}

if (!sync_auth_ok($provided, $secret)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => '同步密钥无效或未配置'], JSON_UNESCAPED_UNICODE);
    exit;
}

$fs = getFS();
$dataDir = __DIR__ . '/../data';
$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    // ---------- 拉取全站数据 ----------
    $backup = [];
    if (is_dir($dataDir)) {
        foreach (glob($dataDir . '/*.json') ?: [] as $path) {
            $name = basename($path, '.json');
            $backup[$name] = $fs->getAll($name);
        }
    }
    $backup['_meta'] = [
        'app' => 'love_wall',
        'exported_at' => date('Y-m-d H:i:s'),
        'version' => 1,
        'generator' => 'ai_sync_pull',
    ];
    echo json_encode($backup, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if ($method === 'POST') {
    // ---------- 写回全站数据（全量覆盖单表）----------
    $raw = file_get_contents('php://input');
    $payload = json_decode($raw, true);
    if (!is_array($payload)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => '请求体需为合法的 JSON 对象'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // 保护白名单：严禁覆盖 `_meta` 与系统运行时文件
    $protected = ['_meta', 'waf_rate_limits', 'waf_logs', 'rate_limits', 'illegal_access_logs'];
    $written = 0;
    $failed = [];

    foreach ($payload as $table => $records) {
        if (!is_string($table) || $table === '') {
            continue;
        }
        // 表名仅允许 字母/数字/下划线，杜绝路径穿越
        if (!preg_match('/^[A-Za-z0-9_]{1,64}$/', $table)) {
            $failed[] = $table;
            continue;
        }
        if (in_array($table, $protected, true)) {
            $failed[] = $table;
            continue;
        }
        $records = is_array($records) ? array_values($records) : [];
        if ($fs->write($table, $records)) {
            $written++;
        } else {
            $failed[] = $table;
        }
    }

    // 记录本次同步
    if (function_exists('logOperation')) {
        logOperation(0, 'ai_sync', 'ai_data_sync', 'table', (string)$written, 'AI 数据同步端点写回 ' . $written . ' 张表');
    }

    if ($failed) {
        http_response_code(200);
        echo json_encode([
            'success' => true,
            'message' => '写回完成',
            'data' => ['written' => $written, 'failed' => $failed],
        ], JSON_UNESCAPED_UNICODE);
    } else {
        echo json_encode([
            'success' => true,
            'message' => '写回完成',
            'data' => ['written' => $written, 'failed' => []],
        ], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

http_response_code(405);
echo json_encode(['success' => false, 'message' => '仅支持 GET / POST'], JSON_UNESCAPED_UNICODE);
