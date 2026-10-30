<?php
// PHP2Shell（CVE-2026-109618/109619）缓解层：必须在任何业务代码之前生效
require_once __DIR__ . '/../includes/php2shell_guard.php';

/* =====================================================================
 * 加载顺序（改动前务必读完）
 * =====================================================================
 *   1. filestorage.php  —— 提供 getFS()，是读 settings.json 的基础
 *   2. cfg_registry.php —— 配置注册表，提供 lwCfg() / lwCfgIntSafe()
 *   3. constants.php    —— 常量定义，内部会调 lwCfgIntSafe() 取后台值
 *
 * 为什么不能按原来的顺序（先 constants 再其它）：
 *   constants.php 里有几个常量（游客可见条数、每页条数、记住登录天数…）
 *   现在要允许后台改。它们必须在 define 的那一刻就知道后台值，
 *   而 define 之后无法重定义 —— 所以只能「先备好取值能力，再定义常量」。
 *
 * 为什么 filestorage 要最先：
 *   lwCfg() 最终还是读 data/settings.json，走的是 getFS()。
 *   filestorage.php 只定义类与函数，没有副作用，提前加载是安全的。
 * =====================================================================
 */
require_once __DIR__ . '/../includes/filestorage.php';
require_once __DIR__ . '/../includes/cfg_registry.php';

require_once __DIR__ . '/constants.php';
// 邮件发送类（SMTP，QQ 邮箱配置见 config/mail_config.php；该配置已 .gitignore 不入开源版）
require_once __DIR__ . '/../includes/qqmail.php';

// 缓存分层（分档规则、ETag/304、公开接口共享缓存的唯一入口）。
// 必须早于任何 header() —— ob_start 要在输出前挂上，否则头已经发了改不了。
require_once __DIR__ . '/../includes/http_cache.php';

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
    /* 优先级：**体积更小的那个** > 源文件
     *
     * 【为什么不再无条件优先 .obf】
     * 原来固定 .obf > .min > 源。但混淆会把字符串搬进数组、
     * 加一堆运行时解包代码，体积反而暴涨：
     *     enhancements.js      524 KB
     *     enhancements.min.js  333 KB   ← 最小
     *     enhancements.obf.js  824 KB   ← 混淆后反而大了 57%
     *     main.js              189 KB
     *     main.min.js          109 KB   ← 最小
     *     main.obf.js          275 KB   ← 大了 45%
     *
     * 在「每个请求固定 ~950ms」的主机上，体积差 2.5 倍直接体现在
     * 首屏时间上。用户反馈的「卡到发不出帖子」很大一部分来自这里。
     *
     * 现在改成：.obf 和 .min 都存在时，选字节数更小的那个。
     * 混淆的「防逆向」价值仍然保留（当它确实更小时），
     * 但不再为了混淆白白多传一倍流量。
     *
     * 为什么不直接覆盖 main.js：
     *   本地开发要读源码调试。保留独立产物，改源码后跑构建脚本生成即可。
     *
     * 为什么不比 mtime：
     *   踩过两次 —— FTP 上传会重置 mtime，两边先后关系随时变；
     *   且源文件改一行就整份退回未压缩版。同步产物属于发布流程的责任。
     */
    $min = preg_replace('/\.(js|css)$/i', '.min.$1', $file);
    $minPath = $root . $min;

    // 候选：源文件总是可用
    $cands = [['rel' => $file, 'abs' => $src]];

    // 只对 JS 考虑混淆（CSS 混淆意义不大）
    if ($ext === 'js') {
        $obf = preg_replace('/\.js$/i', '.obf.js', $file);
        if (is_file($root . $obf)) {
            $cands[] = ['rel' => $obf, 'abs' => $root . $obf];
        }
    }
    if (is_file($minPath)) {
        $cands[] = ['rel' => $min, 'abs' => $minPath];
    }

    // 选体积最小的（源文件一般不参与竞争，但它兜底）
    $best = null;
    $bestSize = PHP_INT_MAX;
    foreach ($cands as $c) {
        $sz = @filesize($c['abs']);
        if ($sz === false || $sz <= 0) {
            continue;
        }
        // 源文件只在没有构建产物时才用
        if ($c['rel'] === $file && count($cands) > 1) {
            continue;
        }
        if ($sz < $bestSize) {
            $bestSize = $sz;
            $best = $c['rel'];
        }
    }

    return $best !== null ? $best : $file;
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
// 【2026-10-24】缓存策略统一交给 includes/http_cache.php 的 lwSendCacheHeaders()。
//
// 原来这里写死了两档（接口 no-store / 页面 private,no-cache），
// 结果有两个问题：
//   1. 它比 http_cache.php 先执行，后者的分档（公开接口 public+swr、
//      游客公开页 public+60s）会被这里**覆盖**，等于白做；
//   2. 游客访问首页/排行榜这种「人人相同」的页面也 private，每次都要整页回源。
//
// 现在只给一个**兜底**值，真正的分档由 lwSendCacheHeaders() 在业务逻辑之后
// 按「是否换发会话 / 是否登录 / 是否在公开白名单」精确决定。
// 兜底用 no-cache（不是 no-store）：即使后续任何环节出错没下发策略，
// 也至少保证「用前回源校验」，不会出现陈旧页面被长期复用。
// 接口的 no-store 语义由 lwResolveCachePolicy() 的默认分支保证（非 GET/换发会话 → no-store）。
header('Cache-Control: private, no-cache, max-age=0, must-revalidate');

