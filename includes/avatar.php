<?php
/**
 * 用户头像复合件（头像 + 头像框 + 等级环 + 迷你勋章）—— 全站唯一真源
 * ---------------------------------------------------------------------------
 * 【为什么必须有这个文件】
 * 之前「头像 + 环」有三种写法：个人中心用负偏移贴环、个人主页用 lwAvatarSlot、
 * 其他地方直接裸 <img>。结果就是环心与圆心偏开、只有部分页面有头像框、
 * 换个页面观感全变。错位的根因从来不是"CSS 没调好"，而是**没有单一结构**。
 *
 * 【同心刚性布局】
 * 所有部件都以「槽位边长 S」为唯一基准，用同一个公式摆：
 *   槽位   width/height = S，position: relative
 *   头像   inset = pad，直径 = S - 2*pad，居中
 *   头像框 inset = 0，铺满 S
 *   等级环 inset = 0，铺满 S（SVG viewBox 与 S 同步）
 *   等级角标 right/bottom = 0
 * 因为每个部件的圆心都等于 (S/2, S/2)，无论 S 取多少、字体缩放到几档，
 * 都只可能同心 —— 这是几何事实，不是"目测对齐"。
 *
 * 【等级环与头像框会打架吗】
 * 不会，因为它们的半径不同：头像框是 S 的外沿（最外圈），等级环画在
 * S 的内侧 stroke/2 处。两者重叠时会自然形成「外框 + 内进度环」的双环，
 * 这正是用户截图里那种效果。所以两者可以同时开。
 */

require_once __DIR__ . '/widget_render.php';
require_once __DIR__ . '/user_level_badge.php';

if (!function_exists('lwAvatarComposite')) {
    /**
     * 渲染一个同心头像复合件。
     *
     * @param array       $user      用户数组（至少含 id；可含 avatar / nickname / qq）
     * @param string      $size      尺寸：'xs'(24) 'sm'(32) 'md'(44) 'lg'(64) 'xl'(96)，或传入数字像素
     * @param array       $opts      ring=是否画等级环(默认 true) frame=是否画头像框(默认 true)
     *                               badges=是否画迷你勋章(默认 false) extra=额外类名
     *                               anonymous=匿名时用默认头像且不画任何装扮
     * @return string HTML
     */
    function lwAvatarComposite(array $user, string $size = 'md', array $opts = []): string
    {
        $sizes = ['xs' => 24, 'sm' => 32, 'md' => 44, 'lg' => 64, 'xl' => 96];
        $px = $sizes[$size] ?? (int)$size;
        if ($px <= 0) {
            $px = 44;
        }

        $anonymous = !empty($opts['anonymous']);
        $withRing  = !$anonymous && ($opts['ring'] ?? true);
        $withFrame = !$anonymous && ($opts['frame'] ?? true);
        $withBadge = !$anonymous && !empty($opts['badges']);
        $extra     = trim((string)($opts['extra'] ?? ''));

        $uid = (int)($user['id'] ?? 0);
        $decor = ($uid > 0 && ($withFrame || $withBadge)) ? lwUserDecorBundle($uid) : ['frame' => null, 'badges' => []];

        $avatar = $anonymous
            ? lwDefaultAvatar()
            : lwAvatarUrl($user['avatar'] ?? '');
        $alt = $anonymous ? '匿名用户' : (string)($user['nickname'] ?? '');

        // 外径尺寸全部走内联变量：一个 class 管尺寸，不再让各页面自己写宽高
        $cls = 'lw-av';
        if ($withRing)  { $cls .= ' has-ring'; }
        if ($withFrame) { $cls .= ' has-frame'; }
        if ($withFrame && !empty($decor['frame']) && trim((string)($decor['frame']['asset'] ?? '')) !== '') {
            $cls .= ' has-img-frame';
        }
        if ($extra !== '') { $cls .= ' ' . lwEsc($extra); }

        $html = '<span class="' . $cls . '" style="--lw-av: ' . $px . 'px">';

        // 头像框在最底层铺满，头像覆盖其上（框的环形部分从外沿露出来）
        if ($withFrame && !empty($decor['frame'])) {
            $f = $decor['frame'];
            $asset = trim((string)($f['asset'] ?? ''));
            if ($asset !== '') {
                // 图片型头像框：后台上传的 PNG/WebP，直接铺满槽位
                $html .= '<span class="lw-widget lw-widget-frame lw-frame-img" aria-hidden="true"'
                       . ' style="background-image:url(\'' . lwEsc($asset) . '\')"></span>';
            } else {
                $html .= '<span class="lw-widget lw-widget-frame ' . lwEsc($f['css_class'] ?? '') . '" aria-hidden="true"></span>';
            }
        }

        // 内联宽高双重保险：widgets.css 一旦没加载（缓存/主机 MIME 问题），
        // 光靠外部 CSS 的 width/height 会让 img 以原始尺寸撑爆容器 ——
        // 表现出来就是"头像框里只有几根线条、下面一个圆圈"。内联写死就不会。
        //
        // 图片型头像框要收得更小：这类框是「图案围着人像」的构图，
        // 图案的镂空口径大约只占槽位 6 成，头像按普通槽位的 88% 铺满会被框压住脸。
        $imgFrame = ($withFrame && !empty($decor['frame']) && trim((string)($decor['frame']['asset'] ?? '')) !== '');
        $inset = (int)round($px * ($imgFrame ? 0.17 : 0.06));
        $diam  = $px - $inset * 2;

        $html .= '<img class="lw-av-img" src="' . lwEsc($avatar) . '" alt="' . lwEsc($alt) . '"'
               . ' width="' . $diam . '" height="' . $diam . '"'
               . ' style="position:absolute;left:' . $inset . 'px;top:' . $inset . 'px;'
               . 'width:' . $diam . 'px;height:' . $diam . 'px;border-radius:50%;object-fit:cover;"'
               . ' loading="lazy" decoding="async"'
               . ' onerror="this.onerror=null;this.src=\'' . lwEsc(lwDefaultAvatar()) . '\'">';

        // 等级环：SVG 与槽位同尺寸，圆心必然重合
        if ($withRing && $uid > 0) {
            $info = lwLevelRow($uid);
            $html .= lwLevelRingInline($info, $px);
        }

        // 迷你勋章：小圆点上浮在右下，不占布局空间
        if ($withBadge && !empty($decor['badges'])) {
            $b = $decor['badges'][0];
            $html .= '<span class="lw-av-medal" title="' . lwEsc($b['name'] ?? '') . '">'
                   . lwEsc(mb_substr((string)($b['name'] ?? '勋'), 0, 1)) . '</span>';
        }

        $html .= '</span>';
        return $html;
    }
}

