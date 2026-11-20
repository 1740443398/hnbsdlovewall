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
.rp-toolbar {
    display: flex; gap: 10px; flex-wrap: wrap; align-items: center;
    margin-bottom: 16px;
}
.rp-tabs { display: flex; gap: 6px; flex-wrap: wrap; }
.rp-tab {
    padding: 7px 15px; border-radius: 9px; font-size: 13px; font-weight: 600;
    background: var(--bg-secondary, #F3F6FA); border: 0; cursor: pointer;
    color: var(--text-secondary, #5A6472); font-family: inherit;
    transition: background .15s, color .15s;
}
.rp-tab:hover { background: #E8EDF5; }
.rp-tab.active { background: var(--primary, #2F5B9A); color: #fff; }
.rp-tab .num {
    display: inline-block; min-width: 18px; padding: 0 5px; margin-left: 5px;
    border-radius: 9px; font-size: 11px; line-height: 17px;
    background: rgba(255,255,255,.25);
}
.rp-tab:not(.active) .num { background: #D5DCE6; color: #4A5568; }

.rp-search {
    flex: 1 1 200px; min-width: 160px; padding: 8px 12px; font-size: 13px;
    border: 1px solid var(--border-color, #E4E9F0); border-radius: 9px;
    background: var(--bg-input, #fff); outline: none; font-family: inherit;
}

.rp-list { display: flex; flex-direction: column; gap: 10px; }

.rp-item {
    background: var(--surface, #fff);
    border: 1px solid var(--border-light, #E9EEF5);
    border-radius: 12px;
    padding: 14px 16px;
    transition: border-color .16s, box-shadow .16s;
}
.rp-item:hover { border-color: rgba(47,91,154,.3); box-shadow: 0 4px 16px -10px rgba(16,24,40,.2); }
.rp-item.is-pending { border-left: 3px solid #E8A33D; }
.rp-item.is-handled { opacity: .78; }

.rp-head {
    display: flex; align-items: center; gap: 8px; flex-wrap: wrap;
    margin-bottom: 8px; font-size: 12px;
}
.rp-kind {
    padding: 2px 8px; border-radius: 5px; font-weight: 600; font-size: 11.5px;
}
.rp-kind.post { background: #EAF1FB; color: #2F5B9A; }
.rp-kind.pm   { background: #F3EEFB; color: #6B46C1; }
.rp-status {
    padding: 2px 8px; border-radius: 5px; font-weight: 600; font-size: 11.5px;
}
.rp-status.pending  { background: #FEF3E7; color: #B4761F; }
.rp-status.ignored  { background: #EEF2F7; color: #5A6472; }
.rp-status.warned   { background: #FEF3E7; color: #B4761F; }
.rp-status.removed  { background: #FDECEC; color: #C0392B; }
.rp-status.banned   { background: #FDECEC; color: #C0392B; }
.rp-status.resolved { background: #EAF7F1; color: #178A62; }
.rp-time { color: var(--text-muted, #98A2B3); margin-left: auto; }

.rp-target {
    font-size: 14px; font-weight: 600; color: var(--text-primary, #1F2733);
    margin-bottom: 4px; word-break: break-word;
}
.rp-sub { font-size: 12.5px; color: var(--text-muted, #98A2B3); margin-bottom: 8px; }
.rp-reason {
    font-size: 13px; color: var(--text-secondary, #5A6472); line-height: 1.7;
    padding: 9px 12px; background: var(--bg-secondary, #F7F9FC);
    border-radius: 8px; margin-bottom: 8px; white-space: pre-wrap; word-break: break-word;
}
.rp-meta { font-size: 12px; color: var(--text-muted, #98A2B3); }
.rp-note {
    font-size: 12.5px; color: #178A62; margin-top: 6px;
    padding: 7px 10px; background: #EAF7F1; border-radius: 7px;
}

.rp-actions { display: flex; gap: 8px; flex-wrap: wrap; margin-top: 10px; }
.rp-btn {
    padding: 6px 13px; border-radius: 8px; font-size: 12.5px; font-weight: 600;
    border: 1px solid var(--border-color, #E4E9F0); background: #fff;
    color: var(--text-secondary, #5A6472); cursor: pointer; font-family: inherit;
    transition: all .15s;
}
.rp-btn:hover { background: var(--bg-secondary, #F3F6FA); }
.rp-btn.ignore  { border-color: #D5DCE6; }
.rp-btn.ignore:hover  { background: #EEF2F7; color: #4A5568; }
.rp-btn.warn    { border-color: #F0D9B5; color: #B4761F; }
.rp-btn.warn:hover    { background: #FEF3E7; }
.rp-btn.remove  { border-color: #F5C6C6; color: #C0392B; }
.rp-btn.remove:hover  { background: #FDECEC; }
.rp-btn.ban     { border-color: #F5C6C6; color: #C0392B; background: #FEF6F6; }
.rp-btn.ban:hover     { background: #C0392B; color: #fff; }
.rp-btn.resolve { border-color: #C5E9D8; color: #178A62; }
.rp-btn.resolve:hover { background: #EAF7F1; }

.rp-empty {
    padding: 48px 20px; text-align: center;
    color: var(--text-muted, #98A2B3); font-size: 13.5px;
}

.rp-pager { display: flex; gap: 8px; justify-content: center; margin-top: 18px; align-items: center; }
.rp-pager button {
    padding: 6px 14px; border-radius: 8px; font-size: 13px;
    border: 1px solid var(--border-color, #E4E9F0); background: #fff;
    cursor: pointer; font-family: inherit;
}
.rp-pager button:disabled { opacity: .4; cursor: default; }
.rp-pager span { font-size: 13px; color: var(--text-muted, #98A2B3); }

/* 处置弹窗 */
.rp-modal {
    position: fixed; inset: 0; z-index: 9999;
    background: rgba(15,23,42,.42);
    display: none; align-items: center; justify-content: center; padding: 20px;
}
.rp-modal.show { display: flex; }
.rp-modal-box {
    background: #fff; border-radius: 16px; width: 100%; max-width: 440px;
    padding: 22px 24px; box-shadow: 0 20px 60px -20px rgba(16,24,40,.4);
}
.rp-modal-box h3 { font-size: 16px; margin: 0 0 6px; color: #1F2733; }
.rp-modal-hint { font-size: 12.5px; color: #98A2B3; margin-bottom: 14px; line-height: 1.7; }
.rp-modal-box textarea {
    width: 100%; min-height: 82px; padding: 10px 12px; font-size: 13.5px;
    border: 1px solid #E4E9F0; border-radius: 9px; font-family: inherit;
    resize: vertical; outline: none; line-height: 1.65;
}
.rp-modal-box textarea:focus { border-color: #2F5B9A; }
.rp-modal-actions { display: flex; gap: 10px; justify-content: flex-end; margin-top: 16px; }
.rp-modal-actions button {
    padding: 8px 18px; border-radius: 9px; font-size: 13.5px; font-weight: 600;
    cursor: pointer; font-family: inherit; border: 1px solid #E4E9F0; background: #fff;
}
.rp-modal-actions .ok { background: #2F5B9A; color: #fff; border-color: #2F5B9A; }
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
            <div class="rp-empty">加载中…</div>
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
            listEl.innerHTML = '<div class="rp-empty">'
                + (state.status === 'pending' ? '没有待处理的举报 🎉' : '没有符合条件的举报')
                + '</div>';
            pagerEl.style.display = 'none';
            return;
        }

        var html = '';
        state.items.forEach(function (r) {
            var cls = r.status === 'pending' ? 'is-pending' : 'is-handled';
            html += '<div class="rp-item ' + cls + '">';

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
        listEl.innerHTML = '<div class="rp-empty">加载中…</div>';
        var p = { action: 'list', status: state.status, page: state.page };
        if (state.keyword) p.keyword = state.keyword;

        api(p).then(function (d) {
            if (!d.success) {
                listEl.innerHTML = '<div class="rp-empty">' + esc(d.message || '加载失败') + '</div>';
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
            listEl.innerHTML = '<div class="rp-empty">网络错误，请刷新重试</div>';
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
