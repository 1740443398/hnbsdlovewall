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

$hasBanUser = checkPermission($adminUser, 'ban_user');
$hasPermanentBan = checkPermission($adminUser, 'permanent_ban');
$hasUnbanUser = checkPermission($adminUser, 'unban_user');
$hasEditBanReason = checkPermission($adminUser, 'edit_ban_reason');
$hasReset2fa = checkPermission($adminUser, 'reset_user_2fa');
$hasResetPassword = checkPermission($adminUser, 'reset_user_password');
$hasViewDetail = checkPermission($adminUser, 'view_user_detail');
$hasChangeUsername = checkPermission($adminUser, 'change_username');
$hasDeleteUser = checkPermission($adminUser, 'delete_user');

adminHeader('用户管理', $adminUser, $csrfToken);
?>

<div class="section">
    <div class="section-header">
        <h3>用户列表</h3>
        <div>
            <?php if ($hasBanUser): ?>
            <button class="btn btn-warning btn-sm" onclick="batchAction('ban')">批量封禁</button>
            <?php endif; ?>
            <?php if ($hasUnbanUser): ?>
            <button class="btn btn-success btn-sm" onclick="batchAction('unban')">批量解封</button>
            <?php endif; ?>
        </div>
    </div>
    <div class="section-body">
        <div class="filter-row">
            <div class="form-group">
                <input type="text" id="searchUser" placeholder="搜索QQ号或昵称..." onkeyup="debounceSearch()">
            </div>
            <button class="btn btn-primary btn-sm" onclick="loadUsers()">搜索</button>
        </div>

        <div class="table-wrapper">
            <table>
                <thead>
                    <tr>
                        <th><input type="checkbox" id="selectAll" onchange="toggleSelectAll()"></th>
                        <th>用户</th>
                        <th>QQ号</th>
                        <th>角色</th>
                        <th>头衔</th>
                        <th>2FA</th>
                        <th>状态</th>
                        <th>访问次数</th>
                        <th>注册时间</th>
                        <th>操作</th>
                    </tr>
                </thead>
                <tbody id="usersTableBody">
                    <tr><td colspan="10" class="loading-spinner">加载中</td></tr>
                </tbody>
            </table>
        </div>
        <div class="pagination" id="usersPagination"></div>
    </div>
</div>

<div class="modal-overlay" id="banModal">
    <div class="modal">
        <div class="modal-header">
            <h3>封禁用户</h3>
            <button class="modal-close" onclick="closeModal('banModal')" aria-label="关闭">×</button>
        </div>
        <div class="modal-body">
            <input type="hidden" id="banUserId">
            <div class="form-group">
                <label>封禁时长</label>
                <select id="banDuration">
                    <option value="1">1天</option>
                    <option value="3">3天</option>
                    <option value="7">7天</option>
                    <?php if ($hasPermanentBan): ?>
                    <option value="permanent">永久封禁</option>
                    <?php endif; ?>
                </select>
            </div>
            <div class="form-group">
                <label>封禁原因</label>
                <textarea id="banReason" placeholder="请输入封禁原因"></textarea>
            </div>
        </div>
        <div class="modal-footer">
            <button class="btn btn-outline" onclick="closeModal('banModal')">取消</button>
            <button class="btn btn-danger" onclick="confirmBan()">确认封禁</button>
        </div>
    </div>
</div>

<div class="modal-overlay" id="editBanReasonModal">
    <div class="modal">
        <div class="modal-header">
            <h3>编辑封禁原因</h3>
            <button class="modal-close" onclick="closeModal('editBanReasonModal')" aria-label="关闭">×</button>
        </div>
        <div class="modal-body">
            <input type="hidden" id="editBanReasonUserId">
            <div class="form-group">
                <label>封禁原因</label>
                <textarea id="editBanReasonText" placeholder="请输入封禁原因"></textarea>
            </div>
        </div>
        <div class="modal-footer">
            <button class="btn btn-outline" onclick="closeModal('editBanReasonModal')">取消</button>
            <button class="btn btn-primary" onclick="confirmEditBanReason()">保存</button>
        </div>
    </div>
</div>

