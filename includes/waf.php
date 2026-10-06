<?php

class WAF {
    private static $blacklistIPs = [];
    private static $rateLimitData = [];
    private static $rateLimitFile = null;
    private static $initialized = false;
    private static $lastSavedJson = null;

    public static function init() {
        if (self::$initialized) return;
        self::$initialized = true;

        self::$rateLimitFile = __DIR__ . '/../data/waf_rate_limits.json';

        // 先注册落盘钩子：后面的检查随时可能 die/exit，钩子必须在最前面就位
        register_shutdown_function([self::class, 'saveRateLimitData']);

        self::loadBlacklist();

        // 已被封禁的 IP 立即拒绝，不再执行后续检查。
        // 否则被封的爬虫/攻击者每个请求仍会跑完整 WAF 并反复整文件重写 waf_logs，
        // 在共享主机上堆积磁盘 IO 与 PHP 进程，是偶发全站 502 的主要诱因。
        // 例外：已登录管理员放行 —— 否则一旦被自动封禁，连后台解封页都进不去（死锁）。
        if (self::isBlacklisted(self::getClientIP()) && !self::isTrustedSession()) {
            self::rejectBanned();
        }

        self::setSecurityHeaders();

        self::cleanup();

        self::validateMethod();

        self::validateRequestSize();

        self::validateUserAgent();

        self::detectBot();

        self::generateFingerprint();

        self::validateReferer();

        self::blockMaliciousRequests();

        self::checkRateLimit();

        self::applyDDOSProtection();

        self::detectIPRotation();

        self::filterInput();
    }

    /** 取某 IP 当前生效的黑名单条目（没有则返回空数组），供封禁页展示原因与解封时间 */
    public static function getBanEntry($ip) {
        if (isset(self::$blacklistIPs[$ip])) {
            $entry = self::$blacklistIPs[$ip];
            if ((int)($entry['expire_at'] ?? 0) === 0 || (int)$entry['expire_at'] > time()) {
                return $entry;
            }
        }
        return [];
    }

    /**
     * 拒绝已被封禁 IP 的请求。
     *   接口（/api/）→ JSON，前端可弹提示；
     *   页面          → 单独的封禁页（含原因、受限 IP、精确解封时间、站长 QQ）。
     */
    private static function rejectBanned() {
        $ip = self::getClientIP();
        $entry = self::getBanEntry($ip);
        $uri = $_SERVER['REQUEST_URI'] ?? '';
        $expireAt = (int)($entry['expire_at'] ?? 0);
        $remain = $expireAt > 0 ? max(0, $expireAt - time()) : 0;

        if (strpos($uri, '/api/') !== false) {
            require_once __DIR__ . '/ban_page.php';
            http_response_code(429);
            header('Content-Type: application/json; charset=utf-8');
            if (!headers_sent() && $remain > 0) {
                header('Retry-After: ' . $remain);
            }
            $code  = lwBanReasonCode((string)($entry['reason'] ?? ''));
            $label = lwBanReasonLabel((string)($entry['reason'] ?? ''));
            $refId = lwBanRefId($ip, $expireAt);
            die(json_encode([
                'success'     => false,
                'message'     => '访问被临时拦截（' . $code . ' · ' . $label . '）。'
                    . ($remain > 0
                        ? '预计 ' . date('Y-m-d H:i:s', $expireAt) . ' 自动恢复。'
                        : '该限制需站长人工解除。')
                    . '如为误判请联系站长 QQ ' . LW_BAN_CONTACT_QQ . '（事件编号 ' . $refId . '）',
                'banned'      => true,
                'reason'      => (string)($entry['reason'] ?? ''),
                'strategy'    => $code,
                'unban_at'    => $expireAt,
                'retry_after' => $remain,
                'ref_id'      => $refId,
                'contact_qq'  => LW_BAN_CONTACT_QQ,
            ], JSON_UNESCAPED_UNICODE));
        }

        require_once __DIR__ . '/ban_page.php';
        lwRenderBanPage($entry, $ip);
    }

    private static function loadBlacklist() {
        $file = __DIR__ . '/../data/ip_blacklist.json';
        if (file_exists($file)) {
            $data = json_decode(file_get_contents($file), true);
            if (is_array($data)) {
                foreach ($data as $entry) {
                    if (!empty($entry['ip'])) {
                        $expireAt = $entry['expire_at'] ?? 0;
                        if ($expireAt > 0 && $expireAt < time()) continue;
                        self::$blacklistIPs[$entry['ip']] = $entry;
                    }
                }
            }
        }
    }

    public static function isBlacklisted($ip) {
        if (isset(self::$blacklistIPs[$ip])) {
            $entry = self::$blacklistIPs[$ip];
            $expireAt = $entry['expire_at'] ?? 0;
            if ($expireAt > 0 && $expireAt < time()) {
                unset(self::$blacklistIPs[$ip]);
                return false;
            }
            return true;
        }
        return false;
    }

    /**
     * 本次请求是否来自「已登录的管理员会话」。
     *
     * 用途：所有自动封禁的阈值都是**行为量启发式**（翻页数量、接口频率、出口 IP 变化、
     * 会话指纹抖动）。这些启发式对「站长自己连续点几十下」几乎必然误判，而一旦被封
     * 就是 10~30 分钟，更糟的是连后台的「IP 黑名单管理」都进不去，只能靠 FTP 改文件解封。
     * 所以：登录态的管理员永不自动封禁、也不被已有黑名单拦截。
     * 登录态本身已经过密码（部分账号还有 TOTP）校验，放开这一层不会给爬虫开口子 ——
     * 真正要防的爬虫/扫描器都是**未登录**的。
     */
    public static function isTrustedSession(): bool {
        if (empty($_SESSION['user_id'])) {
            return false;
        }
        $role = (string)($_SESSION['user_role'] ?? '');
        return $role === 'admin' || $role === 'super_admin';
    }

