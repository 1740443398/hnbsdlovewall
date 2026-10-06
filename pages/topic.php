<?php
/**
 * 话题页 —— 汇集带某个 #话题 的帖子
 *
 * 入口：正文/评论里的 #话题 链接（由 includes/text_linkify.php 生成）。
 * 话题不是独立数据表，而是直接从帖子正文里聚合出来的：发帖时写 `#考研` 就自动归入，
 * 不需要额外维护，也不会出现「话题存在但没有帖子」的空壳。
 *
 * 可见性：复用 includes/post_visibility.php 的唯一口径，与首页信息流、搜索、AI 取数完全一致，
 * 因此不会出现「话题页能翻到搜索里看不到的帖子」。游客额外受 GUEST_VISIBLE_POSTS 限制。
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/post_visibility.php';
require_once __DIR__ . '/../includes/text_linkify.php';

$user = requireLoginOrGuest();
if ($user) {
    $user = checkBanned($user);
}
$isGuest = ($user === null);

$tag = lwNormalizeTag($_REQUEST['tag'] ?? '');

$posts = [];
$related = [];
$total = 0;
$page = max(1, intval($_REQUEST['page'] ?? 1));
$perPage = 20;
$pageCount = 1;

if ($tag !== '') {
    $visible = lwFilterVisiblePosts(getFS()->read('posts'), $user);

    // 一次遍历同时产出：命中该话题的帖子、以及这些帖子里「常一起出现」的其他话题
    $relatedCount = [];
    foreach ($visible as $p) {
        if (!is_array($p)) {
            continue;
        }
        $tags = lwExtractTags(($p['title'] ?? '') . "\n" . ($p['content'] ?? ''));
        if (!$tags) {
            continue;
        }
        $hit = false;
        foreach ($tags as $t => $_) {
            if (mb_strtolower($t) === mb_strtolower($tag)) {
                $hit = true;
            } else {
                $relatedCount[$t] = ($relatedCount[$t] ?? 0) + 1;
            }
        }
        if ($hit) {
            $posts[] = $p;
        }
    }

    usort($posts, function ($a, $b) {
        return strtotime((string)($b['created_at'] ?? '')) - strtotime((string)($a['created_at'] ?? ''));
    });
    $total = count($posts);

    // 游客只放行前 N 条，与首页信息流的口径一致
    if ($isGuest && $total > GUEST_VISIBLE_POSTS) {
        $posts = array_slice($posts, 0, GUEST_VISIBLE_POSTS);
        $total = GUEST_VISIBLE_POSTS;
    }

    $pageCount = max(1, (int)ceil(count($posts) / $perPage));
    if ($page > $pageCount) {
        $page = $pageCount;
    }
    $posts = array_slice($posts, ($page - 1) * $perPage, $perPage);

    arsort($relatedCount);
    $related = array_slice(array_keys($relatedCount), 0, 10);
}

/** 话题页里的帖子摘要：截断要在转义之前做，避免把正文里的 #/@ 标记切坏 */
function topicExcerpt(?string $text, int $limit = 120): string
{
    $text = trim(preg_replace('/\s+/u', ' ', (string)$text));
    if (mb_strlen($text) > $limit) {
        $text = mb_substr($text, 0, $limit) . '…';
    }
    return $text;
}

/** 分类中文名（与 api/search/quick.php、includes/ai_site_data.php 同一套文案） */
function topicCategoryName(string $key): string
{
    $map = [
        'announcement' => '全站公告',
        'lost_found'   => t('cat.lost_found'),
        'study_help'   => t('cat.study_help'),
        'social_chat'  => t('cat.social_chat'),
        'confession'   => t('cat.confession'),
        'school_info'  => t('cat.school_info'),
        'other'        => t('cat.other'),
    ];
    return $map[$key] ?? $key;
}

// 作者昵称：一次读 users 建索引，避免在列表循环里逐条 findById 造成 N+1
$topicNicknames = [];
foreach ((array)getFS()->read('users') as $__u) {
    if (isset($__u['id'])) {
        $topicNicknames[(int)$__u['id']] = trim((string)($__u['nickname'] ?? ''));
    }
}
unset($__u);

$pageTitle = $tag !== ''
    ? ('#' . $tag . ' - ' . t('topic.page_title') . ' - ' . SITE_NAME)
    : (t('topic.page_title') . ' - ' . SITE_NAME);
