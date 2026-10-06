<?php
/**
 * 统一 SVG 图标入口（PHP 端唯一真源，与 assets/js/main.js 的 window.LWIcon 一一对应）
 * ---------------------------------------------------------------------------
 * 用法：
 *   <?= lw_icon('bell', 20) ?>                       // 普通图标
 *   <?= lw_icon('heart', 20, ['state' => 'active'])  // 激活态（红心已点）
 *   <?= lw_icon('refresh', 20, ['state' => 'loading'])?> // 加载态（自转）
 *   <?= lw_icon('bell', 20, ['motion' => 'bell'])    // 加动效容器（动效 CSS 在 style.css 动效层）
 *   <?= lw_icon('menu', 22, ['wrap' => false])       // 不加外层 <span>，动效类直接挂到 <svg> 上
 *
 * 四条硬规范（就是「图标体系」本身）：
 *   B11 尺寸标尺 —— 只允许 16 / 18 / 20 / 22 / 24；传其它值会被吸附到最近档位。
 *   B12 线宽统一 —— 由尺寸推导，不手写：16|18 → 2.2，20 → 2，22 → 1.9，24 → 1.8。
 *   B13 颜色继承 —— 一律 stroke/fill = currentColor，颜色由父级 color 决定，绝不写死色值。
 *        （唯一例外是站点 logo，那是品牌标记不是图标，不经过本函数。）
 *   B14 状态化   —— default(默认) / active(激活) / disabled(禁用) / loading(加载) 四态。
 *
 * 为什么不直接在各页面手写 <svg>：线宽/尺寸/颜色三处最容易各写各的，
 * 同一个铃铛在 A 页面 2px、B 页面 2.5px，时间一长整套图标就会「不齐」。
 */
if (!defined('LW_ICON_SIZES')) {
    define('LW_ICON_SIZES', [16, 18, 20, 22, 24]);
}

/** 把任意尺寸吸附到标尺档位（B11） */
if (!function_exists('lwIconSnapSize')) {
    function lwIconSnapSize($size)
    {
        $size = (int) $size;
        $best = 20;
        $bestDiff = PHP_INT_MAX;
        foreach (LW_ICON_SIZES as $s) {
            $d = abs($s - $size);
            if ($d < $bestDiff) {
                $bestDiff = $d;
                $best = $s;
            }
        }
        return $best;
    }
}

/** 线宽由尺寸推导（B12） */
if (!function_exists('lwIconStroke')) {
    function lwIconStroke($size)
    {
        if ($size <= 18) {
            return '2.2';
        }
        if ($size <= 20) {
            return '2';
        }
        if ($size <= 22) {
            return '1.9';
        }
        return '1.8';
    }
}

/**
 * 图标路径表 —— 全部 24×24 viewBox，纯描边（stroke），不写颜色。
 * 新增图标请只往这里加，不要在页面里手写 <svg>。
 */
