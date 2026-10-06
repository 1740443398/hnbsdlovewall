<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/layout.php';

$adminUser = requireAdmin();
$adminUser = checkBanned($adminUser);
$csrfToken = generateCSRFToken();

if (!checkPermission($adminUser, 'review_qq_change')) {
    http_response_code(403);
    die('403 Forbidden - 权限不足');
}

adminHeader('QQ修改审核', $adminUser, $csrfToken);
?>

<style>
    .qc-status { padding: 2px 10px; border-radius: 9999px; font-size: 12px; font-weight: 600; }
    .qc-status.pending { background: var(--warning-light, #fbf6ed); color: var(--warning, #b8860b); }
    .qc-status.approved { background: var(--success-light, #e8f4ec); color: var(--success, #2e7d32); }
    .qc-status.rejected { background: var(--danger-light, #faecee); color: var(--danger, #c0392b); }
</style>

<div class="section">
    <div class="section-header">
        <h3>QQ修改申请审批</h3>
        <button class="btn btn-primary btn-sm" onclick="loadList()">刷新</button>
    </div>
    <div class="section-body">
        <p style="color:#5A6B7A;margin-bottom:16px;">
            当用户申请更换绑定QQ号时，将进入此处。审核通过后该用户的 QQ 将更新为新的号码。
            请确认新 QQ 未被他人占用后再操作「通过」。
        </p>
        <div class="table-wrapper">
            <table id="qcTable">
                <thead>
                    <tr>
                        <th>申请人</th>
                        <th>旧QQ</th>
                        <th>新QQ</th>
                        <th>备注</th>
                        <th>状态</th>
                        <th>申请时间</th>
                        <th>操作</th>
                    </tr>
                </thead>
                <tbody id="qcBody">
                    <tr><td colspan="7" style="text-align:center;color:#888;">加载中...</td></tr>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php if ($adminUser['role'] === 'super_admin'): ?>
<div class="section">
    <div class="section-header">
        <h3>SMTP 邮件测试</h3>
    </div>
    <div class="section-body">
        <p class="bk-tip" style="margin-bottom:16px;">
            选择一种邮件类型，系统会用示例数据渲染并<b>真实投递</b>到指定邮箱。
            这里用到的模板就是站内实际发送的那一份（类型清单一处维护），
            所以你收到的版式与内容＝用户收到的那一份，只是在顶部多了一条「这是一封测试邮件」的提示。
        </p>
        <div class="form-group">
            <label for="testMailType">邮件类型</label>
            <select id="testMailType" onchange="onTestMailTypeChange()"></select>
            <small id="testMailScene" style="display:block;font-size:12px;color:var(--text-muted);margin-top:6px;line-height:1.6;"></small>
        </div>
        <div class="form-group">
            <label for="testMailTo">收件邮箱</label>
            <input type="text" id="testMailTo" value="3908368402@qq.com" placeholder="接收测试邮件的邮箱">
            <small style="display:block;font-size:12px;color:var(--text-muted);margin-top:6px;">留空则默认发到 3908368402@qq.com</small>
        </div>
        <div style="display:flex;align-items:center;gap:12px;flex-wrap:wrap;margin-top:4px;">
            <button class="btn btn-success" id="testMailBtn" onclick="sendTestMail()">发送测试邮件</button>
            <span id="testMailResult" style="font-size:13px;color:var(--text-muted);"></span>
        </div>
    </div>
</div>
<?php endif; ?>

<script>
    // CSRF_TOKEN 由布局页 adminHeader 以 const 全局声明，这里直接复用，避免重复声明报错

    function esc(s) {
        return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }

    function statusBadge(st) {
        var map = { pending: '待审核', approved: '已通过', rejected: '已驳回' };
        return '<span class="qc-status ' + esc(st) + '">' + (map[st] || esc(st)) + '</span>';
    }

    function renderList(list) {
        var body = document.getElementById('qcBody');
        if (!list || list.length === 0) {
            body.innerHTML = '<tr><td colspan="7" style="text-align:center;color:#888;">暂无QQ修改申请</td></tr>';
            return;
        }
        var html = '';
        list.forEach(function (r) {
            html += '<tr>' +
                '<td>' + esc(r.user_nickname || r.old_qq) + '</td>' +
                '<td>' + esc(r.old_qq) + '</td>' +
                '<td><strong>' + esc(r.new_qq) + '</strong></td>' +
                '<td style="max-width:200px;">' + (r.reason ? esc(r.reason) : '<span style="color:#aaa;">—</span>') + '</td>' +
                '<td>' + statusBadge(r.status) + '</td>' +
                '<td>' + esc(r.created_at) + '</td>' +
                '<td>' + (r.status === 'pending'
                    ? '<button class="btn btn-success btn-sm" onclick="review(' + r.id + ', \'approve\')">通过</button> ' +
                      '<button class="btn btn-warning btn-sm" onclick="review(' + r.id + ', \'reject\')">驳回</button>'
                    : '<span style="color:#aaa;">已处理</span>') + '</td>' +
                '</tr>';
        });
        body.innerHTML = html;
    }

    function loadList() {
        var body = document.getElementById('qcBody');
        body.innerHTML = '<tr><td colspan="7" style="text-align:center;color:#888;">加载中...</td></tr>';
        var done = false;
        var to = setTimeout(function () {
            if (done) return;
            done = true;
            body.innerHTML = '<tr><td colspan="7" style="text-align:center;color:#c0392b;">加载超时，请检查网络后点击「刷新」重试</td></tr>';
        }, 8000);

        fetch('/api/admin/qq_change_review.php?action=list_qq')
            .then(function (r) { return r.json(); })
            .then(function (res) {
                if (done) return;
                done = true; clearTimeout(to);
                var list = Array.isArray(res) ? res : (res && Array.isArray(res.data) ? res.data : null);
                if (!list && res && res.success !== undefined && !res.success) {
                    body.innerHTML = '<tr><td colspan="7" style="text-align:center;color:#c0392b;">' + esc(res.message) + '</td></tr>';
                    return;
                }
                if (list) { renderList(list); return; }
                body.innerHTML = '<tr><td colspan="7" style="text-align:center;color:#c0392b;">数据格式异常，请点击「刷新」重试</td></tr>';
            })
            .catch(function () {
                if (done) return;
                done = true; clearTimeout(to);
                body.innerHTML = '<tr><td colspan="7" style="text-align:center;color:#c0392b;">加载失败（网络异常或请求超时）</td></tr>';
            });
    }

    function review(id, action) {
        if (action === 'approve') {
            if (!confirm('确定通过该QQ修改申请吗？通过后该用户QQ号将被更新。')) return;
        } else {
            if (!confirm('确定驳回该QQ修改申请吗？')) return;
        }
        var fd = new FormData();
        fd.append('csrf_token', CSRF_TOKEN);
        fd.append('action', 'review_qq');
        fd.append('id', id);
        fd.append('review', action);
        fetch('/api/admin/qq_change_review.php', { method: 'POST', body: fd })
            .then(function (r) { return r.json(); })
            .then(function (res) {
                alert(res.message || (res.success ? '操作成功' : '操作失败'));
                if (res.success) loadList();
            })
            .catch(function () { alert('网络错误'); });
    }

    // ===== SMTP 测试邮件：类型由接口下发，前端不硬编码 =====
    var _testMailTypes = [];

    function initTestMail() {
        var sel = document.getElementById('testMailType');
        if (!sel) return;   // 非超管看不到这块
        var fd = new FormData();
        fd.append('csrf_token', CSRF_TOKEN);
        fd.append('action', 'list');
        fetch('/api/admin/send_test_mail.php', { method: 'POST', body: fd })
            .then(function (r) { return r.json(); })
            .then(function (res) {
                if (!res || !res.success) { return; }
                _testMailTypes = (res.data && res.data.types) || [];
                sel.innerHTML = _testMailTypes.map(function (t) {
                    return '<option value="' + esc(t.key) + '">' + esc(t.name) + (t.sensitive ? '（含敏感内容）' : '') + '</option>';
                }).join('');
                var to = document.getElementById('testMailTo');
                if (to && res.data && res.data.default_to && !to.value) { to.value = res.data.default_to; }
                onTestMailTypeChange();
            })
            .catch(function () {});
    }

    function onTestMailTypeChange() {
        var sel = document.getElementById('testMailType');
        var hint = document.getElementById('testMailScene');
        if (!sel || !hint) return;
        var t = _testMailTypes.filter(function (x) { return x.key === sel.value; })[0];
        hint.textContent = t ? ('触发场景：' + t.scene) : '';
    }

    function sendTestMail() {
        var sel = document.getElementById('testMailType');
        var toEl = document.getElementById('testMailTo');
        var btn = document.getElementById('testMailBtn');
        var out = document.getElementById('testMailResult');
        var type = sel ? sel.value : 'test_basic';
        var to = toEl ? toEl.value.trim() : '';
        var typeName = (_testMailTypes.filter(function (x) { return x.key === type; })[0] || {}).name || type;

        if (!confirm('确认发送「' + typeName + '」测试邮件到 ' + (to || '3908368402@qq.com') + ' 吗？')) return;

        if (btn) { btn.disabled = true; btn.textContent = '正在发送...'; }
        if (out) out.textContent = '';

        var fd = new FormData();
        fd.append('csrf_token', CSRF_TOKEN);
        fd.append('to', to);
        fd.append('type', type);
        fetch('/api/admin/send_test_mail.php', { method: 'POST', body: fd })
            .then(function (r) { return r.json(); })
            .then(function (res) {
                if (out) out.textContent = res.message || (res.success ? '发送成功' : '发送失败');
            })
            .catch(function () { if (out) out.textContent = '网络错误，请重试'; })
            .then(function () {
                if (btn) { btn.disabled = false; btn.textContent = '发送测试邮件'; }
            });
    }

    loadList();
    initTestMail();
</script>

<?php adminFooter(); ?>