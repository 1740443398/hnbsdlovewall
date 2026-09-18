<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/layout.php';

$adminUser = requireAdmin();
$adminUser = checkBanned($adminUser);
$csrfToken = generateCSRFToken();

if (!checkPermission($adminUser, 'view_operation_logs')) {
    http_response_code(403);
    die('403 Forbidden - 权限不足');
}

adminHeader('非法访问日志', $adminUser, $csrfToken);
?>

<div class="section">
    <div class="section-header">
        <h3>非法访问日志</h3>
        <div style="display:flex;gap:8px;align-items:center;">
            <button class="btn btn-outline btn-sm" onclick="clearAllLogs()">清空全部日志</button>
            <span style="font-size:12px;color:var(--text-secondary);">共 <strong id="logTotal">-</strong> 条</span>
        </div>
    </div>
    <div class="section-body">
        <div class="filter-row">
            <div class="form-group">
                <label>IP地址</label>
                <input type="text" id="filterIp" placeholder="搜索IP..." onkeyup="debounceSearch()">
            </div>
            <div class="form-group">
                <label>类型</label>
                <select id="filterType" onchange="currentPage=1;loadLogs()">
                    <option value="">全部</option>
                    <option value="empty_ua">空User-Agent</option>
                    <option value="bad_bot">恶意爬虫</option>
                    <option value="headless_browser">无头浏览器</option>
                    <option value="hack_attempt">黑客攻击</option>
                    <option value="bad_method">异常请求方法</option>
                    <option value="rate_limit_exceeded">频率超限</option>
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
                        <th>ID</th>
                        <th>时间</th>
                        <th>IP地址</th>
                        <th>类型</th>
                        <th>访问路径</th>
                        <th>User-Agent</th>
                        <th>请求方法</th>
                        <th>操作</th>
                    </tr>
                </thead>
                <tbody id="logsTableBody">
                    <tr><td colspan="8" class="loading-spinner">加载中</td></tr>
                </tbody>
            </table>
        </div>
        <div class="pagination" id="logsPagination"></div>
    </div>
</div>

<div class="modal-overlay" id="confirmModal">
    <div class="modal">
        <div class="modal-header">
            <h3>确认操作</h3>
            <button class="modal-close" onclick="closeModal('confirmModal')" aria-label="关闭">×</button>
        </div>
        <div class="modal-body"><p id="confirmMessage"></p></div>
        <div class="modal-footer">
            <button class="btn btn-outline" onclick="closeModal('confirmModal')">取消</button>
            <button class="btn btn-danger" id="confirmBtn">确认</button>
        </div>
    </div>
</div>

<script>
var currentPage = 1;
var searchTimer = null;

function debounceSearch() {
    clearTimeout(searchTimer);
    searchTimer = setTimeout(function() { currentPage = 1; loadLogs(); }, 400);
}

function getTypeBadge(type) {
    var map = {
        'empty_ua': '<span class="badge badge-warning">空UA</span>',
        'bad_bot': '<span class="badge badge-danger">恶意爬虫</span>',
        'headless_browser': '<span class="badge badge-danger">无头浏览器</span>',
        'hack_attempt': '<span class="badge badge-danger">黑客攻击</span>',
        'bad_method': '<span class="badge badge-warning">异常方法</span>',
        'rate_limit_exceeded': '<span class="badge badge-info">频率超限</span>',
    };
    var prefix = type ? type.split(':')[0] : '';
    return map[prefix] || '<span class="badge badge-info">' + (type || '未知') + '</span>';
}

