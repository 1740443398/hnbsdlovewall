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
<style>
.perm-mode-desc { font-size: 12px; color: var(--text-secondary); margin-top: 4px; }
.perm-mode-desc.strong { color: var(--primary); font-weight: 600; }
.perm-tag-mode { background: var(--warning-light); color: var(--warning); }

/* 用户搜索选择器（替代手输QQ） */
.user-picker-results {
    display: none;
    margin-top: 6px;
    border: 1px solid var(--border);
    border-radius: 10px;
    background: var(--card-bg);
    max-height: 260px;
    overflow-y: auto;
    box-shadow: 0 8px 24px rgba(16, 24, 40, 0.08);
}
.user-picker-results.show { display: block; }
.user-picker-item {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 9px 12px;
    cursor: pointer;
    border-bottom: 1px solid var(--border-light, var(--border));
    transition: background 0.18s ease;
}
.user-picker-item:last-child { border-bottom: none; }
.user-picker-item:hover { background: var(--bg-secondary, #f4f6fa); }
.user-picker-item img {
    width: 30px;
    height: 30px;
    border-radius: 50%;
    object-fit: cover;
    flex-shrink: 0;
}
.user-picker-name { font-size: 14px; font-weight: 600; color: var(--text); }
.user-picker-qq { font-size: 12px; color: var(--text-secondary); margin-left: auto; }
.user-picker-empty { padding: 12px; font-size: 13px; color: var(--text-secondary); text-align: center; }
.user-picker-selected {
    margin-top: 8px;
    padding: 8px 12px;
    border-radius: 10px;
    background: var(--primary-light, #eef2ff);
    font-size: 13px;
    color: var(--text);
}
</style>

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
                <label>选择用户</label>
                <input type="text" id="addAdminUserSearch" placeholder="输入QQ号或昵称搜索已注册用户..." autocomplete="off" oninput="debounceAddAdminSearch()">
                <input type="hidden" id="addAdminQQ" value="">
                <div id="addAdminUserResults" class="user-picker-results"></div>
                <div class="user-picker-selected" id="addAdminUserSelected" style="display:none;"></div>
                <div class="error-hint" id="addAdminQQError">请选择要添加为管理员的用户</div>
            </div>
            <div class="form-group">
                <label>权限模式</label>
                <select id="addAdminPermMode" onchange="onPermModeChange('add')"></select>
                <div class="perm-mode-desc" id="addAdminPermModeDesc">自定义模式：按下方权限分组逐项勾选</div>
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
            <div class="form-group">
                <label>权限模式</label>
                <select id="editPermsMode" onchange="onPermModeChange('edit')"></select>
                <div class="perm-mode-desc" id="editPermsModeDesc"></div>
            </div>
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
var permTemplates = {};
var permModeShortLabels = {
    'custom': '自定义',
    't1': '模版T1',
    't2': '模版T2',
    't3': '模版T3'
};

function permModeSelectHtml(selected) {
    var html = '<option value="custom"' + (selected === 'custom' ? ' selected' : '') + '>自定义（逐项勾选）</option>';
    for (var t in permTemplates) {
        html += '<option value="' + t + '"' + (selected === t ? ' selected' : '') + '>' + (permTemplates[t].label || t) + '</option>';
    }
    return html;
}

function updatePermModeDesc(scope) {
    var isAdd = scope === 'add';
    var mode = document.getElementById(isAdd ? 'addAdminPermMode' : 'editPermsMode').value;
    var descEl = document.getElementById(isAdd ? 'addAdminPermModeDesc' : 'editPermsModeDesc');
    if (mode === 'custom') {
        descEl.textContent = '自定义模式：按下方权限分组逐项勾选（不勾选任何权限则仅可登录后台）';
        descEl.classList.remove('strong');
    } else if (permTemplates[mode]) {
        descEl.textContent = permTemplates[mode].label + '，共 ' + permTemplates[mode].permissions.length + ' 项权限，已自动勾选';
        descEl.classList.add('strong');
    }
}

function onPermModeChange(scope) {
    var isAdd = scope === 'add';
    var containerId = isAdd ? 'addAdminPerms' : 'editPermsContent';
    var mode = document.getElementById(isAdd ? 'addAdminPermMode' : 'editPermsMode').value;
    updatePermModeDesc(scope);
    if (!permTemplates[mode]) return;
    var perms = permTemplates[mode].permissions;
    var container = document.getElementById(containerId);
    container.querySelectorAll('.perm-cb').forEach(function(cb) {
        cb.checked = perms.indexOf(cb.value) >= 0;
    });
}

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
            permTemplates = data.data.perm_templates || {};

            permLabels = {};
            for (var g in permissionGroups) {
                for (var k in permissionGroups[g]) {
                    permLabels[k] = permissionGroups[g][k];
                }
            }
            document.getElementById('addAdminPermMode').innerHTML = permModeSelectHtml('custom');
            document.getElementById('editPermsMode').innerHTML = permModeSelectHtml('custom');
            var tbody = document.getElementById('adminsTableBody');
            tbody.innerHTML = adminList.map(function(a) {
                var roleBadge = a.role === 'super_admin'
                    ? '<span class="badge badge-super">超级管理员</span>'
                    : '<span class="badge badge-admin">管理员</span>';

                var modeTag = '';
                if (a.role === 'super_admin') {
                    modeTag = '<span class="perm-tag perm-tag-mode">站长（全部权限）</span>';
                } else if (permModeShortLabels[a.perm_mode]) {
                    modeTag = '<span class="perm-tag perm-tag-mode">' + permModeShortLabels[a.perm_mode] + '</span>';
                }

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
                    '<td>' + roleBadge + '<br>' + modeTag + '</td>' +
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
    document.getElementById('addAdminUserSearch').value = '';
    document.getElementById('addAdminQQ').value = '';
    document.getElementById('addAdminUserResults').innerHTML = '';
    document.getElementById('addAdminUserResults').classList.remove('show');
    document.getElementById('addAdminUserSelected').style.display = 'none';
    document.getElementById('addAdminQQError').classList.remove('show');
    document.getElementById('addAdminPermMode').value = 'custom';
    updatePermModeDesc('add');
    renderPermissionCheckboxes('addAdminPerms', []);
    openModal('addAdminModal');
}

var addAdminSearchTimer = null;
function debounceAddAdminSearch() {
    clearTimeout(addAdminSearchTimer);
    addAdminSearchTimer = setTimeout(searchAddAdminUser, 300);
}

function searchAddAdminUser() {
    var kw = document.getElementById('addAdminUserSearch').value.trim();
    var box = document.getElementById('addAdminUserResults');
    if (kw.length === 0) {
        box.innerHTML = '';
        box.classList.remove('show');
        return;
    }
    fetch(SITE_URL + '/api/admin/users.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: new URLSearchParams({
            csrf_token: CSRF_TOKEN,
            action: 'list',
            page: 1,
            search: kw
        })
    })
    .then(function(r) { return r.json(); })
    .then(function(data) {
        if (!data.success) return;
        var users = (data.data && data.data.users) || [];
        if (users.length === 0) {
            box.innerHTML = '<div class="user-picker-empty">未找到匹配用户</div>';
            box.classList.add('show');
            return;
        }
        box.innerHTML = users.slice(0, 8).map(function(u) {
            return '<div class="user-picker-item" data-qq="' + esc(u.qq) + '" data-label="' + esc(u.nickname + '（' + u.qq + '）') + '">' +
                '<img src="' + esc(u.avatar) + '" alt="" onerror="this.style.display=\'none\'">' +
                '<span class="user-picker-name">' + esc(u.nickname) + '</span>' +
                '<span class="user-picker-qq">' + esc(u.qq) + '</span>' +
                (u.role === 'admin' || u.role === 'super_admin' ? '<span class="badge badge-admin">已是管理员</span>' : '') +
                '</div>';
        }).join('');
        box.classList.add('show');
        box.querySelectorAll('.user-picker-item').forEach(function(item) {
            item.addEventListener('click', function() {
                selectAddAdminUser(item.getAttribute('data-qq'), item.getAttribute('data-label'));
            });
        });
    })
    .catch(function() {});
}

function selectAddAdminUser(qq, label) {
    document.getElementById('addAdminQQ').value = qq;
    document.getElementById('addAdminUserSearch').value = '';
    var box = document.getElementById('addAdminUserResults');
    box.innerHTML = '';
    box.classList.remove('show');
    var sel = document.getElementById('addAdminUserSelected');
    sel.innerHTML = '已选择：<strong>' + esc(label) + '</strong>';
    sel.style.display = 'block';
    document.getElementById('addAdminQQError').classList.remove('show');
}

function addAdmin() {
    var qq = document.getElementById('addAdminQQ').value.trim();
    if (!/^[1-9][0-9]{4,14}$/.test(qq)) {
        document.getElementById('addAdminQQError').classList.add('show');
        return;
    }
    document.getElementById('addAdminQQError').classList.remove('show');

    var perms = getCheckedPermissions('addAdminPerms');
    var permMode = document.getElementById('addAdminPermMode').value;

    fetch(SITE_URL + '/api/admin/admins.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: new URLSearchParams({
            csrf_token: CSRF_TOKEN,
            action: 'add',
            qq: qq,
            perm_mode: permMode,
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
    var mode = admin.perm_mode || 'custom';
    if (permTemplates[mode]) {
        // 模版模式：按模版定义勾选（若模版定义有更新则以模版为准）
        renderPermissionCheckboxes('editPermsContent', permTemplates[mode].permissions);
    } else {
        renderPermissionCheckboxes('editPermsContent', admin.permissions || []);
    }
    document.getElementById('editPermsMode').value = mode;
    updatePermModeDesc('edit');
    openModal('editPermsModal');
}

function savePermissions() {
    var adminId = document.getElementById('editPermsAdminId').value;
    var perms = getCheckedPermissions('editPermsContent');
    var permMode = document.getElementById('editPermsMode').value;

    fetch(SITE_URL + '/api/admin/admins.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: new URLSearchParams({
            csrf_token: CSRF_TOKEN,
            action: 'edit_permissions',
            admin_id: adminId,
            perm_mode: permMode,
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
