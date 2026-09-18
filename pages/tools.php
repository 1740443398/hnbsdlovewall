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

$user = getCurrentUser();
if ($user) {
    $user = checkBanned($user);
}
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
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" href="/icon.ico" type="image/x-icon">
    <title>实用工具 - <?= htmlspecialchars($siteName) ?></title>
    <meta name="description" content="淮南市北师大实验中学高中部校园综合信息交流平台 - 实用工具">
    <link rel="stylesheet" href="/assets/css/style.css?v=<?= asset_ver('/assets/css/style.css') ?>">
    <link rel="stylesheet" href="/assets/css/enhancements.css?v=<?= asset_ver('/assets/css/enhancements.css') ?>">
    <script>
        const SITE_URL = '<?= SITE_URL ?>';
        const IS_LOGGED_IN = <?= $user ? 'true' : 'false' ?>;
        const USER_DATA = <?= $user ? json_encode(['id' => $user['id'], 'qq' => $user['qq'], 'nickname' => $user['nickname'], 'avatar' => $user['avatar'], 'role' => $user['role']], JSON_HEX_TAG | JSON_UNESCAPED_UNICODE) : 'null' ?>;
        const CSRF_TOKEN = '<?= generateCSRFToken() ?>';
        const USER_THEME = '<?= $_COOKIE['theme'] ?? '' ?>';
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
            padding-left: 14px;
            border-left: 4px solid #C9A96E;
            margin-bottom: var(--space-md);
            letter-spacing: 0.5px;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .tools-category-title::before {
            content: '';
            display: inline-block;
            width: 8px;
            height: 8px;
            border-radius: 50%;
            background: #C9A96E;
            flex-shrink: 0;
        }
        .tool-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 16px;
        }
        @media (max-width: 1100px) {
            .tool-grid { grid-template-columns: repeat(3, 1fr); }
        }
        @media (max-width: 768px) {
            .tool-grid { grid-template-columns: repeat(2, 1fr); gap: 12px; }
            .tools-header h1 { font-size: 1.8rem; }
            .tools-header { padding: var(--space-lg) var(--space-md) var(--space-md); }
        }
        @media (max-width: 480px) {
            .tool-grid { grid-template-columns: 1fr 1fr; gap: 10px; }
        }
        .tool-card {
            background: var(--card-bg);
            border: 1px solid var(--border);
            border-radius: var(--radius);
            padding: 24px 16px 18px;
            cursor: pointer;
            transition: all var(--transition);
            display: flex;
            flex-direction: column;
            align-items: center;
            text-align: center;
            box-shadow: var(--shadow-sm);
            position: relative;
            overflow: hidden;
            border-top: 3px solid transparent;
        }
        .tool-card:hover {
            transform: translateY(-4px);
            box-shadow: var(--shadow-md);
            border-color: var(--primary);
            border-top-color: #C9A96E;
            background: linear-gradient(180deg, var(--primary-light), var(--card-bg));
        }
        .tool-card .icon-wrap {
            width: 56px;
            height: 56px;
            margin-bottom: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            position: relative;
            z-index: 1;
        }
        .tool-card .icon-wrap svg {
            width: 48px;
            height: 48px;
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
            margin-top: 4px;
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
            padding: 20px 24px 14px;
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
            gap: 8px;
        }
        .tool-modal-header h2 svg { width: 24px; height: 24px; }
        .tool-modal-close {
            width: 34px; height: 34px;
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
            padding: 20px 24px 24px;
        }
        .tool-modal::-webkit-scrollbar { width: 6px; }
        .tool-modal::-webkit-scrollbar-track { background: transparent; }
        .tool-modal::-webkit-scrollbar-thumb { background: var(--border); border-radius: 3px; }

        /* Buttons */
        .t-btn {
            padding: 9px 20px;
            border-radius: var(--radius-sm);
            border: none;
            cursor: pointer;
            font-family: inherit;
            font-size: 0.9rem;
            font-weight: 600;
            transition: all var(--transition-fast);
            display: inline-flex;
            align-items: center;
            gap: 5px;
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
        .t-btn-sm { padding: 5px 12px; font-size: 0.8rem; border-radius: 6px; }
        .t-btn-row { display: flex; gap: 8px; flex-wrap: wrap; margin-top: 14px; }

        /* Form elements */
        .t-input, .t-textarea, .t-select {
            width: 100%;
            padding: 10px 14px;
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
        .t-textarea { resize: vertical; min-height: 90px; }
        .t-select option { background: var(--card-bg); color: var(--text); }
        .t-label {
            display: block;
            font-size: 0.85rem;
            color: var(--text-secondary);
            margin-bottom: 5px;
            font-weight: 500;
        }
        .t-form-group { margin-bottom: 14px; }

        /* Timer display */
        .timer-display {
            font-size: 3.5rem;
            font-weight: 700;
            text-align: center;
            font-variant-numeric: tabular-nums;
            font-family: 'Courier New', monospace;
            color: var(--primary);
            margin: 16px 0;
            letter-spacing: 3px;
        }
        .timer-label {
            text-align: center;
            font-size: 0.9rem;
            color: var(--text-secondary);
            margin-bottom: 14px;
        }

        /* Presets */
        .presets { display: flex; gap: 6px; flex-wrap: wrap; }
        .preset {
            padding: 5px 12px;
            border-radius: 20px;
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
        .todo-input-row { display: flex; gap: 8px; }
        .todo-input-row .t-input { flex: 1; }
        .todo-list { list-style: none; margin-top: 10px; }
        .todo-item {
            display: flex; align-items: center; gap: 8px;
            padding: 8px 10px; border-radius: var(--radius-sm);
            background: var(--bg);
            margin-bottom: 6px;
            transition: all var(--transition-fast);
        }
        .todo-item.done .todo-text { text-decoration: line-through; opacity: 0.5; }
        .todo-check { width: 18px; height: 18px; cursor: pointer; accent-color: var(--primary); }
        .todo-text { flex: 1; font-size: 0.9rem; }
        .todo-del {
            background: none; border: none; color: var(--text-muted);
            cursor: pointer; font-size: 1.1rem; padding: 2px 6px;
            border-radius: 4px;
        }
        .todo-del:hover { color: var(--danger); background: var(--danger-light); }

        /* Calculator */
        .calc-display {
            background: var(--bg);
            border: 1px solid var(--border);
            border-radius: var(--radius-sm);
            padding: 16px;
            text-align: right;
            font-size: 1.8rem;
            font-family: 'Courier New', monospace;
            color: var(--text);
            margin-bottom: 14px;
            min-height: 70px;
            word-break: break-all;
        }
        .calc-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 6px;
        }
        .calc-btn {
            padding: 14px 6px;
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
            width: 100%; height: 100px; border-radius: var(--radius-sm);
            margin-bottom: 14px; transition: background 0.3s;
            border: 1px solid var(--border);
        }
        .color-values {
            display: flex; gap: 10px; flex-wrap: wrap;
        }
        .color-val {
            flex: 1; min-width: 90px;
            background: var(--bg); padding: 8px 12px;
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
            padding: 14px 16px;
            border-radius: var(--radius-sm);
            font-family: 'Courier New', monospace;
            font-size: 1.2rem;
            text-align: center;
            word-break: break-all;
            margin-bottom: 14px;
            display: flex; align-items: center; justify-content: space-between;
            color: var(--text);
        }
        .password-display .copy-btn {
            background: var(--primary-light);
            border: 1px solid var(--primary);
            color: var(--primary);
            padding: 4px 10px;
            border-radius: 5px;
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
            width: 110px; height: 110px;
            margin: 16px auto;
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
        .coin-result { text-align: center; font-size: 1.1rem; margin-top: 8px; color: var(--text); font-weight: 500; }

        /* Dice */
        .dice-container { text-align: center; margin: 16px 0; }
        .dice-face {
            width: 110px; height: 110px;
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
        .dice-result { font-size: 1.3rem; margin-top: 8px; color: var(--text); font-weight: 500; }

        /* Lap list */
        .lap-list {
            list-style: none; max-height: 160px; overflow-y: auto;
            margin-top: 10px;
        }
        .lap-list li {
            padding: 6px 10px; border-radius: 6px;
            background: var(--bg);
            margin-bottom: 3px; font-family: 'Courier New', monospace;
            font-size: 0.85rem;
            display: flex; justify-content: space-between;
            color: var(--text);
        }

        /* QR */
        .qr-container { text-align: center; margin: 14px 0; }
        .qr-container img { border-radius: var(--radius-sm); background: #fff; padding: 8px; border: 1px solid var(--border); }

        /* Wheel */
        .wheel-wrap { text-align: center; margin: 14px 0; position: relative; display: inline-block; }
        .wheel-wrap canvas { max-width: 100%; border-radius: 50%; }
        .wheel-pointer { position: absolute; top: -10px; left: 50%; transform: translateX(-50%); width: 0; height: 0; border-left: 14px solid transparent; border-right: 14px solid transparent; border-top: 24px solid var(--danger); filter: drop-shadow(0 2px 4px rgba(0,0,0,0.2)); }

        /* Scratch */
        .scratch-wrap { text-align: center; margin: 14px 0; }
        .scratch-wrap canvas { border-radius: var(--radius-sm); cursor: pointer; border: 1px solid var(--border); }

        /* Fortune */
        .fortune-result {
            text-align: center; padding: 24px;
            background: var(--bg); border-radius: var(--radius);
            margin: 14px 0; border: 1px solid var(--border);
        }
        .fortune-result .fortune-icon { font-size: 2.5rem; }
        .fortune-result .fortune-text { font-size: 1.4rem; margin: 8px 0; font-weight: 600; color: var(--text); }
        .fortune-result .fortune-detail { color: var(--text-secondary); font-size: 0.9rem; }

        /* Badges */
        .badge-grid {
            display: grid; grid-template-columns: repeat(3, 1fr); gap: 10px;
        }
        .badge-item {
            text-align: center; padding: 14px 8px;
            background: var(--bg); border-radius: var(--radius-sm);
            border: 1px solid var(--border);
        }
        .badge-item svg { width: 40px; height: 40px; margin-bottom: 6px; }
        .badge-item .badge-name { font-size: 0.8rem; font-weight: 600; color: var(--text); }
        .badge-item .badge-desc { font-size: 0.7rem; color: var(--text-muted); }

        /* Vote */
        .vote-option {
            display: flex; align-items: center; gap: 8px;
            margin-bottom: 8px;
        }
        .vote-option .t-input { flex: 1; }
        .vote-bar-wrap {
            background: var(--bg-secondary); border-radius: var(--radius-sm);
            height: 26px; margin-bottom: 6px; overflow: hidden;
            position: relative;
        }
        .vote-bar {
            height: 100%; background: linear-gradient(90deg, var(--primary), var(--primary-dark));
            border-radius: var(--radius-sm); transition: width 0.5s ease;
            display: flex; align-items: center; padding-left: 10px;
            font-size: 0.8rem; font-weight: 600; color: var(--text-inverse);
        }
        .vote-count { font-size: 0.75rem; color: var(--text-muted); margin-top: 1px; }

        /* Music */
        .music-player-inner {
            background: var(--bg); border-radius: var(--radius);
            padding: 16px; text-align: center; border: 1px solid var(--border);
        }
        .music-controls {
            display: flex; align-items: center; justify-content: center;
            gap: 12px; margin: 12px 0;
        }
        .music-controls button {
            width: 38px; height: 38px; border-radius: 50%;
            border: 1px solid var(--border);
            background: var(--card-bg);
            color: var(--text);
            cursor: pointer;
            display: flex; align-items: center; justify-content: center;
            font-size: 1rem;
            transition: all var(--transition-fast);
        }
        .music-controls button:hover { background: var(--primary-light); color: var(--primary); border-color: var(--primary); }
        .music-controls .play-btn { width: 52px; height: 52px; font-size: 1.3rem; }
        .playlist { list-style: none; text-align: left; margin-top: 10px; }
        .playlist li {
            padding: 7px 10px; border-radius: var(--radius-sm); cursor: pointer;
            transition: all var(--transition-fast); font-size: 0.85rem;
            color: var(--text);
        }
        .playlist li:hover, .playlist li.active { background: var(--primary-light); color: var(--primary); }

        /* Eye protection */
        .eye-protection-overlay {
            position: fixed; top: 0; left: 0; right: 0; bottom: 0;
            background: rgba(255, 200, 100, 0.15);
            pointer-events: none; z-index: 9998;
            display: none;
        }

        /* Search results */
        .search-results {
            list-style: none; margin-top: 10px;
        }
        .search-results li {
            padding: 8px 12px; border-radius: var(--radius-sm);
            background: var(--bg); margin-bottom: 4px;
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
        .sticky-notes { display: flex; flex-wrap: wrap; gap: 8px; margin-top: 10px; }
        .sticky-note {
            width: 150px; min-height: 110px; padding: 12px;
            border-radius: 3px; font-size: 0.8rem;
            position: relative; color: #333;
            transform: rotate(-1deg);
            transition: transform 0.2s;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
        }
        .sticky-note:hover { transform: rotate(0deg) scale(1.03); }
        .sticky-note .note-del {
            position: absolute; top: 3px; right: 6px;
            background: none; border: none; cursor: pointer;
            font-size: 0.85rem; opacity: 0.5; color: #333;
        }
        .sticky-note .note-del:hover { opacity: 1; }
        .note-colors { display: flex; gap: 5px; margin-bottom: 8px; }
        .note-color {
            width: 22px; height: 22px; border-radius: 50%;
            cursor: pointer; border: 2px solid transparent;
            transition: all var(--transition-fast);
        }
        .note-color.active { border-color: var(--text); transform: scale(1.15); }

        /* Time input */
        .time-input-row {
            display: flex; gap: 6px; align-items: center;
            justify-content: center; margin: 14px 0;
        }
        .time-input-row .t-input {
            width: 65px; text-align: center; font-size: 1.4rem;
            padding: 8px 6px;
        }
        .time-input-row span { font-size: 1.4rem; color: var(--text-muted); }

        /* Gradient */
        .dual-colors { display: flex; gap: 10px; }
        .dual-colors .color-half { flex: 1; }
        .gradient-preview {
            width: 100%; height: 90px; border-radius: var(--radius-sm);
            margin: 14px 0; border: 1px solid var(--border);
        }
        .css-output {
            background: var(--bg); padding: 12px;
            border-radius: var(--radius-sm); font-family: 'Courier New', monospace;
            font-size: 0.8rem; word-break: break-all;
            margin-top: 8px; color: var(--text); border: 1px solid var(--border);
        }

        /* Converter */
        .converter-row { display: flex; gap: 6px; align-items: flex-end; }
        .converter-row .t-form-group { flex: 1; }
        .converter-row .swap-btn {
            background: var(--bg-secondary); border: 1px solid var(--border);
            color: var(--text); width: 36px; height: 36px;
            border-radius: var(--radius-sm); cursor: pointer;
            display: flex; align-items: center; justify-content: center;
            margin-bottom: 14px; font-size: 1.1rem;
            transition: all var(--transition-fast);
        }
        .converter-row .swap-btn:hover { background: var(--primary-light); color: var(--primary); }

        /* JSON */
        .json-output {
            background: var(--bg); padding: 12px;
            border-radius: var(--radius-sm); font-family: 'Courier New', monospace;
            font-size: 0.8rem; max-height: 180px; overflow-y: auto;
            white-space: pre-wrap; word-break: break-all;
            color: var(--text); border: 1px solid var(--border);
        }
        .json-error { color: var(--danger) !important; }

        /* Weather */
        .weather-card {
            text-align: center; padding: 16px;
            background: var(--bg); border-radius: var(--radius);
            margin: 14px 0; border: 1px solid var(--border);
        }

        /* IP info */
        .ip-info-card {
            background: var(--bg); border-radius: var(--radius);
            padding: 16px; border: 1px solid var(--border);
        }
        .ip-info-row {
            display: flex; justify-content: space-between;
            padding: 7px 0; border-bottom: 1px solid var(--border);
            color: var(--text);
        }
        .ip-info-row:last-child { border-bottom: none; }
        .ip-info-label { color: var(--text-muted); }
        .ip-info-val { font-weight: 500; }

        /* Photo */
        .photo-grid {
            display: grid; grid-template-columns: repeat(3, 1fr); gap: 6px;
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
            list-style: none; padding: 7px 10px;
            border-left: 2px solid var(--border);
            margin-left: 10px; position: relative;
        }
        .sitemap-tree li::before {
            content: ''; position: absolute;
            left: -2px; top: 50%; width: 10px;
            height: 2px; background: var(--border);
        }
        .sitemap-tree li a { color: var(--primary); text-decoration: none; font-size: 0.9rem; }
        .sitemap-tree li a:hover { text-decoration: underline; }

        /* Analog clock */
        .analog-clock {
            width: 180px; height: 180px; margin: 0 auto;
        }
        .digital-clock {
            text-align: center; font-size: 1.8rem;
            font-family: 'Courier New', monospace;
            margin-top: 10px; color: var(--text);
        }

        /* TTS */
        .tts-controls { display: flex; gap: 6px; align-items: center; }
        .tts-controls .t-select { width: auto; }

        /* Toast */
        .t-toast {
            position: fixed; bottom: 30px; left: 50%; transform: translateX(-50%);
            background: var(--text); color: var(--text-inverse);
            padding: 10px 22px; border-radius: var(--radius-sm);
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
<body class="<?= ($user ? ($user['theme'] ?? 'light') : (isset($_COOKIE['theme']) ? $_COOKIE['theme'] : 'light')) ?>-theme">
    <?php if ($bannedMsg): ?>
    <div class="ban-banner">
        <div class="container">
            <span class="ban-text"><?= htmlspecialchars($bannedMsg) ?></span>
        </div>
    </div>
    <?php endif; ?>

    <header class="site-header">
        <div class="header-inner">
            <a href="/" class="site-logo">
                <svg class="logo-icon" width="32" height="32" viewBox="0 0 24 24" fill="none">
                    <defs>
                        <linearGradient id="logoGrad" x1="0%" y1="0%" x2="100%" y2="100%">
                            <stop offset="0%" stop-color="#C9A96E"/>
                            <stop offset="50%" stop-color="#E8D5A3"/>
                            <stop offset="100%" stop-color="#B8943E"/>
                        </linearGradient>
                    </defs>
                    <path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z" fill="url(#logoGrad)"/>
                    <polyline points="9 22 9 12 15 12 15 22" fill="none" stroke="#fff" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                </svg>
                <span class="logo-text"><?= htmlspecialchars($siteName) ?></span>
            </a>
            <div class="header-actions">
                <?php if ($user): ?>
                <div class="user-menu">
                    <button class="user-menu-btn" id="userMenuBtn">
                        <img src="<?= htmlspecialchars($user['avatar']) ?>" alt="头像" class="user-avatar" onerror="this.style.display='none'">
                        <span class="user-name"><?= htmlspecialchars($user['nickname']) ?></span>
                    </button>
                    <div class="user-dropdown" id="userDropdown">
                        <a href="/pages/user_center.php" class="dropdown-item">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                            个人中心
                        </a>
                        <a href="/pages/user_center.php#my-posts" class="dropdown-item">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
                            我的帖子
                        </a>
                        <a href="/pages/user_center.php#my-favorites" class="dropdown-item">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg>
                            我的收藏
                        </a>
                        <a href="/pages/browser.php" class="dropdown-item">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="2" y1="12" x2="22" y2="12"/><path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"/></svg>
                            网页导航
                        </a>
                        <a href="/pages/tools.php" class="dropdown-item">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="3"/><path d="M12 2v4m0 12v4M2 12h4m12 0h4" stroke-width="1.5" stroke-linecap="round"/><circle cx="12" cy="12" r="9" opacity="0.4"/></svg>
                            实用工具
                        </a>
                        <?php if (in_array($user['role'], ['admin', 'super_admin'])): ?>
                        <a href="/admin/" class="dropdown-item">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
                            管理后台
                        </a>
                        <?php endif; ?>
                        <button class="dropdown-item" id="themeToggle">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="5"/><line x1="12" y1="1" x2="12" y2="3"/><line x1="12" y1="21" x2="12" y2="23"/><line x1="4.22" y1="4.22" x2="5.64" y2="5.64"/><line x1="18.36" y1="18.36" x2="19.78" y2="19.78"/><line x1="1" y1="12" x2="3" y2="12"/><line x1="21" y1="12" x2="23" y2="12"/><line x1="4.22" y1="19.78" x2="5.64" y2="18.36"/><line x1="18.36" y1="5.64" x2="19.78" y2="4.22"/></svg>
                            切换主题
                        </button>
                        <button class="dropdown-item" id="logoutBtn">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>
                            退出登录
                        </button>
                    </div>
                </div>
                <?php else: ?>
                <a href="/pages/login.php" class="btn btn-primary">登录 / 注册</a>
                <?php endif; ?>
            </div>
        </div>
    </header>

    <?php if ($announcement): ?>
    <div class="announcement-bar">
        <div class="container">
            <div class="announcement-scroll">
                <span><?= htmlspecialchars($announcement) ?></span>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <main class="tools-main">
        <div class="tools-container">
            <div class="tools-header">
                <h1>实用工具</h1>
                <p class="subtitle">集时间管理、效率工具、计算工具、创意工具、娱乐工具、查询工具、媒体工具于一体</p>
            </div>

            <div class="tools-category">
                <div class="tools-category-title">时间工具</div>
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
                        <div class="tool-name">番茄钟</div>
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
                        <div class="tool-name">倒计时</div>
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
                        <div class="tool-name">秒表</div>
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
                        <div class="tool-name">时钟</div>
                    </div>
                </div>
            </div>

            <div class="tools-category">
                <div class="tools-category-title">效率工具</div>
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
                        <div class="tool-name">待办事项</div>
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
                        <div class="tool-name">便签</div>
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
                        <div class="tool-name">字数统计</div>
                    </div>
                    <div class="tool-card" onclick="openTool('search')">
                        <div class="icon-wrap">
                            <svg viewBox="0 0 64 64" xmlns="http://www.w3.org/2000/svg">
                                <circle cx="28" cy="28" r="18" fill="none" stroke="#74b9ff" stroke-width="4"/>
                                <line x1="42" y1="42" x2="54" y2="54" stroke="#74b9ff" stroke-width="5" stroke-linecap="round"/>
                                <circle cx="22" cy="26" r="3" fill="#74b9ff"/>
                            </svg>
                        </div>
                        <div class="tool-name">快速搜索</div>
                    </div>
                </div>
            </div>

            <div class="tools-category">
                <div class="tools-category-title">计算工具</div>
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
                        <div class="tool-name">计算器</div>
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
                        <div class="tool-name">单位换算</div>
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
                        <div class="tool-name">二维码生成</div>
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
                        <div class="tool-name">JSON格式化</div>
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
                        <div class="tool-name">Base64编解码</div>
                    </div>
                </div>
            </div>

            <div class="tools-category">
                <div class="tools-category-title">创意工具</div>
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
                        <div class="tool-name">颜色选择器</div>
                    </div>
                    <div class="tool-card" onclick="openTool('gradient')">
                        <div class="icon-wrap">
                            <svg viewBox="0 0 64 64" xmlns="http://www.w3.org/2000/svg">
                                <defs><linearGradient id="gradientIcon" x1="0%" y1="0%" x2="0%" y2="100%"><stop offset="0%" stop-color="#e74c3c"/><stop offset="20%" stop-color="#f39c12"/><stop offset="40%" stop-color="#2ecc71"/><stop offset="60%" stop-color="#3498db"/><stop offset="80%" stop-color="#9b59b6"/><stop offset="100%" stop-color="#fd79a8"/></linearGradient></defs>
                                <rect x="16" y="8" width="32" height="48" rx="8" fill="url(#gradientIcon)"/>
                            </svg>
                        </div>
                        <div class="tool-name">渐变色生成器</div>
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
                        <div class="tool-name">密码生成器</div>
                    </div>
                </div>
            </div>

            <div class="tools-category">
                <div class="tools-category-title">娱乐工具</div>
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
                        <div class="tool-name">骰子</div>
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
                        <div class="tool-name">抛硬币</div>
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
                        <div class="tool-name">随机抽取</div>
                    </div>
                    <div class="tool-card" onclick="openTool('fortune')">
                        <div class="icon-wrap">
                            <svg viewBox="0 0 64 64" xmlns="http://www.w3.org/2000/svg">
                                <defs><linearGradient id="fortuneGrad" x1="0%" y1="0%" x2="100%" y2="100%"><stop offset="0%" stop-color="#e17055"/><stop offset="100%" stop-color="#d63031"/></linearGradient></defs>
                                <path d="M16 40 Q16 12 32 12 Q48 12 48 40 L48 52 Q32 48 16 52 Z" fill="url(#fortuneGrad)"/>
                                <path d="M16 40 Q32 36 48 40" fill="none" stroke="#e17055" stroke-width="3"/>
                            </svg>
                        </div>
                        <div class="tool-name">今日运势</div>
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
                        <div class="tool-name">幸运转盘</div>
                    </div>
                    <div class="tool-card" onclick="openTool('scratch')">
                        <div class="icon-wrap">
                            <svg viewBox="0 0 64 64" xmlns="http://www.w3.org/2000/svg">
                                <defs><linearGradient id="scratchGrad" x1="0%" y1="0%" x2="100%" y2="100%"><stop offset="0%" stop-color="#b2bec3"/><stop offset="100%" stop-color="#636e72"/></linearGradient></defs>
                                <rect x="8" y="8" width="48" height="48" rx="8" fill="url(#scratchGrad)"/>
                                <rect x="16" y="16" width="32" height="32" rx="4" fill="rgba(255,255,255,0.15)"/>
                                <text x="32" y="38" text-anchor="middle" font-size="14" fill="rgba(255,255,255,0.5)" font-weight="bold">刮开</text>
                            </svg>
                        </div>
                        <div class="tool-name">刮刮卡</div>
                    </div>
                </div>
            </div>

            <div class="tools-category">
                <div class="tools-category-title">查询工具</div>
                <div class="tool-grid">
                    <div class="tool-card" onclick="openTool('weather')">
                        <div class="icon-wrap">
                            <svg viewBox="0 0 64 64" xmlns="http://www.w3.org/2000/svg">
                                <defs><linearGradient id="weatherGrad" x1="0%" y1="0%" x2="100%" y2="100%"><stop offset="0%" stop-color="#74b9ff"/><stop offset="100%" stop-color="#0984e3"/></linearGradient></defs>
                                <circle cx="28" cy="24" r="14" fill="#fdcb6e"/>
                                <path d="M14 42 Q14 34 20 32 Q28 26 38 28 Q46 34 50 38 Q52 42 50 46 Q48 50 44 50 L20 50 Q14 50 14 46 Z" fill="url(#weatherGrad)"/>
                            </svg>
                        </div>
                        <div class="tool-name">天气</div>
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
                        <div class="tool-name">IP查询</div>
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
                        <div class="tool-name">每日一言</div>
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
                        <div class="tool-name">功能投票</div>
                    </div>
                </div>
            </div>

            <div class="tools-category">
                <div class="tools-category-title">媒体工具</div>
                <div class="tool-grid">
                    <div class="tool-card" onclick="openTool('music')">
                        <div class="icon-wrap">
                            <svg viewBox="0 0 64 64" xmlns="http://www.w3.org/2000/svg">
                                <defs><linearGradient id="musicGrad" x1="0%" y1="0%" x2="100%" y2="100%"><stop offset="0%" stop-color="#a29bfe"/><stop offset="100%" stop-color="#6c5ce7"/></linearGradient></defs>
                                <circle cx="32" cy="32" r="22" fill="url(#musicGrad)"/>
                                <circle cx="32" cy="32" r="4" fill="#fff"/>
                                <path d="M40 18 Q50 22 44 38" fill="none" stroke="#fff" stroke-width="3" stroke-linecap="round"/>
                                <path d="M46 14 Q58 20 50 42" fill="none" stroke="rgba(255,255,255,0.5)" stroke-width="2" stroke-linecap="round"/>
                            </svg>
                        </div>
                        <div class="tool-name">音乐播放器</div>
                    </div>
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
                        <div class="tool-name">照片墙</div>
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
                        <div class="tool-name">文字转语音</div>
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
                        <div class="tool-name">网页截图</div>
                    </div>
                </div>
            </div>

            <div class="tools-category">
                <div class="tools-category-title">其他工具</div>
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
                        <div class="tool-name">投票系统</div>
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
                        <div class="tool-name">成就徽章</div>
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
                        <div class="tool-name">护眼模式</div>
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
                        <div class="tool-name">阅读模式</div>
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
                        <div class="tool-name">站点地图</div>
                    </div>
                </div>
            </div>
        </div>
    </main>

    <div id="modal-container"></div>
    <div class="eye-protection-overlay" id="eyeOverlay"></div>

    <button class="fab" id="postFab" title="发帖" aria-label="发帖" style="display:none">
        <svg width="24" height="24" viewBox="0 0 24 24" fill="none">
            <circle cx="12" cy="12" r="12" fill="#4A90D9"/>
            <line x1="12" y1="7" x2="12" y2="17" stroke="#fff" stroke-width="2.4" stroke-linecap="round"/>
            <line x1="7" y1="12" x2="17" y2="12" stroke="#fff" stroke-width="2.4" stroke-linecap="round"/>
        </svg>
    </button>

    <button class="back-to-top" id="backToTop" title="返回顶部" aria-label="返回顶部">
        <svg width="20" height="20" viewBox="0 0 24 24" fill="none">
            <circle cx="12" cy="12" r="11" fill="#B0BEC5"/>
            <line x1="12" y1="16" x2="12" y2="8" stroke="#fff" stroke-width="2.4" stroke-linecap="round"/>
            <polyline points="8 12 12 8 16 12" fill="none" stroke="#fff" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"/>
        </svg>
    </button>

    <nav class="mobile-bottom-nav" id="mobileNav">
        <a href="/" class="mobile-nav-item">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg>
            <span>首页</span>
        </a>
        <a href="/pages/post.php" class="mobile-nav-item">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
            <span>发帖</span>
        </a>
        <?php if ($user): ?>
        <a href="/pages/user_center.php" class="mobile-nav-item">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
            <span>我的</span>
        </a>
        <?php else: ?>
        <a href="/pages/login.php" class="mobile-nav-item">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M15 3h4a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-4"/><polyline points="10 17 15 12 10 7"/><line x1="15" y1="12" x2="3" y2="12"/></svg>
            <span>登录</span>
        </a>
        <?php endif; ?>
        <a href="/pages/user_center.php#my-favorites" class="mobile-nav-item">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg>
            <span>收藏</span>
        </a>
        <a href="/pages/browser.php" class="mobile-nav-item">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="2" y1="12" x2="22" y2="12"/><path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"/></svg>
            <span>导航</span>
        </a>
    </nav>

    <footer class="site-footer">
        <div class="container">
            <p>本平台为学生自发搭建交流平台，不属于淮南市北师大实验中学官方平台</p>
            <p>&copy; 2026 <?= htmlspecialchars($siteName) ?> · 蕭遞版权所有</p>
            <p>GitHub 开源地址（可点击跳转）：<a href="<?= htmlspecialchars(GITHUB_REPO_URL) ?>" target="_blank" rel="noopener noreferrer"><?= htmlspecialchars(GITHUB_REPO_NAME) ?></a></p>
        </div>
    </footer>

    <script>
    let activeModal = null;

    function openTool(name) {
        if (activeModal) closeModal();
        const container = document.getElementById('modal-container');
        const content = toolContents[name] || '';
        container.innerHTML = `
            <div class="tool-modal-overlay" onclick="closeModal()">
                <div class="tool-modal" onclick="event.stopPropagation()">
                    <div class="tool-modal-header">
                        <h2>${toolIcons[name] || ''} ${toolNames[name] || name}</h2>
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
        var audio = document.getElementById('music-audio');
        if (audio) { audio.pause(); audio.src = ''; }
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
        pomodoro: '番茄钟', countdown: '倒计时', stopwatch: '秒表', clock: '时钟',
        todo: '待办事项', sticky: '便签', wordcount: '字数统计', search: '快速搜索',
        calculator: '计算器', converter: '单位换算', qrcode: '二维码生成', jsonfmt: 'JSON格式化', base64: 'Base64编解码',
        colorpicker: '颜色选择器', gradient: '渐变色生成器', password: '密码生成器',
        dice: '骰子', coin: '抛硬币', randompick: '随机抽取', fortune: '今日运势', wheel: '幸运转盘', scratch: '刮刮卡',
        weather: '天气', ipquery: 'IP查询', quote: '每日一言', featurevote: '功能投票',
        music: '音乐播放器', photowall: '照片墙', tts: '文字转语音', screenshot: '网页截图',
        vote: '投票系统', badges: '成就徽章', eye: '护眼模式', reading: '阅读模式', sitemap: '站点地图'
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
        scratch: '<svg viewBox="0 0 64 64" width="24" height="24"><defs><linearGradient id="tic18" x1="0%" y1="0%" x2="100%" y2="100%"><stop offset="0%" stop-color="#b2bec3"/><stop offset="100%" stop-color="#636e72"/></linearGradient></defs><rect x="8" y="8" width="48" height="48" rx="8" fill="url(#tic18)"/><rect x="16" y="16" width="32" height="32" rx="4" fill="rgba(255,255,255,0.15)"/><text x="32" y="38" text-anchor="middle" font-size="14" fill="rgba(255,255,255,0.5)" font-weight="bold">刮开</text></svg>',
        weather: '<svg viewBox="0 0 64 64" width="24" height="24"><defs><linearGradient id="tic19" x1="0%" y1="0%" x2="100%" y2="100%"><stop offset="0%" stop-color="#74b9ff"/><stop offset="100%" stop-color="#0984e3"/></linearGradient></defs><circle cx="28" cy="24" r="14" fill="#fdcb6e"/><path d="M14 42 Q14 34 20 32 Q28 26 38 28 Q46 34 50 38 Q52 42 50 46 Q48 50 44 50 L20 50 Q14 50 14 46 Z" fill="url(#tic19)"/></svg>',
        ipquery: '<svg viewBox="0 0 64 64" width="24" height="24"><defs><linearGradient id="tic20" x1="0%" y1="0%" x2="100%" y2="100%"><stop offset="0%" stop-color="#3498db"/><stop offset="100%" stop-color="#2c3e50"/></linearGradient></defs><circle cx="32" cy="32" r="24" fill="none" stroke="url(#tic20)" stroke-width="3"/><ellipse cx="32" cy="32" rx="14" ry="24" fill="none" stroke="url(#tic20)" stroke-width="2"/><line x1="8" y1="32" x2="56" y2="32" stroke="url(#tic20)" stroke-width="2"/></svg>',
        quote: '<svg viewBox="0 0 64 64" width="24" height="24"><defs><linearGradient id="tic21" x1="0%" y1="0%" x2="100%" y2="100%"><stop offset="0%" stop-color="#fd79a8"/><stop offset="100%" stop-color="#e84393"/></linearGradient></defs><rect x="12" y="10" width="40" height="44" rx="20" fill="url(#tic21)"/><polygon points="44,36 44,48 52,44" fill="#fff" opacity="0.8"/><line x1="24" y1="22" x2="40" y2="22" stroke="rgba(255,255,255,0.5)" stroke-width="2"/><line x1="24" y1="30" x2="38" y2="30" stroke="rgba(255,255,255,0.4)" stroke-width="2"/></svg>',
        featurevote: '<svg viewBox="0 0 64 64" width="24" height="24"><defs><linearGradient id="ticfv" x1="0%" y1="0%" x2="100%" y2="100%"><stop offset="0%" stop-color="#fbc531"/><stop offset="100%" stop-color="#e84118"/></linearGradient></defs><circle cx="20" cy="22" r="9" fill="url(#ticfv)"/><path d="M12 34 h16 v18 H12 z" fill="url(#ticfv)"/><path d="M44 12 l-7 12 h7 l-8 14 h16 l-6-14 h8 z" fill="#ffd32a"/></svg>',
        music: '<svg viewBox="0 0 64 64" width="24" height="24"><defs><linearGradient id="tic22" x1="0%" y1="0%" x2="100%" y2="100%"><stop offset="0%" stop-color="#a29bfe"/><stop offset="100%" stop-color="#6c5ce7"/></linearGradient></defs><circle cx="32" cy="32" r="22" fill="url(#tic22)"/><circle cx="32" cy="32" r="4" fill="#fff"/><path d="M40 18 Q50 22 44 38" fill="none" stroke="#fff" stroke-width="3"/><path d="M46 14 Q58 20 50 42" fill="none" stroke="rgba(255,255,255,0.5)" stroke-width="2"/></svg>',
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
        pomodoro: `
            <div class="timer-display" id="pomodoro-display">25:00</div>
            <div class="timer-label" id="pomodoro-label">工作时间</div>
            <div class="t-btn-row" style="justify-content:center">
                <button class="t-btn t-btn-primary" id="pomodoro-start" onclick="pomodoroStart()">开始</button>
                <button class="t-btn t-btn-secondary" id="pomodoro-pause" onclick="pomodoroPause()" style="display:none">暂停</button>
                <button class="t-btn t-btn-danger" onclick="pomodoroReset()">重置</button>
            </div>
            <div class="presets" style="justify-content:center;margin-top:14px">
                <span class="preset active" onclick="pomodoroSetMode('work')">工作 25分钟</span>
                <span class="preset" onclick="pomodoroSetMode('break')">休息 5分钟</span>
            </div>
        `,
        countdown: `
            <div class="time-input-row">
                <input type="number" class="t-input" id="cd-h" value="0" min="0" max="99" placeholder="时">
                <span>:</span>
                <input type="number" class="t-input" id="cd-m" value="1" min="0" max="59" placeholder="分">
                <span>:</span>
                <input type="number" class="t-input" id="cd-s" value="0" min="0" max="59" placeholder="秒">
            </div>
            <div class="timer-display" id="cd-display">01:00</div>
            <div class="t-btn-row" style="justify-content:center">
                <button class="t-btn t-btn-primary" id="cd-start" onclick="countdownStart()">开始</button>
                <button class="t-btn t-btn-secondary" id="cd-pause" onclick="countdownPause()" style="display:none">暂停</button>
                <button class="t-btn t-btn-danger" onclick="countdownReset()">重置</button>
            </div>
            <div class="presets" style="justify-content:center;margin-top:14px">
                <span class="preset" onclick="countdownPreset(60)">1分钟</span>
                <span class="preset" onclick="countdownPreset(180)">3分钟</span>
                <span class="preset" onclick="countdownPreset(300)">5分钟</span>
                <span class="preset" onclick="countdownPreset(600)">10分钟</span>
            </div>
        `,
        stopwatch: `
            <div class="timer-display" id="sw-display">00:00.00</div>
            <div class="t-btn-row" style="justify-content:center">
                <button class="t-btn t-btn-primary" id="sw-start" onclick="stopwatchStart()">开始</button>
                <button class="t-btn t-btn-secondary" id="sw-pause" onclick="stopwatchPause()" style="display:none">暂停</button>
                <button class="t-btn t-btn-danger" id="sw-reset" onclick="stopwatchReset()">重置</button>
                <button class="t-btn t-btn-secondary" onclick="stopwatchLap()">计次</button>
            </div>
            <ul class="lap-list" id="sw-laps"></ul>
        `,
        clock: `
            <canvas class="analog-clock" id="analog-clock" width="180" height="180"></canvas>
            <div class="digital-clock" id="digital-clock"></div>
        `,
        todo: `
            <div class="todo-input-row">
                <input type="text" class="t-input" id="todo-input" placeholder="添加待办事项..." onkeydown="if(event.key==='Enter')todoAdd()">
                <button class="t-btn t-btn-primary" onclick="todoAdd()">添加</button>
            </div>
            <ul class="todo-list" id="todo-list"></ul>
        `,
        sticky: `
            <div class="note-colors" id="note-colors">
                <span class="note-color active" style="background:#f9ca24" data-color="#f9ca24" onclick="stickySetColor(this)"></span>
                <span class="note-color" style="background:#ff6b6b" data-color="#ff6b6b" onclick="stickySetColor(this)"></span>
                <span class="note-color" style="background:#74b9ff" data-color="#74b9ff" onclick="stickySetColor(this)"></span>
                <span class="note-color" style="background:#2ecc71" data-color="#2ecc71" onclick="stickySetColor(this)"></span>
                <span class="note-color" style="background:#a29bfe" data-color="#a29bfe" onclick="stickySetColor(this)"></span>
            </div>
            <div class="todo-input-row">
                <textarea class="t-textarea" id="sticky-input" rows="2" placeholder="写便签..."></textarea>
                <button class="t-btn t-btn-primary" onclick="stickyAdd()">添加</button>
            </div>
            <div class="sticky-notes" id="sticky-notes"></div>
        `,
        wordcount: `
            <textarea class="t-textarea" id="wc-input" rows="6" placeholder="输入文字..." oninput="wordCount()"></textarea>
            <div class="t-form-group" style="margin-top:10px">
                <p style="font-size:0.9rem;color:var(--text)">字数：<strong id="wc-chars">0</strong> | 字符数：<strong id="wc-bytes">0</strong> | 行数：<strong id="wc-lines">0</strong></p>
            </div>
        `,
        search: `
            <div class="todo-input-row">
                <input type="text" class="t-input" id="search-query" placeholder="搜索关键词...">
                <button class="t-btn t-btn-primary" onclick="quickSearch()">搜索</button>
            </div>
            <select class="t-select" id="search-engine" style="margin-top:8px">
                <option value="https://www.baidu.com/s?wd=">百度</option>
                <option value="https://www.google.com/search?q=">Google</option>
                <option value="https://cn.bing.com/search?q=">Bing</option>
            </select>
            <ul class="search-results" id="search-results" style="display:none"></ul>
        `,
        calculator: `
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
        `,
        converter: `
            <div class="t-form-group"><label class="t-label">类型</label>
                <select class="t-select" id="conv-type" onchange="converterUpdate()">
                    <option value="length">长度</option><option value="weight">重量</option><option value="temperature">温度</option><option value="area">面积</option><option value="volume">体积</option><option value="speed">速度</option>
                </select>
            </div>
            <div class="converter-row">
                <div class="t-form-group"><label class="t-label">从</label><input type="number" class="t-input" id="conv-from-val" value="1" oninput="converterCalc()"></div>
                <div class="t-form-group"><select class="t-select" id="conv-from-unit" onchange="converterCalc()"></select></div>
                <button class="swap-btn" onclick="converterSwap()">⇄</button>
                <div class="t-form-group"><select class="t-select" id="conv-to-unit" onchange="converterCalc()"></select></div>
            </div>
            <div class="t-form-group"><label class="t-label">结果</label><input type="text" class="t-input" id="conv-result" readonly></div>
        `,
        qrcode: `
            <div class="t-form-group"><label class="t-label">输入内容</label><input type="text" class="t-input" id="qr-input" placeholder="输入网址或文本..."></div>
            <div class="t-btn-row"><button class="t-btn t-btn-primary" onclick="qrGenerate()">生成二维码</button></div>
            <div class="qr-container" id="qr-container"></div>
        `,
        jsonfmt: `
            <textarea class="t-textarea" id="json-input" rows="5" placeholder="输入JSON字符串..."></textarea>
            <div class="t-btn-row">
                <button class="t-btn t-btn-primary" onclick="jsonFormat()">格式化</button>
                <button class="t-btn t-btn-secondary" onclick="jsonValidate()">验证</button>
                <button class="t-btn t-btn-secondary" onclick="jsonCompress()">压缩</button>
            </div>
            <div class="json-output" id="json-output" style="margin-top:10px;display:none"></div>
        `,
        base64: `
            <textarea class="t-textarea" id="b64-input" rows="4" placeholder="输入文本..."></textarea>
            <div class="t-btn-row">
                <button class="t-btn t-btn-primary" onclick="base64Encode()">编码</button>
                <button class="t-btn t-btn-secondary" onclick="base64Decode()">解码</button>
            </div>
            <textarea class="t-textarea" id="b64-output" rows="4" placeholder="结果..." readonly style="margin-top:10px"></textarea>
        `,
        colorpicker: `
            <div class="t-form-group"><label class="t-label">选择颜色</label><input type="color" class="t-input" id="cp-input" value="#4A90D9" oninput="colorPickerUpdate()" style="height:50px;cursor:pointer"></div>
            <div class="color-preview" id="cp-preview" style="background:#4A90D9"></div>
            <div class="color-values">
                <div class="color-val">HEX<strong id="cp-hex">#4A90D9</strong></div>
                <div class="color-val">RGB<strong id="cp-rgb">74,144,217</strong></div>
                <div class="color-val">HSL<strong id="cp-hsl">210,65%,57%</strong></div>
            </div>
        `,
        gradient: `
            <div class="dual-colors">
                <div class="color-half"><label class="t-label">颜色1</label><input type="color" class="t-input" id="gd-c1" value="#4A90D9" oninput="gradientUpdate()" style="height:40px"></div>
                <div class="color-half"><label class="t-label">颜色2</label><input type="color" class="t-input" id="gd-c2" value="#f9ca24" oninput="gradientUpdate()" style="height:40px"></div>
            </div>
            <div class="t-form-group"><label class="t-label">方向</label><select class="t-select" id="gd-dir" onchange="gradientUpdate()"><option value="to right">水平</option><option value="to bottom">垂直</option><option value="to bottom right">对角线</option><option value="to top right">对角向上</option></select></div>
            <div class="gradient-preview" id="gd-preview" style="background:linear-gradient(to right, #4A90D9, #f9ca24)"></div>
            <div class="css-output" id="gd-css">background: linear-gradient(to right, #4A90D9, #f9ca24);</div>
        `,
        password: `
            <div class="t-form-group"><label class="t-label">长度：<span id="pw-len-label">12</span></label><input type="range" id="pw-len" min="6" max="32" value="12" oninput="passwordGen()"></div>
            <div class="t-form-group" style="display:flex;gap:12px;flex-wrap:wrap">
                <label style="font-size:0.85rem;display:flex;align-items:center;gap:4px"><input type="checkbox" id="pw-upper" checked onchange="passwordGen()">大写</label>
                <label style="font-size:0.85rem;display:flex;align-items:center;gap:4px"><input type="checkbox" id="pw-lower" checked onchange="passwordGen()">小写</label>
                <label style="font-size:0.85rem;display:flex;align-items:center;gap:4px"><input type="checkbox" id="pw-num" checked onchange="passwordGen()">数字</label>
                <label style="font-size:0.85rem;display:flex;align-items:center;gap:4px"><input type="checkbox" id="pw-sym" onchange="passwordGen()">符号</label>
            </div>
            <div class="password-display"><span id="pw-display"></span><button class="copy-btn" onclick="passwordCopy()">复制</button></div>
            <div class="t-btn-row"><button class="t-btn t-btn-primary" onclick="passwordGen()">重新生成</button></div>
        `,
        dice: `
            <div class="dice-container"><div class="dice-face" id="dice-face">🎲</div><div class="dice-result" id="dice-result"></div></div>
            <div class="t-btn-row" style="justify-content:center"><button class="t-btn t-btn-primary" onclick="diceRoll()">掷骰子</button></div>
        `,
        coin: `
            <div class="coin-container"><div class="coin" id="coin"><div class="coin-face coin-front">1</div><div class="coin-face coin-back">0</div></div></div>
            <div class="coin-result" id="coin-result"></div>
            <div class="t-btn-row" style="justify-content:center"><button class="t-btn t-btn-primary" onclick="coinFlip()">抛硬币</button></div>
        `,
        randompick: `
            <textarea class="t-textarea" id="rp-input" rows="4" placeholder="每行一个选项..."></textarea>
            <div class="t-btn-row">
                <button class="t-btn t-btn-primary" onclick="randomPick()">随机抽取</button>
                <button class="t-btn t-btn-secondary" onclick="randomPickClear()">清空</button>
            </div>
            <div style="text-align:center;margin-top:14px;font-size:1.3rem;font-weight:600;color:var(--primary)" id="rp-result"></div>
        `,
        fortune: `
            <div class="fortune-result" id="fortune-result" style="display:none">
                <div class="fortune-icon" id="fortune-icon"></div>
                <div class="fortune-text" id="fortune-text"></div>
                <div class="fortune-detail" id="fortune-detail"></div>
            </div>
            <div class="t-btn-row" style="justify-content:center"><button class="t-btn t-btn-primary" onclick="fortuneDraw()">抽取今日运势</button></div>
        `,
        wheel: `
            <div class="t-form-group"><label class="t-label">奖品（每行一个）</label><textarea class="t-textarea" id="wheel-items" rows="3" placeholder="一等奖&#10;二等奖&#10;三等奖&#10;谢谢参与"></textarea></div>
            <div class="wheel-wrap" style="display:block;text-align:center"><div class="wheel-pointer"></div><canvas id="wheel-canvas" width="300" height="300"></canvas></div>
            <div style="text-align:center;margin-top:10px;font-size:1.1rem;font-weight:600;color:var(--primary)" id="wheel-result"></div>
            <div class="t-btn-row" style="justify-content:center"><button class="t-btn t-btn-primary" onclick="wheelSpin()">开始抽奖</button></div>
        `,
        scratch: `
            <div class="t-form-group"><label class="t-label">奖品文本</label><input type="text" class="t-input" id="scratch-text" value="恭喜中奖！"></div>
            <div class="scratch-wrap"><canvas id="scratch-canvas" width="300" height="150"></canvas></div>
            <div class="t-btn-row" style="justify-content:center"><button class="t-btn t-btn-secondary" onclick="scratchReset()">重新刮奖</button></div>
        `,
        weather: `
            <div class="todo-input-row">
                <input type="text" class="t-input" id="weather-city" placeholder="输入城市名，如：淮南" value="淮南">
                <button class="t-btn t-btn-primary" onclick="weatherFetch()">查询</button>
            </div>
            <div class="weather-card" id="weather-card" style="display:none"></div>
        `,
        ipquery: `
            <div class="todo-input-row">
                <input type="text" class="t-input" id="ip-addr" placeholder="输入IP地址，留空查询本机">
                <button class="t-btn t-btn-primary" onclick="ipQuery()">查询</button>
            </div>
            <div class="ip-info-card" id="ip-info" style="display:none;margin-top:10px"></div>
        `,
        quote: `
            <div class="t-btn-row" style="justify-content:center"><button class="t-btn t-btn-primary" onclick="quoteFetch()">获取每日一言</button></div>
            <div style="text-align:center;margin-top:14px;padding:16px;background:var(--bg);border-radius:var(--radius);border:1px solid var(--border);font-size:1.1rem;line-height:1.8;color:var(--text)" id="quote-text"></div>
        `,
        featurevote: `
            <div style="display:flex;gap:8px;margin-bottom:14px;">
                <input type="text" class="t-input" id="fv-input" placeholder="提出你的新功能建议..." maxlength="500" style="flex:1">
                <button class="t-btn t-btn-primary" onclick="featureVoteCreate()">提交建议</button>
            </div>
            <div style="font-size:0.85rem;color:var(--text-muted);margin-bottom:12px;">登录用户可提交功能建议并投票，管理员会在后台查看落地情况。每位用户最多提交 20 条。</div>
            <div id="fv-list"></div>
        `,
        music: `
            <div class="music-player-inner">
                <div class="timer-display" style="font-size:1.5rem" id="music-title">选择歌曲</div>
                <audio id="music-audio" style="display:none"></audio>
                <div class="music-controls">
                    <button onclick="musicPrev()">⏮</button>
                    <button class="play-btn" id="music-play-btn" onclick="musicToggle()">▶</button>
                    <button onclick="musicNext()">⏭</button>
                </div>
                <ul class="playlist" id="music-playlist">
                    <li onclick="musicPlay('https://music.163.com/song/media/outer/url?id=186001', '晴天 - 周杰伦')">晴天 - 周杰伦</li>
                    <li onclick="musicPlay('https://music.163.com/song/media/outer/url?id=5257138', '夜曲 - 周杰伦')">夜曲 - 周杰伦</li>
                    <li onclick="musicPlay('https://music.163.com/song/media/outer/url?id=191254', '起风了 - 买辣椒也用券')">起风了 - 买辣椒也用券</li>
                    <li onclick="musicPlay('https://music.163.com/song/media/outer/url?id=5264651', '稻香 - 周杰伦')">稻香 - 周杰伦</li>
                </ul>
            </div>
        `,
        photowall: `
            <div class="t-form-group"><label class="t-label">上传图片</label><input type="file" class="t-input" id="photo-input" accept="image/*" multiple onchange="photoWallAdd()"></div>
            <div class="photo-grid" id="photo-grid"></div>
        `,
        tts: `
            <textarea class="t-textarea" id="tts-input" rows="3" placeholder="输入要朗读的文字..."></textarea>
            <div class="tts-controls" style="margin-top:10px">
                <select class="t-select" id="tts-voice" style="flex:1"></select>
                <button class="t-btn t-btn-primary" onclick="ttsSpeak()">朗读</button>
                <button class="t-btn t-btn-secondary" onclick="ttsStop()">停止</button>
            </div>
        `,
        screenshot: `
            <div class="t-btn-row" style="justify-content:center"><button class="t-btn t-btn-primary" onclick="screenshotCapture()">截取当前页面</button></div>
            <div style="text-align:center;margin-top:10px;font-size:0.85rem;color:var(--text-muted)">使用html2canvas截图，截图后右键保存</div>
            <div id="screenshot-result" style="margin-top:10px;text-align:center"></div>
        `,
        vote: `
            <div class="t-form-group"><label class="t-label">投票标题</label><input type="text" class="t-input" id="vote-title" placeholder="投票主题"></div>
            <div id="vote-options">
                <div class="vote-option"><input type="text" class="t-input" placeholder="选项1"><button class="t-btn t-btn-danger t-btn-sm" onclick="this.parentElement.remove()">×</button></div>
                <div class="vote-option"><input type="text" class="t-input" placeholder="选项2"><button class="t-btn t-btn-danger t-btn-sm" onclick="this.parentElement.remove()">×</button></div>
            </div>
            <div class="t-btn-row">
                <button class="t-btn t-btn-secondary" onclick="voteAddOption()">添加选项</button>
                <button class="t-btn t-btn-primary" onclick="voteStart()">开始投票</button>
            </div>
            <div id="vote-results" style="margin-top:10px"></div>
        `,
        badges: `
            <div class="badge-grid">
                <div class="badge-item"><svg viewBox="0 0 64 64"><defs><linearGradient id="bg1" x1="0%" y1="0%" x2="100%" y2="100%"><stop offset="0%" stop-color="#f9ca24"/><stop offset="100%" stop-color="#e1b12c"/></linearGradient></defs><circle cx="32" cy="32" r="28" fill="url(#bg1)"/><polygon points="32,12 34,22 38,22 35,25 36,29 32,26 28,29 29,25 26,22 30,22" fill="#fff" opacity="0.9"/></svg><div class="badge-name">发帖达人</div><div class="badge-desc">发布10篇帖子</div></div>
                <div class="badge-item"><svg viewBox="0 0 64 64"><defs><linearGradient id="bg2" x1="0%" y1="0%" x2="100%" y2="100%"><stop offset="0%" stop-color="#3498db"/><stop offset="100%" stop-color="#2980b9"/></linearGradient></defs><circle cx="32" cy="32" r="28" fill="url(#bg2)"/><text x="32" y="38" text-anchor="middle" fill="#fff" font-size="20" font-weight="bold">100</text></svg><div class="badge-name">百帖成就</div><div class="badge-desc">发布100篇帖子</div></div>
                <div class="badge-item"><svg viewBox="0 0 64 64"><defs><linearGradient id="bg3" x1="0%" y1="0%" x2="100%" y2="100%"><stop offset="0%" stop-color="#ff6b6b"/><stop offset="100%" stop-color="#c0392b"/></linearGradient></defs><circle cx="32" cy="32" r="28" fill="url(#bg3)"/><polygon points="32,16 25,48 32,36 39,48" fill="#fff" opacity="0.9"/></svg><div class="badge-name">抢到沙发</div><div class="badge-desc">第一个回复别人</div></div>
                <div class="badge-item"><svg viewBox="0 0 64 64"><defs><linearGradient id="bg4" x1="0%" y1="0%" x2="100%" y2="100%"><stop offset="0%" stop-color="#2ecc71"/><stop offset="100%" stop-color="#27ae60"/></linearGradient></defs><circle cx="32" cy="32" r="28" fill="url(#bg4)"/><text x="32" y="38" text-anchor="middle" fill="#fff" font-size="20" font-weight="bold">7</text></svg><div class="badge-name">连续签到</div><div class="badge-desc">连续签到7天</div></div>
                <div class="badge-item"><svg viewBox="0 0 64 64"><defs><linearGradient id="bg5" x1="0%" y1="0%" x2="100%" y2="100%"><stop offset="0%" stop-color="#a29bfe"/><stop offset="100%" stop-color="#6c5ce7"/></linearGradient></defs><circle cx="32" cy="32" r="28" fill="url(#bg5)"/><path d="M20 32 L28 40 L44 24" fill="none" stroke="#fff" stroke-width="4" stroke-linecap="round" stroke-linejoin="round"/></svg><div class="badge-name">实名认证</div><div class="badge-desc">完成实名认证</div></div>
                <div class="badge-item"><svg viewBox="0 0 64 64"><defs><linearGradient id="bg6" x1="0%" y1="0%" x2="100%" y2="100%"><stop offset="0%" stop-color="#fd79a8"/><stop offset="100%" stop-color="#e84393"/></linearGradient></defs><circle cx="32" cy="32" r="28" fill="url(#bg6)"/><polygon points="32,12 38,26 52,26 40,34 44,48 32,40 20,48 24,34 12,26 26,26" fill="#fff" opacity="0.9"/></svg><div class="badge-name">热门帖子</div><div class="badge-desc">帖子获得10个赞</div></div>
            </div>
        `,
        eye: `
            <div style="text-align:center;padding:10px">
                <p style="color:var(--text-secondary);margin-bottom:14px">护眼模式会为屏幕添加柔和的暖色滤镜，减少蓝光刺激</p>
                <button class="t-btn t-btn-primary" id="eye-toggle" onclick="eyeToggle()">开启护眼模式</button>
            </div>
        `,
        reading: `
            <div style="text-align:center;padding:10px">
                <p style="color:var(--text-secondary);margin-bottom:14px">阅读模式会切换为纸张般的柔和背景，适合长时间阅读</p>
                <button class="t-btn t-btn-primary" id="reading-toggle" onclick="readingToggle()">开启阅读模式</button>
            </div>
        `,
        sitemap: `
            <ul class="sitemap-tree">
                <li><a href="/">🏠 首页</a></li>
                <li><a href="/pages/login.php">🔑 登录/注册</a></li>
                <li><a href="/pages/post.php">📝 发布帖子</a></li>
                <li><a href="/pages/user_center.php">👤 个人中心</a></li>
                <li><a href="/pages/tools.php">🛠 实用工具</a></li>
                <li><a href="/pages/browser.php">🌐 网页导航</a></li>
                <li><a href="/admin/">⚙ 管理后台</a></li>
            </ul>
        `
    };

    let pomodoroTimer = null, cdTimer = null, swTimer = null, clockInterval = null;
    let pomodoroMode = 'work', pomodoroSeconds = 25 * 60;
    let swStartTime = 0, swElapsed = 0, swRunning = false, swLaps = [];
    let cdRemaining = 60, cdRunning = false;
    let calcExpr = '';
    let musicIndex = 0, musicList = [];
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
                case 'music': musicInit(); break;
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
                if (label) label.textContent = pomodoroMode === 'work' ? '休息时间！' : '工作时间！';
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
                showToast(pomodoroMode === 'work' ? '工作时间开始！' : '休息时间开始！');
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
        if (label) label.textContent = '工作时间';
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
        if (label) label.textContent = mode === 'work' ? '工作时间' : '休息时间';
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
                showToast('倒计时结束！');
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
                li.innerHTML = '<span>计次 ' + (i + 1) + '</span><span>' + lap + '</span>';
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
            document.getElementById('calc-display').textContent = '错误';
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
            output.textContent = 'JSON解析错误：' + e.message;
        }
    }

    function jsonValidate() {
        var input = document.getElementById('json-input').value;
        var output = document.getElementById('json-output');
        try {
            JSON.parse(input);
            output.style.display = 'block';
            output.className = 'json-output';
            output.textContent = '✓ JSON格式正确';
        } catch(e) {
            output.style.display = 'block';
            output.className = 'json-output json-error';
            output.textContent = '✗ JSON格式错误：' + e.message;
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
            output.textContent = '压缩失败：' + e.message;
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
            document.getElementById('b64-output').value = '解码失败：' + e.message;
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
        if (!chars) { document.getElementById('pw-display').textContent = '请选择至少一种字符类型'; return; }
        var pw = '';
        var arr = new Uint32Array(len);
        crypto.getRandomValues(arr);
        for (var i = 0; i < len; i++) pw += chars[arr[i] % chars.length];
        document.getElementById('pw-display').textContent = pw;
    }

    function passwordCopy() {
        var pw = document.getElementById('pw-display').textContent;
        if (!pw) return;
        navigator.clipboard.writeText(pw).then(function() { showToast('已复制到剪贴板'); });
    }

    function diceRoll() {
        var face = document.getElementById('dice-face');
        var result = document.getElementById('dice-result');
        face.classList.add('rolling');
        var num = Math.floor(Math.random() * 6) + 1;
        setTimeout(function() {
            face.classList.remove('rolling');
            face.textContent = num;
            result.textContent = '结果：' + num;
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
            result.textContent = isHeads ? '正面！' : '反面！';
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
            { icon: '🌟', text: '大吉', detail: '今天运势极佳，万事如意！' },
            { icon: '✨', text: '吉', detail: '运势不错，保持好心情！' },
            { icon: '☀️', text: '中吉', detail: '平平淡淡才是真，顺其自然。' },
            { icon: '🌤', text: '小吉', detail: '小有收获，注意细节。' },
            { icon: '🌥', text: '末吉', detail: '需要耐心，好事多磨。' },
            { icon: '🌧', text: '凶', detail: '今日需谨慎行事，避免冲动。' },
            { icon: '⛈', text: '大凶', detail: '诸事不宜，宜静不宜动。' }
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
        if (wheelItems.length === 0) wheelItems = ['一等奖', '二等奖', '三等奖', '谢谢参与'];
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
        var text = document.getElementById('scratch-text').value || '恭喜中奖！';
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
        ctx.fillText('刮开此处', canvas.width / 2, canvas.height / 2 + 8);
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
        var city = document.getElementById('weather-city').value.trim() || '淮南';
        var card = document.getElementById('weather-card');
        card.style.display = 'block';
        card.textContent = '查询中...';
        fetch('/api/weather_proxy.php?city=' + encodeURIComponent(city))
            .then(function(r) { return r.json(); })
            .then(function(data) {
                if (data.error) { card.textContent = data.error; return; }
                card.innerHTML = '<div style="font-size:1.2rem;font-weight:600;margin-bottom:6px">' + city.replace(/</g,'&lt;') + '</div>';
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
                    info.textContent = '湿度：' + data.humidity + ' | 风速：' + (data.wind || '--');
                    card.appendChild(info);
                }
            })
            .catch(function() { card.textContent = '查询失败，请检查城市名'; });
    }

    function ipQuery() {
        var ip = document.getElementById('ip-addr').value.trim();
        var info = document.getElementById('ip-info');
        info.style.display = 'block';
        info.innerHTML = '查询中...';
        var url = ip ? 'https://api.ip.sb/geoip/' + ip : 'https://api.ip.sb/geoip';
        fetch(url)
            .then(function(r) { return r.json(); })
            .then(function(data) {
                var rows = [
                    ['IP', data.ip], ['国家', data.country], ['城市', data.city],
                    ['ISP', data.isp], ['时区', data.timezone], ['经纬度', data.latitude + ', ' + data.longitude]
                ];
                info.innerHTML = '';
                rows.forEach(function(r) {
                    var div = document.createElement('div');
                    div.className = 'ip-info-row';
                    div.innerHTML = '<span class="ip-info-label">' + r[0] + '</span><span class="ip-info-val">' + (r[1] || '--').replace(/</g,'&lt;') + '</span>';
                    info.appendChild(div);
                });
            })
            .catch(function() { info.innerHTML = '查询失败'; });
    }

    function quoteFetch() {
        var el = document.getElementById('quote-text');
        el.textContent = '获取中...';
        fetch('https://v1.hitokoto.cn/?c=d&encode=text')
            .then(function(r) { return r.text(); })
            .then(function(text) { el.textContent = text || '今天的努力，是明天的底气。'; })
            .catch(function() { el.textContent = '今天的努力，是明天的底气。'; });
    }

    function esc(str) {
        return String(str == null ? '' : str).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#39;');
    }

    function featureVoteLoad() {
        var list = document.getElementById('fv-list');
        if (!list) return;
        list.textContent = '加载中...';
        fetch('/api/feature_requests.php')
            .then(function(r) { return r.json(); })
            .then(function(res) {
                if (!res.success) { list.textContent = res.message || '加载失败'; return; }
                var features = res.data || [];
                renderFeatureVoteList(features);
            })
            .catch(function() { list.textContent = '加载失败，请稍后重试'; });
    }

    function renderFeatureVoteList(features) {
        var list = document.getElementById('fv-list');
        if (!list) return;
        if (!features.length) {
            list.innerHTML = '<div style="text-align:center;color:var(--text-muted);padding:20px;">还没有功能建议，快来提出第一条吧！</div>';
            return;
        }
        list.innerHTML = features.map(function(f) {
            var done = f.status === 'done';
            var nick = (f.nickname || ('QQ:' + f.qq) || '用户').replace(/</g, '&lt;');
            var title = (f.title || '').replace(/</g, '&lt;');
            var badge = done
                ? '<span style="background:var(--success-light);color:var(--success);padding:2px 8px;border-radius:10px;font-size:0.75rem;">已实现</span>'
                : '<span style="background:var(--warning-light);color:var(--warning);padding:2px 8px;border-radius:10px;font-size:0.75rem;">待实现</span>';
            var voteBtn = IS_LOGGED_IN
                ? '<button class="t-btn t-btn-sm ' + (f.voted ? 't-btn-secondary' : 't-btn-primary') + '" onclick="featureVoteToggle(' + esc(f.id) + ', this)">' + (f.voted ? '已投票' : '投票') + '</button>'
                : '<a href="/pages/login.php" class="t-btn t-btn-sm t-btn-primary">登录后投票</a>';
            return '<div style="border:1px solid var(--border);border-radius:var(--radius);padding:12px;margin-bottom:10px;">' +
                '<div style="display:flex;align-items:flex-start;justify-content:space-between;gap:10px;">' +
                    '<div style="flex:1;"><div style="font-weight:600;margin-bottom:4px;">' + title + '</div>' +
                    '<div style="font-size:0.78rem;color:var(--text-muted);">by ' + nick + ' · ' + (f.created_at || '') + '</div></div>' +
                    '<div style="text-align:center;">' + badge + '<div style="font-size:1.2rem;font-weight:700;color:var(--primary);margin:4px 0;">' + esc(f.vote_count) + '</div>' + voteBtn + '</div>' +
                '</div></div>';
        }).join('');
    }

    function featureVoteCreate() {
        if (!IS_LOGGED_IN) { showToast('请先登录后再提交功能建议'); return; }
        var input = document.getElementById('fv-input');
        var title = (input.value || '').trim();
        if (title.length < 2) { showToast('功能建议不能少于2个字符'); return; }
        if (title.length > 500) { showToast('功能建议不能超过500个字符'); return; }
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
                if (res.success) { showToast(res.message || '提交成功'); input.value = ''; featureVoteLoad(); }
                else { showToast(res.message || '提交失败'); }
            })
            .catch(function() { showToast('提交失败，请稍后重试'); })
            .then(function() { btn.disabled = false; });
    }

    function featureVoteToggle(id, btn) {
        if (!IS_LOGGED_IN) { showToast('请先登录后再投票'); return; }
        var fd = new FormData();
        fd.append('csrf_token', CSRF_TOKEN);
        fd.append('action', 'vote');
        fd.append('feature_id', id);
        btn.disabled = true;
        fetch('/api/feature_requests.php', { method: 'POST', body: fd })
            .then(function(r) { return r.json(); })
            .then(function(res) {
                if (res.success) { showToast(res.message || '操作成功'); featureVoteLoad(); }
                else { showToast(res.message || '操作失败'); btn.disabled = false; }
            })
            .catch(function() { showToast('操作失败，请稍后重试'); btn.disabled = false; });
    }

    function musicInit() {
        var playlist = document.getElementById('music-playlist');
        if (!playlist) return;
        musicList = [];
        var items = playlist.querySelectorAll('li');
        items.forEach(function(li, i) {
            var onclick = li.getAttribute('onclick');
            var match = onclick.match(/musicPlay\('([^']+)',\s*'([^']+)'\)/);
            if (match) musicList.push({ url: match[1], title: match[2] });
        });
    }

    function musicPlay(url, title) {
        var audio = document.getElementById('music-audio');
        var titleEl = document.getElementById('music-title');
        if (audio) { audio.src = url; audio.play(); }
        if (titleEl) titleEl.textContent = title;
        var btn = document.getElementById('music-play-btn');
        if (btn) btn.textContent = '⏸';
        document.querySelectorAll('#music-playlist li').forEach(function(li) { li.classList.remove('active'); });
        if (event && event.target) event.target.classList.add('active');
    }

    function musicToggle() {
        var audio = document.getElementById('music-audio');
        var btn = document.getElementById('music-play-btn');
        if (!audio) return;
        if (audio.paused) {
            if (!audio.src && musicList.length > 0) {
                musicPlay(musicList[0].url, musicList[0].title);
                return;
            }
            audio.play();
            if (btn) btn.textContent = '⏸';
        } else {
            audio.pause();
            if (btn) btn.textContent = '▶';
        }
    }

    function musicPrev() {
        musicIndex = (musicIndex - 1 + musicList.length) % musicList.length;
        var item = musicList[musicIndex];
        musicPlay(item.url, item.title);
    }

    function musicNext() {
        musicIndex = (musicIndex + 1) % musicList.length;
        var item = musicList[musicIndex];
        musicPlay(item.url, item.title);
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
            if (totalSize + file.size > 10 * 1024 * 1024) { showToast('总大小超过10MB限制'); return; }
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
        if (!window.speechSynthesis) { showToast('浏览器不支持语音合成'); return; }
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
        showToast('加载截图库中...');
        var script = document.createElement('script');
        script.src = 'https://html2canvas.hertzen.com/dist/html2canvas.min.js';
        script.onload = function() {
            html2canvas(document.body).then(function(canvas) {
                var result = document.getElementById('screenshot-result');
                result.innerHTML = '';
                var img = document.createElement('img');
                img.src = canvas.toDataURL();
                img.style.maxWidth = '100%';
                img.style.borderRadius = '8px';
                result.appendChild(img);
                showToast('截图完成，右键可保存');
            }).catch(function() { showToast('截图失败'); });
        };
        script.onerror = function() { showToast('截图库加载失败'); };
        document.head.appendChild(script);
    }

    function voteAddOption() {
        var container = document.getElementById('vote-options');
        var div = document.createElement('div');
        div.className = 'vote-option';
        div.innerHTML = '<input type="text" class="t-input" placeholder="新选项"><button class="t-btn t-btn-danger t-btn-sm" onclick="this.parentElement.remove()">×</button>';
        container.appendChild(div);
    }

    function voteStart() {
        var title = document.getElementById('vote-title').value.trim();
        if (!title) { showToast('请输入投票标题'); return; }
        var optionInputs = document.querySelectorAll('#vote-options input');
        voteData.title = title;
        voteData.options = [];
        voteData.votes = [];
        optionInputs.forEach(function(input) {
            var val = input.value.trim();
            if (val) { voteData.options.push(val); voteData.votes.push(0); }
        });
        if (voteData.options.length < 2) { showToast('至少需要2个选项'); return; }
        var results = document.getElementById('vote-results');
        results.innerHTML = '<h3 style="margin-bottom:10px">' + title.replace(/</g,'&lt;') + '</h3>';
        voteData.options.forEach(function(opt, i) {
            results.innerHTML += '<div class="vote-bar-wrap"><div class="vote-bar" style="width:0%">0%</div></div><div class="vote-count">' + opt.replace(/</g,'&lt;') + ' - 0票 <button class="t-btn t-btn-sm t-btn-primary" onclick="voteCast(' + i + ')" style="margin-left:8px">投票</button></div>';
        });
    }

    function voteCast(i) {
        voteData.votes[i]++;
        var total = voteData.votes.reduce(function(a, b) { return a + b; }, 0);
        var results = document.getElementById('vote-results');
        results.innerHTML = '<h3 style="margin-bottom:10px">' + voteData.title.replace(/</g,'&lt;') + '</h3>';
        voteData.options.forEach(function(opt, j) {
            var pct = total > 0 ? Math.round(voteData.votes[j] / total * 100) : 0;
            results.innerHTML += '<div class="vote-bar-wrap"><div class="vote-bar" style="width:' + pct + '%">' + (pct > 0 ? pct + '%' : '') + '</div></div><div class="vote-count">' + opt.replace(/</g,'&lt;') + ' - ' + voteData.votes[j] + '票 <button class="t-btn t-btn-sm t-btn-primary" onclick="voteCast(' + j + ')" style="margin-left:8px">投票</button></div>';
        });
    }

    function eyeInit() {
        var btn = document.getElementById('eye-toggle');
        if (btn) {
            btn.textContent = eyeActive ? '关闭护眼模式' : '开启护眼模式';
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
            btn.textContent = eyeActive ? '关闭护眼模式' : '开启护眼模式';
            btn.className = eyeActive ? 't-btn t-btn-danger' : 't-btn t-btn-primary';
        }
        showToast(eyeActive ? '护眼模式已开启' : '护眼模式已关闭');
    }

    function readingInit() {
        var btn = document.getElementById('reading-toggle');
        if (btn) {
            btn.textContent = readingActive ? '关闭阅读模式' : '开启阅读模式';
            btn.className = readingActive ? 't-btn t-btn-danger' : 't-btn t-btn-primary';
        }
        if (readingActive) document.body.classList.add('reading-mode-body');
    }

    function readingToggle() {
        readingActive = !readingActive;
        document.body.classList.toggle('reading-mode-body', readingActive);
        var btn = document.getElementById('reading-toggle');
        if (btn) {
            btn.textContent = readingActive ? '关闭阅读模式' : '开启阅读模式';
            btn.className = readingActive ? 't-btn t-btn-danger' : 't-btn t-btn-primary';
        }
        showToast(readingActive ? '阅读模式已开启' : '阅读模式已关闭');
    }

    (function(){
        var fab = document.getElementById('postFab');
        if (fab) fab.onclick = function(){ window.location.href = SITE_URL + '/pages/post.php'; };
        var btt = document.getElementById('backToTop');
        if (btt) {
            btt.onclick = function(){ window.scrollTo({top:0,behavior:'smooth'}); };
            window.addEventListener('scroll', function(){
                btt.classList.toggle('show', window.scrollY > 300);
            }, {passive:true});
        }
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
    <script src="/assets/js/main.js?v=<?= asset_ver('/assets/js/main.js') ?>" defer></script>
    <script src="/assets/js/enhancements.js?v=<?= asset_ver('/assets/js/enhancements.js') ?>" defer></script>
</body>
</html>