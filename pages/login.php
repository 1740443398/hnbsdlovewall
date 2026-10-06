<?php

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/security.php';

$currentUser = getCurrentUser();
if ($currentUser) {
    header('Location: ' . SITE_URL . '/');
    exit();
}

// 前置安全声明页：未确认过“谨防钓鱼”声明则先到 gateway 页
if (!hasAcceptedGateway()) {
    header('Location: ' . SITE_URL . '/pages/gateway.php?to=login');
    exit();
}

// 首访且未注册的新设备：默认引导去注册
if (isFirstVisitUnregistered()) {
    header('Location: ' . SITE_URL . '/pages/register.php');
    exit();
}

$csrfToken = generateCSRFToken();

// 防缓存：CSRF_TOKEN 存 session 且每次请求刷新，页面被浏览器缓存会沿用旧的
// token 导致换日后登录 403「CSRF验证失败」。强制不缓存登录页。
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');
?>
<!DOCTYPE html>
<html lang="<?= $LANG_CODE ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <?php require_once __DIR__ . '/../includes/pwa_head.php'; ?>
    <?php require_once __DIR__ . '/../includes/theme_dark_tokens.php'; ?>
    <script src="<?= asset_url('/assets/js/anti_hijack.js') ?>?v=<?= asset_ver('/assets/js/anti_hijack.js') ?>"></script>
    <link rel="icon" href="/icon.ico" type="image/x-icon">
    <title><?= t('login.page_title') ?> - <?= SITE_NAME ?></title>
    <meta name="description" content="<?= htmlspecialchars(t('login.meta_desc')) ?>">
    <style>
        :root {
            --primary: #2F5B9A;
            --primary-hover: #24487C;
            --primary-dark: #1E3A63;
            --primary-light: #E7EEF7;
            --accent: #C9A96E;
            --accent-light: #F8F3E7;
            --danger: #C2453F;
            --danger-light: #FBE9E8;
            --success: #2E8B57;
            --success-light: #E6F4EC;
            --warning: #C7902A;
            --warning-light: #FBF3E3;
            --bg: #F6F7F9;
            --bg-secondary: #EEF0F3;
            --card-bg: #FFFFFF;
            --text: #1C2733;
            --text-secondary: #5B6B7B;
            --text-muted: #8E9AA8;
            --border: #E3E7EC;
            --border-light: #EEF1F4;
            --border-focus: #2F5B9A;
            --radius: 14px;
            --radius-sm: 10px;
            --radius-full: 9999px;
            --shadow: 0 2px 10px rgba(16,24,40,0.06);
            --shadow-hover: 0 8px 24px rgba(47,91,154,0.16);
            --transition: 0.24s ease;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Noto Sans SC', -apple-system, BlinkMacSystemFont, 'PingFang SC', 'Microsoft YaHei', 'Segoe UI', Arial, sans-serif;
            background: var(--bg);
            background-attachment: fixed;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
            color: var(--text);
            -webkit-font-smoothing: antialiased;
            /* 只隐藏横向溢出，允许垂直滚动，避免内容看不全 */
            overflow-x: hidden;
            overflow-y: auto;
        }

        .auth-container {
            width: 100%;
            max-width: 420px;
        }

        .auth-card {
            background: var(--card-bg);
            /* 24px = 全站卡片统一圆角（--radius-card），与首页/帖子卡片保持一致 */
            border-radius: 24px;
            box-shadow: var(--shadow-hover);
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
            font-family: 'Noto Serif SC', 'Source Han Serif SC', 'Songti SC', 'STSong', 'SimSun', 'Times New Roman', serif;
            font-size: 24px;
            font-weight: 700;
            letter-spacing: -0.02em;
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

        .form-input.input-error:focus {
            box-shadow: 0 0 0 3px rgba(231, 76, 60, 0.1);
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

        .qq-avatar-preview {
            position: absolute;
            right: 44px;
            top: 50%;
            transform: translateY(-50%);
            width: 32px;
            height: 32px;
            border-radius: 50%;
            border: 2px solid var(--border);
            display: none;
            object-fit: cover;
            background: var(--bg);
        }

        .qq-avatar-preview.show {
            display: block;
        }

        .checkbox-group {
            display: flex;
            align-items: center;
            gap: 8px;
            margin-bottom: 20px;
        }

        .checkbox-group input[type="checkbox"] {
            width: 18px;
            height: 18px;
            accent-color: var(--primary);
            cursor: pointer;
        }

        .checkbox-group label {
            font-size: 14px;
            color: var(--text-secondary);
            cursor: pointer;
            user-select: none;
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
            background: var(--primary);
            color: #fff;
            border-radius: var(--radius-full);
            box-shadow: 0 4px 14px rgba(47, 91, 154, 0.28);
        }

        .btn-primary:hover {
            background: var(--primary-hover);
            transform: translateY(-1px);
            box-shadow: 0 6px 20px rgba(47, 91, 154, 0.36);
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
            border-radius: var(--radius-full);
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
            justify-content: space-between;
            align-items: center;
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

        .alert-info {
            background: var(--primary-light);
            color: var(--primary-dark);
            border: 1px solid var(--primary);
        }

        .twofa-overlay {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0,0,0,0.5);
            display: none;
            align-items: center;
            justify-content: center;
            z-index: 1000;
            backdrop-filter: blur(4px);
        }

        .twofa-overlay.show {
            display: flex;
        }

        .twofa-modal {
            background: var(--card-bg);
            border-radius: var(--radius);
            padding: 32px;
            width: 90%;
            max-width: 380px;
            box-shadow: 0 20px 60px rgba(0,0,0,0.2);
            text-align: center;
        }

        .twofa-modal h3 {
            font-size: 20px;
            margin-bottom: 8px;
        }

        .twofa-modal p {
            font-size: 14px;
            color: var(--text-secondary);
            margin-bottom: 20px;
        }

        .twofa-code-input {
            display: flex;
            gap: 8px;
            justify-content: center;
            margin-bottom: 20px;
        }

        .twofa-code-input input {
            width: 44px;
            height: 52px;
            text-align: center;
            font-size: 22px;
            font-weight: 700;
            border: 2px solid var(--border);
            border-radius: var(--radius-sm);
            outline: none;
            transition: border-color var(--transition);
            font-family: 'Courier New', monospace;
        }

        .twofa-code-input input:focus {
            border-color: var(--border-focus);
        }

        .twofa-actions {
            display: flex;
            gap: 12px;
            flex-direction: column;
        }

        .twofa-timer {
            font-size: 13px;
            color: var(--text-secondary);
            margin-bottom: 8px;
        }

        .twofa-timer .countdown {
            color: var(--danger);
            font-weight: 600;
        }

        .recovery-link {
            background: none;
            border: none;
            color: var(--primary);
            cursor: pointer;
            font-size: 13px;
            text-decoration: underline;
            padding: 4px;
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

        /* 移动端底部导航：与全站一致的悬浮胶囊（居中、脱离屏幕左右边缘） */
        .mobile-bottom-nav {
            display: none;
            position: fixed;
            left: 50%;
            right: auto;
            bottom: calc(14px + env(safe-area-inset-bottom, 0px));
            transform: translateX(-50%);
            z-index: 100;
            max-width: calc(100vw - 24px);
            background: rgba(255, 255, 255, 0.86);
            backdrop-filter: blur(20px) saturate(160%);
            -webkit-backdrop-filter: blur(20px) saturate(160%);
            border: 1px solid rgba(255, 255, 255, 0.9);
            border-radius: 9999px;
            padding: 6px 8px;
            justify-content: space-around;
            align-items: center;
            gap: 2px;
            box-shadow: 0 14px 32px -14px rgba(16, 24, 40, 0.30);
        }
        .mobile-nav-item {
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 2px;
            padding: 6px 12px;
            border-radius: 9999px;
            text-decoration: none;
            color: var(--text-secondary);
            font-size: 12px;
            font-weight: 500;
            min-width: 54px;
        }
        .mobile-nav-item svg { width: 21px; height: 21px; }
        .mobile-nav-item.active {
            background: var(--primary);
            color: #fff;
            box-shadow: 0 8px 18px -8px rgba(47, 91, 154, 0.65);
        }
        .mobile-nav-item:active { background: var(--primary-light); color: var(--primary); }
        body { padding-bottom: 0; }
        @media (max-width: 768px) {
            .mobile-bottom-nav { display: flex; }
            body { padding-bottom: calc(92px + env(safe-area-inset-bottom, 0px)); }
        }

        /* ===== 手机端体验优化（再次优化） ===== */
        html { -webkit-text-size-adjust: 100%; }

        /* 显示/隐藏密码按钮加大触控区，且不超出输入框高度 */
        .input-icon-btn {
            min-width: 40px;
            min-height: 44px;
        }

        /* 2FA 弹窗：内容超高时可滚动，避免固定全屏陷阱 */
        .twofa-overlay {
            overflow-y: auto;
            padding: 24px 12px;
        }
        .twofa-modal {
            margin: auto;
        }

        @media (max-width: 768px) {
            /* 卡片超高时改为顶部对齐，避免 flex 居中裁切顶部（如 iOS 键盘弹出时） */
            body { align-items: flex-start; }
            .auth-container { margin: 16px auto; }
        }

        @media (max-width: 480px) {
            /* 底部预留必须 ≥ 底部导航「下边距 14px + 自身高度 ≈63px」，
               原来写死的 68px 会让导航压住最后一截内容，这里与全站统一为 5.5rem + 安全区 */
            body { padding: 12px 12px calc(5.5rem + env(safe-area-inset-bottom, 0px)); }
            .auth-card { padding: 24px 16px; }
            /* 验证码 88px 目标 + 3×44px 方块在窄屏可换行，避免横向溢出 */
            .captcha-box { flex-wrap: wrap; }
            .cap-math { flex-wrap: wrap; }
            .checkbox-group input[type="checkbox"] { width: 22px; height: 22px; }
            /* iOS 聚焦不自动缩放（>=16px），同时保持输入框可点区域 >= 44px */
            .form-input { padding: 11px 14px; font-size: 16px; }
            .cap-math input { font-size: 16px; }
            .twofa-modal { padding: 24px 16px; }
            .twofa-code-input { gap: 4px; }
            .twofa-code-input input {
                width: auto;
                flex: 1;
                min-width: 0;
                max-width: 48px;
                height: 48px;
                font-size: 20px;
            }
        }
    </style>
</head>
<body>
    <?php require __DIR__ . '/../includes/ai_widget.php'; ?>
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
                <h1 class="auth-title"><?= t('login.welcome_back') ?></h1>
                <p class="auth-subtitle"><?= t('login.subtitle') ?></p>
            </div>

            <div class="alert alert-error" id="alertError"></div>
            <div class="alert alert-info" id="alertInfo"></div>

            <form id="loginForm" autocomplete="off">
                <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">

                <div class="form-group">
                    <label class="form-label" for="qq"><?= t('login.qq') ?></label>
                    <div class="input-wrapper">
                        <input type="text" id="qq" name="qq" class="form-input" placeholder="<?= t('login.qq_placeholder') ?>" maxlength="15" inputmode="numeric" autocomplete="off">
                        <img id="qqAvatar" class="qq-avatar-preview" src="" alt="<?= t('login.qq_avatar_alt') ?>">
                    </div>
                    <div class="error-message" id="qqError">
                        <span id="qqErrorText"></span>
                    </div>
                </div>

                <div class="form-group">
                    <label class="form-label" for="password"><?= t('login.password') ?></label>
                    <div class="input-wrapper">
                        <input type="password" id="password" name="password" class="form-input input-password" placeholder="<?= t('login.password_placeholder') ?>" autocomplete="off">
                        <button type="button" class="input-icon-btn" id="togglePassword" title="<?= t('login.toggle_password_title') ?>">
                            <?= t('login.show_password') ?>
                        </button>
                    </div>
                    <div class="error-message" id="passwordError">
                        <span id="passwordErrorText"></span>
                    </div>
                </div>

                <div class="checkbox-group">
                    <input type="checkbox" id="rememberMe" name="remember_me" value="1">
                    <label for="rememberMe"><?= t('login.remember_me') ?></label>
                </div>

                <div class="form-group" id="captchaGroup">
                    <label class="form-label" for="captcha"><?= t('login.captcha') ?></label>
                    <div class="captcha-box">
                        <div class="captcha-target" id="captchaTarget" title="<?= t('login.captcha_refresh_title') ?>"><img id="captchaTargetImg" alt="<?= t('login.captcha_alt') ?>"></div>
                        <div class="captcha-tiles" id="captchaTiles"></div>
                    </div>
                    <input type="hidden" id="captcha" name="captcha" value="">
                    <div class="captcha-hint" id="captchaHint"></div>
                </div>

                <button type="submit" class="btn btn-primary" id="submitBtn">
                    <span id="btnText"><?= t('login.submit') ?></span>
                    <span class="spinner" id="btnSpinner" style="display:none"></span>
                </button>
            </form>

            <div class="auth-footer">
                <span><?= t('login.no_account') ?></span>
                <a href="<?= SITE_URL ?>/pages/register.php"><?= t('login.register_now') ?></a>
            </div>

            <div class="auth-links">
                <a href="<?= SITE_URL ?>/pages/gateway.php?browse=1"><?= t('guest.enter') ?></a>
                <a href="<?= SITE_URL ?>/pages/forgot_password.php"><?= t('login.forgot_password') ?></a>
                <a href="<?= SITE_URL ?>/"><?= t('login.back_home') ?></a>
            </div>
        </div>
    </div>

    <div class="twofa-overlay" id="twofaOverlay">
        <div class="twofa-modal">
            <h3><?= t('login.twofa_title') ?></h3>
            <p><?= t('login.twofa_desc') ?></p>
            <div class="twofa-code-input" id="twofaCodeInput">
                <input type="text" maxlength="1" inputmode="numeric" pattern="[0-9]" data-index="0">
                <input type="text" maxlength="1" inputmode="numeric" pattern="[0-9]" data-index="1">
                <input type="text" maxlength="1" inputmode="numeric" pattern="[0-9]" data-index="2">
                <input type="text" maxlength="1" inputmode="numeric" pattern="[0-9]" data-index="3">
                <input type="text" maxlength="1" inputmode="numeric" pattern="[0-9]" data-index="4">
                <input type="text" maxlength="1" inputmode="numeric" pattern="[0-9]" data-index="5">
            </div>
            <div class="twofa-timer" id="twofaTimer">
                <?= t('login.twofa_session_left') ?><span class="countdown" id="twofaCountdown">5:00</span>
            </div>
            <div class="twofa-actions">
                <button type="button" class="btn btn-primary" id="twofaSubmitBtn">
                    <span id="twofaBtnText"><?= t('login.twofa_verify_login') ?></span>
                    <span class="spinner" id="twofaBtnSpinner" style="display:none"></span>
                </button>
                <button type="button" class="btn btn-secondary" id="twofaCancelBtn"><?= t('login.twofa_cancel') ?></button>
                <button type="button" class="recovery-link" id="useRecoveryCode"><?= t('login.twofa_use_recovery') ?></button>
                <div class="form-group" id="recoveryCodeGroup" style="display:none;margin-top:8px;">
                    <input type="text" id="recoveryCodeInput" class="form-input" placeholder="<?= t('login.twofa_recovery_placeholder') ?>" maxlength="10" autocomplete="off">
                </div>
            </div>
        </div>
    </div>

    <script>
    (function() {
        const SITE_URL = <?= json_encode(SITE_URL) ?>;
        // var 而非 const：令牌被服务端判过期时要在运行期换新（见下方 lwAuthPost）。
        var CSRF_TOKEN = '<?= $csrfToken ?>';

        // 带 CSRF 自愈的 POST：403 响应体带 new_csrf_token 时，静默换新令牌重试一次，
        // 用户无需手动刷新。触发场景：表单页停留太久 / 多标签页换签 / 会话被主机弄丢。
        function lwAuthPost(url, formData, retried) {
            return fetch(url, { method: 'POST', body: formData })
                .then(function (res) { return res.json(); })
                .then(function (data) {
                    if (data && !data.success && data.new_csrf_token && !retried) {
                        CSRF_TOKEN = data.new_csrf_token;
                        formData.set('csrf_token', CSRF_TOKEN);
                        return lwAuthPost(url, formData, true);
                    }
                    return data;
                });
        }

        const loginForm = document.getElementById('loginForm');
        const qqInput = document.getElementById('qq');
        const passwordInput = document.getElementById('password');
        const qqAvatar = document.getElementById('qqAvatar');
        const qqError = document.getElementById('qqError');
        const qqErrorText = document.getElementById('qqErrorText');
        const passwordError = document.getElementById('passwordError');
        const passwordErrorText = document.getElementById('passwordErrorText');
        const togglePasswordBtn = document.getElementById('togglePassword');
        const submitBtn = document.getElementById('submitBtn');
        const btnText = document.getElementById('btnText');
        const btnSpinner = document.getElementById('btnSpinner');
        const alertError = document.getElementById('alertError');
        const alertInfo = document.getElementById('alertInfo');
        const rememberMe = document.getElementById('rememberMe');

        const twofaOverlay = document.getElementById('twofaOverlay');
        const twofaCodeInputs = document.querySelectorAll('#twofaCodeInput input');
        const twofaSubmitBtn = document.getElementById('twofaSubmitBtn');
        const twofaBtnText = document.getElementById('twofaBtnText');
        const twofaBtnSpinner = document.getElementById('twofaBtnSpinner');
        const twofaCancelBtn = document.getElementById('twofaCancelBtn');
        const twofaCountdown = document.getElementById('twofaCountdown');
        const useRecoveryCodeBtn = document.getElementById('useRecoveryCode');
        const recoveryCodeGroup = document.getElementById('recoveryCodeGroup');
        const recoveryCodeInput = document.getElementById('recoveryCodeInput');

        let isSubmitting = false;
        let twofaTempToken = null;
        let twofaTimerInterval = null;
        let twofaExpiryTime = null;
        let useRecoveryMode = false;

        function hideAlerts() {
            alertError.classList.remove('show');
            alertError.textContent = '';
            alertInfo.classList.remove('show');
            alertInfo.textContent = '';
        }

        function showAlert(message) {
            hideAlerts();
            alertError.textContent = message;
            alertError.classList.add('show');
        }

        function showInfo(message) {
            hideAlerts();
            alertInfo.textContent = message;
            alertInfo.classList.add('show');
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

        qqInput.addEventListener('input', function() {
            const qq = this.value.trim();
            hideAlerts();

            this.value = qq.replace(/\D/g, '');

            const cleaned = this.value;

            if (cleaned.length === 0) {
                qqError.classList.remove('show');
                qqInput.classList.remove('input-error');
                qqAvatar.classList.remove('show');
                return;
            }

            if (!/^\d+$/.test(cleaned)) {
                showQQError(__t('login.err_qq_digits_only'));
                qqAvatar.classList.remove('show');
                return;
            }

            if (cleaned.length >= 1 && !/^[1-9]/.test(cleaned)) {
                showQQError(__t('login.err_qq_no_zero_start'));
                qqAvatar.classList.remove('show');
                return;
            }

            if (cleaned.length >= 5) {
                if (!/^[1-9][0-9]{4,14}$/.test(cleaned)) {
                    showQQError(__t('login.err_qq_invalid'));
                    qqAvatar.classList.remove('show');
                    return;
                }

                qqError.classList.remove('show');
                qqInput.classList.remove('input-error');
                qqAvatar.src = 'https://q.qlogo.cn/headimg_dl?dst_uin=' + cleaned + '&spec=100';
                qqAvatar.classList.add('show');
            } else {
                qqError.classList.remove('show');
                qqInput.classList.remove('input-error');
                qqAvatar.classList.remove('show');
            }
        });

        function showQQError(msg) {
            qqErrorText.textContent = msg;
            qqError.classList.add('show');
            qqInput.classList.add('input-error');
        }

        togglePasswordBtn.addEventListener('click', function() {
            const type = passwordInput.type === 'password' ? 'text' : 'password';
            passwordInput.type = type;
            this.textContent = type === 'password' ? __t('login.show_password') : __t('login.hide_password');
        });

        loginForm.addEventListener('submit', function(e) {
            e.preventDefault();

            if (isSubmitting) return;

            hideAlerts();

            const qq = qqInput.value.trim();
            const password = passwordInput.value;

            if (!qq || !/^[1-9][0-9]{4,14}$/.test(qq)) {
                showAlert(__t('login.err_qq_required'));
                qqInput.focus();
                return;
            }

            if (!password) {
                showAlert(__t('login.err_password_required'));
                passwordInput.focus();
                return;
            }

            var captchaInput = document.getElementById('captcha');
            var captchaVal = captchaInput ? captchaInput.value.trim() : '';
            if (!captchaVal) {
                showAlert(__t('login.err_captcha_required'));
                if (captchaInput) captchaInput.focus();
                return;
            }

            isSubmitting = true;
            setLoading(submitBtn, btnText, btnSpinner, true);

            var formData = new FormData();
            formData.append('csrf_token', CSRF_TOKEN);
            formData.append('qq', qq);
            formData.append('password', password);
            formData.append('remember_me', rememberMe.checked ? '1' : '0');
            formData.append('captcha', captchaVal);

            lwAuthPost(SITE_URL + '/api/auth/login.php', formData)
            .then(function(data) {
                if (data.success) {
                    if (data.data && data.data.status === '2fa_required') {

                        twofaTempToken = data.data.temp_token;
                        showTwofaModal();
                        setLoading(submitBtn, btnText, btnSpinner, false);
                        isSubmitting = false;
                    } else {
                        // 首次登录（默认站长账号）强制跳转修改密码
                        if (data.user && data.user.must_change_password == 1) {
                            showAlert(__t('login.first_login_change_pwd'));
                            setTimeout(function() {
                                window.location.href = SITE_URL + '/pages/user_center.php?tab=security';
                            }, 1200);
                        } else {
                            showToast(__t('login.success_redirecting'), 'success');
                            setTimeout(function() {
                                window.location.href = SITE_URL + '/';
                            }, 800);
                        }
                    }
                } else {
                    showAlert(data.message || __t('login.failed'));

                    // 验证码为一次性，无论何种失败都刷新新题，避免下次提交仍被拦截
                    loadCaptcha();
                    passwordInput.value = '';
                    passwordInput.focus();
                    setLoading(submitBtn, btnText, btnSpinner, false);
                    isSubmitting = false;
                }
            })
            .catch(function(err) {
                showAlert(__t('login.err_network'));
                passwordInput.value = '';
                setLoading(submitBtn, btnText, btnSpinner, false);
                isSubmitting = false;
            });
        });

        function showTwofaModal() {
            twofaOverlay.classList.add('show');
            twofaCodeInputs[0].focus();
            clearTwofaInputs();
            useRecoveryMode = false;
            recoveryCodeGroup.style.display = 'none';
            recoveryCodeInput.value = '';
            hideAlerts();

            twofaExpiryTime = Date.now() + 300000;
            updateTwofaTimer();
            twofaTimerInterval = setInterval(updateTwofaTimer, 1000);
        }

        function hideTwofaModal() {
            twofaOverlay.classList.remove('show');
            clearInterval(twofaTimerInterval);
            twofaTimerInterval = null;
        }

        function clearTwofaInputs() {
            twofaCodeInputs.forEach(function(inp) { inp.value = ''; });
        }

        function updateTwofaTimer() {
            var remaining = Math.max(0, Math.ceil((twofaExpiryTime - Date.now()) / 1000));
            var mins = Math.floor(remaining / 60);
            var secs = remaining % 60;
            twofaCountdown.textContent = mins + ':' + (secs < 10 ? '0' : '') + secs;

            if (remaining <= 0) {
                clearInterval(twofaTimerInterval);
                hideTwofaModal();
                showAlert(__t('login.twofa_expired'));
            }
        }

        twofaCodeInputs.forEach(function(inp, index) {
            inp.addEventListener('input', function() {
                var val = this.value.replace(/\D/g, '');
                this.value = val;
                if (val && index < 5) {
                    twofaCodeInputs[index + 1].focus();
                }
            });

            inp.addEventListener('keydown', function(e) {
                if (e.key === 'Backspace' && !this.value && index > 0) {
                    twofaCodeInputs[index - 1].focus();
                }
                if (e.key === 'Enter') {
                    submitTwofa();
                }
            });

            inp.addEventListener('paste', function(e) {
                e.preventDefault();
                var pasted = (e.clipboardData || window.clipboardData).getData('text');
                var digits = pasted.replace(/\D/g, '').slice(0, 6);
                digits.split('').forEach(function(d, i) {
                    if (twofaCodeInputs[i]) twofaCodeInputs[i].value = d;
                });
                if (digits.length < 6) twofaCodeInputs[digits.length].focus();
            });
        });

        twofaSubmitBtn.addEventListener('click', submitTwofa);

        function submitTwofa() {
            if (isSubmitting) return;

            var code;
            if (useRecoveryMode) {
                code = recoveryCodeInput.value.trim();
                if (!code || code.length !== 10) {
                    showAlert(__t('login.err_recovery_invalid'));
                    return;
                }
            } else {
                var codeParts = [];
                twofaCodeInputs.forEach(function(inp) { codeParts.push(inp.value); });
                code = codeParts.join('');
                if (code.length !== 6) {
                    showAlert(__t('login.err_2fa_code_incomplete'));
                    return;
                }
            }

            isSubmitting = true;
            setLoading(twofaSubmitBtn, twofaBtnText, twofaBtnSpinner, true);

            var formData = new FormData();
            formData.append('csrf_token', CSRF_TOKEN);
            formData.append('temp_token', twofaTempToken);
            formData.append('code', code);
            if (useRecoveryMode) {
                formData.append('is_recovery', '1');
            }

            lwAuthPost(SITE_URL + '/api/auth/verify_2fa.php', formData)
            .then(function(data) {
                if (data.success) {
                    hideTwofaModal();
                    showToast(__t('login.twofa_success'), 'success');
                    setTimeout(function() {
                        window.location.href = SITE_URL + '/';
                    }, 800);
                } else {
                    showAlert(data.message || __t('login.twofa_failed'));
                    if (!useRecoveryMode) clearTwofaInputs();
                    twofaCodeInputs[0].focus();
                    setLoading(twofaSubmitBtn, twofaBtnText, twofaBtnSpinner, false);
                    isSubmitting = false;
                }
            })
            .catch(function(err) {
                showAlert(__t('login.err_network_retry'));
                setLoading(twofaSubmitBtn, twofaBtnText, twofaBtnSpinner, false);
                isSubmitting = false;
            });
        }

        twofaCancelBtn.addEventListener('click', function() {
            hideTwofaModal();
        });

        useRecoveryCodeBtn.addEventListener('click', function() {
            useRecoveryMode = !useRecoveryMode;
            if (useRecoveryMode) {
                recoveryCodeGroup.style.display = 'block';
                useRecoveryCodeBtn.textContent = __t('login.twofa_use_code');
                clearTwofaInputs();
                recoveryCodeInput.focus();
            } else {
                recoveryCodeGroup.style.display = 'none';
                recoveryCodeInput.value = '';
                useRecoveryCodeBtn.textContent = __t('login.twofa_use_recovery');
                twofaCodeInputs[0].focus();
            }
        });

        function renderCaptchaBox(ch) {
            var capInput = document.getElementById('captcha');
            var target = document.getElementById('captchaTarget');
            var img = document.getElementById('captchaTargetImg');
            var tilesEl = document.getElementById('captchaTiles');
            var hint = document.getElementById('captchaHint');
            if (capInput) capInput.value = '';

            if (ch.mode === 'math') {
                // 无 GD 时的算术回退
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
                if (hint) hint.textContent = __t('login.captcha_math_hint');
                return;
            }

            // 点选模式
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
                        if (hint) hint.textContent = __t('login.captcha_selected_prefix') + t + __t('login.captcha_selected_suffix');
                    });
                    tilesEl.appendChild(btn);
                });
            }
            if (hint) hint.textContent = __t('login.captcha_pick_hint');
        }

        function loadCaptcha() {
            var capInput = document.getElementById('captcha');
            if (capInput) capInput.value = '';
            fetch(SITE_URL + '/api/captcha.php?scene=login')
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

    <?php
    // 登录页是独立版式（无顶栏），底部导航统一走共享外壳的未登录形态：首页 + 登录。
    // 原先这里手写的「发帖」项对未登录用户是死路（点进去又被打回登录页），故不再保留。
    $user = null;
    $mobileNavActive = 'me';
    require __DIR__ . '/../includes/mobile_bottom_nav.php';
    ?>
<?php require_once __DIR__ . '/../includes/lang_ui.php'; ?>
</body>
</html>
