<?php
/**
 * QQ 扫码登录（用于自动获取空间 Cookie）
 * ---------------------------------------------------------------------------
 * 背景：QQ 空间必须登录才能读，Cookie 会过期。站长希望「扫一下就自动配好」，
 *       不用每次去 F12 里复制 Cookie。
 *
 * 协议链（腾讯网页登录的公开流程，纯服务端实现）：
 *   1) GET  ssl.ptlogin2.qq.com/ptqrshow   → 返回二维码 PNG，并下发 qrsig Cookie
 *   2) ptqrtoken = hash33(qrsig)
 *   3) 轮询 ssl.ptlogin2.qq.com/ptqrlogin  → ptuiCB('66' 待扫 / '67' 已扫待确认 /
 *                                              '0' 成功 / '65' 已失效)
 *   4) 成功时 ptuiCB 第 3 个参数带 check_sig 地址（含 ptsigx）
 *   5) 跟着它走完重定向，最后访问 user.qzone.qq.com/<uin>，
 *      从 Set-Cookie 里收集 uin / skey / p_skey，加密存进配置
 *
 * 现实预期（必须说清楚）：
 *   · 腾讯这套流程**经常调整**，且会对异常登录弹二次验证（短信/设备锁）；
 *   · 服务端 IP 与站长常用设备不同，风控概率不低；
 *   · 所以扫码是「锦上添花」，**手动粘贴 Cookie 必须始终保留**，扫码失败直接回退。
 */

if (!function_exists('lwQzoneHash33')) {
    /** QQ 的 hash33：e = ((e << 5) + e + c) & 0x7fffffff */
    function lwQzoneHash33(string $s): int
    {
        $e = 0;
        $len = strlen($s);
        for ($i = 0; $i < $len; $i++) {
            $e = (($e << 5) + $e + ord($s[$i])) & 0x7fffffff;
        }
        return $e;
    }
}

if (!function_exists('lwQzoneQrSession')) {
    function lwQzoneQrSession(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            @session_start();
        }
    }
}

if (!function_exists('lwQzoneQrStart')) {
    /**
     * 取一张新二维码。返回 ['ok'=>bool,'png'=>string(base64),'err'=>string]
     */
    function lwQzoneQrStart(): array
    {
        $appid = '549000912';
        $url = 'https://ssl.ptlogin2.qq.com/ptqrshow'
             . '?appid=' . $appid . '&e=2&l=M&s=3&d=72&v=4&t=' . (microtime(true) * 1000)
             . '&daid=5&pt_3rd_aid=0&u1=https%3A%2F%2Fqzone.qq.com%2Fwebpush%3Frdid%3D1';

        $ch = curl_init($url);
        $headers = [];
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_CONNECTTIMEOUT => 6,
            CURLOPT_REFERER        => 'https://qzone.qq.com/',
            CURLOPT_HTTPHEADER     => [
                'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36',
                'Accept: image/*,*/*;q=0.8',
            ],
            CURLOPT_HEADERFUNCTION => function ($ch, $line) use (&$headers) {
                $p = strpos($line, ':');
                if ($p !== false) {
                    $headers[strtolower(trim(substr($line, 0, $p)))] = trim(substr($line, $p + 1));
                }
                return strlen($line);
            },
        ]);
        $png = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = (string) curl_error($ch);
        curl_close($ch);

        /* 二维码 PNG 很小（实测 435 字节）—— 阈值别按普通图片设成几百 KB，
         * 只要保证是合法 PNG 头且不是空响应即可。 */
        if (!is_string($png) || $code !== 200 || strlen($png) < 64 || strncmp($png, "\x89PNG", 4) !== 0) {
            return ['ok' => false, 'png' => '', 'err' => '取二维码失败：HTTP ' . $code . ' ' . $err];
        }

        /* qrsig 可能出现在多次 Set-Cookie 里，取最后一个 */
        $qrsig = '';
        if (preg_match_all('/qrsig=([^;]+)/i', implode("\n", $headers), $m)) {
            $qrsig = trim(end($m[1]));
        }
        if ($qrsig === '') {
            return ['ok' => false, 'png' => '', 'err' => '取二维码成功但没拿到 qrsig（腾讯可能改了协议）'];
        }

        lwQzoneQrSession();
        $_SESSION['lw_qzone_qr'] = [
            'qrsig'    => $qrsig,
            'token'    => lwQzoneHash33($qrsig),
            'created'  => time(),
        ];
        return ['ok' => true, 'png' => base64_encode($png), 'err' => ''];
    }
}

