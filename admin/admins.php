<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/layout.php';

$adminUser = requireAdmin();
$adminUser = checkBanned($adminUser);
$csrfToken = generateCSRFToken();

if (!checkPermission($adminUser, 'manage_admins')) {
    http_response_code(403);
    die('403 Forbidden - 仅超级管理员可访问');
}

adminHeader('管理员管理', $adminUser, $csrfToken);
?>

<div class="section">
    <div class="section-header">
        <h3>管理员列表</h3>
        <button class="btn btn-primary btn-sm" onclick="openAddAdminModal()">添加管理员</button>
    </div>
    <div class="section-body">
        <div class="table-wrapper">
            <table>
                <thead>
                    <tr>
                        <th>管理员</th>
                        <th>QQ号</th>
                        <th>角色</th>
                        <th>权限</th>
                        <th>添加时间</th>
                        <th>操作</th>
                    </tr>
                </thead>
                <tbody id="adminsTableBody">
                    <tr><td colspan="6" class="loading-spinner">加载中</td></tr>
                </tbody>
            </table>
        </div>
    </div>
</div>

<div class="modal-overlay" id="addAdminModal">
    <div class="modal" style="max-width:700px;">
        <div class="modal-header">
            <h3>添加管理员</h3>
            <button class="modal-close" onclick="closeModal('addAdminModal')" aria-label="关闭">×</button>
        </div>
        <div class="modal-body">
            <div class="form-group">
                <label>QQ号</label>
                <input type="text" id="addAdminQQ" placeholder="请输入已注册用户的QQ号" maxlength="15">
                <div class="error-hint" id="addAdminQQError">QQ号格式不正确</div>
            </div>
            <div class="form-group">
                <label>初始权限</label>
                <div id="addAdminPerms"></div>
            </div>
        </div>
        <div class="modal-footer">
            <button class="btn btn-outline" onclick="closeModal('addAdminModal')">取消</button>
            <button class="btn btn-primary" onclick="addAdmin()">确认添加</button>
        </div>
    </div>
</div>

<div class="modal-overlay" id="editPermsModal">
    <div class="modal" style="max-width:700px;">
        <div class="modal-header">
            <h3>编辑管理员权限</h3>
            <button class="modal-close" onclick="closeModal('editPermsModal')" aria-label="关闭">×</button>
        </div>
        <div class="modal-body">
            <input type="hidden" id="editPermsAdminId">
            <div id="editPermsContent"></div>
        </div>
        <div class="modal-footer">
            <button class="btn btn-outline" onclick="closeModal('editPermsModal')">取消</button>
            <button class="btn btn-primary" onclick="savePermissions()">保存权限</button>
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
var adminList = [];
var permissionGroups = {};
var permLabels = {};

function loadAdmins() {
    fetch(SITE_URL + '/api/admin/admins.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: new URLSearchParams({ csrf_token: CSRF_TOKEN, action: 'list' })
    })
    .then(function(r) { return r.json(); })
    .then(function(data) {
        if (data.success) {
            adminList = data.data.admins;
            permissionGroups = data.data.permission_groups;

            permLabels = {};
            for (var g in permissionGroups) {
                for (var k in permissionGroups[g]) {
                    permLabels[k] = permissionGroups[g][k];
                }
            }
            var tbody = document.getElementById('adminsTableBody');
            tbody.innerHTML = adminList.map(function(a) {
                var roleBadge = a.role === 'super_admin'
                    ? '<span class="badge badge-super">超级管理员</span>'
                    : '<span class="badge badge-admin">管理员</span>';

                var permTags = '<div class="perm-tags">';
                if (a.role === 'super_admin') {
                    permTags += '<span class="perm-tag">全部权限</span>';
                } else {
                    (a.permissions || []).forEach(function(p) {
                        permTags += '<span class="perm-tag">' + (permLabels[p] || p) + '</span>';
                    });
                }
                permTags += '</div>';

                var actions = '';
                if (a.role !== 'super_admin') {
                    actions += '<button class="btn btn-outline btn-sm" onclick="openEditPerms(' + a.id + ')">权限</button>';
                    actions += '<button class="btn btn-danger btn-sm" onclick="confirmDeleteAdmin(' + a.id + ')">删除</button>';
                }

                return '<tr>' +
                    '<td><div style="display:flex;align-items:center;gap:8px;"><img src="' + esc(a.avatar || '') + '" style="width:32px;height:32px;border-radius:50%;" onerror="this.style.display=\'none\'"><span>' + esc(a.nickname || '未设置') + '</span></div></td>' +
                    '<td>' + esc(a.qq) + '</td>' +
                    '<td>' + roleBadge + '</td>' +
                    '<td>' + permTags + '</td>' +
                    '<td>' + (a.created_at ? a.created_at.substring(0,10) : '-') + '</td>' +
                    '<td><div style="display:flex;gap:4px;">' + actions + '</div></td>' +
                    '</tr>';
            }).join('');
        }
    })
    .catch(function(e) {
        showToast('加载失败', 'error');
        var tbody = document.getElementById('adminsTableBody');
        tbody.innerHTML = '<tr><td colspan="6"><div class="empty-state"><div class="empty-icon"><svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg></div><p>加载失败，请刷新重试</p></div></td></tr>';
    });
}

