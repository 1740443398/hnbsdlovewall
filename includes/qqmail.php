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
        $MAIL_CFG = [];
        include $file;
        $cfg = is_array($MAIL_CFG) ? $MAIL_CFG : null;
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
     * 发送一封 HTML 邮件。
     * @param string $to      收件人邮箱
     * @param string $subject 主题（可含中文）
     * @param string $html    正文（HTML，可含中文）
     * @return bool 是否发送成功
     */
    public static function send($to, $subject, $html) {
        $cfg = self::cfg();
        if (!$cfg) {
            return false;
        }
        if (!function_exists('stream_socket_client')) {
            return false;
        }
        // openssl 未启用则直接失败
        if (!in_array('ssl', stream_get_transports(), true)) {
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
            if (!$expect(250)) { @fclose($fp); return false; }
            // AUTH LOGIN，base64 用户名与密码
            $write('AUTH LOGIN');
            if (!$expect(334)) { @fclose($fp); return false; }
            $write(base64_encode($user));
            if (!$expect(334)) { @fclose($fp); return false; }
            $write(base64_encode($pass));
            if (!$expect(235)) { @fclose($fp); return false; }
            // MAIL FROM / RCPT TO
            $write('MAIL FROM:<' . $from . '>');
            if (!$expect(250)) { @fclose($fp); return false; }
            $write('RCPT TO:<' . $to . '>');
            if (!$expect(250)) { @fclose($fp); return false; }
            // DATA
            $write('DATA');
            if (!$expect(354)) { @fclose($fp); return false; }

            // 正文 base64，按 76 字符分行（chunk_split 已带 \r\n）
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
            return $ok;
        } catch (\Throwable $e) {
            @fclose($fp);
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
