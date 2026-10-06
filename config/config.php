<?php
require_once __DIR__ . '/constants.php';
// 邮件发送类（SMTP，QQ 邮箱配置见 config/mail_config.php；该配置已 .gitignore 不入开源版）
require_once __DIR__ . '/../includes/qqmail.php';

// 静态资源版本号：基于文件最后修改时间，避免浏览器缓存旧版本导致样式/脚本失效
/**
 * 返回实际要输出的静态资源路径：能用 .min 就用 .min，否则回退源文件。
 *
 * 准入条件（三条全满足才用 .min）：
 *   ① 源文件存在        —— 否则 source 本身都没了，谈压缩没意义
 *   ② .min 文件存在
 *   ③ .min 的 mtime >= 源的 mtime —— 防止改了源文件却忘了重新压缩，
 *      导致线上长期跑旧代码。这一条是整个方案的安全阀，别删。
 *
 * 于是「改源文件 → 忘了压缩」的后果只是自动退回未压缩版（功能对、体积大），
 * 绝不会出现「线上跑的是上一版逻辑」这种最难查的故障。
 *
 * 同时把版本号接到「实际输出的那个文件」上，缓存失效才不会错位。
 */
function asset_url($file) {
    $root = dirname(__DIR__);
    $src  = $root . $file;
    if (!file_exists($src)) {
        return $file;
    }
    $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
    if ($ext !== 'js' && $ext !== 'css') {
        return $file;
    }
    $min = preg_replace('/\.(js|css)$/i', '.min.$1', $file);
    $minPath = $root . $min;
    if (is_file($minPath) && filemtime($minPath) >= filemtime($src)) {
        return $min;
    }
    return $file;
}

/**
 * 缓存打断时间戳。注意它调 asset_url() 拿「真正会输出的那个文件」，
 * 而不是传进来的原始路径 —— 否则 .min 更新了但源文件没动时，
 * 版本号是不变的，浏览器会继续用缓存里的旧 .min。
 */
function asset_ver($file) {
    $real = asset_url($file);
    $path = dirname(__DIR__) . $real;
    if (file_exists($path)) {
        return filemtime($path);
    }
    return 'v1';
}

date_default_timezone_set('Asia/Shanghai');

$isSecure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ||
            (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https') ||
            (isset($_SERVER['SERVER_PORT']) && (int)$_SERVER['SERVER_PORT'] === 443);
define('IS_SECURE', $isSecure);

// F12 后台 IP 白名单（默认关闭）：逗号分隔的允许 IP；仅当部署方显式设了非空值时，
// requireAdmin() 才拒绝白名单外的 IP。共享主机 IP 易变，故默认空 = 不启用。
if (!defined('ADMIN_ALLOWED_IPS')) {
    define('ADMIN_ALLOWED_IPS', '');
}

header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');
header('X-XSS-Protection: 1; mode=block');
header('Referrer-Policy: strict-origin-when-cross-origin');
header('Permissions-Policy: camera=(), microphone=(), geolocation=(), interest-cohort=()');
header('Cross-Origin-Opener-Policy: same-origin');
header('Cross-Origin-Resource-Policy: same-origin');
header('X-DNS-Prefetch-Control: off');

// F14 安全加固：生产环境绝不向浏览器回显 PHP 错误/警告（避免泄露路径、变量名、SQL 片段）。
// 错误仍记入服务器错误日志（log_errors=1），便于排障但不暴露给访客。
// 注意：CLI 探针（如 render_harness）如需看警告，可单独设 LW_SHOW_ERRS=1 覆盖。
if (!isset($_SERVER['LW_SHOW_ERRS']) || $_SERVER['LW_SHOW_ERRS'] !== '1') {
    ini_set('display_errors', '0');
    ini_set('display_startup_errors', '0');
    ini_set('log_errors', '1');
}

// 缓存策略分两档（原先一律 no-store，代价是浏览器「往返缓存 bfcache」被禁用：
// 用户点返回要整页重跑 PHP，白屏明显）。
// 接口：含用户私有数据，必须 no-store；
// 页面：改用 no-cache —— 使用前仍要回服务器校验，安全性不变，但允许 bfcache，
//       返回时瞬开且表单/滚动位置不丢。
$_lwReqUri = $_SERVER['REQUEST_URI'] ?? '';
if (strpos($_lwReqUri, '/api/') !== false) {
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
} else {
    header('Cache-Control: private, no-cache, max-age=0, must-revalidate');
}
unset($_lwReqUri);

// 会话配置在写入任何输出之前设置。
// 包裹临时关闭 E_DEPRECATED/E_NOTICE：避免弃用/提示先写入输出缓冲，
// 导致 session_start()/header() 报「headers already sent」。
$__errPrev = error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE);
ini_set('session.cookie_secure', $isSecure ? 1 : 0);
ini_set('session.cookie_httponly', 1);
ini_set('session.cookie_samesite', 'Lax');
ini_set('session.gc_maxlifetime', SESSION_LIFETIME);
// 注意：关闭 use_strict_mode。部分共享虚拟主机对 session.id 长度/存储有限制，
// strict_mode=1 会丢弃不认可的 cookie 导致每次请求都新建 session，
// 表现为登录/注册等提交时 CSRF_token 永远不匹配（403「CSRF验证失败」）。
// httpOnly/SameSite/secure 等主要防护仍保留。
ini_set('session.use_strict_mode', 0);
// PHP 8.4 起 session.sid_length 已弃用且不可再修改，交由 PHP 自行管理，故不再设置。
ini_set('session.sid_bits_per_character', 6);
error_reporting($__errPrev);

