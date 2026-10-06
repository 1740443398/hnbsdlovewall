<?php
/**
 * ai_sync 端点的密钥管理与请求验签
 * =================================================================
 * 密钥（种子）不再写死：首次使用时自动生成并写入 config/sync_config.php，
 * 超过 SYNC_SEED_TTL（90 天）自动轮换，无需人工维护。
 *
 * 请求鉴权改为「时间戳 + HMAC 签名」，请求里不再传输种子本体：
 *   X-Sync-Timestamp: <Unix 秒>
 *   X-Sync-Key:       hex(HMAC-SHA256(seed, 规范串))
 *   规范串 = 时间戳 \n 方法 \n 路径 \n sha256(body)
 * 服务端校验三件事：时间戳偏差是否在 SYNC_SIGN_WINDOW 内、签名是否吻合（恒时间比对）、
 * 同一签名是否被重复使用（防重放）。
 *
 * 唯一性说明：种子熵全部来自 random_bytes()（操作系统 CSPRNG，256 位），
 * 与时间戳无关 —— 所以哪怕同一秒在别的服务器上部署本站，也不会算出同一个密钥。
 * 另外把装机路径 / 主机名 / 站点域名拌进派生过程，仅用于「部署隔离」，便于排查来源。
 */

// 种子有效期：到期后自动重新生成，旧签名立即失效
const SYNC_SEED_TTL = 90 * 86400;
// 请求时间戳允许的前后偏差（秒），兼顾本机时钟误差与抗重放
const SYNC_SIGN_WINDOW = 300;
// 防重放记录的保留时长，略大于校验窗口
const SYNC_NONCE_TTL = 600;

function sync_seed_file() {
    return __DIR__ . '/../config/sync_config.php';
}

function sync_nonce_file() {
    return __DIR__ . '/../config/sync_nonces.json';
}

/**
 * 读取当前种子状态：['seed' => string, 'created_at' => int]；文件缺失或为空时 seed 为空串。
 * 用 include 而不是解析文本，是为了兼容手工编辑过的配置。
 */
function sync_seed_state() {
    $file = sync_seed_file();
    if (!is_file($file)) {
        return ['seed' => '', 'created_at' => 0];
    }
    $SYNC_CFG = [];
    include $file;
    if (!is_array($SYNC_CFG)) {
        return ['seed' => '', 'created_at' => 0];
    }
    $seed = trim((string)($SYNC_CFG['secret_key'] ?? ''));
    $createdAt = (int)($SYNC_CFG['created_at'] ?? 0);
    if ($createdAt <= 0) {
        // 兼容早期只有 secret_key 的配置：以文件修改时间近似作为生成时间
        $createdAt = (int)@filemtime($file);
    }
    return ['seed' => $seed, 'created_at' => $createdAt];
}

/**
 * 生成新种子。
 * 关键点：熵必须来自 random_bytes()，不能由时间戳派生 —— 用时间戳派生的话，
 * 同一秒部署的两个站点会得到完全相同的密钥，这正是要避免的。
 * 站点特征（装机路径 / 主机名 / 域名）只参与搅拌，用于不同部署之间相互隔离。
 */
function sync_generate_seed() {
    $entropy = random_bytes(32);
    $context = implode('|', [
        __DIR__,
        php_uname('n'),
        defined('EXPECTED_HOSTS') && is_array(EXPECTED_HOSTS) ? implode(',', EXPECTED_HOSTS) : '',
        (string)microtime(true),
        bin2hex(random_bytes(16)),
    ]);
    // HMAC 以 256 位随机数为密钥，输出 64 位十六进制串作为种子
    return bin2hex(hash_hmac('sha256', $context, $entropy, true));
}

/** 把种子写回 config/sync_config.php（先写临时文件再原子替换）。 */
function sync_write_seed($seed, $createdAt) {
    $file = sync_seed_file();
    $content = "<?php\n"
        . "// AI/开发者 数据同步端点专用密钥（高度敏感：请勿上传公开仓库）\n"
        . "// 本文件由 includes/sync_auth.php 自动生成与轮换，也可手工填写。\n"
        . "// 请求需带 X-Sync-Timestamp 与 X-Sync-Key，算法见 includes/sync_auth.php。\n"
        . "\$SYNC_CFG = [\n"
        . "    'secret_key' => " . var_export((string)$seed, true) . ",\n"
        . "    'created_at' => " . (int)$createdAt . ",\n"
        . "];\n";

    $tmp = $file . '.tmp';
    if (@file_put_contents($tmp, $content, LOCK_EX) === false) {
        return false;
    }
    if (!@rename($tmp, $file)) {
        @unlink($tmp);
        return false;
    }
    @chmod($file, 0600);
    return true;
}

/**
 * 取当前可用种子，必要时自动生成 / 轮换。
 * 返回 null 表示「既没有种子、又写不进配置目录」——此时只能由站长用 tools 下的脚本
 * 在本机生成后上传 config/sync_config.php。$error 会带回原因。
 */
