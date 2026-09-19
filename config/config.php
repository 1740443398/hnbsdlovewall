<?php
require_once __DIR__ . '/constants.php';
// 邮件发送类（SMTP，QQ 邮箱配置见 config/mail_config.php；该配置已 .gitignore 不入开源版）
require_once __DIR__ . '/../includes/qqmail.php';

// 静态资源版本号：基于文件最后修改时间，避免浏览器缓存旧版本导致样式/脚本失效
function asset_ver($file) {
    $path = dirname(__DIR__) . $file;
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

header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');
header('X-XSS-Protection: 1; mode=block');
header('Referrer-Policy: strict-origin-when-cross-origin');
header('Permissions-Policy: camera=(), microphone=(), geolocation=(), interest-cohort=()');
header('Cross-Origin-Opener-Policy: same-origin');
header('Cross-Origin-Resource-Policy: same-origin');
header('X-DNS-Prefetch-Control: off');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

ini_set('session.cookie_secure', $isSecure ? 1 : 0);
ini_set('session.cookie_httponly', 1);
ini_set('session.cookie_samesite', 'Lax');
ini_set('session.gc_maxlifetime', SESSION_LIFETIME);
// 注意：关闭 use_strict_mode。部分共享虚拟主机对 session.id 长度/存储有限制，
// strict_mode=1 会丢弃不认可的 cookie 导致每次请求都新建 session，
// 表现为登录/注册等提交时 CSRF_token 永远不匹配（403「CSRF验证失败」）。
// httpOnly/SameSite/secure 等主要防护仍保留。
ini_set('session.use_strict_mode', 0);
ini_set('session.sid_length', 48);
ini_set('session.sid_bits_per_character', 6);

session_start();
header("Content-Security-Policy: default-src 'self'; script-src 'self' 'unsafe-inline'; style-src 'self' 'unsafe-inline'; img-src 'self' data: https:; font-src 'self' https://fonts.gstatic.com; connect-src 'self' https://trae-api-cn.mchost.guru https://v2.jinrishici.com https://v1.hitokoto.cn https://ipapi.co https://api.ipify.org https://api.qrserver.com https://api.ip.sb https://api.ipapi.is; frame-ancestors 'self'; base-uri 'self'; form-action 'self'; object-src 'none'; media-src 'self' blob:; frame-src 'self' https:; upgrade-insecure-requests;");

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

    if (WAF::isBlacklisted($ip)) {
        http_response_code(403);
        die('Access Denied');
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
        '(\b\.\.\/|\b\.\.\\\\)|' .
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
    if (count($_SESSION['_rate']) > 120) {
        logIllegalAccess($ip, $requestUri, 'rate_limit_exceeded');
        http_response_code(429);
        die('Too Many Requests');
    }
}

function logIllegalAccess($ip, $file, $type = '') {
    $fs = getFS();
    try {
        $fs->insert('illegal_access_logs', [
            'ip' => $ip,
            'file' => $file,
            'type' => $type,
            'ua' => mb_substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 500),
            'method' => $_SERVER['REQUEST_METHOD'] ?? 'GET',
        ]);
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

if (!ini_get('zlib.output_compression') && function_exists('ob_gzhandler')) {
    // 清空 PHP 默认 output_buffering 可能残留的空缓冲区，确保 gzip 压缩真正生效
    while (ob_get_level() > 0 && ob_get_length() === 0) {
        ob_end_clean();
    }
    ob_start('ob_gzhandler');
}
