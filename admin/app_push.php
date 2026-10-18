<?php
/**
 * 后台：向已安装 App 的用户推送系统通知
 * ---------------------------------------------------------------------------
 * 投递机制（务必先读，它决定了这个页面的能力边界）：
 *
 *     写一条推送 → 进 data/app_pushes.json 队列
 *       → App 的后台任务按系统允许的最短周期（15 分钟）请求 api/app_report.php
 *       → 接口顺带下发「游标之后」的推送，App 就地弹一条系统通知
 *
 * 也就是说：创建成功 = 已入队，**不是**已送达。手机息屏、系统省电策略、
 * 用户手动限制后台，都会让轮询变慢甚至暂停。所以这里只适合「站务公告级」
 * 的通知（新功能上线、活动开始、维护公告），不要拿它当即时聊天提醒用 ——
 * 实时的那条由未读数轮询 + 应用角标承担。
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/layout.php';

$adminUser = requireAdmin();
$adminUser = checkBanned($adminUser);
$csrfToken = generateCSRFToken();

if (!checkPermission($adminUser, 'manage_app_push')) {
    header('Location: /admin/index.php');
    exit;
}

adminHeader('App 系统通知推送', $adminUser, $csrfToken);
?>

<div class="section">
    <div class="section-header">
        <h3>推送可达设备</h3>
    </div>
    <div class="section-body">
        <div style="display:flex;flex-wrap:wrap;gap:28px;align-items:baseline;">
            <div><span style="font-size:26px;font-weight:700;" id="pTotal">-</span> <span style="color:var(--text-secondary);font-size:13px;">台累计装机</span></div>
            <div><span style="font-size:26px;font-weight:700;" id="pOnline">-</span> <span style="color:var(--text-secondary);font-size:13px;">台当前在线</span></div>
            <div><span style="font-size:26px;font-weight:700;" id="pBound">-</span> <span style="color:var(--text-secondary);font-size:13px;">台已绑定账号</span></div>
        </div>
        <p style="margin-top:14px;color:var(--text-secondary);font-size:13px;line-height:1.7;">
            通知不会立刻到达 —— App 按系统允许的最短周期（15 分钟）向服务器拉取一次新推送，届时才弹出通知。
            因此本功能定位为「站务公告」，不承担即时提醒。
            <a href="/admin/app_users.php" style="color:var(--primary);">查看装机台账 →</a>
        </p>
    </div>
</div>

<div class="section">
    <div class="section-header"><h3>新建推送</h3></div>
    <div class="section-body">
        <div class="form-group" style="margin-bottom:16px;">
            <label for="fTitle">通知标题</label>
            <input type="text" id="fTitle" maxlength="40" placeholder="例如：暑期活动开始啦" oninput="countChars('fTitle','cTitle',40)">
            <div class="error-hint" id="cTitle" style="color:var(--text-secondary);">0 / 40</div>
        </div>

        <div class="form-group" style="margin-bottom:16px;">
            <label for="fContent">通知内容</label>
            <textarea id="fContent" rows="4" maxlength="300" placeholder="通知栏里会显示这段文字，建议控制在两行以内。" style="width:100%;resize:vertical;" oninput="countChars('fContent','cContent',300)"></textarea>
            <div class="error-hint" id="cContent" style="color:var(--text-secondary);">0 / 300</div>
        </div>

        <div class="form-group" style="margin-bottom:16px;">
            <label for="fUrl">点击通知后跳转（可选，站内相对路径）</label>
            <div style="display:flex;gap:8px;flex-wrap:wrap;">
                <input type="text" id="fUrl" maxlength="200" placeholder="/pages/post_detail.php?id=12" style="flex:1;min-width:220px;">
                <select id="fUrlPreset" onchange="applyPreset(this.value)" style="width:auto;">
                    <option value="">常用页面…</option>
                    <option value="/">首页</option>
                    <option value="/pages/ranking.php">排行榜</option>
                    <option value="/pages/growth.php">成长中心</option>
                    <option value="/pages/tools.php">工具箱</option>
                    <option value="/pages/user_center.php">个人中心</option>
                    <option value="/pages/ai_assistant.php">AI 助手</option>
                </select>
            </div>
            <div class="error-hint" style="color:var(--text-secondary);">只能填站内路径（以 / 开头），留空则点击通知只打开 App 首页。</div>
        </div>

        <div class="form-group" style="margin-bottom:16px;">
            <label>推送范围</label>
            <div style="display:flex;gap:20px;align-items:center;flex-wrap:wrap;margin-top:6px;">
                <label style="display:flex;align-items:center;gap:6px;cursor:pointer;">
                    <input type="radio" name="fTargetMode" value="all" checked onchange="toggleTarget()" style="width:auto;"> 全体已装 App 的用户
                </label>
                <label style="display:flex;align-items:center;gap:6px;cursor:pointer;">
                    <input type="radio" name="fTargetMode" value="some" onchange="toggleTarget()" style="width:auto;"> 仅指定用户
                </label>
            </div>
            <div id="targetWrap" style="display:none;margin-top:10px;">
                <input type="text" id="fTarget" placeholder="用户 ID，多个用英文逗号分隔，例如 3,15,42" maxlength="1000">
                <div class="error-hint" style="color:var(--text-secondary);">用户 ID 可在「用户管理」里查看；最多 200 个。未登录 App 的设备收不到定向推送。</div>
            </div>
        </div>

        <button type="button" class="btn btn-primary" id="sendBtn" onclick="sendPush()">创建并加入推送队列</button>
    </div>
</div>

<div class="section">
    <div class="section-header">
        <h3>推送历史</h3>
        <button type="button" class="btn btn-outline btn-sm" onclick="confirmClear()" id="clearBtn" style="display:none;">清空历史</button>
    </div>
    <div class="section-body">
        <div class="table-wrapper">
            <table>
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>标题</th>
                        <th>内容</th>
                        <th>跳转</th>
                        <th>范围</th>
                        <th>发送人</th>
                        <th>创建时间</th>
                        <th>操作</th>
                    </tr>
                </thead>
                <tbody id="pushBody">
                    <tr><td colspan="8" class="loading-spinner">加载中</td></tr>
                </tbody>
            </table>
        </div>
    </div>
</div>

<div class="modal-overlay" id="confirmModal">
    <div class="modal">
        <div class="modal-header">
            <h3>确认操作</h3>
            <button type="button" class="modal-close" onclick="closeModal('confirmModal')" aria-label="关闭">×</button>
        </div>
        <div class="modal-body"><p id="confirmMessage"></p></div>
        <div class="modal-footer">
            <button type="button" class="btn btn-outline" onclick="closeModal('confirmModal')">取消</button>
            <button type="button" class="btn btn-danger" id="confirmBtn">确认</button>
        </div>
    </div>
</div>

<script>
(function () {
    function fmtTime(t) {
        if (!t) return '-';
        var s = String(t);
        if (/^\d+$/.test(s)) {
            var d = new Date(Number(s) * 1000);
            function p(n) { return n < 10 ? '0' + n : '' + n; }
            return d.getFullYear() + '-' + p(d.getMonth() + 1) + '-' + p(d.getDate()) + ' ' + p(d.getHours()) + ':' + p(d.getMinutes());
        }
        return s.substring(0, 16).replace('T', ' ');
    }

    function api(action, extra) {
        var body = new URLSearchParams({ csrf_token: CSRF_TOKEN, action: action });
        Object.keys(extra || {}).forEach(function (k) { body.append(k, extra[k]); });
        return fetch(SITE_URL + '/api/admin/app_push.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: body
        }).then(function (r) { return r.json(); });
    }

    function loadStats() {
        api('stats').then(function (res) {
            if (!res.success) return;
            var st = res.data.stats || {};
            document.getElementById('pTotal').textContent = st.total || 0;
            document.getElementById('pOnline').textContent = st.online || 0;
            document.getElementById('pBound').textContent = st.bound || 0;
        }).catch(function () {});
    }

    function loadList() {
        api('list').then(function (res) {
            var tbody = document.getElementById('pushBody');
            if (!res.success) {
                tbody.innerHTML = '<tr><td colspan="8"><div class="empty-state"><p>加载失败：' + esc(res.message || '数据异常') + '</p></div></td></tr>';
                return;
            }
            var list = res.data.pushes || [];
            document.getElementById('clearBtn').style.display = list.length ? '' : 'none';
            if (!list.length) {
                tbody.innerHTML = '<tr><td colspan="8"><div class="empty-state"><div class="empty-icon"><svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/></svg></div><p>还没有推送过。上面写好标题和内容，创建一条试试。</p></div></td></tr>';
                return;
            }
            tbody.innerHTML = list.map(function (p) {
                var target = (p.target || '') === ''
                    ? '<span style="color:var(--success);">全体用户</span>'
                    : '<span title="' + esc(p.target) + '" style="color:var(--warning);">指定 ' + String(p.target).split(',').length + ' 人</span>';
                var content = String(p.content || '');
                var short = content.length > 36 ? content.slice(0, 36) + '…' : content;
                return '<tr>' +
                    '<td>' + esc(p.id || 0) + '</td>' +
                    '<td><strong>' + esc(p.title || '') + '</strong></td>' +
                    '<td title="' + esc(content) + '">' + esc(short) + '</td>' +
                    '<td>' + (p.url ? '<code>' + esc(p.url) + '</code>' : '<span style="color:var(--text-secondary);">—</span>') + '</td>' +
                    '<td>' + target + '</td>' +
                    '<td>' + esc(p.admin_name || '—') + '</td>' +
                    '<td>' + fmtTime(p.created_at) + '</td>' +
                    '<td><button type="button" class="btn btn-danger btn-sm" onclick="confirmDelete(' + (p.id || 0) + ', \'' + esc(String(p.title || '')).replace(/'/g, "\\'") + '\')">删除</button></td>' +
                    '</tr>';
            }).join('');
        }).catch(function () {
            document.getElementById('pushBody').innerHTML = '<tr><td colspan="8"><div class="empty-state"><p>加载失败，请检查网络后重试</p><button type="button" class="btn btn-outline btn-sm" onclick="location.reload()">重试</button></div></td></tr>';
        });
    }

    window.countChars = function (inputId, hintId, max) {
        var n = document.getElementById(inputId).value.length;
        var el = document.getElementById(hintId);
        el.textContent = n + ' / ' + max;
        el.style.color = n > max ? 'var(--danger)' : 'var(--text-secondary)';
    };

    window.applyPreset = function (v) {
        if (v) document.getElementById('fUrl').value = v;
        document.getElementById('fUrlPreset').value = '';
    };

    window.toggleTarget = function () {
        var mode = document.querySelector('input[name="fTargetMode"]:checked').value;
        document.getElementById('targetWrap').style.display = mode === 'some' ? '' : 'none';
    };

    window.sendPush = function () {
        var title = document.getElementById('fTitle').value.trim();
        var content = document.getElementById('fContent').value.trim();
        var url = document.getElementById('fUrl').value.trim();

        if (!title) { showToast('请填写通知标题', 'error'); return; }
        if (!content) { showToast('请填写通知内容', 'error'); return; }
        // 服务端才是权威校验，这里只是让用户早点看到错误。
        // `//host` 是协议相对 URL，同样以 / 开头，必须一起挡掉（否则等于开了个外跳口子）。
        if (url && (url.charAt(0) !== '/' || url.charAt(1) === '/')) { showToast('跳转地址必须是站内路径（以单个 / 开头）', 'error'); return; }

        var mode = document.querySelector('input[name="fTargetMode"]:checked').value;
        var target = '';
        if (mode === 'some') {
            var raw = document.getElementById('fTarget').value.trim();
            if (!raw) { showToast('请填写要推送的用户 ID', 'error'); return; }
            var ids = raw.split(/[,，\s]+/).filter(function (x) { return x !== ''; });
            var bad = ids.filter(function (x) { return !/^\d+$/.test(x) || Number(x) <= 0; });
            if (bad.length) { showToast('用户 ID 必须是正整数：' + bad.slice(0, 3).join(', '), 'error'); return; }
            target = ids.join(',');
        }

        var btn = document.getElementById('sendBtn');
        btn.disabled = true;
        btn.textContent = '提交中…';
        api('create', { title: title, content: content, url: url, target: target }).then(function (res) {
            btn.disabled = false;
            btn.textContent = '创建并加入推送队列';
            if (!res.success) { showToast(res.message || '创建失败', 'error'); return; }
            showToast(res.message || '推送已创建');
            document.getElementById('fTitle').value = '';
            document.getElementById('fContent').value = '';
            document.getElementById('fUrl').value = '';
            document.getElementById('fTarget').value = '';
            window.countChars('fTitle', 'cTitle', 40);
            window.countChars('fContent', 'cContent', 300);
            loadList();
        }).catch(function () {
            btn.disabled = false;
            btn.textContent = '创建并加入推送队列';
            showToast('网络错误', 'error');
        });
    };

    window.confirmDelete = function (id, title) {
        document.getElementById('confirmMessage').textContent = '确定删除推送「' + title + '」吗？已收到该推送的用户不受影响，只是记录会被移除。';
        document.getElementById('confirmBtn').onclick = function () {
            api('delete', { id: id }).then(function (res) {
                if (res.success) { showToast('已删除'); closeModal('confirmModal'); loadList(); }
                else { showToast(res.message || '删除失败', 'error'); }
            }).catch(function () { showToast('网络错误', 'error'); });
        };
        openModal('confirmModal');
    };

    window.confirmClear = function () {
        document.getElementById('confirmMessage').textContent = '确定清空全部推送历史吗？此操作不可恢复。';
        document.getElementById('confirmBtn').onclick = function () {
            api('clear').then(function (res) {
                if (res.success) { showToast(res.message || '已清空'); closeModal('confirmModal'); loadList(); }
                else { showToast(res.message || '清空失败', 'error'); }
            }).catch(function () { showToast('网络错误', 'error'); });
        };
        openModal('confirmModal');
    };

    loadStats();
    loadList();
})();
</script>

<?php adminFooter(); ?>
