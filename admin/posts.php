<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/layout.php';

$adminUser = requireAdmin();
$adminUser = checkBanned($adminUser);
$csrfToken = generateCSRFToken();

if (!checkPermission($adminUser, 'view_posts')) {
    http_response_code(403);
    die('403 Forbidden - 权限不足');
}

$hasAuditPosts = checkPermission($adminUser, 'audit_posts');
$hasDeletePosts = checkPermission($adminUser, 'delete_posts');
$hasViewAnonymousAuthor = checkPermission($adminUser, 'view_anonymous_author');

adminHeader('帖子管理', $adminUser, $csrfToken);
?>

<div class="section">
    <div class="section-header">
        <h3>帖子列表</h3>
        <div>
            <?php if ($hasAuditPosts): ?>
            <button class="btn btn-success btn-sm" onclick="batchAction('approve')">批量审核通过</button>
            <?php endif; ?>
            <?php if ($hasDeletePosts): ?>
            <button class="btn btn-danger btn-sm" onclick="batchAction('delete')">批量删除</button>
            <?php endif; ?>
        </div>
    </div>
    <div class="section-body">
        <div class="filter-row">
            <div class="form-group">
                <select id="filterStatus" onchange="currentPage=1;loadPosts()">
                    <option value="">全部状态</option>
                    <option value="pending">待审核</option>
                    <option value="published">已发布</option>
                    <option value="rejected">已拒绝</option>
                </select>
            </div>
            <div class="form-group">
                <select id="filterCategory" onchange="currentPage=1;loadPosts()">
                    <option value="">全部分类</option>
                    <option value="announcement">全站公告</option>
                    <option value="lost_found">寻物/失物招领</option>
                    <option value="study_help">学习求助</option>
                    <option value="social_chat">交友闲聊</option>
                    <option value="confession">表白</option>
                    <option value="school_info">校园打听</option>
                    <option value="other">其他</option>
                </select>
            </div>
            <div class="form-group">
                <input type="text" id="filterKeyword" placeholder="搜索关键词..." onkeyup="debounceSearch()">
            </div>
            <button class="btn btn-primary btn-sm" onclick="currentPage=1;loadPosts()">搜索</button>
        </div>

        <div class="table-wrapper">
            <table>
                <thead>
                    <tr>
                        <th><input type="checkbox" id="selectAll" onchange="toggleSelectAll()"></th>
                        <th>ID</th>
                        <th>标题/内容</th>
                        <th>发布者</th>
                        <th>分类</th>
                        <th>状态</th>
                        <th>点赞/评论</th>
                        <th>时间</th>
                        <th>操作</th>
                    </tr>
                </thead>
                <tbody id="postsTableBody">
                    <tr><td colspan="9" class="loading-spinner">加载中</td></tr>
                </tbody>
            </table>
        </div>
        <div class="pagination" id="postsPagination"></div>
    </div>
</div>

<div class="modal-overlay" id="detailModal">
    <div class="modal" style="max-width:700px;">
        <div class="modal-header">
            <h3>帖子详情</h3>
            <button class="modal-close" onclick="closeModal('detailModal')" aria-label="关闭">×</button>
        </div>
        <div class="modal-body" id="detailContent"></div>
    </div>
</div>

<div class="modal-overlay" id="confirmModal">
    <div class="modal">
        <div class="modal-header">
            <h3 id="confirmTitle">确认操作</h3>
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
var pendingAction = null;

function debounceSearch() {
    clearTimeout(searchTimer);
    searchTimer = setTimeout(function() { currentPage = 1; loadPosts(); }, 400);
}

function toggleSelectAll() {
    var checked = document.getElementById('selectAll').checked;
    document.querySelectorAll('.post-checkbox').forEach(function(cb) { cb.checked = checked; });
}

function getSelectedPosts() {
    var ids = [];
    document.querySelectorAll('.post-checkbox:checked').forEach(function(cb) { ids.push(cb.value); });
    return ids;
}

var catNames = {
    'announcement': '全站公告',
    'lost_found': '寻物/失物招领',
    'study_help': '学习求助',
    'social_chat': '交友闲聊',
    'confession': '表白',
    'school_info': '校园打听',
    'other': '其他'
};

