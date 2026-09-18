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

adminHeader('IP黑名单', $adminUser, $csrfToken);
?>

<div class="section">
    <div class="section-header"><h3>IP黑名单</h3></div>
    <div class="section-body">
        <div class="form-inline" style="margin-bottom:16px;">
            <div class="form-group">
                <label>IP地址</label>
                <input type="text" id="newIp" placeholder="例如: 192.168.1.1" maxlength="45">
                <div class="error-hint" id="ipError">IP地址格式无效</div>
            </div>
            <div class="form-group">
                <label>原因</label>
                <input type="text" id="newIpReason" placeholder="封禁原因" maxlength="200">
            </div>
            <button class="btn btn-danger btn-sm" onclick="addIp()">添加黑名单</button>
        </div>

        <div class="table-wrapper">
            <table>
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>IP地址</th>
                        <th>原因</th>
                        <th>拦截次数</th>
                        <th>添加时间</th>
                        <th>操作</th>
                    </tr>
                </thead>
                <tbody id="ipTableBody">
                    <tr><td colspan="6" class="loading-spinner">加载中</td></tr>
                </tbody>
            </table>
        </div>
        <div class="pagination" id="ipPagination"></div>
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

function loadIps() {
    fetch(SITE_URL + '/api/admin/logs.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: new URLSearchParams({ csrf_token: CSRF_TOKEN, action: 'list_ip_blacklist', page: currentPage })
    })
    .then(function(r) { return r.json(); })
    .then(function(data) {
        if (data.success) {
            var ips = data.data.ips;
            var tbody = document.getElementById('ipTableBody');
            if (ips.length === 0) {
                tbody.innerHTML = '<tr><td colspan="6"><div class="empty-state"><div class="empty-icon"><svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="22 12 16 12 14 15 10 15 8 12 2 12"/><path d="M5.45 5.11L2 12v6a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2v-6l-3.45-6.89A2 2 0 0 0 16.76 4H7.24a2 2 0 0 0-1.79 1.11z"/></svg></div><p>暂无黑名单</p></div></td></tr>';
            } else {
                tbody.innerHTML = ips.map(function(ip) {
                    return '<tr>' +
                        '<td>' + esc(ip.id) + '</td>' +
                        '<td><code>' + esc(ip.ip) + '</code></td>' +
                        '<td>' + esc(ip.reason || '-') + '</td>' +
                        '<td>' + esc(ip.block_count) + '</td>' +
                        '<td>' + fmtTime(ip.created_at) + '</td>' +
                        '<td><button class="btn btn-danger btn-sm" onclick="confirmRemove(' + (ip.id||0) + ', \'' + esc(ip.ip).replace(/'/g, "\\'") + '\')">移除</button></td>' +
                        '</tr>';
                }).join('');
            }
            renderPagination(data.data.total, data.data.page, data.data.total_pages);
        } else {
            showToast(data.message || '加载失败', 'error');
            var tbody = document.getElementById('ipTableBody');
            tbody.innerHTML = '<tr><td colspan="6"><div class="empty-state"><p>加载失败：' + esc(data.message || '数据异常') + '</p><button class="btn btn-outline btn-sm" onclick="loadIps()">重试</button></div></td></tr>';
        }
    })
    .catch(function() {
        showToast('加载失败', 'error');
        var tbody = document.getElementById('ipTableBody');
        tbody.innerHTML = '<tr><td colspan="6"><div class="empty-state"><p>加载失败，请检查网络后重试</p><button class="btn btn-outline btn-sm" onclick="loadIps()">重试</button></div></td></tr>';
    });
}

function renderPagination(total, page, totalPages) {
    var pag = document.getElementById('ipPagination');
    if (totalPages <= 1) { pag.innerHTML = ''; return; }
    var html = '<button ' + (page <= 1 ? 'disabled' : '') + ' onclick="goPage(' + (page-1) + ')">上一页</button>';
    for (var i = 1; i <= totalPages && i <= 10; i++) {
        html += '<button class="' + (i === page ? 'active' : '') + '" onclick="goPage(' + i + ')">' + i + '</button>';
    }
    html += '<button ' + (page >= totalPages ? 'disabled' : '') + ' onclick="goPage(' + (page+1) + ')">下一页</button>';
    html += '<span class="page-info">共 ' + total + ' 条</span>';
    pag.innerHTML = html;
}

function goPage(p) { currentPage = p; loadIps(); }

function fmtTime(t) {
    if (!t) return '-';
    if (typeof t === 'number' || /^\d+$/.test(String(t))) {
        var d = new Date(Number(t) * 1000);
        function p(n){ return n < 10 ? '0' + n : '' + n; }
        return d.getFullYear() + '-' + p(d.getMonth()+1) + '-' + p(d.getDate()) + ' ' + p(d.getHours()) + ':' + p(d.getMinutes());
    }
    return String(t).substring(0,16);
}

function addIp() {
    var ip = document.getElementById('newIp').value.trim();
    var reason = document.getElementById('newIpReason').value.trim();

    var ipRegex = /^(\d{1,3}\.){3}\d{1,3}$/;
    if (!ipRegex.test(ip)) {
        document.getElementById('ipError').classList.add('show');
        return;
    }
    document.getElementById('ipError').classList.remove('show');

    var parts = ip.split('.');
    var valid = parts.every(function(p) { var n = parseInt(p); return n >= 0 && n <= 255; });
    if (!valid) {
        document.getElementById('ipError').classList.add('show');
        return;
    }

    fetch(SITE_URL + '/api/admin/logs.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: new URLSearchParams({ csrf_token: CSRF_TOKEN, action: 'add_ip', ip: ip, reason: reason })
    })
    .then(function(r) { return r.json(); })
    .then(function(data) {
        if (data.success) {
            showToast('IP已加入黑名单');
            document.getElementById('newIp').value = '';
            document.getElementById('newIpReason').value = '';
            loadIps();
        } else {
            showToast(data.message, 'error');
        }
    })
    .catch(function() { showToast('网络错误', 'error'); });
}

function confirmRemove(id, ip) {
    document.getElementById('confirmMessage').textContent = '确定要将 ' + ip + ' 从黑名单移除吗？';
    document.getElementById('confirmBtn').onclick = function() {
        fetch(SITE_URL + '/api/admin/logs.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: new URLSearchParams({ csrf_token: CSRF_TOKEN, action: 'remove_ip', ip_id: id, ip: ip })
        })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (data.success) { showToast('IP已移除'); closeModal('confirmModal'); loadIps(); }
            else { showToast(data.message, 'error'); }
        })
        .catch(function() { showToast('网络错误', 'error'); });
    };
    openModal('confirmModal');
}

loadIps();
</script>

<?php adminFooter(); ?>
