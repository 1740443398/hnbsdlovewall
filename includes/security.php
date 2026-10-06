<?php

function hashPassword($password) {
    return password_hash($password, PASSWORD_BCRYPT, ['cost' => BCRYPT_COST]);
}

function verifyPassword($password, $hash) {
    return password_verify($password, $hash);
}

function generateSecurityStamp() {
    return bin2hex(random_bytes(32));
}

function xss_clean($data) {
    if (is_array($data)) {
        return array_map('xss_clean', $data);
    }
    return htmlspecialchars($data, ENT_QUOTES | ENT_HTML5, 'UTF-8');
}

function sanitizeInput($data) {
    if (is_array($data)) {
        return array_map('sanitizeInput', $data);
    }
    return trim(strip_tags($data));
}

// CSRF 令牌生命周期（2026-10 重构：解决部分用户「CSRF验证失败」）：
// 1. TTL 从 30 分钟放宽到 2 小时——表单开着超半小时（登录页/发帖页尤其常见）不再是失败源；
// 2. 换签时旧令牌不立即作废，进入 10 分钟「宽限期」——多标签页、bfcache 后退恢复、
//    首访并发请求竞态（config.php 首请求 session_regenerate_id）拿到的旧令牌仍可提交；
// 3. 校验失败时当场换发新令牌并放进 403 响应体（new_csrf_token），前端拿到后自动重试一次，
//    用户全程无感，不再需要「刷新页面再试」。跨站攻击者读不到响应（无 CORS），不受影响。
const LW_CSRF_TTL = 7200;
const LW_CSRF_GRACE = 600;

function generateCSRFToken() {
    if (empty($_SESSION['csrf_token']) || time() - ($_SESSION['csrf_token_time'] ?? 0) > LW_CSRF_TTL) {
        if (!empty($_SESSION['csrf_token'])) {
            // 旧令牌降级为宽限令牌
            $_SESSION['csrf_token_prev'] = $_SESSION['csrf_token'];
            $_SESSION['csrf_token_prev_time'] = time();
        }
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        $_SESSION['csrf_token_time'] = time();
    }
    return $_SESSION['csrf_token'];
}

/**
 * 令牌匹配：接受当前令牌，或 10 分钟宽限期内尚未过期的上一任令牌。
 */
function lwCSRFTokenMatches($token) {
    $current = (string)($_SESSION['csrf_token'] ?? '');
    if ($current !== '' && hash_equals($current, $token)) {
        return true;
    }
    $prev = (string)($_SESSION['csrf_token_prev'] ?? '');
    if ($prev !== ''
        && $prev !== $current
        && time() - (int)($_SESSION['csrf_token_prev_time'] ?? 0) <= LW_CSRF_GRACE
        && hash_equals($prev, $token)) {
        return true;
    }
    return false;
}

/**
 * 校验失败后的自愈动作：立即换发新令牌，并挂到全局供 jsonError() 带回给前端。
 */
function lwCSRFRotateForRetry() {
    if (session_status() !== PHP_SESSION_ACTIVE) {
        return;
    }
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    $_SESSION['csrf_token_time'] = time();
    unset($_SESSION['csrf_token_prev'], $_SESSION['csrf_token_prev_time']);
    $GLOBALS['lw_fresh_csrf_token'] = $_SESSION['csrf_token'];
}

function verifyCSRFToken($token) {
    $token = (string)($token ?? '');
    if ($token === '') {
        // 客户端**一个令牌都没带**。以前这里直接 return false 且不换发，
        // 于是「页面漏内联 CSRF 令牌」这类缺陷会变成永久 403 —— 前端自愈拦截器
        // 只在 403 响应体里出现 new_csrf_token 时才重试，拿不到就救不回来
        // （实测 pages/topic.php 的 window.CSRF_TOKEN 曾为空串，该页签到/点赞/收藏全废）。
        // 现在补一次回传，让自愈链能接上：
        //   - 会话里也没有令牌（被主机回收 / Cookie 未持久化）→ 换发一枚；
        //   - 会话里本来就有 → 直接回传**现有的**，不换签。换签会清掉 10 分钟宽限槽，
        //     把其它标签页手上的令牌一并作废，属于自伤，没必要。
        // 安全性不变：跨站攻击者读不到跨源响应（无 CORS），令牌照旧无法窃取；
        // 而「无令牌的客户端也能从任意页面 HTML 里取到令牌」本来就是既有事实。
        if (empty($_SESSION['csrf_token'])) {
            lwCSRFRotateForRetry();
        } else {
            $GLOBALS['lw_fresh_csrf_token'] = (string)$_SESSION['csrf_token'];
        }
        return false;
    }
    if (empty($_SESSION['csrf_token'])) {
        // 会话里根本没有令牌——会话被主机回收、Cookie 未持久化、或首访并发请求竞态
        // （config.php 首请求 session_regenerate_id 后浏览器最终只留了其中一个 session）。
        // 旧逻辑这里直接 return false 且不换发令牌，前端拿不到 new_csrf_token 便无法自愈重试，
        // 于是用户被迫看到「CSRF验证失败」——这正是「有些用户」才中招的原因。
        // 补一次换发：新令牌写入当前 session 并随 403 响应体回传，前端静默重试一次即成功。
        lwCSRFRotateForRetry();
        return false;
    }
    if (lwCSRFTokenMatches($token)) {
        return true;
    }
    lwCSRFRotateForRetry();
    return false;
}

function isValidQQ($qq) {
    return preg_match('/^[1-9][0-9]{4,14}$/', $qq);
}

function getQQAvatar($qq) {
    return 'https://q.qlogo.cn/headimg_dl?dst_uin=' . $qq . '&spec=100';
}

function generateRandomString($length = 32) {
    return bin2hex(random_bytes((int)ceil($length / 2)));
}

/**
 * 生成固定随机 UUID v4（用于给每个用户分配稳定标识，
 * 前端按 uuid 隔离本地数据（如签到），避免同设备多账号串台）。
 */
function generateUUID() {
    $data = random_bytes(16);
    $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
    $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
}

/** 确保用户记录具有固定 uuid（缺失时生成并持久化），返回 uuid。 */
function ensureUserUuid(&$user) {
    if (!empty($user['uuid'])) {
        return $user['uuid'];
    }
    $user['uuid'] = generateUUID();
    $fs = getFS();
    $fs->update('users', $user['id'], ['uuid' => $user['uuid']]);
    return $user['uuid'];
}

function checkSensitiveWords($content) {
    static $wordsCache = null;
    static $wordsCacheTime = 0;
    if ($wordsCache === null || time() - $wordsCacheTime > 60) {
        $fs = getFS();
        $words = $fs->read('sensitive_words');
        $wordsCache = [];
        if (is_array($words)) {
            foreach ($words as $item) {
                if (!empty($item['word'])) {
                    $wordsCache[] = $item['word'];
                }
            }
        }
        $wordsCacheTime = time();
    }
    $found = [];
    foreach ($wordsCache as $word) {
        if (mb_stripos($content, $word) !== false) {
            $found[] = $word;
        }
    }
    return $found;
}