function loadPosts() {
    var status = document.getElementById('filterStatus').value;
    var category = document.getElementById('filterCategory').value;
    var keyword = document.getElementById('filterKeyword').value.trim();
    var tbody = document.getElementById('postsTableBody');

    fetch(SITE_URL + '/api/admin/posts.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: new URLSearchParams({
            csrf_token: CSRF_TOKEN,
            action: 'list',
            page: currentPage,
            status: status,
            category: category,
            keyword: keyword
        })
    })
    .then(function(r) { return r.json(); })
    .then(function(data) {
        if (data.success) {
            var posts = data.data.posts;
            if (posts.length === 0) {
                tbody.innerHTML = '<tr><td colspan="9"><div class="empty-state"><div class="empty-icon"><svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="22 12 16 12 14 15 10 15 8 12 2 12"/><path d="M5.45 5.11L2 12v6a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2v-6l-3.45-6.89A2 2 0 0 0 16.76 4H7.24a2 2 0 0 0-1.79 1.11z"/></svg></div><p>暂无数据</p></div></td></tr>';
            } else {
                tbody.innerHTML = posts.map(function(p) {
                    var statusBadge = '';
                    if (p.status === 'published') statusBadge = '<span class="badge badge-success">已发布</span>';
                    else if (p.status === 'rejected') statusBadge = '<span class="badge badge-danger">已拒绝</span>';
                    else statusBadge = '<span class="badge badge-warning">待审核</span>';

                    var userDisplay = p.is_anonymous ? '<em>匿名</em>' : esc(p.nickname || p.qq);

                    var actions = [];
                    <?php if ($hasAuditPosts): ?>
                    if (p.status === 'pending') {
                        actions.push('<button class="btn btn-success btn-sm" onclick="singleAction(\'approve\',' + p.id + ')">通过</button>');
                        actions.push('<button class="btn btn-warning btn-sm" onclick="singleAction(\'reject\',' + p.id + ')">拒绝</button>');
                    }
                    <?php endif; ?>
                    <?php if ($hasDeletePosts): ?>
                    actions.push('<button class="btn btn-danger btn-sm" onclick="singleAction(\'delete\',' + p.id + ')">删除</button>');
                    <?php endif; ?>
                    <?php if ($hasViewAnonymousAuthor): ?>
                    if (p.is_anonymous || (p.visibility && p.visibility !== 'public')) {
                        actions.push('<button class="btn btn-outline btn-sm" onclick="viewPostAuthor(' + p.id + ')">查看作者</button>');
                    }
                    <?php endif; ?>
                    actions.push('<button class="btn btn-outline btn-sm" onclick="viewDetail(' + p.id + ')">详情</button>');

                    return '<tr>' +
                        '<td><input type="checkbox" class="post-checkbox" value="' + esc(p.id) + '"></td>' +
                        '<td>' + esc(p.id) + '</td>' +
                        '<td><div style="max-width:250px;"><strong>' + esc(p.title || '无标题') + '</strong><br><small style="color:#6b7280;">' + esc(p.content_short || '') + '</small></div></td>' +
                        '<td>' + userDisplay + '</td>' +
                        '<td>' + (catNames[p.category] || p.category) + '</td>' +
                        '<td>' + statusBadge + '</td>' +
                        '<td>赞 ' + p.like_count + ' 评论 ' + p.comment_count + '</td>' +
                        '<td>' + (p.created_at ? p.created_at.substring(0, 16) : '-') + '</td>' +
                        '<td><div style="display:flex;gap:4px;flex-wrap:wrap;">' + actions.join('') + '</div></td>' +
                        '</tr>';
                }).join('');
            }
            renderPagination(data.data.total, data.data.page, data.data.total_pages);
        } else {
            tbody.innerHTML = '<tr><td colspan="9"><div class="empty-state"><div class="empty-icon"><svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg></div><p>' + (data.message || '加载失败') + '</p></div></td></tr>';
        }
    })
    .catch(function(e) {
        showToast('加载失败', 'error');
        tbody.innerHTML = '<tr><td colspan="9"><div class="empty-state"><div class="empty-icon"><svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg></div><p>加载失败，请刷新重试</p></div></td></tr>';
    });
}

function renderPagination(total, page, totalPages) {
    var pag = document.getElementById('postsPagination');
    if (totalPages <= 1) { pag.innerHTML = ''; return; }
    var html = '<button ' + (page <= 1 ? 'disabled' : '') + ' onclick="goPage(' + (page-1) + ')">上一页</button>';
    for (var i = 1; i <= totalPages && i <= 10; i++) {
        html += '<button class="' + (i === page ? 'active' : '') + '" onclick="goPage(' + i + ')">' + i + '</button>';
    }
    html += '<button ' + (page >= totalPages ? 'disabled' : '') + ' onclick="goPage(' + (page+1) + ')">下一页</button>';
    html += '<span class="page-info">共 ' + total + ' 条</span>';
    pag.innerHTML = html;
}

function goPage(p) { currentPage = p; loadPosts(); }

function singleAction(action, postId) {
    var messages = {
        'approve': '确定要通过该帖子吗？',
        'reject': '确定要拒绝该帖子吗？',
        'delete': '确定要删除该帖子吗？此操作不可恢复！'
    };
    pendingAction = { action: action, ids: [postId], single: true };
    document.getElementById('confirmTitle').textContent = '确认操作';
    document.getElementById('confirmMessage').textContent = messages[action] || '确定要执行此操作吗？';
    document.getElementById('confirmBtn').onclick = executeAction;
    openModal('confirmModal');
}