    public static function banIP($ip, $reason = '', $duration = 600) {
        // 已登录管理员：只记日志，不真封（详见 isTrustedSession 的说明）
        if (self::isTrustedSession()) {
            error_log('WAF: 跳过对已登录管理员的自动封禁 reason=' . $reason . ' ip=' . $ip);
            return false;
        }

        // 本次是否「从正常状态变成封禁状态」：只有这种"新封禁"才发通知邮件，
        // 避免同一 IP 被反复判定时把用户邮箱轰炸一遍。
        $wasBanned = false;
        if (isset(self::$blacklistIPs[$ip])) {
            $prevExpire = (int)(self::$blacklistIPs[$ip]['expire_at'] ?? 0);
            $wasBanned = ($prevExpire === 0 || $prevExpire > time());
        }

        self::$blacklistIPs[$ip] = [
            'ip' => $ip,
            'reason' => $reason,
            'expire_at' => time() + $duration,
            'block_count' => (self::$blacklistIPs[$ip]['block_count'] ?? 0) + 1,
            'created_at' => time()
        ];

        $fs = getFS();
        try {
            $blacklist = $fs->read('ip_blacklist');
            if (!is_array($blacklist)) $blacklist = [];
            $found = false;
            foreach ($blacklist as $i => $item) {
                if (($item['ip'] ?? '') === $ip) {
                    $blacklist[$i] = self::$blacklistIPs[$ip];
                    $found = true;
                    break;
                }
            }
            if (!$found) {
                $blacklist[] = self::$blacklistIPs[$ip];
            }
            $now = time();
            $blacklist = array_values(array_filter($blacklist, function($e) use ($now) {
                return ($e['expire_at'] ?? 0) > $now;
            }));
            if (count($blacklist) > 200) {
                $blacklist = array_slice($blacklist, -200);
            }
            $fs->write('ip_blacklist', $blacklist);

            // 新封禁 → 邮件告知当前登录用户（匿名访客没有邮箱，见方法内注释）
            if (!$wasBanned) {
                self::notifyBannedUser($ip, (string)$reason, (int)self::$blacklistIPs[$ip]['expire_at']);
            }
        } catch (Exception $e) {
            error_log('WAF banIP failed: ' . $e->getMessage());
        }
    }

    /**
     * 封禁后用 SMTP 给「当前登录用户」发一封告知邮件。
     *
     * 收件地址按本站既有约定推导：`QQ号@qq.com`（users 表没有 email 字段，
     * 找回密码 / 备份下载验证码都用的这个约定，见 api/auth/forgot_password.php）。
     *
     * 只发给登录态用户：
     *   - 匿名访客（绝大多数自动封禁的对象是采集器/扫描器）根本没有邮箱可发；
     *   - 也避免给真正的攻击者回信暴露站点行为。
     * 全程 try/catch：发信失败绝不影响封禁本身，也不让请求挂住。
     */
    private static function notifyBannedUser($ip, $reason, $expireAt) {
        try {
            $uid = (int)($_SESSION['user_id'] ?? 0);
            if ($uid <= 0) {
                return;
            }
            if (!class_exists('QQMailer') || !method_exists('QQMailer', 'isConfigured')) {
                return;
            }
            if (!QQMailer::isConfigured()) {
                return;
            }

            $fs = getFS();
            $user = $fs->findById('users', $uid);
            if (!$user) {
                return;
            }
            $qq = preg_replace('/\D/', '', (string)($user['qq'] ?? ''));
            if ($qq === '') {
                return;
            }
            $to = $qq . '@qq.com';

            require_once __DIR__ . '/ban_page.php';

            $siteName  = defined('SITE_NAME') ? SITE_NAME : '校园交流墙';
            $nick      = (string)($user['nickname'] ?? '同学');
            $remainTxt = ((int)$expireAt <= 0)
                ? '不确定'
                : lwBanFormatDuration(max(0, (int)$expireAt - time()));

            $html = self::banEmailHtml((string)$ip, (string)$reason, (int)$expireAt, $nick);

            $sent = QQMailer::send($to, '【' . $siteName . '】访问已被临时限制（' . $remainTxt . '后自动恢复）', $html);
            // 记一行结果便于排查（SMTP 不通时站长能从日志一眼看出来，而不是「用户说没收到」）
            error_log('WAF: 封禁通知邮件 ' . ($sent ? '已发送' : '发送失败') . ' to=' . $to . ' ip=' . $ip . ' reason=' . $reason);
        } catch (\Throwable $e) {
            error_log('WAF notifyBannedUser failed: ' . $e->getMessage());
        }
    }

    /**
     * 构造封禁通知邮件的 HTML 正文。
     *
     * 内容全部来自统一模板库 `includes/mail_templates.php` 的 `ban_notice`，
     * 后台「测试邮件 → 访问受限通知」发的就是同一份 —— 改模板即可同时生效。
     * 保留本方法只是为了不打断既有调用方与探针。
     */
    public static function banEmailHtml($ip, $reason, $expireAt, $nick = '同学') {
        // 委托给统一模板库（includes/mail_templates.php）：
        // 邮件内容与后台「测试邮件」里选「访问受限通知」发出的那份完全一致，
        // 不再在这里单独维护一份 HTML（否则改一处漏一处）。
        require_once __DIR__ . '/mail_templates.php';
        $mail = lwMailBuild('ban_notice', [
            'ip'        => $ip,
            'reason'    => $reason,
            'expire_at' => $expireAt,
            'nick'      => $nick,
        ]);
        return $mail['html'];
    }

