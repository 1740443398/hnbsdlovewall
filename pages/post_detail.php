<?php
require_once __DIR__ . '/../config/config.php';

$postId = intval($_REQUEST['id'] ?? 0);
if (!$postId) {
    header('Location: /');
    exit();
}

// 已登录用户正常访问；游客会被引导注册；未登录访客跳登录页
$user = requireLoginOrGuest();
if ($user) {
    $user = checkBanned($user);
}
// 游客只能浏览动态列表，帖子详情需注册后查看
if (!$user && isGuestMode()) {
    denyGuest('帖子详情需要注册账号后才能查看');
}
$fs = getFS();
$post = $fs->findById('posts', $postId);
$userIsAdmin = $user && in_array($user['role'], ['admin', 'super_admin']);
// 能删「别人的」评论 = 管理员且持有 delete_comments 权限。
// 内核 includes/actions/comment_delete.php 就是这么判的；前台按钮必须同一口径，
// 否则没有该权限的管理员会看到删除按钮、点确认后必然失败（表现为「删了没反应」）。
$canDeleteAnyComment = $userIsAdmin && checkPermission($user, 'delete_comments');

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

// 投票数据（与动态列表保持一致），供详情页内联渲染
$poll = is_array($post['poll'] ?? null) ? $post['poll'] : null;
$pollOptions = ($poll && !empty($poll['options']) && is_array($poll['options'])) ? array_values($poll['options']) : [];
$pollData = null;
if ($pollOptions) {
    $votes = isset($poll['votes']) && is_array($poll['votes']) ? $poll['votes'] : [];
    $pollCounts = array_fill(0, count($pollOptions), 0);
    $pollTotal = 0;
    foreach ($votes as $idx) {
        $idx = (int)$idx;
        if ($idx >= 0 && $idx < count($pollOptions)) { $pollCounts[$idx]++; $pollTotal++; }
    }
    $pollMyVote = -1;
    if ($user) { $pollMyVote = isset($votes[(string)$user['id']]) ? (int)$votes[(string)$user['id']] : -1; }
    $pollData = [
        'question' => $poll['question'] ?? '',
        'options' => $pollOptions,
        'counts' => $pollCounts,
        'total' => $pollTotal,
        'my_vote' => $pollMyVote,
        'has_voted' => $pollMyVote >= 0,
    ];
}

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
    'lost_found' => ['name' => t('cat.lost_found'), 'icon' => 'lost_found'],
    'study_help' => ['name' => t('cat.study_help'), 'icon' => 'study_help'],
    'social_chat' => ['name' => t('cat.social_chat'), 'icon' => 'social_chat'],
    'confession' => ['name' => t('cat.confession'), 'icon' => 'confession'],
    'school_info' => ['name' => t('cat.school_info'), 'icon' => 'school_info'],
    'other' => ['name' => t('cat.other'), 'icon' => 'other'],
];

$cat = $categories[$post['category']] ?? $categories['other'];

