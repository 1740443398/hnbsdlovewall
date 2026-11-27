<?php
/**
 * 后台：抽奖管理
 * 创建/删除抽奖，或立即开奖（到点也会自动开）。
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../includes/lottery.php';
require_once __DIR__ . '/layout.php';

$adminUser = requireAdmin();
$adminUser = checkBanned($adminUser);
$csrfToken = generateCSRFToken();

if (!checkPermission($adminUser, 'manage_settings')) {
    http_response_code(403);
    die('403 Forbidden - 权限不足');
}

$msg = ''; $msgType = 'ok';
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    if (!isset($_POST['csrf_token']) || !verifyCSRFToken($_POST['csrf_token'])) {
        $msg = '令牌失效，请刷新后重试'; $msgType = 'error';
    } else {
        $act = (string) ($_POST['act'] ?? '');
        if ($act === 'create') {
            /* 封面上传：可选。只接受常见图片格式，文件名随机化，路径写相对地址 */
            $__coverPath = ''; $__coverErr = false;
            if (!empty($_FILES['cover_image_file']['name'])) {
                $__f = $_FILES['cover_image_file'];
                $__ext = strtolower(pathinfo((string) $__f['name'], PATHINFO_EXTENSION));
                $__allow = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
                $__max = 3 * 1024 * 1024;
                $__uploadDir = defined('UPLOAD_IMAGES_DIR') ? UPLOAD_IMAGES_DIR : (__DIR__ . '/../uploads/images');
                $__uploadDir = rtrim($__uploadDir, '/\\') . '/lottery';
                if (!in_array($__ext, $__allow, true) || (int) ($__f['size'] ?? 0) > $__max) {
                    $msg = '封面图需为 jpg/png/gif/webp 且不超过 3MB'; $msgType = 'error'; $__coverErr = true;
                } else {
                    if (!is_dir($__uploadDir)) { @mkdir($__uploadDir, 0755, true); }
                    $__name = 'lot-' . date('Ymd') . '-' . bin2hex(random_bytes(6)) . '.' . $__ext;
                    $__dest = $__uploadDir . '/' . $__name;
                    if (is_uploaded_file((string) $__f['tmp_name']) && @move_uploaded_file((string) $__f['tmp_name'], $__dest)) {
                        $__coverPath = '/uploads/images/lottery/' . $__name;
                    }
                }
            }
            $r = $__coverErr ? ['ok' => false] : lwLotteryCreate((int) $adminUser['id'], [
                'title'        => $_POST['title'] ?? '',
                'prize'        => $_POST['prize'] ?? '',
                'prize_count'  => $_POST['prize_count'] ?? 1,
                'description'  => $_POST['description'] ?? '',
                'draw_at'      => $_POST['draw_at'] ?? '',
                'cover_image'  => $__coverPath,
                'rule_follow'  => $_POST['rule_follow'] ?? 0,
                'rule_like'    => $_POST['rule_like'] ?? 0,
                'rule_comment' => $_POST['rule_comment'] ?? 0,
                'rule_repost'  => $_POST['rule_repost'] ?? 0,
            ]);
            if (empty($r['ok'])) { $msg = (string) ($r['error'] ?? '创建失败'); $msgType = 'error'; }
            else { $msg = '抽奖已创建（#' . (int) $r['id'] . '）'; }
        } elseif ($act === 'draw') {
            $r = lwLotteryDraw((int) ($_POST['id'] ?? 0));
            $msg = empty($r['ok']) ? (string) ($r['error'] ?? '开奖失败') : ('已开奖，中奖 ' . count((array) ($r['winners'] ?? [])) . ' 人');
            if (empty($r['ok'])) { $msgType = 'error'; }
        } elseif ($act === 'pm_entries') {
            $lid = (int) ($_POST['lottery_id'] ?? 0);
            $text = trim((string) ($_POST['pm_text'] ?? ''));
            $adminQq = (string) ($adminUser['qq'] ?? '');
            if ($lid <= 0 || $text === '') {
                $msg = '请选择抽奖并填写私信内容'; $msgType = 'error';
            } elseif ($adminQq === '') {
                $msg = '管理员账号缺少 QQ 号，无法发私信'; $msgType = 'error';
            } else {
                /* 变量替换：{title} {prize} {prize_count} {draw_at} —— 群发文案不用手抄奖品信息 */
                $__lot = lwLotteryGet($lid);
                $__scope = (string) ($_POST['pm_scope'] ?? 'all');
                $__winnerIds = array_map('intval', (array) json_decode((string) ($__lot['winners'] ?? '[]'), true));
                $__pick = function (int $uid) use ($__scope, $__winnerIds): bool {
                    $isWin = in_array($uid, $__winnerIds, true);
                    if ($__scope === 'winner') { return $isWin; }
                    if ($__scope === 'loser') { return !$isWin; }
                    return true;
                };
                $text = strtr($text, [
                    '{title}'       => (string) ($__lot['title'] ?? ''),
                    '{prize}'       => (string) ($__lot['prize'] ?? ''),
                    '{prize_count}' => (string) (int) ($__lot['prize_count'] ?? 1),
                    '{draw_at}'     => (string) ($__lot['draw_at'] ?? ''),
                ]);
                $keyOf = function ($a, $b) { return $a < $b ? $a . '|' . $b : $b . '|' . $a; };
                $sent = 0; $skip = 0;
                $fsPm = getFS();
                foreach ((array) $fsPm->read('lottery_entries') as $__en) {
                    if ((int) ($__en['lottery_id'] ?? 0) !== $lid) { continue; }
                    $__uid = (int) ($__en['user_id'] ?? 0);
                    if (!$__pick($__uid)) { continue; }
                    $tu = $fsPm->findById('users', $__uid);
                    $tq = (string) ($tu['qq'] ?? '');
                    if (!$tu || $tq === '' || $tq === $adminQq || !empty($tu['is_banned'])) { $skip++; continue; }
                    $fsPm->insert('pm_messages', [
                        'key'  => $keyOf($adminQq, $tq),
                        'who'  => $adminQq,
                        'text' => mb_substr($text, 0, 1000),
                        'ts'   => (int) round(microtime(true) * 1000) + $sent,
                    ]);
                    $sent++;
                }
                if (function_exists('logOperation')) {
                    logOperation((int) $adminUser['id'], $adminQq, 'lottery_pm_entries', 'lottery', (string) $lid, '群发私信 ' . $sent . ' 人');
                }
                $msg = '已发送 ' . $sent . ' 条私信' . ($skip ? ('，跳过 ' . $skip . ' 人') : '');
            }
        } elseif ($act === 'copy') {
            $srcId = (int) ($_POST['id'] ?? 0);
            $src = lwLotteryGet($srcId);
            if (!$src) {
                $msg = '源抽奖不存在'; $msgType = 'error';
            } else {
                /* 复制一份新抽奖：沿用标题/奖品/名额/规则/说明，
                 * 开奖时间按原活动的"创建到开奖时长"顺延，最少 24 小时。 */
                $origTs = (int) (strtotime((string) ($src['draw_at'] ?? '')) ?: 0);
                $createdTs = (int) (strtotime((string) ($src['created_at'] ?? '')) ?: 0);
                $dur = ($origTs > 0 && $createdTs > 0 && $origTs > $createdTs) ? ($origTs - $createdTs) : 3 * 86400;
                $dur = max(86400, min(30 * 86400, $dur));
                $rules = (array) ($src['rules'] ?? []);
                $r = lwLotteryCreate((int) $adminUser['id'], [
                    'title'       => mb_substr(trim((string) ($src['title'] ?? '')) . '（副本）', 0, 120),
                    'prize'       => (string) ($src['prize'] ?? ''),
                    'prize_count' => (int) ($src['prize_count'] ?? 1),
                    'description' => (string) ($src['description'] ?? ''),
                    'draw_at'     => date('Y-m-d H:i:s', time() + $dur),
                    'rules'       => $rules,
                    'rule_like'    => in_array('点赞', $rules, true) ? 1 : 0,
                    'rule_comment' => in_array('评论', $rules, true) ? 1 : 0,
                    'rule_repost'  => in_array('转发', $rules, true) ? 1 : 0,
                    'rule_follow'  => in_array('关注', $rules, true) ? 1 : 0,
                ]);
                if (empty($r['ok'])) { $msg = (string) ($r['error'] ?? '复制失败'); $msgType = 'error'; }
                else { $msg = '已复制为新抽奖（#' . (int) $r['id'] . '）'; }
            }
        } elseif ($act === 'delete') {
            $id = (int) ($_POST['id'] ?? 0);
            $fs = getFS();
            /* 先记下来源帖子：抽奖删了，帖子上的 lottery_id 标记也要清掉，
             * 否则信息流卡片会一直显示「参与抽奖 →」却点进一个已删除的抽奖。 */
            $doomed = lwLotteryGet($id);
            $fs->delete('lotteries', $id);
            foreach ((array) $fs->read('lottery_entries') as $e) {
                if ((int) ($e['lottery_id'] ?? 0) === $id) { $fs->delete('lottery_entries', (int) $e['id']); }
            }
            $linkedPostId = (int) ($doomed['post_id'] ?? 0);
            if ($linkedPostId > 0) {
                $linkedPost = $fs->findById('posts', $linkedPostId);
                if ($linkedPost && (int) ($linkedPost['lottery_id'] ?? 0) === $id) {
                    $fs->update('posts', $linkedPostId, ['lottery_id' => 0]);
                }
            }
            $msg = '已删除抽奖 #' . $id;
        }
    }
}

