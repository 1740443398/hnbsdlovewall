<?php

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../includes/totp.php';

$user = requireLogin();
$user = checkBanned($user);

$csrfToken = generateCSRFToken();
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>2FA设置 - <?= SITE_NAME ?></title>
    <link rel="icon" href="/icon.ico" type="image/x-icon">
    <link rel="stylesheet" href="<?= SITE_URL ?>/assets/css/style.css?v=<?= asset_ver('/assets/css/style.css') ?>">
    <style>
        .twofa-page {
            max-width: 700px;
            margin: 0 auto;
            padding: 24px 16px;
            min-height: 100vh;
        }
        .twofa-card {
            background: var(--card-bg);
            border: 1px solid var(--border);
            border-radius: var(--radius);
            padding: 32px;
            box-shadow: var(--shadow-sm);
        }
        .twofa-card h1 {
            font-size: 1.5rem;
            margin-bottom: 8px;
            text-align: center;
        }
        .twofa-card .subtitle {
            text-align: center;
            color: var(--text-secondary);
            font-size: 0.9rem;
            margin-bottom: 28px;
        }
        .twofa-steps { counter-reset: step; }
        .twofa-step {
            padding: 20px 0 20px 56px;
            position: relative;
            border-bottom: 1px solid var(--border-light);
        }
        .twofa-step:last-child { border-bottom: none; }
        .twofa-step::before {
            counter-increment: step;
            content: counter(step);
            position: absolute;
            left: 0;
            top: 20px;
            width: 36px;
            height: 36px;
            background: var(--primary);
            color: #fff;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1rem;
            font-weight: 700;
        }
        .twofa-step h3 { font-size: 1.05rem; margin-bottom: 6px; }
        .twofa-step p { font-size: 0.85rem; color: var(--text-secondary); margin: 0 0 8px; }
        .twofa-step a { color: var(--primary); }
        .qr-container {
            display: flex;
            justify-content: center;
            padding: 20px;
            background: #fff;
            border: 1px solid var(--border);
            border-radius: var(--radius-sm);
            margin: 12px 0;
        }
        .qr-container img { width: 200px; height: 200px; }
        .manual-key {
            background: var(--bg);
            padding: 12px 16px;
            border-radius: var(--radius-sm);
            font-family: 'Courier New', monospace;
            font-size: 0.9rem;
            word-break: break-all;
            margin: 8px 0;
            border: 1px solid var(--border);
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
        }
        .manual-key .copy-key-btn {
            font-size: 0.75rem;
            background: var(--primary);
            color: #fff;
            border: none;
            border-radius: 4px;
            padding: 4px 10px;
            cursor: pointer;
            flex-shrink: 0;
        }
        .code-input {
            display: flex;
            gap: 8px;
            margin: 12px 0;
            justify-content: center;
        }
        .code-input input {
            width: 48px;
            height: 56px;
            text-align: center;
            font-size: 1.5rem;
            font-weight: 700;
            border: 2px solid var(--border);
            border-radius: var(--radius-sm);
            outline: none;
            font-family: 'Courier New', monospace;
            background: var(--bg);
            color: var(--text);
        }
        .code-input input:focus { border-color: var(--border-focus); }
        .form-group { margin-bottom: 16px; }
        .form-group label { display: block; font-size: 0.85rem; font-weight: 600; margin-bottom: 6px; }
        .form-input {
            width: 100%;
            padding: 10px 14px;
            border: 1px solid var(--border);
            border-radius: var(--radius-sm);
            font-size: 0.9375rem;
            background: var(--bg);
            color: var(--text);
            outline: none;
            min-height: 44px;
        }
        .form-input:focus { border-color: var(--border-focus); box-shadow: 0 0 0 3px rgba(74,144,217,0.15); }
        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
            padding: 10px 20px;
            border: none;
            border-radius: var(--radius-sm);
            font-size: 0.875rem;
            font-weight: 600;
            cursor: pointer;
            min-height: 44px;
            font-family: inherit;
            transition: all 0.2s ease;
        }
        .btn-primary { background: var(--primary); color: #fff; }
        .btn-primary:hover { background: var(--primary-hover); }
        .btn-outline { background: transparent; color: var(--text); border: 1px solid var(--border); }
        .btn-outline:hover { border-color: var(--primary); color: var(--primary); }
        .btn-danger { background: var(--danger); color: #fff; }
        .btn:disabled { opacity: 0.5; cursor: not-allowed; }
        .spinner {
            display: inline-block;
            width: 16px; height: 16px;
            border: 2px solid rgba(255,255,255,0.3);
            border-top-color: #fff;
            border-radius: 50%;
            animation: spin 0.6s linear infinite;
        }
        @keyframes spin { to { transform: rotate(360deg); } }
        .alert {
            padding: 12px 16px;
            border-radius: var(--radius-sm);
            font-size: 0.875rem;
            margin-bottom: 16px;
            display: none;
        }
        .alert.show { display: block; }
        .alert-error { background: var(--danger-light); color: var(--danger); border: 1px solid var(--danger); }
        .alert-success { background: var(--success-light); color: var(--success); border: 1px solid var(--success); }
        .alert-info { background: var(--info-light); color: var(--info); border: 1px solid var(--info); }
        .recovery-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(150px, 1fr));
            gap: 8px;
            margin: 12px 0;
        }
        .recovery-item {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 8px 12px;
            background: var(--bg);
            border: 1px solid var(--border);
            border-radius: 4px;
            font-family: 'Courier New', monospace;
            font-size: 0.85rem;
            letter-spacing: 1px;
        }
        .recovery-item button {
            font-size: 0.7rem;
            color: var(--primary);
            cursor: pointer;
            border: 1px solid var(--primary);
            border-radius: 4px;
            background: transparent;
            padding: 2px 8px;
        }
        .recovery-item button:hover { background: var(--primary); color: #fff; }
        .toast {
            position: fixed;
            top: 20px; left: 50%;
            transform: translateX(-50%);
            padding: 12px 24px;
            border-radius: var(--radius-sm);
            color: #fff;
            font-size: 0.875rem;
            z-index: 2000;
            animation: toastIn 0.3s ease;
            box-shadow: 0 4px 16px rgba(0,0,0,0.15);
        }
        .toast-error { background: var(--danger); }
        .toast-success { background: var(--success); }
        .toast-info { background: var(--primary); }
        @keyframes toastIn {
            from { opacity: 0; transform: translateX(-50%) translateY(-20px); }
            to { opacity: 1; transform: translateX(-50%) translateY(0); }
        }
        .back-link {
            display: block;
            text-align: center;
            margin-top: 20px;
            color: var(--text-secondary);
            font-size: 0.875rem;
        }
        .back-link a { color: var(--primary); }
    </style>
</head>
<body class="<?= $user['theme'] ?? 'light' ?>-theme">
    <div class="twofa-page">
        <div class="twofa-card">
            <h1>双重验证设置</h1>
            <p class="subtitle">提高账户安全性，防止未授权登录</p>

            <div class="alert alert-error" id="alertError"></div>
            <div class="alert alert-success" id="alertSuccess"></div>
            <div class="alert alert-info" id="alertInfo"></div>

            <?php if ($user['twofa_enabled']): ?>
            <div id="twofaEnabled">
                <p style="text-align:center;color:var(--success);font-weight:600;font-size:1.1rem;">2FA已启用</p>
                <p style="text-align:center;color:var(--text-secondary);margin-bottom:20px;">你的账户已受到双重验证保护</p>
                <div style="display:flex;gap:12px;justify-content:center;flex-wrap:wrap;">
                    <button class="btn btn-danger" id="disable2faBtn">禁用2FA</button>
                    <button class="btn btn-outline" id="reset2faBtn">重置2FA</button>
                </div>
            </div>
            <?php else: ?>
            <div id="twofaSetup">
                <div class="twofa-steps">
                    <div class="twofa-step">
                        <h3>下载认证器应用</h3>
                        <p>请先在手机上下载认证器应用：</p>
                        <p>
                            <a href="https://play.google.com/store/apps/details?id=com.google.android.apps.authenticator2" target="_blank">Google Authenticator</a>
                            &nbsp;|&nbsp;
                            <a href="https://www.microsoft.com/authenticator" target="_blank">Microsoft Authenticator</a>
                        </p>
                    </div>
                    <div class="twofa-step">
                        <h3>扫描二维码</h3>
                        <p>点击按钮生成密钥和二维码，使用认证器应用扫描</p>
                        <button class="btn btn-primary" id="generate2faBtn" style="margin:12px 0;">
                            <span id="genBtnText">生成密钥</span>
                            <span class="spinner" id="genBtnSpinner" style="display:none"></span>
                        </button>
                        <div id="setupContent" style="display:none;">
                            <div class="qr-container" id="qrContainer"></div>
                            <p style="font-size:0.8rem;margin-top:8px;">或手动输入密钥：</p>
                            <div class="manual-key" id="manualKeyDiv">
                                <span id="manualKeyText"></span>
                                <button class="copy-key-btn" onclick="copyKey()">复制</button>
                            </div>
                        </div>
                    </div>
                    <div class="twofa-step">
                        <h3>验证并启用</h3>
                        <p>输入认证器应用显示的6位验证码</p>
                        <div class="code-input" id="codeInput">
                            <input type="text" maxlength="1" inputmode="numeric" data-index="0">
                            <input type="text" maxlength="1" inputmode="numeric" data-index="1">
                            <input type="text" maxlength="1" inputmode="numeric" data-index="2">
                            <input type="text" maxlength="1" inputmode="numeric" data-index="3">
                            <input type="text" maxlength="1" inputmode="numeric" data-index="4">
                            <input type="text" maxlength="1" inputmode="numeric" data-index="5">
                        </div>
                        <div class="form-group">
                            <label for="confirmPassword">确认密码</label>
                            <input type="password" id="confirmPassword" class="form-input" placeholder="请输入密码确认操作" style="max-width:300px;">
                        </div>
                        <button class="btn btn-primary" id="verify2faBtn">
                            <span id="verifyBtnText">验证并启用</span>
                            <span class="spinner" id="verifyBtnSpinner" style="display:none"></span>
                        </button>
                    </div>
                </div>
                <div id="recoveryArea" style="display:none;margin-top:24px;padding-top:24px;border-top:1px solid var(--border);">
                    <h3 style="font-size:1rem;margin-bottom:8px;text-align:center;">恢复码</h3>
                    <p style="font-size:0.82rem;color:var(--warning);text-align:center;margin-bottom:12px;">请立即保存这些恢复码！如果丢失认证器，可使用恢复码登录。每个恢复码只能使用一次。</p>
                    <div class="recovery-grid" id="recoveryGrid"></div>
                    <div style="display:flex;gap:8px;margin-top:12px;justify-content:center;flex-wrap:wrap;">
                        <button class="btn btn-outline" id="copyAllBtn">复制全部</button>
                        <button class="btn btn-outline" id="downloadBtn">下载为TXT</button>
                    </div>
                </div>
            </div>
            <?php endif; ?>
        </div>
        <div class="back-link">
            <a href="<?= SITE_URL ?>/pages/user_center.php?tab=2fa">← 返回用户中心</a>
        </div>
    </div>

    <script>
    (function() {
        const SITE_URL = '<?= SITE_URL ?>';
        const CSRF_TOKEN = '<?= $csrfToken ?>';
        let twofaSecret = '';
        let recoveryCodes = [];

        function showToast(msg, type) {
            const existing = document.querySelector('.toast');
            if (existing) existing.remove();
            const t = document.createElement('div');
            t.className = 'toast toast-' + type;
            t.textContent = msg;
            document.body.appendChild(t);
            setTimeout(() => { t.style.opacity = '0'; t.style.transition = 'opacity 0.3s'; setTimeout(() => t.remove(), 300); }, 3000);
        }

        function setLoading(btn, textEl, spinnerEl, loading) {
            btn.disabled = loading;
            textEl.style.display = loading ? 'none' : '';
            spinnerEl.style.display = loading ? 'inline-block' : 'none';
        }

        function showAlert(el, msg) {
            document.querySelectorAll('.alert').forEach(a => a.classList.remove('show'));
            el.textContent = msg;
            el.classList.add('show');
        }

        const genBtn = document.getElementById('generate2faBtn');
        if (genBtn) {
            genBtn.addEventListener('click', function() {
                setLoading(genBtn, document.getElementById('genBtnText'), document.getElementById('genBtnSpinner'), true);
                const fd = new FormData();
                fd.append('csrf_token', CSRF_TOKEN);
                fd.append('action', 'generate');
                fetch(SITE_URL + '/api/user/2fa_setup.php', { method: 'POST', body: fd })
                    .then(r => r.json())
                    .then(d => {
                        setLoading(genBtn, document.getElementById('genBtnText'), document.getElementById('genBtnSpinner'), false);
                        if (d.success) {
                            twofaSecret = d.data.secret;
                            document.getElementById('setupContent').style.display = 'block';
                            document.getElementById('qrContainer').innerHTML = '<img src="' + d.data.qr_code_url + '" alt="QR Code">';
                            document.getElementById('manualKeyText').textContent = d.data.secret;
                            genBtn.style.display = 'none';
                            showToast('密钥已生成，请扫描二维码', 'info');
                        } else {
                            showToast(d.message, 'error');
                        }
                    })
                    .catch(() => {
                        setLoading(genBtn, document.getElementById('genBtnText'), document.getElementById('genBtnSpinner'), false);
                        showToast('网络错误', 'error');
                    });
            });
        }

        const codeInputs = document.querySelectorAll('#codeInput input');
        codeInputs.forEach((inp, i) => {
            inp.addEventListener('input', function() {
                this.value = this.value.replace(/\D/g, '');
                if (this.value && i < 5) codeInputs[i+1].focus();
            });
            inp.addEventListener('keydown', function(e) {
                if (e.key === 'Backspace' && !this.value && i > 0) codeInputs[i-1].focus();
            });
            inp.addEventListener('paste', function(e) {
                e.preventDefault();
                const pasted = (e.clipboardData || window.clipboardData).getData('text').replace(/\D/g, '').slice(0, 6);
                pasted.split('').forEach((d, j) => { if (codeInputs[j]) codeInputs[j].value = d; });
            });
        });

        function getCode() {
            let c = '';
            codeInputs.forEach(inp => c += inp.value);
            return c;
        }

        const verifyBtn = document.getElementById('verify2faBtn');
        if (verifyBtn) {
            verifyBtn.addEventListener('click', function() {
                const code = getCode();
                const pw = document.getElementById('confirmPassword').value;
                if (code.length !== 6) { showToast('请输入6位验证码', 'error'); return; }
                if (!pw) { showToast('请输入密码确认操作', 'error'); return; }

                setLoading(verifyBtn, document.getElementById('verifyBtnText'), document.getElementById('verifyBtnSpinner'), true);
                const fd = new FormData();
                fd.append('csrf_token', CSRF_TOKEN);
                fd.append('action', 'verify_enable');
                fd.append('code', code);
                fd.append('password', pw);
                fetch(SITE_URL + '/api/user/2fa_setup.php', { method: 'POST', body: fd })
                    .then(r => r.json())
                    .then(d => {
                        setLoading(verifyBtn, document.getElementById('verifyBtnText'), document.getElementById('verifyBtnSpinner'), false);
                        if (d.success) {
                            recoveryCodes = d.data.recovery_codes;
                            document.getElementById('recoveryGrid').innerHTML = recoveryCodes.map(c =>
                                '<div class="recovery-item"><span>' + c + '</span><button onclick="navigator.clipboard.writeText(\'' + c + '\')">复制</button></div>'
                            ).join('');
                            document.getElementById('recoveryArea').style.display = 'block';
                            document.querySelector('.twofa-steps').style.display = 'none';
                            showToast(d.message, 'success');
                            setTimeout(() => { window.location.href = SITE_URL + '/pages/login.php'; }, 3000);
                        } else {
                            showToast(d.message, 'error');
                        }
                    })
                    .catch(() => {
                        setLoading(verifyBtn, document.getElementById('verifyBtnText'), document.getElementById('verifyBtnSpinner'), false);
                        showToast('网络错误', 'error');
                    });
            });
        }

        window.copyKey = function() {
            navigator.clipboard.writeText(twofaSecret).then(() => showToast('密钥已复制', 'success'));
        };

        document.getElementById('copyAllBtn')?.addEventListener('click', () => {
            navigator.clipboard.writeText(recoveryCodes.join('\n')).then(() => showToast('已复制全部恢复码', 'success'));
        });

        document.getElementById('downloadBtn')?.addEventListener('click', () => {
            const blob = new Blob([recoveryCodes.join('\n')], {type: 'text/plain'});
            const a = document.createElement('a');
            a.href = URL.createObjectURL(blob);
            a.download = 'recovery-codes.txt';
            a.click();
            showToast('已下载恢复码文件', 'success');
        });

        function do2faAction(action, msg) {
            const pw = prompt(msg + '\n请输入密码：');
            if (!pw) return;
            const fd = new FormData();
            fd.append('csrf_token', CSRF_TOKEN);
            fd.append('action', action);
            fd.append('password', pw);
            fetch(SITE_URL + '/api/user/2fa_setup.php', { method: 'POST', body: fd })
                .then(r => r.json())
                .then(d => {
                    if (d.success) { showToast(d.message, 'success'); setTimeout(() => location.href = SITE_URL + '/pages/login.php', 1500); }
                    else showToast(d.message, 'error');
                });
        }

        document.getElementById('disable2faBtn')?.addEventListener('click', () => do2faAction('disable', '确认禁用2FA？'));
        document.getElementById('reset2faBtn')?.addEventListener('click', () => do2faAction('reset', '确认重置2FA？旧的恢复码将失效。'));
    })();
    </script>

    <nav class="mobile-bottom-nav" id="mobileNav">
        <a href="/" class="mobile-nav-item">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg>
            <span>首页</span>
        </a>
        <a href="/pages/user_center.php" class="mobile-nav-item">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
            <span>我的</span>
        </a>
    </nav>
</body>
</html>