/* ==========================================================================
 * 手机端 App 化交互  mobile-app.js
 * ==========================================================================
 * 配套 mobile-app.css 的三个交互：
 *
 *   1. 滚动收起顶栏与分类条  —— 往下滑时让出 88px 给内容
 *   2. 软键盘检测            —— 键盘弹起时隐藏底部导航，避免遮挡输入框
 *   3. 横向溢出诊断          —— 开发期在 console 报告具体哪个元素撑宽了
 *
 * 设计约束：
 *   · 只在 max-width: 768px 生效，桌面端完全不参与
 *   · 用 passive 监听滚动，不阻塞主线程
 *   · 尊重 prefers-reduced-motion
 * ========================================================================== */

(function () {
    "use strict";

    /* ---- 只在手机端启用 ---- */
    var mq = window.matchMedia("(max-width: 768px)");
    if (!mq.matches) return;

    /* ==================================================================
     * 一、滚动收起顶栏
     * ==================================================================
     * 规则：向下滑超过 60px → 收起；向上滑超过 20px → 展开；到顶 → 必然展开。
     *
     * 为什么阈值不对称（60 / 20）：
     *   如果上下都用同一个阈值，轻微抖动会让顶栏反复闪烁。
     *   收起更难、展开更容易 —— 用户想找顶栏时一滑就出来。
     * ================================================================== */

    var header = document.querySelector(".site-header");
    var tabs = document.querySelector(".category-tabs, .filter-bar, .tab-bar");

    if (header) {
        var lastY = window.scrollY;
        var accDown = 0;
        var accUp = 0;
        var ticking = false;

        var setHidden = function (hidden) {
            if (header.classList.contains("is-hidden") === hidden) return;
            header.classList.toggle("is-hidden", hidden);
            if (tabs) tabs.classList.toggle("is-hidden", hidden);
        };

        var onScroll = function () {
            var y = window.scrollY;
            var dy = y - lastY;

            if (y < 80) {
                setHidden(false);
                accDown = 0;
                accUp = 0;
            } else if (dy > 0) {
                accDown += dy;
                accUp = 0;
                if (accDown > 60) {
                    setHidden(true);
                    accDown = 0;
                }
            } else if (dy < 0) {
                accUp += -dy;
                accDown = 0;
                if (accUp > 20) {
                    setHidden(false);
                    accUp = 0;
                }
            }

            lastY = y;
            ticking = false;
        };

        window.addEventListener("scroll", function () {
            if (!ticking) {
                ticking = true;
                requestAnimationFrame(onScroll);
            }
        }, { passive: true });
    }

    /* ==================================================================
     * 二、软键盘检测
     * ==================================================================
     * 手机浏览器不会告诉你「键盘弹起了」，但可以从视口高度变化推断：
     *   键盘弹起 → visualViewport.height 明显小于窗口高度。
     *
     * 为什么需要：键盘弹起后底部导航会盖住输入框，用户看不见自己打的字。
     * ================================================================== */

    var vv = window.visualViewport;
    if (vv) {
        var baseHeight = window.innerHeight;

        var checkKeyboard = function () {
            var shrunk = baseHeight - vv.height > 150;
            document.body.classList.toggle("lw-keyboard-open", shrunk);
        };

        vv.addEventListener("resize", checkKeyboard, { passive: true });

        document.addEventListener("focusin", function (e) {
            var t = e.target;
            if (t && (t.tagName === "INPUT" || t.tagName === "TEXTAREA" || t.isContentEditable)) {
                setTimeout(checkKeyboard, 120);
            }
        }, { passive: true });

        document.addEventListener("focusout", function () {
            setTimeout(function () {
                document.body.classList.remove("lw-keyboard-open");
            }, 120);
        }, { passive: true });
    }

    /* ==================================================================
     * 三、横向溢出诊断
     * ==================================================================
     * SPT 主题 32 明确要求：不要用 overflow-x:hidden 掩盖问题，
     * 要逐个找到「哪个元素撑宽了」。
     *
     * 用法：手机浏览器控制台执行  LW_MOBILE_OVERFLOW_CHECK()
     * ================================================================== */

    window.LW_MOBILE_OVERFLOW_CHECK = function () {
        var vw = document.documentElement.clientWidth;
        var bad = [];

        var all = document.querySelectorAll("body *");
        for (var i = 0; i < all.length; i++) {
            var el = all[i];
            var r = el.getBoundingClientRect();
            if (r.width === 0 || r.height === 0) continue;

            var style = getComputedStyle(el);
            if (style.overflowX === "auto" || style.overflowX === "scroll") continue;

            if (r.right > vw + 1) {
                var cls = "";
                if (el.className && typeof el.className === "string") {
                    cls = el.className.trim().split(/\s+/).slice(0, 3).join(".");
                }
                bad.push({
                    tag: el.tagName.toLowerCase(),
                    cls: cls,
                    right: Math.round(r.right),
                    width: Math.round(r.width),
                    el: el
                });
            }
        }

        if (!bad.length) {
            console.log("%c✓ 没有横向溢出", "color:#2e8b57;font-weight:600");
            return [];
        }

        console.log("%c发现 " + bad.length + " 个溢出元素（视口 " + vw + "px）", "color:#c0392b;font-weight:600");
        bad.sort(function (a, b) { return b.right - a.right; });
        bad.slice(0, 20).forEach(function (b) {
            console.log("  " + b.tag + (b.cls ? "." + b.cls : "") + "  right=" + b.right + "  width=" + b.width, b.el);
        });
        return bad;
    };

    /* ==================================================================
     * 四、页面切换时的滚动位置恢复
     * ==================================================================
     * 手机用户从列表点进详情、再返回时，期望回到原来的滚动位置。
     * ================================================================== */

    (function () {
        var KEY = "lw_mobile_scroll_" + location.pathname;

        try {
            var saved = sessionStorage.getItem(KEY);
            if (saved && document.referrer.indexOf(location.host) !== -1) {
                var d = JSON.parse(saved);
                if (d && d.y > 100) {
                    requestAnimationFrame(function () {
                        setTimeout(function () {
                            window.scrollTo({ top: d.y, behavior: "instant" });
                        }, 40);
                    });
                }
            }
        } catch (e) {}

        var timer = null;
        window.addEventListener("scroll", function () {
            clearTimeout(timer);
            timer = setTimeout(function () {
                try {
                    sessionStorage.setItem(KEY, JSON.stringify({
                        y: window.scrollY,
                        t: Date.now()
                    }));
                } catch (e) {}
            }, 150);
        }, { passive: true });
    })();

    /* ==================================================================
     * 五、列表项点击反馈（iOS 上 :active 需要 touchstart 才生效）
     * ==================================================================
     * iOS Safari 的 :active 只在元素有 touchstart 监听时才触发。
     * 加一个空监听就能让 CSS 的 .post-card:active 正常工作。
     * ================================================================== */

    document.addEventListener("touchstart", function () {}, { passive: true });

})();