$all = lwLotteryAll();
$userIndex = [];
foreach ((array) getFS()->read('users') as $u) { $userIndex[(int) ($u['id'] ?? 0)] = $u; }

/* 数据概览：抽奖数 / 参与 / 提醒 / 中奖 —— 只做聚合读，不做写入 */
$__lotStats = ['total' => count($all), 'open' => 0, 'drawn' => 0, 'entries' => 0, 'reminds' => 0, 'winners' => 0];
foreach ($all as $__l0) {
    if ((string) ($__l0['status'] ?? 'open') === 'open') { $__lotStats['open']++; } else { $__lotStats['drawn']++; }
}
foreach ((array) getFS()->read('lottery_entries') as $__e0) { if ((int) ($__e0['lottery_id'] ?? 0) > 0) { $__lotStats['entries']++; } }
foreach ($all as $__lw) {
    $__wn = json_decode((string) ($__lw['winners'] ?? '[]'), true);
    if (is_array($__wn)) { $__lotStats['winners'] += count($__wn); }
}
$__lotStats['reminds'] = count((array) getFS()->read('lottery_reminders'));
$__lotStats['share_views'] = 0;
foreach ($all as $__ls) { $__lotStats['share_views'] += (int) ($__ls['share_views'] ?? 0); }
$__lotStats['share_entries'] = 0;
foreach ((array) getFS()->read('lottery_entries') as $__se2) { if ((string) ($__se2['via'] ?? '') === 'share') { $__lotStats['share_entries']++; } }
$__lotStats['views'] = 0;
foreach ($all as $__lv2) { $__lotStats['views'] += (int) ($__lv2['views'] ?? 0); }
$__lotStats['rate'] = $__lotStats['views'] > 0 ? round($__lotStats['entries'] * 100 / $__lotStats['views'], 1) : 0;

