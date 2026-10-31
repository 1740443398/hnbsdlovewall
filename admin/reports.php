<?php
/**
 * 后台：举报处理
 *
 * ==========================================================================
 * 这一版的设计取舍
 * ==========================================================================
 * 【为什么不分「帖子举报 / 私信举报」两个标签】
 * 分标签看着整齐，实际会让管理员漏看：默认停在「私信举报」，
 * 另一个标签堆了十几条也不知道。举报是「有人遇到麻烦在求助」，
 * 漏看的代价比列表长一点大得多。
 * 所以合并成一个时间流，按时间倒序混排，用一个小标签区分来源。
 *
 * 【为什么处置不能只有「删除」】
 * 「删除」删的是举报记录本身 —— 处理完什么痕迹都不留，
 * 事后无法回答「这条举报怎么处理的」。而且它对被举报人
 * 没有任何实际处置，举报人也不知道结果。
 *
 * 现在的处置动作分成「判断」和「动作」两层：
 *   判断：违规 / 不违规
 *   动作：忽略、警告、删除内容、封禁
 * 每一次都记状态、处理人、时间、备注，并通知举报人。
 * ==========================================================================
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/layout.php';

$admin = requireAdmin();
$admin = checkBanned($admin);
if (!checkPermission($admin, 'view_reports')) {
    header('Location: /admin/dashboard.php');
    exit;
}

$csrfToken = generateCSRFToken();
adminHeader('举报处理', $admin, $csrfToken);
?>

<style>
.rp-tab.active { background: var(--primary, var(--primary)); color: var(--card-bg); }
.rp-tab .num {
    display: inline-block; min-width: 18px; padding: 0 5px; margin-left: 5px;
    border-radius: 9px; font-size: 11px; line-height: 17px;
    background: rgba(255,255,255,.25);
}
.rp-tab:not(.active) .num { background: var(--border); color: var(--text-secondary); }
.rp-item.is-pending { border-left: 3px solid #E8A33D; }
.rp-item.is-handled { opacity: .78; }
.rp-kind.post { background: #EAF1FB; color: var(--primary); }
.rp-kind.pm   { background: #F3EEFB; color: #6B46C1; }
.rp-status.pending  { background: var(--warning-light); color: var(--warning); }
.rp-status.ignored  { background: var(--border-light); color: var(--text-secondary); }
.rp-status.warned   { background: var(--warning-light); color: var(--warning); }
.rp-status.removed  { background: var(--danger-light); color: var(--danger); }
.rp-status.banned   { background: var(--danger-light); color: var(--danger); }
.rp-status.resolved { background: var(--success-light); color: var(--success); }
.rp-btn.ignore  { border-color: var(--border); }
.rp-btn.ignore:hover  { background: var(--border-light); color: var(--text-secondary); }
.rp-btn.warn    { border-color: var(--warning-light); color: var(--warning); }
.rp-btn.warn:hover    { background: var(--warning-light); }
.rp-btn.remove  { border-color: var(--danger-light); color: var(--danger); }
.rp-btn.remove:hover  { background: var(--danger-light); }
.rp-btn.ban     { border-color: var(--danger-light); color: var(--danger); background: var(--danger-light); }
.rp-btn.ban:hover     { background: var(--danger); color: var(--card-bg); }
.rp-btn.resolve { border-color: var(--success-light); color: var(--success); }
.rp-btn.resolve:hover { background: var(--success-light); }
.rp-pager button {
    padding: 6px 14px; border-radius: 8px; font-size: 13px;
    border: 1px solid var(--border-color, var(--border)); background: var(--card-bg);
    cursor: pointer; font-family: inherit;
}
.rp-pager button:disabled { opacity: .4; cursor: default; }
.rp-pager span { font-size: 13px; color: var(--text-muted, var(--text-muted)); }

/* 处置弹窗 */
.rp-modal.show { display: flex; }
.rp-modal-box h3 { font-size: 16px; margin: 0 0 6px; color: var(--text); }
.rp-modal-box textarea {
    width: 100%; min-height: 82px; padding: 10px 12px; font-size: 13.5px;
    border: 1px solid var(--border); border-radius: 9px; font-family: inherit;
    resize: vertical; outline: none; line-height: 1.65;
}
.rp-modal-box textarea:focus { border-color: var(--primary); }
.rp-modal-actions button {
    padding: 8px 18px; border-radius: 9px; font-size: 13.5px; font-weight: 600;
    cursor: pointer; font-family: inherit; border: 1px solid var(--border); background: var(--card-bg);
}
.rp-modal-actions .ok { background: var(--primary); color: var(--card-bg); border-color: var(--primary); }
</style>

