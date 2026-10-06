<?php
/**
 * 热榜（周榜 / 月榜）
 *
 * 三个榜单都限定在同一时间窗口内：
 *   1) 热门帖子：按「获赞 → 评论 → 阅读 → 发布时间」逐级比较，不引入加权系数
 *      （加权分是拍脑袋的魔数，用户看不出为什么 A 比 B 高；逐级比较的规则一句话能讲清，
 *       也与首页「最热」排序、includes/ai_site_data.php:128 的 aiSortByHot 同源）。
 *   2) 活跃用户：发帖 3 分 + 评论 1 分，与 AI 助手的活跃榜（aiActiveUsers）同一套权重。
 *   3) 热门话题：窗口内帖子正文里的 #话题 按「覆盖帖子数」计数，复用 includes/text_linkify.php。
 *
 * 可见性沿用 includes/post_visibility.php 的唯一口径：看不见的帖子绝不会出现在榜上，
 * 因此不会出现「榜单点进去提示无权查看」。匿名内容不进入活跃用户榜（无法归属到具体人）。
 * 全站公告是置顶内容，不参与热度竞争，否则每个窗口的榜首永远是公告。
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/post_visibility.php';
require_once __DIR__ . '/../includes/text_linkify.php';

$user = requireLoginOrGuest();
if ($user) {
    $user = checkBanned($user);
}
$isGuest = ($user === null);
$fs = getFS();

// ---- 时间窗口 --------------------------------------------------------------
$rangeDays = ['week' => 7, 'month' => 30];
$range = (string)($_REQUEST['range'] ?? 'week');
if (!isset($rangeDays[$range])) {
    $range = 'week';
}
$since = strtotime('-' . $rangeDays[$range] . ' day');

// ---- 候选帖子（可见 + 已发布 + 窗口内 + 非公告） ----------------------------
$visible = lwFilterVisiblePosts($fs->read('posts'), $user);
$visibleIds = [];   // 全部可见帖子 ID（评论归属用，不受时间窗口限制）
foreach ($visible as $p) {
    if (is_array($p) && isset($p['id'])) {
        $visibleIds[(int)$p['id']] = true;
    }
}

$cands = [];
foreach ($visible as $p) {
    if (!is_array($p)) {
        continue;
    }
    if ((string)($p['status'] ?? 'published') !== 'published') {
        continue;
    }
    if ((string)($p['category'] ?? '') === 'announcement') {
        continue;
    }
    $ts = strtotime((string)($p['created_at'] ?? ''));
    if ($ts === false || $ts < $since) {
        continue;
    }
    $cands[(int)($p['id'] ?? 0)] = $p;
}

// 收藏数：一次遍历建成 帖子ID => 数量，避免逐条 find 造成 N+1
$favCount = [];
foreach ($fs->read('post_favorites') as $f) {
    $pid = (int)($f['post_id'] ?? 0);
    if (isset($cands[$pid])) {
        $favCount[$pid] = ($favCount[$pid] ?? 0) + 1;
    }
}

$postBoard = array_values($cands);
usort($postBoard, function ($a, $b) {
    $la = (int)($a['likes'] ?? 0);
    $lb = (int)($b['likes'] ?? 0);
    if ($la !== $lb) {
        return $lb - $la;
    }
    $ca = (int)($a['comments'] ?? 0);
    $cb = (int)($b['comments'] ?? 0);
    if ($ca !== $cb) {
        return $cb - $ca;
    }
    $va = (int)($a['views'] ?? 0);
    $vb = (int)($b['views'] ?? 0);
    if ($va !== $vb) {
        return $vb - $va;
    }
    return strtotime((string)($b['created_at'] ?? '')) - strtotime((string)($a['created_at'] ?? ''));
});
$postBoard = array_slice($postBoard, 0, 10);

// ---- 活跃用户榜（窗口内：发帖 3 分 + 评论 1 分） ----------------------------
$score = [];
$userPosts = [];
foreach ($cands as $p) {
    if (!empty($p['is_anonymous'])) {
        continue;
    }
    $uid = (int)($p['user_id'] ?? 0);
    if ($uid <= 0) {
        continue;
    }
    $score[$uid] = ($score[$uid] ?? 0) + 3;
    $userPosts[$uid] = ($userPosts[$uid] ?? 0) + 1;
}
$userComments = [];
foreach ($fs->read('comments') as $c) {
    $pid = (int)($c['post_id'] ?? 0);
    if (!isset($visibleIds[$pid]) || !empty($c['is_anonymous'])) {
        continue;
    }
    $ts = strtotime((string)($c['created_at'] ?? ''));
    if ($ts === false || $ts < $since) {
        continue;
    }
    $uid = (int)($c['user_id'] ?? 0);
    if ($uid <= 0) {
        continue;
    }
    $score[$uid] = ($score[$uid] ?? 0) + 1;
    $userComments[$uid] = ($userComments[$uid] ?? 0) + 1;
}
arsort($score);
$userBoard = [];
foreach ($score as $uid => $s) {
    if (count($userBoard) >= 10) {
        break;
    }
    $userBoard[] = ['id' => $uid, 'score' => $s, 'posts' => $userPosts[$uid] ?? 0, 'comments' => $userComments[$uid] ?? 0];
}

// ---- 热门话题榜（按覆盖帖子数） --------------------------------------------
$tagCount = [];
foreach ($cands as $p) {
    $tags = lwExtractTags(($p['title'] ?? '') . "\n" . ($p['content'] ?? ''));
    foreach (array_keys($tags) as $tg) {
        $tagCount[$tg] = ($tagCount[$tg] ?? 0) + 1;
    }
}
arsort($tagCount);
$tagBoard = array_slice($tagCount, 0, 12, true);

// ---- 昵称 / 头像：一次读全表建索引，避免循环里逐条查询 ----------------------
$users = [];
foreach ($fs->read('users') as $u) {
    if (isset($u['id'])) {
        $users[(int)$u['id']] = $u;
    }
}

/** 分类中文名（与话题页、搜索、AI 助手同一套文案） */
function rankingCategoryName(string $key): string
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

