/* ==========================================================================
 * ColorOS 式丝滑动效  motion-plus.js
 * ==========================================================================
 *
 * 配套 motion-plus.css。做四件事：
 *   1. 页面主体淡入
 *   2. 卡片滚动渐显 + 错峰
 *   3. 图片加载淡入（不再「啪」地闪出来）
 *   4. 给可点元素挂无缝按压回弹
 *
 * --------------------------------------------------------------------------
 * 【为什么「加 js-motion 类」不在这里做】
 * --------------------------------------------------------------------------
 * 这个脚本在 body 末尾加载，那时正文已经渲染完一帧了。
 * 如果在这里才加 js-motion，用户会看到「内容出现 → 消失 → 再淡入」的闪烁。
 *
 * 所以 js-motion 由 pwa_head.php 里的同步脚本加（在 <head>，早于 body）。
 * 本文件只负责「哪些元素动、什么时候动」。
 *
 * --------------------------------------------------------------------------
 * 【渐进增强】
 * --------------------------------------------------------------------------
 * 所有动画类都由本脚本添加。脚本没跑 / 报错 / JS 被禁用时，
 * 元素保持默认可见 —— 不会出现「内容永远透明」的白屏。
 * ========================================================================== */

(function () {
    "use strict";

    var docEl = document.documentElement;

    /* 兜底：万一 head 里那段没执行（比如被缓存成旧页面），这里补一次。
     * 此时可能已经晚了一帧，但总比完全没有动画好。 */
    docEl.classList.add("js-motion");

    var reduceMotion = window.matchMedia("(prefers-reduced-motion: reduce)").matches;
    if (reduceMotion) {
        return;  /* 用户明确关了动画：什么都不做，内容保持可见 */
    }

    /* ---- 低端机：缩短动画、取消错峰 ---- */
    var cores = navigator.hardwareConcurrency || 4;
    var mem = navigator.deviceMemory || 4;
    if (cores <= 4 && mem <= 4) {
        docEl.classList.add("mp-lite");
    }

    /* ======================================================================
     * 一、页面主体淡入
     * ======================================================================
     * 只选一个主体容器，不碰顶栏/底栏（它们应该立刻稳定）。
     * ====================================================================== */

    function pageEnter() {
        /* 【已停用】整页淡入改由 CSS 负责（见 motion-plus.css 第九节）。
         *
         * 原来这里给 .site-main 加 mp-page（opacity:0），两帧后再显示。
         * 但脚本是在页面**已绘制**之后才跑的 ——
         * 用户会看到「内容出现 → 消失 → 淡入」的一闪，比没有动画更糟。
         *
         * 现在首屏卡片由 CSS animation 负责（CSS 阻塞渲染，不会闪），
         * 视口外的元素由下面的 IntersectionObserver 负责。
         */
    }

    /* ======================================================================
     * 二、滚动渐显 + 错峰
     * ======================================================================
     * 容器选择器覆盖前台的主要列表/卡片。
     * 后台（admin）不加 —— 后台要的是「快」，入场动画反而碍事。
     * ====================================================================== */

    var REVEAL_SEL = [
        ".post-card",
        ".tool-card",
        ".comment-item",
        ".notification-item",
        ".section",
        ".stat-card",
        ".chart-card",
        ".uc-card",
        ".disc-card",
        ".sr-item",
        ".faq-item",
        /* 详情页分段：标题区 / 正文 / 图片 / 操作栏 / 评论区 依次入场。
         * 这是「点进详情」最能体现 App 感的地方 —— 整页不是一次性糊上来，
         * 而是从上到下逐段落位。首屏可见的元素会由观察器立刻触发，
         * 所以不需要额外处理「首屏」这种情况。 */
        ".post-detail-header",
        ".post-detail-content",
        ".post-images",
        ".post-detail-actions",
        ".post-detail-poll"
    ].join(",");

    /* 如果是后台页面（body 有 admin 标记或路径含 /admin/），直接跳过所有入场动画 */
    var isAdmin = /\/admin\//.test(location.pathname) ||
                  document.body.classList.contains("admin-body") ||
                  !!document.querySelector(".sidebar-nav");

    var io = null;

    function initReveal() {
        if (isAdmin) { return; }

        if (!("IntersectionObserver" in window)) {
            /* 老浏览器：不做渐显（内容照常显示） */
            return;
        }

        io = new IntersectionObserver(function (entries) {
            /* 只处理这一批里「刚进入视口」的，按顺序错峰 */
            var incoming = [];
            for (var i = 0; i < entries.length; i++) {
                if (entries[i].isIntersecting) {
                    incoming.push(entries[i].target);
                }
            }
            if (!incoming.length) { return; }

            /* 按 DOM 顺序排，保证错峰方向是从上到下（符合阅读顺序） */
            incoming.sort(function (a, b) {
                if (a === b) { return 0; }
                return (a.compareDocumentPosition(b) & 4) ? -1 : 1;
            });

            for (var k = 0; k < incoming.length; k++) {
                (function (el, idx) {
                    /* 最多错开 8 个，再往后就不延迟了 —— 否则一屏 20 张卡要等 1 秒 */
                    var delay = Math.min(idx, 8) * 45;
                    el.style.transitionDelay = delay + "ms";
                    el.classList.add("mp-in");
                    if (io) { io.unobserve(el); }
                    /* 动画结束后清掉内联 delay，避免影响后续交互的 transition */
                    setTimeout(function () {
                        el.style.transitionDelay = "";
                    }, 420 + delay + 60);
                })(incoming[k], k);
            }
        }, {
            threshold: 0.06,
            rootMargin: "0px 0px -24px 0px"
        });

        observeAll(document);
    }

    function inViewport(el) {
        var r = el.getBoundingClientRect();
        var vh = window.innerHeight || 800;
        /* 视口内（含下方一点点提前量）的元素不做渐显 ——
         * 给已绘制的元素加隐藏类必然闪一下。
         * 它们要么由 CSS 直接入场（.ssr-card），要么本来就该立刻可见。 */
        return r.top < vh * 1.08 && r.bottom > -40;
    }

    /* 收集 root 自身（若匹配）及其子树里匹配的元素。
     *
     * 【为什么需要这个】
     * 动态加载时常把整张卡片作为新节点插进来，
     * 只扫 root.querySelectorAll 会漏掉 root 自己。
     * 但也不能因此退化成「每次都扫全文档」—— 那是下面修掉的性能问题。
     */
    function collect(root, sel) {
        var out = [];
        if (!root) { return out; }
        if (root !== document && root.nodeType === 1 && root.matches && root.matches(sel)) {
            out.push(root);
        }
        if (root.querySelectorAll) {
            var list = root.querySelectorAll(sel);
            for (var i = 0; i < list.length; i++) { out.push(list[i]); }
        }
        return out;
    }

    function observeAll(root) {
        if (!io) { return; }
        var list = collect(root, REVEAL_SEL);
        for (var i = 0; i < list.length; i++) {
            var el = list[i];
            if (el.classList.contains("mp-reveal") || el.classList.contains("mp-page")) { continue; }
            /* 首屏 SSR 卡片已由 CSS animation 负责，再加 mp-reveal 会打架 */
            if (el.classList.contains("ssr-card")) { continue; }
            /* 顶栏/底栏/浮窗里的东西不动 */
            if (el.closest && el.closest(".site-header, .mobile-bottom-nav, .lw-ai, .toast-container")) { continue; }
            /* 视口内的不动（避免闪烁） */
            if (inViewport(el)) { continue; }
            el.classList.add("mp-reveal");
            io.observe(el);
        }
    }

    /* ======================================================================
     * 三、图片淡入
     * ======================================================================
     * 只处理「还没有 src 加载完成」的图；已缓存的图（complete=true）直接显示，
     * 否则会看到刷一下才出现的空白。
     * ====================================================================== */

    function initImages(root) {
        var imgs = collect(root || document, "img");
        for (var i = 0; i < imgs.length; i++) {
            var img = imgs[i];
            if (img.classList.contains("mp-img")) { continue; }
            /* 头像、图标、装饰图不参与（它们小，闪一下不明显，加了反而多一层） */
            if (img.closest && img.closest(".site-header, .mobile-bottom-nav, .user-avatar, .post-card-avatar")) { continue; }
            if (img.width > 0 && img.width < 64) { continue; }

            img.classList.add("mp-img");
            if (img.complete && img.naturalWidth > 0) {
                img.classList.add("mp-in");
            } else {
                img.addEventListener("load", function () {
                    this.classList.add("mp-in");
                }, { once: true });
                img.addEventListener("error", function () {
                    this.classList.add("mp-in");   /* 加载失败也要显形，不然后续空着 */
                }, { once: true });
            }
        }
    }

    /* ======================================================================
     * 四、按压回弹
     * ======================================================================
     * 给可点元素统一挂 .mp-press（CSS 里定义按下 70ms / 松开 260ms）。
     * 只选「真的会因为点击而跳转或提交」的元素，不给每个 div 都挂（省性能）。
     * ====================================================================== */

    var PRESS_SEL = [
        ".btn",
        ".tool-card",
        ".post-card",
        ".notice-item",
        ".notification-item",
        ".comment-item",
        ".filter-btn",
        ".sr-tab",
        ".faq-q",
        ".uc-nav-item",
        ".mobile-nav-item"
    ].join(",");

    function initPress(root) {
        var list = collect(root || document, PRESS_SEL);
        for (var i = 0; i < list.length; i++) {
            var el = list[i];
            if (el.classList.contains("mp-press")) { continue; }
            el.classList.add("mp-press");
            /* 面积大的卡片用更保守的缩放，否则边缘会露出底色 */
            if (el.matches(".post-card, .tool-card, .section")) {
                el.classList.add("mp-press-lg");
            }
        }
    }

    /* ======================================================================
     * 五、动态内容（AJAX 加载更多 / 打开弹层）
     * ======================================================================
     * 列表是分页加载的，新卡片插入时不会自动被 IntersectionObserver 看到。
     * 用 MutationObserver 监听，新增节点就补挂类。
     *
     * 节流：连续插入 20 张卡只处理一次。
     * ====================================================================== */

    /* 【性能：只处理新增节点，不全文档重扫】
     *
     * 原实现是「每次 DOM 变化 → 节流 120ms → 对 document 做三次
     * querySelectorAll（REVEAL_SEL / PRESS_SEL / img）」。
     *
     * 首页 SSR 就有几十张卡片、上百个 img；每加载一页评论、每次 toast、
     * 每次动画类切换都会唤醒 observer。整页重扫一遍是实打实的 CPU 开销，
     * 长列表滚动时会掉帧 —— 那比没有动画更不「丝滑」。
     *
     * 现在只把「新插入的元素」记下来，只在这些子树里找。
     * 同时用 Set 去重，避免同一个节点被多次入队。
     */
    var moTimer = 0;
    var pendingRoots = [];
    var seenRoots = typeof WeakSet === "function" ? new WeakSet() : null;

    function initDynamicWatch() {
        if (!("MutationObserver" in window)) { return; }

        var mo = new MutationObserver(function (records) {
            for (var i = 0; i < records.length; i++) {
                var added = records[i].addedNodes;
                if (!added || !added.length) { continue; }
                for (var j = 0; j < added.length; j++) {
                    var n = added[j];
                    if (n.nodeType !== 1) { continue; }
                    if (seenRoots) {
                        if (seenRoots.has(n)) { continue; }
                        seenRoots.add(n);
                    }
                    pendingRoots.push(n);
                }
            }
            if (moTimer || !pendingRoots.length) { return; }
            moTimer = setTimeout(flushRoots, 120);
        });

        mo.observe(document.body, { childList: true, subtree: true });
    }

    function flushRoots() {
        moTimer = 0;
        var roots = pendingRoots;
        pendingRoots = [];
        for (var k = 0; k < roots.length; k++) {
            var r = roots[k];
            /* 已经不在文档里了（比如被替换掉）就跳过 */
            if (!r.isConnected) { continue; }
            observeAll(r);
            initPress(r);
            initImages(r);
        }
    }

    /* ======================================================================
     * 启动
     * ====================================================================== */

    function boot() {
        pageEnter();
        initReveal();
        initPress(document);
        initImages(document);
        initDynamicWatch();

        /* 兜底：万一 IntersectionObserver 失效（或元素在奇怪的位置），
         * 3 秒后把所有还没显示的渐显元素强制显示出来。
         * 宁可少一次动画，也不能让内容永远透明。 */
        setTimeout(function () {
            var stuck = document.querySelectorAll(".mp-reveal:not(.mp-in)");
            for (var i = 0; i < stuck.length; i++) {
                stuck[i].classList.add("mp-in");
            }
        }, 3000);
    }

    if (document.readyState === "loading") {
        document.addEventListener("DOMContentLoaded", boot, { once: true });
    } else {
        boot();
    }

    /* 暴露一个手动刷新入口，供 AJAX 代码在插入内容后主动调用 */
    window.LWMotion = {
        refresh: function () {
            observeAll(document);
            initPress(document);
            initImages(document);
        }
    };

})();
