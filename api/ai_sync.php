<?php
/**
 * AI / 开发者 数据同步端点（秘密通道）
 * ----------------------------------------
 * 用途：供站长（含 AI 助手）在「已在本机部署并可直连本服务」的前提下，
 * 不经过邮件验证码申请流程，直接用密钥拉取 / 写回全站 JSON 数据，用于更新或同步本地数据。
 *
 * 鉴权（时间戳 + HMAC 签名，算法与密钥管理见 includes/sync_auth.php）：
 *   密钥（种子）不再写死：首次使用时自动生成并写入 config/sync_config.php，90 天自动轮换。
 *   调用方每次请求带两个请求头，请求里不出现种子本体：
 *     X-Sync-Timestamp: <Unix 秒>
 *     X-Sync-Key:       hex(HMAC-SHA256(种子, 规范串))
 *     规范串 = 时间戳 \n 方法 \n 路径 \n sha256(请求体)
 *   服务端校验时间戳偏差 ≤ 300 秒、签名恒时间比对，并保证同一签名只被接受一次（防重放）。
 *   签名可用 tools/lw-sync-key.bat 现算，不必手工拼串。
 *   另外支持把同一套签名放进查询参数（?ts=&sig=），方便直接在浏览器地址栏打开；
 *   这类链接同样是 5 分钟内有效且只能用一次。
 *
 * 接口：
 *   GET  /api/ai_sync.php                → 返回全站完整备份 JSON（等同「导出完整备份」）
 *   POST /api/ai_sync.php                → 用请求体中的 { 表名: [记录,...] } 全量覆盖写回 data/ 各 JSON 表
 *
 * 注意事项：
 *   - POST 到 /api/ 下受 WAF 的 Referer 空校验影响：非 XHR 且无 CSRF 的 POST 会被 403 拦截。
 *     因此本端点的写回请求必须带请求头：X-Requested-With: XMLHttpRequest（见下方用法示例）。
 *   - 请求体单次上限 2MB（网关统一限制），数据较大时可分表多次写回。
 *   - 本端点不校验登录会话，完全以签名种子为准；请勿在公网暴露或泄露种子。
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../includes/sync_auth.php';

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, no-cache, must-revalidate');

// ---------- 读取请求并校验签名 ----------
// php://input 只安全读取一次，这里读出来后一路复用（验证签名与写回用的是同一份内容）
$method = $_SERVER['REQUEST_METHOD'];
$rawBody = ($method === 'POST') ? (string)file_get_contents('php://input') : '';
$reqPath = (string)parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH);

$auth = sync_verify_request($method, $reqPath, $rawBody);
if (!$auth['ok']) {
    http_response_code($auth['code']);
    echo json_encode(['success' => false, 'message' => $auth['message']], JSON_UNESCAPED_UNICODE);
    exit;
}

$fs = getFS();
$dataDir = __DIR__ . '/../data';

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
    $payload = json_decode($rawBody, true);
    if (!is_array($payload)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => '请求体需为合法的 JSON 对象'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // 保护白名单：严禁覆盖 `_meta` 与系统运行时文件。
    // 运行时 / 会话态表必须列在这里 —— 它们的「当前值」才是有效值，用几天前的备份
    // 覆盖会造成线上事故：remember_tokens / password_reset_tokens 被替换 → 用户莫名掉线、
    // 重置链接失效；rate_limits / waf_rate_limits 被回滚 → 限流计数器倒退，等于给刷量开口子。
    $protected = [
        '_meta',
        'illegal_access_logs',
        'waf_rate_limits', 'waf_rate_limits.alt', 'waf_logs',
        'rate_limits',
        'online_users',
        'remember_tokens', 'password_reset_tokens', 'password_reset_codes',
        'ai_logs', 'operation_logs',
    ];
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