/* 列表状态筛选：只影响列表展示，统计卡始终是全局口径 */
$lotFilter = (string) ($_GET['status'] ?? 'all');
if (!in_array($lotFilter, ['all', 'open', 'drawn'], true)) { $lotFilter = 'all'; }

/* 每个抽奖设置了多少「开奖提醒」——开奖时会按这个数量群发站内通知 */
$remindCount = [];
foreach ((array) getFS()->read('lottery_reminders') as $__r) {
    $__lid = (int) ($__r['lottery_id'] ?? 0);
    if ($__lid > 0) { $remindCount[$__lid] = ($remindCount[$__lid] ?? 0) + 1; }
}

adminHeader('抽奖管理', $adminUser, $csrfToken);
?>

<?php
/* 排序：默认按创建时间倒序；可按参与人数 / 最近开奖时间 */
$lotSort = (string) ($_GET['sort'] ?? 'new');
if (!in_array($lotSort, ['new', 'entries', 'draw_at'], true)) { $lotSort = 'new'; }
$__entryCnt = [];
foreach ((array) getFS()->read('lottery_entries') as $__e2) {
    $__eLid = (int) ($__e2['lottery_id'] ?? 0);
    if ($__eLid > 0) { $__entryCnt[$__eLid] = ($__entryCnt[$__eLid] ?? 0) + 1; }
}
$__viewLots = [];
foreach ($all as $__lv) {
    $__stv = (string) ($__lv['status'] ?? 'open');
    if ($lotFilter === 'open' && $__stv !== 'open') { continue; }
    if ($lotFilter === 'drawn' && $__stv === 'open') { continue; }
    $__viewLots[] = $__lv;
}
usort($__viewLots, function ($a, $b) use ($lotSort, $__entryCnt) {
    if ($lotSort === 'entries') {
        $ca = $__entryCnt[(int) ($a['id'] ?? 0)] ?? 0;
        $cb = $__entryCnt[(int) ($b['id'] ?? 0)] ?? 0;
        if ($ca === $cb) { return (int) ($b['id'] ?? 0) <=> (int) ($a['id'] ?? 0); }
        return $cb <=> $ca;
    }
    if ($lotSort === 'draw_at') {
        return strcmp((string) ($a['draw_at'] ?? ''), (string) ($b['draw_at'] ?? ''));
    }
    return (int) ($b['id'] ?? 0) <=> (int) ($a['id'] ?? 0);
});
?>

<?php if ($msg): ?>
<div class="section" style="padding:12px 16px;margin-bottom:14px;border-left:4px solid <?= $msgType === 'error' ? 'var(--danger)' : 'var(--success)' ?>">
    <?= htmlspecialchars($msg) ?>
