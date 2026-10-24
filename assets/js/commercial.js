/**
 * 商业化体验细节（行为层）
 *
 * 配合 commercial.css 使用，补齐三件事：
 *   1. 骨架屏：首屏数据没到之前显示占位，而不是一片空白
 *   2. 点击涟漪：移动端按下反馈（商业化产品的标配）
 *   3. 无障碍跳转链接：键盘用户能跳过导航直达正文
 */
(function () {
    'use strict';

    var reduceMotion = window.matchMedia &&
        window.matchMedia('(prefers-reduced-motion: reduce)').matches;

    /* ---- 1. 无障碍跳转链接 ---- */
    function initSkipLink() {
        if (document.querySelector(".lw-skip-link")) return;
        var main = document.querySelector("main, .site-main, #mainContent");
        if (!main) return;
        if (!main.id) main.id = "lwMainContent";
        var a = document.createElement("a");
        a.className = "lw-skip-link";
        a.href = "#" + main.id;
        a.textContent = "跳到主要内容";
        document.body.insertBefore(a, document.body.firstChild);
    }

    /* ---- 2. 点击涟漪 ---- */
    function initRipple() {
        if (reduceMotion) return;
        document.addEventListener("pointerdown", function (e) {
            var el = e.target.closest(".btn, button, .card, .post-card, .tool-card, a.nav-link");
            if (!el || el.disabled) return;
            // 已经处理过的不重复加类（避免反复触发布局）
            if (!el.classList.contains("lw-ripple-host")) {
                el.classList.add("lw-ripple-host");
            }
            var rect = el.getBoundingClientRect();
            var size = Math.max(rect.width, rect.height);
            var span = document.createElement("span");
            span.className = "lw-ripple";
            span.style.width = size + "px";
            span.style.height = size + "px";
            span.style.left = (e.clientX - rect.left - size / 2) + "px";
            span.style.top = (e.clientY - rect.top - size / 2) + "px";
            el.appendChild(span);
            setTimeout(function () { span.remove(); }, 600);
        }, { passive: true });
    }

    /* ---- 3. 骨架屏 ---- */
    /** 在容器里放 N 个骨架卡片。 */
    function showSkeleton(container, count) {
        if (!container) return;
        var html = "";
        for (var i = 0; i < (count || 3); i++) {
            html += "<div class=\"lw-skel-card\">" +
                "<div style=\"display:flex;gap:10px;align-items:center;margin-bottom:12px\">" +
                "<div class=\"lw-skeleton lw-skel-avatar\"></div>" +
                "<div style=\"flex:1\">" +
                "<div class=\"lw-skeleton lw-skel-line\" style=\"width:38%\"></div>" +
                "<div class=\"lw-skeleton lw-skel-line\" style=\"width:22%;height:9px\"></div>" +
                "</div></div>" +
                "<div class=\"lw-skeleton lw-skel-line\" style=\"width:92%\"></div>" +
                "<div class=\"lw-skeleton lw-skel-line\" style=\"width:78%\"></div>" +
                "<div class=\"lw-skeleton lw-skel-line\" style=\"width:86%;margin-bottom:0\"></div>" +
                "</div>";
        }
        container.innerHTML = html;
    }

    /** 首屏：如果容器是空的且有 loading 标志，自动铺骨架。 */
    function autoSkeleton() {
        var box = document.querySelector(".posts-container");
        if (!box) return;
        // 已经有真实内容就不动
        if (box.querySelector(".post-card")) return;
        if (box.getAttribute("data-skeleton") === "done") return;
        showSkeleton(box, 3);
        box.setAttribute("data-skeleton", "done");
    }

    /** 图片加载完后移除 shimmer 动画（省电）。 */
    function initImageCleanup() {
        function mark(img) {
            img.setAttribute("complete", "true");
            img.style.animation = "none";
        }
        document.querySelectorAll("img").forEach(function (img) {
            if (img.complete && img.naturalWidth > 0) mark(img);
            else {
                img.addEventListener("load", function () { mark(img); }, { once: true });
                img.addEventListener("error", function () { mark(img); }, { once: true });
            }
        });
        // 动态插入的图片也处理
        if (window.MutationObserver) {
            new MutationObserver(function (ms) {
                ms.forEach(function (m) {
                    m.addedNodes.forEach(function (n) {
                        if (n.nodeType === 1 && n.tagName === "IMG") {
                            if (n.complete) mark(n);
                            else n.addEventListener("load", function () { mark(n); }, { once: true });
                        }
                    });
                });
            }).observe(document.body, { childList: true, subtree: true });
        }
    }

    function boot() {
        initSkipLink();
        initRipple();
        autoSkeleton();
        initImageCleanup();
    }

    if (document.readyState === "loading") {
        document.addEventListener("DOMContentLoaded", boot);
    } else {
        boot();
    }

    // 暴露给需要主动控制的页面
    window.LwSkeleton = { show: showSkeleton, auto: autoSkeleton };
})();
/**
 * 底部 dock 栏自动隐藏
 *
 * 规则：
 *   向下滚 > 40px  → 隐藏（正在阅读，别挡内容）
 *   向上滚 > 20px  → 显示（用户想找导航了）
 *   滚到接近底部   → 隐藏（避免盖住最后一条）
 *   输入框聚焦     → 隐藏（虚拟键盘会顶上来）
 */
