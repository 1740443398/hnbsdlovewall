<?php
/**
 * QQ 空间同步引擎
 * ---------------------------------------------------------------------------
 * 用途：把某个 QQ 号（例如班级表白墙机器人 3893174881）的空间说说镜像到本站，
 *      连同点赞数、评论人和评论内容一起展示。
 *
 * 三条硬约束（决定了下面的实现为什么这样写）：
 *
 *  1) **QQ 空间必须登录**。实测：个人空间主页 302 跳 i.qq.com；
 *     emotion_cgi_msglist_v6 无 cookie 时返回 {"code":-3000,"message":"请先登录空间"}。
 *     所以唯一可行的路径是**保存管理员自己的登录 Cookie**（p_skey / skey / uin），
 *     由站长在后台粘贴（或后续做扫码获取）。Cookie 落盘加密，接口一律脱敏。
 *
 *  2) **只读镜像，不反向写入 QQ**。
 *     站内用户的点赞/评论**不会**回写对方空间：回写等于用站长账号去点赞评论，
 *     既有风控风险（可能触发验证/冻结），语义上也不对（站内 50 个人点赞不该变成
 *     站长一人点了 50 次）。镜像展示是唯一稳妥的语义。
 *
 *  3) **失败必须能定位**。Cookie 过期是最常见的故障，
 *     引擎会把它写成明确状态（cookie_expired），后台直接显示「请重新粘贴 Cookie」，
 *     而不是让同步静默停摆。
 *
 * 数据表：
 *   qzone_items     —— 镜像的说说（tid 为对方空间里的唯一 id，用来去重）
 *   qzone_comments  —— 镜像的评论（item_tid + 评论 tid 去重）
 *   settings.qzone_sync —— 配置（含加密后的 Cookie）
 */

if (!function_exists('lwQzoneDefaults')) {
    /** 默认配置。所有选项后台可改，不写死在逻辑里。 */
    function lwQzoneDefaults(): array
    {
        return [
            'enabled'        => 0,      // 总开关
            'uin'            => '',     // 对方 QQ 号
            'page_size'      => 20,     // 每次拉的说说条数
            'max_items'      => 200,    // 本地最多保留多少条
            'sync_comments'  => 1,      // 1=同步评论内容（昵称+内容，只读）
            'comments_per'   => 30,     // 每条说说取多少评论
            'sync_likes'     => 1,      // 1=同步点赞数（只读展示）
            'show_home'      => 0,      // 1=把最新几条也混进首页
            /* 混进首页的条数：-1 = 全部，0 = 不在首页显示，1..100 = 指定条数 */
            'home_count'     => -1,
            'auto_sync'      => 1,      // 1=允许访问触发自动同步
            'sync_interval'  => 86400,  // 自动同步最小间隔（秒）：默认 24 小时
            /* 失败告警：同步失败或 Cookie 失效时给站长发邮件。
             * 冷却时间是为了防止「Cookie 一直失效 + 有人不停访问」把信箱刷爆。 */
            /* 图片：抓下来存本地并按宽度压缩，避免每次回源 QQ（版权/防盗链/流量三重考虑） */
            'image_max_width' => 1080,  // 压缩后最大宽度（px）
            'image_quality'   => 82,    // JPEG 质量 60-92
            'prefetch_per_run'=> 8,     // 每次同步最多预热多少张图（避免单次请求太久）
            /* OCR：站长要求「线上自动识别」，所以走云端 API（OCR.space 免费档支持中文） */
            'ocr_enabled'     => 0,     // 是否自动识别图片文字（默认关：站长反馈效果一般，代码保留可随时开）
            'ocr_api_key'     => 'helloworld', // OCR.space 的 key；'helloworld' 是官方公开测试 key，建议换成自己免费申请的
            'ocr_per_run'     => 3,     // 每次同步最多识别几张图
            'notify_email'    => '',
            'notify_on_error' => 1,
            'notify_cooldown' => 21600, // 同一类失败最多每 6 小时提醒一次
            'last_notify_at'  => 0,
            'last_notify_kind'=> '',
            'last_sync_at'   => 0,      // 上次成功同步时间戳
            'last_attempt_at'=> 0,
            'last_error'     => '',     // 最近一次错误
            'last_state'     => 'idle', // idle / ok / cookie_expired / error
            'last_count'     => 0,      // 上次同步新增条数
            'last_images'    => 0,      // 上次同步预热的图片数
            'last_ocr'       => 0,      // 上次同步自动识别的图片数
            'cookie_updated_at' => 0,
        ];
    }
}

if (!function_exists('lwQzoneConfig')) {
    function lwQzoneConfig(): array
    {
        $raw = function_exists('getSetting') ? (string) getSetting('qzone_sync', '') : '';
        $cfg = $raw !== '' ? json_decode($raw, true) : null;
        if (!is_array($cfg)) {
            $cfg = [];
        }
        return array_merge(lwQzoneDefaults(), $cfg);
    }
}

if (!function_exists('lwQzoneSaveConfig')) {
    function lwQzoneSaveConfig(array $patch): array
    {
        $cfg = array_merge(lwQzoneConfig(), $patch);
        if (function_exists('updateSetting')) {
            updateSetting('qzone_sync', json_encode($cfg, JSON_UNESCAPED_UNICODE));
        }
        return $cfg;
    }
}

if (!function_exists('lwQzoneSecret')) {
    /** 派生密钥：与 user_ai_config 同一套做法，但用独立盐，互不牵连。 */
    function lwQzoneSecret(): string
    {
        static $k = null;
        if ($k !== null) {
            return $k;
        }
        $seed = '';
        $f = dirname(__DIR__) . '/config/sync_config.php';
        if (is_file($f)) {
            $c = @include $f;
            if (is_array($c) && !empty($c['secret_key'])) {
                $seed = (string) $c['secret_key'];
            }
        }
        $k = hash('sha256', $seed . '|qzone_cookie|v1', true);
        return $k;
    }
}

if (!function_exists('lwQzoneEncrypt')) {
    function lwQzoneEncrypt(string $plain): string
    {
        if ($plain === '') {
            return '';
        }
        $iv = random_bytes(16);
        $c = openssl_encrypt($plain, 'aes-256-cbc', lwQzoneSecret(), OPENSSL_RAW_DATA, $iv);
        return $c === false ? '' : base64_encode($iv . $c);
    }
}

if (!function_exists('lwQzoneDecrypt')) {
    function lwQzoneDecrypt(string $blob): string
    {
        if ($blob === '') {
            return '';
        }
        $raw = base64_decode($blob, true);
        if ($raw === false || strlen($raw) <= 16) {
            return '';
        }
        $p = openssl_decrypt(substr($raw, 16), 'aes-256-cbc', lwQzoneSecret(), OPENSSL_RAW_DATA, substr($raw, 0, 16));
        return $p === false ? '' : $p;
    }
}

if (!function_exists('lwQzoneGetCookie')) {
    /** 取解密后的 Cookie 原文（仅供引擎使用，不要输出到页面）。 */
    function lwQzoneGetCookie(): string
    {
        $cfg = lwQzoneConfig();
        return lwQzoneDecrypt((string) ($cfg['cookie_enc'] ?? ''));
    }
}

if (!function_exists('lwQzoneNormalizeCookieInput')) {
    /**
     * 把用户粘贴的 Cookie 原文规范化成 `a=b; c=d` 形态。
     *
     * 站长/后台可能粘贴三种形态（实际粘贴过第 1 与第 3 种）：
     *   1) 'a=b; c=d'                 —— 纯 Cookie 串
     *   2) 'Cookie: a=b; c=d'         —— 浏览器 Network 里整行请求头
     *   3) '{"cookie": "a=b; c=d"}'   —— 插件/脚本导出的 JSON
     *
     * 原来只有 lwQzoneSetCookie() 做了规范化，lwQzoneCookieValue() 没有，
     * 于是「整行 Cookie:」形态下取 uin 会失败（首段键名被解析成 "Cookie: uin"）。
     * 现在两处共用这一个函数，行为一致。函数是幂等的，重复调用不会改坏。
     */
    function lwQzoneNormalizeCookieInput(string $raw): string
    {
        $raw = trim($raw);
        if ($raw !== '' && ($raw[0] === '{' || $raw[0] === '[')) {
            $j = json_decode($raw, true);
            if (is_array($j)) {
                $raw = (string) ($j['cookie'] ?? ($j['Cookie'] ?? ($j['value'] ?? '')));
            }
        }
        $raw = trim($raw, " \t\n\r\"\'");
        if (stripos($raw, 'cookie:') === 0) {
            $raw = trim(substr($raw, 7));
        }
        return $raw;
    }
}

