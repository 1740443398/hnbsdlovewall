<?php
/**
 * 低配设备样式降级（需求三、7「低性能设备性能适配」）
 * ---------------------------------------------------------------------------
 * 与 assets/js/liquid-glass.js 的 isLowPerf() 判据保持一致（内存 / 核数 / WebGL），
 * 但这里用 **纯 CSS 媒体查询** 先兜一层：脚本还没跑、或脚本被禁用时，样式侧同样降级。
 *
 * 降级的取舍（只砍「观感溢价」，不动功能）：
 *   - backdrop-filter 多层模糊 → 单层或纯色：这是移动端最贵的合成操作
 *   - 大面积 box-shadow → 1px 描边：阴影同样要逐像素合成
 *   - 无限循环装饰动效 → 静帧：省电
 * 文字、间距、层级、交互全部保留，不会出现「降级后看不懂」。
 *
 * data-lw-lowperf="1" 由 JS 在运行时补写（脚本判定比媒体查询更准），
 * 两套判据取并集，谁命中都降级。
 */

if (!function_exists('lwLowPerfCss')) {
    /**
     * @return string 可直接内联进 <style> 的 CSS
     */
    function lwLowPerfCss()
    {
        $sel = 'html[data-lw-lowperf="1"]';
        return $sel . ' *,'
            . $sel . ' *::before,'
            . $sel . ' *::after{'
            . 'backdrop-filter:none!important;'
            . '-webkit-backdrop-filter:none!important;'
            . 'box-shadow:none!important;'
            /* 【改成】只让「无限循环动画」只播一次，不砍一次性入场动画。
             *
             * 原来这里还有 animation-duration:0.001ms!important ——
             * 它会把**所有** CSS 动画压成瞬时，包括：
             *   · 首屏卡片的 mp-card-in 淡入上浮
             *   · 弹窗的 lwSheetIn 底部升起
             *   · 骨架屏的 shimmer
             * 结果是低性能设备上「加了 17 项动效，一项也看不到」。
             *
             * 这比「动画稍慢」糟糕得多：用户会觉得「动画根本没做」。
             *
             * 现在只保留 animation-iteration-count:1 ——
             * 循环动画（骨架 shimmer、呼吸灯类）只播一次，省掉持续重绘；
             * 一次性的入场动画照常播完。真正的性能大头是
             * backdrop-filter / box-shadow（上面已经全部关掉）。
             */
            . 'animation-iteration-count:1!important;'
            /* 【已移除】原本这里还有 transition-duration:0.12s!important。
             *
             * 为什么删掉：
             *   过渡（transition）是 GPU 合成，开销极小；真正耗性能的是
             *   backdrop-filter、box-shadow 和无限循环 animation —— 这三样
             *   上面已经砍掉了，性能收益已经拿到。
             *
             *   而把过渡压到 120ms 的代价极大：按压回弹没有弹性、
             *   滚动渐显来不及看清、弹层滑出像闪一下 ——
             *   所有动效都变成「一闪而过」，这恰恰是「生硬」的来源。
             *
             *   低性能设备该做的是「少做特效」，不是「把特效演快」。
             */
            . '}'
            // 玻璃面板退化为不透明底色，避免半透明叠半透明造成的重复合成
            . $sel . ' .site-header,'
            . $sel . ' .mobile-bottom-nav,'
            . $sel . ' .u-appbar,'
            . $sel . ' .u-drawer{'
            . 'background:var(--surface,#fff)!important;'
            . 'border-color:var(--border,#e5e9f0)!important;'
            . '}'
            . $sel . '.dark-theme .site-header,'
            . $sel . '.dark-theme .mobile-bottom-nav,'
            . $sel . '.dark-theme .u-appbar,'
            . $sel . '.dark-theme .u-drawer{'
            . 'background:var(--surface,#161d29)!important;'
            . '}'
            // 流体背景画布直接不占合成层
            . $sel . ' .lw-lg-canvas{display:none!important}';
    }
}
?>
<style id="lw-lowperf-css"><?= lwLowPerfCss() ?></style>
<script>
(function () {
    // 脚本侧判定（与 liquid-glass.js 同口径），命中即给 <html> 打标；
    // 打标必须在首帧前完成，所以这段是同步内联脚本，不放 defer。
    var low = false;
    try {
        /* 【门槛收紧】原来是 mem < 4GB 或 cores <= 4 就判定低性能。
         *
         * 这个门槛太激进：2020 年之后的中端安卓机大量是 4 核/4GB，
         * 它们跑这些 CSS 过渡完全没压力，却被打上低性能标记，
         * 结果是「特效全砍 + 过渡压到 120ms」，界面像十年前的功能机。
         *
         * 现在收紧到 2 核 / 2GB —— 那是真·低端机（或者被浏览器降频的设备）。
         * 中端机保留完整动效。
         */
        var mem = navigator.deviceMemory;
        if (typeof mem === 'number' && mem > 0 && mem < 2) { low = true; }
        var cores = navigator.hardwareConcurrency;
        if (!low && typeof cores === 'number' && cores > 0 && cores <= 2) { low = true; }
        if (!low && window.matchMedia('(prefers-reduced-motion: reduce)').matches) { low = true; }
    } catch (e) {}
    if (low) { document.documentElement.setAttribute('data-lw-lowperf', '1'); }
})();
</script>