</div>
<?php endif; ?>

<div class="section lot-stats" style="margin-bottom:14px">
    <div class="section-body" style="display:flex;gap:10px;flex-wrap:wrap">
        <?php
        $__cards = [
            ['抽奖总数', $__lotStats['total'], ''],
            ['进行中', $__lotStats['open'], ''],
            ['已开奖', $__lotStats['drawn'], ''],
            ['参与人次', $__lotStats['entries'], ''],
            ['提醒人次', $__lotStats['reminds'], ''],
            ['中奖人数', $__lotStats['winners'], ''],
            ['浏览总数', $__lotStats['views'], ''],
            ['参与转化', $__lotStats['rate'] . '%', ''],
            ['分享浏览', $__lotStats['share_views'], ''],
            ['分享参与', $__lotStats['share_entries'], ''],
        ];
        foreach ($__cards as $__c):
        ?>
        <div style="flex:1 1 120px;min-width:110px;padding:12px 14px;border:1px solid var(--border);border-radius:12px;background:var(--card-bg)">
            <div style="font-size:12px;color:var(--text-muted)"><?= htmlspecialchars($__c[0]) ?></div>
            <div style="font-size:22px;font-weight:800;color:var(--primary);font-variant-numeric:tabular-nums"><?= (int) $__c[1] ?></div>
        </div>
        <?php endforeach; ?>
    </div>
</div>

<?php
$__base = max(1, (int) $__lotStats['views']);
$__funnel = [
    ['浏览抽奖详情', (int) $__lotStats['views']],
    ['参与抽奖', (int) $__lotStats['entries']],
    ['设置开奖提醒', (int) $__lotStats['reminds']],
    ['中奖', (int) $__lotStats['winners']],
];
?>
<div class="section lot-funnel" style="margin-bottom:14px">
    <div class="section-header"><h3>参与转化漏斗</h3></div>
    <div class="section-body" style="display:flex;flex-direction:column;gap:8px">
        <?php foreach ($__funnel as $__i => $__f): ?>
        <div style="display:flex;align-items:center;gap:10px;font-size:12.5px">
            <span style="flex:0 0 96px;color:var(--text-muted)"><?= htmlspecialchars($__f[0]) ?></span>
            <div style="flex:1;height:16px;background:var(--bg-secondary);border-radius:8px;overflow:hidden">
                <div style="height:100%;width:<?= (int) round($__f[1] * 100 / $__base) ?>%;min-width:<?= $__f[1] > 0 ? '6px' : '0' ?>;background:linear-gradient(90deg,#2F5B9A,#5B8BD0);border-radius:8px"></div>
            </div>
            <span style="flex:0 0 108px;text-align:right;font-variant-numeric:tabular-nums">
                <b><?= (int) $__f[1] ?></b>
                <span style="color:var(--text-muted);font-size:11.5px">（<?= (int) round($__f[1] * 100 / $__base) ?>%）</span>
            </span>
        </div>
        <?php endforeach; ?>
    </div>
</div>