/** 榜单名次样式：前三名给金银铜，其余用中性色 */
function rankingMedalClass(int $rank): string
{
    return $rank === 1 ? ' rk-gold' : ($rank === 2 ? ' rk-silver' : ($rank === 3 ? ' rk-bronze' : ''));
}

$pageTitle = t('rank.page_title') . ' - ' . SITE_NAME;
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
    <meta name="description" content="<?= htmlspecialchars(t('rank.page_title') . ' · ' . SITE_NAME) ?>">
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
        .rk-page{max-width:var(--content-max-width,900px);margin:0 auto;padding:1.5rem 1rem 3rem;}
        .rk-hero{position:relative;overflow:hidden;background:var(--surface,#fff);border:1px solid var(--border-light,#E4E9F0);border-radius:1rem;padding:1.25rem 1.375rem;margin-bottom:1.125rem;box-shadow:var(--shadow-sm);}
        .rk-hero::after{content:'';position:absolute;right:-3.5rem;top:-3.5rem;width:10rem;height:10rem;border-radius:50%;background:var(--warning-light,#FBF3E3);opacity:.65;pointer-events:none;}
        .rk-hero-inner{position:relative;z-index:1;}
        .rk-title{display:flex;align-items:center;gap:.5rem;font-size:1.5rem;font-weight:800;color:var(--text);line-height:1.3;}
        .rk-sub{margin-top:.375rem;font-size:.82rem;color:var(--text-secondary);}
        .rk-switch{display:inline-flex;gap:.25rem;margin-top:.875rem;padding:.25rem;border-radius:9999px;background:var(--bg-secondary,#F4F7FA);}
        .rk-switch a{padding:.3125rem 1rem;border-radius:9999px;font-size:.8rem;font-weight:600;color:var(--text-secondary);text-decoration:none;transition:background .2s,color .2s;}
        .rk-switch a.active{background:var(--surface,#fff);color:var(--primary);box-shadow:var(--shadow-sm);}
        .rk-guests{display:flex;gap:.5rem;align-items:center;margin-top:.875rem;padding:.625rem .75rem;border-radius:.625rem;background:var(--warning-light,#FFF8E6);color:var(--warning,#8A6D1F);font-size:.8rem;line-height:1.6;}
        .rk-panel{background:var(--surface,#fff);border:1px solid var(--border-light,#E4E9F0);border-radius:1rem;padding:1.125rem 1.25rem;margin-bottom:1rem;box-shadow:var(--shadow-sm);}
        .rk-panel-head{display:flex;align-items:baseline;justify-content:space-between;gap:.75rem;flex-wrap:wrap;margin-bottom:.75rem;}
        .rk-panel-title{font-size:.95rem;font-weight:700;color:var(--text);}
        .rk-panel-hint{font-size:.74rem;color:var(--text-tertiary);line-height:1.5;}
        .rk-item{display:flex;align-items:center;gap:.75rem;padding:.625rem 0;border-bottom:1px dashed var(--border-light,#E4E9F0);}
        .rk-item:last-child{border-bottom:none;padding-bottom:0;}
        .rk-rank{flex:0 0 auto;width:1.5rem;height:1.5rem;border-radius:.5rem;display:flex;align-items:center;justify-content:center;font-size:.74rem;font-weight:800;background:var(--bg-secondary,#F4F7FA);color:var(--text-tertiary);font-variant-numeric:tabular-nums;}
        .rk-rank.rk-gold{background:linear-gradient(135deg,#F0C674,#D9A43C);color:#5A4310;}
        .rk-rank.rk-silver{background:linear-gradient(135deg,#D8DEE7,#B4BECB);color:#3D4753;}
        .rk-rank.rk-bronze{background:linear-gradient(135deg,#E4B48C,#C68A5C);color:#4F2F13;}
        .rk-main{flex:1;min-width:0;}
        .rk-item-title{display:block;font-size:.9rem;font-weight:600;color:var(--text);text-decoration:none;line-height:1.5;word-break:break-word;}
        .rk-item-title:hover{color:var(--primary);}
        .rk-item-meta{display:flex;gap:.625rem;flex-wrap:wrap;align-items:center;margin-top:.375rem;font-size:.72rem;color:var(--text-tertiary);}
        .rk-avatar{flex:0 0 auto;width:2.25rem;height:2.25rem;border-radius:50%;object-fit:cover;background:var(--bg-secondary,#F4F7FA);}
        .rk-score{flex:0 0 auto;font-size:.8rem;font-weight:800;color:var(--primary);font-variant-numeric:tabular-nums;}
        .rk-tags{display:flex;flex-wrap:wrap;gap:.5rem;}
        .rk-tag{display:inline-flex;align-items:center;gap:.375rem;padding:.3125rem .75rem;border-radius:9999px;background:var(--primary-light,#EEF3FB);color:var(--primary);font-size:.8rem;font-weight:600;text-decoration:none;transition:filter .2s;}
        .rk-tag:hover{filter:brightness(.96);}
        .rk-tag i{font-style:normal;font-size:.72rem;opacity:.75;font-weight:700;}
        .rk-empty{padding:1.5rem 0;text-align:center;font-size:.82rem;color:var(--text-tertiary);}
        @media (max-width:480px){
            .rk-page{padding:1rem .75rem 2.5rem;}
            .rk-title{font-size:1.3rem;}
            .rk-panel{padding:1rem;}
        }
    </style>
    <script src="<?= asset_url('/assets/js/main.js') ?>?v=<?= asset_ver('/assets/js/main.js') ?>" defer></script>
    <script src="<?= asset_url('/assets/js/enhancements.js') ?>?v=<?= asset_ver('/assets/js/enhancements.js') ?>" defer></script>
</head>
<body>
    <a class="skip-to-content" href="#main-content">跳到主内容</a>
    <?php
    $headerBackHref = '/';
    $headerBackText = t('rank.back_home');
    require __DIR__ . '/../includes/site_header.php';
    ?>

    <?php require __DIR__ . '/../includes/ai_widget.php'; ?>

    <main id="main-content" class="site-main">
        <div class="rk-page">
            <div class="rk-hero">
                <div class="rk-hero-inner">
                    <div class="rk-title"><?= t('rank.page_title') ?></div>
                    <div class="rk-sub"><?= $range === 'week' ? t('rank.scope_week') : t('rank.scope_month') ?></div>
                    <div class="rk-switch">
                        <a href="?range=week" class="<?= $range === 'week' ? 'active' : '' ?>"><?= t('rank.week') ?></a>
                        <a href="?range=month" class="<?= $range === 'month' ? 'active' : '' ?>"><?= t('rank.month') ?></a>
                    </div>
                    <?php if ($isGuest): ?>
                    <div class="rk-guests">
                        <span><?= t('rank.guest_note') ?></span>
                        <a class="btn btn-primary btn-sm" href="/pages/register.php" style="flex:0 0 auto;"><?= t('guest.register_cta') ?></a>
                    </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- 热门帖子 -->
            <div class="rk-panel">
                <div class="rk-panel-head">
                    <div class="rk-panel-title"><?= t('rank.posts_title') ?></div>
                    <div class="rk-panel-hint"><?= t('rank.posts_hint') ?></div>
                </div>
                <?php if (!$postBoard): ?>
                <div class="rk-empty"><?= t('rank.empty') ?></div>
                <?php else: ?>
                <?php foreach ($postBoard as $i => $p):
                    $rank = $i + 1;
                    $isAnon = !empty($p['is_anonymous']);
                    $uid = (int)($p['user_id'] ?? 0);
                    $nickRaw = trim((string)($users[$uid]['nickname'] ?? ''));
                    $author = $isAnon ? t('pd.anonymous_user') : ($nickRaw !== '' ? $nickRaw : ('用户' . $uid));
                    $pid = (int)($p['id'] ?? 0);
                ?>
                <div class="rk-item">
                    <span class="rk-rank<?= rankingMedalClass($rank) ?>"><?= $rank ?></span>
                    <div class="rk-main">
                        <a class="rk-item-title" href="/pages/post_detail.php?id=<?= $pid ?>"><?= htmlspecialchars(trim((string)($p['title'] ?? '')) !== '' ? $p['title'] : t('uc.no_title')) ?></a>
                        <div class="rk-item-meta">
                            <span class="category-badge cat-<?= htmlspecialchars((string)($p['category'] ?? 'other')) ?>"><?= htmlspecialchars(rankingCategoryName((string)($p['category'] ?? 'other'))) ?></span>
                            <span>
                                <?php if ($isAnon): ?><?= htmlspecialchars($author) ?><?php else: ?>
                                <a href="<?= htmlspecialchars(lwUserUrl($author)) ?>" style="color:inherit;text-decoration:none;"><?= htmlspecialchars($author) ?></a>
                                <?php endif; ?>
                            </span>
                            <span><?= (int)($p['likes'] ?? 0) ?><?= t('rank.u_like') ?></span>
                            <span><?= (int)($p['comments'] ?? 0) ?><?= t('rank.u_comment') ?></span>
                            <span><?= (int)($favCount[$pid] ?? 0) ?><?= t('rank.u_fav') ?></span>
                            <span><?= (int)($p['views'] ?? 0) ?><?= t('rank.u_view') ?></span>
                            <span><?= htmlspecialchars(timeAgo((string)($p['created_at'] ?? ''))) ?></span>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
                <?php endif; ?>
            </div>

            <!-- 活跃用户 -->
            <div class="rk-panel">
                <div class="rk-panel-head">
                    <div class="rk-panel-title"><?= t('rank.users_title') ?></div>
                    <div class="rk-panel-hint"><?= t('rank.users_hint') ?></div>
                </div>
                <?php if (!$userBoard): ?>
                <div class="rk-empty"><?= t('rank.empty') ?></div>
                <?php else: ?>
                <?php foreach ($userBoard as $i => $u):
                    $rank = $i + 1;
                    $nick = trim((string)($users[$u['id']]['nickname'] ?? '')) ?: ('用户' . $u['id']);
                    $avatar = trim((string)($users[$u['id']]['avatar'] ?? ''));
                    if ($avatar === '' && !empty($users[$u['id']]['qq'])) {
                        $avatar = getQQAvatar((string)$users[$u['id']]['qq']);
                    }
                ?>
                <div class="rk-item">
                    <span class="rk-rank<?= rankingMedalClass($rank) ?>"><?= $rank ?></span>
                    <img class="rk-avatar" src="<?= htmlspecialchars($avatar !== '' ? $avatar : SITE_URL . '/assets/images/default-avatar.svg') ?>" alt="" loading="lazy" onerror="this.src='<?= SITE_URL ?>/assets/images/default-avatar.svg'">
                    <div class="rk-main">
                        <a class="rk-item-title" href="<?= htmlspecialchars(lwUserUrl($nick)) ?>"><?= htmlspecialchars($nick) ?></a>
                        <div class="rk-item-meta">
                            <span><?= (int)$u['posts'] ?><?= t('rank.u_post') ?></span>
                            <span><?= (int)$u['comments'] ?><?= t('rank.u_comment') ?></span>
                        </div>
                    </div>
                    <span class="rk-score"><?= (int)$u['score'] ?></span>
                </div>
                <?php endforeach; ?>
                <?php endif; ?>
            </div>

            <!-- 热门话题 -->
            <div class="rk-panel">
                <div class="rk-panel-head">
                    <div class="rk-panel-title"><?= t('rank.tags_title') ?></div>
                    <div class="rk-panel-hint"><?= t('rank.tags_hint') ?></div>
                </div>
                <?php if (!$tagBoard): ?>
                <div class="rk-empty"><?= t('rank.empty') ?></div>
                <?php else: ?>
                <div class="rk-tags">
                    <?php foreach ($tagBoard as $tg => $cnt): ?>
                    <a class="rk-tag" href="<?= htmlspecialchars(lwTopicUrl((string)$tg)) ?>">#<?= htmlspecialchars((string)$tg) ?><i><?= (int)$cnt ?></i></a>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </main>

    <?php
    $mobileNavActive = '';
    require __DIR__ . '/../includes/mobile_bottom_nav.php';
    ?>

    <div class="toast-container" id="toastContainer"></div>
</body>
</html>
