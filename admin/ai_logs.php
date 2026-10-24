<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/layout.php';

$adminUser = requireAdmin();
$adminUser = checkBanned($adminUser);
$csrfToken = generateCSRFToken();

if (!checkPermission($adminUser, 'view_ai_logs')) {
    http_response_code(403);
    die('403 Forbidden - 权限不足');
}

adminHeader('AI 调用记录', $adminUser, $csrfToken);
?>

<div class="section">
    <div class="section-header"><h3>调用概览</h3></div>
    <div class="section-body">
        <div class="stats-grid" id="aiStats">
            <div class="stat-card info">
                <div class="stat-label">总调用次数</div>
                <div class="stat-value" id="statTotal">-</div>
            </div>
            <div class="stat-card success">
                <div class="stat-label">今日调用</div>
                <div class="stat-value" id="statToday">-</div>
            </div>
            <div class="stat-card warning">
                <div class="stat-label">失败次数</div>
                <div class="stat-value" id="statFail">-</div>
            </div>
            <div class="stat-card">
                <div class="stat-label">平均耗时</div>
                <div class="stat-value" id="statAvg">-</div>
            </div>
        </div>
        <div class="stats-grid" id="aiTokenStats" style="margin-top:12px;">
            <div class="stat-card">
                <div class="stat-label">筛选范围 · Token 合计</div>
                <div class="stat-value" id="statTokens">-</div>
            </div>
            <div class="stat-card">
                <div class="stat-label">输入 Token</div>
                <div class="stat-value" id="statPromptTokens">-</div>
            </div>
            <div class="stat-card">
                <div class="stat-label">输出 Token</div>
                <div class="stat-value" id="statCompletionTokens">-</div>
            </div>
        </div>
    </div>
</div>

<div class="section">
    <div class="section-header"><h3>筛选</h3></div>
    <div class="section-body">
        <div class="filter-row">
            <div class="form-group">
                <label>关键词</label>
                <input type="text" id="filterKeyword" placeholder="提问 / AI回答 / 昵称 / IP..." onkeyup="debounceSearch()">
            </div>
            <div class="form-group">
                <label>结果</label>
                <select id="filterResult" onchange="currentPage=1;loadLogs()">
                    <option value="">全部</option>
                    <option value="success">仅成功</option>
                    <option value="fail">仅失败</option>
                    <option value="guest">仅游客</option>
                </select>
            </div>
            <div class="form-group">
                <label>用户</label>
                <select id="filterUser" onchange="currentPage=1;loadLogs()">
                    <option value="">全部用户</option>
                </select>
            </div>
            <div class="form-group">
                <label>起始日期</label>
                <input type="date" id="filterDateFrom" onchange="currentPage=1;loadLogs()">
            </div>
            <div class="form-group">
                <label>结束日期</label>
                <input type="date" id="filterDateTo" onchange="currentPage=1;loadLogs()">
            </div>
            <div class="form-group">
                <label>单日</label>
                <input type="date" id="filterDate" onchange="currentPage=1;loadLogs()">
            </div>
            <button class="btn btn-primary btn-sm" onclick="currentPage=1;loadLogs()">搜索</button>
            <button class="btn btn-outline btn-sm" onclick="resetFilters()">重置</button>
        </div>
    </div>
</div>

<div class="section">
    <div class="section-header">
        <h3>分用户汇总</h3>
        <div style="font-size:12px;color:var(--text-secondary);">按调用次数排序，点击用户可下钻查看其全部记录</div>
    </div>
    <div class="section-body">
        <div class="table-wrapper">
            <table>
                <thead>
                    <tr>
                        <th>用户</th>
                        <th>调用次数</th>
                        <th>成功 / 失败</th>
                        <th>Token 合计</th>
                        <th>输入 / 输出</th>
                        <th>站点配额 / 自带</th>
                        <th>最近调用</th>
                        <th>操作</th>
                    </tr>
                </thead>
                <tbody id="userAggBody">
                    <tr><td colspan="8" class="loading-spinner">加载中</td></tr>
                </tbody>
            </table>
        </div>
    </div>
</div>

