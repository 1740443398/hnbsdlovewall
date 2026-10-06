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

/* ── 批次 D 新增：近 30 日趋势 / 分布 / 热力 / 待办（D1 D3 D4 D5 D6 D7 D8）────────
   全部从已读入的 posts / users / comments 现算，不新增数据表、不加定时任务。
   日期键固定补齐为 0，否则「某天没有记录」会在折线图上变成断点。 */

/** 近 N 日「日期 → 条数」聚合 */
function lwDashDaily(array $rows, int $days, string $field = 'created_at'): array
{
    $out = [];
    for ($i = $days - 1; $i >= 0; $i--) {
        $out[date('Y-m-d', strtotime("-$i days"))] = 0;
    }
    foreach ($rows as $r) {
        $d = substr((string)($r[$field] ?? ''), 0, 10);
        if ($d !== '' && isset($out[$d])) {
            $out[$d]++;
        }
    }
    return $out;
}

/** 折线 + 面积填充的迷你趋势图（纯 SVG，不引第三方图表库） */
function lwDashSpark(array $series, int $w = 560, int $h = 120): string
{
    $vals = array_values($series);
    $n = count($vals);
    if ($n < 2) {
        return '';
    }
    $max = max(1, max($vals));
    $stepX = $w / ($n - 1);
    $pts = [];
    foreach ($vals as $i => $v) {
        $pts[] = round($i * $stepX, 2) . ',' . round($h - ($v / $max) * ($h - 14) - 7, 2);
    }
    $line = implode(' ', $pts);
    // vector-effect:non-scaling-stroke 让线宽在 viewBox 被拉伸后依然均匀
    return '<svg class="spark" viewBox="0 0 ' . $w . ' ' . $h . '" preserveAspectRatio="none" aria-hidden="true">'
        . '<polygon class="spark-area" points="0,' . $h . ' ' . $line . ' ' . $w . ',' . $h . '"/>'
        . '<polyline class="spark-line" points="' . $line . '"/></svg>';
}

$days30 = [];
for ($i = 29; $i >= 0; $i--) {
    $days30[] = date('Y-m-d', strtotime("-$i days"));
}

$postTrend30 = lwDashDaily($posts, 30);
$commentTrend30 = lwDashDaily($comments, 30);
$visitTrend30 = [];
foreach ($days30 as $d) {
    $visitTrend30[$d] = (int)($daily[$d] ?? 0);
}

// 用户增长曲线 = 累计注册数（含窗口之前注册的老用户，否则曲线会从 0 起跳误导趋势）
$regDates = [];
foreach ($users as $u) {
    $d = substr((string)($u['created_at'] ?? ''), 0, 10);
    if ($d !== '') {
        $regDates[] = $d;
    }
}
sort($regDates);
$userCum30 = [];
foreach ($days30 as $d) {
    $c = 0;
    foreach ($regDates as $rd) {
        if ($rd <= $d) {
            $c++;
        }
    }
    $userCum30[$d] = $c;
}

// 分类分布（饼图）：分类名与 api/search/quick.php、ai_site_data.php 保持同一套文案
$catNames = [
    'lost_found' => '寻物/失物招领', 'study_help' => '学习求助', 'social_chat' => '交友闲聊',
    'confession' => '表白', 'school_info' => '校园打听', 'announcement' => '公告', 'other' => '其他',
];
$catColors = [
    'lost_found' => 'var(--macaron-peach)', 'study_help' => 'var(--macaron-sky)',
    'social_chat' => 'var(--macaron-mint)', 'confession' => 'var(--macaron-pink)',
    'school_info' => 'var(--macaron-lilac)', 'announcement' => 'var(--accent)',
    'other' => 'var(--text-muted)',
];
$catDist = [];
foreach ($posts as $p) {
    $k = (string)($p['category'] ?? 'other');
    $catDist[$k] = ($catDist[$k] ?? 0) + 1;
}
arsort($catDist);
$catTotal = max(1, array_sum($catDist));

