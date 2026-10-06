<?php
/**
 * 用户主页（只读）
 *
 * 入口：正文/评论里的 @提及 链接（includes/text_linkify.php 生成，优先用 ?id=）。
 * 为什么单独开一页而不是复用 pages/user_center.php：
 *   user_center.php 的整个页面（含 7 个标签页、安全设置）都以「当前登录用户本人」为前提，
 *   在里面再插一套「看别人」的分支，等于把权限判断散落到 80KB 模板的每个角落。
 *   这里只读展示公开信息，取数口径与 api/user/profile.php 完全一致，边界清楚。
 *
 * 隐私边界（改动请一并维护）：
 *   - 绝不输出 QQ / 邮箱 / 真实姓名 / 2FA 状态 / 登录信息 —— 那些只在「本人」接口里出现；
 *   - 班级 / 年级仅对已登录用户展示（游客看不到更细的个人信息）；
 *   - 帖子列表复用 includes/post_visibility.php，与首页信息流、话题页、搜索同一口径。
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/post_visibility.php';
require_once __DIR__ . '/../includes/text_linkify.php';
require_once __DIR__ . '/../includes/user_title.php';

$user = requireLoginOrGuest();
if ($user) {
    $user = checkBanned($user);
}
$isGuest = ($user === null);

$fs = getFS();

// 目标用户：优先用稳定的用户 ID；昵称作为回退（前端动态渲染拿不到 ID 时用昵称跳转）
$target = null;
$targetId = intval($_REQUEST['id'] ?? 0);
if ($targetId > 0) {
    $target = $fs->findById('users', $targetId);
} else {
    $nick = trim((string)($_REQUEST['nick'] ?? ''));
    if ($nick !== '' && mb_strlen($nick) <= 24) {
        $byNick = array_values($fs->find('users', ['nickname' => $nick]));
        if ($byNick) {
            $target = $byNick[0];
        }
    }
}

if (!$target) {
    http_response_code(404);
}

$isSelf = $target && $user && (int)$target['id'] === (int)$user['id'];

// ---- 公开信息 -------------------------------------------------------------
$info = null;
$posts = [];
$postTotal = 0;
$isFollowing = false;
$stats = ['posts' => 0, 'comments' => 0, 'followers' => 0, 'following' => 0];

if ($target) {
    $targetId = (int)$target['id'];

    // 帖子：公开列表只看「别人能看到的那部分」，本人看自己的全部（与个人中心一致）
    $all = $fs->find('posts', ['user_id' => $targetId]);
    $visible = $isSelf ? $all : lwFilterVisiblePosts($all, $user);
    usort($visible, function ($a, $b) {
        return strtotime((string)($b['created_at'] ?? '')) - strtotime((string)($a['created_at'] ?? ''));
    });
    foreach ($visible as $p) {
        if (($p['status'] ?? 'published') === 'published') {
            $stats['posts']++;
        }
    }
    $postTotal = count($visible);

    // 游客与首页信息流同口径：只看最新前 N 条
    if ($isGuest && $postTotal > GUEST_VISIBLE_POSTS) {
        $visible = array_slice($visible, 0, GUEST_VISIBLE_POSTS);
    }
    $posts = array_slice($visible, 0, 20);

    $stats['comments'] = count($fs->find('comments', ['user_id' => $targetId]));
    $stats['followers'] = count($fs->find('follows', ['target_id' => $targetId]));
    $stats['following'] = count($fs->find('follows', ['user_id' => $targetId]));
    $isFollowing = $user && !$isSelf
        && (bool)$fs->findOne('follows', ['user_id' => $user['id'], 'target_id' => $targetId]);

    $entranceYear = (int)($target['entrance_year'] ?? 0);
    $info = [
        'id'         => $targetId,
        'nickname'   => (string)($target['nickname'] ?? ('用户' . $targetId)),
        'avatar'     => (string)($target['avatar'] ?? ''),
        'bio'        => (string)($target['bio'] ?? ''),
        'role'       => (string)($target['role'] ?? 'user'),
        'created_at' => (string)($target['created_at'] ?? ''),
        'title'      => $target,
        // 班级/年级属于更细的个人信息，只给已登录用户看
        'grade'      => (!$isGuest && $entranceYear > 0 && function_exists('gradeLabel')) ? gradeLabel($entranceYear) : '',
        'class_num'  => !$isGuest ? (int)($target['class_num'] ?? 0) : 0,
    ];
}

// 成长等级徽章（服务端直出，避免进页面后徽章「跳出来」造成布局抖动）
require_once __DIR__ . '/../includes/user_level_badge.php';
$profileLevel = $info ? lwLevelRow((int)$info['id']) : null;

// 屏蔽关系：仅登录且非本人时才需要判断
require_once __DIR__ . '/../includes/user_blocks.php';
$blockRelation = 'none';
if ($user && $info && $user['id'] != $info['id']) {
    $blockRelation = lwBlockRelation((int)$user['id'], (int)$info['id']);
}
$isBlockedByMe = ($blockRelation === 'i_blocked' || $blockRelation === 'mutual');
$isBlockedMe   = ($blockRelation === 'blocked_me' || $blockRelation === 'mutual');

// 帖子列表只来自同一作者，作者名直接用目标昵称，无需再遍历 users 建索引
$pageTitle = ($info ? $info['nickname'] : t('user.page_title')) . ' - ' . (t('user.page_title')) . ' - ' . SITE_NAME;
?>
<!DOCTYPE html>
<html lang="<?= $LANG_CODE ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <?php require_once __DIR__ . '/../includes/pwa_head.php'; ?>
    <link rel="icon" href="/icon.ico" type="image/x-icon">
    <title><?= htmlspecialchars($pageTitle) ?></title>
    <?php
        // 个人主页对搜索引擎 noindex（见下方 robots 标签，隐私考虑），
        // 但站内/群里分享时仍要有像样的卡片，所以标题用对方昵称。
        $seoTitle = trim((string)($info['nickname'] ?? '')) ?: $pageTitle;
        if (!empty($info['bio'])) {
            $seoDescription = mb_substr(trim(preg_replace('/\s+/u', ' ', (string)$info['bio'])), 0, 110);
        }
        if (!empty($info['avatar'])) {
            $seoImage = (string)$info['avatar'];
        }
        require __DIR__ . '/../includes/seo_meta.php';
    ?>
    <meta name="robots" content="noindex">
    <link rel="stylesheet" href="<?= asset_url('/assets/css/style.css') ?>?v=<?= asset_ver('/assets/css/style.css') ?>">
    <link rel="stylesheet" href="<?= asset_url('/assets/css/enhancements.css') ?>?v=<?= asset_ver('/assets/css/enhancements.css') ?>">
    <script>
        const SITE_URL = '<?= SITE_URL ?>';
        const IS_LOGGED_IN = <?= $user ? 'true' : 'false' ?>;
        const IS_GUEST = <?= $isGuest ? 'true' : 'false' ?>;
        const USER_DATA = <?= $user ? json_encode(['id' => $user['id'], 'qq' => $user['qq'], 'uuid' => $user['uuid'] ?? '', 'nickname' => $user['nickname'], 'avatar' => $user['avatar'], 'role' => $user['role']], JSON_HEX_TAG | JSON_UNESCAPED_UNICODE) : 'null' ?>;
        const CSRF_TOKEN = <?= json_encode(generateCSRFToken()) ?>;
        window.IS_LOGGED_IN = IS_LOGGED_IN;
        window.IS_GUEST = IS_GUEST;
        window.USER_DATA = USER_DATA;
        window.CSRF_TOKEN = CSRF_TOKEN;
    </script>
    <style>
        .u-page{max-width:var(--content-max-width,900px);margin:0 auto;padding:1.5rem 1rem 3rem;}
        .u-card{position:relative;overflow:hidden;background:var(--surface,#fff);border:1px solid var(--border-light,#E4E9F0);border-radius:1rem;box-shadow:var(--shadow-sm,0 2px 10px rgba(20,40,80,.05));}
        .u-cover{height:5rem;background:linear-gradient(120deg,var(--primary-light,#EEF3FB),var(--bg-secondary,#F4F7FA));}
        .u-body{padding:0 1.375rem 1.25rem;}
        .u-top{display:flex;align-items:flex-end;gap:1rem;margin-top:-2.25rem;}
        .u-avatar{width:4.5rem;height:4.5rem;border-radius:50%;object-fit:cover;border:3px solid var(--surface,#fff);background:var(--bg-secondary,#F4F7FA);flex:0 0 auto;box-shadow:0 2px 10px rgba(20,40,80,.08);}
        .u-headline{flex:1;min-width:0;padding-bottom:.25rem;}
        .u-nick{display:flex;align-items:center;gap:.5rem;flex-wrap:wrap;font-size:1.25rem;font-weight:800;color:var(--text);line-height:1.3;word-break:break-all;}
        .u-role{font-size:.68rem;font-weight:700;padding:.125rem .5rem;border-radius:9999px;background:var(--primary-light,#EEF3FB);color:var(--primary);}
        .u-sub{margin-top:.25rem;font-size:.78rem;color:var(--text-tertiary);display:flex;gap:.75rem;flex-wrap:wrap;}
        .u-bio{margin-top:.875rem;font-size:.88rem;color:var(--text-secondary);line-height:1.8;white-space:pre-wrap;word-break:break-word;}
        .u-bio .hashtag-link,.u-bio .mention-link{text-decoration:none;font-weight:600;}
        .u-bio.is-empty{color:var(--text-tertiary);font-style:normal;}
        .u-stats{display:flex;gap:1.5rem;margin-top:1.125rem;padding-top:1rem;border-top:1px solid var(--border-light,#E4E9F0);}
        .u-stat{display:flex;flex-direction:column;gap:.125rem;text-decoration:none;}
        .u-stat b{font-size:1.05rem;font-weight:800;color:var(--text);}
        .u-stat span{font-size:.74rem;color:var(--text-tertiary);}
        .u-actions{margin-top:1.125rem;display:flex;gap:.625rem;flex-wrap:wrap;align-items:center;}
        .u-section-title{font-size:.95rem;font-weight:700;color:var(--text);margin:1.5rem 0 .75rem;display:flex;align-items:center;gap:.5rem;}
        .u-section-title i{width:.25rem;height:.875rem;border-radius:2px;background:var(--primary);display:inline-block;}
        .u-posts{display:flex;flex-direction:column;gap:.75rem;}
        .u-post{display:block;background:var(--surface,#fff);border:1px solid var(--border-light,#E4E9F0);border-radius:.875rem;padding:1rem 1.125rem;text-decoration:none;transition:border-color .2s,box-shadow .2s,transform .2s;}
        .u-post:hover{border-color:var(--primary);box-shadow:var(--shadow-md,0 6px 20px rgba(20,40,80,.08));transform:translateY(-1px);}
        .u-post-top{display:flex;align-items:center;gap:.5rem;flex-wrap:wrap;margin-bottom:.5rem;}
        .u-post-title{font-size:1rem;font-weight:700;color:var(--text);line-height:1.45;margin:0 0 .375rem;}
        .u-post:hover .u-post-title{color:var(--primary);}
        .u-post-body{font-size:.86rem;color:var(--text-secondary);line-height:1.75;word-break:break-word;}
        .u-post-body .hashtag-link,.u-post-body .mention-link{text-decoration:none;font-weight:600;}
        .u-post-foot{display:flex;gap:.875rem;flex-wrap:wrap;margin-top:.625rem;font-size:.76rem;color:var(--text-tertiary);}
        .u-guest-note{margin-top:1rem;padding:.75rem .875rem;border-radius:.625rem;background:var(--warning-light,#FFF8E6);color:var(--warning,#8A6D1F);font-size:.8rem;line-height:1.65;display:flex;align-items:center;gap:.75rem;flex-wrap:wrap;}
        @media (max-width:480px){
            .u-page{padding:1rem .75rem 2.5rem;}
            .u-body{padding:0 1rem 1.125rem;}
            .u-avatar{width:3.75rem;height:3.75rem;}
            .u-nick{font-size:1.1rem;}
            .u-top{margin-top:-1.875rem;}
        }
    </style>
    <script src="<?= asset_url('/assets/js/main.js') ?>?v=<?= asset_ver('/assets/js/main.js') ?>" defer></script>
    <script src="<?= asset_url('/assets/js/enhancements.js') ?>?v=<?= asset_ver('/assets/js/enhancements.js') ?>" defer></script>
</head>
<body>
    <a class="skip-to-content" href="#main-content">跳到主内容</a>
    <?php
    $headerBackHref = '/';
    $headerBackText = t('topic.back_home');
    require __DIR__ . '/../includes/site_header.php';
    ?>

    <?php require __DIR__ . '/../includes/ai_widget.php'; ?>

    <main id="main-content" class="site-main">
        <div class="u-page">
            <?php if (!$info): ?>
            <div class="empty-state">
                <p><?= t('user.not_found') ?></p>
                <p class="empty-subtitle"><?= t('topic.back_home') ?></p>
                <p style="margin-top:1rem;"><a class="btn btn-outline" href="/"><?= t('topic.back_home') ?></a></p>
            </div>
            <?php else: ?>
            <div class="u-card">
                <div class="u-cover"></div>
                <div class="u-body">
                    <div class="u-top">
                        <img class="u-avatar" src="<?= htmlspecialchars($info['avatar'] !== '' ? $info['avatar'] : '/assets/images/default-avatar.svg') ?>" alt="<?= t('pd.author_avatar') ?>" onerror="this.src='/assets/images/default-avatar.svg'">
                        <div class="u-headline">
                            <div class="u-nick">
                                <span><?= htmlspecialchars($info['nickname']) ?></span>
                                <?php if ($profileLevel): ?><?= lwLevelBadgeHTML($profileLevel, 'sm') ?><?php endif; ?>
                                <?= renderUserTitleHTML($info['title']) ?>
                                <?php if ($info['role'] === 'admin' || $info['role'] === 'super_admin'): ?>
                                <span class="u-role"><?= $info['role'] === 'super_admin' ? t('user.role_super_admin') : t('user.role_admin') ?></span>
                                <?php endif; ?>
                            </div>
                            <div class="u-sub">
                                <?php if ($info['created_at'] !== ''): ?>
                                <span><?= t('user.joined') ?> <?= htmlspecialchars(date('Y-m-d', strtotime($info['created_at']) ?: time())) ?></span>
                                <?php endif; ?>
                                <?php if ($info['grade'] !== ''): ?>
                                <span><?= htmlspecialchars($info['grade']) ?><?= $info['class_num'] > 0 ? ' · ' . $info['class_num'] . t('uc.class') : '' ?></span>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <?php if ($info['bio'] !== ''): ?>
                    <div class="u-bio"><?= lwRichText($info['bio']) ?></div>
                    <?php else: ?>
                    <div class="u-bio is-empty"><?= t('user.no_bio') ?></div>
                    <?php endif; ?>

                    <div class="u-stats">
                        <div class="u-stat"><b><?= $stats['posts'] ?></b><span><?= t('user.posts') ?></span></div>
                        <div class="u-stat"><b><?= $stats['comments'] ?></b><span><?= t('user.comments') ?></span></div>
                        <div class="u-stat"><b><?= $stats['followers'] ?></b><span><?= t('user.followers') ?></span></div>
                        <div class="u-stat"><b><?= $stats['following'] ?></b><span><?= t('user.following') ?></span></div>
                    </div>

                    <div class="u-actions">
                        <?php if ($isSelf): ?>
                        <a class="btn btn-outline" href="/pages/user_center.php"><?= t('user.self_link') ?></a>
                        <?php elseif ($user): ?>
                        <?php if ($isBlockedMe): ?>
                        <?php /* 被对方限制互动时，不给关注/私信入口，只说明情况（不暴露「对方屏蔽了你」） */ ?>
                        <span class="u-guest-note" style="margin:0;">该用户已限制与你的互动</span>
                        <?php else: ?>
                        <button type="button" class="follow-btn<?= $isFollowing ? ' following' : '' ?>" data-follow-user="<?= $info['id'] ?>"<?= $isFollowing ? ' title="' . t('user.followed') . '"' : '' ?>>
                            <span><?= $isFollowing ? t('user.followed') : t('user.follow') ?></span>
                            <span class="follow-count" style="display:none"></span>
                        </button>
                        <button type="button" class="btn btn-outline" id="blockBtn"
                                data-user="<?= (int)$info['id'] ?>"
                                data-blocked="<?= $isBlockedByMe ? '1' : '0' ?>"
                                aria-pressed="<?= $isBlockedByMe ? 'true' : 'false' ?>">
                            <?= $isBlockedByMe ? '已屏蔽，点击解除' : '屏蔽该用户' ?>
                        </button>
                        <?php endif; ?>
                        <?php else: ?>
                        <a class="btn btn-primary" href="/pages/register.php"><?= t('guest.register_cta') ?></a>
                        <?php endif; ?>
                    </div>

                    <?php if ($isSelf): ?>
                    <div class="u-guest-note"><?= t('user.self_tip') ?> <a href="/pages/user_center.php"><?= t('user.self_link') ?></a></div>
                    <?php elseif ($isGuest): ?>
                    <div class="u-guest-note">
                        <span><?= t('topic.guest_tip') ?></span>
                        <a class="btn btn-primary btn-sm" href="/pages/register.php" style="flex:0 0 auto;"><?= t('guest.register_cta') ?></a>
                    </div>
                    <?php endif; ?>
                </div>
            </div>

            <div class="u-section-title"><i></i><?= t('user.posts_title') ?></div>
            <?php if (!$posts): ?>
            <div class="empty-state" style="padding:2rem 1rem;">
                <p><?= t('user.no_posts') ?></p>
            </div>
            <?php else: ?>
            <div class="u-posts">
                <?php foreach ($posts as $p):
                    $catKey = (string)($p['category'] ?? 'other');
                    $catMap = [
                        'announcement' => '全站公告',
                        'lost_found'   => t('cat.lost_found'),
                        'study_help'   => t('cat.study_help'),
                        'social_chat'  => t('cat.social_chat'),
                        'confession'   => t('cat.confession'),
                        'school_info'  => t('cat.school_info'),
                        'other'        => t('cat.other'),
                    ];
                    $excerpt = trim(preg_replace('/\s+/u', ' ', (string)($p['content'] ?? '')));
                    if (mb_strlen($excerpt) > 120) {
                        $excerpt = mb_substr($excerpt, 0, 120) . '…';
                    }
                    $statusTag = ($p['status'] ?? 'published') === 'pending' ? '审核中' : (($p['status'] ?? '') === 'rejected' ? '未通过' : '');
                ?>
                <a class="u-post" href="/pages/post_detail.php?id=<?= (int)$p['id'] ?>">
                    <div class="u-post-top">
                        <span class="category-badge cat-<?= htmlspecialchars($catKey) ?>"><?= htmlspecialchars($catMap[$catKey] ?? $catKey) ?></span>
                        <?php if ($isSelf && $statusTag !== ''): ?><span class="anon-badge"><?= $statusTag ?></span><?php endif; ?>
                        <span style="margin-left:auto;font-size:.76rem;color:var(--text-tertiary);"><?= htmlspecialchars(timeAgo($p['created_at'] ?? '')) ?></span>
                    </div>
                    <?php if (trim((string)($p['title'] ?? '')) !== ''): ?>
                    <h3 class="u-post-title"><?= htmlspecialchars($p['title']) ?></h3>
                    <?php endif; ?>
                    <div class="u-post-body"><?= lwRichText($excerpt, false) ?></div>
                    <div class="u-post-foot">
                        <span><?= (int)($p['likes'] ?? 0) ?> 赞</span>
                        <span><?= (int)($p['comments'] ?? 0) ?> 评论</span>
                        <span><?= (int)($p['views'] ?? 0) ?> 阅读</span>
                    </div>
                </a>
                <?php endforeach; ?>
            </div>
            <?php if ($postTotal > count($posts)): ?>
            <div style="text-align:center;margin-top:1rem;">
                <span class="empty-subtitle"><?= t('topic.more') ?>：<?= $postTotal - count($posts) ?></span>
            </div>
            <?php endif; ?>
            <?php endif; ?>
            <?php endif; ?>
        </div>
    </main>

    <?php
    $mobileNavActive = '';
    require __DIR__ . '/../includes/mobile_bottom_nav.php';
    ?>

    <div class="toast-container" id="toastContainer"></div>

    <script>
    (function () {
        'use strict';
        var btn = document.getElementById('blockBtn');
        if (!btn) { return; }
        var busy = false;

        btn.addEventListener('click', function () {
            if (busy) { return; }
            var blocked = btn.getAttribute('data-blocked') === '1';
            var next = !blocked;
            var ask = next
                ? '屏蔽后你将看不到该用户的动态与评论，对方也无法给你发私信、关注你或评论你的帖子。确定屏蔽？'
                : '解除屏蔽后，你们将恢复正常的互相可见与互动。确定解除？';

            var go = function () {
                busy = true;
                btn.disabled = true;
                var body = new URLSearchParams({
                    action: next ? 'block' : 'unblock',
                    user_id: btn.getAttribute('data-user'),
                    csrf_token: CSRF_TOKEN
                });
                fetch(SITE_URL + '/api/user/block.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
                    body: body
                }).then(function (r) { return r.json(); })
                .then(function (res) {
                    busy = false;
                    btn.disabled = false;
                    if (res && res.success) {
                        btn.setAttribute('data-blocked', next ? '1' : '0');
                        btn.setAttribute('aria-pressed', next ? 'true' : 'false');
                        btn.textContent = next ? '已屏蔽，点击解除' : '屏蔽该用户';
                        if (window.App && App.showToast) { App.showToast(res.message || '操作成功', 'success'); }
                        // 屏蔽状态会改变「谁的内容可见」，刷新一次让列表立即体现
                        setTimeout(function () { location.reload(); }, 700);
                    } else if (window.App && App.showToast) {
                        App.showToast((res && res.message) || '操作失败', 'error');
                    }
                }).catch(function () {
                    busy = false;
                    btn.disabled = false;
                    if (window.App && App.showToast) { App.showToast('网络错误，请稍后重试', 'error'); }
                });
            };

            // 复用站内已有的确认弹窗（若可用），否则直接执行 —— 不引入新的交互体系
            if (window.App && App.confirm) {
                App.confirm({ title: '确认操作', message: ask, onConfirm: go });
            } else if (window.confirm(ask)) {
                go();
            }
        });
    })();
    </script>
</body>
</html>