<div class="section">
    <div class="section-header">
        <h3>分时间汇总</h3>
        <div style="font-size:12px;color:var(--text-secondary);">按天聚合，受上方「起始/结束日期」约束</div>
    </div>
    <div class="section-body">
        <div class="table-wrapper">
            <table>
                <thead>
                    <tr>
                        <th>日期</th>
                        <th>调用次数</th>
                        <th>成功 / 失败</th>
                        <th>Token 合计</th>
                        <th>输入 / 输出</th>
                    </tr>
                </thead>
                <tbody id="dayAggBody">
                    <tr><td colspan="5" class="loading-spinner">加载中</td></tr>
                </tbody>
            </table>
        </div>
    </div>
</div>

<div class="section">
    <div class="section-header"><h3>调用明细</h3></div>
    <div class="section-body">
        <div class="table-wrapper">
            <table>
                <thead>
                    <tr>
                        <th>时间</th>
                        <th>用户</th>
                        <th>来源</th>
                        <th>状态</th>
                        <th>耗时</th>
                        <th>模型</th>
                        <th>Token</th>
                        <th>意图</th>
                        <th>提问</th>
                        <th>操作</th>
                    </tr>
                </thead>
                <tbody id="logsTableBody">
                    <tr><td colspan="10" class="loading-spinner">加载中</td></tr>
                </tbody>
            </table>
        </div>
        <div class="pagination" id="logsPagination"></div>
        <div style="margin-top:14px;display:flex;align-items:center;gap:10px;flex-wrap:wrap;">
            <?php if (checkPermission($adminUser, 'manage_ai_logs')): ?>
            <button class="btn btn-outline btn-sm" onclick="cleanImages()" title="删除超过 30 天的图片，日志文本保留">清理 30 天前图片</button>
            <button class="btn btn-danger btn-sm" onclick="clearAllLogs()">清空全部记录</button>
            <?php endif; ?>
            <span style="font-size:12px;color:var(--text-secondary);">
                说明：点击「详情」可查看用户与 AI 的完整对话（含上传的图片与 token 用量）。图片在服务器仅保留 <strong>30 天</strong>。
            </span>
        </div>
    </div>
</div>

<div class="modal-overlay" id="aiDetailModal" onclick="if(event.target===this)closeDetail()">
    <div class="modal" style="max-width:760px;">
        <div class="modal-header">
            <h3>对话详情</h3>
            <button class="modal-close" onclick="closeDetail()">&times;</button>
        </div>
        <div class="modal-body" id="aiDetailBody" style="max-height:70vh;overflow-y:auto;">
            <div class="loading-spinner">加载中…</div>
        </div>
        <div class="modal-footer">
            <button class="btn btn-outline" onclick="closeDetail()">关闭</button>
            <button class="btn btn-danger" id="aiDetailDelBtn" onclick="deleteCurrentLog()">删除此记录</button>
        </div>
    </div>
</div>


<script>
var currentPage = 1;
var searchTimer = null;

function debounceSearch() {
    clearTimeout(searchTimer);
    searchTimer = setTimeout(function() { currentPage = 1; loadLogs(); }, 400);
}

function post(action, extra) {
    var body = new URLSearchParams({ csrf_token: CSRF_TOKEN, action: action });
    Object.keys(extra || {}).forEach(function(k) { body.append(k, extra[k]); });
    return fetch(SITE_URL + '/api/admin/logs.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: body
    }).then(function(r) { return r.json(); });
}

function loadStats() {
    post('ai_logs_stats', {}).then(function(data) {
        if (!data.success) return;
        var s = data.data.stat;
        document.getElementById('statTotal').textContent = s.total;
        document.getElementById('statToday').textContent = s.today;
        document.getElementById('statFail').textContent = s.fail;
        document.getElementById('statAvg').textContent = s.avg_ms + ' ms';
    }).catch(function() {});
}

function fmtNum(n) {
    n = Number(n) || 0;
    return n.toLocaleString('en-US');
}

// 收集当前筛选条件（明细 / 分用户 / 分时间三张表共用同一套口径）
function currentFilters() {
    return {
        keyword: document.getElementById('filterKeyword').value.trim(),
        result: document.getElementById('filterResult').value,
        user_id: document.getElementById('filterUser').value,
        date_from: document.getElementById('filterDateFrom').value,
        date_to: document.getElementById('filterDateTo').value,
        date: document.getElementById('filterDate').value
    };
}