if (!function_exists('lwQzoneSetCookie')) {
    function lwQzoneSetCookie(string $raw): void
    {
        /* 三种粘贴形态（纯串 / 整行 Cookie: / JSON {"cookie":...}）统一在这里规范化 */
        $raw = lwQzoneNormalizeCookieInput($raw);
        lwQzoneSaveConfig([
            'cookie_enc' => $raw === '' ? '' : lwQzoneEncrypt($raw),
            'cookie_updated_at' => $raw === '' ? 0 : time(),
            'last_state' => $raw === '' ? 'idle' : lwQzoneConfig()['last_state'],
        ]);
    }
}

if (!function_exists('lwQzoneCookieHint')) {
    /** 脱敏展示：判断关键字段是否存在，不返回任何值。 */
    function lwQzoneCookieHint(): array
    {
        $raw = lwQzoneGetCookie();
        $has = function (string $k) use ($raw) { return preg_match('/(?:^|;\s*)' . preg_quote($k, '/') . '=/', $raw) === 1; };
        return [
            'present' => $raw !== '',
            'len'     => strlen($raw),
            'p_skey'  => $has('p_skey'),
            'skey'    => $has('skey'),
            'uin'     => $has('uin') || $has('p_uin'),
            'updated' => (int) lwQzoneConfig()['cookie_updated_at'],
        ];
    }
}

if (!function_exists('lwQzoneGtk')) {
    /** QQ 接口的 g_tk：由 p_skey 算出（5381 循环 + 0x7fffffff）。 */
    function lwQzoneGtk(string $pSkey): int
    {
        /* 标准算法是「h = h*33 + c」，累积到 int32 后取 & 0x7fffffff。
         * 但 PHP 的 int 是 64 位：p_skey 有 32 个字符时 $h 会先溢出成 float，
         * 后续 += 就在浮点上做，精度丢失（还会触发
         * "Implicit conversion from float to int loses precision" 弃用警告）。
         * 所以每步都用 & 0xFFFFFFFF 做 32 位环绕，等价于 JS 的 int32 位运算，
         * 最后再 & 0x7fffffff —— 这才是各语言实现里通用的写法。 */
        $h = 5381;
        $len = strlen($pSkey);
        for ($i = 0; $i < $len; $i++) {
            $h = ($h * 33 + ord($pSkey[$i])) & 0xFFFFFFFF;
        }
        return $h & 0x7fffffff;
    }
}

if (!function_exists('lwQzoneCookieValue')) {
    function lwQzoneCookieValue(string $raw, string $key): string
    {
        /* 先规范化：避免调用方直接把「Cookie: 整行」或 JSON 形态传进来时取不到值 */
        $raw = lwQzoneNormalizeCookieInput($raw);
        if (preg_match('/(?:^|;\s*)' . preg_quote($key, '/') . '=([^;]*)/', $raw, $m)) {
            return trim($m[1]);
        }
        return '';
    }
}

if (!function_exists('lwQzoneHttp')) {
    /**
     * 统一出网：强制 https、带 UA/Referer、超时短。
     * 返回 ['ok'=>bool,'code'=>int,'body'=>string,'err'=>string]
     */
    function lwQzoneHttp(string $url, string $cookie, int $timeout = 12): array
    {
        if (!function_exists('curl_init')) {
            return ['ok' => false, 'code' => 0, 'body' => '', 'err' => 'curl 扩展不可用'];
        }
        /* 【自动续期】QQ 每次请求都可能通过 Set-Cookie 下发新的 skey / p_skey。
         * 原来这些响应头被直接丢弃，等于「每次都在用旧会话」——
         * 现在把它们抓下来，交给调用方合并回存储（见 lwQzoneAbsorbResponseCookies）。
         * 这是「一次扫码长期可用」最关键的一环：只要会话还活，密钥会被自动续上。 */
        $respCookies = [];
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_CONNECTTIMEOUT => 6,
            CURLOPT_ENCODING       => '',
            CURLOPT_HTTPHEADER     => [
                'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36',
                'Accept: */*',
                'Accept-Language: zh-CN,zh;q=0.9',
                'Referer: https://user.qzone.qq.com/',
            ],
            CURLOPT_COOKIE         => $cookie,
            CURLOPT_HEADERFUNCTION => function ($ch2, $header) use (&$respCookies) {
                if (stripos($header, 'set-cookie:') === 0) {
                    $raw = trim(substr($header, 11));
                    $pair = explode(';', $raw)[0] ?? '';
                    if (strpos($pair, '=') !== false) {
                        [$k, $v] = explode('=', $pair, 2);
                        $k = trim($k); $v = trim($v);
                        if ($k !== '') { $respCookies[$k] = $v; }
                    }
                }
                return strlen($header);
            },
        ]);
        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = (string) curl_error($ch);
        curl_close($ch);
        return ['ok' => $body !== false && $err === '', 'code' => $code, 'body' => is_string($body) ? $body : '', 'set_cookies' => $respCookies, 'err' => $err];
    }
}

if (!function_exists('lwQzoneAbsorbResponseCookies')) {
    /**
     * 把响应里下发的 Set-Cookie 合并进已保存的 Cookie（自动续期）。
     *
     * 【为什么这是「一次扫码长期可用」的关键】
     * skey / p_skey 是腾讯服务端签发的**会话密钥**，算法上不可能离线推算。
     * 但它们会随着正常请求不断被刷新下发 —— 只要会话还活着。
     * 原来这些响应头被丢弃，所以我们永远在用最初那份；一旦它过期就彻底失效。
     * 现在每次响应都把新值吸收回来，等于「用着用着就自动续期了」。
     *
     * 规则：
     *   · 只接受非空值（QQ 有时会下发空值表示清除，不能把有效值覆盖成空）
     *   · 其余字段原样保留（uin / p_uin / pt4_token / media_p_skey ...）
     *   · 有关键字段变化才写盘，避免每次请求都写配置
     */
    function lwQzoneAbsorbResponseCookies(array $pairs): int
    {
        if (!$pairs) { return 0; }
        $raw = lwQzoneGetCookie();
        if ($raw === '') { return 0; }
        $changed = 0;
        foreach ($pairs as $k => $v) {
            $k = trim((string) $k);
            $v = trim((string) $v);
            if ($k === '' || $v === '') { continue; }
            $cur = lwQzoneCookieValue($raw, $k);
            if ($cur === $v) { continue; }
            if ($cur === '') {
                $raw .= '; ' . $k . '=' . $v;
            } else {
                $raw = preg_replace('/(^|;\s*)' . preg_quote($k, '/') . '=[^;]*/',
                                    '$1' . $k . '=' . $v, $raw, 1);
            }
            $changed++;
        }
        if ($changed > 0) {
            lwQzoneSetCookie($raw);
            lwQzoneSaveConfig(['cookie_refreshed_at' => time()]);
        }
        return $changed;
    }
}

