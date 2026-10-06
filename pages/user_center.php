<?php

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../includes/totp.php';
require_once __DIR__ . '/../includes/level.php';
require_once __DIR__ . '/../includes/user_level_badge.php';

$user = requireMember('个人中心需要注册账号后才能使用');
$user = checkBanned($user);

$csrfToken = generateCSRFToken();

// 是否存在待审核的 QQ 修改申请（用于按钮区域展示「待审核中」）
$pendingQqChange = getFS()->findOne('qq_change_requests', ['user_id' => $user['id'], 'status' => 'pending']);
$tab = isset($_REQUEST['tab']) ? sanitizeInput($_REQUEST['tab']) : 'profile';

$validTabs = ['profile', 'stats', 'security', 'posts', 'comments', 'favorites', 'activity', 'theme', 'invite'];
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
        'stats' => '<svg width="18" height="18" viewBox="0 0 24 24" fill="none"><defs><linearGradient id="' . $uid . 'a" x1="0%" y1="0%" x2="100%" y2="100%"><stop offset="0%" stop-color="#2E8B57"/><stop offset="100%" stop-color="#1F6B45"/></linearGradient></defs><rect x="3" y="12" width="4" height="9" rx="1.2" fill="url(#' . $uid . 'a)"/><rect x="10" y="7" width="4" height="14" rx="1.2" fill="url(#' . $uid . 'a)"/><rect x="17" y="3" width="4" height="18" rx="1.2" fill="url(#' . $uid . 'a)"/></svg>',
        'security' => '<svg width="18" height="18" viewBox="0 0 24 24" fill="none"><defs><linearGradient id="' . $uid . 'a" x1="0%" y1="0%" x2="100%" y2="100%"><stop offset="0%" stop-color="#4A8C5C"/><stop offset="100%" stop-color="#2E6B4A"/></linearGradient></defs><path d="M12 2l8 3v6c0 5-3.5 8.5-8 11-4.5-2.5-8-6-8-11V5l8-3z" fill="url(#' . $uid . 'a)"/><path d="M9 12l2 2 4-4" stroke="#fff" stroke-width="2" fill="none" stroke-linecap="round" stroke-linejoin="round"/></svg>',
        'posts' => '<svg width="18" height="18" viewBox="0 0 24 24" fill="none"><defs><linearGradient id="' . $uid . 'a" x1="0%" y1="0%" x2="100%" y2="100%"><stop offset="0%" stop-color="#2F5B9A"/><stop offset="100%" stop-color="#24487C"/></linearGradient></defs><path d="M12 20h9" stroke="url(#' . $uid . 'a)" stroke-width="2" stroke-linecap="round"/><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4z" fill="url(#' . $uid . 'a)"/></svg>',
        'comments' => '<svg width="18" height="18" viewBox="0 0 24 24" fill="none"><defs><linearGradient id="' . $uid . 'a" x1="0%" y1="0%" x2="100%" y2="100%"><stop offset="0%" stop-color="#5B7BD5"/><stop offset="100%" stop-color="#3A5BA0"/></linearGradient></defs><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z" fill="url(#' . $uid . 'a)"/></svg>',
        'favorites' => '<svg width="18" height="18" viewBox="0 0 24 24" fill="none"><defs><linearGradient id="' . $uid . 'a" x1="0%" y1="0%" x2="100%" y2="100%"><stop offset="0%" stop-color="#E2574C"/><stop offset="100%" stop-color="#C0392B"/></linearGradient></defs><path d="M20.8 4.6a5.5 5.5 0 0 0-7.8 0L12 5.6l-1-1a5.5 5.5 0 0 0-7.8 7.8l1 1L12 20.8l7.8-7.9 1-1a5.5 5.5 0 0 0 0-7.3z" fill="url(#' . $uid . 'a)"/></svg>',
        'activity' => '<svg width="18" height="18" viewBox="0 0 24 24" fill="none"><defs><linearGradient id="' . $uid . 'a" x1="0%" y1="0%" x2="100%" y2="100%"><stop offset="0%" stop-color="#8E44AD"/><stop offset="100%" stop-color="#6C3483"/></linearGradient></defs><circle cx="12" cy="12" r="9" fill="url(#' . $uid . 'a)"/><path d="M12 7v5l3 2" stroke="#fff" stroke-width="2" fill="none" stroke-linecap="round" stroke-linejoin="round"/></svg>',
        'theme' => '<svg width="18" height="18" viewBox="0 0 24 24" fill="none"><defs><linearGradient id="' . $uid . 'a" x1="0%" y1="0%" x2="100%" y2="100%"><stop offset="0%" stop-color="#F39C12"/><stop offset="100%" stop-color="#D68910"/></linearGradient></defs><path d="M21 12.8A9 9 0 1 1 11.2 3a7 7 0 0 0 9.8 9.8z" fill="url(#' . $uid . 'a)"/></svg>',
        'invite' => '<svg width="18" height="18" viewBox="0 0 24 24" fill="none"><defs><linearGradient id="' . $uid . 'a" x1="0%" y1="0%" x2="100%" y2="100%"><stop offset="0%" stop-color="#E2574C"/><stop offset="100%" stop-color="#B8943E"/></linearGradient></defs><path d="M20 12v8a1 1 0 0 1-1 1H5a1 1 0 0 1-1-1v-8" fill="url(#' . $uid . 'a)"/><rect x="2" y="7" width="20" height="5" rx="1" fill="url(#' . $uid . 'a)"/><path d="M12 7v14" stroke="#fff" stroke-width="1.6"/><path d="M12 7S10.5 3 8.5 3 5.5 5 7 7h5zM12 7s1.5-4 3.5-4S18.5 5 17 7h-5z" fill="#fff" opacity=".85"/></svg>',
    ];
    return isset($icons[$name]) ? $icons[$name] : '';
}

$tabs = [
    'profile' => ['name' => t('uc.tab.profile'), 'icon' => ucSvgIcon('profile')],
    'stats' => ['name' => t('uc.tab.stats'), 'icon' => ucSvgIcon('stats')],
    'security' => ['name' => t('uc.tab.security'), 'icon' => ucSvgIcon('security')],
    'posts' => ['name' => t('uc.tab.posts'), 'icon' => ucSvgIcon('posts')],
    'comments' => ['name' => t('uc.tab.comments'), 'icon' => ucSvgIcon('comments')],
    'favorites' => ['name' => t('uc.tab.favorites'), 'icon' => ucSvgIcon('favorites')],
    'activity' => ['name' => t('uc.tab.activity'), 'icon' => ucSvgIcon('activity')],
    'theme' => ['name' => t('uc.tab.theme'), 'icon' => ucSvgIcon('theme')],
];

// 邀请好友页签：仅当邀请功能开启时展示（后台可一键关闭）
require_once __DIR__ . '/../includes/invite.php';
$inviteEnabled = lwInviteEnabled();
if ($inviteEnabled) {
    $tabs['invite'] = ['name' => t('invite.tab'), 'icon' => ucSvgIcon('invite')];
}