function renderPermissionCheckboxes(containerId, selectedPerms) {
    var container = document.getElementById(containerId);
    selectedPerms = selectedPerms || [];
    var html = '';
    for (var g in permissionGroups) {
        html += '<div class="perm-group">';
        html += '<div class="perm-group-header">';
        html += '<h4 class="perm-group-title">' + g + '</h4>';
        html += '<div class="perm-actions">';
        html += '<button type="button" class="perm-action-btn" onclick="selectGroup(\'' + containerId + '\', \'' + g.replace(/'/g, "\\'") + '\', true)">全选</button>';
        html += '<button type="button" class="perm-action-btn" onclick="selectGroup(\'' + containerId + '\', \'' + g.replace(/'/g, "\\'") + '\', false)">清空</button>';
        html += '</div>';
        html += '</div>';
        html += '<div class="perm-checkboxes">';
        for (var k in permissionGroups[g]) {
            var checked = selectedPerms.indexOf(k) >= 0 ? 'checked' : '';
            html += '<label class="perm-checkbox-item">';
            html += '<input type="checkbox" class="perm-cb" value="' + k + '" ' + checked + '>';
            html += '<span class="perm-label-text">' + permissionGroups[g][k] + '</span>';
            html += '</label>';
        }
        html += '</div></div>';
    }
    container.innerHTML = html;
}

function selectGroup(containerId, groupName, select) {
    var perms = permissionGroups[groupName];
    if (!perms) return;
    var container = document.getElementById(containerId);
    for (var k in perms) {
        var cb = container.querySelector('input[value="' + k + '"]');
        if (cb) cb.checked = select;
    }
}

function getCheckedPermissions(containerId) {
    var perms = [];
    document.querySelectorAll('#' + containerId + ' .perm-cb:checked').forEach(function(cb) {
        perms.push(cb.value);
    });
    return perms;
}

function openAddAdminModal() {
    document.getElementById('addAdminQQ').value = '';
    document.getElementById('addAdminQQError').classList.remove('show');
    renderPermissionCheckboxes('addAdminPerms', []);
    openModal('addAdminModal');
}

function addAdmin() {
    var qq = document.getElementById('addAdminQQ').value.trim();
    if (!/^[1-9][0-9]{4,14}$/.test(qq)) {
        document.getElementById('addAdminQQError').classList.add('show');
        return;
    }
    document.getElementById('addAdminQQError').classList.remove('show');

    var perms = getCheckedPermissions('addAdminPerms');

    fetch(SITE_URL + '/api/admin/admins.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: new URLSearchParams({
            csrf_token: CSRF_TOKEN,
            action: 'add',
            qq: qq,
            permissions: JSON.stringify(perms)
        })
    })
    .then(function(r) { return r.json(); })
    .then(function(data) {
        if (data.success) { showToast('管理员添加成功'); closeModal('addAdminModal'); loadAdmins(); }
        else { showToast(data.message, 'error'); }
    })
    .catch(function() { showToast('网络错误', 'error'); });
}

function openEditPerms(adminId) {
    var admin = null;
    for (var i = 0; i < adminList.length; i++) {
        if (adminList[i].id === adminId) { admin = adminList[i]; break; }
    }
    if (!admin) return;
    document.getElementById('editPermsAdminId').value = adminId;
    renderPermissionCheckboxes('editPermsContent', admin.permissions || []);
    openModal('editPermsModal');
}

function savePermissions() {
    var adminId = document.getElementById('editPermsAdminId').value;
    var perms = getCheckedPermissions('editPermsContent');

    fetch(SITE_URL + '/api/admin/admins.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: new URLSearchParams({
            csrf_token: CSRF_TOKEN,
            action: 'edit_permissions',
            admin_id: adminId,
            permissions: JSON.stringify(perms)
        })
    })
    .then(function(r) { return r.json(); })
    .then(function(data) {
        if (data.success) { showToast('权限已更新'); closeModal('editPermsModal'); loadAdmins(); }
        else { showToast(data.message, 'error'); }
    })
    .catch(function() { showToast('网络错误', 'error'); });
}

function confirmDeleteAdmin(adminId) {
    document.getElementById('confirmMessage').textContent = '确定要删除该管理员吗？此操作不可恢复。';
    document.getElementById('confirmBtn').onclick = function() {
        fetch(SITE_URL + '/api/admin/admins.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: new URLSearchParams({ csrf_token: CSRF_TOKEN, action: 'delete', admin_id: adminId })
        })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (data.success) { showToast('管理员已删除'); closeModal('confirmModal'); loadAdmins(); }
            else { showToast(data.message, 'error'); }
        })
        .catch(function() { showToast('网络错误', 'error'); });
    };
    openModal('confirmModal');
}

loadAdmins();
</script>

<?php adminFooter(); ?>
