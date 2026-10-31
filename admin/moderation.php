<?php
/**
 * 后台：内容审核流水线
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/security.php';

$admin = requireAdmin();
$admin = checkBanned($admin);
if (!empty($admin['is_banned'])) { header('Location: /403.php'); exit; }
if (!checkPermission($admin, 'view_reports')) { header('Location: /admin/dashboard.php'); exit; }

$page = 'moderation.php';
$pageTitle = '内容审核';
require __DIR__ . '/layout.php';

/* 补齐 adminHeader()。
 *
 * 原来只有 adminFooter() 没有 adminHeader() —— 结果是页面「有脚没头」：
 * 没有侧边栏、没有顶栏、没有 layout.php 的配色与暗色模式支持，
 * 跟 dashboard / users 那些页面完全不像一家人。
 *
 * 这个疏漏是从旧代码继承下来的：当初写这些页面时只想着「底部加个版权」，
 * 忘了整页骨架都得由 layout.php 提供。
 */
$csrfToken = generateCSRFToken();
adminHeader($pageTitle, $admin, $csrfToken);
?>
<style>
.md-stats{display:grid;grid-template-columns:repeat(auto-fit,minmax(140px,1fr));gap:12px;margin-bottom:16px}
.md-stat{background:var(--card-bg);border-radius:10px;padding:14px 16px;border-left:4px solid var(--primary)}
.md-stat .n{font-size:1.7rem;font-weight:700;color:var(--text)}
.md-stat .l{font-size:.78rem;color:var(--text-secondary);margin-top:2px}
.md-types{display:flex;gap:8px;flex-wrap:wrap;margin-bottom:16px}
.md-type{padding:6px 14px;border-radius:999px;font-size:.82rem;cursor:pointer;border:1px solid transparent;background:var(--bg-secondary);color:var(--text-secondary)}
.md-type:hover{opacity:.8}
.md-type.on{color:var(--card-bg)}
.md-tabs{display:flex;gap:8px;margin-bottom:14px;flex-wrap:wrap}
.md-tab{padding:7px 16px;border-radius:8px;border:1px solid #dfe4ea;background:var(--card-bg);cursor:pointer;font-size:.85rem;color:var(--text-secondary)}
.md-tab.active{background:var(--primary);color:var(--card-bg);border-color:var(--primary)}
.md-item{background:var(--card-bg);border-radius:10px;padding:14px 16px;margin-bottom:10px;border:1px solid var(--bg-secondary)}
.md-item.pending{border-left:4px solid #d92633}
.md-item.review{border-left:4px solid var(--warning)}
.md-item h4{margin:0 0 6px;font-size:.95rem;color:var(--text)}
.md-item .mt{font-size:.76rem;color:var(--text-muted);margin-bottom:8px;display:flex;gap:12px;flex-wrap:wrap;align-items:center}
.md-item .body{font-size:.86rem;color:var(--text-secondary);background:var(--bg-secondary);padding:10px 12px;border-radius:6px;line-height:1.65;margin-bottom:10px;white-space:pre-wrap}
.md-badge{display:inline-block;padding:2px 9px;border-radius:999px;font-size:.72rem;color:var(--card-bg);font-weight:600}
.md-score{font-size:.78rem;font-weight:700;padding:2px 8px;border-radius:6px;background:#edf2f7;color:var(--text)}
.md-why{font-size:.78rem;color:var(--text-secondary);margin-bottom:10px;line-height:1.7}
.md-why b{color:var(--text-secondary)}
.md-acts{display:flex;gap:8px;flex-wrap:wrap}
.md-empty{text-align:center;padding:50px;color:var(--text-muted);background:var(--card-bg);border-radius:10px}
</style>

<div class="page-header">
    <h1 class="page-title">内容审核</h1>
    <p class="page-desc">发布时自动审核（AI + 关键词 + 结构），未通过的人工复核</p>
</div>

<div class="md-stats" id="stats"></div>
<div class="md-types" id="types"></div>

<div class="md-tabs">
    <button class="md-tab active" data-status="pending">待审（未发布）</button>
    <button class="md-tab" data-status="review">已发布待查</button>
    <button class="md-tab" data-status="flagged">全部标记</button>
    <button class="md-tab" data-status="reject">已拒绝</button>
    <button class="md-tab" data-status="all">全部</button>
</div>

<div id="list"></div>

<script>
var STATUS = 'pending';
var TYPE = '';
var PAGE = 1;
var TYPE_DEFS = {};

function esc(s){ return String(s==null?'':s).replace(/[&<>"']/g,function(c){
    return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]; }); }

function loadStats(){
    fetch('/api/admin/moderation.php?action=stats', {credentials:'same-origin'})
    .then(function(r){ return r.json(); })
    .then(function(d){
        if (!d.success) return;
        var s = d.data;
        TYPE_DEFS = s.types || {};
        document.getElementById('stats').innerHTML =
            '<div class="md-stat" style="border-left-color:#d92633"><div class="n">' + s.pending + '</div><div class="l">待审未发布</div></div>' +
            '<div class="md-stat" style="border-left-color:#e08a2e"><div class="n">' + s.review + '</div><div class="l">已发布待查</div></div>' +
            '<div class="md-stat"><div class="n">' + s.rejected + '</div><div class="l">已拒绝</div></div>' +
            '<div class="md-stat"><div class="n">' + s.total_flagged + '</div><div class="l">共需处理</div></div>';

        var th = '<span class="md-type' + (TYPE === '' ? ' on" style="background:#2F5B9A"' : '"') + '" onclick="setType(\\'\\')">全部类型</span>';
        (s.by_type || []).forEach(function(t){
            var on = TYPE === t.type;
            th += '<span class="md-type' + (on ? ' on' : '') + '" style="' +
                (on ? 'background:' + t.color : 'border-color:' + t.color + '40;color:' + t.color) +
                '" onclick="setType(\\'' + t.type + '\\')">' + esc(t.label) + ' ' + t.count + '</span>';
        });
        document.getElementById('types').innerHTML = th;
    });
}

function setType(t){ TYPE = t; PAGE = 1; loadStats(); loadList(); }

function statusLabel(s){
    return {pass:'通过', review:'已发布待查', pending:'待审', reject:'已拒绝'}[s] || s;
}

function loadList(){
    var url = '/api/admin/moderation.php?action=list&status=' + STATUS + '&page=' + PAGE + '&limit=20'
        + (TYPE ? '&type=' + TYPE : '');
    document.getElementById('list').innerHTML = '<div class="md-empty">加载中…</div>';
    fetch(url, {credentials:'same-origin'})
    .then(function(r){ return r.json(); })
    .then(function(d){
        if (!d.success) { document.getElementById('list').innerHTML = '<div class="md-empty">' + esc(d.message) + '</div>'; return; }
        var items = d.data.items || [];
        if (!items.length) {
            document.getElementById('list').innerHTML = '<div class="md-empty">没有需要处理的内容</div>';
            return;
        }
        var html = '';
        items.forEach(function(it){
            var badges = (it.types || []).map(function(t){
                return '<span class="md-badge" style="background:' + t.color + '">' + esc(t.label) + '</span>';
            }).join(' ');
            html += '<div class="md-item ' + esc(it.mod_status) + '">';
            html += '<h4>' + (it.title ? esc(it.title) : '（无标题）') + '</h4>';
            html += '<div class="mt">' + badges;
            html += '<span class="md-score">风险 ' + it.mod_score + '</span>';
            html += '<span>' + esc(it.author) + '</span>';
            html += '<span>' + esc(it.created_at) + '</span>';
            html += '<span>状态 ' + esc(statusLabel(it.mod_status)) + '</span></div>';
            html += '<div class="body">' + esc(it.content) + '</div>';

            if ((it.reasons || []).length) {
                html += '<div class="md-why"><b>判定依据：</b>';
                html += it.reasons.map(function(r){
                    var src = {ai:'AI', keyword:'关键词', structural:'结构'}[r.layer] || r.layer;
                    return '[' + src + '] ' + esc(r.label) + (r.word ? '（' + esc(r.word) + '）' : '');
                }).join('；');
                html += '</div>';
            }
            // 分层得分
            if (it.layers && it.layers.ai) {
                html += '<div class="md-why">分层：AI ' + (it.layers.ai.ok ? it.layers.ai.score + '分' : '未启用/超时')
                    + ' · 关键词 ' + (it.layers.keyword ? it.layers.keyword.score : 0) + '分'
                    + ' · 结构 ' + (it.layers.structural ? it.layers.structural.score : 0) + '分</div>';
            }

            if (it.mod_status === 'pending' || it.mod_status === 'review') {
                html += '<div class="md-acts">';
                html += '<button class="btn btn-sm btn-primary" onclick="act(\\'approve\\',' + it.id + ')">通过并发布</button>';
                html += '<button class="btn btn-sm btn-danger" onclick="act(\\'reject\\',' + it.id + ')">拒绝并隐藏</button>';
                html += '<a class="btn btn-sm" href="/pages/post_detail.php?id=' + it.id + '" target="_blank">查看原帖</a>';
                html += '</div>';
            }
            html += '</div>';
        });
        var pg = d.data.pagination;
        if (pg && pg.total > 20) {
            html += '<div style="text-align:center;margin-top:16px">';
            html += '<button class="btn btn-sm" onclick="PAGE=Math.max(1,PAGE-1);loadList()">上一页</button>';
            html += ' <span style="color:#a0aec0;font-size:.85rem">第 ' + PAGE + ' 页 / 共 ' + pg.total + ' 条</span> ';
            html += '<button class="btn btn-sm" onclick="PAGE=PAGE+1;loadList()">下一页</button></div>';
        }
        document.getElementById('list').innerHTML = html;
    });
}

function act(op, id){
    fetch('/api/admin/moderation.php', {
        method:'POST',
        headers:{'Content-Type':'application/x-www-form-urlencoded'},
        body: 'action=' + op + '&id=' + id + '&csrf_token=' + encodeURIComponent(window.CSRF_TOKEN || ''),
        credentials:'same-origin'
    }).then(function(r){ return r.json(); }).then(function(d){
        if (d.success) { loadStats(); loadList(); }
        else alert(d.message || '操作失败');
    }).catch(function(){ alert('网络错误'); });
}

document.querySelectorAll('.md-tab').forEach(function(b){
    b.addEventListener('click', function(){
        document.querySelectorAll('.md-tab').forEach(function(x){ x.classList.remove('active'); });
        b.classList.add('active');
        STATUS = b.dataset.status; PAGE = 1; loadList();
    });
});

loadStats();
loadList();
</script>

<?php adminFooter(); ?>