if (!function_exists('lwQzoneKeepAlive')) {
    /**
     * 保活：带着现有 Cookie 访问空间首页，让服务端续期会话并吸收新密钥。
     * 返回 ['ok'=>bool,'state'=>'ok|cookie_expired','refreshed'=>int,'err'=>string]
     */
    function lwQzoneKeepAlive(): array
    {
        $cfg = lwQzoneConfig();
        $uin = preg_replace('/\D/', '', (string) $cfg['uin']);
        $raw = lwQzoneGetCookie();
        if ($uin === '' || $raw === '') {
            return ['ok' => false, 'state' => 'cookie_expired', 'refreshed' => 0, 'err' => '未配置 Cookie'];
        }
        $refreshed = 0;
        $last = null;
        foreach (['https://user.qzone.qq.com/' . $uin, 'https://qzone.qq.com/'] as $url) {
            $r = lwQzoneHttp($url, lwQzoneGetCookie(), 15);
            $last = $r;
            if (!empty($r['set_cookies'])) {
                $refreshed += lwQzoneAbsorbResponseCookies($r['set_cookies']);
            }
        }
        /* 【必须用真实接口验证，不能只看字段在不在】
         * 实测：随便一份假的 skey/p_skey 也能通过「字段存在」检查，
         * 于是保活会误报成功，同步继续失败 —— 那就等于没自检。
         * 这里拉 1 条说说做探针：真正能读到数据才算活着。 */
        $probe = lwQzoneFetchFeeds(0, 1);
        $alive = !empty($probe['ok']) && !empty($probe['items']);
        $state = $alive ? 'ok' : ((string) ($probe['state'] ?? 'cookie_expired'));
        lwQzoneSaveConfig(['last_alive_at' => $alive ? time() : 0]);
        return [
            'ok'        => $alive,
            'state'     => $state,
            'refreshed' => $refreshed,
            'err'       => $alive ? '' : ('保活失败：' . ((string) ($probe['err'] ?? '')) ?: 'Cookie 已失效，需要重新扫码或粘贴'),
        ];
    }
}

if (!function_exists('lwQzoneFetchFeeds')) {
    /**
     * 拉一页说说。msglist_v6 返回 JSONP，这里剥掉外层回调。
     * 返回 ['ok'=>,'state'=>'ok|cookie_expired|error','items'=>[],'err'=>'']
     */
    function lwQzoneFetchFeeds(int $pos = 0, int $num = 20): array
    {
        $cfg   = lwQzoneConfig();
        $uin   = preg_replace('/\D/', '', (string) $cfg['uin']);
        $raw   = lwQzoneGetCookie();
        if ($uin === '' || $raw === '') {
            return ['ok' => false, 'state' => 'cookie_expired', 'items' => [], 'err' => '未配置 QQ 号或 Cookie'];
        }
        $pSkey = lwQzoneCookieValue($raw, 'p_skey');
        $gtk   = $pSkey !== '' ? lwQzoneGtk($pSkey) : 0;
        $replyNum = max(0, (int) $cfg['comments_per']);

        $url = 'https://user.qzone.qq.com/proxy/domain/taotao.qq.com/cgi-bin/emotion_cgi_msglist_v6'
             . '?uin=' . $uin . '&ftype=0&sort=0&pos=' . $pos . '&num=' . max(1, min(50, $num))
             . '&replynum=' . $replyNum . '&g_tk=' . $gtk . '&callback=_Callback&code_version=1&format=jsonp';

        $r = lwQzoneHttp($url, $raw);
        if (!$r['ok']) {
            return ['ok' => false, 'state' => 'error', 'items' => [], 'err' => '网络请求失败：' . $r['err']];
        }
        /* 每次拉取都顺手把服务端新下发的密钥吸收进来（会话保持活跃时自动续期） */
        if (!empty($r['set_cookies'])) {
            lwQzoneAbsorbResponseCookies($r['set_cookies']);
        }
        $body = trim($r['body']);
        /* 剥 JSONP 外壳：_Callback({...}) 或 callback({...}) */
        if (preg_match('/^[A-Za-z_$][\w$]*\((.*)\)\s*;?$/s', $body, $m)) {
            $body = $m[1];
        }
        $json = json_decode($body, true);
        if (!is_array($json)) {
            return ['ok' => false, 'state' => 'error', 'items' => [], 'err' => '返回不是合法 JSON（可能是风控页）'];
        }
        $code = isset($json['code']) ? (int) $json['code'] : -1;
        if ($code === -3000 || $code === 1003 || (isset($json['subcode']) && (int) $json['subcode'] === -1000001)) {
            return ['ok' => false, 'state' => 'cookie_expired', 'items' => [], 'err' => (string) ($json['message'] ?? '请先登录空间')];
        }
        if ($code !== 0 && $code !== -1) {
            return ['ok' => false, 'state' => 'error', 'items' => [], 'err' => (string) ($json['message'] ?? ('接口返回 code=' . $code))];
        }

        $list = $json['data']['list'] ?? ($json['msglist'] ?? ($json['list'] ?? []));
        if (!is_array($list)) {
            $list = [];
        }
        $norm = lwQzoneNormalizeList($list);
        /* 【分页必须靠 total，不能靠「返回数 < 请求数」】
         * 实测：请求 num=20，QQ 实际每次只返回 19 条，而 total=513。
         * 原来用 count < num 判定「没有下一页」，永远只拿到第一页 ——
         * 站长问「为什么只有几十条」的答案就在这里。 */
        $total = (int) ($json['total'] ?? 0);
        if ($total <= 0 && isset($json['msglist']) && is_array($json['msglist'])) {
            $total = (int) ($json['total'] ?? 0);
        }
        return [
            'ok'    => true,
            'state' => 'ok',
            'items' => $norm,
            'err'   => '',
            'total' => $total,
            'step'  => count($norm),   // 下一页应该用「实际返回条数」推进，而不是 num
        ];
    }
}


if (!function_exists('lwQzoneFetchLikesBatch')) {
    /**
     * 并发抓一批点赞数。返回 [tid => likes]。
     *
     * 为什么需要：单条串行请求在共享主机上实测整轮同步要 47 秒
     * （19 条动态 × 每次点赞请求 1~2s）。改成 curl_multi 后并发 6 路，
     * 总耗时降到 2~4 秒 —— 这直接决定「访问触发同步」时页面被拖多久。
     */
    function lwQzoneFetchLikesBatch(array $pairs, string $uin, string $cookie, int $gtk, int $concurrency = 6, int $budgetSec = 10): array
    {
        $out = [];
        if (!$pairs || $uin === '' || $cookie === '') { return $out; }
        $queue = [];
        foreach ($pairs as $p) {
            $tid = (string) ($p['tid'] ?? '');
            if ($tid === '') { continue; }
            $unikey = 'http://user.qzone.qq.com/' . $uin . '/mood/' . $tid;
            $queue[$tid] = 'https://user.qzone.qq.com/proxy/domain/r.qzone.qq.com/cgi-bin/user/qz_opcnt2'
                . '?unikey=' . urlencode($unikey) . '&g_tk=' . $gtk . '&callback=_Callback';
        }
        if (!$queue) { return $out; }
        $concurrency = max(1, min(8, $concurrency));

        $parse = function (string $body): int {
            $body = trim($body);
            if (preg_match('/^[A-Za-z_$][\w$]*\((.*)\)\s*;?$/s', $body, $m)) { $body = $m[1]; }
            $body = preg_replace('/([{,])\s*([A-Za-z_][A-Za-z0-9_]*)\s*:/', '$1"$2":', $body);
            $json = json_decode($body, true);
            if (is_array($json) && isset($json['data']) && is_array($json['data'])) {
                foreach ($json['data'] as $row) {
                    $cur = $row['current'] ?? null;
                    if (!is_array($cur)) { continue; }
                    $cnt = $cur['cntdata'] ?? [];
                    if (is_array($cnt) && isset($cnt['like'])) { return max(0, (int) $cnt['like']); }
                }
            }
            if (preg_match('/like:(\d+)/', $body, $mm)) { return max(0, (int) $mm[1]); }
            return 0;
        };

        /* 【主机兼容】部分共享主机（含本站部署环境）禁用了 curl_multi_* 整族函数，
           直接调用会 fatal「Call to undefined function curl_multi_exec()」——
           之前的表现就是后台点「立即同步」报同步异常。检测不到 multi 时退回串行抓取：
           慢一些，但功能完整，且同样受时间预算约束，不会拖死同步。
           注意：这个检测必须在任何 curl_multi_* 调用之前。 */
        if (!function_exists('curl_multi_init')) {
            $seqDeadline = microtime(true) + max(3, $budgetSec);
            foreach ($queue as $tid => $url) {
                if (microtime(true) >= $seqDeadline) { break; }
                $ch = curl_init($url);
                curl_setopt_array($ch, [
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_TIMEOUT        => 5,
                    CURLOPT_CONNECTTIMEOUT => 4,
                    CURLOPT_COOKIE         => $cookie,
                    CURLOPT_REFERER        => 'https://user.qzone.qq.com/',
                    CURLOPT_HTTPHEADER     => [
                        'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36',
                    ],
                ]);
                $body = (string) curl_exec($ch);
                $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
                curl_close($ch);
                if ($code === 200 && $body !== '') {
                    $like = $parse($body);
                    if ($like > 0) { $out[$tid] = $like; }
                }
            }
            return $out;
        }

        $mh = curl_multi_init();
        $handles = [];
        $start = function ($tid, $url) use ($mh, &$handles, $cookie) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 8,
                CURLOPT_CONNECTTIMEOUT => 5,
                CURLOPT_COOKIE         => $cookie,
                CURLOPT_REFERER        => 'https://user.qzone.qq.com/',
                CURLOPT_HTTPHEADER     => [
                    'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36',
                ],
            ]);
            curl_multi_add_handle($mh, $ch);
            $handles[(int) $ch] = ['h' => $ch, 'tid' => $tid];
        };

        $pending = $queue;
        $active = 0;
        foreach ($pending as $tid => $url) {
            $start($tid, $url);
            $active++;
            if ($active >= $concurrency) { break; }
        }
        $started = array_slice(array_keys($queue), 0, $active);
        $rest = array_slice(array_keys($queue), $active, null, true);

        /* 【硬性时间上限】实测线上主机访问 qz_opcnt2 会长时间挂住（6 条 > 90 秒没返回），
           会导致整轮同步被主机 60 秒上限杀掉、什么状态都存不下来。
           所以这里到点就放弃：宁可这批赞数下一轮再补，也不能拖死同步。 */
        $likeDeadline = microtime(true) + max(3, $budgetSec);

        do {
            if (microtime(true) >= $likeDeadline) { break; }
            $status = curl_multi_exec($mh, $running);
            if ($running) {
                if (curl_multi_select($mh, 0.3) === -1) { usleep(50000); }
            }
            while (($info = curl_multi_info_read($mh)) !== false) {
                $ch = $info['handle'];
                $key = (int) $ch;
                if (isset($handles[$key])) {
                    $tid = $handles[$key]['tid'];
                    $body = (string) curl_multi_getcontent($ch);
                    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
                    if ($code === 200 && $body !== '') {
                        $like = $parse($body);
                        if ($like > 0) { $out[$tid] = $like; }
                    }
                    curl_multi_remove_handle($mh, $ch);
                    curl_close($ch);
                    unset($handles[$key]);
                    if ($rest) {
                        $nextTid = array_shift($rest);
                        $start($nextTid, $queue[$nextTid]);
                    }
                }
            }
        } while (($running > 0 || $handles) && microtime(true) < $likeDeadline);

        foreach ($handles as $h) {
            curl_multi_remove_handle($mh, $h['h']);
            curl_close($h['h']);
        }
        curl_multi_close($mh);
        return $out;
    }
}