function resetFilters() {
    document.getElementById('filterKeyword').value = '';
    document.getElementById('filterResult').value = '';
    document.getElementById('filterUser').value = '';
    document.getElementById('filterDateFrom').value = '';
    document.getElementById('filterDateTo').value = '';
    document.getElementById('filterDate').value = '';
    currentPage = 1;
    loadAll();
}

function loadLogs() {
    var f = currentFilters();
    f.page = currentPage;
    post('list_ai_logs', f)
    .then(function(data) {
        if (!data.success) { showToast(data.message || '加载失败', 'error'); return; }
        var logs = data.data.logs;
        // token 汇总
        var ts = data.data.token_sum || { prompt: 0, completion: 0, total: 0 };
        document.getElementById('statTokens').textContent = fmtNum(ts.total);
        document.getElementById('statPromptTokens').textContent = fmtNum(ts.prompt);
        document.getElementById('statCompletionTokens').textContent = fmtNum(ts.completion);

        var tbody = document.getElementById('logsTableBody');
        if (logs.length === 0) {
            tbody.innerHTML = '<tr><td colspan="10"><div class="empty-state"><div class="empty-icon"><svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="22 12 16 12 14 15 10 15 8 12 2 12"/><path d="M5.45 5.11L2 12v6a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2v-6l-3.45-6.89A2 2 0 0 0 16.76 4H7.24a2 2 0 0 0-1.79 1.11z"/></svg></div><p>暂无数据</p></div></td></tr>';
        } else {
            tbody.innerHTML = logs.map(function(l) {
                var who = l.is_guest == 1
                    ? '<span class="badge badge-warning">游客</span>'
                    : '<span class="badge badge-info">' + esc(l.nickname || ('用户' + l.user_id)) + '</span>';
                var status = l.success == 1
                    ? '<span class="badge badge-success">成功</span>'
                    : '<span class="badge badge-danger">失败</span>';
                var page = l.page ? '<div style="font-size:11px;color:var(--text-secondary);">' + esc(l.page) + '</div>' : '';
                var totalTok = (l.total_tokens || 0);
                var tokCell = totalTok > 0
                    ? '<div title="输入 ' + (l.prompt_tokens||0) + ' / 输出 ' + (l.completion_tokens||0) + '">' + fmtNum(totalTok) + '</div><div style="font-size:11px;color:var(--text-secondary);">' + (l.prompt_tokens||0) + ' / ' + (l.completion_tokens||0) + '</div>'
                    : '<span style="color:var(--text-secondary);">-</span>';
                var owner = (l.key_owner === 'user')
                    ? '<div style="font-size:11px;color:var(--text-secondary);">自带模型</div>'
                    : '<div style="font-size:11px;color:var(--text-secondary);">站点配额</div>';
                return '<tr>' +
                    '<td>' + esc((l.created_at || '').substring(0, 16)) + '</td>' +
                    '<td>' + who + page + owner + '</td>' +
                    '<td>' + esc(l.ip || '-') + '</td>' +
                    '<td>' + status + '<div style="font-size:11px;color:var(--text-secondary);" title="' + esc(l.error || '') + '">' + esc((l.success == 1 ? 'HTTP ' + l.http_status : (l.error || '').substring(0, 20))) + '</div></td>' +
                    '<td>' + esc(l.elapsed_ms) + ' ms</td>' +
                    '<td style="font-size:12px;">' + esc(l.model || '-') + '</td>' +
                    '<td style="font-size:12px;">' + tokCell + '</td>' +
                    '<td style="font-size:12px;">' + esc(l.intent || '-') + '</td>' +
                    '<td style="max-width:280px;font-size:12px;" title="' + esc(l.question || '') + '">' + esc(l.question || '-') + '</td>' +
                    '<td><button class="btn btn-outline btn-sm" onclick="openDetail(' + l.id + ')">详情</button></td>' +
                    '</tr>';
            }).join('');
        }
        renderPagination(data.data.total, data.data.page, data.data.total_pages);
    })
    .catch(function() { showToast('加载失败', 'error'); });
}

