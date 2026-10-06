<?php
/**
 * 成长中心（等级 / 经验 / 成就 / 每日任务 / 经验榜）
 *
 * 这一页**全部服务端渲染**，不依赖异步接口才能出内容 —— 原因是成长数据是本页的
 * 唯一内容，若走 AJAX，用户会先看到一整屏骨架屏再等数据，体感很差。
 * 服务端直出则首屏即有内容，只有「解锁新成就」的动作走异步（见页尾脚本）。
 *
 * 数据口径：
 *   等级/经验 → includes/level.php      （等级永远由累计经验推导）
 *   成就      → includes/achievements.php（统计量实时算，不维护计数器）
 *   每日任务  → 由 posts/comments/likes/checkins 今天的记录实时推导
 *   经验榜    → user_levels 按 exp 排序，跳过已注销与被封禁账号
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/level.php';
require_once __DIR__ . '/../includes/achievements.php';
// 徽章渲染（lwLevelRingHTML / lwLevelBadgeHTML）在这个文件里，别漏 —— 漏了会直接 Fatal error
require_once __DIR__ . '/../includes/user_level_badge.php';

$user = requireMember('成长中心需要注册账号后才能使用');
$user = checkBanned($user);
if (!empty($user['is_banned'])) {
    http_response_code(403);
    die('账号已被封禁');
}

$fs    = getFS();
$uid   = (int)$user['id'];
$today = date('Y-m-d');

// ---------- 等级 ----------
$mine = lwLevelRow($uid);
[$rank, $rankTotal] = lwLevelRank($uid);

// 成就结算放在**服务端渲染之前**：这样页面一出来就是「已解锁」的正确状态，
// 不需要前端再拉一次接口、也不需要为了让徽章变亮而整页刷新（刷新会把提示吃掉）。
$newlyUnlocked = lwCheckAchievements($uid, true);
$ach = lwAchievementSummary($uid);

// ---------- 概览统计 ----------
$stats = lwAchievementStats($uid);

// ---------- 每日任务（实时推导） ----------
$countToday = function (array $rows) use ($uid, $today) {
    $n = 0;
    foreach ($rows as $r) {
        if ((int)($r['user_id'] ?? 0) !== $uid) {
            continue;
        }
        $ts = (string)($r['created_at'] ?? '');
        if ($ts !== '' && substr($ts, 0, 10) === $today) {
            $n++;
        }
    }
    return $n;
};
$todayPosts    = $countToday((array)$fs->read('posts'));
$todayComments = $countToday((array)$fs->read('comments'));
$todayLikes    = $countToday((array)$fs->read('post_likes')) + $countToday((array)$fs->read('comment_likes'));
$ck = $fs->findOne('checkins', ['user_id' => $uid]);
$checkedToday = $ck && (string)($ck['last_date'] ?? '') === $today;

$tasks = [
    ['name' => '每日签到',   'desc' => '签到一次',      'done' => $checkedToday,        'exp' => 8,  'url' => '/pages/rollcall.php'],
    ['name' => '发布动态',   'desc' => '发布 1 条动态', 'done' => $todayPosts >= 1,     'exp' => 10, 'url' => '/pages/post.php'],
    ['name' => '参与讨论',   'desc' => '发表 1 条评论', 'done' => $todayComments >= 1,  'exp' => 5,  'url' => '/'],
    ['name' => '为他人点赞', 'desc' => '点赞 1 次',     'done' => $todayLikes >= 1,     'exp' => 2,  'url' => '/'],
];
$taskDone = count(array_filter($tasks, function ($t) { return $t['done']; }));

// ---------- 经验榜 ----------
$topRows = lwLevelTop(15);
$byId = [];
foreach ((array)$fs->read('users') as $u) {
    $byId[(int)($u['id'] ?? 0)] = $u;
}
$board = [];
$pos = 0;
foreach ($topRows as $t) {
    $u = $byId[$t['user_id']] ?? null;
    if (!$u || !empty($u['is_banned'])) {
        continue;                                        // 注销/封禁账号不占名次
    }
    $pos++;
    $board[] = [
        'rank'     => $pos,
        'nickname' => $u['nickname'] ?: ('QQ:' . $u['qq']),
        'qq'       => (string)($u['qq'] ?? ''),
        'avatar'   => $u['avatar'] ?: getQQAvatar((string)($u['qq'] ?? '')),
        'exp'      => $t['exp'],
        'level'    => $t['level'],
        'is_me'    => $t['user_id'] === $uid,
    ];
}

// ---------- 经验规则 ----------
$ruleRows = [];
foreach (lwLevelRules() as $k => $r) {
    $ruleRows[] = ['label' => $r['label'], 'exp' => (int)$r['exp']];
}

// ---------- 成就分组 ----------
$groups = [];
foreach ($ach['items'] as $it) {
    $groups[$it['group']][] = $it;
}

$csrfToken  = generateCSRFToken();
$pageTitle  = t('growth.page_title') . ' - ' . SITE_NAME;
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
    <meta name="description" content="<?= htmlspecialchars(t('growth.page_title') . ' · ' . SITE_NAME) ?>">
    <link rel="stylesheet" href="<?= asset_url('/assets/css/style.css') ?>?v=<?= asset_ver('/assets/css/style.css') ?>">
    <link rel="stylesheet" href="<?= asset_url('/assets/css/enhancements.css') ?>?v=<?= asset_ver('/assets/css/enhancements.css') ?>">
    <script>
        const SITE_URL = '<?= SITE_URL ?>';
        const CSRF_TOKEN = '<?= $csrfToken ?>';
        const IS_LOGGED_IN = true;
        window.IS_LOGGED_IN = true;
        window.USER_DATA = <?= json_encode(['id' => $user['id'], 'qq' => $user['qq'], 'nickname' => $user['nickname'], 'avatar' => $user['avatar'], 'role' => $user['role']], JSON_HEX_TAG | JSON_UNESCAPED_UNICODE) ?>;
    </script>
    <style>
        /* 本页专属微调；通用样式（.gw-*）在 style.css 里，全站共用 */
        .gw-panel { display: none; }
        .gw-panel.active { display: block; }
        .gw-my-level { display: flex; align-items: center; gap: 16px; flex-wrap: wrap; }
        .gw-my-avatar { width: 64px; height: 64px; border-radius: 50%; object-fit: cover; background: var(--bg-secondary, #EDF1F5); }
        .gw-rank-avatar { width: 30px; height: 30px; border-radius: 50%; object-fit: cover; flex: 0 0 auto; background: var(--bg-secondary, #EDF1F5); }
        .gw-tip { font-size: 12.5px; color: var(--text-secondary); margin-top: 6px; }
    </style>
</head>
<body>
    <a class="skip-to-content" href="#main-content">跳到主内容</a>
    <?php
    $headerBackHref = '/';
    $headerBackText = t('growth.back_home');
    require __DIR__ . '/../includes/site_header.php';
    ?>

    <?php require __DIR__ . '/../includes/ai_widget.php'; ?>

    <main id="main-content" class="site-main">
        <div class="gw-wrap">

            <section class="gw-hero" aria-labelledby="gw-hero-title">
                <?= lwLevelRingHTML($mine, 64) ?>
                <div class="gw-hero-main">
                    <h1 class="gw-hero-name" id="gw-hero-title">
                        <span><?= htmlspecialchars($user['nickname'] ?: ('QQ:' . $user['qq'])) ?></span>
                        <?= lwLevelBadgeHTML($mine, 'lg') ?>
                    </h1>
                    <div class="gw-hero-sub">
                        <?= htmlspecialchars(t('growth.my_level')) ?>：Lv.<?= (int)$mine['level'] ?> <?= htmlspecialchars($mine['title']) ?>
                        · <?= htmlspecialchars(t('growth.exp')) ?> <?= (int)$mine['exp'] ?>
                        <?php if ($rank > 0): ?>
                        · <?= htmlspecialchars(sprintf(t('growth.rank_of'), $rank, $rankTotal)) ?>
                        <?php endif; ?>
                    </div>
                    <div class="gw-bar" role="progressbar" aria-valuemin="0" aria-valuemax="100"
                         aria-valuenow="<?= (int)$mine['percent'] ?>"
                         aria-label="<?= htmlspecialchars(t('growth.my_level')) ?>">
                        <i style="width:<?= (float)$mine['percent'] ?>%"></i>
                    </div>
                    <div class="gw-bar-meta">
                        <span>
                            <?php if (!empty($mine['is_max'])): ?>
                                <?= htmlspecialchars(t('growth.max_level')) ?>
                            <?php else: ?>
                                <?= htmlspecialchars(sprintf(t('growth.to_next'), (int)$mine['to_next'])) ?>
                            <?php endif; ?>
                        </span>
                        <span><?= (int)$mine['progress'] ?> / <?= (int)($mine['level_end'] - $mine['level_start']) ?></span>
                    </div>
                    <div class="gw-tip">
                        <?= htmlspecialchars(t('growth.today_exp')) ?> +<?= (int)$mine['today_exp'] ?>
                        · <?= htmlspecialchars(sprintf(t('growth.daily_cap_tip'), lwLevelDailyCap())) ?>
                    </div>
                </div>
            </section>

            <div class="gw-grid">
                <div class="gw-kpi"><b><?= (int)($stats['post_count'] ?? 0) ?></b><span><?= htmlspecialchars(t('growth.stat_posts')) ?></span></div>
                <div class="gw-kpi"><b><?= (int)($stats['comment_count'] ?? 0) ?></b><span><?= htmlspecialchars(t('growth.stat_comments')) ?></span></div>
                <div class="gw-kpi"><b><?= (int)($stats['likes_received'] ?? 0) ?></b><span><?= htmlspecialchars(t('growth.stat_likes')) ?></span></div>
                <div class="gw-kpi"><b><?= (int)($stats['fans'] ?? 0) ?></b><span><?= htmlspecialchars(t('growth.stat_fans')) ?></span></div>
                <div class="gw-kpi"><b><?= (int)($stats['checkin_streak'] ?? 0) ?></b><span><?= htmlspecialchars(t('growth.stat_streak')) ?></span></div>
                <div class="gw-kpi"><b><?= (int)($stats['days_since_reg'] ?? 0) ?></b><span><?= htmlspecialchars(t('growth.stat_days')) ?></span></div>
            </div>

            <div class="gw-tabs" role="tablist">
                <button class="gw-tab active" role="tab" aria-selected="true" data-panel="p-tasks"><?= htmlspecialchars(t('growth.tab_tasks')) ?></button>
                <button class="gw-tab" role="tab" aria-selected="false" data-panel="p-ach"><?= htmlspecialchars(t('growth.tab_ach')) ?></button>
                <button class="gw-tab" role="tab" aria-selected="false" data-panel="p-rank"><?= htmlspecialchars(t('growth.tab_rank')) ?></button>
                <button class="gw-tab" role="tab" aria-selected="false" data-panel="p-rules"><?= htmlspecialchars(t('growth.tab_rules')) ?></button>
            </div>

            <!-- 每日任务 -->
            <div class="gw-panel active" id="p-tasks" role="tabpanel">
                <div class="gw-card">
                    <div class="gw-card-title">
                        <span><?= htmlspecialchars(t('growth.tab_tasks')) ?></span>
                        <span style="font-weight:400;font-size:13px;color:var(--text-secondary);">
                            <?= htmlspecialchars(sprintf(t('growth.tasks_done'), $taskDone, count($tasks))) ?>
                        </span>
                    </div>
                    <?php foreach ($tasks as $task): ?>
                    <a class="gw-task<?= $task['done'] ? ' done' : '' ?>" href="<?= htmlspecialchars($task['url']) ?>"
                       style="text-decoration:none;">
                        <span class="gw-task-ico"><?= $task['done'] ? '✓' : '○' ?></span>
                        <span class="gw-task-body">
                            <span class="gw-task-name"><?= htmlspecialchars($task['name']) ?></span>
                            <span class="gw-tip" style="display:block;margin:0;"><?= htmlspecialchars($task['desc']) ?></span>
                        </span>
                        <span class="gw-task-exp">+<?= (int)$task['exp'] ?> XP</span>
                    </a>
                    <?php endforeach; ?>
                </div>
            </div>

            <!-- 成就徽章 -->
            <div class="gw-panel" id="p-ach" role="tabpanel">
                <div class="gw-card">
                    <div class="gw-card-title">
                        <span><?= htmlspecialchars(t('growth.tab_ach')) ?></span>
                        <span style="font-weight:400;font-size:13px;color:var(--text-secondary);">
                            <?= htmlspecialchars(sprintf(t('growth.ach_progress'), $ach['unlocked'], $ach['total'])) ?>
                            （<?= (float)$ach['percent'] ?>%）
                        </span>
                    </div>
                    <?php foreach ($groups as $groupName => $items): ?>
                    <div style="margin-bottom:18px;">
                        <div style="font-size:13px;font-weight:600;color:var(--text-secondary);margin-bottom:10px;">
                            <?= htmlspecialchars($groupName) ?>
                        </div>
                        <div class="gw-ach-grid">
                            <?php foreach ($items as $it): ?>
                            <div class="gw-ach<?= $it['unlocked'] ? '' : ' locked' ?>">
                                <?php if ($it['unlocked']): ?>
                                <span class="gw-ach-tag"><?= htmlspecialchars(t('growth.unlocked')) ?></span>
                                <?php endif; ?>
                                <div class="gw-ach-ico"><?= $it['icon'] ?></div>
                                <div class="gw-ach-name"><?= htmlspecialchars($it['name']) ?></div>
                                <div class="gw-ach-desc"><?= htmlspecialchars($it['desc']) ?></div>
                                <?php if (!$it['unlocked'] && $it['need'] > 1): ?>
                                <div class="gw-ach-prog"><i style="width:<?= (float)$it['percent'] ?>%"></i></div>
                                <div class="gw-ach-desc"><?= (int)$it['current'] ?> / <?= (int)$it['need'] ?></div>
                                <?php endif; ?>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <!-- 经验榜 -->
            <div class="gw-panel" id="p-rank" role="tabpanel">
                <div class="gw-card">
                    <div class="gw-card-title"><span><?= htmlspecialchars(t('growth.tab_rank')) ?></span></div>
                    <?php if (!$board): ?>
                    <div class="empty-state"><p><?= htmlspecialchars(t('growth.no_rank')) ?></p></div>
                    <?php else: ?>
                    <?php foreach ($board as $b): ?>
                    <div class="gw-rank-row<?= $b['is_me'] ? ' me' : '' ?>">
                        <span class="gw-rank-no<?= $b['rank'] === 1 ? ' top1' : ($b['rank'] === 2 ? ' top2' : ($b['rank'] === 3 ? ' top3' : '')) ?>"><?= (int)$b['rank'] ?></span>
                        <img class="gw-rank-avatar" src="<?= htmlspecialchars($b['avatar']) ?>" alt="" loading="lazy" decoding="async"
                             onerror="this.src='/assets/images/default-avatar.svg'">
                        <span class="gw-rank-name">
                            <?= htmlspecialchars($b['nickname']) ?>
                            <span class="lw-level-badge lw-level-sm lv-tier-<?= $b['level'] >= 25 ? 6 : ($b['level'] >= 20 ? 5 : ($b['level'] >= 15 ? 4 : ($b['level'] >= 10 ? 3 : ($b['level'] >= 5 ? 2 : 1)))) ?>">
                                <span class="lw-level-num"><?= (int)$b['level'] ?></span>
                            </span>
                        </span>
                        <span class="gw-rank-exp"><?= (int)$b['exp'] ?> XP</span>
                    </div>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>

            <!-- 经验规则 -->
            <div class="gw-panel" id="p-rules" role="tabpanel">
                <div class="gw-card">
                    <div class="gw-card-title"><span><?= htmlspecialchars(t('growth.tab_rules')) ?></span></div>
                    <div class="gw-table-scroll">
                        <table style="width:100%;border-collapse:collapse;font-size:14px;">
                            <thead>
                                <tr style="text-align:left;color:var(--text-secondary);font-size:13px;">
                                    <th style="padding:8px 0;font-weight:600;"><?= htmlspecialchars(t('growth.rule_reason')) ?></th>
                                    <th style="padding:8px 0;font-weight:600;text-align:right;"><?= htmlspecialchars(t('growth.rule_exp')) ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($ruleRows as $r): ?>
                                <tr style="border-top:1px solid var(--border, #E9EEF4);">
                                    <td style="padding:10px 0;"><?= htmlspecialchars($r['label']) ?></td>
                                    <td style="padding:10px 0;text-align:right;color:var(--success, #2E8B57);font-weight:600;">+<?= (int)$r['exp'] ?> XP</td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <div class="gw-tip">
                        等级由累计经验推导，满 <?= (int)LW_LEVEL_MAX ?> 级封顶；每日最多累计 <?= lwLevelDailyCap() ?> 点经验。
                    </div>
                </div>
            </div>

        </div>
    </main>

    <?php
    $mobileNavActive = '';
    require __DIR__ . '/../includes/mobile_bottom_nav.php';
    ?>

    <div class="toast-container" id="toastContainer"></div>

    <script src="<?= asset_url('/assets/js/main.js') ?>?v=<?= asset_ver('/assets/js/main.js') ?>" defer></script>
    <script src="<?= asset_url('/assets/js/enhancements.js') ?>?v=<?= asset_ver('/assets/js/enhancements.js') ?>" defer></script>
    <script>
    (function () {
        'use strict';

        // ---- 选项卡切换（纯前端，切换不刷新、不重复请求） ----
        var tabs = document.querySelectorAll('.gw-tab');
        var panels = document.querySelectorAll('.gw-panel');
        function activate(id) {
            panels.forEach(function (p) { p.classList.toggle('active', p.id === id); });
            tabs.forEach(function (t) {
                var on = t.getAttribute('data-panel') === id;
                t.classList.toggle('active', on);
                t.setAttribute('aria-selected', on ? 'true' : 'false');
            });
            // 记住上次看的页签，刷新后不用重新点
            try { localStorage.setItem('lw_growth_tab', id); } catch (e) {}
        }
        tabs.forEach(function (t) {
            t.addEventListener('click', function () { activate(t.getAttribute('data-panel')); });
            // 键盘：左右方向键在页签间移动（WAI-ARIA tab 模式的推荐做法）
            t.addEventListener('keydown', function (e) {
                var list = Array.prototype.slice.call(tabs);
                var i = list.indexOf(t);
                if (e.key === 'ArrowRight') { e.preventDefault(); list[(i + 1) % list.length].focus(); list[(i + 1) % list.length].click(); }
                if (e.key === 'ArrowLeft') { e.preventDefault(); var p = (i - 1 + list.length) % list.length; list[p].focus(); list[p].click(); }
            });
        });
        try {
            var saved = localStorage.getItem('lw_growth_tab');
            if (saved && document.getElementById(saved)) { activate(saved); }
        } catch (e) {}

        // ---- 本轮新解锁的成就：服务端已结算完，这里只负责把好消息告诉用户 ----
        var NEWLY = <?= json_encode(array_map(function ($d) {
            return ['icon' => $d['icon'], 'name' => $d['name'], 'desc' => $d['desc']];
        }, $newlyUnlocked), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?>;
        NEWLY.forEach(function (a, i) {
            setTimeout(function () {
                if (window.App && App.showToast) {
                    App.showToast('解锁成就 ' + a.icon + ' ' + a.name + '：' + a.desc, 'success');
                }
            }, 500 + i * 800);
        });
    })();
    </script>
</body>
</html>
