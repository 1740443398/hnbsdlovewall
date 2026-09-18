<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/layout.php';

$adminUser = requireAdmin();
$adminUser = checkBanned($adminUser);
$csrfToken = generateCSRFToken();

$fs = getFS();

$users = $fs->read('users') ?: [];
$posts = $fs->read('posts') ?: [];
$comments = $fs->read('comments') ?: [];

$today = date('Y-m-d');
$yesterday = date('Y-m-d', strtotime('-1 day'));

$totalUsers = count($users);
$todayUsers = count(array_filter($users, function($u) use ($today) {
    return isset($u['created_at']) && strpos($u['created_at'], $today) === 0;
}));

$totalPosts = count($posts);
$todayPosts = count(array_filter($posts, function($p) use ($today) {
    return isset($p['created_at']) && strpos($p['created_at'], $today) === 0;
}));

$pendingPosts = count(array_filter($posts, function($p) {
    return ($p['status'] ?? '') === 'pending';
}));

$bannedUsers = count(array_filter($users, function($u) {
    return !empty($u['is_banned']);
}));

$totalComments = count($comments);
$todayComments = count(array_filter($comments, function($c) use ($today) {
    return isset($c['created_at']) && strpos($c['created_at'], $today) === 0;
}));

$recent7Days = [];
for ($i = 6; $i >= 0; $i--) {
    $date = date('Y-m-d', strtotime("-$i days"));
    $count = count(array_filter($posts, function($p) use ($date) {
        return isset($p['created_at']) && strpos($p['created_at'], $date) === 0;
    }));
    $recent7Days[] = ['date' => $date, 'count' => $count];
}

// 访问统计：总访问量 / 今日访问 / 近7日访问趋势
$visitStats = $fs->findOne('visit_stats', ['id' => 1]);
$totalVisits = (int)($visitStats['total'] ?? 0);
$todayVisits = (int)($visitStats['today'] ?? 0);
$daily = is_array(($visitStats['daily'] ?? null)) ? $visitStats['daily'] : [];
$visitTrend = [];
for ($i = 6; $i >= 0; $i--) {
    $d = date('Y-m-d', strtotime("-$i days"));
    $visitTrend[] = ['date' => $d, 'count' => (int)($daily[$d] ?? 0)];
}

// 每用户访问次数排行（Top 5）
$userVisits = array_filter($users, function($u) {
    return !empty($u['visit_count']);
});
usort($userVisits, function($a, $b) {
    return (int)($b['visit_count'] ?? 0) - (int)($a['visit_count'] ?? 0);
});
$topVisitors = array_slice($userVisits, 0, 5);

$latestPosts = $fs->orderBy('posts', 'created_at', 'DESC');
$latestPosts = array_slice($latestPosts, 0, 5);

$latestUsers = $fs->orderBy('users', 'created_at', 'DESC');
$latestUsers = array_slice($latestUsers, 0, 5);

adminHeader('数据看板', $adminUser, $csrfToken);
?>

