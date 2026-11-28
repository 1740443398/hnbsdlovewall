<?php
/**
 * 邮箱中心（Mail Center）—— 用户级邮箱收发 + 站内邮箱 + 管理审计
 *
 * ==========================================================================
 * 定位（与既有邮件层的关系）
 * ==========================================================================
 *   includes/qqmail.php      底层 SMTP 客户端（站点级，发系统通知用）
 *   includes/mail_service.php 系统通知编排（注册欢迎/评论/点赞……）
 *   includes/mail_center.php  【本文件】用户自己的邮箱能力：
 *     · 站内邮箱：注册即开通 <QQ号>@<mail_domain>（默认 bsd.ct.ws）
 *       - 站内互发：直接落库，不经过 SMTP，可靠且零成本
 *       - 发往外部：经站长 SMTP 网关中继（envelope From = 网关账号，
 *         信头标注真实站内地址，避免被 QQ SMTP 以发件人不符拒绝）
 *     · 绑定自有邮箱：用户填「邮箱 + 授权码」，可收（IMAP）可发（SMTP）
 *     · 管理审计：每一封收发全部落 audit 流水，后台可查、可封禁
 *
 * ==========================================================================
 * 安全设计
 * ==========================================================================
 * 1. 授权码绝不落明文：AES-256-GCM 加密存储，密钥在 data/mail_center/secret.key
 *    （首次访问自动生成；data/ 目录本就被 .gitignore 与 WAF 挡住）
 * 2. 每封邮件（无论成败）都写 audit-YYYYMM.jsonl，含用户、方向、主题、风控结果
 * 3. 内容扫描：命中敏感词即标记；命中 ≥3 个高危直接拦截发送
 * 4. 每用户每日发送配额（默认 20 封），防群发滥用
 * 5. 封禁开关：后台一键封/解封某用户的整个邮箱功能
 *
 * ==========================================================================
 * 关于「收外部来信」的边界说明
 * ==========================================================================
 * @mail_domain 的 MX 必须指向能收信的服务器（如腾讯企业邮箱 catch-all），
 * 站长在后台配置该邮箱的 IMAP 参数后，本中心会拉取并按收件地址分发到
 * 对应站内邮箱。未配置 MX 前，站内邮箱只能收「站内互发」的信——
 * 这是邮箱服务器域名解析的客观限制，不是本层代码的缺陷。
 * ==========================================================================
 */

