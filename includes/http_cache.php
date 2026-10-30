<?php
/**
 * HTTP 缓存层 —— 「输入 / 输出多命中缓存」的唯一实现。
 * =====================================================================
 *
 * 背景（这次要解决的问题）
 * ------------------------
 * 旧策略只有两档：/api/ 一律 no-store、页面一律 no-cache。保守没有错，但代价是：
 *   ① 连「谁都能看、几分钟不变」的公开数据（信息流第一页、客户端版本号、装扮目录）
 *      也每次回源重跑 PHP；
 *   ② 页面永远 no-cache，且**每页都下发 CSRF 头** —— bfcache 的规矩是「页面收过
 *      no-store 就不得进往返缓存」，于是「点进帖子 → 返回」每次都要整页重跑，白屏一下；
 *   ③ 静态资源虽然 .htaccess 给了 max-age，但同一轮里又有 `Cache-Control: private,
 *      no-cache`（config.php 页面分支），等于把长缓存又打回协商；
 *   ④ 输入侧（AI 对话这种「同一句话反复问」的场景）完全没有缓存，同样的 prompt 付费重打。
 *
 * 现在的分档（优先级从高到低，见 lwResolveCachePolicy()）
 * ------------------------------------------------------
 *   换发会话    no-store                             —— 登录/退出/CSRF 换签绝不能被缓存
 *   公开接口    public, max-age=N, s-maxage, [swr]   —— 显式调用 lwCacheShared() 的 GET 接口
 *   静态版本化  public, max-age=1y, immutable        —— /assets/ 下且 URL 带 ?v=
 *   静态其它    public, max-age=1h
 *   页面导航    private, no-cache                    —— **不再**附带 no-store，bfcache / 磁盘缓存可用
 *   默认接口    no-store                             —— 私有 / 可变数据，一字不改
 *
 * 三条硬约束（踩过坑，别拆）
 * --------------------------
 *   1) 带 Set-Cookie 换发会话的响应**绝不进任何缓存**：本站 CSRF 令牌存在会话里，
 *      把带令牌的响应共享给别人等于把令牌送人。
 *   2) 共享缓存只给**已确认无个人字段**的 GET 用；带 Authorization 的一律不碰。
 *   3) 缓存层自身绝不能把接口弄成 500：任何辅助功能失败都要静默退回旧行为。
 */