<div class="modal-overlay" id="titleModal">
    <div class="modal">
        <div class="modal-header">
            <h3>设置专属头衔</h3>
            <button class="modal-close" onclick="closeModal('titleModal')" aria-label="关闭">×</button>
        </div>
        <div class="modal-body">
            <input type="hidden" id="titleUserId">
            <div style="margin-bottom:16px;padding:12px;background:var(--bg);border-radius:8px;">
                <label style="font-size:13px;color:var(--text-secondary);">预览效果：</label>
                <div id="titlePreview" style="margin-top:8px;">
                    <span class="user-title" style="display:inline-block;padding:2px 12px;border-radius:6px;font-size:13px;font-weight:600;background:var(--primary);color:#fff;">头衔预览</span>
                </div>
            </div>
            <div class="form-group">
                <label>头衔文字</label>
                <input type="text" id="titleText" placeholder="如：校园达人、热心学长..." maxlength="20" oninput="updateTitlePreview()">
            </div>
            <div class="form-group">
                <label>文字颜色</label>
                <div style="display:flex;align-items:center;gap:8px;">
                    <input type="color" id="titleColor" value="#ffffff" onchange="updateTitlePreview()" style="width:40px;height:36px;border:1px solid var(--border);border-radius:6px;cursor:pointer;">
                    <input type="text" id="titleColorText" value="#ffffff" oninput="syncColor('titleColor','titleColorText')" style="width:100px;">
                </div>
            </div>
            <div class="form-group">
                <label>背景颜色</label>
                <div style="display:flex;align-items:center;gap:8px;">
                    <input type="color" id="titleBgColor" value="#4A90D9" onchange="updateTitlePreview()" style="width:40px;height:36px;border:1px solid var(--border);border-radius:6px;cursor:pointer;">
                    <input type="text" id="titleBgColorText" value="#4A90D9" oninput="syncColor('titleBgColor','titleBgColorText')" style="width:100px;">
                </div>
            </div>
            <div class="form-group">
                <label style="display:flex;align-items:center;gap:8px;cursor:pointer;">
                    <input type="checkbox" id="titleRainbow" onchange="updateTitlePreview()" style="width:18px;height:18px;">
                    <span>彩虹变换效果</span>
                </label>
                <small style="color:var(--text-secondary);">开启后头衔背景会循环变换两端之间的颜色</small>
            </div>
            <div id="gradientRow" style="display:none;">
                <div class="form-group">
                    <label>渐变起始颜色</label>
                    <div style="display:flex;align-items:center;gap:8px;">
                        <input type="color" id="gradientStart" value="#ff4757" onchange="updateTitlePreview()" style="width:40px;height:36px;border:1px solid var(--border);border-radius:6px;cursor:pointer;">
                        <input type="text" id="gradientStartText" value="#ff4757" maxlength="7" placeholder="#ff4757" oninput="syncGradientColor('gradientStart','gradientStartText')" style="width:110px;" title="输入16进制颜色代码">
                    </div>
                </div>
                <div class="form-group">
                    <label>渐变结束颜色</label>
                    <div style="display:flex;align-items:center;gap:8px;">
                        <input type="color" id="gradientEnd" value="#a55eea" onchange="updateTitlePreview()" style="width:40px;height:36px;border:1px solid var(--border);border-radius:6px;cursor:pointer;">
                        <input type="text" id="gradientEndText" value="#a55eea" maxlength="7" placeholder="#a55eea" oninput="syncGradientColor('gradientEnd','gradientEndText')" style="width:110px;" title="输入16进制颜色代码">
                    </div>
                </div>
            </div>
        </div>
        <div class="modal-footer">
            <button class="btn btn-danger btn-sm" onclick="removeTitle()" style="float:left;">移除头衔</button>
            <button class="btn btn-outline" onclick="closeModal('titleModal')">取消</button>
            <button class="btn btn-primary" onclick="confirmSetTitle()">保存头衔</button>
        </div>
    </div>
</div>

