<?php
/**
 * D31 帖子投票管理（后台运营页）
 * 列出全站所有带投票的帖子，可关闭/开启、清空票数、移除投票。
 * 数据经 api/admin/post_votes.php 读写（view_posts 可读，delete_posts 可写）。
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/layout.php';

$adminUser = requireAdmin();
$adminUser = checkBanned($adminUser);
$csrfToken = generateCSRFToken();

if (!checkPermission($adminUser, 'view_posts')) {
    http_response_code(403);
    die('403 Forbidden');
}
$canWrite = checkPermission($adminUser, 'delete_posts');

adminHeader('帖子投票', $adminUser, $csrfToken);
?>
<style>
.vote-card { background: var(--card-bg); border: 1px solid var(--border); border-radius: var(--radius); padding: 16px; margin-bottom: 14px; }
.vote-q { font-weight: 600; font-size: 15px; color: var(--text); margin-bottom: 4px; }
.vote-meta { font-size: 12px; color: var(--text-secondary); margin-bottom: 12px; display: flex; gap: 12px; flex-wrap: wrap; }
.vote-opt { display: flex; align-items: center; gap: 10px; margin-bottom: 8px; font-size: 13px; }
.vote-bar { flex: 1; height: 22px; background: var(--input-bg); border-radius: 6px; overflow: hidden; position: relative; }
.vote-bar > span { position: absolute; left: 8px; top: 50%; transform: translateY(-50%); font-size: 12px; color: var(--text); font-weight: 600; z-index: 2; }
.vote-bar > i { display: block; height: 100%; background: var(--primary); opacity: .55; }
.vote-count { width: 54px; text-align: right; color: var(--text-secondary); font-size: 12px; }
.vote-actions { display: flex; gap: 8px; margin-top: 12px; flex-wrap: wrap; }
.vote-closed { color: var(--danger); font-weight: 600; }
</style>

<div class="section">
    <div class="section-header">
        <h3>帖子投票管理</h3>
        <button class="btn btn-outline btn-sm" onclick="loadVotes()">刷新</button>
    </div>
    <div class="section-body">
        <div id="voteList"><div class="loading-spinner">加载中</div></div>
    </div>
</div>

<script>
const PV_CAN_WRITE = <?= $canWrite ? 'true' : 'false' ?>;
function loadVotes() {
    const box = document.getElementById('voteList');
    box.innerHTML = '<div class="loading-spinner">加载中</div>';
    fetch('/api/admin/post_votes.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'action=list&csrf_token=' + encodeURIComponent(<?= json_encode($csrfToken) ?>)
    }).then(r => r.json()).then(d => {
        if (!d.success) { box.innerHTML = '<div class="empty-state">加载失败：' + esc(d.msg || '') + '</div>'; return; }
        const list = d.data.list || [];
        if (!list.length) { box.innerHTML = '<div class="empty-state"><div class="empty-icon">🗳️</div>暂无进行中的投票</div>'; return; }
        box.innerHTML = list.map(renderVote).join('');
    }).catch(e => { box.innerHTML = '<div class="empty-state">请求出错</div>'; });
}
function renderVote(v) {
    const max = Math.max(1, ...v.counts);
    const opts = v.options.map((o, i) => {
        const c = v.counts[i] || 0;
        const pct = Math.round(c / max * 100);
        return '<div class="vote-opt"><div style="width:160px;color:var(--text)">' + esc(o) + '</div>'
            + '<div class="vote-bar"><i style="width:' + pct + '%"></i><span>' + pct + '%</span></div>'
            + '<div class="vote-count">' + c + ' 票</div></div>';
    }).join('');
    const status = v.closed ? '<span class="vote-closed">● 已关闭</span>' : '<span style="color:var(--success)">● 进行中</span>';
    const acts = PV_CAN_WRITE ? (
        '<button class="btn btn-sm ' + (v.closed ? 'btn-success' : 'btn-warning') + '" onclick="pvAct(' + v.id + ',\'' + (v.closed ? 'open' : 'close') + '\')">' + (v.closed ? '开启' : '关闭') + '投票</button>'
        + '<button class="btn btn-sm btn-outline" onclick="pvAct(' + v.id + ',\'reset\')">清空票数</button>'
        + '<button class="btn btn-sm btn-danger" onclick="pvAct(' + v.id + ',\'delete\')">移除投票</button>'
    ) : '';
    return '<div class="vote-card">'
        + '<div class="vote-q">' + esc(v.question || '(未命名投票)') + '</div>'
        + '<div class="vote-meta"><span>帖子 #' + v.id + '</span><span>作者：' + esc(v.author) + '</span><span>总票数：' + v.total + '</span><span>' + status + '</span><span>' + esc(v.created_at) + '</span></div>'
        + opts + (acts ? '<div class="vote-actions">' + acts + '</div>' : '')
        + '</div>';
}
function pvAct(id, act) {
    if (act === 'delete' && !confirm('确定移除该投票？此操作不可撤销。')) return;
    if (act === 'reset' && !confirm('确定清空所有票数？')) return;
    fetch('/api/admin/post_votes.php', {
        method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'action=' + act + '&post_id=' + id + '&csrf_token=' + encodeURIComponent(<?= json_encode($csrfToken) ?>)
    }).then(r => r.json()).then(d => {
        showToast(d.msg || (d.success ? '操作成功' : '操作失败'), d.success ? 'success' : 'error');
        if (d.success) loadVotes();
    }).catch(e => showToast('请求出错', 'error'));
}
loadVotes();
</script>

<?php adminFooter(); ?>
