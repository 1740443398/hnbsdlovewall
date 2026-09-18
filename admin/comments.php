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

$hasDeleteComments = checkPermission($adminUser, 'delete_posts');

adminHeader('评论管理', $adminUser, $csrfToken);
?>

<div class="section">
    <div class="section-header">
        <h3>评论列表</h3>
        <div>
            <?php if ($hasDeleteComments): ?>
            <button class="btn btn-danger btn-sm" onclick="batchDelete()">批量删除</button>
            <?php endif; ?>
        </div>
    </div>
    <div class="section-body">
        <div class="filter-row">
            <div class="form-group" style="min-width:120px;">
                <input type="number" id="filterPostId" placeholder="帖子ID（可选）" onkeyup="debounceSearch()">
            </div>
            <div class="form-group">
                <input type="text" id="filterKeyword" placeholder="搜索评论内容..." onkeyup="debounceSearch()">
            </div>
            <button class="btn btn-primary btn-sm" onclick="currentPage=1;loadComments()">搜索</button>
        </div>

        <div class="table-wrapper">
            <table>
                <thead>
                    <tr>
                        <th><input type="checkbox" id="selectAll" onchange="toggleSelectAll()"></th>
                        <th>ID</th>
                        <th>评论内容</th>
                        <th>所属帖子</th>
                        <th>评论者</th>
                        <th>时间</th>
                        <th>操作</th>
                    </tr>
                </thead>
                <tbody id="commentsTableBody">
                    <tr><td colspan="7" class="loading-spinner">加载中</td></tr>
                </tbody>
            </table>
        </div>
        <div class="pagination" id="commentsPagination"></div>
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
var pendingCommentIds = null;

function debounceSearch() {
    clearTimeout(searchTimer);
    searchTimer = setTimeout(function() { currentPage = 1; loadComments(); }, 400);
}

function toggleSelectAll() {
    var checked = document.getElementById('selectAll').checked;
    document.querySelectorAll('.comment-checkbox').forEach(function(cb) { cb.checked = checked; });
}

function getSelectedComments() {
    var ids = [];
    document.querySelectorAll('.comment-checkbox:checked').forEach(function(cb) { ids.push(cb.value); });
    return ids;
}

function loadComments() {
    var postId = document.getElementById('filterPostId').value.trim();
    var keyword = document.getElementById('filterKeyword').value.trim();
    var tbody = document.getElementById('commentsTableBody');

    fetch(SITE_URL + '/api/admin/comments.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: new URLSearchParams({
            csrf_token: CSRF_TOKEN,
            action: 'list',
            page: currentPage,
            keyword: keyword,
            post_id: postId
        })
    })
    .then(function(r) { return r.json(); })
    .then(function(data) {
        if (data.success) {
            var comments = data.data.comments;
            if (comments.length === 0) {
                tbody.innerHTML = '<tr><td colspan="7"><div class="empty-state"><div class="empty-icon"><svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg></div><p>暂无评论</p></div></td></tr>';
            } else {
                tbody.innerHTML = comments.map(function(c) {
                    var userDisplay = c.is_anonymous ? '<em>匿名</em>' : esc(c.nickname || c.qq);
                    var actions = '';
                    <?php if ($hasDeleteComments): ?>
                    actions = '<button class="btn btn-danger btn-sm" onclick="deleteComment(' + c.id + ')">删除</button>';
                    <?php endif; ?>
                    return '<tr>' +
                        '<td><input type="checkbox" class="comment-checkbox" value="' + esc(c.id) + '"></td>' +
                        '<td>' + esc(c.id) + '</td>' +
                        '<td><div style="max-width:320px;">' + esc(c.content_short || '') + '</div></td>' +
                        '<td><a href="posts.php?post_id=' + esc(c.post_id) + '" target="_blank" style="color:var(--primary);text-decoration:none;">#' + esc(c.post_id) + ' ' + esc(c.post_title || '') + '</a></td>' +
                        '<td>' + userDisplay + '</td>' +
                        '<td>' + (c.created_at ? c.created_at.substring(0, 16) : '-') + '</td>' +
                        '<td>' + actions + '</td>' +
                        '</tr>';
                }).join('');
            }
            renderPagination(data.data.total, data.data.page, data.data.total_pages);
        } else {
            tbody.innerHTML = '<tr><td colspan="7"><div class="empty-state"><div class="empty-icon"><svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg></div><p>' + (data.message || '加载失败') + '</p></div></td></tr>';
        }
    })
    .catch(function() {
        showToast('加载失败', 'error');
        tbody.innerHTML = '<tr><td colspan="7"><div class="empty-state"><div class="empty-icon"><svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg></div><p>加载失败，请刷新重试</p></div></td></tr>';
    });
}

function renderPagination(total, page, totalPages) {
    var pag = document.getElementById('commentsPagination');
    if (totalPages <= 1) { pag.innerHTML = ''; return; }
    var html = '<button ' + (page <= 1 ? 'disabled' : '') + ' onclick="goPage(' + (page-1) + ')">上一页</button>';
    for (var i = 1; i <= totalPages && i <= 10; i++) {
        html += '<button class="' + (i === page ? 'active' : '') + '" onclick="goPage(' + i + ')">' + i + '</button>';
    }
    html += '<button ' + (page >= totalPages ? 'disabled' : '') + ' onclick="goPage(' + (page+1) + ')">下一页</button>';
    html += '<span class="page-info">共 ' + total + ' 条</span>';
    pag.innerHTML = html;
}

function goPage(p) { currentPage = p; loadComments(); }

function deleteComment(commentId) {
    pendingCommentIds = [commentId];
    document.getElementById('confirmTitle').textContent = '确认操作';
    document.getElementById('confirmMessage').textContent = '确定要删除该评论吗？此操作不可恢复！';
    document.getElementById('confirmBtn').onclick = function() {
        fetch(SITE_URL + '/api/admin/comments.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: new URLSearchParams({ csrf_token: CSRF_TOKEN, action: 'delete', comment_id: commentId })
        })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            closeModal('confirmModal');
            if (data.success) { showToast(data.message); loadComments(); }
            else { showToast(data.message || '操作失败', 'error'); }
        })
        .catch(function() { closeModal('confirmModal'); showToast('网络错误', 'error'); });
    };
    openModal('confirmModal');
}

function batchDelete() {
    var ids = getSelectedComments();
    if (ids.length === 0) { showToast('请先选择评论', 'error'); return; }
    pendingCommentIds = ids;
    document.getElementById('confirmTitle').textContent = '确认批量删除';
    document.getElementById('confirmMessage').textContent = '确定要批量删除 ' + ids.length + ' 条评论吗？此操作不可恢复！';
    document.getElementById('confirmBtn').onclick = function() {
        fetch(SITE_URL + '/api/admin/comments.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: new URLSearchParams({
                csrf_token: CSRF_TOKEN,
                action: 'batch',
                comment_ids: JSON.stringify(ids)
            })
        })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            closeModal('confirmModal');
            if (data.success) { showToast('批量删除完成，影响 ' + data.data.affected + ' 条'); loadComments(); }
            else { showToast(data.message || '操作失败', 'error'); }
        })
        .catch(function() { closeModal('confirmModal'); showToast('网络错误', 'error'); });
    };
    openModal('confirmModal');
}

loadComments();
</script>

<?php adminFooter(); ?>