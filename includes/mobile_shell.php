<?php
/**
 * U 端（移动端独立前端）外壳 —— 唯一来源（手稿「独立 U 端」）
 * ---------------------------------------------------------------------------
 * 桌面端仍是「顶部导航 + 内容 + 右侧栏」的经典信息流；
 * 手机端（≤ 后台配置的手机断点）切换成一套真正的 App 外壳：
 *
 *   ① 极简顶栏：左侧站点名 + 当前页标题，右侧只留搜索与菜单；
 *      桌面端的导航链接、发帖按钮、语言切换全部收进抽屉，不再挤在顶栏里。
 *   ② 抽屉菜单：从右上角滑出，分组列出站内全部入口（未登录也能浏览）。
 *   ③ 底部胶囊导航：沿用 includes/mobile_bottom_nav.php（5 项）。
 *
 * 为什么用 CSS 变量而不是写死宽度：断点来自后台「自适应布局」设置
 * （includes/layout_tokens.php 输出 --lw-bp-mobile），
 * 站长改一次断点，U 端外壳与内容栅格同步切换，不用改代码。
 *
 * 引入方式：放在页面 <body> 内最靠前的位置（紧接 skip-to-content 之后），
 * 作用域需要 $user / $pageTitle / $currentPage。
 */
if (!isset($user)) {
    $user = null;
}
$uIsLogged = is_array($user) && !empty($user['id']);
$uNick = $uIsLogged ? (string) ($user['nickname'] ?? '') : '';
$uAvatar = $uIsLogged ? (string) ($user['avatar'] ?? '') : '';
$uRole = $uIsLogged ? (string) ($user['role'] ?? '') : '';
$uIsAdmin = in_array($uRole, ['admin', 'super_admin'], true);

// 极简顶栏标题：优先页面显式传入的 $pageTitle，没有就用站名
$uTitle = '';
if (isset($pageTitle) && $pageTitle !== '') {
    $uTitle = (string) $pageTitle;
}
$uSiteName = function_exists('getSetting') ? (string) getSetting('site_name', '校园交流墙') : '校园交流墙';
if ($uTitle === '') {
    $uTitle = $uSiteName;
}

/** 抽屉菜单项：桌面端顶栏里有的，这里全都有（顺序按使用频率） */
$uLinks = [
    ['/index.php', 'home', '首页'],
    ['/pages/room.php', 'room', '公共聊天室'],
    ['/pages/shop.php', 'gift', '装扮商城'],
    ['/pages/ranking.php', 'award', '排行榜'],
    ['/pages/growth.php', 'trend', '成长中心'],
    ['/pages/files.php', 'file', '文件共享'],
];
$uMeLinks = [
    ['/pages/user_center.php', 'user', '个人中心'],
    ['/pages/user_center.php?tab=posts', 'file', '我的帖子'],
    ['/pages/user_center.php?tab=favorites', 'star', '我的收藏'],
];
?>
<style>
/* ── U 端外壳（仅手机断点内启用）─────────────────────────────────────────
   桌面端观感零变化：所有选择器都包在 @media 内，且只作用于自身新增节点。 */
.u-appbar, .u-drawer, .u-drawer-mask { display: none; }

