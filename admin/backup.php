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

$isSuper = ($adminUser['role'] === 'super_admin');

adminHeader('数据备份/恢复', $adminUser, $csrfToken);
?>

<style>
    .bk-tip { color: var(--text-muted, #888); font-size: 13px; line-height: 1.6; }
    .bk-box { border: 1px solid var(--border, #e2e5ea); border-radius: 12px; padding: 16px; margin-top: 12px; }
    .bk-actions { display: flex; gap: 12px; flex-wrap: wrap; margin-top: 8px; }
</style>

<div class="section">
    <div class="section-header">
        <h3>数据备份 / 恢复</h3>
    </div>
    <div class="section-body">
        <div class="bk-box">
            <div style="font-weight:600;margin-bottom:4px;">导出完整备份</div>
            <div class="bk-tip">一键导出包含全部数据表的完整备份，生成一大段 JSON 文件，可直接用于迁移站点或稍后「导入」还原维护。</div>
            <div class="bk-actions">
                <button class="btn btn-success" onclick="doExport('full')">导出完整备份（全部数据）</button>
            </div>
        </div>

        <?php if ($isSuper): ?>
        <div class="bk-box">
            <div style="font-weight:600;margin-bottom:4px;">导入完整备份</div>
            <div class="bk-tip">从「完整备份」导出的 JSON 文件中复制全部内容粘贴到下方，点击恢复。此操作会覆盖现有数据，不可撤销，请先在「导出」处完成备份后再操作。</div>
            <div style="margin-top:10px;">
                <textarea id="importDataArea" rows="8" class="form-input" placeholder="在此粘贴完整备份的 JSON 内容..." style="width:100%;font-family:Consolas,'Courier New',monospace;font-size:12px;box-sizing:border-box;"></textarea>
            </div>
            <div class="bk-actions">
                <button class="btn btn-primary" onclick="doImport()">恢复数据</button>
                <button class="btn btn-outline" onclick="loadExample()">粘贴示例结构</button>
            </div>
        </div>
        <?php else: ?>
        <div class="bk-tip" style="margin-top:16px;">仅超级管理员可执行数据导入（恢复）。如需导入，请使用超级管理员账号登录。</div>
        <?php endif; ?>
    </div>
</div>

<script>
    var CSRF_TOKEN = <?= json_encode($csrfToken) ?>;

    function doExport(type) {
        var fd = new URLSearchParams();
        fd.append('csrf_token', CSRF_TOKEN);
        fd.append('type', type);
        fetch('/api/admin/export.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: fd.toString()
        })
            .then(function (r) { return r.json(); })
            .then(function (res) {
                if (res && res.success && res.data) {
                    var jsonStr = JSON.stringify(res.data, null, 2);
                    var blob = new Blob(['\ufeff' + jsonStr], { type: 'application/json;charset=utf-8' });
                    var url = URL.createObjectURL(blob);
                    var a = document.createElement('a');
                    a.href = url;
                    a.download = type + '_export_' + new Date().toISOString().slice(0, 10) + '.json';
                    document.body.appendChild(a);
                    a.click();
                    document.body.removeChild(a);
                    URL.revokeObjectURL(url);
                    showToast('导出成功', 'success');
                } else {
                    showToast((res && res.message) || '导出失败', 'error');
                }
            })
            .catch(function () { showToast('导出失败（网络异常）', 'error'); });
    }

    function loadExample() {
        var area = document.getElementById('importDataArea');
        if (!area) return;
        area.value = JSON.stringify({
            users: [{ id: 1, qq: '1740443398', nickname: '示例', password_hash: '...', role: 'user', security_stamp: '...', is_banned: 0, created_at: '2026-01-01 00:00:00' }],
            posts: [],
            comments: [],
            settings: [],
            pm_messages: [],
            pm_read: []
        }, null, 2);
        showToast('已填入示例结构，请替换为自己的备份内容', 'info');
    }

    function doImport() {
        var area = document.getElementById('importDataArea');
        if (!area) return;
        var content = (area.value || '').trim();
        if (!content) { showToast('请先粘贴完整备份的 JSON 内容', 'warning'); return; }
        var parsed;
        try { parsed = JSON.parse(content); }
        catch (e) { showToast('JSON 格式不正确，请检查', 'error'); return; }
        if (!window.confirm('即将覆盖现有数据并恢复备份。此操作不可撤销，请确认你已备份好当前数据。是否继续？')) return;

        var fd = new URLSearchParams();
        fd.append('csrf_token', CSRF_TOKEN);
        fd.append('data', JSON.stringify(parsed));
        fetch('/api/admin/import.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: fd.toString()
        })
            .then(function (r) { return r.json(); })
            .then(function (res) {
                showToast((res && res.message) || '恢复完成', (res && res.success) ? 'success' : 'error');
            })
            .catch(function () { showToast('导入失败（网络异常）', 'error'); });
    }
</script>

<?php adminFooter(); ?>