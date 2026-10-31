<?php
/**
 * QQMailer —— 基于 PHP stream_socket_client 的极简 SMTP 发送类。
 *
 * 仅使用 PHP 内置 Socket（ssl:// 自有扩展：OpenSSL），不依赖第三方 SMTP 库。
 * 适用于本机/免扩展环境的最小实现：EHLO → AUTH LOGIN → MAIL/RCPT → DATA → QUIT。
 *
 * 本类只接受 7bit/US-ASCII 协议层；中文正文经 base64（Content-Transfer-Encoding: base64）发送，
 * 中文主题通过 =?UTF-8?B??= 编码。任何异常都会被捕获并静默返回 false，绝不向外抛出。
 */
class QQMailer {

    /**
     * 最近一次失败的原因。
     *
     * 发信失败时由 send() 写入，供上层记录到 data/mail_log.json，
     * 让站长在后台能看到「为什么这封信没发出去」（认证失败？
     * 连接超时？被拒收？），而不是只有一个失败的布尔值。
     */
    private static $lastError = '';

    /** 取最近一次失败原因（成功时为空串）。 */
    public static function lastError(): string
    {
        return self::$lastError;
    }

    /**
     * 读取 config/mail_config.php 返回配置数组；文件不存在返回 null。
     * @return array|null
     */
    public static function cfg() {
        static $cfg = null;
        static $loaded = false;
        if ($loaded) {
            return $cfg;
        }
        $loaded = true;
        $file = __DIR__ . '/../config/mail_config.php';
        if (!file_exists($file)) {
            return null;
        }

        /* 同时支持两种配置文件写法。
         *
         * 【为什么】
         * 历史上有两种风格：
         *   A) \$MAIL_CFG = [...];         <- 老写法，靠 include 注入变量
         *   B) return [...];               <- 新写法，靠 include 的返回值
         *
         * 原来的代码只认 A。当配置文件被改成 B（后台保存时写的就是 B），
         * \$MAIL_CFG 永远是空数组 -> 判定「未配置」-> 全站邮件静默失败。
         * 这个 bug 不报错、不打日志，只是「发不出去」—— 极难排查。
         *
         * 现在先试 B（return 的返回值），拿不到再退回 A（变量）。
         */
        $MAIL_CFG = [];
        $ret = include $file;

        if (is_array($ret) && !empty($ret)) {
            $cfg = $ret;                       // 写法 B
        } elseif (is_array($MAIL_CFG) && !empty($MAIL_CFG)) {
            $cfg = $MAIL_CFG;                  // 写法 A
        } else {
            $cfg = null;
        }
        return $cfg;
    }

    /**
     * 配置是否可用（存在且 host/user/pass 非空）。
     * @return bool
     */
    public static function isConfigured() {
        $cfg = self::cfg();
        return !empty($cfg)
            && !empty($cfg['host'])
            && !empty($cfg['user'])
            && !empty($cfg['pass']);
    }

