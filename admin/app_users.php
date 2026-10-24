<?php
/**
 * 后台：App 装机台账
 * ---------------------------------------------------------------------------
 * 回答「有没有人装了 App、装了多少、还在不在用」这三个问题。
 *
 * 数据来源：App 内的上报任务（首装一次 + 之后定期心跳）打 api/app_report.php，
 * 落到 data/app_devices.json。也就是说这里列出的每一台设备，都是**真的在跑 App**，
 * 而不是「访问过网页」——后者由前台在线统计承担，两者口径不同，不要混着看。
 *
 * 隐私边界：只收机型 / 系统 / App 版本 / 首末活跃时间，不收 IMEI、手机号、通讯录；
 * device_id 由客户端随机生成、卸载重装即换，列表里也只展示前 12 位。
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/layout.php';

$adminUser = requireAdmin();
$adminUser = checkBanned($adminUser);
$csrfToken = generateCSRFToken();

if (!checkPermission($adminUser, 'view_app_devices')) {
    header('Location: /admin/index.php');
    exit;
}

adminHeader('App 装机台账', $adminUser, $csrfToken);
?>

<div class="stats-grid" id="appStats">
    <div class="stat-card"><div class="stat-icon"><svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="5" y="2" width="14" height="20" rx="2"/><line x1="12" y1="18" x2="12.01" y2="18"/></svg></div><div class="stat-info"><div class="stat-value" id="sTotal">-</div><div class="stat-label">累计装机设备</div></div></div>
    <div class="stat-card success"><div class="stat-icon"><svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 12h-4l-3 9L9 3l-3 9H2"/></svg></div><div class="stat-info"><div class="stat-value" id="sOnline">-</div><div class="stat-label">当前在线（5 分钟内）</div></div></div>
    <div class="stat-card info"><div class="stat-icon"><svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg></div><div class="stat-info"><div class="stat-value" id="sToday">-</div><div class="stat-label">今日活跃设备</div></div></div>
    <div class="stat-card info"><div class="stat-icon"><svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 3v18h18"/><path d="M18 17V9M13 17V5M8 17v-3"/></svg></div><div class="stat-info"><div class="stat-value" id="sWeek">-</div><div class="stat-label">近 7 日活跃设备</div></div></div>
    <div class="stat-card warning"><div class="stat-icon"><svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg></div><div class="stat-info"><div class="stat-value" id="sBound">-</div><div class="stat-label">已登录绑定账号</div></div></div>
</div>

<div class="section">
    <div class="section-header"><h3>版本分布</h3></div>
    <div class="section-body">
        <div id="verDist"><div class="empty-state"><p style="color:var(--text-secondary);">加载中…</p></div></div>
    </div>
</div>

<div class="section">
    <div class="section-header"><h3>设备台账</h3></div>
    <div class="section-body">
        <div class="form-inline" style="margin-bottom:16px;flex-wrap:wrap;gap:12px;">
            <div class="form-group">
                <label for="fKeyword">关键词</label>
                <input type="text" id="fKeyword" placeholder="机型 / 厂商 / 系统 / 版本 / QQ" maxlength="50" onkeydown="if(event.key==='Enter'){applyFilter();}">
            </div>
            <div class="form-group">
                <label for="fSort">排序</label>
                <select id="fSort">
                    <option value="last">按最后活跃时间</option>
                    <option value="first">按首次安装时间</option>
                </select>
            </div>
            <div class="form-group">
                <label for="fOnlyBound" style="display:flex;align-items:center;gap:6px;cursor:pointer;">
                    <input type="checkbox" id="fOnlyBound" style="width:auto;"> 只看已绑定账号
                </label>
            </div>
            <button type="button" class="btn btn-primary btn-sm" onclick="applyFilter()">筛选</button>
            <button type="button" class="btn btn-outline btn-sm" onclick="resetFilter()">重置</button>
        </div>

        <div class="table-wrapper">
            <table>
                <thead>
                    <tr>
                        <th>设备</th>
                        <th>账号</th>
                        <th>机型</th>
                        <th>系统</th>
                        <th>App 版本</th>
                        <th>首次安装</th>
                        <th>最后活跃</th>
                        <th>状态</th>
                    </tr>
                </thead>
                <tbody id="devBody">
                    <tr><td colspan="8" class="loading-spinner">加载中</td></tr>
                </tbody>
            </table>
        </div>
        <div class="pagination" id="devPagination"></div>
    </div>
</div>

<script>
(function () {
    var currentPage = 1;
    var LIMIT = 30;

    function fmtTime(t) {
        if (!t) return '-';
        var s = String(t);
        if (/^\d+$/.test(s)) {
            var d = new Date(Number(s) * 1000);
            function p(n) { return n < 10 ? '0' + n : '' + n; }
            return d.getFullYear() + '-' + p(d.getMonth() + 1) + '-' + p(d.getDate()) + ' ' + p(d.getHours()) + ':' + p(d.getMinutes());
        }
        return s.substring(0, 16).replace('T', ' ');
    }

    function ago(t) {
        if (!t) return '';
        var ts = /^\d+$/.test(String(t)) ? Number(t) : Date.parse(String(t)) / 1000;
        if (!ts) return '';
        var diff = Math.max(0, Math.floor(Date.now() / 1000 - ts));
        if (diff < 60) return diff + ' 秒前';
        if (diff < 3600) return Math.floor(diff / 60) + ' 分钟前';
        if (diff < 86400) return Math.floor(diff / 3600) + ' 小时前';
        if (diff < 2592000) return Math.floor(diff / 86400) + ' 天前';
        return '';
    }

    function esc2(v) { return esc(v === null || v === undefined || v === '' ? '-' : v); }

    function setStats(st) {
        document.getElementById('sTotal').textContent = st.total || 0;
        document.getElementById('sOnline').textContent = st.online || 0;
        document.getElementById('sToday').textContent = st.today || 0;
        document.getElementById('sWeek').textContent = st.week || 0;
        document.getElementById('sBound').textContent = st.bound || 0;

        var box = document.getElementById('verDist');
        var vers = st.versions || {};
        var keys = Object.keys(vers);
        if (!keys.length) {
            box.innerHTML = '<div class="empty-state"><p style="color:var(--text-secondary);">暂无版本数据</p></div>';
            return;
        }
        var max = 1;
        keys.forEach(function (k) { if (vers[k] > max) max = vers[k]; });
        box.innerHTML = keys.map(function (k) {
            var pct = Math.round(vers[k] / max * 100);
            return '<div style="display:flex;align-items:center;gap:12px;margin-bottom:10px;">' +
                '<div style="width:110px;flex:none;font-size:13px;font-weight:600;">v' + esc(k) + '</div>' +
                '<div style="flex:1;height:10px;border-radius:5px;background:var(--bg-secondary);overflow:hidden;">' +
                '<div style="height:100%;width:' + pct + '%;border-radius:5px;background:var(--primary);"></div></div>' +
                '<div style="width:90px;flex:none;text-align:right;font-size:13px;color:var(--text-secondary);">' + vers[k] + ' 台</div>' +
                '</div>';
        }).join('');
    }

    function load() {
        var params = new URLSearchParams({
            page: currentPage,
            limit: LIMIT,
            keyword: document.getElementById('fKeyword').value.trim(),
            sort: document.getElementById('fSort').value,
            only_bound: document.getElementById('fOnlyBound').checked ? '1' : '0'
        });
        fetch(SITE_URL + '/api/admin/app_devices.php?' + params.toString(), { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (res) {
                var tbody = document.getElementById('devBody');
                if (!res.success) {
                    tbody.innerHTML = '<tr><td colspan="8"><div class="empty-state"><p>加载失败：' + esc(res.message || '数据异常') + '</p><button type="button" class="btn btn-outline btn-sm" onclick="window.__lwAppUsers.reload()">重试</button></div></td></tr>';
                    return;
                }
                var d = res.data;
                setStats(d.stats || {});
                var list = d.devices || [];
                if (!list.length) {
                    tbody.innerHTML = '<tr><td colspan="8"><div class="empty-state"><div class="empty-icon"><svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><rect x="5" y="2" width="14" height="20" rx="2"/><line x1="12" y1="18" x2="12.01" y2="18"/></svg></div><p>还没有设备上报过。等用户装上新版 App 并打开一次，这里就会出现记录。</p></div></td></tr>';
                } else {
                    tbody.innerHTML = list.map(function (it) {
                        var account = it.nickname
                            ? esc(it.nickname) + '<br><span style="font-size:12px;color:var(--text-secondary);">ID ' + esc(it.user_id) + (it.qq ? ' · QQ ' + esc(it.qq) : '') + '</span>'
                            : (it.qq ? 'QQ ' + esc(it.qq) : '<span style="color:var(--text-secondary);">未登录</span>');
                        var dotColor = it.online ? 'var(--success)' : 'var(--text-secondary)';
                        var status = '<span style="display:inline-flex;align-items:center;white-space:nowrap;">' +
                            '<span style="width:8px;height:8px;border-radius:50%;background:' + dotColor + ';display:inline-block;margin-right:6px;' + (it.online ? '' : 'opacity:0.45;') + '"></span>' +
                            (it.online ? '在线' : '离线') + '</span>';
                        var model = esc2(it.brand ? it.brand + ' ' + it.model : it.model);
                        return '<tr>' +
                            '<td><code>' + esc2(it.device_id) + '…</code></td>' +
                            '<td>' + account + '</td>' +
                            '<td>' + model + '</td>' +
                            '<td>' + esc2(it.os) + '</td>' +
                            '<td>v' + esc2(it.app_version) + (it.app_version_code ? ' <span style="font-size:12px;color:var(--text-secondary);">(' + esc(it.app_version_code) + ')</span>' : '') + '</td>' +
                            '<td>' + fmtTime(it.first_seen) + '</td>' +
                            '<td>' + fmtTime(it.last_seen) + (ago(it.last_seen) ? '<br><span style="font-size:12px;color:var(--text-secondary);">' + ago(it.last_seen) + '</span>' : '') + '</td>' +
                            '<td>' + status + '</td>' +
                            '</tr>';
                    }).join('');
                }
                renderPagination(d.pagination);
            })
            .catch(function () {
                document.getElementById('devBody').innerHTML = '<tr><td colspan="8"><div class="empty-state"><p>加载失败，请检查网络后重试</p><button type="button" class="btn btn-outline btn-sm" onclick="window.__lwAppUsers.reload()">重试</button></div></td></tr>';
            });
    }

    function renderPagination(p) {
        var box = document.getElementById('devPagination');
        if (!p || p.total_pages <= 1) {
            box.innerHTML = p && p.total ? '<span class="page-info">共 ' + p.total + ' 台设备</span>' : '';
            return;
        }
        var html = '<button type="button" ' + (p.page <= 1 ? 'disabled' : '') + ' onclick="window.__lwAppUsers.go(' + (p.page - 1) + ')">上一页</button>';
        var start = Math.max(1, p.page - 4);
        var end = Math.min(p.total_pages, start + 9);
        for (var i = start; i <= end; i++) {
            html += '<button type="button" class="' + (i === p.page ? 'active' : '') + '" onclick="window.__lwAppUsers.go(' + i + ')">' + i + '</button>';
        }
        html += '<button type="button" ' + (p.page >= p.total_pages ? 'disabled' : '') + ' onclick="window.__lwAppUsers.go(' + (p.page + 1) + ')">下一页</button>';
        html += '<span class="page-info">共 ' + p.total + ' 台 / ' + p.total_pages + ' 页</span>';
        box.innerHTML = html;
    }

    window.__lwAppUsers = {
        reload: function () { load(); },
        go: function (n) { currentPage = Math.max(1, n); load(); },
        filter: function () { currentPage = 1; load(); },
        reset: function () {
            document.getElementById('fKeyword').value = '';
            document.getElementById('fSort').value = 'last';
            document.getElementById('fOnlyBound').checked = false;
            currentPage = 1;
            load();
        }
    };

    load();
    // 在线状态靠时间推算，页面开着不动会过期 —— 每分钟静默刷新当前页
    setInterval(function () { load(); }, 60000);
})();

function applyFilter() { window.__lwAppUsers.filter(); }
function resetFilter() { window.__lwAppUsers.reset(); }
</script>

<?php adminFooter(); ?>
