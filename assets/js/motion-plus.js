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
        var main = document.querySelector(".site-main, .page-content, main, .content-wrap");
        if (!main) { return; }
        main.classList.add("mp-page");
        /* 两帧之后再加入场类：保证浏览器先应用「隐藏」的初始状态 */
        requestAnimationFrame(function () {
            requestAnimationFrame(function () {
                main.classList.add("mp-page-in");
            });
        });
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
        ".faq-item"
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

    function observeAll(root) {
        if (!io) { return; }
        var list = root.querySelectorAll(REVEAL_SEL);
        for (var i = 0; i < list.length; i++) {
            var el = list[i];
            if (el.classList.contains("mp-reveal") || el.classList.contains("mp-page")) { continue; }
            /* 顶栏/底栏里的东西不动 */
            if (el.closest && el.closest(".site-header, .mobile-bottom-nav, .lw-ai, .toast-container")) { continue; }
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
        var imgs = (root || document).querySelectorAll("img");
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
        var list = (root || document).querySelectorAll(PRESS_SEL);
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

    var moTimer = 0;
    function initDynamicWatch() {
        if (!("MutationObserver" in window)) { return; }

        var mo = new MutationObserver(function () {
            if (moTimer) { return; }
            moTimer = setTimeout(function () {
                moTimer = 0;
                observeAll(document);
                initPress(document);
                initImages(document);
            }, 120);
        });

        mo.observe(document.body, { childList: true, subtree: true });
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