<div class="section">
    <div class="section-header">
        <h3>举报处理</h3>
    </div>
    <div class="section-body">

        <div class="rp-toolbar">
            <div class="rp-tabs">
                <button type="button" class="rp-tab active" data-status="pending">
                    待处理<span class="num" id="numPending">0</span>
                </button>
                <button type="button" class="rp-tab" data-status="handled">
                    已处理<span class="num" id="numHandled">0</span>
                </button>
                <button type="button" class="rp-tab" data-status="all">
                    全部<span class="num" id="numAll">0</span>
                </button>
            </div>
            <input type="text" class="rp-search" id="rpSearch"
                   placeholder="搜索理由 / 被举报人 / 举报人…">
        </div>

        <div class="rp-list" id="rpList">
            <div class="adm-empty">加载中…</div>
        </div>

        <div class="rp-pager" id="rpPager" style="display:none;">
            <button type="button" id="rpPrev">上一页</button>
            <span id="rpPageInfo"></span>
            <button type="button" id="rpNext">下一页</button>
        </div>
    </div>
</div>

<!-- 处置弹窗 -->
<div class="rp-modal" id="rpModal">
    <div class="rp-modal-box">
        <h3 id="rpModalTitle">处置举报</h3>
        <div class="rp-modal-hint" id="rpModalHint"></div>
        <textarea id="rpModalNote" placeholder="备注（可选，会显示在记录里，也会发给举报人）"></textarea>
        <div class="rp-modal-actions">
            <button type="button" onclick="rpCloseModal()">取消</button>
            <button type="button" class="ok" id="rpModalOk">确定</button>
        </div>
    </div>
</div>

