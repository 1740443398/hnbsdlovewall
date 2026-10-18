<?php
/**
 * 挂件渲染组件（唯一真源）
 * ---------------------------------------------------------------------------
 * **所有**展示头像/气泡/勋章的地方都必须调用这里的函数，不许各自手写 HTML。
 * 这是「杜绝布局错位」的最后一道闸：标记结构只有一份，CSS 槽位只有一份，
 * 任何页面都不可能"换个写法就把挂件画歪"。
 *
 * 渲染结构与 assets/css/widgets.css 的槽位契约一一对应：
 *   lwAvatarSlot()  → .lw-avatar-slot（固定 1:1 方形槽）
 *   lwBubble()      → .lw-bubble-slot（固定内边距 + 定死尾巴）
 *   lwBadge()       → .lw-badge-slot（固定高度行内槽，nowrap）
 */

require_once __DIR__ . '/widgets.php';

if (!function_exists('lwEsc')) {
    function lwEsc($s): string
    {
        return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

if (!function_exists('lwDefaultAvatar')) {
    function lwDefaultAvatar(): string
    {
        return '/assets/images/default-avatar.svg';
    }
}

if (!function_exists('lwAvatarUrl')) {
    /**
     * 头像地址：支持站内相对路径与 QQ 头像外链；无头像时回落默认图。
     * 需求二、5「开放头像自定义更换，支持动态图片格式头像」——gif/webp 动图也走这里。
     */
    function lwAvatarUrl(?string $avatar): string
    {
        $a = trim((string)$avatar);
        if ($a === '') {
            return lwDefaultAvatar();
        }
        if (strpos($a, '/') === 0) {
            return $a;   // 站内相对路径（用户自传）
        }
        if (preg_match('#^https://#i', $a)) {
            return $a;   // 外链（QQ 头像等）
        }
        return lwDefaultAvatar();
    }
}

if (!function_exists('lwAvatarSlot')) {
    /**
     * 头像 + 头像框槽位。
     *
     * @param array|null $frame    lwUserWidgets() 返回的 avatar_frame 项（null 表示无框）
     * @param string     $avatar   头像 URL
     * @param string     $alt      替代文本
     * @param string     $extraClass 额外类名（尺寸变体用）
     */
    function lwAvatarSlot(?array $frame, string $avatar, string $alt = '', string $extraClass = ''): string
    {
        $url = lwAvatarUrl($avatar);
        // 图片型头像框要单独标一个类：它的镂空口径只占槽位六成，
        // 头像必须缩到 66% 并居中，否则脸会被图案压住（见 widgets.css 的 .has-img-frame）。
        $imgFrame = ($frame && trim((string)($frame['asset'] ?? '')) !== '');
        $cls = 'lw-slot lw-avatar-slot' . ($imgFrame ? ' has-img-frame' : '');
        if ($extraClass !== '') {
            $cls .= ' ' . lwEsc($extraClass);
        }
        $html = '<span class="' . $cls . '">';
        $html .= '<img class="lw-avatar-img" src="' . lwEsc($url) . '" alt="' . lwEsc($alt) . '" loading="lazy" decoding="async"'
               . ' onerror="this.onerror=null;this.src=\'' . lwEsc(lwDefaultAvatar()) . '\'">';
        if ($frame) {
            // 挂件层只允许铺满槽位（CSS .lw-widget 强制 inset:0）。
            // 图片型框必须把 asset 写成 background-image —— 只给类名的话
            // 这层 span 是空的，页面上什么都看不到（类名只是尺寸钩子）。
            $asset = trim((string)($frame['asset'] ?? ''));
            if ($asset !== '') {
                $html .= '<span class="lw-widget lw-widget-frame lw-frame-img" aria-hidden="true"'
                       . ' style="background-image:url(\'' . lwEsc($asset) . '\')"></span>';
            } else {
                $cls = 'lw-widget lw-widget-frame ' . lwEsc($frame['css_class'] ?? '');
                $html .= '<span class="' . trim($cls) . '" aria-hidden="true"></span>';
            }
        }
        $html .= '</span>';
        return $html;
    }
}

if (!function_exists('lwBubbleSlot')) {
    /**
     * 气泡槽位：包裹消息正文。
     *
     * @param array|null $bubble   lwUserWidgets() 返回的 bubble 项
     * @param string     $inner    **已转义**的正文 HTML
     * @param bool       $tail     是否画尾巴（私聊左侧气泡用）
     * @param string     $extraClass 额外类名
     */
    function lwBubbleSlot(?array $bubble, string $inner, bool $tail = false, string $extraClass = ''): string
    {
        $cls = 'lw-slot lw-bubble-slot';
        if ($bubble) {
            // 气泡样式只改颜色/背景，不改内边距与圆角 —— 保证不错位
            $cls .= ' ' . lwEsc($bubble['css_class'] ?? 'lw-bubble-default');
        }
        if ($tail) {
            $cls .= ' has-tail';
        }
        if ($extraClass !== '') {
            $cls .= ' ' . lwEsc($extraClass);
        }
        return '<span class="' . trim($cls) . '">' . $inner . '</span>';
    }
}

if (!function_exists('lwBadgeSlot')) {
    /**
     * 勋章槽位（行内固定高度，永不换行）。
     *
     * @param array|null $badge  lwUserWidgets() 返回的 badge 项，或 ['name'=>...]
     */
    function lwBadgeSlot(?array $badge): string
    {
        if (!$badge || empty($badge['name'])) {
            return '';
        }
        $cls = 'lw-badge-slot';
        if (!empty($badge['css_class'])) {
            $cls .= ' ' . lwEsc($badge['css_class']);
        }
        return '<span class="' . $cls . '">' . lwEsc($badge['name']) . '</span>';
    }
}

if (!function_exists('lwBadgeRow')) {
    /**
     * 成就/勋章行：把成就列表渲染成一排固定高度的徽章。
     * @param array $badges lwUserBadges() 的返回
     */
    function lwBadgeRow(array $badges, int $max = 8): string
    {
        if (!$badges) {
            return '';
        }
        $html = '<div class="lw-badge-row">';
        foreach (array_slice($badges, 0, $max) as $b) {
            $html .= '<span class="lw-badge-slot" title="' . lwEsc($b['desc'] ?? '') . '">' . lwEsc($b['name'] ?? '成就') . '</span>';
        }
        $html .= '</div>';
        return $html;
    }
}

if (!function_exists('lwUserDecorBundle')) {
    /**
     * 一次性取出某用户的全部装扮（带进程内缓存，避免列表页逐条查库）。
     * @return array{frame:?array,bubble:?array,badge:?array,badges:array}
     */
    function lwUserDecorBundle(int $userId): array
    {
        static $cache = [];
        // 缓存有效性跟着「用户装饰表」的版本走。
        // 以前这里是纯静态缓存、永不清空：同一个请求里先渲染头像（读到旧装扮）、
        // 再执行装备/购买，后面渲染出来的还是旧框 —— 表现为
        // 「点了装备没反应，刷新一下又好了」。带上版本号后，写入即失效。
        $ver = function_exists('lwWidgetsVersion') ? lwWidgetsVersion() : 0;
        $key = $userId . '@' . $ver;
        if (isset($cache[$key])) {
            return $cache[$key];
        }
        $w = lwUserWidgets($userId);
        $bundle = [
            'frame'  => $w['avatar_frame'] ?? null,
            'bubble' => $w['bubble'] ?? null,
            'badge'  => $w['badge'] ?? null,
            'badges' => lwUserBadges($userId),
        ];
        $cache[$key] = $bundle;
        return $bundle;
    }
}