    /**
     * 探测 SMTP 连通性（不发信）。
     *
     * =====================================================================
     * 为什么需要这个
     * =====================================================================
     * 站长填完 SMTP 配置后，唯一能验证的办法是「发一封测试邮件」——
     * 但发信失败时原因可能是：端口不通、SSL 握手失败、账号密码错、
     * 发件人被拒、收件箱不存在…… 全混在一个 false 里。
     *
     * 这个函数把链路拆成四步分别报告，每步单独给结论：
     *   1. DNS 解析（域名能不能变成 IP）
     *   2. TCP 连接（端口通不通）
     *   3. TLS/SSL 握手（证书是否被接受）
     *   4. SMTP 握手 + 登录（账号密码对不对）
     *
     * 每一步都有独立耗时，卡在哪一步一目了然。
     * =====================================================================
     *
     * @return array{ok:bool, steps:array, message:string}
     */
    public static function testConnection(): array
    {
        $cfg = self::cfg();
        if (!$cfg) {
            return ['ok' => false, 'steps' => [], 'message' => 'SMTP 配置缺失'];
        }

        $host = (string)($cfg['host'] ?? '');
        $port = (int)($cfg['port'] ?? 465);
        $user = (string)($cfg['user'] ?? '');
        $pass = (string)($cfg['pass'] ?? '');

        $steps = [];

        // ── 1. DNS ──
        $t0 = microtime(true);
        $ips = @gethostbynamel($host);
        $dnsMs = (microtime(true) - $t0) * 1000;
        if (!$ips) {
            $steps[] = ['name' => 'DNS 解析', 'ok' => false, 'ms' => round($dnsMs), 'detail' => '域名无法解析'];
            return ['ok' => false, 'steps' => $steps, 'message' => '域名 ' . $host . ' 解析失败'];
        }
        $steps[] = ['name' => 'DNS 解析', 'ok' => true, 'ms' => round($dnsMs), 'detail' => implode(', ', array_slice($ips, 0, 2))];

        // ── 2. TCP ──
        $t0 = microtime(true);
        $sock = @fsockopen($host, $port, $errno, $errstr, 8);
        $tcpMs = (microtime(true) - $t0) * 1000;
        if (!$sock) {
            $steps[] = ['name' => 'TCP 连接', 'ok' => false, 'ms' => round($tcpMs),
                        'detail' => $errstr !== '' ? $errstr : ('错误码 ' . $errno)];
            return ['ok' => false, 'steps' => $steps, 'message' => '端口 ' . $port . ' 无法连接'];
        }
        @fclose($sock);
        $steps[] = ['name' => 'TCP 连接', 'ok' => true, 'ms' => round($tcpMs), 'detail' => $host . ':' . $port];

        // ── 3. TLS 握手（只有 465 这类 SSL 直连端口才测）──
        if ($port == 465) {
            $t0 = microtime(true);
            $ssl = @stream_socket_client('ssl://' . $host . ':' . $port, $errno, $errstr, 10);
            $tlsMs = (microtime(true) - $t0) * 1000;
            if (!$ssl) {
                $steps[] = ['name' => 'TLS 握手', 'ok' => false, 'ms' => round($tlsMs),
                            'detail' => $errstr !== '' ? $errstr : 'SSL 连接失败'];
                return ['ok' => false, 'steps' => $steps, 'message' => 'SSL 握手失败，检查 openssl 扩展或端口是否正确'];
            }
            @fclose($ssl);
            $steps[] = ['name' => 'TLS 握手', 'ok' => true, 'ms' => round($tlsMs), 'detail' => 'ssl:// 连接正常'];
        }

        // ── 4. SMTP 登录 ──
        $t0 = microtime(true);
        $fp = @stream_socket_client('ssl://' . $host . ':' . $port, $errno, $errstr, 10);
        $loginOk = false;
        $loginDetail = '';

        if ($fp) {
            stream_set_timeout($fp, 10);
            $read = function () use ($fp) {
                $out = '';
                while (!feof($fp)) {
                    $line = fgets($fp, 515);
                    if ($line === false) break;
                    $out .= $line;
                    if (strlen($line) >= 4 && $line[3] === ' ') break;
                }
                return $out;
            };

            $ok250 = function ($resp) {
                return preg_match('/^250/m', $resp) === 1;
            };

            $banner = $read();
            if (!$ok250($banner) && strpos($banner, '220') !== 0) {
                $loginDetail = '欢迎信息异常：' . substr(trim($banner), 0, 80);
            } else {
                fwrite($fp, "EHLO " . gethostname() . "\r\n");
                $ehlo = $read();

                fwrite($fp, "AUTH LOGIN\r\n");
                $a1 = $read();

                fwrite($fp, base64_encode($user) . "\r\n");
                $a2 = $read();

                fwrite($fp, base64_encode($pass) . "\r\n");
                $a3 = $read();

                if (strpos($a3, '235') === 0) {
                    $loginOk = true;
                    $loginDetail = '认证成功';
                    fwrite($fp, "QUIT\r\n");
                } else {
                    if (strpos($a1, '334') !== 0) {
                        $loginDetail = '服务器不支持 AUTH LOGIN';
                    } elseif (strpos($a2, '334') !== 0) {
                        $loginDetail = '用户名被拒：' . substr(trim($a2), 0, 60);
                    } else {
                        $loginDetail = '授权码错误或已过期（' . substr(trim($a3), 0, 60) . '）';
                    }
                }
            }
            @fclose($fp);
        } else {
            $loginDetail = $errstr !== '' ? $errstr : '无法建立连接';
        }

        $loginMs = (microtime(true) - $t0) * 1000;
        $steps[] = ['name' => 'SMTP 登录', 'ok' => $loginOk, 'ms' => round($loginMs), 'detail' => $loginDetail];

        return [
            'ok' => $loginOk,
            'steps' => $steps,
            'message' => $loginOk
                ? '全部检查通过，SMTP 可以正常发信'
                : '登录失败：' . $loginDetail,
        ];
    }

