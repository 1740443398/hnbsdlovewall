<?php
/**
 * 后台：兑换码管理
 *
 * ==========================================================================
 * 为什么单独一页
 * ==========================================================================
 * 兑换码是「发积分/装扮」的运营工具：生成一批码，发给用户，
 * 用户自己在商店页兑换。生成与核销必须在后台，用户端只能兑不能发。
 *
 * 原来这套逻辑（includes/points_codes.php + api/points_code.php）
 * 是完整的，但后台没有入口 —— 等于功能存在却用不了。
 * 这个页面就是补上那个入口。
 * ==========================================================================
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../includes/points_codes.php';
require_once __DIR__ . '/layout.php';

$admin = requireAdmin();
$admin = checkBanned($admin);
if (!in_array($admin['role'] ?? '', ['admin', 'super_admin'], true)) {
    header('Location: /admin/dashboard.php');
    exit;
}

$csrfToken = generateCSRFToken();
$msg = '';
$msgType = 'ok';
$newCodes = [];

$fs = getFS();

/* 可选商品列表（装扮）。用于生成「商品兑换码」——
 * 用户兑换后直接拿到该装扮，不经过积分。
 */
$allWidgets = [];
foreach ((array)$fs->read('widgets') as $w) {
    if (empty($w['active'])) continue;
    $allWidgets[] = [
        'id'    => (int)($w['id'] ?? 0),
        'name'  => (string)($w['name'] ?? ''),
        'slot'  => (string)($w['slot'] ?? ''),
        'price' => (int)($w['price'] ?? 0),
        'days'  => (int)($w['days'] ?? 0),
    ];
}
usort($allWidgets, function ($a, $b) {
    $so = ['avatar_frame' => 1, 'bubble' => 2, 'badge' => 3, 'title' => 4];
    $ca = $so[$a['slot']] ?? 9;
    $cb = $so[$b['slot']] ?? 9;
    return $ca === $cb ? strcmp($a['name'], $b['name']) : $ca - $cb;
});

$slotNames = [
    'avatar_frame' => '头像框',
    'bubble'       => '气泡',
    'badge'        => '徽章',
    'title'        => '头衔',
];

// ---- 生成 --------------------------------------------------------------
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && ($_POST['act'] ?? '') === 'gen') {
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        $msg = 'CSRF 校验失败，请刷新重试';
        $msgType = 'err';
    } else {
        $count    = max(1, min(500, (int)($_POST['count'] ?? 10)));
        $points   = max(0, min(100000, (int)($_POST['points'] ?? 100)));
        $note     = trim((string)($_POST['note'] ?? ''));
        $widgetId = max(0, (int)($_POST['widget_id'] ?? 0));

        $r = lwPointsCodeBatch($count, $points, (int)$admin['id'], $note, $widgetId);
        if (empty($r['ok'])) {
            $msg = (string)($r['error'] ?? '生成失败');
            $msgType = 'err';
        } else {
            $newCodes = $r['codes'] ?? [];
            $msg = '已生成 ' . count($newCodes) . ' 个兑换码（每码 ' . $points . ' 积分）';
        }
    }
}

// ---- 作废 --------------------------------------------------------------
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && ($_POST['act'] ?? '') === 'void') {
    if (verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        $batch = trim((string)($_POST['batch'] ?? ''));
        if ($batch !== '') {
            $n = 0;
            foreach ($fs->read('points_codes') as $row) {
                if ((string)($row['batch'] ?? '') !== $batch) continue;
                if (!empty($row['used_by'])) continue;   // 已用过的不能作废
                $fs->update('points_codes', $row['id'], ['voided' => 1]);
                $n++;
            }
            $msg = '已作废该批次中 ' . $n . ' 个未使用的兑换码';
        }
    }
}

