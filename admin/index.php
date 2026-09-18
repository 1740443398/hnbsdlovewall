<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../includes/totp.php';

$user = getCurrentUser();
if ($user && in_array($user['role'], ['admin', 'super_admin'])) {
    header('Location: /admin/dashboard.php');
    exit();
}

$error = '';
$step = 'login';
$csrfToken = generateCSRFToken();
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" href="/icon.ico" type="image/x-icon">
    <title>管理员登录 - 管理后台 - <?= SITE_NAME ?></title>
    <style>
        :root {
            --bg: #F8F9FA;
            --card-bg: #FFFFFF;
            --text: #1B2A3A;
            --text-secondary: #5A6B7A;
            --primary: #1B3A5C;
            --primary-hover: #2A5078;
            --primary-light: #E8EFF5;
            --danger: #C4626A;
            --danger-light: #FAECEE;
            --border: #DDE2E8;
            --input-bg: #FFFFFF;
            --input-focus: #FFFFFF;
            --shadow: 0 1px 3px rgba(0,0,0,0.08), 0 1px 2px rgba(0,0,0,0.06);
            --shadow-lg: 0 10px 25px rgba(0,0,0,0.1);
            --radius: 12px;
            --radius-sm: 8px;
        }
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;
            background: var(--bg);
            color: var(--text);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            line-height: 1.6;
        }
        .login-container {
            width: 100%;
            max-width: 420px;
            padding: 20px;
        }
        .login-card {
            background: var(--card-bg);
            border-radius: var(--radius);
            box-shadow: var(--shadow-lg);
            padding: 40px 32px;
        }
        .login-header {
            text-align: center;
            margin-bottom: 32px;
        }
        .login-header .logo-icon {
            font-size: 48px;
            display: block;
            margin-bottom: 12px;
        }
        .login-header .logo-icon svg { width: 48px; height: 48px; stroke: #1B3A5C; }
        .login-header h1 {
            font-size: 22px;
            font-weight: 700;
            color: var(--text);
            margin-bottom: 4px;
        }
        .login-header p {
            font-size: 14px;
            color: var(--text-secondary);
        }
        .login-header .admin-badge {
            display: inline-block;
            background: var(--primary-light);
            color: var(--primary);
            font-size: 12px;
            font-weight: 600;
            padding: 4px 12px;
            border-radius: 20px;
            margin-top: 8px;
        }
        .form-group {
            margin-bottom: 20px;
        }
        .form-group label {
            display: block;
            font-size: 14px;
            font-weight: 600;
            color: var(--text);
            margin-bottom: 6px;
        }
        .form-group input {
            width: 100%;
            padding: 12px 16px;
            border: 2px solid var(--border);
            border-radius: var(--radius-sm);
            font-size: 15px;
            color: var(--text);
            background: var(--input-bg);
            transition: border-color 0.2s, box-shadow 0.2s, background 0.2s;
            outline: none;
        }
        .form-group input:focus {
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(79,70,229,0.1);
            background: var(--input-focus);
        }
        .form-group input.error {
            border-color: var(--danger);
            background: var(--danger-light);
        }
        .error-message {
            background: var(--danger-light);
            color: var(--danger);
            padding: 12px 16px;
            border-radius: var(--radius-sm);
            font-size: 14px;
            margin-bottom: 20px;
            display: none;
            border: 1px solid var(--danger-light);
        }
        .error-message.show { display: block; }
        .btn {
            width: 100%;
            padding: 12px 24px;
            border: none;
            border-radius: var(--radius-sm);
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
        }
        .btn-primary {
            background: var(--primary);
            color: #FFFFFF;
        }
        .btn-primary:hover { background: var(--primary-hover); }
        .btn-primary:disabled {
            opacity: 0.6;
            cursor: not-allowed;
        }
        .btn-secondary {
            background: var(--bg);
            color: var(--text);
            border: 1px solid var(--border);
            margin-top: 12px;
        }
        .btn-secondary:hover { background: var(--primary-light); }
        .spinner {
            width: 18px; height: 18px;
            border: 2px solid rgba(255,255,255,0.3);
            border-top-color: #FFFFFF;
            border-radius: 50%;
            animation: spin 0.6s linear infinite;
            display: none;
        }
        @keyframes spin { to { transform: rotate(360deg); } }
        .back-link {
            text-align: center;
            margin-top: 20px;
            font-size: 14px;
        }
        .back-link a {
            color: var(--text-secondary);
            text-decoration: none;
        }
        .back-link a:hover { color: var(--primary); }

        .step-2fa { display: none; }
        .step-2fa.active { display: block; }
        .step-login.hidden { display: none; }
        .2fa-hint {
            font-size: 13px;
            color: var(--text-secondary);
            text-align: center;
            margin-bottom: 16px;
        }
        @media (max-width: 480px) {
            .login-card { padding: 24px 20px; }
            .login-header .logo-icon { font-size: 36px; }
            .login-header .logo-icon svg { width: 36px; height: 36px; }
            .login-header h1 { font-size: 18px; }
        }
    </style>
</head>
<body>
    <div class="login-container">
        <div class="login-card">
            <div class="login-header">
                <span class="logo-icon"><svg viewBox="0 0 24 24"><path d="M12 2L3 6v6c0 5.5 3.8 10.7 9 12 5.2-1.3 9-6.5 9-12V6l-9-4z"/><path d="M9 12l2 2 4-4"/></svg></span>
                <h1>管理员登录</h1>
                <p><?= SITE_NAME ?></p>
                <span class="admin-badge">后台管理</span>
            </div>

            <div class="error-message" id="errorMsg"></div>

            <div class="step-login" id="stepLogin">
                <form id="loginForm" autocomplete="off">
                    <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
                    <div class="form-group">
                        <label for="qq">QQ号</label>
                        <input type="text" id="qq" name="qq" placeholder="请输入QQ号" maxlength="15" inputmode="numeric" autocomplete="off">
                    </div>
                    <div class="form-group">
                        <label for="password">密码</label>
                        <input type="password" id="password" name="password" placeholder="请输入密码" autocomplete="new-password">
                    </div>
                    <button type="submit" class="btn btn-primary" id="loginBtn">
                        <span id="btnText">登录</span>
                        <span class="spinner" id="btnSpinner"></span>
                    </button>
                </form>
            </div>

            <div class="step-2fa" id="step2fa">
                <p class="2fa-hint">该账号已启用双重验证，请输入验证码</p>
                <form id="twofaForm" autocomplete="off">
                    <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
                    <input type="hidden" id="twofaTempToken" name="temp_token" value="">
                    <div class="form-group">
                        <label for="twofaCode">6位验证码</label>
                        <input type="text" id="twofaCode" name="code" placeholder="请输入6位验证码" maxlength="6" inputmode="numeric" autocomplete="off">
                    </div>
                    <button type="submit" class="btn btn-primary" id="twofaBtn">
                        <span id="twofaBtnText">验证</span>
                        <span class="spinner" id="twofaBtnSpinner"></span>
                    </button>
                    <button type="button" class="btn btn-secondary" id="backToLogin">返回登录</button>
                </form>
            </div>
        </div>
        <div class="back-link">
            <a href="/">← 返回首页</a>
        </div>
    </div>

    <script>
    const SITE_URL = <?= json_encode(SITE_URL) ?>;

    function showError(msg) {
        const el = document.getElementById('errorMsg');
        el.textContent = msg;
        el.classList.add('show');
    }

    function hideError() {
        document.getElementById('errorMsg').classList.remove('show');
    }

    function setLoading(btn, spinner, text, loading) {
        document.getElementById(btn).disabled = loading;
        document.getElementById(spinner).style.display = loading ? 'inline-block' : 'none';
        document.getElementById(text).textContent = loading ? '处理中...' : (btn === 'loginBtn' ? '登录' : '验证');
    }

    document.getElementById('loginForm').addEventListener('submit', function(e) {
        e.preventDefault();
        hideError();

        const qq = document.getElementById('qq').value.trim();
        const password = document.getElementById('password').value;

        if (!qq) { showError('请输入QQ号'); return; }
        if (!password) { showError('请输入密码'); return; }

        setLoading('loginBtn', 'btnSpinner', 'btnText', true);

        fetch(SITE_URL + '/api/admin/login.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: new URLSearchParams({
                csrf_token: '<?= $csrfToken ?>',
                qq: qq,
                password: password
            })
        })
        .then(r => r.json())
        .then(data => {
            setLoading('loginBtn', 'btnSpinner', 'btnText', false);
            if (data.success) {
                if (data.data && data.data.status === '2fa_required') {
                    document.getElementById('twofaTempToken').value = data.data.temp_token;
                    document.getElementById('stepLogin').classList.add('hidden');
                    document.getElementById('step2fa').classList.add('active');
                } else {
                    window.location.href = '/admin/dashboard.php';
                }
            } else {
                showError(data.message || '登录失败');
            }
        })
        .catch(() => {
            setLoading('loginBtn', 'btnSpinner', 'btnText', false);
            showError('网络错误，请重试');
        });
    });

    document.getElementById('twofaForm').addEventListener('submit', function(e) {
        e.preventDefault();
        hideError();

        const code = document.getElementById('twofaCode').value.trim();
        if (!code || code.length !== 6) {
            showError('请输入6位验证码');
            return;
        }

        setLoading('twofaBtn', 'twofaBtnSpinner', 'twofaBtnText', true);

        fetch(SITE_URL + '/api/admin/login.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: new URLSearchParams({
                csrf_token: '<?= $csrfToken ?>',
                action: 'verify_2fa',
                temp_token: document.getElementById('twofaTempToken').value,
                code: code
            })
        })
        .then(r => r.json())
        .then(data => {
            setLoading('twofaBtn', 'twofaBtnSpinner', 'twofaBtnText', false);
            if (data.success) {
                window.location.href = '/admin/dashboard.php';
            } else {
                showError(data.message || '验证失败');
            }
        })
        .catch(() => {
            setLoading('twofaBtn', 'twofaBtnSpinner', 'twofaBtnText', false);
            showError('网络错误，请重试');
        });
    });

    document.getElementById('backToLogin').addEventListener('click', function() {
        document.getElementById('stepLogin').classList.remove('hidden');
        document.getElementById('step2fa').classList.remove('active');
        document.getElementById('twofaCode').value = '';
        document.getElementById('twofaTempToken').value = '';
        hideError();
    });

    </script>
</body>
</html>