if (!function_exists('lwQzoneFetchLikes')) {
    /**
     * 查一条说说的点赞数。
     *
     * 为什么不在 msglist 里拿：
     *   实测对方接口的 msglist_v6 **完全不含点赞字段**（19 条里 like* 字段 0 个），
     *   点赞数要走独立的计数接口 qz_opcnt2。
     *
     * 为什么需要容错解析：
     *   这个接口返回的不是标准 JSON —— 键名没引号：
     *     {"ret":0,"data":[{current:{"key":"...","cntdata":{act:0,like:184,...}}}]}
     *   所以先给裸露的键名补引号，再 json_decode；失败就退回正则直接抽 like。
     */
    function lwQzoneFetchLikes(string $uin, string $tid, string $cookie, int $gtk): int
    {
        if ($uin === '' || $tid === '' || $cookie === '') { return 0; }
        $unikey = 'http://user.qzone.qq.com/' . $uin . '/mood/' . $tid;
        $url = 'https://user.qzone.qq.com/proxy/domain/r.qzone.qq.com/cgi-bin/user/qz_opcnt2'
             . '?unikey=' . urlencode($unikey) . '&g_tk=' . $gtk . '&callback=_Callback';
        $r = lwQzoneHttp($url, $cookie, 8);
        if (!$r['ok']) { return 0; }
        $body = trim($r['body']);
        if (preg_match('/^[A-Za-z_$][\w$]*\((.*)\)\s*;?$/s', $body, $m)) {
            $body = $m[1];
        }
        /* 补引号：{current: → {"current": ，只处理对象键名，不碰值里的冒号 */
        $body = preg_replace('/([{,])\s*([A-Za-z_][A-Za-z0-9_]*)\s*:/', '$1"$2":', $body);
        $json = json_decode($body, true);
        if (is_array($json) && isset($json['data']) && is_array($json['data'])) {
            foreach ($json['data'] as $row) {
                $cur = $row['current'] ?? null;
                if (!is_array($cur)) { continue; }
                if ((string) ($cur['key'] ?? '') !== $unikey) { continue; }
                $cnt = $cur['cntdata'] ?? [];
                if (is_array($cnt) && isset($cnt['like'])) {
                    return max(0, (int) $cnt['like']);
                }
            }
        }
        /* 兜底：直接从文本里抠 like */
        if (preg_match('/like:(\d+)/', $body, $mm)) {
            return max(0, (int) $mm[1]);
        }
        return 0;
    }
}

if (!function_exists('lwQzoneNormalizeList')) {
    /** 把对方接口的原始 list 规范化成本站字段；字段缺失一律容错。 */
    function lwQzoneNormalizeList(array $list): array
    {
        $out = [];
        foreach ($list as $it) {
            if (!is_array($it)) {
                continue;
            }
            $tid = (string) ($it['tid'] ?? ($it['id'] ?? ''));
            if ($tid === '') {
                continue;
            }
            /* 【图片：一张都不能丢】
             *
             * 原来只取 url1（QQ 返回的三档尺寸里最小的那档），
             * 而且早期是用 HTML 抓只拿到封面，所以实测 19 条说说只有 22 张图
             * （16 条各 1 张、3 条各 2 张）—— 站长反馈「基本每条都有图」。
             *
             * QQ 的 pic 项有两种形态，都要兼容：
             *   · 数组：['url1'=>小, 'url2'=>中, 'url3'=>大, ...]
             *   · 字符串：直接就是图片地址
             * 尺寸优先取 url3 → url2 → url1（大图清晰，后续我们自己再压缩）。 */
            $images = [];
            if (!empty($it['pic']) && is_array($it['pic'])) {
                foreach ($it['pic'] as $p) {
                    if (is_string($p)) {
                        $p = trim($p);
                        if ($p !== '') { $images[] = $p; }
                        continue;
                    }
                    if (!is_array($p)) {
                        continue;
                    }
                    $u = (string) ($p['url3'] ?? ($p['url2'] ?? ($p['url1'] ?? ($p['url'] ?? ''))));
                    if ($u === '') {
                        /* 少数形态把地址放在 pic[i]['url'] 里或直接是字符串列表 */
                        $u = (string) ($p['pic'] ?? '');
                    }
                    if ($u !== '') { $images[] = $u; }
                }
            }
            /* 有些说说（尤其长图/多图）图片在 pic2 里 */
            if (empty($images) && !empty($it['pic2']) && is_array($it['pic2'])) {
                foreach ($it['pic2'] as $p) {
                    if (is_string($p) && trim($p) !== '') { $images[] = trim($p); }
                    elseif (is_array($p)) {
                        $u = (string) ($p['url3'] ?? ($p['url2'] ?? ($p['url1'] ?? '')));
                        if ($u !== '') { $images[] = $u; }
                    }
                }
            }
            $images = array_values(array_unique($images));
            $comments = [];
            if (!empty($it['commentlist']) && is_array($it['commentlist'])) {
                foreach ($it['commentlist'] as $c) {
                    if (!is_array($c)) {
                        continue;
                    }
                    $cid = (string) ($c['tid'] ?? ($c['id'] ?? ''));
                    /* 评论里会夹 [em]e10087[/em] 这种 QQ 表情码。
                     * 直接显示是噪声，这里剥掉；表情本身信息量低，
                     * 保留文字比留下「[em]e401236[/em]」更像人话。 */
                    $ctext = trim((string) ($c['content'] ?? ''));
                    $ctext = trim(preg_replace('/\[em\]e\d+\[\/em\]/i', '', $ctext));
                    if ($cid === '' || $ctext === '') {
                        continue;
                    }
                    $comments[] = [
                        'tid'        => $cid,
                        'author'     => (string) ($c['name'] ?? ($c['nickname'] ?? '')),
                        'author_uin' => (string) ($c['uin'] ?? ''),
                        /* 评论人头像：QQ 接口可能给 icon/portrait，没有就按 uin 拼 QQ 头像 */
                        'author_avatar' => (string) ($c['icon'] ?? ($c['portrait'] ?? ($c['avatar'] ?? ''))),
                        'content'    => $ctext,
                        'created_at' => (int) ($c['createTime'] ?? ($c['create_time'] ?? 0)),
                    ];
                }
            }
            $out[] = [
                'tid'        => $tid,
                'content'    => trim((string) ($it['content'] ?? '')),
                'created_at' => (int) ($it['created_time'] ?? ($it['createTime'] ?? 0)),
                'likes'      => (int) ($it['likenum'] ?? 0),
                'comments'   => (int) ($it['cmtnum'] ?? 0),
                'images'     => $images,
                'comment_list' => $comments,
                'raw_title'  => (string) ($it['rt_con']['content'] ?? ''),
            ];
        }
        return $out;
    }
}