function sync_active_seed(&$error = '') {
    $state = sync_seed_state();
    $now = time();
    $expired = $state['created_at'] > 0 && ($now - $state['created_at']) > SYNC_SEED_TTL;

    if ($state['seed'] !== '' && !$expired) {
        return $state['seed'];
    }

    $seed = sync_generate_seed();
    if (sync_write_seed($seed, $now)) {
        if (function_exists('logOperation')) {
            logOperation(0, 'ai_sync', 'sync_seed_rotate', 'config', 'sync_config.php',
                $state['seed'] === '' ? '自动生成同步密钥' : '同步密钥已到期并自动轮换');
        }
        return $seed;
    }

    if ($state['seed'] !== '') {
        // 轮换失败时沿用旧种子：宁可暂时不轮换，也不要因为目录不可写把自己锁在门外
        error_log('[love_wall] 同步密钥轮换失败：config/ 目录不可写，暂时沿用旧密钥');
        return $state['seed'];
    }

    $error = '同步密钥尚未生成，且 config/ 目录不可写；请用 tools 下的密钥脚本生成 config/sync_config.php 后上传';
    return null;
}

/** 待签名的规范串。四段用换行分隔，避免拼接歧义。 */
function sync_canonical($timestamp, $method, $path, $body) {
    return $timestamp . "\n"
        . strtoupper((string)$method) . "\n"
        . $path . "\n"
        . hash('sha256', (string)$body);
}

/** 计算请求签名（十六进制小写）。 */
function sync_signature($seed, $timestamp, $method, $path, $body) {
    return hash_hmac('sha256', sync_canonical($timestamp, $method, $path, $body), $seed);
}

/**
 * 防重放：同一签名只接受一次。记录写在 config/sync_nonces.json（该目录已拒绝公网访问）。
 * 记录文件不可写时放行（时间窗本身已经把可重放时间限死在 5 分钟内），只记一条日志。
 */
function sync_nonce_accept($signature, $timestamp) {
    $file = sync_nonce_file();
    $now = time();

    // 整个「读—改—写」必须处在同一把排他锁里：
    // 否则两个并发请求可能同时读到旧内容、各自写回，后写的那份会覆盖掉前者的记录，
    // 同一个签名就能被再用一次，防重放形同虚设。
    $fh = @fopen($file, 'c+');
    if (!$fh) {
        // 打不开记录文件时不阻断业务：SYNC_SIGN_WINDOW 本身已把可重放时间限死在 5 分钟内
        error_log('[love_wall] 防重放记录不可用（config/ 目录不可写），本次放行');
        return true;
    }

    $accepted = true;
    if (flock($fh, LOCK_EX)) {
        $raw = stream_get_contents($fh);
        $store = [];
        if ($raw !== false && $raw !== '') {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                $store = $decoded;
            }
        }
        // 顺手清理过期记录，文件不会无限增长
        foreach ($store as $key => $expires) {
            if ((int)$expires <= $now) {
                unset($store[$key]);
            }
        }

        $key = substr($signature, 0, 40);
        if (isset($store[$key])) {
            $accepted = false;
        } else {
            $store[$key] = (int)$timestamp + SYNC_NONCE_TTL;
            rewind($fh);
            ftruncate($fh, 0);
            if (fwrite($fh, json_encode($store, JSON_FORCE_OBJECT)) === false) {
                error_log('[love_wall] 防重放记录写入失败：config/ 目录不可写');
            }
            fflush($fh);
        }
        flock($fh, LOCK_UN);
    } else {
        error_log('[love_wall] 防重放记录加锁失败，本次放行');
    }
    fclose($fh);

    return $accepted;
}

/**
 * 校验一次请求。返回 ['ok' => bool, 'code' => int, 'message' => string]。
 * 调用方需自行读出请求体传入（php://input 只能安全读取一次）。
 */
function sync_verify_request($method, $path, $body) {
    $error = '';
    $seed = sync_active_seed($error);
    if ($seed === null) {
        return ['ok' => false, 'code' => 503, 'message' => $error];
    }

    $timestamp = trim((string)($_SERVER['HTTP_X_SYNC_TIMESTAMP'] ?? ''));
    $provided  = strtolower(trim((string)($_SERVER['HTTP_X_SYNC_KEY'] ?? '')));

    // 浏览器地址栏只能带查询参数，所以额外允许 ?ts=&sig= 传同一套「时间戳 + 签名」。
    // 安全性等同请求头方式：签名仍然只有 5 分钟寿命，且用过一次立刻作废（末尾的防重放）。
    // 唯一的差别是签名会进入访问日志与浏览器历史 —— 但它是单次票据，不是种子本体，用完即废。
    if ($timestamp === '') {
        $timestamp = trim((string)($_GET['ts'] ?? ''));
    }
    if ($provided === '') {
        $provided = strtolower(trim((string)($_GET['sig'] ?? '')));
    }

    if ($timestamp === '' || $provided === '') {
        return ['ok' => false, 'code' => 403, 'message' => '缺少 X-Sync-Timestamp 或 X-Sync-Key 请求头'];
    }
    if (!ctype_digit($timestamp)) {
        return ['ok' => false, 'code' => 403, 'message' => 'X-Sync-Timestamp 需为 Unix 秒级整数'];
    }
    if (abs(time() - (int)$timestamp) > SYNC_SIGN_WINDOW) {
        return ['ok' => false, 'code' => 403,
            'message' => '请求时间戳超出允许范围（±' . SYNC_SIGN_WINDOW . ' 秒），请校准本机时间后重试'];
    }

    $expected = sync_signature($seed, $timestamp, $method, $path, $body);
    if (!hash_equals($expected, $provided)) {
        return ['ok' => false, 'code' => 403, 'message' => '签名校验失败'];
    }
    if (!sync_nonce_accept($expected, $timestamp)) {
        return ['ok' => false, 'code' => 403, 'message' => '该请求已使用过，请用新的时间戳重新签名'];
    }

    return ['ok' => true, 'code' => 200, 'message' => 'ok'];
}