// 仅当真正停留在邀请页签时才查数据，避免每次打开个人中心都多扫一遍用户表
$inviteData  = ['code' => '', 'link' => '', 'count' => 0, 'list' => []];
$inviteBoard = [];
if ($inviteEnabled && $tab === 'invite') {
    $inviteData  = lwInviteStats((int)$user['id']);
    $inviteBoard = lwInviteLeaderboard(10);
}
?>
<!DOCTYPE html>
<html lang="<?= $LANG_CODE ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <?php require_once __DIR__ . '/../includes/pwa_head.php'; ?>
    <link rel="icon" href="/icon.ico" type="image/x-icon">
    <title><?= t('uc.page_title') ?> - <?= SITE_NAME ?></title>
    <link rel="stylesheet" href="<?= asset_url('/assets/css/style.css') ?>?v=<?= asset_ver('/assets/css/style.css') ?>">
    <link rel="stylesheet" href="<?= asset_url('/assets/css/enhancements.css') ?>?v=<?= asset_ver('/assets/css/enhancements.css') ?>">
    <script>
        const SITE_URL = '<?= SITE_URL ?>';
        const IS_LOGGED_IN = true;
        const USER_DATA = <?= json_encode(['id' => $user['id'], 'qq' => $user['qq'], 'uuid' => $user['uuid'] ?? '', 'nickname' => $user['nickname'], 'avatar' => $user['avatar'], 'role' => $user['role']], JSON_HEX_TAG | JSON_UNESCAPED_UNICODE) ?>;
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
            padding: 1.5rem 1rem;
        }
        .uc-layout {
            display: flex;
            gap: 1.5rem;
            min-height: calc(100vh - 8.75rem);
        }
        .uc-sidebar {
            width: 12.5rem;
            flex-shrink: 0;
            background: var(--card-bg);
            border: 1px solid var(--border-glass);
            border-radius: var(--radius-card);
            overflow: hidden;
            position: sticky;
            top: 5.25rem;
            align-self: flex-start;
        }
        .uc-sidebar .uc-nav-item {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            padding: 0.875rem 1.25rem;
            font-size: 0.875rem;
            color: var(--text-secondary);
            text-decoration: none;
            transition: all 0.2s ease;
            border-left: 3px solid transparent;
            min-height: 3rem;
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
            border: 1px solid var(--border-glass);
            border-radius: var(--radius-card);
            padding: 1.75rem;
            margin-bottom: 1.25rem;
            box-shadow: var(--shadow);
        }
        .uc-section h2 {
            font-size: 1.25rem;
            margin-bottom: 1.25rem;
            padding-bottom: 0.75rem;
            border-bottom: 1px solid var(--border-light);
        }
        .uc-section h2 svg {
            vertical-align: -3px;
            margin-right: 0.375rem;
        }
        .uc-sub-section {
            margin-bottom: 0.5rem;
        }
        .uc-sub-title {
            font-size: 1rem;
            margin-bottom: 0.5rem;
            color: var(--text);
        }
        .uc-sub-desc {
            font-size: 0.82rem;
            color: var(--text-secondary);
            margin-bottom: 0.75rem;
        }
        .uc-divider {
            border: none;
            border-top: 1px solid var(--border-light);
            margin: 1.5rem 0;
        }
        .uc-badge {
            display: inline-block;
            padding: 2px 0.625rem;
            border-radius: 0.75rem;
            font-size: 0.7rem;
            font-weight: 600;
            margin-left: 0.5rem;
            vertical-align: 2px;
        }
        .uc-badge-success { background: var(--success-light); color: var(--success); }
        .uc-badge-warning { background: var(--warning-light); color: var(--warning); }
        .uc-setup-hint {
            display: inline-block;
            margin-top: 0.25rem;
            padding: 2px 0.5rem;
            background: var(--primary-light);
            color: var(--primary);
            border-radius: 0.75rem;
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
            gap: 1.25rem;
            padding: 1.25rem;
            background: var(--bg);
            border-radius: var(--radius-sm);
            margin-bottom: 1.25rem;
        }
        .uc-avatar-wrap { position: relative; display: inline-block; flex-shrink: 0; }
        .uc-avatar-wrap .lw-level-ring {
            position: absolute; top: -8px; left: -8px;
            color: var(--primary, #E2574C); pointer-events: none;
        }
        .uc-avatar-wrap .lw-level-ring-label {
            position: absolute; inset: 0; display: flex; align-items: center; justify-content: center;
            font-size: 0.625rem; font-weight: 700; color: var(--primary, #E2574C);
            background: var(--bg); border-radius: 50%; width: 1.6rem; height: 1.6rem;
            top: auto; bottom: -2px; right: -2px; left: auto;
            box-shadow: 0 1px 4px rgba(0,0,0,.15);
        }
        .uc-avatar {
            width: 4.5rem;
            height: 4.5rem;
            border-radius: 50%;
            object-fit: cover;
            border: 3px solid var(--primary-light);
            background: var(--bg-secondary);
        }
        .uc-profile-info h3 {
            font-size: 1.1rem;
            margin-bottom: 0.25rem;
        }
        .uc-profile-info .uc-qq {
            font-size: 0.8rem;
            color: var(--text-secondary);
        }
        .uc-form-group {
            margin-bottom: 1.125rem;
        }
        .uc-form-group label {
            display: block;
            font-size: 0.85rem;
            font-weight: 600;
            color: var(--text);
            margin-bottom: 0.375rem;
        }
        .uc-form-input {
            width: 100%;
            padding: 0.625rem 0.875rem;
            border: 1px solid var(--border);
            border-radius: var(--radius-sm);
            font-size: 0.9375rem;
            color: var(--text);
            background: var(--bg);
            transition: all 0.15s ease;
            outline: none;
            min-height: 2.75rem;
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
            min-height: 5rem;
            resize: vertical;
        }
        .uc-error-msg {
            font-size: 0.75rem;
            color: var(--danger);
            margin-top: 0.25rem;
            display: none;
            align-items: center;
            gap: 0.25rem;
        }
        .uc-error-msg.show { display: flex; }
        .uc-form-input.uc-has-value { font-weight: 600; color: var(--primary); }
        .uc-subject-wrap { display: flex; align-items: center; gap: 0.625rem; }
        .uc-subject-cap { font-size: 0.8rem; color: var(--text-secondary); white-space: nowrap; min-width: 2.25rem; }
        .uc-subject-grid { display: flex; flex-wrap: wrap; gap: 0.5rem; }
        .uc-subject-tag { position: relative; }
        .uc-subject-tag input { position: absolute; opacity: 0; pointer-events: none; }
        .uc-subject-tag span {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-width: 2.75rem;
            min-height: 2.25rem;
            padding: 0.3125rem 0.75rem;
            font-size: 0.8125rem;
            border: 1px solid var(--border);
            border-radius: 0.5625rem;
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
            gap: 0.375rem;
            padding: 0.625rem 1.25rem;
            border: none;
            border-radius: var(--radius-sm);
            font-size: 0.875rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s ease;
            min-height: 2.75rem;
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
            padding: 0.375rem 0.875rem;
            font-size: 0.8125rem;
            min-height: 2.25rem;
        }
        .uc-input-wrapper {
            position: relative;
        }
        .uc-toggle-pw {
            position: absolute;
            right: 0.5rem;
            top: 50%;
            transform: translateY(-50%);
            background: none;
            border: none;
            cursor: pointer;
            padding: 0.5rem;
            color: var(--text-secondary);
            font-size: 1rem;
        }
        .uc-toggle-pw:hover { color: var(--text); }
        .uc-pw-strength {
            display: flex;
            gap: 0.25rem;
            margin-top: 0.5rem;
            align-items: center;
        }
        .uc-strength-bar {
            height: 0.25rem;
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
            margin-left: 0.5rem;
        }
        .uc-strength-text.weak { color: var(--danger); }
        .uc-strength-text.medium { color: var(--warning); }
        .uc-strength-text.strong { color: var(--success); }
        .uc-alert {
            padding: 0.75rem 1rem;
            border-radius: var(--radius-sm);
            font-size: 0.875rem;
            margin-bottom: 1rem;
            display: none;
        }
        .uc-alert.show { display: block; }
        .uc-alert-error { background: var(--danger-light); color: var(--danger); border: 1px solid #f5c6cb; }
        .uc-alert-success { background: var(--success-light); color: var(--success); border: 1px solid #c3e6cb; }
        .uc-alert-info { background: var(--info-light); color: var(--info); border: 1px solid #b8d4ff; }
        .uc-spinner {
            display: inline-block;
            width: 1rem;
            height: 1rem;
            border: 2px solid rgba(255,255,255,0.3);
            border-top-color: #fff;
            border-radius: 50%;
            animation: spin 0.6s linear infinite;
        }
        @keyframes spin { to { transform: rotate(360deg); } }
        .uc-toast {
            position: fixed;
            top: 1.25rem;
            left: 50%;
            transform: translateX(-50%);
            padding: 0.75rem 1.5rem;
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
            padding: 1rem 0 1rem 3rem;
            position: relative;
            border-bottom: 1px solid var(--border-light);
        }
        .uc-2fa-step:last-child { border-bottom: none; }
        .uc-2fa-step::before {
            counter-increment: step;
            content: counter(step);
            position: absolute;
            left: 0;
            top: 1rem;
            width: 2rem;
            height: 2rem;
            background: var(--primary);
            color: #fff;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 0.875rem;
            font-weight: 700;
        }
        .uc-2fa-step h4 { font-size: 0.95rem; margin-bottom: 0.25rem; }
        .uc-2fa-step p { font-size: 0.82rem; color: var(--text-secondary); margin: 0; }
        .uc-qr-container {
            display: flex;
            justify-content: center;
            padding: 1.25rem;
            background: #fff;
            border: 1px solid var(--border);
            border-radius: var(--radius-sm);
            margin: 0.75rem 0;
        }
        .uc-qr-container img { width: 12.5rem; height: 12.5rem; }
        .uc-recovery-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(160px, 1fr));
            gap: 0.5rem;
            margin: 0.75rem 0;
        }
        .uc-recovery-item {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0.5rem 0.75rem;
            background: var(--bg);
            border: 1px solid var(--border);
            border-radius: 0.25rem;
            font-family: 'Courier New', monospace;
            font-size: 0.85rem;
            letter-spacing: 1px;
        }
        .uc-recovery-item button {
            font-size: 0.7rem;
            color: var(--primary);
            cursor: pointer;
            border: 1px solid var(--primary);
            border-radius: 0.25rem;
            background: transparent;
            padding: 2px 0.5rem;
        }
        .uc-recovery-item button:hover { background: var(--primary); color: #fff; }
        .uc-manual-key {
            background: var(--bg);
            padding: 0.625rem 0.875rem;
            border-radius: var(--radius-sm);
            font-family: 'Courier New', monospace;
            font-size: 0.85rem;
            word-break: break-all;
            margin: 0.5rem 0;
            border: 1px solid var(--border);
        }
        .uc-2fa-code-input {
            display: flex;
            gap: 0.5rem;
            margin: 0.75rem 0;
        }
        .uc-2fa-code-input input {
            width: 2.75rem;
            height: 3.25rem;
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
            padding: 1rem 0;
            border-bottom: 1px solid var(--border-light);
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            gap: 0.75rem;
        }
        .uc-data-item:last-child { border-bottom: none; }
        .uc-data-main { flex: 1; min-width: 0; }
        .uc-data-title {
            font-size: 0.9375rem;
            font-weight: 600;
            margin-bottom: 0.25rem;
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
            margin-top: 0.25rem;
            word-break: break-word;
        }
        .uc-data-actions {
            flex-shrink: 0;
            display: flex;
            gap: 0.5rem;
        }
        .uc-pagination {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.375rem;
            margin-top: 1.25rem;
            flex-wrap: wrap;
        }
        .uc-pagination button {
            padding: 0.5rem 0.875rem;
            border: 1px solid var(--border);
            background: var(--card-bg);
            border-radius: var(--radius-sm);
            cursor: pointer;
            font-size: 0.8125rem;
            color: var(--text);
            min-height: 2.25rem;
        }
        .uc-pagination button:hover { background: var(--bg); }
        .uc-pagination button.active { background: var(--primary); color: #fff; border-color: var(--primary); }
        .uc-pagination button:disabled { opacity: 0.4; cursor: not-allowed; }
        .uc-empty {
            text-align: center;
            padding: 2.5rem 1.25rem;
            color: var(--text-secondary);
            font-size: 0.9rem;
        }

        .uc-log-table {
            width: 100%;
            border-collapse: collapse;
        }
        .uc-log-table th, .uc-log-table td {
            padding: 0.625rem 0.875rem;
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
            gap: 1rem;
            flex-wrap: wrap;
        }
        .uc-theme-option {
            flex: 1;
            min-width: 96px;
            padding: 1.25rem;
            border: 2px solid var(--border);
            border-radius: var(--radius-sm);
            text-align: center;
            cursor: pointer;
            transition: all 0.2s ease;
            background: var(--bg);
        }
        .uc-theme-option:hover { border-color: var(--primary); }
        .uc-theme-option:focus-visible { outline: 2px solid var(--primary); outline-offset: 2px; }
        .uc-theme-option.active { border-color: var(--primary); background: var(--primary-light); }
        .uc-theme-option .uc-theme-icon { font-size: 2rem; margin-bottom: 0.5rem; }
        .uc-theme-option .uc-theme-label { font-size: 0.9rem; font-weight: 600; }
        .uc-font-toggle { display: block; margin-top: 0.5rem; }
        .uc-font-head { display: flex; align-items: baseline; justify-content: space-between; gap: 0.625rem; }
        .uc-font-title { font-size: 0.82rem; color: var(--text-secondary); margin: 0.25rem 0 2px; }
        .uc-font-value { font-size: 0.82rem; font-weight: 600; color: var(--primary); font-variant-numeric: tabular-nums; }
        .uc-font-range { -webkit-appearance: none; appearance: none; width: 100%; height: 0.375rem; margin: 0.75rem 0 2px; border-radius: 62.4375rem; background: var(--border); outline: none; cursor: pointer; }
        .uc-font-range::-webkit-slider-thumb { -webkit-appearance: none; appearance: none; width: 1.375rem; height: 1.375rem; border-radius: 50%; background: var(--primary); border: 2px solid var(--card-bg); box-shadow: 0 2px 6px rgba(0,0,0,0.18); cursor: pointer; }
        .uc-font-range::-moz-range-thumb { width: 1.25rem; height: 1.25rem; border-radius: 50%; background: var(--primary); border: 2px solid var(--card-bg); box-shadow: 0 2px 6px rgba(0,0,0,0.18); cursor: pointer; }
        .uc-font-range::-moz-range-track { height: 0.375rem; border-radius: 62.4375rem; background: var(--border); }
        .uc-font-range:focus-visible { box-shadow: 0 0 0 3px var(--primary-light); }
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
            border-radius: var(--radius-card);
            padding: 1.5rem;
            width: 90%;
            max-width: 400px;
            box-shadow: 0 16px 48px rgba(0,0,0,0.2);
            text-align: center;
        }
        .uc-confirm-box h3 { margin-bottom: 0.75rem; }
        .uc-confirm-box p { font-size: 0.9rem; color: var(--text-secondary); margin-bottom: 1.25rem; }
        .uc-confirm-actions {
            display: flex;
            gap: 0.75rem;
            justify-content: center;
        }
        /* ---- 数据看板 ---- */
        .uc-stat-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(10.5rem, 1fr)); gap: 0.875rem; margin-bottom: 1.25rem; }
        .uc-stat-card {
            position: relative; overflow: hidden; padding: 1rem 1.125rem;
            border-radius: var(--radius-card); border: 1px solid var(--border-light);
            background: var(--card-bg);
            backdrop-filter: blur(var(--glass-blur)) saturate(160%);
            -webkit-backdrop-filter: blur(var(--glass-blur)) saturate(160%);
            box-shadow: var(--shadow-sm);
            transition: transform 0.22s cubic-bezier(0.2, 0.7, 0.3, 1), box-shadow 0.22s;
        }
        .uc-stat-card:hover { transform: translateY(-2px); box-shadow: var(--shadow-md); }
        .uc-stat-card::after {
            content: ''; position: absolute; right: -1.75rem; top: -1.75rem;
            width: 5rem; height: 5rem; border-radius: 50%; background: var(--primary-light); opacity: 0.5; pointer-events: none;
        }
        .uc-stat-top { position: relative; z-index: 1; display: flex; align-items: center; gap: 0.375rem; font-size: 0.78rem; color: var(--text-secondary); }
        .uc-stat-value { position: relative; z-index: 1; margin-top: 0.5rem; font-size: 1.75rem; font-weight: 800; letter-spacing: -0.02em; line-height: 1.1; color: var(--text); font-variant-numeric: tabular-nums; }
        .uc-stat-hint { position: relative; z-index: 1; margin-top: 0.3125rem; font-size: 0.72rem; color: var(--text-tertiary); line-height: 1.5; }
        .uc-stat-hint b { color: var(--text-secondary); font-weight: 600; }
        .uc-stat-panel {
            padding: 1.125rem 1.25rem; margin-bottom: 1.25rem;
            border-radius: var(--radius-card); border: 1px solid var(--border-light);
            background: var(--card-bg); box-shadow: var(--shadow-sm);
        }
        .uc-stat-panel-head { display: flex; align-items: baseline; justify-content: space-between; gap: 0.75rem; flex-wrap: wrap; margin-bottom: 1rem; }
        .uc-stat-panel-title { font-size: 0.92rem; font-weight: 700; color: var(--text); }
        .uc-stat-panel-hint { font-size: 0.75rem; color: var(--text-tertiary); }
        .uc-trend { display: flex; align-items: flex-end; gap: 0.5rem; height: 9rem; }
        .uc-trend-col { flex: 1; height: 100%; display: flex; flex-direction: column; align-items: center; gap: 0.375rem; min-width: 0; }
        .uc-trend-bars { flex: 1; width: 100%; display: flex; align-items: flex-end; justify-content: center; gap: 0.1875rem; }
        .uc-trend-bar { width: 0.625rem; min-height: 0.1875rem; border-radius: 0.1875rem 0.1875rem 0.0625rem 0.0625rem; background: var(--primary); opacity: 0.75; transition: height 0.35s cubic-bezier(0.2, 0.7, 0.3, 1), opacity 0.2s; }
        .uc-trend-bar.received { background: var(--success); }
        .uc-trend-col:hover .uc-trend-bar { opacity: 1; }
        .uc-trend-label { font-size: 0.68rem; color: var(--text-tertiary); white-space: nowrap; }
        .uc-trend-legend { display: flex; gap: 1rem; margin-top: 0.75rem; font-size: 0.72rem; color: var(--text-tertiary); }
        .uc-trend-legend i { display: inline-block; width: 0.5rem; height: 0.5rem; border-radius: 0.125rem; margin-right: 0.3125rem; background: var(--primary); vertical-align: middle; }
        .uc-trend-legend .lg-received i { background: var(--success); }
        .uc-top-item { display: flex; align-items: center; gap: 0.75rem; padding: 0.625rem 0; border-bottom: 1px dashed var(--border-light); }
        .uc-top-item:last-child { border-bottom: none; padding-bottom: 0; }
        .uc-top-rank { flex: 0 0 auto; width: 1.375rem; height: 1.375rem; border-radius: 0.4375rem; display: flex; align-items: center; justify-content: center; font-size: 0.72rem; font-weight: 700; background: var(--primary-light); color: var(--primary); }
        .uc-top-item:first-child .uc-top-rank { background: var(--warning-light); color: var(--warning); }
        .uc-top-main { flex: 1; min-width: 0; }
        .uc-top-title { display: block; font-size: 0.86rem; font-weight: 600; color: var(--text); text-decoration: none; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        .uc-top-title:hover { color: var(--primary); }
        .uc-top-meta { margin-top: 0.1875rem; font-size: 0.72rem; color: var(--text-tertiary); display: flex; gap: 0.625rem; flex-wrap: wrap; }
        .uc-stat-empty { padding: 1.5rem 0; text-align: center; font-size: 0.82rem; color: var(--text-tertiary); }
        @media (max-width: 768px) {
            .uc-layout { flex-direction: column; }
            .uc-sidebar {
                width: 100%;
                position: static;
                display: flex;
                flex-direction: row;
                overflow-x: auto;
                border-radius: var(--radius-card);
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
            .uc-stat-grid { grid-template-columns: repeat(2, 1fr); gap: 0.625rem; }
            .uc-stat-value { font-size: 1.5rem; }
            .uc-stat-panel { padding: 1rem; }
            .uc-trend { height: 7.5rem; gap: 0.25rem; }
        }

        /* ===== 邀请好友 ===== */
        .uc-invite-hero {
            background: linear-gradient(135deg, rgba(47,91,154,0.08), rgba(201,169,110,0.16));
            border: 1px solid rgba(47,91,154,0.16);
            border-radius: var(--radius-card);
            padding: 1.5rem 1.25rem;
            margin-bottom: 1.25rem;
            text-align: center;
        }
        .uc-invite-hero h3 { font-size: 1.125rem; margin: 0 0 0.375rem; color: var(--text); }
        .uc-invite-hero p { font-size: 0.8125rem; color: var(--text-secondary); margin: 0 0 1rem; line-height: 1.6; }
        .uc-invite-code {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            font-family: 'SFMono-Regular', Consolas, 'Liberation Mono', Menlo, monospace;
            font-size: 1.75rem;
            font-weight: 700;
            letter-spacing: 0.25rem;
            color: var(--primary);
            padding: 0.625rem 1.25rem;
            background: var(--card-bg);
            border: 2px dashed rgba(47,91,154,0.35);
            border-radius: var(--radius-sm);
            user-select: all;
        }
        .uc-invite-actions { display: flex; flex-wrap: wrap; gap: 0.625rem; justify-content: center; margin-top: 1rem; }
        .uc-invite-actions .uc-btn { width: auto; padding: 0.625rem 1.25rem; }
        .uc-invite-stat {
            display: inline-flex;
            align-items: baseline;
            gap: 0.35rem;
            margin-top: 1rem;
            font-size: 0.875rem;
            color: var(--text-secondary);
        }
        .uc-invite-stat .num { font-size: 1.75rem; font-weight: 800; color: var(--primary); }
        .uc-invite-card {
            border: 1px solid var(--border-glass);
            border-radius: var(--radius-card);
            padding: 1.125rem 1.25rem;
            margin-bottom: 1.25rem;
            background: var(--card-bg);
        }
        .uc-invite-card > h3 { font-size: 0.9375rem; margin: 0 0 0.875rem; display: flex; align-items: center; gap: 0.5rem; }
        .uc-invite-people { display: flex; flex-direction: column; gap: 0.5rem; }
        .uc-invite-person { display: flex; align-items: center; gap: 0.75rem; padding: 0.5rem 0; border-bottom: 1px solid var(--border-light, #EEF1F4); }
        .uc-invite-person:last-child { border-bottom: none; }
        .uc-invite-person img { width: 2.25rem; height: 2.25rem; border-radius: 50%; object-fit: cover; flex-shrink: 0; background: var(--bg); }
        .uc-invite-person .name { font-size: 0.875rem; font-weight: 600; color: var(--text); }
        .uc-invite-person .time { font-size: 0.75rem; color: var(--text-muted); margin-left: auto; flex-shrink: 0; }
        .uc-invite-rank { display: flex; flex-direction: column; gap: 0.375rem; }
        .uc-invite-rank-row { display: flex; align-items: center; gap: 0.75rem; padding: 0.5rem 0.625rem; border-radius: var(--radius-sm); }
        .uc-invite-rank-row.me { background: var(--primary-light); }
        .uc-invite-rank-row .rank-no { width: 1.5rem; text-align: center; font-weight: 800; color: var(--text-muted); flex-shrink: 0; }
        .uc-invite-rank-row.top1 .rank-no { color: #D4A017; }
        .uc-invite-rank-row.top2 .rank-no { color: #9AA5B1; }
        .uc-invite-rank-row.top3 .rank-no { color: #C08457; }
        .uc-invite-rank-row img { width: 1.875rem; height: 1.875rem; border-radius: 50%; object-fit: cover; flex-shrink: 0; }
        .uc-invite-rank-row .name { font-size: 0.875rem; color: var(--text); overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        .uc-invite-rank-row .cnt { margin-left: auto; font-size: 0.8125rem; font-weight: 700; color: var(--primary); flex-shrink: 0; }
        .uc-invite-empty { text-align: center; padding: 1.5rem 0; color: var(--text-muted); font-size: 0.8125rem; }
        .uc-invite-share { display: flex; gap: 0.625rem; justify-content: center; margin-top: 0.875rem; flex-wrap: wrap; }
        .uc-share-btn { border: 1px solid var(--border, #e5e5e5); background: #fff; color: var(--text); border-radius: 999px; padding: 0.375rem 1rem; font-size: 0.8125rem; cursor: pointer; transition: all .18s ease; }
        .uc-share-btn:hover { transform: translateY(-1px); }
        .uc-share-btn.wechat { color: #07C160; border-color: rgba(7,193,96,.35); }
        .uc-share-btn.wechat:hover { background: #07C160; color: #fff; }
        .uc-share-btn.qq { color: #12B7F5; border-color: rgba(18,183,245,.35); }
        .uc-share-btn.qq:hover { background: #12B7F5; color: #fff; }
        .uc-share-btn.weibo { color: #E6162D; border-color: rgba(230,22,45,.35); }
        .uc-share-btn.weibo:hover { background: #E6162D; color: #fff; }
        .uc-invite-poster-wrap { text-align: center; margin-top: 0.75rem; }
        #invitePosterImg { max-width: 100%; width: 320px; border-radius: var(--radius-card); box-shadow: var(--shadow, 0 8px 24px rgba(0,0,0,.12)); display: none; }
        #invitePosterImg.show { display: inline-block; }
        @media (max-width: 480px) {
            .uc-invite-code { font-size: 1.375rem; letter-spacing: 0.15rem; padding: 0.5rem 1rem; }
            .uc-invite-actions { flex-direction: column; }
            .uc-invite-actions .uc-btn { width: 100%; }
        }
    </style>
</head>
<body>
    <?php
    $headerBackHref = SITE_URL . '/';
    $headerBackText = t('uc.back_home');
    require __DIR__ . '/../includes/site_header.php';
    ?>

    <?php require __DIR__ . '/../includes/ai_widget.php'; ?>

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
                    <h2><?= t('uc.tab.profile') ?></h2>
                    <div class="uc-profile-card">
                        <div class="uc-avatar-wrap">
                            <img src="<?= xss_clean($user['avatar']) ?: getQQAvatar($user['qq']) ?>" alt="<?= t('uc.avatar_alt') ?>" class="uc-avatar" id="profileAvatar" onerror="this.src='<?= SITE_URL ?>/assets/images/default-avatar.svg'">
                            <?php
                            $levelInfo = lwLevelRow((int)$user['id']);
                            echo lwLevelRingHTML($levelInfo, 88);
                            ?>
                        </div>
                        <div class="uc-profile-info">
                            <h3><?= xss_clean($user['nickname'] ?: t('uc.nickname_unset')) ?></h3>
                            <span class="uc-qq">QQ: <?= xss_clean($user['qq']) ?></span>
                            <?php if (empty($user['nickname'])): ?>
                            <span class="uc-setup-hint"><?= t('uc.setup_hint') ?></span>
                            <?php endif; ?>
                        </div>
                    </div>
                    <form id="profileForm" autocomplete="off">
                        <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
                        <div class="uc-form-group">
                            <label><?= t('uc.qq_label') ?> <span class="uc-label-hint"><?= t('uc.qq_bound_hint') ?></span></label>
                            <input type="text" class="uc-form-input" value="<?= xss_clean($user['qq']) ?>" disabled>
                            <?php if (!empty($pendingQqChange)): ?>
                            <div class="uc-change-qq-status" id="qqChangeStatus"><?= t('uc.qq_pending') ?></div>
                            <?php else: ?>
                            <button type="button" class="btn btn-outline btn-sm" id="btnChangeQQ" style="margin-top:0.5rem;background:transparent;"><?= t('uc.qq_change') ?></button>
                            <?php endif; ?>
                        </div>
                        <div class="uc-form-group">
                            <label for="nickname"><?= t('uc.nickname') ?> <span class="uc-label-hint"><?= t('uc.nickname_hint') ?></span></label>
                            <input type="text" id="nickname" name="nickname" class="uc-form-input" value="<?= xss_clean($user['nickname']) ?>" maxlength="50" placeholder="<?= t('uc.nickname_ph') ?>">
                            <div class="uc-char-count"><span id="nicknameCount"><?= mb_strlen($user['nickname'] ?? '') ?></span>/50</div>
                            <div class="uc-error-msg" id="nicknameError"><span id="nicknameErrorText"></span></div>
                        </div>
                        <div class="uc-form-group">
                            <label for="bio"><?= t('uc.bio') ?> <span class="uc-label-hint"><?= t('uc.bio_hint') ?></span></label>
                            <textarea id="bio" name="bio" class="uc-form-input uc-form-textarea" maxlength="200" placeholder="<?= t('uc.bio_ph') ?>"><?= xss_clean($user['bio']) ?></textarea>
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
                            <label for="grade"><?= t('uc.grade') ?> <span class="uc-label-hint"><?= t('uc.grade_hint') ?></span></label>
                            <select id="grade" name="grade" class="uc-form-input <?= $ucGradeIdx >= 0 ? 'uc-has-value' : '' ?>">
                                <option value=""><?= t('uc.not_filled') ?></option>
                                <?php for ($gi = 0; $gi <= 3; $gi++): ?>
                                    <option value="<?= $gi ?>" <?= $gi === $ucGradeIdx ? 'selected' : '' ?>><?= gradeLabel(entranceYearFromGrade($gi)) ?></option>
                                <?php endfor; ?>
                            </select>
                        </div>
                        <div class="uc-form-group">
                            <label for="classNum"><?= t('uc.class') ?> <span class="uc-label-hint"><?= t('uc.class_hint') ?></span></label>
                            <select id="classNum" name="class_num" class="uc-form-input <?= $ucClass > 0 ? 'uc-has-value' : '' ?>">
                                <option value="0"><?= t('uc.not_filled') ?></option>
                                <?php for ($ci = 1; $ci <= 20; $ci++): ?>
                                    <option value="<?= $ci ?>" <?= $ci === $ucClass ? 'selected' : '' ?>><?= sprintf(t('uc.class_format'), $ci) ?></option>
                                <?php endfor; ?>
                            </select>
                        </div>
                        <div class="uc-form-group">
                            <label for="realName"><?= t('uc.real_name') ?> <span class="uc-label-hint"><?= t('uc.real_name_hint') ?></span></label>
                            <input type="text" id="realName" name="real_name" class="uc-form-input" value="<?= xss_clean($ucRealName) ?>" maxlength="20" placeholder="<?= t('uc.real_name_ph') ?>">
                            <div class="uc-error-msg" id="realNameError"><span id="realNameErrorText"></span></div>
                        </div>
                        <div class="uc-form-group">
                            <label><?= t('uc.subjects') ?> <span class="uc-label-hint"><?= t('uc.subjects_hint') ?></span></label>
                            <div class="uc-subject-wrap">
                                <span class="uc-subject-cap"><?= t('uc.first_subject') ?></span>
                                <div class="uc-subject-grid" id="ucFirstSubject">
                                    <?php foreach (gradeSubjectsFirst() as $s): ?>
                                        <label class="uc-subject-tag"><input type="radio" name="first_subject" value="<?= $s ?>" <?= $ucFirstSub === $s ? 'checked' : '' ?>><span><?= $s ?></span></label>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                            <div class="uc-subject-wrap" style="margin-top:0.5rem;">
                                <span class="uc-subject-cap"><?= t('uc.second_subject') ?></span>
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
                            <span id="profileBtnText"><?= t('uc.save_changes') ?></span>
                            <span class="uc-spinner" id="profileBtnSpinner" style="display:none"></span>
                        </button>
                    </form>
                </div>

                <div class="uc-section" id="tab-security" <?= $tab !== 'security' ? 'style="display:none"' : '' ?>>
                    <h2><?= t('uc.tab.security') ?></h2>
                    <div class="uc-alert uc-alert-error" id="secAlertError"></div>
                    <div class="uc-alert uc-alert-success" id="secAlertSuccess"></div>

                    <div class="uc-sub-section">
                        <h3 class="uc-sub-title"><?= t('uc.change_password') ?></h3>
                        <form id="securityForm" autocomplete="off">
                            <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
                            <div class="uc-form-group">
                                <label for="currentPassword"><?= t('uc.current_password') ?></label>
                                <div class="uc-input-wrapper">
                                    <input type="password" id="currentPassword" name="current_password" class="uc-form-input" placeholder="<?= t('uc.current_password_ph') ?>">
                                    <button type="button" class="uc-toggle-pw" data-target="currentPassword"><?= t('uc.show') ?></button>
                                </div>
                            </div>
                            <div class="uc-form-group">
                                <label for="newPassword"><?= t('uc.new_password') ?> <span class="uc-label-hint"><?= t('uc.new_password_hint') ?></span></label>
                                <div class="uc-input-wrapper">
                                    <input type="password" id="newPassword" name="new_password" class="uc-form-input" placeholder="<?= t('uc.new_password_ph') ?>">
                                    <button type="button" class="uc-toggle-pw" data-target="newPassword"><?= t('uc.show') ?></button>
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
                                <label for="confirmPassword"><?= t('uc.confirm_password') ?></label>
                                <div class="uc-input-wrapper">
                                    <input type="password" id="confirmPassword" name="confirm_password" class="uc-form-input" placeholder="<?= t('uc.confirm_password_ph') ?>">
                                    <button type="button" class="uc-toggle-pw" data-target="confirmPassword"><?= t('uc.show') ?></button>
                                </div>
                            </div>
                            <button type="submit" class="uc-btn uc-btn-primary" id="securitySaveBtn">
                                <svg width="16" height="16" viewBox="0 0 24 24" fill="none"><rect x="4" y="10" width="16" height="11" rx="2" fill="#fff"/><path d="M8 10V7a4 4 0 0 1 8 0v3" stroke="#4A90D9" stroke-width="2" fill="none"/><circle cx="12" cy="15.5" r="1.5" fill="#4A90D9"/></svg>
                                <span id="securityBtnText"><?= t('uc.change_password') ?></span>
                                <span class="uc-spinner" id="securityBtnSpinner" style="display:none"></span>
                            </button>
                        </form>
                    </div>

                    <hr class="uc-divider">

                    <div class="uc-sub-section">
                        <h3 class="uc-sub-title"><?= t('uc.twofa_title') ?> <span class="uc-badge <?= $user['twofa_enabled'] ? 'uc-badge-success' : 'uc-badge-warning' ?>"><?= $user['twofa_enabled'] ? t('uc.twofa_enabled') : t('uc.twofa_disabled') ?></span></h3>
                        <p class="uc-sub-desc"><?= t('uc.twofa_desc') ?></p>
                        <?php if ($user['twofa_enabled']): ?>
                        <div id="twofaEnabled" style="display:flex;gap:0.75rem;flex-wrap:wrap;">
                            <button class="uc-btn uc-btn-danger" id="disable2faBtn"><?= t('uc.twofa_disable') ?></button>
                            <button class="uc-btn uc-btn-outline" id="reset2faBtn"><?= t('uc.twofa_reset') ?></button>
                        </div>
                        <?php else: ?>
                        <div id="twofaSetup">
                            <div class="uc-2fa-steps">
                                <div class="uc-2fa-step">
                                    <h4><?= t('uc.twofa_step1') ?></h4>
                                    <p><?= t('uc.twofa_step1_desc') ?> <strong>Google Authenticator</strong> <?= t('uc.twofa_or') ?> <strong>Microsoft Authenticator</strong></p>
                                </div>
                                <div class="uc-2fa-step">
                                    <h4><?= t('uc.twofa_step2') ?></h4>
                                    <button class="uc-btn uc-btn-primary uc-btn-sm" id="generate2faBtn" style="margin:0.5rem 0;">
                                        <span id="gen2faBtnText"><?= t('uc.twofa_gen_btn') ?></span>
                                        <span class="uc-spinner" id="gen2faBtnSpinner" style="display:none"></span>
                                    </button>
                                    <div id="twofaSetupContent" style="display:none;">
                                        <div class="uc-qr-container" id="qrContainer"></div>
                                        <p style="font-size:0.8rem;margin-top:0.5rem;"><?= t('uc.twofa_manual_key') ?></p>
                                        <div class="uc-manual-key" id="manualKey"></div>
                                    </div>
                                </div>
                                <div class="uc-2fa-step">
                                    <h4><?= t('uc.twofa_step3') ?></h4>
                                    <p><?= t('uc.twofa_step3_desc') ?></p>
                                    <div class="uc-2fa-code-input" id="twofaCodeInput">
                                        <input type="text" maxlength="1" inputmode="numeric" data-index="0">
                                        <input type="text" maxlength="1" inputmode="numeric" data-index="1">
                                        <input type="text" maxlength="1" inputmode="numeric" data-index="2">
                                        <input type="text" maxlength="1" inputmode="numeric" data-index="3">
                                        <input type="text" maxlength="1" inputmode="numeric" data-index="4">
                                        <input type="text" maxlength="1" inputmode="numeric" data-index="5">
                                    </div>
                                    <div class="uc-form-group">
                                        <label for="twofaConfirmPassword"><?= t('uc.twofa_confirm_pw') ?></label>
                                        <input type="password" id="twofaConfirmPassword" class="uc-form-input" placeholder="<?= t('uc.enter_password') ?>" style="max-width:300px;">
                                    </div>
                                    <button class="uc-btn uc-btn-primary" id="verify2faBtn">
                                        <span id="verify2faBtnText"><?= t('uc.twofa_step3') ?></span>
                                        <span class="uc-spinner" id="verify2faBtnSpinner" style="display:none"></span>
                                    </button>
                                </div>
                            </div>
                            <div id="recoveryCodesArea" style="display:none;margin-top:1.25rem;">
                                <h3 style="font-size:1rem;margin-bottom:0.5rem;"><?= t('uc.recovery_codes') ?></h3>
                                <p style="font-size:0.82rem;color:var(--warning);margin-bottom:0.75rem;"><?= t('uc.recovery_hint') ?></p>
                                <div class="uc-recovery-grid" id="recoveryCodesGrid"></div>
                                <div style="display:flex;gap:0.5rem;margin-top:0.75rem;flex-wrap:wrap;">
                                    <button class="uc-btn uc-btn-outline uc-btn-sm" id="copyAllCodes"><?= t('uc.copy_all') ?></button>
                                    <button class="uc-btn uc-btn-outline uc-btn-sm" id="downloadCodes"><?= t('uc.download_txt') ?></button>
                                </div>
                            </div>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="uc-section" id="tab-stats" <?= $tab !== 'stats' ? 'style="display:none"' : '' ?>>
                    <h2><?= t('uc.tab.stats') ?></h2>
                    <div class="uc-stat-grid" id="statsCards"></div>

                    <div class="uc-stat-panel">
                        <div class="uc-stat-panel-head">
                            <div class="uc-stat-panel-title"><?= t('uc.stats.trend_title') ?></div>
                            <div class="uc-stat-panel-hint" id="statsWeekHint"></div>
                        </div>
                        <div class="uc-trend" id="statsTrend"></div>
                        <div class="uc-trend-legend">
                            <span><i></i><?= t('uc.stats.legend_mine') ?></span>
                            <span class="lg-received"><i></i><?= t('uc.stats.legend_received') ?></span>
                        </div>
                    </div>

                    <div class="uc-stat-panel">
                        <div class="uc-stat-panel-head">
                            <div class="uc-stat-panel-title"><?= t('uc.stats.top_title') ?></div>
                            <div class="uc-stat-panel-hint"><?= t('uc.stats.top_hint') ?></div>
                        </div>
                        <div id="statsTopPosts"></div>
                    </div>
                </div>

                <div class="uc-section" id="tab-posts" <?= $tab !== 'posts' ? 'style="display:none"' : '' ?>>
                    <h2><?= t('uc.tab.posts') ?></h2>
                    <div id="myPostsList"></div>
                    <div class="uc-pagination" id="myPostsPagination"></div>
                </div>

                <div class="uc-section" id="tab-comments" <?= $tab !== 'comments' ? 'style="display:none"' : '' ?>>
                    <h2><?= t('uc.tab.comments') ?></h2>
                    <div id="myCommentsList"></div>
                    <div class="uc-pagination" id="myCommentsPagination"></div>
                </div>

                <div class="uc-section" id="tab-favorites" <?= $tab !== 'favorites' ? 'style="display:none"' : '' ?>>
                    <h2><?= t('uc.tab.favorites') ?></h2>
                    <div id="myFavoritesList"></div>
                    <div class="uc-pagination" id="myFavoritesPagination"></div>
                </div>

                <div class="uc-section" id="tab-activity" <?= $tab !== 'activity' ? 'style="display:none"' : '' ?>>
                    <h2><?= t('uc.tab.activity') ?></h2>
                    <div style="overflow-x:auto;">
                        <table class="uc-log-table">
                            <thead><tr><th><?= t('uc.log_time') ?></th><th><?= t('uc.log_action') ?></th><th><?= t('uc.log_detail') ?></th><th>IP</th></tr></thead>
                            <tbody id="activityLogBody"></tbody>
                        </table>
                    </div>
                    <div class="uc-pagination" id="activityLogPagination"></div>
                </div>

                <?php if ($inviteEnabled): ?>
                <div class="uc-section" id="tab-invite" <?= $tab !== 'invite' ? 'style="display:none"' : '' ?>>
                    <h2><?= t('invite.title') ?></h2>

                    <div class="uc-invite-hero">
                        <h3><?= t('invite.my_code') ?></h3>
                        <p><?= t('invite.subtitle') ?></p>
                        <div class="uc-invite-code" id="inviteCodeText"><?= htmlspecialchars($inviteData['code']) ?></div>
                        <div class="uc-invite-actions">
                            <button type="button" class="uc-btn uc-btn-primary" id="btnCopyInviteCode"><?= t('invite.copy_code') ?></button>
                            <button type="button" class="uc-btn uc-btn-outline" id="btnCopyInviteLink"><?= t('invite.copy_link') ?></button>
                            <button type="button" class="uc-btn uc-btn-outline" id="btnInvitePoster"><?= t('invite.poster') ?></button>
                        </div>
                        <div class="uc-invite-share">
                            <button type="button" class="uc-share-btn wechat" data-share="wechat"><?= t('invite.share_wechat') ?></button>
                            <button type="button" class="uc-share-btn qq" data-share="qq"><?= t('invite.share_qq') ?></button>
                            <button type="button" class="uc-share-btn weibo" data-share="weibo"><?= t('invite.share_weibo') ?></button>
                        </div>
                        <div class="uc-invite-stat"><span class="num"><?= (int)$inviteData['count'] ?></span>&nbsp;<?= t('invite.people_unit') ?></div>
                    </div>

                    <div class="uc-invite-card">
                        <h3><?= t('invite.list_title') ?></h3>
                        <?php if (!empty($inviteData['list'])): ?>
                        <div class="uc-invite-people">
                            <?php foreach ($inviteData['list'] as $p): ?>
                            <div class="uc-invite-person">
                                <img src="<?= htmlspecialchars($p['avatar'] !== '' ? $p['avatar'] : (SITE_URL . '/assets/images/default-avatar.svg')) ?>" alt="" loading="lazy" onerror="this.src='<?= SITE_URL ?>/assets/images/default-avatar.svg'">
                                <span class="name"><?= htmlspecialchars($p['nickname'] !== '' ? $p['nickname'] : ('用户' . $p['id'])) ?></span>
                                <span class="time"><?= htmlspecialchars(substr((string)$p['time'], 0, 10)) ?></span>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        <?php else: ?>
                        <div class="uc-invite-empty"><?= t('invite.list_empty') ?></div>
                        <?php endif; ?>
                    </div>

                    <div class="uc-invite-card">
                        <h3><?= t('invite.leaderboard') ?></h3>
                        <?php if (!empty($inviteBoard)): ?>
                        <div class="uc-invite-rank">
                            <?php foreach ($inviteBoard as $i => $row): $rk = $i + 1; ?>
                            <div class="uc-invite-rank-row <?= $rk <= 3 ? 'top' . $rk : '' ?> <?= (int)$row['user_id'] === (int)$user['id'] ? 'me' : '' ?>">
                                <span class="rank-no"><?= $rk ?></span>
                                <img src="<?= htmlspecialchars($row['avatar'] !== '' ? $row['avatar'] : (SITE_URL . '/assets/images/default-avatar.svg')) ?>" alt="" loading="lazy" onerror="this.src='<?= SITE_URL ?>/assets/images/default-avatar.svg'">
                                <span class="name"><?= htmlspecialchars($row['nickname'] !== '' ? $row['nickname'] : ('用户' . $row['user_id'])) ?></span>
                                <span class="cnt"><?= (int)$row['count'] ?></span>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        <?php else: ?>
                        <div class="uc-invite-empty"><?= t('invite.leaderboard_empty') ?></div>
                        <?php endif; ?>
                    </div>

                    <div class="uc-invite-card" id="invitePosterCard" style="display:none;">
                        <h3><?= t('invite.poster') ?></h3>
                        <div class="uc-invite-poster-wrap">
                            <img id="invitePosterImg" alt="<?= htmlspecialchars(t('invite.poster')) ?>">
                            <p class="uc-invite-empty" style="padding-top:0.5rem;"><?= t('invite.poster_tip') ?></p>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <div class="uc-section" id="tab-theme" <?= $tab !== 'theme' ? 'style="display:none"' : '' ?>>
                    <h2><?= t('uc.tab.theme') ?></h2>
                    <p style="margin-bottom:1rem;color:var(--text-secondary);"><?= t('uc.theme_desc') ?></p>
                    <div class="uc-theme-toggle">
                        <div class="uc-theme-option <?= ($user['theme'] ?? 'system') === 'system' ? 'active' : '' ?>" data-theme="system" role="button" tabindex="0" aria-pressed="<?= ($user['theme'] ?? 'system') === 'system' ? 'true' : 'false' ?>">
                            <div class="uc-theme-icon"><?= t('uc.theme_auto') ?></div>
                            <div class="uc-theme-label"><?= t('uc.theme_system') ?></div>
                        </div>
                        <div class="uc-theme-option <?= ($user['theme'] ?? 'system') === 'light' ? 'active' : '' ?>" data-theme="light" role="button" tabindex="0" aria-pressed="<?= ($user['theme'] ?? 'system') === 'light' ? 'true' : 'false' ?>">
                            <div class="uc-theme-icon"><?= t('uc.theme_light') ?></div>
                            <div class="uc-theme-label"><?= t('uc.theme_light_mode') ?></div>
                        </div>
                        <div class="uc-theme-option <?= ($user['theme'] ?? 'system') === 'dark' ? 'active' : '' ?>" data-theme="dark" role="button" tabindex="0" aria-pressed="<?= ($user['theme'] ?? 'system') === 'dark' ? 'true' : 'false' ?>">
                            <div class="uc-theme-icon"><?= t('uc.theme_dark') ?></div>
                            <div class="uc-theme-label"><?= t('uc.theme_dark_mode') ?></div>
                        </div>
                    </div>
                    <div class="uc-font-toggle">
                        <div class="uc-font-head">
                            <div class="uc-font-title"><?= t('uc.font_size') ?></div>
                            <div class="uc-font-value" id="ucFontValue">16px</div>
                        </div>
                        <input type="range" class="uc-font-range" id="ucFontRange" min="12" max="48" step="1" value="16" aria-label="<?= t('uc.font_size') ?>">
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="uc-confirm-modal" id="confirmModal">
        <div class="uc-confirm-box">
            <h3 id="confirmTitle"><?= t('uc.confirm_title') ?></h3>
            <p id="confirmMsg"></p>
            <div class="uc-confirm-actions">
                <button class="uc-btn uc-btn-outline" id="confirmCancel"><?= t('uc.cancel') ?></button>
                <button class="uc-btn uc-btn-danger" id="confirmOk"><?= t('uc.confirm') ?></button>
            </div>
        </div>
    </div>

    <footer class="site-footer">
        <p>&copy; <?= date('Y') ?> <?= t('uc.footer_site') ?></p>
    </footer>

    <?php
    // 停在「收藏」标签时高亮底部导航的收藏项，其余标签都算「我的」
    $mobileNavActive = ($tab === 'favorites') ? 'fav' : 'me';
    require __DIR__ . '/../includes/mobile_bottom_nav.php';
    ?>

    <script>
    (function() {
        const SITE_URL = '<?= SITE_URL ?>';
        const CSRF_TOKEN = '<?= $csrfToken ?>';
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
                if (nickname.length === 0) {
                    document.getElementById('nicknameErrorText').textContent = __t('uc.err_nickname_empty');
                    document.getElementById('nicknameError').classList.add('show');
                    valid = false;
                }
                if (nickname.length > 50) {
                    document.getElementById('nicknameErrorText').textContent = __t('uc.err_nickname_max');
                    document.getElementById('nicknameError').classList.add('show');
                    valid = false;
                }
                if (bio.length > 200) {
                    document.getElementById('bioErrorText').textContent = __t('uc.err_bio_max');
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
                    realNameErrText.textContent = __t('uc.err_realname_len');
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
                    ucSubErrText.textContent = firstVal === '' ? __t('uc.err_first_subject') : __t('uc.err_second_subject');
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
                            document.querySelector('.uc-profile-info h3').textContent = nickname || __t('uc.nickname_unset');
                        } else {
                            showToast(d.message, 'error');
                        }
                    })
                    .catch(() => {
                        setLoading(document.getElementById('profileSaveBtn'), document.getElementById('profileBtnText'), document.getElementById('profileBtnSpinner'), false);
                        showToast(__t('uc.net_error'), 'error');
                    });
            });
        }

        // 「申请修改」QQ：prompt 输入 → 校验格式 → 提交后台
        const btnChangeQQ = document.getElementById('btnChangeQQ');
        if (btnChangeQQ) {
            btnChangeQQ.addEventListener('click', function() {
                const newQq = (prompt(__t('uc.qq_new_prompt')) || '').trim();
                if (!newQq) return;
                if (!/^[1-9][0-9]{4,14}$/.test(newQq)) {
                    showToast(__t('uc.err_qq_format'), 'error');
                    return;
                }
                const reason = (prompt(__t('uc.qq_reason_prompt'), '') || '').trim();
                const fd = new FormData();
                fd.append('csrf_token', CSRF_TOKEN);
                fd.append('action', 'request');
                fd.append('new_qq', newQq);
                fd.append('reason', reason);
                fetch(SITE_URL + '/api/user/change_qq.php', { method: 'POST', body: fd })
                    .then(r => r.json())
                    .then(d => {
                        if (d.success) {
                            showToast(d.message, 'success');
                            const btn = document.getElementById('btnChangeQQ');
                            if (btn) {
                                const status = document.createElement('div');
                                status.className = 'uc-change-qq-status';
                                status.id = 'qqChangeStatus';
                                status.textContent = __t('uc.qq_pending');
                                btn.parentNode.replaceChild(status, btn);
                            }
                        } else {
                            showToast(d.message || __t('uc.submit_fail'), 'error');
                        }
                    })
                    .catch(() => showToast(__t('uc.net_error'), 'error'));
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
                text.textContent = score <= 2 ? __t('uc.pw_weak') : score === 3 ? __t('uc.pw_medium') : __t('uc.pw_strong');
            });

            document.querySelectorAll('.uc-toggle-pw').forEach(btn => {
                btn.addEventListener('click', function() {
                    const target = document.getElementById(this.dataset.target);
                    if (target.type === 'password') { target.type = 'text'; this.textContent = __t('uc.hide'); }
                    else { target.type = 'password'; this.textContent = __t('uc.show'); }
                });
            });

            securityForm.addEventListener('submit', function(e) {
                e.preventDefault();
                const currentPw = document.getElementById('currentPassword').value;
                const newPwVal = document.getElementById('newPassword').value;
                const confirmPw = document.getElementById('confirmPassword').value;

                document.getElementById('secAlertError').classList.remove('show');
                document.getElementById('secAlertSuccess').classList.remove('show');

                if (!currentPw) { showToast(__t('uc.current_password_ph'), 'error'); return; }
                if (newPwVal.length < 6) { showToast(__t('uc.err_new_pw_len'), 'error'); return; }
                if (newPwVal !== confirmPw) { showToast(__t('uc.err_pw_mismatch'), 'error'); return; }

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
                        showToast(__t('uc.net_error'), 'error');
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
                            showToast(__t('uc.twofa_key_generated'), 'info');
                        } else {
                            showToast(d.message, 'error');
                        }
                    })
                    .catch(() => {
                        setLoading(generate2faBtn, document.getElementById('gen2faBtnText'), document.getElementById('gen2faBtnSpinner'), false);
                        showToast(__t('uc.net_error'), 'error');
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
                if (code.length !== 6) { showToast(__t('uc.err_code_6'), 'error'); return; }
                if (!password) { showToast(__t('uc.err_pw_confirm_op'), 'error'); return; }

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
                        showToast(__t('uc.net_error'), 'error');
                    });
            });
        }

        function showRecoveryCodes(codes) {
            const grid = document.getElementById('recoveryCodesGrid');
            grid.innerHTML = codes.map(c =>
                '<div class="uc-recovery-item"><span>' + c + '</span><button onclick="navigator.clipboard.writeText(\'' + c + '\')">' + __t('uc.copy') + '</button></div>'
            ).join('');
        }

        const copyAllBtn = document.getElementById('copyAllCodes');
        if (copyAllBtn) {
            copyAllBtn.addEventListener('click', () => {
                navigator.clipboard.writeText(twofaRecoveryCodes.join('\n')).then(() => showToast(__t('uc.copied_all'), 'success'));
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
            disable2faBtn.addEventListener('click', () => showConfirm(__t('uc.twofa_disable'), __t('uc.twofa_disable_msg'), () => {
                const pw = prompt(__t('uc.enter_password_prompt'));
                if (!pw) return;
                const code = prompt(__t('uc.enter_code_prompt'));
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
            reset2faBtn.addEventListener('click', () => showConfirm(__t('uc.twofa_reset'), __t('uc.twofa_reset_msg'), () => {
                const pw = prompt(__t('uc.enter_password_prompt'));
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
            container.innerHTML = '<div class="uc-empty">' + (typeof window.__t === 'function' ? __t('uc.loading') : '加载中...') + '</div>';
            fetch(apiUrl + '?page=' + page)
                .then(r => r.json())
                .then(d => {
                    if (d.success) {
                        const keys = Object.keys(d.data);
                        const dataKey = keys.find(k => k !== 'pagination') || keys[0];
                        const items = d.data[dataKey];
                        if (!items || items.length === 0) {
                            container.innerHTML = '<div class="uc-empty">' + __t('uc.empty_data') + '</div>';
                            document.getElementById(paginationId).innerHTML = '';
                        } else {
                            container.innerHTML = renderFn(d.data);
                            renderPagination(paginationId, d.data.pagination, (p) => loadList(apiUrl, containerId, paginationId, renderFn, p));
                        }
                    } else {
                        container.innerHTML = '<div class="uc-empty">' + (d.message || __t('uc.load_fail')) + '</div>';
                    }
                })
                .catch(() => { container.innerHTML = '<div class="uc-empty">' + __t('uc.load_fail') + '</div>'; });
        }

        function renderPagination(id, pag, cb) {
            const el = document.getElementById(id);
            let html = '';
            html += '<button ' + (pag.page <= 1 ? 'disabled' : '') + ' onclick="void(0)">' + __t('uc.prev_page') + '</button>';
            for (let i = 1; i <= pag.total_pages; i++) {
                html += '<button class="' + (i === pag.page ? 'active' : '') + '">' + i + '</button>';
            }
            html += '<button ' + (pag.page >= pag.total_pages ? 'disabled' : '') + '>' + __t('uc.next_page') + '</button>';
            el.innerHTML = html;
            el.querySelectorAll('button').forEach((btn, idx) => {
                if (idx === 0) btn.addEventListener('click', () => cb(pag.page - 1));
                else if (idx === el.querySelectorAll('button').length - 1) btn.addEventListener('click', () => cb(pag.page + 1));
                else btn.addEventListener('click', () => cb(parseInt(btn.textContent)));
            });
        }

        function deletePost(postId) {
            showConfirm(__t('uc.confirm_delete'), __t('uc.confirm_delete_post'), () => {
                const formData = new FormData();
                formData.append('csrf_token', CSRF_TOKEN);
                formData.append('post_id', postId);
                fetch(SITE_URL + '/api/posts/delete.php', { method: 'POST', body: formData })
                    .then(r => r.json())
                    .then(d => {
                        if (d.success) { showToast(d.message, 'success'); setTimeout(() => location.reload(), 600); }
                        else showToast(d.message, 'error');
                    })
                    .catch(() => showToast(__t('uc.net_error'), 'error'));
            });
        }

        function deleteComment(commentId) {
            showConfirm(__t('uc.confirm_delete'), __t('uc.confirm_delete_comment'), () => {
                const formData = new FormData();
                formData.append('csrf_token', CSRF_TOKEN);
                formData.append('comment_id', commentId);
                fetch(SITE_URL + '/api/posts/delete_comment.php', { method: 'POST', body: formData })
                    .then(r => r.json())
                    .then(d => {
                        if (d.success) { showToast(d.message, 'success'); setTimeout(() => location.reload(), 600); }
                        else showToast(d.message, 'error');
                    })
                    .catch(() => showToast(__t('uc.net_error'), 'error'));
            });
        }

        function unfavoritePost(postId) {
            showConfirm(__t('uc.unfavorite'), __t('uc.confirm_unfavorite'), () => {
                const formData = new FormData();
                formData.append('csrf_token', CSRF_TOKEN);
                formData.append('post_id', postId);
                fetch(SITE_URL + '/api/posts/favorite.php', { method: 'POST', body: formData })
                    .then(r => r.json())
                    .then(d => {
                        if (d.success) { showToast(__t('uc.unfavorited'), 'success'); setTimeout(() => location.reload(), 600); }
                        else showToast(d.message, 'error');
                    })
                    .catch(() => showToast(__t('uc.net_error'), 'error'));
            });
        }

        if (document.getElementById('tab-posts').style.display !== 'none') {
            loadList(SITE_URL + '/api/user/my_posts.php', 'myPostsList', 'myPostsPagination', function(data) {
                return data.posts.map(p => `
                    <div class="uc-data-item">
                        <div class="uc-data-main">
                            <a href="${SITE_URL}/pages/post_detail.php?id=${p.id}" class="uc-data-title">${p.title || __t('uc.no_title')}</a>
                            <div class="uc-data-content">${p.content || ''}</div>
                            <div class="uc-data-meta">${p.timeAgo} · ${p.category_name} · ${p.view_count || 0}${__t('uc.views')} · ${p.like_count || 0}${__t('uc.likes')} · ${p.comment_count || 0}${__t('uc.comments')} · ${p.status === 'pending' ? __t('uc.status_pending') : p.status === 'rejected' ? __t('uc.status_rejected') : ''}</div>
                        </div>
                        <div class="uc-data-actions">
                            <button class="uc-btn uc-btn-danger uc-btn-sm" data-delete-post="${p.id}">${__t('uc.delete')}</button>
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
                            <a href="${SITE_URL}/pages/post_detail.php?id=${c.post_id}" class="uc-data-title">${__t('uc.commented_on')} ${c.post_title || __t('uc.no_title')}</a>
                            <div class="uc-data-content">${c.content || ''}</div>
                            <div class="uc-data-meta">${c.timeAgo || c.created_at}</div>
                        </div>
                        <div class="uc-data-actions">
                            <button class="uc-btn uc-btn-danger uc-btn-sm" data-delete-comment="${c.id}">${__t('uc.delete')}</button>
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
                            <a href="${SITE_URL}/pages/post_detail.php?id=${f.post_id}" class="uc-data-title">${f.title || __t('uc.no_title')}</a>
                            <div class="uc-data-content">${f.content || ''}</div>
                            <div class="uc-data-meta">${f.timeAgo} · ${f.author ? f.author.nickname : ''} · ${f.like_count || 0}${__t('uc.likes')} · ${f.comment_count || 0}${__t('uc.comments')}</div>
                        </div>
                        <div class="uc-data-actions">
                            <button class="uc-btn uc-btn-outline uc-btn-sm" data-unfavorite="${f.post_id}">${__t('uc.unfavorite')}</button>
                        </div>
                    </div>`).join('');
                setTimeout(() => {
                    document.querySelectorAll('[data-unfavorite]').forEach(btn => {
                        btn.addEventListener('click', function() { unfavoritePost(parseInt(this.dataset.unfavorite)); });
                    });
                }, 100);
            });
        }

        if (document.getElementById('tab-stats').style.display !== 'none') {
            const statsCardsEl = document.getElementById('statsCards');
            statsCardsEl.innerHTML = '<div class="uc-stat-card"><div class="uc-stat-hint">' + __t('uc.loading') + '</div></div>';

            const statIco = {
                posts: '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4z"/></svg>',
                likes: '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M20.8 4.6a5.5 5.5 0 0 0-7.8 0L12 5.6l-1-1a5.5 5.5 0 0 0-7.8 7.8l1 1L12 20.8l7.8-7.9 1-1a5.5 5.5 0 0 0 0-7.3z"/></svg>',
                comments: '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>',
                mentions: '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/></svg>',
                views: '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M1.5 12S5 5.5 12 5.5 22.5 12 22.5 12 19 18.5 12 18.5 1.5 12 1.5 12z"/><circle cx="12" cy="12" r="3"/></svg>',
                favorites: '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M19 21l-7-5-7 5V5a2 2 0 0 1 2-2h10a2 2 0 0 1 2 2z"/></svg>'
            };
            const num = n => Number(n || 0).toLocaleString();
            // 标题/时间来自用户内容，拼进 innerHTML 前必须转义
            const esc = s => String(s == null ? '' : s).replace(/[&<>"']/g, ch => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[ch]));

            function renderStatCards(c) {
                const cards = [
                    { key: 'posts', label: __t('uc.tab.posts'), value: c.post_published,
                      hint: __t('uc.stats.hint_pending') + ' <b>' + num(c.post_pending) + '</b> · ' + __t('uc.stats.hint_rejected') + ' <b>' + num(c.post_rejected) + '</b>' },
                    { key: 'likes', label: __t('uc.stats.card_likes'), value: (c.likes || 0) + (c.comment_likes || 0),
                      hint: __t('uc.stats.hint_post_likes') + ' <b>' + num(c.likes) + '</b> · ' + __t('uc.stats.hint_comment_likes') + ' <b>' + num(c.comment_likes) + '</b>' },
                    { key: 'comments', label: __t('uc.tab.comments'), value: c.comments },
                    { key: 'mentions', label: __t('uc.stats.card_mentions'), value: c.mentions,
                      hint: __t('uc.stats.hint_unread') + ' <b>' + num(c.mentions_unread) + '</b>' },
                    { key: 'views', label: __t('uc.stats.card_views'), value: c.views },
                    { key: 'favorites', label: __t('uc.stats.card_favorites'), value: c.favorites,
                      hint: __t('uc.stats.hint_received_comments') + ' <b>' + num(c.received_comments) + '</b>' }
                ];
                statsCardsEl.innerHTML = cards.map(function (item) {
                    return '<div class="uc-stat-card">'
                        + '<div class="uc-stat-top">' + statIco[item.key] + '<span>' + item.label + '</span></div>'
                        + '<div class="uc-stat-value">' + num(item.value) + '</div>'
                        + (item.hint ? '<div class="uc-stat-hint">' + item.hint + '</div>' : '')
                        + '</div>';
                }).join('');
            }

            function renderStatsTrend(trend, week) {
                const wrap = document.getElementById('statsTrend');
                const max = Math.max(1, trend.reduce(function (m, t) { return Math.max(m, t.mine, t.received); }, 0));
                const bar = (v, cls, day, name) =>
                    '<span class="uc-trend-bar ' + cls + '" style="height:' + Math.max(3, Math.round(v / max * 100)) + '%" title="'
                    + day + ' · ' + name + ' ' + v + '"></span>';
                wrap.innerHTML = trend.map(function (t) {
                    return '<div class="uc-trend-col">'
                        + '<div class="uc-trend-bars">'
                        + bar(t.mine, '', t.label, __t('uc.stats.legend_mine'))
                        + bar(t.received, 'received', t.label, __t('uc.stats.legend_received'))
                        + '</div><div class="uc-trend-label">' + t.label + '</div></div>';
                }).join('');
                document.getElementById('statsWeekHint').textContent =
                    __t('uc.stats.legend_mine') + ' ' + week.mine + ' · ' + __t('uc.stats.legend_received') + ' ' + week.received;
            }

            function renderStatsTopPosts(list) {
                const wrap = document.getElementById('statsTopPosts');
                if (!list.length) {
                    wrap.innerHTML = '<div class="uc-stat-empty">' + __t('uc.stats.no_top') + '</div>';
                    return;
                }
                wrap.innerHTML = list.map(function (p, i) {
                    return '<div class="uc-top-item">'
                        + '<span class="uc-top-rank">' + (i + 1) + '</span>'
                        + '<div class="uc-top-main">'
                        + '<a class="uc-top-title" href="' + SITE_URL + '/pages/post_detail.php?id=' + parseInt(p.id, 10) + '">' + (p.title ? esc(p.title) : __t('uc.no_title')) + '</a>'
                        + '<div class="uc-top-meta"><span>' + num(p.like_count) + esc(__t('uc.likes')) + '</span>'
                        + '<span>' + num(p.view_count) + esc(__t('uc.views')) + '</span>'
                        + '<span>' + num(p.comment_count) + esc(__t('uc.comments')) + '</span>'
                        + '<span>' + esc(p.timeAgo || '') + '</span></div>'
                        + '</div></div>';
                }).join('');
            }

            fetch(SITE_URL + '/api/user/stats.php')
                .then(r => r.json())
                .then(d => {
                    if (!d.success || !d.data) {
                        statsCardsEl.innerHTML = '<div class="uc-stat-empty">' + __t('uc.stats.failed') + '</div>';
                        return;
                    }
                    renderStatCards(d.data.cards || {});
                    renderStatsTrend(d.data.trend || [], d.data.week || { mine: 0, received: 0 });
                    renderStatsTopPosts(d.data.top_posts || []);
                })
                .catch(() => {
                    statsCardsEl.innerHTML = '<div class="uc-stat-empty">' + __t('uc.stats.failed') + '</div>';
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

        // 主题三态：跟随系统 / 浅色 / 深色。真正的上色由 window.LWTheme（theme_boot.php）负责。
        function selectTheme(opt) {
            if (!window.LWTheme) return;
            const theme = opt.dataset.theme;
            document.querySelectorAll('.uc-theme-option').forEach(o => {
                const on = o === opt;
                o.classList.toggle('active', on);
                o.setAttribute('aria-pressed', on ? 'true' : 'false');
            });
            window.LWTheme.withTransition();
            window.LWTheme.set(theme);

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
                        showToast(d.message || __t('uc.theme_fail'), 'error');
                    }
                })
                .catch(() => {
                    showToast(__t('uc.theme_net_fail'), 'error');
                });
        }
        document.querySelectorAll('.uc-theme-option').forEach(opt => {
            opt.addEventListener('click', function () { selectTheme(this); });
            opt.addEventListener('keydown', function (e) {
                if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); selectTheme(this); }
            });
        });
        // 载入时按实际偏好校正高亮（localStorage 的选择可能与服务端记录不同）
        if (window.LWTheme) {
            const curPref = window.LWTheme.pref();
            document.querySelectorAll('.uc-theme-option').forEach(o => {
                const on = o.dataset.theme === curPref;
                o.classList.toggle('active', on);
                o.setAttribute('aria-pressed', on ? 'true' : 'false');
            });
        }

        // 字体大小：12–48px 无极调节，localStorage.font_size 存像素值（旧档位 small/medium/large 自动换算）
        const FONT_MIN = 12, FONT_MAX = 48, FONT_DEFAULT = 16;
        const FONT_LEGACY = { small: 14, medium: 16, large: 18 };
        function clampFontSize(value) {
            const px = parseInt(value, 10);
            if (isNaN(px)) return FONT_DEFAULT;
            return Math.min(FONT_MAX, Math.max(FONT_MIN, px));
        }
        function applyFontSize(px) {
            px = clampFontSize(px);
            document.documentElement.style.setProperty('--font-size-base', px + 'px');
            document.documentElement.style.fontSize = px + 'px';
            localStorage.setItem('font_size', String(px));
        }
        const fontRange = document.getElementById('ucFontRange');
        const fontValue = document.getElementById('ucFontValue');
        let curFont = FONT_LEGACY[localStorage.getItem('font_size')] || clampFontSize(localStorage.getItem('font_size'));
        fontRange.value = curFont;
        fontValue.textContent = curFont + 'px';
        applyFontSize(curFont);
        fontRange.addEventListener('input', function() {
            const px = clampFontSize(this.value);
            fontValue.textContent = px + 'px';
            applyFontSize(px);
        });
        fontRange.addEventListener('change', function() {
            showToast(__t('uc.font_size_toast') + clampFontSize(this.value) + 'px', 'success');
        });

        /* ---------------- 邀请好友：复制 / 海报 ----------------
           全部为增强交互，任何环节失败都只 toast，不影响页面其它功能。 */
        (function initInvite() {
            const codeEl    = document.getElementById('inviteCodeText');
            const btnCode   = document.getElementById('btnCopyInviteCode');
            const btnLink   = document.getElementById('btnCopyInviteLink');
            const btnPoster = document.getElementById('btnInvitePoster');
            const posterCard = document.getElementById('invitePosterCard');
            const posterImg  = document.getElementById('invitePosterImg');
            if (!codeEl || !btnCode || !btnLink || !btnPoster) return;

            const INVITE_CODE = (codeEl.textContent || '').trim();
            const INVITE_LINK = <?= json_encode($inviteData['link'] !== '' ? $inviteData['link'] : (SITE_URL . '/pages/register.php'), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
            const LABELS = {
                site:  <?= json_encode(SITE_NAME, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>,
                title: <?= json_encode(t('invite.title'), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>,
                code:  <?= json_encode(t('invite.my_code'), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>,
                hint:  <?= json_encode(t('invite.share_hint'), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>
            };

            // 剪贴板：优先异步 API，非安全上下文回退 execCommand
            function copyText(text) {
                if (navigator.clipboard && window.isSecureContext) {
                    return navigator.clipboard.writeText(text);
                }
                return new Promise(function(resolve, reject) {
                    try {
                        const ta = document.createElement('textarea');
                        ta.value = text;
                        ta.style.cssText = 'position:fixed;left:-9999px;top:0;opacity:0;';
                        document.body.appendChild(ta);
                        ta.focus(); ta.select();
                        const ok = document.execCommand('copy');
                        document.body.removeChild(ta);
                        ok ? resolve() : reject(new Error('copy'));
                    } catch (e) { reject(e); }
                });
            }
            function copyWithToast(text) {
                copyText(text).then(function() {
                    showToast(__t('invite.copied'), 'success');
                }).catch(function() {
                    showToast(__t('invite.copy_failed'), 'error');
                });
            }

            btnCode.addEventListener('click', function() {
                if (!INVITE_CODE) return;
                copyWithToast(INVITE_CODE);
            });
            btnLink.addEventListener('click', function() {
                copyWithToast(INVITE_LINK);
            });

            // 社交分享：微信走「复制链接」引导，QQ/微博走官方分享页
            const SHARE_TITLE = LABELS.site + ' · ' + LABELS.title;
            document.querySelectorAll('.uc-share-btn[data-share]').forEach(function(btn) {
                btn.addEventListener('click', function() {
                    const type = btn.getAttribute('data-share');
                    const enc = encodeURIComponent;
                    if (type === 'wechat') {
                        copyText(INVITE_LINK).then(function() {
                            showToast(__t('invite.share_wechat_toast'), 'success');
                        }).catch(function() {
                            showToast(__t('invite.copy_failed'), 'error');
                        });
                    } else if (type === 'qq') {
                        window.open('https://connect.qq.com/widget/shareqq/index.html?url=' + enc(INVITE_LINK) + '&title=' + enc(SHARE_TITLE) + '&summary=' + enc(LABELS.hint), '_blank', 'noopener,width=680,height=560');
                    } else if (type === 'weibo') {
                        window.open('https://service.weibo.com/share/share.php?url=' + enc(INVITE_LINK) + '&title=' + enc(SHARE_TITLE + ' ' + LABELS.hint), '_blank', 'noopener,width=680,height=560');
                    }
                });
            });

            // 懒加载本地 html2canvas（CSP script-src 'self' 下只能自托管）
            function loadHtml2Canvas() {
                return new Promise(function(resolve, reject) {
                    if (window.html2canvas) return resolve(window.html2canvas);
                    const s = document.createElement('script');
                    s.src = SITE_URL + '/assets/js/vendor/html2canvas.min.js';
                    s.onload = function() { window.html2canvas ? resolve(window.html2canvas) : reject(new Error('missing')); };
                    s.onerror = function() { reject(new Error('load')); };
                    document.head.appendChild(s);
                });
            }

            // 海报节点：全部内联样式 + hex 颜色，兼容 html2canvas 渲染
            function buildPosterNode(code, link) {
                const wrap = document.createElement('div');
                wrap.style.cssText = 'position:fixed;left:-99999px;top:0;width:360px;padding:32px 28px;' +
                    'background:#ffffff;border-radius:20px;box-sizing:border-box;' +
                    'font-family:-apple-system,"PingFang SC","Microsoft YaHei",sans-serif;color:#2b2b2b;text-align:center;';
                const esc = function(s) {
                    return String(s).replace(/[&<>"]/g, function(c) {
                        return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c];
                    });
                };
                wrap.innerHTML =
                    '<div style="font-size:22px;font-weight:800;color:#E2574C;">' + esc(LABELS.site) + '</div>' +
                    '<div style="font-size:13px;color:#999999;margin-top:6px;">' + esc(LABELS.title) + '</div>' +
                    '<div style="margin:24px 0 10px;font-size:12px;color:#999999;">' + esc(LABELS.code) + '</div>' +
                    '<div style="font-size:36px;font-weight:800;letter-spacing:8px;color:#2b2b2b;font-family:monospace;">' + esc(code) + '</div>' +
                    '<div style="margin-top:22px;padding-top:18px;border-top:1px dashed #e5e5e5;font-size:12px;color:#aaaaaa;word-break:break-all;line-height:1.6;">' + esc(link) + '</div>' +
                    '<div style="margin-top:16px;font-size:13px;color:#E2574C;font-weight:600;">' + esc(LABELS.hint) + '</div>';
                return wrap;
            }

            btnPoster.addEventListener('click', function() {
                if (!INVITE_CODE) { showToast(__t('invite.list_empty'), 'error'); return; }
                const oldText = btnPoster.textContent;
                btnPoster.disabled = true;
                btnPoster.textContent = '···';
                loadHtml2Canvas().then(function(h2c) {
                    const node = buildPosterNode(INVITE_CODE, INVITE_LINK);
                    document.body.appendChild(node);
                    return h2c(node, { backgroundColor: '#ffffff', scale: 2, logging: false }).then(function(canvas) {
                        document.body.removeChild(node);
                        posterImg.src = canvas.toDataURL('image/png');
                        posterImg.classList.add('show');
                        if (posterCard) posterCard.style.display = '';
                        if (posterCard && posterCard.scrollIntoView) {
                            posterCard.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
                        }
                    }).catch(function() {
                        if (node.parentNode) document.body.removeChild(node);
                        showToast(__t('invite.copy_failed'), 'error');
                    });
                }).catch(function() {
                    showToast(__t('invite.copy_failed'), 'error');
                }).then(function() {
                    btnPoster.disabled = false;
                    btnPoster.textContent = oldText;
                });
            });
        })();
    })();
    </script>
    <?php require_once __DIR__ . '/../includes/lang_ui.php'; ?>
    <script src="<?= asset_url('/assets/js/enhancements.js') ?>?v=<?= asset_ver('/assets/js/enhancements.js') ?>" defer></script>
</body>
</html>
