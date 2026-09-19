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

// 必须登录后才能查看动态，未登录访问首页跳转到登录页
$user = requireLogin();
$user = checkBanned($user);
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
    ['key' => 'lost_found', 'name' => '寻物/失物招领'],
    ['key' => 'study_help', 'name' => '学习求助'],
    ['key' => 'social_chat', 'name' => '交友闲聊'],
    ['key' => 'confession', 'name' => '表白'],
    ['key' => 'school_info', 'name' => '校园打听'],
    ['key' => 'other', 'name' => '其他'],
];
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <script src="/assets/js/anti_hijack.js"></script>
    <link rel="icon" href="/icon.ico" type="image/x-icon">
    <title><?= htmlspecialchars($siteName) ?></title>
    <meta name="description" content="淮南市北师大实验中学高中部校园综合信息交流平台">
    <link rel="stylesheet" href="/assets/css/style.css?v=<?= asset_ver('/assets/css/style.css') ?>">
    <link rel="stylesheet" href="/assets/css/enhancements.css?v=<?= asset_ver('/assets/css/enhancements.css') ?>">
    <script>
        const SITE_URL = '<?= SITE_URL ?>';
        const IS_LOGGED_IN = <?= $user ? 'true' : 'false' ?>;
        const USER_DATA = <?= $user ? json_encode(['id' => $user['id'], 'qq' => $user['qq'], 'nickname' => $user['nickname'], 'avatar' => $user['avatar'], 'role' => $user['role']], JSON_HEX_TAG | JSON_UNESCAPED_UNICODE) : 'null' ?>;
        const CSRF_TOKEN = '<?= generateCSRFToken() ?>';
        const USER_THEME = '<?= $_COOKIE['theme'] ?? '' ?>';
        const IS_SPONSOR = <?= $isSponsor ? 'true' : 'false' ?>;
    </script>