function getClientIP() {
    return WAF::getClientIP();
}

function isIPBlocked($ip) {
    $fs = getFS();
    $blacklist = $fs->find('ip_blacklist', ['ip' => $ip]);
    return !empty($blacklist);
}

function isValidUserAgent() {
    $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
    if (empty($ua)) return false;
    $blocked = ['bot', 'spider', 'crawler', 'scraper', 'curl', 'wget', 'python', 'java/', 'go-http'];
    foreach ($blocked as $pattern) {
        if (stripos($ua, $pattern) !== false) {
            return false;
        }
    }
    return true;
}

/**
 * 选取一个可用的 TrueType 字体路径（GD 扭曲用），找不到返回 null 则用内置位图字体。
 */
function captchaFontPath() {
    static $font = null;
    static $checked = false;
    if ($checked) {
        return $font;
    }
    $checked = true;
    $candidates = [
        '/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf',
        '/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf',
        '/usr/share/fonts/dejavu/DejaVuSans-Bold.ttf',
        'C:/Windows/Fonts/arialbd.ttf',
        'C:/Windows/Fonts/arial.ttf',
        'C:/Windows/Fonts/timesbd.ttf',
        '/System/Library/Fonts/Helvetica.ttc',
    ];
    foreach ($candidates as $c) {
        if (is_readable($c)) {
            $font = $c;
            return $font;
        }
    }
    // 约定：应用根目录下的 fonts/ 目录放字体
    $rel = realpath(__DIR__ . '/../fonts');
    if ($rel !== false && is_dir($rel)) {
        $files = glob($rel . '/*.ttf') ?: [];
        if ($files) {
            $font = $files[0];
            return $font;
        }
    }
    return null;
}

/**
 * 用 GD 将单个字符渲染成带噪点/干扰线的图片，返回 PNG 的 base64。
 */
function renderCaptchaChar($char) {
    $w = 120;
    $h = 120;
    $img = imagecreatetruecolor($w, $h);
    $bg = imagecolorallocate($img, 248, 249, 252);
    imagefilledrectangle($img, 0, 0, $w, $h, $bg);

    // 干扰线
    for ($i = 0; $i < 4; $i++) {
        $c = imagecolorallocate($img, mt_rand(150, 220), mt_rand(150, 220), mt_rand(160, 230));
        imageline($img, mt_rand(0, $w), mt_rand(0, $h), mt_rand(0, $w), mt_rand(0, $h), $c);
    }
    // 噪点
    for ($i = 0; $i < 260; $i++) {
        $c = imagecolorallocate($img, mt_rand(120, 210), mt_rand(120, 210), mt_rand(120, 210));
        imagesetpixel($img, mt_rand(0, $w - 1), mt_rand(0, $h - 1), $c);
    }

    $fg = imagecolorallocate($img, mt_rand(30, 90), mt_rand(50, 100), mt_rand(60, 110));
    $font = captchaFontPath();
    if ($font !== null && function_exists('imagettftext')) {
        $angle = mt_rand(-22, 22);
        $size = 56;
        $box = imagettfbbox($size, 0, $font, $char);
        $tw = abs($box[2] - $box[0]);
        $th = abs($box[5] - $box[1]);
        $x = intval(($w - $tw) / 2);
        $y = intval(($h + $th) / 2);
        // 轻微波纹：分段绘制制造变形
        for ($seg = 0; $seg < 3; $seg++) {
            $dx = mt_rand(-1, 1);
            $dy = mt_rand(-1, 1);
            imagettftext($img, $size, $angle, $x + $dx, $y + $dy, $fg, $font, $char);
        }
    } else {
        // 无 TrueType 时使用内置 5x7 位图字体，放大并加噪
        imagestring($img, 5, mt_rand(8, 14), mt_rand(10, 18), $char, $fg);
    }

    ob_start();
    imagepng($img);
    $png = ob_get_clean();
    imagedestroy($img);
    return 'data:image/png;base64,' . base64_encode($png);
}

/**
 * 生成点选式图形验证码。
 * 返回的题目仅包含候选块与目标图 base64，目标答案只存 session，
 * 直接抓取 /api/captcha.php 返回的 JSON 无法得到明文答案，需 OCR 识别目标图。
 * 若服务器无 GD，则回退为算术题。
 */
function generateCaptcha($scene = 'default') {
    $key = 'captcha_' . $scene;
    $tiles = [];
    if (function_exists('imagecreatetruecolor')) {
        // 剔除易混淆字符（0/O、1/I/L、2/Z 等）
        $pool = str_split('ABCDEFGHJKMNPQRSTVWXY345678');
        $pool = array_values(array_unique($pool));
        shuffle($pool);
        $tiles = array_slice($pool, 0, 6);
        $answer = $tiles[mt_rand(0, 5)];
        $_SESSION[$key] = [
            'answer' => $answer,
            'created' => time(),
            'fail_count' => 0
        ];
        return [
            'mode' => 'click',
            'tiles' => $tiles,
            'target' => renderCaptchaChar($answer), // 目标图为图片，非明文
            'scene' => $scene
        ];
    }

    // 回退：无 GD 时的算术题（保留原逻辑）
    $a = random_int(2, 9);
    $b = random_int(1, 9);
    $op = random_int(0, 1);
    $answer = $op === 0 ? ($a + $b) : ($a - $b);
    if ($answer < 0) {
        $tmp = max($a, $b);
        $a = $tmp;
        $b = min($a, $b);
        $answer = $a - $b;
    }
    $opStr = $op === 0 ? '＋' : '−';
    $_SESSION[$key] = [
        'answer' => (string)$answer,
        'created' => time(),
        'fail_count' => 0
    ];
    return [
        'mode' => 'math',
        'question' => $a . ' ' . $opStr . ' ' . $b . ' = ?',
        'scene' => $scene
    ];
}

/**
 * 校验验证码答案（点选字符或算术答案）。
 * @param string $scene 场景标识
 * @return bool 通过返回 true
 */
function verifyCaptcha($scene = 'default', $answer = '') {
    $key = 'captcha_' . $scene;
    if (empty($_SESSION[$key])) {
        return false;
    }
    $cap = $_SESSION[$key];
    // 5 分钟过期
    if (time() - ($cap['created'] ?? 0) > 300) {
        unset($_SESSION[$key]);
        return false;
    }
    $answer = trim((string)$answer);
    $ok = hash_equals($cap['answer'], $answer);
    if ($ok) {
        unset($_SESSION[$key]); // 一次性，防重放
    } else {
        $cap['fail_count'] = ($cap['fail_count'] ?? 0) + 1;
        if ($cap['fail_count'] >= 5) {
            unset($_SESSION[$key]); // 连续多次失败则作废，需刷新
        } else {
            $_SESSION[$key] = $cap;
        }
    }
    return $ok;
}

/**
 * 是否需要验证码（首次进入无需，供前端判断是否展示）。
 */
