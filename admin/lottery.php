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
            $r = lwLotteryCreate((int) $adminUser['id'], [
                'title'        => $_POST['title'] ?? '',
                'prize'        => $_POST['prize'] ?? '',
                'prize_count'  => $_POST['prize_count'] ?? 1,
                'description'  => $_POST['description'] ?? '',
                'draw_at'      => $_POST['draw_at'] ?? '',
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
$__viewLots = [];
foreach ($all as $__lv) {
    $__stv = (string) ($__lv['status'] ?? 'open');
    if ($lotFilter === 'open' && $__stv !== 'open') { continue; }
    if ($lotFilter === 'drawn' && $__stv === 'open') { continue; }
    $__viewLots[] = $__lv;
}
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

<div class="section">
    <div class="section-header"><h3>创建抽奖</h3></div>
    <div class="section-body">
        <form method="post" class="lot-form">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
            <input type="hidden" name="act" value="create">
            <div class="lot-row">
                <div class="lot-field"><label>标题</label><input class="form-input" name="title" required placeholder="例如：期中加油抽奖"></div>
                <div class="lot-field"><label>奖品</label><input class="form-input" name="prize" required placeholder="例如：校园墙限定徽章"></div>
                <div class="lot-field"><label>中奖名额</label><input class="form-input" type="number" name="prize_count" value="1" min="1" max="100"></div>
                <div class="lot-field"><label>开奖时间</label><input class="form-input" type="datetime-local" name="draw_at" required></div>
            </div>
            <div class="lot-field"><label>说明（可选）</label><textarea class="form-input" name="description" rows="3" placeholder="抽奖规则、领奖方式等"></textarea></div>
            <div class="lot-field">
                <label>参与方式（可多选；关注 / 点赞 / 评论 会自动核验，转发由「绑定动态」天然满足）</label>
                <label style="font-weight:400;margin-right:14px"><input type="checkbox" name="rule_follow" value="1"> 关注发起人</label>
                <label style="font-weight:400;margin-right:14px"><input type="checkbox" name="rule_like" value="1" checked> 点赞</label>
                <label style="font-weight:400;margin-right:14px"><input type="checkbox" name="rule_comment" value="1"> 评论</label>
                <label style="font-weight:400"><input type="checkbox" name="rule_repost" value="1"> 转发</label>
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
        </div>
    </div>
    <div class="section-body">
        <?php if (!$__viewLots): ?>
            <div style="padding:28px 0;text-align:center;color:var(--text-muted)">还没有抽奖，先在上面创建一个</div>
        <?php else: ?>
        <div style="display:flex;flex-direction:column;gap:10px">
        <?php $postIndex = []; // 只缓存本页用到的帖子，避免每行都查一次
        foreach ($all as $l):
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
                        <button class="btn btn-sm" type="button" onclick="copyLotLink(<?= (int) $l['id'] ?>)">复制链接</button>
                        <?php if ($status === 'open'): ?>
                        <form method="post" onsubmit="return confirm('立即开奖？开奖后不能再参与')">
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                            <input type="hidden" name="act" value="draw">
                            <input type="hidden" name="id" value="<?= (int) $l['id'] ?>">
                            <button class="btn btn-sm btn-success" type="submit">立即开奖</button>
                        </form>
                        <?php endif; ?>
                        <form method="post" style="display:inline-flex;gap:6px;align-items:center;margin-right:6px" onsubmit="return confirm('给全部参与者群发这条私信？')">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                        <input type="hidden" name="action" value="pm_entries">
                        <input type="hidden" name="lottery_id" value="<?= (int) $l['id'] ?>">
                        <input type="text" name="pm_text" placeholder="私信参与者内容…" maxlength="200" style="padding:5px 8px;font-size:12px;border:1px solid var(--border);border-radius:8px;background:var(--card-bg);color:var(--text);min-width:150px">
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