if (!function_exists('lwIconPaths')) {
    function lwIconPaths()
    {
        static $paths = null;
        if ($paths !== null) {
            return $paths;
        }
        $paths = [
            'home'      => '<path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/>',
            'star'      => '<polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/>',
            'plus'      => '<line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/>',
            'message'   => '<path d="M21 11.5a8.38 8.38 0 0 1-.9 3.8 8.5 8.5 0 0 1-7.6 4.7 8.38 8.38 0 0 1-3.8-.9L3 21l1.9-5.7a8.38 8.38 0 0 1-.9-3.8 8.5 8.5 0 0 1 4.7-7.6 8.38 8.38 0 0 1 3.8-.9h.5a8.48 8.48 0 0 1 8 8z"/>',
            'user'      => '<path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/>',
            'login'     => '<path d="M15 3h4a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-4"/><polyline points="10 17 15 12 10 7"/><line x1="15" y1="12" x2="3" y2="12"/>',
            'logout'    => '<path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/>',
            'menu'      => '<line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="18" x2="21" y2="18"/>',
            'close'     => '<line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/>',
            'search'    => '<circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/>',
            'bell'      => '<path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/>',
            'heart'     => '<path d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 0 0 0-7.78z"/>',
            'comment'   => '<path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/>',
            'share'     => '<circle cx="18" cy="5" r="3"/><circle cx="6" cy="12" r="3"/><circle cx="18" cy="19" r="3"/><line x1="8.59" y1="13.51" x2="15.42" y2="17.49"/><line x1="15.41" y1="6.51" x2="8.59" y2="10.49"/>',
            'sun'       => '<circle cx="12" cy="12" r="5"/><line x1="12" y1="1" x2="12" y2="3"/><line x1="12" y1="21" x2="12" y2="23"/><line x1="4.22" y1="4.22" x2="5.64" y2="5.64"/><line x1="18.36" y1="18.36" x2="19.78" y2="19.78"/><line x1="1" y1="12" x2="3" y2="12"/><line x1="21" y1="12" x2="23" y2="12"/><line x1="4.22" y1="19.78" x2="5.64" y2="18.36"/><line x1="18.36" y1="5.64" x2="19.78" y2="4.22"/>',
            'moon'      => '<path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"/>',
            'auto'      => '<circle cx="12" cy="12" r="9"/><path d="M12 3a9 9 0 0 0 0 18z" fill="currentColor" stroke="none"/>',
            'arrow-left'=> '<line x1="19" y1="12" x2="5" y2="12"/><polyline points="12 19 5 12 12 5"/>',
            'arrow-up'  => '<line x1="12" y1="19" x2="12" y2="5"/><polyline points="5 12 12 5 19 12"/>',
            'chevron-down' => '<polyline points="6 9 12 15 18 9"/>',
            'download'  => '<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/>',
            'calendar'  => '<rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/>',
            'clock'     => '<circle cx="12" cy="12" r="9"/><polyline points="12 7 12 12 15.5 14"/>',
            'refresh'   => '<polyline points="23 4 23 10 17 10"/><path d="M20.49 15a9 9 0 1 1-2.12-9.36L23 10"/>',
            'check'     => '<polyline points="20 6 9 17 4 12"/>',
            'edit'      => '<path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.12 2.12 0 0 1 3 3L12 15l-4 1 1-4z"/>',
            'trash'     => '<polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/>',
            'image'     => '<rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><polyline points="21 15 16 10 5 21"/>',
            'tag'       => '<path d="M20.59 13.41l-7.17 7.17a2 2 0 0 1-2.83 0L2 12V2h10l8.59 8.59a2 2 0 0 1 0 2.82z"/><line x1="7" y1="7" x2="7.01" y2="7"/>',
            'eye'       => '<path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/>',
            'settings'  => '<circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 1 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06A1.65 1.65 0 0 0 4.6 15a1.65 1.65 0 0 0-1.51-1H3a2 2 0 1 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06A1.65 1.65 0 0 0 9 4.6a1.65 1.65 0 0 0 1-1.51V3a2 2 0 1 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06A1.65 1.65 0 0 0 19.4 9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 1 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/>',
        ];
        return $paths;
    }
}

/**
 * 渲染一个图标。
 *
 * @param string $name  图标名（见 lwIconPaths()）；未知名称返回空串，绝不让页面炸掉
 * @param int    $size  期望尺寸，会吸附到 16/18/20/22/24
 * @param array  $opts  class 附加类名 | motion 动效名(或数组) | state 状态 | wrap 是否加外层 span（默认 true）
 *                      stroke 覆盖线宽 | label 传入则视为有语义图标（不设 aria-hidden，改用 aria-label）
 * @return string
 */