<div class="stats-grid">
    <div class="stat-card">
        <div class="stat-icon"><svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg></div>
        <div class="stat-info">
            <div class="stat-value"><?= $totalUsers ?></div>
            <div class="stat-label">注册用户</div>
        </div>
        <div class="stat-change positive">+<?= $todayUsers ?> 今日</div>
    </div>
    <div class="stat-card">
        <div class="stat-icon"><svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="8" y1="13" x2="16" y2="13"/><line x1="8" y1="17" x2="16" y2="17"/></svg></div>
        <div class="stat-info">
            <div class="stat-value"><?= $totalPosts ?></div>
            <div class="stat-label">总帖子数</div>
        </div>
        <div class="stat-change positive">+<?= $todayPosts ?> 今日</div>
    </div>
    <div class="stat-card info">
        <div class="stat-icon"><svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg></div>
        <div class="stat-info">
            <div class="stat-value"><?= $totalComments ?></div>
            <div class="stat-label">总评论数</div>
        </div>
        <div class="stat-change positive">+<?= $todayComments ?> 今日</div>
    </div>
    <div class="stat-card warning">
        <div class="stat-icon"><svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg></div>
        <div class="stat-info">
            <div class="stat-value"><?= $pendingPosts ?></div>
            <div class="stat-label">待审核</div>
        </div>
    </div>
    <div class="stat-card danger">
        <div class="stat-icon"><svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="4.93" y1="4.93" x2="19.07" y2="19.07"/></svg></div>
        <div class="stat-info">
            <div class="stat-value"><?= $bannedUsers ?></div>
            <div class="stat-label">封禁用户</div>
        </div>
    </div>
    <div class="stat-card info">
        <div class="stat-icon"><svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg></div>
        <div class="stat-info">
            <div class="stat-value"><?= number_format($totalVisits) ?></div>
            <div class="stat-label">总访问量</div>
        </div>
        <div class="stat-change positive">+<?= $todayVisits ?> 今日</div>
    </div>
    <div class="stat-card warning">
        <div class="stat-icon"><svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 12a9 9 0 1 1-9-9"/><polyline points="12 7 12 12 15 14"/><line x1="22" y1="6" x2="16" y2="12"/></svg></div>
        <div class="stat-info">
            <div class="stat-value"><?= $todayVisits ?></div>
            <div class="stat-label">今日访问</div>
        </div>
    </div>
</div>