function captchaRequired($scene = 'default') {
    // 场景记录在 session，方便前端了解是否需要验证码
    return isset($_SESSION['captcha_' . $scene]);
}

function checkRateLimit($ip, $action, $limit = 60, $window = 60) {
    $key = '_rl_' . $action . '_' . $ip;
    $fs = getFS();
    $fileKey = $action . '_' . $ip;

    $rateData = $fs->read('rate_limits');
    if (!is_array($rateData)) $rateData = [];
    $now = time();
    $fileEntry = null;
    $fileIdx = null;
    foreach ($rateData as $i => $entry) {
        if (($entry['rate_key'] ?? '') === $fileKey) {
            $fileEntry = $entry;
            $fileIdx = $i;
            break;
        }
    }

    if ($fileEntry && ($fileEntry['reset_at'] ?? 0) > $now) {
        $count = ($fileEntry['count'] ?? 0);
        if ($count >= $limit) {
            if (!isset($_SESSION[$key])) {
                $_SESSION[$key] = ['count' => $count, 'reset' => $fileEntry['reset_at']];
            }
            return false;
        }
    }

    if (!isset($_SESSION[$key]) || ($_SESSION[$key]['reset'] ?? 0) < $now) {
        $_SESSION[$key] = ['count' => 0, 'reset' => $now + $window];
    }

    $rl = &$_SESSION[$key];
    if ($rl['count'] >= $limit) {
        $rateData[$fileIdx]['count'] = $rl['count'];
        $rateData[$fileIdx]['reset_at'] = $rl['reset'];
        $fs->write('rate_limits', $rateData);
        return false;
    }
    $rl['count']++;

    if ($fileIdx !== null) {
        $rateData[$fileIdx]['count'] = $rl['count'];
        $rateData[$fileIdx]['reset_at'] = $rl['reset'];
    } else {
        $rateData[] = ['rate_key' => $fileKey, 'count' => $rl['count'], 'reset_at' => $rl['reset']];
    }

    if (count($rateData) > 500) {
        $rateData = array_values(array_filter($rateData, function($e) use ($now) {
            return ($e['reset_at'] ?? 0) > $now - 3600;
        }));
        if (count($rateData) > 500) {
            $rateData = array_slice($rateData, -500);
        }
    }
    $fs->write('rate_limits', $rateData);

    return true;
}

function logOperation($operatorId, $operatorQQ, $action, $targetType = '', $targetId = '', $details = '') {
    try {
        $fs = getFS();
        $fs->insert('operation_logs', [
            'operator_id' => $operatorId,
            'operator_qq' => $operatorQQ,
            'action' => $action,
            'target_type' => $targetType,
            'target_id' => $targetId,
            'details' => $details,
            'ip' => getClientIP()
        ]);
        // 仅裁剪管理员操作日志，保留最近 N 条，避免无限膨胀占用 5GB 存储
        pruneTable('operation_logs', 3000);
    } catch (Exception $e) {
        error_log('logOperation failed: ' . $e->getMessage());
    }
}

/**
 * 按 created_at 保留最近 $cap 条记录（用于瞬时/操作类日志的自我裁剪，无需定时任务）。
 * @return void
 */
function pruneTable($table, $cap) {
    if ($cap <= 0) return;
    $fs = getFS();
    $rows = $fs->read($table);
    if (!is_array($rows)) return;
    $n = count($rows);
    if ($n <= $cap) return;
    // 按时间倒序保留最近 cap 条
    usort($rows, function($a, $b) {
        return strtotime($b['created_at'] ?? '2000-01-01') - strtotime($a['created_at'] ?? '2000-01-01');
    });
    $fs->write($table, array_slice($rows, 0, $cap));
}

function logUserActivity($userId, $action, $details = '') {
    try {
        $fs = getFS();
        $fs->insert('user_activity_logs', [
            'user_id' => $userId,
            'action' => $action,
            'details' => maskSensitive($details),
            'ip' => getClientIP()
        ]);
        // 与操作日志同样做裁剪：无上限增长会让每次追加都整文件重写，拖慢共享主机
        pruneTable('user_activity_logs', 2000);
    } catch (Exception $e) {
        error_log('logUserActivity failed: ' . $e->getMessage());
    }
}

/**
 * 记录一次 AI 助手调用（结构化元数据 + 提问原文截断预览）。
 * 刻意不记录：AI 回复全文、system 提示词、API Key、鉴权头。
 * 失败绝不影响主流程（AI 已经答完，日志只是旁路）。
 */
function logAiCall(array $info) {
    try {
        $fs = getFS();
        $question = isset($info['question']) ? (string)$info['question'] : '';
        $question = trim(preg_replace('/\s+/u', ' ', $question));
        $fs->insert('ai_logs', [
            'user_id' => isset($info['user_id']) ? (int)$info['user_id'] : 0,
            'nickname' => mb_substr((string)($info['nickname'] ?? ''), 0, 40),
            'is_guest' => !empty($info['is_guest']) ? 1 : 0,
            'ip' => (string)($info['ip'] ?? ''),
            'success' => !empty($info['success']) ? 1 : 0,
            'http_status' => (int)($info['http_status'] ?? 0),
            'error' => mb_substr((string)($info['error'] ?? ''), 0, 200),
            'elapsed_ms' => (int)($info['elapsed_ms'] ?? 0),
            'key_tries' => (int)($info['key_tries'] ?? 0),
            // 'site' 消耗站点配额 / 'user' 用户自带模型（站点零成本）——后台看得出谁在烧谁的钱
            'key_owner' => (($info['key_owner'] ?? 'site') === 'user') ? 'user' : 'site',
            'sources' => implode('、', array_slice((array)($info['sources'] ?? []), 0, 12)),
            'intent' => mb_substr((string)($info['intent'] ?? ''), 0, 60),
            'question' => mb_substr($question, 0, 200),
            'page' => mb_substr((string)($info['page'] ?? ''), 0, 120),
        ]);
        pruneTable('ai_logs', 2000);
    } catch (Exception $e) {
        error_log('logAiCall failed: ' . $e->getMessage());
    }
}

/**
 * 记录一次网站访问：累加总访问量、当日访问量（含近30天趋势），
 * 并细分每个已登录用户的访问次数与最后访问时间。
 * @param array|null $user 当前登录用户（由 getCurrentUser 取得）
 * @return void
 */
function trackVisit($user = null) {
    try {
        $fs = getFS();
        $today = date('Y-m-d');

        $stats = $fs->findOne('visit_stats', ['id' => 1]);
        if (!$stats) {
            $fs->insert('visit_stats', [
                'total' => 0,
                'today' => 0,
                'today_date' => $today,
                'daily' => []
            ]);
            $stats = $fs->findOne('visit_stats', ['id' => 1]);
        }

        $daily = is_array(($stats['daily'] ?? null)) ? $stats['daily'] : [];
        $daily[$today] = (int)($daily[$today] ?? 0) + 1;

        // 只保留最近30天，避免文件无限增长
        foreach ($daily as $d => $c) {
            if ($d < date('Y-m-d', strtotime('-29 days'))) {
                unset($daily[$d]);
            }
        }

        $total = array_sum($daily);
        $fs->update('visit_stats', $stats['id'], [
            'total' => $total,
            'today' => $daily[$today],
            'today_date' => $today,
            'daily' => $daily,
        ]);

        // 细分每个用户的访问次数
        if ($user && !empty($user['id'])) {
            $u = $fs->findById('users', $user['id']);
            if ($u) {
                $fs->update('users', $u['id'], [
                    'visit_count' => (int)($u['visit_count'] ?? 0) + 1,
                    'last_visit' => date('Y-m-d H:i:s'),
                ]);
            }
        }
    } catch (Exception $e) {
        error_log('trackVisit failed: ' . $e->getMessage());
    }
}