// 关掉 PHP 会话自带的缓存头（默认 nocache 会发 no-store, must-revalidate）。
// 它发生在 session_start() 内部、晚于上面手写的策略，会把手写的头覆盖掉，
// 于是页面侧「允许 bfcache」的分支永远失效。交给我们自己统一管。
session_cache_limiter('');

session_start();

// ── F2/F16 CSP 收紧（2026-10-05）──────────────────────────────────────────────
// 第三方域名一律显式列举，不再用通配（https:）开口子。
// 每一项都对应代码里真实存在的引用，核对方式：全仓 grep 该域名。
//   q.qlogo.cn        —— QQ 头像（img-src，浏览器直连）
//   api.qrserver.com  —— 二维码生成（img-src 展示 + connect-src 预取）
//   v1.hitokoto.cn    —— 每日一句（connect-src）
//   ipapi.co / api.ipify.org / api.ip.sb / api.ipapi.is —— 工具页 IP 查询（connect-src）
// 已移除的无效项：
//   font-src 里的 https://fonts.gstatic.com（全站无 @font-face、无 Google Fonts 引用）
//   img-src 里的 https:（帖子图片一律落本站 /uploads/images/，无外链图片）
//   frame-src 里的 https:（全站无 <iframe>，收敛为 'none'）
define('LW_CSP', "default-src 'self'; "
    . "script-src 'self' 'unsafe-inline'; "
    . "style-src 'self' 'unsafe-inline'; "
    . "img-src 'self' data: blob: https://q.qlogo.cn https://api.qrserver.com; "
    . "font-src 'self'; "
    . "connect-src 'self' https://v1.hitokoto.cn https://ipapi.co https://api.ipify.org https://api.qrserver.com https://api.ip.sb https://api.ipapi.is; "
    . "media-src 'self' blob:; "
    . "frame-src 'none'; frame-ancestors 'self'; "
    . "base-uri 'self'; form-action 'self'; object-src 'none'; "
    . "upgrade-insecure-requests;");
header('Content-Security-Policy: ' . LW_CSP);


if (!isset($_SESSION['_initiated'])) {
    session_regenerate_id(true);
    $_SESSION['_initiated'] = true;
}

if ($isSecure) {
    header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
}

function antiCrawlerCheck() {
    $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
    $ip = WAF::getClientIP();

    if (WAF::isBlacklisted($ip) && !WAF::isTrustedSession()) {
        // 已登录管理员放行（否则被封后连后台解封页都进不去），详见 WAF::isTrustedSession。
        // 普通访客走独立封禁页：写清触发规则、受限 IP、精确解封时间与站长联系方式，
        // 不再是原来那行没头没尾的「Access Denied」。
        require_once __DIR__ . '/../includes/ban_page.php';
        lwRenderBanPage(WAF::getBanEntry($ip), $ip);
    }

    if (empty($ua)) {
        logIllegalAccess($ip, $_SERVER['REQUEST_URI'] ?? '/', 'empty_ua');
        http_response_code(403);
        die('Access Denied');
    }

    $badBotPattern = '/(' . implode('|', array_map(function($b) { return preg_quote($b, '/'); }, [
        'AhrefsBot', 'SemrushBot', 'DotBot', 'MJ12bot', 'BLEXBot',
        'AspiegelBot', 'PetalBot', 'YandexBot', 'Baiduspider', 'Sogou',
        'Bytespider', 'DataForSeoBot', 'serpstatbot', 'ZoominfoBot',
        'ClaudeBot', 'GPTBot', 'ChatGPT-User', 'CCBot', 'anthropic-ai',
        'Google-Extended', 'Amazonbot', 'FacebookBot', 'Twitterbot',
        'censys', 'nmap', 'masscan', 'zgrab', 'gobuster',
        'dirbuster', 'nikto', 'sqlmap', 'acunetix', 'nessus',
        'openvas', 'wpscan', 'joomscan', 'whatweb', 'webscan',
        'Netcraft', 'Cohere', 'PerplexityBot', 'YouBot', 'ImagesiftBot',
        'Omgilibot', 'Omgili', 'Webzio', 'TurnitinBot', 'Brandwatch',
        'magpie-crawler', 'Scrapy', 'PhantomJS', 'HeadlessChrome',
        'python-requests', 'python-urllib',
    ])) . ')/i';
    if (preg_match($badBotPattern, $ua)) {
        logIllegalAccess($ip, $_SERVER['REQUEST_URI'] ?? '/', 'bad_bot');
        http_response_code(403);
        die('Access Denied');
    }

    $hackerPattern = '/(\.(sql|db|bak|backup|old|save|config|env|git|svn|hg)(\b|\/|\?|$))|' .
        '(\b(union\s+select|insert\s+into|drop\s+table|alter\s+table)\b)|' .
        '(\b(eval|exec|system|passthru|shell_exec|popen)\s*\()|' .
        '(\b\.\.\/|\b\.\.\\\)|' .
        '(\/wp-admin|\/wp-login|\/wp-content|\/wp-includes)|' .
        '(\/phpmyadmin|\/pma|\/mysql|\/adminer)|' .
        '(\/\.env|\/\.git\/|\/\.svn\/|\/\.hg\/)|' .
        '(\/config\.php|\/database\.php|\/wp-config\.php)/i';
    $requestUri = $_SERVER['REQUEST_URI'] ?? '';
    if (preg_match($hackerPattern, $requestUri)) {
        logIllegalAccess($ip, $requestUri, 'hack_attempt');
        http_response_code(403);
        die('Access Denied');
    }

    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    $badMethods = ['TRACE', 'TRACK', 'DEBUG'];
    if (in_array(strtoupper($method), $badMethods)) {
        logIllegalAccess($ip, $requestUri, 'bad_method:' . $method);
        http_response_code(405);
        die('Method Not Allowed');
    }

    if (!isset($_SESSION['_rate'])) {
        $_SESSION['_rate'] = [];
    }
    $now = time();
    $_SESSION['_rate'] = array_filter($_SESSION['_rate'], function($t) use ($now) { return $t > $now - 60; });
    $_SESSION['_rate'][] = $now;
    if (count($_SESSION['_rate']) > 300) {
        logIllegalAccess($ip, $requestUri, 'rate_limit_exceeded');
        http_response_code(429);
        die('Too Many Requests');
    }
}