if (!function_exists('lwQzoneSync')) {
    /**
     * 执行一次同步。返回结果数组（同时写入状态，供后台展示）。
     * $opts: ['pages'=>1]
     */
    function lwQzoneSync(array $opts = []): array
    {
        $cfg = lwQzoneConfig();
        $fs  = getFS();
        $now = time();
        $pages = max(1, min(5, (int) ($opts['pages'] ?? 1)));
        $num   = max(1, min(50, (int) $cfg['page_size']));

        lwQzoneSaveConfig(['last_attempt_at' => $now]);

        /* 【整轮时间预算】线上单请求 60 秒上限：抓取/点赞/预热/OCR 全部串行跑很容易超。
         * 到了预算就跳过后续可选项（点赞、图片预热、OCR），保证同步本身能落盘返回。
         * 没做的那部分下一轮会继续补（点赞按预算分批、预热按 prefetch_per_run）。 */
        $deadline = microtime(true) + max(15, min(50, (int) ($cfg['sync_budget_sec'] ?? 40)));

        $added = 0;
        $updated = 0;
        $items = [];

        /* 【分页策略：从最新往前，按 total 收口】
         *
         * 目标：把「最新 max_items 条」都拿到（站长这次要前 50 条）。
         * 关键事实（实测）：请求 num=20，QQ 实际只回 19 条，而 total=513。
         * 所以：下一页用「实际返回条数」推进 pos，用 total 判断是否到底 ——
         * 原来用「返回数 < num 就当没有下一页」，永远只拿第一页，
         * 这就是「几百条动态只同步到几十条」的根因。
         */
        $wantItems = max(10, (int) $cfg['max_items']);
        $maxPages  = max(1, min(20, (int) ($cfg['sync_pages_per_run'] ?? 6)));
        $wantPages = (int) ceil($wantItems / max(1, $num));
        $pageLimit = max(1, min($maxPages, $wantPages));
        /* 【手动「立即同步一次」只跑指定页数，快速返回】
         * 后台按钮传 ['pages'=>1]，本意是拉最新一页就结束。但此前 $pages 参数
         * 定义了却从未使用 —— 实际仍按配置跑满 $pageLimit（默认 6）页，
         * 每页网络最坏 12s，6×12=72s 直接撞穿免费主机 60s 上限 → 请求被 kill，
         * 用户看到的就是「点一下卡死、返回 500」。显式传 pages 时用它收口。 */
        if (array_key_exists('pages', $opts)) {
            $pageLimit = min($pageLimit, max(1, min(5, (int) $opts['pages'])));
        }
        $totalAll  = 0;
        $pos       = 0;
        $gotItems  = 0;

        for ($p = 0; $p < $pageLimit; $p++) {
            /* 整轮时间硬闸：任何情况下都不让抓取阶段吃掉全部预算，
             * 必须给落盘/收尾留时间（否则超时被 kill → 500）。 */
            if (microtime(true) >= $deadline - 8) { break; }
            $r = lwQzoneFetchFeeds($pos, $num);
            if (!$r['ok'] && ($r['state'] ?? '') === 'cookie_expired') {
                /* 【先自救再报错】Cookie 失效时先跑一次保活：
                 * 很多「失效」只是 p_skey 轮换没了，skey 还活着 ——
                 * 访问一次空间首页就能拿回新 p_skey，不必让站长重新扫码。 */
                $ka = lwQzoneKeepAlive();
                if (!empty($ka['ok'])) {
                    $r = lwQzoneFetchFeeds($pos, $num);
                }
            }
            if (!$r['ok']) {
                lwQzoneSaveConfig([
                    'last_state' => $r['state'],
                    'last_error' => $r['err'],
                ]);
                /* 失败即告警（内部有冷却，不会刷屏） */
                lwQzoneNotifyFailure((string) $r['state'], (string) $r['err']);
                return ['ok' => false, 'state' => $r['state'], 'err' => $r['err'], 'added' => $added, 'updated' => $updated];
            }
            if (!empty($r['total'])) { $totalAll = (int) $r['total']; }
            $step = (int) ($r['step'] ?? count($r['items']));
            if ($step === 0) { break; }              /* 真的到末尾了 */
            $items    = array_merge($items, $r['items']);
            $gotItems += $step;
            $pos      += $step;
            lwQzoneSaveConfig(['sync_pos' => $pos, 'sync_total' => $totalAll]);
            if ($totalAll > 0 && $pos >= $totalAll) { break; }   /* 全量到底 */
            if ($gotItems >= $wantItems) { break; }              /* 已够本轮上限 */
            usleep(250000);                                       /* 稍歇，降低风控概率 */
        }

        /* 点赞数：msglist 里没有，需要逐条查 qz_opcnt2。
         * 每次最多补 30 条，避免免费主机上单个请求拖太久；其余下一轮再补。 */
        $likesSkipped = false;
        /* 【点赞只补一次】
         * 站长要求：同步时把点赞数抓一次即可，之后不需要持续同步（这台主机的
         * qz_opcnt2 接口经常长时间挂住，硬跑会拖死整轮）。
         * 所以只给「本地还没有赞数」的说说补：已经抓到过的（>0）直接跳过，
         * 这样后续每轮同步根本不会发起点赞请求，速度快且不会超时。 */
        $existingLikes = [];
        foreach ((array) $fs->read('qzone_items') as $row) {
            $existingLikes[(string) ($row['tid'] ?? '')] = (int) ($row['likes'] ?? 0);
        }
        $needLike = [];
        foreach ($items as $itx) {
            $t = (string) $itx['tid'];
            if (($existingLikes[$t] ?? 0) <= 0 && (int) ($itx['likes'] ?? 0) <= 0) {
                $needLike[] = $itx;
            }
        }
        if ((int) $cfg['sync_likes'] === 1 && $needLike && (microtime(true) < $deadline - 12)) {
            $rawCk = lwQzoneGetCookie();
            $gtk   = lwQzoneGtk(lwQzoneCookieValue($rawCk, 'p_skey'));
            $uinN  = preg_replace('/\D/', '', (string) $cfg['uin']);
            /* 并发抓点赞：串行实测整轮 47 秒 → 并发 6 路后 2~4 秒。
               这直接决定「访问触发同步」时请求被拖多久。 */
            $targets = [];
            /* 只补「还没有赞数」的说说：新出现的 + 以前没补上的。
               已经有一次赞数的（包括站长导入时带的）永远不再请求 ——
               满足「同步时同步一次点赞数就行，后面不需要再同步」。 */
            $budget = max(10, min(30, count($needLike)));
            foreach ($needLike as $itx) {
                if ($budget-- <= 0) { break; }
                $targets[] = ['tid' => (string) $itx['tid']];
            }
            /* 时间不够就少补一些：宁可本轮少补几条，也别让整轮请求被主机杀掉 */
            $remain = $deadline - microtime(true);
            if ($remain < 12) { $targets = array_slice($targets, 0, 12); }
            $batch = lwQzoneFetchLikesBatch($targets, (string) $uinN, $rawCk, $gtk, 6, 10);
            foreach ($items as &$itx) {
                $tid = (string) $itx['tid'];
                if (isset($batch[$tid]) && $batch[$tid] > 0) { $itx['likes'] = $batch[$tid]; }
            }
            unset($itx);
        }

        /* 【批量写盘】原来这里面每条 item 都 findOne + update（整文件读写），
         * 每条评论也各自 findOne/insert/update —— 57 条说说 + 123 条评论
         * 就是几百次全量文件操作，线上直接把 60 秒请求上限吃穿（同步被 kill，无输出）。
         * 改成：两个表各读一次建索引，循环里只算记录，最后 insertMany/updateMany 各一次。 */
        $existItems = [];
        foreach ((array) $fs->read('qzone_items') as $row) {
            $k = (string) ($row['tid'] ?? '');
            if ($k !== '') { $existItems[$k] = $row; }
        }
        $existCmts = [];
        foreach ((array) $fs->read('qzone_comments') as $row) {
            $k = (string) ($row['item_tid'] ?? '') . '|' . (string) ($row['tid'] ?? '');
            $existCmts[$k] = $row;
        }

        $newItems    = [];
        $itemPatches = [];
        $newCmts     = [];
        $cmtPatches  = [];

        foreach ($items as $it) {
            $rec = [
                'tid'        => $it['tid'],
                'content'    => $it['content'],
                'images'     => json_encode($it['images'], JSON_UNESCAPED_SLASHES),
                'posted_ts'  => $it['created_at'],
                'likes'      => $it['likes'],
                'comment_num'=> $it['comments'],
                'synced_at'  => $now,
            ];
            if (isset($existItems[(string) $it['tid']])) {
                /* 【点赞不能被 0 覆盖】
                 * msglist_v6 完全不含点赞字段（实测 19 条里 like* 全空），
                 * 点赞只能从 qz_opcnt2 单独取。如果本轮没取到（sync_likes=0 或接口超时），
                 * $it['likes'] 就是 0 —— 直接写进去会把之前正确的赞数抹掉，
                 * 站长看到的就是「原帖 184 赞变成 0」。所以：0 不覆盖已有值。 */
                if ((int) ($rec['likes'] ?? 0) <= 0) {
                    unset($rec['likes']);
                }
                $itemPatches[(int) $existItems[(string) $it['tid']]['id']] = $rec;
                $updated++;
            } else {
                /* item_id 先留 0，插入后按 tid 回填 */
                $rec['item_id'] = 0;
                $newItems[] = $rec;
                $added++;
            }

            if ((int) $cfg['sync_comments'] === 1 && !empty($it['comment_list'])) {
                foreach ($it['comment_list'] as $c) {
                    $k = (string) $it['tid'] . '|' . (string) $c['tid'];
                    $crec = [
                        'item_tid'   => $it['tid'],
                        'tid'        => $c['tid'],
                        'author'     => $c['author'],
                        'author_uin' => $c['author_uin'],
                        'content'    => $c['content'],
                        'posted_ts'  => $c['created_at'],
                        'synced_at'  => $now,
                    ];
                    if (isset($existCmts[$k])) {
                        $cmtPatches[(int) $existCmts[$k]['id']] = $crec;
                    } else {
                        $crec['item_id'] = 0;   /* 插入后回填 */
                        $newCmts[] = $crec;
                    }
                }
            }
        }

        /* 落盘：新 item 先插入，拿到 id 后回填评论的 item_id */
        $itemIdByTid = [];
        foreach ($existItems as $tid => $row) { $itemIdByTid[$tid] = (int) $row['id']; }
        if ($newItems) {
            $insItems = $fs->insertMany('qzone_items', $newItems, true);
            foreach ($insItems as $row) {
                $itemIdByTid[(string) $row['tid']] = (int) $row['id'];
            }
        }
        if ($itemPatches) { $fs->updateMany('qzone_items', $itemPatches); }
        foreach ($newCmts as &$c) {
            $c['item_id'] = $itemIdByTid[(string) $c['item_tid']] ?? 0;
        }
        unset($c);
        if ($newCmts) { $fs->insertMany('qzone_comments', $newCmts, true); }
        if ($cmtPatches) { $fs->updateMany('qzone_comments', $cmtPatches); }

        /* 修剪：本地最多保留 max_items 条（按对方发布时间倒序），超出删除。
           站长要求「站内只保留前 50 条」，这里兜住 qzone_items，避免无限堆积。 */
        $allItems = $fs->read('qzone_items');
        if (is_array($allItems) && count($allItems) > max(10, (int) $cfg['max_items'])) {
            usort($allItems, function ($a, $b) { return ((int) ($b['posted_ts'] ?? 0)) <=> ((int) ($a['posted_ts'] ?? 0)); });
            $drop = array_slice($allItems, max(10, (int) $cfg['max_items']));
            $dropTids = [];
            foreach ($drop as $old) {
                $dropTids[(string) ($old['tid'] ?? '')] = true;
                $fs->delete('qzone_items', (int) $old['id']);
            }
            foreach ($fs->read('qzone_comments') as $c) {
                if (isset($dropTids[(string) ($c['item_tid'] ?? '')])) {
                    $fs->delete('qzone_comments', (int) $c['id']);
                }
            }
        }

        /* 预热图片（爬过来 + 压缩存本地）。失败不影响同步结果。 */
        $imgCached = 0;
        try {
            /* 只剩不到 15 秒就不预热了：一张图 1~3 秒，硬跑必然超时 */
            if (microtime(true) < $deadline - 15) {
                $imgCached = lwQzonePrefetchImages();
            }
        } catch (Throwable $e) {
            $imgCached = 0;
        }

        /* 线上自动 OCR：对还没识别过的说说调云端 API，结果落 data/qzone_ocr.json。
           失败/未配置都只是跳过，不影响同步本身。 */
        $ocrDone = 0;
        try {
            $ocrInc = __DIR__ . '/qzone_ocr.php';
            if (microtime(true) < $deadline - 8 && is_file($ocrInc)) {
                require_once $ocrInc;
                if (function_exists('lwQzoneOcrAutoRun')) {
                    $ocrRes = lwQzoneOcrAutoRun();
                    $ocrDone = (int) ($ocrRes['done'] ?? 0);
                }
            }
        } catch (Throwable $e) {
            $ocrDone = 0;
        }

        lwQzoneSaveConfig([
            'last_state'   => 'ok',
            'last_error'   => '',
            'last_sync_at' => $now,
            'last_count'   => $added,
            'last_images'  => $imgCached,
            'last_ocr'     => $ocrDone,
        ]);
        return ['ok' => true, 'state' => 'ok', 'err' => '', 'added' => $added, 'updated' => $updated, 'images' => $imgCached];
    }
}