    private static function validateMethod() {
        $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
        $allowed = ['GET', 'POST', 'HEAD', 'OPTIONS'];
        $blocked = ['TRACE', 'TRACK', 'DEBUG', 'PUT', 'DELETE', 'PATCH', 'CONNECT'];

        if (in_array($method, $blocked)) {
            self::logAndBlock('blocked_method', $method);
        }

        if (!in_array($method, $allowed)) {
            self::logAndBlock('unknown_method', $method);
        }
    }

    private static function validateRequestSize() {
        $uri = $_SERVER['REQUEST_URI'] ?? '';
        if (mb_strlen($uri) > 2048) {
            self::logAndBlock('url_too_long', mb_strlen($uri) . ' chars');
        }

        $contentLength = (int)($_SERVER['CONTENT_LENGTH'] ?? 0);
        if ($contentLength > 2 * 1024 * 1024) {
            self::logAndBlock('post_too_large', round($contentLength / 1024, 1) . 'KB');
        }
    }

    private static function validateUserAgent() {
        $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';

        if (empty($ua)) {
            self::logAndBlock('empty_user_agent');
        }

        if (mb_strlen($ua) > 512) {
            self::logAndBlock('ua_too_long');
        }

        $maliciousPatterns = [
            '/sqlmap/i', '/acunetix/i', '/nessus/i', '/nikto/i',
            '/nmap/i', '/masscan/i', '/zgrab/i', '/gobuster/i',
            '/dirbuster/i', '/wpscan/i', '/joomscan/i', '/whatweb/i',
            '/openvas/i', '/webscan/i', '/netsparker/i', '/appscan/i',
            '/burpsuite/i', '/owasp/i', '/zap/i', '/vega/i',
            '/scrapy/i', '/phantomjs/i', '/headless/i',
            '/python-requests/i', '/python-urllib/i',
            '/libwww-perl/i',
            '/censys/i', '/shodan/i',
        ];
        foreach ($maliciousPatterns as $pattern) {
            if (preg_match($pattern, $ua)) {
                self::logAndBlock('malicious_ua', substr($ua, 0, 100));
            }
        }
    }