function logIllegalAccess($ip, $file, $type = '') {
    $fs = getFS();
    try {
        // 日志封顶 300 条：避免表无限膨胀（整文件重写越来越慢，拖累共享主机）
        $fs->insertCapped('illegal_access_logs', [
            'ip' => $ip,
            'file' => $file,
            'type' => $type,
            'ua' => mb_substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 500),
            'method' => $_SERVER['REQUEST_METHOD'] ?? 'GET',
        ], 300);
    } catch (Exception $e) {
        error_log('logIllegalAccess failed: ' . $e->getMessage());
    }
}

spl_autoload_register(function($class) {
    $file = __DIR__ . '/../includes/' . $class . '.php';
    if (file_exists($file)) {
        require_once $file;
    }
});

require_once __DIR__ . '/../includes/filestorage.php';
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../includes/waf.php';
require_once __DIR__ . '/../includes/i18n.php';
// 图标入口（lw_icon）：全站页面都要用，放在引导层统一加载，避免各页面漏 require 触发
// 「Call to undefined function lw_icon()」白屏 —— includes/ 下是函数，autoloader 只管类。
require_once __DIR__ . '/../includes/icons.php';

WAF::init();

$requestUri = $_SERVER['REQUEST_URI'] ?? '';
$isApiRequest = strpos($requestUri, '/api/') !== false;
$isStaticAsset = preg_match('/\.(css|js|png|jpg|jpeg|gif|svg|ico|woff2?|ttf|eot)$/i', $requestUri);
if (!$isApiRequest && !$isStaticAsset) {
    antiCrawlerCheck();
}

if ($isApiRequest && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
    $rawBody = file_get_contents('php://input');

    if (stripos($contentType, 'application/json') !== false && !empty($rawBody)) {
        $jsonData = json_decode($rawBody, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            http_response_code(400);
            header('Content-Type: application/json; charset=utf-8');
            die(json_encode([
                'success' => false,
                'message' => '请求数据格式错误',
            ], JSON_UNESCAPED_UNICODE));
        }
    }

    $contentLength = (int)($_SERVER['CONTENT_LENGTH'] ?? 0);
    if ($contentLength > 2 * 1024 * 1024) {
        http_response_code(413);
        header('Content-Type: application/json; charset=utf-8');
        die(json_encode([
            'success' => false,
            'message' => '请求体过大，最大允许 2MB',
        ], JSON_UNESCAPED_UNICODE));
    }
}

// SSE（流式）端点必须在 require 本文件之前 define('LW_NO_GZIP', 1)：
// gzip 会把整段响应攒够一块才发，流式就退化成「一次性返回」，思考过程也就没法实时同步了。
if (!defined('LW_NO_GZIP') && !ini_get('zlib.output_compression') && function_exists('ob_gzhandler')) {
    // 清空 PHP 默认 output_buffering 可能残留的空缓冲区，确保 gzip 压缩真正生效
    while (ob_get_level() > 0 && ob_get_length() === 0) {
        ob_end_clean();
    }
    ob_start('ob_gzhandler');
}