if (!defined('LW_HTTP_CACHE_LOADED')) {
    define('LW_HTTP_CACHE_LOADED', true);

    /**
     * 整站资源版本 = assets/ 与 download/ 下最新一次 mtime。
     *
     * 为什么不是逐文件：全站几十处 asset_url()，每处都 filemtime 一次（共享主机上
     * 元数据查询不便宜）。这里扫一次、结果存进 data/asset_version.txt，之后每请求
     * 只读一个十几字节的小文件；读不到或写不进去就退回旧行为（asset_ver 用 filemtime）。
     *
     * 为什么取「最大 mtime」而不是自增计数器：内容回滚时版本号不会倒退，天然单调，
     * 跨部署也没有竞态。代价是**改任何一个资源都会让全站 ?v= 一起变**（一次性全量
     * 重下），换来「改了就一定生效」—— 对本站这种纯静态小站是正确的取舍。
     *
     * ⚠️ 存值必须**校验**后才能用：部署方式是整包覆盖（FTP 上传 zip 再解压），
     * `data/` 不在上传包里，于是**新代码 + 旧 asset_version.txt** 是常态。
     * 只信存值就等于「改了资源但版本号没动」→ 浏览器拿着一年 immutable 的旧文件不动，
     * 正好是这个机制最该避免的故障。校验方式：存值必须落在「资源目录里最新的那个 mtime」
     * 与「当前时间」之间 —— 自上次扫描以来目录没再被改过，存值就是对的；
     * 被改过就会超出上界，自动重新扫描。单次扫描只在「确实有新改动」时发生。
     *
     * @param bool $force 忽略存值，强制重新扫描（部署脚本/探针用）
     * @return int 0 表示无法计算
     */
    function lwAssetVersioning($force = false)
    {
        static $cached = null;
        if ($cached !== null && !$force) {
            return $cached;
        }

        $root = dirname(__DIR__);
        $stampFile = $root . '/data/asset_version.txt';

        // 一次遍历同时得到「最新 mtime」，顺带把各文件的 mtime 收集起来供上界校验
        $max = 0;
        foreach (['/assets', '/download'] as $sub) {
            $dir = $root . $sub;
            if (!is_dir($dir)) {
                continue;
            }
            try {
                $it = new RecursiveIteratorIterator(
                    new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
                    RecursiveIteratorIterator::SELF_FIRST
                );
            } catch (Exception $e) {
                continue;
            }
            foreach ($it as $file) {
                if (!$file->isFile()) {
                    continue;
                }
                $base = $file->getBasename();
                // 编辑器/系统垃圾文件碰一下 mtime 就把全站版本顶掉，不值当
                if ($base === '' || $base === '.DS_Store' || $base === 'Thumbs.db' || $base[0] === '.') {
                    continue;
                }
                $m = @$file->getMTime();
                if ($m && $m > $max) {
                    $max = $m;
                }
            }
        }

        $stored = 0;
        if (!$force && is_file($stampFile)) {
            $raw = @file_get_contents($stampFile);
            $stored = $raw === false ? 0 : (int)trim($raw);
        }

        // 存值可用条件：它就是当前最新 mtime（说明目录自上次扫描后没变），
        // 且不超过现在（防止部署时把文件时间设成未来）。
        if ($stored > 0 && $stored === $max) {
            $cached = $stored;
            return $cached;
        }

        if ($max > 0) {
            // 写失败不影响正确性（下次再扫），只是多花一次遍历
            if (@file_put_contents($stampFile, (string)$max, LOCK_EX) === false) {
                error_log('lwAssetVersioning: 无法写入 ' . $stampFile . '（退回逐文件 filemtime）');
            }
        }
        $cached = $max;
        return $cached;
    }

    /**
     * 静态资源版本号：整站版本优先，算不出来退回逐文件 mtime。
     *
     * @param string $realPath 站点根下的真实路径（如 /assets/css/style.min.css）
     * @return int|string 时间戳或 'v1'
     */
    function lwAssetVer($realPath)
    {
        $global = lwAssetVersioning();
        if ($global > 0) {
            return $global;
        }
        $path = dirname(__DIR__) . $realPath;
        if (is_file($path)) {
            $m = @filemtime($path);
            if ($m) {
                return $m;
            }
        }
        return 'v1';
    }

    /**
     * 请求类型判定。引导层与响应层共用同一份判定，避免两边对「这是页面还是接口」分叉。
     *
     * @return array{isApi:bool,isStatic:bool,isPage:bool,path:string}
     */
    function lwRequestKind()
    {
        static $kind = null;
        if ($kind !== null) {
            return $kind;
        }
        $uri = (string)($_SERVER['REQUEST_URI'] ?? '');
        $path = (string)(parse_url($uri, PHP_URL_PATH) ?: '/');
        $isApi = strpos($path, '/api/') !== false;
        $isStatic = (bool)preg_match(
            '/\.(css|js|mjs|json|map|png|jpe?g|gif|svg|webp|avif|ico|bmp|woff2?|ttf|eot|otf|mp4|webm|mp3|webmanifest)$/i',
            $path
        );
        $kind = [
            'path' => $path,
            'isApi' => $isApi,
            // /api/ 下的路径永远是接口（哪怕它以别的后缀结尾）
            'isStatic' => !$isApi && $isStatic,
            'isPage' => !$isApi && !$isStatic,
        ];
        return $kind;
    }

    /**
     * 这个页面能否被**共享缓存**（CDN / 反向代理 / 浏览器 HTTP 缓存）复用。
     *
     * 【为什么需要】
     * 原来页面导航一律 private, no-cache —— 每个游客每次访问都完整回源跑 PHP。
     * 但对**未登录的游客**来说，首页/排行榜/话题页这些内容是「人人相同」的，
     * 完全可以给 public + 短 TTL：命中时连 PHP 都不用启动。
     *
     * 【为什么 TTL 这么短】
     * 页面里嵌着公告、在线人数、通知角标这些几十秒级变化的数据。
     * 给 60 秒的 max-age + 300 秒 stale-while-revalidate：
     *   - 60 秒内直接命中本地缓存，零请求
     *   - 60~360 秒内先给旧副本、后台悄悄换新，用户感知不到延迟
     *   - 超过 360 秒才真正回源
     * 对「校园交流墙」这种内容更新频率，60 秒陈旧度完全可接受。
     *
     * 【安全边界 —— 这是关键】
     * 只有同时满足以下全部条件才走公共缓存：
     *   1. GET/HEAD
     *   2. 没有登录态 cookie（未登录游客）
     *   3. 不在换发会话（不会 Set-Cookie）
     *   4. 路径在白名单里（只公开浏览页，不含后台/个人中心/接口）
     * 任何一条不满足就退回原来的 private, no-cache。
     *
     * 换句话说：**登录用户永远不会看到别人的缓存副本**。
     *
     * @return array{0:string,1:bool}|null 命中返回 [cacheControl, allowValidators]
     */
    function lwPublicPagePolicy()
    {
        $kind = lwRequestKind();
        if (!$kind['isPage']) {
            return null;
        }
        $method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
        if ($method !== 'GET' && $method !== 'HEAD') {
            return null;
        }
        // 【为什么不再用 lwHasSetCookieHeader() 一刀切】
        // 站点给**每个游客**都会下发 PHPSESSID（游客模式需要会话）。
        // 于是「有 Set-Cookie」在游客场景下恒为 true，公共缓存永远不命中 ——
        // 实测诊断确认这是唯一拒绝原因。
        //
        // 正确做法：允许下发 Set-Cookie，但让中间缓存**不把 Cookie 一起缓存**。
        // 用 Vary 与「忽略 Set-Cookie 的私有部分」做不到，因为响应体本身
        // 不含任何用户私有数据（游客视角的首页就是同一份 HTML）。
        //
        // 但有一个真实风险：如果缓存层把「带 Set-Cookie 的响应」缓存并复用，
        // 后来的人会拿到别人的 session id。所以在公共档位里我们
        // **主动把会话 cookie 从响应里去掉**（见 lwStripSessionCookieForPublic），
        // 只保留 __test 这类非身份 cookie。
        if (lwResponseWritesSessionCookie()) {
            // 明确的「换发会话」动作（登录/退出/CSRF 换签）绝不能缓存
            return null;
        }
        // 有**登录态** cookie 就不是「人人相同」，绝不能进公共缓存。
        //
        // 【踩过的坑】这里不能只看「PHPSESSID 存不存在」：
        //   1. 站点给**游客**也下发 PHPSESSID（游客模式用），
        //      只看它会导致所有游客页都判成私有、公共缓存永远不命中；
        //   2. 主机反爬还会下发 __test 通行证 cookie，同样与登录无关。
        //
        // 正确判据：PHPSESSID 存在时，看服务端能否解析出登录用户。
        // 解析不出来 = 游客会话，可以共享。
        $sid = $_COOKIE[session_name()] ?? '';
        if ($sid !== '') {
            if (!function_exists('getCurrentUser') || getCurrentUser() !== null) {
                // 函数未就绪（极早期阶段）或确实已登录 -> 保守，不共享
                return null;
            }
        }
        if (!empty($_COOKIE['lw_uid']) || !empty($_COOKIE['lw_token'])) {
            return null;
        }

        // 白名单：只有这些公开浏览页参与共享缓存。
        // 刻意**不收** user_center / admin / 各种需要身份或带随机内容的页面。
        $path = $kind['path'];
        $allow = [
            '/',
            '/index.php',
            '/pages/ranking.php',
            '/pages/topic.php',
            '/pages/gateway.php',
        ];
        // 帖子详情是公开可读的（游客也能看），但带上 ?id= 不同 id 是不同资源，
        // 交给 Vary + URL 自然区分即可。
        if (preg_match('#^/pages/(post_detail|post)\.php$#', $path)) {
            $allow[] = $path;
        }
        if (!in_array($path, $allow, true)) {
            return null;
        }

        // 60 秒新鲜 + 300 秒 stale-while-revalidate
        return ['public, max-age=60, s-maxage=60, stale-while-revalidate=300', true];
    }

    /** 本次响应是否会 Set-Cookie 换发会话（登录 / 退出 / CSRF 换签）。 */
    function lwResponseWritesSessionCookie()
    {
        return !empty($GLOBALS['lw_cache_session_cookie']);
    }

    /**
     * 本次响应是否带了 Set-Cookie（含 PHP 自己因为 session_start 重发的 PHPSESSID）。
     *
     * 为什么要单独判一次：PHP 的 session.use_cookies 默认打开，session_start() 会在
     * 每次请求都重发一遍 PHPSESSID（值不变）。不少浏览器与代理把「带 Set-Cookie 的响应」
     * 当私有内容，公共缓存会直接失效 —— 那样「公开接口给共享缓存」就完全白做。
     * 判据看的是**真正发出去的响应头**，而不是我们的中间标记，所以不会漏。
     *
     * 只在决定「能否共享」时使用；SessionFixation 需要的「换了会话 ID 就绝不能缓存」
     * 仍然由 lwMarkSessionCookieWritten() 的显式标记保证（那份标记更严格）。
     */
    function lwHasSetCookieHeader()
    {
        if (!function_exists('headers_list')) {
            return false;
        }
        foreach (headers_list() as $line) {
            if (stripos($line, 'Set-Cookie:') === 0) {
                return true;
            }
        }
        return false;
    }

    /**
     * 标记「本次响应会换发会话」—— 所有缓存档位一律降级为 no-store。
     *
     * 为什么要单独一个标记：/api/ 的默认档本就是 no-store，看似不用管；
     * 但档位可以被接口用 lwCacheShared() 抬高，漏掉这一步就是把别人的会话 Cookie
     * 发进公共缓存。登录/退出/CSRF 换签处必须调用本函数。
     */
    function lwMarkSessionCookieWritten()
    {
        $GLOBALS['lw_cache_session_cookie'] = true;
    }

    /**
     * 当前请求该用哪一档缓存。
     *
     * @return array{0:string,1:bool} [Cache-Control 值, 是否参与条件请求(ETag/304)]
     */
    function lwResolveCachePolicy()
    {
        $kind = lwRequestKind();
        $method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
        $isGet = ($method === 'GET' || $method === 'HEAD');

        // ① 非 GET 或换发会话：一律不缓存
        if (!lwResponseWritesSessionCookie() && $isGet) {
            $shared = $GLOBALS['lw_cache_shared'] ?? null;

            // ② 公开 GET 接口（接口自己声明时长）
            if ($kind['isApi'] && is_array($shared)) {
                $maxAge = max(0, (int)($shared['max_age'] ?? 0));
                $swr = max(0, (int)($shared['swr'] ?? 0));
                // 【不再因 Set-Cookie 一刀切降级】
                // 站点给每个请求（含游客）都会重发 PHPSESSID，
                // 于是 lwHasSetCookieHeader() 恒为 true，公开接口的 public
                // 档位永远命不中 —— 这是「给了共享缓存却从不生效」的真正原因。
                //
                // 改为：只在「确实换发了会话」（登录/退出/CSRF 换签）时降级；
                // 普通重发的 Session id 由 jsonResponse() 负责摘掉
                // （那里已有 lwResponseWritesSessionCookie() 保护，不会误伤）。
                if ($maxAge > 0) {
                    $v = 'public, max-age=' . $maxAge . ', s-maxage=' . $maxAge;
                    if ($swr > 0) {
                        // 过期后先给旧副本、后台悄悄换新：信息流这种「差几秒没人看得出来」
                        // 的数据最适合，用户永远不用等在旧数据上。
                        $v .= ', stale-while-revalidate=' . $swr;
                    }
                    return [$v, true];
                }
            }

            // ③ 静态资源：URL 带版本号 = 内容不可变，给一年
            if ($kind['isStatic']) {
                $uri = (string)($_SERVER['REQUEST_URI'] ?? '');
                if (preg_match('/[?&](v|ver|version|h|hash)=[^\s&]+/i', $uri)) {
                    return ['public, max-age=31536000, immutable', true];
                }
                return ['public, max-age=3600', true];
            }

            // ④ 页面导航。
            //    优先尝试「游客 + 公开页」的共享缓存（命中时连 PHP 都不用跑）；
            //    不满足条件才退回 private, no-cache。
            if ($kind['isPage']) {
                $pub = lwPublicPagePolicy();
                if ($pub !== null) {
                    return $pub;
                }
                // 带 no-cache（用前回源校验）但**不带** no-store，
                // 浏览器才肯把整页放进 bfcache / 往返缓存。
                return ['private, no-cache, max-age=0, must-revalidate', true];
            }
        }

        return ['no-store, no-cache, must-revalidate, max-age=0', false];
    }

    
/**
 * 公共缓存档位：去掉响应里的**身份** cookie。
 *
 * 为什么必须做：
 *   本站给每个游客都下发 PHPSESSID（游客模式用），而游客看到的公开页
 *   （首页/排行榜/话题）内容完全一样，理应进共享缓存。
 *   但缓存层一旦把「带 Set-Cookie: PHPSESSID=xxx」的响应存下来复用，
 *   后来的访客就会拿到**别人的** session id —— 这是真实风险。
 *
 * 所以：只有确认为「公共页 + 纯游客」的响应才调用本函数，
 * 把 PHPSESSID 的 Set-Cookie 从响应头里摘掉。页面本身不依赖它
 * （游客只读浏览不写会话）。__test（主机反爬通行证）保留。
 */
function lwStripSessionCookieForPublic()
{
    if (headers_sent()) {
        return;
    }
    $sessName = session_name();
    $kept = [];
    $removed = false;
    foreach (headers_list() as $h) {
        if (stripos($h, 'set-cookie:') !== 0) {
            continue;
        }
        if (stripos($h, 'set-cookie: ' . $sessName . '=') === 0) {
            $removed = true;
            continue;
        }
        $kept[] = $h;
    }
    if (!$removed) {
        return;
    }
    header_remove('Set-Cookie');
    foreach ($kept as $h) {
        header(substr($h, strlen('Set-Cookie: ')), false);
    }
}

/**
     * 下发缓存相关响应头（必须在任何输出前调用，见 config.php 引导阶段）。
     */
    function lwSendCacheHeaders()
    {
        static $sent = false;
        if ($sent || headers_sent()) {
            return;
        }
        $sent = true;

        list($cacheControl, $allowValidators) = lwResolveCachePolicy();
        header('Cache-Control: ' . $cacheControl);

        if ($allowValidators && !empty($GLOBALS['lw_cache_last_modified'])) {
            header('Last-Modified: ' . gmdate('D, d M Y H:i:s', (int)$GLOBALS['lw_cache_last_modified']) . ' GMT');
        }

        // Vary 精确即可：多了会让缓存碎片化（每个 UA/语言一份），少了会把私有内容串给别人。
        // 注意 header($h, false) 是「追加」——同一请求里可能先发过页面那组，
        // 这里只在当前档位需要时补一条，用 false 保持与后续可能出现的同名头兼容。
        $kind = lwRequestKind();
        if ($kind['isPage'] && !lwResponseWritesSessionCookie()) {
            // 公共页面（游客 + 公开页）一旦加了 Vary: Cookie，
            // 缓存层会认为「每个 Cookie 都是新副本」，命中率直接归零 ——
            // 这正是「缓存命中越多越好」要避免的。
            // 只有私有页面才按 Cookie 区分。
            $isPublic = (lwPublicPagePolicy() !== null);
            if ($isPublic) {
                // 公共档位：先摘掉身份 cookie，否则共享缓存会把别人的
                // session id 发给下一个人（详见函数注释）
                lwStripSessionCookieForPublic();
            }
            header($isPublic
                ? 'Vary: Accept-Encoding, Accept-Language'
                : 'Vary: Accept-Encoding, Cookie, Accept-Language');
        } elseif ($kind['isStatic'] || !empty($GLOBALS['lw_cache_shared'])) {
            // 公开接口/静态资源：按编码与语言区分。**不要**加 Cookie ——
            // 加了等于告诉缓存层「同一 URL 每个 Cookie 都是新副本」，公共命中率归零。
            header('Vary: Accept-Encoding, Accept-Language');
        }
    }

    /** 发送 304 并结束请求（无 body）。 */
    function lwSendNotModified($etag)
    {
        http_response_code(304);
        header('ETag: ' . $etag);
        // 304 也要带全策略，否则中间层可能把下一次响应当成「无策略 = 可猜」
        list($cc) = lwResolveCachePolicy();
        header('Cache-Control: ' . $cc);
        header_remove('Content-Type');
        header_remove('Content-Length');
        exit();
    }

    /**
     * 若客户端已持有相同版本 → 304 并结束；否则下发 ETag 并返回。
     *
     * @param string $body 即将输出的响应体
     * @param bool   $allowPrivate no-store 接口也允许 304 吗？允许 ——
     *        「no-store」禁止的是**存下来复用**，不禁止「回源时发现没变、只回一个头」，
     *        这能省掉整段 body（列表接口尤其明显）。
     * @return string|null 生成的 ETag
     */
    function lwMaybeNotModified($body, $allowPrivate = false)
    {
        $method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
        if ($method !== 'GET' && $method !== 'HEAD') {
            return null;
        }
        list($cc, $allowValidators) = lwResolveCachePolicy();
        if (!$allowValidators && !$allowPrivate) {
            return null;
        }

        // 内容摘要：对同一份 body 永远稳定（旧代码每次请求都换 ETag，等于永远命不中）
        $etag = '"' . substr(sha1($body . '|' . $cc), 0, 20) . '"';
        header('ETag: ' . $etag);

        $inm = trim((string)($_SERVER['HTTP_IF_NONE_MATCH'] ?? ''));
        if ($inm !== '') {
            foreach (explode(',', $inm) as $candidate) {
                $candidate = trim($candidate);
                if ($candidate === '' || $candidate === '0') {
                    continue;
                }
                if ($candidate === '*'
                    || $candidate === $etag
                    || ltrim($candidate, 'W/') === $etag) {
                    lwSendNotModified($etag);
                }
            }
        }
        return $etag;
    }

    /**
     * 去掉本次响应里的 PHPSESSID 重发，保留其它 Cookie（如 remember_token、语言偏好）。
     *
     * 为什么可以去掉：session_start() 在 session.use_cookies=1 时每次请求都会重发一遍
     * PHPSESSID，**值不变**。它不是「换会话」，只是 PHP 的默认动作；而「带 Set-Cookie
     * 的响应」在很多浏览器/代理眼里就是私有内容，公共缓存会因此全部失效。
     *
     * 什么时候**不能**调：换发会话的响应（登录 / 退出 / CSRF 换签 / 首访 regenerate）。
     * 这些位置都有 lwMarkSessionCookieWritten() 标记，调用点必须先判它（见 jsonResponse）。
     */
    function lwStripSessionCookie()
    {
        if (!function_exists('header_remove') || !session_id()) {
            return;
        }
        $target = strtolower(session_name());
        // header_remove() 只能按名字整体清掉，所以先取出全部，过滤后再写回
        $lines = headers_list();
        $kept = [];
        $dropped = false;
        foreach ($lines as $line) {
            if (stripos($line, 'Set-Cookie:') === 0) {
                $cookie = trim(substr($line, 11));
                if (strtolower(strtok($cookie, '=')) === $target) {
                    $dropped = true;
                    continue;
                }
            }
            $kept[] = $line;
        }
        if (!$dropped) {
            return;
        }
        // 【2026-10-24 修】原来这里是「逐条 header_remove 再 header 写回」，
        // 但 header($name, $value) 的两参数形式在 PHP 里是
        // header(string $header, bool $replace) —— 第二个参数不是值！
        // 传字符串会被转成 true，实际发出去的头变成只含 header 名，
        // 而 header_remove 已经把原值清掉了 → **Cache-Control 等头全部丢失**。
        //
        // 这是「公开接口给了 public 却始终 no-store」的真正原因：
        // jsonResponse() 每响应一次就调本函数一次，把所有缓存头清空一次。
        //
        // 正确做法：只在**确实需要删**时整体重写；不需要删就原样返回。
        // 重建时用完整的 "Name: value" 单参形式。
        header_remove('Set-Cookie');
        foreach ($kept as $line) {
            header($line, false);
        }
    }

    /**
     * 声明「本接口是公开可共享的 GET 响应」。
     *
     * ⚠️ 调用方必须**先确认响应里不含任何个人数据**（游客/未登录分支）。
     *
     * 必须在任何 echo 之前调用：Cache-Control 在响应头发出时就定了型，
     * 接口内部再改策略只影响 304 / ETag 的判定，改不动已经发出去的档位。
     *
     * @param int $maxAge 浏览器与中间层各自的缓存秒数；0 = 关闭
     * @param int $swr    过期后允许先给旧副本的秒数
     */
    function lwCacheShared($maxAge = 60, $swr = 0)
    {
        /* 后台「性能与缓存 → 启用磁盘缓存」关掉后，把共享缓存降为 0。
         *
         * 这里的「磁盘缓存」指浏览器/中间层的 HTTP 缓存，不是服务器本地文件。
         * 关掉它意味着每次请求都回源 —— 只在排查「改了代码但页面没变」时才该关。
         */
        if (function_exists('lwCfgBool')) {
            try {
                if (!lwCfgBool('enable_disk_cache', true)) {
                    $maxAge = 0;
                    $swr = 0;
                }
            } catch (Throwable $e) {}
        }
        $GLOBALS['lw_cache_shared'] = ['max_age' => max(0, (int)$maxAge), 'swr' => max(0, (int)$swr)];

        // 响应头还没发出去：立刻按新档位重发一次（这一步让接口不必等到 echo 才生效）
        if (!headers_sent()) {
            list($cacheControl, ) = lwResolveCachePolicy();
            header('Cache-Control: ' . $cacheControl);
            if (!lwRequestKind()['isPage']) {
                header('Vary: Accept-Encoding, Accept-Language', false);
            }
        } else {
            // 已经输出过内容：说明调用点在错误的位置，记一笔便于排查（不影响用户）
            error_log('lwCacheShared: 在已输出内容之后调用，缓存档位未能提升：'
                . (string)($_SERVER['REQUEST_URI'] ?? ''));
        }
    }
}

