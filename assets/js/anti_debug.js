/*
 * F17 轻量反调试探针（非阻断）。
 * 设计原则：只探测、不破坏。devtools 打开时给控制台提示并置反篡改哨兵，
 * 绝不清空页面或阻断正常用户（避免误伤普通访客/低端机）。
 * 真正的「反篡改」强校验由构建脚本 --obfuscate 模式注入（见 tools_obfuscate_readme.md）。
 */
(function () {
    'use strict';
    try {
        // 反篡改哨兵：默认恒真；--obfuscate 模式下若被外部篡改会变为 false 触发重载。
        window.__lw_ok = true;

        var devtoolsOpen = false;
        var threshold = 160; // 视窗内外尺寸差超过该值（px）判定为 devtools 停靠
        setInterval(function () {
            var w = window.outerWidth - window.innerWidth;
            var h = window.outerHeight - window.innerHeight;
            var open = (w > threshold) || (h > threshold);
            if (open && !devtoolsOpen) {
                devtoolsOpen = true;
                console.warn('%c[LoveWall] 检测到开发者工具已打开', 'color:#c0392b;font-weight:bold');
            } else if (!open) {
                devtoolsOpen = false;
            }
        }, 1000);
    } catch (e) {
        /* 探针失败绝不影响主流程 */
    }
})();