// 会话配置在写入任何输出之前设置。
// 包裹临时关闭 E_DEPRECATED/E_NOTICE：避免弃用/提示先写入输出缓冲，
// 导致 session_start()/header() 报「headers already sent」。
$__errPrev = error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE);
ini_set('session.cookie_secure', $isSecure ? 1 : 0);
ini_set('session.cookie_httponly', 1);
ini_set('session.cookie_samesite', 'Lax');
/* =====================================================================
 * 会话存活时间 —— 这里有个踩过的坑
 * =====================================================================
 * 原来只写了 ini_set('session.gc_maxlifetime', SESSION_LIFETIME)。
 * 实测发现它**没有生效**：realpath(ini_get(...)) 仍是 1440（24 分钟），
 * 因为部分共享主机在 php.ini 里用 PHP_INI_SYSTEM 锁死了这一项，
 * ini_set 静默失败 —— 不报错，但值没变。
 *
 * 后果就是：用户登录 24 分钟后 session 被 GC 回收，
 * 页面看起来「登录状态没了」，刷新一次又好（新 session 里
 * 其实还有 remember_token 兜底）—— 表现就是站长反馈的
 * 「登录后必须手动刷新才显示登录状态」。
 *
 * 解决分两步（缺一不可）：
 *   1. 抬高 gc_maxlifetime（能设就设，设不了也继续走第 2 步）
 *   2. 自建 GC 目录 + 自己控制回收节奏：
 *      把 session.save_path 指到 data/_sessions，
 *      并把 gc_probability 设为 0 —— 这样 PHP 的 GC 完全不会动我们的文件，
 *      清理由 tools/ 下的维护脚本按「真正的过期时间」来做。
 *
 * 第 2 步是关键：只要 PHP 自己的 GC 不跑，session 文件就不会
 * 在 24 分钟时被回收，登录态自然就能一直保持。
 * =====================================================================
 */
@ini_set('session.gc_maxlifetime', SESSION_LIFETIME);
/* session cookie 的生命周期。
 *
 * 【为什么不能是 0】
 * 0 的语义是「会话 cookie」：浏览器关掉就没了。这在桌面浏览器上没问题，
 * 但 Android WebView / 系统 WebView 的 CookieManager 对会话 cookie
 * 的处理是「进程被回收就丢」—— 而安卓后台进程随时会被系统杀掉。
 *
 * 结果就是站长反馈的现象：App 登录后，切出去一会儿再回来，
 * 登录态没了，要重新登录。
 *
 * 【现在设成与会话时长一致】
 * 让 cookie 有一个明确的过期时间（和有 remember 时一样长），
 * 这样即使进程被杀、WebView 重启，cookie 仍在，
 * 服务端 session 也还在（我们上一段已经把 GC 关掉了），登录态就保住了。
 *
 * 安全上没有变弱：cookie 仍是 HttpOnly + Secure + SameSite=Lax，
 * 过期时间到了浏览器会自动丢弃，服务端 session 也按 SESSION_LIFETIME 清。
 */
@ini_set('session.cookie_lifetime', SESSION_LIFETIME);

/* 自建 session 目录，让 PHP 的 GC 摸不到它。
 * 目录不可写时静默跳过，退回系统默认行为 —— 绝不能因为这一步失败就把站点搞挂。 */
$__sessDir = __DIR__ . '/../data/_sessions';
if (!is_dir($__sessDir)) {
    @mkdir($__sessDir, 0700, true);
}
if (is_dir($__sessDir) && is_writable($__sessDir)) {
    @ini_set('session.save_path', $__sessDir);
    // gc_probability=0：PHP 永不主动清理。清理交给维护脚本，
    // 按「最后访问时间超过 SESSION_LIFETIME」来判断。
    @ini_set('session.gc_probability', '0');
    @ini_set('session.gc_divisor', '1');
}
unset($__sessDir);
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