<?php
/* 抽奖日历：按月查看排期（开奖日期为轴） */
$__ym = (string) ($_GET['month'] ?? date('Y-m'));
if (!preg_match('/^\d{4}-\d{2}$/', $__ym)) { $__ym = date('Y-m'); }
$__first = strtotime($__ym . '-01 00:00:00');
$__daysIn = (int) date('t', $__first);
$__startDow = (int) date('w', $__first); // 0=周日
$__byDay = [];
foreach ($all as $__lc) {
    $__dl = substr((string) ($__lc['draw_at'] ?? ''), 0, 10);
    if (substr($__dl, 0, 7) === $__ym) { $__byDay[(int) substr($__dl, 8, 2)][] = $__lc; }
}
$__prev = date('Y-m', strtotime($__ym . '-01 -1 month'));
$__next = date('Y-m', strtotime($__ym . '-01 +1 month'));
?>
<div class="section lot-calendar" style="margin-bottom:14px">
    <div class="section-header">
        <h3>抽奖日历（<?= htmlspecialchars($__ym) ?>）</h3>
        <div style="display:flex;gap:8px;font-size:12.5px">
            <a href="?month=<?= urlencode($__prev) ?>" style="text-decoration:none;color:var(--text-muted)">← 上月</a>
            <a href="?month=<?= date('Y-m') ?>" style="text-decoration:none;color:var(--primary)">本月</a>
            <a href="?month=<?= urlencode($__next) ?>" style="text-decoration:none;color:var(--text-muted)">下月 →</a>
        </div>
    </div>
    <div class="section-body" style="overflow-x:auto">
        <div style="display:grid;grid-template-columns:repeat(7,minmax(72px,1fr));gap:6px;min-width:520px">
            <?php foreach (['日', '一', '二', '三', '四', '五', '六'] as $__w): ?>
            <div style="text-align:center;font-size:11.5px;color:var(--text-muted);padding:2px 0"><?= $__w ?></div>
            <?php endforeach; ?>
            <?php for ($__i = 0; $__i < $__startDow; $__i++): ?><div></div><?php endfor; ?>
            <?php for ($__d = 1; $__d <= $__daysIn; $__d++):
                $__list = $__byDay[$__d] ?? [];
                $__isToday = ($__ym === date('Y-m')) && ($__d === (int) date('j'));
            ?>
            <div data-lotdate="<?= $__ym . '-' . sprintf('%02d', $__d) ?>" style="min-height:58px;border:1px solid <?= $__isToday ? 'var(--primary)' : 'var(--border)' ?>;border-radius:8px;padding:4px 5px;font-size:11px;background:<?= $__isToday ? 'rgba(47,91,154,.06)' : 'var(--card-bg)' ?>;cursor:pointer" title="<?= $__list ? '' : '点击在此日创建抽奖' ?>">
                <div style="font-weight:700;color:<?= $__isToday ? 'var(--primary)' : 'var(--text-muted)' ?>"><?= $__d ?></div>
                <?php foreach (array_slice($__list, 0, 2) as $__lc): ?>
                <a href="/pages/lottery.php?id=<?= (int) $__lc['id'] ?>" title="<?= htmlspecialchars((string) ($__lc['title'] ?? '')) ?>" style="display:block;margin-top:2px;padding:1px 4px;border-radius:5px;background:<?= (($__lc['status'] ?? 'open') === 'open') ? 'rgba(47,91,154,.12)' : 'rgba(107,122,139,.12)' ?>;color:<?= (($__lc['status'] ?? 'open') === 'open') ? 'var(--primary)' : 'var(--text-muted)' ?>;text-decoration:none;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?= htmlspecialchars((string) ($__lc['title'] ?? '')) ?></a>
                <?php endforeach; ?>
                <?php if (count($__list) > 2): ?><div style="color:var(--text-muted)">+<?= count($__list) - 2 ?></div><?php endif; ?>
            </div>
            <?php endfor; ?>
        </div>
    </div>
</div>

<div class="section">
    <div class="section-header"><h3>创建抽奖</h3></div>
    <div class="section-body">
        <form method="post" class="lot-form" enctype="multipart/form-data">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
            <input type="hidden" name="act" value="create">
            <div class="lot-field" style="margin-bottom:6px">
                <label>快速模板（可选，自动填表）</label>
                <select id="tpl-select" class="form-input" style="max-width:260px">
                    <option value="">— 自定义 —</option>
                    <option value="praise">👍 点赞抽奖（社区活跃）</option>
                    <option value="share">🔁 转发抽奖（扩散引流）</option>
                    <option value="study">📚 学习打卡抽奖（比赛版适用）</option>
                    <option value="exam">📝 期中加油抽奖（考试季）</option>
                </select>
            </div>
            <div class="lot-row">
                <div class="lot-field"><label>标题</label><input class="form-input" name="title" required placeholder="例如：期中加油抽奖"></div>
                <div class="lot-field"><label>奖品</label><input class="form-input" name="prize" required placeholder="例如：校园墙限定徽章"></div>
                <div class="lot-field"><label>中奖名额</label><input class="form-input" type="number" name="prize_count" value="1" min="1" max="100"></div>
                <div class="lot-field"><label>开奖时间</label><input class="form-input" type="datetime-local" name="draw_at" required></div>
            </div>
            <div class="lot-field"><label>封面图（可选，≤3MB，jpg/png/gif/webp）</label>
                <div style="display:flex;align-items:center;gap:10px">
                    <input class="form-input" type="file" name="cover_image_file" accept="image/*" id="coverInput" style="flex:1">
                    <img id="coverPreview" alt="" style="display:none;width:56px;height:56px;border-radius:10px;object-fit:cover;border:1px solid var(--border)">
                    <button type="button" id="coverClear" class="btn btn-sm" style="display:none">移除</button>
                </div>
            </div>
            <div class="lot-field"><label>说明（可选）</label><textarea class="form-input" name="description" rows="3" placeholder="抽奖规则、领奖方式等"></textarea></div>
            <div class="lot-field">
                <label>参与方式（可多选；关注 / 点赞 / 评论 会自动核验，转发由「绑定动态」天然满足）</label>
                <label style="font-weight:400;margin-right:14px"><input type="checkbox" name="rule_follow" value="1"> 关注发起人</label>
                <label style="font-weight:400;margin-right:14px"><input type="checkbox" name="rule_like" value="1" checked> 点赞</label>
                <label style="font-weight:400;margin-right:14px"><input type="checkbox" name="rule_comment" value="1"> 评论</label>
                <label style="font-weight:400;margin-right:14px"><input type="checkbox" name="rule_repost" value="1"> 转发</label>
                <div style="margin-top:6px"><input class="form-input" name="rule_custom" placeholder="自定义提示条款（可选，如：需为本校学生 / 领奖需带学生证）" style="font-size:12.5px"></div>
            </div>
            <button class="btn btn-primary" type="submit">创建抽奖</button>
        </form>
    </div>