if (!function_exists('lwQzoneQrPoll')) {
    /**
     * 轮询扫码状态。
     * 返回 ['ok'=>bool,'state'=>'waiting|scanned|success|expired|none|error','msg'=>string,'cookieSaved'=>bool]
     */
    function lwQzoneQrPoll(): array
    {
        lwQzoneQrSession();
        $st = $_SESSION['lw_qzone_qr'] ?? null;
        if (!is_array($st) || empty($st['qrsig'])) {
            return ['ok' => false, 'state' => 'none', 'msg' => '没有进行中的二维码，请先点「显示二维码」', 'cookieSaved' => false];
        }
        if (time() - (int) $st['created'] > 300) {
            unset($_SESSION['lw_qzone_qr']);
            return ['ok' => false, 'state' => 'expired', 'msg' => '二维码已超时（5 分钟），请刷新重试', 'cookieSaved' => false];
        }

        $qrsig = (string) $st['qrsig'];
        $appid = '549000912';
        $url = 'https://ssl.ptlogin2.qq.com/ptqrlogin'
             . '?u1=https%3A%2F%2Fqzone.qq.com%2Fwebpush%3Frdid%3D1'
             . '&ptqrtoken=' . (int) $st['token']
             . '&ptredirect=1&h=1&t=1&g=1&from_ui=1&ptlang=2052'
             . '&action=0-0-' . (int) (microtime(true) * 1000)
             . '&js_ver=10233&js_type=1&login_sig=&pt_uistyle=40&aid=' . $appid
             . '&daid=5&has_onekey=1&pt_3rd_aid=0';

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 12,
            CURLOPT_COOKIE         => 'qrsig=' . $qrsig,
            CURLOPT_REFERER        => 'https://qzone.qq.com/',
            CURLOPT_HTTPHEADER     => [
                'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36',
            ],
        ]);
        $body = curl_exec($ch);
        $err = (string) curl_error($ch);
        curl_close($ch);

        if (!is_string($body) || trim($body) === '') {
            return ['ok' => false, 'state' => 'error', 'msg' => '轮询失败：' . ($err ?: '空响应'), 'cookieSaved' => false];
        }
        /* ptuiCB('66','0','','0','二维码未失效。','') */
        if (!preg_match("/ptuiCB\('(\d+)'\s*,\s*'(\d+)'\s*,\s*'([^']*)'\s*,\s*'(\d+)'\s*,\s*'([^']*)'/", $body, $m)) {
            return ['ok' => false, 'state' => 'error', 'msg' => '无法解析轮询响应（腾讯可能改了协议）', 'cookieSaved' => false];
        }
        $cbCode  = (int) $m[1];
        $cbUrl   = (string) $m[3];
        $cbMsg   = (string) $m[5];

        switch ($cbCode) {
            case 66:
                return ['ok' => true, 'state' => 'waiting', 'msg' => '等待扫码…', 'cookieSaved' => false];
            case 67:
                return ['ok' => true, 'state' => 'scanned', 'msg' => '已扫码，请在手机上点「允许登录」', 'cookieSaved' => false];
            case 65:
                unset($_SESSION['lw_qzone_qr']);
                return ['ok' => false, 'state' => 'expired', 'msg' => '二维码已失效，请刷新', 'cookieSaved' => false];
            case 0:
                $ck = lwQzoneQrComplete($cbUrl);
                if ($ck['ok']) {
                    unset($_SESSION['lw_qzone_qr']);
                    return ['ok' => true, 'state' => 'success', 'msg' => '扫码成功，Cookie 已自动保存', 'cookieSaved' => true];
                }
                return ['ok' => false, 'state' => 'error', 'msg' => '扫码成功但取 Cookie 失败：' . $ck['err'], 'cookieSaved' => false];
            default:
                return ['ok' => false, 'state' => 'error', 'msg' => '未知状态：' . $cbMsg . '（code=' . $cbCode . '）', 'cookieSaved' => false];
        }
    }
}

if (!function_exists('lwQzoneReadJar')) {
    /** 读 Netscape 格式的 cookie jar（curl COOKIEJAR 产物），返回 name=>value */
    function lwQzoneReadJar(string $file): array
    {
        $out = [];
        if (!is_file($file)) { return $out; }
        foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            if ($line === '') { continue; }

            /* 【关键】curl 把 **HttpOnly** cookie 写成 `#HttpOnly_<domain>\t...` 开头。
             * 而 QQ 空间的 p_skey / skey 正是 HttpOnly —— 原来这行代码把所有 '#' 开头的行
             * 都当注释跳过，等于「登录成功也读不到 p_skey」，正是站长扫码后看到
             * 「登录链走完但没拿到 p_skey/skey」的直接原因。
             * 现在只跳过真正的注释（'# ' 或 '#'），HttpOnly 前缀剥掉后照常解析。 */
            if (substr($line, 0, 10) === '#HttpOnly_') {
                $line = substr($line, 10);
            } elseif ($line[0] === '#') {
                continue;
            }

            if (strpos($line, "\t") === false) { continue; }
            $parts = explode("\t", $line);
            if (count($parts) < 7) { continue; }
            $name = trim($parts[5]);
            $val  = trim($parts[6]);
            if ($name !== '' && $val !== '') { $out[$name] = $val; }
        }
        return $out;
    }
}