    private static function validateReferer() {
        $uri = $_SERVER['REQUEST_URI'] ?? '';
        $isApi = strpos($uri, '/api/') !== false;
        $isAdmin = strpos($uri, '/admin/') !== false;
        $isSensitive = $isApi || $isAdmin;

        if (!$isSensitive) return;

        $referer = $_SERVER['HTTP_REFERER'] ?? '';
        $host = $_SERVER['HTTP_HOST'] ?? '';

        // POST 请求缺少 Referer 时，仅当请求不是带 CSRF 保护的同站操作时才拦截。
        // 发帖/投票等前端均通过 CSRF token 防护，靠 Referer 判断会误伤正常用户（
        // 例如 referrer 策略裁剪或跨页面跳转后直接发帖），故此处放宽为：
        // 非 XHR 且无 CSRF token 才拦截，真正同站 XHR 请求交由 CSRF 校验兜底。
        if (empty($referer) && $_SERVER['REQUEST_METHOD'] === 'POST') {
            $isXhr = (($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest');
            $hasCsrf = !empty($_POST['csrf_token']);
            if (!$isXhr && !$hasCsrf) {
                self::logAndBlock('empty_referer_post', $uri);
            }
        }

        if (!empty($referer) && !empty($host)) {
            // 注意：HTTP_HOST 含端口（如 127.0.0.1:8011），而 parse_url 的 HOST 不含端口，
            // 直接比较会把同源（同域名同端口）请求误判为跨源。此处统一提取 host（去端口）再比较。
            $refererHost = parse_url($referer, PHP_URL_HOST);
            $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
            $hostOnly = parse_url($scheme . '://' . $host, PHP_URL_HOST);
            if ($refererHost && $hostOnly && $refererHost !== $hostOnly) {
                if ($isAdmin) {
                    self::logAndBlock('cross_origin_referer_admin', $referer);
                }
            }
        }
    }

    private static function blockMaliciousRequests() {
        $uri = $_SERVER['REQUEST_URI'] ?? '';
        $queryString = $_SERVER['QUERY_STRING'] ?? '';

        if (preg_match('/\.\.(\/|\\\)/', $uri) || strpos($uri, "\0") !== false) {
            self::logAndBlock('path_traversal', $uri);
        }

        $sensitivePatterns = [
            '/\.env/i', '/\.git\//i', '/\.svn\//i', '/\.hg\//i',
            '/\.DS_Store/i', '/\.htaccess/i', '/\.htpasswd/i',
            '/wp-admin/i', '/wp-login/i', '/wp-content/i', '/wp-includes/i',
            '/phpmyadmin/i', '/pma/i', '/mysql/i', '/adminer/i',
            // 注意：这三个必须要求「config.php 前面是 / 或开头」，不能只写 '/config\.php/i'。
            // 裸写会把任何**名字里带 config.php** 的正常接口一起拦掉
            // （实测 /api/user/ai_config.php 直接被 403，功能静默失效）。
            // 加边界后既能挡住探测 /config.php、/admin/config.php，又不误伤 *_config.php 命名的接口。
            '/(\/|^)config\.php/i', '/(\/|^)database\.php/i', '/(\/|^)wp-config\.php/i',
            '/\.sql$/i', '/\.bak$/i', '/\.backup$/i', '/\.old$/i',
            '/\.save$/i', '/\.swp$/i', '/~$/i',
            '/xmlrpc\.php/i', '/wlwmanifest\.xml/i',
            '/actuator/i', '/swagger/i', '/api-docs/i',
            '/druid/i', '/console/i', '/manager/i',
            '/\.json$/i', '/\.yml$/i', '/\.yaml$/i',
        ];
        foreach ($sensitivePatterns as $pattern) {
            if (preg_match($pattern, $uri)) {
                self::logAndBlock('sensitive_file_probe', $uri);
            }
        }

        // ⚠️ 误伤警告（已踩过，改动前请读完）：
        // 本站 SQL 注入面为零（无数据库，全部走 JSON 文件 + 服务端强类型校验），
        // 而这些规则只跑在 **URI + query string** 上，不碰 POST body。
        // 过宽的规则会把正常中文/英文内容挡在门外，实测：
        //   /pages/u.php?nick=O'Brien      → 403（英文名里的撇号，学生搜同学直接失败）
        //   ?tag=what if (...)             → 403（英文 "if(" 出现在话题名里）
        // 所以下面这几条**故意从「裸字符」收窄为「只在成对/典型注入形态下才命中」**：
        // 单独一个 ' 或 # 不再拦截（它们由 XSS 规则与输出转义兜底），
        // 但 ' or、' and、-- 注释、# 注入这类典型形态仍然拦。
        $sqlPatterns = [
            '/\'\s*(or|and)\s+/i',
            '/\'\s*=\s*\'\s*or/i',
            '/\d\s*(or|and)\s+\d\s*=\s*\d/i',
            '/(\%27|\')\s*(or|and)\s+(\d|%27|\')/i',
            // URL 编码形态：' → %27，空格 → %20/%09/+，所以中间的 \s* 匹配不到，要单独放宽
            '/\%27[\s%0-9a-f+]{0,8}(or|and)[\s%0-9a-f+]{0,8}(=|%3d|\d)/i',
            '/\-\-\s/i',
            '/\b(unhex|char)\s*\(\s*(0x[0-9a-f]+|\d+\s*,)/i',
            '/\b(union\s+select)\b/i',
            '/\b(union\s+all\s+select)\b/i',
            '/\b(insert\s+into)\b/i',
            '/\b(drop\s+table)\b/i',
            '/\b(drop\s+database)\b/i',
            '/\b(alter\s+table)\b/i',
            '/\b(delete\s+from)\b/i',
            '/\b(update\s+.*\s+set)\b/i',
            '/\b(truncate\s+table)\b/i',
            '/\b(exec\s*\()/i',
            '/\b(execute\s*\()/i',
            '/\b(sleep\s*\()/i',
            '/\b(benchmark\s*\()/i',
            '/\b(waitfor\s+delay)\b/i',
            '/\b(load_file\s*\()/i',
            '/\b(outfile)\b/i',
            '/\b(into\s+outfile)\b/i',
            '/\b(into\s+dumpfile)\b/i',
            '/\b(information_schema)\b/i',
            '/\b(group_concat\s*\()/i',
            '/\b(concat|substring|mid|ascii|ord|hex|unhex|bin|cast|convert|if)\s*\([^)]{0,200}\b(from|select|where|union|information_schema|0x[0-9a-f]{4,})\b/i',
            '/\b(case\s+when)\b/i',
            '/\/\*.*\*\//i',
            '/;\s*(select|insert|update|delete|drop|alter|create|truncate)/i',
            // 以下几条精度高、几乎不误伤正常内容，用来补上收窄裸字符规则后留下的空洞。
            // 注意 `select ... from` 的 `.` 是「任意一个字符」，写成 `.+` 会过度匹配中文长句。
            '/\bselect\s+\S+\s+from\b/i',
            '/\band\s*\(\s*select\b/i',
            '/\bor\s*\(\s*select\b/i',
            '/\b(xp_cmdshell|sp_executesql|sp_configure)\b/i',
            '/\bselect\s+.*\s+into\s+(out|dump)file\b/i',
        ];

        $checkString = $uri . ' ' . $queryString;
        foreach ($sqlPatterns as $pattern) {
            if (preg_match($pattern, $checkString)) {
                self::logAndBlock('sql_injection_attempt', substr($checkString, 0, 200));
            }
        }

        $xssPatterns = [
            '/<script[^>]*>/i',
            '/<iframe[^>]*>/i',
            '/<object[^>]*>/i',
            '/<embed[^>]*>/i',
            '/<link[^>]*>/i',
            '/<meta[^>]*>/i',
            '/<style[^>]*>/i',
            '/<applet[^>]*>/i',
            '/<base[^>]*>/i',
            '/<form[^>]*>/i',
            '/<img[^>]+onerror/i',
            '/<img[^>]+onload/i',
            '/<svg[^>]+onload/i',
            '/<body[^>]+onload/i',
            '/<input[^>]+onfocus/i',
            '/javascript\s*:/i',
            '/vbscript\s*:/i',
            '/data\s*:\s*text\/html/i',
            '/on\w+\s*=/i',
            '/expression\s*\(/i',
            '/eval\s*\(/i',
            '/alert\s*\(/i',
            '/prompt\s*\(/i',
            '/confirm\s*\(/i',
            '/document\.cookie/i',
            '/document\.write/i',
            '/window\.location/i',
            '/fromCharCode/i',
        ];

        foreach ($xssPatterns as $pattern) {
            if (preg_match($pattern, $checkString)) {
                self::logAndBlock('xss_attempt', substr($checkString, 0, 200));
            }
        }

        $cmdPatterns = [
            '/\b(eval|exec|system|passthru|shell_exec|popen|proc_open|pcntl_exec)\s*\(/i',
            '/\b(assert|create_function|call_user_func|array_map|array_filter|preg_replace)\s*\(/i',
            '/(\||;|&&|`)\s*(cat|ls|pwd|id|whoami|uname|wget|curl|nc|ncat|bash|sh|python|perl|ruby|php|gcc|make)/i',
            '/\/bin\//i',
            '/\/etc\/passwd/i',
            '/\/etc\/shadow/i',
            '/\\x00/i',
            '/\%00/i',
        ];

        foreach ($cmdPatterns as $pattern) {
            if (preg_match($pattern, $checkString)) {
                self::logAndBlock('command_injection_attempt', substr($checkString, 0, 200));
            }
        }

        $scannerPatterns = [
            '/\b(acunetix|appscan|burpsuite|netsparker|nessus|nikto|nmap|openvas|qualys|rapid7|tenable|veracode|webinspect|zap)\b/i',
            '/\b(sqlmap|havij|pangolin|safe3|sql inject|blind sql)\b/i',
            '/\b(xss|csrf|ssrf|lfi|rfi|rce|idor|ssti|xxe)\b/i',
        ];

        foreach ($scannerPatterns as $pattern) {
            if (preg_match($pattern, $checkString)) {
                $ip = self::getClientIP();
                self::banIP($ip, 'scanner_detected', 3600);
                self::logAndBlock('scanner_detected', $checkString);
            }
        }
    }

    /**
     * 限流数据的实际落盘文件。
     * 主文件在个别环境下会变成「损坏且无法读取」的目录项或被设成只读，此时 file_put_contents 会静默失败，
     * 限流计数就永远存不下来（每个请求都从零开始）。一旦备用文件出现就固定用它，保证计数连续。
     */
    private static function rateLimitDataFile() {
        $alt = dirname(self::$rateLimitFile) . '/waf_rate_limits.alt.json';
        if (is_file($alt)) {
            // 备用文件被写坏（0 字节 / 内容非法 / 磁盘损坏）时立即弃用并回退主文件。
            // 否则限流计数永远从零开始：既拦不住攻击，也会让软限流判断失真。
            $size = @filesize($alt);
            if ($size !== false && $size > 0) {
                $raw = @file_get_contents($alt);
                if ($raw !== false && $raw !== '' && json_decode($raw, true) !== null) {
                    return $alt;
                }
            }
            @unlink($alt);
            @unlink($alt . '.lock');
        }
        return self::$rateLimitFile;
    }

    /**
     * 读取限流数据（同一请求内只读一次）。
     */
    private static function loadRateLimitData() {
        if (self::$rateLimitData) return;
        $raw = @file_get_contents(self::rateLimitDataFile());
        // 记下磁盘上的原始串：请求结束时若内容一字未变（例如本次只是取静态资源、
        // 计数没有增加），就直接跳过写盘。否则每个请求都要整文件重写一遍，
        // 在共享主机上是纯粹的磁盘 IO 浪费。
        self::$lastSavedJson = ($raw === false || $raw === '') ? null : $raw;
        if ($raw !== false && $raw !== '') {
            $data = json_decode($raw, true);
            if (is_array($data)) {
                self::$rateLimitData = $data;
            }
        }
    }

    /**
     * 软限流：只暂时拒绝当前请求（429），不拉黑 IP。
     * 运营商 NAT / 校园网下大量真实用户共享同一出口 IP，
     * 直接封禁会把整片用户一起挡住（显示 Access Denied）。
     */
    private static function softLimit($isApi = null) {
        $uri = $_SERVER['REQUEST_URI'] ?? '';
        if ($isApi === null) {
            $isApi = strpos($uri, '/api/') !== false;
        }
        http_response_code(429);
        header('Retry-After: 60');
        if ($isApi) {
            header('Content-Type: application/json; charset=utf-8');
            die(json_encode(['success' => false, 'message' => '请求过于频繁，请稍后再试'], JSON_UNESCAPED_UNICODE));
        }
        header('Content-Type: text/html; charset=utf-8');
        die('请求过于频繁，请稍后刷新重试');
    }

    private static function checkRateLimit() {
        $ip = self::getClientIP();
        $uri = $_SERVER['REQUEST_URI'] ?? '/';
        $now = time();

        self::loadRateLimitData();

        foreach (self::$rateLimitData as $key => $entry) {
            if (($entry['reset_at'] ?? 0) < $now) {
                unset(self::$rateLimitData[$key]);
            }
        }

        $ipKey = 'ip_' . $ip;
        $ipEntry = self::$rateLimitData[$ipKey] ?? ['count' => 0, 'reset_at' => $now + 60, 'first_seen' => $now];
        if ($ipEntry['reset_at'] < $now) {
            $ipEntry = ['count' => 0, 'reset_at' => $now + 60, 'first_seen' => $now];
        }
        // 静态资源不计入全站限流：单次页面加载会带出十几个 css/js/图片请求，
        // 校园网 / 运营商 NAT 的共享出口 IP 正常访问也会被算爆。
        if (!preg_match('/\.(css|js|png|jpg|jpeg|gif|svg|ico|webp|woff2?|ttf|eot)$/i', $uri)) {
            $ipEntry['count']++;
            self::$rateLimitData[$ipKey] = $ipEntry;

            // 普通超限只软拒绝（429），只有极端洪泛（1500 次/分钟）才短封，
            // 避免共享出口 IP 的正常访问被整体拉黑。
            if ($ipEntry['count'] > 3000) {
                self::banIP($ip, 'rate_limit_flood:' . $ipEntry['count'], 300);
                self::logAndBlock('rate_limit_exceeded', $ip . ' ' . $ipEntry['count'] . ' req/min');
            } elseif ($ipEntry['count'] > 800) {
                self::softLimit(false);
            }
        }

        $isApi = strpos($uri, '/api/') !== false;
        if ($isApi) {
            $apiKey = 'api_' . $ip;
            $apiEntry = self::$rateLimitData[$apiKey] ?? ['count' => 0, 'reset_at' => $now + 60];
            if ($apiEntry['reset_at'] < $now) {
                $apiEntry = ['count' => 0, 'reset_at' => $now + 60];
            }
            $apiEntry['count']++;
            self::$rateLimitData[$apiKey] = $apiEntry;

            // 单个页面加载往往就要发出数个接口请求，阈值需要给共享 IP 留出余量：
            // 240 次/分钟开始软拒绝，1200 次/分钟（明显是刷接口）才短封。
            if ($apiEntry['count'] > 2500) {
                self::banIP($ip, 'api_rate_limit_flood', 300);
                self::softLimit(true);
            } elseif ($apiEntry['count'] > 500) {
                self::softLimit(true);
            }
        }

        if ($isApi) {
            $traverseKey = 'traverse_' . $ip;
            $traverseEntry = self::$rateLimitData[$traverseKey] ?? ['ids' => [], 'reset_at' => $now + 30];
            if ($traverseEntry['reset_at'] < $now) {
                $traverseEntry = ['ids' => [], 'reset_at' => $now + 30];
            }
            if (preg_match('/[?&]id=(\d+)/', $uri, $m)) {
                $traverseEntry['ids'][$m[1]] = true;
            }
            self::$rateLimitData[$traverseKey] = $traverseEntry;

            // 30 秒内翻看 300 个不同 ID 才判定为遍历扫描
            // （历史：50 → 200 → 300，前两档都太容易误伤正常浏览/分页翻页）
            if (count($traverseEntry['ids']) > 300) {
                self::banIP($ip, 'traversal_scan', 600);
                self::logAndBlock('traversal_scan', $ip . ' scanned ' . count($traverseEntry['ids']) . ' IDs');
            }
        }

        if (count(self::$rateLimitData) > 1000) {
            self::saveRateLimitData();
        }
    }

    public static function saveRateLimitData() {
        // 本次请求没有加载/写过限流数据时直接跳过：
        // 否则 json_encode([]) 会把文件里已有的计数清空，让限流计数「归零失效」。
        if (empty(self::$rateLimitData)) return;

        if (count(self::$rateLimitData) > 1000) {
            $now = time();
            self::$rateLimitData = array_filter(self::$rateLimitData, function($e) use ($now) {
                return ($e['reset_at'] ?? 0) > $now;
            });
            if (count(self::$rateLimitData) > 1000) {
                self::$rateLimitData = array_slice(self::$rateLimitData, -1000, 1000, true);
            }
        }
        $json = json_encode(self::$rateLimitData);
        if ($json === self::$lastSavedJson) return;
        $target = self::rateLimitDataFile();
        if (@file_put_contents($target, $json, LOCK_EX) === false && $target === self::$rateLimitFile) {
            // 主文件写不进去（损坏条目 / 只读 / 被占用）时改用备用文件，下一次请求会自动读它
            @file_put_contents(dirname(self::$rateLimitFile) . '/waf_rate_limits.alt.json', $json, LOCK_EX);
        }
        self::$lastSavedJson = $json;
    }

    private static function filterInput() {
        if (!empty($_GET)) {
            $_GET = self::recursiveFilter($_GET);
        }

        if (!empty($_POST)) {
            $_POST = self::recursiveFilter($_POST);
        }

        if (!empty($_REQUEST)) {
            $_REQUEST = self::recursiveFilter($_REQUEST);
        }
    }

    private static function recursiveFilter($data) {
        if (is_array($data)) {
            return array_map([self::class, 'recursiveFilter'], $data);
        }
        if (is_string($data)) {
            $data = str_replace("\0", '', $data);
            if (mb_strlen($data) > 50000) {
                $data = mb_substr($data, 0, 50000);
            }
            $data = self::stripMaliciousContent($data);
        }
        return $data;
    }

    private static function stripMaliciousContent($str) {
        $patterns = [
            '/<script[\s\S]*?<\/script>/i',
            '/<iframe[\s\S]*?<\/iframe>/i',
            '/<object[\s\S]*?<\/object>/i',
            '/<embed[\s\S]*?>/i',
            '/<applet[\s\S]*?<\/applet>/i',
            '/<meta[\s\S]*?>/i',
            '/<link[\s\S]*?>/i',
            '/javascript\s*:[\s\S]*?$/im',
            '/vbscript\s*:/i',
            '/on\w+\s*=\s*["\'][\s\S]*?["\']/i',
            '/on\w+\s*=\s*\S+/i',
        ];
        $str = preg_replace($patterns, '[blocked]', $str);

        return $str;
    }

    public static function getClientIP() {
        $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            return $ip;
        }
        if (filter_var($ip, FILTER_VALIDATE_IP)) {
            return $ip;
        }
        return '0.0.0.0';
    }

    private static function logAndBlock($type, $detail = '') {
        $ip = self::getClientIP();
        $uri = $_SERVER['REQUEST_URI'] ?? '/';
        $ua = mb_substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 200);
        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

        try {
            $fs = getFS();
            // 日志封顶：waf_logs 曾涨到 180KB+，每次拦截都要整文件重写，
            // 在共享主机上堆积 IO（偶发 502 的诱因），这里只保留最近 300 条。
            $fs->insertCapped('waf_logs', [
                'ip' => $ip,
                'type' => $type,
                'detail' => mb_substr($detail, 0, 500),
                'uri' => mb_substr($uri, 0, 500),
                'ua' => $ua,
                'method' => $method,
                'time' => date('Y-m-d H:i:s')
            ], 300);
        } catch (Exception $e) {
            error_log('WAF log failed: ' . $e->getMessage());
        }

        self::noteViolation($ip, $type);

        http_response_code(403);
        die('403 Forbidden');
    }

