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

if (!function_exists('lwQzoneQrComplete')) {
    /**
     * 扫码成功后走完登录链，收集 uin / skey / p_skey 并写入配置。
     * $cbUrl 是 ptuiCB 第 3 个参数（check_sig 地址，含 ptsigx）。
     */
    function lwQzoneQrComplete(string $cbUrl): array
    {
        if ($cbUrl === '' || stripos($cbUrl, 'http') !== 0) {
            return ['ok' => false, 'err' => '回调地址无效'];
        }
        $jar = [];
        $fetch = function (string $u) use (&$jar): string {
            $setc = [];
            $ch = curl_init($u);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 15,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_COOKIE         => implode('; ', $jar),
                CURLOPT_REFERER        => 'https://qzone.qq.com/',
                CURLOPT_HTTPHEADER     => [
                    'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36',
                ],
                CURLOPT_HEADERFUNCTION => function ($ch, $line) use (&$setc) {
                    if (stripos($line, 'set-cookie:') === 0) {
                        $setc[] = trim(substr($line, 11));
                    }
                    return strlen($line);
                },
            ]);
            $body = curl_exec($ch);
            $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $loc  = (string) curl_getinfo($ch, CURLINFO_REDIRECT_URL);
            curl_close($ch);
            foreach ($setc as $c) {
                if (preg_match('/^([^=]+)=([^;]*)/', $c, $mm)) {
                    $jar[trim($mm[1])] = trim($mm[2]);
                }
            }
            return $loc !== '' ? $loc : (is_string($body) ? $body : '');
        };

        /* 1) 走 check_sig（可能多级重定向） */
        $next = $cbUrl;
        for ($i = 0; $i < 6; $i++) {
            $r = $fetch($next);
            if (stripos($r, 'http') === 0) { $next = $r; continue; }
            break;
        }

        /* 2) 用 uin 访问空间首页，拿 p_skey */
        $uin = '';
        foreach (['uin', 'p_uin'] as $k) {
            if (!empty($jar[$k])) {
                $uin = preg_replace('/\D/', '', (string) $jar[$k]);
                if ($uin !== '') { break; }
            }
        }
        if ($uin === '') {
            /* 从回调地址里兜底取 uin */
            if (preg_match('/[?&]uin=([^&]+)/', $cbUrl, $m)) {
                $uin = preg_replace('/\D/', '', urldecode($m[1]));
            }
        }
        if ($uin === '') {
            return ['ok' => false, 'err' => '没能确定登录的 QQ 号'];
        }
        $fetch('https://user.qzone.qq.com/' . $uin);

        $pSkey = (string) ($jar['p_skey'] ?? '');
        $skey  = (string) ($jar['skey'] ?? '');
        if ($pSkey === '' || $skey === '') {
            return ['ok' => false, 'err' => '登录链走完但没拿到 p_skey/skey（可能被风控拦截）'];
        }
        $cookie = 'uin=o' . str_pad($uin, 10, '0', STR_PAD_LEFT) . '; skey=' . $skey . '; p_skey=' . $pSkey;
        lwQzoneSetCookie($cookie);
        lwQzoneSaveConfig(['uin' => $uin, 'cookie_updated_at' => time(), 'last_state' => 'idle', 'last_error' => '']);
        return ['ok' => true, 'err' => ''];
    }
}