// 分用户汇总表
function loadUserAgg() {
    var f = currentFilters();
    delete f.keyword; delete f.result; delete f.date;
    post('ai_logs_by_user', f).then(function(data) {
        var tbody = document.getElementById('userAggBody');
        var users = (data.success && data.data.users) || [];
        if (users.length === 0) {
            tbody.innerHTML = '<tr><td colspan="8" style="text-align:center;color:var(--text-secondary);">暂无数据</td></tr>';
            return;
        }
        tbody.innerHTML = users.map(function(u) {
            var name = u.is_guest == 1
                ? '<span class="badge badge-warning">游客</span>'
                : '<span class="badge badge-info">' + esc(u.nickname) + '</span>';
            return '<tr>' +
                '<td>' + name + '</td>' +
                '<td><strong>' + u.calls + '</strong></td>' +
                '<td><span style="color:#2E8B57;">' + u.success + '</span> / <span style="color:#cb2431;">' + u.fail + '</span></td>' +
                '<td>' + fmtNum(u.total_tokens) + '</td>' +
                '<td style="font-size:12px;">' + fmtNum(u.prompt_tokens) + ' / ' + fmtNum(u.completion_tokens) + '</td>' +
                '<td style="font-size:12px;">' + u.site_cost + ' / ' + u.own_cost + '</td>' +
                '<td style="font-size:12px;">' + esc((u.last_at || '').substring(0, 16)) + '</td>' +
                '<td>' + (u.is_guest == 1
                    ? '<span style="font-size:12px;color:var(--text-secondary);">—</span>'
                    : '<button class="btn btn-outline btn-sm" onclick="drillUser(' + u.user_id + ')">查看记录</button>') + '</td>' +
                '</tr>';
        }).join('');
        // 顺手把用户下拉选项灌上（数据来源同一份，省一次请求）
        fillUserOptions(users);
    }).catch(function() {
        document.getElementById('userAggBody').innerHTML = '<tr><td colspan="8" style="text-align:center;color:var(--text-secondary);">加载失败</td></tr>';
    });
}

// 分时间汇总表
function loadDayAgg() {
    var f = currentFilters();
    delete f.keyword; delete f.result; delete f.user_id; delete f.date;
    post('ai_logs_by_day', f).then(function(data) {
        var tbody = document.getElementById('dayAggBody');
        var days = (data.success && data.data.days) || [];
        if (days.length === 0) {
            tbody.innerHTML = '<tr><td colspan="5" style="text-align:center;color:var(--text-secondary);">暂无数据</td></tr>';
            return;
        }
        // 倒序展示（最近的天在最上面）
        days = days.slice().reverse();
        tbody.innerHTML = days.map(function(d) {
            return '<tr>' +
                '<td>' + esc(d.day) + '</td>' +
                '<td><strong>' + d.calls + '</strong></td>' +
                '<td><span style="color:#2E8B57;">' + d.success + '</span> / <span style="color:#cb2431;">' + d.fail + '</span></td>' +
                '<td>' + fmtNum(d.total_tokens) + '</td>' +
                '<td style="font-size:12px;">' + fmtNum(d.prompt_tokens) + ' / ' + fmtNum(d.completion_tokens) + '</td>' +
                '</tr>';
        }).join('');
    }).catch(function() {
        document.getElementById('dayAggBody').innerHTML = '<tr><td colspan="5" style="text-align:center;color:var(--text-secondary);">加载失败</td></tr>';
    });
}

// 下钻到某用户：设置用户筛选并刷新明细
function drillUser(uid) {
    document.getElementById('filterUser').value = String(uid);
    currentPage = 1;
    loadLogs();
    document.getElementById('logsTableBody').scrollIntoView({ behavior: 'smooth', block: 'start' });
}

// 用户下拉选项：从分用户汇总里灌数据（避免额外接口）
function fillUserOptions(users) {
    var sel = document.getElementById('filterUser');
    var cur = sel.value;
    var html = '<option value="">全部用户</option>';
    users.forEach(function(u) {
        if (u.is_guest == 1) return;
        html += '<option value="' + u.user_id + '">' + esc(u.nickname) + '（' + u.calls + ' 次）</option>';
    });
    sel.innerHTML = html;
    sel.value = cur;
}

function loadAll() {
    loadLogs();
    loadUserAgg();
    loadDayAgg();
}