<div class="dashboard-grid">
    <div class="chart-card">
        <h3>近7日发帖趋势</h3>
        <div class="bar-chart">
            <?php foreach ($recent7Days as $day): ?>
            <div class="bar-item">
                <div class="bar-value"><?= $day['count'] ?></div>
                <div class="bar-fill" style="height: <?= max(4, ($day['count'] / max(1, max(array_column($recent7Days, 'count')))) * 100) ?>%"></div>
                <div class="bar-label"><?= substr($day['date'], 5) ?></div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>

    <div class="dashboard-side">
        <div class="chart-card">
            <h3>快捷操作</h3>
            <div style="display:flex;flex-wrap:wrap;gap:8px;padding:4px 0;">
                <a href="/admin/posts.php?status=pending" class="btn btn-warning btn-sm">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
                    审核帖子 (<?= $pendingPosts ?>)
                </a>
                <a href="/admin/users.php" class="btn btn-outline btn-sm">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/></svg>
                    用户管理
                </a>
                <a href="/admin/settings.php" class="btn btn-outline btn-sm">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1 0 2.83 2 2 0 0 1-2.83 0l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-2 2 2 2 0 0 1-2-2v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83 0 2 2 0 0 1 0-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1-2-2 2 2 0 0 1 2-2h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 0-2.83 2 2 0 0 1 2.83 0l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 2-2 2 2 0 0 1 2 2v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 0 2 2 0 0 1 0 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 2 2 2 2 0 0 1-2 2h-.09a1.65 1.65 0 0 0-1.51 1z"/></svg>
                    站点设置
                </a>
                <a href="/admin/announcements.php" class="btn btn-outline btn-sm">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 11l18-5v12L3 14v-3z"/><path d="M11.6 16.8a3 3 0 1 1-5.8-1.6"/></svg>
                    公告管理
                </a>
                <a href="/admin/ip_blacklist.php" class="btn btn-outline btn-sm">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="4.93" y1="4.93" x2="19.07" y2="19.07"/></svg>
                    IP黑名单
                </a>
            </div>
        </div>

        <div class="chart-card">
            <h3>最近帖子</h3>
            <div style="max-height:220px;overflow-y:auto;">
                <?php if (empty($latestPosts)): ?>
                <div style="text-align:center;padding:24px;color:var(--text-secondary);">
                    <div style="font-size:32px;margin-bottom:8px;opacity:0.3;">📝</div>
                    <p style="font-size:13px;">暂无帖子</p>
                </div>
                <?php else: ?>
                <?php foreach ($latestPosts as $lp): ?>
                <a href="/pages/post_detail.php?id=<?= $lp['id'] ?>" style="display:flex;align-items:center;justify-content:space-between;padding:8px 0;border-bottom:1px solid var(--border);text-decoration:none;color:var(--text);">
                    <span style="flex:1;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;font-size:13px;"><?= htmlspecialchars(mb_substr($lp['title'] ?: $lp['content'], 0, 30)) ?></span>
                    <span style="font-size:11px;color:var(--text-secondary);white-space:nowrap;margin-left:8px;"><?= substr($lp['created_at'], 0, 16) ?></span>
                </a>
                <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>

        <div class="chart-card">
            <h3>新注册用户</h3>
            <div style="max-height:220px;overflow-y:auto;">
                <?php if (empty($latestUsers)): ?>
                <div style="text-align:center;padding:24px;color:var(--text-secondary);">
                    <div style="font-size:32px;margin-bottom:8px;opacity:0.3;">👤</div>
                    <p style="font-size:13px;">暂无用户</p>
                </div>
                <?php else: ?>
                <?php foreach ($latestUsers as $lu): ?>
                <div style="display:flex;align-items:center;gap:10px;padding:8px 0;border-bottom:1px solid var(--border);">
                    <img src="<?= htmlspecialchars($lu['avatar']) ?>" style="width:28px;height:28px;border-radius:50%;" onerror="this.style.display='none'">
                    <span style="font-size:13px;flex:1;min-width:0;"><?= htmlspecialchars($lu['nickname'] ?: 'QQ:'.$lu['qq']) ?></span>
                    <span style="font-size:11px;color:var(--text-secondary);white-space:nowrap;"><?= substr($lu['created_at'], 0, 10) ?></span>
                </div>
                <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>

        <div class="chart-card">
            <h3>访问次数排行 <small style="font-weight:400;color:var(--text-secondary);">每用户访问次数</small></h3>
            <div style="max-height:220px;overflow-y:auto;">
                <?php if (empty($topVisitors)): ?>
                <div style="text-align:center;padding:24px;color:var(--text-secondary);">
                    <div style="font-size:32px;margin-bottom:8px;opacity:0.3;">👀</div>
                    <p style="font-size:13px;">暂无访问记录（用户登录访问首页后计入）</p>
                </div>
                <?php else: ?>
                <div class="visit-rank-row" style="display:flex;align-items:center;gap:10px;padding:8px 0;border-bottom:1px solid var(--border);">
                    <span style="width:22px;height:22px;border-radius:50%;background:var(--primary-light);color:var(--primary);font-weight:700;font-size:12px;display:flex;align-items:center;justify-content:center;flex-shrink:0;">★</span>
                    <span style="font-size:13px;flex:1;min-width:0;font-weight:600;">总访问 <?= number_format($totalVisits) ?> 次</span>
                    <span style="font-size:11px;color:var(--text-secondary);">今日 +<?= $todayVisits ?></span>
                </div>
                <?php foreach ($topVisitors as $idx => $tv): ?>
                <div style="display:flex;align-items:center;gap:10px;padding:8px 0;border-bottom:1px solid var(--border);">
                    <span style="width:22px;height:22px;border-radius:50%;background:<?= $idx === 0 ? 'var(--warning)' : 'var(--bg)' ?>;color:<?= $idx === 0 ? '#fff' : 'var(--text-secondary)' ?>;font-weight:700;font-size:12px;display:flex;align-items:center;justify-content:center;flex-shrink:0;"><?= $idx + 1 ?></span>
                    <span style="font-size:13px;flex:1;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"><?= htmlspecialchars($tv['nickname'] ?: 'QQ:'.$tv['qq']) ?></span>
                    <span style="font-size:12px;color:var(--primary);font-weight:700;"><?= (int)($tv['visit_count'] ?? 0) ?> 次</span>
                </div>
                <?php endforeach; ?>
                <a href="/admin/users.php" style="display:block;text-align:center;padding:10px;font-size:12px;color:var(--primary);text-decoration:none;">查看全部用户 ></a>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<style>
.dashboard-grid {
    display: grid;
    grid-template-columns: 1fr 360px;
    gap: 20px;
    margin-bottom: 24px;
}
.dashboard-side {
    display: flex;
    flex-direction: column;
    gap: 20px;
}
@media (max-width: 1000px) {
    .dashboard-grid {
        grid-template-columns: 1fr;
    }
}
</style>

<?php adminFooter(); ?>