(function () {
    'use strict';

    var nav = null;
    var lastY = 0;
    var hidden = false;
    var ticking = false;

    function findNav() {
        if (!nav) nav = document.getElementById("mobileNav") || document.querySelector(".mobile-bottom-nav");
        return nav;
    }

    function setHidden(v) {
        var el = findNav();
        if (!el) return;
        if (v === hidden) return;
        hidden = v;
        el.classList.toggle("is-hidden", v);
    }

    function onScroll() {
        if (ticking) return;
        ticking = true;
        window.requestAnimationFrame(function () {
            ticking = false;
            var y = window.scrollY || document.documentElement.scrollTop || 0;
            var max = document.documentElement.scrollHeight - window.innerHeight;

            // 接近底部 80px 内：隐藏，别挡住最后一条
            if (max > 0 && y > max - 80) {
                setHidden(true);
                lastY = y;
                return;
            }
            // 页面顶部：始终显示
            if (y < 60) {
                setHidden(false);
                lastY = y;
                return;
            }

            var dy = y - lastY;
            if (dy > 40) {
                setHidden(true);   // 向下滚：收起
            } else if (dy < -20) {
                setHidden(false);  // 向上滚：显示
            }
            if (Math.abs(dy) > 10) lastY = y;
        });
    }

    function initKeyboard() {
        // 输入框聚焦 → 标记键盘打开（CSS 会收起 dock）
        document.addEventListener("focusin", function (e) {
            var t = e.target;
            if (!t) return;
            var tag = (t.tagName || "").toLowerCase();
            if (tag === "input" || tag === "textarea" || t.isContentEditable) {
                document.body.classList.add("lw-keyboard-open");
            }
        });
        document.addEventListener("focusout", function () {
            // 延迟一点，避免输入框之间切换时闪烁
            setTimeout(function () {
                var a = document.activeElement;
                var tag = a ? (a.tagName || "").toLowerCase() : "";
                if (tag !== "input" && tag !== "textarea" && !(a && a.isContentEditable)) {
                    document.body.classList.remove("lw-keyboard-open");
                }
            }, 120);
        });
    }

    function boot() {
        if (!findNav()) return;
        window.addEventListener("scroll", onScroll, { passive: true });
        initKeyboard();
        // 初始位于顶部时确保可见
        if ((window.scrollY || 0) < 60) setHidden(false);
    }

    if (document.readyState === "loading") {
        document.addEventListener("DOMContentLoaded", boot);
    } else {
        boot();
    }
})();