</div>

<div class="section">
    <div class="section-header">
        <h3>抽奖列表（<?= count($__viewLots) ?>/<?= count($all) ?>）</h3>
        <div style="display:flex;gap:6px;font-size:12.5px">
            <a href="?status=all" style="padding:3px 10px;border-radius:999px;text-decoration:none;<?= $lotFilter === 'all' ? 'background:#2F5B9A;color:#fff' : 'background:var(--bg-secondary);color:var(--text-muted)' ?>">全部</a>
            <a href="?status=open" style="padding:3px 10px;border-radius:999px;text-decoration:none;<?= $lotFilter === 'open' ? 'background:#2F5B9A;color:#fff' : 'background:var(--bg-secondary);color:var(--text-muted)' ?>">进行中</a>
            <a href="?status=drawn" style="padding:3px 10px;border-radius:999px;text-decoration:none;<?= $lotFilter === 'drawn' ? 'background:#2F5B9A;color:#fff' : 'background:var(--bg-secondary);color:var(--text-muted)' ?>">已开奖</a>
            <span style="color:var(--border);margin:0 2px">|</span>
            <a href="?status=<?= urlencode($lotFilter) ?>&sort=new" style="padding:3px 8px;text-decoration:none;<?= $lotSort === 'new' ? 'color:#2F5B9A;font-weight:700' : 'color:var(--text-muted)' ?>">最新创建</a>
            <a href="?status=<?= urlencode($lotFilter) ?>&sort=entries" style="padding:3px 8px;text-decoration:none;<?= $lotSort === 'entries' ? 'color:#2F5B9A;font-weight:700' : 'color:var(--text-muted)' ?>">参与人数</a>
            <a href="?status=<?= urlencode($lotFilter) ?>&sort=draw_at" style="padding:3px 8px;text-decoration:none;<?= $lotSort === 'draw_at' ? 'color:#2F5B9A;font-weight:700' : 'color:var(--text-muted)' ?>">开奖时间</a>
        </div>
    </div>
    <div class="section-body">
        <?php if (!$__viewLots): ?>
            <div style="padding:28px 0;text-align:center;color:var(--text-muted)">还没有抽奖，先在上面创建一个</div>
        <?php else: ?>
        <div style="display:flex;flex-direction:column;gap:10px">
        <?php $postIndex = []; // 只缓存本页用到的帖子，避免每行都查一次
        foreach ($__viewLots as $l):
            $entries = lwLotteryEntries((int) $l['id']);
            $winners = json_decode((string) ($l['winners'] ?? '[]'), true) ?: [];
            $status = (string) ($l['status'] ?? 'open');
            // 发帖时附带的抽奖会把来源帖子 ID 记在 post_id；后台单独建的是 0
            $postId = (int) ($l['post_id'] ?? 0);
            if ($postId > 0 && !array_key_exists($postId, $postIndex)) {
                $postIndex[$postId] = getFS()->findById('posts', $postId);
            }
            $postRow = $postId > 0 ? ($postIndex[$postId] ?? null) : null;
        ?>
            <div style="border:1px solid var(--border);border-radius:12px;padding:12px">
                <div style="display:flex;justify-content:space-between;gap:10px;flex-wrap:wrap;align-items:center">
                    <div>
                        <b>#<?= (int) $l['id'] ?> <?= htmlspecialchars((string) $l['title']) ?></b>
                        <span class="badge <?= $status === 'open' ? 'badge-admin' : 'badge-user' ?>" style="margin-left:6px"><?= $status === 'open' ? '进行中' : '已开奖' ?></span>
                        <div style="font-size:12px;color:var(--text-muted);margin-top:4px">
                            奖品：<?= htmlspecialchars((string) $l['prize']) ?> × <?= (int) $l['prize_count'] ?>
                            · 开奖：<?= htmlspecialchars((string) $l['draw_at']) ?>
                            · 参与：<?= count($entries) ?> 人
                            · 提醒：<?= (int) ($remindCount[(int) $l['id']] ?? 0) ?> 人
                            <?php
                            $__rulesView = (array) ($l['rules'] ?? []);
                            if (!$__rulesView) {
                                if (!empty($l['rule_like']))    { $__rulesView[] = '点赞'; }
                                if (!empty($l['rule_comment'])) { $__rulesView[] = '评论'; }
                                if (!empty($l['rule_repost']))  { $__rulesView[] = '转发'; }
                            }
                            ?>
                            · 规则：<?= $__rulesView ? htmlspecialchars(implode(' / ', $__rulesView)) : '无' ?>
                            · <?php if ($postId > 0 && $postRow): ?>
                                <a href="/pages/post_detail.php?id=<?= $postId ?>" style="font-weight:600">附带动态 #<?= $postId ?></a>
                              <?php elseif ($postId > 0): ?>
                                附带动态 #<?= $postId ?> <span style="color:var(--danger)">（动态已删除）</span>
                              <?php else: ?>
                                后台独立创建
                              <?php endif; ?>
                        </div>
                        <?php if ($status !== 'open'): ?>
                        <div style="font-size:12px;color:var(--text-muted);margin-top:4px">
                            中奖：<?php if ($winners): foreach ($winners as $w): ?><a href="/pages/u.php?id=<?= (int) $w ?>"><?= htmlspecialchars((string) ($userIndex[(int) $w]['nickname'] ?? ('#' . (int) $w))) ?></a> <?php endforeach; else: ?>无人参与<?php endif; ?>
                        </div>
                        <?php endif; ?>
                    </div>
                    <div style="display:flex;gap:6px">
                        <a class="btn btn-sm" href="/pages/lottery.php?id=<?= (int) $l['id'] ?>">查看</a>
                        <a class="btn btn-sm" href="/admin/lottery_edit.php?id=<?= (int) $l['id'] ?>">编辑</a>
                        <a class="btn btn-sm" href="/admin/lottery_export.php?id=<?= (int) $l['id'] ?>">导出 CSV</a>
                        <form method="post" style="display:inline" onsubmit="return confirm('按原配置复制一个新抽奖？')">
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                            <input type="hidden" name="act" value="copy">
                            <input type="hidden" name="id" value="<?= (int) $l['id'] ?>">
                            <button class="btn btn-sm" type="submit">复制</button>
                        </form>
                        <button class="btn btn-sm" type="button" onclick="copyLotLink(<?= (int) $l['id'] ?>)">复制链接</button>
                        <?php if ($status === 'open'): ?>
                        <form method="post" onsubmit="return confirm('立即开奖？开奖后不能再参与')">
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                            <input type="hidden" name="act" value="draw">
                            <input type="hidden" name="id" value="<?= (int) $l['id'] ?>">
                            <button class="btn btn-sm btn-success" type="submit">立即开奖</button>
                        </form>
                        <?php endif; ?>
                        <?php $__wc = count((array) ($winners ?? [])); ?>
                    <div class="pm-scope-hint" style="font-size:11.5px;color:var(--text-muted);margin:6px 0 2px">
                        群发范围：全部 <?= count($entries) ?> 人 · 中奖 <?= $__wc ?> 人 · 未中 <?= max(0, count($entries) - $__wc) ?> 人
                    </div>
                    <form method="post" style="display:inline-flex;gap:6px;align-items:center;margin-right:6px" onsubmit="return confirm('给所选范围的参与者群发这条私信？')">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                        <input type="hidden" name="act" value="pm_entries">
                        <input type="hidden" name="lottery_id" value="<?= (int) $l['id'] ?>">
                        <select name="pm_scope" style="padding:5px 6px;font-size:12px;border:1px solid var(--border);border-radius:8px;background:var(--card-bg);color:var(--text)">
                            <option value="all">全部参与者</option>
                            <option value="winner">仅中奖者</option>
                            <option value="loser">仅未中奖</option>
                        </select>
                        <input type="text" name="pm_text" placeholder="私信内容（可用 {title}/{prize}/{prize_count}/{draw_at}）" maxlength="200" style="padding:5px 8px;font-size:12px;border:1px solid var(--border);border-radius:8px;background:var(--card-bg);color:var(--text);min-width:150px">
                        <button class="btn btn-sm" type="submit">群发</button>
                    </form>
                    <form method="post" onsubmit="return confirm('删除这个抽奖及其参与记录？')">
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                            <input type="hidden" name="act" value="delete">
                            <input type="hidden" name="id" value="<?= (int) $l['id'] ?>">
                            <button class="btn btn-sm btn-danger" type="submit">删除</button>
                        </form>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>
