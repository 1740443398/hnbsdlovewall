<?php
/**
 * 「添加到主屏幕」（PWA）相关的 <head> 元信息 —— 唯一来源，带标准 <head> 的页面 require 本文件。
 *
 * 为什么抽出来并铺到各页面：iOS 的「添加到主屏幕」读的是**当前页面**的 apple-* 元信息，
 * 漏加 tag 的页面会被降级成普通 Safari 书签（带地址栏），所以主要浏览页都要覆盖到。
 *
 * 两个实现细节：
 *  1) manifest 用 .webmanifest 后缀而不是 .json —— 站内 WAF 把「以 .json 结尾的 URI」一律
 *     当作敏感文件探测拦掉，用 .json 会被自己的防护打了个正着。
 *  2) apple-mobile-web-app-capable 的旧写法必须保留：iOS 17 之前只认它，新写法在旧系统上无效。
 *
 * 另外在这里注册 Service Worker（/sw.js）：Chrome 只有在「manifest 合格 + HTTPS +
 * 带真实 fetch 事件的 SW」都满足时才会派发 beforeinstallprompt（也就是用户下拉菜单里
 * 「安装应用」能直接唤起系统安装框的前提），缺 SW 时那个入口只能退化成手动安装指引。
 */
$pwaIconVer = function_exists('asset_ver') ? asset_ver('/assets/images/icons/icon-192.png') : '1';
$pwaSwVer = function_exists('asset_ver') ? asset_ver('/sw.js') : '1';
// E1：apple-touch-icon 也走 asset_url()，与其他静态资源保持同一条版本化通道
$pwaAppleIconUrl = function_exists('asset_url') ? asset_url('/assets/images/icons/apple-touch-icon.png') : '/assets/images/icons/apple-touch-icon.png';
$pwaAppleIconVer = function_exists('asset_ver') ? asset_ver('/assets/images/icons/apple-touch-icon.png') : $pwaIconVer;

// 彩蛋资源版本号（同样带 ?v=，避免浏览器缓存住旧文件）
// 路径经 asset_url() 解析：有新鲜 .min 就用 .min，没有就回退源文件
// 彩蛋的 css/js 已并入 bundle.css / bundle.js，这里只保留文案注入
// 彩蛋文案：PHP 侧取词条后注入给 easter-eggs.js，随页面语言自动切换
$pwaEggStrings = function_exists('t') ? [
    t('egg.celebrate.1'),
    t('egg.celebrate.2'),
    t('egg.celebrate.3'),
    t('egg.celebrate.4'),
    t('egg.celebrate.5'),
] : [];