<script>
(function () {
    var state = { status: 'pending', page: 1, keyword: '', items: [], totalPages: 1 };
    var pendingVerdict = null, pendingItem = null;
    var listEl = document.getElementById('rpList');
    var pagerEl = document.getElementById('rpPager');

    var VERDICT_TEXT = {
        ignored:  { title: '忽略这条举报', hint: '认为举报不成立时使用。不会对被举报人做任何处理。' },
        warned:   { title: '警告被举报人', hint: '发送一条站内警告通知，不删除内容、不封禁。' },
        removed:  { title: '删除被举报内容', hint: '把帖子状态改为已拒绝，对外不可见。' },
        banned:   { title: '封禁被举报人', hint: '立即封禁该用户，并发邮件通知。请确认举报属实。' },
        resolved: { title: '标记为已处理', hint: '不需要额外动作，只是把这条举报归档。' }
    };

    function esc(s) {
        return String(s == null ? '' : s)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    function api(params) {
        var body = new URLSearchParams(params);
        body.set('csrf_token', CSRF_TOKEN);
        return fetch(SITE_URL + '/api/admin/reports.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
                'X-Requested-With': 'XMLHttpRequest'
            },
            body: body,
            credentials: 'same-origin'
        }).then(function (r) { return r.json(); });
    }

    function fmtTime(t) {
        if (!t) return '';
        return t.length >= 16 ? t.slice(5, 16) : t;
    }

    function render() {
        if (!state.items.length) {
            listEl.innerHTML = '<div class="adm-empty">'
                + (state.status === 'pending' ? '没有待处理的举报 🎉' : '没有符合条件的举报')
                + '</div>';
            pagerEl.style.display = 'none';
            return;
        }

        var html = '';
        state.items.forEach(function (r) {
            var cls = r.status === 'pending' ? 'is-pending' : 'is-handled';
            html += '<div class="adm-card ' + cls + '">';

            html += '<div class="rp-head">';
            html += '<span class="rp-kind ' + r.kind + '">' + esc(r.kind_label) + '</span>';
            html += '<span class="rp-status ' + esc(r.status) + '">' + esc(r.status_label) + '</span>';
            html += '<span class="rp-time">' + esc(fmtTime(r.created_at)) + '</span>';
            html += '</div>';

            html += '<div class="rp-target">' + esc(r.target_name) + '</div>';
            if (r.target_sub) {
                html += '<div class="rp-sub">' + esc(r.target_sub) + '</div>';
            }
            html += '<div class="rp-reason">举报理由：' + esc(r.reason)
                 + (r.detail ? '\n补充：' + esc(r.detail) : '') + '</div>';

            html += '<div class="rp-meta">举报人：' + esc(r.reporter || '（未知）') + '</div>';

            if (r.status !== 'pending' && (r.handled_by || r.handled_at)) {
                html += '<div class="rp-meta" style="margin-top:4px;">处理人：'
                     + esc(r.handled_by || '—') + ' · ' + esc(fmtTime(r.handled_at)) + '</div>';
            }
            if (r.note) {
                html += '<div class="rp-note">备注：' + esc(r.note) + '</div>';
            }

            if (r.status === 'pending') {
                html += '<div class="rp-actions">';
                html += btn('ignore', '忽略', r.kind, r.id);
                html += btn('warned', '警告', r.kind, r.id);
                if (r.kind === 'post') html += btn('remove', '删除内容', r.kind, r.id);
                html += btn('ban', '封禁用户', r.kind, r.id);
                html += btn('resolve', '已处理', r.kind, r.id);
                html += '</div>';
            } else {
                html += '<div class="rp-actions">';
                html += '<button type="button" class="rp-btn ignore" '
                     +  'onclick="rpReopen(\'' + r.kind + '\',' + r.id + ')">重新打开</button>';
                html += '<button type="button" class="rp-btn remove" '
                     +  'onclick="rpDelete(\'' + r.kind + '\',' + r.id + ')">删除记录</button>';
                html += '</div>';
            }

            html += '</div>';
        });
        listEl.innerHTML = html;

        if (state.totalPages > 1) {
            pagerEl.style.display = 'flex';
            document.getElementById('rpPageInfo').textContent =
                '第 ' + state.page + ' / ' + state.totalPages + ' 页';
            document.getElementById('rpPrev').disabled = state.page <= 1;
            document.getElementById('rpNext').disabled = state.page >= state.totalPages;
        } else {
            pagerEl.style.display = 'none';
        }
    }

    function btn(verdict, label, kind, id) {
        return '<button type="button" class="rp-btn ' + verdict + '" '
             + 'onclick="rpOpenModal(\'' + verdict + '\',\'' + kind + '\',' + id + ')">'
             + esc(label) + '</button>';
    }

    function load() {
        listEl.innerHTML = '<div class="adm-empty">加载中…</div>';
        var p = { action: 'list', status: state.status, page: state.page };
        if (state.keyword) p.keyword = state.keyword;

        api(p).then(function (d) {
            if (!d.success) {
                listEl.innerHTML = '<div class="adm-empty">' + esc(d.message || '加载失败') + '</div>';
                return;
            }
            var data = d.data || {};
            state.items = data.items || [];
            state.totalPages = data.total_pages || 1;
            state.page = data.page || 1;

            var c = data.counts || {};
            document.getElementById('numPending').textContent = c.pending || 0;
            document.getElementById('numHandled').textContent = c.handled || 0;
            document.getElementById('numAll').textContent =
                (c.pending || 0) + (c.handled || 0);

            render();
        }).catch(function () {
            listEl.innerHTML = '<div class="adm-empty">网络错误，请刷新重试</div>';
        });
    }

    // 标签切换
    document.querySelectorAll('.rp-tab').forEach(function (t) {
        t.addEventListener('click', function () {
            document.querySelectorAll('.rp-tab').forEach(function (x) {
                x.classList.remove('active');
            });
            this.classList.add('active');
            state.status = this.getAttribute('data-status');
            state.page = 1;
            load();
        });
    });

    // 搜索（防抖）
    var searchTimer = null;
    document.getElementById('rpSearch').addEventListener('input', function () {
        var v = this.value.trim();
        clearTimeout(searchTimer);
        searchTimer = setTimeout(function () {
            state.keyword = v;
            state.page = 1;
            load();
        }, 380);
    });

    document.getElementById('rpPrev').addEventListener('click', function () {
        if (state.page > 1) { state.page--; load(); }
    });
    document.getElementById('rpNext').addEventListener('click', function () {
        if (state.page < state.totalPages) { state.page++; load(); }
    });

    // ---- 处置弹窗 ----
    window.rpOpenModal = function (verdict, kind, id) {
        var info = VERDICT_TEXT[verdict] || { title: '处置', hint: '' };
        pendingVerdict = verdict;
        pendingItem = { kind: kind, id: id };
        document.getElementById('rpModalTitle').textContent = info.title;
        document.getElementById('rpModalHint').textContent = info.hint;
        document.getElementById('rpModalNote').value = '';
        document.getElementById('rpModal').classList.add('show');
        setTimeout(function () {
            document.getElementById('rpModalNote').focus();
        }, 60);
    };

    window.rpCloseModal = function () {
        document.getElementById('rpModal').classList.remove('show');
        pendingVerdict = null;
        pendingItem = null;
    };

    document.getElementById('rpModalOk').addEventListener('click', function () {
        if (!pendingVerdict || !pendingItem) return;
        var btn = this;
        btn.disabled = true;
        btn.textContent = '处理中…';

        api({
            action: 'handle',
            kind: pendingItem.kind,
            id: pendingItem.id,
            verdict: pendingVerdict,
            note: document.getElementById('rpModalNote').value.trim()
        }).then(function (d) {
            btn.disabled = false;
            btn.textContent = '确定';
            if (d.success) {
                rpCloseModal();
                if (window.showToast) showToast(d.message || '已处理');
                load();
            } else {
                alert(d.message || '处理失败');
            }
        }).catch(function () {
            btn.disabled = false;
            btn.textContent = '确定';
            alert('网络错误');
        });
    });

    window.rpDelete = function (kind, id) {
        if (!confirm('删除这条举报记录？记录会被永久移除（不影响已执行的处置）。')) return;
        api({ action: 'delete', kind: kind, id: id }).then(function (d) {
            if (d.success) load();
            else alert(d.message || '删除失败');
        });
    };

    // 「重新打开」= 把状态改回 pending
    window.rpReopen = function (kind, id) {
        api({ action: 'handle', kind: kind, id: id, verdict: 'resolved', note: '重新打开' })
            .then(function (d) {
                if (d.success) load();
                else alert(d.message || '操作失败');
            });
    };

    load();
})();
</script>

<?php adminFooter(); ?>
