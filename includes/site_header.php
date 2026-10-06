<?php
/**
 * 全站顶栏 —— 唯一来源，所有带标准顶栏的页面都 require 本文件。
 *
 * 作用域契约（调用方只需准备变量，其余一律在本文件内兜底）：
 *   必需：$user               当前登录用户数组；未登录传 null / 不传均可
 *   可选：$isGuest            未登录且处于游客模式（本文件不输出游客横幅，仅用于右侧按钮判断）
 *         $headerSiteName     站点名，默认依次取 $siteName / SITE_NAME / getSetting('site_name')
 *         $headerBackHref     非空则在右侧渲染「返回」胶囊按钮
 *         $headerBackText     返回按钮文案，默认「返回首页」
 *         $headerShowSearch   预留的顶栏搜索槽位开关（默认 false）
 *         $headerGuestCta     未登录时右侧按钮：'register'(默认) | 'login' | 'none'
 *
 * 输出四部分：
 *   1) <header class="site-header">…</header>
 *   2) .sheet-backdrop —— 必须放在 header **外面**：header 自身有 backdrop-filter 会形成层叠上下文，
 *      遮罩若放进去会盖住 header 本身，且无法压住页面内容
 *   3) 「申请头衔」弹窗（title_request_modal.php）—— 同理必须放 header **外面**：
 *      backdrop-filter 会成为 position:fixed 后代的包含块，放里面弹窗会错位（桌面端一直错）
 *   4) 一段自包含兜底脚本 —— 为不加载 main.js 的页面（pages/post.php、pages/user_center.php）补上
 *      头像菜单 / 退出登录 / 主题切换。main.js 的 UserMenu.init() 会设置 window.__lwUserMenuBound，
 *      本脚本在其之后执行时自动让位，避免双重绑定。
 *
 * 修改顶栏结构请只改这里，避免各页面再次出现差异。
 */
