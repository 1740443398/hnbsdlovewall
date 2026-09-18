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

adminHeader('操作日志', $adminUser, $csrfToken);
?>

<div class="section">
    <div class="section-header"><h3>操作日志</h3></div>
    <div class="section-body">
        <div class="filter-row">
            <div class="form-group">
                <label>操作者QQ</label>
                <input type="text" id="filterOperator" placeholder="搜索操作者QQ..." onkeyup="debounceSearch()">
            </div>
            <div class="form-group">
                <label>操作类型</label>
                <select id="filterAction" onchange="currentPage=1;loadLogs()">
                    <option value="">全部</option>
                    <option value="admin_login">管理员登录</option>
                    <option value="ban_user">封禁用户</option>
                    <option value="unban_user">解封用户</option>
                    <option value="approve_post">审核通过</option>
                    <option value="reject_post">拒绝帖子</option>
                    <option value="delete_post">删除帖子</option>
                    <option value="add_admin">添加管理员</option>
                    <option value="delete_admin">删除管理员</option>
                    <option value="edit_admin_permissions">编辑权限</option>
                    <option value="reset_user_2fa">重置2FA</option>
                    <option value="reset_user_password">重置密码</option>
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
                        <th>操作者QQ</th>
                        <th>操作</th>
                        <th>目标类型</th>
                        <th>目标</th>
                        <th>详情</th>
                        <th>IP</th>
                        <th>时间</th>
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

<script>
var currentPage = 1;
var searchTimer = null;

function debounceSearch() {
    clearTimeout(searchTimer);
    searchTimer = setTimeout(function() { currentPage = 1; loadLogs(); }, 400);
}

function loadLogs() {
    var operator = document.getElementById('filterOperator').value.trim();
    var action = document.getElementById('filterAction').value;
    var date = document.getElementById('filterDate').value;

    fetch(SITE_URL + '/api/admin/logs.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: new URLSearchParams({
            csrf_token: CSRF_TOKEN,
            action: 'list_operation_logs',
            page: currentPage,
            operator: operator,
            log_action: action,
            date: date
        })
    })
    .then(function(r) { return r.json(); })
    .then(function(data) {
        if (data.success) {
            var logs = data.data.logs;
            var tbody = document.getElementById('logsTableBody');
            if (logs.length === 0) {
                tbody.innerHTML = '<tr><td colspan="8"><div class="empty-state"><div class="empty-icon"><svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="22 12 16 12 14 15 10 15 8 12 2 12"/><path d="M5.45 5.11L2 12v6a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2v-6l-3.45-6.89A2 2 0 0 0 16.76 4H7.24a2 2 0 0 0-1.79 1.11z"/></svg></div><p>暂无数据</p></div></td></tr>';
            } else {
                tbody.innerHTML = logs.map(function(l) {
                    return '<tr>' +
                        '<td>' + esc(l.id) + '</td>' +
                        '<td>' + esc(l.operator_qq) + '</td>' +
                        '<td><span class="badge badge-info">' + esc(l.action) + '</span></td>' +
                        '<td>' + esc(l.target_type || '-') + '</td>' +
                        '<td>' + esc(l.target_id || '-') + '</td>' +
                        '<td style="max-width:200px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">' + esc(l.details || '-') + '</td>' +
                        '<td>' + esc(l.ip || '-') + '</td>' +
                        '<td>' + (l.created_at ? l.created_at.substring(0,16) : '-') + '</td>' +
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

loadLogs();
</script>

<?php adminFooter(); ?>
