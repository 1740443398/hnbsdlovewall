<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/layout.php';

$adminUser = requireAdmin();
$adminUser = checkBanned($adminUser);
$csrfToken = generateCSRFToken();

$fs = getFS();

$isSuperAdmin = ($adminUser['role'] === 'super_admin');
$hasEditRules = $isSuperAdmin || checkPermission($adminUser, 'edit_rules');

$settings = $fs->read('settings');
$settingMap = [];
foreach ($settings as $s) {
    $settingMap[$s['setting_key']] = $s['setting_value'];
}

$sensitiveWords = $fs->read('sensitive_words');

adminHeader('站点设置', $adminUser, $csrfToken);
?>

<style>
.setting-section {
    background: var(--card-bg);
    border-radius: 12px;
    box-shadow: 0 1px 3px rgba(0,0,0,0.06);
    margin-bottom: 20px;
    overflow: hidden;
    border: 1px solid var(--border);
    transition: box-shadow 0.2s ease;
}
.setting-section:hover {
    box-shadow: 0 4px 12px rgba(0,0,0,0.08);
}
.setting-section-title {
    padding: 16px 24px;
    font-size: 15px;
    font-weight: 700;
    color: var(--text);
    border-bottom: 1px solid var(--border);
    display: flex;
    align-items: center;
    gap: 10px;
    background: linear-gradient(180deg, rgba(99,102,241,0.02) 0%, transparent 100%);
}
.setting-section-title .section-icon {
    width: 32px;
    height: 32px;
    border-radius: 8px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 16px;
}
.setting-section-title .section-icon.blue { background: #eef2ff; }
.setting-section-title .section-icon.purple { background: #f3e8ff; }
.setting-section-title .section-icon.green { background: #ecfdf5; }
.setting-section-title .section-icon.orange { background: #fff7ed; }
.setting-section-title .section-icon.red { background: #fef2f2; }
.setting-section-body { padding: 20px 24px; }
.setting-item {
    display: flex;
    align-items: flex-start;
    padding: 16px 0;
    border-bottom: 1px solid var(--border);
    gap: 20px;
}
.setting-item:last-child { border-bottom: none; }
.setting-item-label {
    width: 180px;
    min-width: 180px;
    padding-top: 4px;
}
.setting-item-label strong {
    display: block;
    font-size: 14px;
    color: var(--text);
    margin-bottom: 4px;
}
.setting-item-label small {
    font-size: 12px;
    color: var(--text-secondary);
    line-height: 1.5;
}
.setting-item-control {
    flex: 1;
    display: flex;
    align-items: center;
    gap: 10px;
    flex-wrap: wrap;
}
.setting-item-control.full { flex-direction: column; align-items: stretch; }
.setting-input {
    padding: 9px 14px;
    border: 1.5px solid var(--border);
    border-radius: 8px;
    font-size: 14px;
    color: var(--text);
    background: var(--card-bg);
    outline: none;
    transition: all 0.2s;
    font-family: inherit;
}
.setting-input:focus {
    border-color: var(--primary);
    background: var(--card-bg);
    box-shadow: 0 0 0 3px rgba(99,102,241,0.1);
}
.setting-input:hover {
    border-color: var(--primary);
    background: var(--bg);
}
.setting-input.wide { width: 100%; max-width: 500px; }
.setting-input.wider { width: 100%; max-width: 600px; }
.setting-input.small { width: 100px; }
.setting-input.medium { width: 160px; }
.setting-textarea {
    width: 100%;
    padding: 10px 14px;
    border: 1.5px solid var(--border);
    border-radius: 8px;
    font-size: 14px;
    color: var(--text);
    background: var(--card-bg);
    outline: none;
    resize: vertical;
    min-height: 100px;
    font-family: inherit;
    line-height: 1.6;
    transition: all 0.2s;
}
.setting-textarea:focus {
    border-color: var(--primary);
    background: var(--card-bg);
    box-shadow: 0 0 0 3px rgba(99,102,241,0.1);
}
.setting-textarea.mono { font-family: 'SF Mono', 'Cascadia Code', 'Consolas', monospace; font-size: 13px; }
.btn-save {
    padding: 9px 18px;
    background: var(--primary);
    color: #ffffff;
    border: none;
    border-radius: 8px;
    font-size: 13px;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.2s;
    white-space: nowrap;
    box-shadow: 0 2px 6px rgba(27,58,92,0.2);
}
.btn-save:hover { background: var(--primary-hover); }
.btn-save:active { transform: translateY(0); }
.btn-save.sm { padding: 6px 14px; font-size: 12px; }
.btn-save.danger { background: var(--danger); }
.btn-save.danger:hover { background: #a84e55; }
.btn-save.success { background: #3a7048; }
.btn-save.success:hover { background: #2f5f3c; }
.toggle-wrap {
    display: flex;
    align-items: center;
    gap: 10px;
}
.toggle-wrap .toggle-label {
    font-size: 14px;
    color: var(--text);
}
.sponsor-add-form {
    display: flex;
    gap: 10px;
    align-items: flex-end;
    flex-wrap: wrap;
    margin-bottom: 16px;
    padding: 16px;
    background: var(--bg);
    border-radius: 10px;
    border: 1px solid var(--border);
}
.sponsor-add-form .form-field { display: flex; flex-direction: column; gap: 4px; }
.sponsor-add-form .form-field label { font-size: 12px; font-weight: 600; color: var(--text-secondary); }
.sponsor-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 13px;
}
.sponsor-table th {
    padding: 10px 14px;
    text-align: left;
    background: var(--bg);
    font-weight: 600;
    color: var(--text-secondary);
    font-size: 12px;
    border-bottom: 1px solid var(--border);
}
.sponsor-table td {
    padding: 10px 14px;
    border-bottom: 1px solid var(--border);
}
.sponsor-table tr:hover td { background: var(--bg); }
@media (max-width: 768px) {
    .setting-item { flex-direction: column; gap: 8px; }
    .setting-item-label { width: 100%; min-width: 100%; }
}
</style>

<div class="setting-section">
    <div class="setting-section-title">
        <span class="section-icon blue">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#6366f1" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1 0 2.83 2 2 0 0 1-2.83 0l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-2 2 2 2 0 0 1-2-2v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83 0 2 2 0 0 1 0-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1-2-2 2 2 0 0 1 2-2h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 0-2.83 2 2 0 0 1 2.83 0l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 2-2 2 2 0 0 1 2 2v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 0 2 2 0 0 1 0 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 2 2 2 2 0 0 1-2 2h-.09a1.65 1.65 0 0 0-1.51 1z"/></svg>
        </span>
        基本设置
    </div>
    <div class="setting-section-body">
        <div class="setting-item">
            <div class="setting-item-label">
                <strong>站点名称</strong>
                <small>显示在首页顶部的标题</small>
            </div>
            <div class="setting-item-control">
                <input type="text" id="siteName" class="setting-input wide" value="<?= htmlspecialchars($settingMap['site_name'] ?? '') ?>">
                <button class="btn-save" onclick="saveSetting('site_name', document.getElementById('siteName').value)">保存</button>
            </div>
        </div>
        <div class="setting-item">
            <div class="setting-item-label">
                <strong>置顶公告</strong>
                <small>首页滚动公告内容</small>
            </div>
            <div class="setting-item-control full">
                <textarea id="announcement" class="setting-textarea" rows="3"><?= htmlspecialchars($settingMap['announcement'] ?? '') ?></textarea>
                <button class="btn-save" onclick="saveSetting('announcement', document.getElementById('announcement').value)">保存</button>
            </div>
        </div>
    </div>
</div>

<?php if ($isSuperAdmin): ?>
<div class="setting-section">
    <div class="setting-section-title">
        <span class="section-icon red">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#ef4444" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
        </span>
        全站控制（仅超管）
    </div>
    <div class="setting-section-body">
        <div class="setting-item">
            <div class="setting-item-label">
                <strong>维护模式</strong>
                <small>开启后仅管理员可访问</small>
            </div>
            <div class="setting-item-control">
                <label class="toggle-switch">
                    <input type="checkbox" id="maintenanceMode" <?= ($settingMap['maintenance_mode'] ?? '0') == '1' ? 'checked' : '' ?> onchange="saveSetting('maintenance_mode', this.checked ? '1' : '0')">
                    <span class="toggle-slider"></span>
                </label>
                <span class="toggle-label" id="maintenanceStatus"><?= ($settingMap['maintenance_mode'] ?? '0') == '1' ? '已开启' : '已关闭' ?></span>
            </div>
        </div>
        <div class="setting-item">
            <div class="setting-item-label">
                <strong>维护提示语</strong>
                <small>维护时展示给用户的提示</small>
            </div>
            <div class="setting-item-control">
                <input type="text" id="maintenanceMessage" class="setting-input wide" value="<?= htmlspecialchars($settingMap['maintenance_message'] ?? '网站正在维护中，请稍后再来。') ?>">
                <button class="btn-save" onclick="saveSetting('maintenance_message', document.getElementById('maintenanceMessage').value)">保存</button>
            </div>
        </div>
        <div class="setting-item">
            <div class="setting-item-label">
                <strong>开放注册</strong>
                <small>关闭后禁止新用户注册</small>
            </div>
            <div class="setting-item-control">
                <label class="toggle-switch">
                    <input type="checkbox" id="registerEnabled" <?= ($settingMap['register_enabled'] ?? '1') == '1' ? 'checked' : '' ?> onchange="saveSetting('register_enabled', this.checked ? '1' : '0')">
                    <span class="toggle-slider"></span>
                </label>
                <span class="toggle-label" id="registerStatus"><?= ($settingMap['register_enabled'] ?? '1') == '1' ? '已开启' : '已关闭' ?></span>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<div class="setting-section">
    <div class="setting-section-title">
        <span class="section-icon orange">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#f59e0b" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg>
        </span>
        赞助管理
    </div>
    <div class="setting-section-body">
        <div class="setting-item">
            <div class="setting-item-label">
                <strong>当前收款金额</strong>
                <small>对外展示的赞助总额</small>
            </div>
            <div class="setting-item-control">
                <input type="number" id="sponsorAmount" class="setting-input medium" step="0.01" min="0" value="0.00">
                <span style="font-size:14px;color:var(--text-secondary)">元</span>
                <button class="btn-save" onclick="updateSponsorAmount()">更新金额</button>
            </div>
        </div>
        <div class="setting-item">
            <div class="setting-item-label">
                <strong>添加赞助者</strong>
                <small>输入赞助者QQ号，自动获取站内昵称</small>
            </div>
            <div class="setting-item-control full">
                <div class="sponsor-add-form">
                    <div class="form-field">
                        <label>赞助者QQ号</label>
                        <input type="text" id="sponsorQQ" class="setting-input wide" placeholder="如：123456789" style="max-width:200px;">
                    </div>
                    <div class="form-field">
                        <label>赞助金额</label>
                        <input type="number" id="sponsorAddAmount" class="setting-input medium" step="0.01" min="0.01" placeholder="10.00">
                    </div>
                    <button class="btn-save success" onclick="addSponsor()" style="margin-bottom:0;">添加赞助者</button>
                </div>
                <div class="table-wrapper" style="margin-top:12px;">
                    <table class="sponsor-table">
                        <thead>
                            <tr><th>#</th><th>QQ号</th><th>昵称</th><th>金额</th><th>操作</th></tr>
                        </thead>
                        <tbody id="sponsorTableBody">
                            <tr><td colspan="5" style="text-align:center;color:var(--text-secondary);padding:20px;">加载中...</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<?php if ($hasEditRules): ?>
<div class="setting-section">
    <div class="setting-section-title">
        <span class="section-icon green">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#10b981" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
        </span>
        社区规范与敏感词
    </div>
    <div class="setting-section-body">
        <div class="setting-item">
            <div class="setting-item-label">
                <strong>社区规范</strong>
                <small>用户首次进入网站时显示的规则</small>
            </div>
            <div class="setting-item-control full">
                <textarea id="communityRules" class="setting-textarea" rows="5"><?= htmlspecialchars($settingMap['community_rules'] ?? '') ?></textarea>
                <button class="btn-save" onclick="saveSetting('community_rules', document.getElementById('communityRules').value)">保存</button>
            </div>
        </div>
        <div class="setting-item">
            <div class="setting-item-label">
                <strong>敏感词列表</strong>
                <small>每行一个，发帖/评论包含敏感词进入审核</small>
            </div>
            <div class="setting-item-control full">
                <textarea id="sensitiveWords" class="setting-textarea mono" rows="6"><?= implode("\n", array_column($sensitiveWords, 'word')) ?></textarea>
                <button class="btn-save" onclick="saveSensitiveWords()">保存敏感词</button>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<script>
async function saveSetting(key, value) {
    try {
        const res = await fetch(SITE_URL + '/api/admin/settings.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: new URLSearchParams({ csrf_token: CSRF_TOKEN, action: 'save', key, value })
        });
        const data = await res.json();
        if (data.success) {
            showToast('保存成功');
            if (key === 'maintenance_mode') {
                document.getElementById('maintenanceStatus').textContent = value === '1' ? '已开启' : '已关闭';
            }
            if (key === 'register_enabled') {
                document.getElementById('registerStatus').textContent = value === '1' ? '已开启' : '已关闭';
            }
        } else {
            showToast(data.message || '保存失败', 'error');
        }
    } catch(e) {
        showToast('网络错误', 'error');
    }
}

async function saveSensitiveWords() {
    const words = document.getElementById('sensitiveWords').value;
    try {
        const res = await fetch(SITE_URL + '/api/admin/settings.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: new URLSearchParams({ csrf_token: CSRF_TOKEN, action: 'save_words', words })
        });
        const data = await res.json();
        if (data.success) showToast('保存成功');
        else showToast(data.message || '保存失败', 'error');
    } catch(e) {
        showToast('网络错误', 'error');
    }
}

function loadSponsorData() {
    fetch(SITE_URL + '/api/sponsor.php')
        .then(r => r.json())
        .then(data => {
            if (data.success && data.data) {
                document.getElementById('sponsorAmount').value = data.data.current_amount || 0;
                var list = data.data.sponsor_list || [];
                var tbody = document.getElementById('sponsorTableBody');
                if (list.length === 0) {
                    tbody.innerHTML = '<tr><td colspan="5" style="text-align:center;color:var(--text-secondary);padding:20px;">暂无赞助记录</td></tr>';
                } else {
                    tbody.innerHTML = list.map(function(item, idx) {
                        return '<tr><td>' + (idx + 1) + '</td><td>' + (item.qq || '未知') + '</td><td><strong>' + (item.name || '匿名') + '</strong></td><td>' + parseFloat(item.amount || 0).toFixed(2) + ' 元</td><td><button class="btn-save danger sm" onclick="deleteSponsor(' + idx + ')" style="padding:4px 10px;font-size:11px;">删除</button></td></tr>';
                    }).join('');
                }
            }
        })
        .catch(() => {});
}

async function addSponsor() {
    var qq = document.getElementById('sponsorQQ').value.trim();
    var amount = parseFloat(document.getElementById('sponsorAddAmount').value);
    if (!qq) { showToast('请输入赞助者QQ号', 'error'); return; }
    if (!amount || amount <= 0) { showToast('请输入有效金额', 'error'); return; }
    try {
        const res = await fetch(SITE_URL + '/api/admin/sponsor.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: new URLSearchParams({ csrf_token: CSRF_TOKEN, action: 'add_sponsor', qq, amount })
        });
        const data = await res.json();
        if (data.success) {
            showToast('添加成功：' + (data.data.name || '') + ' ' + amount + '元');
            document.getElementById('sponsorQQ').value = '';
            document.getElementById('sponsorAddAmount').value = '';
            loadSponsorData();
        } else {
            showToast(data.message || '添加失败', 'error');
        }
    } catch(e) {
        showToast('网络错误', 'error');
    }
}

async function deleteSponsor(index) {
    if (!confirm('确定删除该赞助记录？')) return;
    try {
        const res = await fetch(SITE_URL + '/api/admin/sponsor.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: new URLSearchParams({ csrf_token: CSRF_TOKEN, action: 'delete_sponsor', index })
        });
        const data = await res.json();
        if (data.success) {
            showToast('已删除');
            loadSponsorData();
        } else {
            showToast(data.message || '删除失败', 'error');
        }
    } catch(e) {
        showToast('网络错误', 'error');
    }
}

async function updateSponsorAmount() {
    var amount = document.getElementById('sponsorAmount').value;
    try {
        const res = await fetch(SITE_URL + '/api/admin/sponsor.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: new URLSearchParams({ csrf_token: CSRF_TOKEN, action: 'update', current_amount: amount })
        });
        const data = await res.json();
        if (data.success) showToast('金额更新成功');
        else showToast(data.message || '更新失败', 'error');
    } catch(e) {
        showToast('网络错误', 'error');
    }
}

loadSponsorData();
</script>

<?php adminFooter(); ?>