</div>

<script>
/* 抽奖模板：一键填标题/说明/规则/名额/开奖时间，减少重复填写 */
(function () {
  var TPL = {
    praise: { title: '社区点赞抽奖', prize: '校园墙限定徽章', count: 3, rules: ['like'], hours: 48,
      desc: '为你喜欢的动态点个赞，就有机会获得限定徽章～开奖后请主动联系管理员，3 天内未领取视为放弃。' },
    share: { title: '转发扩散抽奖', prize: '奶茶券', count: 2, rules: ['repost'], hours: 72,
      desc: '转发本抽奖到你的动态，让更多同学看到校园墙～开奖后请携带学号到管理员处领取。' },
    study: { title: '学习打卡抽奖', prize: '文具礼包', count: 5, rules: ['comment', 'like'], hours: 120,
      desc: '在评论区写下你本周的学习打卡内容，即可参与抽奖。坚持打卡的同学更容易被看见哦～' },
    exam: { title: '期中加油抽奖', prize: '幸运文具盲盒', count: 5, rules: ['follow', 'comment'], hours: 72,
      desc: '期中将至，写下你的目标或给同学加油，抽 5 位同学送盲盒～中奖后凭学生证领取。' }
  };
  var sel = document.getElementById('tpl-select');
  if (!sel) return;
  sel.addEventListener('change', function () {
    var t = TPL[this.value];
    if (!t) return;
    var f = this.closest('form');
    f.querySelector('[name="title"]').value = t.title;
    f.querySelector('[name="prize"]').value = t.prize;
    f.querySelector('[name="prize_count"]').value = t.count;
    f.querySelector('[name="description"]').value = t.desc;
    ['follow', 'like', 'comment', 'repost'].forEach(function (k) {
      var el = f.querySelector('[name="rule_' + k + '"]');
      if (el) el.checked = t.rules.indexOf(k) >= 0;
    });
    var d = new Date(Date.now() + t.hours * 3600 * 1000);
    var pad = function (n) { return n < 10 ? '0' + n : n; };
    f.querySelector('[name="draw_at"]').value = d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate())
      + 'T' + pad(d.getHours()) + ':' + pad(d.getMinutes());
  });
})();

