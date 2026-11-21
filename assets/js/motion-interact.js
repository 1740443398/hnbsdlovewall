/* ==========================================================================
 * 交互细节  motion-interact.js
 * ==========================================================================
 * 配套 motion-interact.css。做三件事：
 *   1. 浮层打开前，把 transform-origin 设到触发按钮的位置
 *   2. 切分类/Tab 后，让内容区淡入
 *   3. 骨架屏换成真实内容时，让旧骨架淡出
 * ========================================================================== */

(function () {
    "use strict";

    if (window.matchMedia("(prefers-reduced-motion: reduce)").matches) {
        return;
    }

    /* ======================================================================
     * 一、浮层从触发点生长
     * ======================================================================
     * 用 capture 阶段监听（比面板自己的 handler 早），
     * 保证 transform-origin 在 display 变 block 之前就设好。
     * ====================================================================== */

    var ORIGIN_HINTS = [
        /* [触发按钮选择器, 浮层选择器, origin] */
        ["#notificationBell, .notification-bell", ".notification-panel", "top right"],
        ["#userMenuBtn, .user-menu-btn", ".user-dropdown", "top right"],
        [".header-search, .search-box", ".search-suggestions", "top center"],
        [".search-box, .sr-box", ".search-history-dropdown", "top left"]
    ];

    document.addEventListener("click", function (e) {
        if (!e.target || !e.target.closest) { return; }
        for (var i = 0; i < ORIGIN_HINTS.length; i++) {
            var hint = ORIGIN_HINTS[i];
            if (!e.target.closest(hint[0])) { continue; }
            var panel = document.querySelector(hint[1]);
            if (panel) {
                panel.style.setProperty("--mp-origin", hint[2]);
            }
        }
    }, true);

    /* ======================================================================
     * 二、切换分类/Tab 后内容淡入
     * ======================================================================
     * 这些按钮点下去之后，列表容器会异步替换内容（也有的直接跳页）。
     * 直接跳页的情况由页面进入动画负责，这里只管「本页内替换」。
     *
     * 为什么用 requestAnimationFrame 延后一帧：
     *   点击处理器同步跑完之后，列表可能还没渲染新内容。
     *   延一帧再挂动画类，才能覆盖到新内容。
     * ====================================================================== */

    var TAB_SEL = ".cat-filter, .disc-tab, .filter-btn, .sr-tab, .tab-item, .category-tab";
    var TARGET_SEL = ".posts-container, #postsContainer, .disc-grid, .sr-results, .feed-list, .post-list, .tab-content";

    function flashContent() {
        var target = document.querySelector(TARGET_SEL);
        if (!target) { return; }
        target.classList.remove("mp-tab-in");
        /* 强制读一次 offsetWidth 让浏览器「忘掉」上一个动画，否则重复点同一项不会重播 */
        void target.offsetWidth;
        target.classList.add("mp-tab-in");
        /* 动画结束后清掉类，避免影响后续 transition */
        setTimeout(function () {
            target.classList.remove("mp-tab-in");
        }, 340);
    }

    document.addEventListener("click", function (e) {
        if (!e.target || !e.target.closest) { return; }
        if (e.target.closest(TAB_SEL)) {
            /* 有些 Tab 点击后是整页跳转 —— 那种情况下这行不会有可见影响 */
            requestAnimationFrame(flashContent);
        }
    }, false);

    /* ======================================================================
     * 三、骨架屏淡出
     * ======================================================================
     * 监听容器变化：如果变化前里面有骨架、变化后没有了，
     * 就给「变化的父容器」补一次淡入。
     *
     * 这里不直接抓骨架元素（它可能已经被移除，无法做淡出），
     * 而是让新内容淡入 —— 视觉结果一样，且不会出现「骨架淡出到一半
     * 新内容已经占位」的重叠。
     * ====================================================================== */

    var SKELETON_SEL = ".skeleton, .lw-skeleton, .skeleton-card, .post-skeleton";

    if ("MutationObserver" in window) {
        /* 节流：这个回调会遍历所有 records 并在命中列表容器时挂动画类。
         * 不节流的话，一次批量渲染（比如加载 20 张卡片）会连续触发几十次。 */
        var ieTimer = 0;
        var mo = new MutationObserver(function (records) {
            if (ieTimer) { return; }
            ieTimer = setTimeout(function () { ieTimer = 0; }, 140);
            for (var i = 0; i < records.length; i++) {
                var rec = records[i];
                if (rec.type !== "childList" || !rec.addedNodes.length) { continue; }
                var parent = rec.target;
                if (!parent || !parent.closest) { continue; }

                /* 新加入的内容里如果有骨架，说明还在加载，不动 */
                if (parent.querySelector && parent.querySelector(SKELETON_SEL)) { continue; }

                /* 只有「明显是列表容器」才做淡入，避免全站乱闪 */
                if (!parent.matches(TARGET_SEL) && !parent.closest(TARGET_SEL)) { continue; }

                parent.classList.remove("mp-tab-in");
                void parent.offsetWidth;
                parent.classList.add("mp-tab-in");
                (function (el) {
                    setTimeout(function () { el.classList.remove("mp-tab-in"); }, 340);
                })(parent);
                break;
            }
        });

        function startWatch() {
            mo.observe(document.body, { childList: true, subtree: true });
        }

        if (document.readyState === "loading") {
            document.addEventListener("DOMContentLoaded", startWatch, { once: true });
        } else {
            startWatch();
        }
    }


    /* ======================================================================
     * 四、链接点击后的「离开态」
     * ======================================================================
     *
     * 这个站点每次请求约 2 秒。点一个链接（或一张卡片）之后，浏览器要等
     * 新页面到达才会换画面 —— 这 2 秒里**没有任何视觉反馈**。
     *
     * 用户的第一反应是「我点到了吗」，于是反复点。
     *
     * 这里在点击的瞬间给源元素一个轻微的「正在离开」状态（变淡 + 微缩），
     * 直到新页面接管。这和点赞的乐观更新是同一个道理：
     * 先把「收到了」这件事告诉用户，别让他等得心里没底。
     *
     * 只处理「会真的导航走」的链接：
     *   · 排除 # 锚点、javascript:、mailto:、tel:
     *   · 排除 target="_blank" 和带 download 的（它们不会替换当前页）
     *   · 排除外站链接
     * 外部导航被取消（比如弹了确认框）时，1.6 秒后自动撤销，
     * 避免元素一直半透明。
     */

    function isRealNavigation(link) {
        if (!link || !link.getAttribute) { return false; }
        if (link.target === "_blank" || link.hasAttribute("download")) { return false; }
        var href = link.getAttribute("href");
        if (!href) { return false; }
        var h = href.trim().toLowerCase();
        if (h.charAt(0) === "#") { return false; }
        if (h.indexOf("javascript:") === 0) { return false; }
        if (h.indexOf("mailto:") === 0 || h.indexOf("tel:") === 0) { return false; }
        try {
            var u = new URL(link.href, location.href);
            if (u.origin !== location.origin) { return false; }
        } catch (e) { return false; }
        return true;
    }

    document.addEventListener("click", function (e) {
        if (e.defaultPrevented) { return; }
        var link = e.target && e.target.closest ? e.target.closest("a[href]") : null;
        if (!link || !isRealNavigation(link)) { return; }

        link.classList.add("mp-leaving");

        /* 兜底撤销：如果导航被阻止（弹窗确认、脚本拦截），1.6 秒后恢复 */
        setTimeout(function () {
            link.classList.remove("mp-leaving");
        }, 1600);
    }, false);

    /* 对外暴露，方便其他模块在替换内容后主动触发 */
    window.LWInteract = {
        flashContent: flashContent
    };

})();
