<?php
require_once __DIR__ . '/../config/config.php';

$postId = intval($_REQUEST['id'] ?? 0);
if (!$postId) {
    header('Location: /');
    exit();
}

$user = getCurrentUser();
if ($user) {
    $user = checkBanned($user);
}
$fs = getFS();
$post = $fs->findById('posts', $postId);
$userIsAdmin = $user && in_array($user['role'], ['admin', 'super_admin']);

if (!$post || ($post['status'] !== 'published' && !($user && $user['id'] == $post['user_id']) && !$userIsAdmin)) {
    header('Location: /');
    exit();
}

if (!$userIsAdmin) {
    $vis = $post['visibility'] ?? 'public';
    if ($vis === 'visible_to') {
        $allowedQQs = array_map('trim', explode(',', $post['visible_to'] ?? ''));
        if (!$user || !in_array($user['qq'], $allowedQQs)) {
            header('Location: /');
            exit();
        }
    } elseif ($vis === 'exclude_to') {
        $excludedQQs = array_map('trim', explode(',', $post['exclude_to'] ?? ''));
        if ($user && in_array($user['qq'], $excludedQQs)) {
            header('Location: /');
            exit();
        }
    }
}

$isAnonymous = !empty($post['is_anonymous']);
$postUser = $isAnonymous ? null : $fs->findById('users', $post['user_id']);

$isAuthor = $user && $user['id'] == $post['user_id'];

$views = intval($post['views'] ?? 0);

$comments = $fs->find('comments', ['post_id' => $postId]);
usort($comments, function($a, $b) {
    return strtotime($a['created_at']) - strtotime($b['created_at']);
});

$isLiked = false;
$isFavorited = false;
if ($user) {
    $likes = $fs->find('post_likes', ['user_id' => $user['id'], 'post_id' => $postId]);
    $isLiked = !empty($likes);
    $favs = $fs->find('post_favorites', ['user_id' => $user['id'], 'post_id' => $postId]);
    $isFavorited = !empty($favs);
}

$categories = [
    'announcement' => ['name' => '全站公告', 'icon' => 'announcement'],
    'lost_found' => ['name' => '寻物/失物招领', 'icon' => 'lost_found'],
    'study_help' => ['name' => '学习求助', 'icon' => 'study_help'],
    'social_chat' => ['name' => '交友闲聊', 'icon' => 'social_chat'],
    'confession' => ['name' => '表白', 'icon' => 'confession'],
    'school_info' => ['name' => '校园打听', 'icon' => 'school_info'],
    'other' => ['name' => '其他', 'icon' => 'other'],
];

$cat = $categories[$post['category']] ?? $categories['other'];