function loadLogs() {
    var ip = document.getElementById('filterIp').value.trim();
    var type = document.getElementById('filterType').value;
    var date = document.getElementById('filterDate').value;

    fetch(SITE_URL + '/api/admin/logs.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: new URLSearchParams({
            csrf_token: CSRF_TOKEN,
            action: 'list_illegal_logs',
            page: currentPage,
            ip: ip,
            type: type,
            date: date
        })
    })
    .then(function(r) { return r.json(); })
    .then(function(data) {
        if (data.success) {
            var logs = data.data.logs;
            var tbody = document.getElementById('logsTableBody');
            document.getElementById('logTotal').textContent = data.data.total || 0;
            if (logs.length === 0) {
                tbody.innerHTML = '<tr><td colspan="8"><div class="empty-state"><div class="empty-icon"><svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="22 12 16 12 14 15 10 15 8 12 2 12"/><path d="M5.45 5.11L2 12v6a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2v-6l-3.45-6.89A2 2 0 0 0 16.76 4H7.24a2 2 0 0 0-1.79 1.11z"/></svg></div><p>暂无非法访问记录</p></div></td></tr>';
            } else {
                tbody.innerHTML = logs.map(function(l) {
                    return '<tr>' +
                        '<td>' + esc(l.id) + '</td>' +
                        '<td><small>' + (l.created_at ? l.created_at.substring(0,16) : '-') + '</small></td>' +
                        '<td><code>' + esc(l.ip || '-') + '</code></td>' +
                        '<td>' + getTypeBadge(l.type) + '</td>' +
                        '<td style="max-width:200px;word-break:break-all;"><small>' + esc(l.file || '-') + '</small></td>' +
                        '<td style="max-width:200px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="' + esc(l.ua || '') + '"><small>' + esc(l.ua || '-') + '</small></td>' +
                        '<td>' + esc(l.method || '-') + '</td>' +
                        '<td><button class="btn btn-danger btn-sm" onclick="deleteLog(' + l.id + ')">删除</button> <button class="btn btn-outline btn-sm" onclick="blockIp(\'' + esc(l.ip || '').replace(/'/g, "\\'") + '\')">封禁IP</button></td>' +
                        '</tr>';
                }).join('');
            }
            renderPagination(data.data.total, data.data.page, data.data.total_pages);
        }
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

function deleteLog(id) {
    document.getElementById('confirmMessage').textContent = '确定要删除此非法访问记录吗？';
    document.getElementById('confirmBtn').onclick = function() {
        fetch(SITE_URL + '/api/admin/logs.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: new URLSearchParams({ csrf_token: CSRF_TOKEN, action: 'delete_illegal_log', id: id })
        })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (data.success) { showToast('已删除'); closeModal('confirmModal'); loadLogs(); }
            else { showToast(data.message, 'error'); }
        })
        .catch(function() { showToast('网络错误', 'error'); });
    };
    openModal('confirmModal');
}

function blockIp(ip) {
    if (!ip || ip === '0.0.0.0' || ip === '127.0.0.1') {
        showToast('无法封禁此IP', 'error');
        return;
    }
    document.getElementById('confirmMessage').textContent = '确定要将 ' + ip + ' 加入IP黑名单吗？';
    document.getElementById('confirmBtn').onclick = function() {
        fetch(SITE_URL + '/api/admin/logs.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: new URLSearchParams({ csrf_token: CSRF_TOKEN, action: 'add_ip', ip: ip, reason: '从非法访问日志中封禁' })
        })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (data.success) { showToast('IP已封禁'); closeModal('confirmModal'); }
            else { showToast(data.message, 'error'); }
        })
        .catch(function() { showToast('网络错误', 'error'); });
    };
    openModal('confirmModal');
}

function clearAllLogs() {
    document.getElementById('confirmMessage').textContent = '确定要清空所有非法访问日志吗？此操作不可恢复！';
    document.getElementById('confirmBtn').onclick = function() {
        fetch(SITE_URL + '/api/admin/logs.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: new URLSearchParams({ csrf_token: CSRF_TOKEN, action: 'clear_illegal_logs' })
        })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (data.success) { showToast('已清空'); closeModal('confirmModal'); loadLogs(); }
            else { showToast(data.message, 'error'); }
        })
        .catch(function() { showToast('网络错误', 'error'); });
    };
    openModal('confirmModal');
}

loadLogs();
</script>

<?php adminFooter(); ?>
