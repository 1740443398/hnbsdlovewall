<?php
/**
 * 后台 —— 成长体系管理。
 *
 * 一个页面同时管三件事：
 *   ① 经验规则（哪些行为给多少经验、每日上限、总开关）
 *   ② 等级分布与经验榜（看现在的效果）
 *   ③ 成就解锁统计（看哪些成就太难/太易，便于调整）
 *
 * 规则改动**立即生效**，不需要重算历史数据 —— 因为等级永远由「累计总经验」推导，
 * 改规则只影响「从今往后的涨速」，不会让已有用户的等级突然跳变。
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../includes/level.php';
require_once __DIR__ . '/../includes/achievements.php';
require_once __DIR__ . '/layout.php';

$adminUser = requireAdmin();
$adminUser = checkBanned($adminUser);
$csrfToken = generateCSRFToken();

if (!checkPermission($adminUser, 'manage_growth')) {
    http_response_code(403);
    die('403 Forbidden - 权限不足');
}

adminHeader('成长体系', $adminUser, $csrfToken);
?>

<div class="stats-grid" id="statCards">
    <div class="stat-card">
        <div class="stat-icon"><svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/></svg></div>
        <div class="stat-info"><div class="stat-value" id="sUsers">-</div><div class="stat-label">参与成长用户</div></div>
    </div>
    <div class="stat-card info">
        <div class="stat-icon"><svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 2l2.9 6.26L21 9.27l-4.5 4.38.94 6.35L12 17.42l-5.44 2.58.94-6.35L3 9.27l6.1-1.01z"/></svg></div>
        <div class="stat-info"><div class="stat-value" id="sMaxLevel">-</div><div class="stat-label">当前最高等级</div></div>
    </div>
    <div class="stat-card success">
        <div class="stat-icon"><svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg></div>
        <div class="stat-info"><div class="stat-value" id="sUnlocks">-</div><div class="stat-label">成就累计解锁</div></div>
    </div>
    <div class="stat-card">
        <div class="stat-icon"><svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg></div>
        <div class="stat-info"><div class="stat-value" id="sCap">-</div><div class="stat-label">每日经验上限</div></div>
    </div>
</div>

<div class="section">
    <div class="section-header">
        <h3>经验规则</h3>
        <label style="display:flex;align-items:center;gap:8px;font-weight:400;font-size:14px;">
            <input type="checkbox" id="levelEnabled" style="width:auto;"> 启用成长体系
        </label>
    </div>
    <div class="section-body">
        <p style="color:var(--text-secondary);font-size:13px;margin:0 0 14px;">
            修改规则只影响之后的涨速，已获得的经验不受影响。设为 0 表示该行为不再给经验。
        </p>
        <div class="table-wrapper">
            <table>
                <thead><tr><th style="width:40%;">行为</th><th style="width:30%;">每次经验</th><th>说明</th></tr></thead>
                <tbody id="rulesBody"><tr><td colspan="3" class="loading-spinner">加载中</td></tr></tbody>
            </table>
        </div>
        <div class="form-inline" style="margin-top:16px;">
            <div class="form-group">
                <label>每日经验上限</label>
                <input type="number" id="dailyCap" min="1" max="100000" style="width:130px;">
            </div>
            <button class="btn btn-sm" onclick="saveRules()">保存规则</button>
            <button class="btn btn-outline btn-sm" onclick="recalc()">重算等级缓存</button>
        </div>
        <p style="color:var(--text-secondary);font-size:12px;margin:10px 0 0;">
            每日上限用于防刷：当天累到上限后不再获得经验，次日自动重置。
        </p>
    </div>
</div>

<div class="section">
    <div class="section-header"><h3>等级分布</h3></div>
    <div class="section-body">
        <div id="distBox"><div class="loading-spinner">加载中</div></div>
    </div>
</div>

<div class="section">
    <div class="section-header"><h3>经验榜 TOP 15</h3></div>
    <div class="section-body">
        <div class="table-wrapper">
            <table>
                <thead><tr><th style="width:60px;">名次</th><th>用户</th><th style="width:110px;">等级</th><th style="width:110px;">经验</th><th style="width:100px;">操作</th></tr></thead>
                <tbody id="topBody"><tr><td colspan="5" class="loading-spinner">加载中</td></tr></tbody>
            </table>
        </div>
    </div>
</div>

<div class="section">
    <div class="section-header"><h3>成就解锁统计</h3></div>
    <div class="section-body">
        <p style="color:var(--text-secondary);font-size:13px;margin:0 0 14px;">
            解锁人数为 0 的成就说明门槛过高或触发条件不易达成，可考虑下调；全部人都解锁则说明门槛过低。
        </p>
        <div class="table-wrapper">
            <table>
                <thead><tr><th style="width:56px;">图标</th><th>成就</th><th style="width:90px;">分组</th><th style="width:110px;">解锁人数</th></tr></thead>
                <tbody id="achBody"><tr><td colspan="4" class="loading-spinner">加载中</td></tr></tbody>
            </table>
        </div>
    </div>
</div>

<div class="modal-overlay" id="detailModal">
    <div class="modal">
        <div class="modal-header">
            <h3 id="detailTitle">用户成长明细</h3>
            <button class="modal-close" onclick="closeModal('detailModal')" aria-label="关闭">×</button>
        </div>
        <div class="modal-body" id="detailBody"></div>
        <div class="modal-footer">
            <button class="btn btn-outline" onclick="closeModal('detailModal')">关闭</button>
        </div>
    </div>
</div>

<script>
var growthCache = null;

function pad2(n) { return n < 10 ? '0' + n : '' + n; }
function fmtTime(t) {
    if (!t) return '-';
    if (typeof t === 'number' || /^\d+$/.test(String(t))) {
        var d = new Date(Number(t) * 1000);
        return d.getFullYear() + '-' + pad2(d.getMonth() + 1) + '-' + pad2(d.getDate()) + ' ' + pad2(d.getHours()) + ':' + pad2(d.getMinutes());
    }
    return String(t).substring(0, 16);
}

function api(payload) {
    var body = new URLSearchParams(payload);
    body.set('csrf_token', CSRF_TOKEN);
    return fetch(SITE_URL + '/api/admin/growth.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: body
    }).then(function (r) { return r.json(); });
}

function loadGrowth() {
    api({ action: 'overview' }).then(function (res) {
        if (!res.success) { showToast(res.message || '加载失败', 'error'); return; }
        growthCache = res.data;
        renderStats(res.data);
        renderRules(res.data);
        renderDist(res.data);
        renderTop(res.data);
        renderAch(res.data);
    }).catch(function () { showToast('网络错误', 'error'); });
}

function renderStats(d) {
    var maxLv = 0;
    Object.keys(d.distribution || {}).forEach(function (k) {
        var n = parseInt(k, 10);
        if (n > maxLv) maxLv = n;
    });
    document.getElementById('sUsers').textContent = d.total_users || 0;
    document.getElementById('sMaxLevel').textContent = maxLv > 0 ? ('Lv.' + maxLv) : '-';
    document.getElementById('sUnlocks').textContent = d.total_unlocks || 0;
    document.getElementById('sCap').textContent = d.daily_cap || 0;
}

function renderRules(d) {
    document.getElementById('levelEnabled').checked = !!d.enabled;
    document.getElementById('dailyCap').value = d.daily_cap;
    var tb = document.getElementById('rulesBody');
    var titles = {};
    (d.level_titles || []).forEach(function (t) { titles[t.level] = t.title; });
    tb.innerHTML = (d.rules || []).map(function (r) {
        return '<tr>' +
            '<td>' + esc(r.label) + '</td>' +
            '<td><input type="number" min="0" max="10000" style="width:110px;" data-rule="' + esc(r.key) + '" value="' + r.exp + '"></td>' +
            '<td><code style="font-size:12px;color:var(--text-secondary);">' + esc(r.key) + '</code></td>' +
            '</tr>';
    }).join('') || '<tr><td colspan="3" class="loading-spinner">暂无规则</td></tr>';
}

function renderDist(d) {
    var box = document.getElementById('distBox');
    var dist = d.distribution || {};
    var keys = Object.keys(dist);
    if (!keys.length) {
        box.innerHTML = '<div class="empty-state"><p>还没有用户获得经验</p></div>';
        return;
    }
    var max = Math.max.apply(null, keys.map(function (k) { return dist[k]; }));
    box.innerHTML = keys.map(function (k) {
        var n = dist[k];
        var pct = max > 0 ? Math.round(n / max * 100) : 0;
        return '<div style="display:flex;align-items:center;gap:12px;margin-bottom:8px;">' +
            '<span style="width:56px;font-size:13px;color:var(--text-secondary);">Lv.' + esc(k) + '</span>' +
            // 进度条：轨道与填充都走变量。原先写死 #f1f5f9 / #111827，
            // 暗色下会变成「近白轨道 + 近黑填充」，与主题完全反着来。
            '<span style="flex:1;background:var(--primary-light);border-radius:6px;height:20px;overflow:hidden;">' +
                '<span style="display:block;height:100%;width:' + pct + '%;background:var(--primary);border-radius:6px;"></span>' +
            '</span>' +
            '<span style="width:52px;text-align:right;font-size:13px;">' + n + ' 人</span>' +
            '</div>';
    }).join('');
}

function renderTop(d) {
    var tb = document.getElementById('topBody');
    var list = d.exp_top || [];
    if (!list.length) {
        tb.innerHTML = '<tr><td colspan="5"><div class="empty-state"><p>暂无数据</p></div></td></tr>';
        return;
    }
    tb.innerHTML = list.map(function (t, i) {
        return '<tr>' +
            '<td>' + (i + 1) + '</td>' +
            '<td>' + esc(t.nickname) + ' <span style="color:var(--text-secondary);font-size:12px;">' + esc(t.qq) + '</span></td>' +
            '<td>Lv.' + esc(t.level) + '</td>' +
            '<td>' + esc(t.exp) + '</td>' +
            '<td><button class="btn btn-outline btn-sm" onclick="openDetail(' + t.user_id + ')">详情</button></td>' +
            '</tr>';
    }).join('');
}

function renderAch(d) {
    var tb = document.getElementById('achBody');
    var list = d.achievements || [];
    tb.innerHTML = list.map(function (a) {
        return '<tr>' +
            '<td style="font-size:18px;">' + esc(a.icon) + '</td>' +
            '<td>' + esc(a.name) + '<div style="color:var(--text-secondary);font-size:12px;">' + esc(a.desc) + '</div></td>' +
            '<td>' + esc(a.group) + '</td>' +
            '<td>' + esc(a.unlocked) + ' 人</td>' +
            '</tr>';
    }).join('') || '<tr><td colspan="4" class="loading-spinner">暂无成就</td></tr>';
}

function saveRules() {
    var rules = [];
    document.querySelectorAll('[data-rule]').forEach(function (el) {
        rules.push({ key: el.getAttribute('data-rule'), exp: parseInt(el.value, 10) || 0 });
    });
    api({
        action: 'save_rules',
        enabled: document.getElementById('levelEnabled').checked ? '1' : '0',
        daily_cap: document.getElementById('dailyCap').value,
        rules: JSON.stringify(rules)
    }).then(function (res) {
        showToast(res.message || (res.success ? '已保存' : '保存失败'), res.success ? 'success' : 'error');
        if (res.success) loadGrowth();
    }).catch(function () { showToast('网络错误', 'error'); });
}

function recalc() {
    api({ action: 'recalc' }).then(function (res) {
        showToast(res.message || (res.success ? '完成' : '失败'), res.success ? 'success' : 'error');
        if (res.success) loadGrowth();
    }).catch(function () { showToast('网络错误', 'error'); });
}

function openDetail(uid) {
    document.getElementById('detailBody').innerHTML = '<div class="loading-spinner">加载中</div>';
    openModal('detailModal');
    api({ action: 'user_detail', user_id: uid }).then(function (res) {
        if (!res.success) {
            document.getElementById('detailBody').innerHTML = '<p>' + esc(res.message || '加载失败') + '</p>';
            return;
        }
        var d = res.data;
        document.getElementById('detailTitle').textContent = d.user.nickname + ' 的成长明细';
        var rows = (d.achievements.items || []).map(function (it) {
            return '<div style="display:flex;align-items:center;gap:8px;padding:4px 0;' + (it.unlocked ? '' : 'opacity:.45;') + '">' +
                '<span>' + esc(it.icon) + '</span><span style="flex:1;">' + esc(it.name) + '</span>' +
                '<span style="font-size:12px;color:var(--text-secondary);">' + (it.unlocked ? '已解锁' : (it.current + '/' + it.need)) + '</span>' +
                '</div>';
        }).join('');
        document.getElementById('detailBody').innerHTML =
            '<div style="margin-bottom:14px;font-size:14px;line-height:1.9;">' +
            '等级：<b>Lv.' + esc(d.level.level) + ' ' + esc(d.level.title) + '</b><br>' +
            '总经验：<b>' + esc(d.level.exp) + '</b>（今日 +' + esc(d.level.today_exp) + '）<br>' +
            '经验榜排名：<b>' + (d.level.rank > 0 ? ('第 ' + d.level.rank + ' 名 / 共 ' + d.level.rank_total + ' 人') : '未上榜') + '</b><br>' +
            '成就：<b>' + esc(d.achievements.unlocked) + ' / ' + esc(d.achievements.total) + '</b>' +
            '</div>' +
            '<div style="border-top:1px solid #e2e8f0;padding-top:10px;max-height:340px;overflow:auto;">' + rows + '</div>' +
            '<div style="margin-top:12px;">' +
            '<button class="btn btn-danger btn-sm" onclick="resetUser(' + d.user.id + ')">重置该用户成长数据</button>' +
            '</div>';
    }).catch(function () {
        document.getElementById('detailBody').innerHTML = '<p>网络错误</p>';
    });
}

function resetUser(uid) {
    confirmDialog('重置成长数据', '重置后该用户的等级与成就会被清空（其帖子与评论不受影响），确定继续？', function () {
        api({ action: 'reset_user', user_id: uid }).then(function (res) {
            showToast(res.message || (res.success ? '已重置' : '失败'), res.success ? 'success' : 'error');
            if (res.success) { closeModal('detailModal'); loadGrowth(); }
        }).catch(function () { showToast('网络错误', 'error'); });
    });
}

loadGrowth();
</script>

<?php adminFooter(); ?>
