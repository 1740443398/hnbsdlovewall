<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/layout.php';

$adminUser = requireAdmin();
$adminUser = checkBanned($adminUser);
$csrfToken = generateCSRFToken();

if (!checkPermission($adminUser, 'view_ai_logs')) {
    http_response_code(403);
    die('403 Forbidden - 权限不足');
}

adminHeader('AI 调用记录', $adminUser, $csrfToken);
?>

<div class="section">
    <div class="section-header"><h3>调用概览</h3></div>
    <div class="section-body">
        <div class="stats-grid" id="aiStats">
            <div class="stat-card info">
                <div class="stat-label">总调用次数</div>
                <div class="stat-value" id="statTotal">-</div>
            </div>
            <div class="stat-card success">
                <div class="stat-label">今日调用</div>
                <div class="stat-value" id="statToday">-</div>
            </div>
            <div class="stat-card warning">
                <div class="stat-label">失败次数</div>
                <div class="stat-value" id="statFail">-</div>
            </div>
            <div class="stat-card">
                <div class="stat-label">平均耗时</div>
                <div class="stat-value" id="statAvg">-</div>
            </div>
        </div>
    </div>
</div>

<div class="section">
    <div class="section-header"><h3>调用明细</h3></div>
    <div class="section-body">
        <div class="filter-row">
            <div class="form-group">
                <label>关键词</label>
                <input type="text" id="filterKeyword" placeholder="提问内容 / 昵称 / IP..." onkeyup="debounceSearch()">
            </div>
            <div class="form-group">
                <label>结果</label>
                <select id="filterResult" onchange="currentPage=1;loadLogs()">
                    <option value="">全部</option>
                    <option value="success">仅成功</option>
                    <option value="fail">仅失败</option>
                    <option value="guest">仅游客</option>
                </select>
            </div>
            <div class="form-group">
                <label>日期</label>
                <input type="date" id="filterDate" onchange="currentPage=1;loadLogs()">
            </div>
            <button class="btn btn-primary btn-sm" onclick="currentPage=1;loadLogs()">搜索</button>
        </div>

        <div class="table-wrapper">
            <table>
                <thead>
                    <tr>
                        <th>时间</th>
                        <th>用户</th>
                        <th>来源</th>
                        <th>状态</th>
                        <th>耗时</th>
                        <th>意图</th>
                        <th>读取的数据</th>
                        <th>提问</th>
                    </tr>
                </thead>
                <tbody id="logsTableBody">
                    <tr><td colspan="8" class="loading-spinner">加载中</td></tr>
                </tbody>
            </table>
        </div>
        <div class="pagination" id="logsPagination"></div>
        <p style="margin-top:14px;font-size:12px;color:var(--text-secondary);">
            说明：出于隐私与安全考虑，仅记录提问原文的前 200 字，<strong>不保存 AI 回复全文、系统提示词与密钥</strong>。记录按时间自动保留最近 2000 条。
        </p>
    </div>
</div>

<script>
var currentPage = 1;
var searchTimer = null;

function debounceSearch() {
    clearTimeout(searchTimer);
    searchTimer = setTimeout(function() { currentPage = 1; loadLogs(); }, 400);
}

function post(action, extra) {
    var body = new URLSearchParams({ csrf_token: CSRF_TOKEN, action: action });
    Object.keys(extra || {}).forEach(function(k) { body.append(k, extra[k]); });
    return fetch(SITE_URL + '/api/admin/logs.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: body
    }).then(function(r) { return r.json(); });
}

function loadStats() {
    post('ai_logs_stats', {}).then(function(data) {
        if (!data.success) return;
        var s = data.data.stat;
        document.getElementById('statTotal').textContent = s.total;
        document.getElementById('statToday').textContent = s.today;
        document.getElementById('statFail').textContent = s.fail;
        document.getElementById('statAvg').textContent = s.avg_ms + ' ms';
    }).catch(function() {});
}

function loadLogs() {
    post('list_ai_logs', {
        page: currentPage,
        keyword: document.getElementById('filterKeyword').value.trim(),
        result: document.getElementById('filterResult').value,
        date: document.getElementById('filterDate').value
    })
    .then(function(data) {
        if (!data.success) { showToast(data.message || '加载失败', 'error'); return; }
        var logs = data.data.logs;
        var tbody = document.getElementById('logsTableBody');
        if (logs.length === 0) {
            tbody.innerHTML = '<tr><td colspan="8"><div class="empty-state"><div class="empty-icon"><svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="22 12 16 12 14 15 10 15 8 12 2 12"/><path d="M5.45 5.11L2 12v6a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2v-6l-3.45-6.89A2 2 0 0 0 16.76 4H7.24a2 2 0 0 0-1.79 1.11z"/></svg></div><p>暂无数据</p></div></td></tr>';
        } else {
            tbody.innerHTML = logs.map(function(l) {
                var who = l.is_guest == 1
                    ? '<span class="badge badge-warning">游客</span>'
                    : '<span class="badge badge-info">' + esc(l.nickname || ('用户' + l.user_id)) + '</span>';
                var status = l.success == 1
                    ? '<span class="badge badge-success">成功</span>'
                    : '<span class="badge badge-danger" title="' + esc(l.error || '') + '">失败</span>';
                var page = l.page ? '<div style="font-size:11px;color:var(--text-secondary);">' + esc(l.page) + '</div>' : '';
                var errTip = (l.success != 1 && l.error) ? esc(l.error) : '-';
                return '<tr>' +
                    '<td>' + esc((l.created_at || '').substring(0, 16)) + '</td>' +
                    '<td>' + who + page + '</td>' +
                    '<td>' + esc(l.ip || '-') + '</td>' +
                    '<td>' + status + '<div style="font-size:11px;color:var(--text-secondary);" title="' + esc(l.error || '') + '">' + esc((l.success == 1 ? 'HTTP ' + l.http_status : (l.error || '').substring(0, 20))) + '</div></td>' +
                    '<td>' + esc(l.elapsed_ms) + ' ms</td>' +
                    '<td style="font-size:12px;">' + esc(l.intent || '-') + '</td>' +
                    '<td style="max-width:180px;font-size:12px;">' + esc(l.sources || '-') + '</td>' +
                    '<td style="max-width:280px;font-size:12px;" title="' + esc(l.question || '') + '">' + esc(l.question || '-') + '</td>' +
                    '</tr>';
            }).join('');
        }
        renderPagination(data.data.total, data.data.page, data.data.total_pages);
    })
    .catch(function() { showToast('加载失败', 'error'); });
}

function renderPagination(total, page, totalPages) {
    var pag = document.getElementById('logsPagination');
    if (totalPages <= 1) { pag.innerHTML = ''; return; }
    var html = '<button ' + (page <= 1 ? 'disabled' : '') + ' onclick="goPage(' + (page-1) + ')">上一页</button>';
    for (var i = 1; i <= totalPages && i <= 10; i++) {
        html += '<button class="' + (i === page ? 'active' : '') + '" onclick="goPage(' + i + ')">' + i + '</button>';
    }
    html += '<button ' + (page >= totalPages ? 'disabled' : '') + ' onclick="goPage(' + (page+1) + ')">下一页</button>';
    html += '<span class="page-info">共 ' + total + ' 条</span>';
    pag.innerHTML = html;
}

function goPage(p) { currentPage = p; loadLogs(); }

loadStats();
loadLogs();
</script>

<?php adminFooter(); ?>