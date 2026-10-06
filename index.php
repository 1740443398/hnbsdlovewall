<?php
require_once __DIR__ . '/config/config.php';

$fs = getFS();

$maintenanceMode = getSetting('maintenance_mode', '0') == '1';
$maintenanceMsg = getSetting('maintenance_message', '网站正在维护中，请稍后再来。');
if ($maintenanceMode) {
    $user = getCurrentUser();
    $isAdmin = $user && in_array($user['role'], ['admin', 'super_admin']);
    if (!$isAdmin) {
        require_once __DIR__ . '/pages/maintenance.php';
        exit();
    }
}

// 已登录用户正常访问；游客模式（未注册先逛逛）可浏览受限内容；其余跳登录页
$user = requireLoginOrGuest();
if ($user) {
    $user = checkBanned($user);
}
$isGuest = ($user === null);
// 记录本次访问（总访问量 + 该用户访问次数）
trackVisit($user);
$isSponsor = false;
if ($user && isset($user['qq'])) {
    $sponsors = $fs->read('sponsors');
    $sponsorQQs = array_column($sponsors, 'qq');
    $isSponsor = in_array($user['qq'], $sponsorQQs);
}
$bannedMsg = '';
if ($user && $user['is_banned']) {
    $bannedMsg = '账号已被封禁：' . ($user['ban_reason'] ?? '');
    if ($user['ban_until']) {
        $bannedMsg .= '（解封时间：' . $user['ban_until'] . '）';
    }
}

$announcement = getSetting('announcement', '');
$siteName = getSetting('site_name', '淮南北师大实验中学高中部校园交流墙');

