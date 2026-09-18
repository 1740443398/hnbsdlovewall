<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/layout.php';

$adminUser = requireAdmin();
$adminUser = checkBanned($adminUser);
$csrfToken = generateCSRFToken();

if (!checkPermission($adminUser, 'edit_announcement')) {
    http_response_code(403);
    die('403 Forbidden - 权限不足');
}

adminHeader('公告管理', $adminUser, $csrfToken);
?>

<div class="section">
    <div class="section-header">
        <h3>公告列表</h3>
        <button class="btn btn-primary btn-sm" onclick="openCreateModal()">新建公告</button>
    </div>
    <div class="section-body">
        <div class="table-wrapper">
            <table>
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>标题</th>
                        <th>状态</th>
                        <th>创建者</th>
                        <th>创建时间</th>
                        <th>操作</th>
                    </tr>
                </thead>
                <tbody id="announcementsTable">
                    <tr><td colspan="6" class="loading-spinner">加载中</td></tr>
                </tbody>
            </table>
        </div>
    </div>
</div>

<div class="modal-overlay" id="editModal">
    <div class="modal" style="max-width:700px;">
        <div class="modal-header">
            <h3 id="editModalTitle">新建公告</h3>
            <button class="modal-close" onclick="closeModal('editModal')" aria-label="关闭">×</button>
        </div>
        <div class="modal-body">
            <input type="hidden" id="editAnnId">
            <div class="form-group">
                <label>标题</label>
                <input type="text" id="editAnnTitle" placeholder="公告标题" maxlength="200">
                <div class="error-hint" id="titleError">标题不能为空</div>
            </div>
            <div class="form-group">
                <label>内容（支持HTML）</label>
                <textarea id="editAnnContent" rows="8" placeholder="公告内容..."></textarea>
                <div class="error-hint" id="contentError">内容不能为空</div>
            </div>
            <div class="form-group">
                <label style="display:flex;align-items:center;gap:12px;">
                    <label class="toggle-switch"><input type="checkbox" id="editAnnActive" checked><span class="toggle-slider"></span></label>
                    <span>启用公告</span>
                </label>
            </div>
        </div>
        <div class="modal-footer">
            <button class="btn btn-outline" onclick="closeModal('editModal')">取消</button>
            <button class="btn btn-primary" id="editModalSaveBtn" onclick="saveAnnouncement()">保存</button>
        </div>
    </div>
</div>

<div class="modal-overlay" id="previewModal">
    <div class="modal" style="max-width:700px;">
        <div class="modal-header">
            <h3 id="previewTitle">预览</h3>
            <button class="modal-close" onclick="closeModal('previewModal')" aria-label="关闭">×</button>
        </div>
        <div class="modal-body" id="previewContent"></div>
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
var announcementList = [];

function loadAnnouncements() {
    fetch(SITE_URL + '/api/admin/announcements.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: new URLSearchParams({ csrf_token: CSRF_TOKEN, action: 'list' })
    })
    .then(function(r) { return r.json(); })
    .then(function(data) {
        if (data.success) {
            announcementList = data.data;
            var tbody = document.getElementById('announcementsTable');
            if (announcementList.length === 0) {
                tbody.innerHTML = '<tr><td colspan="6"><div class="empty-state"><div class="empty-icon"><svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="22 12 16 12 14 15 10 15 8 12 2 12"/><path d="M5.45 5.11L2 12v6a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2v-6l-3.45-6.89A2 2 0 0 0 16.76 4H7.24a2 2 0 0 0-1.79 1.11z"/></svg></div><p>暂无公告</p></div></td></tr>';
            } else {
                tbody.innerHTML = announcementList.map(function(a) {
                    var statusBadge = a.is_active
                        ? '<span class="badge badge-success">启用</span>'
                        : '<span class="badge badge-warning">已禁用</span>';
                    return '<tr>' +
                        '<td>' + esc(a.id) + '</td>' +
                        '<td><strong>' + esc(a.title) + '</strong></td>' +
                        '<td>' + statusBadge + '</td>' +
                        '<td>' + esc(a.nickname || a.qq) + '</td>' +
                        '<td>' + (a.created_at ? a.created_at.substring(0,16) : '-') + '</td>' +
                        '<td><div style="display:flex;gap:4px;">' +
                            '<button class="btn btn-outline btn-sm" onclick="openEditModal(' + a.id + ')">编辑</button>' +
                            '<button class="btn btn-outline btn-sm" onclick="previewAnnouncement(' + a.id + ')">预览</button>' +
                            '<button class="btn btn-danger btn-sm" onclick="confirmDelete(' + a.id + ')">删除</button>' +
                        '</div></td>' +
                        '</tr>';
                }).join('');
            }
        }
    })
    .catch(function(e) {
        showToast('加载失败', 'error');
        var tbody = document.getElementById('announcementsTable');
        tbody.innerHTML = '<tr><td colspan="6"><div class="empty-state"><div class="empty-icon"><svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg></div><p>加载失败，请刷新重试</p></div></td></tr>';
    });
}