function forceLogout($userId) {
    $fs = getFS();
    $user = $fs->findById('users', $userId);
    if ($user) {
        $fs->update('users', $userId, ['security_stamp' => generateSecurityStamp()]);
    }
    if (isset($_COOKIE['remember_token'])) {
        $secure = defined('IS_SECURE') ? IS_SECURE : false;
        setcookie('remember_token', '', time() - 3600, '/', '', $secure, true);
    }
}

function getSetting($key, $default = '') {
    static $settingsCache = null;
    static $settingsCacheTime = 0;
    global $_SETTING_CACHE_DIRTY;
    if ($_SETTING_CACHE_DIRTY ?? false) {
        $settingsCache = null;
        $settingsCacheTime = 0;
        $_SETTING_CACHE_DIRTY = false;
    }
    if ($settingsCache === null || time() - $settingsCacheTime > 30) {
        $fs = getFS();
        $settings = $fs->read('settings');
        $settingsCache = [];
        if (is_array($settings)) {
            foreach ($settings as $item) {
                if (isset($item['setting_key'])) {
                    $settingsCache[$item['setting_key']] = $item['setting_value'] ?? '';
                }
            }
        }
        $settingsCacheTime = time();
    }
    return $settingsCache[$key] ?? $default;
}

function updateSetting($key, $value) {
    $fs = getFS();
    $settings = $fs->read('settings');
    $found = false;
    foreach ($settings as $i => $item) {
        if ($item['setting_key'] === $key) {
            $settings[$i]['setting_value'] = $value;
            $found = true;
            break;
        }
    }
    if (!$found) {
        $maxId = 0;
        foreach ($settings as $item) {
            if (isset($item['id']) && $item['id'] > $maxId) {
                $maxId = $item['id'];
            }
        }
        $settings[] = [
            'id' => $maxId + 1,
            'setting_key' => $key,
            'setting_value' => $value
        ];
    }
    $fs->write('settings', $settings);
    $fs->clearCache('settings');
    global $_SETTING_CACHE_DIRTY;
    $_SETTING_CACHE_DIRTY = true;
    return true;
}

function timeAgo($datetime) {
    $timestamp = strtotime($datetime);
    if ($timestamp === false || $timestamp <= 0) {
        return '未知时间';
    }
    $diff = time() - $timestamp;
    if ($diff < 60) return '刚刚';
    if ($diff < 3600) return floor($diff / 60) . '分钟前';
    if ($diff < 86400) return floor($diff / 3600) . '小时前';
    if ($diff < 604800) return floor($diff / 86400) . '天前';
    if ($diff < 2592000) return floor($diff / 604800) . '周前';
    return date('Y-m-d H:i', $timestamp);
}

function jsonResponse($data, $code = 200) {
    if (!headers_sent()) {
        http_response_code($code);
        header('Content-Type: application/json; charset=utf-8');
    }
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit();
}

/* ========= 年级 / 班级 / 选科（安徽 3+1+2）辅助 ========= */

function gradeSubjectsFirst() {
    return ['物理', '历史'];
}
function gradeSubjectsSecond() {
    return ['化学', '生物', '政治', '地理'];
}

/**
 * 当前"学年起始年" A：7月(暑假)至今算作新学年，9月新学期正式开始。
 * @return int 如 2026
 */
function schoolAcademicYear() {
    $m = (int)date('n');
    $y = (int)date('Y');
    return $m < 7 ? $y - 1 : $y;
}

/**
 * 是否处于暑假（7~8月）——用于“新高一/新高二”的前缀展示。
 */
function isSummerHoliday() {
    $m = (int)date('n');
    return $m === 7 || $m === 8;
}

/**
 * 由入学高一那一年计算当前年级索引：0=高一 1=高二 2=高三 3=已毕业。
 */
function currentGradeIndex($entranceYear) {
    $entranceYear = (int)$entranceYear;
    if ($entranceYear <= 0) {
        return -1;
    }
    $idx = schoolAcademicYear() - $entranceYear;
    return $idx < 0 ? 0 : ($idx > 3 ? 3 : $idx);
}

/**
 * 年级显示文案，暑假期间对未毕业者加“新”前缀（新高一/新高二/新高三）。
 */
function gradeLabel($entranceYear, $withNew = true) {
    $idx = currentGradeIndex($entranceYear);
    if ($idx < 0) {
        return '未填写';
    }
    $names = ['高一', '高二', '高三', '已毕业'];
    $name = $names[$idx];
    if ($withNew && $idx < 3 && isSummerHoliday()) {
        $name = '新' . $name;
    }
    return $name;
}

/**
 * “已毕业”起始年：用于某届学生高一那一年（A-3，即今年夏季刚毕业那一届）。
 */
function graduatedEntranceYear() {
    return schoolAcademicYear() - 3;
}

/**
 * 由用户当前所选年级反推入学高一之年（用于注册/修改时存储 entrance_year）。
 * @param int $gradeIndex 0=高一 1=高二 2=高三 3=已毕业
 */
function entranceYearFromGrade($gradeIndex) {
    $gradeIndex = (int)$gradeIndex;
    $gradeIndex = min(3, max(0, $gradeIndex));
    return schoolAcademicYear() - $gradeIndex;
}

/**
 * 校验班级：1-20，0 表示未填写。
 */
function validClassNum($classNum) {
    $classNum = (int)$classNum;
    return $classNum >= 0 && $classNum <= 20;
}

/**
 * 校验安徽 高考选科：首选(物理/历史) + 再选2门。
 * @return true|string 通过返回 true，否则错误文案
 */
function validateSubjectSelection($first, $second) {
    if (!in_array($first, gradeSubjectsFirst(), true)) {
        return '首选科目需为「物理」或「历史」';
    }
    $second = is_array($second) ? $second : [];
    $second = array_values(array_filter(array_map('trim', $second), function ($s) {
        return $s !== '';
    }));
    $second = array_values(array_unique($second));
    if (count($second) !== 2) {
        return '再选科目请选择 2 门';
    }
    foreach ($second as $s) {
        if (!in_array($s, gradeSubjectsSecond(), true)) {
            return '再选科目无效';
        }
    }
    return true;
}