    /**
     * 跨请求累计违规次数：原实现只按 session 计数，无 Cookie 的爬虫每次都是新会话，
     * 永远只算 1 次、封不掉，于是它每个请求都写一次日志，持续放大磁盘 IO。
     * 改为按 IP 在 10 分钟窗口内累计，达到 6 次即短封。
     */
    private static function noteViolation($ip, $type) {
        self::loadRateLimitData();
        $now = time();
        $key = 'viol_' . $ip;
        $entry = self::$rateLimitData[$key] ?? null;
        if (!is_array($entry) || ($entry['reset_at'] ?? 0) < $now) {
            $entry = ['count' => 0, 'reset_at' => $now + 600];
        }
        $entry['count']++;
        self::$rateLimitData[$key] = $entry;
        self::saveRateLimitData();

        if ($entry['count'] >= 10) {
            self::banIP($ip, 'repeated_' . $type, 900);
        }
    }

    public static function generateNonce($action = '') {
        $nonce = bin2hex(random_bytes(16));
        $key = 'waf_nonce_' . $action;
        if (!isset($_SESSION[$key])) {
            $_SESSION[$key] = [];
        }
        $_SESSION[$key][$nonce] = time() + 300;
        $_SESSION[$key] = array_filter($_SESSION[$key], function($t) { return $t > time(); });
        if (count($_SESSION[$key]) > 50) {
            $_SESSION[$key] = array_slice($_SESSION[$key], -50, 50, true);
        }
        return $nonce;
    }