<div class="modal-overlay" id="confirmModal">
    <div class="modal">
        <div class="modal-header">
            <h3 id="confirmTitle">确认操作</h3>
            <button class="modal-close" onclick="closeModal('confirmModal')" aria-label="关闭">×</button>
        </div>
        <div class="modal-body">
            <p id="confirmMessage"></p>
        </div>
        <div class="modal-footer">
            <button class="btn btn-outline" onclick="closeModal('confirmModal')">取消</button>
            <button class="btn btn-danger" id="confirmBtn" onclick="">确认</button>
        </div>
    </div>
</div>

<div class="modal-overlay" id="resetPasswordModal">
    <div class="modal">
        <div class="modal-header">
            <h3>重置密码</h3>
            <button class="modal-close" onclick="closeModal('resetPasswordModal')" aria-label="关闭">×</button>
        </div>
        <div class="modal-body">
            <input type="hidden" id="resetPasswordUserId">
            <div class="form-group">
                <label>新密码（至少6位）</label>
                <input type="text" id="newPassword" placeholder="请输入新密码" minlength="6">
                <div class="error-hint" id="passwordError">密码至少6位</div>
            </div>
        </div>
        <div class="modal-footer">
            <button class="btn btn-outline" onclick="closeModal('resetPasswordModal')">取消</button>
            <button class="btn btn-primary" onclick="confirmResetPassword()">确认重置</button>
        </div>
    </div>
</div>

<div class="modal-overlay" id="userDetailModal">
    <div class="modal">
        <div class="modal-header">
            <h3>查看用户信息</h3>
            <button class="modal-close" onclick="closeModal('userDetailModal')" aria-label="关闭">×</button>
        </div>
        <div class="modal-body" id="userDetailBody">
            <div style="text-align:center;padding:20px;">加载中...</div>
        </div>
        <div class="modal-footer">
            <button class="btn btn-outline" onclick="closeModal('userDetailModal')">关闭</button>
        </div>
    </div>
</div>

<div class="modal-overlay" id="changeUsernameModal">
    <div class="modal">
        <div class="modal-header">
            <h3>更改用户名</h3>
            <button class="modal-close" onclick="closeModal('changeUsernameModal')" aria-label="关闭">×</button>
        </div>
        <div class="modal-body">
            <input type="hidden" id="changeUsernameUserId">
            <div class="form-group">
                <label>新用户名（2-20字）</label>
                <input type="text" id="changeUsernameText" placeholder="请输入新用户名" maxlength="20">
                <div class="error-hint" id="changeUsernameError" style="display:none;"></div>
            </div>
        </div>
        <div class="modal-footer">
            <button class="btn btn-outline" onclick="closeModal('changeUsernameModal')">取消</button>
            <button class="btn btn-primary" onclick="confirmChangeUsername()">保存</button>
        </div>
    </div>
</div>

<script>
var currentPage = 1;
var searchTimer = null;

function debounceSearch() {
    clearTimeout(searchTimer);
    searchTimer = setTimeout(function() { currentPage = 1; loadUsers(); }, 400);
}

function toggleSelectAll() {
    var checked = document.getElementById('selectAll').checked;
    document.querySelectorAll('.user-checkbox').forEach(function(cb) { cb.checked = checked; });
}

function getSelectedUsers() {
    var ids = [];
    document.querySelectorAll('.user-checkbox:checked').forEach(function(cb) { ids.push(cb.value); });
    return ids;
}