if (!function_exists('lw_icon')) {
    function lw_icon($name, $size = 20, $opts = [])
    {
        $all = lwIconPaths();
        $name = (string) $name;
        if (!isset($all[$name])) {
            return '';
        }
        $opts    = is_array($opts) ? $opts : [];
        $snapped = lwIconSnapSize($size);
        $stroke  = isset($opts['stroke']) ? (string) $opts['stroke'] : lwIconStroke($snapped);

        // 状态 → 类名（B14）
        $state = isset($opts['state']) ? (string) $opts['state'] : 'default';
        $stateClasses = '';
        if ($state === 'loading') {
            $stateClasses .= ' lw-icon-spin';
        } elseif ($state === 'active') {
            $stateClasses .= ' is-on';
        } elseif ($state === 'disabled') {
            $stateClasses .= ' is-disabled';
        }

        // 动效（B2~B10，CSS 实现见 style.css 动效层）
        $motions = '';
        if (!empty($opts['motion'])) {
            $list = is_array($opts['motion']) ? $opts['motion'] : [$opts['motion']];
            foreach ($list as $m) {
                $m = preg_replace('/[^a-z0-9-]/i', '', (string) $m);
                if ($m !== '') {
                    $motions .= ' lw-icon-' . $m;
                }
            }
        }

        $extra = isset($opts['class']) ? ' ' . trim((string) $opts['class']) : '';
        $label = isset($opts['label']) ? trim((string) $opts['label']) : '';
        $a11y  = $label !== ''
            ? ' role="img" aria-label="' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '"'
            : ' aria-hidden="true"';

        $modifiers = $motions . $stateClasses . $extra;

        // wrap=false：动效类直接挂到 <svg> 上，避免多一层 <span> 破坏父级「直接子元素」选择器
        // （典型：.mobile-nav-item > span 带 overflow:hidden，会裁掉激活态图标的药丸阴影）
        $noWrap = array_key_exists('wrap', $opts) && $opts['wrap'] === false;
        $svgClass = 'lw-icon' . $modifiers;

        $svg = '<svg class="' . htmlspecialchars($svgClass, ENT_QUOTES, 'UTF-8') . '"'
            . ' width="' . $snapped . '" height="' . $snapped . '" viewBox="0 0 24 24" fill="none"'
            . ' stroke="currentColor" stroke-width="' . htmlspecialchars($stroke, ENT_QUOTES, 'UTF-8') . '"'
            . ' stroke-linecap="round" stroke-linejoin="round"' . $a11y . '>'
            . $all[$name] . '</svg>';

        if ($noWrap) {
            return $svg;
        }

        return '<span class="lw-icon-wrap lw-icon--' . htmlspecialchars($name, ENT_QUOTES, 'UTF-8') . $modifiers . '">'
            . $svg . '</span>';
    }
}

/**
 * 空状态「插画」（B17）—— 与图标同源的入口，但产出的是多元素分层插画：
 * 柔和色块底 + 主体线条 + 两枚点缀星光，比单线条图标更耐看，也更适合大面积留白。
 * 颜色同样全部走 currentColor 与设计令牌（--macaron-*），不写死色值。
 *
 * @param string $kind posts|search|comments|favorites|notifications|error
 * @param int    $size 宽度像素，高度按 4:3 自动推导
 */
