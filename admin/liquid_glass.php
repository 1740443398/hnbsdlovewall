<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/layout.php';

$adminUser = requireAdmin();
$adminUser = checkBanned($adminUser);
$csrfToken = generateCSRFToken();

if (!checkPermission($adminUser, 'manage_liquid_glass')) {
    header('Location: /admin/index.php');
    exit;
}

$fs = getFS();
$map = [];
foreach ((array)$fs->read('settings') as $s) {
    $map[$s['setting_key']] = $s['setting_value'];
}

adminHeader('液态玻璃管控', $adminUser, $csrfToken);
?>

<div class="setting-section">
    <div class="setting-section-title">
        <span class="section-icon blue">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#3b82f6" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 21c-5 0-9-3.6-9-8 0-3.6 3.6-9 9-9s9 5.4 9 9c0 1.4-1.1 2.4-2.4 2.4h-1.7c-.8 0-1.4.6-1.4 1.4 0 .3.1.6.3.9.2.3.3.6.3 1 0 1.1-1.1 2.3-3.1 2.3z"/></svg>
        </span>
        全局液态玻璃效果
    </div>
    <div class="setting-section-body">
        <div class="setting-item">
            <div class="setting-item-label">
                <strong>全局开放</strong>
                <small>关闭后所有用户都无法启用液态玻璃（前台强制回退）</small>
            </div>
            <div class="setting-item-control">
                <label class="toggle-switch">
                    <input type="checkbox" id="lgEnabled" <?= ($map['lg_enabled'] ?? '1') === '1' ? 'checked' : '' ?> onchange="saveLg()">
                    <span class="toggle-slider"></span>
                </label>
                <span class="toggle-label" id="lgEnabledStatus"><?= ($map['lg_enabled'] ?? '1') === '1' ? '已开启' : '已关闭' ?></span>
            </div>
        </div>
        <div class="setting-item">
            <div class="setting-item-label">
                <strong>强制关闭</strong>
                <small>临时全站降级：忽略用户设置，一律回退 MIUIX 主题（应急用）</small>
            </div>
            <div class="setting-item-control">
                <label class="toggle-switch">
                    <input type="checkbox" id="lgForceOff" <?= ($map['lg_force_off'] ?? '0') === '1' ? 'checked' : '' ?> onchange="saveLg()">
                    <span class="toggle-slider"></span>
                </label>
                <span class="toggle-label" id="lgForceOffStatus"><?= ($map['lg_force_off'] ?? '0') === '1' ? '已开启' : '已关闭' ?></span>
            </div>
        </div>
        <div class="setting-item">
            <div class="setting-item-label">
                <strong>流体速度倍率</strong>
                <small>0.2 ~ 3.0，默认 1.0；越大流体运动越快</small>
            </div>
            <div class="setting-item-control">
                <input type="number" id="lgSpeed" class="setting-input medium" step="0.1" min="0.2" max="3" value="<?= htmlspecialchars($map['lg_speed'] ?? '1') ?>">
                <button class="btn-save" onclick="saveLg()">保存</button>
            </div>
        </div>
        <div class="setting-item">
            <div class="setting-item-label">
                <strong>光强倍率</strong>
                <small>0.0 ~ 2.0，默认 1.0；越大背景光晕越明显</small>
            </div>
            <div class="setting-item-control">
                <input type="number" id="lgLight" class="setting-input medium" step="0.1" min="0" max="2" value="<?= htmlspecialchars($map['lg_light'] ?? '1') ?>">
                <button class="btn-save" onclick="saveLg()">保存</button>
            </div>
        </div>
    </div>
</div>

<div class="setting-section">
    <div class="setting-section-title">
        <span class="section-icon purple">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#8b5cf6" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 3v18h18"/><path d="M18 17V9M13 17V5M8 17v-3"/></svg>
        </span>
        用户分项开关使用统计
    </div>
    <div class="setting-section-body">
        <div class="table-wrapper">
            <table class="sponsor-table">
                <thead>
                    <tr><th>分项</th><th>开启次数</th><th>关闭次数</th><th>开启占比</th></tr>
                </thead>
                <tbody id="lgStatsBody">
                    <tr><td colspan="4" style="text-align:center;color:var(--text-secondary);padding:20px;">加载中...</td></tr>
                </tbody>
            </table>
        </div>
    </div>
</div>

<script>
(async function () {
    async function load() {
        try {
            const res = await fetch(SITE_URL + '/api/admin/liquid_glass.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: new URLSearchParams({ csrf_token: CSRF_TOKEN, action: 'overview' })
            });
            const data = await res.json();
            if (!data.success) { showToast(data.message || '加载失败', 'error'); return; }
            const labelMap = { master: '全局总开关', bgFluid: '背景流体', dockFluid: 'Dock 流体光', gloss: '组件玻璃材质' };
            const tbody = document.getElementById('lgStatsBody');
            const rows = (data.data.stats || []).map(function (it) {
                const total = it.on + it.off;
                const pct = total ? Math.round(it.on / total * 100) : 0;
                return '<tr><td><strong>' + (labelMap[it.key] || it.key) + '</strong></td><td>' + it.on + '</td><td>' + it.off + '</td><td>' + pct + '%</td></tr>';
            });
            tbody.innerHTML = rows.length ? rows.join('') : '<tr><td colspan="4" style="text-align:center;color:var(--text-secondary);padding:20px;">暂无上报数据</td></tr>';
        } catch (e) { showToast('网络错误', 'error'); }
    }
    window.saveLg = async function () {
        const body = new URLSearchParams({
            csrf_token: CSRF_TOKEN,
            action: 'save',
            enabled: document.getElementById('lgEnabled').checked ? '1' : '0',
            force_off: document.getElementById('lgForceOff').checked ? '1' : '0',
            speed: document.getElementById('lgSpeed').value,
            light: document.getElementById('lgLight').value
        });
        try {
            const res = await fetch(SITE_URL + '/api/admin/liquid_glass.php', { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body });
            const data = await res.json();
            if (data.success) {
                showToast('已保存');
                document.getElementById('lgEnabledStatus').textContent = document.getElementById('lgEnabled').checked ? '已开启' : '已关闭';
                document.getElementById('lgForceOffStatus').textContent = document.getElementById('lgForceOff').checked ? '已开启' : '已关闭';
            } else { showToast(data.message || '保存失败', 'error'); }
        } catch (e) { showToast('网络错误', 'error'); }
    };
    load();
})();
</script>

<?php adminFooter(); ?>
