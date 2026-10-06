<?php
require_once __DIR__ . '/../config/config.php';

$fs = getFS();

$maintenanceMode = getSetting('maintenance_mode', '0') == '1';
$maintenanceMsg = getSetting('maintenance_message', '网站正在维护中，请稍后再来。');
if ($maintenanceMode) {
    $user = getCurrentUser();
    $isAdmin = $user && in_array($user['role'], ['admin', 'super_admin']);
    if (!$isAdmin) {
        require_once __DIR__ . '/maintenance.php';
        exit();
    }
}

// 已登录用户正常访问；游客模式可只读使用工具页；其余跳登录页
$user = requireLoginOrGuest();
if ($user) {
    $user = checkBanned($user);
}
$isGuest = ($user === null);
$isSponsor = false;
if ($user && isset($user['qq'])) {
    $sponsors = $fs->read('sponsors');
    $sponsorQQs = array_column($sponsors, 'qq');
    $isSponsor = in_array($user['qq'], $sponsorQQs);
}
$bannedMsg = '';
if ($user && $user['is_banned']) {
    $bannedMsg = '账号已被封禁：' . ($user['ban_reason'] ?? '');
    if ($user['ban_until']) {
        $bannedMsg .= '（解封时间：' . $user['ban_until'] . '）';
    }
}