// 登录用户存在服务端的主题偏好（localStorage 为空时的兜底，保证换设备后体验一致）
$lwServerTheme = '';
if (isset($user) && is_array($user) && isset($user['theme'])) {
    $lwServerTheme = (string) $user['theme'];
}
if (!in_array($lwServerTheme, ['light', 'dark', 'system'], true)) {
    $lwServerTheme = '';
}
?>
    <!-- ── 资源提示：把「第三方域名的 DNS+TLS 握手」提前到页面空闲时做 ────────────
         为什么值得加：QQ 头像（q.qlogo.cn）在信息流里一屏就有几十张，每张都要走
         完整的 DNS → TCP → TLS 握手才能拿到图。preconnect 让浏览器在解析到这行
         就把连接建好，等 <img> 真正开始加载时直接复用已建立的连接，
         首屏头像的出图时间通常能提前 100~300ms（移动网络下更明显）。
         只对本站 CSP 白名单里的域名做提示，不新增任何第三方依赖。

         E9/F16 复核（2026-10-05）：原先还挂着 fonts.gstatic.com 的 preconnect +
         dns-prefetch，但全站没有任何 @font-face 或 fonts.googleapis.com 的引用，
         字体走的是本地系统字体栈（见 style.css 的 --font-sans/--font-display）。
         等于每次首屏都白白为 Google 建一条 DNS+TCP+TLS 连接（移动网络下 100ms+），
         还把用户 IP 暴露给第三方。已整条移除，CSP 的 font-src 同步收敛为 'self'。 -->
    <link rel="preconnect" href="https://q.qlogo.cn" crossorigin>
    <link rel="preconnect" href="https://v1.hitokoto.cn">
    <!-- 老浏览器不认 preconnect 时退回 dns-prefetch，至少省掉 DNS 查询 -->
    <link rel="dns-prefetch" href="https://q.qlogo.cn">
    <link rel="dns-prefetch" href="https://v1.hitokoto.cn">

    <?php /* manifest 用 .css 后缀：主机商的反爬挑战按扩展名白名单放行（.css/.png 直通，
             .webmanifest 会被塞回 200 + HTML 挑战页），而浏览器取 manifest 是不带凭据的
             请求，必然撞上挑战页 → 控制台报 Syntax error 且 PWA 不可安装。
             内容仍是 JSON，浏览器不校验 MIME 只校验 body，详见 .htaccess 同名说明。 */ ?>
    <link rel="manifest" href="/manifest.css">
    <meta name="theme-color" content="#2F5B9A">
    <meta name="color-scheme" content="light dark">

    <?php // E3 首屏关键 CSS 内联：把首屏必需的最小样式直接写进 <head>，避免首绘等待整份 style.css 下载+解析。 ?>
    <style id="lw-critical-css">
        *,*::before,*::after{box-sizing:border-box}
        html,body{margin:0;padding:0;background:#f4f6fb;color:#1f2733;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,"PingFang SC","Microsoft YaHei",sans-serif}
        body{min-height:100vh;-webkit-text-size-adjust:100%}
        /* 图片通用防溢出。
           ⚠️ 必须排除「有尺寸契约的组件图」：这条 `height:auto` 会强行覆盖
           .lw-av-img 的 88% 高度，在固定方形的头像槽位里把照片压成扁条，
           看起来就是"头像框里只剩几根线和下面一个圆圈"。
           :not([class*=lw-]) 把头像复合件、挂件层全部放行。 */
        img{max-width:100%;height:auto}
        /* 头像复合件的基础几何：没有这一段的话 <span class="lw-av"> 只是 inline，
         * 只有 --lw-av 变量而没有宽高，在 flex 容器里会被拉伸并推挤相邻内容，
         * 表现就是「输入框左边一大块空白」。 */
        .lw-av{display:inline-block;position:relative;flex:0 0 auto;width:var(--lw-av,44px);height:var(--lw-av,44px);vertical-align:middle;border-radius:50%}
        img[class*="lw-av"], img[class*="lw-widget"], img[class*="lw-avatar"]{height:revert;max-width:none}
        .container{max-width:960px;margin:0 auto;padding:0 14px}
        .top-bar{height:56px;display:flex;align-items:center}
        .btn{padding:8px 16px;border-radius:10px}
    </style>
    <?php // 布局自适应令牌（需求三、5）：必须早于主题样式，先铺 @media 断点再谈配色 ?>
    <?php require_once __DIR__ . '/layout_tokens.php'; ?>
    <?php // 低配设备降级层（需求三、7）：越早越好，避免先按满血样式画一帧再掉回去 ?>
    <?php require_once __DIR__ . '/lowperf.php'; ?>
    <script>window.LW_SERVER_THEME = <?= json_encode($lwServerTheme) ?>;</script>
<?php require_once __DIR__ . '/theme_boot.php'; ?>
    <!-- 主屏应用：全屏运行 + 图标 + 名称 -->
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="default">
    <meta name="apple-mobile-web-app-title" content="校园交流墙">
    <link rel="apple-touch-icon" sizes="180x180" href="<?= $pwaAppleIconUrl ?>?v=<?= $pwaAppleIconVer ?>">
    <script>
        // 注册 Service Worker（离线兜底 + 让「安装应用」具备可安装性）。
        // 失败不影响任何功能，仅少一个离线页。
        // 带 ?v= 版本号：宿主/CDN 可能把旧 sw.js 缓存住，改脚本内容后旧版迟迟换不掉；
        // 换 URL 会强制浏览器重新拉取并按新版更新（查询串不影响注册作用域，仍是 /）。
        //
        // 为什么还要 reg.update() + 自动刷一次：
        //   本站主机带反爬挑战，旧版 SW 曾把主机挑战页（200 + text/html）当成 JS/CSS 缓存下来，
        //   之后一直「HTML 当脚本」喂给页面 —— 表现为 AI 助手与图标一直不显示、怎么刷新都不好，
        //   因为缓存在网络之前先命中，服务器正常了也轮不到它。
        //   这里主动拉一次新 SW；新 SW 的 activate 会删掉全部旧缓存（含被污染的那份），
        //   接管后自动刷新一次页面，用户无需手动清站点数据。同一版本只自动刷一次，避免循环。
        if ('serviceWorker' in navigator) {
            var LW_SW_TAG = '<?= $pwaSwVer ?>';
            window.addEventListener('load', function () {
                navigator.serviceWorker.register('/sw.js?v=' + encodeURIComponent(LW_SW_TAG)).then(function (reg) {
                    if (reg && typeof reg.update === 'function') { reg.update().catch(function () {}); }
                    if (!navigator.serviceWorker.controller) { return; }
                    navigator.serviceWorker.addEventListener('controllerchange', function () {
                        try {
                            if (sessionStorage.getItem('lw_sw_reload') === LW_SW_TAG) { return; }
                            sessionStorage.setItem('lw_sw_reload', LW_SW_TAG);
                        } catch (e) { /* 隐私模式禁用 sessionStorage：宁可不刷，也不要循环 */ return; }
                        location.reload();
                    });
                }).catch(function () {});
            });
        }
    </script>
    <!-- 彩蛋：样式 + 文案 + 脚本。用绝对路径引入，index.php 与 pages/ 下的页面共用本片段。
         文案先于脚本声明（脚本 defer，解析完才执行），后台 /admin 下由脚本自身直接跳过。 -->
    <?php
    /* 彩蛋的 css/js 已经并进 bundle.css / bundle.js，不再单独加载。
     * 只保留文案注入 —— easter-eggs.js 会读 window.LW_EGG_STRINGS。
     *
     * 【历史教训】之前清理时只删了变量定义、忘了删这里的引用，
     * 于是每个页面都刷一堆 "Undefined variable $pwaEggCssUrl" 的警告。
     * PHP 警告会写日志、也拖慢响应，这种疏漏比不优化更糟。
     */
    ?>
    <script>
        window.LW_EGG_STRINGS = <?= json_encode($pwaEggStrings, JSON_UNESCAPED_UNICODE) ?>;
    </script>

    <?php
    // ── 体验增强模块（polish）────────────────────────────────────────────
    // 返回顶部 / 阅读进度 / 字号调节 / 快捷键面板 / 图片灯箱 / 复制链接。
    // 全部是「有则更好」的增强：JS 挂了不影响任何主功能，所以直接全站引入。
    // 后台不需要（后台有自己的交互体系，混入反而干扰）。
    $lwIsAdminArea = defined('LW_ADMIN_AREA') || strpos($_SERVER['REQUEST_URI'] ?? '', '/admin/') === 0;
    if (!$lwIsAdminArea) :
    ?>
    <?php
    /* polish 的 css/js 已经并进 bundle.css / bundle.js，这里不再单独加载。
     *
     * 原来这里还有一个 preload + defer 脚本，等于同样的内容下载两遍 ——
     * 在主机的 ~950ms/请求 环境下，这一个重复直接吃掉 2 秒。 */
    ?>
    <?php
    // ── 全局液态玻璃（liquid-glass）───────────────────────────────────────
    // 独立作用域样式 + Canvas 流体引擎。非首屏必需，异步加载；
    // 总开关默认开启，用户可在个人中心关闭（自动回退 MIUIX 主题）。
    ?>
    <?php /* liquid-glass 的样式已经在 bundle.css 里，不再单独 preload —— 少一个请求 */ ?>
    <?php // 双向适配：移动浏览器里提示「用 App 打开」 ?>
    <script>
    (function(){
      // 只在移动端、非 App 内、且用户没关过时提示
      var ua = navigator.userAgent || '';
      var isMobile = /Android|iPhone|iPad|iPod/i.test(ua);
      var inApp = /LoveWallApp/i.test(ua);
      if (!isMobile || inApp) return;
      try { if (localStorage.getItem('lw_app_banner_closed') === '1') return; } catch(e) {}
      if (document.getElementById('lwAppBanner')) return;

      function show(){
        var d = document.createElement('div');
        d.id = 'lwAppBanner';
        d.style.cssText = 'position:fixed;left:0;right:0;bottom:0;z-index:9997;' +
          'background:linear-gradient(135deg,#2F5B9A,#24487A);color:#fff;' +
          'padding:12px 16px;display:flex;align-items:center;gap:12px;' +
          'font-size:.85rem;box-shadow:0 -2px 16px rgba(0,0,0,.14);' +
          'padding-bottom:calc(12px + env(safe-area-inset-bottom))';
        d.innerHTML = '<div style="flex:1;line-height:1.5">' +
          '<b>用 App 打开体验更好</b><br>' +
          '<span style="opacity:.85;font-size:.78rem">支持离线缓存、消息推送、图片查看器</span></div>' +
          '<a href="/download/lovewall-v2.4.0.apk" style="background:#fff;color:#2F5B9A;' +
          'padding:8px 16px;border-radius:999px;text-decoration:none;font-weight:600;' +
          'font-size:.82rem;white-space:nowrap">下载</a>' +
          '<span id="lwAppBannerX" style="opacity:.7;padding:4px 8px;cursor:pointer;font-size:1.2rem">×</span>';
        document.body.appendChild(d);
        document.getElementById('lwAppBannerX').addEventListener('click', function(){
          d.remove();
          try { localStorage.setItem('lw_app_banner_closed', '1'); } catch(e) {}
        });
      }
      // 等页面稳定再显示，避免和首屏渲染抢资源
      if (document.readyState === 'complete') setTimeout(show, 2500);
      else window.addEventListener('load', function(){ setTimeout(show, 2500); });
    })();
    </script>

    <?php
    /* =====================================================================
     * 【资源合并：17 个请求 -> 2 个】
     * =====================================================================
     *
     * 线上主机 openresty 对每个 HTTP 请求固定要 ~950ms（跟文件大小无关），
     * 首页原来要拉 17 个独立的 css/js，光排队就 17 秒 ——
     * 用户感受到的就是「网站卡，帖子发不出去」。
     *
     * 合并成 bundle.css + bundle.js 后只剩 2 个请求，实测省 13 秒以上。
     *
     * 合并顺序、内容由 tools/lw_bundle_assets.py 维护：
     *   改了任何一个源文件，跑一次脚本重新生成即可。
     * 单独文件仍然留在磁盘上（不动它们），bundle 只是「打包引用」。
     *
     * 注意：lw_storage / lw_request_cache / lw_errorguard 必须**尽早**执行
     * （main.js 依赖它们），所以 bundle.js 不加 defer —— 与原来行为一致。
     */
    $lwBundleCss = function_exists('asset_url') ? asset_url('/assets/css/bundle.css') : '/assets/css/bundle.css';
    $lwBundleCssVer = function_exists('asset_ver') ? asset_ver('/assets/css/bundle.css') : '1';
    $lwBundleJs = function_exists('asset_url') ? asset_url('/assets/js/bundle.js') : '/assets/js/bundle.js';
    $lwBundleJsVer = function_exists('asset_ver') ? asset_ver('/assets/js/bundle.js') : '1';
    ?>
    <link rel="stylesheet" href="<?= $lwBundleCss ?>?v=<?= $lwBundleCssVer ?>">
    <script src="<?= $lwBundleJs ?>?v=<?= $lwBundleJsVer ?>"></script>
    <?php endif; ?>
