<?php
/**
 * 后台 —— 敏感词库管理。
 *
 * 支持单个添加与批量粘贴（换行 / 逗号 / 分号分隔），列表自带搜索、行内编辑与批量删除。
 * 注意：判定方式是子串匹配，短词容易误伤，列表里对 2 字以内（含 2 字）的短词标黄提示。
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/layout.php';

$adminUser = requireAdmin();
$adminUser = checkBanned($adminUser);
$csrfToken = generateCSRFToken();

if (!checkPermission($adminUser, 'manage_sensitive_words')) {
    http_response_code(403);
    die('403 Forbidden - 权限不足');
}

adminHeader('敏感词库', $adminUser, $csrfToken);
?>

<div class="section">
    <div class="section-header"><h3>添加敏感词</h3></div>
    <div class="section-body">
        <div class="form-group">
            <label>敏感词（可一次粘贴多个，用换行、逗号或分号分隔）</label>
            <textarea id="newWords" rows="4" placeholder="违法&#10;诈骗&#10;代考,代写；刷单" style="width:100%;resize:vertical;"></textarea>
        </div>
        <div class="form-inline" style="margin-top:12px;">
            <button class="btn btn-sm" onclick="addWords()">添加</button>
            <span style="color:var(--text-secondary);font-size:12px;">每个词至少 2 个字；重复的词会自动跳过</span>
        </div>
        <p style="color:#b45309;background:#fffbeb;padding:10px 12px;border-radius:8px;font-size:13px;margin:14px 0 0;">
            提示：本站采用「包含即命中」的子串匹配。像「好的」「这个」这类日常用语加入后会大面积误伤正常发言，请只添加确实需要拦截的词。
        </p>
    </div>
</div>

<div class="section">
    <div class="section-header"><h3>敏感词测试器</h3></div>
    <div class="section-body">
        <p style="color:var(--text-secondary);font-size:13px;margin:0 0 12px;">
            粘贴一段正文，立即看到会命中哪些词、出现在哪里 —— <b>发之前就能预判</b>，不用真的发帖被拦了才知道词配错。
            判定逻辑与前台完全同一份代码，不会出现「后台说没事、前台被拦」。
        </p>
        <div class="form-group">
            <label>待检测文本</label>
            <textarea id="testText" rows="4" placeholder="把准备发布的内容粘到这里…" style="width:100%;resize:vertical;"></textarea>
        </div>
        <div class="form-inline" style="margin-top:12px;">
            <button class="btn btn-sm" id="testBtn" onclick="testWords()">检测</button>
            <span id="testSummary" style="font-size:13px;color:var(--text-secondary);"></span>
        </div>
        <div id="testResult" style="margin-top:14px;"></div>
    </div>
</div>

<div class="section">
    <div class="section-header">
        <h3>词库列表 <span id="totalTip" style="font-weight:400;font-size:13px;color:var(--text-secondary);"></span></h3>
        <div style="display:flex;gap:8px;align-items:center;">
            <input type="text" id="kw" placeholder="搜索敏感词" style="width:160px;" onkeydown="if(event.key==='Enter'){currentPage=1;loadWords();}">
            <button class="btn btn-outline btn-sm" onclick="currentPage=1;loadWords()">搜索</button>
            <button class="btn btn-danger btn-sm" onclick="batchDelete()">批量删除</button>
        </div>
    </div>
    <div class="section-body">
        <div class="table-wrapper">
            <table>
                <thead>
                    <tr>
                        <th style="width:44px;"><input type="checkbox" id="checkAll" onchange="toggleAll(this)" style="width:auto;" aria-label="全选"></th>
                        <th style="width:70px;">ID</th>
                        <th>敏感词</th>
                        <th style="width:90px;">字数</th>
                        <th style="width:150px;">操作</th>
                    </tr>
                </thead>
                <tbody id="wordBody"><tr><td colspan="5" class="loading-spinner">加载中</td></tr></tbody>
            </table>
        </div>
        <div class="pagination" id="pager"></div>
    </div>
</div>

<script>
var currentPage = 1;

function api(payload) {
    var body = new URLSearchParams(payload);
    body.set('csrf_token', CSRF_TOKEN);
    return fetch(SITE_URL + '/api/admin/sensitive_words.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: body
    }).then(function (r) { return r.json(); });
}

function loadWords() {
    document.getElementById('wordBody').innerHTML = '<tr><td colspan="5" class="loading-spinner">加载中</td></tr>';
    api({ action: 'list', page: currentPage, keyword: document.getElementById('kw').value.trim() }).then(function (res) {
        if (!res.success) {
            document.getElementById('wordBody').innerHTML = '<tr><td colspan="5"><div class="empty-state"><p>' + esc(res.message || '加载失败') + '</p><button class="btn btn-outline btn-sm" onclick="loadWords()">重试</button></div></td></tr>';
            return;
        }
        var d = res.data;
        document.getElementById('totalTip').textContent = '（共 ' + d.total + ' 个）';
        var tb = document.getElementById('wordBody');
        if (!d.list.length) {
            tb.innerHTML = '<tr><td colspan="5"><div class="empty-state"><p>暂无敏感词</p></div></td></tr>';
        } else {
            tb.innerHTML = d.list.map(function (w) {
                return '<tr>' +
                    '<td><input type="checkbox" class="rowCheck" value="' + w.id + '" style="width:auto;" aria-label="选择"></td>' +
                    '<td>' + w.id + '</td>' +
                    '<td>' +
                        '<span class="edit-view" id="view' + w.id + '">' + esc(w.word) +
                        (w.risky ? ' <span style="font-size:11px;color:#b45309;background:#fffbeb;padding:1px 6px;border-radius:999px;">短词易误伤</span>' : '') +
                        '</span>' +
                        '<input type="text" class="edit-input" id="edit' + w.id + '" value="' + esc(w.word) + '" style="display:none;width:220px;">' +
                    '</td>' +
                    '<td>' + w.len + '</td>' +
                    '<td>' +
                        '<button class="btn btn-outline btn-sm" id="btn' + w.id + '" onclick="toggleEdit(' + w.id + ')">修改</button> ' +
                        '<button class="btn btn-danger btn-sm" onclick="delOne(' + w.id + ', \'' + esc(w.word).replace(/\'/g, "\\'") + '\')">删除</button>' +
                    '</td>' +
                    '</tr>';
            }).join('');
        }
        document.getElementById('checkAll').checked = false;
        renderPager(d.total, d.page, d.total_pages);
    }).catch(function () {
        document.getElementById('wordBody').innerHTML = '<tr><td colspan="5"><div class="empty-state"><p>网络错误</p><button class="btn btn-outline btn-sm" onclick="loadWords()">重试</button></div></td></tr>';
    });
}

function renderPager(total, page, totalPages) {
    var pag = document.getElementById('pager');
    if (totalPages <= 1) { pag.innerHTML = ''; return; }
    var html = '<button ' + (page <= 1 ? 'disabled' : '') + ' onclick="goPage(' + (page - 1) + ')">上一页</button>';
    var start = Math.max(1, page - 3), end = Math.min(totalPages, start + 6);
    for (var i = start; i <= end; i++) {
        html += '<button class="' + (i === page ? 'active' : '') + '" onclick="goPage(' + i + ')">' + i + '</button>';
    }
    html += '<button ' + (page >= totalPages ? 'disabled' : '') + ' onclick="goPage(' + (page + 1) + ')">下一页</button>';
    html += '<span class="page-info">共 ' + total + ' 条 / ' + totalPages + ' 页</span>';
    pag.innerHTML = html;
}

function goPage(p) { currentPage = p; loadWords(); }

function addWords() {
    var raw = document.getElementById('newWords').value.trim();
    if (!raw) { showToast('请先填写敏感词', 'error'); return; }
    api({ action: 'add', words: raw }).then(function (res) {
        showToast(res.message || (res.success ? '已添加' : '添加失败'), res.success ? 'success' : 'error');
        if (res.success) {
            document.getElementById('newWords').value = '';
            currentPage = 1;
            loadWords();
        }
    }).catch(function () { showToast('网络错误', 'error'); });
}

function toggleEdit(id) {
    var view = document.getElementById('view' + id);
    var input = document.getElementById('edit' + id);
    var btn = document.getElementById('btn' + id);
    if (input.style.display === 'none') {
        input.style.display = 'inline-block';
        view.style.display = 'none';
        btn.textContent = '保存';
        btn.className = 'btn btn-success btn-sm';
        input.focus();
    } else {
        var val = input.value.trim();
        api({ action: 'update', id: id, word: val }).then(function (res) {
            showToast(res.message || (res.success ? '已更新' : '更新失败'), res.success ? 'success' : 'error');
            if (res.success) loadWords();
        }).catch(function () { showToast('网络错误', 'error'); });
    }
}

function delOne(id, word) {
    confirmDialog('删除敏感词', '确定要删除「' + word + '」吗？删除后将不再拦截该词。', function () {
        api({ action: 'delete', id: id }).then(function (res) {
            showToast(res.message || (res.success ? '已删除' : '删除失败'), res.success ? 'success' : 'error');
            if (res.success) loadWords();
        }).catch(function () { showToast('网络错误', 'error'); });
    });
}

function toggleAll(el) {
    document.querySelectorAll('.rowCheck').forEach(function (c) { c.checked = el.checked; });
}

function batchDelete() {
    var ids = [];
    document.querySelectorAll('.rowCheck:checked').forEach(function (c) { ids.push(parseInt(c.value, 10)); });
    if (!ids.length) { showToast('请先勾选要删除的词条', 'error'); return; }
    confirmDialog('批量删除', '确定要删除选中的 ' + ids.length + ' 个敏感词吗？此操作不可撤销。', function () {
        api({ action: 'batch_delete', ids: JSON.stringify(ids) }).then(function (res) {
            showToast(res.message || (res.success ? '已删除' : '删除失败'), res.success ? 'success' : 'error');
            if (res.success) { currentPage = 1; loadWords(); }
        }).catch(function () { showToast('网络错误', 'error'); });
    });
}

function testWords() {
    var text = document.getElementById('testText').value;
    var btn = document.getElementById('testBtn');
    var sum = document.getElementById('testSummary');
    var box = document.getElementById('testResult');
    if (!text.trim()) {
        sum.textContent = '请先输入要检测的文本';
        box.innerHTML = '';
        return;
    }
    btn.disabled = true;
    btn.textContent = '检测中…';
    api({ action: 'test', text: text }).then(function (res) {
        btn.disabled = false;
        btn.textContent = '检测';
        if (!res.success) {
            sum.textContent = res.message || '检测失败';
            return;
        }
        var d = res.data;
        if (d.clean) {
            sum.innerHTML = '<span style="color:#10b981;font-weight:600;">✓ 未命中任何敏感词，可以正常发布</span>';
            box.innerHTML = '';
            return;
        }
        sum.innerHTML = '<span style="color:#ef4444;font-weight:600;">✗ 命中 ' + d.count + ' 个敏感词，发布将被拦截</span>';

        // 把命中的词在原文里高亮出来（按位置标记，不重排文本）
        var marks = [];
        (d.hits || []).forEach(function (h) {
            (h.positions || []).forEach(function (p) {
                marks.push({ start: p, len: h.word.length, word: h.word });
            });
        });
        marks.sort(function (a, b) { return a.start - b.start; });

        var out = '', cursor = 0;
        marks.forEach(function (m) {
            if (m.start < cursor) { return; }                 // 重叠的只标第一处
            out += esc(text.slice(cursor, m.start));
            out += '<mark style="background:#fee2e2;color:#991b1b;padding:1px 4px;border-radius:4px;">'
                 + esc(text.substr(m.start, m.len)) + '</mark>';
            cursor = m.start + m.len;
        });
        out += esc(text.slice(cursor));

        var list = (d.hits || []).map(function (h) {
            return '<span style="display:inline-block;margin:2px 4px 2px 0;padding:2px 8px;border-radius:999px;background:#fee2e2;color:#991b1b;font-size:12px;">'
                 + esc(h.word) + ' ×' + h.count + '</span>';
        }).join('');

        box.innerHTML =
            '<div style="margin-bottom:12px;">' + list + '</div>' +
            '<div style="padding:12px;background:var(--bg-secondary);color:var(--text);border-radius:8px;font-size:14px;line-height:1.9;word-break:break-word;">' + out + '</div>';
    }).catch(function () {
        btn.disabled = false;
        btn.textContent = '检测';
        sum.textContent = '网络错误';
    });
}

loadWords();
</script>

<?php adminFooter(); ?>
