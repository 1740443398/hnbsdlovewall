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
$hasChangeQq = checkPermission($adminUser, 'change_user_qq');
$hasManageTitle = checkPermission($adminUser, 'manage_user_title');
$hasDeleteUser = checkPermission($adminUser, 'delete_user');
$isSuperAdmin = ($adminUser['role'] ?? '') === 'super_admin';

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
            <button class="btn btn-outline btn-sm" onclick="toggleAdvFilter()" id="advToggleBtn" aria-expanded="false">高级检索</button>
            <button class="btn btn-outline btn-sm" onclick="resetAdvFilter()">重置条件</button>
        </div>

        <?php /* 批次 D：D12 用户高级检索 —— 多条件 AND 组合，默认折叠，不占常驻空间 */ ?>
        <div class="adv-filter" id="advFilter" hidden>
            <div class="adv-grid">
                <label class="adv-field">
                    <span>角色</span>
                    <select id="advRole">
                        <option value="">全部</option>
                        <option value="user">普通用户</option>
                        <option value="admin">管理员</option>
                        <option value="super_admin">超级管理员</option>
                    </select>
                </label>
                <label class="adv-field">
                    <span>账号状态</span>
                    <select id="advStatus">
                        <option value="">全部</option>
                        <option value="normal">正常</option>
                        <option value="banned">已封禁</option>
                    </select>
                </label>
                <label class="adv-field">
                    <span>两步验证</span>
                    <select id="advTwofa">
                        <option value="">全部</option>
                        <option value="1">已开启</option>
                        <option value="0">未开启</option>
                    </select>
                </label>
                <label class="adv-field">
                    <span>自定义头衔</span>
                    <select id="advHasTitle">
                        <option value="">全部</option>
                        <option value="1">有头衔</option>
                        <option value="0">无头衔</option>
                    </select>
                </label>
                <label class="adv-field">
                    <span>注册起始</span>
                    <input type="date" id="advRegFrom">
                </label>
                <label class="adv-field">
                    <span>注册截止</span>
                    <input type="date" id="advRegTo">
                </label>
                <label class="adv-field">
                    <span>排序</span>
                    <select id="advSort">
                        <option value="">注册时间</option>
                        <option value="visit_count">访问次数</option>
                        <option value="last_visit">最近访问</option>
                    </select>
                </label>
                <label class="adv-field">
                    <span>方向</span>
                    <select id="advOrder">
                        <option value="desc">降序</option>
                        <option value="asc">升序</option>
                    </select>
                </label>
            </div>
            <div class="adv-actions">
                <button class="btn btn-primary btn-sm" onclick="currentPage = 1; loadUsers();">应用条件</button>
                <span class="adv-hint" id="advHint"></span>
            </div>
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

<style>
/* ---------- 批次 D：用户详情抽屉（D13）----------
   .adv-filter（高级检索面板）是 users/posts 两个页面共用的，已放进 admin/layout.php。 */

/* 抽屉：右侧滑入，宽度自适应，内容区独立滚动 */
.drawer-overlay { justify-content: flex-end !important; align-items: stretch !important; padding: 0 !important; }
.drawer-overlay .drawer {
    width: min(560px, 100vw);
    max-width: 100%;
    height: 100%;
    max-height: 100%;
    border-radius: 0;
    display: flex;
    flex-direction: column;
    animation: drawerIn .22s ease;
}
@keyframes drawerIn { from { transform: translateX(24px); opacity: .4; } to { transform: none; opacity: 1; } }
.drawer .modal-body { flex: 1; overflow-y: auto; }
@media (prefers-reduced-motion: reduce) { .drawer-overlay .drawer { animation: none; } }

