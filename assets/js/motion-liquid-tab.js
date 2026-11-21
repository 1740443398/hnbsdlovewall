/* ==========================================================================
 * 液态 Tab 指示器  motion-liquid-tab.js
 * ==========================================================================
 *
 * 配套 motion-liquid-tab.css。
 *
 * --------------------------------------------------------------------------
 * 【核心：三段关键帧，不是匀速平移】
 * --------------------------------------------------------------------------
 * SPT 的标准明确写了「不要仅将固定宽度背景匀速平移」。
 * 匀速平移看起来像「一块色块滑过去」；真实系统里是：
 *
 *   起点宽度 ──扩大到并集范围──> 峰值 ──收拢到终点宽度──> 终点
 *
 * 也就是移动过程中指示器**先被拉长**（像个橡皮筋被拽过去），
 * 然后**收拢**成目标形状。这就是「液态」的来源。
 *
 * 关键帧：
 *   0%   从当前可见位置（连续点击时不是上一个终点，而是当前真实位置）
 *   52%  扩展到 [起点, 终点] 的并集，位置对齐并集左端
 *   100% 收拢到终点位置与宽度
 *
 * --------------------------------------------------------------------------
 * 【几个必须处理对的细节】
 * --------------------------------------------------------------------------
 * · 连续快速点击：从**当前可见位置**接续，不是从上一个目标位置。
 *   否则连点两个标签会看到指示器先「弹回」上一个目标再出发。
 * · 内容切换不等待动画：我们只是装饰，点击立即切数据，动画在后台跑。
 * · 尺寸变化后重算：resize / 字体加载完成都会让按钮位置变。
 * · 命中区域不变：指示器 pointer-events: none，点击始终落在按钮上。
 * ========================================================================== */