function loadUsers() {
    var search = document.getElementById('searchUser').value.trim();
    var tbody = document.getElementById('usersTableBody');

    fetch(SITE_URL + '/api/admin/users.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: new URLSearchParams({
            csrf_token: CSRF_TOKEN,
            action: 'list',
            page: currentPage,
            search: search
        })
    })
    .then(function(r) { return r.json(); })
    .then(function(data) {
        if (data.success) {
            var users = data.data.users;
            if (users.length === 0) {
                tbody.innerHTML = '<tr><td colspan="10"><div class="empty-state"><div class="empty-icon"><svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="22 12 16 12 14 15 10 15 8 12 2 12"/><path d="M5.45 5.11L2 12v6a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2v-6l-3.45-6.89A2 2 0 0 0 16.76 4H7.24a2 2 0 0 0-1.79 1.11z"/></svg></div><p>暂无数据</p></div></td></tr>';
            } else {
                tbody.innerHTML = users.map(function(u) {
                    var roleBadge = '';
                    if (u.role === 'super_admin') roleBadge = '<span class="badge badge-super">超级管理员</span>';
                    else if (u.role === 'admin') roleBadge = '<span class="badge badge-admin">管理员</span>';
                    else roleBadge = '<span class="badge badge-user">用户</span>';

                    var statusBadge = u.is_banned
                        ? '<span class="badge badge-danger">已封禁</span>'
                        : '<span class="badge badge-success">正常</span>';

                    var twofaBadge = u.twofa_enabled
                        ? '<span class="badge badge-info">已启用</span>'
                        : '<span class="badge badge-user">未启用</span>';

                    var actions = [];
                    <?php if ($hasViewDetail): ?>
                    actions.push('<button class="btn btn-outline btn-sm" onclick="openUserDetail(' + u.id + ')">查看详情</button>');
                    <?php endif; ?>
                    <?php if ($hasChangeUsername): ?>
                    actions.push('<button class="btn btn-outline btn-sm" onclick="openChangeUsername(' + u.id + ')">改用户名</button>');
                    <?php endif; ?>
                    actions.push('<button class="btn btn-outline btn-sm" onclick="openTitleModal(' + u.id + ', ' + esc(JSON.stringify(u.title_text || '')) + ', ' + esc(JSON.stringify(u.title_color || '')) + ', ' + esc(JSON.stringify(u.title_bg_color || '')) + ', ' + (u.title_rainbow || 0) + ', ' + esc(JSON.stringify(u.title_gradient_start || '')) + ', ' + esc(JSON.stringify(u.title_gradient_end || '')) + ')">设置头衔</button>');
                    <?php if ($hasBanUser): ?>
                    if (!u.is_banned && u.role !== 'super_admin') {
                        actions.push('<button class="btn btn-warning btn-sm" onclick="openBanModal(' + u.id + ')">封禁</button>');
                    }
                    <?php endif; ?>
                    <?php if ($hasUnbanUser): ?>
                    if (u.is_banned) {
                        actions.push('<button class="btn btn-success btn-sm" onclick="confirmAction(\'unban\', ' + u.id + ')">解封</button>');
                    }
                    <?php endif; ?>
                    <?php if ($hasEditBanReason): ?>
                    if (u.is_banned) {
                        actions.push('<button class="btn btn-outline btn-sm" onclick="openEditBanReason(' + u.id + ', \'' + esc(u.ban_reason || '').replace(/'/g, "\\'") + '\')">编辑原因</button>');
                    }
                    <?php endif; ?>
                    <?php if ($hasReset2fa): ?>
                    if (u.twofa_enabled) {
                        actions.push('<button class="btn btn-outline btn-sm" onclick="confirmAction(\'reset_2fa\', ' + u.id + ')">重置2FA</button>');
                    }
                    <?php endif; ?>
                    <?php if ($hasResetPassword): ?>
                    if (u.role !== 'super_admin') {
                        actions.push('<button class="btn btn-outline btn-sm" onclick="openResetPassword(' + u.id + ')">重置密码</button>');
                    }
                    <?php endif; ?>
                    <?php if ($hasDeleteUser): ?>
                    if (u.role !== 'super_admin' && u.role !== 'admin') {
                        actions.push('<button class="btn btn-danger btn-sm" onclick="confirmDeleteUser(' + u.id + ', ' + esc(JSON.stringify(u.nickname || u.qq)) + ')">删除</button>');
                    }
                    <?php endif; ?>

                    var titleBadge = '-';
                    if (u.title_text) {
                        var style = 'display:inline-block;padding:2px 8px;border-radius:4px;font-size:12px;font-weight:600;';
                        if (u.title_rainbow == 1) {
                            var gs = u.title_gradient_start || '#ff4757';
                            var ge = u.title_gradient_end || '#a55eea';
                            style += 'background:linear-gradient(90deg,' + gs + ',' + ge + ',' + gs + ');background-size:200% 100%;animation:titleRainbow 2s linear infinite;color:#fff;';
                        } else {
                            style += 'background:' + esc(u.title_bg_color || '#4A90D9') + ';color:' + esc(u.title_color || '#ffffff') + ';';
                        }
                        titleBadge = '<span style="' + style + '">' + esc(u.title_text) + '</span>';
                    }

                    return '<tr>' +
                        '<td><input type="checkbox" class="user-checkbox" value="' + u.id + '" ' + (u.role === 'super_admin' ? 'disabled' : '') + '></td>' +
                        '<td><div style="display:flex;align-items:center;gap:8px;"><img src="' + esc(u.avatar) + '" style="width:32px;height:32px;border-radius:50%;" onerror="this.style.display=\'none\'"><span>' + esc(u.nickname || '未设置') + '</span></div></td>' +
                        '<td>' + esc(u.qq) + '</td>' +
                        '<td>' + roleBadge + '</td>' +
                        '<td>' + titleBadge + '</td>' +
                        '<td>' + twofaBadge + '</td>' +
                        '<td>' + statusBadge + '</td>' +
                        '<td title="最近访问: ' + (u.last_visit ? esc(u.last_visit) : '-') + '"><strong>' + (u.visit_count || 0) + '</strong> 次</td>' +
                        '<td>' + (u.created_at ? u.created_at.substring(0, 10) : '-') + '</td>' +
                        '<td><div style="display:flex;gap:4px;flex-wrap:wrap;">' + actions.join('') + '</div></td>' +
                        '</tr>';
                }).join('');
            }
            renderPagination(data.data.total, data.data.page, data.data.total_pages);
        } else {
            tbody.innerHTML = '<tr><td colspan="10"><div class="empty-state"><div class="empty-icon"><svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg></div><p>' + (data.message || '加载失败') + '</p></div></td></tr>';
        }
    })
    .catch(function(e) {
        showToast('加载失败', 'error');
        tbody.innerHTML = '<tr><td colspan="10"><div class="empty-state"><div class="empty-icon"><svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg></div><p>加载失败，请刷新重试</p></div></td></tr>';
    });
}

function renderPagination(total, page, totalPages) {
    var pag = document.getElementById('usersPagination');
    if (totalPages <= 1) { pag.innerHTML = ''; return; }
    var html = '<button ' + (page <= 1 ? 'disabled' : '') + ' onclick="goPage(' + (page-1) + ')">上一页</button>';
    for (var i = 1; i <= totalPages; i++) {
        html += '<button class="' + (i === page ? 'active' : '') + '" onclick="goPage(' + i + ')">' + i + '</button>';
    }
    html += '<button ' + (page >= totalPages ? 'disabled' : '') + ' onclick="goPage(' + (page+1) + ')">下一页</button>';
    html += '<span class="page-info">共 ' + total + ' 条</span>';
    pag.innerHTML = html;
}

function goPage(p) { currentPage = p; loadUsers(); }

function openBanModal(userId) {
    document.getElementById('banUserId').value = userId;
    document.getElementById('banReason').value = '';
    openModal('banModal');
}

function confirmBan() {
    var userId = document.getElementById('banUserId').value;
    var duration = document.getElementById('banDuration').value;
    var reason = document.getElementById('banReason').value;

    fetch(SITE_URL + '/api/admin/users.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: new URLSearchParams({
            csrf_token: CSRF_TOKEN,
            action: 'ban',
            user_id: userId,
            duration: duration,
            reason: reason
        })
    })
    .then(function(r) { return r.json(); })
    .then(function(data) {
        if (data.success) {
            showToast('封禁成功');
            closeModal('banModal');
            loadUsers();
        } else {
            showToast(data.message || '操作失败', 'error');
        }
    })
    .catch(function() { showToast('网络错误', 'error'); });
}

