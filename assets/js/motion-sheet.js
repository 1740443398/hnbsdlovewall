/* ==========================================================================
 * 抽屉拖拽关闭  motion-sheet.js
 * ==========================================================================
 *
 * 手机端弹窗是底部抽屉。这个脚本让它「按住往下拖 → 跟手 → 松手收起/回弹」。
 *
 * --------------------------------------------------------------------------
 * 【几个必须处理对的细节】
 * --------------------------------------------------------------------------
 *
 * 1. 只在抽屉顶部 52px 内才开始拖
 *    抽屉内部是可滚动内容。如果整块都能拖，用户想滚内容时会误触。
 *    顶部 52px 正好覆盖手柄和标题区，那里本来就不需要滚动。
 *
 * 2. 拖动时临时把 transition 设为 none
 *    否则 CSS 的 320ms 过渡会让抽屉「追不上手指」——
 *    手指移 100px，抽屉还在慢慢过渡，手感立刻就假了。
 *
 * 3. 松手用「速度」和「距离」双条件判断
 *    只看距离：慢慢往下拖 80px 又拖回来，会被判成关闭。
 *    只看速度：轻轻一甩 40px 就关掉，太敏感。
 *    两个条件都要（dy > 30% 高度 或 速度 > 0.55px/ms）。
 *
 * 4. 拖拽过程中遮罩要跟着变淡
 *    遮罩不动而抽屉在动，会有「下面还有一层没跟上」的割裂感。
 *
 * 5. 关闭时走既有的关闭路径（点遮罩），不要自己 remove class
 *    既有路径会处理 body 滚动锁、aria、焦点归还等。
 * ========================================================================== */

(function () {
    "use strict";

    /* 只在手机端生效（抽屉形态只在 <=768px 出现） */
    if (!window.matchMedia("(max-width: 768px)").matches) {
        return;
    }

    var SHEET_SEL = [
        ".modal-box",
        ".modal-content",
        ".site-modal-box",
        ".pm-modal",
        ".pmr-modal",
        ".sponsor-modal"
    ].join(",");

    var HANDLE_ZONE = 52;      /* 顶部可拖区域高度 */
    var CLOSE_RATIO = 0.30;    /* 拖过自身高度的 30% 就关 */
    var CLOSE_VELOCITY = 0.55; /* px/ms，快速下滑也算关 */

    var drag = null;

    function findSheet(target) {
        return target && target.closest ? target.closest(SHEET_SEL) : null;
    }

    function overlayOf() {
        return document.querySelector(".modal-overlay.show, .modal-overlay.open");
    }

    function onDown(e) {
        var sheet = findSheet(e.target);
        if (!sheet) { return; }

        /* 按钮/输入框上的按下不接管，否则点不到 */
        if (e.target.closest && e.target.closest("button, a, input, textarea, select, label")) {
            return;
        }

        var rect = sheet.getBoundingClientRect();
        /* 只认顶部区域 */
        if (e.clientY - rect.top > HANDLE_ZONE) { return; }

        drag = {
            sheet: sheet,
            startY: e.clientY,
            lastY: e.clientY,
            lastT: performance.now(),
            startedAt: performance.now(),
            dy: 0,
            v: 0,
            moved: false,
            pointerId: e.pointerId
        };

        /* 关键：关掉过渡，让抽屉完全跟手 */
        sheet.style.transition = "none";

        try {
            if (sheet.setPointerCapture) { sheet.setPointerCapture(e.pointerId); }
        } catch (err) {}
    }

    function onMove(e) {
        if (!drag || (e.pointerId !== undefined && e.pointerId !== drag.pointerId)) { return; }

        var dy = e.clientY - drag.startY;
        if (dy < 0) { dy = 0; }        /* 往上拖不给动，抽屉不能长高 */

        /* 记速度用于松手判定 */
        var now = performance.now();
        var dt = now - drag.lastT;
        if (dt > 0) {
            drag.v = (e.clientY - drag.lastY) / dt;
        }
        drag.lastY = e.clientY;
        drag.lastT = now;

        if (dy > 3) { drag.moved = true; }
        drag.dy = dy;

        drag.sheet.style.transform = "translateY(" + dy + "px)";

        /* 遮罩联动变淡：拖到一半时遮罩已经透明一半 */
        var ov = overlayOf();
        if (ov) {
            var h = drag.sheet.getBoundingClientRect().height || 400;
            var prog = Math.min(1, dy / h);
            ov.style.transition = "none";
            ov.style.opacity = String(Math.max(0, 1 - prog * 1.4));
        }

        /* 拖动时禁止页面跟着滚 */
        if (drag.moved && e.cancelable) {
            e.preventDefault();
        }
    }

    function finish(close) {
        if (!drag) { return; }
        var sheet = drag.sheet;
        var ov = overlayOf();

        /* 恢复过渡（CSS 里的 spring） */
        sheet.style.transition = "";
        if (ov) { ov.style.transition = ""; ov.style.opacity = ""; }

        if (close) {
            sheet.style.transform = "";
            sheet.classList.add("lw-sheet-closing");
            /* 走既有关闭路径：点遮罩。那条路径会处理滚动锁 / aria / 焦点 */
            setTimeout(function () {
                if (ov) {
                    ov.click();
                } else {
                    sheet.classList.remove("lw-sheet-closing");
                }
                /* 万一既有路径没关掉，兜底清掉动画类 */
                setTimeout(function () {
                    sheet.classList.remove("lw-sheet-closing");
                    sheet.style.transform = "";
                }, 320);
            }, 40);
        } else {
            /* 回弹：CSS 的 spring 过渡会把它弹回原位 */
            sheet.style.transform = "";
        }

        drag = null;
    }

    function onUp(e) {
        if (!drag) { return; }
        var dy = drag.dy;
        var v = drag.v;
        var h = drag.sheet.getBoundingClientRect().height || 400;

        /* 没怎么动 = 这是一次点击，不算拖拽，直接回弹 */
        if (!drag.moved) {
            drag.sheet.style.transition = "";
            drag.sheet.style.transform = "";
            var ov0 = overlayOf();
            if (ov0) { ov0.style.transition = ""; ov0.style.opacity = ""; }
            drag = null;
            return;
        }

        var shouldClose = (dy > h * CLOSE_RATIO) || (v > CLOSE_VELOCITY);
        finish(shouldClose);
    }

    function onCancel() {
        if (!drag) { return; }
        finish(false);
    }

    /* 用 capture 阶段，保证比抽屉内部的滚动处理更早拿到事件 */
    document.addEventListener("pointerdown", onDown, { capture: true, passive: true });
    document.addEventListener("pointermove", onMove, { capture: true, passive: false });
    document.addEventListener("pointerup", onUp, { capture: true, passive: true });
    document.addEventListener("pointercancel", onCancel, { capture: true, passive: true });

    /* 老旧浏览器（无 Pointer Events）：退化为 touch 事件 */
    if (!window.PointerEvent) {
        document.addEventListener("touchstart", function (e) {
            var t = e.touches[0];
            if (t) { onDown({ target: e.target, clientY: t.clientY, pointerId: 0 }); }
        }, { capture: true, passive: true });

        document.addEventListener("touchmove", function (e) {
            var t = e.touches[0];
            if (t) { onMove({ clientY: t.clientY, pointerId: 0, cancelable: e.cancelable, preventDefault: function () { e.preventDefault(); } }); }
        }, { capture: true, passive: false });

        document.addEventListener("touchend", function () {
            onUp({ pointerId: 0 });
        }, { capture: true, passive: true });
    }

})();
