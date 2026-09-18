<?php

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/security.php';

$csrfToken = generateCSRFToken();

$resetToken = isset($_REQUEST['token']) ? sanitizeInput($_REQUEST['token']) : '';
$resetQQ = isset($_REQUEST['qq']) ? sanitizeInput($_REQUEST['qq']) : '';
$showResetForm = !empty($resetToken) && !empty($resetQQ);
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <script src="/assets/js/anti_hijack.js"></script>
    <title>忘记密码 - <?= SITE_NAME ?></title>
    <link rel="icon" href="/icon.ico" type="image/x-icon">
    <meta name="description" content="校园交流墙密码重置">
    <style>
        :root {
            --primary: #1B3A5C;
            --primary-dark: #142B44;
            --primary-light: #E8EFF5;
            --primary-hover: #2A5078;
            --danger: #C4626A;
            --danger-light: #FAECEE;
            --success: #4A8C5C;
            --success-light: #E8F4EC;
            --warning: #C9A96E;
            --warning-light: #FBF6ED;
            --bg: #F8F9FA;
            --card-bg: #FFFFFF;
            --text: #1B2A3A;
            --text-secondary: #5A6B7A;
            --border: #DDE2E8;
            --border-focus: #1B3A5C;
            --radius: 12px;
            --radius-sm: 8px;
            --shadow: 0 4px 24px rgba(27,58,92,0.08);
            --shadow-hover: 0 8px 32px rgba(27,58,92,0.12);
            --transition: 0.2s ease;
        }

        * { margin: 0; padding: 0; box-sizing: border-box; }

        body {
            font-family: 'ZCOOL QingKe HuangYou', 'Noto Sans SC', 'PingFang SC', 'Microsoft YaHei', sans-serif;
            background: linear-gradient(160deg, #142B44 0%, #1B3A5C 55%, #0D1F35 100%);
            background-attachment: fixed;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
            color: var(--text);
            -webkit-font-smoothing: antialiased;
            /* 只隐藏横向溢出，允许垂直滚动 */
            overflow-x: hidden;
            overflow-y: auto;
        }

        .auth-container {
            width: 100%;
            max-width: 420px;
        }

        .auth-card {
            background: var(--card-bg);
            border-radius: var(--radius);
            box-shadow: 0 2px 12px rgba(0, 0, 0, 0.10);
            padding: 40px 32px;
        }

        .auth-header {
            text-align: center;
            margin-bottom: 32px;
        }

        .auth-logo {
            width: 64px;
            height: 64px;
            margin: 0 auto 12px;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .auth-logo svg {
            width: 56px;
            height: 56px;
        }

        .auth-title {
            font-size: 24px;
            font-weight: 700;
            color: var(--text);
            margin-bottom: 4px;
        }

        .auth-subtitle {
            font-size: 14px;
            color: var(--text-secondary);
        }

        .form-group {
            margin-bottom: 20px;
            position: relative;
        }

        .form-label {
            display: block;
            font-size: 14px;
            font-weight: 600;
            color: var(--text);
            margin-bottom: 6px;
        }

        .input-wrapper {
            position: relative;
            display: flex;
            align-items: center;
        }

        .form-input {
            width: 100%;
            padding: 12px 16px;
            border: 2px solid var(--border);
            border-radius: var(--radius-sm);
            font-size: 15px;
            color: var(--text);
            background: var(--bg);
            transition: all var(--transition);
            outline: none;
            font-family: inherit;
        }

        .form-input:focus {
            border-color: var(--border-focus);
            background: var(--card-bg);
            box-shadow: 0 0 0 3px rgba(79, 140, 255, 0.1);
        }

        .form-input.input-error {
            border-color: var(--danger);
            background: var(--danger-light);
        }

        .form-input.input-success {
            border-color: var(--success);
        }

        .input-icon-btn {
            position: absolute;
            right: 8px;
            background: none;
            border: none;
            cursor: pointer;
            padding: 8px;
            color: var(--text-secondary);
            font-size: 18px;
            line-height: 1;
            transition: color var(--transition);
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .input-icon-btn:hover {
            color: var(--text);
        }

        .error-message {
            font-size: 12px;
            color: var(--danger);
            margin-top: 4px;
            display: none;
            align-items: center;
            gap: 4px;
        }

        .error-message.show {
            display: flex;
        }

        .success-message {
            font-size: 12px;
            color: var(--success);
            margin-top: 4px;
            display: none;
            align-items: center;
            gap: 4px;
        }

        .success-message.show {
            display: flex;
        }

        .password-strength {
            margin-top: 8px;
            display: flex;
            gap: 4px;
            align-items: center;
        }

        .strength-bar {
            height: 4px;
            flex: 1;
            background: var(--border);
            border-radius: 2px;
            transition: all var(--transition);
        }

        .strength-bar.weak { background: var(--danger); }
        .strength-bar.medium { background: var(--warning); }
        .strength-bar.strong { background: var(--success); }

        .strength-text {
            font-size: 12px;
            font-weight: 600;
            margin-left: 8px;
            min-width: 40px;
        }

        .strength-text.weak { color: var(--danger); }
        .strength-text.medium { color: var(--warning); }
        .strength-text.strong { color: var(--success); }

        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            width: 100%;
            padding: 14px 24px;
            border: none;
            border-radius: var(--radius-sm);
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            transition: all var(--transition);
            font-family: inherit;
            text-decoration: none;
        }

        .btn-primary {
            background: linear-gradient(135deg, #1B3A5C, #142B44);
            color: #fff;
            box-shadow: 0 4px 16px rgba(27, 58, 92, 0.25);
        }

        .btn-primary:hover {
            background: linear-gradient(135deg, #2A5078, #1B3A5C);
            transform: translateY(-1px);
            box-shadow: 0 6px 20px rgba(27, 58, 92, 0.35);
        }

        .btn-primary:active {
            transform: translateY(0);
        }

        .btn-primary:disabled {
            background: var(--primary);
            opacity: 0.5;
            cursor: not-allowed;
            transform: none;
        }

        .btn-secondary {
            background: transparent;
            color: var(--primary);
            border: 2px solid var(--primary);
        }

        .btn-secondary:hover {
            background: var(--primary-light);
        }

        .auth-footer {
            text-align: center;
            margin-top: 24px;
            font-size: 14px;
            color: var(--text-secondary);
        }

        .auth-footer a {
            color: var(--primary);
            text-decoration: none;
            font-weight: 600;
            transition: color var(--transition);
        }

        .auth-footer a:hover {
            color: var(--primary-dark);
            text-decoration: underline;
        }

        .auth-links {
            display: flex;
            justify-content: center;
            margin-top: 16px;
            font-size: 13px;
            gap: 16px;
        }

        .auth-links a {
            color: var(--text-secondary);
            text-decoration: none;
            transition: color var(--transition);
        }

        .auth-links a:hover {
            color: var(--primary);
        }

        .alert {
            padding: 12px 16px;
            border-radius: var(--radius-sm);
            font-size: 14px;
            margin-bottom: 16px;
            display: none;
        }

        .alert.show {
            display: block;
        }

        .alert-error {
            background: var(--danger-light);
            color: var(--danger);
            border: 1px solid var(--danger);
        }

        .alert-success {
            background: var(--success-light);
            color: var(--success);
            border: 1px solid var(--success);
        }

        .alert-info {
            background: var(--primary-light);
            color: var(--primary-dark);
            border: 1px solid var(--primary);
        }

        .spinner {
            display: inline-block;
            width: 18px;
            height: 18px;
            border: 2px solid rgba(255,255,255,0.3);
            border-top-color: #fff;
            border-radius: 50%;
            animation: spin 0.6s linear infinite;
        }

        @keyframes spin {
            to { transform: rotate(360deg); }
        }

        .toast {
            position: fixed;
            top: 20px;
            left: 50%;
            transform: translateX(-50%);
            padding: 12px 24px;
            border-radius: var(--radius-sm);
            color: #fff;
            font-size: 14px;
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

        .step-indicator {
            display: flex;
            justify-content: center;
            gap: 8px;
            margin-bottom: 24px;
        }

        .step-dot {
            width: 12px;
            height: 12px;
            border-radius: 50%;
            background: var(--border);
            transition: background var(--transition);
        }

        .step-dot.active {
            background: var(--primary);
        }

        .step-dot.completed {
            background: var(--success);
        }

        .divider {
            display: flex;
            align-items: center;
            gap: 8px;
            margin: 20px 0;
            color: var(--text-secondary);
            font-size: 13px;
        }

        .divider::before,
        .divider::after {
            content: '';
            flex: 1;
            height: 1px;
            background: var(--border);
        }

        @media (max-width: 480px) {
            .auth-card {
                padding: 28px 20px;
            }
            .auth-title {
                font-size: 20px;
            }
            .form-input {
                padding: 10px 14px;
                font-size: 14px;
            }
            .btn {
                padding: 12px 20px;
                font-size: 15px;
            }
        }
    </style>
</head>
<body>
    <div class="auth-container">
        <div class="auth-card">
            <div class="auth-header">
                <div class="auth-logo">
                    <svg width="56" height="56" viewBox="0 0 24 24" fill="none">
                        <defs>
                            <linearGradient id="authLogoGrad" x1="0%" y1="0%" x2="100%" y2="100%">
                                <stop offset="0%" stop-color="#4FC3F7"/>
                                <stop offset="100%" stop-color="#1976D2"/>
                            </linearGradient>
                        </defs>
                        <path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z" fill="url(#authLogoGrad)"/>
                        <polyline points="9 22 9 12 15 12 15 22" fill="none" stroke="#fff" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                    </svg>
                </div>
                <h1 class="auth-title"><?= $showResetForm ? '重置密码' : '忘记密码' ?></h1>
                <p class="auth-subtitle"><?= $showResetForm ? '设置您的新密码' : '通过QQ号找回密码' ?></p>
            </div>

            <div class="alert alert-error" id="alertError"></div>
            <div class="alert alert-success" id="alertSuccess"></div>
            <div class="alert alert-info" id="alertInfo"></div>

            <?php if ($showResetForm): ?>

            <div class="step-indicator">
                <span class="step-dot completed"></span>
                <span class="step-dot active"></span>
            </div>

            <form id="resetForm" autocomplete="off">
                <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
                <input type="hidden" id="resetToken" name="reset_token" value="<?= xss_clean($resetToken) ?>">
                <input type="hidden" id="resetQQ" name="qq" value="<?= xss_clean($resetQQ) ?>">

                <div class="form-group">
                    <label class="form-label">QQ号</label>
                    <div class="input-wrapper">
                        <input type="text" class="form-input" value="<?= xss_clean($resetQQ) ?>" disabled>
                    </div>
                </div>

                <div class="form-group">
                    <label class="form-label" for="newPassword">新密码</label>
                    <div class="input-wrapper">
                        <input type="password" id="newPassword" name="new_password" class="form-input" placeholder="至少8位，包含字母和数字" autocomplete="new-password">
                        <button type="button" class="input-icon-btn" id="toggleNewPassword" title="显示/隐藏密码">显示</button>
                    </div>
                    <div class="password-strength" id="passwordStrength" style="display:none;">
                        <span class="strength-bar" id="strengthBar1"></span>
                        <span class="strength-bar" id="strengthBar2"></span>
                        <span class="strength-bar" id="strengthBar3"></span>
                        <span class="strength-text" id="strengthText"></span>
                    </div>
                    <div class="error-message" id="passwordError">
                        <span id="passwordErrorText"></span>
                    </div>
                </div>

                <div class="form-group">
                    <label class="form-label" for="confirmNewPassword">确认新密码</label>
                    <div class="input-wrapper">
                        <input type="password" id="confirmNewPassword" name="confirm_password" class="form-input" placeholder="请再次输入新密码" autocomplete="new-password">
                        <button type="button" class="input-icon-btn" id="toggleConfirmNewPassword" title="显示/隐藏密码">显示</button>
                    </div>
                    <div class="error-message" id="confirmError">
                        <span id="confirmErrorText"></span>
                    </div>
                    <div class="success-message" id="confirmSuccess">
                        <span>密码匹配</span>
                    </div>
                </div>

                <button type="submit" class="btn btn-primary" id="submitBtn">
                    <span id="btnText">重置密码</span>
                    <span class="spinner" id="btnSpinner" style="display:none"></span>
                </button>
            </form>
            <?php else: ?>

            <div class="step-indicator">
                <span class="step-dot active"></span>
                <span class="step-dot"></span>
            </div>

            <form id="forgotForm" autocomplete="off">
                <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">

                <div class="form-group">
                    <label class="form-label" for="qq">QQ号</label>
                    <div class="input-wrapper">
                        <input type="text" id="qq" name="qq" class="form-input" placeholder="请输入注册时使用的QQ号" maxlength="15" inputmode="numeric" autocomplete="off">
                    </div>
                    <div class="error-message" id="qqError">
                        <span id="qqErrorText"></span>
                    </div>
                </div>

                <button type="submit" class="btn btn-primary" id="submitBtn">
                    <span id="btnText">发送重置链接</span>
                    <span class="spinner" id="btnSpinner" style="display:none"></span>
                </button>
            </form>
            <?php endif; ?>

            <div class="auth-footer">
                <a href="<?= SITE_URL ?>/pages/login.php">返回登录</a>
            </div>

            <div class="auth-links">
                <a href="<?= SITE_URL ?>/pages/register.php">注册账号</a>
                <a href="<?= SITE_URL ?>/">返回首页</a>
            </div>
        </div>
    </div>

    <script>
    (function() {
        const SITE_URL = '<?= SITE_URL ?>';
        const CSRF_TOKEN = '<?= $csrfToken ?>';
        const SHOW_RESET_FORM = <?= $showResetForm ? 'true' : 'false' ?>;

        let isSubmitting = false;

        function hideAllAlerts() {
            ['alertError', 'alertSuccess', 'alertInfo'].forEach(function(id) {
                var el = document.getElementById(id);
                if (el) { el.classList.remove('show'); el.textContent = ''; }
            });
        }

        function showAlert(message) {
            hideAllAlerts();
            var el = document.getElementById('alertError');
            if (el) { el.textContent = message; el.classList.add('show'); }
        }

        function showSuccess(message) {
            hideAllAlerts();
            var el = document.getElementById('alertSuccess');
            if (el) { el.textContent = message; el.classList.add('show'); }
        }

        function showInfo(message) {
            hideAllAlerts();
            var el = document.getElementById('alertInfo');
            if (el) { el.textContent = message; el.classList.add('show'); }
        }

        function showToast(message, type) {
            var existing = document.querySelector('.toast');
            if (existing) existing.remove();
            var toast = document.createElement('div');
            toast.className = 'toast toast-' + type;
            toast.textContent = message;
            document.body.appendChild(toast);
            setTimeout(function() {
                toast.style.opacity = '0';
                toast.style.transition = 'opacity 0.3s ease';
                setTimeout(function() { toast.remove(); }, 300);
            }, 3000);
        }

        function setLoading(btn, textEl, spinnerEl, loading) {
            if (loading) {
                btn.disabled = true;
                textEl.style.display = 'none';
                spinnerEl.style.display = 'inline-block';
            } else {
                btn.disabled = false;
                textEl.style.display = '';
                spinnerEl.style.display = 'none';
            }
        }

        if (SHOW_RESET_FORM) {

            var resetForm = document.getElementById('resetForm');
            var newPasswordInput = document.getElementById('newPassword');
            var confirmNewInput = document.getElementById('confirmNewPassword');
            var toggleNewBtn = document.getElementById('toggleNewPassword');
            var toggleConfirmNewBtn = document.getElementById('toggleConfirmNewPassword');
            var passwordStrength = document.getElementById('passwordStrength');
            var strengthBar1 = document.getElementById('strengthBar1');
            var strengthBar2 = document.getElementById('strengthBar2');
            var strengthBar3 = document.getElementById('strengthBar3');
            var strengthText = document.getElementById('strengthText');
            var passwordError = document.getElementById('passwordError');
            var passwordErrorText = document.getElementById('passwordErrorText');
            var confirmError = document.getElementById('confirmError');
            var confirmErrorText = document.getElementById('confirmErrorText');
            var confirmSuccess = document.getElementById('confirmSuccess');
            var submitBtn = document.getElementById('submitBtn');
            var btnText = document.getElementById('btnText');
            var btnSpinner = document.getElementById('btnSpinner');

            toggleNewBtn.addEventListener('click', function() {
                newPasswordInput.type = newPasswordInput.type === 'password' ? 'text' : 'password';
                this.textContent = newPasswordInput.type === 'password' ? '显示' : '隐藏';
            });

            toggleConfirmNewBtn.addEventListener('click', function() {
                confirmNewInput.type = confirmNewInput.type === 'password' ? 'text' : 'password';
                this.textContent = confirmNewInput.type === 'password' ? '显示' : '隐藏';
            });

            newPasswordInput.addEventListener('input', function() {
                var pwd = this.value;
                passwordError.classList.remove('show');

                if (pwd.length === 0) {
                    passwordStrength.style.display = 'none';
                    return;
                }

                passwordStrength.style.display = 'flex';
                var bars = [strengthBar1, strengthBar2, strengthBar3];
                var score = 0;
                if (pwd.length >= 8) score++;
                if (pwd.length >= 12) score++;
                if (/[a-z]/.test(pwd)) score++;
                if (/[A-Z]/.test(pwd)) score++;
                if (/[0-9]/.test(pwd)) score++;
                if (/[^a-zA-Z0-9]/.test(pwd)) score++;

                var level, colorClass;
                if (score <= 2) { level = '弱'; colorClass = 'weak'; bars[0].className = 'strength-bar weak'; bars[1].className = 'strength-bar'; bars[2].className = 'strength-bar'; }
                else if (score <= 3) { level = '中等'; colorClass = 'medium'; bars[0].className = 'strength-bar medium'; bars[1].className = 'strength-bar medium'; bars[2].className = 'strength-bar'; }
                else if (score <= 4) { level = '强'; colorClass = 'strong'; bars[0].className = 'strength-bar strong'; bars[1].className = 'strength-bar strong'; bars[2].className = 'strength-bar strong'; }
                else { level = '非常强'; colorClass = 'strong'; bars[0].className = 'strength-bar strong'; bars[1].className = 'strength-bar strong'; bars[2].className = 'strength-bar strong'; }

                strengthText.textContent = level;
                strengthText.className = 'strength-text ' + colorClass;
                checkPwdMatch();
            });

            confirmNewInput.addEventListener('input', checkPwdMatch);

            function checkPwdMatch() {
                var pwd = newPasswordInput.value;
                var cpwd = confirmNewInput.value;
                confirmError.classList.remove('show');
                confirmSuccess.classList.remove('show');
                confirmNewInput.classList.remove('input-error', 'input-success');
                if (cpwd.length === 0) return;
                if (pwd !== cpwd) {
                    confirmErrorText.textContent = '两次输入的密码不一致';
                    confirmError.classList.add('show');
                    confirmNewInput.classList.add('input-error');
                } else {
                    confirmSuccess.classList.add('show');
                    confirmNewInput.classList.add('input-success');
                }
            }

            resetForm.addEventListener('submit', function(e) {
                e.preventDefault();
                if (isSubmitting) return;
                hideAllAlerts();

                var pwd = newPasswordInput.value;
                var cpwd = confirmNewInput.value;

                if (pwd.length < 8) { showAlert('密码长度不能少于8位'); newPasswordInput.focus(); return; }
                if (!/[a-zA-Z]/.test(pwd) || !/[0-9]/.test(pwd)) { showAlert('密码必须包含字母和数字'); newPasswordInput.focus(); return; }
                if (pwd !== cpwd) { showAlert('两次输入的密码不一致'); confirmNewInput.focus(); return; }

                isSubmitting = true;
                setLoading(submitBtn, btnText, btnSpinner, true);

                var formData = new FormData(resetForm);
                fetch(SITE_URL + '/api/auth/reset_password.php', {
                    method: 'POST',
                    body: formData
                })
                .then(function(res) { return res.json(); })
                .then(function(data) {
                    if (data.success) {
                        showToast('密码重置成功！请使用新密码登录', 'success');
                        setTimeout(function() {
                            window.location.href = SITE_URL + '/pages/login.php';
                        }, 1500);
                    } else {
                        showAlert(data.message || '重置失败');
                        setLoading(submitBtn, btnText, btnSpinner, false);
                        isSubmitting = false;
                    }
                })
                .catch(function() {
                    showAlert('网络错误，请重试');
                    setLoading(submitBtn, btnText, btnSpinner, false);
                    isSubmitting = false;
                });
            });

            newPasswordInput.focus();
        } else {

            var forgotForm = document.getElementById('forgotForm');
            var qqInput = document.getElementById('qq');
            var qqError = document.getElementById('qqError');
            var qqErrorText = document.getElementById('qqErrorText');
            var submitBtn = document.getElementById('submitBtn');
            var btnText = document.getElementById('btnText');
            var btnSpinner = document.getElementById('btnSpinner');

            qqInput.addEventListener('input', function() {
                var qq = this.value.trim();
                hideAllAlerts();
                this.value = qq.replace(/\D/g, '');
                var cleaned = this.value;
                qqError.classList.remove('show');
                qqInput.classList.remove('input-error');

                if (cleaned.length === 0) return;
                if (cleaned.length >= 1 && !/^[1-9]/.test(cleaned)) {
                    qqErrorText.textContent = 'QQ号不能以0开头';
                    qqError.classList.add('show');
                    qqInput.classList.add('input-error');
                }
            });

            forgotForm.addEventListener('submit', function(e) {
                e.preventDefault();
                if (isSubmitting) return;
                hideAllAlerts();

                var qq = qqInput.value.trim();
                if (!qq || !/^[1-9][0-9]{4,14}$/.test(qq)) {
                    showAlert('请输入正确的QQ号');
                    qqInput.focus();
                    return;
                }

                isSubmitting = true;
                setLoading(submitBtn, btnText, btnSpinner, true);

                var formData = new FormData(forgotForm);
                fetch(SITE_URL + '/api/auth/forgot_password.php', {
                    method: 'POST',
                    body: formData
                })
                .then(function(res) { return res.json(); })
                .then(function(data) {
                    if (data.success) {
                        showSuccess(data.message || '密码重置链接已生成');
                        setLoading(submitBtn, btnText, btnSpinner, false);
                        isSubmitting = false;
                    } else {
                        showAlert(data.message || '请求失败');
                        setLoading(submitBtn, btnText, btnSpinner, false);
                        isSubmitting = false;
                    }
                })
                .catch(function() {
                    showAlert('网络错误，请重试');
                    setLoading(submitBtn, btnText, btnSpinner, false);
                    isSubmitting = false;
                });
            });

            qqInput.focus();
        }
    })();
    </script>

    <nav class="mobile-bottom-nav" id="mobileNav">
        <a href="/" class="mobile-nav-item">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg>
            <span>首页</span>
        </a>
        <a href="/pages/login.php" class="mobile-nav-item">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M15 3h4a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-4"/><polyline points="10 17 15 12 10 7"/><line x1="15" y1="12" x2="3" y2="12"/></svg>
            <span>登录</span>
        </a>
    </nav>
</body>
</html>