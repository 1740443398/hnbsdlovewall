<?php
/**
 * 后台「AI 设置」——配置站内默认 AI（模型 / 端点 / Key / 开关）
 *
 * 取值优先级（详见 includes/site_ai_config.php）：
 *   data/site_ai_config.json（本页写入） > config/ai_config.php（部署兜底） > 内置默认
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../includes/ai_client.php';
require_once __DIR__ . '/../includes/site_ai_config.php';
require_once __DIR__ . '/layout.php';

$adminUser = requireAdmin();
$adminUser = checkBanned($adminUser);
$csrfToken = generateCSRFToken();

if ($adminUser['role'] !== 'super_admin') {
    http_response_code(403);
    die('403 Forbidden - 仅超级管理员可访问');
}

$cur = lwSiteAiPublic();
$providers = lwUserAiProviders();

adminHeader('AI 设置', $adminUser, $csrfToken);
?>

<div class="section">
    <div class="section-header"><h3>站点默认 AI</h3></div>
    <div class="section-body">
        <div id="aiStatusBar" style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;padding:10px 14px;border-radius:10px;margin-bottom:16px;font-size:13px;background:var(--bg-secondary);">
            <span>当前生效来源：</span>
            <strong id="aiSourceLabel">-</strong>
            <span style="color:var(--text-secondary);">·</span>
            <span>可用状态：</span>
            <strong id="aiReadyLabel">-</strong>
            <span style="color:var(--text-secondary);">·</span>
            <span>Key：</span>
            <strong id="aiKeyLabel">-</strong>
        </div>

        <div class="form-group">
            <label>启用站内 AI 助手</label>
            <label style="display:inline-flex;align-items:center;gap:8px;font-weight:400;">
                <input type="checkbox" id="fEnabled"> 开启（关闭后所有用户的默认 AI 助手将暂停服务）
            </label>
        </div>

        <div class="form-group">
            <label>服务商</label>
            <select id="fProvider">
                <?php foreach ($providers as $key => $p): ?>
                <option value="<?= htmlspecialchars($key) ?>"><?= htmlspecialchars($p['label']) ?></option>
                <?php endforeach; ?>
            </select>
            <div style="font-size:12px;color:var(--text-secondary);margin-top:4px;" id="providerHint"></div>
        </div>

        <div class="form-group">
            <label>接口地址（OpenAI 兼容的 /chat/completions，必须 https）</label>
            <input type="text" id="fBaseUrl" placeholder="https://open.bigmodel.cn/api/paas/v4/chat/completions">
        </div>

        <div class="form-group">
            <label>模型名</label>
            <input type="text" id="fModel" placeholder="glm-4.1v-thinking-flash">
        </div>

        <div class="form-group">
            <label>API Key</label>
            <input type="password" id="fApiKey" placeholder="留空 = 沿用已保存的 Key" autocomplete="new-password">
            <div style="font-size:12px;color:var(--text-secondary);margin-top:4px;">
                已保存：<span id="keyHint">-</span>（出于安全考虑不回显原文，留空即不修改）
            </div>
        </div>

        <div class="form-group">
            <label>备用 Key（可选，一行一个，最多 5 把）</label>
            <textarea id="fApiKeyExtra" rows="3" placeholder="用于多 Key 轮换，避免单 Key 额度耗尽后整站 AI 瘫痪"></textarea>
            <div style="font-size:12px;color:var(--text-secondary);margin-top:4px;">
                请求时会随机选起点、遇额度问题自动换下一把（与 config 文件里的 api_keys 行为一致）。
            </div>
        </div>

        <div class="form-group">
            <label>max_tokens（0 = 不限）</label>
            <input type="number" id="fMaxTokens" min="0" max="32000" value="0">
        </div>

        <div style="display:flex;gap:10px;flex-wrap:wrap;margin-top:16px;">
            <button class="btn btn-primary" onclick="saveAI()">保存设置</button>
            <button class="btn btn-outline" onclick="testAI()">测试连通性</button>
        </div>

        <div id="testResult" style="margin-top:14px;"></div>
    </div>
</div>

<div class="section">
    <div class="section-header"><h3>说明</h3></div>
    <div class="section-body">
        <ul style="font-size:13px;line-height:2;color:var(--text-secondary);margin:0;padding-left:20px;">
            <li>取值优先级：<strong>本页配置</strong> → <code>config/ai_config.php</code> → 内置默认。本页只填模型名时，Key 仍会自动沿用配置文件里的。</li>
            <li>API Key 以 AES-256-CBC 密文存放在 <code>data/site_ai_config.json</code>，接口返回一律脱敏。</li>
            <li>接口地址强制 https 公网域名：禁止 IP、内网地址与非 443 端口（防 SSRF）。</li>
            <li>此设置只影响<strong>站点默认模型</strong>；用户在「AI 助手 → 高级选项」里自带模型时不受影响。</li>
        </ul>
    </div>
</div>

<script>
var PROVIDERS = <?= json_encode(array_map(function($p) { return ['base_url' => $p['base_url'], 'model' => $p['model'], 'hint' => $p['hint']]; }, $providers), JSON_UNESCAPED_UNICODE) ?>;

function post(action, extra) {
    var body = new URLSearchParams({ csrf_token: CSRF_TOKEN, action: action });
    Object.keys(extra || {}).forEach(function(k) { body.append(k, extra[k]); });
    return fetch(SITE_URL + '/api/admin/ai_settings.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: body
    }).then(function(r) { return r.json(); });
}

var SOURCE_LABEL = { admin: '后台配置', file: 'config 文件', builtin: '内置默认' };

function fillForm(d) {
    document.getElementById('fEnabled').checked = !!d.enabled;
    document.getElementById('fProvider').value = d.provider || 'custom';
    document.getElementById('fBaseUrl').value = d.base_url || '';
    document.getElementById('fModel').value = d.model || '';
    document.getElementById('fMaxTokens').value = d.max_tokens || 0;
    document.getElementById('keyHint').textContent = d.key_hint || '（未配置）';
    document.getElementById('aiSourceLabel').textContent = SOURCE_LABEL[d.source] || d.source || '-';
    document.getElementById('aiKeyLabel').textContent = d.has_key ? (d.key_count + ' 把') : '未配置';
    var ready = document.getElementById('aiReadyLabel');
    ready.textContent = d.ready ? '可用' : '不可用';
    ready.style.color = d.ready ? '#2E8B57' : '#cb2431';
    onProviderChange();
}

function onProviderChange() {
    var p = PROVIDERS[document.getElementById('fProvider').value];
    var hint = document.getElementById('providerHint');
    if (p) {
        hint.textContent = p.hint || '';
        // 仅当输入框为空时自动填充，不覆盖已保存的自定义值
        if (!document.getElementById('fBaseUrl').value && p.base_url) {
            document.getElementById('fBaseUrl').value = p.base_url;
        }
        if (!document.getElementById('fModel').value && p.model) {
            document.getElementById('fModel').value = p.model;
        }
    } else {
        hint.textContent = '';
    }
}

function applyProviderDefaults() {
    var p = PROVIDERS[document.getElementById('fProvider').value];
    if (p) {
        if (p.base_url) document.getElementById('fBaseUrl').value = p.base_url;
        if (p.model) document.getElementById('fModel').value = p.model;
    }
}

document.getElementById('fProvider').addEventListener('change', applyProviderDefaults);

function saveAI() {
    post('save_site_ai', {
        enabled: document.getElementById('fEnabled').checked ? '1' : '0',
        provider: document.getElementById('fProvider').value,
        base_url: document.getElementById('fBaseUrl').value.trim(),
        model: document.getElementById('fModel').value.trim(),
        api_key: document.getElementById('fApiKey').value.trim(),
        api_key_extra: document.getElementById('fApiKeyExtra').value,
        max_tokens: document.getElementById('fMaxTokens').value || 0
    }).then(function(d) {
        if (!d.success) { showToast(d.message || '保存失败', 'error'); return; }
        document.getElementById('fApiKey').value = '';
        showToast(d.message || '已保存', 'success');
        fillForm(d.data);
    }).catch(function() { showToast('保存失败', 'error'); });
}

function testAI() {
    var box = document.getElementById('testResult');
    box.innerHTML = '<span style="color:var(--text-secondary);">测试中…</span>';
    post('test_site_ai', {}).then(function(d) {
        if (!d.success) {
            box.innerHTML = '<div style="padding:10px 12px;border-radius:8px;background:rgba(203,36,49,.08);color:#cb2431;font-size:13px;">测试失败：' + esc(d.message || '未知错误') + '</div>';
            return;
        }
        var r = d.data;
        box.innerHTML = '<div style="padding:10px 12px;border-radius:8px;background:rgba(46,139,87,.08);color:#2E8B57;font-size:13px;">连通正常 · HTTP ' + esc(r.http) + ' · 耗时 ' + esc(r.elapsed_ms) + ' ms · 模型回复：' + esc(r.reply || '(空)') + '</div>';
    }).catch(function() {
        box.innerHTML = '<div style="padding:10px 12px;border-radius:8px;background:rgba(203,36,49,.08);color:#cb2431;font-size:13px;">网络错误，测试失败</div>';
    });
}

post('get_site_ai', {}).then(function(d) {
    if (d.success) fillForm(d.data);
}).catch(function() { showToast('加载配置失败', 'error'); });
</script>

<?php adminFooter(); ?>
