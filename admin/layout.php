<?php
if (!function_exists('adminHeader')) {

function adminHeader($title, $adminUser, $csrfToken) {
    $page = basename($_SERVER['PHP_SELF']);
    ?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" href="/icon.ico" type="image/x-icon">
    <title><?= xss_clean($title) ?> - 管理后台</title>
    <script>
    const SITE_URL = <?= json_encode(SITE_URL) ?>;
    const CSRF_TOKEN = <?= json_encode($csrfToken) ?>;
    </script>
    <style>
        :root {
            --bg: #F8F9FA;
            --card-bg: #FFFFFF;
            --sidebar-bg: #142B44;
            --sidebar-hover: rgba(255,255,255,0.08);
            --sidebar-active: #C9A96E;
            --text: #1B2A3A;
            --text-secondary: #5A6B7A;
            --text-sidebar: rgba(255,255,255,0.75);
            --text-sidebar-active: #FFFFFF;
            --primary: #1B3A5C;
            --primary-hover: #2A5078;
            --primary-light: #E8EFF5;
            --success: #4A8C5C;
            --success-light: #E8F4EC;
            --warning: #C9A96E;
            --warning-light: #FBF6ED;
            --danger: #C4626A;
            --danger-light: #FAECEE;
            --info: #1B3A5C;
            --info-light: #E8EFF5;
            --border: #DDE2E8;
            --radius: 10px;
            --radius-sm: 6px;
            --shadow: 0 1px 3px rgba(0,0,0,0.06);
            --shadow-md: 0 4px 12px rgba(0,0,0,0.08);
            --transition: 0.2s ease;
        }
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;
            background: var(--bg);
            color: var(--text);
            display: flex;
            min-height: 100vh;
            line-height: 1.6;
        }
        .sidebar {
            width: 250px;
            min-width: 250px;
            background: var(--sidebar-bg);
            color: var(--text-sidebar);
            display: flex;
            flex-direction: column;
            position: fixed;
            top: 0; left: 0; bottom: 0;
            z-index: 100;
            transition: transform var(--transition);
            overflow-y: auto;
        }
        .sidebar-header {
            padding: 24px 20px;
            border-bottom: 1px solid rgba(255,255,255,0.06);
        }
        .sidebar-header .logo {
            font-size: 18px;
            font-weight: 700;
            color: var(--text-sidebar-active);
            display: flex;
            align-items: center;
            gap: 10px;
            text-decoration: none;
        }
        .sidebar-header .logo-icon { font-size: 24px; }
        .sidebar-header .subtitle {
            font-size: 11px;
            color: var(--text-sidebar);
            margin-top: 4px;
        }
        .sidebar-nav { flex: 1; padding: 12px 0; }
        .sidebar-nav .nav-section {
            padding: 12px 20px 6px;
            font-size: 11px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: rgba(255,255,255,0.4);
            font-weight: 600;
        }
        .sidebar-nav a {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 12px 20px;
            color: var(--text-sidebar);
            text-decoration: none;
            font-size: 14px;
            transition: all var(--transition);
            border-left: 3px solid transparent;
        }
        .sidebar-nav a:hover { background: var(--sidebar-hover); color: var(--text-sidebar-active); }
        .sidebar-nav a.active {
            background: rgba(201,169,110,0.15);
            color: var(--sidebar-active);
            border-left-color: var(--sidebar-active);
        }
        .sidebar-nav a .nav-icon { font-size: 18px; width: 24px; text-align: center; display: inline-flex; align-items: center; justify-content: center; }
        .sidebar-nav a .nav-icon svg { width: 18px; height: 18px; stroke: currentColor; fill: none; stroke-width: 2; stroke-linecap: round; stroke-linejoin: round; }
        .sidebar-header .logo-icon svg { width: 24px; height: 24px; stroke: var(--sidebar-active); fill: none; stroke-width: 2; stroke-linecap: round; stroke-linejoin: round; }
        .top-bar .menu-toggle svg { width: 22px; height: 22px; stroke: currentColor; fill: none; stroke-width: 2; stroke-linecap: round; }
        .sidebar-footer .logout-btn svg { width: 18px; height: 18px; stroke: currentColor; fill: none; stroke-width: 2; stroke-linecap: round; stroke-linejoin: round; }
        .sidebar-footer {
            padding: 16px 20px;
            border-top: 1px solid rgba(255,255,255,0.06);
        }
        .sidebar-footer .user-info {
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .sidebar-footer .user-avatar {
            width: 36px; height: 36px;
            border-radius: 50%;
            background: var(--sidebar-active);
        }
        .sidebar-footer .user-details { flex: 1; }
        .sidebar-footer .user-name { font-size: 13px; color: var(--text-sidebar-active); }
        .sidebar-footer .user-role { font-size: 11px; color: var(--text-sidebar); }
        .sidebar-footer .user-role.super { color: var(--sidebar-active); }
        .sidebar-footer .logout-btn {
            background: none; border: none;
            color: var(--text-sidebar);
            cursor: pointer;
            font-size: 18px;
            padding: 6px;
            border-radius: 6px;
        }
        .sidebar-footer .logout-btn:hover { color: var(--danger); }
        .main-content {
            flex: 1;
            margin-left: 250px;
            min-height: 100vh;
            display: flex;
            flex-direction: column;
        }
        .top-bar {
            background: var(--card-bg);
            padding: 14px 28px;
            border-bottom: 1px solid var(--border);
            display: flex;
            align-items: center;
            justify-content: space-between;
            position: sticky;
            top: 0;
            z-index: 50;
            backdrop-filter: blur(10px);
            -webkit-backdrop-filter: blur(10px);
        }
        .top-bar .page-title { font-size: 18px; font-weight: 700; }
        .top-bar .menu-toggle {
            display: none;
            background: none;
            border: none;
            font-size: 24px;
            cursor: pointer;
            padding: 4px 8px;
            border-radius: 6px;
            color: var(--text);
        }
        .top-bar .menu-toggle:hover { background: var(--bg); }
        .top-bar .top-actions {
            display: flex;
            align-items: center;
            gap: 12px;
        }
        .top-bar .top-actions a {
            font-size: 13px;
            color: var(--text-secondary);
            text-decoration: none;
        }
        .top-bar .top-actions a:hover { color: var(--primary); }
        .page-content { padding: 24px 28px; flex: 1; }
        .sidebar-overlay {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(0,0,0,0.5);
            z-index: 99;
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(220px, 1fr));
            gap: 16px;
            margin-bottom: 24px;
        }
        .stat-card {
            background: var(--card-bg);
            border-radius: var(--radius);
            padding: 20px;
            box-shadow: var(--shadow);
            border-left: 4px solid var(--primary);
            transition: box-shadow var(--transition);
        }
        .stat-card:hover { box-shadow: var(--shadow-md); }
        .stat-card.warning { border-left-color: var(--warning); }
        .stat-card.danger { border-left-color: var(--danger); }
        .stat-card.success { border-left-color: var(--success); }
        .stat-card.info { border-left-color: var(--info); }
        .stat-card .stat-label { font-size: 13px; color: var(--text-secondary); margin-bottom: 8px; }
        .stat-card .stat-value { font-size: 28px; font-weight: 700; }
        .stat-card .stat-icon { font-size: 24px; float: right; opacity: 0.3; }
        .chart-card {
            background: var(--card-bg);
            border-radius: var(--radius);
            padding: 24px;
            box-shadow: var(--shadow);
            margin-bottom: 24px;
        }
        .chart-card h3 { font-size: 16px; margin-bottom: 20px; }
        .bar-chart {
            display: flex;
            align-items: flex-end;
            gap: 12px;
            height: 200px;
            padding: 0 8px;
        }
        .bar-item {
            flex: 1;
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 6px;
            height: 100%;
            justify-content: flex-end;
        }
        .bar-fill {
            width: 100%;
            max-width: 50px;
            background: var(--primary);
            border-radius: 6px 6px 0 0;
            transition: height 0.5s ease;
            min-height: 4px;
        }
        .bar-value { font-size: 12px; font-weight: 600; }
        .bar-label { font-size: 11px; color: var(--text-secondary); }
        .filter-bar {
            display: flex;
            gap: 8px;
            margin-bottom: 20px;
        }
        .filter-btn {
            padding: 8px 16px;
            border: 1px solid var(--border);
            background: var(--card-bg);
            border-radius: var(--radius-sm);
            font-size: 13px;
            cursor: pointer;
            color: var(--text-secondary);
            transition: all var(--transition);
        }
        .filter-btn:hover { border-color: var(--primary); color: var(--primary); }
        .filter-btn.active { background: var(--primary); color: #fff; border-color: var(--primary); }
        .section {
            background: var(--card-bg);
            border-radius: var(--radius);
            box-shadow: var(--shadow);
            margin-bottom: 24px;
        }
        .section-header {
            padding: 18px 24px;
            border-bottom: 1px solid var(--border);
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 12px;
        }
        .section-header h3 { font-size: 16px; }
        .section-body { padding: 24px; }
        .table-wrapper { overflow-x: auto; }
        table { width: 100%; border-collapse: collapse; }
        table th, table td {
            padding: 12px 16px;
            text-align: left;
            border-bottom: 1px solid var(--border);
            font-size: 14px;
        }
        table th {
            background: var(--bg);
            font-weight: 600;
            color: var(--text-secondary);
            font-size: 12px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            white-space: nowrap;
        }
        table tbody tr:hover { background: var(--bg); }
        .badge {
            display: inline-block;
            padding: 3px 10px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
        }
        .badge-success { background: var(--success-light); color: var(--success); }
        .badge-warning { background: var(--warning-light); color: var(--warning); }
        .badge-danger { background: var(--danger-light); color: var(--danger); }
        .badge-info { background: var(--info-light); color: var(--info); }
        .badge-super { background: linear-gradient(135deg, var(--warning-light), var(--danger-light)); color: var(--warning); }
        .badge-admin { background: var(--primary-light); color: var(--primary); }
        .badge-user { background: var(--bg); color: var(--text-secondary); }
        .btn {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 8px 16px;
            border: none;
            border-radius: var(--radius-sm);
            font-size: 13px;
            font-weight: 600;
            cursor: pointer;
            transition: all var(--transition);
            text-decoration: none;
            white-space: nowrap;
        }
        .btn-sm { padding: 6px 12px; font-size: 12px; }
        .btn-primary { background: var(--primary); color: #fff; }
        .btn-primary:hover { background: var(--primary-hover); }
        .btn-success { background: var(--success); color: #fff; }
        .btn-success:hover { background: #3A7048; }
        .btn-danger { background: var(--danger); color: #fff; }
        .btn-danger:hover { background: #A84E55; }
        .btn-warning { background: var(--warning); color: #fff; }
        .btn-warning:hover { background: #B8943E; }
        .btn-outline {
            background: transparent;
            border: 1px solid var(--border);
            color: var(--text);
        }
        .btn-outline:hover { background: var(--bg); border-color: var(--primary); }
        .btn:disabled { opacity: 0.5; cursor: not-allowed; }
        .form-group { margin-bottom: 16px; }
        .form-group label {
            display: block;
            font-size: 13px;
            font-weight: 600;
            color: var(--text);
            margin-bottom: 6px;
        }
        .form-group input, .form-group select, .form-group textarea {
            width: 100%;
            padding: 10px 14px;
            border: 1px solid var(--border);
            border-radius: var(--radius-sm);
            font-size: 14px;
            color: var(--text);
            background: var(--card-bg);
            transition: border-color var(--transition);
            outline: none;
            font-family: inherit;
        }
        .form-group input:focus, .form-group select:focus, .form-group textarea:focus {
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(79,70,229,0.1);
        }
        .form-group input.error, .form-group select.error { border-color: var(--danger); }
        .form-group textarea { resize: vertical; min-height: 80px; }
        .form-group .error-hint {
            font-size: 12px;
            color: var(--danger);
            margin-top: 4px;
            display: none;
        }
        .form-group .error-hint.show { display: block; }
        .form-inline { display: flex; gap: 8px; align-items: flex-end; }
        .form-inline .form-group { flex: 1; margin-bottom: 0; }
        .pagination {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
            margin-top: 20px;
        }
        .pagination button {
            padding: 8px 14px;
            border: 1px solid var(--border);
            background: var(--card-bg);
            border-radius: var(--radius-sm);
            cursor: pointer;
            font-size: 13px;
            color: var(--text);
        }
        .pagination button:hover { background: var(--bg); }
        .pagination button.active { background: var(--primary); color: #fff; border-color: var(--primary); }
        .pagination button:disabled { opacity: 0.4; cursor: not-allowed; }
        .pagination .page-info { font-size: 13px; color: var(--text-secondary); padding: 0 8px; }
        .modal-overlay {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(0,0,0,0.5);
            z-index: 200;
            align-items: center;
            justify-content: center;
        }
        .modal-overlay.show { display: flex; }
        .modal {
            background: var(--card-bg);
            border-radius: var(--radius);
            box-shadow: 0 20px 60px rgba(0,0,0,0.2);
            width: 90%;
            max-width: 560px;
            max-height: 80vh;
            overflow-y: auto;
            animation: modalIn 0.2s ease;
        }
        @keyframes modalIn {
            from { opacity: 0; transform: translateY(-20px) scale(0.95); }
            to { opacity: 1; transform: translateY(0) scale(1); }
        }
        .modal-header {
            padding: 20px 24px;
            border-bottom: 1px solid var(--border);
            display: flex;
            align-items: center;
            justify-content: space-between;
        }
        .modal-header h3 { font-size: 16px; }
        .modal-close {
            background: none;
            border: none;
            font-size: 20px;
            cursor: pointer;
            color: var(--text-secondary);
            padding: 4px 8px;
            border-radius: 4px;
        }
        .modal-close:hover { background: var(--bg); color: var(--text); }
        .modal-body { padding: 24px; }
        .modal-footer {
            padding: 16px 24px;
            border-top: 1px solid var(--border);
            display: flex;
            justify-content: flex-end;
            gap: 8px;
        }
        .toast {
            position: fixed;
            top: 20px;
            right: 20px;
            z-index: 300;
            padding: 14px 20px;
            border-radius: var(--radius-sm);
            color: #fff;
            font-size: 14px;
            font-weight: 500;
            box-shadow: 0 4px 20px rgba(0,0,0,0.15);
            animation: toastIn 0.3s ease;
            max-width: 400px;
        }
        @keyframes toastIn {
            from { opacity: 0; transform: translateX(40px); }
            to { opacity: 1; transform: translateX(0); }
        }
        .toast-success { background: var(--success); }
        .toast-error { background: var(--danger); }
        .filter-row {
            display: flex;
            gap: 12px;
            flex-wrap: wrap;
            align-items: flex-end;
            margin-bottom: 16px;
        }
        .filter-row .form-group { margin-bottom: 0; min-width: 150px; }
        .loading-spinner {
            text-align: center;
            padding: 40px;
            color: var(--text-secondary);
        }
        .loading-spinner::after {
            content: '';
            display: inline-block;
            width: 24px; height: 24px;
            border: 2px solid var(--border);
            border-top-color: var(--primary);
            border-radius: 50%;
            animation: spin 0.6s linear infinite;
            margin-left: 8px;
            vertical-align: middle;
        }
        @keyframes spin { to { transform: rotate(360deg); } }
        .empty-state {
            text-align: center;
            padding: 60px 20px;
            color: var(--text-secondary);
        }
        .empty-state .empty-icon { font-size: 48px; margin-bottom: 12px; }
        .toggle-switch {
            position: relative;
            display: inline-block;
            width: 44px;
            height: 24px;
        }
        .toggle-switch input { opacity: 0; width: 0; height: 0; }
        .toggle-slider {
            position: absolute;
            cursor: pointer;
            inset: 0;
            background: var(--border);
            border-radius: 24px;
            transition: 0.3s;
        }
        .toggle-slider:before {
            content: '';
            position: absolute;
            width: 18px; height: 18px;
            left: 3px; bottom: 3px;
            background: #fff;
            border-radius: 50%;
            transition: 0.3s;
        }
        .toggle-switch input:checked + .toggle-slider { background: var(--primary); }
        .toggle-switch input:checked + .toggle-slider:before { transform: translateX(20px); }
        .perm-group {
            margin-bottom: 20px;
            background: var(--card-bg);
            border: 1px solid var(--border);
            border-radius: var(--radius);
            overflow: hidden;
        }
        .perm-group-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 14px 18px;
            background: var(--bg);
            border-bottom: 1px solid var(--border);
            gap: 12px;
        }
        .perm-group-title {
            font-size: 0.9rem;
            font-weight: 700;
            color: var(--text);
            display: flex;
            align-items: center;
            gap: 8px;
            margin: 0;
        }
        .perm-group-title::before {
            content: '';
            display: inline-block;
            width: 4px;
            height: 18px;
            background: var(--primary);
            border-radius: 2px;
        }
        .perm-actions {
            display: flex;
            gap: 4px;
            flex-shrink: 0;
        }
        .perm-action-btn {
            font-size: 0.75rem;
            padding: 4px 12px;
            border: 1px solid var(--border);
            border-radius: var(--radius-sm);
            background: var(--card-bg);
            color: var(--text-secondary);
            cursor: pointer;
            transition: all 0.15s ease;
            font-family: inherit;
            white-space: nowrap;
        }
        .perm-action-btn:hover {
            border-color: var(--primary);
            color: var(--primary);
            background: var(--primary-light);
        }
        .perm-checkboxes {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 4px;
            padding: 14px 18px;
        }
        .perm-checkbox-item {
            display: flex;
            align-items: center;
            gap: 10px;
            font-size: 0.85rem;
            cursor: pointer;
            padding: 8px 10px;
            border-radius: 6px;
            transition: all 0.15s ease;
            user-select: none;
        }
        .perm-checkbox-item:hover {
            background: var(--bg);
        }
        .perm-cb {
            appearance: none;
            -webkit-appearance: none;
            width: 20px;
            height: 20px;
            flex-shrink: 0;
            border: 2px solid var(--border);
            border-radius: 4px;
            background: var(--card-bg);
            cursor: pointer;
            transition: all 0.15s ease;
            position: relative;
            margin: 0;
        }
        .perm-cb:hover { border-color: var(--primary); }
        .perm-cb:checked {
            background: var(--primary);
            border-color: var(--primary);
        }
        .perm-cb:checked::after {
            content: '';
            position: absolute;
            left: 5px;
            top: 2px;
            width: 6px;
            height: 10px;
            border: solid #fff;
            border-width: 0 2px 2px 0;
            transform: rotate(45deg);
        }
        .perm-label-text {
            color: var(--text);
            line-height: 1.4;
        }
        .perm-checkbox-item:has(input:checked) {
            background: var(--primary-light);
        }
        .perm-checkbox-item:has(input:checked) .perm-label-text {
            color: var(--primary);
            font-weight: 500;
        }
        @media (max-width: 480px) {
            .perm-checkboxes {
                grid-template-columns: 1fr;
            }
        }
        .perm-tags {
            display: flex;
            flex-wrap: wrap;
            gap: 4px;
        }
        .perm-tag {
            font-size: 11px;
            padding: 2px 8px;
            border-radius: 10px;
            background: var(--primary-light);
            color: var(--primary);
        }
        @media (max-width: 768px) {
            .sidebar { transform: translateX(-100%); }
            .sidebar.open { transform: translateX(0); }
            .sidebar-overlay.show { display: block; }
            .main-content { margin-left: 0; }
            .top-bar .menu-toggle { display: block; }
            .stats-grid { grid-template-columns: repeat(2, 1fr); }
            .page-content { padding: 16px; }
            .bar-chart { height: 150px; gap: 6px; }
            .filter-row { flex-direction: column; }
            .filter-row .form-group { min-width: 100%; }
            .form-inline { flex-direction: column; }
        }
        @media (max-width: 480px) {
            .stats-grid { grid-template-columns: 1fr; }
            .stat-card .stat-value { font-size: 22px; }
        }

        .card {
            background: var(--card-bg);
            border-radius: var(--radius);
            box-shadow: var(--shadow);
            border: 1px solid var(--border);
            transition: box-shadow var(--transition);
            overflow: hidden;
        }
        .card:hover { box-shadow: var(--shadow-md); }
        .card-header {
            padding: 16px 20px;
            border-bottom: 1px solid var(--border);
            font-weight: 700;
            font-size: 15px;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .card-body { padding: 20px; }

        .setting-section {
            background: var(--card-bg);
            border-radius: var(--radius);
            box-shadow: var(--shadow);
            margin-bottom: 20px;
            overflow: hidden;
            border: 1px solid var(--border);
            transition: box-shadow var(--transition);
        }
        .setting-section:hover { box-shadow: var(--shadow-md); }
        .setting-section-title {
            padding: 16px 24px;
            font-size: 15px;
            font-weight: 700;
            color: var(--text);
            border-bottom: 1px solid var(--border);
            display: flex;
            align-items: center;
            gap: 10px;
            background: linear-gradient(180deg, rgba(79,70,229,0.02) 0%, transparent 100%);
        }
    </style>
</head>
<body>
    <div class="sidebar-overlay" id="sidebarOverlay"></div>
    <aside class="sidebar" id="sidebar">
        <div class="sidebar-header">
            <a href="/admin/dashboard.php" class="logo">
                <span class="logo-icon"><svg viewBox="0 0 24 24"><path d="M12 2L3 6v6c0 5.5 3.8 10.7 9 12 5.2-1.3 9-6.5 9-12V6l-9-4z"/><path d="M9 12l2 2 4-4"/></svg></span>
                <span>管理后台</span>
            </a>
            <div class="subtitle"><?= SITE_NAME ?></div>
        </div>
        <nav class="sidebar-nav">
            <div class="nav-section">主菜单</div>
            <a href="/admin/dashboard.php" class="<?= $page === 'dashboard.php' ? 'active' : '' ?>">
                <span class="nav-icon"><svg viewBox="0 0 24 24"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/></svg></span> 仪表盘
            </a>
            <?php if (checkPermission($adminUser, 'view_users')): ?>
            <a href="/admin/users.php" class="<?= $page === 'users.php' ? 'active' : '' ?>">
                <span class="nav-icon"><svg viewBox="0 0 24 24"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg></span> 用户管理
            </a>
            <a href="/admin/title_requests.php" class="<?= $page === 'title_requests.php' ? 'active' : '' ?>">
                <span class="nav-icon"><svg viewBox="0 0 24 24"><path d="M12 2l2.9 6.26L21 9.27l-4.5 4.38.94 6.35L12 17.42l-5.44 2.58.94-6.35L3 9.27l6.1-1.01z"/></svg></span> 头衔申请
            </a>
            <?php endif; ?>
            <?php if (checkPermission($adminUser, 'view_posts')): ?>
            <a href="/admin/posts.php" class="<?= $page === 'posts.php' ? 'active' : '' ?>">
                <span class="nav-icon"><svg viewBox="0 0 24 24"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="8" y1="13" x2="16" y2="13"/><line x1="8" y1="17" x2="16" y2="17"/></svg></span> 帖子管理
            </a>
            <a href="/admin/comments.php" class="<?= $page === 'comments.php' ? 'active' : '' ?>">
                <span class="nav-icon"><svg viewBox="0 0 24 24"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg></span> 评论管理
            </a>
            <?php endif; ?>
            <div class="nav-section">设置</div>
            <a href="/admin/settings.php" class="<?= $page === 'settings.php' ? 'active' : '' ?>">
                <span class="nav-icon"><svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1 0 2.83 2 2 0 0 1-2.83 0l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-2 2 2 2 0 0 1-2-2v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83 0 2 2 0 0 1 0-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1-2-2 2 2 0 0 1 2-2h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 0-2.83 2 2 0 0 1 2.83 0l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 2-2 2 2 0 0 1 2 2v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 0 2 2 0 0 1 0 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 2 2 2 2 0 0 1-2 2h-.09a1.65 1.65 0 0 0-1.51 1z"/></svg></span> 站点设置
            </a>
            <a href="/admin/music.php" class="<?= $page === 'music.php' ? 'active' : '' ?>">
                <span class="nav-icon"><svg viewBox="0 0 24 24"><path d="M9 18V5l12-2v13"/><circle cx="6" cy="18" r="3"/><circle cx="18" cy="16" r="3"/></svg></span> 音乐管理
            </a>
            <a href="/admin/feature_votes.php" class="<?= $page === 'feature_votes.php' ? 'active' : '' ?>">
                <span class="nav-icon"><svg viewBox="0 0 24 24"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/><line x1="8" y1="9" x2="16" y2="9"/><line x1="8" y1="13" x2="14" y2="13"/></svg></span> 功能投票
            </a>
            <?php if (checkPermission($adminUser, 'edit_announcement')): ?>
            <a href="/admin/announcements.php" class="<?= $page === 'announcements.php' ? 'active' : '' ?>">
                <span class="nav-icon"><svg viewBox="0 0 24 24"><path d="M3 11l18-5v12L3 14v-3z"/><path d="M11.6 16.8a3 3 0 1 1-5.8-1.6"/></svg></span> 公告管理
            </a>
            <?php endif; ?>
            <?php if (checkPermission($adminUser, 'manage_admins')): ?>
            <a href="/admin/admins.php" class="<?= $page === 'admins.php' ? 'active' : '' ?>">
                <span class="nav-icon"><svg viewBox="0 0 24 24"><path d="M21 2l-2 2m-7.61 7.61a5.5 5.5 0 1 1-7.778 7.778 5.5 5.5 0 0 1 7.777-7.777zm0 0L15.5 7.5m0 0l3 3L22 7l-3-3m-3.5 3.5L19 4"/></svg></span> 管理员管理
            </a>
            <?php endif; ?>
            <div class="nav-section">日志与安全</div>
            <a href="/admin/operation_logs.php" class="<?= $page === 'operation_logs.php' ? 'active' : '' ?>">
                <span class="nav-icon"><svg viewBox="0 0 24 24"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="8" y1="13" x2="16" y2="13"/><line x1="8" y1="17" x2="16" y2="17"/></svg></span> 操作日志
            </a>
            <a href="/admin/ip_blacklist.php" class="<?= $page === 'ip_blacklist.php' ? 'active' : '' ?>">
                <span class="nav-icon"><svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><line x1="4.93" y1="4.93" x2="19.07" y2="19.07"/></svg></span> IP黑名单
            </a>
            <a href="/admin/illegal_access.php" class="<?= $page === 'illegal_access.php' ? 'active' : '' ?>">
                <span class="nav-icon"><svg viewBox="0 0 24 24"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg></span> 非法访问
            </a>
            <?php if (checkPermission($adminUser, 'view_reports')): ?>
            <a href="/admin/reports.php" class="<?= $page === 'reports.php' ? 'active' : '' ?>">
                <span class="nav-icon"><svg viewBox="0 0 24 24"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg></span> 举报管理
            </a>
            <?php endif; ?>
        </nav>
        <div class="sidebar-footer">
            <div class="user-info">
                <img src="<?= xss_clean($adminUser['avatar'] ?: getQQAvatar($adminUser['qq'])) ?>" alt="" class="user-avatar" onerror="this.src='data:image/svg+xml,<svg xmlns=%22http://www.w3.org/2000/svg%22 viewBox=%220 0 100 100%22><rect fill=%22%234f46e5%22 width=%22100%22 height=%22100%22/><circle fill=%22white%22 cx=%2250%22 cy=%2238%22 r=%2218%22/><path fill=%22white%22 d=%22M22 86 a28 28 0 0 1 56 0 z%22/></svg>'">
                <div class="user-details">
                    <div class="user-name"><?= xss_clean($adminUser['nickname'] ?: 'QQ:'.$adminUser['qq']) ?></div>
                    <div class="user-role <?= $adminUser['role'] === 'super_admin' ? 'super' : '' ?>"><?= $adminUser['role'] === 'super_admin' ? '超级管理员' : '管理员' ?></div>
                </div>
                <button class="logout-btn" onclick="event.preventDefault();fetch('/api/auth/logout.php',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:'csrf_token='+encodeURIComponent(<?= json_encode($csrfToken) ?>)}).then(function(){location.href='/'})" title="退出登录" aria-label="退出登录"><svg viewBox="0 0 24 24"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg></button>
            </div>
        </div>
    </aside>
    <div class="main-content">
        <div class="top-bar">
            <button class="menu-toggle" id="menuToggle" aria-label="菜单"><svg viewBox="0 0 24 24"><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="18" x2="21" y2="18"/></svg></button>
            <h1 class="page-title"><?= xss_clean($title) ?></h1>
            <div class="top-actions">
                <a href="/">← 返回前台</a>
            </div>
        </div>
        <div class="page-content">
<?php
}

function adminFooter() {
    ?>
        </div>
    </div>
    <script>

    function esc(str) {
        if (!str) return '';
        // 手动转义全部 5 个特殊字符（含双/单引号）。
        // 关键：JSON.stringify 产生的字符串形如 "\"站长\""，若不在属性内转义引号，
        // 插入 onclick 属性会提前闭合导致 "Unexpected end of input"、点击无反应。
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;');
    }

    document.getElementById('menuToggle').addEventListener('click', function() {
        document.getElementById('sidebar').classList.toggle('open');
        document.getElementById('sidebarOverlay').classList.toggle('show');
    });
    document.getElementById('sidebarOverlay').addEventListener('click', function() {
        document.getElementById('sidebar').classList.remove('open');
        document.getElementById('sidebarOverlay').classList.remove('show');
    });

    function showToast(msg, type) {
        var toast = document.createElement('div');
        toast.className = 'toast toast-' + (type || 'success');
        toast.textContent = msg;
        document.body.appendChild(toast);
        setTimeout(function() {
            toast.style.opacity = '0';
            toast.style.transition = 'opacity 0.3s';
            setTimeout(function() { toast.remove(); }, 300);
        }, 3000);
    }

    function openModal(modalId) {
        document.getElementById(modalId).classList.add('show');
    }
    function closeModal(modalId) {
        document.getElementById(modalId).classList.remove('show');
    }

    document.addEventListener('click', function(e) {
        if (e.target.classList.contains('modal-overlay') && e.target.classList.contains('show')) {
            e.target.classList.remove('show');
        }
    });
    </script>
</body>
</html>
<?php
}

}
