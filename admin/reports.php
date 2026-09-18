<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/layout.php';

$adminUser = requireAdmin();
$adminUser = checkBanned($adminUser);
$csrfToken = generateCSRFToken();

if (!checkPermission($adminUser, 'view_reports')) {
    http_response_code(403);
    die('403 Forbidden - 权限不足');
}

$hasManageReports = checkPermission($adminUser, 'manage_reports');

adminHeader('举报管理', $adminUser, $csrfToken);
?>

<div class="section">
    <div class="section-header">
        <h3>举报列表</h3>
        <div>
            <button class="btn btn-outline btn-sm tab-btn <?= $tabActive = ($_GET['type'] ?? 'pm') === 'pm' ? 'active' : '' ?>" onclick="switchType('pm')">私信举报</button>
            <button class="btn btn-outline btn-sm tab-btn <?= ($_GET['type'] ?? 'pm') === 'post' ? 'active' : '' ?>" onclick="switchType('post')">帖子举报</button>
        </div>
    </div>
    <div class="section-body">
        <div class="filter-row">
            <div class="form-group">
                <input type="text" id="filterKeyword" placeholder="搜索理由 / 目标 / 举报人..." onkeyup="debounceSearch()">
            </div>
            <button class="btn btn-primary btn-sm" onclick="currentPage=1;loadReports()">搜索</button>
        </div>

        <div class="table-wrapper">
            <table>
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>类型</th>
                        <th>被举报</th>
                        <th>举报理由</th>
                        <th>举报人</th>
                        <th>时间</th>
                        <th>操作</th>
                    </tr>
                </thead>
                <tbody id="reportsTableBody">
                    <tr><td colspan="7" class="loading-spinner">加载中</td></tr>
                </tbody>
            </table>
        </div>
        <div class="pagination" id="reportsPagination"></div>
    </div>
</div>

<div class="modal-overlay" id="detailModal">
    <div class="modal">
        <div class="modal-header">
            <h3>举报详情</h3>
            <button class="modal-close" onclick="closeModal('detailModal')" aria-label="关闭">×</button>
        </div>
        <div class="modal-body" id="detailBody"></div>
        <div class="modal-footer">
            <button class="btn btn-outline" onclick="closeModal('detailModal')">关闭</button>
        </div>
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
var currentType = <?= json_encode($_GET['type'] ?? 'pm') ?>;

function switchType(t) {
    currentType = t;
    currentPage = 1;
    document.querySelectorAll('.tab-btn').forEach(function (btn) {
        btn.classList.toggle('active', btn.textContent === (t === 'pm' ? '私信举报' : '帖子举报'));
    });
    loadReports();
}

function debounceSearch() {
    clearTimeout(searchTimer);
    searchTimer = setTimeout(function () { currentPage = 1; loadReports(); }, 400);
}

function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
        return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
}

function fetchJSON(body, cb) {
    fetch(SITE_URL + '/api/admin/reports.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: new URLSearchParams(body)
    }).then(function (r) { return r.json(); }).then(cb).catch(function () {
        showToast('网络错误', 'error');
    });
}

function loadReports() {
    var keyword = document.getElementById('filterKeyword').value.trim();
    var tbody = document.getElementById('reportsTableBody');
    fetchJSON({ csrf_token: CSRF_TOKEN, action: 'list', page: currentPage, keyword: keyword, type: currentType }, function (data) {
        if (!data.success) {
            tbody.innerHTML = '<tr><td colspan="7"><div class="empty-state"><div class="empty-icon"><svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg></div><p>' + esc(data.message || '加载失败') + '</p></div></td></tr>';
            return;
        }
        var items = data.data.items;
        if (items.length === 0) {
            tbody.innerHTML = '<tr><td colspan="7"><div class="empty-state"><div class="empty-icon"><svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg></div><p>暂无举报</p></div></td></tr>';
        } else {
            tbody.innerHTML = items.map(function (r) {
                var detailBtn = '<button class="btn btn-outline btn-sm" onclick="showDetail(' + r.id + ', ' + currentType + ')">详情</button> ';
                var delBtn = '';
                <?php if ($hasManageReports): ?>
                delBtn = '<button class="btn btn-danger btn-sm" onclick="deleteReport(' + r.id + ')">删除</button>';
                <?php endif; ?>
                return '<tr>' +
                    '<td>' + esc(r.id) + '</td>' +
                    '<td>' + (currentType === 'post' ? '帖子' : '私信') + '</td>' +
                    '<td title="' + esc(r.target_sub) + '">' + esc(r.target_name) + '</td>' +
                    '<td><div style="max-width:240px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="' + esc(r.reason) + '">' + esc(r.reason) + '</div></td>' +
                    '<td>' + esc(r.reporter_qq) + '</td>' +
                    '<td>' + (r.created_at ? esc(String(r.created_at).substring(0, 16)) : '-') + '</td>' +
                    '<td>' + detailBtn + delBtn + '</td>' +
                    '</tr>';
            }).join('');
        }
        renderPagination(data.data.total, data.data.page, data.data.total_pages);
    });
}