function jsonError($message, $code = 400, $extra = []) {
    $payload = ['success' => false, 'message' => $message];
    // 允许调用方附加结构化字段（例如危险操作的 reauth_required 标记），前端据此决定是否弹出密码框
    if (is_array($extra)) {
        foreach ($extra as $k => $v) {
            if (!array_key_exists($k, $payload)) {
                $payload[$k] = $v;
            }
        }
    }
    // CSRF 校验失败的 403 会附上新令牌，前端拿它自动重试一次，用户无需手动刷新。
    // 跨站攻击者读不到跨源响应（无 CORS），把令牌放进错误体不引入风险。
    if (!empty($GLOBALS['lw_fresh_csrf_token']) && is_string($GLOBALS['lw_fresh_csrf_token'])) {
        $payload['new_csrf_token'] = $GLOBALS['lw_fresh_csrf_token'];
    }
    jsonResponse($payload, $code);
}

function jsonSuccess($data = [], $message = '操作成功') {
    jsonResponse(['success' => true, 'message' => $message, 'data' => $data]);
}

function getCurrentUser() {
    static $cachedUser = null;
    static $cachedUserId = null;

    if (empty($_SESSION['user_id'])) {
        return null;
    }
    $userId = (int)$_SESSION['user_id'];
    if ($cachedUserId === $userId && $cachedUser !== null) {
        return $cachedUser;
    }

    $fs = getFS();
    $user = $fs->findById('users', $userId);
    if (!$user) return null;
    // 为旧账号补充分配固定 uuid（供前端按用户隔离本地数据）
    ensureUserUuid($user);
    if (!empty($user['security_stamp']) &&
        (empty($_SESSION['security_stamp']) || $_SESSION['security_stamp'] !== $user['security_stamp'])) {
        $_SESSION = [];
        session_unset();
        session_destroy();
        session_regenerate_id(true);
        return null;
    }
    $cachedUserId = $userId;
    $cachedUser = $user;
    return $user;
}

function requireLogin() {
    $user = getCurrentUser();
    if (!$user) {
        $isAjax = (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && $_SERVER['HTTP_X_REQUESTED_WITH'] === 'XMLHttpRequest')
               || (isset($_SERVER['HTTP_ACCEPT']) && strpos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false);
        if ($isAjax) {
            jsonError('请先登录', 401);
        }
        if (!headers_sent()) {
            header('Location: /pages/login.php');
            exit();
        }
        echo '<!DOCTYPE html><html><head><meta charset="UTF-8"><meta http-equiv="refresh" content="0;url=/pages/login.php"></head><body>请先<a href="/pages/login.php">登录</a></body></html>';
        exit();
    }
    return $user;
}

/**
 * 是否处于游客模式：未登录，且访客主动选择了「先逛逛」。
 * 游客只能浏览前若干条动态、公告与社区规范，以及只读工具页。
 */
function isGuestMode() {
    if (getCurrentUser()) return false;
    return !empty($_SESSION['guest_mode']);
}

/** 开启游客模式（写入会话标记）。 */
function enableGuestMode() {
    $_SESSION['guest_mode'] = 1;
}

/** 关闭游客模式（登录/退出时应调用）。 */
function disableGuestMode() {
    unset($_SESSION['guest_mode']);
}

/**
 * 页面级守卫：已登录用户放行；游客放行并返回 null；两者都不是则跳登录页。
 */
function requireLoginOrGuest() {
    $user = getCurrentUser();
    if ($user || isGuestMode()) return $user;
    return requireLogin();
}

/**
 * 游客禁区守卫：已登录放行；游客引导去注册；未登录跳登录页。
 * 用于帖子详情、发帖、个人中心、私信等需要正式账号的功能。
 */
function requireMember($message = '该功能需要注册账号后才能使用') {
    $user = getCurrentUser();
    if ($user) return $user;
    if (isGuestMode()) denyGuest($message);
    return requireLogin();
}

/**
 * 拦截游客并引导注册：AJAX 请求返回 401，页面请求跳转注册页。
 * 注册页自身会在未确认安全声明时先转到 gateway 页。
 */
function denyGuest($message = '该功能需要注册账号后才能使用') {
    $isAjax = (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && $_SERVER['HTTP_X_REQUESTED_WITH'] === 'XMLHttpRequest')
           || (isset($_SERVER['HTTP_ACCEPT']) && strpos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false);
    if ($isAjax) {
        jsonError($message, 401);
    }
    $target = SITE_URL . '/pages/register.php';
    if (!headers_sent()) {
        header('Location: ' . $target);
        exit();
    }
    echo '<!DOCTYPE html><html><head><meta charset="UTF-8"><meta http-equiv="refresh" content="0;url=' . htmlspecialchars($target) . '"></head><body>'
       . htmlspecialchars($message) . ' <a href="' . htmlspecialchars($target) . '">前往注册</a></body></html>';
    exit();
}

function requireAdmin() {
    $user = requireLogin();
    if (!in_array($user['role'], ['admin', 'super_admin'])) {
        http_response_code(403);
        die('403 Forbidden');
    }
    // F12 后台「IP + 权限」双校验：默认关闭（共享主机不易固定 IP），
    // 仅当部署方在 config 里设了 ADMIN_ALLOWED_IPS（逗号分隔）时才启用白名单。
    if (defined('ADMIN_ALLOWED_IPS') && ADMIN_ALLOWED_IPS !== '') {
        $allowed = array_map('trim', explode(',', ADMIN_ALLOWED_IPS));
        if (!in_array(getClientIP(), $allowed, true)) {
            http_response_code(403);
            die('403 Forbidden - IP 不在后台白名单');
        }
    }
    return $user;
}

function requireSuperAdmin() {
    $user = requireAdmin();
    if ($user['role'] !== 'super_admin') {
        http_response_code(403);
        die('403 Forbidden');
    }
    return $user;
}

/**
 * 危险操作「二次密码确认」（F11）。
 * 破坏性后台操作（删用户 / 重置 2FA / 重置密码等）除了校验权限码之外，
 * 还要求当前管理员重新输入自己的登录密码，作为「step-up 认证」：
 * 这样即使会话令牌被窃取、或管理员临时离开未锁屏，攻击者也无法一键清库。
 * 密码由前端放在 admin_password 字段；缺失或不匹配一律拒绝，并回传 reauth_required
 * 供前端弹出密码框后重试。
 */
function requireAdminPassword($admin) {
    $pwd = (string)($_POST['admin_password'] ?? '');
    if ($pwd === '') {
        jsonError('该操作风险较高，请输入你的登录密码以确认', 403, ['reauth_required' => true]);
    }
    if (empty($admin['password_hash']) || !verifyPassword($pwd, $admin['password_hash'])) {
        jsonError('密码不正确，操作已取消', 403, ['reauth_required' => true]);
    }
}

/**
 * 管理员权限模版：t1 仅查看（只读）/ t2 低危权限 / t3 全站权限（低于站长）。
 * 基于 getAllPermissionDefinitions() 动态构造；super_admin 拥有全部权限，不受模版限制。
 * 后续新增权限 key 时，T3 自动纳入（排除项除外），T1/T2 需按需补充。
 * @return array ['t1'=>['name','label','permissions'=>[...]], 't2'=>..., 't3'=>...]
 */
