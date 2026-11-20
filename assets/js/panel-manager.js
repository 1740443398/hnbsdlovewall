/* ==========================================================================
 * 浮层互斥管理  panel-manager.js
 * ==========================================================================
 *
 * 【解决什么问题】
 *
 * 截图暴露：点开通知面板，再点用户菜单，两个面板会同时展开、
 * 互相遮挡、右侧内容被压住。手机上更明显 —— 两个面板叠在一起
 * 几乎占满整屏。
 *
 * 【根本原因】
 *
 * 站内有三个独立的浮层，各自管自己的开关：
 *
 *   通知面板     main.js  NotificationSystem
 *                this.panel.classList.toggle('show')
 *
 *   用户菜单     main.js  UserMenu
 *                this.dropdown.classList.toggle('show')
 *
 *   @提及建议    main.js  mentionPanel
 *                suggestions.classList.add('show')
 *
 * 三个模块互相不知道对方存在。点 A 时不会关 B，
 * 于是只要用户不从空白处点一下，就能同时开出两个面板。
 *
 * 【怎么修】
 *
 * 不改那三个模块的内部逻辑（改它们风险高、容易漏），
 * 而是在它们之上加一个「互斥层」：
 *
 *   任何面板打开 → 广播一个事件 → 其他面板收到后自动关闭
 *
 * 用 MutationObserver 监听 class 变化，不侵入任何现有代码。
 * 这样即使以后新增面板，只要注册进来就自动获得互斥行为。
 * ========================================================================== */

(function () {
    "use strict";

    /**
     * 需要互斥的浮层选择器。
     *
     * 顺序无所谓 —— 打开任意一个，其他全部关闭。
     * 新增浮层时只要把选择器加进这个数组即可。
     */
    var PANEL_SELECTORS = [
        ".notification-panel",      // 通知面板
        ".user-dropdown",           // 用户菜单
        ".mention-suggestions",     // @ 提及建议
        ".search-history-dropdown", // 搜索历史
        ".search-suggestions"       // 搜索建议
    ];

    var SHOW_CLASS = "show";

    /** 已打开的面板集合 */
    var openPanels = new Set();

    /** 正在关闭中，避免观察者递归触发 */
    var mutating = false;

    /**
     * 关闭除 keep 之外的所有面板。
     */
    function closeOthers(keep) {
        if (mutating) return;
        mutating = true;

        try {
            PANEL_SELECTORS.forEach(function (sel) {
                if (sel === keep) return;

                var els = document.querySelectorAll(sel);
                for (var i = 0; i < els.length; i++) {
                    var el = els[i];
                    if (!el.classList.contains(SHOW_CLASS)) continue;

                    el.classList.remove(SHOW_CLASS);
                    openPanels.delete(el);

                    /* 通知面板用的是内联定位（top/right 被 JS 算过），
                     * 关掉时不需要复原 —— 下次打开会重新计算。
                     * 这里只处理 aria 状态，保证无障碍正确。 */
                    if (el.hasAttribute("aria-expanded")) {
                        el.setAttribute("aria-expanded", "false");
                    }
                }

                // 触发器也要同步 aria
                var triggers = document.querySelectorAll('[aria-controls="' + sel.replace(/^\./, "") + '"]');
                for (var k = 0; k < triggers.length; k++) {
                    triggers[k].setAttribute("aria-expanded", "false");
                }
            });
        } finally {
            mutating = false;
        }
    }

    /**
     * 检查某个元素是否是需要管理的面板。
     */
    function panelSelectorOf(el) {
        for (var i = 0; i < PANEL_SELECTORS.length; i++) {
            if (el.matches && el.matches(PANEL_SELECTORS[i])) {
                return PANEL_SELECTORS[i];
            }
        }
        return null;
    }

    /**
     * MutationObserver：监听全站的 class 变化。
     *
     * 性能考虑：
     *   只在 class 属性变化时进回调；回调里先做一次廉价的
     *   「是否含 show 类」判断，绝大多数变化会被立刻过滤掉。
     */
    var observer = new MutationObserver(function (mutations) {
        if (mutating) return;

        for (var i = 0; i < mutations.length; i++) {
            var m = mutations[i];
            if (m.type !== "attributes" || m.attributeName !== "class") continue;

            var el = m.target;
            var sel = panelSelectorOf(el);
            if (!sel) continue;

            var isOpen = el.classList.contains(SHOW_CLASS);

            if (isOpen) {
                if (!openPanels.has(el)) {
                    openPanels.add(el);
                    // 打开了一个 → 关掉其他所有
                    closeOthers(sel);
                }
            } else {
                openPanels.delete(el);
            }
        }
    });

    /**
     * 启动观察。
     *
     * document.body 可能还没解析完，所以：
     *   · 已就绪 → 立即启动
     *   · 未就绪 → 等 DOMContentLoaded
     */
    function start() {
        observer.observe(document.body, {
            attributes: true,
            attributeFilter: ["class"],
            subtree: true
        });
    }

    if (document.readyState === "loading") {
        document.addEventListener("DOMContentLoaded", start, { once: true });
    } else {
        start();
    }

    /* ======================================================================
     * 补充：点击页面空白处关闭所有面板
     * ======================================================================
     * 原来每个面板自己监听 document click，逻辑重复且容易漏。
     * 这里统一兜底：点的地方既不在面板内、也不在触发器内 → 全关。
     *
     * 用 capture 阶段，保证在面板自己的处理之前跑完。
     * ====================================================================== */

    document.addEventListener("click", function (e) {
        var t = e.target;
        if (!t || !t.nodeType) return;

        // 点在任一面板内 → 不关
        for (var i = 0; i < PANEL_SELECTORS.length; i++) {
            if (t.closest && t.closest(PANEL_SELECTORS[i])) {
                // 但如果是面板内的「跳转」链接，允许面板保持到页面跳走
                return;
            }
        }

        // 点在触发器上 → 交给触发器自己处理（它会打开面板）
        if (t.closest && t.closest("[data-panel-trigger], #notificationBell, #userMenuBtn, .user-menu-btn, .notification-bell")) {
            return;
        }

        // 其余情况：关掉所有
        closeOthers(null);
    }, true);

    /* ======================================================================
     * 键盘可访问性：Escape 关闭所有面板
     * ====================================================================== */

    document.addEventListener("keydown", function (e) {
        if (e.key !== "Escape") return;
        if (!openPanels.size) return;
        closeOthers(null);
    });

    /* ======================================================================
     * 对外暴露：供其他模块主动关闭
     * ====================================================================== */

    window.LWPanels = {
        /** 关闭全部面板 */
        closeAll: function () { closeOthers(null); },
        /** 关闭除指定选择器外的面板 */
        closeOthers: closeOthers,
        /** 当前打开的面板选择器列表 */
        openList: function () {
            return Array.from(openPanels).map(function (el) {
                return panelSelectorOf(el);
            }).filter(Boolean);
        }
    };

})();