?>
<!DOCTYPE html>
<html lang="<?= $LANG_CODE ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <?php require_once __DIR__ . '/../includes/pwa_head.php'; ?>
    <link rel="icon" href="/icon.ico" type="image/x-icon">
    <title><?= htmlspecialchars($pageTitle) ?></title>
    <?php require __DIR__ . '/../includes/seo_meta.php'; ?>
    <?php if ($tag !== ''): ?>
    <meta name="description" content="<?= htmlspecialchars('#' . $tag . ' · ' . $total . t('topic.count_posts')) ?>">
    <?php endif; ?>
    <link rel="stylesheet" href="<?= asset_url('/assets/css/style.css') ?>?v=<?= asset_ver('/assets/css/style.css') ?>">
    <link rel="stylesheet" href="<?= asset_url('/assets/css/enhancements.css') ?>?v=<?= asset_ver('/assets/css/enhancements.css') ?>">
    <script>
        const SITE_URL = '<?= SITE_URL ?>';
        const IS_LOGGED_IN = <?= $user ? 'true' : 'false' ?>;
        const IS_GUEST = <?= $isGuest ? 'true' : 'false' ?>;
        const USER_DATA = <?= $user ? json_encode(['id' => $user['id'], 'qq' => $user['qq'], 'uuid' => $user['uuid'] ?? '', 'nickname' => $user['nickname'], 'avatar' => $user['avatar'], 'role' => $user['role']], JSON_HEX_TAG | JSON_UNESCAPED_UNICODE) : 'null' ?>;
        window.IS_LOGGED_IN = IS_LOGGED_IN;
        window.IS_GUEST = IS_GUEST;
        window.USER_DATA = USER_DATA;
    </script>
    <style>
        .topic-page{max-width:var(--content-max-width,900px);margin:0 auto;padding:1.5rem 1rem 3rem;}
        .topic-hero{position:relative;overflow:hidden;background:var(--surface,#fff);border:1px solid var(--border-light,#E4E9F0);border-radius:1rem;padding:1.25rem 1.375rem;margin-bottom:1.125rem;box-shadow:var(--shadow-sm,0 2px 10px rgba(20,40,80,.05));}
        .topic-hero::after{content:'';position:absolute;right:-3.5rem;top:-3.5rem;width:10rem;height:10rem;border-radius:50%;background:var(--primary-light,#EEF3FB);opacity:.7;pointer-events:none;}
        .topic-hero-inner{position:relative;z-index:1;}
        .topic-name{display:inline-flex;align-items:center;gap:.25rem;font-size:1.5rem;font-weight:800;color:var(--primary);letter-spacing:.01em;word-break:break-all;line-height:1.3;}
        .topic-meta{margin-top:.5rem;font-size:.82rem;color:var(--text-secondary);}
        .topic-meta b{color:var(--text);font-weight:700;}
        .topic-guests{display:flex;gap:.5rem;align-items:center;margin-top:.875rem;padding:.625rem .75rem;border-radius:.625rem;background:var(--warning-light,#FFF8E6);color:var(--warning,#8A6D1F);font-size:.8rem;line-height:1.6;}
        .topic-related{margin-top:1rem;padding-top:.875rem;border-top:1px dashed var(--border-light,#E4E9F0);}
        .topic-related-title{font-size:.78rem;color:var(--text-tertiary);margin-bottom:.5rem;}
        .topic-related-list{display:flex;flex-wrap:wrap;gap:.375rem;}
        .topic-chip{display:inline-flex;align-items:center;padding:.1875rem .625rem;border-radius:9999px;background:var(--primary-light,#EEF3FB);color:var(--primary);font-size:.78rem;font-weight:600;text-decoration:none;transition:filter .2s;}
        .topic-chip:hover{filter:brightness(.96);}
        .topic-list{display:flex;flex-direction:column;gap:.75rem;}
        .topic-item{display:block;background:var(--surface,#fff);border:1px solid var(--border-light,#E4E9F0);border-radius:.875rem;padding:1rem 1.125rem;text-decoration:none;transition:border-color .2s,box-shadow .2s,transform .2s;}
        .topic-item:hover{border-color:var(--primary);box-shadow:var(--shadow-md,0 6px 20px rgba(20,40,80,.08));transform:translateY(-1px);}
        .topic-item-top{display:flex;align-items:center;gap:.5rem;flex-wrap:wrap;margin-bottom:.5rem;}
        .topic-item-title{font-size:1rem;font-weight:700;color:var(--text);line-height:1.45;margin:0 0 .375rem;}
        .topic-item:hover .topic-item-title{color:var(--primary);}
        .topic-item-body{font-size:.86rem;color:var(--text-secondary);line-height:1.75;word-break:break-word;}
        .topic-item-body .hashtag-link,.topic-item-body .mention-link{text-decoration:none;font-weight:600;}
        .topic-item-foot{display:flex;align-items:center;gap:.875rem;flex-wrap:wrap;margin-top:.625rem;font-size:.76rem;color:var(--text-tertiary);}
        .topic-item-foot .ti-author{color:var(--text-secondary);font-weight:600;}
        .topic-pager{display:flex;justify-content:center;align-items:center;gap:.75rem;margin-top:1.25rem;}
        .topic-pager a,.topic-pager span{padding:.375rem .875rem;border-radius:9999px;font-size:.82rem;text-decoration:none;border:1px solid var(--border-light,#E4E9F0);color:var(--text-secondary);}
        .topic-pager a:hover{border-color:var(--primary);color:var(--primary);}
        .topic-pager .cur{background:var(--primary);border-color:var(--primary);color:#fff;font-weight:600;}
        @media (max-width:480px){
            .topic-page{padding:1rem .75rem 2.5rem;}
            .topic-name{font-size:1.3rem;}
            .topic-item{padding:.875rem 1rem;}
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
        <div class="topic-page">
            <?php if ($tag === ''): ?>
            <div class="topic-hero">
                <div class="topic-hero-inner">
                    <div class="topic-name"><?= t('topic.page_title') ?></div>
                    <div class="topic-meta"><?= t('topic.invalid') ?></div>
                </div>
            </div>
            <div class="empty-state">
                <p><?= t('topic.empty') ?></p>
                <p class="empty-subtitle"><?= t('topic.empty_tip') ?></p>
                <p style="margin-top:1rem;"><a class="btn btn-outline" href="/"><?= t('topic.back_home') ?></a></p>
            </div>
            <?php else: ?>
            <div class="topic-hero">
                <div class="topic-hero-inner">
                    <div class="topic-name">#<?= htmlspecialchars($tag) ?></div>
                    <div class="topic-meta">
                        <b><?= $total ?></b> <?= t('topic.count_posts') ?>
                        <?php if ($pageCount > 1): ?>· <?= $page ?> / <?= $pageCount ?><?php endif; ?>
                    </div>
                    <?php if ($isGuest && $total > 0): ?>
                    <div class="topic-guests">
                        <span><?= t('topic.guest_tip') ?></span>
                        <a class="btn btn-primary btn-sm" href="/pages/register.php" style="flex:0 0 auto;"><?= t('guest.register_cta') ?? '注册' ?></a>
                    </div>
                    <?php endif; ?>
                    <?php if ($related): ?>
                    <div class="topic-related">
                        <div class="topic-related-title"><?= t('topic.related') ?></div>
                        <div class="topic-related-list">
                            <?php foreach ($related as $r): ?>
                            <a class="topic-chip" href="<?= htmlspecialchars(lwTopicUrl($r)) ?>">#<?= htmlspecialchars($r) ?></a>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
            </div>

            <?php if (!$posts): ?>
            <div class="empty-state">
                <p><?= t('topic.empty') ?></p>
                <p class="empty-subtitle"><?= t('topic.empty_tip') ?></p>
            </div>
            <?php else: ?>
            <div class="topic-list">
                <?php foreach ($posts as $p):
                    $isAnon = !empty($p['is_anonymous']);
                    $uid = (int)($p['user_id'] ?? 0);
                    $authorName = $isAnon
                        ? t('pd.anonymous_user')
                        : (($topicNicknames[$uid] ?? '') !== '' ? $topicNicknames[$uid] : ('用户' . $uid));
                    $catKey = (string)($p['category'] ?? 'other');
                ?>
                <a class="topic-item" href="/pages/post_detail.php?id=<?= (int)$p['id'] ?>">
                    <div class="topic-item-top">
                        <span class="category-badge cat-<?= htmlspecialchars($catKey) ?>"><?= htmlspecialchars(topicCategoryName($catKey)) ?></span>
                        <?php if ($isAnon): ?><span class="anon-badge">匿名</span><?php endif; ?>
                        <span style="margin-left:auto;font-size:.76rem;color:var(--text-tertiary);"><?= htmlspecialchars(timeAgo($p['created_at'] ?? '')) ?></span>
                    </div>
                    <?php if (trim((string)($p['title'] ?? '')) !== ''): ?>
                    <h3 class="topic-item-title"><?= htmlspecialchars($p['title']) ?></h3>
                    <?php endif; ?>
                    <div class="topic-item-body"><?= lwRichText(topicExcerpt($p['content'] ?? ''), false) ?></div>
                    <div class="topic-item-foot">
                        <span class="ti-author"><?= htmlspecialchars($authorName) ?></span>
                        <span><?= (int)($p['likes'] ?? 0) ?> 赞</span>
                        <span><?= (int)($p['comments'] ?? 0) ?> 评论</span>
                        <span><?= (int)($p['views'] ?? 0) ?> 阅读</span>
                    </div>
                </a>
                <?php endforeach; ?>
            </div>

            <?php if ($pageCount > 1): ?>
            <div class="topic-pager">
                <?php if ($page > 1): ?>
                <a href="<?= htmlspecialchars(lwTopicUrl($tag) . '&page=' . ($page - 1)) ?>"><?= t('topic.prev_page') ?></a>
                <?php endif; ?>
                <span class="cur"><?= $page ?> / <?= $pageCount ?></span>
                <?php if ($page < $pageCount): ?>
                <a href="<?= htmlspecialchars(lwTopicUrl($tag) . '&page=' . ($page + 1)) ?>"><?= t('topic.next_page') ?></a>
                <?php endif; ?>
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
</body>
</html>