</head>
<body class="<?= ($user ? ($user['theme'] ?? 'light') : (isset($_COOKIE['theme']) ? $_COOKIE['theme'] : 'light')) ?>-theme">
    <?php if ($bannedMsg): ?>
    <div class="ban-banner">
        <div class="container">
            <span class="ban-text"><?= htmlspecialchars($bannedMsg) ?></span>
        </div>
    </div>
    <?php endif; ?>

    <header class="site-header">
        <div class="header-inner">
            <a href="/" class="site-logo">
                <svg class="logo-icon" width="32" height="32" viewBox="0 0 24 24" fill="none">
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
                <span class="logo-text"><?= htmlspecialchars($siteName) ?></span>
            </a>
            <div class="header-actions">
                <?php if ($user): ?>
                <div class="user-menu">
                    <button class="user-menu-btn" id="userMenuBtn">
                        <img src="<?= htmlspecialchars($user['avatar']) ?>" alt="头像" class="user-avatar" onerror="this.style.display='none'">
                        <span class="user-name"><?= htmlspecialchars($user['nickname']) ?></span>
                    </button>
                    <div class="user-dropdown" id="userDropdown">
                        <a href="/pages/user_center.php" class="dropdown-item">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                            个人中心
                        </a>
                        <a href="/pages/user_center.php#my-posts" class="dropdown-item">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
                            我的帖子
                        </a>
                        <a href="/pages/user_center.php#my-favorites" class="dropdown-item">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg>
                            我的收藏
                        </a>
                        <button type="button" class="dropdown-item" id="titleRequestBtn">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 2l2.9 6.26L21 9.27l-4.5 4.38.94 6.35L12 17.42l-5.44 2.58.94-6.35L3 9.27l6.1-1.01z"/></svg>
                            申请头衔
                        </button>
                        <a href="/pages/browser.php" class="dropdown-item">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="2" y1="12" x2="22" y2="12"/><path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"/></svg>
                            网页导航
                        </a>
                        <a href="/pages/tools.php" class="dropdown-item">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="3"/><path d="M12 2v4m0 12v4M2 12h4m12 0h4" stroke-width="1.5" stroke-linecap="round"/><circle cx="12" cy="12" r="9" opacity="0.4"/></svg>
                            实用工具
                        </a>
                        <?php if (in_array($user['role'], ['admin', 'super_admin'])): ?>
                        <a href="/admin/" class="dropdown-item">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
                            管理后台
                        </a>
                        <?php endif; ?>
                        <button class="dropdown-item" id="themeToggle">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="5"/><line x1="12" y1="1" x2="12" y2="3"/><line x1="12" y1="21" x2="12" y2="23"/><line x1="4.22" y1="4.22" x2="5.64" y2="5.64"/><line x1="18.36" y1="18.36" x2="19.78" y2="19.78"/><line x1="1" y1="12" x2="3" y2="12"/><line x1="21" y1="12" x2="23" y2="12"/><line x1="4.22" y1="19.78" x2="5.64" y2="18.36"/><line x1="18.36" y1="5.64" x2="19.78" y2="4.22"/></svg>
                            切换主题
                        </button>
                        <button class="dropdown-item" id="logoutBtn">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>
                            退出登录
                        </button>
                    </div>
                </div>
                <?php else: ?>
                <a href="/pages/login.php" class="btn btn-primary">登录 / 注册</a>
                <?php endif; ?>
            </div>
        </div>
    </header>

    <?php if ($announcement): ?>
    <div class="announcement-bar">
        <div class="container">
            <div class="announcement-scroll">
                <span><?= htmlspecialchars($announcement) ?></span>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <main class="site-main">
        <div class="container">
            <div class="content-wrapper">
                <div class="main-content">
                    <div class="feed-toolbar">
                        <div class="search-box">
                            <input type="text" id="searchInput" placeholder="搜索帖子关键词..." class="search-input">
                            <button class="btn-icon" id="searchBtn" aria-label="搜索">
                                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="11" cy="11" r="7"/><line x1="16.5" y1="16.5" x2="21" y2="21"/></svg>
                            </button>
                        </div>
                        <div class="sort-tabs">
                            <button class="sort-tab active" data-sort="latest">最新</button>
                            <button class="sort-tab" data-sort="hot">热门</button>
                        </div>
                    </div>

                    <div class="category-filters">
                        <button class="cat-filter active" data-cat="all">全部</button>
                        <?php foreach ($categories as $cat): ?>
                        <button class="cat-filter" data-cat="<?= $cat['key'] ?>"><?= htmlspecialchars($cat['name']) ?></button>
                        <?php endforeach; ?>
                    </div>

                    <div class="posts-container" id="postsContainer">
                        <div class="loading-skeleton">
                            <div class="skeleton-item"></div>
                            <div class="skeleton-item"></div>
                            <div class="skeleton-item"></div>
                        </div>
                    </div>

                    <div class="load-more" id="loadMore" style="display:none">
                        <button class="btn btn-outline" id="loadMoreBtn">加载更多</button>
                    </div>
                    <div class="no-more" id="noMore" style="display:none">
                        <span>没有更多帖子了</span>
                    </div>
                </div>

                <aside class="sidebar">
                    <div class="widget widget-rules">
                        <h3>社区规范</h3>
                        <div class="widget-content rules-preview">
                            <ul>
                                <li>禁止发布辱骂、引战内容</li>
                                <li>禁止泄露他人隐私</li>
                                <li>禁止发布违规广告</li>
                                <li>文明发言，尊重他人</li>
                            </ul>
                        </div>
                    </div>
                    <div class="widget">
                        <h3>快速链接</h3>
                        <div class="widget-content">
                            <a href="/pages/post.php"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" style="vertical-align:middle;margin-right:4px"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z" fill="none" stroke="currentColor" stroke-width="2"/><polyline points="14 2 14 8 20 8" fill="none" stroke="currentColor" stroke-width="2"/><path d="M12 18v-6m0 0-2 2m2-2 2 2" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></svg>发布帖子</a>
                            <a href="/pages/tools.php">
                                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" style="vertical-align:middle;margin-right:4px"><circle cx="12" cy="12" r="3" fill="currentColor"/><path d="M12 2v4m0 12v4M2 12h4m12 0h4" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/><circle cx="12" cy="12" r="9" fill="none" stroke="currentColor" stroke-width="1.5" opacity="0.5"/></svg>
                                实用工具
                            </a>
                            <a href="/pages/browser.php">
                                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" style="vertical-align:middle;margin-right:4px"><circle cx="12" cy="12" r="11" fill="none" stroke="currentColor" stroke-width="2"/><line x1="2" y1="12" x2="22" y2="12" stroke="currentColor" stroke-width="1.5"/><path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z" fill="none" stroke="currentColor" stroke-width="1.5"/></svg>
                                网页导航
                            </a>
                            <?php if (!$user): ?>
                            <a href="/pages/login.php">登录 / 注册</a>
                            <?php endif; ?>
                        </div>
                    </div>
                </aside>
            </div>
        </div>
    </main>

    <div class="modal-overlay" id="titleRequestModal" style="display:none;">
        <div class="modal" style="max-width:460px;">
            <div class="modal-header">
                <h3 class="modal-title">申请头衔</h3>
                <button type="button" class="modal-close" id="titleReqClose">&times;</button>
            </div>
            <div class="modal-body">
                <p style="font-size:0.85rem;color:var(--text-secondary);margin-bottom:12px;">填好想申请的头衔文字（可附一句说明），提交后由管理员审核，通过后将在你的动态旁展示。</p>
                <div class="form-group">
                    <label class="form-label" for="trTitle">想申请的头衔</label>
                    <input type="text" id="trTitle" class="form-input" maxlength="20" placeholder="例如：校园小百科">
                </div>
                <div class="form-group">
                    <label class="form-label" for="trReason">申请说明（选填）</label>
                    <textarea id="trReason" class="form-input" maxlength="200" rows="3" placeholder="简单说明为什么想用这个头衔"></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline" id="trCancel">取消</button>
                <button type="button" class="btn btn-primary" id="trSubmit">提交申请</button>
            </div>
        </div>
    </div>

    <div class="modal-overlay" id="guideModal" style="display:none;">
        <div class="modal" style="max-width:520px;">
            <div class="modal-header">
                <h3 class="modal-title">👋 欢迎来到交流墙</h3>
            </div>
            <div class="modal-body" style="font-size:0.9rem;color:var(--text);line-height:1.9;">
                <p style="margin-bottom:4px;">这里是同学们的交流小天地，第一次来先看看这些常用功能：</p>
                <ul style="padding-left:18px;margin:8px 0 4px;">
                    <li>📝 点右下角「发帖」发布你的动态，可实名或匿名</li>
                    <li>🎯 顶部「签到」坚持每日打卡，留下你的连续签到足迹</li>
                    <li>✉️ 顶部「私信」和同学一对一交流</li>
                    <li>⭐ 顶栏头像菜单里可「申请头衔」「收藏」你的帖子</li>
                </ul>
                <p style="font-size:0.82rem;color:var(--text-muted);">遇到问题可以随时通过「功能投票」或私信管理员反馈。</p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-primary" id="guideOk">开始使用</button>
            </div>
        </div>
    </div>

    <button class="back-to-top" id="backToTop" title="返回顶部" aria-label="返回顶部">
        <svg width="20" height="20" viewBox="0 0 24 24" fill="none">
            <defs>
                <linearGradient id="backGrad" x1="0%" y1="0%" x2="100%" y2="100%">
                    <stop offset="0%" stop-color="#8899AA"/>
                    <stop offset="100%" stop-color="#5A6B7A"/>
                </linearGradient>
            </defs>
            <circle cx="12" cy="12" r="11" fill="url(#backGrad)"/>
            <line x1="12" y1="16" x2="12" y2="8" stroke="#fff" stroke-width="2.4" stroke-linecap="round"/>
            <polyline points="8 12 12 8 16 12" fill="none" stroke="#fff" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"/>
        </svg>
    </button>

    <nav class="mobile-bottom-nav" id="mobileNav">
        <a href="/" class="mobile-nav-item active">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg>
            <span>首页</span>
        </a>
        <a href="/pages/post.php" class="mobile-nav-item">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
            <span>发帖</span>
        </a>
        <?php if ($user): ?>
        <a href="/pages/user_center.php" class="mobile-nav-item">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
            <span>我的</span>
        </a>
        <?php else: ?>
        <a href="/pages/login.php" class="mobile-nav-item">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M15 3h4a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-4"/><polyline points="10 17 15 12 10 7"/><line x1="15" y1="12" x2="3" y2="12"/></svg>
            <span>登录</span>
        </a>
        <?php endif; ?>
        <a href="/pages/user_center.php#my-favorites" class="mobile-nav-item">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg>
            <span>收藏</span>
        </a>
        <a href="/pages/browser.php" class="mobile-nav-item">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="2" y1="12" x2="22" y2="12"/><path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"/></svg>
            <span>导航</span>
        </a>
    </nav>

    <div class="toast-container" id="toastContainer"></div>

    <div class="modal-overlay" id="sponsorModal" style="display:none">
        <div class="modal sponsor-modal">
            <div class="modal-header">
                <h3>支持我们</h3>
                <button class="modal-close sponsor-close" aria-label="关闭">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <line x1="18" y1="6" x2="6" y2="18"/>
                        <line x1="6" y1="6" x2="18" y2="18"/>
                    </svg>
                </button>
            </div>
            <div class="modal-body sponsor-body">
                <p class="sponsor-desc">如果觉得校园交流墙对你有帮助，欢迎赞助支持服务器运营！</p>
                <div class="sponsor-qr-group">
                    <div class="sponsor-qr">
                        <img src="/zanzhu/zanzhu.png" alt="微信赞助码" class="sponsor-img" onerror="this.style.display='none';this.nextElementSibling.style.display='block'">
                        <p class="sponsor-qr-placeholder" style="display:none">赞助图片加载中...</p>
                        <p class="sponsor-hint">微信扫码</p>
                    </div>
                    <div class="sponsor-qr">
                        <img src="/zanzhu/zz.jpg" alt="支付宝赞助码" class="sponsor-img" onerror="this.style.display='none';this.nextElementSibling.style.display='block'">
                        <p class="sponsor-qr-placeholder" style="display:none">赞助图片加载中...</p>
                        <p class="sponsor-hint">支付宝扫码</p>
                    </div>
                </div>
                <div class="sponsor-info" id="sponsorInfo">
                    <div class="sponsor-amount">已收到赞助：<strong id="sponsorAmount">--</strong> 元</div>
                    <div class="sponsor-list" id="sponsorList"></div>
                </div>
            </div>
            <div class="modal-footer">
                <button class="btn btn-primary sponsor-close">关闭</button>
            </div>
        </div>
    </div>

    <footer class="site-footer">
        <div class="container">
            <p>本平台为学生自发搭建交流平台，不属于淮南市北师大实验中学官方平台</p>
            <p>&copy; 2026 <?= htmlspecialchars($siteName) ?> · 蕭遞版权所有</p>
            <p>GitHub 开源地址（可点击跳转）：<a href="<?= htmlspecialchars(GITHUB_REPO_URL) ?>" target="_blank" rel="noopener noreferrer"><?= htmlspecialchars(GITHUB_REPO_NAME) ?></a></p>
            <p style="opacity:.7;font-size:12px;">本站为开源项目，网页开发与 Bug 修复部分经由 AI 参与完成</p>
        </div>
    </footer>

    <script>
    (function(){
      var btt = document.getElementById('backToTop');
      if (btt) {
        btt.onclick = function(){ window.scrollTo({top:0,behavior:'smooth'}); };
        window.addEventListener('scroll', function(){
          btt.classList.toggle('show', window.scrollY > 300);
        }, {passive:true});
      }
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
      var openBtn = document.getElementById('titleRequestBtn');
      var modal = document.getElementById('titleRequestModal');
      if (!openBtn || !modal) return;
      var tTitle = document.getElementById('trTitle');
      var tReason = document.getElementById('trReason');
      function openModal() {
        var dd = document.getElementById('userDropdown');
        if (dd) dd.classList.remove('show');
        tTitle.value = '';
        tReason.value = '';
        modal.style.display = 'flex';
        setTimeout(function () { tTitle.focus(); }, 50);
      }
      function closeModal() {
        modal.style.display = 'none';
      }
      openBtn.addEventListener('click', openModal);
      document.getElementById('titleReqClose').addEventListener('click', closeModal);
      document.getElementById('trCancel').addEventListener('click', closeModal);
      modal.addEventListener('click', function (e) {
        if (e.target === modal) closeModal();
      });
      document.getElementById('trSubmit').addEventListener('click', function () {
        var text = tTitle.value.trim();
        if (!text) { alert('请输入想申请的头衔文字'); tTitle.focus(); return; }
        var fd = new FormData();
        fd.append('csrf_token', CSRF_TOKEN);
        fd.append('action', 'submit');
        fd.append('title_text', text);
        fd.append('reason', tReason.value.trim());
        this.disabled = true;
        fetch(SITE_URL + '/api/title_request.php', { method: 'POST', body: fd })
          .then(function (r) { return r.json(); })
          .then(function (res) {
            alert(res.message || (res.success ? '提交成功' : '提交失败'));
            if (res.success) closeModal();
          })
          .catch(function () { alert('网络错误，请重试'); })
          .finally(function () { document.getElementById('trSubmit').disabled = false; });
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
        modal.style.display = 'flex';
        okBtn.addEventListener('click', function () {
          localStorage.setItem(KEY, '1');
          modal.style.display = 'none';
        });
      } catch (e) {}
    })();
    </script>
    <script src="/assets/js/main.js?v=<?= asset_ver('/assets/js/main.js') ?>" defer></script>
    <script src="/assets/js/enhancements.js?v=<?= asset_ver('/assets/js/enhancements.js') ?>" defer></script>
</body>
</html>