// 活跃时段热力：按发布小时聚合（帖子 + 评论一起算）
$hourHeat = array_fill(0, 24, 0);
foreach ([$posts, $comments] as $rows) {
    foreach ($rows as $r) {
        $ts = (string)($r['created_at'] ?? '');
        if ($ts === '') {
            continue;
        }
        $h = (int)substr($ts, 11, 2);
        if ($h >= 0 && $h < 24) {
            $hourHeat[$h]++;
        }
    }
}
$hourHeatMax = max(1, max($hourHeat));

// 待办中心（D8）
$pendingTitles = count(array_filter($fs->read('title_requests') ?: [], function ($r) {
    return ($r['status'] ?? '') === 'pending';
}));
$pendingQq = count(array_filter($fs->read('qq_change_requests') ?: [], function ($r) {
    return ($r['status'] ?? '') === 'pending';
}));
// 注意：post_reports 表**没有处理状态字段**（用户上报后即一条记录），
// 所以这里只能给出「举报总数」，不要臆造 status 去筛「未处理」。
$totalReports = count($fs->read('post_reports') ?: []);
$todoTotal = $pendingPosts + $pendingTitles + $pendingQq + $totalReports;

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

// 系统信息：JSON 表数量与行数、目录可写性
$dataDir = __DIR__ . '/../data';
$jsonTables = is_dir($dataDir) ? (glob($dataDir . '/*.json') ?: []) : [];
$jsonTotalRows = 0;
$tableRows = [];
foreach ($jsonTables as $path) {
    $tb = basename($path, '.json');
    $rows = count($fs->read($tb));
    $tableRows[$tb] = $rows;
    $jsonTotalRows += $rows;
}
$dirChecks = [
    'data/ 数据目录' => is_writable($dataDir),
    'uploads/ 上传目录' => is_writable(__DIR__ . '/../uploads'),
];
// 系统信息卡片用的两个值（此前漏定义，页面上会报 Undefined variable）
$jsonTableCount = count($jsonTables);
$phpVersion = PHP_VERSION;

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
    <a href="/admin/backup.php" class="stat-card success" style="text-decoration:none;color:inherit;display:block;">
        <div class="stat-icon"><svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="18" height="18" rx="2"/><polyline points="7 13 12 18 17 8"/><circle cx="15.5" cy="8.5" r="1"/></svg></div>
        <div class="stat-info">
            <div class="stat-value"><?= $jsonTableCount ?></div>
            <div class="stat-label">可备份数据表（<?= $jsonTotalRows ?> 行）</div>
        </div>
        <div class="stat-change positive">备份提醒：定期留档 ></div>
    </a>
</div>

<div class="dashboard-grid">
    <div class="dashboard-main">
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
            <h3>当前在线 <small id="onlineDot" style="font-weight:400;color:var(--text-secondary);">读取中…</small></h3>            <div id="onlineSummary" style="display:flex;gap:10px;margin-bottom:10px;">
                <div style="flex:1;background:var(--bg);border-radius:var(--radius-sm);padding:10px 12px;">
                    <div style="font-size:22px;font-weight:700;" id="onlineTotal">-</div>
                    <div style="font-size:12px;color:var(--text-secondary);">在线总数</div>
                </div>
                <div style="flex:1;background:var(--bg);border-radius:var(--radius-sm);padding:10px 12px;">
                    <div style="font-size:22px;font-weight:700;" id="onlineMembers">-</div>
                    <div style="font-size:12px;color:var(--text-secondary);">登录用户</div>
                </div>
                <div style="flex:1;background:var(--bg);border-radius:var(--radius-sm);padding:10px 12px;">
                    <div style="font-size:22px;font-weight:700;" id="onlineGuests">-</div>
                    <div style="font-size:12px;color:var(--text-secondary);">游客</div>
                </div>
            </div>
            <div id="onlineList" style="max-height:220px;overflow-y:auto;font-size:13px;">
                <div style="text-align:center;padding:18px;color:var(--text-secondary);">读取中…</div>
            </div>
            <p style="margin-top:10px;font-size:11px;color:var(--text-secondary);line-height:1.5;">
                实时刷新（每 5 秒）。用户关闭页面时会立刻下线，异常断线最多 2 分钟从名单移除。
                游客仅统计人数，不记录其 IP 与会话。
            </p>
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

