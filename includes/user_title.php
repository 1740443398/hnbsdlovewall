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
if (!function_exists('renderUserTitleHTML')) {
    function renderUserTitleHTML($u)
    {
        if (empty($u['title_text'])) {
            return '';
        }
        $fg = htmlspecialchars(($u['title_color'] ?? '') ?: '#fff');
        if (!empty($u['title_rainbow'])) {
            $gs = htmlspecialchars(($u['title_gradient_start'] ?? '') ?: '#ff4757');
            $ge = htmlspecialchars(($u['title_gradient_end'] ?? '') ?: '#a55eea');
            $style = '--ut-gs:' . $gs . ';--ut-ge:' . $ge . ';--ut-fg:' . $fg . ';';
            return '<span class="user-title is-rainbow" style="' . $style . '">' . htmlspecialchars($u['title_text']) . '</span>';
        }
        $bg = htmlspecialchars(($u['title_bg_color'] ?? '') ?: '#4A90D9');
        $style = '--ut-bg:' . $bg . ';--ut-fg:' . $fg . ';';
        return '<span class="user-title" style="' . $style . '">' . htmlspecialchars($u['title_text']) . '</span>';
    }
}