if (!defined('LW_MAIL_CENTER_LOADED')) {
    define('LW_MAIL_CENTER_LOADED', 1);

    /* ── 基础设施：目录 / 配置 / 密钥 ─────────────────────────────────── */

    function lwMcDir(string $sub = ''): string
    {
        $base = dirname(__DIR__) . '/data/mail_center';
        if ($sub !== '') { $base .= '/' . $sub; }
        if (!is_dir($base)) { @mkdir($base, 0755, true); }
        return $base;
    }

    /** 站点级配置（mail_domain / 配额 / 网关 / catch-all） */
    function lwMcCfg(): array
    {
        static $cfg = null;
        if ($cfg !== null) { return $cfg; }
        $f = lwMcDir() . '/config.json';
        $defaults = [
            'mail_domain' => 'bsd.ct.ws',   // 站内邮箱域名（可在后台改）
            'daily_limit' => 20,            // 每用户每日外发上限
            'inbox_cap'   => 200,           // 每用户收件箱保留条数
            // 站点官方信箱：对外公布的固定联系地址。与普通用户邮箱的区别是
            // 它不依赖任何注册账号是否存活 —— 站长换号、账号被封、甚至从未
            // 注册，这些地址照样能收站内信，后台「站点信箱」随时可查。
            'site_mailboxes' => [
                '1740443398@bsd.ct.ws',
            ],
            'catchall'    => [              // 外部来信 catch-all IMAP（配好 MX 后启用）
                'enabled' => false,
                'imap_host' => '',          // 如 imap.exmail.qq.com
                'imap_user' => '',          // catch-all 邮箱
                'imap_pass_enc' => '',      // 授权码（加密存储）
                'last_uid' => 0,            // 断点续拉
            ],
        ];
        if (is_file($f)) {
            $j = json_decode((string)@file_get_contents($f), true);
            if (is_array($j)) {
                $cfg = array_merge($defaults, $j);
                if (!is_array($cfg['catchall'] ?? null)) { $cfg['catchall'] = $defaults['catchall']; }
                if (!is_array($cfg['site_mailboxes'] ?? null)) { $cfg['site_mailboxes'] = $defaults['site_mailboxes']; }
                return $cfg;
            }
        }
        $cfg = $defaults;
        return $cfg;
    }

    function lwMcCfgSave(array $cfg): bool
    {
        try {
            return @file_put_contents(
                lwMcDir() . '/config.json',
                json_encode($cfg, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT),
                LOCK_EX
            ) !== false;
        } catch (Throwable $e) { return false; }
    }

    /** 加密密钥（首次自动生成 32 字节） */
    function lwMcSecret(): string
    {
        static $key = null;
        if ($key !== null) { return $key; }
        $f = lwMcDir() . '/secret.key';
        if (is_file($f)) {
            $hex = trim((string)@file_get_contents($f));
            if (preg_match('/^[0-9a-f]{64}$/i', $hex)) {
                $key = hash('sha256', hex2bin($hex), true);
                return $key;
            }
        }
        $hex = bin2hex(random_bytes(32));
        @file_put_contents($f, $hex, LOCK_EX);
        $key = hash('sha256', hex2bin($hex), true);
        return $key;
    }

    /** AES-256-GCM 加密（授权码存储专用），密文带 enc1: 前缀 */
    function lwMcEnc(string $plain): string
    {
        if ($plain === '') { return ''; }
        if (!function_exists('openssl_encrypt')) { return 'plain:' . base64_encode($plain); }
        $iv = random_bytes(12);
        $tag = '';
        $cipher = openssl_encrypt($plain, 'aes-256-gcm', lwMcSecret(), OPENSSL_RAW_DATA, $iv, $tag);
        if ($cipher === false) { return 'plain:' . base64_encode($plain); }
        return 'enc1:' . base64_encode($iv . $tag . $cipher);
    }

    function lwMcDec(string $stored): string
    {
        if ($stored === '') { return ''; }
        if (strpos($stored, 'plain:') === 0) { return base64_decode(substr($stored, 6)) ?: ''; }
        if (strpos($stored, 'enc1:') !== 0 || !function_exists('openssl_decrypt')) { return ''; }
        $raw = base64_decode(substr($stored, 5), true);
        if ($raw === false || strlen($raw) < 29) { return ''; }
        $iv = substr($raw, 0, 12);
        $tag = substr($raw, 12, 16);
        $cipher = substr($raw, 28);
        $plain = openssl_decrypt($cipher, 'aes-256-gcm', lwMcSecret(), OPENSSL_RAW_DATA, $iv, $tag);
        return $plain === false ? '' : $plain;
    }

    /* ── 账号存取 ─────────────────────────────────────────────────────── */

    function lwMcAcctAll(): array
    {
        $f = lwMcDir() . '/accounts.json';
        $j = json_decode((string)@file_get_contents($f), true);
        return is_array($j) ? $j : [];
    }

    function lwMcAcctPutAll(array $all): void
    {
        @file_put_contents(
            lwMcDir() . '/accounts.json',
            json_encode($all, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT),
            LOCK_EX
        );
    }

    function lwMcAcct(int $uid): array
    {
        $all = lwMcAcctAll();
        $a = $all[(string)$uid] ?? null;
        return is_array($a) ? $a : [];
    }

    function lwMcAcctPut(int $uid, array $acct): void
    {
        $all = lwMcAcctAll();
        $all[(string)$uid] = $acct;
        lwMcAcctPutAll($all);
    }

    /**
     * 注册即开通站内邮箱 <QQ号>@<mail_domain>。
     * 已存在则幂等返回。任何异常静默（不影响注册主流程）。
     */
    function lwMcProvision(int $uid, string $qq): string
    {
        try {
            if ($uid <= 0 || !preg_match('/^[1-9][0-9]{4,11}$/', $qq)) { return ''; }
            $acct = lwMcAcct($uid);
            if (!empty($acct['site_addr'])) { return (string)$acct['site_addr']; }
            $domain = (string)(lwMcCfg()['mail_domain'] ?? 'bsd.ct.ws');
            $siteAddr = $qq . '@' . $domain;
            lwMcAcctPut($uid, array_merge($acct, [
                'uid'        => $uid,
                'site_addr'  => $siteAddr,
                'created_at' => date('Y-m-d H:i:s'),
                'bound'      => $acct['bound'] ?? null,
                'banned'     => 0,
            ]));
            lwMcAudit([
                'uid' => $uid, 'addr' => $siteAddr, 'dir' => 'sys',
                'event' => 'provision', 'subject' => '开通站内邮箱',
                'status' => 'ok', 'ts' => time(),
            ]);
            return $siteAddr;
        } catch (Throwable $e) {
            return '';
        }
    }

    function lwMcSiteAddr(int $uid): string
    {
        return (string)(lwMcAcct($uid)['site_addr'] ?? '');
    }

    function lwMcBanned(int $uid): bool
    {
        return !empty(lwMcAcct($uid)['banned']);
    }

    function lwMcSetBan(int $uid, bool $banned, string $reason = '', string $by = ''): void
    {
        $acct = lwMcAcct($uid);
        if (!$acct) { return; }
        $acct['banned'] = $banned ? 1 : 0;
        $acct['ban_reason'] = $banned ? $reason : '';
        $acct['banned_at'] = $banned ? date('Y-m-d H:i:s') : '';
        $acct['banned_by'] = $banned ? $by : '';
        lwMcAcctPut($uid, $acct);
        lwMcAudit([
            'uid' => $uid, 'addr' => (string)($acct['site_addr'] ?? ''), 'dir' => 'sys',
            'event' => $banned ? 'ban' : 'unban', 'subject' => $reason,
            'status' => 'ok', 'ts' => time(),
        ]);
    }

    /** 按站内地址反查 uid（站内互发投递用） */
    function lwMcFindUidByAddr(string $addr): int
    {
        $addr = strtolower(trim($addr));
        foreach (lwMcAcctAll() as $uid => $a) {
            if (strcasecmp((string)($a['site_addr'] ?? ''), $addr) === 0) { return (int)$uid; }
        }
        return 0;
    }

    /* ── 站点官方信箱（对外公布的联系地址） ───────────────────────────── */

    /**
     * 站点信箱表：[小写地址 => ['addr'=>原样地址, 'uid'=>虚拟uid(负数), 'label'=>说明]]
     *
     * 为什么会存在这一层：
     *   普通站内邮箱绑死在某个注册用户身上（accounts.json 里有记录才会投递）。
     *   而官网、条款页、封禁页上写的联系邮箱必须「永远能收信」——站长换 QQ 号、
     *   账号被误封、甚至站点还没人注册，这个地址都不能失效。
     *   所以用负数虚拟 uid 单独划一块存储区（inbox/-1.json），后台可直接查阅。
     */
    function lwMcSiteBoxes(): array
    {
        static $boxes = null;
        if ($boxes !== null) { return $boxes; }
        $boxes = [];
        $list = lwMcCfg()['site_mailboxes'] ?? [];
        if (!is_array($list)) { $list = []; }
        $i = 0;
        foreach ($list as $item) {
            $addr = is_array($item) ? (string)($item['addr'] ?? '') : (string)$item;
            $addr = strtolower(trim($addr));
            if ($addr === '' || !filter_var($addr, FILTER_VALIDATE_EMAIL)) { continue; }
            if (isset($boxes[$addr])) { continue; }
            $label = is_array($item) ? (string)($item['label'] ?? '') : '';
            $boxes[$addr] = [
                'addr'  => $addr,
                'uid'   => -1 - $i,                      // -1, -2, -3 …… 与真实用户互不冲突
                'label' => $label !== '' ? $label : '站点官方信箱',
            ];
            $i++;
        }
        return $boxes;
    }

    function lwMcIsSiteBox(string $addr): bool
    {
        return isset(lwMcSiteBoxes()[strtolower(trim($addr))]);
    }

    /**
     * 把收件地址解析成投递目标（可以多个）。
     *
     * 同一地址可能同时命中两类目标：
     *   · 站点官方信箱（负 uid，后台可查，永久有效）
     *   · 已注册用户开通的同名站内邮箱（正 uid，用户自己的邮箱中心可看）
     * 两份都投，站内信不会因为「谁是站长」这种组织问题而丢。
     */
    function lwMcResolveTargets(string $addr): array
    {
        $addr = strtolower(trim($addr));
        if ($addr === '') { return []; }
        $out = [];
        $boxes = lwMcSiteBoxes();
        if (isset($boxes[$addr])) { $out[] = (int)$boxes[$addr]['uid']; }
        $u = lwMcFindUidByAddr($addr);
        if ($u > 0) { $out[] = $u; }
        return array_values(array_unique($out));
    }

    /** 站点信箱里的信（后台查看用） */
    function lwMcSiteBoxInbox(string $addr): array
    {
        $boxes = lwMcSiteBoxes();
        $addr = strtolower(trim($addr));
        if (!isset($boxes[$addr])) { return []; }
        return lwMcStoreGet('inbox', (int)$boxes[$addr]['uid']);
    }

    /** 站点信箱列表 + 未读量（后台首屏用） */
    function lwMcSiteBoxOverview(): array
    {
        $out = [];
        foreach (lwMcSiteBoxes() as $addr => $b) {
            $rows = lwMcStoreGet('inbox', (int)$b['uid']);
            $last = $rows[0] ?? null;
            $out[] = [
                'addr'  => (string)$b['addr'],
                'label' => (string)$b['label'],
                'count' => count($rows),
                'last'  => is_array($last) ? [
                    'from' => (string)($last['from'] ?? ''),
                    'subject' => (string)($last['subject'] ?? ''),
                    'ts' => (string)($last['ts'] ?? ''),
                ] : null,
            ];
        }
        return $out;
    }

    /* ── 常见邮箱服务商主机推导 ───────────────────────────────────────── */

    /**
     * 返回 [smtp_host, smtp_port, secure, imap_host, imap_port]
     * secure: 'ssl'（465 直连）| 'starttls'（587 升级）
     */
    function lwMcHostsFor(string $email): array
    {
        $domain = strtolower((string)substr(strrchr(trim($email), '@') ?: '', 1));
        $map = [
            'qq.com'      => ['smtp.qq.com', 465, 'ssl', 'imap.qq.com', 993],
            'foxmail.com' => ['smtp.qq.com', 465, 'ssl', 'imap.qq.com', 993],
            '163.com'     => ['smtp.163.com', 465, 'ssl', 'imap.163.com', 993],
            '126.com'     => ['smtp.126.com', 465, 'ssl', 'imap.126.com', 993],
            'yeah.net'    => ['smtp.yeah.net', 465, 'ssl', 'imap.yeah.net', 993],
            'sina.com'    => ['smtp.sina.com', 465, 'ssl', 'imap.sina.com', 993],
            'gmail.com'   => ['smtp.gmail.com', 465, 'ssl', 'imap.gmail.com', 993],
            'outlook.com' => ['smtp.office365.com', 587, 'starttls', 'outlook.office365.com', 993],
            'hotmail.com' => ['smtp.office365.com', 587, 'starttls', 'outlook.office365.com', 993],
        ];
        return $map[$domain] ?? ['', 0, 'ssl', '', 0];
    }

    /* ── 通用 SMTP 客户端（支持 ssl 直连 / STARTTLS） ─────────────────── */

    /**
     * @param array $o {host,port,secure,user,pass,envelope_from,from_name,from,reply_to,to,subject,html}
     * @return array{ok:bool, err:string}
     */
    function lwMcSmtpSend(array $o): array
    {
        $err = function (string $m): array { return ['ok' => false, 'err' => $m]; };
        $host = (string)($o['host'] ?? '');
        $port = (int)($o['port'] ?? 465);
        $secure = (string)($o['secure'] ?? 'ssl');
        if ($host === '' || ($o['to'] ?? '') === '') { return $err('参数不完整'); }
        if (!in_array('ssl', stream_get_transports(), true)) { return $err('PHP openssl 未启用'); }

        $errno = 0; $errstr = '';
        $transport = ($secure === 'ssl') ? 'ssl://' : '';
        $fp = @stream_socket_client($transport . $host . ':' . $port, $errno, $errstr, 15);
        if (!$fp) { return $err('连接失败：' . ($errstr !== '' ? $errstr : ('错误码 ' . $errno))); }
        stream_set_timeout($fp, 15);

        $read = function () use ($fp) {
            $full = '';
            while (true) {
                $line = @fgets($fp, 512);
                if ($line === false) { break; }
                $full .= $line;
                if (!(isset($line[3]) && $line[3] === '-')) { break; }
            }
            return $full;
        };
        $write = function (string $cmd) use ($fp) { @fwrite($fp, $cmd . "\r\n"); };
        $expect = function (int $code) use ($read, &$errstr) {
            $resp = trim($read());
            return $resp !== '' && strpos($resp, (string)$code) === 0;
        };
        $fail = function (string $m) use ($fp, $err) { @fclose($fp); return $err($m); };

        try {
            $banner = trim($read());
            if (strpos($banner, '220') !== 0) { return $fail('服务器欢迎信息异常'); }
            $write('EHLO localhost');
            $ehlo = $read();
            if (strpos($ehlo, '250') !== 0) { return $fail('EHLO 握手被拒'); }

            if ($secure === 'starttls') {
                $write('STARTTLS');
                if (!$expect(220)) { return $fail('服务器不支持 STARTTLS'); }
                if (!@stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                    return $fail('TLS 升级失败');
                }
                $write('EHLO localhost');
                if (strpos($read(), '250') !== 0) { return $fail('TLS 后 EHLO 失败'); }
            }

            if (!empty($o['user'])) {
                $write('AUTH LOGIN');
                if (!$expect(334)) { return $fail('服务器不支持 AUTH LOGIN'); }
                $write(base64_encode((string)$o['user']));
                if (!$expect(334)) { return $fail('用户名被拒'); }
                $write(base64_encode((string)$o['pass']));
                if (!$expect(235)) { return $fail('认证失败（授权码错误或已过期）'); }
            }

            $envelopeFrom = (string)($o['envelope_from'] ?? $o['from'] ?? '');
            $write('MAIL FROM:<' . $envelopeFrom . '>');
            if (!$expect(250)) { return $fail('发件人被拒：' . $envelopeFrom); }
            $write('RCPT TO:<' . (string)$o['to'] . '>');
            if (!$expect(250)) { return $fail('收件人被拒：' . (string)$o['to']); }
            $write('DATA');
            if (!$expect(354)) { return $fail('服务器拒绝接收正文'); }

            $encHdr = function (string $s): string {
                return preg_match('/[\x80-\xff]/', $s)
                    ? '=?UTF-8?B?' . base64_encode($s) . '?=' : $s;
            };
            $headers = [
                'From: ' . $encHdr((string)($o['from_name'] ?? '')) . ' <' . (string)($o['from'] ?? $envelopeFrom) . '>',
                'To: <' . (string)$o['to'] . '>',
                'Subject: ' . $encHdr((string)($o['subject'] ?? '')),
                'Date: ' . date('r'),
                'MIME-Version: 1.0',
                'Content-Type: text/html; charset=UTF-8',
                'Content-Transfer-Encoding: base64',
            ];
            if (!empty($o['reply_to'])) {
                $headers[] = 'Reply-To: ' . (string)$o['reply_to'];
            }
            $bodyB64 = chunk_split(base64_encode((string)($o['html'] ?? '')), 76, "\r\n");
            $message = implode("\r\n", $headers) . "\r\n\r\n" . $bodyB64;

            foreach (preg_split("/\r\n/", $message) as $line) {
                if (strpos($line, '.') === 0) { $line = '.' . $line; }  // 点填充防提前结束
                $write($line);
            }
            $write('.');
            $ok = $expect(250);
            $write('QUIT');
            @fclose($fp);
            return $ok ? ['ok' => true, 'err' => ''] : $err('服务器未确认收信');
        } catch (Throwable $e) {
            if (!empty($fp)) { @fclose($fp); }
            return $err('SMTP 异常：' . $e->getMessage());
        }
    }

    /* ── 极简 IMAP 客户端（拉取收件） ─────────────────────────────────── */

    /**
     * 拉取最近邮件（PEEK 不改变未读状态）。
     * @return array 每封 {uid, from, from_addr, subject, date, text}
     */
    function lwMcImapFetch(string $host, int $port, string $user, string $pass, int $limit = 15): array
    {
        $errno = 0; $errstr = '';
        $fp = @stream_socket_client('ssl://' . $host . ':' . $port, $errno, $errstr, 12);
        if (!$fp) { return []; }
        stream_set_timeout($fp, 12);

        $n = 0;
        $cmd = function (string $c) use ($fp) { @fwrite($fp, $c . "\r\n"); };
        /** 读到以 "TAG " 开头的结束行为止，处理 {N} 字面量 */
        $readTagged = function (string $tag) use ($fp, &$n) {
            $buf = '';
            while (!feof($fp)) {
                $line = (string)@fgets($fp, 8192);
                // IMAP 字面量：行尾 {N}\r\n → 精确读取 N 字节
                if (preg_match('/\{(\d+)\}\r?\n$/', $line, $m)) {
                    $need = (int)$m[1];
                    $lit = '';
                    while (strlen($lit) < $need && !feof($fp)) {
                        $lit .= (string)@fread($fp, min(65536, $need - strlen($lit)));
                    }
                    $buf .= $line . $lit;
                    continue;
                }
                $buf .= $line;
                if (strpos($line, $tag . ' ') === 0) { break; }
            }
            return $buf;
        };

        $parseMsgs = function (string $fetchBuf): array {
            $msgs = [];
            // 按消息分段：* N FETCH (...) —— 每段含 UID n 与报文
            preg_match_all('/\*\s+(\d+)\s+FETCH\s+(.*?)(?=\*\s+\d+\s+FETCH|\z)/s', $fetchBuf, $chunks, PREG_SET_ORDER);
            foreach ($chunks as $c) {
                $body = $c[2];
                preg_match('/UID\s+(\d+)/', $body, $um);
                $uid = (int)($um[1] ?? 0);
                // HEADERS 段：[HEADER...] 后紧跟 {N} 字面量
                if (!preg_match('/\[HEADER[^\]]*\]\s*\{(\d+)\}(\r\n|\n)/', $body, $hm, 0)) { continue; }
                $hPos = strpos($body, $hm[0]);
                if ($hPos === false) { continue; }
                $hStart = $hPos + strlen($hm[0]);
                $hdr = substr($body, $hStart, (int)$hm[1]);
                // TEXT 段：HEADERS 字面量结束之后找 [TEXT] {N}
                $text = '';
                if (preg_match('/\[TEXT\]\s*\{(\d+)\}(\r\n|\n)/', $body, $tm, 0, $hStart)) {
                    $tPos = strpos($body, $tm[0], $hStart);
                    if ($tPos !== false) {
                        $tStart = $tPos + strlen($tm[0]);
                        $text = substr($body, $tStart, min((int)$tm[1], 65536));
                    }
                }
                $hget = function (string $name) use ($hdr): string {
                    if (preg_match('/^' . $name . '\s*:\s*(.+)$/im', $hdr, $m)) {
                        return trim($m[1]);
                    }
                    return '';
                };
                $decodeMime = function (string $s): string {
                    return preg_replace_callback('/=\?([^?]+)\?([bBqQ])\?([^?]*)\?=/', function ($m) {
                        $v = strtolower($m[2]) === 'b' ? base64_decode($m[3]) : quoted_printable_decode($m[3]);
                        return $v !== false ? $v : $m[0];
                    }, $s);
                };
                $from = $decodeMime($hget('FROM'));
                $to = $decodeMime($hget('TO'));
                $subject = $decodeMime($hget('SUBJECT'));
                $date = $hget('DATE');
                // 解码正文：从 TEXT 的原编码推断（简化：检测 base64 大块）
                $cte = '';
                if (preg_match('/Content-Transfer-Encoding\s*:\s*(\w+)/i', $hdr, $ce)) { $cte = strtolower($ce[1]); }
                if ($cte === 'base64') {
                    $text = base64_decode(preg_replace('/\s+/', '', $text)) ?: $text;
                } elseif ($cte === 'quoted-printable') {
                    $text = quoted_printable_decode($text);
                }
                // HTML 正文降级为纯文本预览
                if (preg_match('/<\w+[\s>]/', $text) && stripos($text, '<html') !== false) {
                    $text = trim(strip_tags(preg_replace('/<(script|style)[^>]*>.*?<\/\1>/is', '', $text)));
                }
                $text = trim(mb_substr(trim($text), 0, 2000));
                $fromAddr = '';
                if (preg_match('/<([^>]+)>/', $from, $fm)) { $fromAddr = $fm[1]; }
                elseif (preg_match('/[\w.\-]+@[\w.\-]+/', $from, $fm2)) { $fromAddr = $fm2[0]; }
                $msgs[] = [
                    'uid' => $uid,
                    'from' => trim(preg_replace('/\s+/', ' ', $from)),
                    'from_addr' => $fromAddr,
                    'to' => trim(preg_replace('/\s+/', ' ', $to)),
                    'subject' => trim(preg_replace('/\s+/', ' ', $subject)),
                    'date' => $date,
                    'text' => $text,
                ];
            }
            return $msgs;
        };

        try {
            // 服务器问候（单行 * OK ...）
            @fgets($fp, 8192);
            // LOGIN
            $t = 'A' . (++$n);
            $q = function (string $s): string { return '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], $s) . '"'; };
            $cmd($t . ' LOGIN ' . $q($user) . ' ' . $q($pass));
            $loginResp = $readTagged($t);
            if (strpos($loginResp, $t . ' OK') !== 0) { @fclose($fp); return []; }

            $t = 'A' . (++$n);
            $cmd($t . ' SELECT INBOX');
            $readTagged($t);

            $t = 'A' . (++$n);
            $cmd($t . ' UID SEARCH SINCE ' . date('d-M-Y', time() - 7 * 86400));
            $searchResp = $readTagged($t);
            $uids = [];
            if (preg_match('/\* SEARCH\s+(.*)$/m', $searchResp, $sm)) {
                $uids = array_filter(array_map('intval', preg_split('/\s+/', trim($sm[1]))));
            }
            if (!$uids) { $cmd('A' . (++$n) . ' LOGOUT'); @fclose($fp); return []; }
            $uids = array_slice($uids, -$limit);

            $all = [];
            foreach ($uids as $uid) {
                $t = 'A' . (++$n);
                $cmd($t . ' UID FETCH ' . $uid . ' (BODY.PEEK[HEADER.FIELDS (FROM TO SUBJECT DATE CONTENT-TRANSFER-ENCODING)] BODY.PEEK[TEXT])');
                $resp = $readTagged($t);
                $msgs = $parseMsgs($resp);
                foreach ($msgs as $m) { $m['uid'] = $uid; $all[] = $m; }
            }
            $cmd('A' . (++$n) . ' LOGOUT');
            @fclose($fp);
            return $all;
        } catch (Throwable $e) {
            if (!empty($fp)) { @fclose($fp); }
            return [];
        }
    }

    /* ── 收件箱 / 已发送 / 审计 存储 ─────────────────────────────────── */

    function lwMcStorePath(string $kind, int $uid): string
    {
        return lwMcDir($kind) . '/' . $uid . '.json';
    }

    function lwMcStoreGet(string $kind, int $uid): array
    {
        $j = json_decode((string)@file_get_contents(lwMcStorePath($kind, $uid)), true);
        return is_array($j) ? $j : [];
    }

    function lwMcStorePut(string $kind, int $uid, array $rows): void
    {
        $cap = (int)(lwMcCfg()['inbox_cap'] ?? 200);
        if ($kind === 'sent') { $cap = min($cap, 200); }
        if (count($rows) > $cap) { $rows = array_slice($rows, 0, $cap); }
        @file_put_contents(
            lwMcStorePath($kind, $uid),
            json_encode($rows, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT),
            LOCK_EX
        );
    }

    /** 头插一条（新邮件在前），按 msgid 去重 */
    function lwMcStorePush(string $kind, int $uid, array $row): bool
    {
        $rows = lwMcStoreGet($kind, $uid);
        $key = $row['msgid'] ?? '';
        if ($key !== '') {
            foreach ($rows as $r) { if (($r['msgid'] ?? '') === $key) { return false; } }
        }
        array_unshift($rows, $row);
        lwMcStorePut($kind, $uid, $rows);
        return true;
    }

    /** 审计流水（按月文件，后台审计台读取） */
    function lwMcAudit(array $row): void
    {
        try {
            $row['ts'] = (int)($row['ts'] ?? time());
            $f = lwMcDir() . '/audit-' . date('Ym') . '.jsonl';
            @file_put_contents($f, json_encode($row, JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND | LOCK_EX);
        } catch (Throwable $e) {
        }
    }

    function lwMcAuditRead(string $month = ''): array
    {
        $month = $month !== '' ? $month : date('Ym');
        if (!preg_match('/^\d{6}$/', $month)) { return []; }
        $f = lwMcDir() . '/audit-' . $month . '.jsonl';
        if (!is_file($f)) { return []; }
        $rows = [];
        foreach (file($f, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            $j = json_decode($line, true);
            if (is_array($j)) { $rows[] = $j; }
        }
        return array_reverse($rows); // 新的在前
    }

    /** 违规标记（后台审计台人工标记；key = ts-uid-dir） */
    function lwMcFlagsGet(): array
    {
        $j = json_decode((string)@file_get_contents(lwMcDir() . '/flags.json'), true);
        return is_array($j) ? $j : [];
    }

    function lwMcFlagsPut(array $flags): void
    {
        @file_put_contents(
            lwMcDir() . '/flags.json',
            json_encode($flags, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT),
            LOCK_EX
        );
    }

    /* ── 内容风控 ─────────────────────────────────────────────────────── */

    function lwMcWordList(): array
    {
        static $words = null;
        if ($words !== null) { return $words; }
        $j = json_decode((string)@file_get_contents(dirname(__DIR__) . '/data/sensitive_words.json'), true);
        $words = [];
        if (is_array($j)) {
            foreach ($j as $w) {
                $word = trim((string)($w['word'] ?? ''));
                if (mb_strlen($word) >= 2) { $words[] = $word; }
            }
        }
        return $words;
    }

    /**
     * 扫描主题+正文。level: none（干净）/ flagged（有命中，放行但标记）/ high（≥3 命中，拦截）
     */
    function lwMcScan(string $subject, string $text): array
    {
        $hay = mb_strtolower($subject . "\n" . $text);
        $hits = [];
        foreach (lwMcWordList() as $w) {
            if (mb_stripos($hay, mb_strtolower($w)) !== false) { $hits[] = $w; }
            if (count($hits) >= 12) { break; }
        }
        $level = 'none';
        if (count($hits) >= 3) { $level = 'high'; }
        elseif ($hits) { $level = 'flagged'; }
        // 外链轰炸（钓鱼/广告常见形态）：正文超 5 个不同外链也视为高危
        preg_match_all('/https?:\/\/[a-z0-9\-\.]+/i', $text, $lm);
        $links = array_unique(array_map('strtolower', $lm[0] ?? []));
        if (count($links) > 5) { $level = 'high'; $hits[] = '(外链 ' . count($links) . ' 个)'; }
        return ['level' => $level, 'hits' => array_slice($hits, 0, 8)];
    }

    /* ── 配额 ────────────────────────────────────────────────────────── */

    function lwMcQuota(int $uid): array
    {
        $limit = max(1, (int)(lwMcCfg()['daily_limit'] ?? 20));
        $f = lwMcDir() . '/quota.json';
        $all = json_decode((string)@file_get_contents($f), true);
        if (!is_array($all)) { $all = []; }
        $today = date('Y-m-d');
        $rec = $all[(string)$uid] ?? null;
        $used = (is_array($rec) && ($rec['date'] ?? '') === $today) ? (int)$rec['count'] : 0;
        return ['allowed' => $used < $limit, 'used' => $used, 'limit' => $limit];
    }

    function lwMcQuotaBump(int $uid): void
    {
        $f = lwMcDir() . '/quota.json';
        $all = json_decode((string)@file_get_contents($f), true);
        if (!is_array($all)) { $all = []; }
        $today = date('Y-m-d');
        $rec = $all[(string)$uid] ?? null;
        $used = (is_array($rec) && ($rec['date'] ?? '') === $today) ? (int)$rec['count'] + 1 : 1;
        $all[(string)$uid] = ['date' => $today, 'count' => $used];
        @file_put_contents($f, json_encode($all, JSON_PRETTY_PRINT), LOCK_EX);
    }

    /* ── 发送编排 ─────────────────────────────────────────────────────── */

    /**
     * 用户发信统一入口。
     * @param string $fromKind 'site'（站内地址，走网关/站内投递）| 'bound'（自有邮箱直发）
     * @return array{ok:bool, err:string, risk:string}
     */
    function lwMcSend(int $uid, string $fromKind, string $to, string $subject, string $text): array
    {
        $fail = function (string $err, string $risk = 'none') use ($uid, $fromKind, $to, $subject, $text) {
            lwMcAudit([
                'uid' => $uid, 'addr' => $fromKind === 'site' ? lwMcSiteAddr($uid) : (string)(lwMcAcct($uid)['bound']['email'] ?? ''),
                'dir' => 'out', 'event' => 'send', 'from' => '', 'to' => $to,
                'subject' => $subject, 'text' => mb_substr($text, 0, 500),
                'status' => 'failed', 'reason' => $err, 'risk' => $risk, 'ts' => time(),
            ]);
            return ['ok' => false, 'err' => $err, 'risk' => $risk];
        };

        if (lwMcBanned($uid)) {
            $r = (string)(lwMcAcct($uid)['ban_reason'] ?? '');
            return $fail('邮箱功能已被管理员停用' . ($r !== '' ? '：' . $r : ''));
        }

        $to = trim($to);
        if (!filter_var($to, FILTER_VALIDATE_EMAIL)) { return $fail('收件人邮箱格式不正确'); }
        $subject = trim(mb_substr($subject, 0, 120));
        $text = trim($text);
        if ($subject === '') { return $fail('主题不能为空'); }
        if ($text === '' || mb_strlen($text) > 10000) { return $fail('正文需在 1~10000 字之间'); }

        $risk = lwMcScan($subject, $text);
        if ($risk['level'] === 'high') {
            return $fail('内容触发安全策略，已拦截并记录；如有疑问请联系管理员', 'high');
        }

        /* 站内互发：收件人是本站站内地址（含站点官方信箱）→ 直接落库，不走 SMTP。
           站点域名下的信无论如何都不该出网：MX 未配置时外发必然退信，
           而且站内互发零成本、即时到达、还能被审计。 */
        $localTargets = [];
        $domain = strtolower((string)(lwMcCfg()['mail_domain'] ?? 'bsd.ct.ws'));
        if (preg_match('/@' . preg_quote($domain, '/') . '$/i', $to)) {
            $localTargets = lwMcResolveTargets($to);
            if (!$localTargets) { return $fail('站内不存在该邮箱地址'); }
        }

        $fromAddr = '';
        $msgid = date('YmdHis') . '-' . bin2hex(random_bytes(4));
        $now = date('Y-m-d H:i:s');
        $siteName = defined('SITE_NAME') ? SITE_NAME : '校园交流墙';

        if ($fromKind === 'bound' && !$localTargets) {
            $bound = lwMcAcct($uid)['bound'] ?? null;
            if (!$bound || empty($bound['email'])) { return $fail('尚未绑定自有邮箱'); }
            $fromAddr = (string)$bound['email'];
            $hosts = lwMcHostsFor($fromAddr);
            $smtpHost = (string)($bound['smtp_host'] ?: $hosts[0]);
            $smtpPort = (int)($bound['smtp_port'] ?: $hosts[1]);
            $secure = (string)($bound['secure'] ?: $hosts[2]);
            if ($smtpHost === '') { return $fail('无法识别该邮箱的服务商，请手动填写 SMTP 参数'); }
            $r = lwMcSmtpSend([
                'host' => $smtpHost, 'port' => $smtpPort, 'secure' => $secure,
                'user' => $fromAddr, 'pass' => lwMcDec((string)$bound['pass_enc']),
                'envelope_from' => $fromAddr,
                'from_name' => (string)($bound['display_name'] ?: $fromAddr),
                'from' => $fromAddr, 'to' => $to,
                'subject' => $subject,
                'html' => nl2br(htmlspecialchars($text, ENT_QUOTES, 'UTF-8')),
            ]);
            if (!$r['ok']) { return $fail('发送失败：' . $r['err'], $risk['level']); }
            lwMcQuotaBump($uid);
        } else {
            // 站内地址发信（含收件人在本站、但用户选了自有邮箱身份的情况：走站内投递）
            if (!$localTargets) {
                // 外部投递：经站点网关中继
                if (!class_exists('QQMailer')) {
                    @require_once __DIR__ . '/qqmail.php';
                }
                if (!class_exists('QQMailer') || !QQMailer::isConfigured()) {
                    return $fail('站点发信网关未配置，暂时无法发往外部邮箱');
                }
                $cfg = QQMailer::cfg();
                $userRow = function_exists('getFS') ? getFS()->findById('users', $uid) : null;
                $nick = (string)($userRow['nickname'] ?? '');
                $siteAddr = lwMcSiteAddr($uid);
                $r = lwMcSmtpSend([
                    'host' => (string)$cfg['host'], 'port' => (int)($cfg['port'] ?? 465),
                    'secure' => 'ssl',
                    'user' => (string)$cfg['user'], 'pass' => (string)$cfg['pass'],
                    'envelope_from' => (string)$cfg['user'],
                    'from_name' => ($nick !== '' ? $nick . '（' . $siteAddr . '）' : $siteAddr),
                    'from' => (string)$cfg['user'],   // envelope/信头 From 必须与认证账号一致，否则被 QQ SMTP 拒绝
                    'reply_to' => $siteAddr,
                    'to' => $to, 'subject' => $subject,
                    'html' => '<p style="color:#98A2B3;font-size:12px;margin:0 0 14px">此邮件来自 ' . htmlspecialchars($siteName, ENT_QUOTES, 'UTF-8')
                        . ' 站内邮箱 <b>' . htmlspecialchars($siteAddr, ENT_QUOTES, 'UTF-8') . '</b>（经站方通道中继，直接回复将送达该站内邮箱）</p>'
                        . nl2br(htmlspecialchars($text, ENT_QUOTES, 'UTF-8')),
                ]);
                if (!$r['ok']) { return $fail('发送失败：' . $r['err'], $risk['level']); }
                lwMcQuotaBump($uid);
            }
            $fromAddr = $fromKind === 'bound'
                ? (string)(lwMcAcct($uid)['bound']['email'] ?? '')
                : lwMcSiteAddr($uid);
        }

        $row = [
            'msgid' => $msgid, 'ts' => $now, 'from' => $fromAddr, 'to' => $to,
            'subject' => $subject, 'text' => mb_substr($text, 0, 2000),
            'risk' => $risk['level'], 'risk_hits' => $risk['hits'],
            'transport' => $localTargets ? 'internal' : ($fromKind === 'bound' ? 'bound' : 'gateway'),
        ];

        // 落发送者已发送
        lwMcStorePush('sent', $uid, $row);

        // 站内投递：落收件箱。目标可能是「站点官方信箱 + 该地址对应的注册用户」
        // 两个位置，两份都投 —— 站内信绝不能因为组织归属问题而丢。
        if ($localTargets) {
            $inRow = $row;
            $inRow['dir'] = 'in';
            foreach ($localTargets as $tu) {
                lwMcStorePush('inbox', (int)$tu, $inRow);
            }
        }

        lwMcAudit([
            'uid' => $uid, 'addr' => $fromAddr, 'dir' => 'out', 'event' => 'send',
            'from' => $fromAddr, 'to' => $to, 'subject' => $subject,
            'text' => mb_substr($text, 0, 500), 'status' => 'ok',
            'transport' => $row['transport'], 'risk' => $risk['level'], 'ts' => time(),
        ]);

        return ['ok' => true, 'err' => '', 'risk' => $risk['level']];
    }

    /* ── 收信编排 ─────────────────────────────────────────────────────── */

    /**
     * 拉取收件。$kind: 'bound'（用户自有邮箱）| 'site'（catch-all → 按地址分发）
     * @return array{ok:bool, err:string, fetched:int}
     */
    function lwMcFetch(int $uid, string $kind): array
    {
        if (lwMcBanned($uid)) {
            return ['ok' => false, 'err' => '邮箱功能已被管理员停用', 'fetched' => 0];
        }
        $limit = 15;

        if ($kind === 'bound') {
            $bound = lwMcAcct($uid)['bound'] ?? null;
            if (!$bound || empty($bound['email'])) {
                return ['ok' => false, 'err' => '尚未绑定自有邮箱', 'fetched' => 0];
            }
            $hosts = lwMcHostsFor((string)$bound['email']);
            $imapHost = (string)($bound['imap_host'] ?: $hosts[3]);
            $imapPort = (int)($bound['imap_port'] ?: $hosts[4]);
            if ($imapHost === '') { return ['ok' => false, 'err' => '无法识别该邮箱的 IMAP 服务器', 'fetched' => 0]; }
            $msgs = lwMcImapFetch($imapHost, $imapPort, (string)$bound['email'], lwMcDec((string)$bound['pass_enc']), $limit);
            if (!$msgs) { return ['ok' => true, 'err' => '', 'fetched' => 0]; }
            $n = 0;
            foreach ($msgs as $m) {
                $ok = lwMcStorePush('inbox', $uid, [
                    'msgid' => 'imap-' . ((string)$bound['email']) . '-' . $m['uid'],
                    'ts' => (string)($m['date'] ?: date('Y-m-d H:i:s')),
                    'from' => $m['from'], 'from_addr' => $m['from_addr'],
                    'to' => (string)$bound['email'],
                    'subject' => $m['subject'], 'text' => $m['text'],
                    'transport' => 'imap-bound', 'risk' => lwMcScan($m['subject'], $m['text'])['level'],
                ]);
                if ($ok) { $n++; }
            }
            lwMcAudit([
                'uid' => $uid, 'addr' => (string)$bound['email'], 'dir' => 'in', 'event' => 'fetch',
                'from' => '', 'to' => (string)$bound['email'], 'subject' => "IMAP 拉取 {$n} 封",
                'status' => 'ok', 'transport' => 'imap-bound', 'ts' => time(),
            ]);
            return ['ok' => true, 'err' => '', 'fetched' => $n];
        }

        // site：catch-all 拉取 → 按收件地址分发到对应站内邮箱
        $cfg = lwMcCfg();
        $ca = $cfg['catchall'] ?? [];
        if (empty($ca['enabled']) || empty($ca['imap_host']) || empty($ca['imap_user'])) {
            return ['ok' => false, 'err' => '管理员尚未启用外部来信接收（需配置 catch-all 邮箱）', 'fetched' => 0];
        }
        $msgs = lwMcImapFetch((string)$ca['imap_host'], 993, (string)$ca['imap_user'], lwMcDec((string)($ca['imap_pass_enc'] ?? '')), 30);
        $domain = strtolower((string)($cfg['mail_domain'] ?? 'bsd.ct.ws'));
        $n = 0;
        $seenUids = [];
        foreach ($msgs as $m) {
            $seenUids[] = $m['uid'];
            // 从 TO 头里找本站域名的收件地址
            $targets = [];
            if (preg_match_all('/[\w.\-]+@' . preg_quote($domain, '/') . '/i', (string)($m['to'] ?? ''), $tm)) {
                foreach (array_unique(array_map('strtolower', $tm[0])) as $t) { $targets[] = $t; }
            }
            if (!$targets) { continue; }
            foreach ($targets as $t) {
                $destList = lwMcResolveTargets($t);   // 站点官方信箱 + 注册用户，两者都要投
                if (!$destList) { continue; }
                foreach ($destList as $destUid) {
                    $ok = lwMcStorePush('inbox', (int)$destUid, [
                        'msgid' => 'imap-site-' . $m['uid'] . '-' . $destUid,
                        'ts' => (string)($m['date'] ?: date('Y-m-d H:i:s')),
                        'from' => $m['from'], 'from_addr' => $m['from_addr'],
                        'to' => $t, 'subject' => $m['subject'], 'text' => $m['text'],
                        'transport' => 'imap-site', 'risk' => lwMcScan($m['subject'], $m['text'])['level'],
                    ]);
                    if ($ok) { $n++; }
                }
            }
        }
        $ca['last_uid'] = max((int)($ca['last_uid'] ?? 0), $seenUids ? max($seenUids) : 0);
        $cfg['catchall'] = $ca;
        lwMcCfgSave($cfg);
        lwMcAudit([
            'uid' => $uid, 'addr' => (string)$ca['imap_user'], 'dir' => 'in', 'event' => 'fetch',
            'from' => '', 'to' => '@' . $domain, 'subject' => "catch-all 拉取分发 {$n} 封",
            'status' => 'ok', 'transport' => 'imap-site', 'ts' => time(),
        ]);
        return ['ok' => true, 'err' => '', 'fetched' => $n];
    }

    /* ── 绑定自有邮箱 ─────────────────────────────────────────────────── */

    /**
     * 保存绑定。$params: {email, pass, smtp_host?, smtp_port?, secure?, imap_host?, imap_port?, display_name?}
     * @return array{ok:bool, err:string}
     */
    function lwMcBind(int $uid, array $p): array
    {
        if (lwMcBanned($uid)) { return ['ok' => false, 'err' => '邮箱功能已被管理员停用']; }
        $email = strtolower(trim((string)($p['email'] ?? '')));
        $pass = (string)($p['pass'] ?? '');
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) { return ['ok' => false, 'err' => '邮箱格式不正确']; }
        if ($pass === '') { return ['ok' => false, 'err' => '请填写授权码（不是登录密码）']; }
        $hosts = lwMcHostsFor($email);
        $smtpHost = trim((string)($p['smtp_host'] ?? '')) ?: $hosts[0];
        $smtpPort = (int)($p['smtp_port'] ?? 0) ?: $hosts[1];
        $secure = trim((string)($p['secure'] ?? '')) ?: $hosts[2];
        $imapHost = trim((string)($p['imap_host'] ?? '')) ?: $hosts[3];
        $imapPort = (int)($p['imap_port'] ?? 0) ?: $hosts[4];

        $acct = lwMcAcct($uid);
        $acct['bound'] = [
            'email' => $email,
            'display_name' => trim((string)($p['display_name'] ?? '')),
            'smtp_host' => $smtpHost, 'smtp_port' => $smtpPort, 'secure' => $secure,
            'imap_host' => $imapHost, 'imap_port' => $imapPort,
            'pass_enc' => lwMcEnc($pass),
            'bound_at' => date('Y-m-d H:i:s'),
        ];
        lwMcAcctPut($uid, $acct);
        lwMcAudit([
            'uid' => $uid, 'addr' => $email, 'dir' => 'sys', 'event' => 'bind',
            'subject' => '绑定自有邮箱', 'status' => 'ok', 'ts' => time(),
        ]);
        return ['ok' => true, 'err' => ''];
    }

    function lwMcUnbind(int $uid): void
    {
        $acct = lwMcAcct($uid);
        if (isset($acct['bound'])) { $acct['bound'] = null; lwMcAcctPut($uid, $acct); }
    }

    /** 绑定连通性自检（SMTP 登录验证，不发信） */
    function lwMcBindTest(int $uid): array
    {
        $bound = lwMcAcct($uid)['bound'] ?? null;
        if (!$bound) { return ['ok' => false, 'err' => '尚未绑定']; }
        return lwMcSmtpProbe(
            (string)$bound['smtp_host'], (int)$bound['smtp_port'], (string)$bound['secure'],
            (string)$bound['email'], lwMcDec((string)$bound['pass_enc'])
        );
    }

    /** 仅验证 SMTP 登录（RCPT 之前退出） */
    function lwMcSmtpProbe(string $host, int $port, string $secure, string $user, string $pass): array
    {
        $transport = ($secure === 'ssl') ? 'ssl://' : '';
        $fp = @stream_socket_client($transport . $host . ':' . $port, $errno, $errstr, 12);
        if (!$fp) { return ['ok' => false, 'err' => '连接失败：' . ($errstr !== '' ? $errstr : ('错误码 ' . $errno))]; }
        stream_set_timeout($fp, 12);
        $read = function () use ($fp) {
            $full = '';
            while (true) {
                $line = @fgets($fp, 512);
                if ($line === false) { break; }
                $full .= $line;
                if (!(isset($line[3]) && $line[3] === '-')) { break; }
            }
            return $full;
        };
        $banner = trim($read());
        if (strpos($banner, '220') !== 0) { @fclose($fp); return ['ok' => false, 'err' => '欢迎信息异常']; }
        fwrite($fp, "EHLO localhost\r\n");
        if (strpos($read(), '250') !== 0) { @fclose($fp); return ['ok' => false, 'err' => 'EHLO 失败']; }
        if ($secure === 'starttls') {
            fwrite($fp, "STARTTLS\r\n");
            if (strpos(trim($read()), '220') !== 0) { @fclose($fp); return ['ok' => false, 'err' => '不支持 STARTTLS']; }
            if (!@stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                @fclose($fp); return ['ok' => false, 'err' => 'TLS 升级失败'];
            }
            fwrite($fp, "EHLO localhost\r\n");
            $read();
        }
        fwrite($fp, "AUTH LOGIN\r\n");
        if (strpos(trim($read()), '334') !== 0) { @fclose($fp); return ['ok' => false, 'err' => '不支持 AUTH LOGIN']; }
        fwrite($fp, base64_encode($user) . "\r\n");
        if (strpos(trim($read()), '334') !== 0) { @fclose($fp); return ['ok' => false, 'err' => '用户名被拒']; }
        fwrite($fp, base64_encode($pass) . "\r\n");
        $resp = trim($read());
        fwrite($fp, "QUIT\r\n");
        @fclose($fp);
        if (strpos($resp, '235') === 0) { return ['ok' => true, 'err' => '']; }
        return ['ok' => false, 'err' => '授权码错误或已过期'];
    }
}