<!-- 批次 D：近 30 日趋势（D1 覆盖今日/近7日/近30日 · D3 访问趋势 · D4 内容量趋势 · D5 用户增长曲线） -->
<div class="dash-trends">
    <div class="chart-card">
        <h3>访问趋势 <small>近 30 日 · 峰值 <?= (int)max(array_values($visitTrend30)) ?></small></h3>
        <?= lwDashSpark($visitTrend30) ?>
        <div class="spark-foot">
            <span><?= substr($days30[0], 5) ?></span>
            <span>今日 <?= (int)($daily[date('Y-m-d')] ?? 0) ?></span>
            <span><?= substr(end($days30), 5) ?></span>
        </div>
    </div>
    <div class="chart-card">
        <h3>用户增长 <small>累计注册 <?= count($users) ?> 人</small></h3>
        <?= lwDashSpark($userCum30) ?>
        <div class="spark-foot">
            <span><?= substr($days30[0], 5) ?></span>
            <span>近 30 日新增 <?= count(array_filter($regDates, function ($d) use ($days30) { return $d >= $days30[0]; })) ?></span>
            <span><?= substr(end($days30), 5) ?></span>
        </div>
    </div>
    <div class="chart-card">
        <h3>发帖趋势 <small>近 30 日</small></h3>
        <?= lwDashSpark($postTrend30) ?>
        <div class="spark-foot">
            <span><?= substr($days30[0], 5) ?></span>
            <span>今日 <?= (int)($postTrend30[date('Y-m-d')] ?? 0) ?></span>
            <span><?= substr(end($days30), 5) ?></span>
        </div>
    </div>
</div>

<!-- 批次 D：分类分布饼图 / 活跃时段热力 / 待办中心（D6 D7 D8） -->
<div class="dash-row3">
    <div class="chart-card">
        <h3>分类分布 <small>共 <?= array_sum($catDist) ?> 帖</small></h3>
        <?php if (empty($catDist)): ?>
        <p class="dash-empty">暂无帖子</p>
        <?php else: ?>
        <div class="donut-wrap">
            <div class="donut" style="background: conic-gradient(<?php
                $acc = 0;
                $stops = [];
                foreach ($catDist as $k => $c) {
                    $s = $acc / $catTotal * 100;
                    $acc += $c;
                    $e = $acc / $catTotal * 100;
                    $stops[] = ($catColors[$k] ?? 'var(--text-muted)') . ' ' . round($s, 3) . '% ' . round($e, 3) . '%';
                }
                echo implode(', ', $stops);
            ?>);"><span class="donut-hole"><?= array_sum($catDist) ?></span></div>
            <ul class="donut-legend">
                <?php foreach ($catDist as $k => $c): ?>
                <li>
                    <i style="background:<?= $catColors[$k] ?? 'var(--text-muted)' ?>"></i>
                    <span><?= htmlspecialchars($catNames[$k] ?? $k) ?></span>
                    <b><?= $c ?></b>
                    <em><?= round($c / $catTotal * 100, 1) ?>%</em>
                </li>
                <?php endforeach; ?>
            </ul>
        </div>
        <?php endif; ?>
    </div>

    <div class="chart-card">
        <h3>活跃时段 <small>按发布小时统计</small></h3>
        <div class="heat">
            <?php foreach ($hourHeat as $h => $c): ?>
            <span class="heat-cell" style="--v:<?= $c === 0 ? 0.06 : round(0.16 + 0.84 * ($c / $hourHeatMax), 3) ?>"
                  title="<?= $h ?>:00 · <?= $c ?> 条"><?= $h % 3 === 0 ? $h : '' ?></span>
            <?php endforeach; ?>
        </div>
        <p class="dash-hint">颜色越深 = 该时段发布越密集（峰值 <?= $hourHeatMax ?> 条/小时）。</p>
    </div>

    <div class="chart-card">
        <h3>待办中心 <small><?= $todoTotal > 0 ? $todoTotal . ' 项待处理' : '暂无待办' ?></small></h3>
        <ul class="todo-list">
            <li><a href="/admin/posts.php?status=pending"><span>待审核帖子</span><b class="<?= $pendingPosts > 0 ? 'hot' : '' ?>"><?= $pendingPosts ?></b></a></li>
            <li><a href="/admin/title_requests.php"><span>待审头衔申请</span><b class="<?= $pendingTitles > 0 ? 'hot' : '' ?>"><?= $pendingTitles ?></b></a></li>
            <li><a href="/admin/qq_changes.php"><span>待审 QQ 换绑</span><b class="<?= $pendingQq > 0 ? 'hot' : '' ?>"><?= $pendingQq ?></b></a></li>
            <li><a href="/admin/reports.php"><span>举报记录</span><b class="<?= $totalReports > 0 ? 'hot' : '' ?>"><?= $totalReports ?></b></a></li>
        </ul>
        <p class="dash-hint">举报表暂无「已处理」状态字段，此处显示累计举报数。</p>
    </div>
