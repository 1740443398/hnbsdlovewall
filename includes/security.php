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

function generateCSRFToken() {
    if (empty($_SESSION['csrf_token']) || time() - ($_SESSION['csrf_token_time'] ?? 0) > 1800) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        $_SESSION['csrf_token_time'] = time();
    }
    return $_SESSION['csrf_token'];
}

function verifyCSRFToken($token) {
    if (empty($_SESSION['csrf_token']) || empty($token)) {
        return false;
    }
    return hash_equals($_SESSION['csrf_token'], $token);
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
            'details' => $details,
            'ip' => getClientIP()
        ]);
    } catch (Exception $e) {
        error_log('logUserActivity failed: ' . $e->getMessage());
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
 * 是否处于暑假（7~8月）——用于"新高一/新高二"的前缀展示。
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
 * 年级显示文案，暑假期间对未毕业者加"新"前缀（新高一/新高二/新高三）。
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
 * "已毕业"起始年：用于某届学生高一那一年（A-3，即今年夏季刚毕业那一届）。
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

function jsonError($message, $code = 400) {
    jsonResponse(['success' => false, 'message' => $message], $code);
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

function requireAdmin() {
    $user = requireLogin();
    if (!in_array($user['role'], ['admin', 'super_admin'])) {
        http_response_code(403);
        die('403 Forbidden');
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

function checkPermission($user, $permissionKey) {
    if ($user['role'] === 'super_admin') return true;
    $fs = getFS();
    $permissions = $fs->find('admin_permissions', ['user_id' => $user['id'], 'permission_key' => $permissionKey]);
    return !empty($permissions);
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
            'review_qq_change' => '审核QQ号修改申请',
            'ban_user' => '临时封禁用户',
            'permanent_ban' => '永久封禁用户',
            'unban_user' => '解除用户封禁',
            'edit_ban_reason' => '编辑封禁备注',
            'view_2fa_status' => '查看用户2FA状态',
            'reset_user_2fa' => '重置用户2FA密钥',
            'reset_user_password' => '重置用户密码',
            'delete_user' => '删除用户及其内容',
        ],
        '内容与信息安全' => [
            'view_anonymous_author' => '查看匿名/不公开动态的发布者身份（请保密，勿泄露他人隐私）',
        ],
        '站点设置' => [
            'edit_announcement' => '编辑置顶公告',
            'edit_rules' => '修改社区规范',
            'view_stats' => '查看全站统计',
            'toggle_post_audit' => '开启/关闭发帖审核',
            'toggle_geetest' => '控制极验开关',
            'edit_geetest' => '修改极验ID与密钥',
            'toggle_https' => '切换HTTPS强制跳转',
        ],
        '管理员管理' => [
            'manage_admins' => '新增/删除/调整管理员权限',
        ],
        '日志与安全' => [
            'view_operation_logs' => '查看操作日志和IP黑名单',
            'manage_ip_blacklist' => '管理IP黑名单（添加/移除）',
            'view_illegal_logs' => '查看非法访问日志',
        ],
        '举报管理' => [
            'view_reports' => '查看用户举报',
            'manage_reports' => '处理举报（删除）',
        ],
    ];
}

function checkLoginAttempts($identifier) {
    $fs = getFS();
    $ip = getClientIP();
    $now = time();

    $attempts = $fs->read('login_attempts');
    if (!is_array($attempts)) $attempts = [];

    $attempts = array_values(array_filter($attempts, function($a) use ($now) {
        return ($a['expire_at'] ?? 0) > $now;
    }));

    foreach ($attempts as $a) {
        if (($a['identifier'] ?? '') === $identifier && ($a['count'] ?? 0) >= 5) {
            $remaining = ($a['expire_at'] ?? 0) - $now;
            return [false, '账号已被临时锁定，请' . ceil($remaining / 60) . '分钟后再试'];
        }
        if (($a['ip'] ?? '') === $ip && ($a['count'] ?? 0) >= 10) {
            WAF::banIP($ip, 'login_brute_force', 900);
            return [false, 'IP已被临时锁定，请15分钟后再试'];
        }
    }

    return [true, ''];
}

function recordLoginAttempt($identifier, $success) {
    if ($success) {
        $fs = getFS();
        $attempts = $fs->read('login_attempts');
        if (!is_array($attempts)) return;
        $attempts = array_values(array_filter($attempts, function($a) use ($identifier) {
            return ($a['identifier'] ?? '') !== $identifier;
        }));
        $fs->write('login_attempts', $attempts);
        return;
    }

    $fs = getFS();
    $ip = getClientIP();
    $now = time();

    $attempts = $fs->read('login_attempts');
    if (!is_array($attempts)) $attempts = [];

    $attempts = array_values(array_filter($attempts, function($a) use ($now) {
        return ($a['expire_at'] ?? 0) > $now;
    }));

    $found = false;
    foreach ($attempts as $i => $a) {
        if (($a['identifier'] ?? '') === $identifier) {
            $attempts[$i]['count'] = ($a['count'] ?? 0) + 1;
            $attempts[$i]['time'] = $now;
            $attempts[$i]['ip'] = $ip;
            if ($attempts[$i]['count'] >= 5) {
                $attempts[$i]['expire_at'] = $now + 900;
            }
            $found = true;
            break;
        }
    }
    if (!$found) {
        $attempts[] = [
            'identifier' => $identifier,
            'ip' => $ip,
            'count' => 1,
            'time' => $now,
            'expire_at' => $now + 3600
        ];
    }

    if (count($attempts) > 200) {
        $attempts = array_slice($attempts, -200);
    }
    $fs->write('login_attempts', $attempts);
}

function checkRegisterLimit() {
    $fs = getFS();
    $ip = getClientIP();
    $now = time();

    $regs = $fs->read('register_limits');
    if (!is_array($regs)) $regs = [];

    $regs = array_values(array_filter($regs, function($r) use ($now) {
        return ($r['expire_at'] ?? 0) > $now;
    }));

    $minuteCount = 0;
    $dayCount = 0;
    foreach ($regs as $r) {
        if (($r['ip'] ?? '') === $ip) {
            if (($r['time'] ?? 0) > $now - 60) $minuteCount++;
            if (($r['time'] ?? 0) > $now - 86400) $dayCount++;
        }
    }

    if ($minuteCount >= 3) return [false, '注册过于频繁，请1分钟后再试'];
    if ($dayCount >= 10) return [false, '今日注册次数已达上限'];

    return [true, ''];
}

function recordRegisterAttempt() {
    $fs = getFS();
    $ip = getClientIP();
    $now = time();

    $regs = $fs->read('register_limits');
    if (!is_array($regs)) $regs = [];

    $regs = array_values(array_filter($regs, function($r) use ($now) {
        return ($r['expire_at'] ?? 0) > $now;
    }));

    $regs[] = [
        'ip' => $ip,
        'time' => $now,
        'expire_at' => $now + 86400
    ];

    if (count($regs) > 500) {
        $regs = array_slice($regs, -500);
    }
    $fs->write('register_limits', $regs);
}

function checkSessionSecurity() {
    if (empty($_SESSION['user_id'])) return true;

    $currentIP = getClientIP();
    $currentUA = mb_substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 200);

    if (!isset($_SESSION['_bound_ip'])) {
        $_SESSION['_bound_ip'] = $currentIP;
        $_SESSION['_bound_ua'] = $currentUA;
        return true;
    }

    $boundIP = $_SESSION['_bound_ip'];
    $boundParts = explode('.', $boundIP);
    $currentParts = explode('.', $currentIP);

    $ipChanged = false;
    if (count($boundParts) === 4 && count($currentParts) === 4) {
        if ($boundParts[0] !== $currentParts[0] || $boundParts[1] !== $currentParts[1]) {
            $ipChanged = true;
        }
    } else {
        $ipChanged = ($boundIP !== $currentIP);
    }

    $uaChanged = ($_SESSION['_bound_ua'] !== $currentUA);

    if ($ipChanged && $uaChanged) {
        $_SESSION = [];
        session_unset();
        session_destroy();
        return false;
    }

    return true;
}

function verifyCSRFTokenEnhanced($token) {
    if (empty($_SESSION['csrf_token']) || empty($token)) {
        return false;
    }
    if (!hash_equals($_SESSION['csrf_token'], $token)) {
        return false;
    }

    $host = $_SERVER['HTTP_HOST'] ?? '';
    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
    $referer = $_SERVER['HTTP_REFERER'] ?? '';

    if (!empty($origin)) {
        $originHost = parse_url($origin, PHP_URL_HOST);
        if ($originHost && $originHost !== $host) {
            return false;
        }
    } elseif (!empty($referer)) {
        $refererHost = parse_url($referer, PHP_URL_HOST);
        if ($refererHost && $refererHost !== $host) {
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

    $key = 'waf_scraping_' . str_replace(['.', ':'], '_', $ip);
    if (!isset($_SESSION[$key])) {
        $_SESSION[$key] = [
            'pages'      => [],
            'first_seen' => $now,
            'count'      => 0,
        ];
    }

    $scraping = &$_SESSION[$key];

    if ($now - $scraping['first_seen'] > 30) {
        $scraping = [
            'pages'      => [],
            'first_seen' => $now,
            'count'      => 0,
        ];
    }

    $scraping['count']++;
    $scraping['pages'][$uri] = true;

    if (count($scraping['pages']) > 20 && $scraping['count'] > 30) {
        WAF::banIP($ip, 'content_scraping', 1800);
        http_response_code(429);
        header('Retry-After: 1800');
        header('Content-Type: application/json; charset=utf-8');
        die(json_encode([
            'success' => false,
            'message' => '检测到异常访问模式，请稍后再试',
        ], JSON_UNESCAPED_UNICODE));
    }
}

/**
 * 前置安全声明页（gateway）：进入登录/注册/找回密码之前，先确认"本站为官方入口，谨防钓鱼"。
 * 是否已确认过（cookie 记忆，避免每次打扰）。
 */
function hasAcceptedGateway() {
    return !empty($_COOKIE['lw_gate']);
}

/**
 * 用户在前置安全声明页点击"我已了解，继续"后调用，写入确认 cookie。
 */
function acceptGateway() {
    setcookie('lw_gate', '1', time() + 180 * 24 * 3600, '/', '', IS_SECURE, false);
}

/**
 * 判断本设备是否"首次访问且未注册"，用于引导新访客直接注册。
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
 * 将本设备标记为"已知"，后续不再被视为首访。
 */
function markDeviceKnown() {
    setcookie('lw_known', '1', time() + 180 * 24 * 3600, '/', '', IS_SECURE, false);
}