function renderPagination(total, page, totalPages) {
    var pag = document.getElementById('reportsPagination');
    if (totalPages <= 1) { pag.innerHTML = ''; return; }
    var html = '<button ' + (page <= 1 ? 'disabled' : '') + ' onclick="goPage(' + (page - 1) + ')">上一页</button>';
    for (var i = 1; i <= totalPages && i <= 10; i++) {
        html += '<button class="' + (i === page ? 'active' : '') + '" onclick="goPage(' + i + ')">' + i + '</button>';
    }
    html += '<button ' + (page >= totalPages ? 'disabled' : '') + ' onclick="goPage(' + (page + 1) + ')">下一页</button>';
    html += '<span class="page-info">共 ' + total + ' 条</span>';
    pag.innerHTML = html;
}

function goPage(p) { currentPage = p; loadReports(); }

function showDetail(id, type) {
    fetchJSON({ csrf_token: CSRF_TOKEN, action: 'list', page: 1, keyword: '', type: type }, function (data) {
        if (!data.success) return;
        var item = null;
        (data.data.items || []).forEach(function (r) { if (parseInt(r.id, 10) === parseInt(id, 10)) item = r; });
        if (!item) {
            // 当前页可能没有，直接提示
            document.getElementById('detailBody').innerHTML = '<p style="color:var(--text-secondary);">请刷新列表后重试</p>';
        } else {
            var html = '<p><strong>被举报：</strong>' + esc(item.target_name) + (item.target_sub ? '（' + esc(item.target_sub) + '）' : '') + '</p>';
            html += '<p><strong>举报人：</strong>' + esc(item.reporter_qq) + '</p>';
            html += '<p><strong>时间：</strong>' + esc(item.created_at) + '</p>';
            html += '<p><strong>理由：</strong><br>' + esc(item.reason) + '</p>';
            if (item.records && item.records.length) {
                html += '<hr style="border:none;border-top:1px solid var(--border);margin:14px 0"><p><strong>聊天记录（' + item.records.length + ' 条）：</strong></p>';
                html += item.records.map(function (rec) {
                    var d = new Date(rec.ts);
                    var hm = d.getHours() + ':' + String(d.getMinutes()).padStart(2, '0');
                    return '<div style="background:var(--bg-secondary);border-radius:8px;padding:8px 10px;margin-bottom:6px;font-size:13px;">' +
                        '<span style="color:var(--text-muted);font-size:11px;">' + esc(rec.who) + ' · ' + esc(hm) + '</span><br>' +
                        '<span style="word-break:break-all;">' + esc(rec.text) + '</span></div>';
                }).join('');
            }
            document.getElementById('detailBody').innerHTML = html;
        }
        openModal('detailModal');
    });
}

function deleteReport(id) {
    document.getElementById('confirmMessage').textContent = '确定要删除这条举报吗？此操作不可恢复！';
    document.getElementById('confirmBtn').onclick = function () {
        fetchJSON({ csrf_token: CSRF_TOKEN, action: 'delete', id: id, type: currentType }, function (data) {
            closeModal('confirmModal');
            if (data.success) { showToast(data.message); loadReports(); }
            else { showToast(data.message || '操作失败', 'error'); }
        });
    };
    openModal('confirmModal');
}

loadReports();
</script>

<?php adminFooter(); ?>