<?php
/**
 * 后台 —— 系统健康检查。
 *
 * 给运维者一眼看懂「这台站的哪个环节正在变坏」：环境、数据、配置、安全四块，
 * 外加一个综合评分与问题清单。红色=必须处理，黄色=建议处理，蓝色=知悉即可。
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/layout.php';

$adminUser = requireAdmin();
$adminUser = checkBanned($adminUser);
$csrfToken = generateCSRFToken();

if (!checkPermission($adminUser, 'view_health')) {
    http_response_code(403);
    die('403 Forbidden - 权限不足');
}

adminHeader('系统健康', $adminUser, $csrfToken);
?>

<div class="stats-grid" id="headCards">
    <div class="stat-card">
        <div class="stat-icon"><svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 12h-4l-3 9L9 3l-3 9H2"/></svg></div>
        <div class="stat-info"><div class="stat-value" id="sScore">-</div><div class="stat-label">健康评分</div></div>
    </div>
    <div class="stat-card info">
        <div class="stat-icon"><svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><ellipse cx="12" cy="5" rx="9" ry="3"/><path d="M21 12c0 1.66-4 3-9 3s-9-1.34-9-3"/><path d="M3 5v14c0 1.66 4 3 9 3s9-1.34 9-3V5"/></svg></div>
        <div class="stat-info"><div class="stat-value" id="sRows">-</div><div class="stat-label">数据总条数</div></div>
    </div>
    <div class="stat-card success">
        <div class="stat-icon"><svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/></svg></div>
        <div class="stat-info"><div class="stat-value" id="sDisk">-</div><div class="stat-label">磁盘剩余</div></div>
    </div>
    <div class="stat-card warning">
        <div class="stat-icon"><svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg></div>
        <div class="stat-info"><div class="stat-value" id="sIssues">-</div><div class="stat-label">待处理项</div></div>
    </div>
</div>

<div class="section">
    <div class="section-header">
        <h3>问题清单</h3>
        <button class="btn btn-outline btn-sm" onclick="loadHealth()">重新体检</button>
    </div>
    <div class="section-body">
        <div id="issueBox"><div class="loading-spinner">体检中</div></div>
    </div>
</div>

<div class="section">
    <div class="section-header"><h3>运行环境</h3></div>
    <div class="section-body">
        <div id="envBox"><div class="loading-spinner">加载中</div></div>
    </div>
</div>

<div class="section">
    <div class="section-header">
        <h3>数据表（按体积）</h3>
    </div>
    <div class="section-body">
        <div class="table-wrapper">
            <table>
                <thead><tr><th>数据表</th><th style="width:130px;">条数</th><th style="width:130px;">体积</th></tr></thead>
                <tbody id="tableBody"><tr><td colspan="3" class="loading-spinner">加载中</td></tr></tbody>
            </table>
        </div>
        <div id="orphanBox" style="margin-top:14px;"></div>
    </div>
</div>

<div class="section">
    <div class="section-header"><h3>配置与安全</h3></div>
    <div class="section-body">
        <div id="secBox"><div class="loading-spinner">加载中</div></div>
        <div style="margin-top:14px;">
            <button class="btn btn-outline btn-sm" onclick="gc()">清理残留临时文件</button>
        </div>
    </div>
</div>

<script>
function api(payload) {
    var body = new URLSearchParams(payload);
    body.set('csrf_token', CSRF_TOKEN);
    return fetch(SITE_URL + '/api/admin/health.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: body
    }).then(function (r) { return r.json(); });
}

var LEVEL_STYLE = {
    error: { color: '#ef4444', label: '严重' },
    warn:  { color: '#f59e0b', label: '警告' },
    info:  { color: '#3b82f6', label: '提示' }
};

function line(label, value, ok) {
    var dot = ok === undefined ? '' : (ok
        ? '<span style="color:#10b981;">●</span> '
        : '<span style="color:#ef4444;">●</span> ');
    return '<div style="display:flex;justify-content:space-between;padding:7px 0;border-bottom:1px solid #f1f5f9;font-size:14px;">' +
        '<span style="color:var(--text-secondary);">' + dot + label + '</span>' +
        '<span style="font-weight:500;">' + value + '</span></div>';
}

function loadHealth() {
    document.getElementById('issueBox').innerHTML = '<div class="loading-spinner">体检中</div>';
    api({ action: 'check' }).then(function (res) {
        if (!res.success) {
            document.getElementById('issueBox').innerHTML = '<p>' + esc(res.message || '体检失败') + '</p>';
            return;
        }
        var d = res.data;
        renderHead(d);
        renderIssues(d);
        renderEnv(d);
        renderTables(d);
        renderSec(d);
    }).catch(function () {
        document.getElementById('issueBox').innerHTML = '<p>网络错误，无法完成体检</p>';
    });
}

function renderHead(d) {
    document.getElementById('sScore').textContent = d.score;
    document.getElementById('sRows').textContent = d.data.total_rows;
    document.getElementById('sDisk').textContent = d.environment.disk_free;
    document.getElementById('sIssues').textContent = (d.issues || []).length;
}

function renderIssues(d) {
    var box = document.getElementById('issueBox');
    var list = d.issues || [];
    if (!list.length) {
        box.innerHTML = '<div class="empty-state"><p style="color:#10b981;font-weight:500;">全部正常，未发现问题</p>' +
            '<p style="font-size:12px;color:var(--text-secondary);">体检时间：' + esc(d.checked_at) + '</p></div>';
        return;
    }
    box.innerHTML = list.map(function (i) {
        var s = LEVEL_STYLE[i.level] || LEVEL_STYLE.info;
        return '<div style="display:flex;align-items:flex-start;gap:10px;padding:8px 0;border-bottom:1px solid #f1f5f9;">' +
            '<span style="flex:none;font-size:12px;padding:2px 8px;border-radius:999px;background:' + s.color + '1a;color:' + s.color + ';">' + s.label + '</span>' +
            '<span style="font-size:14px;">' + esc(i.text) + '</span></div>';
    }).join('');
}

function renderEnv(d) {
    var e = d.environment;
    var html = '';
    html += line('PHP 版本', esc(e.php_version) + (e.php_ok ? '' : ' <span style="color:#ef4444;">（过低）</span>'), e.php_ok);
    html += line('运行方式', esc(e.server) + ' · ' + esc(e.sapi));
    html += line('HTTPS', e.https ? '已启用' : '未启用', e.https);

    var extHtml = (e.extensions || []).map(function (x) {
        return '<span style="display:inline-block;padding:3px 10px;margin:2px;border-radius:999px;font-size:12px;' +
            (x.ok ? 'background:#10b9811a;color:#059669;' : 'background:#ef44441a;color:#dc2626;') + '">' +
            esc(x.name) + (x.ok ? ' ✓' : ' ✗') + '</span>';
    }).join('');
    html += '<div style="padding:10px 0;border-bottom:1px solid #f1f5f9;">' +
        '<div style="color:var(--text-secondary);font-size:14px;margin-bottom:6px;">必需扩展</div>' + extHtml + '</div>';

    html += line('磁盘剩余', esc(e.disk_free) + ' / ' + esc(e.disk_total) + '（' + e.disk_percent + '%）', e.disk_percent > 10);

    (e.dirs || []).forEach(function (dir) {
        html += line(esc(dir.label), dir.exists ? (dir.writable ? '正常可写' : '存在但不可写') : '不存在', dir.exists && dir.writable);
    });

    document.getElementById('envBox').innerHTML = html;
}

function renderTables(d) {
    var tb = document.getElementById('tableBody');
    var t = d.data.tables || [];
    tb.innerHTML = t.map(function (x) {
        return '<tr><td><code>' + esc(x.name) + '</code></td><td>' + esc(x.rows) + '</td><td>' + esc(x.size_h) + '</td></tr>';
    }).join('') || '<tr><td colspan="3" class="loading-spinner">暂无数据表</td></tr>';

    var o = d.data;
    var notes = [];
    if (o.orphan_comments) notes.push('孤儿评论 <b>' + o.orphan_comments + '</b> 条（所属帖子已删除，建议清理）');
    if (o.orphan_likes) notes.push('孤儿点赞 <b>' + o.orphan_likes + '</b> 条');
    if (o.orphan_follows) notes.push('失效关注关系 <b>' + o.orphan_follows + '</b> 条');
    if (o.leftovers && o.leftovers.length) {
        notes.push('残留临时文件 <b>' + o.leftovers.length + '</b> 个（可用下方按钮清理）');
    }
    document.getElementById('orphanBox').innerHTML = notes.length
        ? '<div style="padding:10px 12px;background:var(--bg-secondary);color:var(--text-secondary);border-radius:8px;font-size:13px;line-height:1.9;">' + notes.join('<br>') + '</div>'
        : '<div style="font-size:13px;color:#10b981;">数据一致性检查通过，未发现孤儿记录</div>';
}

function renderSec(d) {
    var c = d.config, s = d.security;
    var html = '';
    (c.files || []).forEach(function (f) {
        html += line(esc(f.label), f.exists ? '已配置' : '未配置（功能降级）');
    });
    html += line('维护模式', c.maintenance ? '开启中' : '关闭');
    html += line('注册开关', c.register_open ? '开放' : '关闭');
    html += line('成长体系', c.growth_enabled ? '启用' : '停用');
    html += line('IP 黑名单', s.blacklist_count + ' 条');
    html += line('今日非法访问', s.illegal_today + ' 次（累计 ' + s.illegal_total + '）', s.illegal_today === 0);
    html += line('WAF 拦截日志', s.waf_logs + ' 条');

    if (c.stale_assets && c.stale_assets.length) {
        html += '<div style="margin-top:12px;padding:10px 12px;background:#fffbeb;border-radius:8px;font-size:13px;color:#92400e;">' +
            '以下静态资源源文件比 .min 新，需重新压缩：<br><code style="font-size:12px;">' +
            c.stale_assets.map(esc).join('、') + '</code><br>' +
            '<span style="color:#b45309;">在本机执行 <code>bash tools_build_assets.sh</code> 后重新上传 .min 文件。</span></div>';
    } else {
        html += '<div style="margin-top:12px;font-size:13px;color:#10b981;">静态资源压缩均为最新</div>';
    }

    document.getElementById('secBox').innerHTML = html;
}

function gc() {
    confirmDialog('清理残留文件', '将删除 data/ 下超过 1 小时未被使用的临时文件与空锁文件，不会影响正常数据。确定继续？', function () {
        api({ action: 'gc' }).then(function (res) {
            showToast(res.message || (res.success ? '已清理' : '失败'), res.success ? 'success' : 'error');
            if (res.success) loadHealth();
        }).catch(function () { showToast('网络错误', 'error'); });
    });
}

loadHealth();
</script>

<?php adminFooter(); ?>