function batchAction(action) {
    var ids = getSelectedPosts();
    if (ids.length === 0) { showToast('请先选择帖子', 'error'); return; }
    var messages = {
        'approve': '确定要批量通过 ' + ids.length + ' 个帖子吗？',
        'delete': '确定要批量删除 ' + ids.length + ' 个帖子吗？此操作不可恢复！'
    };
    pendingAction = { action: action, ids: ids, single: false };
    document.getElementById('confirmTitle').textContent = '确认批量操作';
    document.getElementById('confirmMessage').textContent = messages[action] || '确定要执行此操作吗？';
    document.getElementById('confirmBtn').onclick = executeAction;
    openModal('confirmModal');
}

function executeAction() {
    if (!pendingAction) return;
    var a = pendingAction;

    if (a.single) {
        var postId = a.ids[0];
        fetch(SITE_URL + '/api/admin/posts.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: new URLSearchParams({
                csrf_token: CSRF_TOKEN,
                action: a.action,
                post_id: postId
            })
        })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (data.success) { showToast(data.message); closeModal('confirmModal'); loadPosts(); }
            else { showToast(data.message || '操作失败', 'error'); }
        })
        .catch(function() { showToast('网络错误', 'error'); });
    } else {
        fetch(SITE_URL + '/api/admin/posts.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: new URLSearchParams({
                csrf_token: CSRF_TOKEN,
                action: 'batch',
                batch_action: a.action,
                post_ids: JSON.stringify(a.ids)
            })
        })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (data.success) { showToast('批量操作完成，影响 ' + data.data.affected + ' 条'); closeModal('confirmModal'); loadPosts(); }
            else { showToast(data.message || '操作失败', 'error'); }
        })
        .catch(function() { showToast('网络错误', 'error'); });
    }
    pendingAction = null;
}

function viewPostAuthor(postId) {
    fetch(SITE_URL + '/api/admin/users.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: new URLSearchParams({
            csrf_token: CSRF_TOKEN,
            action: 'view_post_author',
            post_id: postId
        })
    })
    .then(function(r) { return r.json(); })
    .then(function(data) {
        if (!data.success) {
            showToast(data.message || '操作失败', 'error');
            return;
        }
        var a = data.data;
        // 敏感信息，二次确认后再展示，避免误触泄露隐私
        if (confirm('正在查看动态「' + (a.post_title || a.post_id) + '」的发布者身份。\n\n该发布者为匿名/定向可见动态，请务必严格保密，不得向任何第三方（含其他管理员、用户）泄露其身份信息。\n\n确定要继续查看吗？')) {
            showToast('发布者：' + a.author_nickname + '（QQ：' + a.author_qq + '）', 'info');
        }
    })
    .catch(function() { showToast('网络错误', 'error'); });
}

function viewDetail(postId) {
    fetch(SITE_URL + '/api/admin/posts.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: new URLSearchParams({
            csrf_token: CSRF_TOKEN,
            action: 'detail',
            post_id: postId
        })
    })
    .then(function(r) { return r.json(); })
    .then(function(data) {
        if (data.success) {
            var p = data.data;
            function esc(str) { return String(str).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#39;'); }
            var html = '<div style="margin-bottom:12px;"><strong>标题：</strong>' + esc(p.title || '无标题') + '</div>';
            html += '<div style="margin-bottom:12px;"><strong>发布者：</strong>' + esc(p.is_anonymous ? '匿名' : (p.nickname || p.qq)) + '</div>';
            html += '<div style="margin-bottom:12px;"><strong>分类：</strong>' + esc(catNames[p.category] || p.category) + '</div>';
            html += '<div style="margin-bottom:12px;"><strong>状态：</strong>' + esc(p.status) + '</div>';
            html += '<div style="margin-bottom:12px;"><strong>内容：</strong></div>';
            html += '<div style="background:#f9fafb;padding:12px;border-radius:6px;margin-bottom:12px;white-space:pre-wrap;">' + esc(p.content || '') + '</div>';
            if (p.images && p.images.length > 0) {
                html += '<div style="margin-bottom:12px;"><strong>图片：</strong></div>';
                html += '<div style="display:flex;gap:8px;flex-wrap:wrap;">';
                p.images.forEach(function(img) {
                    html += '<img src="' + esc(img) + '" style="max-width:150px;max-height:150px;border-radius:6px;">';
                });
                html += '</div>';
            }
            if (p.comments && p.comments.length > 0) {
                html += '<div style="margin-top:16px;"><strong>评论（' + p.comments.length + '）：</strong></div>';
                p.comments.forEach(function(c) {
                    html += '<div style="padding:8px;margin:4px 0;background:#f9fafb;border-radius:4px;">';
                    html += '<strong>' + esc(c.is_anonymous ? '匿名' : (c.nickname || c.qq)) + '</strong>: ';
                    html += esc(c.content || '') + '<br><small style="color:#6b7280;">' + esc(c.created_at || '') + '</small>';
                    html += '</div>';
                });
            }
            document.getElementById('detailContent').innerHTML = html;
            openModal('detailModal');
        }
    })
    .catch(function() { showToast('加载失败', 'error'); });
}

loadPosts();
</script>

<?php adminFooter(); ?>