@media (max-width: 768px), (max-width: var(--lw-bp-mobile, 640px)) {
    /* 顶栏瘦身：桌面端顶栏内的导航与发帖按钮整体收起（入口全部搬进抽屉） */
    .site-header .main-nav,
    .site-header .header-actions .btn-new-post,
    .site-header .header-actions .lang-switch { display: none !important; }

    /* 站点 logo 在 U 端只留文字，避免与页面标题抢位置 */
    .site-header .header-inner { justify-content: flex-start; }
    .site-header .site-logo .logo-text { max-width: 42vw; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }

    /* 顶栏下方的「当前页标题」条 */
    .u-appbar {
        display: flex;
        align-items: center;
        gap: 0.5rem;
        position: sticky;
        top: var(--header-height, 3.5rem);
        z-index: calc(var(--z-fab, 1000) - 1);
        padding: 0.5rem 0.875rem;
        margin: 0 0 0.5rem;
        background: rgba(255, 255, 255, 0.72);
        backdrop-filter: blur(18px) saturate(170%);
        -webkit-backdrop-filter: blur(18px) saturate(170%);
        border-bottom: 1px solid var(--glass-border, rgba(16, 24, 40, 0.07));
    }
    .dark-theme .u-appbar { background: rgba(20, 27, 38, 0.72); }
    .u-appbar-title {
        flex: 1 1 auto;
        min-width: 0;
        font-size: 0.95rem;
        font-weight: 600;
        color: var(--text, #1f2733);
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
        text-decoration: none;   /* 标题现在是回首页的链接，不能出现下划线 */
    }
    .u-appbar-btn {
        flex: 0 0 auto;
        width: 2.25rem;
        height: 2.25rem;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        border: none;
        border-radius: 0.75rem;
        background: transparent;
        color: var(--text-secondary, #5b667a);
        cursor: pointer;
        transition: background 0.18s ease;
    }
    .u-appbar-btn:active { background: rgba(16, 24, 40, 0.06); }

    /* 抽屉 */
    .u-drawer-mask {
        display: block;
        position: fixed;
        inset: 0;
        z-index: calc(var(--z-fab, 1000) + 20);
        background: rgba(12, 18, 28, 0.32);
        opacity: 0;
        pointer-events: none;
        transition: opacity 0.24s ease;
    }
    .u-drawer-mask.is-open { opacity: 1; pointer-events: auto; }

    .u-drawer {
        display: flex;
        flex-direction: column;
        position: fixed;
        top: 0;
        right: 0;
        bottom: 0;
        z-index: calc(var(--z-fab, 1000) + 21);
        width: min(84vw, 20rem);
        padding: calc(0.75rem + env(safe-area-inset-top, 0px)) 0.75rem calc(0.75rem + env(safe-area-inset-bottom, 0px));
        background: rgba(255, 255, 255, 0.94);
        backdrop-filter: blur(26px) saturate(180%);
        -webkit-backdrop-filter: blur(26px) saturate(180%);
        border-left: 1px solid var(--glass-border, rgba(16, 24, 40, 0.07));
        box-shadow: -18px 0 44px -22px rgba(16, 24, 40, 0.4);
        transform: translateX(102%);
        transition: transform 0.28s cubic-bezier(0.22, 0.8, 0.28, 1);
        overflow-y: auto;
        overscroll-behavior: contain;
    }
    .dark-theme .u-drawer { background: rgba(20, 27, 38, 0.95); }
    .u-drawer.is-open { transform: translateX(0); }

    .u-drawer-head {
        display: flex;
        align-items: center;
        gap: 0.625rem;
        padding: 0.5rem 0.5rem 0.875rem;
        border-bottom: 1px solid var(--border, #eef1f6);
        margin-bottom: 0.5rem;
    }
    .u-drawer-avatar {
        width: 2.5rem;
        height: 2.5rem;
        border-radius: 50%;
        object-fit: cover;
        background: var(--input-bg, #f1f4f9);
        flex: 0 0 auto;
    }
    .u-drawer-who { min-width: 0; flex: 1 1 auto; }
    .u-drawer-nick {
        font-size: 0.9rem;
        font-weight: 600;
        color: var(--text, #1f2733);
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
    }
    .u-drawer-sub { font-size: 0.72rem; color: var(--text-secondary, #5b667a); }

    .u-drawer-group {
        font-size: 0.7rem;
        letter-spacing: 0.06em;
        color: var(--text-secondary, #5b667a);
        padding: 0.625rem 0.625rem 0.375rem;
        text-transform: uppercase;
    }
    .u-drawer-item {
        display: flex;
        align-items: center;
        gap: 0.75rem;
        padding: 0.6875rem 0.625rem;
        border-radius: 0.875rem;
        color: var(--text, #1f2733);
        text-decoration: none;
        font-size: 0.9rem;
        min-height: 2.75rem;
        transition: background 0.16s ease;
    }
    .u-drawer-item:active,
    .u-drawer-item.is-active { background: var(--primary-light, rgba(47, 91, 154, 0.1)); }
    .u-drawer-item svg { flex: 0 0 auto; color: var(--text-secondary, #5b667a); }
    .u-drawer-item.is-active svg { color: var(--primary, #2f5b9a); }
    .u-drawer-foot { margin-top: auto; padding-top: 0.75rem; }
}

/* 低配设备：关掉抽屉与胶囊导航的实时光学模糊（一次性合成，滚动不掉帧） */
@media (max-width: 768px) and (prefers-reduced-motion: reduce) {
    .u-appbar, .u-drawer, .mobile-bottom-nav {
        backdrop-filter: none;
        -webkit-backdrop-filter: none;
    }
    .u-drawer { transition: none; }
}
</style>

<div class="u-appbar" id="uAppbar" aria-hidden="true">
    <a class="u-appbar-title" href="<?= SITE_URL ?>/" aria-label="回到首页"><?= htmlspecialchars($uTitle) ?></a>
    <button type="button" class="u-appbar-btn" id="uSearchBtn" aria-label="搜索">
        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="11" cy="11" r="7"/><line x1="16.5" y1="16.5" x2="21" y2="21"/></svg>
    </button>
    <button type="button" class="u-appbar-btn" id="uMenuBtn" aria-label="菜单" aria-expanded="false" aria-controls="uDrawer">
        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><line x1="4" y1="7" x2="20" y2="7"/><line x1="4" y1="12" x2="20" y2="12"/><line x1="4" y1="17" x2="20" y2="17"/></svg>
    </button>
</div>

<div class="u-drawer-mask" id="uDrawerMask"></div>
<aside class="u-drawer" id="uDrawer" aria-label="功能菜单">
    <div class="u-drawer-head">
        <?php if ($uIsLogged): ?>
            <img class="u-drawer-avatar" loading="lazy" decoding="async" src="<?= htmlspecialchars($uAvatar) ?>" alt="" onerror="this.style.visibility='hidden'">
            <div class="u-drawer-who">
                <div class="u-drawer-nick"><?= htmlspecialchars($uNick) ?></div>
                <div class="u-drawer-sub"><?= $uIsAdmin ? '管理员' : '已登录' ?></div>
            </div>
        <?php else: ?>
            <div class="u-drawer-who">
                <div class="u-drawer-nick">游客模式</div>
                <div class="u-drawer-sub">登录后可发帖与私信</div>
            </div>
        <?php endif; ?>
    </div>

    <div class="u-drawer-group">逛逛</div>
    <?php $uCur = (string) ($_SERVER['SCRIPT_NAME'] ?? ''); ?>
    <?php foreach ($uLinks as $lk): ?>
    <a href="<?= SITE_URL . $lk[0] ?>" class="u-drawer-item<?= $uCur === $lk[0] ? ' is-active' : '' ?>">
        <?= lw_icon($lk[1], 20, ['wrap' => false]) ?>
        <span><?= htmlspecialchars($lk[2]) ?></span>
    </a>
    <?php endforeach; ?>

    <?php if ($uIsLogged): ?>
    <div class="u-drawer-group">我的</div>
    <?php foreach ($uMeLinks as $lk): ?>
    <a href="<?= SITE_URL . $lk[0] ?>" class="u-drawer-item<?= $uCur === explode('?', $lk[0])[0] ? ' is-active' : '' ?>">
        <?= lw_icon($lk[1], 20, ['wrap' => false]) ?>
        <span><?= htmlspecialchars($lk[2]) ?></span>
    </a>
    <?php endforeach; ?>
    <?php endif; ?>

    <div class="u-drawer-foot">
        <?php if ($uIsAdmin): ?>
        <a href="<?= SITE_URL ?>/admin/" class="u-drawer-item"><?= lw_icon('settings', 20, ['wrap' => false]) ?><span>后台管理</span></a>
        <?php endif; ?>
        <?php if ($uIsLogged): ?>
        <a href="<?= SITE_URL ?>/api/logout.php" class="u-drawer-item"><?= lw_icon('logout', 20, ['wrap' => false]) ?><span>退出登录</span></a>
        <?php else: ?>
        <a href="<?= SITE_URL ?>/pages/login.php" class="u-drawer-item"><?= lw_icon('login', 20, ['wrap' => false]) ?><span>登录</span></a>
        <?php endif; ?>
    </div>
</aside>

<script>
(function () {
    var drawer = document.getElementById('uDrawer');
    var mask = document.getElementById('uDrawerMask');
    var btn = document.getElementById('uMenuBtn');
    var searchBtn = document.getElementById('uSearchBtn');
    if (!drawer || !mask || !btn) { return; }

    function open() {
        drawer.classList.add('is-open');
        mask.classList.add('is-open');
        btn.setAttribute('aria-expanded', 'true');
        document.body.style.overflow = 'hidden';
    }
    function close() {
        drawer.classList.remove('is-open');
        mask.classList.remove('is-open');
        btn.setAttribute('aria-expanded', 'false');
        document.body.style.overflow = '';
    }
    btn.addEventListener('click', function () {
        drawer.classList.contains('is-open') ? close() : open();
    });
    mask.addEventListener('click', close);
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') { close(); }
    });

    // 搜索：直接把焦点交给站内已有的搜索框（顶部搜索框在 U 端被隐藏但仍存在于 DOM）
    if (searchBtn) {
        searchBtn.addEventListener('click', function () {
            var box = document.querySelector('.search-box input, input[name="q"]');
            if (box) {
                box.scrollIntoView({ block: 'center', behavior: 'smooth' });
                setTimeout(function () { box.focus(); }, 220);
            } else {
                window.location.href = '<?= SITE_URL ?>/index.php';
            }
        });
    }
})();
</script>
