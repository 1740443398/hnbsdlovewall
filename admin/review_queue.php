<?php
/**
 * 后台：内容审核队列（页面）
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/security.php';

$admin = requireAdmin();
$admin = checkBanned($admin);
if (!empty($admin['is_banned'])) { header('Location: /403.php'); exit; }
if (!checkPermission($admin, 'view_reports')) { header('Location: /admin/dashboard.php'); exit; }

$page = 'review_queue.php';
$pageTitle = '审核队列';
require __DIR__ . '/layout.php';
?>
<style>
.rq-tabs{display:flex;gap:8px;margin-bottom:16px;flex-wrap:wrap}
.rq-tab{padding:7px 16px;border-radius:8px;border:1px solid #dfe4ea;background:#fff;cursor:pointer;font-size:.86rem;color:#4a5568}
.rq-tab.active{background:#2F5B9A;color:#fff;border-color:#2F5B9A}
.rq-stats{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:12px;margin-bottom:18px}
.rq-stat{background:#fff;border-radius:10px;padding:14px 16px;border-left:4px solid #2F5B9A}
.rq-stat .n{font-size:1.7rem;font-weight:700;color:#1a202c}
.rq-stat .l{font-size:.78rem;color:#718096;margin-top:2px}
.rq-item{background:#fff;border-radius:10px;padding:14px 16px;margin-bottom:10px;border:1px solid #edf1f5}
.rq-item.processing{border-left:4px solid #e08a2e}
.rq-item.handled{opacity:.65}
.rq-item h4{margin:0 0 6px;font-size:.95rem;color:#2d3748}
.rq-item .meta{font-size:.76rem;color:#a0aec0;margin-bottom:8px}
.rq-item .excerpt{font-size:.85rem;color:#4a5568;background:#f7fafc;padding:8px 10px;border-radius:6px;margin-bottom:10px;line-height:1.6}
.rq-item .acts{display:flex;gap:8px;flex-wrap:wrap}
.rq-empty{text-align:center;padding:50px;color:#a0aec0;background:#fff;border-radius:10px}
</style>

<div class="page-header">
    <h1 class="page-title">审核队列</h1>
    <p class="page-desc">举报处理闭环：待处理 → 认领 → 处理并归档</p>
</div>

<div class="rq-stats" id="stats"></div>

<div class="rq-tabs">
    <button class="rq-tab active" data-status="pending">待处理</button>
    <button class="rq-tab" data-status="processing">处理中</button>
    <button class="rq-tab" data-status="handled">已处理</button>
    <button class="rq-tab" data-status="all">全部</button>
    <input type="text" id="kw" placeholder="搜索理由/详情" style="margin-left:auto;padding:7px 12px;border:1px solid #dfe4ea;border-radius:8px;font-size:.85rem">
</div>

<div id="list"></div>

<script>
var STATUS = 'pending';
var PAGE = 1;
var selected = {};

function esc(s){ return String(s == null ? '' : s).replace(/[&<>"']/g, function(c){
    return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c];
}); }

function loadStats(){
    fetch('/api/admin/review_queue.php?action=stats', {credentials:'same-origin'})
    .then(function(r){ return r.json(); })
    .then(function(d){
        if (!d.success) return;
        var s = d.data;
        document.getElementById('stats').innerHTML =
            '<div class="rq-stat"><div class="n">' + s.pending + '</div><div class="l">待处理</div></div>' +
            '<div class="rq-stat"><div class="n">' + s.today + '</div><div class="l">今日新增</div></div>' +
            '<div class="rq-stat"><div class="n">' + s.handled + '</div><div class="l">已处理</div></div>' +
            '<div class="rq-stat"><div class="n">' + s.total + '</div><div class="l">累计举报</div></div>';
    });
}

function resultLabel(r){
    return {'deleted':'已删除内容','ignored':'已忽略','warned':'已警告'}[r] || r || '';
}

function loadList(){
    var kw = document.getElementById('kw').value.trim();
    var url = '/api/admin/review_queue.php?action=list&status=' + STATUS + '&page=' + PAGE + '&limit=20'
        + (kw ? '&keyword=' + encodeURIComponent(kw) : '');
    document.getElementById('list').innerHTML = '<div class="rq-empty">加载中…</div>';
    fetch(url, {credentials:'same-origin'})
    .then(function(r){ return r.json(); })
    .then(function(d){
        if (!d.success) {
            document.getElementById('list').innerHTML = '<div class="rq-empty">' + esc(d.message) + '</div>';
            return;
        }
        var items = d.data.items || [];
        if (!items.length) {
            document.getElementById('list').innerHTML = '<div class="rq-empty">这里空空如也 —— 没有待处理的举报</div>';
            return;
        }
        var html = '';
        items.forEach(function(it){
            html += '<div class="rq-item ' + esc(it.status) + '">';
            html += '<h4>' + esc(it.type) + ' #' + it.target_id + ' · ' + esc(it.reason) + '</h4>';
            html += '<div class="meta">举报人 ' + esc(it.reporter) + ' · ' + esc(it.created_at);
            if (it.handler) html += ' · 处理人 ' + esc(it.handler);
            if (it.result) html += ' · 结果 ' + esc(resultLabel(it.result));
            html += '</div>';
            if (it.detail) html += '<div class="excerpt">' + esc(it.detail) + '</div>';
            if (it.excerpt) html += '<div class="excerpt">内容：' + esc(it.excerpt) + '</div>';
            if (it.status === 'pending' || it.status === 'processing') {
                html += '<div class="acts">';
                html += '<button class="btn btn-sm btn-primary" onclick="act(\'assign\',' + it.id + ')">认领</button>';
                html += '<button class="btn btn-sm btn-danger" onclick="act(\'resolve\',' + it.id + ',\'deleted\')">删除内容</button>';
                html += '<button class="btn btn-sm" onclick="act(\'resolve\',' + it.id + ',\'ignored\')">忽略</button>';
                html += '<button class="btn btn-sm" onclick="act(\'resolve\',' + it.id + ',\'warned\')">警告作者</button>';
                html += '</div>';
            }
            html += '</div>';
        });
        // 批量处理
        if (d.data.pagination && d.data.pagination.total > 20) {
            html += '<div style="text-align:center;margin-top:16px">';
            html += '<button class="btn btn-sm" onclick="PAGE=Math.max(1,PAGE-1);loadList()">上一页</button>';
            html += ' <span style="color:#a0aec0;font-size:.85rem">第 ' + PAGE + ' 页 / 共 ' + d.data.pagination.total + ' 条</span> ';
            html += '<button class="btn btn-sm" onclick="PAGE=PAGE+1;loadList()">下一页</button>';
            html += '</div>';
        }
        document.getElementById('list').innerHTML = html;
    });
}

function act(kind, id, result){
    var body = 'action=' + kind + '&id=' + id + '&csrf_token=' + encodeURIComponent(window.CSRF_TOKEN || '');
    if (result) body += '&result=' + result;
    fetch('/api/admin/review_queue.php', {
        method:'POST',
        headers:{'Content-Type':'application/x-www-form-urlencoded'},
        body: body, credentials:'same-origin'
    }).then(function(r){ return r.json(); }).then(function(d){
        if (d.success) { loadStats(); loadList(); }
        else alert(d.message || '操作失败');
    }).catch(function(){ alert('网络错误'); });
}

document.querySelectorAll('.rq-tab').forEach(function(b){
    b.addEventListener('click', function(){
        document.querySelectorAll('.rq-tab').forEach(function(x){ x.classList.remove('active'); });
        b.classList.add('active');
        STATUS = b.dataset.status;
        PAGE = 1;
        loadList();
    });
});
document.getElementById('kw').addEventListener('keydown', function(e){
    if (e.key === 'Enter') { PAGE = 1; loadList(); }
});

loadStats();
loadList();
setInterval(loadStats, 60000);
</script>

<?php adminFooter(); ?>
