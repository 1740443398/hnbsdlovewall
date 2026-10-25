<?php
/**
 * 后台：邀请关系图
 *
 * 看清「谁把谁拉进来的」这棵关系树。
 *
 * 为什么值得单独一页：
 *   邀请是本站唯一的传播路径，它同时承担了三件事 ——
 *   进站门槛（有码免答题）、归属关系（谁的人）、奖励发放。
 *   一旦有人批量注册小号刷邀请奖励，光看「邀请数排行榜」是发现不了的，
 *   必须看到「谁邀请了谁、什么时候、这些人后来活不活跃」。
 *
 * 数据来源：users.invited_by（邀请人 user_id）+ invited_at。
 * 全部从现有用户表推导，不新增存储。
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../includes/invite.php';
require_once __DIR__ . '/layout.php';

$adminUser = requireAdmin();
$adminUser = checkBanned($adminUser);
$csrfToken = generateCSRFToken();

adminHeader('邀请关系图', $adminUser, $csrfToken);

$fs = getFS();
$users = $fs->read('users');
$users = is_array($users) ? $users : [];

// 建索引
$byId = [];
foreach ($users as $u) {
    $id = (int)($u['id'] ?? 0);
    if ($id > 0) {
        $byId[$id] = $u;
    }
}

// 按邀请人分组
$tree = [];       // inviterId => [invitee...]
$roots = [];      // 被邀请但没有邀请人的（自然注册）
$inviterSet = []; // 邀请过人的人

foreach ($users as $u) {
    $by = (int)($u['invited_by'] ?? 0);
    if ($by > 0 && isset($byId[$by])) {
        $tree[$by][] = $u;
        $inviterSet[$by] = true;
    }
}

// 统计
$totalUsers = count($users);
$invitedCount = 0;
foreach ($tree as $list) {
    $invitedCount += count($list);
}
$inviterCount = count($inviterSet);

// 异常检测：短时间大量注册（潜在刷号）
$now = time();
$suspicious = [];
foreach ($tree as $inviterId => $list) {
    if (count($list) < 5) continue;
    // 按 10 分钟窗口聚类
    $times = [];
    foreach ($list as $u) {
        $t = strtotime((string)($u['invited_at'] ?? $u['created_at'] ?? '')) ?: 0;
        if ($t > 0) $times[] = $t;
    }
    sort($times);
    for ($i = 0; $i + 4 < count($times); $i++) {
        if ($times[$i + 4] - $times[$i] <= 600) {
            $suspicious[$inviterId] = [
                'count' => count($list),
                'burst' => 5,
                'window' => 10,
            ];
            break;
        }
    }
}
?>
<style>
.ig-wrap { padding: 0 0 40px; }
.ig-stats { display: flex; gap: 18px; flex-wrap: wrap; margin-bottom: 22px; }
.ig-stat {
    flex: 1; min-width: 140px; background: #fff; border: 1px solid #E4E9F0;
    border-radius: 12px; padding: 16px 18px;
}
.ig-stat .n { font-size: 24px; font-weight: 700; line-height: 1.2; }
.ig-stat .l { font-size: 12px; color: #9AA4B2; margin-top: 3px; }
.ig-tree { background: #fff; border: 1px solid #E4E9F0; border-radius: 12px; overflow: hidden; }
.ig-node { border-bottom: 1px solid #F0F3F8; }
.ig-node:last-child { border-bottom: 0; }
.ig-row {
    display: flex; align-items: center; gap: 10px;
    padding: 12px 16px; cursor: pointer; user-select: none;
}
.ig-row:hover { background: #FAFBFD; }
.ig-caret {
    width: 16px; text-align: center; color: #9AA4B2; font-size: 11px;
    transition: transform .15s; flex-shrink: 0;
}
.ig-caret.open { transform: rotate(90deg); }
.ig-avatar {
    width: 30px; height: 30px; border-radius: 50%; flex-shrink: 0;
    background: #E8EEF8; display: flex; align-items: center; justify-content: center;
    font-size: 13px; font-weight: 600; color: #4F7FE0; overflow: hidden;
}
.ig-avatar img { width: 100%; height: 100%; object-fit: cover; }
.ig-name { font-weight: 600; font-size: 14px; }
.ig-meta { font-size: 12px; color: #9AA4B2; }
.ig-badge {
    font-size: 11px; padding: 2px 8px; border-radius: 99px;
    background: #EAF7F1; color: #178A62; font-weight: 600;
}
.ig-badge.warn { background: #FDECEC; color: #C0392B; }
.ig-badge.idle { background: #F0F3F8; color: #9AA4B2; }
.ig-children {
    padding: 0 16px 12px 46px; display: none;
}
.ig-children.open { display: block; }
.ig-child {
    display: flex; align-items: center; gap: 8px;
    padding: 7px 10px; border-radius: 8px; font-size: 13px;
    border-left: 2px solid #E4E9F0; margin-bottom: 4px; padding-left: 12px;
}
.ig-child:hover { background: #FAFBFD; }
.ig-empty { padding: 40px 20px; text-align: center; color: #9AA4B2; font-size: 14px; }
.ig-tip {
    background: #F8FAFC; border-left: 3px solid #4F7FE0; padding: 12px 14px;
    border-radius: 0 8px 8px 0; font-size: 12.5px; color: #5B667A;
    margin-bottom: 18px; line-height: 1.7;
}
.ig-filter { margin-bottom: 16px; display: flex; gap: 10px; align-items: center; flex-wrap: wrap; }
.ig-filter input {
    padding: 8px 12px; border: 1px solid #E4E9F0; border-radius: 9px;
    font-size: 13px; font-family: inherit; outline: none; width: 220px;
}
.ig-filter input:focus { border-color: #2F5B9A; }
</style>

<div class="ig-wrap">
    <div class="ig-tip">
        <b>这棵树怎么来的</b><br>
        每个用户注册时如果填了有效邀请码，就记下 <code>users.invited_by</code>。
        这里把「谁邀请了谁」摊成树，方便看清传播路径，也能发现批量刷号的异常。
        <br><br>
        <b>异常标红</b>表示：同一个人在 10 分钟内邀请了 5 个以上账号，通常是刷奖励的信号。
    </div>

    <div class="ig-stats">
        <div class="ig-stat">
            <div class="n"><?= number_format($totalUsers) ?></div>
            <div class="l">注册用户总数</div>
        </div>
        <div class="ig-stat">
            <div class="n"><?= number_format($invitedCount) ?></div>
            <div class="l">通过邀请注册</div>
        </div>
        <div class="ig-stat">
            <div class="n">
                <?= $totalUsers > 0 ? round($invitedCount / $totalUsers * 100) : 0 ?><span style="font-size:14px;font-weight:400">%</span>
            </div>
            <div class="l">邀请占比</div>
        </div>
        <div class="ig-stat">
            <div class="n"><?= number_format($inviterCount) ?></div>
            <div class="l">发出过邀请的人</div>
        </div>
        <div class="ig-stat">
            <div class="n" style="color:<?= count($suspicious) > 0 ? '#C0392B' : '#178A62' ?>">
                <?= count($suspicious) ?>
            </div>
            <div class="l">疑似异常邀请</div>
        </div>
    </div>

    <div class="ig-filter">
        <input type="text" id="igSearch" placeholder="搜索邀请人 / 被邀请人昵称…" onkeydown="if(event.key==='Enter')igFilter()">
        <button type="button" class="btn btn-primary btn-sm" onclick="igFilter()">筛选</button>
        <button type="button" class="btn btn-outline btn-sm" onclick="document.getElementById('igSearch').value='';igFilter()">重置</button>
    </div>

    <div class="ig-tree" id="igTree">
        <?php if (!$tree): ?>
            <div class="ig-empty">还没有任何邀请记录</div>
        <?php else: ?>
            <?php
            // 按被邀请人数排序，邀请多的在前
            uasort($tree, function ($a, $b) {
                return count($b) - count($a);
            });
            foreach ($tree as $inviterId => $list):
                $inviter = $byId[$inviterId] ?? null;
                if (!$inviter) continue;
                $n = count($list);
                $isSus = isset($suspicious[$inviterId]);
                // 最近邀请时间
                $lastTime = '';
                foreach ($list as $x) {
                    $t = (string)($x['invited_at'] ?? $x['created_at'] ?? '');
                    if ($t > $lastTime) $lastTime = $t;
                }
                $initial = mb_substr((string)($inviter['nickname'] ?? '?'), 0, 1);
                $avatar = (string)($inviter['avatar'] ?? '');
            ?>
            <div class="ig-node" data-name="<?= htmlspecialchars(mb_strtolower((string)($inviter['nickname'] ?? ''))) ?>">
                <div class="ig-row" onclick="igToggle(this)">
                    <span class="ig-caret">▶</span>
                    <div class="ig-avatar">
                        <?php if ($avatar !== ''): ?>
                            <img src="<?= htmlspecialchars($avatar) ?>" alt="">
                        <?php else: ?>
                            <?= htmlspecialchars($initial) ?>
                        <?php endif; ?>
                    </div>
                    <div style="flex:1;min-width:0">
                        <div class="ig-name"><?= htmlspecialchars((string)($inviter['nickname'] ?? '未知')) ?></div>
                        <div class="ig-meta">
                            QQ <?= htmlspecialchars((string)($inviter['qq'] ?? '-')) ?>
                            · 已邀请 <?= $n ?> 人
                            <?php if ($lastTime !== ''): ?>· 最近 <?= htmlspecialchars($lastTime) ?><?php endif; ?>
                        </div>
                    </div>
                    <?php if ($isSus): ?>
                        <span class="ig-badge warn">疑似刷号</span>
                    <?php endif; ?>
                    <span class="ig-badge"><?= $n ?></span>
                </div>
                <div class="ig-children">
                    <?php foreach ($list as $x):
                        $t = (string)($x['invited_at'] ?? $x['created_at'] ?? '');
                        $xis = mb_substr((string)($x['nickname'] ?? '?'), 0, 1);
                        // 被邀请人自己又邀请了多少人
                        $grand = isset($tree[(int)($x['id'] ?? 0)]) ? count($tree[(int)($x['id'] ?? 0)]) : 0;
                    ?>
                    <div class="ig-child" data-name="<?= htmlspecialchars(mb_strtolower((string)($x['nickname'] ?? ''))) ?>">
                        <div class="ig-avatar" style="width:22px;height:22px;font-size:11px">
                            <?= htmlspecialchars($xis) ?>
                        </div>
                        <span style="flex:1"><?= htmlspecialchars((string)($x['nickname'] ?? '未知')) ?></span>
                        <?php if ($grand > 0): ?>
                            <span class="ig-badge idle">再邀 <?= $grand ?></span>
                        <?php endif; ?>
                        <span class="ig-meta"><?= htmlspecialchars($t) ?></span>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>

<script>
function igToggle(row) {
    var caret = row.querySelector('.ig-caret');
    var children = row.nextElementSibling;
    if (!children) return;
    var open = children.classList.toggle('open');
    if (caret) caret.classList.toggle('open', open);
}

function igFilter() {
    var kw = (document.getElementById('igSearch').value || '').trim().toLowerCase();
    var nodes = document.querySelectorAll('.ig-node');
    for (var i = 0; i < nodes.length; i++) {
        var node = nodes[i];
        var own = (node.getAttribute('data-name') || '');
        var kids = node.querySelectorAll('.ig-child');
        var kidHit = false;
        for (var k = 0; k < kids.length; k++) {
            var kn = (kids[k].getAttribute('data-name') || '');
            var hit = kw === '' || kn.indexOf(kw) >= 0;
            kids[k].style.display = hit ? '' : 'none';
            if (kw !== '' && hit) kidHit = true;
        }
        var selfHit = kw === '' || own.indexOf(kw) >= 0;
        node.style.display = (selfHit || kidHit) ? '' : 'none';
        // 命中子项时自动展开
        if (kidHit) {
            var ch = node.querySelector('.ig-children');
            var cr = node.querySelector('.ig-caret');
            if (ch) ch.classList.add('open');
            if (cr) cr.classList.add('open');
        }
    }
}

// 默认展开邀请最多的前 3 个
(function () {
    var nodes = document.querySelectorAll('.ig-node');
    for (var i = 0; i < Math.min(3, nodes.length); i++) {
        var ch = nodes[i].querySelector('.ig-children');
        var cr = nodes[i].querySelector('.ig-caret');
        if (ch) ch.classList.add('open');
        if (cr) cr.classList.add('open');
    }
})();
</script>
<?php adminFooter(); ?>