if (!function_exists('lwLevelRingInline')) {
    /**
     * 与槽位同尺寸的等级进度环（同心版）。
     *
     * 与 {@see lwLevelRingHTML()} 的区别：那个是给成长中心独立展示用的，
     * 尺寸 = avatar + 16；这个是**在槽位内部**画的，尺寸必须严格等于槽位，
     * 否则环会跑出槽位或与头像不同心。
     */
    function lwLevelRingInline(array $info, int $slotPx): string
    {
        if (!lwLevelEnabled()) {
            return '';
        }
        $level   = (int)($info['level'] ?? 1);
        $title   = (string)($info['title'] ?? '新人');
        $percent = (float)($info['percent'] ?? 0);
        if (!empty($info['is_max'])) {
            $percent = 100.0;
        }

        // 线宽随尺寸缩放：24px 的小头像上 4px 会糊成一团
        $stroke = $slotPx >= 72 ? 3.5 : ($slotPx >= 44 ? 3 : ($slotPx >= 32 ? 2.4 : 2));
        $r  = ($slotPx - $stroke) / 2;
        $c  = 2 * M_PI * $r;
        $on = round($c * max(0, min(100, $percent)) / 100, 2);

        return '<span class="lw-av-ring" title="Lv.' . $level . ' ' . lwEsc($title) . ' · ' . round($percent) . '%" aria-hidden="true">'
             . '<svg viewBox="0 0 ' . $slotPx . ' ' . $slotPx . '" width="' . $slotPx . '" height="' . $slotPx . '">'
             . '<circle cx="' . ($slotPx / 2) . '" cy="' . ($slotPx / 2) . '" r="' . $r . '"'
             . ' fill="none" stroke="currentColor" stroke-opacity=".14" stroke-width="' . $stroke . '"/>'
             . '<circle cx="' . ($slotPx / 2) . '" cy="' . ($slotPx / 2) . '" r="' . $r . '"'
             . ' fill="none" stroke="currentColor" stroke-width="' . $stroke . '" stroke-linecap="round"'
             . ' stroke-dasharray="' . $on . ' ' . round($c - $on, 2) . '"'
             . ' transform="rotate(-90 ' . ($slotPx / 2) . ' ' . ($slotPx / 2) . ')"/>'
             . '</svg></span>';
    }
}

if (!function_exists('lwAvatarOf')) {
    /**
     * 便捷入口：给一个用户 id 就出复合头像（内部只查一次装扮，有进程内缓存）。
     */
    function lwAvatarOf(int $userId, string $size = 'md', array $opts = []): string
    {
        if ($userId <= 0) {
            return lwAvatarComposite([], $size, array_merge($opts, ['anonymous' => true]));
        }
        static $cache = [];
        if (!isset($cache[$userId])) {
            $u = getFS()->findById('users', $userId);
            $cache[$userId] = $u ?: [];
        }
        if (!$cache[$userId]) {
            return lwAvatarComposite([], $size, array_merge($opts, ['anonymous' => true]));
        }
        return lwAvatarComposite($cache[$userId], $size, $opts);
    }
}
