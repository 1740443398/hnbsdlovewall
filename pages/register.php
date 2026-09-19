<?php

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/security.php';

$currentUser = getCurrentUser();
if ($currentUser) {
    header('Location: ' . SITE_URL . '/');
    exit();
}

$registerEnabled = getSetting('register_enabled', '1') == '1';
if (!$registerEnabled) {
    http_response_code(403);
    die('<!DOCTYPE html><html lang="zh-CN"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1.0"><title>注册已关闭</title><style>body{display:flex;align-items:center;justify-content:center;min-height:100vh;background:#F8F9FA;color:#1B2A3A;font-family:sans-serif;text-align:center;font-size:1.2rem;}</style></head><body><div><h1>注册已关闭</h1><p>管理员已关闭注册功能。</p></div></body></html>');
    exit();
}

$csrfToken = generateCSRFToken();
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <script src="/assets/js/anti_hijack.js"></script>
    <link rel="icon" href="/icon.ico" type="image/x-icon">
    <title>注册 - <?= SITE_NAME ?></title>
    <meta name="description" content="校园交流墙用户注册">
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

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'ZCOOL QingKe HuangYou', 'Noto Sans SC', 'PingFang SC', 'Microsoft YaHei', sans-serif;
            background: linear-gradient(160deg, #142B44 0%, #1B3A5C 55%, #0D1F35 100%);
            background-attachment: fixed;
            min-height: 100vh;
            display: flex;
            padding: 20px;
            padding-bottom: 64px;
            color: var(--text);
            -webkit-font-smoothing: antialiased;
            /* 只隐藏横向溢出，允许垂直滚动 */
            overflow-x: hidden;
            overflow-y: auto;
        }

        .auth-container {
            width: 100%;
            max-width: 440px;
            margin: auto; /* 用 margin:auto 居中，卡片较高时顶端对齐且可滚动，避免顶部被裁切 */
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
            box-shadow: 0 0 0 3px rgba(27,58,92,0.1);
        }

        .form-input.input-error {
            border-color: var(--danger);
            background: var(--danger-light);
        }

        .form-input.input-error:focus {
            box-shadow: 0 0 0 3px rgba(196,98,106,0.1);
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

        /* 密码框右侧为“显示/隐藏”按钮预留空间，避免文字被按钮遮挡 */
        .form-input.input-password {
            padding-right: 72px;
        }

        /* 点选式图形验证码 */
        .captcha-box {
            display: flex;
            align-items: center;
            gap: 12px;
        }
        .captcha-target {
            width: 88px;
            height: 88px;
            flex: 0 0 auto;
            border: 1px solid var(--border);
            border-radius: 12px;
            overflow: hidden;
            background: var(--surface);
            cursor: pointer;
        }
        .captcha-target img {
            width: 100%;
            height: 100%;
            display: block;
            object-fit: cover;
        }
        .captcha-tiles {
            display: grid;
            grid-template-columns: repeat(3, 44px);
            gap: 8px;
        }
        .cap-tile {
            width: 44px;
            height: 44px;
            font-size: 20px;
            font-weight: 700;
            color: var(--text);
            border: 1px solid var(--border);
            border-radius: 10px;
            background: var(--surface);
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            transition: border-color .15s, background .15s, transform .1s;
        }
        .cap-tile:hover {
            border-color: var(--primary);
        }
        .cap-tile:active {
            transform: scale(0.94);
        }
        .cap-tile.selected {
            border-color: var(--primary);
            background: var(--primary);
            color: #fff;
        }
        .captcha-hint {
            margin-top: 8px;
            font-size: 12px;
            color: var(--text-secondary);
        }
        .cap-math {
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 15px;
            font-weight: 600;
            color: var(--text);
        }
        .cap-math input {
            width: 64px;
            padding: 6px 10px;
            font-size: 16px;
            text-align: center;
            border: 1px solid var(--border);
            border-radius: 8px;
            background: var(--surface);
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

        .qq-avatar-preview {
            position: absolute;
            right: 44px;
            top: 50%;
            transform: translateY(-50%);
            width: 36px;
            height: 36px;
            border-radius: 50%;
            border: 2px solid var(--border);
            display: none;
            object-fit: cover;
            background: var(--bg);
        }

        .qq-avatar-preview.show {
            display: block;
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

        .form-select {
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
            appearance: none;
            background-image: url("data:image/svg+xml;charset=utf-8,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='8' viewBox='0 0 12 8'%3E%3Cpath fill='%235A6B7A' d='M1 1l5 5 5-5' stroke='%235A6B7A' stroke-width='1.5' fill='none' stroke-linecap='round' stroke-linejoin='round'/%3E%3C/svg%3E");
            background-repeat: no-repeat;
            background-position: right 14px center;
            padding-right: 36px;
            cursor: pointer;
        }
        .form-select:focus {
            border-color: var(--border-focus);
            background-color: var(--card-bg);
            box-shadow: 0 0 0 3px rgba(27,58,92,0.1);
        }
        .form-select:disabled {
            opacity: 0.5;
            cursor: not-allowed;
        }

        .form-hint {
            font-size: 12px;
            color: var(--text-secondary);
            margin-top: 4px;
            line-height: 1.5;
        }

        .opt-group-hint {
            margin: 6px 0 12px;
            padding: 8px 12px;
            font-size: 13px;
            color: #7a6a1f;
            background: rgba(184, 134, 11, 0.08);
            border: 1px dashed rgba(184, 134, 11, 0.4);
            border-radius: var(--radius, 10px);
        }

        .opt-mark {
            display: inline-block;
            margin-left: 6px;
            padding: 1px 8px;
            font-size: 11px;
            font-weight: 600;
            color: #B8860B;
            background: rgba(184, 134, 11, 0.12);
            border: 1px solid rgba(184, 134, 11, 0.35);
            border-radius: 9999px;
            vertical-align: middle;
        }

        .subject-grid {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            margin-top: 6px;
        }
        .subject-tag {
            position: relative;
        }
        .subject-tag input {
            position: absolute;
            opacity: 0;
            pointer-events: none;
        }
        .subject-tag span {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-width: 44px;
            padding: 8px 14px;
            font-size: 14px;
            border: 1px solid var(--border);
            border-radius: 10px;
            color: var(--text);
            background: var(--bg);
            cursor: pointer;
            user-select: none;
            transition: all var(--transition);
        }
        .subject-tag span:hover {
            border-color: var(--primary-light);
            color: var(--primary);
        }
        .subject-tag input:checked + span {
            border-color: var(--primary);
            background: var(--primary);
            color: #fff;
            box-shadow: 0 2px 8px rgba(27,58,92,0.2);
        }
        .subject-tag input:focus-visible + span {
            box-shadow: 0 0 0 3px rgba(27,58,92,0.2);
        }
        .subject-tag input:disabled + span {
            opacity: 0.45;
            cursor: not-allowed;
        }
        .subject-grid.--disabled .subject-tag span {
            opacity: 0.45;
            cursor: not-allowed;
        }

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

        @keyframes toastIn {
            from { opacity: 0; transform: translateX(-50%) translateY(-20px); }
            to { opacity: 1; transform: translateX(-50%) translateY(0); }
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
                <h1 class="auth-title">创建账号</h1>
                <p class="auth-subtitle">加入校园交流墙</p>
            </div>

            <div class="alert alert-error" id="alertError"></div>

            <form id="registerForm" autocomplete="off">
                <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
                <input type="hidden" name="_form_ts" id="formTs" value="<?= time() ?>">

                <div style="position:absolute;left:-9999px;top:-9999px;" aria-hidden="true">
                    <label for="website">Website</label>
                    <input type="text" id="website" name="website" tabindex="-1" autocomplete="off">
                </div>

                <div class="form-group">
                    <label class="form-label" for="username">用户名</label>
                    <div class="input-wrapper">
                        <input type="text" id="username" name="username" class="form-input" placeholder="给自己起个好记的名字" autocomplete="off">
                    </div>
                    <div class="error-message" id="usernameError">
                        <span id="usernameErrorText"></span>
                    </div>
                    <div class="success-message" id="usernameSuccess">
                        <span>用户名可用</span>
                    </div>
                </div>

                <div class="form-group">
                    <label class="form-label" for="qq">QQ号</label>
                    <div class="input-wrapper">
                        <input type="text" id="qq" name="qq" class="form-input" placeholder="请输入QQ号" maxlength="15" inputmode="numeric" autocomplete="off">
                        <img id="qqAvatar" class="qq-avatar-preview" src="" alt="QQ头像预览">
                    </div>
                    <div class="error-message" id="qqError">
                        <span id="qqErrorText"></span>
                    </div>
                    <div class="success-message" id="qqSuccess">
                        <span id="qqSuccessText"></span>
                    </div>
                </div>

                <div class="opt-group-hint">以下信息选填，可暂时跳过，注册后随时也能补充</div>
                <div class="form-group" id="gradeGroup">
                    <label class="form-label" for="grade">年级 <span class="opt-mark">选填</span></label>
                    <select id="grade" name="grade" class="form-select">
                        <option value="">请选择年级</option>
                        <?php for ($gi = 0; $gi <= 3; $gi++): ?>
                            <option value="<?= $gi ?>"><?= gradeLabel(entranceYearFromGrade($gi)) ?></option>
                        <?php endfor; ?>
                    </select>
                    <div class="form-hint">每年自动升一级，暑假期间会显示「新×高」。</div>
                </div>

                <div class="form-group" id="classGroup">
                    <label class="form-label" for="classNum">班级 <span class="opt-mark">选填</span></label>
                    <select id="classNum" name="class_num" class="form-select">
                        <option value="0">请选择班级</option>
                        <?php for ($ci = 1; $ci <= 20; $ci++): ?>
                            <option value="<?= $ci ?>"><?= $ci ?>班</option>
                        <?php endfor; ?>
                    </select>
                </div>

                <div class="form-group" id="realNameGroup">
                    <label class="form-label" for="realName">真实姓名 <span class="opt-mark">选填</span></label>
                    <div class="input-wrapper">
                        <input type="text" id="realName" name="real_name" class="form-input" placeholder="仅本人可见，用于证明身份" maxlength="20" autocomplete="off">
                    </div>
                    <div class="error-message" id="realNameError">
                        <span id="realNameErrorText"></span>
                    </div>
                </div>

                <div class="form-group">
                    <label class="form-label" for="password">密码</label>
                    <div class="input-wrapper">
                        <input type="password" id="password" name="password" class="form-input input-password" placeholder="至少6位，建议使用大小写字母、数字和符号增加强度" autocomplete="new-password">
                        <button type="button" class="input-icon-btn" id="togglePassword" title="显示/隐藏密码">
                            显示
                        </button>
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
                    <label class="form-label" for="confirmPassword">确认密码</label>
                    <div class="input-wrapper">
                        <input type="password" id="confirmPassword" name="confirm_password" class="form-input input-password" placeholder="请再次输入密码" autocomplete="new-password">
                        <button type="button" class="input-icon-btn" id="toggleConfirmPassword" title="显示/隐藏密码">
                            显示
                        </button>
                    </div>
                    <div class="error-message" id="confirmError">
                        <span id="confirmErrorText"></span>
                    </div>
                    <div class="success-message" id="confirmSuccess">
                        <span>密码匹配</span>
                    </div>
                </div>

                <div class="form-group" id="captchaGroup">
                    <label class="form-label" for="captcha">验证码</label>
                    <div class="captcha-box">
                        <div class="captcha-target" id="captchaTarget" title="点击刷新验证码"><img id="captchaTargetImg" alt="验证码"></div>
                        <div class="captcha-tiles" id="captchaTiles"></div>
                    </div>
                    <input type="hidden" id="captcha" name="captcha" value="">
                    <div class="captcha-hint" id="captchaHint"></div>
                    <div class="error-message" id="captchaError">
                        <span id="captchaErrorText"></span>
                    </div>
                </div>

                <button type="submit" class="btn btn-primary" id="submitBtn">
                    <span id="btnText">注 册</span>
                    <span class="spinner" id="btnSpinner" style="display:none"></span>
                </button>
            </form>

            <div class="auth-footer">
                <span>已有账号？</span>
                <a href="<?= SITE_URL ?>/pages/login.php">立即登录</a>
            </div>

            <div class="auth-links">
                <a href="<?= SITE_URL ?>/">返回首页</a>
            </div>
        </div>
    </div>

    <script>
    (function() {
        const SITE_URL = <?= json_encode(SITE_URL) ?>;
        const CSRF_TOKEN = '<?= $csrfToken ?>';

        const registerForm = document.getElementById('registerForm');
        const qqInput = document.getElementById('qq');
        const qqAvatar = document.getElementById('qqAvatar');
        const qqError = document.getElementById('qqError');
        const qqErrorText = document.getElementById('qqErrorText');
        const qqSuccess = document.getElementById('qqSuccess');
        const qqSuccessText = document.getElementById('qqSuccessText');
        const passwordInput = document.getElementById('password');
        const confirmInput = document.getElementById('confirmPassword');
        const togglePasswordBtn = document.getElementById('togglePassword');
        const toggleConfirmBtn = document.getElementById('toggleConfirmPassword');
        const passwordStrength = document.getElementById('passwordStrength');
        const strengthBar1 = document.getElementById('strengthBar1');
        const strengthBar2 = document.getElementById('strengthBar2');
        const strengthBar3 = document.getElementById('strengthBar3');
        const strengthText = document.getElementById('strengthText');
        const passwordError = document.getElementById('passwordError');
        const passwordErrorText = document.getElementById('passwordErrorText');
        const confirmError = document.getElementById('confirmError');
        const confirmErrorText = document.getElementById('confirmErrorText');
        const confirmSuccess = document.getElementById('confirmSuccess');
        const submitBtn = document.getElementById('submitBtn');
        const btnText = document.getElementById('btnText');
        const btnSpinner = document.getElementById('btnSpinner');
        const alertError = document.getElementById('alertError');
        const usernameInput = document.getElementById('username');
        const usernameError = document.getElementById('usernameError');
        const usernameErrorText = document.getElementById('usernameErrorText');
        const usernameSuccess = document.getElementById('usernameSuccess');
        const gradeSelect = document.getElementById('grade');
        const classSelect = document.getElementById('classNum');
        const realNameInput = document.getElementById('realName');
        const realNameError = document.getElementById('realNameError');
        const realNameErrorText = document.getElementById('realNameErrorText');

        let isSubmitting = false;

        function hideAlert() {
            alertError.classList.remove('show');
            alertError.textContent = '';
        }

        function showAlert(message) {
            hideAlert();
            alertError.textContent = message;
            alertError.classList.add('show');
        }

        function showToast(message, type) {
            const existing = document.querySelector('.toast');
            if (existing) existing.remove();
            const toast = document.createElement('div');
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

        let qqCheckTimer = null;

        function validateUsername() {
            const name = usernameInput.value.trim();
            usernameError.classList.remove('show');
            usernameSuccess.classList.remove('show');
            usernameInput.classList.remove('input-error', 'input-success');

            if (name.length === 0) {
                usernameErrorText.textContent = '请输入用户名';
                usernameError.classList.add('show');
                usernameInput.classList.add('input-error');
                return false;
            }

            usernameInput.classList.add('input-success');
            return true;
        }

        usernameInput.addEventListener('input', function() {
            hideAlert();
            validateUsername();
        });
        qqInput.addEventListener('input', function() {
            const qq = this.value.trim();
            hideAlert();
            this.value = qq.replace(/\D/g, '');
            const cleaned = this.value;

            qqError.classList.remove('show');
            qqSuccess.classList.remove('show');
            qqInput.classList.remove('input-error', 'input-success');

            if (cleaned.length === 0) {
                qqAvatar.classList.remove('show');
                return;
            }

            if (!/^\d+$/.test(cleaned)) {
                showQQError('QQ号只能包含数字');
                qqAvatar.classList.remove('show');
                return;
            }

            if (cleaned.length >= 1 && !/^[1-9]/.test(cleaned)) {
                showQQError('QQ号不能以0开头');
                qqAvatar.classList.remove('show');
                return;
            }

            if (cleaned.length >= 5) {
                if (!/^[1-9][0-9]{4,14}$/.test(cleaned)) {
                    showQQError('QQ号格式不正确（5-15位数字）');
                    qqAvatar.classList.remove('show');
                    return;
                }

                qqAvatar.src = 'https://q.qlogo.cn/headimg_dl?dst_uin=' + cleaned + '&spec=100';
                qqAvatar.classList.add('show');
                qqInput.classList.add('input-success');

                clearTimeout(qqCheckTimer);
                qqCheckTimer = setTimeout(function() {
                    checkQQRegistered(cleaned);
                }, 500);
            } else {
                qqAvatar.classList.remove('show');
            }
        });

        function showQQError(msg) {
            qqErrorText.textContent = msg;
            qqError.classList.add('show');
            qqInput.classList.add('input-error');
            qqSuccess.classList.remove('show');
            qqInput.classList.remove('input-success');
            qqAvatar.classList.remove('show');
        }

        function checkQQRegistered(qq) {
            var formData = new FormData();
            formData.append('csrf_token', CSRF_TOKEN);
            formData.append('qq', qq);
            formData.append('check_only', '1');

            fetch(SITE_URL + '/api/auth/register.php?check=1', {
                method: 'POST',
                body: formData
            })
            .then(function(res) { return res.json(); })
            .then(function(data) {
                if (data.success) {

                    qqSuccessText.textContent = '该QQ号可以注册';
                    qqSuccess.classList.add('show');
                    qqError.classList.remove('show');
                    qqInput.classList.remove('input-error');
                    qqInput.classList.add('input-success');
                } else {

                    showQQError(data.message || '该QQ号已被注册');
                }
            })
            .catch(function() {

            });
        }

        passwordInput.addEventListener('input', function() {
            const pwd = this.value;
            passwordError.classList.remove('show');

            if (pwd.length === 0) {
                passwordStrength.style.display = 'none';
                return;
            }

            passwordStrength.style.display = 'flex';

            const bars = [strengthBar1, strengthBar2, strengthBar3];
            let score = 0;

            if (pwd.length >= 8) score++;
            if (pwd.length >= 12) score++;
            if (/[a-z]/.test(pwd)) score++;
            if (/[A-Z]/.test(pwd)) score++;
            if (/[0-9]/.test(pwd)) score++;
            if (/[^a-zA-Z0-9]/.test(pwd)) score++;

            let level, colorClass;
            if (score <= 2) {
                level = '弱';
                colorClass = 'weak';
                bars[0].className = 'strength-bar weak';
                bars[1].className = 'strength-bar';
                bars[2].className = 'strength-bar';
            } else if (score <= 3) {
                level = '中等';
                colorClass = 'medium';
                bars[0].className = 'strength-bar medium';
                bars[1].className = 'strength-bar medium';
                bars[2].className = 'strength-bar';
            } else if (score <= 4) {
                level = '强';
                colorClass = 'strong';
                bars[0].className = 'strength-bar strong';
                bars[1].className = 'strength-bar strong';
                bars[2].className = 'strength-bar strong';
            } else {
                level = '非常强';
                colorClass = 'strong';
                bars[0].className = 'strength-bar strong';
                bars[1].className = 'strength-bar strong';
                bars[2].className = 'strength-bar strong';
            }

            strengthText.textContent = level;
            strengthText.className = 'strength-text ' + colorClass;

            checkPasswordMatch();
        });

        confirmInput.addEventListener('input', function() {
            checkPasswordMatch();
        });

        function checkPasswordMatch() {
            const pwd = passwordInput.value;
            const confirmPwd = confirmInput.value;

            confirmError.classList.remove('show');
            confirmSuccess.classList.remove('show');
            confirmInput.classList.remove('input-error', 'input-success');

            if (confirmPwd.length === 0) return;

            if (pwd !== confirmPwd) {
                confirmErrorText.textContent = '两次输入的密码不一致';
                confirmError.classList.add('show');
                confirmInput.classList.add('input-error');
            } else {
                confirmSuccess.classList.add('show');
                confirmInput.classList.add('input-success');
            }
        }

        togglePasswordBtn.addEventListener('click', function() {
            passwordInput.type = passwordInput.type === 'password' ? 'text' : 'password';
            this.textContent = passwordInput.type === 'password' ? '显示' : '隐藏';
        });

        toggleConfirmBtn.addEventListener('click', function() {
            confirmInput.type = confirmInput.type === 'password' ? 'text' : 'password';
            this.textContent = confirmInput.type === 'password' ? '显示' : '隐藏';
        });

        // ---- 选科选项已移除（不做高考选科采集） ----

        function validateProfileFields() {
            // 年级：若已选择，校验范围
            var gradeVal = gradeSelect ? gradeSelect.value : '';
            if (gradeVal !== '' && !(/^[0-3]$/.test(gradeVal))) {
                showAlert('年级选择无效');
                if (gradeSelect) gradeSelect.focus();
                return false;
            }

            // 班级：0-20
            var classVal = classSelect ? classSelect.value : '0';
            if (!(/^(0|[1-9]|1[0-9]|20)$/.test(classVal))) {
                showAlert('班级选择无效');
                if (classSelect) classSelect.focus();
                return false;
            }

            return true;
        }

        function validateRealName() {
            realNameError.classList.remove('show');
            realNameInput.classList.remove('input-error');
            var name = realNameInput.value.trim();
            if (name.length === 0) return true;
            if (name.length < 2 || name.length > 20) {
                realNameErrorText.textContent = '姓名长度需在 2-20 个字符之间';
                realNameError.classList.add('show');
                realNameInput.classList.add('input-error');
                return false;
            }
            return true;
        }

        realNameInput.addEventListener('input', function() {
            hideAlert();
            validateRealName();
        });

        registerForm.addEventListener('submit', function(e) {
            e.preventDefault();

            if (isSubmitting) return;
            hideAlert();

            const qq = qqInput.value.trim();
            const password = passwordInput.value;
            const confirmPassword = confirmInput.value;
            const username = usernameInput.value.trim();

            if (!validateUsername()) {
                showAlert(usernameErrorText.textContent || '请输入正确的用户名');
                usernameInput.focus();
                return;
            }

            if (!validateProfileFields()) return;

            if (!validateRealName()) {
                realNameInput.focus();
                return;
            }

            if (!qq || !/^[1-9][0-9]{4,14}$/.test(qq)) {
                showAlert('请输入正确的QQ号（5-15位数字）');
                qqInput.focus();
                return;
            }

            if (password.length < 6) {
                showAlert('密码长度不能少于6位');
                passwordInput.focus();
                return;
            }

            if (password !== confirmPassword) {
                showAlert('两次输入的密码不一致');
                confirmInput.focus();
                return;
            }

            var captchaVal = document.getElementById('captcha') ? document.getElementById('captcha').value.trim() : '';
            if (!captchaVal) {
                showAlert('请输入验证码答案');
                document.getElementById('captcha').focus();
                return;
            }

            isSubmitting = true;
            setLoading(submitBtn, btnText, btnSpinner, true);

            var formData = new FormData();
            formData.append('csrf_token', CSRF_TOKEN);
            formData.append('qq', qq);
            formData.append('username', username);
            formData.append('password', password);
            formData.append('confirm_password', confirmPassword);
            formData.append('captcha', captchaVal);
            formData.append('grade', gradeSelect ? gradeSelect.value : '');
            formData.append('class_num', classSelect ? classSelect.value : '0');
            formData.append('real_name', realNameInput ? realNameInput.value.trim() : '');

            fetch(SITE_URL + '/api/auth/register.php', {
                method: 'POST',
                body: formData
            })
            .then(function(res) { return res.json(); })
            .then(function(data) {
                if (data.success) {
                    // 首次注册登录：记录本月已展示赞助窗口，避免注册完成后自动弹出赞助弹窗
                    try {
                        var _now = new Date();
                        localStorage.setItem('sponsor_shown_month', _now.getFullYear() + '-' + (_now.getMonth() + 1));
                    } catch (e) {}
                    showToast('注册成功，正在跳转...', 'success');
                    setTimeout(function() {
                        window.location.href = SITE_URL + '/';
                    }, 800);
                } else {
                    showAlert(data.message || '注册失败');
                    // 验证码为一次性，无论何种失败都刷新新题，避免下次提交仍被拦截
                    loadCaptcha();
                    var cap = document.getElementById('captcha');
                    if (cap) cap.value = '';
                    setLoading(submitBtn, btnText, btnSpinner, false);
                    isSubmitting = false;
                }
            })
            .catch(function(err) {
                showAlert('网络错误，请检查网络连接后重试');
                setLoading(submitBtn, btnText, btnSpinner, false);
                isSubmitting = false;
            });
        });

        function renderCaptchaBox(ch) {
            var capInput = document.getElementById('captcha');
            var target = document.getElementById('captchaTarget');
            var img = document.getElementById('captchaTargetImg');
            var tilesEl = document.getElementById('captchaTiles');
            var hint = document.getElementById('captchaHint');
            if (capInput) capInput.value = '';

            if (ch.mode === 'math') {
                if (img && target) { target.innerHTML = ''; target.style.display = 'none'; }
                if (tilesEl) {
                    tilesEl.innerHTML = '<div class="cap-math">' + (ch.question || '') +
                        ' <input type="text" id="capMathInput" maxlength="2" inputmode="numeric" autocomplete="off"></div>';
                    var mi = document.getElementById('capMathInput');
                    if (mi) {
                        mi.focus();
                        mi.addEventListener('input', function() { if (capInput) capInput.value = mi.value.trim(); });
                    }
                }
                if (hint) hint.textContent = '请输入算式答案';
                return;
            }

            if (target) target.style.display = '';
            if (img) img.src = ch.target;
            if (tilesEl) {
                tilesEl.innerHTML = '';
                (ch.tiles || []).forEach(function(t) {
                    var btn = document.createElement('button');
                    btn.type = 'button';
                    btn.className = 'cap-tile';
                    btn.textContent = t;
                    btn.setAttribute('data-v', t);
                    btn.addEventListener('click', function() {
                        var all = tilesEl.querySelectorAll('.cap-tile');
                        for (var i = 0; i < all.length; i++) all[i].classList.remove('selected');
                        btn.classList.add('selected');
                        if (capInput) capInput.value = t;
                        if (hint) hint.textContent = '已选：' + t + '（再次点击上方小图可换一道）';
                    });
                    tilesEl.appendChild(btn);
                });
            }
            if (hint) hint.textContent = '请从下方 6 个中选出与上方图片相同的字符';
        }

        function loadCaptcha() {
            var capInput = document.getElementById('captcha');
            if (capInput) capInput.value = '';
            fetch(SITE_URL + '/api/captcha.php?scene=register')
                .then(function(res) { return res.json(); })
                .then(function(res) {
                    if (res && res.success && res.data) renderCaptchaBox(res.data);
                })
                .catch(function() {});
        }

        (function() {
            var target = document.getElementById('captchaTarget');
            if (target) target.addEventListener('click', loadCaptcha);
        })();
        loadCaptcha();

        qqInput.focus();
    })();
    </script>
</body>
</html>