$categories = [
    ['key' => 'lost_found', 'name' => t('cat.lost_found')],
    ['key' => 'study_help', 'name' => t('cat.study_help')],
    ['key' => 'social_chat', 'name' => t('cat.social_chat')],
    ['key' => 'confession', 'name' => t('cat.confession')],
    ['key' => 'school_info', 'name' => t('cat.school_info')],
    ['key' => 'other', 'name' => t('cat.other')],
];
?>
<!DOCTYPE html>
<html lang="<?= htmlspecialchars($LANG_CODE) ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
<?php require_once __DIR__ . '/includes/pwa_head.php'; ?>
    <script src="<?= asset_url('/assets/js/anti_hijack.js') ?>?v=<?= asset_ver('/assets/js/anti_hijack.js') ?>"></script>
    <link rel="icon" href="/icon.ico" type="image/x-icon">
    <title><?= htmlspecialchars($siteName) ?></title>
    <?php require __DIR__ . '/includes/seo_meta.php'; ?>
    <meta name="description" content="<?= htmlspecialchars(t('site.meta_desc')) ?>">
    <link rel="stylesheet" href="<?= asset_url('/assets/css/style.css') ?>?v=<?= asset_ver('/assets/css/style.css') ?>">
    <link rel="stylesheet" href="<?= asset_url('/assets/css/enhancements.css') ?>?v=<?= asset_ver('/assets/css/enhancements.css') ?>">
    <script>
        const SITE_URL = '<?= SITE_URL ?>';
        const IS_LOGGED_IN = <?= $user ? 'true' : 'false' ?>;
        const USER_DATA = <?= $user ? json_encode(['id' => $user['id'], 'qq' => $user['qq'], 'uuid' => $user['uuid'] ?? '', 'nickname' => $user['nickname'], 'avatar' => $user['avatar'], 'role' => $user['role']], JSON_HEX_TAG | JSON_UNESCAPED_UNICODE) : 'null' ?>;
        const CSRF_TOKEN = '<?= generateCSRFToken() ?>';
        const IS_SPONSOR = <?= $isSponsor ? 'true' : 'false' ?>;
        const IS_GUEST = <?= $isGuest ? 'true' : 'false' ?>;
        const GUEST_VISIBLE_POSTS = <?= (int) GUEST_VISIBLE_POSTS ?>;
        const GUEST_BLOCKED_MSG = <?= json_encode(t('guest.blocked_detail'), JSON_UNESCAPED_UNICODE) ?>;
        const PAGE_LANG = '<?= $LANG_CODE ?>';
        const LANG_DICT = <?= json_encode($LANG, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
    </script>
</head>
<body>
    <a class="skip-to-content" href="#main-content">跳到主内容</a>
    <?php if ($bannedMsg): ?>
    <div class="ban-banner">
        <div class="container">
            <span class="ban-text"><?= htmlspecialchars($bannedMsg) ?></span>
        </div>
    </div>
    <?php endif; ?>

    <?php require __DIR__ . '/includes/site_header.php'; ?>

    <?php require __DIR__ . '/includes/ai_widget.php'; ?>

    <?php if ($isGuest): ?>
    <div class="guest-bar">
        <div class="container">
            <span class="guest-bar-text">
                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
                <?= t('guest.banner') ?>
            </span>
            <a href="<?= SITE_URL ?>/pages/register.php" class="guest-bar-btn"><?= t('guest.register_cta') ?></a>
        </div>
    </div>
    <?php endif; ?>

    <?php if ($announcement): ?>
    <div class="announcement-bar">
        <div class="container">
            <div class="announcement-scroll">
                <span><?= htmlspecialchars($announcement) ?></span>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <main id="main-content" class="site-main">
        <div class="container">
            <div class="content-wrapper">
                <div class="main-content">
                    <?php if ($isGuest): ?>
                    <div class="guest-feed-note">
                        <?= str_replace('{n}', GUEST_VISIBLE_POSTS, t('guest.feed_note')) ?>
                    </div>
                    <?php else: ?>
                    <div class="feed-sticky">
                    <div class="feed-toolbar">
                        <div class="search-box">
                            <input type="text" id="searchInput" placeholder="<?= t('home.search_ph') ?>" class="search-input">
                            <button class="btn-icon" id="searchBtn" aria-label="<?= t('home.search_aria') ?>">
                                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="11" cy="11" r="7"/><line x1="16.5" y1="16.5" x2="21" y2="21"/></svg>
                            </button>
                        </div>
                        <div class="sort-tabs">
                            <button class="sort-tab active" data-sort="latest"><?= t('home.latest') ?></button>
                            <button class="sort-tab" data-sort="hot"><?= t('home.hot') ?></button>
                            <a class="sort-tab" href="<?= SITE_URL ?>/pages/ranking.php"><?= t('rank.page_title') ?></a>
                        </div>
                    </div>

                    <div class="category-filters">
                        <button class="cat-filter active" data-cat="all"><?= t('home.all') ?></button>
                        <?php foreach ($categories as $cat): ?>
                        <button class="cat-filter" data-cat="<?= $cat['key'] ?>"><?= htmlspecialchars($cat['name']) ?></button>
                        <?php endforeach; ?>
                    </div>
                    </div><!-- /.feed-sticky：手机端吸顶，桌面端 display:contents 等于不存在 -->
                    <?php endif; ?>

                    <div class="posts-container" id="postsContainer">
                        <div class="loading-skeleton">
                            <div class="skeleton-item"></div>
                            <div class="skeleton-item"></div>
                            <div class="skeleton-item"></div>
                        </div>
                    </div>

                    <?php if ($isGuest): ?>
                    <div class="guest-cta">
                        <div class="guest-cta-title"><?= t('guest.cta_title') ?></div>
                        <p class="guest-cta-desc"><?= t('guest.cta_desc') ?></p>
                        <div class="guest-cta-actions">
                            <a href="<?= SITE_URL ?>/pages/register.php" class="btn btn-primary"><?= t('guest.register_cta') ?></a>
                            <a href="<?= SITE_URL ?>/pages/login.php" class="btn btn-outline"><?= t('nav.login') ?></a>
                        </div>
                    </div>
                    <?php else: ?>
                    <div class="load-more" id="loadMore" style="display:none">
                        <button class="btn btn-outline" id="loadMoreBtn"><?= t('home.load_more') ?></button>
                    </div>
                    <div class="no-more" id="noMore" style="display:none">
                        <span><?= t('home.no_more') ?></span>
                    </div>
                    <?php endif; ?>
                </div>

                <aside class="sidebar">
                    <div class="widget widget-rules">
                        <h3><?= t('sidebar.rules') ?></h3>
                        <div class="widget-content rules-preview">
                            <ul>
                                <li><?= t('sidebar.rules.i1') ?></li>
                                <li><?= t('sidebar.rules.i2') ?></li>
                                <li><?= t('sidebar.rules.i3') ?></li>
                                <li><?= t('sidebar.rules.i4') ?></li>
                            </ul>
                        </div>
                    </div>
                    <div class="widget">
                        <h3><?= t('sidebar.links') ?></h3>
                        <div class="widget-content">
                            <a href="<?= $isGuest ? SITE_URL . '/pages/register.php' : '/pages/post.php' ?>"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" style="vertical-align:middle;margin-right:4px"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z" fill="none" stroke="currentColor" stroke-width="2"/><polyline points="14 2 14 8 20 8" fill="none" stroke="currentColor" stroke-width="2"/><path d="M12 18v-6m0 0-2 2m2-2 2 2" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></svg><?= t('sidebar.post') ?></a>
                            <a href="/pages/tools.php">
                                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" style="vertical-align:middle;margin-right:4px"><circle cx="12" cy="12" r="3" fill="currentColor"/><path d="M12 2v4m0 12v4M2 12h4m12 0h4" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/><circle cx="12" cy="12" r="9" fill="none" stroke="currentColor" stroke-width="1.5" opacity="0.5"/></svg>
                                <?= t('nav.tools') ?>
                            </a>
                            <?php if ($isGuest): ?>
                            <a href="<?= SITE_URL ?>/pages/register.php"><?= t('guest.register_cta') ?></a>
                            <a href="<?= SITE_URL ?>/pages/login.php"><?= t('nav.login') ?></a>
                            <?php endif; ?>
                        </div>
                    </div>
                </aside>
            </div>
        </div>
    </main>

    <div class="modal-overlay" id="guideModal" style="display:none;">
        <div class="modal" style="max-width:520px;">
            <div class="modal-header">
                <h3 class="modal-title">👋 <?= t('guide.title') ?></h3>
            </div>
            <div class="modal-body" style="font-size:0.9rem;color:var(--text);line-height:1.9;">
                <p style="margin-bottom:4px;"><?= t('guide.p1') ?></p>
                <ul style="padding-left:18px;margin:8px 0 4px;">
                    <li>📝 <?= t('guide.l1') ?></li>
                    <li>🎯 <?= t('guide.l2') ?></li>
                    <li>✉️ <?= t('guide.l3') ?></li>
                    <li>⭐ <?= t('guide.l4') ?></li>
                </ul>
                <p style="font-size:0.82rem;color:var(--text-muted);"><?= t('guide.p2') ?></p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-primary" id="guideOk"><?= t('guide.start') ?></button>
            </div>
        </div>
    </div>

    <?php /* 「回到顶部」已并入右下的统一浮动按钮（polish.js 的 .lw-fab），
             此处不再单独渲染静态按钮，避免同屏出现两个回顶入口。 */ ?>

    <?php
    $mobileNavActive = 'home';
    require __DIR__ . '/includes/mobile_bottom_nav.php';
    ?>

    <div class="toast-container" id="toastContainer"></div>

    <div class="modal-overlay" id="sponsorModal" style="display:none">
        <div class="modal sponsor-modal">
            <div class="modal-header">
                <h3><?= t('sponsor.title') ?></h3>
                <button class="modal-close sponsor-close" aria-label="<?= t('sponsor.close') ?>">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <line x1="18" y1="6" x2="6" y2="18"/>
                        <line x1="6" y1="6" x2="18" y2="18"/>
                    </svg>
                </button>
            </div>
            <div class="modal-body sponsor-body">
                <p class="sponsor-desc"><?= t('sponsor.desc') ?></p>
                <div class="sponsor-qr-group">
                    <div class="sponsor-qr">
                        <img src="/zanzhu/zanzhu.png" alt="微信赞助码" class="sponsor-img" onerror="this.style.display='none';this.nextElementSibling.style.display='block'">
                        <p class="sponsor-qr-placeholder" style="display:none"><?= t('sponsor.loading') ?></p>
                        <p class="sponsor-hint"><?= t('sponsor.wechat') ?></p>
                    </div>
                    <div class="sponsor-qr">
                        <img src="/zanzhu/zz.jpg" alt="支付宝赞助码" class="sponsor-img" onerror="this.style.display='none';this.nextElementSibling.style.display='block'">
                        <p class="sponsor-qr-placeholder" style="display:none"><?= t('sponsor.loading') ?></p>
                        <p class="sponsor-hint"><?= t('sponsor.alipay') ?></p>
                    </div>
                </div>
                <div class="sponsor-info" id="sponsorInfo">
                    <div class="sponsor-amount"><?= t('sponsor.total') ?><strong id="sponsorAmount">--</strong> 元</div>
                    <div class="sponsor-list" id="sponsorList"></div>
                </div>
            </div>
            <div class="modal-footer">
                <button class="btn btn-primary sponsor-close"><?= t('sponsor.close') ?></button>
            </div>
        </div>
    </div>

    <footer class="site-footer">
        <div class="container">
            <p><?= t('footer.disclaimer') ?></p>
            <p>&copy; 2026 <?= htmlspecialchars($siteName) ?> · 蕭遞版权所有</p>
            <p><?= t('footer.open_source') ?><a href="<?= htmlspecialchars(GITHUB_REPO_URL) ?>" target="_blank" rel="noopener noreferrer"><?= htmlspecialchars(GITHUB_REPO_NAME) ?></a></p>
            <p style="opacity:.7;font-size:12px;"><?= t('footer.ai_note') ?></p>
        </div>
    </footer>

    <script>
    (function(){
      var spClose = document.querySelectorAll('.sponsor-close');
      for (var i=0; i<spClose.length; i++) {
        spClose[i].onclick = function(){ var m=document.getElementById('sponsorModal'); if(m) m.style.display='none'; };
      }
      var navItems = document.querySelectorAll('.mobile-nav-item');
      var currentPath = window.location.pathname;
      navItems.forEach(function(item) {
        var href = item.getAttribute('href');
        if (href === '/' || href === '/index.php') {
          if (currentPath === '/' || currentPath === '/index.php' || currentPath === '') item.classList.add('active');
        } else if (href && currentPath.indexOf(href.replace(/\/pages\/.*/, '')) === 0) {
        }
        if (href && (currentPath === href || (href !== '/' && currentPath.indexOf(href.split('#')[0]) === 0))) {
          navItems.forEach(function(n) { n.classList.remove('active'); });
          item.classList.add('active');
        }
      });
    })();
    </script>
    <script>
    (function () {
      // 新用户新手指引：登录用户首次访问时展示一次
      try {
        if (typeof IS_LOGGED_IN === 'undefined' || !IS_LOGGED_IN) return;
        var KEY = 'lovewall_guide_v1';
        if (localStorage.getItem(KEY) === '1') return;
        var modal = document.getElementById('guideModal');
        var okBtn = document.getElementById('guideOk');
        if (!modal || !okBtn) return;
        function show(done) {
          modal.style.display = 'flex';
          okBtn.addEventListener('click', function () {
            localStorage.setItem(KEY, '1');
            modal.style.display = 'none';
            done();
          });
        }
        // 与赞助弹窗、社区规范等统一排队，依次展示，避免同时叠加弹出
        // （main.js 以 defer 加载，需等它执行完、PopupQueue 就绪后再入队）
        document.addEventListener('DOMContentLoaded', function () {
          if (window.PopupQueue) window.PopupQueue.push(show);
          else show(function () {});
        });
      } catch (e) {}
    })();
    </script>
    <script src="<?= asset_url('/assets/js/main.js') ?>?v=<?= asset_ver('/assets/js/main.js') ?>" defer></script>
    <script src="<?= asset_url('/assets/js/enhancements.js') ?>?v=<?= asset_ver('/assets/js/enhancements.js') ?>" defer></script>
    <?php require_once __DIR__ . '/includes/lang_ui.php'; ?>
</body>
</html>