function openEditBanReason(userId, reason) {
    document.getElementById('editBanReasonUserId').value = userId;
    document.getElementById('editBanReasonText').value = reason;
    openModal('editBanReasonModal');
}

function confirmEditBanReason() {
    var userId = document.getElementById('editBanReasonUserId').value;
    var reason = document.getElementById('editBanReasonText').value;

    fetch(SITE_URL + '/api/admin/users.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: new URLSearchParams({
            csrf_token: CSRF_TOKEN,
            action: 'edit_ban_reason',
            user_id: userId,
            reason: reason
        })
    })
    .then(function(r) { return r.json(); })
    .then(function(data) {
        if (data.success) { showToast('已更新'); closeModal('editBanReasonModal'); loadUsers(); }
        else { showToast(data.message || '操作失败', 'error'); }
    })
    .catch(function() { showToast('网络错误', 'error'); });
}

function openResetPassword(userId) {
    document.getElementById('resetPasswordUserId').value = userId;
    document.getElementById('newPassword').value = '';
    document.getElementById('passwordError').classList.remove('show');
    openModal('resetPasswordModal');
}

function confirmResetPassword() {
    var userId = document.getElementById('resetPasswordUserId').value;
    var password = document.getElementById('newPassword').value;

    if (password.length < 6) {
        document.getElementById('passwordError').classList.add('show');
        return;
    }

    fetch(SITE_URL + '/api/admin/users.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: new URLSearchParams({
            csrf_token: CSRF_TOKEN,
            action: 'reset_password',
            user_id: userId,
            new_password: password
        })
    })
    .then(function(r) { return r.json(); })
    .then(function(data) {
        if (data.success) { showToast('密码已重置'); closeModal('resetPasswordModal'); }
        else { showToast(data.message || '操作失败', 'error'); }
    })
    .catch(function() { showToast('网络错误', 'error'); });
}

