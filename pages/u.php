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
require_once __DIR__ . '/../includes/achievements.php';
require_once __DIR__ . '/../includes/widget_render.php';

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
    // 装扮（头像框 / 气泡 / 徽章 / 成就）—— 一次性取出，避免模板里逐项查库
    $decor = lwUserDecorBundle($targetId);
} else {
    $decor = ['frame' => null, 'bubble' => null, 'badge' => null, 'badges' => []];
}

if ($target) {

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
    $stats['followers'] = lwFollowerCount($fs, $target);
    $stats['following'] = lwFollowingCount($fs, $target);
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
        'user'       => $target,
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
    <link rel="stylesheet" href="<?= asset_url('/assets/css/widgets.css') ?>?v=<?= asset_ver('/assets/css/widgets.css') ?>">
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
        /* =================================================================
         * 用户主页 —— 仿 B 站个人空间
         * =================================================================
         * B 站个人空间的三个特征，这里都做了：
         *   1. 顶部一块大横幅（banner），头像压在下沿、略微出框
         *   2. 横幅右侧放统计（关注/粉丝/获赞），数字大、标签小
         *   3. 下方是标签页式的作品列表，卡片式排布
         *
         * 为什么用横幅而不是纯色卡：
         *   个人主页是「用户自己的地盘」，第一眼要有辨识度。
         *   纯色卡看十个人都是一样的，横幅至少能让人放张图。
         *
         * 没有头图时的兜底：用渐变 + 一层极淡的网格纹理，
         * 比一块死白/死蓝有质感得多，也不会显得「图没加载出来」。
         * ================================================================= */

        .u-page {
            max-width: var(--content-max-width, 980px);
            margin: 0 auto;
            padding: 1.25rem 1rem 3rem;
        }

        /* ---- 顶部横幅 ---- */
        .u-card {
            position: relative;
            border-radius: 18px;
            overflow: hidden;
            background: var(--surface, #fff);
            border: 1px solid var(--border-light, #E9EEF5);
            box-shadow: 0 1px 2px rgba(16,24,40,.04), 0 12px 32px -20px rgba(16,24,40,.18);
        }

        .u-cover {
            position: relative;
            height: 10rem;
            background:
                radial-gradient(circle at 20% 30%, rgba(59,111,182,.18), transparent 55%),
                radial-gradient(circle at 80% 70%, rgba(88,168,214,.16), transparent 50%),
                linear-gradient(120deg, #EAF1FB 0%, #F3F7FD 50%, #E8F4F0 100%);
            background-size: cover;
            background-position: center;
        }
        /* 极淡网格：没有头图时靠它撑住质感 */
        .u-cover::after {
            content: "";
            position: absolute;
            inset: 0;
            background-image:
                linear-gradient(rgba(255,255,255,.5) 1px, transparent 1px),
                linear-gradient(90deg, rgba(255,255,255,.5) 1px, transparent 1px);
            background-size: 22px 22px;
            opacity: .35;
            pointer-events: none;
        }
        .u-cover.has-img::after { display: none; }

        .u-body {
            position: relative;
            padding: 0 1.5rem 1.25rem;
        }

        /* ---- 头像压边框 ---- */
        .u-top {
            display: flex;
            align-items: flex-end;
            gap: 1rem;
            margin-top: -2.75rem;
        }
        .u-avatar-wrap {
            position: relative;
            flex: 0 0 auto;
            width: 6rem;
            height: 6rem;
            border-radius: 50%;
            border: 4px solid var(--surface, #fff);
            background: var(--surface, #fff);
            box-shadow: 0 4px 16px -6px rgba(16,24,40,.22);
            overflow: hidden;
        }
        .u-avatar-wrap img,
        .u-avatar-wrap .lw-av {
            width: 100% !important;
            height: 100% !important;
        }

        .u-headline {
            flex: 1 1 auto;
            min-width: 0;
            padding-bottom: .35rem;
        }
        .u-nick {
            font-size: 1.3rem;
            font-weight: 700;
            letter-spacing: -.3px;
            display: flex;
            align-items: center;
            gap: .5rem;
            flex-wrap: wrap;
            line-height: 1.3;
        }
        .u-sub {
            font-size: .8rem;
            color: var(--text-muted, #98A2B3);
            margin-top: .3rem;
            display: flex;
            gap: .75rem;
            flex-wrap: wrap;
        }
        .u-sub span { white-space: nowrap; }

        /* ---- 统计（B 站那排数字）---- */
        .u-stats {
            display: flex;
            gap: 2rem;
            margin-top: 1rem;
            padding-top: .9rem;
            border-top: 1px solid var(--border-light, #F0F3F8);
            flex-wrap: wrap;
        }
        .u-stat {
            display: flex;
            align-items: baseline;
            gap: .35rem;
            cursor: default;
        }
        .u-stat b {
            font-size: 1.1rem;
            font-weight: 700;
            font-variant-numeric: tabular-nums;
            color: var(--text-primary, #1F2733);
        }
        .u-stat span {
            font-size: .76rem;
            color: var(--text-muted, #98A2B3);
        }
        .u-stat.is-link { cursor: pointer; }
        .u-stat.is-link:hover b { color: var(--primary, #2F5B9A); }

        /* ---- 简介 ---- */
        .u-bio {
            margin-top: .9rem;
            font-size: .85rem;
            line-height: 1.75;
            color: var(--text-secondary, #5A6472);
            white-space: pre-wrap;
            word-break: break-word;
        }
        .u-bio.is-empty { color: var(--text-muted, #B6BFCB); font-style: italic; }

        /* ---- 操作按钮 ---- */
        .u-actions {
            display: flex;
            gap: .6rem;
            flex-wrap: wrap;
            margin-top: 1.1rem;
        }
        .u-actions .btn { border-radius: 10px; }

        .u-guest-note {
            margin-top: 1rem;
            font-size: .8rem;
            color: var(--text-muted, #98A2B3);
            padding: .7rem .9rem;
            background: var(--bg-secondary, #F7F9FC);
            border-radius: 10px;
            line-height: 1.7;
        }

        /* ---- 作品区 ---- */
        .u-section-title {
            display: flex;
            align-items: center;
            gap: .5rem;
            font-size: .95rem;
            font-weight: 700;
            margin: 1.75rem 0 .9rem;
            color: var(--text-primary, #1F2733);
        }
        .u-section-title i {
            display: inline-block;
            width: 3px;
            height: 15px;
            border-radius: 2px;
            background: var(--primary, #2F5B9A);
        }

        .u-posts {
            display: grid;
            gap: .85rem;
        }

        .u-post {
            background: var(--surface, #fff);
            border: 1px solid var(--border-light, #E9EEF5);
            border-radius: 14px;
            padding: 1rem 1.1rem;
            transition: transform .18s ease, box-shadow .18s ease, border-color .18s ease;
        }
        .u-post:hover {
            transform: translateY(-2px);
            border-color: rgba(59,111,182,.28);
            box-shadow: 0 8px 24px -14px rgba(16,24,40,.22);
        }
        .u-post-top {
            display: flex;
            align-items: center;
            gap: .5rem;
            font-size: .74rem;
            color: var(--text-muted, #98A2B3);
            margin-bottom: .45rem;
            flex-wrap: wrap;
        }
        .u-post-body {
            font-size: .88rem;
            line-height: 1.72;
            color: var(--text-primary, #1F2733);
            word-break: break-word;
        }
        .u-post-foot {
            display: flex;
            gap: 1rem;
            margin-top: .55rem;
            font-size: .74rem;
            color: var(--text-muted, #98A2B3);
        }

        /* ---- 移动端 ---- */
        @media (max-width: 640px) {
            .u-page { padding: .75rem .6rem 2.5rem; }
            .u-cover { height: 7rem; }
            .u-body { padding: 0 1rem 1rem; }
            .u-top { margin-top: -2.25rem; gap: .75rem; }
            .u-avatar-wrap { width: 4.75rem; height: 4.75rem; border-width: 3px; }
            .u-nick { font-size: 1.08rem; }
            .u-stats { gap: 1.25rem; }
            .u-stat b { font-size: .98rem; }
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
                        <?php /* 头像走全站唯一复合件：头像框 + 等级环 + 勋章，同心刚性布局 */ ?>
                        <?php require_once __DIR__ . '/../includes/avatar.php'; ?>
                        <span class="u-avatar-wrap"><?= lwAvatarComposite($info['user'] ?? ['id' => $info['id'] ?? 0, 'avatar' => $info['avatar'] ?? '', 'nickname' => $info['nickname'] ?? ''], 'lg', ['badges' => true]) ?></span>
                        <div class="u-headline">
                            <div class="u-nick">
                                <span><?= htmlspecialchars($info['nickname']) ?></span>
                                <?php if ($profileLevel): ?><?= lwLevelBadgeHTML($profileLevel, 'sm') ?><?php endif; ?>
                                <?= renderUserTitleHTML($info['title']) ?>
                                <?php if ($info['role'] === 'admin' || $info['role'] === 'super_admin'): ?>
                                <span class="u-role"><?= $info['role'] === 'super_admin' ? t('user.role_super_admin') : t('user.role_admin') ?></span>
                                <?php endif; ?>
                                <?php /* 昵称旁不再挂「买来的标签」：勋章一律由成长体系的成就决定，
                                          在下面的成就行里统一展示（见 lwBadgeRow 那一段）。 */ ?>
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

                    <?php /* 成长体系勋章行（需求二、1）：固定高度、自动换行、永不溢出。
                            数据源是 lwUserBadges() —— 只认真实解锁的成就，买不到。 */ ?>
                    <?= lwBadgeRow($decor['badges'], 12) ?>

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