if (!function_exists('lwQzoneMaybeAutoSync')) {
    /** 访问触发：仅在开关打开且距上次尝试超过间隔时跑一次，避免每次请求都出网。 */
    function lwQzoneMaybeAutoSync(): void
    {
        $cfg = lwQzoneConfig();
        if ((int) $cfg['enabled'] !== 1 || (int) $cfg['auto_sync'] !== 1) {
            return;
        }
        $now = time();
        if ($now - (int) $cfg['last_attempt_at'] < max(60, (int) $cfg['sync_interval'])) {
            return;
        }
        /* 先占位，防止并发请求同时出网 */
        lwQzoneSaveConfig(['last_attempt_at' => $now]);
        try {
            lwQzoneSync(['pages' => 1]);
        } catch (Throwable $e) {
            lwQzoneSaveConfig(['last_state' => 'error', 'last_error' => '异常：' . $e->getMessage()]);
        }
    }
}

if (!function_exists('lwQzoneHomeStrip')) {
    /**
     * 首页「空间动态」横滑条的数据。
     * 只有总开关打开、且配置了「混入首页」时才有内容，否则返回空数组。
     */
    function lwQzoneHomeStrip(): array
    {
        $cfg = lwQzoneConfig();
        if ((int) $cfg['enabled'] !== 1 || (int) $cfg['show_home'] !== 1) {
            return [];
        }
        $n = (int) $cfg['home_count'];
        if ($n === 0) { return []; }                 /* 0 = 不在首页显示 */
        if ($n < 0)   { return lwQzoneListItems(1000, 0); } /* -1 = 全部 */
        return lwQzoneListItems(min(100, $n), 0);
    }
}