function getPermissionTemplates() {
    static $templates = null;
    if ($templates !== null) {
        return $templates;
    }

    $all = [];
    foreach (getAllPermissionDefinitions() as $group) {
        foreach ($group as $key => $desc) {
            $all[] = $key;
        }
    }

    // T1：仅查看类权限，且不含 view_anonymous_author（不能看匿名动态的发布者）
    $viewOnly = array_values(array_filter($all, function ($k) {
        return strpos($k, 'view_') === 0 && $k !== 'view_anonymous_author';
    }));

    // T2 在 T1 基础上追加的低危写权限（审核/编辑类非破坏性操作）
    $lowRiskWrite = ['audit_posts', 'edit_announcement', 'edit_rules', 'review_qq_change', 'manage_reports'];

    // T3 排除项（站长专属）：管理超级管理员、查看匿名动态发布者
    $t3Exclude = ['manage_admins', 'view_anonymous_author'];
    $full = array_values(array_filter($all, function ($k) use ($t3Exclude) {
        return !in_array($k, $t3Exclude, true);
    }));

    $templates = [
        't1' => ['name' => 't1', 'label' => '模版T1：仅查看后台（只读）', 'permissions' => $viewOnly],
        't2' => ['name' => 't2', 'label' => '模版T2：低危权限（可审核/编辑公告规则等）', 'permissions' => array_values(array_unique(array_merge($viewOnly, $lowRiskWrite)))],
        't3' => ['name' => 't3', 'label' => '模版T3：全站权限（低于站长）', 'permissions' => $full],
    ];
    return $templates;
}

if (!defined('PERMISSION_TEMPLATES')) {
    define('PERMISSION_TEMPLATES', getPermissionTemplates());
}

/**
 * 获取管理员权限模式：super / custom / t1 / t2 / t3。
 * 兼容旧数据：无 perm_mode 字段时按 custom（逐项权限）处理。
 */
function getPermissionMode($user) {
    if (($user['role'] ?? '') === 'super_admin') {
        return 'super';
    }
    $mode = $user['perm_mode'] ?? 'custom';
    if (isset(PERMISSION_TEMPLATES[$mode])) {
        return $mode;
    }
    return 'custom';
}

/**
 * 权限模式展示文案。
 */
function getPermissionModeLabel($mode) {
    $labels = [
        'super' => '站长',
        'custom' => '自定义',
        't1' => '模版T1（仅查看）',
        't2' => '模版T2（低危）',
        't3' => '模版T3（全站）',
    ];
    return $labels[$mode] ?? '自定义';
}

/**
 * 应用权限模版：清空该用户 admin_permissions 后按模版批量插入，并写入 perm_mode。
 * $templateKey 非法时按 custom 处理（清空权限并置 perm_mode=custom）。
 * @return string 实际生效的模版 key
 */
function applyPermissionTemplate($userId, $templateKey) {
    $userId = (int)$userId;
    $fs = getFS();
    if (!isset(PERMISSION_TEMPLATES[$templateKey])) {
        $templateKey = 'custom';
    }
    $existing = $fs->find('admin_permissions', ['user_id' => $userId]);
    foreach ($existing as $p) {
        $fs->delete('admin_permissions', $p['id']);
    }
    if ($templateKey !== 'custom') {
        foreach (PERMISSION_TEMPLATES[$templateKey]['permissions'] as $perm) {
            $fs->insert('admin_permissions', ['user_id' => $userId, 'permission_key' => $perm]);
        }
    }
    $fs->update('users', $userId, ['perm_mode' => $templateKey]);
    return $templateKey;
}

function checkPermission($user, $permissionKey) {
    if (($user['role'] ?? '') === 'super_admin') return true;
    $mode = getPermissionMode($user);
    if ($mode !== 'custom') {
        // 模版模式：以模版定义为唯一依据，忽略历史逐项记录，
        // 从而保证 T1 只读、T2 低危、T3 全站（不随 admin_permissions 表残留记录被放大权限）
        return in_array($permissionKey, PERMISSION_TEMPLATES[$mode]['permissions'], true);
    }
    $fs = getFS();
    $permissions = $fs->find('admin_permissions', ['user_id' => $user['id'], 'permission_key' => $permissionKey]);
    return !empty($permissions);
}

/**
 * 向所有可访问后台的管理员推送站内通知（用于仪表盘数据变化提醒）。
 * 仅查看权限（t1）的管理员不接收业务变更提醒。
 * @param string $content 通知正文
 * @param string $type 通知类型，默认 admin
 * @return int 实际推送人数
 */
function notifyAdmins($content, $type = 'admin') {
    $fs = getFS();
    $users = $fs->read('users');
    $sent = 0;
    foreach ($users as $u) {
        if (!in_array($u['role'] ?? '', ['admin', 'super_admin'], true)) {
            continue;
        }
        if (!empty($u['is_banned'])) {
            continue;
        }
        if (getPermissionMode($u) === 't1') {
            continue;
        }
        $fs->insert('notifications', [
            'user_id' => $u['id'],
            'type' => $type,
            'content' => $content,
            'is_read' => false,
        ]);
        $sent++;
    }
    return $sent;
}

function checkBanned(&$user) {
    if (!empty($user['is_banned'])) {
        $banUntil = strtotime($user['ban_until'] ?? '');
        if ($banUntil !== false && $banUntil < time()) {
            $fs = getFS();
            $fs->update('users', $user['id'], ['is_banned' => 0, 'ban_reason' => '', 'ban_until' => null]);
            $user['is_banned'] = 0;
            $user['ban_reason'] = '';
            $user['ban_until'] = null;
        }
    }
    return $user;
}

function getAdminPermissions($userId) {
    $fs = getFS();
    $permissions = $fs->find('admin_permissions', ['user_id' => $userId]);
    return array_column($permissions, 'permission_key');
}