function renderUserTitleHTML($u) {
    if (empty($u['title_text'])) return '';
    $style = 'display:inline-block;padding:2px 10px;border-radius:5px;font-size:11px;font-weight:600;line-height:1.4;vertical-align:middle;margin-left:6px;white-space:nowrap;';
    if (!empty($u['title_rainbow'])) {
        $gs = $u['title_gradient_start'] ?: '#ff4757';
        $ge = $u['title_gradient_end'] ?: '#a55eea';
        $fg = $u['title_color'] ?: '#fff';
        $style .= 'background:linear-gradient(90deg,' . htmlspecialchars($gs) . ',' . htmlspecialchars($ge) . ',' . htmlspecialchars($gs) . ');background-size:200% 100%;animation:titleRainbow 2s linear infinite;color:' . htmlspecialchars($fg) . ';';
    } else {
        $bg = $u['title_bg_color'] ?: '#1B3A5C';
        $color = $u['title_color'] ?: '#fff';
        $style .= 'background:' . htmlspecialchars($bg) . ';color:' . htmlspecialchars($color) . ';';
    }
    return '<span class="user-title" style="' . $style . '">' . htmlspecialchars($u['title_text']) . '</span>';
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" href="/icon.ico" type="image/x-icon">
    <title><?= htmlspecialchars($post['title']) ?> - <?= htmlspecialchars(getSetting('site_name', '校园交流墙')) ?></title>
    <link rel="stylesheet" href="/assets/css/style.css?v=<?= asset_ver('/assets/css/style.css') ?>">
    <link rel="stylesheet" href="/assets/css/enhancements.css?v=<?= asset_ver('/assets/css/enhancements.css') ?>">
    <style>
        .post-follow-meta{display:inline-flex;align-items:center;gap:12px;margin-left:10px;vertical-align:middle;flex-wrap:wrap;}
        .follow-link{background:none;border:none;color:var(--text-secondary);font-size:0.8rem;cursor:pointer;padding:2px 4px;font-family:inherit;transition:color .2s;}
        .follow-link:hover{color:var(--primary);}
        .follow-link b{font-weight:700;color:var(--text);}
        .follow-link:hover b{color:var(--primary);}
        @media (max-width:480px){.post-follow-meta{width:100%;margin-left:0;margin-top:8px;}}
        .comment-floor{color:var(--text-tertiary);font-size:0.75rem;margin-right:6px;opacity:.8;font-family:monospace;}
        .comment-children{margin-left:38px;padding-left:12px;border-left:2px solid var(--border-color);}
        .comment-reply{margin-top:8px !important;}
        .comment-replyto{color:var(--text-tertiary);font-size:0.82em;margin-left:4px;}
        .comment-actions{display:flex;gap:12px;margin-top:6px;align-items:center;}
        .comment-reply-btn,.comment-delete-btn{background:none;border:none;color:var(--text-secondary);font-size:0.78rem;cursor:pointer;padding:0;font-family:inherit;display:inline-flex;align-items:center;gap:3px;transition:color .2s;}
        .comment-reply-btn:hover{color:var(--primary);}
        .comment-delete-btn:hover{color:#e74c3c;}
    </style>
    <script>
        const SITE_URL = '<?= SITE_URL ?>';
        const IS_LOGGED_IN = <?= $user ? 'true' : 'false' ?>;
        const USER_DATA = <?= $user ? json_encode(['id' => $user['id'], 'qq' => $user['qq'], 'nickname' => $user['nickname'], 'avatar' => $user['avatar'], 'role' => $user['role']], JSON_HEX_TAG | JSON_UNESCAPED_UNICODE) : 'null' ?>;
        const CSRF_TOKEN = '<?= generateCSRFToken() ?>';
        const POST_ID = <?= $postId ?>;
    </script>
    <script src="/assets/js/main.js?v=<?= asset_ver('/assets/js/main.js') ?>" defer></script>
    <script src="/assets/js/enhancements.js?v=<?= asset_ver('/assets/js/enhancements.js') ?>" defer></script>
</head>
<?php
$userTheme = $user ? ($user['theme'] ?? 'light') : 'light';
$themeClass = isset($_COOKIE['theme']) ? $_COOKIE['theme'] : $userTheme;
?>
<body class="<?= $themeClass ?>-theme">
    <header class="site-header">
        <div class="header-inner">
            <a href="/" class="site-logo">
                <svg class="logo-icon" width="28" height="28" viewBox="0 0 24 24" fill="none">
                    <defs>
                        <linearGradient id="logoGradPd" x1="0%" y1="0%" x2="100%" y2="100%">
                            <stop offset="0%" stop-color="#C9A96E"/>
                            <stop offset="50%" stop-color="#E8D5A3"/>
                            <stop offset="100%" stop-color="#B8943E"/>
                        </linearGradient>
                    </defs>
                    <path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z" fill="url(#logoGradPd)"/>
                    <polyline points="9 22 9 12 15 12 15 22" fill="none" stroke="#fff" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                </svg>
                <span class="logo-text"><?= htmlspecialchars(getSetting('site_name', '校园交流墙')) ?></span>
            </a>
            <div class="header-actions">
                <a href="/" class="btn btn-outline btn-sm">返回首页</a>
            </div>
        </div>
    </header>

    <main class="main-content">
        <article class="post-detail-card">
            <div class="post-detail-header">
                <span class="category-badge cat-<?= $post['category'] === 'announcement' ? 'announcement' : htmlspecialchars($cat['icon']) ?>"><?= htmlspecialchars($cat['name']) ?></span>
                <h1 class="post-detail-title"><?= htmlspecialchars($post['title']) ?></h1>
                <div class="post-detail-meta">
                    <?php if (!$isAnonymous && $postUser && !empty($postUser['avatar'])): ?>
                    <img src="<?= htmlspecialchars($postUser['avatar']) ?>" class="avatar-sm" alt="作者头像" onerror="this.src='/assets/images/default-avatar.svg'">
                    <?php else: ?>
                    <img src="/assets/images/default-avatar.svg" class="avatar-sm" alt="匿名头像">
                    <?php endif; ?>
                    <span class="author-name"><?= $isAnonymous ? '匿名用户' : htmlspecialchars($postUser['nickname'] ?? '匿名用户') ?></span>
                    <?php
                    if (!$isAnonymous && $postUser) {
                        echo renderUserTitleHTML($postUser);
                    }
                    ?>
                    <?php if ($isAuthor): ?>
                    <span class="badge badge-info">作者</span>
                    <?php endif; ?>
                    <span class="post-time"><?= htmlspecialchars(timeAgo($post['created_at'])) ?></span>
                    <?php
                    // 关注区：非匿名帖展示作者粉丝/关注数与关注按钮
                    if (!$isAnonymous && $postUser):
                        $fs = getFS();
                        $followerCount = count($fs->find('follows', ['target_id' => $postUser['id']]));
                        $followingCount = count($fs->find('follows', ['user_id' => $postUser['id']]));
                        $canFollow = $user && $user['id'] != $postUser['id'];
                        $isFollowing = $canFollow && (bool)$fs->findOne('follows', ['user_id' => $user['id'], 'target_id' => $postUser['id']]);
                    ?>
                    <div class="post-follow-meta">
                        <button type="button" class="follow-link" data-show-followers data-user-id="<?= (int)$postUser['id'] ?>" title="查看粉丝">粉丝 <b class="follow-count"><?= $followerCount ?></b></button>
                        <button type="button" class="follow-link" data-show-following data-user-id="<?= (int)$postUser['id'] ?>" title="查看关注">关注 <b class="follow-count"><?= $followingCount ?></b></button>
                        <?php if ($canFollow): ?>
                        <button type="button" class="follow-btn<?= $isFollowing ? ' following' : '' ?>" data-follow-user="<?= (int)$postUser['id'] ?>">
                            <?php if ($isFollowing): ?>
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg> 已关注
                            <?php else: ?>
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="M16 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="8.5" cy="7" r="4"/><line x1="20" y1="8" x2="20" y2="14"/><line x1="23" y1="11" x2="17" y2="11"/></svg> 关注
                            <?php endif; ?>
                            <span class="follow-count" style="display:none"></span>
                        </button>
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>
                </div>
            </div>

            <div class="post-detail-content">
                <?= nl2br(htmlspecialchars($post['content'])) ?>
                <?php
                $images = json_decode($post['images'] ?? '[]', true) ?: [];
                if (!empty($images)):
                ?>
                <div class="post-images grid-<?= min(count($images), 4) ?>">
                    <?php foreach ($images as $img): ?>
                    <img src="<?= htmlspecialchars($img) ?>" class="post-image" alt="帖子图片" loading="lazy" onclick="openLightbox(this.src)">
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>

            <div class="post-detail-actions">
                <?php if (($post['category'] ?? '') !== 'announcement'): ?>
                <button class="action-btn like-btn<?= $isLiked ? ' liked' : '' ?>" id="likeBtn" onclick="toggleLike(<?= $postId ?>)" aria-label="点赞">
                    <span class="action-icon" id="likeIcon"></span>
                    <span class="action-count" id="likeCount"><?= intval($post['likes'] ?? 0) ?></span>
                </button>
                <button class="action-btn favorite-btn<?= $isFavorited ? ' favorited' : '' ?>" id="favBtn" onclick="toggleFavorite(<?= $postId ?>)" aria-label="收藏">
                    <span class="action-icon" id="favIcon"></span>
                    <span class="action-count" id="favCount"><?= $isFavorited ? '已收藏' : '收藏' ?></span>
                </button>
                <?php endif; ?>
                <button class="action-btn" onclick="copyPostLink()" aria-label="复制链接">
                    <span class="action-icon" id="linkIcon"></span>
                    <span class="action-count">复制链接</span>
                </button>
                <button class="action-btn copy-content-btn" onclick="copyPost()" aria-label="复制内容">
                    <span class="action-icon" id="copyIcon"></span>
                    <span class="action-count">复制</span>
                </button>
                <span class="view-count" id="viewCount">
                    <span class="action-icon" id="viewIcon"></span>
                    <span><?= $views ?> 阅读</span>
                </span>
                <?php if ($isAuthor || $userIsAdmin): ?>
                <button class="action-btn edit-btn" onclick="editPost(<?= $postId ?>)" aria-label="编辑">
                    <span class="action-icon" id="editIcon"></span>
                    <span class="action-count">编辑</span>
                </button>
                <button class="action-btn danger delete-btn" onclick="deletePost(<?= $postId ?>)" aria-label="删除">
                    <span class="action-icon" id="deleteIcon"></span>
                    <span class="action-count">删除</span>
                </button>
                <?php endif; ?>
                <?php if ($user && !$isAuthor): ?>
                <button class="action-btn report-btn" onclick="reportPost(<?= $postId ?>)" aria-label="举报">
                    <span class="action-icon" id="reportIcon"></span>
                    <span class="action-count">举报</span>
                </button>
                <?php endif; ?>
            </div>

            <div class="comments-section">
                <h3 class="comments-title">
                    <span id="commentIcon"></span>
                    评论 <span class="comment-total">(<?= intval($post['comments'] ?? 0) ?>)</span>
                </h3>
                <div class="comments-list" id="commentsList">
                    <?php
                    // 懒加载：仅渲染顶层评论；各顶层评论的子回复数统计在此计入，
                    // 实际回复内容一律等用户点击「回复/展开」时再通过接口取数渲染。
                    $commentChildren = [];
                    foreach ($comments as $c) {
                        $pid = intval($c['parent_id'] ?? 0);
                        if ($pid > 0) {
                            $commentChildren[$pid][] = $c;
                        }
                    }
                    $floor = 1;
                    foreach ($comments as $c):
                        $pid = intval($c['parent_id'] ?? 0);
                        if ($pid > 0) continue; // 子回复不在此渲染
                        $cAnonymous = !empty($c['is_anonymous']);
                        $cUser = $cAnonymous ? null : $fs->findById('users', $c['user_id']);
                        $cNick = !$cAnonymous && $cUser ? ($cUser['nickname'] ?? '匿名用户') : '匿名用户';
                        $replyCount = count($commentChildren[$c['id']] ?? []);
                    ?>
                    <div class="comment-item" id="comment-<?= $c['id'] ?>">
                        <img src="<?= $cAnonymous || empty($cUser['avatar']) ? '/assets/images/default-avatar.svg' : htmlspecialchars($cUser['avatar']) ?>" class="comment-avatar avatar-sm" alt="评论头像" onerror="this.src='/assets/images/default-avatar.svg'">
                        <div class="comment-body">
                            <div class="comment-header">
                                <span class="comment-floor">#<?= $floor ?></span>
                                <span class="comment-author"><?= $cAnonymous ? '匿名用户' : htmlspecialchars($cUser['nickname'] ?? '匿名用户') ?></span>
                                <?php if (!$cAnonymous && $cUser) { echo renderUserTitleHTML($cUser); } ?>
                                <span class="comment-time"><?= htmlspecialchars(timeAgo($c['created_at'])) ?></span>
                            </div>
                            <p class="comment-text"><?= nl2br(htmlspecialchars($c['content'])) ?></p>
                            <div class="comment-actions">
                                <button class="comment-reply-btn" onclick="setReplyTarget(<?= $c['id'] ?>, '<?= htmlspecialchars($cNick, ENT_QUOTES) ?>')">回复</button>
                                <?php if ($replyCount > 0): ?>
                                <button class="comment-replies-toggle" id="repliesToggle<?= $c['id'] ?>" onclick="loadReplies(<?= $c['id'] ?>)">查看 <?= $replyCount ?> 条回复</button>
                                <?php endif; ?>
                                <?php if ($user && ($user['id'] == $c['user_id'] || $user['role'] === 'admin' || $user['role'] === 'super_admin')): ?>
                                <button class="comment-delete-btn" onclick="deleteComment(<?= $c['id'] ?>)" aria-label="删除评论">
                                    <span id="delCommentIcon<?= $c['id'] ?>"></span>
                                    <span>删除</span>
                                </button>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    <div class="comment-children" id="children-<?= $c['id'] ?>" data-loaded="0" style="display:none;"></div>
                    <?php $floor++; ?>
                    <?php endforeach; ?>
                    <?php if (empty($comments)): ?>
                    <div class="empty-state">
                        <div class="empty-icon" id="emptyCommentIcon"></div>
                        <p>还没有评论，快来抢沙发吧！</p>
                    </div>
                    <?php endif; ?>
                </div>

                <?php if (($post['category'] ?? '') === 'announcement'): ?>
                <p class="login-hint">公告为官方通知，仅展示，暂不支持评论。</p>
                <?php elseif ($user): ?>
                <div class="comment-input-area">
                    <img src="<?= htmlspecialchars($user['avatar'] ?? '/assets/images/default-avatar.svg') ?>" class="avatar-sm comment-input-avatar" alt="我的头像" onerror="this.src='/assets/images/default-avatar.svg'">
                    <div class="comment-input-wrap">
                        <textarea id="commentInput" placeholder="写下你的评论... (Ctrl+Enter 发送)" rows="2"></textarea>
                        <button class="btn btn-primary" id="submitCommentBtn" onclick="submitComment()">
                            <span id="sendIcon"></span>
                            发送
                        </button>
                    </div>
                </div>
                <?php else: ?>
                <p class="login-hint">请 <a href="/pages/login.php">登录</a> 后发表评论</p>
                <?php endif; ?>
            </div>
        </article>
    </main>

    <div class="toast-container" id="toastContainer"></div>

    <nav class="mobile-bottom-nav" id="mobileNav">
        <a href="/" class="mobile-nav-item">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg>
            <span>首页</span>
        </a>
        <a href="/pages/post.php" class="mobile-nav-item">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
            <span>发帖</span>
        </a>
        <a href="/pages/browser.php" class="mobile-nav-item">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="2" y1="12" x2="22" y2="12"/><path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"/></svg>
            <span>导航</span>
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
    </nav>

    <div class="modal-overlay" id="confirmModal" style="display:none">
        <div class="modal confirm-modal">
            <div class="modal-header">
                <h3 id="confirmTitle">确认操作</h3>
                <button class="modal-close" id="confirmClose" aria-label="关闭">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                </button>
            </div>
            <div class="modal-body">
                <p id="confirmMsg"></p>
            </div>
            <div class="modal-footer">
                <button class="btn btn-outline" id="confirmCancel">取消</button>
                <button class="btn btn-danger" id="confirmOk">确认</button>
            </div>
        </div>
    </div>

    <footer class="site-footer">
        <div class="container">
            <p>本平台为学生自发搭建交流平台，不属于淮南市北师大实验中学官方平台</p>
            <p>&copy; 2026 <?= htmlspecialchars(SITE_NAME) ?> · 蕭遞版权所有</p>
            <p>GitHub 开源地址（可点击跳转）：<a href="<?= htmlspecialchars(GITHUB_REPO_URL) ?>" target="_blank" rel="noopener noreferrer"><?= htmlspecialchars(GITHUB_REPO_NAME) ?></a></p>
        </div>
    </footer>

    <script>
        let likeState = <?= $isLiked ? 'true' : 'false' ?>;
        let favState = <?= $isFavorited ? 'true' : 'false' ?>;
        let _pollTimer = null;
        let _lastPollTime = new Date().toISOString();
        let _confirmCallback = null;
        // 楼中楼回复：replyTarget.id 为要回复到的顶层评论 id（0 表示正常评论）
        let replyTarget = { id: 0, name: '' };

        function setReplyTarget(parentId, name) {
            replyTarget = { id: parentId, name: name || '' };
            var ta = document.getElementById('commentInput');
            if (ta) {
                ta.placeholder = replyTarget.id ? ('回复 @' + replyTarget.name + '：…') : '写下你的评论... (Ctrl+Enter 发送)';
                ta.focus();
            }
        }

        // 懒加载：展开某顶层评论的回复时才取数渲染
        async function loadReplies(parentId) {
            var container = document.getElementById('children-' + parentId);
            var toggle = document.getElementById('repliesToggle' + parentId);
            if (!container) return;
            if (container.getAttribute('data-loaded') === '1') {
                container.style.display = container.style.display === 'none' ? 'block' : 'none';
                if (toggle) toggle.textContent = (container.style.display === 'none') ? ((toggle.getAttribute('data-count') || '1 条回复')) : '收起回复';
                return;
            }
            try {
                var res = await App.fetchAPI('/api/posts/comment_replies.php?post_id=<?= $postId ?>&parent_id=' + parentId);
                var replies = res.data.comments || [];
                container.innerHTML = '';
                replies.forEach(function(rc) { container.appendChild(buildReplyEl(parentId, rc)); });
                container.setAttribute('data-loaded', '1');
                container.style.display = 'block';
                if (toggle) {
                    toggle.textContent = '收起回复';
                    toggle.setAttribute('data-count', replies.length + ' 条回复');
                }
            } catch(e) { App.showToast(e.message || '加载回复失败', 'error'); }
        }

        function buildReplyEl(parentId, rc) {
            var el = document.createElement('div');
            el.className = 'comment-item comment-reply';
            el.id = 'comment-' + rc.id;
            var avatarSrc = rc.is_anonymous ? '/assets/images/default-avatar.svg' : (rc.author_avatar || '/assets/images/default-avatar.svg');
            var authorName = rc.is_anonymous ? '匿名用户' : App.escapeHtml(rc.author_nickname || '匿名用户');
            var replyName = rc.reply_to_name || '';
            var delBtn = rc.is_author ? '<button class="comment-delete-btn" onclick="deleteComment(' + rc.id + ')"><span id="delCommentIcon' + rc.id + '"></span><span>删除</span></button>' : '';
            var titleHtml = '';
            if (!rc.is_anonymous && rc.author_title_text) {
                var tStyle = 'display:inline-block;padding:2px 10px;border-radius:5px;font-size:11px;font-weight:600;line-height:1.4;vertical-align:middle;margin-left:6px;white-space:nowrap;background:' + (rc.author_title_bg_color || '#4A90D9') + ';color:' + (rc.author_title_color || '#ffffff') + ';';
                titleHtml = '<span class="user-title" style="' + tStyle + '">' + App.escapeHtml(rc.author_title_text) + '</span>';
            }
            el.innerHTML = '<img src="' + App.escapeHtml(avatarSrc) + '" class="comment-avatar avatar-sm" alt="头像" onerror="this.src=\'/assets/images/default-avatar.svg\'">' +
                '<div class="comment-body"><div class="comment-header">' +
                '<span class="comment-author">' + authorName + '</span>' + titleHtml +
                '<span class="comment-time">' + (rc.time_ago || '') + '</span></div>' +
                '<p class="comment-text">' + App.escapeHtml(rc.content).replace(/\n/g, '<br>') + (replyName ? '<span class="comment-replyto"> @' + App.escapeHtml(replyName) + '</span>' : '') + '</p>' +
                '<div class="comment-actions">' +
                '<button class="comment-reply-btn" onclick="setReplyTarget(' + parentId + ', \'' + authorName.replace(/'/g, "\\'") + '\')">回复</button>' + delBtn +
                '</div></div>';
            setTimeout(function() {
                var ic = document.getElementById('delCommentIcon' + rc.id);
                if (ic && typeof App !== 'undefined' && typeof App.svgIcon === 'function') ic.innerHTML = App.svgIcon('trash', 14);
            }, 100);
            return el;
        }

        function refreshReplyToggle(parentId) {
            var container = document.getElementById('children-' + parentId);
            var toggle = document.getElementById('repliesToggle' + parentId);
            if (!container || !toggle) return;
            if (container.getAttribute('data-loaded') !== '1') return;
            var n = container.children.length;
            toggle.textContent = n > 0 ? (n + ' 条回复') : '收起回复';
        }

        function showConfirm(title, msg, callback) {
            document.getElementById('confirmTitle').textContent = title;
            document.getElementById('confirmMsg').textContent = msg;
            document.getElementById('confirmModal').style.display = 'flex';
            _confirmCallback = callback;
        }
        function hideConfirm() {
            document.getElementById('confirmModal').style.display = 'none';
            _confirmCallback = null;
        }
        document.getElementById('confirmClose').addEventListener('click', hideConfirm);
        document.getElementById('confirmCancel').addEventListener('click', hideConfirm);
        document.getElementById('confirmOk').addEventListener('click', function() {
            hideConfirm();
            if (_confirmCallback) _confirmCallback();
        });
        document.getElementById('confirmModal').addEventListener('click', function(e) {
            if (e.target === this) hideConfirm();
        });

        function renderDetailIcons() {
            var likeIcon = document.getElementById('likeIcon');
            var favIcon = document.getElementById('favIcon');
            var copyIcon = document.getElementById('copyIcon');
            var deleteIcon = document.getElementById('deleteIcon');
            var editIcon = document.getElementById('editIcon');
            var reportIcon = document.getElementById('reportIcon');
            var linkIcon = document.getElementById('linkIcon');
            var viewIcon = document.getElementById('viewIcon');
            var commentIcon = document.getElementById('commentIcon');
            var sendIcon = document.getElementById('sendIcon');
            var emptyCommentIcon = document.getElementById('emptyCommentIcon');

            if (typeof App === 'undefined' || typeof App.svgIcon !== 'function') { setTimeout(renderDetailIcons, 100); return; }

            if (likeIcon) likeIcon.innerHTML = App.svgIcon(likeState ? 'heart' : 'heartOutline', 18);
            if (favIcon) favIcon.innerHTML = App.svgIcon(favState ? 'star' : 'starOutline', 18);
            if (copyIcon) copyIcon.innerHTML = App.svgIcon('copy', 18);
            if (deleteIcon) deleteIcon.innerHTML = App.svgIcon('trash', 18);
            if (editIcon) editIcon.innerHTML = App.svgIcon('edit', 18);
            if (reportIcon) reportIcon.innerHTML = App.svgIcon('alertCircle', 18);
            if (linkIcon) linkIcon.innerHTML = App.svgIcon('copy', 18);
            if (viewIcon) viewIcon.innerHTML = App.svgIcon('eye', 18);
            if (commentIcon) commentIcon.innerHTML = App.svgIcon('message', 18);
            if (sendIcon) sendIcon.innerHTML = App.svgIcon('send', 16);
            if (emptyCommentIcon) emptyCommentIcon.innerHTML = App.svgIcon('message', 48);

            <?php foreach ($comments as $c): ?>
            var delCIcon = document.getElementById('delCommentIcon<?= $c['id'] ?>');
            if (delCIcon) delCIcon.innerHTML = App.svgIcon('trash', 14);
            <?php endforeach; ?>
        }

        async function toggleLike(postId) {
            if (!IS_LOGGED_IN) {
                App.showToast('请先登录', 'warning');
                setTimeout(function(){ window.location.href='/pages/login.php'; }, 1000);
                return;
            }
            try {
                const res = await App.fetchAPI('/api/posts/like.php', {
                    method: 'POST',
                    body: 'post_id=' + postId
                });
                likeState = res.data.is_liked;
                document.getElementById('likeCount').textContent = res.data.like_count;
                var btn = document.getElementById('likeBtn');
                if (likeState) btn.classList.add('liked'); else btn.classList.remove('liked');
                document.getElementById('likeIcon').innerHTML = App.svgIcon(likeState ? 'heart' : 'heartOutline', 18);
            } catch(e) {
                App.showToast(e.message || '操作失败', 'error');
            }
        }

        async function toggleFavorite(postId) {
            if (!IS_LOGGED_IN) {
                App.showToast('请先登录', 'warning');
                setTimeout(function(){ window.location.href='/pages/login.php'; }, 1000);
                return;
            }
            try {
                const res = await App.fetchAPI('/api/posts/favorite.php', {
                    method: 'POST',
                    body: 'post_id=' + postId
                });
                favState = res.data.is_favorited;
                document.getElementById('favCount').textContent = favState ? '已收藏' : '收藏';
                var btn = document.getElementById('favBtn');
                if (favState) btn.classList.add('favorited'); else btn.classList.remove('favorited');
                document.getElementById('favIcon').innerHTML = App.svgIcon(favState ? 'star' : 'starOutline', 18);
                App.showToast(favState ? '已收藏' : '已取消收藏', favState ? 'success' : 'info');
            } catch(e) {
                App.showToast(e.message || '操作失败', 'error');
            }
        }

        function copyPostLink() {
            var url = window.location.href;
            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(url).then(function() {
                    App.showToast('链接已复制到剪贴板', 'success');
                }).catch(function() {
                    fallbackCopy(url);
                });
            } else {
                fallbackCopy(url);
            }
        }

        function fallbackCopy(text) {
            var textarea = document.createElement('textarea');
            textarea.value = text;
            textarea.style.position = 'fixed';
            textarea.style.opacity = '0';
            document.body.appendChild(textarea);
            textarea.select();
            try { document.execCommand('copy'); App.showToast('链接已复制', 'success'); }
            catch(e) { App.showToast('复制失败', 'error'); }
            document.body.removeChild(textarea);
        }

        async function submitComment() {
            var content = document.getElementById('commentInput').value.trim();
            var btn = document.getElementById('submitCommentBtn');
            var textarea = document.getElementById('commentInput');
            if (!content) {
                App.showToast('评论内容不能为空', 'warning');
                return;
            }
            btn.disabled = true;
            btn.textContent = '发送中...';
            try {
                var res = await App.fetchAPI('/api/posts/comment.php', {
                    method: 'POST',
                    body: 'post_id=<?= $postId ?>&content=' + encodeURIComponent(content) + '&parent_id=' + (replyTarget.id || 0)
                });
                var comment = res.data.comment;
                var isReply = replyTarget.id > 0;
                var replyName = replyTarget.name || '';

                var commentsList = document.getElementById('commentsList');

                var emptyState = commentsList.querySelector('.empty-state');
                if (emptyState) emptyState.remove();
                var commentEl = document.createElement('div');
                commentEl.className = 'comment-item' + (isReply ? ' comment-reply' : '');
                commentEl.id = 'comment-' + comment.id;
                var avatarSrc = comment.is_anonymous ? '/assets/images/default-avatar.svg' : (comment.author_avatar || '/assets/images/default-avatar.svg');
                var authorName = comment.is_anonymous ? '匿名用户' : App.escapeHtml(comment.author_nickname || '匿名用户');
                var titleHtml = '';
                if (!comment.is_anonymous && comment.author_title_text) {
                    var titleStyle = 'display:inline-block;padding:2px 10px;border-radius:5px;font-size:11px;font-weight:600;line-height:1.4;vertical-align:middle;margin-left:6px;white-space:nowrap;';
                    if (comment.author_title_rainbow == 1) {
                        var gStart = comment.author_title_gradient_start || '#ff4757';
                        var gEnd = comment.author_title_gradient_end || '#a55eea';
                        var gColor = comment.author_title_color || '#fff';
                        titleStyle += 'background:linear-gradient(90deg,' + gStart + ',' + gEnd + ',' + gStart + ');background-size:200% 100%;animation:titleRainbow 2s linear infinite;color:' + gColor + ' !important;';
                    } else {
                        titleStyle += 'background:' + (comment.author_title_bg_color || '#4A90D9') + ';color:' + (comment.author_title_color || '#ffffff') + ';';
                    }
                    titleHtml = '<span class="user-title" style="' + titleStyle + '">' + App.escapeHtml(comment.author_title_text) + '</span>';
                }
                var replyPrefix = (isReply && replyName) ? '<span class="comment-replyto"> @' + App.escapeHtml(replyName) + '</span>' : '';
                var replyForBtn = isReply ? replyTarget.id : comment.id;
                commentEl.innerHTML = '<img src="' + App.escapeHtml(avatarSrc) + '" class="comment-avatar avatar-sm" alt="评论头像" onerror="this.src=\'/assets/images/default-avatar.svg\'">' +
                    '<div class="comment-body"><div class="comment-header">' +
                    '<span class="comment-author">' + authorName + '</span>' + titleHtml +
                    '<span class="comment-time">刚刚</span></div>' +
                    '<p class="comment-text">' + App.escapeHtml(comment.content).replace(/\n/g, '<br>') + replyPrefix + '</p>' +
                    '<div class="comment-actions">' +
                    '<button class="comment-reply-btn" onclick="setReplyTarget(' + replyForBtn + ', \'' + authorName.replace(/'/g, "\\'") + '\')">回复</button>' +
                    '<button class="comment-delete-btn" onclick="deleteComment(' + comment.id + ')" aria-label="删除评论">' +
                    '<span id="delCommentIcon' + comment.id + '"></span><span>删除</span></button>' +
                    '</div></div>';

                var targetContainer;
                if (isReply) {
                    targetContainer = document.getElementById('children-' + replyTarget.id);
                    if (!targetContainer) {
                        targetContainer = document.createElement('div');
                        targetContainer.className = 'comment-children';
                        targetContainer.id = 'children-' + replyTarget.id;
                        var parentEl = document.getElementById('comment-' + replyTarget.id);
                        if (parentEl && parentEl.parentNode) {
                            parentEl.insertAdjacentElement('afterend', targetContainer);
                        } else {
                            commentsList.appendChild(targetContainer);
                        }
                    }
                } else {
                    targetContainer = commentsList;
                }
                targetContainer.appendChild(commentEl);

                // 回复子区懒加载：回复后即时亮出对应父评论的子区与「查看回复」切换钮
                if (isReply) {
                    if (targetContainer.style) targetContainer.style.display = 'block';
                    var rToggle = document.getElementById('repliesToggle' + replyTarget.id);
                    if (!rToggle) {
                        rToggle = document.createElement('button');
                        rToggle.className = 'comment-replies-toggle';
                        rToggle.id = 'repliesToggle' + replyTarget.id;
                        rToggle.setAttribute('onclick', 'loadReplies(' + replyTarget.id + ')');
                        var parentEl = document.getElementById('comment-' + replyTarget.id);
                        var pActions = parentEl ? parentEl.querySelector('.comment-actions') : null;
                        if (pActions) pActions.appendChild(rToggle);
                    }
                    refreshReplyToggle(replyTarget.id);
                }

                // 回复发送后复位回复目标与 placeholder
                replyTarget = { id: 0, name: '' };
                if (textarea) {
                    textarea.placeholder = '写下你的评论... (Ctrl+Enter 发送)';
                }

                setTimeout(function() {
                    var delIcon = document.getElementById('delCommentIcon' + comment.id);
                    if (delIcon && typeof App !== 'undefined' && typeof App.svgIcon === 'function') {
                        delIcon.innerHTML = App.svgIcon('trash', 14);
                    }
                }, 100);

                var totalEl = document.querySelector('.comment-total');
                if (totalEl) {
                    var currentCount = parseInt((totalEl.textContent || '').replace(/[()]/g, '') || '0') + 1;
                    totalEl.textContent = '(' + currentCount + ')';
                }

                textarea.value = '';
                textarea.style.height = 'auto';

                btn.disabled = false;
                var icon = document.getElementById('sendIcon');
                btn.innerHTML = (icon ? icon.outerHTML : '') + '发送';
                App.showToast('评论成功', 'success');

                commentEl.scrollIntoView({ behavior: 'smooth', block: 'center' });
            } catch(e) {
                btn.disabled = false;
                var icon = document.getElementById('sendIcon');
                btn.innerHTML = (icon ? icon.outerHTML : '') + '发送';
                App.showToast(e.message || '评论失败', 'error');
            }
        }

        document.getElementById('commentInput').addEventListener('keydown', function(e) {
            if ((e.ctrlKey || e.metaKey) && e.key === 'Enter') {
                e.preventDefault();
                submitComment();
            }
        });

        async function deleteComment(id) {
            showConfirm('确认删除', '确定删除此评论？', async function() {
                try {
                    var res = await App.fetchAPI('/api/posts/delete_comment.php', {
                        method: 'POST',
                        body: 'comment_id=' + id
                    });
                    var el = document.getElementById('comment-' + id);
                    if (el) el.remove();
                    App.showToast('评论已删除', 'success');
                } catch(e) {
                    App.showToast(e.message || '删除失败', 'error');
                }
            });
        }

        async function deletePost(id) {
            showConfirm('确认删除', '确定删除此帖子及所有评论？此操作不可恢复。', async function() {
                try {
                    var res = await App.fetchAPI('/api/posts/delete.php', {
                        method: 'POST',
                        body: 'post_id=' + id
                    });
                    App.showToast('删除成功', 'success');
                    setTimeout(function(){ window.location.href = '/'; }, 600);
                } catch(e) {
                    App.showToast(e.message || '删除失败', 'error');
                }
            });
        }

        function copyPost() {
            var text = <?= json_encode('【' . $cat['name'] . '】' . $post['title'] . "\n\n" . $post['content'], JSON_HEX_TAG | JSON_UNESCAPED_UNICODE) ?>;
            if (typeof App !== 'undefined' && typeof App.copyToClipboard === 'function') {
                App.copyToClipboard(text);
            } else {
                fallbackCopy(text);
            }
        }

        function editPost(postId) {
            window.location.href = '/pages/post.php?edit=' + postId;
        }

        function reportPost(postId) {
            if (typeof App === 'undefined' || !App.Modal || !App.Modal.open) {
                if (typeof App !== 'undefined' && typeof App.showToast === 'function') {
                    App.showToast('举报功能加载中...', 'warning');
                }
                return;
            }
            var reasons = ['色情低俗', '辱骂攻击', '垃圾广告', '违规内容', '泄露隐私', '其他'];
            var content = '<div style="margin-bottom:16px;"><label style="display:block;font-weight:600;margin-bottom:8px;">举报原因</label><div style="display:flex;flex-wrap:wrap;gap:8px;">';
            reasons.forEach(function(r, i) {
                content += '<label style="display:flex;align-items:center;gap:4px;padding:6px 12px;border:1px solid var(--border);border-radius:20px;cursor:pointer;font-size:13px;transition:all .15s;"><input type="radio" name="reportReason" value="' + r + '" style="accent-color:var(--primary);">' + r + '</label>';
            });
            content += '</div></div>';
            content += '<div><label style="display:block;font-weight:600;margin-bottom:8px;">补充说明（选填）</label><textarea id="reportDesc" class="form-input" rows="2" placeholder="请描述具体问题..." style="width:100%;resize:vertical;"></textarea></div>';

            App.Modal.open({
                title: '举报帖子',
                content: content,
                confirmText: '提交举报',
                confirmClass: 'btn-danger',
                onConfirm: function() {
                    var reason = document.querySelector('input[name="reportReason"]:checked');
                    if (!reason) { App.showToast('请选择举报原因', 'warning'); return; }
                    var desc = document.getElementById('reportDesc') ? document.getElementById('reportDesc').value.trim() : '';
                    App.fetchAPI('/api/posts/report.php', {
                        method: 'POST',
                        body: 'post_id=' + postId + '&reason=' + encodeURIComponent(reason.value) + '&description=' + encodeURIComponent(desc)
                    }).then(function(res) {
                        App.showToast('举报已提交，感谢您的反馈', 'success');
                    }).catch(function() {});
                }
            });
        }

        function openLightbox(src) {
            var overlay = document.createElement('div');
            overlay.style.cssText = 'position:fixed;inset:0;z-index:500;background:rgba(0,0,0,0.92);display:flex;align-items:center;justify-content:center;cursor:pointer;';
            var img = document.createElement('img');
            img.src = src;
            img.style.cssText = 'max-width:90vw;max-height:90vh;object-fit:contain;border-radius:8px;';
            overlay.appendChild(img);
            document.body.appendChild(overlay);
            document.body.style.overflow = 'hidden';
            overlay.addEventListener('click', function(){
                document.body.removeChild(overlay);
                document.body.style.overflow = '';
            });
        }

        function startDetailPoll() {
            _pollTimer = setInterval(async function() {
                if (typeof App === 'undefined' || typeof App.fetchAPI !== 'function') return;
                try {
                    var res = await App.fetchAPI('/api/posts/poll.php?ids=<?= $postId ?>&since=' + encodeURIComponent(_lastPollTime));
                    _lastPollTime = new Date().toISOString();
                    if (res.data && res.data.posts && res.data.posts['<?= $postId ?>']) {
                        var stats = res.data.posts['<?= $postId ?>'];
                        if (stats.like_count !== undefined) {
                            document.getElementById('likeCount').textContent = stats.like_count;
                        }
                        if (stats.comment_count !== undefined) {
                            var totalEl = document.querySelector('.comment-total');
                            if (totalEl) totalEl.textContent = '(' + stats.comment_count + ')';
                        }
                    }
                } catch(e) {}
            }, 3000);
        }

        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', function() {
                renderDetailIcons();
                startDetailPoll();
            });
        } else {

            renderDetailIcons();
            startDetailPoll();
        }

        window.addEventListener('beforeunload', function() {
            if (_pollTimer) clearInterval(_pollTimer);
        });
    </script>

    <script>
    (function(){
      var btt = document.getElementById('backToTop');
      if (btt) {
        btt.onclick = function(){ window.scrollTo({top:0,behavior:'smooth'}); };
        window.addEventListener('scroll', function(){
          btt.classList.toggle('show', window.scrollY > 300);
        }, {passive:true});
      }
    })();
    </script>
</body>
</html>