if (!function_exists('lw_illustration')) {
    function lw_illustration($kind = 'posts', $size = 96)
    {
        $size = max(48, (int) $size);
        $h = (int) round($size * 0.75);
        $parts = [
            'posts' => '<rect x="18" y="14" width="46" height="42" rx="8" fill="currentColor" opacity=".08"/>'
                . '<rect x="26" y="20" width="46" height="42" rx="8" fill="none" stroke="currentColor" stroke-width="2.2" opacity=".55"/>'
                . '<line x1="34" y1="33" x2="62" y2="33" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" opacity=".45"/>'
                . '<line x1="34" y1="42" x2="56" y2="42" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" opacity=".35"/>'
                . '<line x1="34" y1="51" x2="48" y2="51" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" opacity=".25"/>'
                . '<ellipse cx="52" cy="66" rx="26" ry="4" fill="currentColor" opacity=".07"/>',
            'search' => '<circle cx="42" cy="30" r="17" fill="currentColor" opacity=".08"/>'
                . '<circle cx="42" cy="30" r="17" fill="none" stroke="currentColor" stroke-width="2.4" opacity=".6"/>'
                . '<line x1="54" y1="42" x2="68" y2="56" stroke="currentColor" stroke-width="3" stroke-linecap="round" opacity=".6"/>'
                . '<line x1="30" y1="58" x2="50" y2="58" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-dasharray="5 5" opacity=".35"/>'
                . '<line x1="30" y1="65" x2="44" y2="65" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-dasharray="5 5" opacity=".25"/>',
            'comments' => '<path d="M20 22h48a8 8 0 0 1 8 8v18a8 8 0 0 1-8 8H44l-12 10V56h-4a8 8 0 0 1-8-8V30a8 8 0 0 1 8-8z" fill="currentColor" opacity=".08"/>'
                . '<path d="M18 20h46a8 8 0 0 1 8 8v18a8 8 0 0 1-8 8H40l-11 9V54h-3a8 8 0 0 1-8-8V28a8 8 0 0 1 8-8z" fill="none" stroke="currentColor" stroke-width="2.2" opacity=".55"/>'
                . '<circle cx="32" cy="37" r="2.6" fill="currentColor" opacity=".4"/>'
                . '<circle cx="43" cy="37" r="2.6" fill="currentColor" opacity=".4"/>'
                . '<circle cx="54" cy="37" r="2.6" fill="currentColor" opacity=".4"/>',
            'favorites' => '<path d="M48 12l9.6 19.4 21.4 3.1-15.5 15.1 3.7 21.3L48 60.8 28.8 70.9l3.7-21.3L17 34.5l21.4-3.1z" fill="currentColor" opacity=".1"/>'
                . '<path d="M48 14l8.8 17.8 19.6 2.9-14.2 13.8 3.4 19.5L48 58.9 30.4 68l3.4-19.5L19.6 34.7l19.6-2.9z" fill="none" stroke="currentColor" stroke-width="2.2" opacity=".55" stroke-linejoin="round"/>',
            'notifications' => '<path d="M48 14a16 16 0 0 0-16 16c0 17-6 21-6 21h44s-6-4-6-21a16 16 0 0 0-16-16z" fill="currentColor" opacity=".08"/>'
                . '<path d="M48 14a16 16 0 0 0-16 16c0 17-6 21-6 21h44s-6-4-6-21a16 16 0 0 0-16-16z" fill="none" stroke="currentColor" stroke-width="2.2" opacity=".55" stroke-linejoin="round"/>'
                . '<path d="M42 58a6 6 0 0 0 12 0" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" opacity=".55"/>',
            'error' => '<path d="M40 20a14 14 0 0 0 0 28 12 12 0 0 0 22 6 11 11 0 0 0 8-20 15 15 0 0 0-30-14z" fill="currentColor" opacity=".08"/>'
                . '<path d="M40 20a14 14 0 0 0 0 28 12 12 0 0 0 22 6 11 11 0 0 0 8-20 15 15 0 0 0-30-14z" fill="none" stroke="currentColor" stroke-width="2.2" opacity=".55" stroke-linejoin="round"/>'
                . '<line x1="48" y1="30" x2="48" y2="40" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" opacity=".6"/>'
                . '<circle cx="48" cy="46" r="1.8" fill="currentColor" opacity=".6"/>',
        ];
        $body = $parts[$kind] ?? $parts['posts'];
        // 两枚点缀星光：用马卡龙令牌上色，给纯灰插画一点「活气」
        $sparks = '<path d="M16 18l1.6 4.4L22 24l-4.4 1.6L16 30l-1.6-4.4L10 24l4.4-1.6z" fill="var(--macaron-sky, #9CC7F0)" opacity=".85"/>'
            . '<path d="M82 46l1.2 3.3L86.5 50.5l-3.3 1.2L82 55l-1.2-3.3L77.5 50.5l3.3-1.2z" fill="var(--macaron-mint, #8FD9C0)" opacity=".85"/>';

        return '<svg class="lw-illustration lw-illustration--' . htmlspecialchars($kind, ENT_QUOTES, 'UTF-8') . '"'
            . ' width="' . $size . '" height="' . $h . '" viewBox="0 0 96 72" fill="none"'
            . ' role="img" aria-hidden="true" focusable="false">' . $body . $sparks . '</svg>';
    }
}