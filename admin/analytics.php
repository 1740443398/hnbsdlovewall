<?php
/**
 * 后台：数据看板（趋势报表）
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/security.php';

$admin = requireAdmin();
$admin = checkBanned($admin);
if (!empty($admin['is_banned'])) { header('Location: /403.php'); exit; }

$page = 'analytics.php';
$pageTitle = '数据看板';
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
.an-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:12px;margin-bottom:20px}
.an-card{background:#fff;border-radius:10px;padding:16px;border-left:4px solid #2F5B9A}
.an-card .n{font-size:1.8rem;font-weight:700;color:#1a202c;line-height:1.1}
.an-card .l{font-size:.78rem;color:#718096;margin-top:4px}
.an-card .d{font-size:.75rem;margin-top:6px;font-weight:600}
.an-up{color:#38a169}
.an-down{color:#e53e3e}
.an-panel{background:#fff;border-radius:10px;padding:18px;margin-bottom:16px}
.an-panel h3{margin:0 0 14px;font-size:.95rem;color:#2d3748}
.an-chart{height:200px;display:flex;align-items:flex-end;gap:3px;padding-top:10px}
.an-bar{flex:1;background:linear-gradient(180deg,#4a7bc8,#2F5B9A);border-radius:3px 3px 0 0;min-height:2px;position:relative}
.an-bar:hover{background:#e08a2e}
.an-bar span{position:absolute;bottom:-18px;left:50%;transform:translateX(-50%);font-size:.6rem;color:#a0aec0;white-space:nowrap}
.an-legend{display:flex;gap:16px;font-size:.78rem;color:#718096;margin-top:24px;flex-wrap:wrap}
.an-legend i{display:inline-block;width:10px;height:10px;border-radius:2px;margin-right:4px}
.an-row{display:flex;justify-content:space-between;padding:8px 0;border-bottom:1px solid #f7fafc;font-size:.86rem}
.an-row:last-child{border:none}
</style>

<div class="page-header">
    <h1 class="page-title">数据看板</h1>
    <p class="page-desc">内容与用户增长趋势（用于运营决策）</p>
    <select id="range" style="padding:7px 12px;border:1px solid #dfe4ea;border-radius:8px;font-size:.85rem">
        <option value="7">最近 7 天</option>
        <option value="30" selected>最近 30 天</option>
        <option value="90">最近 90 天</option>
    </select>
</div>

<div class="an-grid" id="cards"></div>

<div class="an-panel">
    <h3>每日发帖量</h3>
    <div class="an-chart" id="chartPosts"></div>
    <div class="an-legend">
        <span><i style="background:#2F5B9A"></i>发帖</span>
        <span><i style="background:#4a7bc8"></i>鼠标悬停看具体数值</span>
    </div>
</div>

<div class="an-panel">
    <h3>每日评论量</h3>
    <div class="an-chart" id="chartComments"></div>
</div>

<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(280px,1fr));gap:16px">
    <div class="an-panel">
        <h3>分类分布</h3>
        <div id="cats"></div>
    </div>
    <div class="an-panel">
        <h3>活跃作者 Top 10</h3>
        <div id="authors"></div>
    </div>
</div>

<script>
var DATA = null;

function esc(s){ return String(s==null?'':s).replace(/[&<>"']/g,function(c){
    return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]; }); }

function card(label, cmp, unit){
    var pct = cmp.change_pct;
    var cls = pct >= 0 ? 'an-up' : 'an-down';
    var arrow = pct >= 0 ? '↑' : '↓';
    return '<div class="an-card"><div class="n">' + cmp.current + '</div>' +
        '<div class="l">' + label + (unit ? '（' + unit + '）' : '') + '</div>' +
        '<div class="d ' + cls + '">' + arrow + ' ' + Math.abs(pct) + '% 环比</div></div>';
}

function renderChart(el, rows, key){
    var max = 1;
    rows.forEach(function(r){ if (r[key] > max) max = r[key]; });
    var html = '';
    var step = Math.max(1, Math.floor(rows.length / 15));
    rows.forEach(function(r, i){
        var h = Math.round((r[key] / max) * 170);
        html += '<div class="an-bar" style="height:' + Math.max(2, h) + 'px" title="' +
            r.date + ': ' + r[key] + '"><span>' +
            (i % step === 0 ? r.date.slice(5) : '') + '</span></div>';
    });
    document.getElementById(el).innerHTML = html;
}

function load(){
    var days = document.getElementById('range').value;
    fetch('/api/admin/analytics.php?action=overview&days=' + days, {credentials:'same-origin'})
    .then(function(r){ return r.json(); })
    .then(function(d){
        if (!d.success) { alert(d.message || '加载失败'); return; }
        DATA = d.data;
        var c = d.data.compare;
        document.getElementById('cards').innerHTML =
            card('发帖', c.posts) +
            card('评论', c.comments) +
            card('新增用户', c.users) +
            card('点赞', c.likes);

        renderChart('chartPosts', d.data.series, 'posts');
        renderChart('chartComments', d.data.series, 'comments');

        var total = d.data.categories.reduce(function(a, x){ return a + x.count; }, 0) || 1;
        var ch = '';
        d.data.categories.forEach(function(x){
            var pct = Math.round(x.count * 100 / total);
            ch += '<div class="an-row"><span>' + esc(x.category) + '</span>' +
                  '<span style="color:#718096">' + x.count + ' 篇 · ' + pct + '%</span></div>';
        });
        document.getElementById('cats').innerHTML = ch || '<div style="color:#a0aec0">暂无数据</div>';

        var ah = '';
        d.data.top_authors.forEach(function(a, i){
            ah += '<div class="an-row"><span>' + (i+1) + '. ' + esc(a.name) + '</span>' +
                  '<span style="color:#718096">' + a.posts + ' 篇</span></div>';
        });
        document.getElementById('authors').innerHTML = ah || '<div style="color:#a0aec0">暂无数据</div>';
    });
}

document.getElementById('range').addEventListener('change', load);
load();
</script>

<?php adminFooter(); ?>