</div>

<div class="chart-card sys-info">
    <h3>系统信息</h3>
    <div class="sys-grid">
        <div class="sys-item"><span class="sys-label">PHP 版本</span><span class="sys-value"><?= htmlspecialchars($phpVersion) ?></span></div>
        <div class="sys-item"><span class="sys-label">JSON 数据表</span><span class="sys-value"><?= $jsonTableCount ?> 张 / <?= $jsonTotalRows ?> 行</span></div>
        <div class="sys-item"><span class="sys-label">站点名称</span><span class="sys-value"><?= htmlspecialchars(SITE_NAME) ?></span></div>
        <?php foreach ($dirChecks as $dn => $ok): ?>
        <div class="sys-item">
            <span class="sys-label"><?= htmlspecialchars($dn) ?></span>
            <span class="sys-value <?= $ok ? 'ok' : 'bad' ?>"><?= $ok ? '可写' : '不可写' ?></span>
        </div>
        <?php endforeach; ?>
    </div>
    <details class="sys-details">
        <summary>各表行数明细</summary>
        <div class="sys-table">
            <?php foreach ($tableRows as $tb => $rows): ?>
            <div class="sys-item"><span class="sys-label"><?= htmlspecialchars($tb) ?>.json</span><span class="sys-value"><?= $rows ?> 行</span></div>
            <?php endforeach; ?>
        </div>
    </details>
</div>