// ---- 汇总批次 ----------------------------------------------------------
$rows = (array)$fs->read('points_codes');
$byBatch = [];
foreach ($rows as $r) {
    $b = (string)($r['batch'] ?? '');
    if ($b === '') continue;
    if (!isset($byBatch[$b])) {
        $byBatch[$b] = [
            'batch' => $b,
            'points' => (int)($r['points'] ?? 0),
            'note' => (string)($r['note'] ?? ''),
            'created_at' => (string)($r['created_at'] ?? ''),
            'total' => 0, 'used' => 0, 'voided' => 0,
        ];
    }
    $byBatch[$b]['total']++;
    if (!empty($r['used_by'])) $byBatch[$b]['used']++;
    if (!empty($r['voided'])) $byBatch[$b]['voided']++;
}
// 新的在前
uasort($byBatch, function ($a, $b) {
    return strcmp((string)$b['created_at'], (string)$a['created_at']);
});

// ---- 兑换记录 ----------------------------------------------------------
$redeemed = [];
foreach ($rows as $r) {
    if (!empty($r['used_by'])) {
        $redeemed[] = $r;
    }
}
usort($redeemed, function ($a, $b) {
    return (int)($b['used_at'] ?? 0) <=> (int)($a['used_at'] ?? 0);
});
$redeemed = array_slice($redeemed, 0, 100);

$userIndex = [];
foreach ((array)$fs->read('users') as $u) {
    $userIndex[(int)($u['id'] ?? 0)] = $u;
}

adminHeader('兑换码管理', $admin, $csrfToken);
?>