if (!isset($user)) {
    $user = null;
}
if (!isset($headerSiteName) || $headerSiteName === '') {
    if (isset($siteName) && $siteName !== '') {
        $headerSiteName = $siteName;
    } elseif (defined('SITE_NAME') && SITE_NAME !== '') {
        $headerSiteName = SITE_NAME;
    } else {
        $headerSiteName = getSetting('site_name', '校园交流墙');
    }
}
$headerShowSearch = !empty($headerShowSearch);
if (!isset($headerBackHref)) {
    $headerBackHref = '';
}
if (!isset($headerBackText) || $headerBackText === '') {
    $headerBackText = '返回首页';
}
if (!isset($headerGuestCta) || $headerGuestCta === '') {
    $headerGuestCta = 'register';
}
?>
<header class="site-header">
    <div class="header-inner">
        <button type="button" class="mobile-menu-btn" id="mobileMenuBtn" aria-label="菜单" aria-expanded="false">
            <?= lw_icon('menu', 22, ['wrap' => false, 'stroke' => '2.5']) ?>
        </button>
        <a href="<?= SITE_URL ?>/" class="site-logo">
            <svg class="logo-icon" width="30" height="30" viewBox="0 0 24 24" fill="none">
                <defs>
                    <linearGradient id="logoGrad" x1="0%" y1="0%" x2="100%" y2="100%">
                        <stop offset="0%" stop-color="#C9A96E"/>
                        <stop offset="50%" stop-color="#E8D5A3"/>
                        <stop offset="100%" stop-color="#B8943E"/>
                    </linearGradient>
                </defs>
                <path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z" fill="url(#logoGrad)"/>
                <polyline points="9 22 9 12 15 12 15 22" fill="none" stroke="#fff" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
            </svg>
            <span class="logo-text"><?= htmlspecialchars($headerSiteName) ?></span>
        </a>
        <?php if ($headerShowSearch): ?>
        <div class="header-search-slot" data-mount="header-search"></div>
        <?php endif; ?>
        <div class="header-actions" data-mount="header-end">
            <?php if ($headerBackHref !== ''): ?>
            <a href="<?= htmlspecialchars($headerBackHref) ?>" class="btn btn-outline btn-sm header-back-btn">
                <?= lw_icon('arrow-left', 16, ['wrap' => false]) ?>
                <span class="header-back-text"><?= htmlspecialchars($headerBackText) ?></span>
            </a>
            <?php endif; ?>
            <?php require __DIR__ . '/user_dropdown.php'; ?>
            <?php if (!$user && $headerGuestCta !== 'none'): ?>
            <?php if ($headerGuestCta === 'login'): ?>
            <a href="<?= SITE_URL ?>/pages/login.php" class="btn btn-primary btn-sm"><?= t('nav.login') ?></a>
            <?php else: ?>
            <a href="<?= SITE_URL ?>/pages/register.php" class="btn btn-primary guest-header-cta"><?= t('guest.register_cta') ?></a>
            <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>
</header>

<?php require __DIR__ . '/title_request_modal.php'; ?>

<div class="sheet-backdrop" id="sheetBackdrop" aria-hidden="true"></div>

<script>
(function () {
    // 顶栏头像菜单的「弹层化」补全脚本，分三层职责：
    //   ① 外观同步（所有页面都跑）：遮罩显示、body 滚动锁、aria-expanded。用 MutationObserver 跟随
    //      .show 类，这样无论开合是 main.js 的 UserMenu 触发还是本脚本触发，外观都一致。
    //   ② 关闭手势（所有页面都跑）：点遮罩、Esc、手机端下滑。main.js 的 UserMenu 只做了
    //      「点按钮开合 + 点外部关闭」，缺这三个，所以这里补齐后两个分支共用。
    //   ③ 兜底绑定（仅未加载 main.js 的页面）：开合、退出登录、主题切换。
    //      main.js 的 UserMenu.init() 会设置 window.__lwUserMenuBound，见到标记就让位，避免双重绑定。
    function ready(fn) {
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', fn, { once: true });
        } else {
            fn();
        }
    }
    ready(function () {
        var btn = document.getElementById('userMenuBtn');
        var dd = document.getElementById('userDropdown');
        if (!btn || !dd) return;

        var backdrop = document.getElementById('sheetBackdrop');

        function isMobile() {
            return window.matchMedia('(max-width: 768px)').matches;
        }
        function isOpen() {
            return dd.classList.contains('show');
        }
        function closeSheet() {
            dd.classList.remove('show');
        }

        // ① 外观同步
        function syncSheet() {
            var on = isOpen();
            if (backdrop) { backdrop.classList.toggle('sheet-open', on); }
            document.body.classList.toggle('sheet-locked', on);
            btn.setAttribute('aria-expanded', on ? 'true' : 'false');
        }
        if (window.MutationObserver) {
            new MutationObserver(syncSheet).observe(dd, { attributes: true, attributeFilter: ['class'] });
        }
        syncSheet();

        // ② 关闭手势
        if (backdrop) {
            backdrop.addEventListener('click', closeSheet);
        }
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && isOpen()) { closeSheet(); }
        });

        // 手机端底部弹层：向下拖动关闭（仅在面板已滚到顶部时生效，避免抢走内部滚动）
        var startY = 0;
        var tracking = false;
        dd.addEventListener('touchstart', function (e) {
            if (!isMobile() || !isOpen() || e.touches.length !== 1) { tracking = false; return; }
            tracking = true;
            startY = e.touches[0].clientY;
        }, { passive: true });
        dd.addEventListener('touchmove', function (e) {
            if (!tracking) { return; }
            if (dd.scrollTop > 0) { tracking = false; return; }
            if (e.touches[0].clientY - startY > 80) {
                tracking = false;
                closeSheet();
            }
        }, { passive: true });
        dd.addEventListener('touchend', function () { tracking = false; }, { passive: true });

        // ③ 兜底绑定：main.js 已接管则退出
        if (window.__lwUserMenuBound) { return; }
        window.__lwHeaderUIBound = true;

        btn.addEventListener('click', function (e) {
            e.preventDefault();
            e.stopPropagation();
            if (isOpen()) { closeSheet(); } else { dd.classList.add('show'); }
        });
        document.addEventListener('click', function (e) {
            if (!isOpen()) { return; }
            if (btn.contains(e.target) || dd.contains(e.target)) { return; }
            closeSheet();
        });

        function csrf() {
            // window.CSRF_TOKEN 由 includes/theme_boot.php 在下发（全站唯一真源）：
            // 没有内联 `const CSRF_TOKEN` 的页面也能拿到令牌，不再整页 POST 都 403。
            if (typeof CSRF_TOKEN !== 'undefined' && CSRF_TOKEN) { return CSRF_TOKEN; }
            return window.CSRF_TOKEN || '';
        }
        function post(url, body) {
            return fetch(url, {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                    'X-Requested-With': 'XMLHttpRequest'
                },
                body: body
            });
        }

        // 主题切换（兜底实现：仅用于未加载 main.js 的页面）。
        // 真正的主题逻辑在 includes/theme_boot.php 的 window.LWTheme，这里只驱动它并同步按钮文案。
        var themeBtn = document.getElementById('themeToggle');
        if (themeBtn) {
            var themeLabel = function (pref) {
                var tr = function (k, fb) { return (typeof window.__t === 'function') ? window.__t(k) : fb; };
                if (pref === 'dark') { return tr('uc.theme_dark', '深色'); }
                if (pref === 'light') { return tr('uc.theme_light', '浅色'); }
                return tr('uc.theme_system', '跟随系统');
            };
            var syncLabel = function (pref) {
                var lbl = themeBtn.querySelector('.theme-toggle-label');
                if (lbl) { lbl.textContent = themeLabel(pref); }
                themeBtn.setAttribute('data-theme-pref', pref);
            };
            if (window.LWTheme) { syncLabel(window.LWTheme.pref()); }
            themeBtn.addEventListener('click', function () {
                if (!window.LWTheme) { return; }
                window.LWTheme.withTransition();
                var pref = window.LWTheme.next();
                syncLabel(pref);
                post('/api/user/update_theme.php', 'theme=' + encodeURIComponent(pref) + '&csrf_token=' + encodeURIComponent(csrf()))
                    .catch(function () {});
            });
        }

        var outBtn = document.getElementById('logoutBtn');
        if (outBtn) {
            outBtn.addEventListener('click', function (e) {
                e.preventDefault();
                post('/api/auth/logout.php', 'csrf_token=' + encodeURIComponent(csrf()))
                    .catch(function () {})
                    .then(function () { window.location.href = '/'; });
            });
        }
    });
})();
</script>