function openCreateModal() {
    document.getElementById('editAnnId').value = '';
    document.getElementById('editModalTitle').textContent = '新建公告';
    document.getElementById('editAnnTitle').value = '';
    document.getElementById('editAnnContent').value = '';
    document.getElementById('editAnnActive').checked = true;
    document.getElementById('editModalSaveBtn').textContent = '创建';
    document.getElementById('titleError').classList.remove('show');
    document.getElementById('contentError').classList.remove('show');
    openModal('editModal');
}

function openEditModal(id) {
    var ann = null;
    for (var i = 0; i < announcementList.length; i++) {
        if (announcementList[i].id === id) { ann = announcementList[i]; break; }
    }
    if (!ann) return;
    document.getElementById('editAnnId').value = ann.id;
    document.getElementById('editModalTitle').textContent = '编辑公告';
    document.getElementById('editAnnTitle').value = ann.title;
    document.getElementById('editAnnContent').value = ann.content;
    document.getElementById('editAnnActive').checked = ann.is_active;
    document.getElementById('editModalSaveBtn').textContent = '更新';
    document.getElementById('titleError').classList.remove('show');
    document.getElementById('contentError').classList.remove('show');
    openModal('editModal');
}

function saveAnnouncement() {
    var id = document.getElementById('editAnnId').value;
    var title = document.getElementById('editAnnTitle').value.trim();
    var content = document.getElementById('editAnnContent').value.trim();
    var isActive = document.getElementById('editAnnActive').checked ? 1 : 0;

    var valid = true;
    if (!title) { document.getElementById('titleError').classList.add('show'); valid = false; }
    else { document.getElementById('titleError').classList.remove('show'); }
    if (!content) { document.getElementById('contentError').classList.add('show'); valid = false; }
    else { document.getElementById('contentError').classList.remove('show'); }
    if (!valid) return;

    var action = id ? 'update' : 'create';
    var params = { csrf_token: CSRF_TOKEN, action: action, title: title, content: content, is_active: isActive };
    if (id) params.id = id;

    fetch(SITE_URL + '/api/admin/announcements.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: new URLSearchParams(params)
    })
    .then(function(r) { return r.json(); })
    .then(function(data) {
        if (data.success) { showToast(data.message); closeModal('editModal'); loadAnnouncements(); }
        else { showToast(data.message, 'error'); }
    })
    .catch(function() { showToast('网络错误', 'error'); });
}

function previewAnnouncement(id) {
    var ann = null;
    for (var i = 0; i < announcementList.length; i++) {
        if (announcementList[i].id === id) { ann = announcementList[i]; break; }
    }
    if (!ann) return;
    document.getElementById('previewTitle').textContent = ann.title;
    document.getElementById('previewContent').textContent = ann.title + '\n\n' + ann.content;
    openModal('previewModal');
}

function confirmDelete(id) {
    document.getElementById('confirmMessage').textContent = '确定要删除该公告吗？';
    document.getElementById('confirmBtn').onclick = function() {
        fetch(SITE_URL + '/api/admin/announcements.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: new URLSearchParams({ csrf_token: CSRF_TOKEN, action: 'delete', id: id })
        })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (data.success) { showToast('公告已删除'); closeModal('confirmModal'); loadAnnouncements(); }
            else { showToast(data.message, 'error'); }
        })
        .catch(function() { showToast('网络错误', 'error'); });
    };
    openModal('confirmModal');
}

loadAnnouncements();
</script>

<?php adminFooter(); ?>