<style>
.pc-wrap { max-width: 960px; }
.pc-msg { padding: 11px 14px; border-radius: 10px; font-size: 13px; margin-bottom: 16px; }
.pc-msg.ok  { background: #EAF7F1; color: #178A62; border: 1px solid #C5E9D8; }
.pc-msg.err { background: #FDECEC; color: #C0392B; border: 1px solid #F5C6C6; }

.pc-field { margin-bottom: 14px; }
.pc-field label { display: block; font-size: 13px; font-weight: 600; margin-bottom: 6px; }
.pc-field .hint { font-size: 12px; color: var(--text-muted, #98A2B3); margin-bottom: 6px; line-height: 1.6; }
.pc-input {
    width: 100%; padding: 9px 12px; font-size: 13.5px; font-family: inherit;
    border: 1px solid var(--border-color, #E4E9F0); border-radius: 9px;
    background: var(--bg-input, #fff); color: var(--text-primary, #1F2733); outline: none;
}
.pc-input:focus { border-color: var(--primary, #2F5B9A); }

.pc-grid { display: grid; grid-template-columns: 1fr 1fr 2fr; gap: 12px; }
@media (max-width: 700px) { .pc-grid { grid-template-columns: 1fr; } }

.pc-codes {
    background: #1F2733; color: #A8E6B8; border-radius: 11px; padding: 16px 18px;
    font-family: ui-monospace, Menlo, Consolas, monospace; font-size: 13px;
    line-height: 2; max-height: 320px; overflow-y: auto; white-space: pre-wrap;
    margin-top: 14px;
}
.pc-copy { margin-top: 10px; }

.pc-type-box {
    padding: 14px 16px; background: #F7F9FC;
    border: 1px solid #E4E9F0; border-radius: 11px; margin-bottom: 16px;
}
.pc-type-title { font-size: 13px; font-weight: 600; margin-bottom: 10px; }
.pc-type-tabs { display: flex; gap: 10px; flex-wrap: wrap; }
.pc-type-opt {
    flex: 1 1 180px; display: block; padding: 11px 13px; background: #fff;
    border: 1.5px solid #E4E9F0; border-radius: 9px; cursor: pointer;
    transition: border-color .15s, background .15s;
}
.pc-type-opt:hover { border-color: rgba(47,91,154,.4); }
.pc-type-opt:has(input:checked) { border-color: #2F5B9A; background: #F0F6FF; }
.pc-type-opt span { font-size: 13px; font-weight: 600; margin-left: 6px; }
.pc-type-opt small {
    display: block; font-size: 11.5px; color: #98A2B3; margin-top: 4px; margin-left: 22px;
}

.pc-table { width: 100%; border-collapse: collapse; font-size: 13px; }
.pc-table th {
    text-align: left; padding: 9px 10px; font-size: 12px; font-weight: 600;
    color: var(--text-muted, #98A2B3); border-bottom: 1px solid var(--border-color, #E4E9F0);
}
.pc-table td { padding: 10px; border-bottom: 1px solid var(--border-light, #F3F6FA); }
.pc-table code {
    background: var(--bg-secondary, #F3F6FA); padding: 2px 7px; border-radius: 5px;
    font-size: 12px; letter-spacing: .5px;
}
.pc-pill {
    display: inline-block; padding: 2px 9px; border-radius: 999px; font-size: 11.5px; font-weight: 600;
}
.pc-pill.used { background: #EEF2F7; color: #5A6472; }
.pc-pill.live { background: #EAF7F1; color: #178A62; }
</style>

<div class="pc-wrap">
    <?php if ($msg !== ''): ?>
    <div class="pc-msg <?= $msgType ?>"><?= htmlspecialchars($msg) ?></div>
    <?php endif; ?>

    <form method="post" class="section" id="genForm">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
        <input type="hidden" name="act" value="gen">
        <div class="section-header"><h3>生成兑换码</h3></div>
        <div class="section-body">
            <div class="hint" style="margin-bottom:14px;">
                生成后把码复制发出去，用户在「商店」页输入即可兑换积分。
                每码只能用一次。
            </div>
            <div class="pc-grid">
                <div class="pc-field">
                    <label>数量</label>
                    <div class="hint">1~500</div>
                    <input class="pc-input" name="count" type="number" value="10" min="1" max="500">
                </div>
                <div class="pc-field">
                    <label>每个码的积分</label>
                    <input class="pc-input" name="points" type="number" value="100" min="1" max="100000">
                </div>
                <div class="pc-field">
                    <label>备注（便于日后分辨批次）</label>
                    <div class="hint">例如「国庆活动」「给高三鼓励」</div>
                    <input class="pc-input" name="note" placeholder="可留空">
                </div>
            </div>

            <!-- 码类型：积分码 / 商品码 -->
            <div class="pc-type-box">
                <div class="pc-type-title">兑换什么</div>
                <div class="pc-type-tabs">
                    <label class="pc-type-opt">
                        <input type="radio" name="code_type" value="points" checked
                               onchange="toggleType('points')">
                        <span>积分码</span>
                        <small>兑换后账户增加积分</small>
                    </label>
                    <label class="pc-type-opt">
                        <input type="radio" name="code_type" value="widget"
                               onchange="toggleType('widget')">
                        <span>商品码</span>
                        <small>兑换后直接获得指定装扮</small>
                    </label>
                </div>

                <div id="widgetPicker" style="display:none;margin-top:12px;">
                    <label style="display:block;font-size:13px;font-weight:600;margin-bottom:6px;">
                        选择商品
                    </label>
                    <div class="hint" style="margin-bottom:8px;">
                        共 <?= count($allWidgets) ?> 个在售装扮。选中的商品会直接发给兑换人，
                        不扣积分。
                    </div>
                    <select class="pc-input" name="widget_id" id="widgetSelect">
                        <option value="0">— 请选择 —</option>
                        <?php
                        $curSlot = '';
                        foreach ($allWidgets as $w):
                            if ($w['slot'] !== $curSlot):
                                if ($curSlot !== '') echo '</optgroup>';
                                $curSlot = $w['slot'];
                                echo '<optgroup label="' . htmlspecialchars($slotNames[$curSlot] ?? $curSlot) . '">';
                            endif;
                        ?>
                        <option value="<?= $w['id'] ?>">
                            <?= htmlspecialchars($w['name']) ?>
                            （<?= $w['price'] ?> 积分 / <?= $w['days'] > 0 ? $w['days'] . ' 天' : '永久' ?>）
                        </option>
                        <?php endforeach; ?>
                        <?php if ($curSlot !== '') echo '</optgroup>'; ?>
                    </select>
                </div>
            </div>

            <button type="submit" class="btn btn-primary" id="genBtn">生成积分码</button>
        </div>
    </form>

    <?php if ($newCodes): ?>
    <div class="section" style="margin-top:16px;">
        <div class="section-header"><h3>本次生成的兑换码</h3></div>
        <div class="section-body">
            <div class="hint">复制下面的内容保存好，关掉页面后就只能到下方批次里看了。</div>
            <div class="pc-codes" id="newCodeBox"><?= htmlspecialchars(implode("\n", $newCodes)) ?></div>
            <button type="button" class="btn pc-copy" onclick="copyCodes()">复制全部</button>
        </div>
    </div>
    <script>
    function copyCodes() {
        var box = document.getElementById('newCodeBox');
        var txt = box ? box.textContent : '';
        if (!txt) return;
        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(txt).then(function () {
                alert('已复制 ' + txt.split('\n').length + ' 个兑换码');
            }, function () { alert('复制失败，请手动选中复制'); });
        } else {
            var ta = document.createElement('textarea');
            ta.value = txt;
            document.body.appendChild(ta);
            ta.select();
            try { document.execCommand('copy'); alert('已复制'); } catch (e) { alert('复制失败'); }
            document.body.removeChild(ta);
        }
    }
    </script>
    <?php endif; ?>

    <div class="section" style="margin-top:16px;">
        <div class="section-header"><h3>批次</h3></div>
        <div class="section-body">
            <?php if (!$byBatch): ?>
                <div style="padding:26px 0;text-align:center;color:var(--text-muted,#98A2B3);font-size:13px;">
                    还没有生成过兑换码。
                </div>
            <?php else: ?>
            <div style="overflow-x:auto;">
                <table class="pc-table">
                    <thead>
                        <tr>
                            <th>批次</th><th>积分/码</th><th>备注</th>
                            <th>总数</th><th>已用</th><th>作废</th><th>创建时间</th><th>操作</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($byBatch as $b): ?>
                        <tr>
                            <td><code><?= htmlspecialchars($b['batch']) ?></code></td>
                            <td><b><?= $b['points'] ?></b></td>
                            <td><?= htmlspecialchars($b['note'] !== '' ? $b['note'] : '—') ?></td>
                            <td><?= $b['total'] ?></td>
                            <td><span class="pc-pill used"><?= $b['used'] ?></span></td>
                            <td><?= $b['voided'] ?></td>
                            <td style="white-space:nowrap;font-size:12px;color:var(--text-muted,#98A2B3);">
                                <?= htmlspecialchars($b['created_at']) ?>
                            </td>
                            <td>
                                <?php if ($b['voided'] < $b['total'] - $b['used']): ?>
                                <form method="post" style="margin:0;"
                                      onsubmit="return confirm('作废该批次所有未使用的码？此操作不可撤销。');">
                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                                    <input type="hidden" name="act" value="void">
                                    <input type="hidden" name="batch" value="<?= htmlspecialchars($b['batch']) ?>">
                                    <button type="submit" class="btn btn-sm">作废未用</button>
                                </form>
                                <?php else: ?>
                                    <span style="font-size:12px;color:var(--text-muted,#98A2B3);">—</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <div class="section" style="margin-top:16px;">
        <div class="section-header"><h3>兑换记录（最近 100 条）</h3></div>
        <div class="section-body">
            <?php if (!$redeemed): ?>
                <div style="padding:26px 0;text-align:center;color:var(--text-muted,#98A2B3);font-size:13px;">
                    还没有人兑换。
                </div>
            <?php else: ?>
            <div style="overflow-x:auto;">
                <table class="pc-table">
                    <thead>
                        <tr><th>兑换码</th><th>积分</th><th>兑换人</th><th>时间</th></tr>
                    </thead>
                    <tbody>
                    <?php foreach ($redeemed as $r):
                        $uid = (int)($r['used_by'] ?? 0);
                        $u = $userIndex[$uid] ?? null;
                    ?>
                        <tr>
                            <td><code><?= htmlspecialchars((string)($r['code'] ?? '')) ?></code></td>
                            <td><b><?= (int)($r['points'] ?? 0) ?></b></td>
                            <td><?= htmlspecialchars($u ? (string)($u['nickname'] ?? '') : ('#' . $uid)) ?></td>
                            <td style="font-size:12px;color:var(--text-muted,#98A2B3);">
                                <?= !empty($r['used_at']) ? date('Y-m-d H:i', (int)$r['used_at']) : '—' ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php adminFooter(); ?>