    /**
     * 发送一封 HTML 邮件。
     * @param string $to      收件人邮箱
     * @param string $subject 主题（可含中文）
     * @param string $html    正文（HTML，可含中文）
     * @return bool 是否发送成功
     */
    public static function send($to, $subject, $html) {
        self::$lastError = '';
        $cfg = self::cfg();
        if (!$cfg) {
            self::$lastError = 'SMTP 配置缺失（config/mail_config.php）';
            return false;
        }
        if (!function_exists('stream_socket_client')) {
            self::$lastError = 'PHP 未启用 stream_socket_client';
            return false;
        }
        // openssl 未启用则直接失败
        if (!in_array('ssl', stream_get_transports(), true)) {
            self::$lastError = 'PHP 未启用 openssl（无法 SSL 连接 SMTP）';
            return false;
        }

        $host = $cfg['host'];
        $port = (int)($cfg['port'] ?? 465);
        $user = $cfg['user'];
        $pass = $cfg['pass'];
        $from = $cfg['from'] ?: $user;
        $fromName = ($cfg['from_name'] ?? '') ?: $from;

        $errno = 0;
        $errstr = '';
        $fp = @stream_socket_client('ssl://' . $host . ':' . $port, $errno, $errstr, 30);
        if (!$fp) {
            self::$lastError = '连接 SMTP 失败：' . ($errstr !== '' ? $errstr : ('错误码 ' . $errno));
            return false;
        }
        stream_set_timeout($fp, 30);

        $read = function () use ($fp) {
            $full = '';
            while (true) {
                $line = @fgets($fp, 512);
                if ($line === false) {
                    break;
                }
                $full .= $line;
                // 若第四字符为 '-' 表示还有后续多行响应，继续读取
                if (!(isset($line[3]) && $line[3] === '-')) {
                    break;
                }
            }
            return $full;
        };
        $write = function ($cmd) use ($fp) {
            @fwrite($fp, $cmd . "\r\n");
        };
        $expect = function ($code) use ($read) {
            $resp = trim($read());
            return $resp !== '' && strpos($resp, (string)$code) === 0;
        };

        try {
            // 服务器问候
            $read();
            // EHLO —— QQ 邮箱支持 EHLO（无需 HELO）
            $write('EHLO ' . $host);
            if (!$expect(250)) { self::$lastError = 'SMTP 拒绝 EHLO 握手'; @fclose($fp); return false; }
            // AUTH LOGIN，base64 用户名与密码
            $write('AUTH LOGIN');
            if (!$expect(334)) { self::$lastError = 'SMTP 不支持 AUTH LOGIN'; @fclose($fp); return false; }
            $write(base64_encode($user));
            if (!$expect(334)) { self::$lastError = 'SMTP 不接受用户名'; @fclose($fp); return false; }
            $write(base64_encode($pass));
            if (!$expect(235)) { self::$lastError = 'SMTP 认证失败（授权码错误或已过期）'; @fclose($fp); return false; }
            // MAIL FROM / RCPT TO
            $write('MAIL FROM:<' . $from . '>');
            if (!$expect(250)) { self::$lastError = 'SMTP 拒绝发件人：' . $from; @fclose($fp); return false; }
            $write('RCPT TO:<' . $to . '>');
            if (!$expect(250)) { self::$lastError = 'SMTP 拒绝收件人：' . $to; @fclose($fp); return false; }
            // DATA
            $write('DATA');
            if (!$expect(354)) { self::$lastError = 'SMTP 拒绝进入数据传输'; @fclose($fp); return false; }

            // 正文 base64，按 76 字符分行（chunk_split 已带 \r\n）
            // 统一附上站点页脚（含站址），所有外发用户邮件均携带
            $html .= '<br><hr style="border:none;border-top:1px solid #eee;margin:26px 0 14px">'
                . '<p style="color:#9aa0a6;font-size:12px;margin:0;line-height:1.7">'
                . '本邮件由淮南师大校园综合信息交流平台自动发送，'
                . '站点地址：<a href="https://hnbsd.ct.ws" style="color:#6a7bfd;text-decoration:none">https://hnbsd.ct.ws</a>'
                . '<br>如非本人操作或无需处理，请忽略此邮件。</p>';
            $bodyB64 = chunk_split(base64_encode($html), 76, "\r\n");

            $headers = [
                'From: ' . self::encHeader($fromName) . ' <' . $from . '>',
                'To: <' . $to . '>',
                'Subject: ' . self::encHeader($subject),
                'Date: ' . date('r'),
                'MIME-Version: 1.0',
                'Content-Type: text/html; charset=UTF-8',
                'Content-Transfer-Encoding: base64',
            ];

            $message = implode("\r\n", $headers) . "\r\n\r\n" . $bodyB64;

            // 逐行写入，避免一次性写大 buffer 被阻塞
            foreach (preg_split("/\r\n/", $message) as $line) {
                $write($line);
            }
            // 结束标记
            $write('.');
            $ok = $expect(250);
            $write('QUIT');
            @fclose($fp);
            if (!$ok) { self::$lastError = 'SMTP 未确认收信'; }
        return $ok;
        } catch (\Throwable $e) {
        self::$lastError = 'SMTP 异常：' . $e->getMessage();
        if (!empty($fp)) { @fclose($fp); }
        return false;
        }
    }

    /**
     * 非 ASCII 内容用 UTF-8 base64 编码成 MIME 头。
     * @param string $str
     * @return string
     */
    private static function encHeader($str) {
        if (preg_match('/[\x80-\xff]/', (string)$str)) {
            return '=?UTF-8?B?' . base64_encode($str) . '?=';
        }
        return (string)$str;
    }
}