<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/layout.php';

$adminUser = requireAdmin();
$adminUser = checkBanned($adminUser);
$csrfToken = generateCSRFToken();

if (!checkPermission($adminUser, 'view_users')) {
    http_response_code(403);
    die('403 Forbidden - 权限不足');
}

adminHeader('头衔申请', $adminUser, $csrfToken);
?>

<style>
    .tr-status { padding: 2px 10px; border-radius: 9999px; font-size: 12px; font-weight: 600; }
    .tr-status.pending { background: var(--warning-light, #fbf6ed); color: var(--warning, #b8860b); }
    .tr-status.approved { background: var(--success-light, #e8f4ec); color: var(--success, #2e7d32); }
    .tr-status.rejected { background: var(--danger-light, #faecee); color: var(--danger, #c0392b); }
</style>

<div class="section">
    <div class="section-header">
        <h3>头衔申请审批</h3>
        <button class="btn btn-primary btn-sm" onclick="loadList()">刷新</button>
    </div>
    <div class="section-body">
        <div class="table-wrapper">
            <table id="trTable">
                <thead>
                    <tr>
                        <th>用户</th>
                        <th>申请头衔</th>
                        <th>申请说明</th>
                        <th>当前头衔</th>
                        <th>状态</th>
                        <th>申请时间</th>
                        <th>操作</th>
                    </tr>
                </thead>
                <tbody id="trBody">
                    <tr><td colspan="7" style="text-align:center;color:#888;">加载中...</td></tr>
                </tbody>
            </table>
        </div>
    </div>
</div>

<script>
    var CSRF_TOKEN = <?= json_encode($csrfToken) ?>;

    function esc(s) {
        return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }

    function statusBadge(st) {
        var map = { pending: '待审核', approved: '已通过', rejected: '已驳回' };
        return '<span class="tr-status ' + esc(st) + '">' + (map[st] || esc(st)) + '</span>';
    }

    function loadList() {
        var body = document.getElementById('trBody');
        body.innerHTML = '<tr><td colspan="7" style="text-align:center;color:#888;">加载中...</td></tr>';
        var fd = new FormData();
        fd.append('csrf_token', CSRF_TOKEN);
        fd.append('action', 'list');
        // 请求超时兜底，避免永远停留在「加载中」
        var ctrl = new AbortController();
        var to = setTimeout(function () { ctrl.abort(); }, 10000);
        fetch('/api/admin/title_request_review.php', { method: 'POST', body: fd, signal: ctrl.signal })
            .then(function (r) { return r.json(); })
            .then(function (res) {
                clearTimeout(to);
                if (!res.success) { body.innerHTML = '<tr><td colspan="7" style="text-align:center;color:#c0392b;">' + esc(res.message) + '</td></tr>'; return; }
                var list = res.data || [];
                if (list.length === 0) { body.innerHTML = '<tr><td colspan="7" style="text-align:center;color:#888;">暂无头衔申请</td></tr>'; return; }
                var html = '';
                list.forEach(function (r) {
                    html += '<tr>' +
                        '<td>' + esc(r.nickname) + ' <span style="color:#888;">(' + esc(r.qq) + ')</span></td>' +
                        '<td><strong>' + esc(r.title_text) + '</strong></td>' +
                        '<td style="max-width:220px;">' + (r.reason ? esc(r.reason) : '<span style="color:#aaa;">—</span>') + '</td>' +
                        '<td>' + (r.cur_title ? esc(r.cur_title) : '<span style="color:#aaa;">无</span>') + '</td>' +
                        '<td>' + statusBadge(r.status) + '</td>' +
                        '<td>' + esc(r.created_at) + '</td>' +
                        '<td>' + (r.status === 'pending'
                            ? '<button class="btn btn-success btn-sm" onclick="review(' + r.id + ', \'approve\', \'' + esc(r.title_text).replace(/'/g, "\\'") + '\')">通过</button> ' +
                              '<button class="btn btn-warning btn-sm" onclick="review(' + r.id + ', \'reject\')">驳回</button>'
                            : '<span style="color:#aaa;">已处理</span>') + '</td>' +
                        '</tr>';
                });
                body.innerHTML = html;
            })
            .catch(function () { clearTimeout(to); body.innerHTML = '<tr><td colspan="7" style="text-align:center;color:#c0392b;">加载失败（网络异常或请求超时）</td></tr>'; });
    }

    function review(id, action, titleText) {
        if (action === 'approve') {
            if (!confirm('确定通过并将头衔「' + titleText + '」授予该用户吗？')) return;
        } else {
            if (!confirm('确定驳回该头衔申请吗？')) return;
        }
        var fd = new FormData();
        fd.append('csrf_token', CSRF_TOKEN);
        fd.append('id', id);
        fd.append('action', action);
        fetch('/api/admin/title_request_review.php', { method: 'POST', body: fd })
            .then(function (r) { return r.json(); })
            .then(function (res) {
                alert(res.message || (res.success ? '操作成功' : '操作失败'));
                if (res.success) loadList();
            })
            .catch(function () { alert('网络错误'); });
    }

    loadList();
</script>

<?php adminFooter(); ?>