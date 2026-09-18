<?php

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../includes/totp.php';

$user = requireLogin();
$user = checkBanned($user);

$csrfToken = generateCSRFToken();
$tab = isset($_REQUEST['tab']) ? sanitizeInput($_REQUEST['tab']) : 'profile';

$validTabs = ['profile', 'security', 'posts', 'comments', 'favorites', 'activity', 'theme'];
if (!in_array($tab, $validTabs)) {
    $tab = 'profile';
}

$ucIcoUid = 0;
function ucSvgIcon($name) {
    global $ucIcoUid;
    $ucIcoUid++;
    $uid = 'uci' . $ucIcoUid;
    $icons = [
        'profile' => '<svg width="18" height="18" viewBox="0 0 24 24" fill="none"><defs><linearGradient id="' . $uid . 'a" x1="0%" y1="0%" x2="100%" y2="100%"><stop offset="0%" stop-color="#C9A96E"/><stop offset="100%" stop-color="#B8943E"/></linearGradient></defs><circle cx="12" cy="8" r="4" fill="url(#' . $uid . 'a)"/><path d="M4 21a8 8 0 0 1 16 0" fill="url(#' . $uid . 'a)"/></svg>',
        'security' => '<svg width="18" height="18" viewBox="0 0 24 24" fill="none"><defs><linearGradient id="' . $uid . 'a" x1="0%" y1="0%" x2="100%" y2="100%"><stop offset="0%" stop-color="#4A8C5C"/><stop offset="100%" stop-color="#2E6B4A"/></linearGradient></defs><path d="M12 2l8 3v6c0 5-3.5 8.5-8 11-4.5-2.5-8-6-8-11V5l8-3z" fill="url(#' . $uid . 'a)"/><path d="M9 12l2 2 4-4" stroke="#fff" stroke-width="2" fill="none" stroke-linecap="round" stroke-linejoin="round"/></svg>',
        'posts' => '<svg width="18" height="18" viewBox="0 0 24 24" fill="none"><defs><linearGradient id="' . $uid . 'a" x1="0%" y1="0%" x2="100%" y2="100%"><stop offset="0%" stop-color="#1B3A5C"/><stop offset="100%" stop-color="#2A5078"/></linearGradient></defs><path d="M12 20h9" stroke="url(#' . $uid . 'a)" stroke-width="2" stroke-linecap="round"/><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4z" fill="url(#' . $uid . 'a)"/></svg>',
        'comments' => '<svg width="18" height="18" viewBox="0 0 24 24" fill="none"><defs><linearGradient id="' . $uid . 'a" x1="0%" y1="0%" x2="100%" y2="100%"><stop offset="0%" stop-color="#5B7BD5"/><stop offset="100%" stop-color="#3A5BA0"/></linearGradient></defs><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z" fill="url(#' . $uid . 'a)"/></svg>',
        'favorites' => '<svg width="18" height="18" viewBox="0 0 24 24" fill="none"><defs><linearGradient id="' . $uid . 'a" x1="0%" y1="0%" x2="100%" y2="100%"><stop offset="0%" stop-color="#E2574C"/><stop offset="100%" stop-color="#C0392B"/></linearGradient></defs><path d="M20.8 4.6a5.5 5.5 0 0 0-7.8 0L12 5.6l-1-1a5.5 5.5 0 0 0-7.8 7.8l1 1L12 20.8l7.8-7.9 1-1a5.5 5.5 0 0 0 0-7.3z" fill="url(#' . $uid . 'a)"/></svg>',
        'activity' => '<svg width="18" height="18" viewBox="0 0 24 24" fill="none"><defs><linearGradient id="' . $uid . 'a" x1="0%" y1="0%" x2="100%" y2="100%"><stop offset="0%" stop-color="#8E44AD"/><stop offset="100%" stop-color="#6C3483"/></linearGradient></defs><circle cx="12" cy="12" r="9" fill="url(#' . $uid . 'a)"/><path d="M12 7v5l3 2" stroke="#fff" stroke-width="2" fill="none" stroke-linecap="round" stroke-linejoin="round"/></svg>',
        'theme' => '<svg width="18" height="18" viewBox="0 0 24 24" fill="none"><defs><linearGradient id="' . $uid . 'a" x1="0%" y1="0%" x2="100%" y2="100%"><stop offset="0%" stop-color="#F39C12"/><stop offset="100%" stop-color="#D68910"/></linearGradient></defs><path d="M21 12.8A9 9 0 1 1 11.2 3a7 7 0 0 0 9.8 9.8z" fill="url(#' . $uid . 'a)"/></svg>',
    ];
    return isset($icons[$name]) ? $icons[$name] : '';
}

