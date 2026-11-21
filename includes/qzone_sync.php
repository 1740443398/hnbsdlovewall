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
            'home_count'     => 3,      // 混进首页的条数
            'auto_sync'      => 1,      // 1=允许访问触发自动同步
            'sync_interval'  => 86400,  // 自动同步最小间隔（秒）：默认 24 小时
            /* 失败告警：同步失败或 Cookie 失效时给站长发邮件。
             * 冷却时间是为了防止「Cookie 一直失效 + 有人不停访问」把信箱刷爆。 */
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

if (!function_exists('lwQzoneSetCookie')) {
    function lwQzoneSetCookie(string $raw): void
    {
        $raw = trim($raw);
        /* 兼容整段 header 粘贴：'Cookie: a=b; c=d' → 'a=b; c=d' */
        if (stripos($raw, 'cookie:') === 0) {
            $raw = trim(substr($raw, 7));
        }
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
        ]);
        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = (string) curl_error($ch);
        curl_close($ch);
        return ['ok' => $body !== false && $err === '', 'code' => $code, 'body' => is_string($body) ? $body : '', 'err' => $err];
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
        return ['ok' => true, 'state' => 'ok', 'items' => lwQzoneNormalizeList($list), 'err' => ''];
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
            $images = [];
            if (!empty($it['pic']) && is_array($it['pic'])) {
                foreach ($it['pic'] as $p) {
                    if (!is_array($p)) {
                        continue;
                    }
                    $u = (string) ($p['url1'] ?? ($p['url2'] ?? ($p['url3'] ?? '')));
                    if ($u !== '') {
                        $images[] = $u;
                    }
                }
            }
            $comments = [];
            if (!empty($it['commentlist']) && is_array($it['commentlist'])) {
                foreach ($it['commentlist'] as $c) {
                    if (!is_array($c)) {
                        continue;
                    }
                    $cid = (string) ($c['tid'] ?? ($c['id'] ?? ''));
                    $ctext = trim((string) ($c['content'] ?? ''));
                    if ($cid === '' || $ctext === '') {
                        continue;
                    }
                    $comments[] = [
                        'tid'        => $cid,
                        'author'     => (string) ($c['name'] ?? ($c['nickname'] ?? '')),
                        'author_uin' => (string) ($c['uin'] ?? ''),
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

        $added = 0;
        $updated = 0;
        $items = [];

        for ($p = 0; $p < $pages; $p++) {
            $r = lwQzoneFetchFeeds($p * $num, $num);
            if (!$r['ok']) {
                lwQzoneSaveConfig([
                    'last_state' => $r['state'],
                    'last_error' => $r['err'],
                ]);
                /* 失败即告警（内部有冷却，不会刷屏） */
                lwQzoneNotifyFailure((string) $r['state'], (string) $r['err']);
                return ['ok' => false, 'state' => $r['state'], 'err' => $r['err'], 'added' => $added, 'updated' => $updated];
            }
            $items = array_merge($items, $r['items']);
            if (count($r['items']) < $num) {
                break; /* 没有下一页 */
            }
        }

        foreach ($items as $it) {
            $exist = $fs->findOne('qzone_items', ['tid' => $it['tid']]);
            /* 注意：filestorage::insert() 会用当前时间**强制覆盖** created_at/updated_at，
             * 所以对方说说的发布时间必须存在单独的 posted_ts 里，否则会丢。 */
            $rec = [
                'tid'        => $it['tid'],
                'content'    => $it['content'],
                'images'     => json_encode($it['images'], JSON_UNESCAPED_SLASHES),
                'posted_ts'  => $it['created_at'],
                'likes'      => $it['likes'],
                'comment_num'=> $it['comments'],
                'synced_at'  => $now,
            ];
            if ($exist) {
                $fs->update('qzone_items', $exist['id'], $rec);
                $itemId = (int) $exist['id'];
                $updated++;
            } else {
                $created = $fs->insert('qzone_items', $rec);
                if (!$created) {
                    continue;
                }
                $itemId = (int) $created['id'];
                $added++;
            }

            if ((int) $cfg['sync_comments'] === 1 && !empty($it['comment_list'])) {
                foreach ($it['comment_list'] as $c) {
                    $hit = $fs->findOne('qzone_comments', ['item_tid' => $it['tid'], 'tid' => $c['tid']]);
                    $crec = [
                        'item_tid'   => $it['tid'],
                        'item_id'    => $itemId,
                        'tid'        => $c['tid'],
                        'author'     => $c['author'],
                        'author_uin' => $c['author_uin'],
                        'content'    => $c['content'],
                        'posted_ts'  => $c['created_at'],
                        'synced_at'  => $now,
                    ];
                    if ($hit) {
                        $fs->update('qzone_comments', $hit['id'], $crec);
                    } else {
                        $fs->insert('qzone_comments', $crec);
                    }
                }
            }
        }

        /* 修剪：本地最多保留 max_items 条（按对方发布时间倒序），超出删除 */
        $all = $fs->read('qzone_items');
        if (is_array($all)) {
            usort($all, function ($a, $b) { return ((int) ($b['posted_ts'] ?? 0)) <=> ((int) ($a['posted_ts'] ?? 0)); });
            $max = max(10, (int) $cfg['max_items']);
            if (count($all) > $max) {
                foreach (array_slice($all, $max) as $old) {
                    $fs->delete('qzone_items', $old['id']);
                    foreach ($fs->find('qzone_comments', ['item_tid' => $old['tid']]) as $c) {
                        $fs->delete('qzone_comments', $c['id']);
                    }
                }
            }
        }

        lwQzoneSaveConfig([
            'last_state'   => 'ok',
            'last_error'   => '',
            'last_sync_at' => $now,
            'last_count'   => $added,
        ]);
        return ['ok' => true, 'state' => 'ok', 'err' => '', 'added' => $added, 'updated' => $updated];
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
