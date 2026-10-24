<?php
/**
 * 等级徽章渲染 —— 唯一来源
 *
 * 为什么单独一个文件：帖子卡片、评论、用户主页、个人中心四处都要显示「Lv.N 段位」，
 * 若各写一份，样式与取数口径迟早分叉（出现过「列表页显示 Lv.3，点进去变 Lv.2」这类问题
 * 通常就是取数口径不一致导致的）。统一走这里，等级计算只依赖 includes/level.php。
 *
 * 用法：
 *   require_once __DIR__ . '/../includes/user_level_badge.php';
 *   echo lwLevelBadge($user);                       // 自动按 id 取等级
 *   echo lwLevelBadge($user, $info);                // 已有批次数据时直接传入，省一次读
 *   echo lwLevelBadgeHTML($info, 'lg');             // 只有等级信息时
 */

require_once __DIR__ . '/level.php';

if (!function_exists('lwLevelBadgeHTML')) {
    /**
     * 由等级信息直接生成徽章 HTML。
     *
     * @param array  $info lwLevelProgress() 的返回值
     * @param string $size 'sm' | 'md' | 'lg'
     */
    function lwLevelBadgeHTML(array $info, string $size = 'sm'): string
    {
        if (!lwLevelEnabled()) {
            return '';
        }
        $level = (int)($info['level'] ?? 1);
        $title = (string)($info['title'] ?? '新人');
        $size  = in_array($size, ['sm', 'md', 'lg'], true) ? $size : 'sm';

        // 段位配色：与前台设计系统的中性色系协调，只用一层浅底 + 深字，不抢内容注意力
        $tier = 'lv-tier-1';
        if ($level >= 25) {
            $tier = 'lv-tier-6';
        } elseif ($level >= 20) {
            $tier = 'lv-tier-5';
        } elseif ($level >= 15) {
            $tier = 'lv-tier-4';
        } elseif ($level >= 10) {
            $tier = 'lv-tier-3';
        } elseif ($level >= 5) {
            $tier = 'lv-tier-2';
        }

        $text = 'Lv.' . $level . ' ' . $title;

        return '<span class="lw-level-badge lw-level-' . $size . ' ' . $tier . '"'
             . ' title="' . xss_clean($text . ' · 共 ' . (int)($info['exp'] ?? 0) . ' 经验') . '"'
             . ' aria-label="等级 ' . $level . '，段位 ' . xss_clean($title) . '">'
             . '<span class="lw-level-num">' . $level . '</span>'
             . '<span class="lw-level-title">' . xss_clean($title) . '</span>'
             . '</span>';
    }
}

if (!function_exists('lwLevelBadge')) {
    /**
     * 由用户数组生成徽章。$info 传了就用，没传就按 id 查一次。
     *
     * @param array      $user 至少含 id
     * @param array|null $info 预取的等级信息（批量场景传入可避免 N 次查库）
     */
    function lwLevelBadge(array $user, ?array $info = null, string $size = 'sm'): string
    {
        $uid = (int)($user['id'] ?? 0);
        if ($uid <= 0) {
            return '';
        }
        if ($info === null) {
            $info = lwLevelRow($uid);
        }
        return lwLevelBadgeHTML($info, $size);
    }
}

if (!function_exists('lwLevelRingHTML')) {
    /**
     * 带进度环的等级徽章（个人中心 / 成长中心用）。
     * 用 SVG 圆环表示升级进度，纯 CSS 无法可靠画「带缺口的环」，所以走内联 SVG。
     */
    function lwLevelRingHTML(array $info, int $avatarSize = 96): string
    {
        $level   = (int)($info['level'] ?? 1);
        $title   = (string)($info['title'] ?? '新人');
        $percent = (float)($info['percent'] ?? 0);
        if (!empty($info['is_max'])) {
            $percent = 100.0;
        }

        $size   = max(48, min(160, $avatarSize + 16));
        $stroke = 4;
        $r      = ($size - $stroke) / 2;
        $c      = 2 * M_PI * $r;
        $dash   = round($c * ($percent / 100), 2);
        $gap    = round($c - $dash, 2);

        return '<span class="lw-level-ring" style="width:' . $size . 'px;height:' . $size . 'px"'
             . ' title="Lv.' . $level . ' ' . xss_clean($title) . ' · ' . $percent . '%">'
             . '<svg viewBox="0 0 ' . $size . ' ' . $size . '" width="' . $size . '" height="' . $size . '" aria-hidden="true">'
             . '<circle cx="' . ($size / 2) . '" cy="' . ($size / 2) . '" r="' . $r . '" fill="none" stroke="currentColor" stroke-opacity=".15" stroke-width="' . $stroke . '"/>'
             . '<circle cx="' . ($size / 2) . '" cy="' . ($size / 2) . '" r="' . $r . '" fill="none" stroke="currentColor" stroke-width="' . $stroke . '"'
             . ' stroke-linecap="round" stroke-dasharray="' . $dash . ' ' . $gap . '"'
             . ' transform="rotate(-90 ' . ($size / 2) . ' ' . ($size / 2) . ')"/>'
             . '</svg>'
             . '<span class="lw-level-ring-label">Lv.' . $level . '</span>'
             . '</span>';
    }
}