/* 日历：点击没有排期的日期 → 预填创建表单的开奖时间并滚动过去 */
document.addEventListener('click', function (e) {
  var cell = e.target.closest('[data-lotdate]');
  if (!cell) return;
  if (e.target.closest('a')) return;
  if (cell.querySelector('a')) return;
  var form = document.querySelector('form.lot-form');
  if (!form) return;
  var inp = form.querySelector('[name="draw_at"]');
  if (inp) {
    inp.value = cell.getAttribute('data-lotdate') + 'T20:00';
    form.scrollIntoView({ behavior: 'smooth', block: 'start' });
    inp.style.outline = '2px solid var(--primary)';
    setTimeout(function () { inp.style.outline = ''; }, 1600);
  }
});

/* 封面选择后即时预览（本地 FileReader，不上传） */
(function () {
  var inp = document.getElementById('coverInput');
  var img = document.getElementById('coverPreview');
  var clr = document.getElementById('coverClear');
  if (!inp) return;
  inp.addEventListener('change', function () {
    var f = this.files && this.files[0];
    if (!f) { img.style.display = 'none'; clr.style.display = 'none'; return; }
    if (!/^image\//.test(f.type)) { alert('请选择图片文件'); this.value = ''; return; }
    var r = new FileReader();
    r.onload = function (e) { img.src = e.target.result; img.style.display = 'block'; clr.style.display = 'inline-block'; };
    r.readAsDataURL(f);
  });
  if (clr) clr.addEventListener('click', function () {
    inp.value = ''; img.style.display = 'none'; img.removeAttribute('src'); this.style.display = 'none';
  });
})();

function copyLotLink(id) {
    var url = location.origin + '/pages/lottery.php?id=' + id;
    if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(url).then(function () {
            var t = document.createElement('div');
            t.textContent = '链接已复制';
            t.style.cssText = 'position:fixed;left:50%;bottom:30px;transform:translateX(-50%);background:#111;color:#fff;padding:8px 16px;border-radius:20px;font-size:13px;z-index:99';
            document.body.appendChild(t);
            setTimeout(function () { t.remove(); }, 1800);
        }, function () { prompt('手动复制：', url); });
    } else { prompt('手动复制：', url); }
}
</script>

<style>
.lot-form { display:flex; flex-direction:column; gap:12px; }
.lot-row { display:grid; grid-template-columns:repeat(auto-fit,minmax(180px,1fr)); gap:12px; }
.lot-field label { display:block; font-size:12.5px; font-weight:600; margin-bottom:5px; }
</style>

<?php adminFooter(); ?>
