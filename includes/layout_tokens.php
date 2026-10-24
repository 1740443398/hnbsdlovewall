<?php
/**
 * 布局自适应令牌（需求三、5 的消费端）
 * ---------------------------------------------------------------------------
 * 后台 admin/layout_settings.php 写入 settings 表的 layout_* 键，
 * 这里把它们编译成「一层 CSS 变量 + 一层 @media」，在 <head> 里内联输出。
 *
 * 为什么在服务端算而不是前端读接口：
 *   断点会参与 @media 条件的生成，CSS 媒体查询里的数值无法用 JS 事后替换，
 *   必须由服务端拼进样式表 —— 否则要等脚本执行完才生效，首屏会先闪一下默认布局。
 *
 * 输出的变量全部带 --lw-layout- 前缀，只做「覆盖」，不改任何既有 CSS 文件；
 * 原有样式未引用这些变量时表现完全不变，属于零风险增量。
 */

if (!function_exists('lwLayoutInt')) {
    /** 取一个整数设置并夹在区间内（后台输入框可被绕过，服务端必须再兜一次） */
    function lwLayoutInt($key, $default, $min, $max)
    {
        $raw = function_exists('getSetting') ? getSetting($key, '') : '';
        if ($raw === '' || $raw === null) {
            return (int) $default;
        }
        $v = (int) $raw;
        if ($v < $min) {
            $v = $min;
        }
        if ($v > $max) {
            $v = $max;
        }
        return $v;
    }
}

if (!function_exists('lwLayoutTokens')) {
    /**
     * @return array 归一化后的布局参数
     */
    function lwLayoutTokens()
    {
        static $cache = null;
        if ($cache !== null) {
            return $cache;
        }
        $mobile = lwLayoutInt('layout_breakpoint_mobile', 640, 360, 1200);
        $tablet = lwLayoutInt('layout_breakpoint_tablet', 1024, 640, 1600);
        // 两档断点必须严格递增，否则「平板档」永远不会命中
        if ($tablet <= $mobile) {
            $tablet = $mobile + 160;
        }
        $cache = [
            'mobile'      => $mobile,
            'tablet'      => $tablet,
            'max_width'   => lwLayoutInt('layout_content_max_width', 900, 600, 1600),
            'font_scale'  => lwLayoutInt('layout_font_scale', 100, 80, 130),
            'card_radius' => lwLayoutInt('layout_card_radius', 16, 0, 28),
            'compact'     => lwLayoutInt('layout_compact_mode', 0, 0, 1),
            'space_scale' => lwLayoutInt('layout_space_scale', 75, 50, 100),
        ];
        return $cache;
    }
}

if (!function_exists('lwLayoutCss')) {
    /**
     * 生成 <style> 内容。每次请求只算一次，字符串很小（< 1KB）。
     */
    function lwLayoutCss()
    {
        $t = lwLayoutTokens();
        $root = [];
        $root[] = '--lw-bp-mobile:' . $t['mobile'] . 'px';
        $root[] = '--lw-bp-tablet:' . $t['tablet'] . 'px';
        $root[] = '--lw-content-max:' . $t['max_width'] . 'px';
        $root[] = '--lw-font-scale:' . number_format($t['font_scale'] / 100, 2, '.', '');
        $root[] = '--lw-card-radius:' . $t['card_radius'] . 'px';
        if ($t['compact'] === 1) {
            $root[] = '--lw-space-scale:0.75';
        }
        $css = ':root{' . implode(';', $root) . '}';

        // 版心宽度：容器跟随后台设置，但保留页面的水平内边距
        $css .= '.container{max-width:' . $t['max_width'] . 'px}';

        // 字号缩放：只作用于 body 的 font-size，em/rem 之外的手写 px 不受影响，
        // 避免「一调就全站崩版」。需要整体缩放的模块用 --lw-font-scale 自行乘。
        if ($t['font_scale'] !== 100) {
            $css .= 'body{font-size:' . number_format($t['font_scale'] / 100, 2, '.', '') . 'em}';
        }

        // 卡片圆角：覆盖 root 变量（各组件都引用 --card-radius）
        if ($t['card_radius'] !== 16) {
            $css .= ':root{--card-radius:' . $t['card_radius'] . 'px;--radius:' . max(6, $t['card_radius'] - 2) . 'px}';
        }

        // 紧凑模式：间距刻度整体收 25%（style.css 的 --space-* 全部乘 --space-scale），
        // 并关掉装饰性阴影（低配设备省一次合成）
        if ($t['compact'] === 1) {
            $css .= ':root{--space-scale:' . number_format(($t['space_scale'] ?? 75) / 100, 2, '.', '') . '}';
            $css .= '.card,.post-card,.setting-section{margin-bottom:12px!important;box-shadow:none!important}'
                . '.container{padding-left:10px;padding-right:10px}';
        }
        return $css;
    }
}
?>
<style id="lw-layout-tokens"><?= lwLayoutCss() ?></style>