<style>
/* ---------- 批次 D 新增：趋势图 / 饼图 / 热力 / 待办 ---------- */
.dash-trends,
.dash-row3 {
    display: grid;
    grid-template-columns: repeat(3, minmax(0, 1fr));
    gap: 20px;
    margin-bottom: 24px;
}
@media (max-width: 1100px) {
    .dash-trends,
    .dash-row3 { grid-template-columns: minmax(0, 1fr); }
}
.chart-card h3 small { font-weight: 400; color: var(--text-secondary); font-size: 12px; }
/* 迷你折线图：preserveAspectRatio=none 拉伸铺满，non-scaling-stroke 保证线宽不被拉粗 */
.spark { width: 100%; height: 110px; display: block; margin-top: 8px; }
.spark-line { fill: none; stroke: var(--primary); stroke-width: 2.5; vector-effect: non-scaling-stroke; stroke-linejoin: round; stroke-linecap: round; }
.spark-area { fill: var(--primary); opacity: .12; }
.spark-foot { display: flex; justify-content: space-between; font-size: 11px; color: var(--text-secondary); margin-top: 6px; }
/* 饼图：conic-gradient 画环 + 中心挖孔，纯 CSS 无依赖 */
.donut-wrap { display: flex; align-items: center; gap: 18px; flex-wrap: wrap; }
.donut { width: 132px; height: 132px; border-radius: 50%; flex: 0 0 auto; display: flex; align-items: center; justify-content: center; }
.donut-hole { width: 66px; height: 66px; border-radius: 50%; background: var(--card-bg); display: flex; align-items: center; justify-content: center; font-size: 18px; font-weight: 700; color: var(--text); }
.donut-legend { list-style: none; margin: 0; padding: 0; flex: 1; min-width: 160px; }
.donut-legend li { display: flex; align-items: center; gap: 8px; padding: 4px 0; font-size: 12.5px; }
.donut-legend i { width: 10px; height: 10px; border-radius: 3px; flex: 0 0 auto; }
.donut-legend span { flex: 1; min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.donut-legend b { font-variant-numeric: tabular-nums; }
.donut-legend em { font-style: normal; color: var(--text-secondary); width: 46px; text-align: right; font-variant-numeric: tabular-nums; }
/* 活跃时段热力：24 格两行，深浅由 --v 控制 */
.heat { display: grid; grid-template-columns: repeat(12, minmax(0, 1fr)); gap: 3px; margin-top: 10px; }
.heat-cell { aspect-ratio: 1 / 1; border-radius: 4px; background: var(--primary); opacity: var(--v, .1); display: flex; align-items: flex-end; justify-content: center; font-size: 9px; color: #fff; }
.dash-hint { font-size: 11px; color: var(--text-secondary); margin-top: 8px; line-height: 1.5; }
.dash-empty { text-align: center; padding: 24px; color: var(--text-secondary); font-size: 13px; }
/* 待办中心 */
.todo-list { list-style: none; margin: 0; padding: 0; }
.todo-list li + li { border-top: 1px solid var(--border); }
.todo-list a { display: flex; align-items: center; justify-content: space-between; gap: 10px; padding: 10px 2px; text-decoration: none; color: var(--text); font-size: 13px; }
.todo-list a:hover { color: var(--primary); }
.todo-list b { font-variant-numeric: tabular-nums; color: var(--text-secondary); }
.todo-list b.hot { color: #fff; background: var(--danger); border-radius: 999px; padding: 1px 8px; font-size: 12px; }

.dashboard-grid {
    display: grid;
    grid-template-columns: 1fr 360px;
    gap: 20px;
    margin-bottom: 24px;
    /* 关键：两列各自按内容高度排列，**不要让网格把卡片拉满整行**。
       之前缺少这一条时，左侧「近7日发帖趋势」被拉到与右侧整列等高（实测 1567px），
       而柱状图只占 240px —— 卡片下方一千多像素全是空白，非常难看。 */
    align-items: start;
}
/* 左列：卡片纵向堆叠，互相之间只留 20px，不参与任何拉伸 */
.dashboard-main {
    display: flex;
    flex-direction: column;
    gap: 20px;
    min-width: 0;
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
.sys-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(220px, 1fr));
    gap: 12px;
}
.sys-item {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 10px 14px;
    background: var(--bg);
    border-radius: var(--radius-sm);
    font-size: 13px;
}
.sys-label { color: var(--text-secondary); }
.sys-value { font-weight: 600; }
.sys-value.ok { color: var(--success); }
.sys-value.bad { color: var(--danger); font-weight: 700; }
.sys-details { margin-top: 16px; }
.sys-details summary {
    cursor: pointer;
    font-size: 13px;
    color: var(--text-secondary);
    padding: 6px 0;
}
.sys-table {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(220px, 1fr));
    gap: 8px;
    margin-top: 10px;
}

/* 「当前在线」卡片的实时指示：一颗呼吸绿点，让"在刷新"这件事看得见 */
.online-live-dot {
    display: inline-block;
    width: 7px;
    height: 7px;
    margin-right: 5px;
    border-radius: 50%;
    background: var(--success, #2E8B57);
    vertical-align: middle;
    box-shadow: 0 0 0 0 rgba(46, 139, 87, .55);
    animation: onlinePulse 2s ease-out infinite;
}
@keyframes onlinePulse {
    0%   { box-shadow: 0 0 0 0 rgba(46, 139, 87, .55); }
    70%  { box-shadow: 0 0 0 6px rgba(46, 139, 87, 0); }
    100% { box-shadow: 0 0 0 0 rgba(46, 139, 87, 0); }
}
@media (prefers-reduced-motion: reduce) {
    .online-live-dot { animation: none; }
}
</style>

<script>
// ===== 当前在线：每 5 秒刷新一次（实时），页面隐藏时暂停（省电、少打扰） =====
(function () {
    var timer = null;
    var REFRESH_MS = 5000;

    function agoText(s) {
        if (s < 15) return '刚刚活跃';
        if (s < 60) return s + ' 秒前活跃';
        if (s < 3600) return Math.floor(s / 60) + ' 分钟前活跃';
        return Math.floor(s / 3600) + ' 小时前活跃';
    }

    function render(data) {
        document.getElementById('onlineTotal').textContent = data.total;
        document.getElementById('onlineMembers').textContent = data.members;
        document.getElementById('onlineGuests').textContent = data.guests;
        // 精确到秒：5 秒一轮的刷新，只写到分钟看不出在动
        document.getElementById('onlineDot').innerHTML =
            '<span class="online-live-dot" aria-hidden="true"></span>实时 · 更新于 ' +
            new Date().toTimeString().substring(0, 8);

        var list = document.getElementById('onlineList');
        if (!data.users || data.users.length === 0) {
            list.innerHTML = '<div style="text-align:center;padding:18px;color:var(--text-secondary);">当前没有登录用户在线' +
                (data.guests > 0 ? '（仅 ' + data.guests + ' 位游客）' : '') + '</div>';
            return;
        }
        list.innerHTML = data.users.map(function (u) {
            var isAdmin = (u.role === 'admin' || u.role === 'super_admin');
            return '<div style="display:flex;align-items:center;gap:8px;padding:7px 0;border-bottom:1px solid var(--border);">' +
                '<span style="width:8px;height:8px;border-radius:50%;background:var(--success);flex-shrink:0;"></span>' +
                '<span style="flex:1;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">' +
                esc(u.nickname || ('用户' + u.id)) + '</span>' +
                (isAdmin ? '<span class="badge badge-info">管理员</span>' : '') +
                '<span style="font-size:11px;color:var(--text-secondary);flex-shrink:0;">' + agoText(u.seconds_ago) + '</span>' +
                '</div>';
        }).join('');
        if (data.guests > 0) {
            list.innerHTML += '<div style="display:flex;align-items:center;gap:8px;padding:7px 0;color:var(--text-secondary);">' +
                '<span style="width:8px;height:8px;border-radius:50%;background:var(--border);flex-shrink:0;"></span>' +
                '<span>另有 ' + data.guests + ' 位游客在线</span></div>';
        }
    }

    function loadOnline() {
        fetch('/api/admin/dashboard.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: new URLSearchParams({ csrf_token: CSRF_TOKEN, action: 'online_users' })
        })
        .then(function (r) { return r.json(); })
        .then(function (res) { if (res && res.success) render(res.data); })
        .catch(function () { /* 网络抖动忽略，下一轮自动重试 */ });
    }

    function start() {
        loadOnline();
        if (timer) clearInterval(timer);
        timer = setInterval(loadOnline, REFRESH_MS);
    }

    document.addEventListener('visibilitychange', function () {
        if (document.hidden) {
            if (timer) { clearInterval(timer); timer = null; }
        } else {
            start();
        }
    });

    start();
})();
</script>

<?php adminFooter(); ?>