function renderPagination(total, page, totalPages) {
    var pag = document.getElementById('logsPagination');
    if (totalPages <= 1) { pag.innerHTML = ''; return; }
    var html = '<button ' + (page <= 1 ? 'disabled' : '') + ' onclick="goPage(' + (page-1) + ')">上一页</button>';
    for (var i = 1; i <= totalPages && i <= 10; i++) {
        html += '<button class="' + (i === page ? 'active' : '') + '" onclick="goPage(' + i + ')">' + i + '</button>';
    }
    html += '<button ' + (page >= totalPages ? 'disabled' : '') + ' onclick="goPage(' + (page+1) + ')">下一页</button>';
    html += '<span class="page-info">共 ' + total + ' 条</span>';
    pag.innerHTML = html;
}

function goPage(p) { currentPage = p; loadLogs(); }

var currentLogId = 0;

function openDetail(id) {
    currentLogId = id;
    var modal = document.getElementById('aiDetailModal');
    var body = document.getElementById('aiDetailBody');
    body.innerHTML = '<div class="loading-spinner">加载中…</div>';
    modal.classList.add('show');
    post('ai_log_detail', { id: id }).then(function(data) {
        if (!data.success) { body.innerHTML = '<p style="color:var(--text-secondary);">' + esc(data.message || '加载失败') + '</p>'; return; }
        renderDetail(data.data.log, data.data.conversation);
    }).catch(function() {
        body.innerHTML = '<p style="color:var(--text-secondary);">网络错误，加载失败</p>';
    });
}

function renderDetail(log, conv) {
    var html = '';
    // 元信息头
    html += '<div style="display:flex;flex-wrap:wrap;gap:10px 18px;font-size:12px;color:var(--text-secondary);padding:0 0 12px;border-bottom:1px solid var(--border-light);margin-bottom:14px;">';
    html += '<span>时间：' + esc((log.created_at || '').substring(0, 19)) + '</span>';
    html += '<span>用户：' + esc(log.is_guest == 1 ? '游客' : (log.nickname || ('用户' + log.user_id))) + '</span>';
    html += '<span>IP：' + esc(log.ip || '-') + '</span>';
    html += '<span>状态：' + (log.success == 1 ? '成功' : '失败') + '</span>';
    html += '<span>耗时：' + esc(log.elapsed_ms) + ' ms</span>';
    html += '<span>密钥来源：' + (log.key_owner === 'user' ? '用户自带' : '站点') + '</span>';
    if (log.model) html += '<span>模型：' + esc(log.model) + '</span>';
    html += '</div>';

    // token 用量卡片
    var pt = parseInt(log.prompt_tokens || 0, 10);
    var ct = parseInt(log.completion_tokens || 0, 10);
    var tt = parseInt(log.total_tokens || 0, 10);
    html += '<div style="display:grid;grid-template-columns:repeat(3,1fr);gap:10px;margin-bottom:14px;">';
    html += tokenCard('输入 Token', pt, 'var(--text)');
    html += tokenCard('输出 Token', ct, 'var(--text)');
    html += tokenCard('合计 Token', tt, '#2E8B57');
    html += '</div>';
    if (tt === 0) {
        html += '<div style="font-size:12px;color:var(--text-secondary);margin:-6px 0 14px;">（本条无 token 记录：老日志或上游未返回 usage）</div>';
    }

    if (log.success != 1 && log.error) {
        html += '<div style="background:rgba(239,68,68,0.08);color:#dc2626;font-size:12px;padding:8px 10px;border-radius:6px;margin-bottom:14px;">错误：' + esc(log.error) + '</div>';
    }

    // AI 回答单独高亮（提问与回答分开展示，便于快速扫读）
    if (log.answer) {
        html += '<div style="margin-bottom:16px;">';
        html += '<div style="font-size:12px;font-weight:600;color:var(--text-secondary);margin-bottom:6px;">AI 回答</div>';
        html += '<div style="background:var(--bg-secondary);border-left:3px solid #2E8B57;padding:10px 12px;border-radius:6px;font-size:13px;line-height:1.7;white-space:pre-wrap;word-break:break-word;max-height:240px;overflow-y:auto;">' + esc(log.answer) + '</div>';
        html += '</div>';
    }

    // 完整对话气泡
    html += '<div style="font-size:12px;font-weight:600;color:var(--text-secondary);margin-bottom:8px;">完整对话</div>';
    conv.forEach(function(turn) {
        var isUser = turn.role !== 'assistant';
        html += '<div style="display:flex;justify-content:' + (isUser ? 'flex-end' : 'flex-start') + ';margin-bottom:12px;">';
        html += '<div style="max-width:80%;background:' + (isUser ? 'var(--primary-color,#4f7cff)' : 'var(--bg-secondary,#f4f4f5)') + ';color:' + (isUser ? '#fff' : 'inherit') + ';padding:8px 12px;border-radius:10px;font-size:13px;line-height:1.6;word-break:break-word;white-space:pre-wrap;">';
        html += '<div style="font-size:11px;opacity:.7;margin-bottom:3px;">' + (isUser ? '用户' : 'AI') + '</div>';
        html += esc(turn.content || '');
        if (turn.image_meta && turn.image_meta.length) {
            html += '<div style="display:flex;flex-wrap:wrap;gap:8px;margin-top:8px;">';
            turn.image_meta.forEach(function(im) {
                if (im.exists) {
                    html += '<a href="' + SITE_URL + '/' + esc(im.url) + '" target="_blank" rel="noopener"><img src="' + SITE_URL + '/' + esc(im.url) + '" style="width:96px;height:96px;object-fit:cover;border-radius:8px;border:1px solid rgba(0,0,0,.1);" alt="图片"></a>';
                } else {
                    html += '<span style="width:96px;height:96px;display:flex;align-items:center;justify-content:center;border-radius:8px;border:1px dashed var(--border-light);font-size:11px;color:var(--text-secondary);text-align:center;">图片已过期<br>(超30天)</span>';
                }
            });
            html += '</div>';
        }
        html += '</div></div>';
    });
    if (!conv.length) html += '<p style="color:var(--text-secondary);font-size:13px;">该记录没有可展示的对话内容。</p>';
    document.getElementById('aiDetailBody').innerHTML = html;
    var delBtn = document.getElementById('aiDetailDelBtn');
    delBtn.style.display = '<?php echo checkPermission($adminUser, "manage_ai_logs") ? "inline-block" : "none"; ?>';
}

