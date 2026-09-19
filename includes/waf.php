<?php

class WAF {
    private static $blacklistIPs = [];
    private static $rateLimitData = [];
    private static $rateLimitFile = null;
    private static $initialized = false;

    public static function init() {
        if (self::$initialized) return;
        self::$initialized = true;

        self::$rateLimitFile = __DIR__ . '/../data/waf_rate_limits.json';

        self::loadBlacklist();

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

        register_shutdown_function([self::class, 'saveRateLimitData']);
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

    public static function banIP($ip, $reason = '', $duration = 600) {
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
        } catch (Exception $e) {
            error_log('WAF banIP failed: ' . $e->getMessage());
        }
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

        if (preg_match('/\.\.(\/|\\\\)/', $uri) || strpos($uri, "\0") !== false) {
            self::logAndBlock('path_traversal', $uri);
        }

        $sensitivePatterns = [
            '/\.env/i', '/\.git\//i', '/\.svn\//i', '/\.hg\//i',
            '/\.DS_Store/i', '/\.htaccess/i', '/\.htpasswd/i',
            '/wp-admin/i', '/wp-login/i', '/wp-content/i', '/wp-includes/i',
            '/phpmyadmin/i', '/pma/i', '/mysql/i', '/adminer/i',
            '/config\.php/i', '/database\.php/i', '/wp-config\.php/i',
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

        $sqlPatterns = [
            '/(\%27)|(\')|(\-\-)|(\%23)|(#)/i',
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
            '/\b(concat\s*\()/i',
            '/\b(substring\s*\()/i',
            '/\b(mid\s*\()/i',
            '/\b(ascii\s*\()/i',
            '/\b(ord\s*\()/i',
            '/\b(hex\s*\()/i',
            '/\b(unhex\s*\()/i',
            '/\b(bin\s*\()/i',
            '/\b(cast\s*\()/i',
            '/\b(convert\s*\()/i',
            '/\b(if\s*\()/i',
            '/\b(case\s+when)\b/i',
            '/--\s/i',
            '/\/\*.*\*\//i',
            '/;\s*(select|insert|update|delete|drop|alter|create|truncate)/i',
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

    private static function checkRateLimit() {
        $ip = self::getClientIP();
        $uri = $_SERVER['REQUEST_URI'] ?? '/';
        $now = time();

        self::$rateLimitData = [];
        if (file_exists(self::$rateLimitFile)) {
            $data = json_decode(file_get_contents(self::$rateLimitFile), true);
            if (is_array($data)) {
                self::$rateLimitData = $data;
            }
        }

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
        $ipEntry['count']++;
        self::$rateLimitData[$ipKey] = $ipEntry;

        if ($ipEntry['count'] > 120) {
            self::banIP($ip, 'rate_limit_exceeded:' . $ipEntry['count'], 600);
            self::logAndBlock('rate_limit_exceeded', $ip . ' ' . $ipEntry['count'] . ' req/min');
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

            if ($apiEntry['count'] > 30) {
                self::banIP($ip, 'api_rate_limit', 300);
                http_response_code(429);
                die(json_encode(['success' => false, 'message' => '请求过于频繁，请稍后再试']));
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

            if (count($traverseEntry['ids']) > 50) {
                self::banIP($ip, 'traversal_scan', 1800);
                self::logAndBlock('traversal_scan', $ip . ' scanned ' . count($traverseEntry['ids']) . ' IDs');
            }
        }

        if (count(self::$rateLimitData) > 1000) {
            self::saveRateLimitData();
        }
    }

    public static function saveRateLimitData() {
        if (count(self::$rateLimitData) > 1000) {
            $now = time();
            self::$rateLimitData = array_filter(self::$rateLimitData, function($e) use ($now) {
                return ($e['reset_at'] ?? 0) > $now;
            });
            if (count(self::$rateLimitData) > 1000) {
                self::$rateLimitData = array_slice(self::$rateLimitData, -1000, 1000, true);
            }
        }
        @file_put_contents(self::$rateLimitFile, json_encode(self::$rateLimitData), LOCK_EX);
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
            $fs->insert('waf_logs', [
                'ip' => $ip,
                'type' => $type,
                'detail' => mb_substr($detail, 0, 500),
                'uri' => mb_substr($uri, 0, 500),
                'ua' => $ua,
                'method' => $method,
                'time' => date('Y-m-d H:i:s')
            ]);
        } catch (Exception $e) {
            error_log('WAF log failed: ' . $e->getMessage());
        }

        $attackKey = 'waf_attack_' . $ip;
        if (!isset($_SESSION[$attackKey])) {
            $_SESSION[$attackKey] = ['count' => 0, 'first_time' => time()];
        }
        $_SESSION[$attackKey]['count']++;
        if ($_SESSION[$attackKey]['count'] > 5) {
            self::banIP($ip, $type, 3600);
        }

        http_response_code(403);
        die('403 Forbidden');
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

        if (mt_rand(1, 100) === 1) {
            self::saveRateLimitData();
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
                if ($_SESSION['waf_fp_changes'] > 3) {
                    $ip = self::getClientIP();
                    self::banIP($ip, 'fingerprint_anomaly', 600);
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

        if (count($uniqueIPs) > 3) {
            $userId = $_SESSION['user_id'];
            error_log(sprintf(
                'WAF: IP rotation detected for user %d, IPs: %s',
                $userId,
                implode(', ', array_keys($uniqueIPs))
            ));
            self::banIP($ip, 'ip_rotation', 1800);
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

        if ($ddos['hits'] > 30 && !$ddos['challenged']) {
            $ddos['challenged'] = true;
            self::setJSChallenge('ddos_high_freq');
        }

        if ($ddos['hits'] > 100) {
            $ddos['ban_count']++;
            $durations = [600, 1800, 3600, 21600, 86400];
            $idx       = min($ddos['ban_count'] - 1, count($durations) - 1);
            $duration  = $durations[$idx];

            self::banIP($ip, 'ddos_protection_level_' . $ddos['ban_count'], $duration);

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
