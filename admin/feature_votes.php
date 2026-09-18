<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/layout.php';

$adminUser = requireAdmin();
$adminUser = checkBanned($adminUser);
$csrfToken = generateCSRFToken();

adminHeader('功能投票', $adminUser, $csrfToken);
?>

<div class="section">
    <div class="section-header">
        <h3>功能建议列表</h3>
    </div>
    <div class="section-body">
        <div class="table-wrapper">
            <table>
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>功能建议</th>
                        <th>提出者</th>
                        <th>票数</th>
                        <th>状态</th>
                        <th>提交时间</th>
                        <th>操作</th>
                    </tr>
                </thead>
                <tbody id="fvTable">
                    <tr><td colspan="7" class="loading-spinner">加载中</td></tr>
                </tbody>
            </table>
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
    var featureList = [];

    function confirmAction(message, fn) {
        document.getElementById('confirmMessage').textContent = message;
        var btn = document.getElementById('confirmBtn');
        btn.onclick = function () { closeModal('confirmModal'); fn(); };
        openModal('confirmModal');
    }

    function loadFeatureVotes() {
        fetch('/api/feature_requests.php')
            .then(function (r) { return r.json(); })
            .then(function (res) {
                if (!res.success) return;
                featureList = res.data || [];
                renderFeatureVotes();
            })
            .catch(function () {
                document.getElementById('fvTable').innerHTML = '<tr><td colspan="7">加载失败</td></tr>';
            });
    }

    function renderFeatureVotes() {
        var tbody = document.getElementById('fvTable');
        if (featureList.length === 0) {
            tbody.innerHTML = '<tr><td colspan="7">暂无功能建议</td></tr>';
            return;
        }
        tbody.innerHTML = featureList.map(function (f) {
            var done = f.status === 'done';
            return '<tr>' +
                '<td>' + esc(f.id) + '</td>' +
                '<td style="max-width:380px;">' + esc(f.title) + '</td>' +
                '<td>' + esc(f.nickname || ('QQ:' + f.qq)) + '</td>' +
                '<td><span class="badge" style="background:var(--info-light);color:var(--info);">' + esc(f.vote_count) + '</span></td>' +
                '<td><span class="badge" style="background:' + (done ? 'var(--success-light)' : 'var(--warning-light)') + ';color:' + (done ? 'var(--success)' : 'var(--warning)') + ';">' + (done ? '已实现' : '待实现') + '</span></td>' +
                '<td>' + esc(f.created_at || '--') + '</td>' +
                '<td>' +
                    '<button class="btn btn-sm ' + (done ? 'btn-outline' : 'btn-primary') + '" onclick="toggleStatus(' + esc(f.id) + ')">' + (done ? '标记待实现' : '标记已实现') + '</button> ' +
                    '<button class="btn btn-sm btn-danger" onclick="deleteFeature(' + esc(f.id) + ')">删除</button>' +
                '</td>' +
            '</tr>';
        }).join('');
    }

    function toggleStatus(id) {
        var f = featureList.find(function (x) { return parseInt(x.id) === parseInt(id); });
        var target = (f && f.status === 'done') ? 'pending' : 'done';
        confirmAction('确定标记该功能建议为' + (target === 'done' ? '已实现' : '待实现') + '吗？', function () {
            var fd = new FormData();
            fd.append('csrf_token', CSRF_TOKEN);
            fd.append('action', 'status');
            fd.append('id', id);
            fd.append('status', target);
            fetch('/api/admin/feature_requests.php', { method: 'POST', body: fd })
                .then(function (r) { return r.json(); })
                .then(function (res) {
                    if (res.success) { showToast(res.message || '已更新'); loadFeatureVotes(); }
                    else { alert(res.message || '操作失败'); }
                })
                .catch(function () { alert('操作失败'); });
        });
    }

    function deleteFeature(id) {
        confirmAction('确定删除该功能建议吗？删除后无法恢复，相关投票也会一并删除。', function () {
            var fd = new FormData();
            fd.append('csrf_token', CSRF_TOKEN);
            fd.append('action', 'delete');
            fd.append('id', id);
            fetch('/api/admin/feature_requests.php', { method: 'POST', body: fd })
                .then(function (r) { return r.json(); })
                .then(function (res) {
                    if (res.success) { showToast(res.message || '已删除'); loadFeatureVotes(); }
                    else { alert(res.message || '操作失败'); }
                })
                .catch(function () { alert('操作失败'); });
        });
    }

    function showToast(msg) {
        var t = document.createElement('div');
        t.textContent = msg;
        t.style.cssText = 'position:fixed;bottom:32px;left:50%;transform:translateX(-50%);background:#333;color:#fff;padding:10px 18px;border-radius:8px;z-index:9999;font-size:14px;box-shadow:0 4px 12px rgba(0,0,0,0.2);';
        document.body.appendChild(t);
        setTimeout(function () { t.remove(); }, 2200);
    }

    loadFeatureVotes();
</script>

<?php adminFooter(); ?>