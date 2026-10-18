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
            . 'animation-duration:0.001ms!important;'
            . 'animation-iteration-count:1!important;'
            . 'transition-duration:0.12s!important;'
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
        var mem = navigator.deviceMemory;
        if (typeof mem === 'number' && mem > 0 && mem < 4) { low = true; }
        var cores = navigator.hardwareConcurrency;
        if (!low && typeof cores === 'number' && cores > 0 && cores <= 4) { low = true; }
        if (!low && window.matchMedia('(prefers-reduced-motion: reduce)').matches) { low = true; }
    } catch (e) {}
    if (low) { document.documentElement.setAttribute('data-lw-lowperf', '1'); }
})();
</script>