var pendingConfirmAction = null;

function confirmAction(action, userId) {
    pendingConfirmAction = { action: action, userId: userId };
    var messages = {
        'unban': '确定要解封该用户吗？',
        'reset_2fa': '确定要重置该用户的2FA吗？这将禁用其双重验证。',
    };
    var titles = {
        'unban': '确认解封',
        'reset_2fa': '确认重置2FA',
    };
    document.getElementById('confirmTitle').textContent = titles[action] || '确认操作';
    document.getElementById('confirmMessage').textContent = messages[action] || '确定要执行此操作吗？';
    document.getElementById('confirmBtn').onclick = executePendingAction;
    openModal('confirmModal');
}

function confirmDeleteUser(userId, name) {
    if (!confirm('确定要删除用户「' + name + '」吗？\n该用户及其全部内容（帖子、评论、通知等）将被永久删除，此操作不可恢复！')) return;

    fetch(SITE_URL + '/api/admin/users.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: new URLSearchParams({
            csrf_token: CSRF_TOKEN,
            action: 'delete_user',
            user_id: userId
        })
    })
    .then(function(r) { return r.json(); })
    .then(function(data) {
        if (data.success) { showToast('用户已删除'); loadUsers(); }
        else { showToast(data.message || '操作失败', 'error'); }
    })
    .catch(function() { showToast('网络错误', 'error'); });
}

function executePendingAction() {
    if (!pendingConfirmAction) return;
    var a = pendingConfirmAction;

    fetch(SITE_URL + '/api/admin/users.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: new URLSearchParams({
            csrf_token: CSRF_TOKEN,
            action: a.action,
            user_id: a.userId
        })
    })
    .then(function(r) { return r.json(); })
    .then(function(data) {
        if (data.success) { showToast(data.message); closeModal('confirmModal'); loadUsers(); }
        else { showToast(data.message || '操作失败', 'error'); }
    })
    .catch(function() { showToast('网络错误', 'error'); });
    pendingConfirmAction = null;
}

function batchAction(action) {
    var ids = getSelectedUsers();
    if (ids.length === 0) { showToast('请先选择用户', 'error'); return; }

    var msg = action === 'ban' ? '确定要批量封禁选中的 ' + ids.length + ' 个用户吗？' : '确定要批量解封选中的 ' + ids.length + ' 个用户吗？';
    document.getElementById('confirmTitle').textContent = '确认批量操作';
    document.getElementById('confirmMessage').textContent = msg;
    document.getElementById('confirmBtn').onclick = function() {
        fetch(SITE_URL + '/api/admin/users.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: new URLSearchParams({
                csrf_token: CSRF_TOKEN,
                action: 'batch',
                batch_action: action,
                user_ids: JSON.stringify(ids)
            })
        })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (data.success) { showToast('批量操作完成'); closeModal('confirmModal'); loadUsers(); }
            else { showToast(data.message || '操作失败', 'error'); }
        })
        .catch(function() { showToast('网络错误', 'error'); });
    };
    openModal('confirmModal');
}