$tabs = [
    'profile' => ['name' => '个人资料', 'icon' => ucSvgIcon('profile')],
    'security' => ['name' => '安全设置', 'icon' => ucSvgIcon('security')],
    'posts' => ['name' => '我的发布', 'icon' => ucSvgIcon('posts')],
    'comments' => ['name' => '我的评论', 'icon' => ucSvgIcon('comments')],
    'favorites' => ['name' => '我的收藏', 'icon' => ucSvgIcon('favorites')],
    'activity' => ['name' => '安全记录', 'icon' => ucSvgIcon('activity')],
    'theme' => ['name' => '主题设置', 'icon' => ucSvgIcon('theme')],
];
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" href="/icon.ico" type="image/x-icon">
    <title>用户中心 - <?= SITE_NAME ?></title>
    <link rel="stylesheet" href="<?= SITE_URL ?>/assets/css/style.css?v=<?= asset_ver('/assets/css/style.css') ?>">
    <link rel="stylesheet" href="<?= SITE_URL ?>/assets/css/enhancements.css?v=<?= asset_ver('/assets/css/enhancements.css') ?>">
    <script>
        const SITE_URL = '<?= SITE_URL ?>';
        const IS_LOGGED_IN = true;
        const USER_DATA = <?= json_encode(['id' => $user['id'], 'qq' => $user['qq'], 'nickname' => $user['nickname'], 'avatar' => $user['avatar'], 'role' => $user['role']], JSON_HEX_TAG | JSON_UNESCAPED_UNICODE) ?>;
        // 同步到 window：本页未引入 main.js，而增强脚本通过 window.IS_LOGGED_IN/window.USER_DATA
        // 判断登录态（const 声明的脚本级绑定不会挂到 window 上），否则登录后仍会误判为“未登录”。
        window.IS_LOGGED_IN = IS_LOGGED_IN;
        window.USER_DATA = USER_DATA;
        window.CSRF_TOKEN = <?= json_encode($csrfToken) ?>;
    </script>
    <style>
        .user-center-page {
            max-width: 1100px;
            margin: 0 auto;
            padding: 24px 16px;
        }
        .uc-layout {
            display: flex;
            gap: 24px;
            min-height: calc(100vh - 140px);
        }
        .uc-sidebar {
            width: 200px;
            flex-shrink: 0;
            background: var(--card-bg);
            border: 1px solid var(--border);
            border-radius: var(--radius);
            overflow: hidden;
            position: sticky;
            top: 84px;
            align-self: flex-start;
        }
        .uc-sidebar .uc-nav-item {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 14px 20px;
            font-size: 0.875rem;
            color: var(--text-secondary);
            text-decoration: none;
            transition: all 0.2s ease;
            border-left: 3px solid transparent;
            min-height: 48px;
        }
        .uc-sidebar .uc-nav-item svg {
            flex-shrink: 0;
            transition: transform 0.2s ease;
        }
        .uc-sidebar .uc-nav-item:hover {
            background: var(--bg);
            color: var(--text);
        }
        .uc-sidebar .uc-nav-item:hover svg {
            transform: scale(1.15);
        }
        .uc-sidebar .uc-nav-item.active {
            background: var(--primary-light);
            color: var(--primary);
            border-left-color: var(--primary);
            font-weight: 600;
        }
        .uc-sidebar .uc-nav-item.active svg {
            transform: scale(1.1);
        }
        .uc-content {
            flex: 1;
            min-width: 0;
        }
        .uc-section {
            background: var(--card-bg);
            border: 1px solid var(--border);
            border-radius: var(--radius);
            padding: 28px;
            margin-bottom: 20px;
            box-shadow: var(--shadow-sm);
        }
        .uc-section h2 {
            font-size: 1.25rem;
            margin-bottom: 20px;
            padding-bottom: 12px;
            border-bottom: 1px solid var(--border-light);
        }
        .uc-section h2 svg {
            vertical-align: -3px;
            margin-right: 6px;
        }
        .uc-sub-section {
            margin-bottom: 8px;
        }
        .uc-sub-title {
            font-size: 1rem;
            margin-bottom: 8px;
            color: var(--text);
        }
        .uc-sub-desc {
            font-size: 0.82rem;
            color: var(--text-secondary);
            margin-bottom: 12px;
        }
        .uc-divider {
            border: none;
            border-top: 1px solid var(--border-light);
            margin: 24px 0;
        }
        .uc-badge {
            display: inline-block;
            padding: 2px 10px;
            border-radius: 12px;
            font-size: 0.7rem;
            font-weight: 600;
            margin-left: 8px;
            vertical-align: 2px;
        }
        .uc-badge-success { background: var(--success-light); color: var(--success); }
        .uc-badge-warning { background: var(--warning-light); color: var(--warning); }
        .uc-setup-hint {
            display: inline-block;
            margin-top: 4px;
            padding: 2px 8px;
            background: var(--primary-light);
            color: var(--primary);
            border-radius: 12px;
            font-size: 0.7rem;
            font-weight: 500;
        }
        .uc-char-count {
            font-size: 0.7rem;
            color: var(--text-muted);
            text-align: right;
            margin-top: 2px;
        }
        .uc-label-hint {
            font-weight: 400;
            color: var(--text-muted);
            font-size: 0.75rem;
        }
        .uc-profile-card {
            display: flex;
            align-items: center;
            gap: 20px;
            padding: 20px;
            background: var(--bg);
            border-radius: var(--radius-sm);
            margin-bottom: 20px;
        }
        .uc-avatar {
            width: 72px;
            height: 72px;
            border-radius: 50%;
            object-fit: cover;
            border: 3px solid var(--primary-light);
            background: var(--bg-secondary);
        }
        .uc-profile-info h3 {
            font-size: 1.1rem;
            margin-bottom: 4px;
        }
        .uc-profile-info .uc-qq {
            font-size: 0.8rem;
            color: var(--text-secondary);
        }
        .uc-form-group {
            margin-bottom: 18px;
        }
        .uc-form-group label {
            display: block;
            font-size: 0.85rem;
            font-weight: 600;
            color: var(--text);
            margin-bottom: 6px;
        }
        .uc-form-input {
            width: 100%;
            padding: 10px 14px;
            border: 1px solid var(--border);
            border-radius: var(--radius-sm);
            font-size: 0.9375rem;
            color: var(--text);
            background: var(--bg);
            transition: all 0.15s ease;
            outline: none;
            min-height: 44px;
        }
        .uc-form-input:focus {
            border-color: var(--border-focus);
            background: var(--card-bg);
            box-shadow: 0 0 0 3px rgba(74,144,217,0.15);
        }
        .uc-form-input.input-error {
            border-color: var(--danger);
        }
        .uc-form-textarea {
            min-height: 80px;
            resize: vertical;
        }
        .uc-error-msg {
            font-size: 0.75rem;
            color: var(--danger);
            margin-top: 4px;
            display: none;
            align-items: center;
            gap: 4px;
        }
        .uc-error-msg.show { display: flex; }
        .uc-form-input.uc-has-value { font-weight: 600; color: var(--primary); }
        .uc-subject-wrap { display: flex; align-items: center; gap: 10px; }
        .uc-subject-cap { font-size: 0.8rem; color: var(--text-secondary); white-space: nowrap; min-width: 36px; }
        .uc-subject-grid { display: flex; flex-wrap: wrap; gap: 8px; }
        .uc-subject-tag { position: relative; }
        .uc-subject-tag input { position: absolute; opacity: 0; pointer-events: none; }
        .uc-subject-tag span {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-width: 44px;
            min-height: 36px;
            padding: 5px 12px;
            font-size: 0.8125rem;
            border: 1px solid var(--border);
            border-radius: 9px;
            color: var(--text);
            background: var(--bg);
            cursor: pointer;
            user-select: none;
            transition: all 0.15s ease;
        }
        .uc-subject-tag span:hover { border-color: var(--primary); color: var(--primary); }
        .uc-subject-tag input:checked + span {
            border-color: var(--primary);
            background: var(--primary);
            color: #fff;
            box-shadow: 0 2px 8px rgba(74,144,217,0.25);
        }
        .uc-subject-tag input:focus-visible + span { box-shadow: 0 0 0 3px rgba(74,144,217,0.2); }
        .uc-btn {
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
            transition: all 0.2s ease;
            min-height: 44px;
            font-family: inherit;
        }
        .uc-btn-primary {
            background: var(--primary);
            color: #fff;
        }
        .uc-btn-primary:hover { background: var(--primary-hover); }
        .uc-btn-danger {
            background: var(--danger);
            color: #fff;
        }
        .uc-btn-danger:hover { background: var(--accent-pink-dark); }
        .uc-btn-outline {
            background: transparent;
            color: var(--text);
            border: 1px solid var(--border);
        }
        .uc-btn-outline:hover { border-color: var(--primary); color: var(--primary); }
        .uc-btn:disabled { opacity: 0.5; cursor: not-allowed; }
        .uc-btn-sm {
            padding: 6px 14px;
            font-size: 0.8125rem;
            min-height: 36px;
        }
        .uc-input-wrapper {
            position: relative;
        }
        .uc-toggle-pw {
            position: absolute;
            right: 8px;
            top: 50%;
            transform: translateY(-50%);
            background: none;
            border: none;
            cursor: pointer;
            padding: 8px;
            color: var(--text-secondary);
            font-size: 1rem;
        }
        .uc-toggle-pw:hover { color: var(--text); }
        .uc-pw-strength {
            display: flex;
            gap: 4px;
            margin-top: 8px;
            align-items: center;
        }
        .uc-strength-bar {
            height: 4px;
            flex: 1;
            background: var(--border);
            border-radius: 2px;
            transition: background 0.3s;
        }
        .uc-strength-bar.weak { background: var(--danger); }
        .uc-strength-bar.medium { background: var(--warning); }
        .uc-strength-bar.strong { background: var(--success); }
        .uc-strength-text {
            font-size: 0.75rem;
            font-weight: 600;
            margin-left: 8px;
        }
        .uc-strength-text.weak { color: var(--danger); }
        .uc-strength-text.medium { color: var(--warning); }
        .uc-strength-text.strong { color: var(--success); }
        .uc-alert {
            padding: 12px 16px;
            border-radius: var(--radius-sm);
            font-size: 0.875rem;
            margin-bottom: 16px;
            display: none;
        }
        .uc-alert.show { display: block; }
        .uc-alert-error { background: var(--danger-light); color: var(--danger); border: 1px solid #f5c6cb; }
        .uc-alert-success { background: var(--success-light); color: var(--success); border: 1px solid #c3e6cb; }
        .uc-alert-info { background: var(--info-light); color: var(--info); border: 1px solid #b8d4ff; }
        .uc-spinner {
            display: inline-block;
            width: 16px;
            height: 16px;
            border: 2px solid rgba(255,255,255,0.3);
            border-top-color: #fff;
            border-radius: 50%;
            animation: spin 0.6s linear infinite;
        }
        @keyframes spin { to { transform: rotate(360deg); } }
        .uc-toast {
            position: fixed;
            top: 20px;
            left: 50%;
            transform: translateX(-50%);
            padding: 12px 24px;
            border-radius: var(--radius-sm);
            color: #fff;
            font-size: 0.875rem;
            z-index: 2000;
            animation: toastIn 0.3s ease;
            box-shadow: 0 4px 16px rgba(0,0,0,0.15);
        }
        .uc-toast-error { background: var(--danger); }
        .uc-toast-success { background: var(--success); }
        .uc-toast-info { background: var(--primary); }
        @keyframes toastIn {
            from { opacity: 0; transform: translateX(-50%) translateY(-20px); }
            to { opacity: 1; transform: translateX(-50%) translateY(0); }
        }

        .uc-2fa-steps { counter-reset: step; }
        .uc-2fa-step {
            padding: 16px 0 16px 48px;
            position: relative;
            border-bottom: 1px solid var(--border-light);
        }
        .uc-2fa-step:last-child { border-bottom: none; }
        .uc-2fa-step::before {
            counter-increment: step;
            content: counter(step);
            position: absolute;
            left: 0;
            top: 16px;
            width: 32px;
            height: 32px;
            background: var(--primary);
            color: #fff;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 0.875rem;
            font-weight: 700;
        }
        .uc-2fa-step h4 { font-size: 0.95rem; margin-bottom: 4px; }
        .uc-2fa-step p { font-size: 0.82rem; color: var(--text-secondary); margin: 0; }
        .uc-qr-container {
            display: flex;
            justify-content: center;
            padding: 20px;
            background: #fff;
            border: 1px solid var(--border);
            border-radius: var(--radius-sm);
            margin: 12px 0;
        }
        .uc-qr-container img { width: 200px; height: 200px; }
        .uc-recovery-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(160px, 1fr));
            gap: 8px;
            margin: 12px 0;
        }
        .uc-recovery-item {
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
        .uc-recovery-item button {
            font-size: 0.7rem;
            color: var(--primary);
            cursor: pointer;
            border: 1px solid var(--primary);
            border-radius: 4px;
            background: transparent;
            padding: 2px 8px;
        }
        .uc-recovery-item button:hover { background: var(--primary); color: #fff; }
        .uc-manual-key {
            background: var(--bg);
            padding: 10px 14px;
            border-radius: var(--radius-sm);
            font-family: 'Courier New', monospace;
            font-size: 0.85rem;
            word-break: break-all;
            margin: 8px 0;
            border: 1px solid var(--border);
        }
        .uc-2fa-code-input {
            display: flex;
            gap: 8px;
            margin: 12px 0;
        }
        .uc-2fa-code-input input {
            width: 44px;
            height: 52px;
            text-align: center;
            font-size: 1.25rem;
            font-weight: 700;
            border: 2px solid var(--border);
            border-radius: var(--radius-sm);
            outline: none;
            font-family: 'Courier New', monospace;
            background: var(--bg);
            color: var(--text);
        }
        .uc-2fa-code-input input:focus { border-color: var(--border-focus); }

        .uc-data-list { }
        .uc-data-item {
            padding: 16px 0;
            border-bottom: 1px solid var(--border-light);
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            gap: 12px;
        }
        .uc-data-item:last-child { border-bottom: none; }
        .uc-data-main { flex: 1; min-width: 0; }
        .uc-data-title {
            font-size: 0.9375rem;
            font-weight: 600;
            margin-bottom: 4px;
            color: var(--text);
            text-decoration: none;
        }
        .uc-data-title:hover { color: var(--primary); }
        .uc-data-meta {
            font-size: 0.75rem;
            color: var(--text-muted);
        }
        .uc-data-content {
            font-size: 0.85rem;
            color: var(--text-secondary);
            margin-top: 4px;
            word-break: break-word;
        }
        .uc-data-actions {
            flex-shrink: 0;
            display: flex;
            gap: 8px;
        }
        .uc-pagination {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
            margin-top: 20px;
            flex-wrap: wrap;
        }
        .uc-pagination button {
            padding: 8px 14px;
            border: 1px solid var(--border);
            background: var(--card-bg);
            border-radius: var(--radius-sm);
            cursor: pointer;
            font-size: 0.8125rem;
            color: var(--text);
            min-height: 36px;
        }
        .uc-pagination button:hover { background: var(--bg); }
        .uc-pagination button.active { background: var(--primary); color: #fff; border-color: var(--primary); }
        .uc-pagination button:disabled { opacity: 0.4; cursor: not-allowed; }
        .uc-empty {
            text-align: center;
            padding: 40px 20px;
            color: var(--text-secondary);
            font-size: 0.9rem;
        }

        .uc-log-table {
            width: 100%;
            border-collapse: collapse;
        }
        .uc-log-table th, .uc-log-table td {
            padding: 10px 14px;
            text-align: left;
            border-bottom: 1px solid var(--border-light);
            font-size: 0.8125rem;
        }
        .uc-log-table th {
            background: var(--bg-secondary);
            font-weight: 600;
            color: var(--text-secondary);
            font-size: 0.75rem;
        }
        .uc-log-table tbody tr:hover { background: var(--bg); }

        .uc-theme-toggle {
            display: flex;
            gap: 16px;
        }
        .uc-theme-option {
            flex: 1;
            padding: 20px;
            border: 2px solid var(--border);
            border-radius: var(--radius-sm);
            text-align: center;
            cursor: pointer;
            transition: all 0.2s ease;
            background: var(--bg);
        }
        .uc-theme-option:hover { border-color: var(--primary); }
        .uc-theme-option.active { border-color: var(--primary); background: var(--primary-light); }
        .uc-theme-option .uc-theme-icon { font-size: 2rem; margin-bottom: 8px; }
        .uc-theme-option .uc-theme-label { font-size: 0.9rem; font-weight: 600; }
        .uc-confirm-modal {
            position: fixed;
            top: 0; left: 0; right: 0; bottom: 0;
            background: rgba(0,0,0,0.5);
            z-index: 200;
            display: none;
            align-items: center;
            justify-content: center;
        }
        .uc-confirm-modal.show { display: flex; }
        .uc-confirm-box {
            background: var(--card-bg);
            border-radius: var(--radius);
            padding: 24px;
            width: 90%;
            max-width: 400px;
            box-shadow: 0 16px 48px rgba(0,0,0,0.2);
            text-align: center;
        }
        .uc-confirm-box h3 { margin-bottom: 12px; }
        .uc-confirm-box p { font-size: 0.9rem; color: var(--text-secondary); margin-bottom: 20px; }
        .uc-confirm-actions {
            display: flex;
            gap: 12px;
            justify-content: center;
        }
        @media (max-width: 768px) {
            .uc-layout { flex-direction: column; }
            .uc-sidebar {
                width: 100%;
                position: static;
                display: flex;
                flex-direction: row;
                overflow-x: auto;
                border-radius: var(--radius);
            }
            .uc-sidebar .uc-nav-item {
                white-space: nowrap;
                border-left: none;
                border-bottom: 3px solid transparent;
                flex-shrink: 0;
            }
            .uc-sidebar .uc-nav-item.active {
                border-left-color: transparent;
                border-bottom-color: var(--primary);
            }
            .uc-profile-card { flex-direction: column; text-align: center; }
            .uc-recovery-grid { grid-template-columns: 1fr 1fr; }
            .uc-log-table { font-size: 0.75rem; }
        }
    </style>
</head>
<body class="<?= $user['theme'] ?? 'light' ?>-theme">
    <header class="site-header">
        <div class="header-inner">
            <a href="<?= SITE_URL ?>/" class="site-logo">
                <svg class="logo-icon" width="28" height="28" viewBox="0 0 24 24" fill="none">
                    <defs>
                        <linearGradient id="logoGradUc" x1="0%" y1="0%" x2="100%" y2="100%">
                            <stop offset="0%" stop-color="#C9A96E"/>
                            <stop offset="50%" stop-color="#E8D5A3"/>
                            <stop offset="100%" stop-color="#B8943E"/>
                        </linearGradient>
                    </defs>
                    <path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z" fill="url(#logoGradUc)"/>
                    <polyline points="9 22 9 12 15 12 15 22" fill="none" stroke="#fff" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                </svg>
                <span class="logo-text"><?= SITE_NAME ?></span>
            </a>
            <div class="header-actions">
                <a href="<?= SITE_URL ?>/" class="btn btn-outline btn-sm">返回首页</a>
            </div>
        </div>
    </header>

    <div class="user-center-page">
        <div class="uc-layout">
            <aside class="uc-sidebar">
                <?php foreach ($tabs as $key => $t): ?>
                <a href="?tab=<?= $key ?>" class="uc-nav-item <?= $tab === $key ? 'active' : '' ?>">
                    <?= $t['icon'] ?> <?= $t['name'] ?>
                </a>
                <?php endforeach; ?>
            </aside>

            <div class="uc-content">

                <div class="uc-section" id="tab-profile" <?= $tab !== 'profile' ? 'style="display:none"' : '' ?>>
                    <h2>个人资料</h2>
                    <div class="uc-profile-card">
                        <img src="<?= xss_clean($user['avatar']) ?: getQQAvatar($user['qq']) ?>" alt="头像" class="uc-avatar" id="profileAvatar" onerror="this.src='<?= SITE_URL ?>/assets/images/default-avatar.svg'">
                        <div class="uc-profile-info">
                            <h3><?= xss_clean($user['nickname'] ?: '未设置昵称') ?></h3>
                            <span class="uc-qq">QQ: <?= xss_clean($user['qq']) ?></span>
                            <?php if (empty($user['nickname'])): ?>
                            <span class="uc-setup-hint">填写昵称，让大家认识你！</span>
                            <?php endif; ?>
                        </div>
                    </div>
                    <form id="profileForm" autocomplete="off">
                        <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
                        <div class="uc-form-group">
                            <label>QQ号 <span class="uc-label-hint">（注册时绑定，不可修改）</span></label>
                            <input type="text" class="uc-form-input" value="<?= xss_clean($user['qq']) ?>" disabled>
                        </div>
                        <div class="uc-form-group">
                            <label for="nickname">昵称 <span class="uc-label-hint">（2-20个字符，让大家认识你）</span></label>
                            <input type="text" id="nickname" name="nickname" class="uc-form-input" value="<?= xss_clean($user['nickname']) ?>" maxlength="50" placeholder="起个好听的名字吧">
                            <div class="uc-char-count"><span id="nicknameCount"><?= mb_strlen($user['nickname'] ?? '') ?></span>/50</div>
                            <div class="uc-error-msg" id="nicknameError"><span id="nicknameErrorText"></span></div>
                        </div>
                        <div class="uc-form-group">
                            <label for="bio">个人简介 <span class="uc-label-hint">（选填，最多200字）</span></label>
                            <textarea id="bio" name="bio" class="uc-form-input uc-form-textarea" maxlength="200" placeholder="介绍一下自己吧，比如兴趣爱好、班级等..."><?= xss_clean($user['bio']) ?></textarea>
                            <div class="uc-char-count"><span id="bioCount"><?= mb_strlen($user['bio'] ?? '') ?></span>/200</div>
                            <div class="uc-error-msg" id="bioError"><span id="bioErrorText"></span></div>
                        </div>
                        <?php
                        $ucEntrance = (int)($user['entrance_year'] ?? 0);
                        $ucGradeIdx = $ucEntrance > 0 ? currentGradeIndex($ucEntrance) : -1;
                        $ucClass = (int)($user['class_num'] ?? 0);
                        $ucFirstSub = $user['subject_first'] ?? '';
                        $ucSecondSub = explode(',', $user['subject_second'] ?? '');
                        $ucRealName = $user['real_name'] ?? '';
                        ?>
                        <div class="uc-form-group">
                            <label for="grade">年级 <span class="uc-label-hint">（选填，每年自动升一级）</span></label>
                            <select id="grade" name="grade" class="uc-form-input <?= $ucGradeIdx >= 0 ? 'uc-has-value' : '' ?>">
                                <option value="">未填写</option>
                                <?php for ($gi = 0; $gi <= 3; $gi++): ?>
                                    <option value="<?= $gi ?>" <?= $gi === $ucGradeIdx ? 'selected' : '' ?>><?= gradeLabel(entranceYearFromGrade($gi)) ?></option>
                                <?php endfor; ?>
                            </select>
                        </div>
                        <div class="uc-form-group">
                            <label for="classNum">班级 <span class="uc-label-hint">（选填 1-20 班）</span></label>
                            <select id="classNum" name="class_num" class="uc-form-input <?= $ucClass > 0 ? 'uc-has-value' : '' ?>">
                                <option value="0">未填写</option>
                                <?php for ($ci = 1; $ci <= 20; $ci++): ?>
                                    <option value="<?= $ci ?>" <?= $ci === $ucClass ? 'selected' : '' ?>><?= $ci ?>班</option>
                                <?php endfor; ?>
                            </select>
                        </div>
                        <div class="uc-form-group">
                            <label for="realName">真实姓名 <span class="uc-label-hint">（选填，仅本人可见）</span></label>
                            <input type="text" id="realName" name="real_name" class="uc-form-input" value="<?= xss_clean($ucRealName) ?>" maxlength="20" placeholder="请输入真实姓名">
                            <div class="uc-error-msg" id="realNameError"><span id="realNameErrorText"></span></div>
                        </div>
                        <div class="uc-form-group">
                            <label>高考选科 <span class="uc-label-hint">（选填，安徽 3+1+2）</span></label>
                            <div class="uc-subject-wrap">
                                <span class="uc-subject-cap">首选</span>
                                <div class="uc-subject-grid" id="ucFirstSubject">
                                    <?php foreach (gradeSubjectsFirst() as $s): ?>
                                        <label class="uc-subject-tag"><input type="radio" name="first_subject" value="<?= $s ?>" <?= $ucFirstSub === $s ? 'checked' : '' ?>><span><?= $s ?></span></label>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                            <div class="uc-subject-wrap" style="margin-top:8px;">
                                <span class="uc-subject-cap">再选</span>
                                <div class="uc-subject-grid" id="ucSecondSubject">
                                    <?php foreach (gradeSubjectsSecond() as $s): ?>
                                        <label class="uc-subject-tag"><input type="checkbox" name="second_subject[]" value="<?= $s ?>" <?= in_array($s, $ucSecondSub, true) ? 'checked' : '' ?>><span><?= $s ?></span></label>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                            <div class="uc-error-msg" id="ucSubjectError"><span id="ucSubjectErrorText"></span></div>
                        </div>
                        <button type="submit" class="uc-btn uc-btn-primary" id="profileSaveBtn">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" id="profileBtnIcon"><path d="M5 12l5 5 9-11" stroke="#fff" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"/></svg>
                            <span id="profileBtnText">保存修改</span>
                            <span class="uc-spinner" id="profileBtnSpinner" style="display:none"></span>
                        </button>
                    </form>
                </div>

                <div class="uc-section" id="tab-security" <?= $tab !== 'security' ? 'style="display:none"' : '' ?>>
                    <h2>安全设置</h2>
                    <div class="uc-alert uc-alert-error" id="secAlertError"></div>
                    <div class="uc-alert uc-alert-success" id="secAlertSuccess"></div>

                    <div class="uc-sub-section">
                        <h3 class="uc-sub-title">修改密码</h3>
                        <form id="securityForm" autocomplete="off">
                            <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
                            <div class="uc-form-group">
                                <label for="currentPassword">当前密码</label>
                                <div class="uc-input-wrapper">
                                    <input type="password" id="currentPassword" name="current_password" class="uc-form-input" placeholder="请输入当前密码">
                                    <button type="button" class="uc-toggle-pw" data-target="currentPassword">显示</button>
                                </div>
                            </div>
                            <div class="uc-form-group">
                                <label for="newPassword">新密码 <span class="uc-label-hint">（至少8个字符，包含大小写字母和数字更安全）</span></label>
                                <div class="uc-input-wrapper">
                                    <input type="password" id="newPassword" name="new_password" class="uc-form-input" placeholder="设置新密码">
                                    <button type="button" class="uc-toggle-pw" data-target="newPassword">显示</button>
                                </div>
                                <div class="uc-pw-strength" id="pwStrength">
                                    <span class="uc-strength-bar" id="sb1"></span>
                                    <span class="uc-strength-bar" id="sb2"></span>
                                    <span class="uc-strength-bar" id="sb3"></span>
                                    <span class="uc-strength-bar" id="sb4"></span>
                                    <span class="uc-strength-text" id="strengthText"></span>
                                </div>
                            </div>
                            <div class="uc-form-group">
                                <label for="confirmPassword">确认新密码</label>
                                <div class="uc-input-wrapper">
                                    <input type="password" id="confirmPassword" name="confirm_password" class="uc-form-input" placeholder="再次输入新密码">
                                    <button type="button" class="uc-toggle-pw" data-target="confirmPassword">显示</button>
                                </div>
                            </div>
                            <button type="submit" class="uc-btn uc-btn-primary" id="securitySaveBtn">
                                <svg width="16" height="16" viewBox="0 0 24 24" fill="none"><rect x="4" y="10" width="16" height="11" rx="2" fill="#fff"/><path d="M8 10V7a4 4 0 0 1 8 0v3" stroke="#4A90D9" stroke-width="2" fill="none"/><circle cx="12" cy="15.5" r="1.5" fill="#4A90D9"/></svg>
                                <span id="securityBtnText">修改密码</span>
                                <span class="uc-spinner" id="securityBtnSpinner" style="display:none"></span>
                            </button>
                        </form>
                    </div>

                    <hr class="uc-divider">

                    <div class="uc-sub-section">
                        <h3 class="uc-sub-title">双重验证 (2FA) <span class="uc-badge <?= $user['twofa_enabled'] ? 'uc-badge-success' : 'uc-badge-warning' ?>"><?= $user['twofa_enabled'] ? '已启用' : '未启用' ?></span></h3>
                        <p class="uc-sub-desc">开启后登录需要输入手机验证器中的动态码，大幅提升账号安全性。</p>
                        <?php if ($user['twofa_enabled']): ?>
                        <div id="twofaEnabled" style="display:flex;gap:12px;flex-wrap:wrap;">
                            <button class="uc-btn uc-btn-danger" id="disable2faBtn">禁用2FA</button>
                            <button class="uc-btn uc-btn-outline" id="reset2faBtn">重置2FA</button>
                        </div>
                        <?php else: ?>
                        <div id="twofaSetup">
                            <div class="uc-2fa-steps">
                                <div class="uc-2fa-step">
                                    <h4>下载认证器应用</h4>
                                    <p>在手机应用商店下载 <strong>Google Authenticator</strong> 或 <strong>Microsoft Authenticator</strong></p>
                                </div>
                                <div class="uc-2fa-step">
                                    <h4>生成密钥并扫描</h4>
                                    <button class="uc-btn uc-btn-primary uc-btn-sm" id="generate2faBtn" style="margin:8px 0;">
                                        <span id="gen2faBtnText">点击生成密钥</span>
                                        <span class="uc-spinner" id="gen2faBtnSpinner" style="display:none"></span>
                                    </button>
                                    <div id="twofaSetupContent" style="display:none;">
                                        <div class="uc-qr-container" id="qrContainer"></div>
                                        <p style="font-size:0.8rem;margin-top:8px;">手动密钥：</p>
                                        <div class="uc-manual-key" id="manualKey"></div>
                                    </div>
                                </div>
                                <div class="uc-2fa-step">
                                    <h4>验证并启用</h4>
                                    <p>输入认证器中显示的6位数字验证码</p>
                                    <div class="uc-2fa-code-input" id="twofaCodeInput">
                                        <input type="text" maxlength="1" inputmode="numeric" data-index="0">
                                        <input type="text" maxlength="1" inputmode="numeric" data-index="1">
                                        <input type="text" maxlength="1" inputmode="numeric" data-index="2">
                                        <input type="text" maxlength="1" inputmode="numeric" data-index="3">
                                        <input type="text" maxlength="1" inputmode="numeric" data-index="4">
                                        <input type="text" maxlength="1" inputmode="numeric" data-index="5">
                                    </div>
                                    <div class="uc-form-group">
                                        <label for="twofaConfirmPassword">输入密码确认</label>
                                        <input type="password" id="twofaConfirmPassword" class="uc-form-input" placeholder="请输入密码" style="max-width:300px;">
                                    </div>
                                    <button class="uc-btn uc-btn-primary" id="verify2faBtn">
                                        <span id="verify2faBtnText">验证并启用</span>
                                        <span class="uc-spinner" id="verify2faBtnSpinner" style="display:none"></span>
                                    </button>
                                </div>
                            </div>
                            <div id="recoveryCodesArea" style="display:none;margin-top:20px;">
                                <h3 style="font-size:1rem;margin-bottom:8px;">恢复码</h3>
                                <p style="font-size:0.82rem;color:var(--warning);margin-bottom:12px;">请立即保存！丢失认证器时可用恢复码登录，每个只能用一次。</p>
                                <div class="uc-recovery-grid" id="recoveryCodesGrid"></div>
                                <div style="display:flex;gap:8px;margin-top:12px;flex-wrap:wrap;">
                                    <button class="uc-btn uc-btn-outline uc-btn-sm" id="copyAllCodes">复制全部</button>
                                    <button class="uc-btn uc-btn-outline uc-btn-sm" id="downloadCodes">下载TXT</button>
                                </div>
                            </div>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="uc-section" id="tab-posts" <?= $tab !== 'posts' ? 'style="display:none"' : '' ?>>
                    <h2>我的发布</h2>
                    <div id="myPostsList"></div>
                    <div class="uc-pagination" id="myPostsPagination"></div>
                </div>

                <div class="uc-section" id="tab-comments" <?= $tab !== 'comments' ? 'style="display:none"' : '' ?>>
                    <h2>我的评论</h2>
                    <div id="myCommentsList"></div>
                    <div class="uc-pagination" id="myCommentsPagination"></div>
                </div>

                <div class="uc-section" id="tab-favorites" <?= $tab !== 'favorites' ? 'style="display:none"' : '' ?>>
                    <h2>我的收藏</h2>
                    <div id="myFavoritesList"></div>
                    <div class="uc-pagination" id="myFavoritesPagination"></div>
                </div>

                <div class="uc-section" id="tab-activity" <?= $tab !== 'activity' ? 'style="display:none"' : '' ?>>
                    <h2>安全记录</h2>
                    <div style="overflow-x:auto;">
                        <table class="uc-log-table">
                            <thead><tr><th>时间</th><th>操作</th><th>详情</th><th>IP</th></tr></thead>
                            <tbody id="activityLogBody"></tbody>
                        </table>
                    </div>
                    <div class="uc-pagination" id="activityLogPagination"></div>
                </div>

                <div class="uc-section" id="tab-theme" <?= $tab !== 'theme' ? 'style="display:none"' : '' ?>>
                    <h2>主题设置</h2>
                    <p style="margin-bottom:16px;color:var(--text-secondary);">选择你喜欢的界面主题</p>
                    <div class="uc-theme-toggle">
                        <div class="uc-theme-option <?= ($user['theme'] ?? 'light') === 'light' ? 'active' : '' ?>" data-theme="light">
                            <div class="uc-theme-icon">浅色</div>
                            <div class="uc-theme-label">浅色模式</div>
                        </div>
                        <div class="uc-theme-option <?= ($user['theme'] ?? 'light') === 'dark' ? 'active' : '' ?>" data-theme="dark">
                            <div class="uc-theme-icon">深色</div>
                            <div class="uc-theme-label">深色模式</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="uc-confirm-modal" id="confirmModal">
        <div class="uc-confirm-box">
            <h3 id="confirmTitle">确认操作</h3>
            <p id="confirmMsg"></p>
            <div class="uc-confirm-actions">
                <button class="uc-btn uc-btn-outline" id="confirmCancel">取消</button>
                <button class="uc-btn uc-btn-danger" id="confirmOk">确认</button>
            </div>
        </div>
    </div>

    <footer class="site-footer">
        <p>&copy; <?= date('Y') ?> 校园交流墙</p>
    </footer>

    <nav class="mobile-bottom-nav" id="mobileNav">
        <a href="<?= SITE_URL ?>/" class="mobile-nav-item">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg>
            <span>首页</span>
        </a>
        <a href="<?= SITE_URL ?>/pages/post.php" class="mobile-nav-item">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
            <span>发帖</span>
        </a>
        <a href="<?= SITE_URL ?>/pages/browser.php" class="mobile-nav-item">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="2" y1="12" x2="22" y2="12"/><path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"/></svg>
            <span>导航</span>
        </a>
        <a href="<?= SITE_URL ?>/pages/user_center.php" class="mobile-nav-item active">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
            <span>我的</span>
        </a>
        <a href="<?= SITE_URL ?>/pages/user_center.php#my-favorites" class="mobile-nav-item">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg>
            <span>收藏</span>
        </a>
    </nav>

    <script>
    (function() {
        const SITE_URL = '<?= SITE_URL ?>';
        const CSRF_TOKEN = '<?= $csrfToken ?>';
        const USER_THEME = '<?= $user['theme'] ?? 'light' ?>';
        const TWOFA_ENABLED = <?= isset($user['twofa_enabled']) && $user['twofa_enabled'] ? 'true' : 'false' ?>;

        function showToast(msg, type) {
            const existing = document.querySelector('.uc-toast');
            if (existing) existing.remove();
            const t = document.createElement('div');
            t.className = 'uc-toast uc-toast-' + type;
            t.textContent = msg;
            document.body.appendChild(t);
            setTimeout(() => { t.style.opacity = '0'; t.style.transition = 'opacity 0.3s'; setTimeout(() => t.remove(), 300); }, 3000);
        }

        function setLoading(btn, textEl, spinnerEl, loading) {
            btn.disabled = loading;
            textEl.style.display = loading ? 'none' : '';
            spinnerEl.style.display = loading ? 'inline-block' : 'none';
        }

        const profileForm = document.getElementById('profileForm');
        if (profileForm) {
            profileForm.addEventListener('submit', function(e) {
                e.preventDefault();
                const nickname = document.getElementById('nickname').value.trim();
                const bio = document.getElementById('bio').value.trim();
                document.getElementById('nicknameError').classList.remove('show');
                document.getElementById('bioError').classList.remove('show');

                let valid = true;
                if (nickname.length > 50) {
                    document.getElementById('nicknameErrorText').textContent = '昵称不能超过50个字符';
                    document.getElementById('nicknameError').classList.add('show');
                    valid = false;
                }
                if (bio.length > 200) {
                    document.getElementById('bioErrorText').textContent = '简介不能超过200个字符';
                    document.getElementById('bioError').classList.add('show');
                    valid = false;
                }
                if (!valid) return;

                // ---- 选填扩展资料校验 ----
                const realName = document.getElementById('realName').value.trim();
                const realNameErr = document.getElementById('realNameError');
                const realNameErrText = document.getElementById('realNameErrorText');
                realNameErr.classList.remove('show');
                document.getElementById('realName').classList.remove('input-error');
                if (realName.length > 0 && (realName.length < 2 || realName.length > 20)) {
                    realNameErrText.textContent = '姓名长度需在 2-20 个字符之间';
                    realNameErr.classList.add('show');
                    document.getElementById('realName').classList.add('input-error');
                    return;
                }

                const firstBoxes = document.querySelectorAll('#ucFirstSubject input[type=radio]');
                const secondBoxes = document.querySelectorAll('#ucSecondSubject input[type=checkbox]');
                let firstVal = '';
                const secondArr = [];
                firstBoxes.forEach(r => { if (r.checked) firstVal = r.value; });
                secondBoxes.forEach(c => { if (c.checked) secondArr.push(c.value); });
                const ucSubErr = document.getElementById('ucSubjectError');
                const ucSubErrText = document.getElementById('ucSubjectErrorText');
                ucSubErr.classList.remove('show');
                if ((firstVal !== '' || secondArr.length > 0) && (firstVal === '' || secondArr.length !== 2)) {
                    ucSubErrText.textContent = firstVal === '' ? '请选择首选科目（物理或历史）' : '再选科目请选择 2 门';
                    ucSubErr.classList.add('show');
                    return;
                }
                // 再选科目最多 2 门
                secondBoxes.forEach(c => {
                    c.addEventListener('change', function() {
                        const sel = document.querySelectorAll('#ucSecondSubject input[type=checkbox]:checked');
                        if (sel.length > 2) this.checked = false;
                    });
                });

                setLoading(document.getElementById('profileSaveBtn'), document.getElementById('profileBtnText'), document.getElementById('profileBtnSpinner'), true);

                const formData = new FormData();
                formData.append('csrf_token', CSRF_TOKEN);
                formData.append('nickname', nickname);
                formData.append('bio', bio);
                formData.append('grade', document.getElementById('grade').value);
                formData.append('class_num', document.getElementById('classNum').value);
                formData.append('real_name', realName);
                if (firstVal) formData.append('first_subject', firstVal);
                secondArr.forEach(s => formData.append('second_subject[]', s));

                fetch(SITE_URL + '/api/user/update_profile.php', { method: 'POST', body: formData })
                    .then(r => r.json())
                    .then(d => {
                        setLoading(document.getElementById('profileSaveBtn'), document.getElementById('profileBtnText'), document.getElementById('profileBtnSpinner'), false);
                        if (d.success) {
                            showToast(d.message, 'success');
                            document.querySelector('.uc-profile-info h3').textContent = nickname || '未设置昵称';
                        } else {
                            showToast(d.message, 'error');
                        }
                    })
                    .catch(() => {
                        setLoading(document.getElementById('profileSaveBtn'), document.getElementById('profileBtnText'), document.getElementById('profileBtnSpinner'), false);
                        showToast('网络错误', 'error');
                    });
            });
        }

        const securityForm = document.getElementById('securityForm');
        if (securityForm) {

            const newPw = document.getElementById('newPassword');
            newPw.addEventListener('input', function() {
                const pw = this.value;
                let score = 0;
                if (pw.length >= 8) score++;
                if (/[a-z]/.test(pw) && /[A-Z]/.test(pw)) score++;
                if (/\d/.test(pw)) score++;
                if (/[^a-zA-Z0-9]/.test(pw)) score++;

                const bars = [document.getElementById('sb1'), document.getElementById('sb2'), document.getElementById('sb3'), document.getElementById('sb4')];
                const text = document.getElementById('strengthText');
                bars.forEach((b, i) => {
                    b.className = 'uc-strength-bar';
                    if (i < score) {
                        b.classList.add(score <= 2 ? 'weak' : score === 3 ? 'medium' : 'strong');
                    }
                });
                text.className = 'uc-strength-text';
                text.classList.add(score <= 2 ? 'weak' : score === 3 ? 'medium' : 'strong');
                text.textContent = score <= 2 ? '弱' : score === 3 ? '中等' : '强';
            });

            document.querySelectorAll('.uc-toggle-pw').forEach(btn => {
                btn.addEventListener('click', function() {
                    const target = document.getElementById(this.dataset.target);
                    if (target.type === 'password') { target.type = 'text'; this.textContent = '隐藏'; }
                    else { target.type = 'password'; this.textContent = '显示'; }
                });
            });

            securityForm.addEventListener('submit', function(e) {
                e.preventDefault();
                const currentPw = document.getElementById('currentPassword').value;
                const newPwVal = document.getElementById('newPassword').value;
                const confirmPw = document.getElementById('confirmPassword').value;

                document.getElementById('secAlertError').classList.remove('show');
                document.getElementById('secAlertSuccess').classList.remove('show');

                if (!currentPw) { showToast('请输入当前密码', 'error'); return; }
                if (newPwVal.length < 6) { showToast('新密码长度至少为6个字符', 'error'); return; }
                if (newPwVal !== confirmPw) { showToast('两次输入的新密码不一致', 'error'); return; }

                setLoading(document.getElementById('securitySaveBtn'), document.getElementById('securityBtnText'), document.getElementById('securityBtnSpinner'), true);

                const formData = new FormData();
                formData.append('csrf_token', CSRF_TOKEN);
                formData.append('current_password', currentPw);
                formData.append('new_password', newPwVal);
                formData.append('confirm_password', confirmPw);

                fetch(SITE_URL + '/api/user/change_password.php', { method: 'POST', body: formData })
                    .then(r => r.json())
                    .then(d => {
                        setLoading(document.getElementById('securitySaveBtn'), document.getElementById('securityBtnText'), document.getElementById('securityBtnSpinner'), false);
                        if (d.success) {
                            showToast(d.message, 'success');
                            setTimeout(() => { window.location.href = SITE_URL + '/pages/login.php'; }, 1500);
                        } else {
                            showToast(d.message, 'error');
                        }
                    })
                    .catch(() => {
                        setLoading(document.getElementById('securitySaveBtn'), document.getElementById('securityBtnText'), document.getElementById('securityBtnSpinner'), false);
                        showToast('网络错误', 'error');
                    });
            });
        }

        let twofaSecret = '';
        let twofaRecoveryCodes = [];

        const generate2faBtn = document.getElementById('generate2faBtn');
        if (generate2faBtn) {
            generate2faBtn.addEventListener('click', function() {
                setLoading(generate2faBtn, document.getElementById('gen2faBtnText'), document.getElementById('gen2faBtnSpinner'), true);
                const formData = new FormData();
                formData.append('csrf_token', CSRF_TOKEN);
                formData.append('action', 'generate');

                fetch(SITE_URL + '/api/user/2fa_setup.php', { method: 'POST', body: formData })
                    .then(r => r.json())
                    .then(d => {
                        setLoading(generate2faBtn, document.getElementById('gen2faBtnText'), document.getElementById('gen2faBtnSpinner'), false);
                        if (d.success) {
                            twofaSecret = d.data.secret;
                            document.getElementById('twofaSetupContent').style.display = 'block';
                            document.getElementById('qrContainer').innerHTML = '<img src="' + d.data.qr_code_url + '" alt="QR Code">';
                            document.getElementById('manualKey').textContent = d.data.secret;
                            generate2faBtn.style.display = 'none';
                            showToast('密钥已生成，请扫描二维码', 'info');
                        } else {
                            showToast(d.message, 'error');
                        }
                    })
                    .catch(() => {
                        setLoading(generate2faBtn, document.getElementById('gen2faBtnText'), document.getElementById('gen2faBtnSpinner'), false);
                        showToast('网络错误', 'error');
                    });
            });
        }

        const twofaInputs = document.querySelectorAll('#twofaCodeInput input');
        twofaInputs.forEach((inp, i) => {
            inp.addEventListener('input', function() {
                this.value = this.value.replace(/\D/g, '');
                if (this.value && i < 5) twofaInputs[i+1].focus();
            });
            inp.addEventListener('keydown', function(e) {
                if (e.key === 'Backspace' && !this.value && i > 0) twofaInputs[i-1].focus();
            });
            inp.addEventListener('paste', function(e) {
                e.preventDefault();
                const pasted = (e.clipboardData || window.clipboardData).getData('text').replace(/\D/g, '').slice(0, 6);
                pasted.split('').forEach((d, j) => { if (twofaInputs[j]) twofaInputs[j].value = d; });
            });
        });

        function get2faCode() {
            let code = '';
            twofaInputs.forEach(inp => code += inp.value);
            return code;
        }

        const verify2faBtn = document.getElementById('verify2faBtn');
        if (verify2faBtn) {
            verify2faBtn.addEventListener('click', function() {
                const code = get2faCode();
                const password = document.getElementById('twofaConfirmPassword').value;
                if (code.length !== 6) { showToast('请输入6位验证码', 'error'); return; }
                if (!password) { showToast('请输入密码确认操作', 'error'); return; }

                setLoading(verify2faBtn, document.getElementById('verify2faBtnText'), document.getElementById('verify2faBtnSpinner'), true);
                const formData = new FormData();
                formData.append('csrf_token', CSRF_TOKEN);
                formData.append('action', 'verify_enable');
                formData.append('code', code);
                formData.append('password', password);

                fetch(SITE_URL + '/api/user/2fa_setup.php', { method: 'POST', body: formData })
                    .then(r => r.json())
                    .then(d => {
                        setLoading(verify2faBtn, document.getElementById('verify2faBtnText'), document.getElementById('verify2faBtnSpinner'), false);
                        if (d.success) {
                            twofaRecoveryCodes = d.data.recovery_codes;
                            showRecoveryCodes(twofaRecoveryCodes);
                            document.getElementById('recoveryCodesArea').style.display = 'block';
                            document.getElementById('twofaSetup').querySelector('.uc-2fa-steps').style.display = 'none';
                            showToast(d.message, 'success');
                            setTimeout(() => { window.location.href = SITE_URL + '/pages/login.php'; }, 3000);
                        } else {
                            showToast(d.message, 'error');
                        }
                    })
                    .catch(() => {
                        setLoading(verify2faBtn, document.getElementById('verify2faBtnText'), document.getElementById('verify2faBtnSpinner'), false);
                        showToast('网络错误', 'error');
                    });
            });
        }

        function showRecoveryCodes(codes) {
            const grid = document.getElementById('recoveryCodesGrid');
            grid.innerHTML = codes.map(c =>
                '<div class="uc-recovery-item"><span>' + c + '</span><button onclick="navigator.clipboard.writeText(\'' + c + '\')">复制</button></div>'
            ).join('');
        }

        const copyAllBtn = document.getElementById('copyAllCodes');
        if (copyAllBtn) {
            copyAllBtn.addEventListener('click', () => {
                navigator.clipboard.writeText(twofaRecoveryCodes.join('\n')).then(() => showToast('已复制全部恢复码', 'success'));
            });
        }

        const downloadBtn = document.getElementById('downloadCodes');
        if (downloadBtn) {
            downloadBtn.addEventListener('click', () => {
                const blob = new Blob([twofaRecoveryCodes.join('\n')], {type: 'text/plain'});
                const a = document.createElement('a');
                a.href = URL.createObjectURL(blob);
                a.download = 'recovery-codes.txt';
                a.click();
            });
        }

        const disable2faBtn = document.getElementById('disable2faBtn');
        if (disable2faBtn) {
            disable2faBtn.addEventListener('click', () => showConfirm('禁用2FA', '请输入密码和当前2FA验证码确认禁用双重验证。此操作会强制登出所有设备。', () => {
                const pw = prompt('请输入密码：');
                if (!pw) return;
                const code = prompt('请输入当前2FA验证码（6位数字）：');
                if (!code) return;
                const formData = new FormData();
                formData.append('csrf_token', CSRF_TOKEN);
                formData.append('password', pw);
                formData.append('code', code);
                fetch(SITE_URL + '/api/user/2fa_disable.php', { method: 'POST', body: formData })
                    .then(r => r.json())
                    .then(d => {
                        if (d.success) { showToast(d.message, 'success'); setTimeout(() => location.href = SITE_URL + '/pages/login.php', 1500); }
                        else showToast(d.message, 'error');
                    });
            }));
        }

        const reset2faBtn = document.getElementById('reset2faBtn');
        if (reset2faBtn) {
            reset2faBtn.addEventListener('click', () => showConfirm('重置2FA', '请输入密码确认重置双重验证。旧的恢复码将失效，需要重新设置。', () => {
                const pw = prompt('请输入密码：');
                if (!pw) return;
                const formData = new FormData();
                formData.append('csrf_token', CSRF_TOKEN);
                formData.append('password', pw);
                fetch(SITE_URL + '/api/user/2fa_reset.php', { method: 'POST', body: formData })
                    .then(r => r.json())
                    .then(d => {
                        if (d.success) { showToast(d.message, 'success'); setTimeout(() => location.href = SITE_URL + '/pages/login.php', 1500); }
                        else showToast(d.message, 'error');
                    });
            }));
        }

        let confirmCallback = null;
        function showConfirm(title, msg, cb) {
            document.getElementById('confirmTitle').textContent = title;
            document.getElementById('confirmMsg').textContent = msg;
            document.getElementById('confirmModal').classList.add('show');
            confirmCallback = cb;
        }
        document.getElementById('confirmCancel').addEventListener('click', () => document.getElementById('confirmModal').classList.remove('show'));
        document.getElementById('confirmOk').addEventListener('click', () => {
            document.getElementById('confirmModal').classList.remove('show');
            if (confirmCallback) confirmCallback();
        });

        function loadList(apiUrl, containerId, paginationId, renderFn, page) {
            page = page || 1;
            const container = document.getElementById(containerId);
            container.innerHTML = '<div class="uc-empty">加载中...</div>';
            fetch(apiUrl + '?page=' + page)
                .then(r => r.json())
                .then(d => {
                    if (d.success) {
                        const keys = Object.keys(d.data);
                        const dataKey = keys.find(k => k !== 'pagination') || keys[0];
                        const items = d.data[dataKey];
                        if (!items || items.length === 0) {
                            container.innerHTML = '<div class="uc-empty">暂无数据</div>';
                            document.getElementById(paginationId).innerHTML = '';
                        } else {
                            container.innerHTML = renderFn(d.data);
                            renderPagination(paginationId, d.data.pagination, (p) => loadList(apiUrl, containerId, paginationId, renderFn, p));
                        }
                    } else {
                        container.innerHTML = '<div class="uc-empty">' + (d.message || '加载失败') + '</div>';
                    }
                })
                .catch(() => { container.innerHTML = '<div class="uc-empty">加载失败</div>'; });
        }

        function renderPagination(id, pag, cb) {
            const el = document.getElementById(id);
            let html = '';
            html += '<button ' + (pag.page <= 1 ? 'disabled' : '') + ' onclick="void(0)">上一页</button>';
            for (let i = 1; i <= pag.total_pages; i++) {
                html += '<button class="' + (i === pag.page ? 'active' : '') + '">' + i + '</button>';
            }
            html += '<button ' + (pag.page >= pag.total_pages ? 'disabled' : '') + '>下一页</button>';
            el.innerHTML = html;
            el.querySelectorAll('button').forEach((btn, idx) => {
                if (idx === 0) btn.addEventListener('click', () => cb(pag.page - 1));
                else if (idx === el.querySelectorAll('button').length - 1) btn.addEventListener('click', () => cb(pag.page + 1));
                else btn.addEventListener('click', () => cb(parseInt(btn.textContent)));
            });
        }

        function deletePost(postId) {
            showConfirm('确认删除', '确定要删除此帖子吗？所有评论和点赞也将被删除。此操作不可恢复。', () => {
                const formData = new FormData();
                formData.append('csrf_token', CSRF_TOKEN);
                formData.append('post_id', postId);
                fetch(SITE_URL + '/api/posts/delete.php', { method: 'POST', body: formData })
                    .then(r => r.json())
                    .then(d => {
                        if (d.success) { showToast(d.message, 'success'); setTimeout(() => location.reload(), 600); }
                        else showToast(d.message, 'error');
                    })
                    .catch(() => showToast('网络错误', 'error'));
            });
        }

        function deleteComment(commentId) {
            showConfirm('确认删除', '确定要删除此评论吗？', () => {
                const formData = new FormData();
                formData.append('csrf_token', CSRF_TOKEN);
                formData.append('comment_id', commentId);
                fetch(SITE_URL + '/api/posts/delete_comment.php', { method: 'POST', body: formData })
                    .then(r => r.json())
                    .then(d => {
                        if (d.success) { showToast(d.message, 'success'); setTimeout(() => location.reload(), 600); }
                        else showToast(d.message, 'error');
                    })
                    .catch(() => showToast('网络错误', 'error'));
            });
        }

        function unfavoritePost(postId) {
            showConfirm('取消收藏', '确定要取消收藏此帖子吗？', () => {
                const formData = new FormData();
                formData.append('csrf_token', CSRF_TOKEN);
                formData.append('post_id', postId);
                fetch(SITE_URL + '/api/posts/favorite.php', { method: 'POST', body: formData })
                    .then(r => r.json())
                    .then(d => {
                        if (d.success) { showToast('已取消收藏', 'success'); setTimeout(() => location.reload(), 600); }
                        else showToast(d.message, 'error');
                    })
                    .catch(() => showToast('网络错误', 'error'));
            });
        }

        if (document.getElementById('tab-posts').style.display !== 'none') {
            loadList(SITE_URL + '/api/user/my_posts.php', 'myPostsList', 'myPostsPagination', function(data) {
                return data.posts.map(p => `
                    <div class="uc-data-item">
                        <div class="uc-data-main">
                            <a href="${SITE_URL}/pages/post_detail.php?id=${p.id}" class="uc-data-title">${p.title || '(无标题)'}</a>
                            <div class="uc-data-content">${p.content || ''}</div>
                            <div class="uc-data-meta">${p.timeAgo} · ${p.category_name} · ${p.view_count || 0}阅读 · ${p.like_count || 0}赞 · ${p.comment_count || 0}评论 · ${p.status === 'pending' ? '审核中' : p.status === 'rejected' ? '已驳回' : ''}</div>
                        </div>
                        <div class="uc-data-actions">
                            <button class="uc-btn uc-btn-danger uc-btn-sm" data-delete-post="${p.id}">删除</button>
                        </div>
                    </div>`).join('');

                setTimeout(() => {
                    document.querySelectorAll('[data-delete-post]').forEach(btn => {
                        btn.addEventListener('click', function() { deletePost(parseInt(this.dataset.deletePost)); });
                    });
                }, 100);
            });
        }

        if (document.getElementById('tab-comments').style.display !== 'none') {
            loadList(SITE_URL + '/api/user/my_comments.php', 'myCommentsList', 'myCommentsPagination', function(data) {
                return data.comments.map(c => `
                    <div class="uc-data-item">
                        <div class="uc-data-main">
                            <a href="${SITE_URL}/pages/post_detail.php?id=${c.post_id}" class="uc-data-title">评论于：${c.post_title || '(无标题)'}</a>
                            <div class="uc-data-content">${c.content || ''}</div>
                            <div class="uc-data-meta">${c.timeAgo || c.created_at}</div>
                        </div>
                        <div class="uc-data-actions">
                            <button class="uc-btn uc-btn-danger uc-btn-sm" data-delete-comment="${c.id}">删除</button>
                        </div>
                    </div>`).join('');
                setTimeout(() => {
                    document.querySelectorAll('[data-delete-comment]').forEach(btn => {
                        btn.addEventListener('click', function() { deleteComment(parseInt(this.dataset.deleteComment)); });
                    });
                }, 100);
            });
        }

        if (document.getElementById('tab-favorites').style.display !== 'none') {
            loadList(SITE_URL + '/api/user/my_favorites.php', 'myFavoritesList', 'myFavoritesPagination', function(data) {
                return data.favorites.map(f => `
                    <div class="uc-data-item">
                        <div class="uc-data-main">
                            <a href="${SITE_URL}/pages/post_detail.php?id=${f.post_id}" class="uc-data-title">${f.title || '(无标题)'}</a>
                            <div class="uc-data-content">${f.content || ''}</div>
                            <div class="uc-data-meta">${f.timeAgo} · ${f.author ? f.author.nickname : ''} · ${f.like_count || 0}赞 · ${f.comment_count || 0}评论</div>
                        </div>
                        <div class="uc-data-actions">
                            <button class="uc-btn uc-btn-outline uc-btn-sm" data-unfavorite="${f.post_id}">取消收藏</button>
                        </div>
                    </div>`).join('');
                setTimeout(() => {
                    document.querySelectorAll('[data-unfavorite]').forEach(btn => {
                        btn.addEventListener('click', function() { unfavoritePost(parseInt(this.dataset.unfavorite)); });
                    });
                }, 100);
            });
        }

        if (document.getElementById('tab-activity').style.display !== 'none') {
            loadList(SITE_URL + '/api/user/activity_logs.php', 'activityLogBody', 'activityLogPagination', function(data) {
                return data.logs.map(l => `
                    <tr>
                        <td>${l.created_at}</td>
                        <td>${l.action}</td>
                        <td>${l.details || ''}</td>
                        <td>${l.ip}</td>
                    </tr>`).join('');
            });
        }

        document.querySelectorAll('.uc-theme-option').forEach(opt => {
            opt.addEventListener('click', function() {
                const theme = this.dataset.theme;
                document.querySelectorAll('.uc-theme-option').forEach(o => o.classList.remove('active'));
                this.classList.add('active');

                document.body.classList.remove('light-theme', 'dark-theme');
                document.body.classList.add(theme + '-theme');
                localStorage.setItem('theme', theme);

                const formData = new FormData();
                formData.append('csrf_token', CSRF_TOKEN);
                formData.append('theme', theme);
                fetch(SITE_URL + '/api/user/update_theme.php', {
                    method: 'POST',
                    body: formData,
                    headers: { 'X-Requested-With': 'XMLHttpRequest' }
                })
                    .then(r => r.json())
                    .then(d => {
                        if (d.success) {
                            showToast(d.message, 'success');
                        } else {
                            showToast(d.message || '主题切换失败', 'error');
                        }
                    })
                    .catch(() => {
                        showToast('网络错误，请重试', 'error');
                    });
            });
        });
    })();
    </script>
    <script src="<?= SITE_URL ?>/assets/js/enhancements.js?v=<?= asset_ver('/assets/js/enhancements.js') ?>" defer></script>
</body>
</html>