/* 会话初始化与保活。
 *
 * 【原来的问题】
 * 只在「第一次进站」时 regenerate_id。但 PHP 的 session GC 是按概率触发的
 * （默认 1/1000 每次请求），共享主机上还可能更频繁。
 * 一旦 session 文件被回收，用户就被踢回未登录 ——
 * App 用户体感就是「用着用着突然要我重新登录」。
 *
 * 【现在的做法】
 *   1. 每个请求都刷新 session 文件的 mtime（滑动过期），
 *      只要用户在活跃使用，就永远不会被 GC 掉
 *   2. regenerate 只在真正需要时做（首次进站）
 *   3. 把 gc_maxlifetime 提到配置的会话时长，
 *      并且让 App 的长连接（心跳）也算活跃
 */
if (!isset($_SESSION['_initiated'])) {
    session_regenerate_id(true);
    $_SESSION['_initiated'] = true;
}

/* 滑动过期：每次请求都把 session 数据「踩」一下。
 * PHP 的 GC 看的是 session 文件的 mtime，写一次就能续命。
 * 这个操作很轻（只改一个标记位），但能让登录态一直活着。
 */
if (!isset($_SESSION['_touched']) || (time() - (int)$_SESSION['_touched']) > 300) {
    $_SESSION['_touched'] = time();
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
/* 自恢复守护：熔断 / 数据自愈 / 磁盘保护 / 保护模式。
 * 放在 security 之后、WAF 之前 —— 这样后面的每个请求都能用上，
 * 同时它本身不依赖 WAF（主机挂的时候 WAF 也可能挂）。 */
require_once __DIR__ . '/../includes/selfheal.php';
// 站内邮件通知服务（统一入口 + 发送记录 + 限频）
require_once __DIR__ . '/../includes/mail_service.php';

/* =====================================================================
 * 全站维护模式拦截
 * =====================================================================
 * 原来只有 index.php 判断维护模式 —— 也就是说：
 * 站长打开「维护模式」后，首页显示维护提示，
 * 但 /pages/post_detail.php?id=123 这类深链接照样能访问，
 * App 的接口也照常返回数据。等于「只关了大门，窗户全开着」。
 *
 * 现在统一在 config.php 末尾拦一次，覆盖所有入口。
 *
 * 放行清单（这些必须能访问，否则站长自己也进不去、也改不回来）：
 *   - /admin/ 整个目录
 *   - 登录 / 登出接口
 *   - 静态资源（css / js / 图片）
 *   - 维护提示页本身
 * =====================================================================
 */
if (PHP_SAPI !== 'cli' && function_exists('lwCfgBool')) {
    try {
        if (lwCfgBool('maintenance_mode', false)) {
            $__mtUri  = (string)($_SERVER['REQUEST_URI'] ?? '');
            $__mtPath = (string)(parse_url($__mtUri, PHP_URL_PATH) ?? '');

            $__mtExempt = [
                '#^/admin(/|$)#',
                '#^/api/auth/(login|logout|verify_2fa)\.php$#',
                '#^/api/admin/#',
                '#^/assets/#',
                '#^/uploads/#',
                '#^/download/#',
                '#^/pages/maintenance\.php$#',
                '#^/pages/login\.php$#',
                '#^/icon\.ico$#',
                '#^/manifest\.css$#',
                '#^/sw\.js$#',
                '#^/offline\.html$#',
            ];
            $__mtSkip = false;
            foreach ($__mtExempt as $__re) {
                if (preg_match($__re, $__mtPath)) { $__mtSkip = true; break; }
            }

            // 管理员登录态也放行
            if (!$__mtSkip && function_exists('getCurrentUser')) {
                try {
                    $__mtU = getCurrentUser();
                    if ($__mtU && in_array((string)($__mtU['role'] ?? ''), ['admin', 'super_admin'], true)) {
                        $__mtSkip = true;
                    }
                } catch (Throwable $e) {}
            }

            if (!$__mtSkip) {
                $__mtMsg = (string)lwCfg('maintenance_message', '网站正在维护中，请稍后再来。');
                $__mtIsApi = (strpos($__mtPath, '/api/') === 0);
                if ($__mtIsApi) {
                    if (!headers_sent()) {
                        http_response_code(503);
                        header('Content-Type: application/json; charset=utf-8');
                        header('Retry-After: 3600');
                    }
                    echo json_encode([
                        'success' => false,
                        'message' => $__mtMsg,
                        'maintenance' => true,
                    ], JSON_UNESCAPED_UNICODE);
                } else {
                    if (!headers_sent()) {
                        http_response_code(503);
                        header('Content-Type: text/html; charset=utf-8');
                        header('Retry-After: 3600');
                    }
                    $__mtSite = function_exists('lwCfg') ? (string)lwCfg('site_name', SITE_NAME) : SITE_NAME;
                    echo '<!DOCTYPE html><html lang="zh-CN"><head><meta charset="UTF-8">'
                       . '<meta name="viewport" content="width=device-width,initial-scale=1">'
                       . '<title>维护中 · ' . htmlspecialchars($__mtSite, ENT_QUOTES, 'UTF-8') . '</title>'
                       . '<style>body{margin:0;min-height:100vh;display:flex;align-items:center;justify-content:center;'
                       . 'background:linear-gradient(180deg,#F7F9FC,#EFF3F9);font-family:-apple-system,BlinkMacSystemFont,'
                       . '"PingFang SC","Microsoft YaHei",sans-serif;color:#1A1D23;padding:24px}'
                       . '.b{max-width:420px;text-align:center;background:rgba(255,255,255,.85);border:1px solid rgba(15,23,42,.07);'
                       . 'border-radius:20px;padding:36px 28px;box-shadow:0 8px 30px -12px rgba(16,24,40,.12)}'
                       . 'h1{font-size:19px;margin:0 0 12px}p{font-size:14px;line-height:1.8;color:#5A6472;margin:0;white-space:pre-wrap}'
                       . '</style></head><body><div class="b"><h1>网站维护中</h1><p>'
                       . htmlspecialchars($__mtMsg, ENT_QUOTES, 'UTF-8')
                       . '</p></div></body></html>';
                }
                exit;
            }
        }
    } catch (Throwable $e) {
        // 维护判断出错绝不能把全站搞挂
    }
}
// 站长账号的展示值覆盖（粉丝数 / 等级 / 积分）
require_once __DIR__ . '/../includes/owner_overlay.php';
require_once __DIR__ . '/../includes/waf.php';
require_once __DIR__ . '/../includes/i18n.php';
// 图标入口（lw_icon）：全站页面都要用，放在引导层统一加载，避免各页面漏 require 触发
// 「Call to undefined function lw_icon()」白屏 —— includes/ 下是函数，autoloader 只管类。
require_once __DIR__ . '/../includes/icons.php';

/* 【CLI 下跳过 WAF / 反爬 / 请求体检查】
 *
 * 这些中间件全都依赖 HTTP 上下文（REQUEST_URI、UA、IP、CONTENT_TYPE）。
 * 命令行里它们全是空的，于是：
 *   - WAF 看到空 URI 当成可疑路径 → 403
 *   - 反爬看到空 UA → 拦截
 * 结果 tools/ 下的维护脚本（清理、迁移、诊断）一个都跑不起来。
 *
 * CLI 不是攻击面（攻击者无法在服务器上执行 php 命令），
 * 所以直接跳过这一段；常量、函数、存储层的加载不受影响。
 */
if (PHP_SAPI !== 'cli') {

/* 后台「安全 → 启用 WAF」关掉后跳过整个防护层。
 *
 * 只跳过 WAF 与反爬，下面的 CSRF / body 校验仍然生效 ——
 * 那些人手写代码的防线不该因为关掉一个开关就消失。
 * 关它通常只有一个理由：排查「正常请求被误拦」，
 * 排查完一定要打开。
 */
$__wafOn = true;
if (function_exists('lwCfgBool')) {
    try { $__wafOn = lwCfgBool('waf_enabled', true); } catch (Throwable $e) { $__wafOn = true; }
}

if ($__wafOn) {
    WAF::init();
}

$requestUri = $_SERVER['REQUEST_URI'] ?? '';
$isApiRequest = strpos($requestUri, '/api/') !== false;
$isStaticAsset = preg_match('/\.(css|js|png|jpg|jpeg|gif|svg|ico|woff2?|ttf|eot)$/i', $requestUri);
if ($__wafOn && !$isApiRequest && !$isStaticAsset) {
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

}  // end if (PHP_SAPI !== 'cli')

// SSE（流式）端点必须在 require 本文件之前 define('LW_NO_GZIP', 1)：
// gzip 会把整段响应攒够一块才发，流式就退化成「一次性返回」，思考过程也就没法实时同步了。
if (!defined('LW_NO_GZIP') && !ini_get('zlib.output_compression') && function_exists('ob_gzhandler')) {
    // 清空 PHP 默认 output_buffering 可能残留的空缓冲区，确保 gzip 压缩真正生效
    while (ob_get_level() > 0 && ob_get_length() === 0) {
        ob_end_clean();
    }
    ob_start('ob_gzhandler');
}