function tokenCard(label, val, color) {
    return '<div style="background:var(--bg-secondary);border-radius:8px;padding:10px 12px;text-align:center;">' +
        '<div style="font-size:11px;color:var(--text-secondary);margin-bottom:4px;">' + label + '</div>' +
        '<div style="font-size:18px;font-weight:700;color:' + color + ';">' + fmtNum(val) + '</div>' +
        '</div>';
}

function closeDetail() {
    document.getElementById('aiDetailModal').classList.remove('show');
    currentLogId = 0;
}

function deleteCurrentLog() {
    if (!currentLogId) return;
    if (!confirm('确定删除这条 AI 调用记录吗？其关联的图片也会一并删除。')) return;
    post('delete_ai_log', { id: currentLogId }).then(function(data) {
        if (data.success) { showToast(data.message || '已删除', 'success'); closeDetail(); loadStats(); loadAll(); }
        else { showToast(data.message || '删除失败', 'error'); }
    }).catch(function() { showToast('删除失败', 'error'); });
}

function clearAllLogs() {
    if (!confirm('确定清空全部 AI 调用记录吗？此操作不可恢复，所有相关图片都会被删除。')) return;
    if (!confirm('再次确认：真的要清空全部记录？')) return;
    post('clear_ai_logs', {}).then(function(data) {
        if (data.success) { showToast(data.message || '已清空', 'success'); loadStats(); loadAll(); }
        else { showToast(data.message || '操作失败', 'error'); }
    }).catch(function() { showToast('操作失败', 'error'); });
}

function cleanImages() {
    if (!confirm('确定清理服务器上超过 30 天的 AI 图片吗？日志文本会保留。')) return;
    post('clean_ai_images', { days: 30 }).then(function(data) {
        if (data.success) { showToast(data.message || '已清理', 'success'); }
        else { showToast(data.message || '操作失败', 'error'); }
    }).catch(function() { showToast('操作失败', 'error'); });
}

loadStats();
loadAll();
</script>

<?php adminFooter(); ?>