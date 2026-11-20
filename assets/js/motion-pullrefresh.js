/* ==========================================================================
 * 下拉刷新  motion-pullrefresh.js
 * ==========================================================================
 *
 * 【为什么加】
 * 手机 App 里「在列表顶部往下拽」是最基础的手势。
 * 站内没有 —— 用户想刷新只能滚回顶部找按钮，这在信息流里很别扭。
 *
 * --------------------------------------------------------------------------
 * 【几个必须处理对的地方】
 * --------------------------------------------------------------------------
 *
 * 1. 只在页面最顶部才激活（window.scrollY <= 0）
 *    如果任何位置下拉都触发，用户往上滚时会误触刷新。
 *
 * 2. 位移要阻尼
 *    手指下拉 100px，指示器只下移 50px（系数 0.5）。
 *    不做阻尼会让指示器跟手太紧，显得「轻浮」；
 *    系统级的 PTR 都是「越拉越沉」。
 *
 * 3. 触发阈值和视觉阈值分开
 *    视觉上 70px 就是「释放刷新」的分界；
 *    但实际判定用 70*0.7，给用户一点容错（拉到接近了松手也认）。
 *
 * 4. 不用浏览器原生 PTR
 *    mobile-app.css 里 body 已有 overscroll-behavior-y: none，
 *    原生下拉刷新已被禁用，所以这里完全接管，不会两个转圈同时出现。
 *
 * 5. 弹层/抽屉打开时不激活
 *    否则在抽屉里下拉会触发页面刷新。
 * ========================================================================== */

(function () {
    "use strict";

    /* 只在手机端 */
    if (!window.matchMedia("(max-width: 768px)").matches) { return; }

    var THRESHOLD = 70;        /* 视觉阈值（px，已含阻尼后） */
    var JUDGE = THRESHOLD * 0.7;
    var DAMPING = 0.5;
    var MAX_PULL = 110;

    var start = null;
    var indicator = null;
    var busy = false;

    /* ---- 指示器（懒创建，只注入一次样式） ---- */
    function ensureIndicator() {
        if (indicator) { return indicator; }

        var style = document.createElement("style");
        style.textContent =
            ".lw-ptr{position:fixed;left:50%;top:0;z-index:60;" +
            "width:38px;height:38px;margin-left:-19px;" +
            "display:flex;align-items:center;justify-content:center;" +
            "border-radius:50%;background:rgba(255,255,255,.94);" +
            "box-shadow:0 4px 16px -4px rgba(16,24,40,.28),0 0 0 1px rgba(16,24,40,.05);" +
            "opacity:0;pointer-events:none;" +
            "transition:opacity 180ms ease-out;will-change:transform,opacity;}" +
            "html.dark-theme .lw-ptr{background:rgba(27,36,52,.96);" +
            "box-shadow:0 4px 16px -4px rgba(0,0,0,.6),0 0 0 1px rgba(255,255,255,.07);}" +
            ".lw-ptr svg{width:18px;height:18px;color:#2F5B9A;transition:transform 180ms ease-out;}" +
            "html.dark-theme .lw-ptr svg{color:#5B8CC9;}" +
            ".lw-ptr.is-spin svg{animation:lwPtrSpin .8s linear infinite;}" +
            ".lw-ptr.is-ready svg{transform:rotate(180deg);}" +
            ".lw-ptr.is-spin svg{transform:none;}" +
            "@keyframes lwPtrSpin{to{transform:rotate(360deg);}}" +
            "@media (prefers-reduced-motion: reduce){" +
            ".lw-ptr svg,.lw-ptr{transition:none;}.lw-ptr.is-spin svg{animation:none;}}" +
            "@media print{.lw-ptr{display:none;}}";
        document.head.appendChild(style);

        indicator = document.createElement("div");
        indicator.className = "lw-ptr";
        indicator.setAttribute("aria-hidden", "true");
        indicator.innerHTML =
            '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" ' +
            'stroke-linecap="round" stroke-linejoin="round">' +
            '<path d="M12 19V5"/><path d="M5 12l7-7 7 7"/></svg>';
        document.body.appendChild(indicator);
        return indicator;
    }

    function showPull(d) {
        var el = ensureIndicator();
        el.style.opacity = String(Math.min(1, d / 46));
        el.style.transform = "translateY(" + (d - 8) + "px)";
        el.classList.remove("is-spin");
        el.classList.toggle("is-ready", d >= THRESHOLD);
    }

    function hidePull() {
        if (!indicator) { return; }
        indicator.style.opacity = "0";
        indicator.style.transform = "translateY(-8px)";
        indicator.classList.remove("is-ready", "is-spin");
    }

    function spin() {
        var el = ensureIndicator();
        el.style.opacity = "1";
        el.style.transform = "translateY(" + (THRESHOLD - 8) + "px)";
        el.classList.remove("is-ready");
        el.classList.add("is-spin");
    }

    /* ---- 刷新动作 ---- */
    function doRefresh() {
        if (busy) { return; }
        busy = true;
        spin();

        var done = function () {
            setTimeout(function () {
                hidePull();
                busy = false;
            }, 320);
        };

        /* 优先用既有的列表刷新（会保留滚动位置、只换列表） */
        var feed = window.PostFeed || (window.App && window.App.PostFeed);
        if (feed && typeof feed.loadPosts === "function") {
            try {
                var r = feed.loadPosts(true);
                if (r && typeof r.then === "function") {
                    r.then(done, done);
                } else {
                    setTimeout(done, 900);
                }
                return;
            } catch (err) {}
        }

        var feed2 = window.App && window.App.Posts;
        if (feed2 && typeof feed2.refresh === "function") {
            try {
                feed2.refresh();
                setTimeout(done, 900);
                return;
            } catch (err2) {}
        }

        /* 兜底：整页重载 */
        setTimeout(function () { location.reload(); }, 260);
    }

    /* ---- 手势 ---- */
    function blocked() {
        if (document.querySelector(".modal-overlay.show, .lightbox-overlay[style*='display: flex'], .lightbox-overlay[style*='display:block']")) {
            return true;
        }
        var sheet = document.querySelector(".lw-sheet-closing");
        if (sheet) { return true; }
        return false;
    }

    function onStart(e) {
        if (busy || blocked()) { return; }
        if (e.touches.length !== 1) { return; }
        if (window.scrollY > 0) { return; }
        start = { y: e.touches[0].clientY, active: false, d: 0 };
    }

    function onMove(e) {
        if (!start || e.touches.length !== 1) { return; }
        if (window.scrollY > 0) { start = null; hidePull(); return; }

        var dy = e.touches[0].clientY - start.y;
        if (dy <= 0) {
            if (start.active) { start.active = false; start.d = 0; hidePull(); }
            return;
        }
        if (!start.active && dy < 8) { return; }

        start.active = true;
        /* 阻尼：越拉越沉 */
        var d = Math.min(dy * DAMPING, MAX_PULL);
        start.d = d;
        showPull(d);

        /* 接管往下拽，避免页面跟着跳 */
        if (e.cancelable) { e.preventDefault(); }
    }

    function onEnd() {
        if (!start) { return; }
        var d = start.d;
        var wasActive = start.active;
        start = null;

        if (!wasActive) { return; }

        if (d >= JUDGE) {
            doRefresh();
        } else {
            hidePull();
        }
    }

    document.addEventListener("touchstart", onStart, { passive: true });
    document.addEventListener("touchmove", onMove, { passive: false });
    document.addEventListener("touchend", onEnd, { passive: true });
    document.addEventListener("touchcancel", function () {
        start = null;
        hidePull();
    }, { passive: true });

})();