.ud-head { display: flex; align-items: center; gap: 12px; padding: 12px; background: var(--bg); border-radius: var(--radius-sm); }
.ud-head img { width: 48px; height: 48px; border-radius: 50%; object-fit: cover; flex: 0 0 auto; }
.ud-head-main { min-width: 0; }
.ud-name { font-weight: 700; font-size: 15px; display: flex; align-items: center; gap: 6px; flex-wrap: wrap; }
.ud-title { font-size: 11px; font-weight: 600; padding: 1px 6px; border-radius: 999px; background: var(--primary-light); color: var(--primary); }
.ud-sub { font-size: 12px; color: var(--text-secondary); margin-top: 3px; }
.ud-tags { display: flex; gap: 6px; margin-top: 6px; flex-wrap: wrap; }
.ud-stats { display: grid; grid-template-columns: repeat(4, 1fr); gap: 8px; margin: 14px 0; }
.ud-stat { background: var(--bg); border-radius: var(--radius-sm); padding: 8px 6px; text-align: center; }
.ud-stat b { display: block; font-size: 17px; font-variant-numeric: tabular-nums; }
.ud-stat span { font-size: 11px; color: var(--text-secondary); }
.ud-sec { margin-top: 16px; }
.ud-sec h4 { font-size: 13px; margin: 0 0 6px; display: flex; align-items: center; gap: 6px; }
.ud-sec h4 em { font-style: normal; font-size: 11px; color: var(--text-secondary); font-weight: 400; }
.ud-row { display: flex; justify-content: space-between; gap: 12px; padding: 7px 0; border-bottom: 1px solid var(--border); font-size: 13px; }
.ud-row > span { color: var(--text-secondary); flex: 0 0 auto; }
.ud-row > b { font-weight: 500; text-align: right; word-break: break-all; }
.ud-item { display: flex; align-items: center; gap: 10px; padding: 7px 0; border-bottom: 1px solid var(--border); text-decoration: none; color: var(--text); font-size: 13px; }
.ud-item:hover { color: var(--primary); }
.ud-item-main { flex: 1; min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.ud-item-meta { flex: 0 0 auto; font-size: 11px; color: var(--text-secondary); }
.ud-log { display: grid; grid-template-columns: 1fr auto; gap: 2px 10px; padding: 6px 0; border-bottom: 1px solid var(--border); font-size: 12.5px; }
.ud-log-a { font-weight: 600; }
.ud-log-t { grid-row: 1; text-align: right; color: var(--text-secondary); font-size: 11px; }
.ud-log-d { grid-column: 1 / -1; color: var(--text-secondary); overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.ud-log-i { grid-column: 1 / -1; font-size: 11px; color: var(--text-muted, var(--text-secondary)); font-variant-numeric: tabular-nums; }
.ud-empty { font-size: 12.5px; color: var(--text-secondary); padding: 6px 0; margin: 0; }
@media (max-width: 560px) { .ud-stats { grid-template-columns: repeat(2, 1fr); } }
</style>

<div class="modal-overlay drawer-overlay" id="userDetailModal">
    <div class="modal drawer">
        <div class="modal-header">
            <h3>用户详情</h3>
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

<div class="modal-overlay" id="changeQqModal">
    <div class="modal">
        <div class="modal-header">
            <h3>修改绑定QQ</h3>
            <button class="modal-close" onclick="closeModal('changeQqModal')" aria-label="关闭">×</button>
        </div>
        <div class="modal-body">
            <input type="hidden" id="changeQqUserId">
            <div class="form-group">
                <label>新绑定QQ号</label>
                <input type="text" id="changeQqText" placeholder="请输入新的QQ号" maxlength="15">
                <div class="error-hint" id="changeQqError" style="display:none;"></div>
            </div>
            <div class="form-hint" style="margin-top:10px;font-size:12px;color:var(--text-secondary,#888);line-height:1.6;">
                修改后该账号将被强制下线，同时系统会向<b>原QQ邮箱</b>发送换绑通知邮件，邮件中会写明换绑后的新QQ号。
            </div>
        </div>
        <div class="modal-footer">
            <button class="btn btn-outline" onclick="closeModal('changeQqModal')">取消</button>
            <button class="btn btn-primary" onclick="confirmChangeQq()">确认换绑</button>
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

// ── 高级检索（D12）────────────────────────────────────────────────
function toggleAdvFilter() {
    var box = document.getElementById('advFilter');
    var btn = document.getElementById('advToggleBtn');
    var open = box.hidden;
    box.hidden = !open;
    btn.setAttribute('aria-expanded', open ? 'true' : 'false');
    btn.textContent = open ? '收起检索' : '高级检索';
}

function resetAdvFilter() {
    ['advRole', 'advStatus', 'advTwofa', 'advHasTitle', 'advRegFrom', 'advRegTo', 'advSort', 'advOrder']
        .forEach(function (id) {
            var el = document.getElementById(id);
            if (!el) return;
            el.value = (id === 'advOrder') ? 'desc' : '';
        });
    document.getElementById('advHint').textContent = '';
    currentPage = 1;
    loadUsers();
}

/** 收集高级条件：空值一律不带，保持 URL 干净 */
function advFilterParams() {
    var map = {
        role: 'advRole', status: 'advStatus', twofa: 'advTwofa', has_title: 'advHasTitle',
        reg_from: 'advRegFrom', reg_to: 'advRegTo', sort: 'advSort', order: 'advOrder'
    };
    var out = {};
    Object.keys(map).forEach(function (key) {
        var el = document.getElementById(map[key]);
        if (!el) return;
        var v = el.value.trim();
        if (v !== '') out[key] = v;
    });
    var hint = document.getElementById('advHint');
    if (hint) {
        var n = Object.keys(out).filter(function (k) { return k !== 'order'; }).length;
        hint.textContent = n > 0 ? ('已启用 ' + n + ' 个筛选条件') : '';
    }
    return out;
}

function loadUsers() {
    var search = document.getElementById('searchUser').value.trim();
    var tbody = document.getElementById('usersTableBody');

    // 高级检索条件（D12）：只在填了值时才带上，避免污染普通搜索的缓存与日志
    var adv = advFilterParams();

    fetch(SITE_URL + '/api/admin/users.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: new URLSearchParams(Object.assign({
            csrf_token: CSRF_TOKEN,
            action: 'list',
            page: currentPage,
            search: search
        }, adv))
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
                    <?php if ($hasChangeQq): ?>
                    if (u.role !== 'super_admin') {
                        actions.push('<button class="btn btn-outline btn-sm" onclick="openChangeQq(' + u.id + ')">改绑定QQ</button>');
                    }
                    <?php endif; ?>
                    <?php if ($hasManageTitle): ?>
                    actions.push('<button class="btn btn-outline btn-sm" onclick="openTitleModal(' + u.id + ', ' + esc(JSON.stringify(u.title_text || '')) + ', ' + esc(JSON.stringify(u.title_color || '')) + ', ' + esc(JSON.stringify(u.title_bg_color || '')) + ', ' + (u.title_rainbow || 0) + ', ' + esc(JSON.stringify(u.title_gradient_start || '')) + ', ' + esc(JSON.stringify(u.title_gradient_end || '')) + ')">设置头衔</button>');
                    <?php endif; ?>
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
                    // 超管可删除普通管理员；所有操作者都不能删除超级管理员
                    if (u.role !== 'super_admin' && (<?= $isSuperAdmin ? 'true' : 'false' ?> || u.role !== 'admin')) {
                        actions.push('<button class="btn btn-danger btn-sm" onclick="confirmDeleteUser(' + u.id + ', ' + esc(JSON.stringify(u.nickname || u.qq)) + ')">删除</button>');
                    }
                    <?php endif; ?>

                    var titleBadge = '-';
                    if (u.title_text) {
                        var style = 'display:inline-block;padding:2px 8px;border-radius:4px;font-size:12px;font-weight:600;';
                        if (u.title_rainbow == 1) {
                            var gs = u.title_gradient_start || '#ff4757';
                            var ge = u.title_gradient_end || '#a55eea';
                            style += 'background:linear-gradient(90deg,' + gs + ',' + ge + ',' + gs + ');color:#fff;';
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

    adminSecurePost(SITE_URL + '/api/admin/users.php', { action: 'reset_password', user_id: userId, new_password: password }, {
        message: '重置该用户密码前，请输入你的登录密码以确认：',
        onSuccess: function () { showToast('密码已重置'); closeModal('resetPasswordModal'); },
        onError: function (m) { showToast(m || '操作失败', 'error'); }
    });
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
    adminSecurePost(SITE_URL + '/api/admin/users.php', { action: 'delete_user', user_id: userId }, {
        message: '即将删除用户「' + name + '」，该用户及其全部内容（帖子、评论、通知等）将被永久删除，不可恢复。\n\n请输入你的登录密码以确认：',
        onSuccess: function () { showToast('用户已删除'); loadUsers(); },
        onError: function (m) { showToast(m || '操作失败', 'error'); }
    });
}

function executePendingAction() {
    if (!pendingConfirmAction) return;
    var a = pendingConfirmAction;
    pendingConfirmAction = null;

    // F11：重置他人 2FA 属高危操作，改走「需二次密码」的安全提交
    if (a.action === 'reset_2fa') {
        closeModal('confirmModal');
        adminSecurePost(SITE_URL + '/api/admin/users.php', { action: a.action, user_id: a.userId }, {
            message: '重置该用户的 2FA（双重验证）前，请输入你的登录密码以确认：',
            onSuccess: function (data) { showToast(data.message); loadUsers(); },
            onError: function (m) { showToast(m || '操作失败', 'error'); }
        });
        return;
    }

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
        style += 'background:linear-gradient(90deg,' + gs + ',' + ge + ',' + gs + ');color:' + color + ';';
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
        var s = u.stats || {};
        var roleText = u.role === 'super_admin' ? '超级管理员' : (u.role === 'admin' ? '管理员' : '用户');

        function kv(k, v) {
            return '<div class="ud-row"><span>' + esc(k) + '</span><b>' + esc(String(v)) + '</b></div>';
        }
        function section(title, inner, count) {
            return '<div class="ud-sec"><h4>' + esc(title) + (count !== undefined ? ' <em>' + count + '</em>' : '') + '</h4>' + inner + '</div>';
        }

        var head =
            '<div class="ud-head">' +
            '<img src="' + esc(u.avatar || '') + '" alt="" onerror="this.style.display=\'none\'">' +
            '<div class="ud-head-main"><div class="ud-name">' + esc(u.nickname) +
            (u.title_text ? ' <span class="ud-title">' + esc(u.title_text) + '</span>' : '') + '</div>' +
            '<div class="ud-sub">ID ' + u.id + ' · QQ ' + esc(u.qq) + ' · ' + esc(roleText) + '</div>' +
            '<div class="ud-tags">' +
            (u.is_banned ? '<span class="badge badge-danger">已封禁</span>' : '<span class="badge badge-success">正常</span>') +
            (u.twofa_enabled ? '<span class="badge badge-info">2FA 已开启</span>' : '<span class="badge badge-user">未开 2FA</span>') +
            '</div></div></div>';

        // 数据概览：全部来自接口实测字段（posts/comments 是真实计数，不是缓存）
        var statsGrid = '<div class="ud-stats">' +
            [['发帖', s.posts], ['评论', s.comments], ['获赞', s.likes_received], ['被收藏', s.favorites_received],
             ['粉丝', s.fans], ['关注', s.following], ['连续签到', s.checkin_streak], ['成就', s.achievements]]
                .map(function (it) {
                    return '<div class="ud-stat"><b>' + (it[1] === undefined ? 0 : it[1]) + '</b><span>' + it[0] + '</span></div>';
                }).join('') + '</div>';

        var profile = kv('真实姓名', u.real_name || '未填写') + kv('班级', u.class_num ? u.class_num + '班' : '未填写') +
            kv('入学年份', u.entrance_year || '未填写') + kv('注册时间', u.created_at || '-') +
            kv('最近访问', u.last_visit || '-') + kv('访问次数', (u.visit_count || 0) + ' 次') +
            (u.is_banned ? kv('封禁原因', u.ban_reason || '-') + kv('解封时间', u.ban_until || '永久') : '');

        var postsHtml = (u.recent_posts && u.recent_posts.length)
            ? u.recent_posts.map(function (p) {
                return '<a class="ud-item" href="/pages/post_detail.php?id=' + encodeURIComponent(p.id) + '" target="_blank" rel="noopener">' +
                    '<span class="ud-item-main">' + esc(p.title || '(无标题)') + '</span>' +
                    '<span class="ud-item-meta">' + esc((p.created_at || '').substring(0, 10)) + '</span></a>';
            }).join('')
            : '<p class="ud-empty">暂无发帖</p>';

        var commentsHtml = (u.recent_comments && u.recent_comments.length)
            ? u.recent_comments.map(function (c) {
                return '<a class="ud-item" href="/pages/post_detail.php?id=' + encodeURIComponent(c.post_id) + '" target="_blank" rel="noopener">' +
                    '<span class="ud-item-main">' + esc(c.content || '') + '</span>' +
                    '<span class="ud-item-meta">' + esc((c.created_at || '').substring(0, 10)) + '</span></a>';
            }).join('')
            : '<p class="ud-empty">暂无评论</p>';

        var activityHtml = (u.activity && u.activity.length)
            ? u.activity.map(function (a) {
                return '<div class="ud-log"><span class="ud-log-a">' + esc(a.action || '-') + '</span>' +
                    '<span class="ud-log-d">' + esc(a.details || '') + '</span>' +
                    '<span class="ud-log-i">' + esc(a.ip || '') + '</span>' +
                    '<span class="ud-log-t">' + esc((a.created_at || '').substring(5, 16)) + '</span></div>';
            }).join('')
            : '<p class="ud-empty">暂无活动记录</p>';

        body.innerHTML = head + statsGrid +
            section('基础资料', profile) +
            section('最近发帖', postsHtml, (s.posts || 0)) +
            section('最近评论', commentsHtml, (s.comments || 0)) +
            section('登录 / 活动记录', activityHtml);
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

function openChangeQq(userId) {
    document.getElementById('changeQqUserId').value = userId;
    document.getElementById('changeQqText').value = '';
    document.getElementById('changeQqError').style.display = 'none';
    openModal('changeQqModal');
}

function confirmChangeQq() {
    var userId = document.getElementById('changeQqUserId').value;
    var newQq = document.getElementById('changeQqText').value.trim();
    var errEl = document.getElementById('changeQqError');

    if (!/^[1-9][0-9]{4,14}$/.test(newQq)) {
        errEl.textContent = 'QQ号格式不正确';
        errEl.style.display = 'block';
        return;
    }

    if (!confirm('确认将该用户绑定QQ变更为 ' + newQq + ' ？\n\n系统将向原QQ邮箱发送换绑通知，该账号会被强制下线。')) {
        return;
    }

    fetch(SITE_URL + '/api/admin/users.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: new URLSearchParams({
            csrf_token: CSRF_TOKEN,
            action: 'change_qq',
            user_id: userId,
            new_qq: newQq
        })
    })
    .then(function(r) { return r.json(); })
    .then(function(data) {
        if (data.success) {
            showToast(data.message || '绑定QQ已更新');
            closeModal('changeQqModal');
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