if (!function_exists('lwQzoneListItems')) {
    /** 取镜像列表（带评论），供前台展示。 */
    function lwQzoneListItems(int $limit = 20, int $offset = 0): array
    {
        $fs  = getFS();
        $all = $fs->read('qzone_items');
        if (!is_array($all) || !$all) {
            return [];
        }
        usort($all, function ($a, $b) { return ((int) ($b['posted_ts'] ?? 0)) <=> ((int) ($a['posted_ts'] ?? 0)); });
        $page = array_slice($all, max(0, $offset), max(1, $limit));
        $cfg  = lwQzoneConfig();
        foreach ($page as &$it) {
            $it['images_arr'] = json_decode((string) ($it['images'] ?? '[]'), true) ?: [];
            $it['comments_arr'] = [];
            if ((int) $cfg['sync_comments'] === 1) {
                $cs = $fs->find('qzone_comments', ['item_tid' => $it['tid']]);
                usort($cs, function ($a, $b) { return ((int) ($a['posted_ts'] ?? 0)) <=> ((int) ($b['posted_ts'] ?? 0)); });
                $it['comments_arr'] = $cs;
            }
        }
        unset($it);
        return $page;
    }
}


/* ============================================================================
 * 失败告警：同步失败 / Cookie 失效时发邮件给站长
 * ============================================================================
 * 为什么需要冷却：
 *   Cookie 失效后，只要有人访问就会不断命中失败分支。
 *   若不冷却，站长的信箱会被同一封「Cookie 失效」刷满。
 *   这里按「失败种类」（cookie_expired / error）分别计冷却。
 */

if (!function_exists('lwQzoneMailReady')) {
    function lwQzoneMailReady(): bool
    {
        if (!class_exists('QQMailer')) {
            $f = dirname(__DIR__) . '/includes/qqmail.php';
            if (is_file($f)) { require_once $f; }
        }
        return class_exists('QQMailer') && QQMailer::isConfigured();
    }
}

if (!function_exists('lwQzoneNotifyFailure')) {
    function lwQzoneNotifyFailure(string $state, string $err): bool
    {
        $cfg = lwQzoneConfig();
        if ((int) $cfg['notify_on_error'] !== 1) { return false; }
        $to = trim((string) $cfg['notify_email']);
        if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) { return false; }
        if (!lwQzoneMailReady()) { return false; }

        /* 同类失败冷却 */
        $now = time();
        if ((string) $cfg['last_notify_kind'] === $state
            && $now - (int) $cfg['last_notify_at'] < max(300, (int) $cfg['notify_cooldown'])) {
            return false;
        }

        $isCookie = $state === 'cookie_expired';
        $subject = $isCookie ? '【空间转发】Cookie 已失效，需要重新粘贴' : '【空间转发】同步失败';
        $detail = htmlspecialchars($err, ENT_QUOTES, 'UTF-8');
        $body = '<div style="font-family:system-ui,-apple-system,Segoe UI,sans-serif;font-size:14px;line-height:1.8;color:#1a2433">'
              . '<p>站点：' . htmlspecialchars((defined('SITE_URL') ? SITE_URL : ''), ENT_QUOTES, 'UTF-8') . '</p>'
              . '<p>目标 QQ：' . htmlspecialchars((string) $cfg['uin'], ENT_QUOTES, 'UTF-8') . '</p>'
              . '<p>状态：' . ($isCookie ? 'Cookie 失效（QQ 空间需要重新登录）' : '同步出错') . '</p>'
              . '<p>原因：' . $detail . '</p>'
              . '<p>时间：' . date('Y-m-d H:i:s') . '</p>'
              . '<p>处理：' . ($isCookie
                    ? '打开后台「QQ 空间转发」页，重新粘贴 Cookie 后点「测试 Cookie」。'
                    : '打开后台「QQ 空间转发」页查看状态，必要时手动同步一次。')
              . '</p></div>';

        $ok = false;
        try {
            $ok = (bool) QQMailer::send($to, $subject, $body);
        } catch (Throwable $e) {
            $ok = false;
        }
        lwQzoneSaveConfig(['last_notify_at' => $now, 'last_notify_kind' => $state]);
        return $ok;
    }
}

/* ============================================================================
 * 导入 / 导出（支撑「本地先爬一遍，部署时导入，减轻服务器抓取压力」）
 * ============================================================================
 * 本地用 tools/qzone_scrape.php 抓一份 JSON，上传后由后台「导入」按钮灌进站内。
 * 导入按 tid 合并：已有则更新点赞/评论数，没有则新增。
 */

if (!function_exists('lwQzoneExportData')) {
    function lwQzoneExportData(): array
    {
        $fs = getFS();
        $items = $fs->read('qzone_items');
        if (!is_array($items)) { $items = []; }
        usort($items, function ($a, $b) { return ((int) ($b['posted_ts'] ?? 0)) <=> ((int) ($a['posted_ts'] ?? 0)); });
        $comments = [];
        foreach ($items as $it) {
            $comments[$it['tid']] = $fs->find('qzone_comments', ['item_tid' => $it['tid']]);
        }
        return ['version' => 1, 'exported_at' => date('Y-m-d H:i:s'), 'items' => $items, 'comments' => $comments];
    }
}

if (!function_exists('lwQzoneImportData')) {
    /** 返回 ['ok'=>bool,'added'=>int,'updated'=>int,'err'=>string] */
    function lwQzoneImportData(array $bundle): array
    {
        if (!isset($bundle['items']) || !is_array($bundle['items'])) {
            return ['ok' => false, 'added' => 0, 'updated' => 0, 'err' => 'JSON 结构不对：缺少 items'];
        }
        $fs  = getFS();
        $now = time();
        $added = 0;
        $updated = 0;
        foreach ($bundle['items'] as $it) {
            $tid = (string) ($it['tid'] ?? '');
            if ($tid === '') { continue; }
            $rec = [
                'tid'         => $tid,
                'content'     => (string) ($it['content'] ?? ''),
                'images'      => is_string($it['images'] ?? null) ? $it['images'] : json_encode($it['images'] ?? [], JSON_UNESCAPED_SLASHES),
                'posted_ts'   => (int) ($it['posted_ts'] ?? 0),
                'likes'       => (int) ($it['likes'] ?? 0),
                'comment_num' => (int) ($it['comment_num'] ?? 0),
                'synced_at'   => $now,
            ];
            $exist = $fs->findOne('qzone_items', ['tid' => $tid]);
            if ($exist) {
                $fs->update('qzone_items', $exist['id'], $rec);
                $itemId = (int) $exist['id'];
                $updated++;
            } else {
                $created = $fs->insert('qzone_items', $rec);
                if (!$created) { continue; }
                $itemId = (int) $created['id'];
                $added++;
            }
            $cs = $bundle['comments'][$tid] ?? [];
            if (is_array($cs)) {
                foreach ($cs as $c) {
                    $ctid = (string) ($c['tid'] ?? '');
                    if ($ctid === '') { continue; }
                    $crec = [
                        'item_tid'   => $tid,
                        'item_id'    => $itemId,
                        'tid'        => $ctid,
                        'author'     => (string) ($c['author'] ?? ''),
                        'author_uin' => (string) ($c['author_uin'] ?? ''),
                        'content'    => (string) ($c['content'] ?? ''),
                        'posted_ts'  => (int) ($c['posted_ts'] ?? 0),
                        'synced_at'  => $now,
                    ];
                    $hit = $fs->findOne('qzone_comments', ['item_tid' => $tid, 'tid' => $ctid]);
                    if ($hit) { $fs->update('qzone_comments', $hit['id'], $crec); }
                    else { $fs->insert('qzone_comments', $crec); }
                }
            }
        }
        /* 导入也算一次同步，更新状态 */
        lwQzoneSaveConfig(['last_sync_at' => $now, 'last_state' => 'ok', 'last_error' => '', 'last_count' => $added]);
        return ['ok' => true, 'added' => $added, 'updated' => $updated, 'err' => ''];
    }
}