// 头衔徽章渲染（与用户主页 pages/u.php 共用同一份，见 includes/user_title.php）
require_once __DIR__ . '/../includes/user_title.php';
// 正文 #话题 / @提及 解析（服务端唯一来源，见 includes/text_linkify.php）
require_once __DIR__ . '/../includes/text_linkify.php';
?>
<!DOCTYPE html>
<html lang="<?= $LANG_CODE ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <?php require_once __DIR__ . '/../includes/pwa_head.php'; ?>
    <link rel="icon" href="/icon.ico" type="image/x-icon">
    <title><?= htmlspecialchars($post['title']) ?> - <?= htmlspecialchars(getSetting('site_name', '校园交流墙')) ?></title>
    <?php
        // 帖子详情页的分享卡片：带上真实标题与正文摘要，分享到群里能看出内容
        $seoType  = 'article';
        $seoTitle = (string)($post['title'] ?? '');
        $seoDescription = mb_substr(trim(preg_replace('/\s+/u', ' ', (string)($post['content'] ?? ''))), 0, 110);
        // 配图帖用首图当分享封面，纯文字帖走默认封面
        $seoImage = '';
        if (!empty($post['images'][0])) {
            $seoImage = (string)$post['images'][0];
        }
        require __DIR__ . '/../includes/seo_meta.php';
    ?>
    <link rel="stylesheet" href="<?= asset_url('/assets/css/style.css') ?>?v=<?= asset_ver('/assets/css/style.css') ?>">
    <link rel="stylesheet" href="<?= asset_url('/assets/css/enhancements.css') ?>?v=<?= asset_ver('/assets/css/enhancements.css') ?>">
    <style>
        .post-follow-meta{display:inline-flex;align-items:center;gap:0.75rem;margin-left:0.625rem;vertical-align:middle;flex-wrap:wrap;}
        .follow-link{background:none;border:none;color:var(--text-secondary);font-size:0.8rem;cursor:pointer;padding:2px 0.25rem;font-family:inherit;transition:color .2s;}
        .follow-link:hover{color:var(--primary);}
        .follow-link b{font-weight:700;color:var(--text);}
        .follow-link:hover b{color:var(--primary);}
        @media (max-width:480px){.post-follow-meta{width:100%;margin-left:0;margin-top:0.5rem;}}
        .comment-floor{color:var(--text-tertiary);font-size:0.75rem;margin-right:0.375rem;opacity:.8;font-family:monospace;}
        .comment-children{margin-left:2.75rem;margin-top:0.5rem;}
        .comment-children > .comment-item + .comment-item{margin-top:0.5rem;}
        .comment-reply{margin-top:0;padding:0.625rem 0.75rem;background:var(--bg-secondary,#EDF1F5);border-radius:0.625rem;border-bottom:none;gap:0.5625rem;}
        .comment-reply .comment-avatar{width:1.625rem;height:1.625rem;flex:0 0 26px;}
        .comment-reply .comment-header{gap:0.375rem;}
        .comment-reply .comment-text{font-size:0.9rem;margin-top:2px;}
        .comment-replyto{display:inline-flex;align-items:center;color:var(--text-secondary);font-size:0.76rem;margin-left:0.375rem;background:var(--surface,#fff);border-radius:9999px;padding:1px 0.5625rem;line-height:1.6;max-width:12em;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;vertical-align:middle;}
        .comment-replyto b{color:var(--primary);font-weight:600;}
        .comment-actions{display:flex;gap:0.75rem;margin-top:0.375rem;align-items:center;flex-wrap:wrap;}
        .comment-reply-btn,.comment-delete-btn{background:none;border:none;color:var(--text-secondary);font-size:0.78rem;cursor:pointer;padding:0;font-family:inherit;display:inline-flex;align-items:center;gap:0.1875rem;transition:color .2s;}
        .comment-reply-btn:hover{color:var(--primary);}
        .comment-delete-btn:hover{color:#e74c3c;}
        .comment-replies-toggle{background:var(--primary-light,#eef3fb);border:none;color:var(--primary);font-size:0.76rem;font-weight:600;cursor:pointer;padding:0.1875rem 0.6875rem;border-radius:9999px;font-family:inherit;transition:filter .2s;}
        .comment-replies-toggle:hover{filter:brightness(.96);}
        @media (max-width:480px){.comment-children{margin-left:1.125rem;}.comment-reply{padding:0.5625rem 0.625rem;}}
        .post-detail-poll{margin-top:1.125rem;padding:1rem;border:1px solid var(--border-color);border-radius:0.75rem;background:var(--surface,#fff);}
        .post-detail-poll .poll-question{font-size:0.98rem;font-weight:700;color:var(--text);margin-bottom:0.75rem;}
        .post-detail-poll-empty .empty-hint{color:var(--text-tertiary);font-weight:600;}
        .post-detail-poll-empty .empty-tip{font-size:0.8rem;color:var(--text-tertiary);margin-top:2px;}
    </style>
    <script>
        const SITE_URL = '<?= SITE_URL ?>';
        const IS_LOGGED_IN = <?= $user ? 'true' : 'false' ?>;
        const USER_DATA = <?= $user ? json_encode(['id' => $user['id'], 'qq' => $user['qq'], 'uuid' => $user['uuid'] ?? '', 'nickname' => $user['nickname'], 'avatar' => $user['avatar'], 'role' => $user['role']], JSON_HEX_TAG | JSON_UNESCAPED_UNICODE) : 'null' ?>;
        const CSRF_TOKEN = '<?= generateCSRFToken() ?>';
        const POST_ID = <?= $postId ?>;
    </script>
    <script src="<?= asset_url('/assets/js/main.js') ?>?v=<?= asset_ver('/assets/js/main.js') ?>" defer></script>
    <script src="<?= asset_url('/assets/js/enhancements.js') ?>?v=<?= asset_ver('/assets/js/enhancements.js') ?>" defer></script>
</head>
<body>
    <a class="skip-to-content" href="#main-content">跳到主内容</a>
    <?php
    $headerBackHref = '/';
    $headerBackText = t('pd.back_home');
    require __DIR__ . '/../includes/site_header.php';
    ?>

    <?php require __DIR__ . '/../includes/ai_widget.php'; ?>

    <main id="main-content" class="main-content">
        <article class="post-detail-card" data-author-id="<?= !$isAnonymous && $postUser ? (int)$postUser['id'] : 0 ?>" data-post-id="<?= $postId ?>">
            <div class="post-detail-header">
                <span class="category-badge cat-<?= $post['category'] === 'announcement' ? 'announcement' : htmlspecialchars($cat['icon']) ?>"><?= htmlspecialchars($cat['name']) ?></span>
                <h1 class="post-detail-title"><?= htmlspecialchars($post['title']) ?></h1>
                <div class="post-detail-meta">
                    <?php if (!$isAnonymous && $postUser && !empty($postUser['avatar'])): ?>
                    <img src="<?= htmlspecialchars($postUser['avatar']) ?>" class="avatar-sm" alt="<?= t('pd.author_avatar') ?>" loading="lazy" decoding="async" onerror="this.src='<?= asset_url('/assets/images/default-avatar.svg') ?>'">
                    <?php else: ?>
                    <img src="<?= asset_url('/assets/images/default-avatar.svg') ?>" class="avatar-sm" alt="<?= t('pd.anonymous_avatar') ?>" width="32" height="32" decoding="async">
                    <?php endif; ?>
                    <span class="author-name"><?= $isAnonymous ? t('pd.anonymous_user') : htmlspecialchars($postUser['nickname'] ?? t('pd.anonymous_user')) ?></span>
                    <?php
                    if (!$isAnonymous && $postUser) {
                        echo renderUserTitleHTML($postUser);
                    }
                    ?>
                    <?php if ($isAuthor): ?>
                    <span class="badge badge-info"><?= t('pd.author') ?></span>
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
                        <button type="button" class="follow-link" data-show-followers data-user-id="<?= (int)$postUser['id'] ?>" title="<?= t('pd.view_followers') ?>"><?= t('pd.followers') ?> <b class="follow-count"><?= $followerCount ?></b></button>
                        <button type="button" class="follow-link" data-show-following data-user-id="<?= (int)$postUser['id'] ?>" title="<?= t('pd.view_following') ?>"><?= t('pd.following') ?> <b class="follow-count"><?= $followingCount ?></b></button>
                        <?php if ($canFollow): ?>
                        <button type="button" class="follow-btn<?= $isFollowing ? ' following' : '' ?>" data-follow-user="<?= (int)$postUser['id'] ?>">
                            <?php if ($isFollowing): ?>
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg> <?= t('pd.followed') ?>
                            <?php else: ?>
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="M16 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="8.5" cy="7" r="4"/><line x1="20" y1="8" x2="20" y2="14"/><line x1="23" y1="11" x2="17" y2="11"/></svg> <?= t('pd.follow') ?>
                            <?php endif; ?>
                            <span class="follow-count" style="display:none"></span>
                        </button>
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>
                </div>
            </div>

            <div class="post-detail-content">
                <?= lwRichText($post['content']) ?>
                <?php
                $images = postImagesExisting($post);
                if (!empty($images)):
                ?>
                <div class="post-images grid-<?= min(count($images), 4) ?>">
                    <?php foreach ($images as $img): ?>
                    <img src="<?= htmlspecialchars($img) ?>" class="post-image" alt="<?= t('pd.post_image') ?>" loading="lazy" decoding="async" onclick="openLightbox(this.src)">
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>

            <?php if ($pollData): ?>
            <div class="post-detail-poll">
                <div class="poll-widget<?= $pollData['has_voted'] ? ' poll-voted' : '' ?>" id="detailPollWidget" data-post-id="<?= $postId ?>">
                    <?php if ($pollData['question']): ?>
                    <div class="poll-question"><?= htmlspecialchars($pollData['question']) ?></div>
                    <?php endif; ?>
                    <div class="poll-results">
                        <?php foreach ($pollData['options'] as $i => $opt):
                            $c = $pollData['counts'][$i] ?? 0;
                            $pct = $pollData['total'] > 0 ? round(($c / $pollData['total']) * 100) : 0;
                            $isMine = $pollData['has_voted'] && $pollData['my_vote'] === $i;
                            $showRes = $pollData['has_voted'];
                        ?>
                        <div class="poll-option<?= $isMine ? ' selected' : '' ?>" data-index="<?= $i ?>" onclick="detailSelectPoll(<?= $i ?>)">
                            <span class="poll-label"><?= htmlspecialchars($opt) ?></span>
                            <?php if ($showRes): ?>
                            <span class="poll-pct"><?= $pct ?>%</span>
                            <span class="poll-bar-wrap"><i class="poll-bar" style="width:<?= $pct ?>%"></i></span>
                            <?php else: ?>
                            <span class="poll-pct" style="display:none"></span>
                            <span class="poll-bar-wrap" style="display:none"><i class="poll-bar" style="width:0%"></i></span>
                            <?php endif; ?>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <div class="poll-vote-actions">
                        <?php if ($pollData['has_voted']): ?>
                        <span class="poll-voted-note">✔ <?= t('pd.poll_voted') ?></span>
                        <?php else: ?>
                        <button type="button" class="poll-vote-btn" onclick="detailVotePoll()"><?= t('pd.poll_vote') ?></button>
                        <?php endif; ?>
                        <span class="poll-total"><?= $pollData['total'] ?> <?= t('pd.poll_participants') ?></span>
                    </div>
                </div>
            </div>
            <?php else: ?>
            <div class="post-detail-poll post-detail-poll-empty">
                <div class="poll-widget poll-empty" id="detailPollWidget" data-post-id="<?= $postId ?>">
                    <div class="poll-question">
                        <span class="empty-hint"><?= t('pd.poll_empty') ?></span>
                        <?php if ($isAuthor || $userIsAdmin): ?>
                        <button type="button" class="poll-vote-btn" onclick="goEditPoll()" style="float:right;margin-top:-0.25rem;"><?= t('pd.poll_create') ?></button>
                        <?php endif; ?>
                    </div>
                    <div class="empty-tip"><?= t('pd.poll_empty_tip') ?></div>
                </div>
            </div>
            <?php endif; ?>

            <div class="post-detail-actions">
                <button class="action-btn like-btn<?= $isLiked ? ' liked' : '' ?>" id="likeBtn" onclick="toggleLike(<?= $postId ?>)" aria-label="<?= t('pd.like') ?>">
                    <span class="action-icon" id="likeIcon"></span>
                    <span class="action-count" id="likeCount"><?= intval($post['likes'] ?? 0) ?></span>
                </button>
                <button class="action-btn favorite-btn<?= $isFavorited ? ' favorited' : '' ?>" id="favBtn" onclick="toggleFavorite(<?= $postId ?>)" aria-label="<?= t('pd.favorite') ?>">
                    <span class="action-icon" id="favIcon"></span>
                    <span class="action-count" id="favCount"><?= $isFavorited ? t('pd.favorited') : t('pd.favorite') ?></span>
                </button>
                <button class="action-btn" onclick="copyPostLink()" aria-label="<?= t('pd.copy_link') ?>">
                    <span class="action-icon" id="linkIcon"></span>
                    <span class="action-count"><?= t('pd.copy_link') ?></span>
                </button>
                <button class="action-btn copy-content-btn" onclick="copyPost()" aria-label="<?= t('pd.copy_content') ?>">
                    <span class="action-icon" id="copyIcon"></span>
                    <span class="action-count"><?= t('pd.copy') ?></span>
                </button>
                <span class="view-count" id="viewCount">
                    <span class="action-icon" id="viewIcon"></span>
                    <span><?= $views ?> <?= t('pd.views') ?></span>
                </span>
                <?php if ($isAuthor || $userIsAdmin): ?>
                <button class="action-btn edit-btn" onclick="editPost(<?= $postId ?>)" aria-label="<?= t('pd.edit') ?>">
                    <span class="action-icon" id="editIcon"></span>
                    <span class="action-count"><?= t('pd.edit') ?></span>
                </button>
                <button class="action-btn danger delete-btn" onclick="deletePost(<?= $postId ?>)" aria-label="<?= t('pd.delete') ?>">
                    <span class="action-icon" id="deleteIcon"></span>
                    <span class="action-count"><?= t('pd.delete') ?></span>
                </button>
                <?php endif; ?>
                <?php if ($user && !$isAuthor): ?>
                <button class="action-btn report-btn" onclick="reportPost(<?= $postId ?>)" aria-label="<?= t('pd.report') ?>">
                    <span class="action-icon" id="reportIcon"></span>
                    <span class="action-count"><?= t('pd.report') ?></span>
                </button>
                <?php endif; ?>
            </div>

            <div class="comments-section">
                <h3 class="comments-title">
                    <span id="commentIcon"></span>
                    <?= t('pd.comment') ?> <span class="comment-total">(<?= intval($post['comments'] ?? 0) ?>)</span>
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
                        $cNick = !$cAnonymous && $cUser ? ($cUser['nickname'] ?? t('pd.anonymous_user')) : t('pd.anonymous_user');
                        $replyCount = count($commentChildren[$c['id']] ?? []);
                    ?>
                    <div class="comment-item" id="comment-<?= $c['id'] ?>" data-user-id="<?= (int)($c['user_id'] ?? 0) ?>" data-created-at="<?= htmlspecialchars((string)($c['created_at'] ?? '')) ?>" data-reply-count="<?= $replyCount ?>">
                        <img src="<?= $cAnonymous || empty($cUser['avatar']) ? asset_url('/assets/images/default-avatar.svg') : htmlspecialchars($cUser['avatar']) ?>" class="comment-avatar avatar-sm" alt="<?= t('pd.comment_avatar') ?>" loading="lazy" decoding="async" width="32" height="32" onerror="this.src='<?= asset_url('/assets/images/default-avatar.svg') ?>'">
                        <div class="comment-body">
                            <div class="comment-header">
                                <span class="comment-floor">#<?= $floor ?></span>
                                <span class="comment-author"><?= $cAnonymous ? t('pd.anonymous_user') : htmlspecialchars($cUser['nickname'] ?? t('pd.anonymous_user')) ?></span>
                                <?php if (!$cAnonymous && $cUser) { echo renderUserTitleHTML($cUser); } ?>
                                <span class="comment-time"><?= htmlspecialchars(timeAgo($c['created_at'])) ?></span>
                            </div>
                            <p class="comment-text"><?= lwRichText($c['content']) ?></p>
                            <div class="comment-actions">
                                <button class="comment-reply-btn" onclick="setReplyTarget(<?= $c['id'] ?>, '<?= htmlspecialchars($cNick, ENT_QUOTES) ?>')"><?= t('pd.reply') ?></button>
                                <?php if ($replyCount > 0): ?>
                                <button class="comment-replies-toggle" id="repliesToggle<?= $c['id'] ?>" onclick="loadReplies(<?= $c['id'] ?>)"><?= t('pd.view_replies') ?> <?= $replyCount ?> <?= t('pd.replies_unit') ?></button>
                                <?php endif; ?>
                                <?php if ($user && ($user['id'] == $c['user_id'] || $canDeleteAnyComment)): ?>
                                <button class="comment-delete-btn" onclick="deleteComment(<?= $c['id'] ?>)" aria-label="<?= t('pd.delete_comment') ?>">
                                    <span id="delCommentIcon<?= $c['id'] ?>"></span>
                                    <span><?= t('pd.delete') ?></span>
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
                        <p><?= t('pd.no_comments') ?></p>
                    </div>
                    <?php endif; ?>
                </div>

                <?php if ($user): ?>
                <div class="comment-input-area">
                    <img src="<?= htmlspecialchars($user['avatar'] ?? '/assets/images/default-avatar.svg') ?>" class="avatar-sm comment-input-avatar" alt="<?= t('pd.my_avatar') ?>" onerror="this.src='/assets/images/default-avatar.svg'">
                    <div class="comment-input-wrap">
                        <div class="comment-input-fields">
                            <textarea id="commentInput" placeholder="<?= t('pd.comment_placeholder') ?>" rows="2"></textarea>
                            <label class="comment-anon-toggle" for="commentAnonymous">
                                <input type="checkbox" id="commentAnonymous" name="is_anonymous" value="1">
                                <span class="comment-anon-label"><?= t('pd.anon_comment') ?></span>
                            </label>
                        </div>
                        <button class="btn btn-primary" id="submitCommentBtn" onclick="submitComment()">
                            <span id="sendIcon"></span>
                            <?= t('pd.send') ?>
                        </button>
                    </div>
                </div>
                <?php else: ?>
                <p class="login-hint"><?= t('pd.login_to_comment_pre') ?> <a href="/pages/login.php"><?= t('pd.login_to_comment_link') ?></a> <?= t('pd.login_to_comment_post') ?></p>
                <?php endif; ?>
            </div>
        </article>
    </main>

    <div class="toast-container" id="toastContainer"></div>

    <?php
    // 详情页不属于底部 5 个标签，因此不高亮任何一项
    $mobileNavActive = '';
    require __DIR__ . '/../includes/mobile_bottom_nav.php';
    ?>

    <div class="modal-overlay" id="confirmModal" style="display:none">
        <div class="modal confirm-modal">
            <div class="modal-header">
                <h3 id="confirmTitle"><?= t('pd.confirm_operation') ?></h3>
                <button class="modal-close" id="confirmClose" aria-label="<?= t('pd.close') ?>">
                    <?= lw_icon('close', 16, ['wrap' => false]) ?>
                </button>
            </div>
            <div class="modal-body">
                <p id="confirmMsg"></p>
            </div>
            <div class="modal-footer">
                <button class="btn btn-outline" id="confirmCancel"><?= t('pd.cancel') ?></button>
                <button class="btn btn-danger" id="confirmOk"><?= t('pd.confirm') ?></button>
            </div>
        </div>
    </div>

    <footer class="site-footer">
        <div class="container">
            <p><?= t('footer.disclaimer') ?></p>
            <p>&copy; 2026 <?= htmlspecialchars(SITE_NAME) ?> · <?= t('pd.copyright') ?></p>
            <p><?= t('footer.open_source') ?><a href="<?= htmlspecialchars(GITHUB_REPO_URL) ?>" target="_blank" rel="noopener noreferrer"><?= htmlspecialchars(GITHUB_REPO_NAME) ?></a></p>
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
                ta.placeholder = replyTarget.id ? (__t('pd.reply_to_pre') + replyTarget.name + __t('pd.reply_to_post')) : __t('pd.comment_placeholder');
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
                if (toggle) toggle.textContent = (container.style.display === 'none') ? ((toggle.getAttribute('data-count') || ('1 ' + __t('pd.replies_unit')))) : __t('pd.collapse_replies');
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
                    toggle.textContent = __t('pd.collapse_replies');
                    toggle.setAttribute('data-count', replies.length + ' ' + __t('pd.replies_unit'));
                }
            } catch(e) { App.showToast(e.message || __t('pd.load_replies_fail'), 'error'); }
        }

        // 头衔徽章统一渲染：obj 为扁平对象，prefix 为 '' 或 'author_'
        function titleBadgeHtml(obj, prefix) {
            prefix = prefix || '';
            var text = obj[prefix + 'title_text'];
            if (!text || obj.is_anonymous) return '';
            var cls = 'user-title', vars;
            if (obj[prefix + 'title_rainbow'] == 1) {
                cls += ' is-rainbow';
                vars = '--ut-gs:' + (obj[prefix + 'title_gradient_start'] || '#ff4757') +
                       ';--ut-ge:' + (obj[prefix + 'title_gradient_end'] || '#a55eea') +
                       ';--ut-fg:' + (obj[prefix + 'title_color'] || '#fff');
            } else {
                vars = '--ut-bg:' + (obj[prefix + 'title_bg_color'] || '#4A90D9') +
                       ';--ut-fg:' + (obj[prefix + 'title_color'] || '#fff');
            }
            return '<span class="' + cls + '" style="' + vars + '">' + App.escapeHtml(text) + '</span>';
        }

        function buildReplyEl(parentId, rc) {
            var el = document.createElement('div');
            el.className = 'comment-item comment-reply';
            el.id = 'comment-' + rc.id;
            el.dataset.userId = rc.user_id || 0;
            el.dataset.createdAt = rc.created_at || '';
            var avatarSrc = rc.is_anonymous ? '/assets/images/default-avatar.svg' : (rc.author_avatar || '/assets/images/default-avatar.svg');
            var authorName = rc.is_anonymous ? __t('pd.anonymous_user') : App.escapeHtml(rc.author_nickname || __t('pd.anonymous_user'));
            var replyName = rc.reply_to_name || '';
            var delBtn = rc.is_author ? '<button class="comment-delete-btn" onclick="deleteComment(' + rc.id + ')"><span id="delCommentIcon' + rc.id + '"></span><span>' + __t('pd.delete') + '</span></button>' : '';
            var replyTag = replyName ? '<span class="comment-replyto">' + __t('pd.reply_to_pre') + '<b>' + App.escapeHtml(replyName) + '</b></span>' : '';
            el.innerHTML = '<img src="' + App.escapeHtml(avatarSrc) + '" class="comment-avatar avatar-sm" alt="' + __t('pd.avatar') + '" onerror="this.src=\'/assets/images/default-avatar.svg\'">' +
                '<div class="comment-body"><div class="comment-header">' +
                '<span class="comment-author">' + authorName + '</span>' + titleBadgeHtml(rc, 'author_') +
                replyTag +
                '<span class="comment-time">' + (rc.time_ago || '') + '</span></div>' +
                '<p class="comment-text">' + App.escapeHtml(rc.content).replace(/\n/g, '<br>') + '</p>' +
                '<div class="comment-actions">' +
                '<button class="comment-reply-btn" onclick="setReplyTarget(' + parentId + ', \'' + authorName.replace(/'/g, "\\'") + '\')">' + __t('pd.reply') + '</button>' + delBtn +
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
            toggle.textContent = n > 0 ? (n + ' ' + __t('pd.replies_unit')) : __t('pd.collapse_replies');
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
            // 先取出回调再 hideConfirm()：hideConfirm 会把 _confirmCallback 置空，
            // 顺序反了的话所有走确认弹窗的操作（删评论 / 删帖 / 举报）都会「点了没反应」。
            var cb = _confirmCallback;
            hideConfirm();
            if (cb) cb();
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
            if (emptyCommentIcon) emptyCommentIcon.innerHTML = App.LWIllustration('comments');

            <?php foreach ($comments as $c): ?>
            var delCIcon = document.getElementById('delCommentIcon<?= $c['id'] ?>');
            if (delCIcon) delCIcon.innerHTML = App.svgIcon('trash', 14);
            <?php endforeach; ?>
        }

        async function toggleLike(postId) {
            if (!IS_LOGGED_IN) {
                App.showToast(__t('pd.login_required'), 'warning');
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
                App.showToast(e.message || __t('pd.action_failed'), 'error');
            }
        }

        async function toggleFavorite(postId) {
            if (!IS_LOGGED_IN) {
                App.showToast(__t('pd.login_required'), 'warning');
                setTimeout(function(){ window.location.href='/pages/login.php'; }, 1000);
                return;
            }
            try {
                const res = await App.fetchAPI('/api/posts/favorite.php', {
                    method: 'POST',
                    body: 'post_id=' + postId
                });
                favState = res.data.is_favorited;
                document.getElementById('favCount').textContent = favState ? __t('pd.favorited') : __t('pd.favorite');
                var btn = document.getElementById('favBtn');
                if (favState) btn.classList.add('favorited'); else btn.classList.remove('favorited');
                document.getElementById('favIcon').innerHTML = App.svgIcon(favState ? 'star' : 'starOutline', 18);
                App.showToast(favState ? __t('pd.favorited') : __t('pd.unfavorited'), favState ? 'success' : 'info');
            } catch(e) {
                App.showToast(e.message || __t('pd.action_failed'), 'error');
            }
        }

        function copyPostLink() {
            var url = window.location.href;
            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(url).then(function() {
                    App.showToast(__t('pd.link_copied_clipboard'), 'success');
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
            try { document.execCommand('copy'); App.showToast(__t('pd.link_copied'), 'success'); }
            catch(e) { App.showToast(__t('pd.copy_failed'), 'error'); }
            document.body.removeChild(textarea);
        }

        async function submitComment() {
            var content = document.getElementById('commentInput').value.trim();
            var btn = document.getElementById('submitCommentBtn');
            var textarea = document.getElementById('commentInput');
            if (!content) {
                App.showToast(__t('pd.comment_empty'), 'warning');
                return;
            }
            btn.disabled = true;
            btn.textContent = __t('pd.sending');
            try {
                var anonBox = document.getElementById('commentAnonymous');
                var res = await App.fetchAPI('/api/posts/comment.php', {
                    method: 'POST',
                    body: 'post_id=<?= $postId ?>&content=' + encodeURIComponent(content) + '&parent_id=' + (replyTarget.id || 0) +
                          '&is_anonymous=' + (anonBox && anonBox.checked ? '1' : '0')
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
                commentEl.dataset.userId = comment.user_id || 0;
                commentEl.dataset.createdAt = comment.created_at || '';
                var avatarSrc = comment.is_anonymous ? '/assets/images/default-avatar.svg' : (comment.author_avatar || '/assets/images/default-avatar.svg');
                var authorName = comment.is_anonymous ? __t('pd.anonymous_user') : App.escapeHtml(comment.author_nickname || __t('pd.anonymous_user'));
                var titleHtml = titleBadgeHtml(comment, 'author_');
                var replyTag = (isReply && replyName) ? '<span class="comment-replyto">' + __t('pd.reply_to_pre') + '<b>' + App.escapeHtml(replyName) + '</b></span>' : '';
                var replyForBtn = isReply ? replyTarget.id : comment.id;
                commentEl.innerHTML = '<img src="' + App.escapeHtml(avatarSrc) + '" class="comment-avatar avatar-sm" alt="' + __t('pd.comment_avatar') + '" onerror="this.src=\'/assets/images/default-avatar.svg\'">' +
                    '<div class="comment-body"><div class="comment-header">' +
                    '<span class="comment-author">' + authorName + '</span>' + titleHtml + replyTag +
                    '<span class="comment-time">' + __t('pd.just_now') + '</span></div>' +
                    '<p class="comment-text">' + App.escapeHtml(comment.content).replace(/\n/g, '<br>') + '</p>' +
                    '<div class="comment-actions">' +
                    '<button class="comment-reply-btn" onclick="setReplyTarget(' + replyForBtn + ', \'' + authorName.replace(/'/g, "\\'") + '\')">' + __t('pd.reply') + '</button>' +
                    '<button class="comment-delete-btn" onclick="deleteComment(' + comment.id + ')" aria-label="' + __t('pd.delete_comment') + '">' +
                    '<span id="delCommentIcon' + comment.id + '"></span><span>' + __t('pd.delete') + '</span></button>' +
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
                    textarea.placeholder = __t('pd.comment_placeholder');
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
                // 匿名开关**故意不重置**：若提交后自动取消勾选，用户会以为下一条仍是匿名，
                // 实际却带着真名发出去 —— 这类「静默实名」比多勾一次危险得多。

                btn.disabled = false;
                var icon = document.getElementById('sendIcon');
                btn.innerHTML = (icon ? icon.outerHTML : '') + __t('pd.send');
                App.showToast(__t('pd.comment_success'), 'success');

                commentEl.scrollIntoView({ behavior: 'smooth', block: 'center' });
            } catch(e) {
                btn.disabled = false;
                var icon = document.getElementById('sendIcon');
                btn.innerHTML = (icon ? icon.outerHTML : '') + __t('pd.send');
                App.showToast(e.message || __t('pd.comment_failed'), 'error');
            }
        }

        document.getElementById('commentInput').addEventListener('keydown', function(e) {
            if ((e.ctrlKey || e.metaKey) && e.key === 'Enter') {
                e.preventDefault();
                submitComment();
            }
        });

        async function deleteComment(id) {
            showConfirm(__t('pd.confirm_delete'), __t('pd.confirm_delete_comment'), async function() {
                try {
                    var res = await App.fetchAPI('/api/posts/delete_comment.php', {
                        method: 'POST',
                        body: 'comment_id=' + id
                    });
                    var el = document.getElementById('comment-' + id);
                    if (el) el.remove();
                    App.showToast(__t('pd.comment_deleted'), 'success');
                } catch(e) {
                    App.showToast(e.message || __t('pd.delete_failed'), 'error');
                }
            });
        }

        async function deletePost(id) {
            showConfirm(__t('pd.confirm_delete'), __t('pd.confirm_delete_post'), async function() {
                try {
                    var res = await App.fetchAPI('/api/posts/delete.php', {
                        method: 'POST',
                        body: 'post_id=' + id
                    });
                    App.showToast(__t('pd.delete_success'), 'success');
                    setTimeout(function(){ window.location.href = '/'; }, 600);
                } catch(e) {
                    App.showToast(e.message || __t('pd.delete_failed'), 'error');
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
                    App.showToast(__t('pd.report_loading'), 'warning');
                }
                return;
            }
            var reasons = [__t('pd.reason_porn'), __t('pd.reason_abuse'), __t('pd.reason_ad'), __t('pd.reason_violation'), __t('pd.reason_privacy'), __t('pd.reason_other')];
            var content = '<div style="margin-bottom:1rem;"><label style="display:block;font-weight:600;margin-bottom:0.5rem;">' + __t('pd.report_reason') + '</label><div style="display:flex;flex-wrap:wrap;gap:0.5rem;">';
            reasons.forEach(function(r, i) {
                content += '<label style="display:flex;align-items:center;gap:0.25rem;padding:0.375rem 0.75rem;border:1px solid var(--border);border-radius:1.25rem;cursor:pointer;font-size:0.8125rem;transition:all .15s;"><input type="radio" name="reportReason" value="' + r + '" style="accent-color:var(--primary);">' + r + '</label>';
            });
            content += '</div></div>';
            content += '<div><label style="display:block;font-weight:600;margin-bottom:0.5rem;">' + __t('pd.report_desc_label') + '</label><textarea id="reportDesc" class="form-input" rows="2" placeholder="' + __t('pd.report_desc_placeholder') + '" style="width:100%;resize:vertical;"></textarea></div>';

            App.Modal.open({
                title: __t('pd.report_post'),
                content: content,
                confirmText: __t('pd.report_submit'),
                confirmClass: 'btn-danger',
                onConfirm: function() {
                    var reason = document.querySelector('input[name="reportReason"]:checked');
                    if (!reason) { App.showToast(__t('pd.report_reason_required'), 'warning'); return; }
                    var desc = document.getElementById('reportDesc') ? document.getElementById('reportDesc').value.trim() : '';
                    App.fetchAPI('/api/posts/report.php', {
                        method: 'POST',
                        body: 'post_id=' + postId + '&reason=' + encodeURIComponent(reason.value) + '&description=' + encodeURIComponent(desc)
                    }).then(function(res) {
                        App.showToast(__t('pd.report_success'), 'success');
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

        // ===== 详情页内联投票 =====
        let _selPoll = -1;
        function detailSelectPoll(i) {
            var w = document.getElementById('detailPollWidget');
            if (!w || w.classList.contains('poll-voted')) return;
            var els = w.querySelectorAll('.poll-option');
            _selPoll = i;
            for (var k = 0; k < els.length; k++) els[k].classList.remove('selected');
            if (els[i]) els[i].classList.add('selected');
        }
        async function detailVotePoll() {
            if (!IS_LOGGED_IN) {
                App.showToast(__t('pd.login_required_vote'), 'warning');
                setTimeout(function(){ window.location.href='/pages/login.php'; }, 1000);
                return;
            }
            if (_selPoll < 0) { App.showToast(__t('pd.poll_select_option'), 'warning'); return; }
            var btn = document.querySelector('#detailPollWidget .poll-vote-btn');
            if (btn) { btn.disabled = true; btn.textContent = __t('pd.submitting'); }
            try {
                var res = await App.fetchAPI('/api/posts/vote.php', {
                    method: 'POST',
                    body: 'post_id=<?= $postId ?>&option=' + _selPoll
                });
                if (!res.success) throw new Error(res.message || __t('pd.poll_vote_failed'));
                var results = res.data || [];
                var total = results.reduce(function(s, r){ return s + (r.count || 0); }, 0) || 1;
                var w = document.getElementById('detailPollWidget');
                w.classList.add('poll-voted');
                var els = w.querySelectorAll('.poll-option');
                els.forEach(function(o, i) {
                    var c = results[i] ? results[i].count : 0;
                    var pct = Math.round((c / total) * 100);
                    var barWrap = o.querySelector('.poll-bar-wrap'); if (barWrap) barWrap.style.display = '';
                    var bar = o.querySelector('.poll-bar'); if (bar) bar.style.width = pct + '%';
                    var pctEl = o.querySelector('.poll-pct');
                    if (pctEl) { pctEl.style.display = ''; pctEl.textContent = pct + '%'; }
                });
                var ta = w.querySelector('.poll-total'); if (ta) ta.textContent = total + ' ' + __t('pd.poll_participants');
                var acts = w.querySelector('.poll-vote-actions');
                if (acts) acts.innerHTML = '<span class="poll-voted-note">✔ ' + __t('pd.poll_voted') + '</span>';
                App.showToast(__t('pd.poll_vote_success'), 'success');
            } catch(e) {
                if (btn) { btn.disabled = false; btn.textContent = __t('pd.poll_vote'); }
                App.showToast(e.message || __t('pd.poll_vote_failed'), 'error');
            }
        }
        function goEditPoll() {
            window.location.href = '/pages/post.php?edit=<?= $postId ?>';
        }
        // ===== /详情页内联投票 =====

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
      // #backToTop 已从本页移除：回到顶部统一由 polish.js 的 .lw-fab 提供
    })();
    </script>
    <?php require_once __DIR__ . '/../includes/lang_ui.php'; ?>
</body>
</html>