$announcement = getSetting('announcement', '');
$siteName = getSetting('site_name', '淮南北师大实验中学高中部校园交流墙');
?>
<!DOCTYPE html>
<html lang="<?= htmlspecialchars($LANG_CODE) ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <?php require_once __DIR__ . '/../includes/pwa_head.php'; ?>
    <link rel="icon" href="/icon.ico" type="image/x-icon">
    <title><?= t('tools.page_title') ?> - <?= htmlspecialchars($siteName) ?></title>
    <?php require __DIR__ . '/../includes/seo_meta.php'; ?>
    <meta name="description" content="<?= htmlspecialchars(t('site.meta_desc')) ?> - <?= htmlspecialchars(t('tools.page_title')) ?>">
    <link rel="stylesheet" href="<?= asset_url('/assets/css/style.css') ?>?v=<?= asset_ver('/assets/css/style.css') ?>">
    <link rel="stylesheet" href="<?= asset_url('/assets/css/enhancements.css') ?>?v=<?= asset_ver('/assets/css/enhancements.css') ?>">
    <script>
        const SITE_URL = '<?= SITE_URL ?>';
        const IS_LOGGED_IN = <?= $user ? 'true' : 'false' ?>;
        const USER_DATA = <?= $user ? json_encode(['id' => $user['id'], 'qq' => $user['qq'], 'uuid' => $user['uuid'] ?? '', 'nickname' => $user['nickname'], 'avatar' => $user['avatar'], 'role' => $user['role']], JSON_HEX_TAG | JSON_UNESCAPED_UNICODE) : 'null' ?>;
        const CSRF_TOKEN = '<?= generateCSRFToken() ?>';
        const IS_SPONSOR = <?= $isSponsor ? 'true' : 'false' ?>;
    </script>
    <style>
        /* Tools Page Specific Styles */
        .tools-main {
            padding: var(--space-lg) 0 var(--space-xl);
        }
        .tools-container {
            max-width: var(--content-max-width);
            margin: 0 auto;
            padding: 0 var(--space-md);
        }
        .tools-header {
            text-align: center;
            padding: var(--space-xl) var(--space-md) var(--space-lg);
        }
        .tools-header h1 {
            font-size: 2.2rem;
            font-weight: 700;
            color: var(--text);
            margin-bottom: var(--space-sm);
            letter-spacing: 1px;
        }
        .tools-header .subtitle {
            font-size: 1rem;
            color: var(--text-secondary);
            font-weight: 400;
        }
        .tools-category {
            margin: var(--space-xl) 0 var(--space-md);
        }
        .tools-category-title {
            font-size: 1.25rem;
            font-weight: 700;
            color: var(--primary);
            padding-left: 0.875rem;
            border-left: 4px solid #C9A96E;
            margin-bottom: var(--space-md);
            letter-spacing: 0.5px;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        .tools-category-title::before {
            content: '';
            display: inline-block;
            width: 0.5rem;
            height: 0.5rem;
            border-radius: 50%;
            background: #C9A96E;
            flex-shrink: 0;
        }
        .tool-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 1rem;
        }
        @media (max-width: 1100px) {
            .tool-grid { grid-template-columns: repeat(3, 1fr); }
        }
        @media (max-width: 768px) {
            .tool-grid { grid-template-columns: repeat(2, 1fr); gap: 0.75rem; }
            .tools-header h1 { font-size: 1.8rem; }
            .tools-header { padding: var(--space-lg) var(--space-md) var(--space-md); }
        }
        @media (max-width: 480px) {
            .tool-grid { grid-template-columns: 1fr 1fr; gap: 0.625rem; }
        }
        .tool-card {
            background: var(--card-bg);
            border: 1px solid var(--border-glass);
            border-radius: var(--radius-card);
            padding: 1.5rem 1rem 1.125rem;
            cursor: pointer;
            transition: all var(--transition);
            display: flex;
            flex-direction: column;
            align-items: center;
            text-align: center;
            box-shadow: var(--shadow);
            position: relative;
            overflow: hidden;
            border-top: 3px solid transparent;
            text-decoration: none;
            color: var(--text);
        }
        .tool-card:hover {
            transform: translateY(-4px);
            box-shadow: var(--shadow-md);
            border-color: var(--primary);
            border-top-color: #C9A96E;
            background: linear-gradient(180deg, var(--primary-light), var(--card-bg));
        }
        .tool-card .icon-wrap {
            width: 3.5rem;
            height: 3.5rem;
            margin-bottom: 0.75rem;
            display: flex;
            align-items: center;
            justify-content: center;
            position: relative;
            z-index: 1;
        }
        .tool-card .icon-wrap svg {
            width: 3rem;
            height: 3rem;
        }
        .tool-card .tool-name {
            font-size: 0.95rem;
            font-weight: 600;
            color: var(--text);
            position: relative;
            z-index: 1;
            letter-spacing: 0.3px;
        }
        .tool-card .tool-desc {
            font-size: 0.75rem;
            color: var(--text-muted);
            margin-top: 0.25rem;
            position: relative;
            z-index: 1;
        }

        /* Modal */
        .tool-modal-overlay {
            position: fixed;
            top: 0; left: 0; right: 0; bottom: 0;
            background: rgba(0, 0, 0, 0.45);
            z-index: var(--z-modal);
            display: flex;
            align-items: center;
            justify-content: center;
            animation: modalFadeIn 0.2s ease;
        }
        .tool-modal-overlay.closing { animation: modalFadeOut 0.18s ease forwards; }
        @keyframes modalFadeIn { from { opacity: 0; } to { opacity: 1; } }
        @keyframes modalFadeOut { from { opacity: 1; } to { opacity: 0; } }
        @keyframes modalSlideUp {
            from { transform: translateY(30px); opacity: 0; }
            to { transform: translateY(0); opacity: 1; }
        }
        @keyframes modalSlideDown {
            from { transform: translateY(0); opacity: 1; }
            to { transform: translateY(30px); opacity: 0; }
        }
        .tool-modal {
            background: var(--card-bg);
            border-radius: var(--radius-lg);
            border: 1px solid var(--border);
            width: 92%;
            max-width: 560px;
            max-height: 85vh;
            overflow-y: auto;
            box-shadow: var(--shadow-lg);
            animation: modalSlideUp 0.25s cubic-bezier(0.4, 0, 0.2, 1);
            position: relative;
        }
        .tool-modal.closing { animation: modalSlideDown 0.18s ease forwards; }
        .tool-modal-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 1.25rem 1.5rem 0.875rem;
            border-bottom: 1px solid var(--border);
            position: sticky;
            top: 0;
            background: var(--card-bg);
            border-radius: var(--radius-lg) var(--radius-lg) 0 0;
            z-index: 10;
        }
        .tool-modal-header h2 {
            font-size: 1.15rem;
            font-weight: 600;
            color: var(--text);
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        .tool-modal-header h2 svg { width: 1.5rem; height: 1.5rem; }
        .tool-modal-close {
            width: 2.125rem; height: 2.125rem;
            border-radius: var(--radius-full);
            border: 1px solid var(--border);
            background: var(--bg-secondary);
            color: var(--text-secondary);
            cursor: pointer;
            font-size: 1.1rem;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: all var(--transition-fast);
            line-height: 1;
        }
        .tool-modal-close:hover {
            background: var(--danger-light);
            border-color: var(--danger);
            color: var(--danger);
        }
        .tool-modal-body {
            padding: 1.25rem 1.5rem 1.5rem;
        }
        .tool-modal::-webkit-scrollbar { width: 6px; }
        .tool-modal::-webkit-scrollbar-track { background: transparent; }
        .tool-modal::-webkit-scrollbar-thumb { background: var(--border); border-radius: 3px; }

        /* Buttons */
        .t-btn {
            padding: 0.5625rem 1.25rem;
            border-radius: var(--radius-sm);
            border: none;
            cursor: pointer;
            font-family: inherit;
            font-size: 0.9rem;
            font-weight: 600;
            transition: all var(--transition-fast);
            display: inline-flex;
            align-items: center;
            gap: 0.3125rem;
            justify-content: center;
        }
        .t-btn-primary {
            background: var(--primary);
            color: var(--text-inverse);
            box-shadow: 0 2px 8px rgba(74, 144, 217, 0.3);
        }
        .t-btn-primary:hover { background: var(--primary-hover); transform: translateY(-1px); box-shadow: 0 4px 12px rgba(74, 144, 217, 0.4); }
        .t-btn-secondary {
            background: var(--bg-secondary);
            color: var(--text);
            border: 1px solid var(--border);
        }
        .t-btn-secondary:hover { background: var(--border); }
        .t-btn-danger {
            background: var(--danger);
            color: var(--text-inverse);
        }
        .t-btn-danger:hover { background: var(--accent-pink-dark); }
        .t-btn-sm { padding: 0.3125rem 0.75rem; font-size: 0.8rem; border-radius: 0.375rem; }
        .t-btn-row { display: flex; gap: 0.5rem; flex-wrap: wrap; margin-top: 0.875rem; }

        /* Form elements */
        .t-input, .t-textarea, .t-select {
            width: 100%;
            padding: 0.625rem 0.875rem;
            border-radius: var(--radius-sm);
            border: 1px solid var(--border);
            background: var(--bg);
            color: var(--text);
            font-family: inherit;
            font-size: 0.9rem;
            outline: none;
            transition: border-color var(--transition-fast), box-shadow var(--transition-fast);
        }
        .t-input:focus, .t-textarea:focus, .t-select:focus {
            border-color: var(--primary);
            box-shadow: var(--shadow-focus);
        }
        .t-textarea { resize: vertical; min-height: 5.625rem; }
        .t-select option { background: var(--card-bg); color: var(--text); }
        .t-label {
            display: block;
            font-size: 0.85rem;
            color: var(--text-secondary);
            margin-bottom: 0.3125rem;
            font-weight: 500;
        }
        .t-form-group { margin-bottom: 0.875rem; }

        /* Timer display */
        .timer-display {
            font-size: 3.5rem;
            font-weight: 700;
            text-align: center;
            font-variant-numeric: tabular-nums;
            font-family: 'Courier New', monospace;
            color: var(--primary);
            margin: 1rem 0;
            letter-spacing: 3px;
        }
        .timer-label {
            text-align: center;
            font-size: 0.9rem;
            color: var(--text-secondary);
            margin-bottom: 0.875rem;
        }

        /* Presets */
        .presets { display: flex; gap: 0.375rem; flex-wrap: wrap; }
        .preset {
            padding: 0.3125rem 0.75rem;
            border-radius: 1.25rem;
            background: var(--bg-secondary);
            border: 1px solid var(--border);
            color: var(--text-secondary);
            cursor: pointer;
            font-size: 0.8rem;
            font-family: inherit;
            transition: all var(--transition-fast);
        }
        .preset:hover, .preset.active {
            background: var(--primary-light);
            border-color: var(--primary);
            color: var(--primary);
        }

        /* Todo */
        .todo-input-row { display: flex; gap: 0.5rem; }
        .todo-input-row .t-input { flex: 1; }
        .todo-list { list-style: none; margin-top: 0.625rem; }
        .todo-item {
            display: flex; align-items: center; gap: 0.5rem;
            padding: 0.5rem 0.625rem; border-radius: var(--radius-sm);
            background: var(--bg);
            margin-bottom: 0.375rem;
            transition: all var(--transition-fast);
        }
        .todo-item.done .todo-text { text-decoration: line-through; opacity: 0.5; }
        .todo-check { width: 1.125rem; height: 1.125rem; cursor: pointer; accent-color: var(--primary); }
        .todo-text { flex: 1; font-size: 0.9rem; }
        .todo-del {
            background: none; border: none; color: var(--text-muted);
            cursor: pointer; font-size: 1.1rem; padding: 2px 0.375rem;
            border-radius: 0.25rem;
        }
        .todo-del:hover { color: var(--danger); background: var(--danger-light); }

        /* Calculator */
        .calc-display {
            background: var(--bg);
            border: 1px solid var(--border);
            border-radius: var(--radius-sm);
            padding: 1rem;
            text-align: right;
            font-size: 1.8rem;
            font-family: 'Courier New', monospace;
            color: var(--text);
            margin-bottom: 0.875rem;
            min-height: 4.375rem;
            word-break: break-all;
        }
        .calc-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 0.375rem;
        }
        .calc-btn {
            padding: 0.875rem 0.375rem;
            border-radius: var(--radius-sm);
            border: 1px solid var(--border);
            font-size: 1.1rem;
            cursor: pointer;
            font-family: inherit;
            background: var(--card-bg);
            color: var(--text);
            transition: all var(--transition-fast);
        }
        .calc-btn:hover { background: var(--bg-secondary); }
        .calc-btn.operator { background: var(--primary-light); color: var(--primary); border-color: var(--primary); }
        .calc-btn.operator:hover { background: var(--primary); color: var(--text-inverse); }
        .calc-btn.equals { background: var(--primary); color: var(--text-inverse); border-color: var(--primary); }
        .calc-btn.equals:hover { background: var(--primary-hover); }
        .calc-btn.clear { background: var(--danger-light); color: var(--danger); border-color: var(--danger); }
        .calc-btn.clear:hover { background: var(--danger); color: var(--text-inverse); }
        .calc-btn.span2 { grid-column: span 2; }

        /* Color picker */
        .color-preview {
            width: 100%; height: 6.25rem; border-radius: var(--radius-sm);
            margin-bottom: 0.875rem; transition: background 0.3s;
            border: 1px solid var(--border);
        }
        .color-values {
            display: flex; gap: 0.625rem; flex-wrap: wrap;
        }
        .color-val {
            flex: 1; min-width: 5.625rem;
            background: var(--bg); padding: 0.5rem 0.75rem;
            border-radius: var(--radius-sm); text-align: center;
            font-family: 'Courier New', monospace; font-size: 0.8rem;
            color: var(--text);
            border: 1px solid var(--border);
        }
        .color-val strong { display: block; font-size: 0.9rem; margin-top: 2px; }

        /* Password */
        .password-display {
            background: var(--bg);
            border: 1px solid var(--border);
            padding: 0.875rem 1rem;
            border-radius: var(--radius-sm);
            font-family: 'Courier New', monospace;
            font-size: 1.2rem;
            text-align: center;
            word-break: break-all;
            margin-bottom: 0.875rem;
            display: flex; align-items: center; justify-content: space-between;
            color: var(--text);
        }
        .password-display .copy-btn {
            background: var(--primary-light);
            border: 1px solid var(--primary);
            color: var(--primary);
            padding: 0.25rem 0.625rem;
            border-radius: 0.3125rem;
            font-size: 0.8rem;
            cursor: pointer;
            font-family: inherit;
            transition: all var(--transition-fast);
        }
        .password-display .copy-btn:hover { background: var(--primary); color: var(--text-inverse); }
        input[type="range"] { width: 100%; accent-color: var(--primary); }

        /* Coin */
        .coin-container {
            perspective: 1000px;
            width: 6.875rem; height: 6.875rem;
            margin: 1rem auto;
        }
        .coin {
            width: 100%; height: 100%;
            position: relative;
            transform-style: preserve-3d;
            transition: transform 0.1s;
        }
        .coin.flipping { animation: coinFlip 2s ease-out; }
        @keyframes coinFlip {
            0% { transform: rotateY(0); }
            100% { transform: rotateY(1800deg); }
        }
        .coin-face {
            position: absolute; width: 100%; height: 100%;
            border-radius: 50%; backface-visibility: hidden;
            display: flex; align-items: center; justify-content: center;
            font-size: 2.2rem; font-weight: 700;
        }
        .coin-front { background: linear-gradient(135deg, #f9ca24, #e1b12c); color: #fff; }
        .coin-back { background: linear-gradient(135deg, #636e72, #2d3436); color: #fff; transform: rotateY(180deg); }
        .coin-result { text-align: center; font-size: 1.1rem; margin-top: 0.5rem; color: var(--text); font-weight: 500; }

        /* Dice */
        .dice-container { text-align: center; margin: 1rem 0; }
        .dice-face {
            width: 6.875rem; height: 6.875rem;
            margin: 0 auto;
            background: linear-gradient(135deg, #ff6b6b, #c0392b);
            border-radius: var(--radius);
            display: flex; align-items: center; justify-content: center;
            font-size: 3rem;
            box-shadow: var(--shadow);
            transition: transform 0.1s;
            color: #fff;
        }
        .dice-face.rolling { animation: diceRoll 0.6s ease-out; }
        @keyframes diceRoll {
            0% { transform: rotate(0deg) scale(1); }
            25% { transform: rotate(90deg) scale(1.2); }
            50% { transform: rotate(180deg) scale(0.9); }
            75% { transform: rotate(270deg) scale(1.1); }
            100% { transform: rotate(360deg) scale(1); }
        }
        .dice-result { font-size: 1.3rem; margin-top: 0.5rem; color: var(--text); font-weight: 500; }

        /* Lap list */
        .lap-list {
            list-style: none; max-height: 160px; overflow-y: auto;
            margin-top: 0.625rem;
        }
        .lap-list li {
            padding: 0.375rem 0.625rem; border-radius: 0.375rem;
            background: var(--bg);
            margin-bottom: 0.1875rem; font-family: 'Courier New', monospace;
            font-size: 0.85rem;
            display: flex; justify-content: space-between;
            color: var(--text);
        }

        /* QR */
        .qr-container { text-align: center; margin: 0.875rem 0; }
        .qr-container img { border-radius: var(--radius-sm); background: #fff; padding: 0.5rem; border: 1px solid var(--border); }

        /* Wheel */
        .wheel-wrap { text-align: center; margin: 0.875rem 0; position: relative; display: inline-block; }
        .wheel-wrap canvas { max-width: 100%; border-radius: 50%; }
        .wheel-pointer { position: absolute; top: -0.625rem; left: 50%; transform: translateX(-50%); width: 0; height: 0; border-left: 14px solid transparent; border-right: 14px solid transparent; border-top: 24px solid var(--danger); filter: drop-shadow(0 2px 4px rgba(0,0,0,0.2)); }

        /* Scratch */
        .scratch-wrap { text-align: center; margin: 0.875rem 0; }
        .scratch-wrap canvas { border-radius: var(--radius-sm); cursor: pointer; border: 1px solid var(--border); }

        /* Fortune */
        .fortune-result {
            text-align: center; padding: 1.5rem;
            background: var(--bg); border-radius: var(--radius-card);
            margin: 0.875rem 0; border: 1px solid var(--border);
        }
        .fortune-result .fortune-icon { font-size: 2.5rem; }
        .fortune-result .fortune-text { font-size: 1.4rem; margin: 0.5rem 0; font-weight: 600; color: var(--text); }
        .fortune-result .fortune-detail { color: var(--text-secondary); font-size: 0.9rem; }

        /* Badges */
        .badge-grid {
            display: grid; grid-template-columns: repeat(3, 1fr); gap: 0.625rem;
        }
        .badge-item {
            text-align: center; padding: 0.875rem 0.5rem;
            background: var(--bg); border-radius: var(--radius-sm);
            border: 1px solid var(--border);
        }
        .badge-item svg { width: 2.5rem; height: 2.5rem; margin-bottom: 0.375rem; }
        .badge-item .badge-name { font-size: 0.8rem; font-weight: 600; color: var(--text); }
        .badge-item .badge-desc { font-size: 0.7rem; color: var(--text-muted); }

        /* Vote */
        .vote-option {
            display: flex; align-items: center; gap: 0.5rem;
            margin-bottom: 0.5rem;
        }
        .vote-option .t-input { flex: 1; }
        .vote-bar-wrap {
            background: var(--bg-secondary); border-radius: var(--radius-sm);
            height: 1.625rem; margin-bottom: 0.375rem; overflow: hidden;
            position: relative;
        }
        .vote-bar {
            height: 100%; background: linear-gradient(90deg, var(--primary), var(--primary-dark));
            border-radius: var(--radius-sm); transition: width 0.5s ease;
            display: flex; align-items: center; padding-left: 0.625rem;
            font-size: 0.8rem; font-weight: 600; color: var(--text-inverse);
        }
        .vote-count { font-size: 0.75rem; color: var(--text-muted); margin-top: 1px; }

        /* Eye protection */
        .eye-protection-overlay {
            position: fixed; top: 0; left: 0; right: 0; bottom: 0;
            background: rgba(255, 200, 100, 0.15);
            pointer-events: none; z-index: 9998;
            display: none;
        }

        /* Search results */
        .search-results {
            list-style: none; margin-top: 0.625rem;
        }
        .search-results li {
            padding: 0.5rem 0.75rem; border-radius: var(--radius-sm);
            background: var(--bg); margin-bottom: 0.25rem;
            cursor: pointer; transition: all var(--transition-fast);
            color: var(--text); font-size: 0.9rem;
        }
        .search-results li:hover { background: var(--primary-light); color: var(--primary); }

        /* Reading mode */
        .reading-mode-body {
            background: #f4ecd8 !important;
            color: #3e2723 !important;
        }
        .reading-mode-body .site-header { background: #e8dcc8 !important; }
        .reading-mode-body .tool-card { background: #faf3e6 !important; border-color: #d4c5a9 !important; }
        .reading-mode-body .tool-card:hover { background: #f0e6d2 !important; }

        /* Sticky notes */
        .sticky-notes { display: flex; flex-wrap: wrap; gap: 0.5rem; margin-top: 0.625rem; }
        .sticky-note {
            width: 9.375rem; min-height: 6.875rem; padding: 0.75rem;
            border-radius: 0.1875rem; font-size: 0.8rem;
            position: relative; color: #333;
            transform: rotate(-1deg);
            transition: transform 0.2s;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
        }
        .sticky-note:hover { transform: rotate(0deg) scale(1.03); }
        .sticky-note .note-del {
            position: absolute; top: 0.1875rem; right: 0.375rem;
            background: none; border: none; cursor: pointer;
            font-size: 0.85rem; opacity: 0.5; color: #333;
        }
        .sticky-note .note-del:hover { opacity: 1; }
        .note-colors { display: flex; gap: 0.3125rem; margin-bottom: 0.5rem; }
        .note-color {
            width: 1.375rem; height: 1.375rem; border-radius: 50%;
            cursor: pointer; border: 2px solid transparent;
            transition: all var(--transition-fast);
        }
        .note-color.active { border-color: var(--text); transform: scale(1.15); }

        /* Time input */
        .time-input-row {
            display: flex; gap: 0.375rem; align-items: center;
            justify-content: center; margin: 0.875rem 0;
        }
        .time-input-row .t-input {
            width: 4.0625rem; text-align: center; font-size: 1.4rem;
            padding: 0.5rem 0.375rem;
        }
        .time-input-row span { font-size: 1.4rem; color: var(--text-muted); }

        /* Gradient */
        .dual-colors { display: flex; gap: 0.625rem; }
        .dual-colors .color-half { flex: 1; }
        .gradient-preview {
            width: 100%; height: 5.625rem; border-radius: var(--radius-sm);
            margin: 0.875rem 0; border: 1px solid var(--border);
        }
        .css-output {
            background: var(--bg); padding: 0.75rem;
            border-radius: var(--radius-sm); font-family: 'Courier New', monospace;
            font-size: 0.8rem; word-break: break-all;
            margin-top: 0.5rem; color: var(--text); border: 1px solid var(--border);
        }

        /* Converter */
        .converter-row { display: flex; gap: 0.375rem; align-items: flex-end; }
        .converter-row .t-form-group { flex: 1; }
        .converter-row .swap-btn {
            background: var(--bg-secondary); border: 1px solid var(--border);
            color: var(--text); width: 2.25rem; height: 2.25rem;
            border-radius: var(--radius-sm); cursor: pointer;
            display: flex; align-items: center; justify-content: center;
            margin-bottom: 0.875rem; font-size: 1.1rem;
            transition: all var(--transition-fast);
        }
        .converter-row .swap-btn:hover { background: var(--primary-light); color: var(--primary); }

        /* JSON */
        .json-output {
            background: var(--bg); padding: 0.75rem;
            border-radius: var(--radius-sm); font-family: 'Courier New', monospace;
            font-size: 0.8rem; max-height: 180px; overflow-y: auto;
            white-space: pre-wrap; word-break: break-all;
            color: var(--text); border: 1px solid var(--border);
        }
        .json-error { color: var(--danger) !important; }

        /* Weather */
        .weather-card {
            text-align: center; padding: 1rem;
            background: var(--bg); border-radius: var(--radius-card);
            margin: 0.875rem 0; border: 1px solid var(--border);
        }

        /* IP info */
        .ip-info-card {
            background: var(--bg); border-radius: var(--radius-card);
            padding: 1rem; border: 1px solid var(--border);
        }
        .ip-info-row {
            display: flex; justify-content: space-between;
            padding: 0.4375rem 0; border-bottom: 1px solid var(--border);
            color: var(--text);
        }
        .ip-info-row:last-child { border-bottom: none; }
        .ip-info-label { color: var(--text-muted); }
        .ip-info-val { font-weight: 500; }

        /* Photo */
        .photo-grid {
            display: grid; grid-template-columns: repeat(3, 1fr); gap: 0.375rem;
        }
        .photo-grid img {
            width: 100%; aspect-ratio: 1; object-fit: cover;
            border-radius: var(--radius-sm); cursor: pointer;
            transition: transform 0.2s; border: 1px solid var(--border);
        }
        .photo-grid img:hover { transform: scale(1.05); }

        /* Sitemap */
        .sitemap-tree { padding-left: 0; }
        .sitemap-tree li {
            list-style: none; padding: 0.4375rem 0.625rem;
            border-left: 2px solid var(--border);
            margin-left: 0.625rem; position: relative;
        }
        .sitemap-tree li::before {
            content: ''; position: absolute;
            left: -2px; top: 50%; width: 0.625rem;
            height: 2px; background: var(--border);
        }
        .sitemap-tree li a { color: var(--primary); text-decoration: none; font-size: 0.9rem; }
        .sitemap-tree li a:hover { text-decoration: underline; }

        /* Analog clock */
        .analog-clock {
            width: 11.25rem; height: 11.25rem; margin: 0 auto;
        }
        .digital-clock {
            text-align: center; font-size: 1.8rem;
            font-family: 'Courier New', monospace;
            margin-top: 0.625rem; color: var(--text);
        }

        /* TTS */
        .tts-controls { display: flex; gap: 0.375rem; align-items: center; }
        .tts-controls .t-select { width: auto; }

        /* Toast */
        .t-toast {
            position: fixed; bottom: 1.875rem; left: 50%; transform: translateX(-50%);
            background: var(--text); color: var(--text-inverse);
            padding: 0.625rem 1.375rem; border-radius: var(--radius-sm);
            font-size: 0.85rem; z-index: 9999;
            animation: toastIn 0.3s ease, toastOut 0.3s ease 1.7s forwards;
            font-family: inherit;
            box-shadow: var(--shadow-md);
        }
        @keyframes toastIn { from { opacity: 0; transform: translateX(-50%) translateY(16px); } to { opacity: 1; transform: translateX(-50%) translateY(0); } }
        @keyframes toastOut { from { opacity: 1; } to { opacity: 0; } }

        /* Dark theme overrides */
        [data-theme="dark"] .tool-modal { background: #1e1e2e; border-color: rgba(255,255,255,0.1); }
        [data-theme="dark"] .tool-modal-header { background: #1e1e2e; border-bottom-color: rgba(255,255,255,0.08); }
        [data-theme="dark"] .tool-modal-close { background: rgba(255,255,255,0.08); border-color: rgba(255,255,255,0.15); color: rgba(255,255,255,0.6); }
        [data-theme="dark"] .tool-modal-close:hover { background: rgba(231,76,60,0.2); border-color: rgba(231,76,60,0.4); color: #e74c3c; }
        [data-theme="dark"] .reading-mode-body { background: #2a2218 !important; color: #e8dcc8 !important; }
        [data-theme="dark"] .reading-mode-body .site-header { background: #3a3228 !important; }
        [data-theme="dark"] .reading-mode-body .tool-card { background: #3a3228 !important; border-color: #5a4a38 !important; }
        [data-theme="dark"] .reading-mode-body .tool-card:hover { background: #4a4238 !important; }
    </style>
</head>
<body>
    <a class="skip-to-content" href="#main-content">跳到主内容</a>
    <?php if ($bannedMsg): ?>
    <div class="ban-banner">
        <div class="container">
            <span class="ban-text"><?= htmlspecialchars($bannedMsg) ?></span>
        </div>
    </div>
    <?php endif; ?>

    <?php
    $headerGuestCta = 'login';
    require __DIR__ . '/../includes/site_header.php';
    ?>

    <?php require __DIR__ . '/../includes/ai_widget.php'; ?>

    <?php if ($isGuest): ?>
    <div class="guest-bar">
        <div class="container">
            <span class="guest-bar-text">
                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
                <?= t('guest.banner') ?>
            </span>
            <a href="<?= SITE_URL ?>/pages/register.php" class="guest-bar-btn"><?= t('guest.register_cta') ?></a>
        </div>
    </div>
    <?php endif; ?>

    <?php if ($announcement): ?>
    <div class="announcement-bar">
        <div class="container">
            <div class="announcement-scroll">
                <span><?= htmlspecialchars($announcement) ?></span>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <main id="main-content" class="tools-main">
        <div class="tools-container">
            <div class="tools-header">
                <h1><?= t('tools.page_title') ?></h1>
                <p class="subtitle"><?= t('tools.page_desc') ?></p>
            </div>

            <div class="tools-category">
                <div class="tools-category-title"><?= t('tools.cat_time') ?></div>
                <div class="tool-grid">
                    <div class="tool-card" onclick="openTool('pomodoro')">
                        <div class="icon-wrap">
                            <svg viewBox="0 0 64 64" xmlns="http://www.w3.org/2000/svg">
                                <defs><linearGradient id="tomatoGrad" x1="0%" y1="0%" x2="100%" y2="100%"><stop offset="0%" stop-color="#ff6b6b"/><stop offset="100%" stop-color="#c0392b"/></linearGradient></defs>
                                <ellipse cx="32" cy="38" rx="22" ry="20" fill="url(#tomatoGrad)"/>
                                <path d="M28 10 Q28 18 22 18" fill="none" stroke="#2ecc71" stroke-width="3" stroke-linecap="round"/>
                                <ellipse cx="32" cy="14" rx="10" ry="6" fill="#27ae60"/>
                                <path d="M22 18 Q32 22 42 18" fill="none" stroke="#27ae60" stroke-width="2.5" stroke-linecap="round"/>
                                <line x1="32" y1="38" x2="32" y2="26" stroke="#fff" stroke-width="2.5" stroke-linecap="round"/>
                                <line x1="32" y1="38" x2="42" y2="38" stroke="#fff" stroke-width="2" stroke-linecap="round"/>
                                <circle cx="32" cy="38" r="3" fill="#fff"/>
                            </svg>
                        </div>
                        <div class="tool-name"><?= t('tools.name_tomato') ?></div>
                    </div>
                    <div class="tool-card" onclick="openTool('countdown')">
                        <div class="icon-wrap">
                            <svg viewBox="0 0 64 64" xmlns="http://www.w3.org/2000/svg">
                                <defs><linearGradient id="hourglassGrad" x1="0%" y1="0%" x2="0%" y2="100%"><stop offset="0%" stop-color="#f9ca24"/><stop offset="100%" stop-color="#e1b12c"/></linearGradient></defs>
                                <rect x="22" y="4" width="20" height="6" rx="2" fill="#f9ca24"/>
                                <rect x="22" y="54" width="20" height="6" rx="2" fill="#f9ca24"/>
                                <polygon points="26,10 38,10 44,32 38,54 26,54 20,32" fill="url(#hourglassGrad)"/>
                                <polygon points="26,10 38,10 32,32" fill="#f6e58d" opacity="0.7"/>
                                <circle cx="32" cy="32" r="3" fill="#d35400"/>
                            </svg>
                        </div>
                        <div class="tool-name"><?= t('tools.name_countdown') ?></div>
                    </div>
                    <div class="tool-card" onclick="openTool('stopwatch')">
                        <div class="icon-wrap">
                            <svg viewBox="0 0 64 64" xmlns="http://www.w3.org/2000/svg">
                                <defs><linearGradient id="stopwatchGrad" x1="0%" y1="0%" x2="100%" y2="100%"><stop offset="0%" stop-color="#3498db"/><stop offset="100%" stop-color="#2980b9"/></linearGradient></defs>
                                <circle cx="32" cy="36" r="22" fill="url(#stopwatchGrad)"/>
                                <rect x="30" y="8" width="4" height="8" rx="1" fill="#3498db"/>
                                <circle cx="32" cy="14" r="4" fill="#3498db"/>
                                <line x1="32" y1="36" x2="32" y2="24" stroke="#fff" stroke-width="3" stroke-linecap="round"/>
                                <line x1="32" y1="36" x2="44" y2="36" stroke="#fff" stroke-width="2.5" stroke-linecap="round"/>
                                <circle cx="32" cy="36" r="3" fill="#fff"/>
                            </svg>
                        </div>
                        <div class="tool-name"><?= t('tools.name_stopwatch') ?></div>
                    </div>
                    <div class="tool-card" onclick="openTool('clock')">
                        <div class="icon-wrap">
                            <svg viewBox="0 0 64 64" xmlns="http://www.w3.org/2000/svg">
                                <defs><linearGradient id="clockGrad" x1="0%" y1="0%" x2="100%" y2="100%"><stop offset="0%" stop-color="#a29bfe"/><stop offset="100%" stop-color="#6c5ce7"/></linearGradient></defs>
                                <circle cx="32" cy="32" r="26" fill="url(#clockGrad)"/>
                                <line x1="32" y1="32" x2="32" y2="18" stroke="#fff" stroke-width="3" stroke-linecap="round"/>
                                <line x1="32" y1="32" x2="42" y2="32" stroke="#fff" stroke-width="2" stroke-linecap="round"/>
                                <circle cx="32" cy="32" r="3" fill="#fff"/>
                            </svg>
                        </div>
                        <div class="tool-name"><?= t('tools.name_clock') ?></div>
                    </div>
                </div>
            </div>

            <div class="tools-category">
                <div class="tools-category-title"><?= t('tools.cat_efficiency') ?></div>
                <div class="tool-grid">
                    <div class="tool-card" onclick="openTool('todo')">
                        <div class="icon-wrap">
                            <svg viewBox="0 0 64 64" xmlns="http://www.w3.org/2000/svg">
                                <defs><linearGradient id="checklistGrad" x1="0%" y1="0%" x2="100%" y2="100%"><stop offset="0%" stop-color="#2ecc71"/><stop offset="100%" stop-color="#27ae60"/></linearGradient></defs>
                                <rect x="8" y="6" width="48" height="52" rx="6" fill="url(#checklistGrad)"/>
                                <rect x="14" y="14" width="36" height="6" rx="2" fill="rgba(255,255,255,0.3)"/>
                                <rect x="14" y="26" width="36" height="6" rx="2" fill="rgba(255,255,255,0.2)"/>
                                <rect x="14" y="38" width="36" height="6" rx="2" fill="rgba(255,255,255,0.2)"/>
                                <circle cx="20" cy="17" r="4" fill="none" stroke="#fff" stroke-width="2"/>
                                <polyline points="17,17 19,20 23,14" fill="none" stroke="#fff" stroke-width="2" stroke-linecap="round"/>
                            </svg>
                        </div>
                        <div class="tool-name"><?= t('tools.name_todo') ?></div>
                    </div>
                    <div class="tool-card" onclick="openTool('sticky')">
                        <div class="icon-wrap">
                            <svg viewBox="0 0 64 64" xmlns="http://www.w3.org/2000/svg">
                                <defs><linearGradient id="stickyGrad" x1="0%" y1="0%" x2="100%" y2="100%"><stop offset="0%" stop-color="#f9ca24"/><stop offset="100%" stop-color="#f0932b"/></linearGradient></defs>
                                <rect x="12" y="8" width="40" height="44" rx="3" fill="url(#stickyGrad)"/>
                                <polygon points="52,8 52,20 40,20" fill="#f6e58d" opacity="0.5"/>
                                <line x1="18" y1="18" x2="42" y2="18" stroke="rgba(0,0,0,0.15)" stroke-width="2"/>
                                <line x1="18" y1="26" x2="42" y2="26" stroke="rgba(0,0,0,0.1)" stroke-width="2"/>
                            </svg>
                        </div>
                        <div class="tool-name"><?= t('tools.name_sticky') ?></div>
                    </div>
                    <div class="tool-card" onclick="openTool('wordcount')">
                        <div class="icon-wrap">
                            <svg viewBox="0 0 64 64" xmlns="http://www.w3.org/2000/svg">
                                <defs><linearGradient id="wordcountGrad" x1="0%" y1="0%" x2="100%" y2="100%"><stop offset="0%" stop-color="#a29bfe"/><stop offset="100%" stop-color="#6c5ce7"/></linearGradient></defs>
                                <rect x="8" y="10" width="48" height="44" rx="6" fill="url(#wordcountGrad)"/>
                                <line x1="18" y1="20" x2="46" y2="20" stroke="rgba(255,255,255,0.5)" stroke-width="2" stroke-linecap="round"/>
                                <line x1="18" y1="28" x2="42" y2="28" stroke="rgba(255,255,255,0.4)" stroke-width="2" stroke-linecap="round"/>
                                <line x1="18" y1="36" x2="40" y2="36" stroke="rgba(255,255,255,0.3)" stroke-width="2" stroke-linecap="round"/>
                            </svg>
                        </div>
                        <div class="tool-name"><?= t('tools.name_wordcount') ?></div>
                    </div>
                    <div class="tool-card" onclick="openTool('search')">
                        <div class="icon-wrap">
                            <svg viewBox="0 0 64 64" xmlns="http://www.w3.org/2000/svg">
                                <circle cx="28" cy="28" r="18" fill="none" stroke="#74b9ff" stroke-width="4"/>
                                <line x1="42" y1="42" x2="54" y2="54" stroke="#74b9ff" stroke-width="5" stroke-linecap="round"/>
                                <circle cx="22" cy="26" r="3" fill="#74b9ff"/>
                            </svg>
                        </div>
                        <div class="tool-name"><?= t('tools.name_search') ?></div>
                    </div>
                </div>
            </div>

            <div class="tools-category">
                <div class="tools-category-title"><?= t('tools.cat_calc') ?></div>
                <div class="tool-grid">
                    <div class="tool-card" onclick="openTool('calculator')">
                        <div class="icon-wrap">
                            <svg viewBox="0 0 64 64" xmlns="http://www.w3.org/2000/svg">
                                <defs><linearGradient id="calcGrad" x1="0%" y1="0%" x2="100%" y2="100%"><stop offset="0%" stop-color="#3498db"/><stop offset="100%" stop-color="#2c3e50"/></linearGradient></defs>
                                <rect x="8" y="6" width="48" height="52" rx="8" fill="url(#calcGrad)"/>
                                <rect x="14" y="12" width="36" height="12" rx="4" fill="rgba(0,0,0,0.3)"/>
                                <rect x="14" y="28" width="8" height="6" rx="2" fill="rgba(255,255,255,0.15)"/>
                                <rect x="24" y="28" width="8" height="6" rx="2" fill="rgba(255,255,255,0.15)"/>
                                <rect x="34" y="28" width="8" height="6" rx="2" fill="rgba(255,255,255,0.15)"/>
                                <rect x="44" y="28" width="8" height="6" rx="2" fill="rgba(231,76,60,0.5)"/>
                            </svg>
                        </div>
                        <div class="tool-name"><?= t('tools.name_calculator') ?></div>
                    </div>
                    <div class="tool-card" onclick="openTool('converter')">
                        <div class="icon-wrap">
                            <svg viewBox="0 0 64 64" xmlns="http://www.w3.org/2000/svg">
                                <defs><linearGradient id="rulerGrad" x1="0%" y1="0%" x2="100%" y2="100%"><stop offset="0%" stop-color="#00b894"/><stop offset="100%" stop-color="#00cec9"/></linearGradient></defs>
                                <rect x="8" y="20" width="48" height="6" rx="3" fill="url(#rulerGrad)"/>
                                <line x1="12" y1="20" x2="12" y2="28" stroke="#fff" stroke-width="1"/>
                                <line x1="24" y1="20" x2="24" y2="28" stroke="#fff" stroke-width="1"/>
                                <line x1="36" y1="20" x2="36" y2="28" stroke="#fff" stroke-width="1"/>
                                <line x1="48" y1="20" x2="48" y2="28" stroke="#fff" stroke-width="1"/>
                                <circle cx="32" cy="38" r="6" fill="none" stroke="#00b894" stroke-width="2"/>
                                <line x1="32" y1="32" x2="32" y2="14" stroke="#00b894" stroke-width="2"/>
                            </svg>
                        </div>
                        <div class="tool-name"><?= t('tools.name_converter') ?></div>
                    </div>
                    <div class="tool-card" onclick="openTool('qrcode')">
                        <div class="icon-wrap">
                            <svg viewBox="0 0 64 64" xmlns="http://www.w3.org/2000/svg">
                                <rect x="8" y="8" width="18" height="18" rx="2" fill="#2d3436"/>
                                <rect x="12" y="12" width="10" height="10" rx="1" fill="#fff"/>
                                <rect x="16" y="16" width="2" height="2" fill="#2d3436"/>
                                <rect x="38" y="8" width="18" height="18" rx="2" fill="#2d3436"/>
                                <rect x="42" y="12" width="10" height="10" rx="1" fill="#fff"/>
                                <rect x="46" y="16" width="2" height="2" fill="#2d3436"/>
                                <rect x="8" y="38" width="18" height="18" rx="2" fill="#2d3436"/>
                                <rect x="12" y="42" width="10" height="10" rx="1" fill="#fff"/>
                                <rect x="16" y="46" width="2" height="2" fill="#2d3436"/>
                                <rect x="36" y="36" width="4" height="4" fill="#2d3436"/>
                                <rect x="44" y="36" width="4" height="4" fill="#2d3436"/>
                                <rect x="36" y="44" width="4" height="4" fill="#2d3436"/>
                                <rect x="48" y="36" width="2" height="2" fill="#2d3436"/>
                                <rect x="52" y="40" width="4" height="4" fill="#2d3436"/>
                                <rect x="52" y="48" width="4" height="4" fill="#2d3436"/>
                                <rect x="36" y="52" width="4" height="4" fill="#2d3436"/>
                                <rect x="48" y="52" width="2" height="2" fill="#2d3436"/>
                            </svg>
                        </div>
                        <div class="tool-name"><?= t('tools.name_qrcode') ?></div>
                    </div>
                    <div class="tool-card" onclick="openTool('jsonfmt')">
                        <div class="icon-wrap">
                            <svg viewBox="0 0 64 64" xmlns="http://www.w3.org/2000/svg">
                                <defs><linearGradient id="jsonGrad" x1="0%" y1="0%" x2="100%" y2="100%"><stop offset="0%" stop-color="#e17055"/><stop offset="100%" stop-color="#d63031"/></linearGradient></defs>
                                <text x="10" y="22" font-size="20" font-weight="bold" fill="url(#jsonGrad)" font-family="monospace">{</text>
                                <text x="10" y="40" font-size="20" font-weight="bold" fill="url(#jsonGrad)" font-family="monospace">}</text>
                                <text x="22" y="22" font-size="12" fill="#fab1a0" font-family="monospace">"key"</text>
                                <text x="22" y="38" font-size="12" fill="#74b9ff" font-family="monospace">value</text>
                            </svg>
                        </div>
                        <div class="tool-name"><?= t('tools.name_jsonfmt') ?></div>
                    </div>
                    <div class="tool-card" onclick="openTool('base64')">
                        <div class="icon-wrap">
                            <svg viewBox="0 0 64 64" xmlns="http://www.w3.org/2000/svg">
                                <defs><linearGradient id="base64Grad" x1="0%" y1="0%" x2="100%" y2="100%"><stop offset="0%" stop-color="#636e72"/><stop offset="100%" stop-color="#2d3436"/></linearGradient></defs>
                                <rect x="6" y="10" width="52" height="44" rx="6" fill="url(#base64Grad)"/>
                                <text x="14" y="26" font-size="10" fill="#dfe6e9" font-family="monospace" font-weight="bold">btoa</text>
                                <text x="14" y="46" font-size="10" fill="#74b9ff" font-family="monospace" font-weight="bold">atob</text>
                            </svg>
                        </div>
                        <div class="tool-name"><?= t('tools.name_base64') ?></div>
                    </div>
                </div>
            </div>

            <div class="tools-category">
                <div class="tools-category-title"><?= t('tools.cat_creative') ?></div>
                <div class="tool-grid">
                    <div class="tool-card" onclick="openTool('colorpicker')">
                        <div class="icon-wrap">
                            <svg viewBox="0 0 64 64" xmlns="http://www.w3.org/2000/svg">
                                <circle cx="32" cy="28" r="6" fill="#e74c3c"/>
                                <circle cx="22" cy="20" r="4" fill="#f39c12"/>
                                <circle cx="42" cy="20" r="4" fill="#2ecc71"/>
                                <circle cx="18" cy="34" r="4" fill="#3498db"/>
                                <circle cx="46" cy="34" r="4" fill="#9b59b6"/>
                                <circle cx="32" cy="48" r="6" fill="#e74c3c"/>
                                <circle cx="22" cy="44" r="4" fill="#f1c40f"/>
                                <circle cx="42" cy="44" r="4" fill="#1abc9c"/>
                            </svg>
                        </div>
                        <div class="tool-name"><?= t('tools.name_colorpicker') ?></div>
                    </div>
                    <div class="tool-card" onclick="openTool('gradient')">
                        <div class="icon-wrap">
                            <svg viewBox="0 0 64 64" xmlns="http://www.w3.org/2000/svg">
                                <defs><linearGradient id="gradientIcon" x1="0%" y1="0%" x2="0%" y2="100%"><stop offset="0%" stop-color="#e74c3c"/><stop offset="20%" stop-color="#f39c12"/><stop offset="40%" stop-color="#2ecc71"/><stop offset="60%" stop-color="#3498db"/><stop offset="80%" stop-color="#9b59b6"/><stop offset="100%" stop-color="#fd79a8"/></linearGradient></defs>
                                <rect x="16" y="8" width="32" height="48" rx="8" fill="url(#gradientIcon)"/>
                            </svg>
                        </div>
                        <div class="tool-name"><?= t('tools.name_gradient') ?></div>
                    </div>
                    <div class="tool-card" onclick="openTool('password')">
                        <div class="icon-wrap">
                            <svg viewBox="0 0 64 64" xmlns="http://www.w3.org/2000/svg">
                                <defs><linearGradient id="keyGrad" x1="0%" y1="0%" x2="100%" y2="100%"><stop offset="0%" stop-color="#f9ca24"/><stop offset="100%" stop-color="#e1b12c"/></linearGradient></defs>
                                <circle cx="24" cy="30" r="12" fill="none" stroke="url(#keyGrad)" stroke-width="4"/>
                                <circle cx="24" cy="30" r="4" fill="url(#keyGrad)"/>
                                <line x1="34" y1="30" x2="56" y2="30" stroke="url(#keyGrad)" stroke-width="4" stroke-linecap="round"/>
                                <line x1="48" y1="30" x2="48" y2="22" stroke="url(#keyGrad)" stroke-width="3" stroke-linecap="round"/>
                                <line x1="54" y1="30" x2="54" y2="22" stroke="url(#keyGrad)" stroke-width="3" stroke-linecap="round"/>
                            </svg>
                        </div>
                        <div class="tool-name"><?= t('tools.name_password') ?></div>
                    </div>
                </div>
            </div>

            <div class="tools-category">
                <div class="tools-category-title"><?= t('tools.cat_entertain') ?></div>
                <div class="tool-grid">
                    <div class="tool-card" onclick="openTool('dice')">
                        <div class="icon-wrap">
                            <svg viewBox="0 0 64 64" xmlns="http://www.w3.org/2000/svg">
                                <defs><linearGradient id="diceGrad" x1="0%" y1="0%" x2="100%" y2="100%"><stop offset="0%" stop-color="#ff6b6b"/><stop offset="100%" stop-color="#c0392b"/></linearGradient></defs>
                                <rect x="8" y="8" width="48" height="48" rx="8" fill="url(#diceGrad)"/>
                                <circle cx="24" cy="24" r="3" fill="#fff"/>
                                <circle cx="40" cy="40" r="3" fill="#fff"/>
                                <circle cx="32" cy="32" r="3" fill="#fff"/>
                                <circle cx="24" cy="40" r="3" fill="#fff"/>
                                <circle cx="40" cy="24" r="3" fill="#fff"/>
                            </svg>
                        </div>
                        <div class="tool-name"><?= t('tools.name_dice') ?></div>
                    </div>
                    <div class="tool-card" onclick="openTool('coin')">
                        <div class="icon-wrap">
                            <svg viewBox="0 0 64 64" xmlns="http://www.w3.org/2000/svg">
                                <defs><linearGradient id="coinGrad" x1="0%" y1="0%" x2="100%" y2="100%"><stop offset="0%" stop-color="#f9ca24"/><stop offset="100%" stop-color="#e1b12c"/></linearGradient></defs>
                                <ellipse cx="32" cy="44" rx="24" ry="6" fill="#d4a017"/>
                                <rect x="8" y="14" width="48" height="30" rx="24" fill="url(#coinGrad)"/>
                                <ellipse cx="32" cy="14" rx="24" ry="6" fill="#f6e58d"/>
                                <text x="32" y="36" text-anchor="middle" font-size="16" font-weight="bold" fill="#d4a017">$</text>
                            </svg>
                        </div>
                        <div class="tool-name"><?= t('tools.name_coin') ?></div>
                    </div>
                    <div class="tool-card" onclick="openTool('randompick')">
                        <div class="icon-wrap">
                            <svg viewBox="0 0 64 64" xmlns="http://www.w3.org/2000/svg">
                                <defs><linearGradient id="shuffleGrad" x1="0%" y1="0%" x2="100%" y2="100%"><stop offset="0%" stop-color="#a29bfe"/><stop offset="100%" stop-color="#6c5ce7"/></linearGradient></defs>
                                <polyline points="12,16 52,16 52,26" fill="none" stroke="url(#shuffleGrad)" stroke-width="3" stroke-linecap="round"/>
                                <polyline points="52,38 12,38 12,48" fill="none" stroke="url(#shuffleGrad)" stroke-width="3" stroke-linecap="round"/>
                                <polyline points="44,10 52,16 44,22" fill="none" stroke="url(#shuffleGrad)" stroke-width="3" stroke-linecap="round"/>
                                <polyline points="20,42 12,48 20,54" fill="none" stroke="url(#shuffleGrad)" stroke-width="3" stroke-linecap="round"/>
                            </svg>
                        </div>
                        <div class="tool-name"><?= t('tools.name_randompick') ?></div>
                    </div>
                    <a class="tool-card" href="<?= SITE_URL ?>/pages/rollcall.php">
                        <div class="icon-wrap">
                            <svg viewBox="0 0 64 64" xmlns="http://www.w3.org/2000/svg">
                                <defs><linearGradient id="rollcallGrad" x1="0%" y1="0%" x2="100%" y2="100%"><stop offset="0%" stop-color="#f6e58d"/><stop offset="100%" stop-color="#e1b12c"/></linearGradient></defs>
                                <rect x="12" y="8" width="40" height="48" rx="6" fill="url(#rollcallGrad)"/>
                                <circle cx="24" cy="23" r="4" fill="#fff" opacity="0.85"/>
                                <circle cx="24" cy="33" r="4" fill="#fff" opacity="0.65"/>
                                <circle cx="24" cy="43" r="4" fill="#fff" opacity="0.45"/>
                                <line x1="33" y1="23" x2="44" y2="23" stroke="#fff" stroke-width="3" stroke-linecap="round" opacity="0.75"/>
                                <line x1="33" y1="33" x2="44" y2="33" stroke="#fff" stroke-width="3" stroke-linecap="round" opacity="0.55"/>
                                <line x1="33" y1="43" x2="40" y2="43" stroke="#fff" stroke-width="3" stroke-linecap="round" opacity="0.4"/>
                            </svg>
                        </div>
                        <div class="tool-name"><?= t('tools.name_rollcall') ?></div>
                    </a>
                    <div class="tool-card" onclick="openTool('fortune')">
                        <div class="icon-wrap">
                            <svg viewBox="0 0 64 64" xmlns="http://www.w3.org/2000/svg">
                                <defs><linearGradient id="fortuneGrad" x1="0%" y1="0%" x2="100%" y2="100%"><stop offset="0%" stop-color="#e17055"/><stop offset="100%" stop-color="#d63031"/></linearGradient></defs>
                                <path d="M16 40 Q16 12 32 12 Q48 12 48 40 L48 52 Q32 48 16 52 Z" fill="url(#fortuneGrad)"/>
                                <path d="M16 40 Q32 36 48 40" fill="none" stroke="#e17055" stroke-width="3"/>
                            </svg>
                        </div>
                        <div class="tool-name"><?= t('tools.name_fortune') ?></div>
                    </div>
                    <div class="tool-card" onclick="openTool('wheel')">
                        <div class="icon-wrap">
                            <svg viewBox="0 0 64 64" xmlns="http://www.w3.org/2000/svg">
                                <circle cx="32" cy="32" r="24" fill="none" stroke="#e74c3c" stroke-width="3" stroke-dasharray="38 38"/>
                                <circle cx="32" cy="32" r="24" fill="none" stroke="#f39c12" stroke-width="3" stroke-dasharray="38 38" transform="rotate(60,32,32)"/>
                                <circle cx="32" cy="32" r="24" fill="none" stroke="#2ecc71" stroke-width="3" stroke-dasharray="38 38" transform="rotate(120,32,32)"/>
                                <circle cx="32" cy="32" r="24" fill="none" stroke="#3498db" stroke-width="3" stroke-dasharray="38 38" transform="rotate(180,32,32)"/>
                                <circle cx="32" cy="32" r="24" fill="none" stroke="#9b59b6" stroke-width="3" stroke-dasharray="38 38" transform="rotate(240,32,32)"/>
                                <circle cx="32" cy="32" r="4" fill="#fff"/>
                            </svg>
                        </div>
                        <div class="tool-name"><?= t('tools.name_wheel') ?></div>
                    </div>
                    <div class="tool-card" onclick="openTool('scratch')">
                        <div class="icon-wrap">
                            <svg viewBox="0 0 64 64" xmlns="http://www.w3.org/2000/svg">
                                <defs><linearGradient id="scratchGrad" x1="0%" y1="0%" x2="100%" y2="100%"><stop offset="0%" stop-color="#b2bec3"/><stop offset="100%" stop-color="#636e72"/></linearGradient></defs>
                                <rect x="8" y="8" width="48" height="48" rx="8" fill="url(#scratchGrad)"/>
                                <rect x="16" y="16" width="32" height="32" rx="4" fill="rgba(255,255,255,0.15)"/>
                                <text x="32" y="38" text-anchor="middle" font-size="14" fill="rgba(255,255,255,0.5)" font-weight="bold"><?= t('tools.scratch_hint') ?></text>
                            </svg>
                        </div>
                        <div class="tool-name"><?= t('tools.name_scratch') ?></div>
                    </div>
                </div>
            </div>

            <div class="tools-category">
                <div class="tools-category-title"><?= t('tools.cat_query') ?></div>
                <div class="tool-grid">
                    <div class="tool-card" onclick="openTool('weather')">
                        <div class="icon-wrap">
                            <svg viewBox="0 0 64 64" xmlns="http://www.w3.org/2000/svg">
                                <defs><linearGradient id="weatherGrad" x1="0%" y1="0%" x2="100%" y2="100%"><stop offset="0%" stop-color="#74b9ff"/><stop offset="100%" stop-color="#0984e3"/></linearGradient></defs>
                                <circle cx="28" cy="24" r="14" fill="#fdcb6e"/>
                                <path d="M14 42 Q14 34 20 32 Q28 26 38 28 Q46 34 50 38 Q52 42 50 46 Q48 50 44 50 L20 50 Q14 50 14 46 Z" fill="url(#weatherGrad)"/>
                            </svg>
                        </div>
                        <div class="tool-name"><?= t('tools.name_weather') ?></div>
                    </div>
                    <div class="tool-card" onclick="openTool('ipquery')">
                        <div class="icon-wrap">
                            <svg viewBox="0 0 64 64" xmlns="http://www.w3.org/2000/svg">
                                <defs><linearGradient id="ipGrad" x1="0%" y1="0%" x2="100%" y2="100%"><stop offset="0%" stop-color="#3498db"/><stop offset="100%" stop-color="#2c3e50"/></linearGradient></defs>
                                <circle cx="32" cy="32" r="24" fill="none" stroke="url(#ipGrad)" stroke-width="3"/>
                                <ellipse cx="32" cy="32" rx="14" ry="24" fill="none" stroke="url(#ipGrad)" stroke-width="2"/>
                                <line x1="8" y1="32" x2="56" y2="32" stroke="url(#ipGrad)" stroke-width="2"/>
                            </svg>
                        </div>
                        <div class="tool-name"><?= t('tools.name_ipquery') ?></div>
                    </div>
                    <div class="tool-card" onclick="openTool('quote')">
                        <div class="icon-wrap">
                            <svg viewBox="0 0 64 64" xmlns="http://www.w3.org/2000/svg">
                                <defs><linearGradient id="quoteGrad" x1="0%" y1="0%" x2="100%" y2="100%"><stop offset="0%" stop-color="#fd79a8"/><stop offset="100%" stop-color="#e84393"/></linearGradient></defs>
                                <rect x="12" y="10" width="40" height="44" rx="20" fill="url(#quoteGrad)"/>
                                <polygon points="44,36 44,48 52,44" fill="#fff" opacity="0.8"/>
                                <line x1="24" y1="22" x2="40" y2="22" stroke="rgba(255,255,255,0.5)" stroke-width="2" stroke-linecap="round"/>
                                <line x1="24" y1="30" x2="38" y2="30" stroke="rgba(255,255,255,0.4)" stroke-width="2" stroke-linecap="round"/>
                            </svg>
                        </div>
                        <div class="tool-name"><?= t('tools.name_quote') ?></div>
                    </div>
                    <div class="tool-card" onclick="openTool('featurevote')">
                        <div class="icon-wrap">
                            <svg viewBox="0 0 64 64" xmlns="http://www.w3.org/2000/svg">
                                <defs><linearGradient id="fvGrad" x1="0%" y1="0%" x2="100%" y2="100%"><stop offset="0%" stop-color="#fbc531"/><stop offset="100%" stop-color="#e84118"/></linearGradient></defs>
                                <circle cx="20" cy="22" r="9" fill="url(#fvGrad)"/>
                                <path d="M12 34 h16 v18 H12 z" fill="url(#fvGrad)"/>
                                <path d="M44 12 l-7 12 h7 l-8 14 h16 l-6-14 h8 z" fill="#ffd32a"/>
                            </svg>
                        </div>
                        <div class="tool-name"><?= t('tools.name_featurevote') ?></div>
                    </div>
                </div>
            </div>

            <div class="tools-category">
                <div class="tools-category-title"><?= t('tools.cat_media') ?></div>
                <div class="tool-grid">
                    <div class="tool-card" onclick="openTool('photowall')">
                        <div class="icon-wrap">
                            <svg viewBox="0 0 64 64" xmlns="http://www.w3.org/2000/svg">
                                <defs><linearGradient id="photoGrad" x1="0%" y1="0%" x2="100%" y2="100%"><stop offset="0%" stop-color="#00cec9"/><stop offset="100%" stop-color="#00b894"/></linearGradient></defs>
                                <rect x="6" y="8" width="24" height="24" rx="4" fill="url(#photoGrad)"/>
                                <circle cx="16" cy="18" r="4" fill="rgba(255,255,255,0.3)"/>
                                <polygon points="6,32 16,22 20,26 26,18 30,32" fill="rgba(255,255,255,0.2)"/>
                                <rect x="34" y="8" width="24" height="24" rx="4" fill="url(#photoGrad)"/>
                                <rect x="6" y="36" width="24" height="24" rx="4" fill="url(#photoGrad)"/>
                                <rect x="34" y="36" width="24" height="24" rx="4" fill="url(#photoGrad)"/>
                            </svg>
                        </div>
                        <div class="tool-name"><?= t('tools.name_photowall') ?></div>
                    </div>
                    <div class="tool-card" onclick="openTool('tts')">
                        <div class="icon-wrap">
                            <svg viewBox="0 0 64 64" xmlns="http://www.w3.org/2000/svg">
                                <defs><linearGradient id="ttsGrad" x1="0%" y1="0%" x2="100%" y2="100%"><stop offset="0%" stop-color="#3498db"/><stop offset="100%" stop-color="#2980b9"/></linearGradient></defs>
                                <rect x="10" y="20" width="16" height="24" rx="4" fill="url(#ttsGrad)"/>
                                <polygon points="26,26 44,16 44,48 26,38" fill="url(#ttsGrad)"/>
                                <path d="M48 22 Q54 32 48 42" fill="none" stroke="#3498db" stroke-width="3" stroke-linecap="round"/>
                            </svg>
                        </div>
                        <div class="tool-name"><?= t('tools.name_tts') ?></div>
                    </div>
                    <div class="tool-card" onclick="openTool('screenshot')">
                        <div class="icon-wrap">
                            <svg viewBox="0 0 64 64" xmlns="http://www.w3.org/2000/svg">
                                <defs><linearGradient id="camGrad" x1="0%" y1="0%" x2="100%" y2="100%"><stop offset="0%" stop-color="#2ecc71"/><stop offset="100%" stop-color="#27ae60"/></linearGradient></defs>
                                <rect x="8" y="16" width="48" height="32" rx="6" fill="url(#camGrad)"/>
                                <circle cx="32" cy="32" r="10" fill="none" stroke="#fff" stroke-width="3"/>
                                <circle cx="32" cy="32" r="4" fill="#fff"/>
                                <rect x="24" y="10" width="16" height="8" rx="3" fill="url(#camGrad)"/>
                            </svg>
                        </div>
                        <div class="tool-name"><?= t('tools.name_screenshot') ?></div>
                    </div>
                </div>
            </div>

            <div class="tools-category">
                <div class="tools-category-title"><?= t('tools.cat_other') ?></div>
                <div class="tool-grid">
                    <div class="tool-card" onclick="openTool('vote')">
                        <div class="icon-wrap">
                            <svg viewBox="0 0 64 64" xmlns="http://www.w3.org/2000/svg">
                                <rect x="10" y="8" width="44" height="48" rx="6" fill="none" stroke="#74b9ff" stroke-width="3"/>
                                <line x1="22" y1="18" x2="42" y2="18" stroke="#74b9ff" stroke-width="2"/>
                                <line x1="22" y1="28" x2="42" y2="28" stroke="#74b9ff" stroke-width="2"/>
                                <line x1="22" y1="38" x2="42" y2="38" stroke="#74b9ff" stroke-width="2"/>
                                <circle cx="18" cy="18" r="3" fill="none" stroke="#74b9ff" stroke-width="2"/>
                                <circle cx="18" cy="28" r="3" fill="#74b9ff"/>
                                <polyline points="15,28 17,31 21,25" fill="none" stroke="#fff" stroke-width="2" stroke-linecap="round"/>
                            </svg>
                        </div>
                        <div class="tool-name"><?= t('tools.name_vote') ?></div>
                    </div>
                    <div class="tool-card" onclick="openTool('badges')">
                        <div class="icon-wrap">
                            <svg viewBox="0 0 64 64" xmlns="http://www.w3.org/2000/svg">
                                <defs><linearGradient id="trophyGrad" x1="0%" y1="0%" x2="100%" y2="100%"><stop offset="0%" stop-color="#f9ca24"/><stop offset="100%" stop-color="#e1b12c"/></linearGradient></defs>
                                <path d="M20 12 L24 36 L40 36 L44 12 Z" fill="url(#trophyGrad)"/>
                                <rect x="28" y="36" width="8" height="8" fill="url(#trophyGrad)"/>
                                <rect x="22" y="44" width="20" height="4" rx="1" fill="url(#trophyGrad)"/>
                                <polygon points="32,18 34,22 38,22 35,25 36,29 32,26 28,29 29,25 26,22 30,22" fill="#fff" opacity="0.8"/>
                            </svg>
                        </div>
                        <div class="tool-name"><?= t('tools.name_badges') ?></div>
                    </div>
                    <div class="tool-card" onclick="openTool('eye')">
                        <div class="icon-wrap">
                            <svg viewBox="0 0 64 64" xmlns="http://www.w3.org/2000/svg">
                                <defs><linearGradient id="eyeGrad" x1="0%" y1="0%" x2="100%" y2="100%"><stop offset="0%" stop-color="#2ecc71"/><stop offset="100%" stop-color="#27ae60"/></linearGradient></defs>
                                <ellipse cx="32" cy="32" rx="22" ry="14" fill="none" stroke="url(#eyeGrad)" stroke-width="3"/>
                                <circle cx="32" cy="32" r="6" fill="url(#eyeGrad)"/>
                                <circle cx="32" cy="32" r="3" fill="#1e1e32"/>
                            </svg>
                        </div>
                        <div class="tool-name"><?= t('tools.name_eye') ?></div>
                    </div>
                    <div class="tool-card" onclick="openTool('reading')">
                        <div class="icon-wrap">
                            <svg viewBox="0 0 64 64" xmlns="http://www.w3.org/2000/svg">
                                <defs><linearGradient id="bookGrad" x1="0%" y1="0%" x2="100%" y2="100%"><stop offset="0%" stop-color="#3498db"/><stop offset="100%" stop-color="#2980b9"/></linearGradient></defs>
                                <path d="M12 10 L12 54 L32 48 L52 54 L52 10 L32 16 Z" fill="url(#bookGrad)"/>
                                <line x1="32" y1="16" x2="32" y2="48" stroke="#2980b9" stroke-width="2"/>
                                <line x1="20" y1="22" x2="28" y2="22" stroke="rgba(255,255,255,0.5)" stroke-width="1.5"/>
                                <line x1="20" y1="28" x2="28" y2="28" stroke="rgba(255,255,255,0.4)" stroke-width="1.5"/>
                                <line x1="36" y1="22" x2="44" y2="22" stroke="rgba(255,255,255,0.5)" stroke-width="1.5"/>
                                <line x1="36" y1="28" x2="44" y2="28" stroke="rgba(255,255,255,0.4)" stroke-width="1.5"/>
                            </svg>
                        </div>
                        <div class="tool-name"><?= t('tools.name_reading') ?></div>
                    </div>
                    <div class="tool-card" onclick="openTool('sitemap')">
                        <div class="icon-wrap">
                            <svg viewBox="0 0 64 64" xmlns="http://www.w3.org/2000/svg">
                                <circle cx="32" cy="8" r="4" fill="none" stroke="#74b9ff" stroke-width="2.5"/>
                                <circle cx="16" cy="28" r="4" fill="none" stroke="#74b9ff" stroke-width="2.5"/>
                                <circle cx="48" cy="28" r="4" fill="none" stroke="#74b9ff" stroke-width="2.5"/>
                                <circle cx="10" cy="48" r="4" fill="none" stroke="#74b9ff" stroke-width="2.5"/>
                                <circle cx="24" cy="48" r="4" fill="none" stroke="#74b9ff" stroke-width="2.5"/>
                                <line x1="32" y1="12" x2="16" y2="24" stroke="#74b9ff" stroke-width="1.5"/>
                                <line x1="32" y1="12" x2="48" y2="24" stroke="#74b9ff" stroke-width="1.5"/>
                                <line x1="16" y1="32" x2="10" y2="44" stroke="#74b9ff" stroke-width="1.5"/>
                                <line x1="16" y1="32" x2="24" y2="44" stroke="#74b9ff" stroke-width="1.5"/>
                            </svg>
                        </div>
                        <div class="tool-name"><?= t('tools.name_sitemap') ?></div>
                    </div>
                </div>
            </div>
        </div>
    </main>

    <div id="modal-container"></div>
    <div class="eye-protection-overlay" id="eyeOverlay"></div>

    <?php /* 浮动件已收敛：本页原先有两个独立的右下角浮动按钮 ——
             ① #postFab（发帖，内联 display:none，实际从未显示的死代码）
             ② .back-to-top（回顶，站内第三个回顶入口）
             现在统一由 polish.js 的 .lw-fab 提供（回到顶部 / 随便看看 / 字号 / AI 小助手）。
             发帖入口保留在顶栏与移动端底部导航中间键。 */ ?>

    <?php
    $mobileNavActive = 'me';
    require __DIR__ . '/../includes/mobile_bottom_nav.php';
    ?>

    <footer class="site-footer">
        <div class="container">
            <p><?= t('footer.disclaimer') ?></p>
            <p>&copy; 2026 <?= htmlspecialchars($siteName) ?> · <?= t('tools.copyright') ?></p>
            <p><?= t('footer.open_source') ?><a href="<?= htmlspecialchars(GITHUB_REPO_URL) ?>" target="_blank" rel="noopener noreferrer"><?= htmlspecialchars(GITHUB_REPO_NAME) ?></a></p>
        </div>
    </footer>

    <script>
    let activeModal = null;

    // 延迟翻译助手：脚本在 lang_ui.php（</body> 前）之前执行，window.__t 尚未定义，
    // 因此工具名称等需要在渲染（点击）时才取翻译。
    function _t(k) { return (typeof window.__t === 'function') ? window.__t(k) : k; }

    function openTool(name) {
        if (activeModal) closeModal();
        const container = document.getElementById('modal-container');
        const content = (typeof toolContents[name] === 'function') ? toolContents[name]() : (toolContents[name] || '');
        const iconSvg = (toolIcons[name] || '').replace('SCRATCH_HINT_TOKEN', __t('tools.scratch_hint'));
        container.innerHTML = `
            <div class="tool-modal-overlay" onclick="closeModal()">
                <div class="tool-modal" onclick="event.stopPropagation()">
                    <div class="tool-modal-header">
                        <h2>${iconSvg} ${_t(toolNames[name]) || name}</h2>
                        <button class="tool-modal-close" onclick="closeModal()">&times;</button>
                    </div>
                    <div class="tool-modal-body">${content}</div>
                </div>
            </div>
        `;
        activeModal = name;
        document.body.style.overflow = 'hidden';

        if (typeof initTool === 'function') initTool(name);
    }

    function closeModal() {
        if (!activeModal) return;
        clearInterval(pomodoroTimer);
        clearInterval(cdTimer);
        clearInterval(swTimer);
        clearInterval(clockInterval);
        pomodoroTimer = cdTimer = swTimer = clockInterval = null;
        if (window.speechSynthesis) window.speechSynthesis.cancel();
        if (activeModal === 'wheel') { localStorage.removeItem('tools_wheel_items'); }
        const overlay = document.querySelector('.tool-modal-overlay');
        const modal = document.querySelector('.tool-modal');
        if (overlay) overlay.classList.add('closing');
        if (modal) modal.classList.add('closing');
        setTimeout(() => {
            document.getElementById('modal-container').innerHTML = '';
            activeModal = null;
            document.body.style.overflow = '';
        }, 180);
    }

    function showToast(msg) {
        const t = document.createElement('div');
        t.className = 't-toast';
        t.textContent = msg;
        document.body.appendChild(t);
        setTimeout(() => t.remove(), 2200);
    }

    const toolNames = {
        pomodoro: 'tools.name_tomato', countdown: 'tools.name_countdown', stopwatch: 'tools.name_stopwatch', clock: 'tools.name_clock',
        todo: 'tools.name_todo', sticky: 'tools.name_sticky', wordcount: 'tools.name_wordcount', search: 'tools.name_search',
        calculator: 'tools.name_calculator', converter: 'tools.name_converter', qrcode: 'tools.name_qrcode', jsonfmt: 'tools.name_jsonfmt', base64: 'tools.name_base64',
        colorpicker: 'tools.name_colorpicker', gradient: 'tools.name_gradient', password: 'tools.name_password',
        dice: 'tools.name_dice', coin: 'tools.name_coin', randompick: 'tools.name_randompick', fortune: 'tools.name_fortune', wheel: 'tools.name_wheel', scratch: 'tools.name_scratch',
        weather: 'tools.name_weather', ipquery: 'tools.name_ipquery', quote: 'tools.name_quote', featurevote: 'tools.name_featurevote',
        photowall: 'tools.name_photowall', tts: 'tools.name_tts', screenshot: 'tools.name_screenshot',
        vote: 'tools.name_vote', badges: 'tools.name_badges', eye: 'tools.name_eye', reading: 'tools.name_reading', sitemap: 'tools.name_sitemap'
    };

    const toolIcons = {
        pomodoro: '<svg viewBox="0 0 64 64" width="24" height="24"><defs><linearGradient id="tic1" x1="0%" y1="0%" x2="100%" y2="100%"><stop offset="0%" stop-color="#ff6b6b"/><stop offset="100%" stop-color="#c0392b"/></linearGradient></defs><ellipse cx="32" cy="38" rx="22" ry="20" fill="url(#tic1)"/><ellipse cx="32" cy="14" rx="10" ry="6" fill="#27ae60"/><path d="M22 18 Q32 22 42 18" fill="none" stroke="#27ae60" stroke-width="2.5"/><line x1="32" y1="38" x2="32" y2="26" stroke="#fff" stroke-width="2.5"/><line x1="32" y1="38" x2="42" y2="38" stroke="#fff" stroke-width="2"/></svg>',
        countdown: '<svg viewBox="0 0 64 64" width="24" height="24"><defs><linearGradient id="tic2" x1="0%" y1="0%" x2="0%" y2="100%"><stop offset="0%" stop-color="#f9ca24"/><stop offset="100%" stop-color="#e1b12c"/></linearGradient></defs><rect x="22" y="4" width="20" height="6" rx="2" fill="#f9ca24"/><rect x="22" y="54" width="20" height="6" rx="2" fill="#f9ca24"/><polygon points="26,10 38,10 44,32 38,54 26,54 20,32" fill="url(#tic2)"/><polygon points="26,10 38,10 32,32" fill="#f6e58d" opacity="0.7"/><circle cx="32" cy="32" r="3" fill="#d35400"/></svg>',
        stopwatch: '<svg viewBox="0 0 64 64" width="24" height="24"><defs><linearGradient id="tic3" x1="0%" y1="0%" x2="100%" y2="100%"><stop offset="0%" stop-color="#3498db"/><stop offset="100%" stop-color="#2980b9"/></linearGradient></defs><circle cx="32" cy="36" r="22" fill="url(#tic3)"/><circle cx="32" cy="14" r="4" fill="#3498db"/><line x1="32" y1="36" x2="32" y2="24" stroke="#fff" stroke-width="3"/><line x1="32" y1="36" x2="44" y2="36" stroke="#fff" stroke-width="2.5"/></svg>',
        clock: '<svg viewBox="0 0 64 64" width="24" height="24"><defs><linearGradient id="tic4" x1="0%" y1="0%" x2="100%" y2="100%"><stop offset="0%" stop-color="#a29bfe"/><stop offset="100%" stop-color="#6c5ce7"/></linearGradient></defs><circle cx="32" cy="32" r="26" fill="url(#tic4)"/><line x1="32" y1="32" x2="32" y2="18" stroke="#fff" stroke-width="3"/><line x1="32" y1="32" x2="42" y2="32" stroke="#fff" stroke-width="2"/></svg>',
        todo: '<svg viewBox="0 0 64 64" width="24" height="24"><defs><linearGradient id="tic5" x1="0%" y1="0%" x2="100%" y2="100%"><stop offset="0%" stop-color="#2ecc71"/><stop offset="100%" stop-color="#27ae60"/></linearGradient></defs><rect x="8" y="6" width="48" height="52" rx="6" fill="url(#tic5)"/><rect x="14" y="14" width="36" height="6" rx="2" fill="rgba(255,255,255,0.3)"/><rect x="14" y="26" width="36" height="6" rx="2" fill="rgba(255,255,255,0.2)"/><rect x="14" y="38" width="36" height="6" rx="2" fill="rgba(255,255,255,0.2)"/><circle cx="20" cy="17" r="4" fill="none" stroke="#fff" stroke-width="2"/><polyline points="17,17 19,20 23,14" fill="none" stroke="#fff" stroke-width="2"/></svg>',
        sticky: '<svg viewBox="0 0 64 64" width="24" height="24"><defs><linearGradient id="tic6" x1="0%" y1="0%" x2="100%" y2="100%"><stop offset="0%" stop-color="#f9ca24"/><stop offset="100%" stop-color="#f0932b"/></linearGradient></defs><rect x="12" y="8" width="40" height="44" rx="3" fill="url(#tic6)"/><polygon points="52,8 52,20 40,20" fill="#f6e58d" opacity="0.5"/><line x1="18" y1="18" x2="42" y2="18" stroke="rgba(0,0,0,0.15)" stroke-width="2"/><line x1="18" y1="26" x2="42" y2="26" stroke="rgba(0,0,0,0.1)" stroke-width="2"/></svg>',
        wordcount: '<svg viewBox="0 0 64 64" width="24" height="24"><defs><linearGradient id="tic7" x1="0%" y1="0%" x2="100%" y2="100%"><stop offset="0%" stop-color="#a29bfe"/><stop offset="100%" stop-color="#6c5ce7"/></linearGradient></defs><rect x="8" y="10" width="48" height="44" rx="6" fill="url(#tic7)"/><line x1="18" y1="20" x2="46" y2="20" stroke="rgba(255,255,255,0.5)" stroke-width="2"/><line x1="18" y1="28" x2="42" y2="28" stroke="rgba(255,255,255,0.4)" stroke-width="2"/><line x1="18" y1="36" x2="40" y2="36" stroke="rgba(255,255,255,0.3)" stroke-width="2"/></svg>',
        search: '<svg viewBox="0 0 64 64" width="24" height="24"><circle cx="28" cy="28" r="18" fill="none" stroke="#74b9ff" stroke-width="4"/><line x1="42" y1="42" x2="54" y2="54" stroke="#74b9ff" stroke-width="5" stroke-linecap="round"/></svg>',
        calculator: '<svg viewBox="0 0 64 64" width="24" height="24"><defs><linearGradient id="tic8" x1="0%" y1="0%" x2="100%" y2="100%"><stop offset="0%" stop-color="#3498db"/><stop offset="100%" stop-color="#2c3e50"/></linearGradient></defs><rect x="8" y="6" width="48" height="52" rx="8" fill="url(#tic8)"/><rect x="14" y="12" width="36" height="12" rx="4" fill="rgba(0,0,0,0.3)"/><rect x="14" y="28" width="8" height="6" rx="2" fill="rgba(255,255,255,0.15)"/><rect x="24" y="28" width="8" height="6" rx="2" fill="rgba(255,255,255,0.15)"/><rect x="34" y="28" width="8" height="6" rx="2" fill="rgba(255,255,255,0.15)"/><rect x="44" y="28" width="8" height="6" rx="2" fill="rgba(231,76,60,0.5)"/></svg>',
        converter: '<svg viewBox="0 0 64 64" width="24" height="24"><defs><linearGradient id="tic9" x1="0%" y1="0%" x2="100%" y2="100%"><stop offset="0%" stop-color="#00b894"/><stop offset="100%" stop-color="#00cec9"/></linearGradient></defs><rect x="8" y="20" width="48" height="6" rx="3" fill="url(#tic9)"/><line x1="12" y1="20" x2="12" y2="28" stroke="#fff" stroke-width="1"/><line x1="24" y1="20" x2="24" y2="28" stroke="#fff" stroke-width="1"/><line x1="36" y1="20" x2="36" y2="28" stroke="#fff" stroke-width="1"/><line x1="48" y1="20" x2="48" y2="28" stroke="#fff" stroke-width="1"/><circle cx="32" cy="38" r="6" fill="none" stroke="#00b894" stroke-width="2"/><line x1="32" y1="32" x2="32" y2="14" stroke="#00b894" stroke-width="2"/></svg>',
        qrcode: '<svg viewBox="0 0 64 64" width="24" height="24"><rect x="8" y="8" width="18" height="18" rx="2" fill="#2d3436"/><rect x="12" y="12" width="10" height="10" rx="1" fill="#fff"/><rect x="16" y="16" width="2" height="2" fill="#2d3436"/><rect x="38" y="8" width="18" height="18" rx="2" fill="#2d3436"/><rect x="42" y="12" width="10" height="10" rx="1" fill="#fff"/><rect x="46" y="16" width="2" height="2" fill="#2d3436"/><rect x="8" y="38" width="18" height="18" rx="2" fill="#2d3436"/><rect x="12" y="42" width="10" height="10" rx="1" fill="#fff"/><rect x="16" y="46" width="2" height="2" fill="#2d3436"/><rect x="36" y="36" width="4" height="4" fill="#2d3436"/><rect x="44" y="36" width="4" height="4" fill="#2d3436"/><rect x="36" y="44" width="4" height="4" fill="#2d3436"/><rect x="48" y="36" width="2" height="2" fill="#2d3436"/><rect x="52" y="40" width="4" height="4" fill="#2d3436"/><rect x="52" y="48" width="4" height="4" fill="#2d3436"/><rect x="36" y="52" width="4" height="4" fill="#2d3436"/><rect x="48" y="52" width="2" height="2" fill="#2d3436"/></svg>',
        jsonfmt: '<svg viewBox="0 0 64 64" width="24" height="24"><defs><linearGradient id="tic10" x1="0%" y1="0%" x2="100%" y2="100%"><stop offset="0%" stop-color="#e17055"/><stop offset="100%" stop-color="#d63031"/></linearGradient></defs><text x="10" y="22" font-size="20" font-weight="bold" fill="url(#tic10)" font-family="monospace">{</text><text x="10" y="40" font-size="20" font-weight="bold" fill="url(#tic10)" font-family="monospace">}</text><text x="22" y="22" font-size="12" fill="#fab1a0" font-family="monospace">"key"</text><text x="22" y="38" font-size="12" fill="#74b9ff" font-family="monospace">value</text></svg>',
        base64: '<svg viewBox="0 0 64 64" width="24" height="24"><defs><linearGradient id="tic11" x1="0%" y1="0%" x2="100%" y2="100%"><stop offset="0%" stop-color="#636e72"/><stop offset="100%" stop-color="#2d3436"/></linearGradient></defs><rect x="6" y="10" width="52" height="44" rx="6" fill="url(#tic11)"/><text x="14" y="26" font-size="10" fill="#dfe6e9" font-family="monospace" font-weight="bold">btoa</text><text x="14" y="46" font-size="10" fill="#74b9ff" font-family="monospace" font-weight="bold">atob</text><line x1="14" y1="32" x2="50" y2="32" stroke="rgba(255,255,255,0.15)" stroke-width="1"/></svg>',
        colorpicker: '<svg viewBox="0 0 64 64" width="24" height="24"><circle cx="32" cy="28" r="6" fill="#e74c3c"/><circle cx="22" cy="20" r="4" fill="#f39c12"/><circle cx="42" cy="20" r="4" fill="#2ecc71"/><circle cx="18" cy="34" r="4" fill="#3498db"/><circle cx="46" cy="34" r="4" fill="#9b59b6"/><circle cx="32" cy="48" r="6" fill="#e74c3c"/><circle cx="22" cy="44" r="4" fill="#f1c40f"/><circle cx="42" cy="44" r="4" fill="#1abc9c"/></svg>',
        gradient: '<svg viewBox="0 0 64 64" width="24" height="24"><defs><linearGradient id="tic12" x1="0%" y1="0%" x2="0%" y2="100%"><stop offset="0%" stop-color="#e74c3c"/><stop offset="20%" stop-color="#f39c12"/><stop offset="40%" stop-color="#2ecc71"/><stop offset="60%" stop-color="#3498db"/><stop offset="80%" stop-color="#9b59b6"/><stop offset="100%" stop-color="#fd79a8"/></linearGradient></defs><rect x="16" y="8" width="32" height="48" rx="8" fill="url(#tic12)"/></svg>',
        password: '<svg viewBox="0 0 64 64" width="24" height="24"><defs><linearGradient id="tic13" x1="0%" y1="0%" x2="100%" y2="100%"><stop offset="0%" stop-color="#f9ca24"/><stop offset="100%" stop-color="#e1b12c"/></linearGradient></defs><circle cx="24" cy="30" r="12" fill="none" stroke="url(#tic13)" stroke-width="4"/><circle cx="24" cy="30" r="4" fill="url(#tic13)"/><line x1="34" y1="30" x2="56" y2="30" stroke="url(#tic13)" stroke-width="4"/><line x1="48" y1="30" x2="48" y2="22" stroke="url(#tic13)" stroke-width="3"/><line x1="54" y1="30" x2="54" y2="22" stroke="url(#tic13)" stroke-width="3"/></svg>',
        dice: '<svg viewBox="0 0 64 64" width="24" height="24"><defs><linearGradient id="tic14" x1="0%" y1="0%" x2="100%" y2="100%"><stop offset="0%" stop-color="#ff6b6b"/><stop offset="100%" stop-color="#c0392b"/></linearGradient></defs><rect x="8" y="8" width="48" height="48" rx="8" fill="url(#tic14)"/><circle cx="24" cy="24" r="3" fill="#fff"/><circle cx="40" cy="40" r="3" fill="#fff"/><circle cx="32" cy="32" r="3" fill="#fff"/><circle cx="24" cy="40" r="3" fill="#fff"/><circle cx="40" cy="24" r="3" fill="#fff"/></svg>',
        coin: '<svg viewBox="0 0 64 64" width="24" height="24"><defs><linearGradient id="tic15" x1="0%" y1="0%" x2="100%" y2="100%"><stop offset="0%" stop-color="#f9ca24"/><stop offset="100%" stop-color="#e1b12c"/></linearGradient></defs><ellipse cx="32" cy="44" rx="24" ry="6" fill="#d4a017"/><rect x="8" y="14" width="48" height="30" rx="24" fill="url(#tic15)"/><ellipse cx="32" cy="14" rx="24" ry="6" fill="#f6e58d"/><text x="32" y="36" text-anchor="middle" font-size="16" font-weight="bold" fill="#d4a017">$</text></svg>',
        randompick: '<svg viewBox="0 0 64 64" width="24" height="24"><defs><linearGradient id="tic16" x1="0%" y1="0%" x2="100%" y2="100%"><stop offset="0%" stop-color="#a29bfe"/><stop offset="100%" stop-color="#6c5ce7"/></linearGradient></defs><polyline points="12,16 52,16 52,26" fill="none" stroke="url(#tic16)" stroke-width="3"/><polyline points="52,38 12,38 12,48" fill="none" stroke="url(#tic16)" stroke-width="3"/><polyline points="44,10 52,16 44,22" fill="none" stroke="url(#tic16)" stroke-width="3"/><polyline points="20,42 12,48 20,54" fill="none" stroke="url(#tic16)" stroke-width="3"/></svg>',
        fortune: '<svg viewBox="0 0 64 64" width="24" height="24"><defs><linearGradient id="tic17" x1="0%" y1="0%" x2="100%" y2="100%"><stop offset="0%" stop-color="#e17055"/><stop offset="100%" stop-color="#d63031"/></linearGradient></defs><path d="M16 40 Q16 12 32 12 Q48 12 48 40 L48 52 Q32 48 16 52 Z" fill="url(#tic17)"/><path d="M16 40 Q32 36 48 40" fill="none" stroke="#e17055" stroke-width="3"/></svg>',
        wheel: '<svg viewBox="0 0 64 64" width="24" height="24"><circle cx="32" cy="32" r="24" fill="none" stroke="#e74c3c" stroke-width="3" stroke-dasharray="38 38"/><circle cx="32" cy="32" r="24" fill="none" stroke="#f39c12" stroke-width="3" stroke-dasharray="38 38" transform="rotate(60,32,32)"/><circle cx="32" cy="32" r="24" fill="none" stroke="#2ecc71" stroke-width="3" stroke-dasharray="38 38" transform="rotate(120,32,32)"/><circle cx="32" cy="32" r="24" fill="none" stroke="#3498db" stroke-width="3" stroke-dasharray="38 38" transform="rotate(180,32,32)"/><circle cx="32" cy="32" r="24" fill="none" stroke="#9b59b6" stroke-width="3" stroke-dasharray="38 38" transform="rotate(240,32,32)"/><circle cx="32" cy="32" r="4" fill="#fff"/></svg>',
        scratch: '<svg viewBox="0 0 64 64" width="24" height="24"><defs><linearGradient id="tic18" x1="0%" y1="0%" x2="100%" y2="100%"><stop offset="0%" stop-color="#b2bec3"/><stop offset="100%" stop-color="#636e72"/></linearGradient></defs><rect x="8" y="8" width="48" height="48" rx="8" fill="url(#tic18)"/><rect x="16" y="16" width="32" height="32" rx="4" fill="rgba(255,255,255,0.15)"/><text x="32" y="38" text-anchor="middle" font-size="14" fill="rgba(255,255,255,0.5)" font-weight="bold">SCRATCH_HINT_TOKEN</text></svg>',
        weather: '<svg viewBox="0 0 64 64" width="24" height="24"><defs><linearGradient id="tic19" x1="0%" y1="0%" x2="100%" y2="100%"><stop offset="0%" stop-color="#74b9ff"/><stop offset="100%" stop-color="#0984e3"/></linearGradient></defs><circle cx="28" cy="24" r="14" fill="#fdcb6e"/><path d="M14 42 Q14 34 20 32 Q28 26 38 28 Q46 34 50 38 Q52 42 50 46 Q48 50 44 50 L20 50 Q14 50 14 46 Z" fill="url(#tic19)"/></svg>',
        ipquery: '<svg viewBox="0 0 64 64" width="24" height="24"><defs><linearGradient id="tic20" x1="0%" y1="0%" x2="100%" y2="100%"><stop offset="0%" stop-color="#3498db"/><stop offset="100%" stop-color="#2c3e50"/></linearGradient></defs><circle cx="32" cy="32" r="24" fill="none" stroke="url(#tic20)" stroke-width="3"/><ellipse cx="32" cy="32" rx="14" ry="24" fill="none" stroke="url(#tic20)" stroke-width="2"/><line x1="8" y1="32" x2="56" y2="32" stroke="url(#tic20)" stroke-width="2"/></svg>',
        quote: '<svg viewBox="0 0 64 64" width="24" height="24"><defs><linearGradient id="tic21" x1="0%" y1="0%" x2="100%" y2="100%"><stop offset="0%" stop-color="#fd79a8"/><stop offset="100%" stop-color="#e84393"/></linearGradient></defs><rect x="12" y="10" width="40" height="44" rx="20" fill="url(#tic21)"/><polygon points="44,36 44,48 52,44" fill="#fff" opacity="0.8"/><line x1="24" y1="22" x2="40" y2="22" stroke="rgba(255,255,255,0.5)" stroke-width="2"/><line x1="24" y1="30" x2="38" y2="30" stroke="rgba(255,255,255,0.4)" stroke-width="2"/></svg>',
        featurevote: '<svg viewBox="0 0 64 64" width="24" height="24"><defs><linearGradient id="ticfv" x1="0%" y1="0%" x2="100%" y2="100%"><stop offset="0%" stop-color="#fbc531"/><stop offset="100%" stop-color="#e84118"/></linearGradient></defs><circle cx="20" cy="22" r="9" fill="url(#ticfv)"/><path d="M12 34 h16 v18 H12 z" fill="url(#ticfv)"/><path d="M44 12 l-7 12 h7 l-8 14 h16 l-6-14 h8 z" fill="#ffd32a"/></svg>',
        photowall: '<svg viewBox="0 0 64 64" width="24" height="24"><defs><linearGradient id="tic23" x1="0%" y1="0%" x2="100%" y2="100%"><stop offset="0%" stop-color="#00cec9"/><stop offset="100%" stop-color="#00b894"/></linearGradient></defs><rect x="6" y="8" width="24" height="24" rx="4" fill="url(#tic23)"/><circle cx="16" cy="18" r="4" fill="rgba(255,255,255,0.3)"/><polygon points="6,32 16,22 20,26 26,18 30,32" fill="rgba(255,255,255,0.2)"/><rect x="34" y="8" width="24" height="24" rx="4" fill="url(#tic23)"/><rect x="6" y="36" width="24" height="24" rx="4" fill="url(#tic23)"/><rect x="34" y="36" width="24" height="24" rx="4" fill="url(#tic23)"/></svg>',
        tts: '<svg viewBox="0 0 64 64" width="24" height="24"><defs><linearGradient id="tic24" x1="0%" y1="0%" x2="100%" y2="100%"><stop offset="0%" stop-color="#3498db"/><stop offset="100%" stop-color="#2980b9"/></linearGradient></defs><rect x="10" y="20" width="16" height="24" rx="4" fill="url(#tic24)"/><polygon points="26,26 44,16 44,48 26,38" fill="url(#tic24)"/><path d="M48 22 Q54 32 48 42" fill="none" stroke="#3498db" stroke-width="3"/></svg>',
        screenshot: '<svg viewBox="0 0 64 64" width="24" height="24"><defs><linearGradient id="tic25" x1="0%" y1="0%" x2="100%" y2="100%"><stop offset="0%" stop-color="#2ecc71"/><stop offset="100%" stop-color="#27ae60"/></linearGradient></defs><rect x="8" y="16" width="48" height="32" rx="6" fill="url(#tic25)"/><circle cx="32" cy="32" r="10" fill="none" stroke="#fff" stroke-width="3"/><circle cx="32" cy="32" r="4" fill="#fff"/><rect x="24" y="10" width="16" height="8" rx="3" fill="url(#tic25)"/></svg>',
        vote: '<svg viewBox="0 0 64 64" width="24" height="24"><rect x="10" y="8" width="44" height="48" rx="6" fill="none" stroke="#74b9ff" stroke-width="3"/><line x1="22" y1="18" x2="42" y2="18" stroke="#74b9ff" stroke-width="2"/><line x1="22" y1="28" x2="42" y2="28" stroke="#74b9ff" stroke-width="2"/><line x1="22" y1="38" x2="42" y2="38" stroke="#74b9ff" stroke-width="2"/><circle cx="18" cy="18" r="3" fill="none" stroke="#74b9ff" stroke-width="2"/><circle cx="18" cy="28" r="3" fill="#74b9ff"/><polyline points="15,28 17,31 21,25" fill="none" stroke="#fff" stroke-width="2"/></svg>',
        badges: '<svg viewBox="0 0 64 64" width="24" height="24"><defs><linearGradient id="tic26" x1="0%" y1="0%" x2="100%" y2="100%"><stop offset="0%" stop-color="#f9ca24"/><stop offset="100%" stop-color="#e1b12c"/></linearGradient></defs><path d="M20 12 L24 36 L40 36 L44 12 Z" fill="url(#tic26)"/><rect x="28" y="36" width="8" height="8" fill="url(#tic26)"/><rect x="22" y="44" width="20" height="4" rx="1" fill="url(#tic26)"/><polygon points="32,18 34,22 38,22 35,25 36,29 32,26 28,29 29,25 26,22 30,22" fill="#fff" opacity="0.8"/></svg>',
        eye: '<svg viewBox="0 0 64 64" width="24" height="24"><defs><linearGradient id="tic27" x1="0%" y1="0%" x2="100%" y2="100%"><stop offset="0%" stop-color="#2ecc71"/><stop offset="100%" stop-color="#27ae60"/></linearGradient></defs><ellipse cx="32" cy="32" rx="22" ry="14" fill="none" stroke="url(#tic27)" stroke-width="3"/><circle cx="32" cy="32" r="6" fill="url(#tic27)"/><circle cx="32" cy="32" r="3" fill="#1e1e32"/></svg>',
        reading: '<svg viewBox="0 0 64 64" width="24" height="24"><defs><linearGradient id="tic28" x1="0%" y1="0%" x2="100%" y2="100%"><stop offset="0%" stop-color="#3498db"/><stop offset="100%" stop-color="#2980b9"/></linearGradient></defs><path d="M12 10 L12 54 L32 48 L52 54 L52 10 L32 16 Z" fill="url(#tic28)"/><line x1="32" y1="16" x2="32" y2="48" stroke="#2980b9" stroke-width="2"/><line x1="20" y1="22" x2="28" y2="22" stroke="rgba(255,255,255,0.5)" stroke-width="1.5"/><line x1="20" y1="28" x2="28" y2="28" stroke="rgba(255,255,255,0.4)" stroke-width="1.5"/></svg>',
        sitemap: '<svg viewBox="0 0 64 64" width="24" height="24"><circle cx="32" cy="8" r="4" fill="none" stroke="#74b9ff" stroke-width="2.5"/><circle cx="16" cy="28" r="4" fill="none" stroke="#74b9ff" stroke-width="2.5"/><circle cx="48" cy="28" r="4" fill="none" stroke="#74b9ff" stroke-width="2.5"/><circle cx="10" cy="48" r="4" fill="none" stroke="#74b9ff" stroke-width="2.5"/><circle cx="24" cy="48" r="4" fill="none" stroke="#74b9ff" stroke-width="2.5"/><line x1="32" y1="12" x2="16" y2="24" stroke="#74b9ff" stroke-width="1.5"/><line x1="32" y1="12" x2="48" y2="24" stroke="#74b9ff" stroke-width="1.5"/><line x1="16" y1="32" x2="10" y2="44" stroke="#74b9ff" stroke-width="1.5"/><line x1="16" y1="32" x2="24" y2="44" stroke="#74b9ff" stroke-width="1.5"/></svg>'
    };

    const toolContents = {
        pomodoro: function(){ return `
            <div class="timer-display" id="pomodoro-display">25:00</div>
            <div class="timer-label" id="pomodoro-label">${__t('tools.pomodoro_work_time')}</div>
            <div class="t-btn-row" style="justify-content:center">
                <button class="t-btn t-btn-primary" id="pomodoro-start" onclick="pomodoroStart()">${__t('tools.start')}</button>
                <button class="t-btn t-btn-secondary" id="pomodoro-pause" onclick="pomodoroPause()" style="display:none">${__t('tools.pause')}</button>
                <button class="t-btn t-btn-danger" onclick="pomodoroReset()">${__t('tools.reset')}</button>
            </div>
            <div class="presets" style="justify-content:center;margin-top:0.875rem">
                <span class="preset active" onclick="pomodoroSetMode('work')">${__t('tools.pomodoro_work_25')}</span>
                <span class="preset" onclick="pomodoroSetMode('break')">${__t('tools.pomodoro_break_5')}</span>
            </div>
        `; },
        countdown: function(){ return `
            <div class="time-input-row">
                <input type="number" class="t-input" id="cd-h" value="0" min="0" max="99" placeholder="${__t('tools.hour')}">
                <span>:</span>
                <input type="number" class="t-input" id="cd-m" value="1" min="0" max="59" placeholder="${__t('tools.minute')}">
                <span>:</span>
                <input type="number" class="t-input" id="cd-s" value="0" min="0" max="59" placeholder="${__t('tools.second')}">
            </div>
            <div class="timer-display" id="cd-display">01:00</div>
            <div class="t-btn-row" style="justify-content:center">
                <button class="t-btn t-btn-primary" id="cd-start" onclick="countdownStart()">${__t('tools.start')}</button>
                <button class="t-btn t-btn-secondary" id="cd-pause" onclick="countdownPause()" style="display:none">${__t('tools.pause')}</button>
                <button class="t-btn t-btn-danger" onclick="countdownReset()">${__t('tools.reset')}</button>
            </div>
            <div class="presets" style="justify-content:center;margin-top:0.875rem">
                <span class="preset" onclick="countdownPreset(60)">${__t('tools.preset_1min')}</span>
                <span class="preset" onclick="countdownPreset(180)">${__t('tools.preset_3min')}</span>
                <span class="preset" onclick="countdownPreset(300)">${__t('tools.preset_5min')}</span>
                <span class="preset" onclick="countdownPreset(600)">${__t('tools.preset_10min')}</span>
            </div>
        `; },
        stopwatch: function(){ return `
            <div class="timer-display" id="sw-display">00:00.00</div>
            <div class="t-btn-row" style="justify-content:center">
                <button class="t-btn t-btn-primary" id="sw-start" onclick="stopwatchStart()">${__t('tools.start')}</button>
                <button class="t-btn t-btn-secondary" id="sw-pause" onclick="stopwatchPause()" style="display:none">${__t('tools.pause')}</button>
                <button class="t-btn t-btn-danger" id="sw-reset" onclick="stopwatchReset()">${__t('tools.reset')}</button>
                <button class="t-btn t-btn-secondary" onclick="stopwatchLap()">${__t('tools.lap')}</button>
            </div>
            <ul class="lap-list" id="sw-laps"></ul>
        `; },
        clock: function(){ return `
            <canvas class="analog-clock" id="analog-clock" width="180" height="180"></canvas>
            <div class="digital-clock" id="digital-clock"></div>
        `; },
        todo: function(){ return `
            <div class="todo-input-row">
                <input type="text" class="t-input" id="todo-input" placeholder="${__t('tools.todo_ph')}" onkeydown="if(event.key==='Enter')todoAdd()">
                <button class="t-btn t-btn-primary" onclick="todoAdd()">${__t('tools.add')}</button>
            </div>
            <ul class="todo-list" id="todo-list"></ul>
        `; },
        sticky: function(){ return `
            <div class="note-colors" id="note-colors">
                <span class="note-color active" style="background:#f9ca24" data-color="#f9ca24" onclick="stickySetColor(this)"></span>
                <span class="note-color" style="background:#ff6b6b" data-color="#ff6b6b" onclick="stickySetColor(this)"></span>
                <span class="note-color" style="background:#74b9ff" data-color="#74b9ff" onclick="stickySetColor(this)"></span>
                <span class="note-color" style="background:#2ecc71" data-color="#2ecc71" onclick="stickySetColor(this)"></span>
                <span class="note-color" style="background:#a29bfe" data-color="#a29bfe" onclick="stickySetColor(this)"></span>
            </div>
            <div class="todo-input-row">
                <textarea class="t-textarea" id="sticky-input" rows="2" placeholder="${__t('tools.sticky_ph')}"></textarea>
                <button class="t-btn t-btn-primary" onclick="stickyAdd()">${__t('tools.add')}</button>
            </div>
            <div class="sticky-notes" id="sticky-notes"></div>
        `; },
        wordcount: function(){ return `
            <textarea class="t-textarea" id="wc-input" rows="6" placeholder="${__t('tools.input_text_ph')}" oninput="wordCount()"></textarea>
            <div class="t-form-group" style="margin-top:0.625rem">
                <p style="font-size:0.9rem;color:var(--text)">${__t('tools.word_count')}：<strong id="wc-chars">0</strong> | ${__t('tools.char_count')}：<strong id="wc-bytes">0</strong> | ${__t('tools.line_count')}：<strong id="wc-lines">0</strong></p>
            </div>
        `; },
        search: function(){ return `
            <div class="todo-input-row">
                <input type="text" class="t-input" id="search-query" placeholder="${__t('tools.search_ph')}">
                <button class="t-btn t-btn-primary" onclick="quickSearch()">${__t('tools.search_btn')}</button>
            </div>
            <select class="t-select" id="search-engine" style="margin-top:0.5rem">
                <option value="https://www.baidu.com/s?wd=">${__t('tools.baidu')}</option>
                <option value="https://www.google.com/search?q=">Google</option>
                <option value="https://cn.bing.com/search?q=">Bing</option>
            </select>
            <ul class="search-results" id="search-results" style="display:none"></ul>
        `; },
        calculator: function(){ return `
            <div class="calc-display" id="calc-display">0</div>
            <div class="calc-grid">
                <button class="calc-btn clear" onclick="calcClear()">C</button>
                <button class="calc-btn" onclick="calcInput('(')">(</button>
                <button class="calc-btn" onclick="calcInput(')')">)</button>
                <button class="calc-btn operator" onclick="calcInput('/')">/</button>
                <button class="calc-btn" onclick="calcInput('7')">7</button>
                <button class="calc-btn" onclick="calcInput('8')">8</button>
                <button class="calc-btn" onclick="calcInput('9')">9</button>
                <button class="calc-btn operator" onclick="calcInput('*')">*</button>
                <button class="calc-btn" onclick="calcInput('4')">4</button>
                <button class="calc-btn" onclick="calcInput('5')">5</button>
                <button class="calc-btn" onclick="calcInput('6')">6</button>
                <button class="calc-btn operator" onclick="calcInput('-')">-</button>
                <button class="calc-btn" onclick="calcInput('1')">1</button>
                <button class="calc-btn" onclick="calcInput('2')">2</button>
                <button class="calc-btn" onclick="calcInput('3')">3</button>
                <button class="calc-btn operator" onclick="calcInput('+')">+</button>
                <button class="calc-btn span2" onclick="calcInput('0')">0</button>
                <button class="calc-btn" onclick="calcInput('.')">.</button>
                <button class="calc-btn equals" onclick="calcEval()">=</button>
            </div>
        `; },
        converter: function(){ return `
            <div class="t-form-group"><label class="t-label">${__t('tools.conv_type')}</label>
                <select class="t-select" id="conv-type" onchange="converterUpdate()">
                    <option value="length">${__t('tools.conv_length')}</option><option value="weight">${__t('tools.conv_weight')}</option><option value="temperature">${__t('tools.conv_temp')}</option><option value="area">${__t('tools.conv_area')}</option><option value="volume">${__t('tools.conv_volume')}</option><option value="speed">${__t('tools.conv_speed')}</option>
                </select>
            </div>
            <div class="converter-row">
                <div class="t-form-group"><label class="t-label">${__t('tools.conv_from')}</label><input type="number" class="t-input" id="conv-from-val" value="1" oninput="converterCalc()"></div>
                <div class="t-form-group"><select class="t-select" id="conv-from-unit" onchange="converterCalc()"></select></div>
                <button class="swap-btn" onclick="converterSwap()">⇄</button>
                <div class="t-form-group"><select class="t-select" id="conv-to-unit" onchange="converterCalc()"></select></div>
            </div>
            <div class="t-form-group"><label class="t-label">${__t('tools.conv_result')}</label><input type="text" class="t-input" id="conv-result" readonly></div>
        `; },
        qrcode: function(){ return `
            <div class="t-form-group"><label class="t-label">${__t('tools.qr_content')}</label><input type="text" class="t-input" id="qr-input" placeholder="${__t('tools.qr_ph')}"></div>
            <div class="t-btn-row"><button class="t-btn t-btn-primary" onclick="qrGenerate()">${__t('tools.qr_generate')}</button></div>
            <div class="qr-container" id="qr-container"></div>
        `; },
        jsonfmt: function(){ return `
            <textarea class="t-textarea" id="json-input" rows="5" placeholder="${__t('tools.json_ph')}"></textarea>
            <div class="t-btn-row">
                <button class="t-btn t-btn-primary" onclick="jsonFormat()">${__t('tools.json_format_btn')}</button>
                <button class="t-btn t-btn-secondary" onclick="jsonValidate()">${__t('tools.json_validate_btn')}</button>
                <button class="t-btn t-btn-secondary" onclick="jsonCompress()">${__t('tools.json_compress_btn')}</button>
            </div>
            <div class="json-output" id="json-output" style="margin-top:0.625rem;display:none"></div>
        `; },
        base64: function(){ return `
            <textarea class="t-textarea" id="b64-input" rows="4" placeholder="${__t('tools.input_text_ph')}"></textarea>
            <div class="t-btn-row">
                <button class="t-btn t-btn-primary" onclick="base64Encode()">${__t('tools.b64_encode')}</button>
                <button class="t-btn t-btn-secondary" onclick="base64Decode()">${__t('tools.b64_decode')}</button>
            </div>
            <textarea class="t-textarea" id="b64-output" rows="4" placeholder="${__t('tools.result_ph')}" readonly style="margin-top:0.625rem"></textarea>
        `; },
        colorpicker: function(){ return `
            <div class="t-form-group"><label class="t-label">${__t('tools.cp_pick_color')}</label><input type="color" class="t-input" id="cp-input" value="#4A90D9" oninput="colorPickerUpdate()" style="height:3.125rem;cursor:pointer"></div>
            <div class="color-preview" id="cp-preview" style="background:#4A90D9"></div>
            <div class="color-values">
                <div class="color-val">HEX<strong id="cp-hex">#4A90D9</strong></div>
                <div class="color-val">RGB<strong id="cp-rgb">74,144,217</strong></div>
                <div class="color-val">HSL<strong id="cp-hsl">210,65%,57%</strong></div>
            </div>
        `; },
        gradient: function(){ return `
            <div class="dual-colors">
                <div class="color-half"><label class="t-label">${__t('tools.gd_color1')}</label><input type="color" class="t-input" id="gd-c1" value="#4A90D9" oninput="gradientUpdate()" style="height:2.5rem"></div>
                <div class="color-half"><label class="t-label">${__t('tools.gd_color2')}</label><input type="color" class="t-input" id="gd-c2" value="#f9ca24" oninput="gradientUpdate()" style="height:2.5rem"></div>
            </div>
            <div class="t-form-group"><label class="t-label">${__t('tools.gd_dir')}</label><select class="t-select" id="gd-dir" onchange="gradientUpdate()"><option value="to right">${__t('tools.gd_horizontal')}</option><option value="to bottom">${__t('tools.gd_vertical')}</option><option value="to bottom right">${__t('tools.gd_diagonal')}</option><option value="to top right">${__t('tools.gd_diagonal_up')}</option></select></div>
            <div class="gradient-preview" id="gd-preview" style="background:linear-gradient(to right, #4A90D9, #f9ca24)"></div>
            <div class="css-output" id="gd-css">background: linear-gradient(to right, #4A90D9, #f9ca24);</div>
        `; },
        password: function(){ return `
            <div class="t-form-group"><label class="t-label">${__t('tools.pw_length')}：<span id="pw-len-label">12</span></label><input type="range" id="pw-len" min="6" max="32" value="12" oninput="passwordGen()"></div>
            <div class="t-form-group" style="display:flex;gap:0.75rem;flex-wrap:wrap">
                <label style="font-size:0.85rem;display:flex;align-items:center;gap:0.25rem"><input type="checkbox" id="pw-upper" checked onchange="passwordGen()">${__t('tools.pw_upper')}</label>
                <label style="font-size:0.85rem;display:flex;align-items:center;gap:0.25rem"><input type="checkbox" id="pw-lower" checked onchange="passwordGen()">${__t('tools.pw_lower')}</label>
                <label style="font-size:0.85rem;display:flex;align-items:center;gap:0.25rem"><input type="checkbox" id="pw-num" checked onchange="passwordGen()">${__t('tools.pw_num')}</label>
                <label style="font-size:0.85rem;display:flex;align-items:center;gap:0.25rem"><input type="checkbox" id="pw-sym" onchange="passwordGen()">${__t('tools.pw_sym')}</label>
            </div>
            <div class="password-display"><span id="pw-display"></span><button class="copy-btn" onclick="passwordCopy()">${__t('tools.copy')}</button></div>
            <div class="t-btn-row"><button class="t-btn t-btn-primary" onclick="passwordGen()">${__t('tools.pw_regenerate')}</button></div>
        `; },
        dice: function(){ return `
            <div class="dice-container"><div class="dice-face" id="dice-face">🎲</div><div class="dice-result" id="dice-result"></div></div>
            <div class="t-btn-row" style="justify-content:center"><button class="t-btn t-btn-primary" onclick="diceRoll()">${__t('tools.dice_roll_btn')}</button></div>
        `; },
        coin: function(){ return `
            <div class="coin-container"><div class="coin" id="coin"><div class="coin-face coin-front">1</div><div class="coin-face coin-back">0</div></div></div>
            <div class="coin-result" id="coin-result"></div>
            <div class="t-btn-row" style="justify-content:center"><button class="t-btn t-btn-primary" onclick="coinFlip()">${__t('tools.name_coin')}</button></div>
        `; },
        randompick: function(){ return `
            <textarea class="t-textarea" id="rp-input" rows="4" placeholder="${__t('tools.rp_ph')}"></textarea>
            <div class="t-btn-row">
                <button class="t-btn t-btn-primary" onclick="randomPick()">${__t('tools.name_randompick')}</button>
                <button class="t-btn t-btn-secondary" onclick="randomPickClear()">${__t('tools.clear')}</button>
            </div>
            <div style="text-align:center;margin-top:0.875rem;font-size:1.3rem;font-weight:600;color:var(--primary)" id="rp-result"></div>
        `; },
        fortune: function(){ return `
            <div class="fortune-result" id="fortune-result" style="display:none">
                <div class="fortune-icon" id="fortune-icon"></div>
                <div class="fortune-text" id="fortune-text"></div>
                <div class="fortune-detail" id="fortune-detail"></div>
            </div>
            <div class="t-btn-row" style="justify-content:center"><button class="t-btn t-btn-primary" onclick="fortuneDraw()">${__t('tools.fortune_draw')}</button></div>
        `; },
        wheel: function(){ return `
            <div class="t-form-group"><label class="t-label">${__t('tools.wheel_prizes_label')}</label><textarea class="t-textarea" id="wheel-items" rows="3" placeholder="${[__t('tools.prize_1'), __t('tools.prize_2'), __t('tools.prize_3'), __t('tools.prize_thanks')].join('&#10;')}"></textarea></div>
            <div class="wheel-wrap" style="display:block;text-align:center"><div class="wheel-pointer"></div><canvas id="wheel-canvas" width="300" height="300"></canvas></div>
            <div style="text-align:center;margin-top:0.625rem;font-size:1.1rem;font-weight:600;color:var(--primary)" id="wheel-result"></div>
            <div class="t-btn-row" style="justify-content:center"><button class="t-btn t-btn-primary" onclick="wheelSpin()">${__t('tools.wheel_spin')}</button></div>
        `; },
        scratch: function(){ return `
            <div class="t-form-group"><label class="t-label">${__t('tools.scratch_prize_label')}</label><input type="text" class="t-input" id="scratch-text" value="${__t('tools.scratch_win_text')}"></div>
            <div class="scratch-wrap"><canvas id="scratch-canvas" width="300" height="150"></canvas></div>
            <div class="t-btn-row" style="justify-content:center"><button class="t-btn t-btn-secondary" onclick="scratchReset()">${__t('tools.scratch_again')}</button></div>
        `; },
        weather: function(){ return `
            <div class="todo-input-row">
                <input type="text" class="t-input" id="weather-city" placeholder="${__t('tools.weather_city_ph')}" value="${__t('tools.city_default')}">
                <button class="t-btn t-btn-primary" onclick="weatherFetch()">${__t('tools.query')}</button>
            </div>
            <div class="weather-card" id="weather-card" style="display:none"></div>
        `; },
        ipquery: function(){ return `
            <div class="todo-input-row">
                <input type="text" class="t-input" id="ip-addr" placeholder="${__t('tools.ip_ph')}">
                <button class="t-btn t-btn-primary" onclick="ipQuery()">${__t('tools.query')}</button>
            </div>
            <div class="ip-info-card" id="ip-info" style="display:none;margin-top:0.625rem"></div>
        `; },
        quote: function(){ return `
            <div class="t-btn-row" style="justify-content:center"><button class="t-btn t-btn-primary" onclick="quoteFetch()">${__t('tools.quote_fetch')}</button></div>
            <div style="text-align:center;margin-top:0.875rem;padding:1rem;background:var(--bg);border-radius:var(--radius);border:1px solid var(--border);font-size:1.1rem;line-height:1.8;color:var(--text)" id="quote-text"></div>
        `; },
        featurevote: function(){ return `
            <div style="display:flex;gap:0.5rem;margin-bottom:0.875rem;">
                <input type="text" class="t-input" id="fv-input" placeholder="${__t('tools.fv_ph')}" maxlength="500" style="flex:1">
                <button class="t-btn t-btn-primary" onclick="featureVoteCreate()">${__t('tools.fv_submit')}</button>
            </div>
            <div style="font-size:0.85rem;color:var(--text-muted);margin-bottom:0.75rem;">${__t('tools.fv_desc')}</div>
            <div id="fv-list"></div>
        `; },
        photowall: function(){ return `
            <div class="t-form-group"><label class="t-label">${__t('tools.photo_upload')}</label><input type="file" class="t-input" id="photo-input" accept="image/*" multiple onchange="photoWallAdd()"></div>
            <div class="photo-grid" id="photo-grid"></div>
        `; },
        tts: function(){ return `
            <textarea class="t-textarea" id="tts-input" rows="3" placeholder="${__t('tools.tts_ph')}"></textarea>
            <div class="tts-controls" style="margin-top:0.625rem">
                <select class="t-select" id="tts-voice" style="flex:1"></select>
                <button class="t-btn t-btn-primary" onclick="ttsSpeak()">${__t('tools.tts_speak')}</button>
                <button class="t-btn t-btn-secondary" onclick="ttsStop()">${__t('tools.tts_stop')}</button>
            </div>
        `; },
        screenshot: function(){ return `
            <div class="t-btn-row" style="justify-content:center"><button class="t-btn t-btn-primary" onclick="screenshotCapture()">${__t('tools.screenshot_capture')}</button></div>
            <div style="text-align:center;margin-top:0.625rem;font-size:0.85rem;color:var(--text-muted)">${__t('tools.screenshot_hint')}</div>
            <div id="screenshot-result" style="margin-top:0.625rem;text-align:center"></div>
        `; },
        vote: function(){ return `
            <div class="t-form-group"><label class="t-label">${__t('tools.vote_title_label')}</label><input type="text" class="t-input" id="vote-title" placeholder="${__t('tools.vote_title_ph')}"></div>
            <div id="vote-options">
                <div class="vote-option"><input type="text" class="t-input" placeholder="${__t('tools.vote_option_1')}"><button class="t-btn t-btn-danger t-btn-sm" onclick="this.parentElement.remove()">×</button></div>
                <div class="vote-option"><input type="text" class="t-input" placeholder="${__t('tools.vote_option_2')}"><button class="t-btn t-btn-danger t-btn-sm" onclick="this.parentElement.remove()">×</button></div>
            </div>
            <div class="t-btn-row">
                <button class="t-btn t-btn-secondary" onclick="voteAddOption()">${__t('tools.vote_add_option')}</button>
                <button class="t-btn t-btn-primary" onclick="voteStart()">${__t('tools.vote_start')}</button>
            </div>
            <div id="vote-results" style="margin-top:0.625rem"></div>
        `; },
        badges: function(){ return `
            <div class="badge-grid">
                <div class="badge-item"><svg viewBox="0 0 64 64"><defs><linearGradient id="bg1" x1="0%" y1="0%" x2="100%" y2="100%"><stop offset="0%" stop-color="#f9ca24"/><stop offset="100%" stop-color="#e1b12c"/></linearGradient></defs><circle cx="32" cy="32" r="28" fill="url(#bg1)"/><polygon points="32,12 34,22 38,22 35,25 36,29 32,26 28,29 29,25 26,22 30,22" fill="#fff" opacity="0.9"/></svg><div class="badge-name">${__t('tools.badge_post_master')}</div><div class="badge-desc">${__t('tools.badge_post_master_desc')}</div></div>
                <div class="badge-item"><svg viewBox="0 0 64 64"><defs><linearGradient id="bg2" x1="0%" y1="0%" x2="100%" y2="100%"><stop offset="0%" stop-color="#3498db"/><stop offset="100%" stop-color="#2980b9"/></linearGradient></defs><circle cx="32" cy="32" r="28" fill="url(#bg2)"/><text x="32" y="38" text-anchor="middle" fill="#fff" font-size="20" font-weight="bold">100</text></svg><div class="badge-name">${__t('tools.badge_100')}</div><div class="badge-desc">${__t('tools.badge_100_desc')}</div></div>
                <div class="badge-item"><svg viewBox="0 0 64 64"><defs><linearGradient id="bg3" x1="0%" y1="0%" x2="100%" y2="100%"><stop offset="0%" stop-color="#ff6b6b"/><stop offset="100%" stop-color="#c0392b"/></linearGradient></defs><circle cx="32" cy="32" r="28" fill="url(#bg3)"/><polygon points="32,16 25,48 32,36 39,48" fill="#fff" opacity="0.9"/></svg><div class="badge-name">${__t('tools.badge_sofa')}</div><div class="badge-desc">${__t('tools.badge_sofa_desc')}</div></div>
                <div class="badge-item"><svg viewBox="0 0 64 64"><defs><linearGradient id="bg4" x1="0%" y1="0%" x2="100%" y2="100%"><stop offset="0%" stop-color="#2ecc71"/><stop offset="100%" stop-color="#27ae60"/></linearGradient></defs><circle cx="32" cy="32" r="28" fill="url(#bg4)"/><text x="32" y="38" text-anchor="middle" fill="#fff" font-size="20" font-weight="bold">7</text></svg><div class="badge-name">${__t('tools.badge_sign')}</div><div class="badge-desc">${__t('tools.badge_sign_desc')}</div></div>
                <div class="badge-item"><svg viewBox="0 0 64 64"><defs><linearGradient id="bg5" x1="0%" y1="0%" x2="100%" y2="100%"><stop offset="0%" stop-color="#a29bfe"/><stop offset="100%" stop-color="#6c5ce7"/></linearGradient></defs><circle cx="32" cy="32" r="28" fill="url(#bg5)"/><path d="M20 32 L28 40 L44 24" fill="none" stroke="#fff" stroke-width="4" stroke-linecap="round" stroke-linejoin="round"/></svg><div class="badge-name">${__t('tools.badge_verify')}</div><div class="badge-desc">${__t('tools.badge_verify_desc')}</div></div>
                <div class="badge-item"><svg viewBox="0 0 64 64"><defs><linearGradient id="bg6" x1="0%" y1="0%" x2="100%" y2="100%"><stop offset="0%" stop-color="#fd79a8"/><stop offset="100%" stop-color="#e84393"/></linearGradient></defs><circle cx="32" cy="32" r="28" fill="url(#bg6)"/><polygon points="32,12 38,26 52,26 40,34 44,48 32,40 20,48 24,34 12,26 26,26" fill="#fff" opacity="0.9"/></svg><div class="badge-name">${__t('tools.badge_hot')}</div><div class="badge-desc">${__t('tools.badge_hot_desc')}</div></div>
            </div>
        `; },
        eye: function(){ return `
            <div style="text-align:center;padding:0.625rem">
                <p style="color:var(--text-secondary);margin-bottom:0.875rem">${__t('tools.eye_desc')}</p>
                <button class="t-btn t-btn-primary" id="eye-toggle" onclick="eyeToggle()">${__t('tools.eye_on')}</button>
            </div>
        `; },
        reading: function(){ return `
            <div style="text-align:center;padding:0.625rem">
                <p style="color:var(--text-secondary);margin-bottom:0.875rem">${__t('tools.reading_desc')}</p>
                <button class="t-btn t-btn-primary" id="reading-toggle" onclick="readingToggle()">${__t('tools.reading_on')}</button>
            </div>
        `; },
        sitemap: function(){ return `
            <ul class="sitemap-tree">
                <li><a href="/">🏠 ${__t('mnav.home')}</a></li>
                <li><a href="/pages/login.php">🔑 ${__t('nav.login')}</a></li>
                <li><a href="/pages/post.php">📝 ${__t('sidebar.post')}</a></li>
                <li><a href="/pages/user_center.php">👤 ${__t('nav.profile')}</a></li>
                <li><a href="/pages/tools.php">🛠 ${__t('nav.tools')}</a></li>
                <li><a href="/admin/">⚙ ${__t('nav.admin')}</a></li>
            </ul>
        `; }
    };

    let pomodoroTimer = null, cdTimer = null, swTimer = null, clockInterval = null;
    let pomodoroMode = 'work', pomodoroSeconds = 25 * 60;
    let swStartTime = 0, swElapsed = 0, swRunning = false, swLaps = [];
    let cdRemaining = 60, cdRunning = false;
    let calcExpr = '';
    let stickyColor = '#f9ca24';
    let wheelItems = [], wheelAngle = 0;
    let voteData = { title: '', options: [], votes: [] };
    let eyeActive = false, readingActive = false;

    function initTool(name) {
        setTimeout(function() {
            switch(name) {
                case 'countdown': countdownUpdate(); break;
                case 'clock': clockInit(); break;
                case 'password': passwordGen(); break;
                case 'converter': converterUpdate(); break;
                case 'colorpicker': colorPickerUpdate(); break;
                case 'gradient': gradientUpdate(); break;
                case 'wheel': wheelInit(); break;
                case 'scratch': scratchReset(); break;
                case 'tts': ttsInit(); break;
                case 'todo': todoLoad(); break;
                case 'sticky': stickyLoad(); break;
                case 'photowall': photoWallLoad(); break;
                case 'featurevote': featureVoteLoad(); break;
                case 'eye': eyeInit(); break;
                case 'reading': readingInit(); break;
            }
        }, 50);
    }

    function pad2(n) { return n < 10 ? '0' + n : '' + n; }
    function fmtTime(s) {
        var m = Math.floor(s / 60), sec = s % 60;
        return pad2(m) + ':' + pad2(sec);
    }

    function pomodoroStart() {
        if (pomodoroTimer) return;
        var startBtn = document.getElementById('pomodoro-start');
        var pauseBtn = document.getElementById('pomodoro-pause');
        if (startBtn) startBtn.style.display = 'none';
        if (pauseBtn) pauseBtn.style.display = '';
        pomodoroTimer = setInterval(function() {
            pomodoroSeconds--;
            var d = document.getElementById('pomodoro-display');
            if (d) d.textContent = fmtTime(pomodoroSeconds);
            if (pomodoroSeconds <= 0) {
                clearInterval(pomodoroTimer);
                pomodoroTimer = null;
                var label = document.getElementById('pomodoro-label');
                if (label) label.textContent = pomodoroMode === 'work' ? __t('tools.pomodoro_break_time_excl') : __t('tools.pomodoro_work_time_excl');
                pomodoroMode = pomodoroMode === 'work' ? 'break' : 'work';
                pomodoroSeconds = pomodoroMode === 'work' ? 25 * 60 : 5 * 60;
                var presets = document.querySelectorAll('.preset');
                presets.forEach(function(p) { p.classList.remove('active'); });
                if (presets.length >= 2) {
                    if (pomodoroMode === 'work') presets[0].classList.add('active');
                    else presets[1].classList.add('active');
                }
                if (startBtn) startBtn.style.display = '';
                if (pauseBtn) pauseBtn.style.display = 'none';
                showToast(pomodoroMode === 'work' ? __t('tools.pomodoro_work_start') : __t('tools.pomodoro_break_start'));
            }
        }, 1000);
    }

    function pomodoroPause() {
        if (pomodoroTimer) {
            clearInterval(pomodoroTimer);
            pomodoroTimer = null;
        }
        var startBtn = document.getElementById('pomodoro-start');
        var pauseBtn = document.getElementById('pomodoro-pause');
        if (startBtn) startBtn.style.display = '';
        if (pauseBtn) pauseBtn.style.display = 'none';
    }

    function pomodoroReset() {
        if (pomodoroTimer) { clearInterval(pomodoroTimer); pomodoroTimer = null; }
        pomodoroMode = 'work';
        pomodoroSeconds = 25 * 60;
        var d = document.getElementById('pomodoro-display');
        if (d) d.textContent = '25:00';
        var label = document.getElementById('pomodoro-label');
        if (label) label.textContent = __t('tools.pomodoro_work_time');
        var startBtn = document.getElementById('pomodoro-start');
        var pauseBtn = document.getElementById('pomodoro-pause');
        if (startBtn) startBtn.style.display = '';
        if (pauseBtn) pauseBtn.style.display = 'none';
        var presets = document.querySelectorAll('.preset');
        presets.forEach(function(p) { p.classList.remove('active'); });
        if (presets[0]) presets[0].classList.add('active');
    }

    function pomodoroSetMode(mode) {
        pomodoroPause();
        pomodoroMode = mode;
        pomodoroSeconds = mode === 'work' ? 25 * 60 : 5 * 60;
        var d = document.getElementById('pomodoro-display');
        if (d) d.textContent = fmtTime(pomodoroSeconds);
        var label = document.getElementById('pomodoro-label');
        if (label) label.textContent = mode === 'work' ? __t('tools.pomodoro_work_time') : __t('tools.pomodoro_break_time');
        var presets = document.querySelectorAll('.preset');
        presets.forEach(function(p) { p.classList.remove('active'); });
        if (mode === 'work' && presets[0]) presets[0].classList.add('active');
        if (mode === 'break' && presets[1]) presets[1].classList.add('active');
    }

    function countdownUpdate() {
        var h = parseInt(document.getElementById('cd-h').value) || 0;
        var m = parseInt(document.getElementById('cd-m').value) || 0;
        var s = parseInt(document.getElementById('cd-s').value) || 0;
        cdRemaining = h * 3600 + m * 60 + s;
        var d = document.getElementById('cd-display');
        if (d) d.textContent = fmtTime(cdRemaining);
    }

    function countdownStart() {
        if (cdRunning) return;
        countdownUpdate();
        if (cdRemaining <= 0) return;
        cdRunning = true;
        var startBtn = document.getElementById('cd-start');
        var pauseBtn = document.getElementById('cd-pause');
        if (startBtn) startBtn.style.display = 'none';
        if (pauseBtn) pauseBtn.style.display = '';
        cdTimer = setInterval(function() {
            cdRemaining--;
            var d = document.getElementById('cd-display');
            if (d) d.textContent = fmtTime(Math.max(0, cdRemaining));
            if (cdRemaining <= 0) {
                clearInterval(cdTimer);
                cdTimer = null;
                cdRunning = false;
                if (startBtn) startBtn.style.display = '';
                if (pauseBtn) pauseBtn.style.display = 'none';
                showToast(__t('tools.countdown_done'));
            }
        }, 1000);
    }

    function countdownPause() {
        if (cdTimer) { clearInterval(cdTimer); cdTimer = null; }
        cdRunning = false;
        var startBtn = document.getElementById('cd-start');
        var pauseBtn = document.getElementById('cd-pause');
        if (startBtn) startBtn.style.display = '';
        if (pauseBtn) pauseBtn.style.display = 'none';
    }

    function countdownReset() {
        if (cdTimer) { clearInterval(cdTimer); cdTimer = null; }
        cdRunning = false;
        countdownUpdate();
        var startBtn = document.getElementById('cd-start');
        var pauseBtn = document.getElementById('cd-pause');
        if (startBtn) startBtn.style.display = '';
        if (pauseBtn) pauseBtn.style.display = 'none';
    }

    function countdownPreset(sec) {
        document.getElementById('cd-h').value = Math.floor(sec / 3600);
        document.getElementById('cd-m').value = Math.floor((sec % 3600) / 60);
        document.getElementById('cd-s').value = sec % 60;
        countdownReset();
    }

    function stopwatchStart() {
        if (swRunning) return;
        swRunning = true;
        swStartTime = Date.now() - swElapsed;
        var startBtn = document.getElementById('sw-start');
        var pauseBtn = document.getElementById('sw-pause');
        if (startBtn) startBtn.style.display = 'none';
        if (pauseBtn) pauseBtn.style.display = '';
        swTimer = setInterval(function() {
            swElapsed = Date.now() - swStartTime;
            var d = document.getElementById('sw-display');
            if (d) {
                var ms = Math.floor(swElapsed / 10) % 100;
                var s = Math.floor(swElapsed / 1000) % 60;
                var m = Math.floor(swElapsed / 60000);
                d.textContent = pad2(m) + ':' + pad2(s) + '.' + pad2(ms);
            }
        }, 50);
    }

    function stopwatchPause() {
        if (swTimer) { clearInterval(swTimer); swTimer = null; }
        swRunning = false;
        swElapsed = Date.now() - swStartTime;
        var startBtn = document.getElementById('sw-start');
        var pauseBtn = document.getElementById('sw-pause');
        if (startBtn) startBtn.style.display = '';
        if (pauseBtn) pauseBtn.style.display = 'none';
    }

    function stopwatchReset() {
        if (swTimer) { clearInterval(swTimer); swTimer = null; }
        swRunning = false;
        swElapsed = 0;
        swLaps = [];
        var d = document.getElementById('sw-display');
        if (d) d.textContent = '00:00.00';
        var laps = document.getElementById('sw-laps');
        if (laps) laps.innerHTML = '';
        var startBtn = document.getElementById('sw-start');
        var pauseBtn = document.getElementById('sw-pause');
        if (startBtn) startBtn.style.display = '';
        if (pauseBtn) pauseBtn.style.display = 'none';
    }

    function stopwatchLap() {
        var d = document.getElementById('sw-display');
        if (!d) return;
        swLaps.push(d.textContent);
        var laps = document.getElementById('sw-laps');
        if (laps) {
            laps.innerHTML = '';
            swLaps.forEach(function(lap, i) {
                var li = document.createElement('li');
                li.innerHTML = '<span>' + __t('tools.lap_count') + ' ' + (i + 1) + '</span><span>' + lap + '</span>';
                laps.appendChild(li);
            });
        }
    }

    function clockInit() {
        if (clockInterval) clearInterval(clockInterval);
        function draw() {
            var canvas = document.getElementById('analog-clock');
            if (!canvas) return;
            var ctx = canvas.getContext('2d');
            var now = new Date();
            var h = now.getHours() % 12, m = now.getMinutes(), s = now.getSeconds();
            var w = canvas.width, cx = w / 2, cy = w / 2, r = w / 2 - 10;
            ctx.clearRect(0, 0, w, w);
            ctx.beginPath(); ctx.arc(cx, cy, r, 0, Math.PI * 2); ctx.fillStyle = '#fff'; ctx.fill();
            ctx.strokeStyle = '#ddd'; ctx.lineWidth = 2; ctx.stroke();
            for (var i = 0; i < 12; i++) {
                var angle = (i - 3) * Math.PI / 6;
                var x1 = cx + Math.cos(angle) * (r - 15);
                var y1 = cy + Math.sin(angle) * (r - 15);
                var x2 = cx + Math.cos(angle) * (r - 5);
                var y2 = cy + Math.sin(angle) * (r - 5);
                ctx.beginPath(); ctx.moveTo(x1, y1); ctx.lineTo(x2, y2);
                ctx.strokeStyle = '#333'; ctx.lineWidth = 2; ctx.stroke();
            }
            var hAngle = ((h + m / 60) - 3) * Math.PI / 6;
            ctx.beginPath(); ctx.moveTo(cx, cy); ctx.lineTo(cx + Math.cos(hAngle) * r * 0.5, cy + Math.sin(hAngle) * r * 0.5);
            ctx.strokeStyle = '#333'; ctx.lineWidth = 4; ctx.lineCap = 'round'; ctx.stroke();
            var mAngle = ((m + s / 60) - 15) * Math.PI / 30;
            ctx.beginPath(); ctx.moveTo(cx, cy); ctx.lineTo(cx + Math.cos(mAngle) * r * 0.7, cy + Math.sin(mAngle) * r * 0.7);
            ctx.strokeStyle = '#666'; ctx.lineWidth = 3; ctx.stroke();
            var sAngle = (s - 15) * Math.PI / 30;
            ctx.beginPath(); ctx.moveTo(cx, cy); ctx.lineTo(cx + Math.cos(sAngle) * r * 0.85, cy + Math.sin(sAngle) * r * 0.85);
            ctx.strokeStyle = '#e74c3c'; ctx.lineWidth = 1.5; ctx.stroke();
            ctx.beginPath(); ctx.arc(cx, cy, 4, 0, Math.PI * 2); ctx.fillStyle = '#333'; ctx.fill();
            var dig = document.getElementById('digital-clock');
            if (dig) dig.textContent = pad2(now.getHours()) + ':' + pad2(m) + ':' + pad2(s);
        }
        draw();
        clockInterval = setInterval(draw, 1000);
    }

    function todoLoad() {
        var list = document.getElementById('todo-list');
        if (!list) return;
        var items = JSON.parse(localStorage.getItem('tools_todo') || '[]');
        list.innerHTML = '';
        items.forEach(function(item, i) {
            var li = document.createElement('li');
            li.className = 'todo-item' + (item.done ? ' done' : '');
            li.innerHTML = '<input type="checkbox" class="todo-check"' + (item.done ? ' checked' : '') + ' onchange="todoToggle(' + i + ')"><span class="todo-text">' + item.text.replace(/</g, '&lt;') + '</span><button class="todo-del" onclick="todoDel(' + i + ')">×</button>';
            list.appendChild(li);
        });
    }

    function todoSave(items) {
        localStorage.setItem('tools_todo', JSON.stringify(items));
    }

    function todoAdd() {
        var input = document.getElementById('todo-input');
        if (!input || !input.value.trim()) return;
        var items = JSON.parse(localStorage.getItem('tools_todo') || '[]');
        items.push({ text: input.value.trim(), done: false });
        todoSave(items);
        input.value = '';
        todoLoad();
    }

    function todoToggle(i) {
        var items = JSON.parse(localStorage.getItem('tools_todo') || '[]');
        if (items[i]) items[i].done = !items[i].done;
        todoSave(items);
        todoLoad();
    }

    function todoDel(i) {
        var items = JSON.parse(localStorage.getItem('tools_todo') || '[]');
        items.splice(i, 1);
        todoSave(items);
        todoLoad();
    }

    function stickySetColor(el) {
        document.querySelectorAll('.note-color').forEach(function(e) { e.classList.remove('active'); });
        el.classList.add('active');
        stickyColor = el.getAttribute('data-color');
    }

    function stickyLoad() {
        var container = document.getElementById('sticky-notes');
        if (!container) return;
        var notes = JSON.parse(localStorage.getItem('tools_sticky') || '[]');
        container.innerHTML = '';
        notes.forEach(function(note, i) {
            var div = document.createElement('div');
            div.className = 'sticky-note';
            div.style.background = note.color;
            div.innerHTML = '<button class="note-del" onclick="stickyDel(' + i + ')">×</button>' + note.text.replace(/</g, '&lt;').replace(/\n/g, '<br>');
            container.appendChild(div);
        });
    }

    function stickyAdd() {
        var input = document.getElementById('sticky-input');
        if (!input || !input.value.trim()) return;
        var notes = JSON.parse(localStorage.getItem('tools_sticky') || '[]');
        notes.push({ text: input.value.trim(), color: stickyColor });
        localStorage.setItem('tools_sticky', JSON.stringify(notes));
        input.value = '';
        stickyLoad();
    }

    function stickyDel(i) {
        var notes = JSON.parse(localStorage.getItem('tools_sticky') || '[]');
        notes.splice(i, 1);
        localStorage.setItem('tools_sticky', JSON.stringify(notes));
        stickyLoad();
    }

    function wordCount() {
        var text = document.getElementById('wc-input').value;
        document.getElementById('wc-chars').textContent = text.replace(/\s/g, '').length;
        document.getElementById('wc-bytes').textContent = new Blob([text]).size;
        document.getElementById('wc-lines').textContent = text.split('\n').length;
    }

    function quickSearch() {
        var q = document.getElementById('search-query').value.trim();
        if (!q) return;
        var engine = document.getElementById('search-engine').value;
        window.open(engine + encodeURIComponent(q), '_blank');
    }

    function calcInput(v) {
        calcExpr += v;
        document.getElementById('calc-display').textContent = calcExpr || '0';
    }

    function calcClear() {
        calcExpr = '';
        document.getElementById('calc-display').textContent = '0';
    }

    function calcEval() {
        try {
            var result = Function('"use strict";return (' + calcExpr + ')')();
            calcExpr = String(result);
            document.getElementById('calc-display').textContent = calcExpr;
        } catch(e) {
            document.getElementById('calc-display').textContent = __t('tools.err');
            calcExpr = '';
        }
    }

    var convUnits = {
        length: { m: 1, km: 1000, cm: 0.01, mm: 0.001, mi: 1609.344, ft: 0.3048, in: 0.0254 },
        weight: { kg: 1, g: 0.001, mg: 0.000001, t: 1000, lb: 0.453592, oz: 0.0283495 },
        temperature: null,
        area: { m2: 1, km2: 1000000, ha: 10000, ft2: 0.092903, ac: 4046.86 },
        volume: { l: 1, ml: 0.001, m3: 1000, gal: 3.78541, qt: 0.946353, cup: 0.236588 },
        speed: { 'm/s': 1, 'km/h': 0.277778, mph: 0.44704, knot: 0.514444 }
    };

    function converterUpdate() {
        var type = document.getElementById('conv-type').value;
        var from = document.getElementById('conv-from-unit');
        var to = document.getElementById('conv-to-unit');
        from.innerHTML = ''; to.innerHTML = '';
        if (type === 'temperature') {
            ['Celsius (°C)', 'Fahrenheit (°F)', 'Kelvin (K)'].forEach(function(u) {
                from.innerHTML += '<option>' + u + '</option>';
                to.innerHTML += '<option>' + u + '</option>';
            });
            to.selectedIndex = 1;
        } else {
            var units = Object.keys(convUnits[type]);
            units.forEach(function(u) {
                from.innerHTML += '<option>' + u + '</option>';
                to.innerHTML += '<option>' + u + '</option>';
            });
            to.selectedIndex = 1;
        }
        converterCalc();
    }

    function converterCalc() {
        var type = document.getElementById('conv-type').value;
        var val = parseFloat(document.getElementById('conv-from-val').value) || 0;
        var from = document.getElementById('conv-from-unit').value;
        var to = document.getElementById('conv-to-unit').value;
        var result;
        if (type === 'temperature') {
            var celsius;
            if (from.indexOf('Celsius') === 0) celsius = val;
            else if (from.indexOf('Fahrenheit') === 0) celsius = (val - 32) * 5 / 9;
            else celsius = val - 273.15;
            if (to.indexOf('Celsius') === 0) result = celsius;
            else if (to.indexOf('Fahrenheit') === 0) result = celsius * 9 / 5 + 32;
            else result = celsius + 273.15;
        } else {
            result = val * convUnits[type][from] / convUnits[type][to];
        }
        document.getElementById('conv-result').value = parseFloat(result.toFixed(6));
    }

    function converterSwap() {
        var from = document.getElementById('conv-from-unit');
        var to = document.getElementById('conv-to-unit');
        var tmp = from.value;
        from.value = to.value;
        to.value = tmp;
        converterCalc();
    }

    function qrGenerate() {
        var text = document.getElementById('qr-input').value.trim();
        var container = document.getElementById('qr-container');
        if (!text) { container.innerHTML = ''; return; }
        container.innerHTML = '<img src="https://api.qrserver.com/v1/create-qr-code/?size=200x200&data=' + encodeURIComponent(text) + '" alt="QR Code">';
    }

    function jsonFormat() {
        var input = document.getElementById('json-input').value;
        var output = document.getElementById('json-output');
        try {
            var obj = JSON.parse(input);
            output.style.display = 'block';
            output.className = 'json-output';
            output.textContent = JSON.stringify(obj, null, 2);
        } catch(e) {
            output.style.display = 'block';
            output.className = 'json-output json-error';
            output.textContent = __t('tools.json_parse_err') + e.message;
        }
    }

    function jsonValidate() {
        var input = document.getElementById('json-input').value;
        var output = document.getElementById('json-output');
        try {
            JSON.parse(input);
            output.style.display = 'block';
            output.className = 'json-output';
            output.textContent = __t('tools.json_valid');
        } catch(e) {
            output.style.display = 'block';
            output.className = 'json-output json-error';
            output.textContent = __t('tools.json_invalid') + e.message;
        }
    }

    function jsonCompress() {
        var input = document.getElementById('json-input').value;
        var output = document.getElementById('json-output');
        try {
            var obj = JSON.parse(input);
            output.style.display = 'block';
            output.className = 'json-output';
            output.textContent = JSON.stringify(obj);
        } catch(e) {
            output.style.display = 'block';
            output.className = 'json-output json-error';
            output.textContent = __t('tools.json_compress_fail') + e.message;
        }
    }

    function base64Encode() {
        var input = document.getElementById('b64-input').value;
        document.getElementById('b64-output').value = btoa(unescape(encodeURIComponent(input)));
    }

    function base64Decode() {
        var input = document.getElementById('b64-input').value;
        try {
            document.getElementById('b64-output').value = decodeURIComponent(escape(atob(input)));
        } catch(e) {
            document.getElementById('b64-output').value = __t('tools.b64_decode_fail') + e.message;
        }
    }

    function colorPickerUpdate() {
        var hex = document.getElementById('cp-input').value;
        document.getElementById('cp-preview').style.background = hex;
        document.getElementById('cp-hex').textContent = hex;
        var r = parseInt(hex.slice(1,3), 16), g = parseInt(hex.slice(3,5), 16), b = parseInt(hex.slice(5,7), 16);
        document.getElementById('cp-rgb').textContent = r + ',' + g + ',' + b;
        var h, s, l;
        r /= 255; g /= 255; b /= 255;
        var max = Math.max(r, g, b), min = Math.min(r, g, b);
        l = (max + min) / 2;
        if (max === min) { h = s = 0; } else {
            var d = max - min;
            s = l > 0.5 ? d / (2 - max - min) : d / (max + min);
            switch(max) {
                case r: h = ((g - b) / d + (g < b ? 6 : 0)) / 6; break;
                case g: h = ((b - r) / d + 2) / 6; break;
                case b: h = ((r - g) / d + 4) / 6; break;
            }
        }
        document.getElementById('cp-hsl').textContent = Math.round(h * 360) + '°,' + Math.round(s * 100) + '%,' + Math.round(l * 100) + '%';
    }

    function gradientUpdate() {
        var c1 = document.getElementById('gd-c1').value;
        var c2 = document.getElementById('gd-c2').value;
        var dir = document.getElementById('gd-dir').value;
        var css = 'linear-gradient(' + dir + ', ' + c1 + ', ' + c2 + ')';
        document.getElementById('gd-preview').style.background = css;
        document.getElementById('gd-css').textContent = 'background: ' + css + ';';
    }

    function passwordGen() {
        var len = parseInt(document.getElementById('pw-len').value);
        var upper = document.getElementById('pw-upper').checked;
        var lower = document.getElementById('pw-lower').checked;
        var num = document.getElementById('pw-num').checked;
        var sym = document.getElementById('pw-sym').checked;
        document.getElementById('pw-len-label').textContent = len;
        var chars = '';
        if (upper) chars += 'ABCDEFGHIJKLMNOPQRSTUVWXYZ';
        if (lower) chars += 'abcdefghijklmnopqrstuvwxyz';
        if (num) chars += '0123456789';
        if (sym) chars += '!@#$%^&*()_+-=[]{}|;:,.<>?';
        if (!chars) { document.getElementById('pw-display').textContent = __t('tools.pw_type_required'); return; }
        var pw = '';
        var arr = new Uint32Array(len);
        crypto.getRandomValues(arr);
        for (var i = 0; i < len; i++) pw += chars[arr[i] % chars.length];
        document.getElementById('pw-display').textContent = pw;
    }

    function passwordCopy() {
        var pw = document.getElementById('pw-display').textContent;
        if (!pw) return;
        navigator.clipboard.writeText(pw).then(function() { showToast(__t('tools.copied')); });
    }

    function diceRoll() {
        var face = document.getElementById('dice-face');
        var result = document.getElementById('dice-result');
        face.classList.add('rolling');
        var num = Math.floor(Math.random() * 6) + 1;
        setTimeout(function() {
            face.classList.remove('rolling');
            face.textContent = num;
            result.textContent = __t('tools.result_colon') + num;
        }, 600);
    }

    function coinFlip() {
        var coin = document.getElementById('coin');
        var result = document.getElementById('coin-result');
        if (!coin || coin.classList.contains('flipping')) return;
        var isHeads = Math.random() < 0.5;
        // 重置上一次的朝向与动画，确保每次抛掷都从正面开始转动
        coin.classList.remove('flipping');
        coin.style.transform = '';
        void coin.offsetWidth; // 强制回流以重新触发动画
        coin.classList.add('flipping');
        setTimeout(function() {
            coin.classList.remove('flipping');
            // 动画结束于 rotateY(1800deg)（正面），反面时再补 180 度
            coin.style.transform = isHeads ? 'rotateY(0deg)' : 'rotateY(180deg)';
            result.textContent = isHeads ? __t('tools.coin_heads') : __t('tools.coin_tails');
        }, 2000);
    }

    function randomPick() {
        var input = document.getElementById('rp-input').value.trim();
        if (!input) return;
        var items = input.split('\n').filter(function(l) { return l.trim(); });
        if (items.length === 0) return;
        var picked = items[Math.floor(Math.random() * items.length)];
        document.getElementById('rp-result').textContent = '🎯 ' + picked;
    }

    function randomPickClear() {
        document.getElementById('rp-input').value = '';
        document.getElementById('rp-result').textContent = '';
    }

    function fortuneDraw() {
        var fortunes = [
            { icon: '🌟', text: __t('tools.fortune_great'), detail: __t('tools.fortune_great_detail') },
            { icon: '✨', text: __t('tools.fortune_good'), detail: __t('tools.fortune_good_detail') },
            { icon: '☀️', text: __t('tools.fortune_mid'), detail: __t('tools.fortune_mid_detail') },
            { icon: '🌤', text: __t('tools.fortune_minor'), detail: __t('tools.fortune_minor_detail') },
            { icon: '🌥', text: __t('tools.fortune_late'), detail: __t('tools.fortune_late_detail') },
            { icon: '🌧', text: __t('tools.fortune_bad'), detail: __t('tools.fortune_bad_detail') },
            { icon: '⛈', text: __t('tools.fortune_worst'), detail: __t('tools.fortune_worst_detail') }
        ];
        var f = fortunes[Math.floor(Math.random() * fortunes.length)];
        var container = document.getElementById('fortune-result');
        document.getElementById('fortune-icon').textContent = f.icon;
        document.getElementById('fortune-text').textContent = f.text;
        document.getElementById('fortune-detail').textContent = f.detail;
        container.style.display = 'block';
    }

    function wheelInit() {
        var saved = localStorage.getItem('tools_wheel_items');
        var textarea = document.getElementById('wheel-items');
        if (saved && textarea) textarea.value = saved;
        if (textarea) { wheelItems = textarea.value.split('\n').filter(function(l) { return l.trim(); }); }
        wheelDraw();
    }

    function wheelDraw() {
        var canvas = document.getElementById('wheel-canvas');
        if (!canvas) return;
        wheelItems = document.getElementById('wheel-items').value.split('\n').filter(function(l) { return l.trim(); });
        if (wheelItems.length === 0) wheelItems = [__t('tools.prize_1'), __t('tools.prize_2'), __t('tools.prize_3'), __t('tools.prize_thanks')];
        localStorage.setItem('tools_wheel_items', wheelItems.join('\n'));
        var ctx = canvas.getContext('2d');
        var cx = canvas.width / 2, cy = canvas.height / 2, r = 140;
        ctx.clearRect(0, 0, canvas.width, canvas.height);
        var colors = ['#e74c3c', '#f39c12', '#2ecc71', '#3498db', '#9b59b6', '#e67e22', '#1abc9c', '#e91e63'];
        var sliceAngle = 2 * Math.PI / wheelItems.length;
        wheelItems.forEach(function(item, i) {
            ctx.beginPath();
            ctx.moveTo(cx, cy);
            ctx.arc(cx, cy, r, wheelAngle + i * sliceAngle, wheelAngle + (i + 1) * sliceAngle);
            ctx.closePath();
            ctx.fillStyle = colors[i % colors.length];
            ctx.fill();
            ctx.strokeStyle = '#fff';
            ctx.lineWidth = 2;
            ctx.stroke();
            ctx.save();
            ctx.translate(cx, cy);
            ctx.rotate(wheelAngle + i * sliceAngle + sliceAngle / 2);
            ctx.textAlign = 'center';
            ctx.fillStyle = '#fff';
            ctx.font = 'bold 12px ZCOOL QingKe HuangYou, sans-serif';
            ctx.fillText(item.length > 6 ? item.slice(0, 6) + '..' : item, r * 0.65, 5);
            ctx.restore();
        });
        ctx.beginPath();
        ctx.arc(cx, cy, 20, 0, 2 * Math.PI);
        ctx.fillStyle = '#fff';
        ctx.fill();
        ctx.strokeStyle = '#333';
        ctx.lineWidth = 3;
        ctx.stroke();
    }

    function wheelSpin() {
        wheelDraw();
        var canvas = document.getElementById('wheel-canvas');
        var result = document.getElementById('wheel-result');
        var spins = 5 + Math.random() * 5;
        var targetAngle = wheelAngle + spins * 2 * Math.PI;
        var startTime = Date.now();
        var duration = 3000;
        var startAngle = wheelAngle;
        var spin = setInterval(function() {
            var elapsed = Date.now() - startTime;
            var progress = Math.min(elapsed / duration, 1);
            var ease = 1 - Math.pow(1 - progress, 3);
            wheelAngle = startAngle + (targetAngle - startAngle) * ease;
            wheelDraw();
            if (progress >= 1) {
                clearInterval(spin);
                wheelAngle = wheelAngle % (2 * Math.PI);
                var sliceAngle = 2 * Math.PI / wheelItems.length;
                var normalizedAngle = (2 * Math.PI - (wheelAngle % (2 * Math.PI))) % (2 * Math.PI);
                var index = Math.floor(normalizedAngle / sliceAngle) % wheelItems.length;
                result.textContent = '🎉 ' + wheelItems[index];
            }
        }, 16);
    }

    function scratchReset() {
        var canvas = document.getElementById('scratch-canvas');
        if (!canvas) return;
        var ctx = canvas.getContext('2d');
        var text = document.getElementById('scratch-text').value || __t('tools.scratch_win_text');
        ctx.clearRect(0, 0, canvas.width, canvas.height);
        ctx.fillStyle = '#f9ca24';
        ctx.fillRect(0, 0, canvas.width, canvas.height);
        ctx.fillStyle = '#333';
        ctx.font = 'bold 24px ZCOOL QingKe HuangYou, sans-serif';
        ctx.textAlign = 'center';
        ctx.fillText(text, canvas.width / 2, canvas.height / 2 + 8);
        ctx.fillStyle = '#b2bec3';
        ctx.fillRect(0, 0, canvas.width, canvas.height);
        ctx.fillStyle = '#636e72';
        ctx.font = 'bold 20px ZCOOL QingKe HuangYou, sans-serif';
        ctx.fillText(__t('tools.scratch_here'), canvas.width / 2, canvas.height / 2 + 8);
        var scratching = false;
        canvas.onmousedown = function(e) { scratching = true; scratch(e); };
        canvas.onmouseup = function() { scratching = false; };
        canvas.onmousemove = function(e) { if (scratching) scratch(e); };
        canvas.ontouchstart = function(e) { scratching = true; scratch(e.touches[0]); };
        canvas.ontouchend = function() { scratching = false; };
        canvas.ontouchmove = function(e) { if (scratching) scratch(e.touches[0]); };
        function scratch(e) {
            var rect = canvas.getBoundingClientRect();
            var x = e.clientX - rect.left, y = e.clientY - rect.top;
            ctx.globalCompositeOperation = 'destination-out';
            ctx.beginPath();
            ctx.arc(x, y, 20, 0, Math.PI * 2);
            ctx.fill();
            ctx.globalCompositeOperation = 'source-over';
        }
    }

    function weatherFetch() {
        var city = document.getElementById('weather-city').value.trim() || __t('tools.city_default');
        var card = document.getElementById('weather-card');
        card.style.display = 'block';
        card.textContent = __t('tools.querying');
        fetch('/api/weather_proxy.php?city=' + encodeURIComponent(city))
            .then(function(r) { return r.json(); })
            .then(function(data) {
                if (data.error) { card.textContent = data.error; return; }
                card.innerHTML = '<div style="font-size:1.2rem;font-weight:600;margin-bottom:0.375rem">' + city.replace(/</g,'&lt;') + '</div>';
                var temp = document.createElement('div');
                temp.style.cssText = 'font-size:2.5rem;font-weight:700;color:var(--primary)';
                temp.textContent = data.temp || '--';
                card.appendChild(temp);
                var desc = document.createElement('div');
                desc.style.cssText = 'font-size:1rem;color:var(--text-secondary)';
                desc.textContent = data.desc || '--';
                card.appendChild(desc);
                if (data.humidity) {
                    var info = document.createElement('div');
                    info.style.cssText = 'font-size:0.85rem;color:var(--text-muted);margin-top:6px';
                    info.textContent = __t('tools.humidity') + data.humidity + ' | ' + __t('tools.wind_speed') + (data.wind || '--');
                    card.appendChild(info);
                }
            })
            .catch(function() { card.textContent = __t('tools.weather_fail'); });
    }

    function ipQuery() {
        var ip = document.getElementById('ip-addr').value.trim();
        var info = document.getElementById('ip-info');
        info.style.display = 'block';
        info.innerHTML = __t('tools.querying');
        var url = ip ? 'https://api.ip.sb/geoip/' + ip : 'https://api.ip.sb/geoip';
        fetch(url)
            .then(function(r) { return r.json(); })
            .then(function(data) {
                var rows = [
                    ['IP', data.ip], [__t('tools.ip_country'), data.country], [__t('tools.ip_city'), data.city],
                    ['ISP', data.isp], [__t('tools.ip_timezone'), data.timezone], [__t('tools.ip_coord'), data.latitude + ', ' + data.longitude]
                ];
                info.innerHTML = '';
                rows.forEach(function(r) {
                    var div = document.createElement('div');
                    div.className = 'ip-info-row';
                    div.innerHTML = '<span class="ip-info-label">' + r[0] + '</span><span class="ip-info-val">' + (r[1] || '--').replace(/</g,'&lt;') + '</span>';
                    info.appendChild(div);
                });
            })
            .catch(function() { info.innerHTML = __t('tools.query_fail'); });
    }

    function quoteFetch() {
        var el = document.getElementById('quote-text');
        el.textContent = __t('tools.fetching');
        fetch('https://v1.hitokoto.cn/?c=d&encode=text')
            .then(function(r) { return r.text(); })
            .then(function(text) { el.textContent = text || __t('tools.quote_default'); })
            .catch(function() { el.textContent = __t('tools.quote_default'); });
    }

    function esc(str) {
        return String(str == null ? '' : str).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#39;');
    }

    function featureVoteLoad() {
        var list = document.getElementById('fv-list');
        if (!list) return;
        list.textContent = __t('tools.loading');
        fetch('/api/feature_requests.php')
            .then(function(r) { return r.json(); })
            .then(function(res) {
                if (!res.success) { list.textContent = res.message || __t('tools.load_fail'); return; }
                var features = res.data || [];
                renderFeatureVoteList(features);
            })
            .catch(function() { list.textContent = __t('tools.load_fail_retry'); });
    }

    function renderFeatureVoteList(features) {
        var list = document.getElementById('fv-list');
        if (!list) return;
        if (!features.length) {
            list.innerHTML = '<div style="text-align:center;color:var(--text-muted);padding:1.25rem;">' + __t('tools.fv_empty') + '</div>';
            return;
        }
        list.innerHTML = features.map(function(f) {
            var done = f.status === 'done';
            var nick = (f.nickname || ('QQ:' + f.qq) || __t('tools.fv_user')).replace(/</g, '&lt;');
            var title = (f.title || '').replace(/</g, '&lt;');
            var badge = done
                ? '<span style="background:var(--success-light);color:var(--success);padding:2px 0.5rem;border-radius:0.625rem;font-size:0.75rem;">' + __t('tools.fv_done') + '</span>'
                : '<span style="background:var(--warning-light);color:var(--warning);padding:2px 0.5rem;border-radius:0.625rem;font-size:0.75rem;">' + __t('tools.fv_pending') + '</span>';
            var voteBtn = IS_LOGGED_IN
                ? '<button class="t-btn t-btn-sm ' + (f.voted ? 't-btn-secondary' : 't-btn-primary') + '" onclick="featureVoteToggle(' + esc(f.id) + ', this)">' + (f.voted ? __t('tools.fv_voted') : __t('tools.fv_vote')) + '</button>'
                : '<a href="/pages/login.php" class="t-btn t-btn-sm t-btn-primary">' + __t('tools.fv_login_to_vote') + '</a>';
            return '<div style="border:1px solid var(--border);border-radius:var(--radius);padding:0.75rem;margin-bottom:0.625rem;">' +
                '<div style="display:flex;align-items:flex-start;justify-content:space-between;gap:0.625rem;">' +
                    '<div style="flex:1;"><div style="font-weight:600;margin-bottom:0.25rem;">' + title + '</div>' +
                    '<div style="font-size:0.78rem;color:var(--text-muted);">by ' + nick + ' · ' + (f.created_at || '') + '</div></div>' +
                    '<div style="text-align:center;">' + badge + '<div style="font-size:1.2rem;font-weight:700;color:var(--primary);margin:0.25rem 0;">' + esc(f.vote_count) + '</div>' + voteBtn + '</div>' +
                '</div></div>';
        }).join('');
    }

    function featureVoteCreate() {
        if (!IS_LOGGED_IN) { showToast(__t('tools.fv_login_first_submit')); return; }
        var input = document.getElementById('fv-input');
        var title = (input.value || '').trim();
        if (title.length < 2) { showToast(__t('tools.fv_too_short')); return; }
        if (title.length > 500) { showToast(__t('tools.fv_too_long')); return; }
        var fd = new FormData();
        fd.append('csrf_token', CSRF_TOKEN);
        fd.append('action', 'create');
        fd.append('title', title);
        var btn = null;
        if (input && input.parentNode) { btn = input.parentNode.querySelector('button'); }
        if (btn) btn.disabled = true;
        fetch('/api/feature_requests.php', { method: 'POST', body: fd })
            .then(function(r) { return r.json(); })
            .then(function(res) {
                if (res.success) { showToast(res.message || __t('tr.success')); input.value = ''; featureVoteLoad(); }
                else { showToast(res.message || __t('tr.fail')); }
            })
            .catch(function() { showToast(__t('tools.submit_fail_retry')); })
            .then(function() { btn.disabled = false; });
    }

    function featureVoteToggle(id, btn) {
        if (!IS_LOGGED_IN) { showToast(__t('tools.fv_login_first_vote')); return; }
        var fd = new FormData();
        fd.append('csrf_token', CSRF_TOKEN);
        fd.append('action', 'vote');
        fd.append('feature_id', id);
        btn.disabled = true;
        fetch('/api/feature_requests.php', { method: 'POST', body: fd })
            .then(function(r) { return r.json(); })
            .then(function(res) {
                if (res.success) { showToast(res.message || __t('tools.op_ok')); featureVoteLoad(); }
                else { showToast(res.message || __t('tools.op_fail')); btn.disabled = false; }
            })
            .catch(function() { showToast(__t('tools.op_fail_retry')); btn.disabled = false; });
    }

    function photoWallLoad() {
        var grid = document.getElementById('photo-grid');
        if (!grid) return;
        var photos = JSON.parse(localStorage.getItem('tools_photos') || '[]');
        grid.innerHTML = '';
        photos.forEach(function(src, i) {
            var img = document.createElement('img');
            img.src = src;
            img.alt = 'Photo ' + (i + 1);
            img.onclick = function() { window.open(src, '_blank'); };
            grid.appendChild(img);
        });
    }

    function photoWallAdd() {
        var input = document.getElementById('photo-input');
        if (!input || !input.files.length) return;
        var photos = JSON.parse(localStorage.getItem('tools_photos') || '[]');
        var totalSize = photos.join('').length;
        Array.from(input.files).forEach(function(file) {
            if (totalSize + file.size > 10 * 1024 * 1024) { showToast(__t('tools.photo_size_limit')); return; }
            var reader = new FileReader();
            reader.onload = function(e) {
                photos.push(e.target.result);
                localStorage.setItem('tools_photos', JSON.stringify(photos));
                photoWallLoad();
            };
            reader.readAsDataURL(file);
            totalSize += file.size;
        });
        input.value = '';
    }

    function ttsInit() {
        if (!window.speechSynthesis) return;
        var select = document.getElementById('tts-voice');
        if (!select) return;
        select.innerHTML = '';
        var voices = speechSynthesis.getVoices();
        if (voices.length === 0) {
            speechSynthesis.onvoiceschanged = function() {
                ttsInit();
            };
            return;
        }
        voices.forEach(function(v) {
            var opt = document.createElement('option');
            opt.value = v.name;
            opt.textContent = v.name + ' (' + v.lang + ')';
            select.appendChild(opt);
        });
    }

    function ttsSpeak() {
        if (!window.speechSynthesis) { showToast(__t('tools.tts_unsupported')); return; }
        var text = document.getElementById('tts-input').value.trim();
        if (!text) return;
        speechSynthesis.cancel();
        var utter = new SpeechSynthesisUtterance(text);
        var voiceName = document.getElementById('tts-voice').value;
        var voices = speechSynthesis.getVoices();
        var voice = voices.find(function(v) { return v.name === voiceName; });
        if (voice) utter.voice = voice;
        utter.rate = 1;
        utter.pitch = 1;
        speechSynthesis.speak(utter);
    }

    function ttsStop() {
        if (window.speechSynthesis) speechSynthesis.cancel();
    }

    function screenshotCapture() {
        showToast(__t('tools.screenshot_loading'));
        var script = document.createElement('script');
        // 本地自托管 html2canvas：原先指向第三方 CDN，会被本站 CSP 的 script-src 'self' 拦截导致功能直接失效；
        // 自托管同时兼顾离线可用与「不把页面数据交给外部域名」的隐私要求。
        script.src = '/assets/js/vendor/html2canvas.min.js';
        script.onload = function() {
            html2canvas(document.body).then(function(canvas) {
                var result = document.getElementById('screenshot-result');
                result.innerHTML = '';
                var img = document.createElement('img');
                img.src = canvas.toDataURL();
                img.style.maxWidth = '100%';
                img.style.borderRadius = '8px';
                result.appendChild(img);
                showToast(__t('tools.screenshot_done'));
            }).catch(function() { showToast(__t('tools.screenshot_fail')); });
        };
        script.onerror = function() { showToast(__t('tools.screenshot_lib_fail')); };
        document.head.appendChild(script);
    }

    function voteAddOption() {
        var container = document.getElementById('vote-options');
        var div = document.createElement('div');
        div.className = 'vote-option';
        div.innerHTML = '<input type="text" class="t-input" placeholder="' + __t('tools.vote_new_option') + '"><button class="t-btn t-btn-danger t-btn-sm" onclick="this.parentElement.remove()">×</button>';
        container.appendChild(div);
    }

    function voteStart() {
        var title = document.getElementById('vote-title').value.trim();
        if (!title) { showToast(__t('tools.vote_title_required')); return; }
        var optionInputs = document.querySelectorAll('#vote-options input');
        voteData.title = title;
        voteData.options = [];
        voteData.votes = [];
        optionInputs.forEach(function(input) {
            var val = input.value.trim();
            if (val) { voteData.options.push(val); voteData.votes.push(0); }
        });
        if (voteData.options.length < 2) { showToast(__t('tools.vote_min_options')); return; }
        var results = document.getElementById('vote-results');
        results.innerHTML = '<h3 style="margin-bottom:0.625rem">' + title.replace(/</g,'&lt;') + '</h3>';
        voteData.options.forEach(function(opt, i) {
            results.innerHTML += '<div class="vote-bar-wrap"><div class="vote-bar" style="width:0%">0%</div></div><div class="vote-count">' + opt.replace(/</g,'&lt;') + ' - 0' + __t('tools.vote_votes') + ' <button class="t-btn t-btn-sm t-btn-primary" onclick="voteCast(' + i + ')" style="margin-left:0.5rem">' + __t('tools.fv_vote') + '</button></div>';
        });
    }

    function voteCast(i) {
        voteData.votes[i]++;
        var total = voteData.votes.reduce(function(a, b) { return a + b; }, 0);
        var results = document.getElementById('vote-results');
        results.innerHTML = '<h3 style="margin-bottom:0.625rem">' + voteData.title.replace(/</g,'&lt;') + '</h3>';
        voteData.options.forEach(function(opt, j) {
            var pct = total > 0 ? Math.round(voteData.votes[j] / total * 100) : 0;
            results.innerHTML += '<div class="vote-bar-wrap"><div class="vote-bar" style="width:' + pct + '%">' + (pct > 0 ? pct + '%' : '') + '</div></div><div class="vote-count">' + opt.replace(/</g,'&lt;') + ' - ' + voteData.votes[j] + __t('tools.vote_votes') + ' <button class="t-btn t-btn-sm t-btn-primary" onclick="voteCast(' + j + ')" style="margin-left:0.5rem">' + __t('tools.fv_vote') + '</button></div>';
        });
    }

    function eyeInit() {
        var btn = document.getElementById('eye-toggle');
        if (btn) {
            btn.textContent = eyeActive ? __t('tools.eye_off') : __t('tools.eye_on');
            btn.className = eyeActive ? 't-btn t-btn-danger' : 't-btn t-btn-primary';
        }
        var overlay = document.getElementById('eyeOverlay');
        if (overlay) overlay.style.display = eyeActive ? 'block' : 'none';
    }

    function eyeToggle() {
        eyeActive = !eyeActive;
        var overlay = document.getElementById('eyeOverlay');
        if (overlay) overlay.style.display = eyeActive ? 'block' : 'none';
        var btn = document.getElementById('eye-toggle');
        if (btn) {
            btn.textContent = eyeActive ? __t('tools.eye_off') : __t('tools.eye_on');
            btn.className = eyeActive ? 't-btn t-btn-danger' : 't-btn t-btn-primary';
        }
        showToast(eyeActive ? __t('tools.eye_enabled') : __t('tools.eye_disabled'));
    }

    function readingInit() {
        var btn = document.getElementById('reading-toggle');
        if (btn) {
            btn.textContent = readingActive ? __t('tools.reading_off') : __t('tools.reading_on');
            btn.className = readingActive ? 't-btn t-btn-danger' : 't-btn t-btn-primary';
        }
        if (readingActive) document.body.classList.add('reading-mode-body');
    }

    function readingToggle() {
        readingActive = !readingActive;
        document.body.classList.toggle('reading-mode-body', readingActive);
        var btn = document.getElementById('reading-toggle');
        if (btn) {
            btn.textContent = readingActive ? __t('tools.reading_off') : __t('tools.reading_on');
            btn.className = readingActive ? 't-btn t-btn-danger' : 't-btn t-btn-primary';
        }
        showToast(readingActive ? __t('tools.reading_enabled') : __t('tools.reading_disabled'));
    }

    (function(){
        // #postFab / #backToTop 已移除，对应逻辑一并删掉（回顶改由 .lw-fab 提供）
        var navItems = document.querySelectorAll('.mobile-nav-item');
        var currentPath = window.location.pathname;
        navItems.forEach(function(item) {
            var href = item.getAttribute('href');
            if (href && (currentPath === href || (href !== '/' && currentPath.indexOf(href.split('#')[0]) === 0))) {
                navItems.forEach(function(n) { n.classList.remove('active'); });
                item.classList.add('active');
            }
        });
    })();
    </script>
    <script src="<?= asset_url('/assets/js/main.js') ?>?v=<?= asset_ver('/assets/js/main.js') ?>" defer></script>
    <?php require_once __DIR__ . '/../includes/lang_ui.php'; ?>
    <script src="<?= asset_url('/assets/js/enhancements.js') ?>?v=<?= asset_ver('/assets/js/enhancements.js') ?>" defer></script>
</body>
</html>