    public static function verifyNonce($nonce, $action = '') {
        $key = 'waf_nonce_' . $action;
        if (empty($_SESSION[$key]) || empty($nonce)) {
            return false;
        }
        if (isset($_SESSION[$key][$nonce])) {
            if ($_SESSION[$key][$nonce] > time()) {
                unset($_SESSION[$key][$nonce]);
                return true;
            }
            unset($_SESSION[$key][$nonce]);
        }
        return false;
    }

    public static function sanitizeID($value) {
        return (int)$value;
    }

    public static function sanitizeAlpha($value, $maxLen = 50) {
        $value = preg_replace('/[^a-zA-Z0-9_\-]/', '', (string)$value);
        return mb_substr($value, 0, $maxLen);
    }

    public static function sanitizeText($value, $maxLen = 5000) {
        $value = strip_tags((string)$value);
        $value = htmlspecialchars($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        return mb_substr($value, 0, $maxLen);
    }

    public static function checkPasswordStrength($password) {
        $minLen = 8;
        if (mb_strlen($password) < $minLen) return '密码至少8个字符';
        if (!preg_match('/[A-Z]/', $password)) return '密码需包含大写字母';
        if (!preg_match('/[a-z]/', $password)) return '密码需包含小写字母';
        if (!preg_match('/[0-9]/', $password)) return '密码需包含数字';
        return true;
    }

    public static function setSecurityHeaders() {
        header('X-Permitted-Cross-Domain-Policies: none');
    }

    public static function cleanup() {
        $now = time();

        foreach (self::$blacklistIPs as $ip => $entry) {
            if (($entry['expire_at'] ?? 0) > 0 && $entry['expire_at'] < $now) {
                unset(self::$blacklistIPs[$ip]);
            }
        }

        if (self::$rateLimitData) {
            foreach (self::$rateLimitData as $key => $entry) {
                if (($entry['reset_at'] ?? 0) < $now) {
                    unset(self::$rateLimitData[$key]);
                }
            }
        }

        if (isset($_SESSION['waf_ip_history'])) {
            $_SESSION['waf_ip_history'] = array_filter(
                $_SESSION['waf_ip_history'],
                function ($entry) use ($now) {
                    return ($entry['time'] ?? 0) > $now - 300;
                }
            );
        }

        if (isset($_SESSION['waf_bot_timing']['timestamps'])) {
            $_SESSION['waf_bot_timing']['timestamps'] = array_filter(
                $_SESSION['waf_bot_timing']['timestamps'],
                function ($t) use ($now) {
                    return $t > $now - 60;
                }
            );
        }
    }

    public static function detectBot() {
        $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
        $ip = self::getClientIP();

        $acceptLang = $_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? '';
        $acceptEnc  = $_SERVER['HTTP_ACCEPT_ENCODING'] ?? '';
        $accept     = $_SERVER['HTTP_ACCEPT'] ?? '';

        $missingCount = 0;
        if (empty($acceptLang)) $missingCount++;
        if (empty($acceptEnc)) $missingCount++;
        if (empty($accept)) $missingCount++;

        $timingKey = 'waf_bot_timing';
        if (!isset($_SESSION[$timingKey])) {
            $_SESSION[$timingKey] = ['timestamps' => [], 'count' => 0];
        }
        $now = time();
        $_SESSION[$timingKey]['timestamps'][] = $now;
        $_SESSION[$timingKey]['count']++;

        $_SESSION[$timingKey]['timestamps'] = array_values(array_filter(
            $_SESSION[$timingKey]['timestamps'],
            function ($t) use ($now) {
                return $t > $now - 60;
            }
        ));

        $timestamps = $_SESSION[$timingKey]['timestamps'];
        if (count($timestamps) >= 10) {
            $intervals = [];
            for ($i = 1; $i < count($timestamps); $i++) {
                $intervals[] = $timestamps[$i] - $timestamps[$i - 1];
            }
            if (count($intervals) > 0) {
                $avgInterval = array_sum($intervals) / count($intervals);
                $variance = 0;
                foreach ($intervals as $interval) {
                    $variance += pow($interval - $avgInterval, 2);
                }
                $variance /= count($intervals);

                if ($variance < 0.01 && $avgInterval < 2) {
                    self::setJSChallenge('bot_timing');
                }
            }
        }

        if ($missingCount >= 2 && $_SESSION[$timingKey]['count'] > 15) {
            self::setJSChallenge('bot_missing_fingerprint');
        }
    }

    private static function setJSChallenge($reason = '') {
        $challenge = bin2hex(random_bytes(16));
        $secure = defined('IS_SECURE') ? IS_SECURE : false;
        setcookie('waf_js_challenge', $challenge, [
            'expires'  => time() + 3600,
            'path'     => '/',
            'secure'   => $secure,
            'httponly' => false,
            'samesite' => 'Lax',
        ]);
        $_SESSION['waf_challenge_token'] = $challenge;
        $_SESSION['waf_challenge_reason'] = $reason;
        $_SESSION['waf_challenge_required'] = true;
    }

    public static function generateFingerprint() {
        $components = [
            $_SERVER['HTTP_USER_AGENT']      ?? '',
            $_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? '',
            $_SERVER['HTTP_ACCEPT_ENCODING'] ?? '',
            self::getClientIP(),
        ];
        $fingerprint = hash('sha256', implode('|', $components));

        $key = 'waf_fingerprint';
        if (isset($_SESSION[$key])) {
            $prev = $_SESSION[$key];
            if ($prev['hash'] !== $fingerprint && ($prev['time'] ?? 0) > time() - 10) {
                $_SESSION['waf_fp_changes'] = ($_SESSION['waf_fp_changes'] ?? 0) + 1;
                // 阈值 15 次（原 8 次，2026-10 放宽）：UA / 语言头被中间层改写、移动网络切换
                // 出口 IP 都会造成指纹变化，过低会把正常用户整段 IP 拉黑。
                if ($_SESSION['waf_fp_changes'] > 15) {
                    $ip = self::getClientIP();
                    self::banIP($ip, 'fingerprint_anomaly', 300);
                    self::logAndBlock('fingerprint_anomaly', 'Rapid fingerprint changes detected');
                }
            } elseif ($prev['hash'] !== $fingerprint) {
                $_SESSION['waf_fp_changes'] = 0;
            }
        }

        $_SESSION[$key] = ['hash' => $fingerprint, 'time' => time()];
        return $fingerprint;
    }

    public static function detectIPRotation() {
        if (empty($_SESSION['user_id'])) return;

        $ip  = self::getClientIP();
        $now = time();
        $key = 'waf_ip_history';

        if (!isset($_SESSION[$key])) {
            $_SESSION[$key] = [];
        }

        $_SESSION[$key] = array_filter($_SESSION[$key], function ($entry) use ($now) {
            return ($entry['time'] ?? 0) > $now - 300;
        });

        $found = false;
        foreach ($_SESSION[$key] as &$entry) {
            if (($entry['ip'] ?? '') === $ip) {
                $entry['time'] = $now;
                $found = true;
                break;
            }
        }
        unset($entry);
        if (!$found) {
            $_SESSION[$key][] = ['ip' => $ip, 'time' => $now];
        }

        $uniqueIPs = [];
        foreach ($_SESSION[$key] as $entry) {
            $uniqueIPs[$entry['ip']] = true;
        }

        // 同一登录会话在 5 分钟内换了 5 个以上出口 IP 才算异常（原 3 个太紧：
        // 校园网多出口、手机在 WiFi/流量之间切换、运营商 NAT 轮换都会正常触发）。
        if (count($uniqueIPs) > 5) {
            // 已登录管理员只记日志：出口 IP 变化（校园网多出口 / 手机切 WiFi）在管理员身上
            // 很常见，直接封 IP + 销毁会话会把运营者踢出后台。
            if (self::isTrustedSession()) {
                error_log(sprintf(
                    'WAF: 管理员会话检测到 IP 变化，仅记录不处理 user=%d IPs: %s',
                    (int)$_SESSION['user_id'],
                    implode(', ', array_keys($uniqueIPs))
                ));
                return;
            }
            $userId = $_SESSION['user_id'];
            error_log(sprintf(
                'WAF: IP rotation detected for user %d, IPs: %s',
                $userId,
                implode(', ', array_keys($uniqueIPs))
            ));
            self::banIP($ip, 'ip_rotation', 600);
            $_SESSION = [];
            session_unset();
            session_destroy();
            http_response_code(403);
            die('403 Forbidden');
        }
    }

    public static function applyDDOSProtection() {
        $ip  = self::getClientIP();
        $now = time();

        $key  = 'waf_ddos_' . str_replace(['.', ':'], '_', $ip);
        if (!isset($_SESSION[$key])) {
            $_SESSION[$key] = [
                'hits'       => 0,
                'first_hit'  => $now,
                'ban_count'  => 0,
                'challenged' => false,
            ];
        }

        $ddos = &$_SESSION[$key];

        if ($now - $ddos['first_hit'] > 300) {
            $ddos = [
                'hits'       => 0,
                'first_hit'  => $now,
                'ban_count'  => $ddos['ban_count'],
                'challenged' => false,
            ];
        }

        $ddos['hits']++;

        if ($ddos['hits'] > 400 && !$ddos['challenged']) {
            $ddos['challenged'] = true;
            self::setJSChallenge('ddos_high_freq');
        }

        // 按会话统计的频率保护：阈值放宽到 600 次/5 分钟，封禁固定 10 分钟。
        // 原逻辑 100 次即封且逐级延长（最长 24 小时），共享 IP / 管理员连续操作会被长时间误封。
        if ($ddos['hits'] > 1200) {
            $duration = 600;

            self::banIP($ip, 'ddos_protection', $duration);

            http_response_code(429);
            header('Retry-After: ' . $duration);
            header('Content-Type: application/json; charset=utf-8');
            die(json_encode([
                'success'    => false,
                'message'    => '请求过于频繁，请 ' . ceil($duration / 60) . ' 分钟后再试',
                'retry_after' => $duration,
            ], JSON_UNESCAPED_UNICODE));
        }
    }
}

function waf_json_response($data, $code = 200) {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit();
}

function waf_json_error($message, $code = 400) {
    waf_json_response(['success' => false, 'message' => $message], $code);
}

function waf_json_success($data = [], $message = '操作成功') {
    waf_json_response(['success' => true, 'message' => $message, 'data' => $data]);
}