function getAllPermissionDefinitions() {
    return [
        '帖子管理' => [
            'view_posts' => '查看全部帖子',
            'audit_posts' => '审核待发布帖子',
            'delete_posts' => '删除任意用户帖子',
            'delete_comments' => '删除任意用户评论',
        ],
        '用户管理' => [
            'view_users' => '查看全部注册用户',
            'view_user_detail' => '查看用户个人信息（姓名/班级/年级）',
            'change_username' => '更改用户用户名',
            'change_user_qq' => '修改用户绑定QQ并邮件通知原QQ',
            'manage_user_title' => '设置/移除用户头衔',
            'review_title_request' => '审核用户头衔申请',
            'review_qq_change' => '审核QQ号修改申请',
            'ban_user' => '临时封禁用户',
            'permanent_ban' => '永久封禁用户',
            'unban_user' => '解除用户封禁',
            'edit_ban_reason' => '编辑封禁备注',
            'reset_user_2fa' => '重置用户2FA密钥',
            'reset_user_password' => '重置用户密码',
            'delete_user' => '删除用户及其内容',
        ],
        '内容与信息安全' => [
            'view_anonymous_author' => '查看匿名/不公开动态的发布者身份（请保密，勿泄露他人隐私）',
            'manage_sensitive_words' => '管理敏感词库（增删敏感词与替换规则）',
        ],
        '站点设置' => [
            'edit_announcement' => '编辑置顶公告',
            'edit_rules' => '修改社区规范',
            'manage_sponsor' => '管理赞助信息与赞助榜单',
            'manage_feature_requests' => '管理功能建议',
            'manage_growth' => '管理成长体系（等级经验规则 / 成就）',
            'view_stats' => '查看全站统计',
        ],
        '管理员管理' => [
            'manage_admins' => '新增/删除/调整管理员权限',
        ],
        '日志与安全' => [
            'view_operation_logs' => '查看操作日志和IP黑名单',
            'view_ai_logs' => '查看 AI 助手调用记录',
            'manage_ip_blacklist' => '管理IP黑名单（添加/移除）',
            'view_illegal_logs' => '查看非法访问日志',
            'view_health' => '查看系统健康状态（磁盘/日志/数据表体检）',
            'export_data' => '导出站点数据',
        ],
        '举报管理' => [
            'view_reports' => '查看用户举报',
            'manage_reports' => '处理举报（删除）',
        ],
    ];
}

// 注：原有 checkRegisterLimit() / recordRegisterAttempt() 一对函数已删除（死代码）。
// 它们读写 `register_limits` 表，但**全站无任何调用点**——注册限流实际由
// api/auth/register.php 的 `checkRateLimit($ip, 'register')` 承担（统一机制，
// 不必再维护第二种实现）。留着会让后来者误以为注册限流走的是 register_limits 表。

function verifyCSRFTokenEnhanced($token) {
    if (empty($token)) {
        return false;
    }
    if (empty($_SESSION['csrf_token'])) {
        // 同 verifyCSRFToken：会话令牌缺失时同样换发新令牌，让前端能拿到 new_csrf_token 自愈重试，
        // 而不是把「会话丢失」误报成「CSRF验证失败」把用户挡在门外。
        lwCSRFRotateForRetry();
        return false;
    }
    if (!lwCSRFTokenMatches((string)$token)) {
        lwCSRFRotateForRetry();
        return false;
    }

    $host = $_SERVER['HTTP_HOST'] ?? '';
    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
    $referer = $_SERVER['HTTP_REFERER'] ?? '';

    // HTTP_HOST 带端口（本地是 127.0.0.1:8899），而 parse_url 取出的 host 不带端口，
    // 直接比较会把同源请求误判成跨源、平白 403，所以统一取出主机名再比。
    $hostOnly = $host;
    if ($host !== '') {
        $parsedHost = parse_url('http://' . $host, PHP_URL_HOST);
        if (is_string($parsedHost) && $parsedHost !== '') {
            $hostOnly = $parsedHost;
        }
    }

    if (!empty($origin)) {
        $originHost = parse_url($origin, PHP_URL_HOST);
        if ($originHost && $originHost !== $hostOnly) {
            return false;
        }
    } elseif (!empty($referer)) {
        $refererHost = parse_url($referer, PHP_URL_HOST);
        if ($refererHost && $refererHost !== $hostOnly) {
            return false;
        }
    }

    return true;
}

function maskPhone($phone) {
    if (mb_strlen($phone) >= 7) {
        return substr($phone, 0, 3) . '****' . substr($phone, -4);
    }
    return '****';
}

function maskEmail($email) {
    $parts = explode('@', $email);
    if (count($parts) === 2) {
        $name = $parts[0];
        if (mb_strlen($name) > 2) {
            $name = $name[0] . '***' . $name[mb_strlen($name) - 1];
        } else {
            $name = $name[0] . '***';
        }
        return $name . '@' . $parts[1];
    }
    return '***@***';
}

/**
 * F15 日志脱敏：把任意日志详情串里的手机号 / 邮箱 / «QQ:xxx» 打码，
 * 避免运营日志落盘真实隐私。仅作用于「展示用详情」，不碰主键/动作名等结构化字段。
 */
function maskSensitive($text) {
    if (!is_string($text) || $text === '') return $text;
    $text = preg_replace_callback('/1[3-9]\d{9}/', function ($m) { return maskPhone($m[0]); }, $text);
    $text = preg_replace_callback('/[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}/', function ($m) { return maskEmail($m[0]); }, $text);
    $text = preg_replace_callback('/(?<=[Qq][Qq][:：\s])\d{5,12}/', function ($m) { return substr($m[0], 0, 2) . '****' . substr($m[0], -2); }, $text);
    return $text;
}

function validatePasswordStrength($password) {
    // 放宽密码要求：仅要求最少位数，并给出强弱提示（前端有强弱条），不再强制大小写/数字/特殊字符组合
    if (mb_strlen($password) < 6) return '密码至少需要6个字符';
    return true;
}

function validateFileUpload($file, $allowedTypes = [], $maxSize = 5242880) {
    if (empty($allowedTypes)) {
        $allowedTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
    }

    if (!isset($file['error']) || is_array($file['error'])) {
        return [false, '无效的上传参数'];
    }

    if ($file['error'] !== UPLOAD_ERR_OK) {
        $errors = [
            UPLOAD_ERR_INI_SIZE   => '文件超过服务器配置限制',
            UPLOAD_ERR_FORM_SIZE  => '文件超过表单限制',
            UPLOAD_ERR_PARTIAL    => '文件上传不完整',
            UPLOAD_ERR_NO_FILE    => '没有选择文件',
            UPLOAD_ERR_NO_TMP_DIR => '服务器临时目录缺失',
            UPLOAD_ERR_CANT_WRITE => '无法写入磁盘',
            UPLOAD_ERR_EXTENSION  => '服务器扩展拦截了上传',
        ];
        return [false, $errors[$file['error']] ?? '未知上传错误'];
    }

    if ($file['size'] > $maxSize) {
        return [false, '文件大小超过限制（最大 ' . round($maxSize / 1048576, 1) . 'MB）'];
    }

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $detectedMime = $finfo->file($file['tmp_name']);

    if (!in_array($detectedMime, $allowedTypes)) {
        return [false, '不允许的文件类型: ' . $detectedMime];
    }

    $handle = fopen($file['tmp_name'], 'rb');
    $header = fread($handle, 8);
    fclose($handle);

    $magicBytes = [
        'image/jpeg' => ["\xFF\xD8\xFF"],
        'image/png'  => ["\x89PNG"],
        'image/gif'  => ["GIF87a", "GIF89a"],
        'image/webp' => ["RIFF"],
    ];

    $validMagic = false;
    if (isset($magicBytes[$detectedMime])) {
        foreach ($magicBytes[$detectedMime] as $magic) {
            if (strpos($header, $magic) === 0) {
                $validMagic = true;
                break;
            }
        }
    }

    if (!$validMagic) {
        return [false, '文件内容与声明类型不匹配，可能是恶意文件'];
    }

    $extMap = [
        'image/jpeg' => '.jpg',
        'image/png'  => '.png',
        'image/gif'  => '.gif',
        'image/webp' => '.webp',
    ];
    $ext     = $extMap[$detectedMime] ?? '';
    $newName = bin2hex(random_bytes(16)) . '_' . time() . $ext;

    return [true, $newName, $detectedMime];
}