(function () {
    "use strict";

    /* 减动效模式下 CSS 已把指示器隐藏，这里直接不初始化，
     * 按钮保留自身的 active 底色（CSS 里也做了对应回退）。 */
    if (window.matchMedia("(prefers-reduced-motion: reduce)").matches) {
        return;
    }

    var CONTAINER_SEL = ".category-filters";
    var DURATION = 360;
    var EASING = "cubic-bezier(0.22, 0.61, 0.36, 1)";  /* 先快后缓，像液体收住 */

    function setup(container) {
        if (container.dataset.liquidReady === "1") { return; }
        var active = container.querySelector(".cat-filter.active");
        if (!active) { return; }

        container.dataset.liquidReady = "1";
        container.classList.add("has-liquid");

        var ind = document.createElement("span");
        ind.className = "lw-liquid-tab";
        ind.setAttribute("aria-hidden", "true");
        container.insertBefore(ind, container.firstChild);

        /* 按钮在容器内容坐标里的位置（绝对值，含横向滚动量） */
        function rectOf(el) {
            var c = container.getBoundingClientRect();
            var r = el.getBoundingClientRect();
            return {
                left: r.left - c.left + container.scrollLeft,
                width: r.width,
                height: r.height
            };
        }

        function cancelAnims() {
            if (typeof ind.getAnimations !== "function") { return; }
            try {
                ind.getAnimations().forEach(function (a) { a.cancel(); });
            } catch (e) {}
        }

        function place(el, animate) {
            var to = rectOf(el);
            ind.style.height = to.height + "px";

            if (!animate) {
                cancelAnims();
                ind.style.left = to.left + "px";
                ind.style.width = to.width + "px";
                return;
            }

            if (typeof ind.animate !== "function") {
                ind.style.left = to.left + "px";
                ind.style.width = to.width + "px";
                return;
            }

            /* 从当前**真实可见**的位置接续（连续点击的关键） */
            var cs = window.getComputedStyle(ind);
            var fromLeft = parseFloat(cs.left);
            var fromWidth = parseFloat(cs.width);
            if (!isFinite(fromLeft)) { fromLeft = to.left; }
            if (!isFinite(fromWidth)) { fromWidth = to.width; }

            var unionLeft = Math.min(fromLeft, to.left);
            var unionRight = Math.max(fromLeft + fromWidth, to.left + to.width);

            cancelAnims();

            var anim = ind.animate([
                { left: fromLeft + "px", width: fromWidth + "px" },
                {
                    left: unionLeft + "px",
                    width: (unionRight - unionLeft) + "px",
                    offset: 0.52
                },
                { left: to.left + "px", width: to.width + "px" }
            ], {
                duration: DURATION,
                easing: EASING,
                fill: "forwards"
            });

            /* 动画结束后把结果固化到 style，并取消 forwards 填充。
             * 否则下一次读 getComputedStyle 会读到动画的合成值，
             * 连续点击时的「起点」就不准了。 */
            anim.onfinish = function () {
                ind.style.left = to.left + "px";
                ind.style.width = to.width + "px";
                try { anim.cancel(); } catch (e) {}
            };
        }

        /* 首次定位（不播动画） */
        place(active, false);

        /* 点击：等既有 handler 先把 active 类切完再算位置 */
        container.addEventListener("click", function (e) {
            var btn = e.target && e.target.closest ? e.target.closest(".cat-filter") : null;
            if (!btn) { return; }

            requestAnimationFrame(function () {
                place(btn, true);

                /* 被点的标签如果半个身子在可视区外，平滑滚进来 */
                var cr = container.getBoundingClientRect();
                var br = btn.getBoundingClientRect();
                var margin = 10;
                if (br.left < cr.left + margin || br.right > cr.right - margin) {
                    try {
                        btn.scrollIntoView({ behavior: "smooth", block: "nearest", inline: "center" });
                    } catch (err) {
                        container.scrollLeft += (br.left - cr.left) - 20;
                    }
                }
            });
        });

        /* 尺寸变化后重算（字体加载、旋转屏幕、窗口缩放） */
        var rt = 0;
        function recompute() {
            clearTimeout(rt);
            rt = setTimeout(function () {
                var a = container.querySelector(".cat-filter.active");
                if (a) { place(a, false); }
            }, 140);
        }
        window.addEventListener("resize", recompute);
        window.addEventListener("orientationchange", recompute);
        if (document.fonts && document.fonts.ready) {
            document.fonts.ready.then(recompute).catch(function () {});
        }

        /* 外部切换分类（比如从 URL 恢复、或 JS 主动切换）时也要跟上：
         * 监听 active 类变化即可。 */
        if ("MutationObserver" in window) {
            var mo = new MutationObserver(function () {
                var a = container.querySelector(".cat-filter.active");
                if (a && a !== active && parseFloat(a.dataset.lqLast || "0") !== 1) {
                    active = a;
                    place(a, true);
                }
            });
            mo.observe(container, { attributes: true, subtree: true, attributeFilter: ["class"] });
        }
    }

    function scan() {
        var list = document.querySelectorAll(CONTAINER_SEL);
        for (var i = 0; i < list.length; i++) { setup(list[i]); }
    }

    if (document.readyState === "loading") {
        document.addEventListener("DOMContentLoaded", scan, { once: true });
    } else {
        scan();
    }

    /* 分类栏可能是 AJAX 后插入的 */
    if ("MutationObserver" in window) {
        /* 节流：body 的 subtree 变化非常频繁（无限流、toast、动画类切换都会触发），
         * 每次变更都 querySelectorAll 会明显拖慢页面。 */
        var scanTimer = 0;
        var bodyMo = new MutationObserver(function () {
            if (scanTimer) { return; }
            scanTimer = setTimeout(function () {
                scanTimer = 0;
                scan();
            }, 160);
        });
        function observeBody() {
            bodyMo.observe(document.body, { childList: true, subtree: true });
        }
        if (document.body) { observeBody(); }
        else { document.addEventListener("DOMContentLoaded", observeBody, { once: true }); }
    }

    /* 对外暴露，便于其他模块强制重算 */
    window.LWLiquidTab = {
        refresh: scan
    };

})();