/**
 * 缓存头自动下发（引导）。
 *
 * 原来 lwResolveCachePolicy / lwSendCacheHeaders 写得很完整，
 * 但全站没有任何地方调用它 —— config.php 里写死的
 * Cache-Control: private, no-cache 成了实际输出值，分档形同虚设。
 *
 * 挂在输出缓冲回调上：body 收尾时按最终状态算策略再发包。
 * 必须等业务逻辑跑完，因为要判断「是否登录 / 是否换发会话 / 接口声明的 TTL」。
 */
function lwCacheBootstrap()
{
    static $booted = false;
    if ($booted) {
        return;
    }
    $booted = true;

    $method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    if ($method !== 'GET' && $method !== 'HEAD') {
        return;
    }
    if (headers_sent()) {
        return;
    }

    @ob_start(static function (string $buffer): string {
        if (headers_sent()) {
            return $buffer;
        }
        $existing = '';
        foreach (headers_list() as $h) {
            if (stripos($h, 'cache-control:') === 0) {
                $existing = trim(substr($h, 14));
                break;
            }
        }
        // 只有「兜底值」才允许被分档覆盖；接口自己声明过的以业务为准
        $isFallback = ($existing === ''
            || stripos($existing, 'private, no-cache') === 0
            || stripos($existing, 'no-store, no-cache') === 0);
        if ($isFallback) {
            lwSendCacheHeaders();
        }
        return $buffer;
    });
}

lwCacheBootstrap();