/**
 * 解析帖子的 images 字段（库里的存法是 JSON 字符串），并丢掉磁盘上已不存在的图片。
 * 图片文件被清理或迁移丢失后，记录里仍留着这些路径，前端每次渲染都会去请求，
 * 控制台就会一直报 404；这里统一过滤掉，页面按「无图」渲染即可。
 * 只放行以 / 开头的站内路径（含 .. 的一律丢弃，防目录穿越），外链原样保留。
 */
function postImagesExisting($post) {
    $images = is_array($post) ? ($post['images'] ?? '[]') : $post;
    if (is_string($images)) {
        $images = json_decode($images, true);
    }
    if (!is_array($images)) {
        return [];
    }
    $root = rtrim(BASE_PATH, '/\\');
    $result = [];
    foreach ($images as $img) {
        $img = trim((string)$img);
        if ($img === '') {
            continue;
        }
        if (preg_match('#^https?://#i', $img)) {
            $result[] = $img;
            continue;
        }
        if (strpos($img, '..') !== false || $img[0] !== '/') {
            continue;
        }
        if (!is_file($root . str_replace('/', DIRECTORY_SEPARATOR, $img))) {
            continue;
        }
        $result[] = $img;
    }
    return array_values($result);
}

function generateFormToken($formName = 'default') {
    $token = bin2hex(random_bytes(32));
    $key   = 'form_token_' . $formName;

    if (!isset($_SESSION[$key])) {
        $_SESSION[$key] = [];
    }

    $now = time();
    $_SESSION[$key] = array_filter($_SESSION[$key], function ($entry) use ($now) {
        return ($entry['expires'] ?? 0) > $now;
    });

    if (count($_SESSION[$key]) > 20) {
        $_SESSION[$key] = array_slice($_SESSION[$key], -20, 20, true);
    }

    $_SESSION[$key][$token] = [
        'expires' => $now + 600,
        'used'    => false,
        'created' => $now,
    ];

    return $token;
}

function verifyFormToken($token, $formName = 'default') {
    $key = 'form_token_' . $formName;

    if (empty($_SESSION[$key]) || empty($token)) {
        return false;
    }

    if (isset($_SESSION[$key][$token])) {
        $entry = &$_SESSION[$key][$token];
        if ($entry['expires'] > time() && !$entry['used']) {
            $entry['used'] = true;
            return true;
        }
        unset($_SESSION[$key][$token]);
    }

    return false;
}

function detectContentScraping() {
    $ip  = getClientIP();
    $now = time();
    $uri = $_SERVER['REQUEST_URI'] ?? '/';

    // 已登录管理员跳过这套「反爬」启发式：
    // 它按「30 秒内 >20 个不同 URI 且 >30 次请求」判定，站长自己连续翻几页就会被算中，
    // 代价是 30 分钟封禁 + 无提示 403「Access Denied」，非常难自查。
    // 真正要防的采集器是未登录的，这里放行不影响防护效果。
    if (class_exists('WAF') && WAF::isTrustedSession()) {
        return;
    }

    $key = 'waf_scraping_' . str_replace(['.', ':'], '_', $ip);
    if (!isset($_SESSION[$key])) {
        $_SESSION[$key] = [
            'pages'      => [],
            'first_seen' => $now,
            'count'      => 0,
        ];
    }

    $scraping = &$_SESSION[$key];

    if ($now - $scraping['first_seen'] > 60) {
        $scraping = [
            'pages'      => [],
            'first_seen' => $now,
            'count'      => 0,
        ];
    }

    $scraping['count']++;
    $scraping['pages'][$uri] = true;

    // 反爬阈值（2026-10 放宽）：窗口 30s→60s，不同页面 20→40，总请求 30→80，封禁 30min→10min。
    // 原值对「正常用户连着翻几页」太紧（一次页面加载会带出好几个接口请求），
    // 且共享出口 IP（校园网/运营商 NAT）会被整片算进来。真采集器一秒就能刷爆 80 次，
    // 放宽后仍然拦得住，只是不再误伤真人。
    if (count($scraping['pages']) > 40 && $scraping['count'] > 80) {
        WAF::banIP($ip, 'content_scraping', 600);
        require_once __DIR__ . '/ban_page.php';
        http_response_code(429);
        header('Retry-After: 600');
        header('Content-Type: application/json; charset=utf-8');
        $expireAt = time() + 600;
        die(json_encode([
            'success'  => false,
            'banned'   => true,
            'strategy' => lwBanReasonCode('content_scraping'),
            'message'  => '访问被临时拦截（' . lwBanReasonCode('content_scraping') . ' · '
                . lwBanReasonLabel('content_scraping') . '）。预计 '
                . date('Y-m-d H:i:s', $expireAt) . ' 自动恢复。如为误判请联系站长 QQ '
                . LW_BAN_CONTACT_QQ . '（事件编号 ' . lwBanRefId($ip, $expireAt) . '）',
            'unban_at' => $expireAt,
            'ref_id'   => lwBanRefId($ip, $expireAt),
            'contact_qq' => LW_BAN_CONTACT_QQ,
        ], JSON_UNESCAPED_UNICODE));
    }
}

/**
 * 前置安全声明页（gateway）：进入登录/注册/找回密码之前，先确认“本站为官方入口，谨防钓鱼”。
 * 是否已确认过（cookie 记忆，避免每次打扰）。
 */
function hasAcceptedGateway() {
    return !empty($_COOKIE['lw_gate']);
}

/**
 * 用户在前置安全声明页点击“我已了解，继续”后调用，写入确认 cookie。
 */
function acceptGateway() {
    setcookie('lw_gate', '1', time() + 180 * 24 * 3600, '/', '', IS_SECURE, false);
}

/**
 * 判断本设备是否“首次访问且未注册”，用于引导新访客直接注册。
 * Cookie + IP 双保险：本机已有识别 cookie，或该 IP 此前出现过（已访问/已注册设备），
 * 都不视为首访，避免打断正常登录用户。
 */
function isFirstVisitUnregistered() {
    if (!empty($_COOKIE['lw_known'])) {
        return false;
    }
    $ip = getClientIP();
    $fs = getFS();
    $prev = $fs->find('device_ips', ['ip' => $ip]);
    if (!empty($prev)) {
        markDeviceKnown();
        return false;
    }
    // 真正首访：记录 IP 来源，并写入本机 cookie
    $fs->insert('device_ips', ['ip' => $ip, 'first_seen' => date('Y-m-d H:i:s')]);
    markDeviceKnown();
    return true;
}

/**
 * 将本设备标记为“已知”，后续不再被视为首访。
 */
function markDeviceKnown() {
    setcookie('lw_known', '1', time() + 180 * 24 * 3600, '/', '', IS_SECURE, false);
}
