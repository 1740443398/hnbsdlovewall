<?php
/**
 * 用户头衔徽章渲染 —— 唯一来源
 *
 * 原先只在 pages/post_detail.php 里定义，用户主页（pages/u.php）也需要同一套样式，
 * 因此抽到这里由两边共用，避免头衔配色/彩虹渐变逻辑出现两份实现。
 *
 * 入参：$u 用户记录（读取 title_text / title_color / title_bg_color /
 *       title_rainbow / title_gradient_start / title_gradient_end），
 *       没有头衔时返回空字符串。
 */
if (!function_exists('lwSafeCssColor')) {
    /**
     * 只允许“纯粹的颜色值”进入 style 属性。
     *
     * 为什么不能只靠 htmlspecialchars()：它只转义 < > & " '，
     * 而 CSS 声明里的分号是合法字符 —— 值写成
     *   `red;position:fixed;top:0;left:0;width:100vw;height:100vh;z-index:99999`
     * 就能在页面上铺一层不可见覆盖层（点击劫持 / 钓鱼），htmlspecialchars 完全拦不住。
     * 头衔颜色虽由管理员配置，仍按纵深防御做成格式白名单：
     * 非法值一律回退为默认色，而不是原样拼进 CSS。
     */
    function lwSafeCssColor($value, $default)
    {
        $v = trim((string)($value ?? ''));
        if ($v === '') {
            return $default;
        }
        // #rgb / #rgba / #rrggbb / #rrggbbaa
        if (preg_match('/^#[0-9a-fA-F]{3,8}$/', $v)) {
            return $v;
        }
        // rgb() / rgba() / hsl() / hsla()（只含数字、逗号、空格、点、百分号）
        if (preg_match('/^(rgb|rgba|hsl|hsla)\(\s*[\d.,%\s]+\)$/i', $v)) {
            return $v;
        }
        // 具名颜色只放行一批安全的常见色，避免 expression() / var() 之类被带进来
        static $named = [
            'transparent', 'currentcolor', 'inherit', 'initial', 'unset',
            'white', 'black', 'red', 'green', 'blue', 'yellow', 'orange',
            'purple', 'pink', 'gray', 'grey', 'gold', 'silver', 'cyan',
            'magenta', 'brown', 'navy', 'teal', 'olive', 'maroon', 'lime',
            'aqua', 'fuchsia', 'indigo', 'violet', 'coral', 'salmon', 'tomato',
        ];
        if (in_array(strtolower($v), $named, true)) {
            return $v;
        }
        return $default;
    }
}

if (!function_exists('lwRelativeLuminance')) {
    /** WCAG 相对亮度；给出的不是可解析的十六进制色时返回 null。 */
    function lwRelativeLuminance($color)
    {
        $hex = ltrim((string)$color, '#');
        if (strlen($hex) === 3) {
            $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
        }
        if (!preg_match('/^[0-9a-f]{6}$/i', $hex)) {
            return null;
        }
        $lin = static function ($v) {
            $v /= 255;
            return $v <= 0.03928 ? $v / 12.92 : pow(($v + 0.055) / 1.055, 2.4);
        };
        return 0.2126 * $lin(hexdec(substr($hex, 0, 2)))
             + 0.7152 * $lin(hexdec(substr($hex, 2, 2)))
             + 0.0722 * $lin(hexdec(substr($hex, 4, 2)));
    }
}

if (!function_exists('lwReadableFg')) {
    /**
     * 在一组背景色里挑「最不容易糊」的前景色（白 / 近黑），返回 #FFFFFF 或 #10161F。
     *
     * 为什么需要：称号徽章的底色由站长自选，默认还是 #ff4757→#a55eea 的彩虹渐变，
     * 而前景色过去写死 #fff —— 实测白字落在这些默认底色上只有 3.34~3.90:1（不达
     * WCAG AA 正文 4.5:1），站长一旦选了偏亮的渐变，称号就完全看不清了。
     * 这里按 WCAG 取「在全部候选底色上的最小对比度」最大的那个前景色。
     * $preferred 非空时不覆盖（站长显式指定的颜色优先）。
     */
    function lwReadableFg(array $backgrounds, $preferred = '')
    {
        if ($preferred !== '' && $preferred !== null) {
            return $preferred;
        }
        $best = '#FFFFFF';
        $bestWorst = -1.0;
        foreach (['#FFFFFF', '#10161F'] as $fg) {
            $lf = lwRelativeLuminance($fg);
            $worst = null;
            foreach ($backgrounds as $bg) {
                $lb = lwRelativeLuminance($bg);
                if ($lb === null) {
                    continue;   // rgb()/hsl()/具名色解析不了，跳过，剩下的仍有意义
                }
                $ratio = (max($lf, $lb) + 0.05) / (min($lf, $lb) + 0.05);
                $worst = ($worst === null) ? $ratio : min($worst, $ratio);
            }
            if ($worst !== null && $worst > $bestWorst) {
                $bestWorst = $worst;
                $best = $fg;
            }
        }
        return $best;
    }
}

if (!function_exists('renderUserTitleHTML')) {
    function renderUserTitleHTML($u)
    {
        if (empty($u['title_text'])) {
            return '';
        }
        // 默认传 '' 而不是 '#fff'：空串代表「站长没指定」，交给下面的自动选色决定
        $fg = lwSafeCssColor($u['title_color'] ?? '', '');
        if (!empty($u['title_rainbow'])) {
            $gs = lwSafeCssColor($u['title_gradient_start'] ?? '', '#ff4757');
            $ge = lwSafeCssColor($u['title_gradient_end'] ?? '', '#a55eea');
            $fg = lwReadableFg([$gs, $ge], $fg);
            $style = '--ut-gs:' . $gs . ';--ut-ge:' . $ge . ';--ut-fg:' . $fg . ';';
            return '<span class="user-title is-rainbow" style="' . $style . '">' . htmlspecialchars($u['title_text']) . '</span>';
        }
        $bg = lwSafeCssColor($u['title_bg_color'] ?? '', '#4A90D9');
        $fg = lwReadableFg([$bg], $fg);
        $style = '--ut-bg:' . $bg . ';--ut-fg:' . $fg . ';';
        return '<span class="user-title" style="' . $style . '">' . htmlspecialchars($u['title_text']) . '</span>';
    }
}