if (!function_exists('lwQzoneCookieFromJar')) {
    /**
     * 把 jar 解析结果拼成可直接用的 Cookie 头。
     *
     * 关键点：
     *   · uin 必须规范成 `o` + 10 位数字（QQ 认 `o1740443398` 这种形态）
     *   · **保留全部拿到的字段**（pt4_token / p_uin / media_p_skey 等），
     *     不只留 uin/skey/p_skey —— 点赞接口与部分页面校验会用到别的字段
     *   · 空值字段丢弃，总长限制 3000 字（Cookie 头过长会被网关截断）
     */
    function lwQzoneCookieFromJar(array $ck, string $uin): string
    {
        $uin = preg_replace('/D/', '', $uin);
        $parts = [];
        if ($uin !== '') {
            $parts[] = 'uin=o' . str_pad($uin, 10, '0', STR_PAD_LEFT);
        }
        $skip = ['uin'];
        foreach ($ck as $k => $v) {
            $k = (string) $k;
            $v = (string) $v;
            if ($k === '' || in_array($k, $skip, true)) { continue; }
            if ($v === '') { continue; }
            $parts[] = $k . '=' . $v;
            if (strlen(implode('; ', $parts)) > 3000) { break; }
        }
        return implode('; ', $parts);
    }
}

if (!function_exists('lwQzoneQrComplete')) {
    /**
     * 扫码成功后走完登录链，收集 uin / skey / p_skey 并写入配置。
     *
     * 【为什么重写这一版】
     *   上一版用「手工解析 Set-Cookie + 手工拼 Cookie 头」跟进重定向，
     *   站长实测扫码后失败：登录链走完但拿不到 p_skey/skey。
     *   原因很可能是：
     *     · QQ 的 Set-Cookie 带 Domain/Path（.qq.com / .qzone.qq.com），
     *       手工按名字拼回去，域作用域就错了；
     *     · check_sig 之后的重定向链里有的环节靠 JS/meta 跳转，
     *       手工读 Location 会断在半路，p_skey 是在**最后访问 qzone 首页**时下发。
     *   现在交给 curl 的 COOKIEFILE/COOKIEJAR 处理域名与重定向，
     *   再显式访问一次 user.qzone.qq.com/<uin> 让 p_skey 落地。
     */
    function lwQzoneQrComplete(string $cbUrl): array
    {
        if ($cbUrl === '' || stripos($cbUrl, 'http') !== 0) {
            return ['ok' => false, 'err' => '回调地址无效'];
        }
        /* uin：优先从回调地址的参数里取（o1740443398 / 1740443398 都要能认） */
        $uin = '';
        if (preg_match('/[?&]uin=([^&]+)/', $cbUrl, $m)) {
            $uin = preg_replace('/\D/', '', urldecode($m[1]));
        }
        if ($uin === '') {
            return ['ok' => false, 'err' => '回调地址里没有 uin，无法确定登录账号'];
        }

        $jar = tempnam(sys_get_temp_dir(), 'qzjar');
        if ($jar === false) {
            return ['ok' => false, 'err' => '无法创建临时 cookie 文件'];
        }
        $fetch = function (string $url) use ($jar): array {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS      => 10,
                CURLOPT_TIMEOUT        => 20,
                CURLOPT_CONNECTTIMEOUT => 8,
                CURLOPT_COOKIEFILE     => $jar,
                CURLOPT_COOKIEJAR      => $jar,
                CURLOPT_REFERER        => 'https://qzone.qq.com/',
                CURLOPT_HTTPHEADER     => [
                    'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36',
                ],
            ]);
            $body = curl_exec($ch);
            $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $eff  = (string) curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
            curl_close($ch);
            return ['code' => $code, 'url' => $eff, 'body' => is_string($body) ? $body : ''];
        };

        $r1 = $fetch($cbUrl);
        /* 显式访问空间首页：p_skey 通常在这里下发 */
        $r2 = $fetch('https://user.qzone.qq.com/' . $uin);
        /* 再补一次 qzone 主域，覆盖 .qzone.qq.com 作用域 */
        $r3 = $fetch('https://qzone.qq.com/');

        $ck = lwQzoneReadJar($jar);
        @unlink($jar);

        $pSkey = (string) ($ck['p_skey'] ?? '');
        $skey  = (string) ($ck['skey'] ?? '');
        /* 只列「确实拿到了值」的 cookie —— 原来把键名列出来，
           会让站长以为「明明有 p_skey 却说没拿到」，误导排查方向。 */
        $names = array_keys($ck);
        $nameStr = $names ? implode(', ', array_slice($names, 0, 12)) : '（无）';

        if ($pSkey === '' || $skey === '') {
            return [
                'ok' => false,
                'err' => '登录链走完但没拿到 p_skey/skey（可能被风控拦截）。'
                       . '本次拿到的 cookie 名：' . $nameStr . '。'
                       . '可直接用手动粘贴 Cookie 的方式（更稳）。',
            ];
        }
        /* 用全部拿到的 cookie 字段拼（不只三个），提高后续接口兼容性 */
        $cookie = lwQzoneCookieFromJar($ck, $uin);
        lwQzoneSetCookie($cookie);
        lwQzoneSaveConfig(['uin' => $uin, 'cookie_updated_at' => time(), 'last_state' => 'idle', 'last_error' => '']);
        return ['ok' => true, 'err' => ''];
    }
}