loadUsers();

function openTitleModal(userId, titleText, titleColor, titleBgColor, titleRainbow, gradientStart, gradientEnd) {
    document.getElementById('titleUserId').value = userId;
    document.getElementById('titleText').value = titleText || '';
    document.getElementById('titleColor').value = titleColor || '#ffffff';
    document.getElementById('titleColorText').value = titleColor || '#ffffff';
    document.getElementById('titleBgColor').value = titleBgColor || '#4A90D9';
    document.getElementById('titleBgColorText').value = titleBgColor || '#4A90D9';
    document.getElementById('titleRainbow').checked = titleRainbow == 1;
    document.getElementById('gradientStart').value = gradientStart || '#ff4757';
    document.getElementById('gradientStartText').value = gradientStart || '#ff4757';
    document.getElementById('gradientEnd').value = gradientEnd || '#a55eea';
    document.getElementById('gradientEndText').value = gradientEnd || '#a55eea';
    updateTitlePreview();
    openModal('titleModal');
}

function syncColor(pickerId, textId) {
    var val = document.getElementById(textId).value.trim();
    if (val && val.charAt(0) !== '#') val = '#' + val;
    if (/^#[0-9a-fA-F]{6}$/.test(val)) {
        document.getElementById(pickerId).value = val.toLowerCase();
        document.getElementById(textId).value = val.toLowerCase();
    }
    updateTitlePreview();
}

function syncGradientColor(pickerId, textId) {
    syncColor(pickerId, textId);
}

function updateTitlePreview() {
    var text = document.getElementById('titleText').value || '头衔预览';
    var color = document.getElementById('titleColor').value;
    var bgColor = document.getElementById('titleBgColor').value;
    var rainbow = document.getElementById('titleRainbow').checked;
    document.getElementById('gradientRow').style.display = rainbow ? 'block' : 'none';

    var style = 'display:inline-block;padding:2px 12px;border-radius:6px;font-size:13px;font-weight:600;box-shadow:0 2px 6px rgba(0,0,0,0.18);';
    if (rainbow) {
        var gs = document.getElementById('gradientStart').value;
        var ge = document.getElementById('gradientEnd').value;
        // 规范化：无前导 # 自动补；非法值降级为默认色
        if (!/^#[0-9a-fA-F]{6}$/.test(gs)) gs = '#ff4757';
        if (!/^#[0-9a-fA-F]{6}$/.test(ge)) ge = '#a55eea';
        style += 'background:linear-gradient(90deg,' + gs + ',' + ge + ',' + gs + ');background-size:200% 100%;animation:titleRainbow 2s linear infinite;color:' + color + ';';
    } else {
        style += 'background:' + bgColor + ';color:' + color + ';';
    }

    document.getElementById('titlePreview').innerHTML = '<span style="' + style + '">' + text + '</span>';
}

function confirmSetTitle() {
    var userId = document.getElementById('titleUserId').value;
    var titleText = document.getElementById('titleText').value.trim();
    var titleColor = document.getElementById('titleColor').value;
    var titleBgColor = document.getElementById('titleBgColor').value;
    var titleRainbow = document.getElementById('titleRainbow').checked ? 1 : 0;
    var gradientStart = titleRainbow ? document.getElementById('gradientStart').value : '';
    var gradientEnd = titleRainbow ? document.getElementById('gradientEnd').value : '';

    fetch(SITE_URL + '/api/admin/user_title.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: new URLSearchParams({
            csrf_token: CSRF_TOKEN,
            action: 'set_title',
            user_id: userId,
            title_text: titleText,
            title_color: titleColor,
            title_bg_color: titleBgColor,
            title_rainbow: titleRainbow,
            gradient_start: gradientStart,
            gradient_end: gradientEnd
        })
    })
    .then(function(r) { return r.json(); })
    .then(function(data) {
        if (data.success) { showToast('头衔设置成功'); closeModal('titleModal'); loadUsers(); }
        else { showToast(data.message || '操作失败', 'error'); }
    })
    .catch(function() { showToast('网络错误', 'error'); });
}

