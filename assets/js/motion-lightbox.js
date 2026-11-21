/* ==========================================================================
 * 图片查看器下拉关闭  motion-lightbox.js
 * ==========================================================================
 *
 * 【为什么加】
 * 站内图片查看器（main.js 的 Lightbox）原本只能点关闭按钮或点黑底退出。
 * 手机上放大看图时，手指自然就想「往下拖走」—— 这是系统相册的肌肉记忆。
 * 没有这个动作，查看器就还是「网页里的浮层」。
 *
 * --------------------------------------------------------------------------
 * 【必须避免和既有手势打架】
 * --------------------------------------------------------------------------
 * 这个查看器已经支持「左右滑动切换上/下一张」（main.js 里的 touch swipe）。
 * 如果无脑接管 touch，会把左右滑也吃掉。
 *
 * 做法：
 *   1. 先判定方向。垂直位移明显大于水平（1.2 倍）才进入「下拉」模式。
 *   2. 判定为水平后什么都不做，让既有 swipe 逻辑照常工作。
 *   3. 全程不 stopPropagation —— 只是给 img 加 transform，不阻断事件。
 *
 * --------------------------------------------------------------------------
 * 【跟手的细节】
 * --------------------------------------------------------------------------
 * · 拖动时 img 的 transition 设 none（否则追不上手指）
 * · 图片随下拉轻微缩小（1 → 0.88），模拟「往后退」的空间感
 * · 黑色背景同步变透明，让下面的页面露出来
 * · 松手：拖过 120px 或速度 > 0.6 就关闭；否则弹回
 * ========================================================================== */

(function () {
    "use strict";

    if (window.matchMedia("(prefers-reduced-motion: reduce)").matches) {
        /* 减动效模式下仍允许下拉关闭，只是不做缩小/背景渐变 */
    }

    var CLOSE_DISTANCE = 120;   /* 拖过多少像素就关 */
    var CLOSE_VELOCITY = 0.6;   /* px/ms */
    var AXIS_LOCK = 1.2;        /* 垂直要比水平大这么多倍，才认定是下拉 */

    var bound = new WeakSet();

    function bindOverlay(ov) {
        if (bound.has(ov)) { return; }
        bound.add(ov);

        var img = ov.querySelector("img");
        if (!img) { return; }

        var start = null;

        function reset(done) {
            img.style.transition = "transform 320ms cubic-bezier(0.34, 1.4, 0.64, 1)";
            img.style.transform = "";
            ov.style.background = "rgba(0,0,0,0.9)";
            start = null;
            if (done) {
                /* 走既有关闭路径：overlay 自己的 click handler 会处理关闭 */
                setTimeout(function () {
                    try {
                        ov.dispatchEvent(new MouseEvent("click", { bubbles: true }));
                    } catch (e) {}
                }, 40);
            }
        }

        ov.addEventListener("touchstart", function (e) {
            if (e.touches.length !== 1) { return; }
            var t = e.touches[0];
            start = {
                x: t.clientX,
                y: t.clientY,
                lastY: t.clientY,
                lastT: Date.now(),
                t: Date.now(),
                mode: 0,      /* 0=未定 1=垂直 2=水平 */
                dy: 0,
                v: 0
            };
        }, { passive: true });

        ov.addEventListener("touchmove", function (e) {
            if (!start || e.touches.length !== 1) { return; }
            var t = e.touches[0];
            var dx = t.clientX - start.x;
            var dy = t.clientY - start.y;

            /* 方向判定：前 12px 内定不下来就一直等 */
            if (start.mode === 0) {
                if (Math.abs(dy) < 12 && Math.abs(dx) < 12) { return; }
                start.mode = (Math.abs(dy) > Math.abs(dx) * AXIS_LOCK) ? 1 : 2;
            }
            if (start.mode === 2) { return; }   /* 水平：交给既有 swipe */

            var now = Date.now();
            var dt = now - start.lastT;
            if (dt > 0) { start.v = (t.clientY - start.lastY) / dt; }
            start.lastY = t.clientY;
            start.lastT = now;

            var d = Math.max(0, dy);
            start.dy = d;

            img.style.transition = "none";
            /* 轻微缩小：下拉越多越远，空间感更强 */
            var scale = 1 - Math.min(d / 1400, 0.12);
            img.style.transform = "translateY(" + d + "px) scale(" + scale + ")";
            ov.style.background = "rgba(0,0,0," + Math.max(0, 0.9 - d / 520 * 0.9).toFixed(3) + ")";

            if (e.cancelable) { e.preventDefault(); }
        }, { passive: false });

        ov.addEventListener("touchend", function () {
            if (!start) { return; }
            if (start.mode !== 1) {
                /* 水平或没拖动：不动，交回既有逻辑 */
                start = null;
                return;
            }
            var d = start.dy;
            var v = start.v;
            var shouldClose = (d > CLOSE_DISTANCE) || (v > CLOSE_VELOCITY);
            reset(shouldClose);
        }, { passive: true });

        ov.addEventListener("touchcancel", function () {
            if (start && start.mode === 1) { reset(false); return; }
            start = null;
        }, { passive: true });
    }

    /* overlay 是 main.js 动态创建的，所以监听 body 等它出现 */
    function scan() {
        var list = document.querySelectorAll(".lightbox-overlay");
        for (var i = 0; i < list.length; i++) { bindOverlay(list[i]); }
    }

    if ("MutationObserver" in window) {
        /* 节流：Lightbox overlay 只会在打开时插入一次，
         * 但 body 的 subtree 变化非常频繁。不节流等于每次 DOM 变化都
         * 跑一遍 querySelectorAll('.lightbox-overlay')。 */
        var lbTimer = 0;
        var mo = new MutationObserver(function () {
            if (lbTimer) { return; }
            lbTimer = setTimeout(function () {
                lbTimer = 0;
                scan();
            }, 150);
        });
        function start() {
            scan();
            mo.observe(document.body, { childList: true, subtree: true });
        }
        if (document.readyState === "loading") {
            document.addEventListener("DOMContentLoaded", start, { once: true });
        } else {
            start();
        }
    } else {
        setTimeout(scan, 800);
    }

})();