/* ============================================================================
 * 图片：抓取 + 压缩 + 本地缓存
 * ============================================================================
 * 为什么要存本地而不是直接引用 QQ 地址：
 *   1) 防盗链：带本站 Referer 直连 QQ 图片拿到的是占位图（实测 3.7KB PNG）
 *   2) 体积：原图动辄 892x1969、单张 ~100KB，一天 20 张就是 2MB，
 *      手机上流量和首屏都不友好 —— 压到宽度 1080 / q82 通常只剩 1/3
 *   3) 稳定：QQ 的图片地址带签名，会过期；本地有一份才不会被对方改动牵着走
 */

if (!function_exists('lwQzoneMediaDir')) {
    function lwQzoneMediaDir(): string
    {
        $dir = dirname(__DIR__) . '/data/qzone_media';
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        return $dir;
    }
}

if (!function_exists('lwQzoneMediaFile')) {
    function lwQzoneMediaFile(string $url): string
    {
        return lwQzoneMediaDir() . '/' . sha1($url) . '.jpg';
    }
}

if (!function_exists('lwQzoneDownloadImage')) {
    function lwQzoneDownloadImage(string $url): ?string
    {
        $r = lwQzoneHttp($url, lwQzoneGetCookie(), 15);
        if (!$r['ok'] || $r['code'] !== 200 || strlen($r['body']) < 512) {
            return null;
        }
        return $r['body'];
    }
}

if (!function_exists('lwQzoneCompressImage')) {
    /**
     * 用 GD 缩到指定宽度并转 JPEG。失败返回 null（调用方退回原图）。
     * 像素上限保护：超大图（>40MP）不处理，避免共享主机上 GD 吃爆内存。
     */
    function lwQzoneCompressImage(string $bytes, int $maxW = 1080, int $quality = 82): ?string
    {
        if (!function_exists('imagecreatefromstring')) {
            return null; /* 没装 GD 就退回原图 */
        }
        $info = @getimagesizefromstring($bytes);
        if (!$info || empty($info[0]) || empty($info[1])) {
            return null;
        }
        $w = (int) $info[0];
        $h = (int) $info[1];
        if ($w <= 0 || $h <= 0 || ($w * $h) > 40000000) {
            return null;
        }
        $src = @imagecreatefromstring($bytes);
        if (!$src) {
            return null;
        }
        $maxW = max(320, min(2400, $maxW));
        $newW = $w;
        $newH = $h;
        if ($w > $maxW) {
            $newW = $maxW;
            $newH = (int) round($h * ($maxW / $w));
        }
        $dst = @imagecreatetruecolor($newW, $newH);
        if (!$dst) {
            imagedestroy($src);
            return null;
        }
        /* JPEG 没有透明通道：先铺白底，避免 PNG 透明区域变黑 */
        $white = imagecolorallocate($dst, 255, 255, 255);
        imagefilledrectangle($dst, 0, 0, $newW, $newH, $white);
        imagecopyresampled($dst, $src, 0, 0, 0, 0, $newW, $newH, $w, $h);
        ob_start();
        imagejpeg($dst, null, max(50, min(95, $quality)));
        $out = ob_get_clean();
        imagedestroy($src);
        imagedestroy($dst);
        return is_string($out) && strlen($out) > 256 ? $out : null;
    }
}

if (!function_exists('lwQzoneCacheImage')) {
    /**
     * 抓取并压缩一张图，落盘。已存在直接返回路径。
     * 返回本地文件路径，失败返回 ''。
     */
    function lwQzoneCacheImage(string $url): string
    {
        if ($url === '' || strpos($url, 'https://') !== 0) {
            return '';
        }
        $file = lwQzoneMediaFile($url);
        if (is_file($file) && filesize($file) > 1024) {
            return $file;
        }
        $raw = lwQzoneDownloadImage($url);
        if ($raw === null) {
            return '';
        }
        $cfg = lwQzoneConfig();
        $jpeg = lwQzoneCompressImage($raw, (int) $cfg['image_max_width'], (int) $cfg['image_quality']);
        /* 【取较小者】QQ 的原图本身已经压过一轮，重新编码不一定更小：
         *   实测 892x1969 47.7KB → q82 重编码 59.4KB（反而大 24%）
         *   只有「宽度超标需要缩放」时才真正划算（1170→1080 省 14%）
         * 所以：两个都算出来，谁小存谁。绝不能默认用重编码结果。 */
        $data = ($jpeg !== null && strlen($jpeg) < strlen($raw)) ? $jpeg : $raw;
        $tmp = $file . '.' . bin2hex(random_bytes(4)) . '.tmp';
        if (@file_put_contents($tmp, $data) === false) {
            return '';
        }
        if (!@rename($tmp, $file)) {
            @unlink($tmp);
            return '';
        }
        return $file;
    }
}


if (!function_exists('lwQzoneCacheImageVariant')) {
    /**
     * 按目标宽度生成/取一张缓存变体（例如首页卡片只要 420px 宽）。
     *
     * 为什么要变体：实测线上首页 4 张 QQ 图就占了 475 KB，
     * 而手机卡片渲染宽度只有 ~350px —— 传 1080px 原图是纯浪费。
     * 变体按 sha1(url)_w<宽度>.jpg 缓存，同一张图可同时存在多档尺寸。
     */
    function lwQzoneCacheImageVariant(string $url, int $w = 0, int $quality = 0): string
    {
        if ($url === '' || strpos($url, 'https://') !== 0) { return ''; }
        $cfg = lwQzoneConfig();
        $maxW = (int) $cfg['image_max_width'];
        $q    = (int) $cfg['image_quality'];
        /* w<=0 或已经不小于上限 → 直接用全尺寸缓存 */
        if ($w <= 0 || $w >= $maxW) {
            return lwQzoneCacheImage($url);
        }
        $w = max(160, min(1200, $w));
        if ($q <= 0) { $q = $cfg['image_quality']; }
        $file = lwQzoneMediaDir() . '/' . sha1($url) . '_w' . $w . '.jpg';
        if (is_file($file) && filesize($file) > 512) { return $file; }

        $full = lwQzoneCacheImage($url);
        if ($full === '') { return ''; }
        $bytes = (string) @file_get_contents($full);
        if ($bytes === '') { return ''; }
        $variant = lwQzoneCompressImage($bytes, $w, max(60, min(88, $q)));
        if ($variant === null) { return $full; }
        $tmp = $file . '.' . bin2hex(random_bytes(3)) . '.tmp';
        if (@file_put_contents($tmp, $variant) === false) { return $full; }
        if (!@rename($tmp, $file)) { @unlink($tmp); return $full; }
        return $file;
    }
}

if (!function_exists('lwQzonePrefetchImages')) {
    /**
     * 同步时预热图片（每次最多 prefetch_per_run 张）。
     * 优先处理最新、且还没缓存过的条目 —— 这样首页横滑条和第一页能立刻有图。
     * 返回本轮成功缓存的数量。
     */
    function lwQzonePrefetchImages(): int
    {
        $cfg = lwQzoneConfig();
        $limit = max(0, min(30, (int) $cfg['prefetch_per_run']));
        if ($limit <= 0) {
            return 0;
        }
        $fs = getFS();
        $all = $fs->read('qzone_items');
        if (!is_array($all) || !$all) {
            return 0;
        }
        usort($all, function ($a, $b) { return ((int) ($b['posted_ts'] ?? 0)) <=> ((int) ($a['posted_ts'] ?? 0)); });
        $done = 0;
        foreach ($all as $it) {
            if ($done >= $limit) { break; }
            $imgs = json_decode((string) ($it['images'] ?? '[]'), true);
            if (!is_array($imgs)) { continue; }
            foreach ($imgs as $u) {
                if ($done >= $limit) { break; }
                $u = (string) $u;
                if ($u === '') { continue; }
                $file = lwQzoneMediaFile($u);
                if (is_file($file) && filesize($file) > 1024) { continue; }
                if (lwQzoneCacheImage($u) !== '') { $done++; }
                usleep(60000); /* 60ms：别把 QQ 图片服务器打太急 */
            }
        }
        return $done;
    }
}