function removeTitle() {
    var userId = document.getElementById('titleUserId').value;
    fetch(SITE_URL + '/api/admin/user_title.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: new URLSearchParams({
            csrf_token: CSRF_TOKEN,
            action: 'remove_title',
            user_id: userId
        })
    })
    .then(function(r) { return r.json(); })
    .then(function(data) {
        if (data.success) { showToast('头衔已移除'); closeModal('titleModal'); loadUsers(); }
        else { showToast(data.message || '操作失败', 'error'); }
    })
    .catch(function() { showToast('网络错误', 'error'); });
}

function openUserDetail(userId) {
    var body = document.getElementById('userDetailBody');
    body.innerHTML = '<div style="text-align:center;padding:20px;">加载中...</div>';
    openModal('userDetailModal');

    fetch(SITE_URL + '/api/admin/users.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: new URLSearchParams({
            csrf_token: CSRF_TOKEN,
            action: 'view_detail',
            user_id: userId
        })
    })
    .then(function(r) { return r.json(); })
    .then(function(data) {
        if (!data.success) {
            body.innerHTML = '<p style="color:var(--danger);text-align:center;padding:20px;">' + esc(data.message || '加载失败') + '</p>';
            return;
        }
        var u = data.data;
        var rows = [
            ['QQ号', u.qq],
            ['用户名', u.nickname],
            ['真实姓名', u.real_name || '未填写'],
            ['班级', u.class_num ? u.class_num + '班' : '未填写'],
            ['年级', u.entrance_year ? '入学年份 ' + u.entrance_year : '未填写'],
            ['角色', u.role === 'super_admin' ? '超级管理员' : (u.role === 'admin' ? '管理员' : '用户')],
            ['注册时间', u.created_at],
            ['最近访问', u.last_visit || '-'],
            ['访问次数', u.visit_count + ' 次']
        ];
        body.innerHTML =
            '<div style="display:flex;align-items:center;gap:12px;padding:12px;background:var(--bg);border-radius:8px;margin-bottom:12px;">' +
            '<img src="' + esc(u.avatar || '') + '" style="width:44px;height:44px;border-radius:50%;" onerror="this.style.display=\'none\'">' +
            '<div><div style="font-weight:600;font-size:15px;">' + esc(u.nickname) + '</div>' +
            '<div style="font-size:12px;color:var(--text-secondary);">ID: ' + u.id + '</div></div></div>' +
            rows.map(function(r) {
                return '<div style="display:flex;justify-content:space-between;padding:8px 12px;border-bottom:1px solid var(--border);font-size:14px;">' +
                    '<span style="color:var(--text-secondary);">' + r[0] + '</span><span style="font-weight:500;">' + esc(String(r[1])) + '</span></div>';
            }).join('');
    })
    .catch(function() {
        body.innerHTML = '<p style="color:var(--danger);text-align:center;padding:20px;">网络错误</p>';
    });
}

function openChangeUsername(userId) {
    document.getElementById('changeUsernameUserId').value = userId;
    document.getElementById('changeUsernameText').value = '';
    document.getElementById('changeUsernameError').style.display = 'none';
    openModal('changeUsernameModal');
}

function confirmChangeUsername() {
    var userId = document.getElementById('changeUsernameUserId').value;
    var newUsername = document.getElementById('changeUsernameText').value.trim();
    var errEl = document.getElementById('changeUsernameError');

    if (newUsername.length < 2) {
        errEl.textContent = '用户名至少2个字符';
        errEl.style.display = 'block';
        return;
    }

    fetch(SITE_URL + '/api/admin/users.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: new URLSearchParams({
            csrf_token: CSRF_TOKEN,
            action: 'change_username',
            user_id: userId,
            new_username: newUsername
        })
    })
    .then(function(r) { return r.json(); })
    .then(function(data) {
        if (data.success) {
            showToast('用户名已更新');
            closeModal('changeUsernameModal');
            loadUsers();
        } else {
            errEl.textContent = data.message || '操作失败';
            errEl.style.display = 'block';
        }
    })
    .catch(function() { showToast('网络错误', 'error'); });
}
</script>

<?php adminFooter(); ?>
