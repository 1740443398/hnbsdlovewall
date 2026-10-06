<?php
if (!function_exists('adminHeader')) {

function adminHeader($title, $adminUser, $csrfToken) {
    $page = basename($_SERVER['PHP_SELF']);
    // 后台暗色模式（D34）：服务端种子优先于 localStorage，再回退 system
    $adminTheme = '';
    if (isset($adminUser['theme']) && in_array($adminUser['theme'], ['light', 'dark', 'system'], true)) {
        $adminTheme = $adminUser['theme'];
    }
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
    /* CSRF_TOKEN 用 var 而非 const：下方 fetch 拦截器在令牌轮换后需要重新赋值它，
       以便后续所有请求用上新令牌（const 无法重赋值）。 */
    var CSRF_TOKEN = <?= json_encode($csrfToken) ?>;

    /* 后台公共工具：必须在这里（<head>，早于任何页面脚本）定义。
     *
     * 原来的位置在页面最末的 adminFooter()，看似「反正都是函数声明会提升」，
     * 实际会踩一个时序竞态：页面脚本一开头就发 AJAX（loadUsers 之类），
     * 如果请求瞬间结束（例如返回的是一张 HTML 拦截页而不是 JSON，r.json() 立刻抛错），
     * Promise 的 .catch 会在**当前脚本块结束后、解析器继续往下走之前**就被执行，
     * 而此刻文档还没解析到页尾，showToast / esc 尚未定义 ——
     * 于是整站后台每页都刷 «ReferenceError: showToast is not defined»。
     * 放 <head> 里就没有这个窗口了。 */
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
    </script>
    <script>
    /* CSRF 自愈拦截器：后台 AJAX 全部走原生 fetch，这里统一包一层。
     *
     * 背景：会话被主机回收 / Cookie 丢失 / 令牌轮换后，后台任何提交都会收到
     *   403 { success:false, new_csrf_token:"..." }。旧代码只把 403 当失败弹给用户，
     *   管理员同样会看到「CSRF验证失败」却无法自愈（主站 main.js 早有自愈，后台一直缺）。
     *
     * 做法：拦截 fetch，遇到 403 且响应体带 new_csrf_token 时——
     *   ① 更新全局 CSRF_TOKEN（已改为 var，可重赋值，后续请求自动用新值）；
     *   ② 把本次请求体里的 csrf_token 换成新值；
     *   ③ 静默重试一次（仅一次，防死循环）。
     * 安全性：跨站攻击者读不到跨源响应（无 CORS），拿不到新令牌，令牌外泄风险为零。 */
    (function () {
        if (window.__lwCsrfFetchPatched || typeof window.fetch !== 'function') return;
        window.__lwCsrfFetchPatched = true;
        var _fetch = window.fetch.bind(window);
        function swapBody(body, tok) {
            try {
                if (body instanceof FormData || body instanceof URLSearchParams) {
                    body.set('csrf_token', tok);
                    return body;
                }
                if (typeof body === 'string' && body.indexOf('csrf_token') !== -1) {
                    return body.replace(/csrf_token=[^&]*/, 'csrf_token=' + encodeURIComponent(tok));
                }
            } catch (e) {}
            return body;
        }
        window.fetch = function (input, init) {
            init = init || {};
            return _fetch(input, init).then(function (res) {
                // 仅处理 URL 字符串请求（后台全部如此）+ 403 + 未重试过
                if (res.status !== 403 || init.__lwCsrfRetried || typeof input !== 'string') return res;
                // 克隆后再读，避免消费掉调用方要用的响应体
                return res.clone().json().then(function (data) {
                    if (!data || !data.new_csrf_token) return res;
                    try { window.CSRF_TOKEN = data.new_csrf_token; } catch (e) {}
                    var ni = Object.assign({}, init);
                    ni.__lwCsrfRetried = true;
                    ni.body = swapBody(init.body, data.new_csrf_token);
                    return _fetch(input, ni);
                }).catch(function () { return res; });
            });
        };
    })();
    </script>
    <script>
    /* 后台暗色模式引导（D34）：必须在首帧前同步执行，避免闪白。
       三态：system / light / dark；localStorage 为主，服务端种子优先于 system 默认。 */
    (function () {
        try {
            var LW_ADMIN_THEME_SEED = <?= json_encode($adminTheme) ?>;
            var ICONS = {
                system: '<svg viewBox="0 0 24 24"><rect x="2" y="3" width="20" height="14" rx="2"/><line x1="8" y1="21" x2="16" y2="21"/><line x1="12" y1="17" x2="12" y2="21"/></svg>',
                light:  '<svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="4"/><line x1="12" y1="2" x2="12" y2="5"/><line x1="12" y1="19" x2="12" y2="22"/><line x1="2" y1="12" x2="5" y2="12"/><line x1="19" y1="12" x2="22" y2="12"/><line x1="4.9" y1="4.9" x2="7" y2="7"/><line x1="17" y1="17" x2="19.1" y2="19.1"/><line x1="4.9" y1="19.1" x2="7" y2="17"/><line x1="17" y1="7" x2="19.1" y2="4.9"/></svg>',
                dark:   '<svg viewBox="0 0 24 24"><path d="M21 12.8A9 9 0 1 1 11.2 3 7 7 0 0 0 21 12.8z"/></svg>'
            };
            var LABELS = { system: '跟随系统', light: '浅色', dark: '深色' };
            function apply(pref) {
                var el = document.documentElement;
                var dark = pref === 'dark' || (pref === 'system' && window.matchMedia('(prefers-color-scheme: dark)').matches);
                el.classList.remove('light-theme', 'dark-theme');
                el.classList.add(dark ? 'dark-theme' : 'light-theme');
            }
            var pref = localStorage.getItem('lw_admin_theme') || LW_ADMIN_THEME_SEED || 'system';
            apply(pref);
            function paint(btn) {
                if (!btn) return;
                btn.setAttribute('aria-label', '主题：' + (LABELS[pref] || '主题'));
                btn.title = '主题：' + (LABELS[pref] || '主题');
                var ic = btn.querySelector('.tt-icon'); if (ic) ic.innerHTML = ICONS[pref] || ICONS.system;
                var tx = btn.querySelector('.tt-text'); if (tx) tx.textContent = LABELS[pref] || '主题';
            }
            window.LWAdminTheme = {
                pref: pref,
                set: function (p) {
                    pref = p;
                    try { localStorage.setItem('lw_admin_theme', p); } catch (e) {}
                    apply(p);
                    paint(document.getElementById('adminThemeToggle'));
                    window.dispatchEvent(new CustomEvent('lw-admin-theme', { detail: p }));
                },
                next: function () {
                    var order = ['system', 'light', 'dark'];
                    this.set(order[(order.indexOf(pref) + 1) % 3]);
                },
                paint: paint
            };
            window.LWAdminTheme.paint(document.getElementById('adminThemeToggle'));
            if (window.matchMedia) {
                var mq = window.matchMedia('(prefers-color-scheme: dark)');
                var onSys = function (e) {
                    if ((localStorage.getItem('lw_admin_theme') || LW_ADMIN_THEME_SEED || 'system') === 'system') apply('system');
                };
                if (mq.addEventListener) mq.addEventListener('change', onSys); else if (mq.addListener) mq.addListener(onSys);
            }
        } catch (e) {}
    })();
    </script>
    <style>
        :root {
            --font-family: 'Inter', 'Noto Sans SC', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            --bg: #f4f6fa;
            --bg-secondary: #f4f4f5;
            --card-bg: #ffffff;
            --surface: #ffffff;
            --text: #1e293b;
            --text-secondary: #64748b;
            --text-muted: #64748b;
            --primary: #111827;
            --primary-hover: #000000;
            --primary-light: #f1f5f9;
            --success: #10b981;
            --success-light: #ecfdf5;
            --warning: #f59e0b;
            --warning-light: #fffbeb;
            --danger: #ef4444;
            --danger-light: #fef2f2;
            --info: #3b82f6;
            --info-light: #eff6ff;
            --border: #e2e8f0;
            --border-light: #f1f5f9;
            --input-bg: #f4f4f5;
            --input-hover: #e4e4e7;
            --input-focus: #ffffff;
            --radius: 12px;
            --radius-sm: 10px;
            --card-radius: 20px;
            --shadow: 0 1px 3px rgba(0,0,0,0.04), 0 8px 32px rgba(0,0,0,0.06);
            --shadow-md: 0 4px 16px rgba(0,0,0,0.06);
            --shadow-lg: 0 8px 32px rgba(0,0,0,0.10);
            --transition: 0.25s cubic-bezier(0.34,1.56,0.64,1);
            --sidebar-bg: #ffffff;
            --sidebar-hover: #f4f4f5;
            --sidebar-active: #111827;
            --text-sidebar: #64748b;
            --text-sidebar-active: #111827;
        }

        /* ===== 后台暗色模式（D34）：以 html.dark-theme 覆盖同名变量 =====
           所有组件都用 var(--*) 取色，这里只重定义变量即可整体换肤，无需逐个改组件。 */
        html.dark-theme {
            color-scheme: dark;
            --bg: #0F1520;
            --bg-secondary: #171E2B;
            --card-bg: #1B2434;
            --surface: #1B2434;
            --text: #E4E9F0;
            --text-secondary: #9AA7B8;
            --text-muted: #6C7A8C;
            --primary: #5B8CC9;
            --primary-hover: #6E9AD3;
            --primary-light: #1F2E44;
            --success: #4CAF7D;
            --success-light: #173026;
            --warning: #D0A24C;
            --warning-light: #2E2617;
            --danger: #E06A64;
            --danger-light: #331C1B;
            --info: #5B8CC9;
            --info-light: #1F2E44;
            --border: #2A3748;
            --border-light: #222E3E;
            --input-bg: #141C28;
            --input-hover: #1F2A3A;
            --input-focus: #1B2434;
            --sidebar-bg: #141C28;
            --sidebar-hover: #1F2A3A;
            --sidebar-active: #C9A96E;
            --text-sidebar: #9AA7B8;
            --text-sidebar-active: #E4E9F0;
            --shadow: 0 1px 3px rgba(0,0,0,0.4), 0 8px 32px rgba(0,0,0,0.5);
            --shadow-md: 0 4px 16px rgba(0,0,0,0.5);
            --shadow-lg: 0 8px 32px rgba(0,0,0,0.55);
        }
        html.dark-theme body { background: var(--bg); color: var(--text); }
        html.dark-theme .top-bar { background: rgba(20,28,40,0.82); }
        html.dark-theme .sidebar { border-right-color: var(--border); }
        html.dark-theme .section, html.dark-theme .card, html.dark-theme .setting-section,
        html.dark-theme .perm-group, html.dark-theme .chart-card, html.dark-theme .stat-card {
            border-color: var(--border);
        }
        html.dark-theme table th, html.dark-theme table td { border-color: var(--border); }
        html.dark-theme table tbody tr:hover { background: var(--bg-secondary); }
        html.dark-theme .bar-fill { background: var(--primary); }
        html.dark-theme .perm-checkbox-item:has(input:checked) { background: var(--primary-light); }
        html.dark-theme .stat-card { border-left-color: var(--primary); }
        html.dark-theme .loading-spinner::after { border-color: var(--border); border-top-color: var(--primary); }
        html.dark-theme .top-bar .menu-toggle { color: var(--text); }
        html.dark-theme .top-bar .menu-toggle:hover { background: var(--input-bg); }
        @media (prefers-reduced-motion: no-preference) {
            html.dark-theme .admin-theme-toggle svg { transition: transform .45s var(--transition); }
            html.dark-theme .admin-theme-toggle:active svg { transform: rotate(180deg) scale(.9); }
        }
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: var(--font-family);
            background: var(--bg);
            color: var(--text);
            display: flex;
            min-height: 100vh;
            line-height: 1.6;
            -webkit-font-smoothing: antialiased;
            -moz-osx-font-smoothing: grayscale;
        }
        .sidebar {
            width: 240px;
            min-width: 240px;
            background: var(--sidebar-bg);
            color: var(--text-sidebar);
            display: flex;
            flex-direction: column;
            position: fixed;
            top: 0; left: 0; bottom: 0;
            z-index: 100;
            border-right: 1px solid var(--border);
            transition: transform var(--transition);
            overflow-y: auto;
        }
        .sidebar-header {
            padding: 20px;
            border-bottom: 1px solid var(--border);
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
        .sidebar-nav { flex: 1; padding: 12px; }
        .sidebar-nav .nav-section {
            padding: 12px 12px 6px;
            font-size: 11px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: var(--text-muted);
            font-weight: 600;
        }
        .sidebar-nav a {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 10px 12px;
            border-radius: 10px;
            margin-bottom: 2px;
            color: var(--text-sidebar);
            text-decoration: none;
            font-size: 14px;
            transition: all var(--transition);
        }
        .sidebar-nav a:hover { background: var(--sidebar-hover); color: var(--text); transform: translateX(2px); }
        .sidebar-nav a.active {
            background: var(--primary);
            color: #ffffff;
        }
        .sidebar-nav a .nav-icon { font-size: 18px; width: 24px; text-align: center; display: inline-flex; align-items: center; justify-content: center; }
        .sidebar-nav a .nav-icon svg { width: 18px; height: 18px; stroke: currentColor; fill: none; stroke-width: 2; stroke-linecap: round; stroke-linejoin: round; }
        .sidebar-header .logo-icon svg { width: 24px; height: 24px; stroke: var(--sidebar-active); fill: none; stroke-width: 2; stroke-linecap: round; stroke-linejoin: round; }
        .top-bar .menu-toggle svg { width: 22px; height: 22px; stroke: currentColor; fill: none; stroke-width: 2; stroke-linecap: round; }
        .sidebar-footer .logout-btn svg { width: 18px; height: 18px; stroke: currentColor; fill: none; stroke-width: 2; stroke-linecap: round; stroke-linejoin: round; }
        .sidebar-footer {
            padding: 16px 20px;
            border-top: 1px solid var(--border);
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
            border-radius: var(--radius-sm);
            transition: all var(--transition);
        }
        .sidebar-footer .logout-btn:hover { background: var(--input-bg); color: var(--danger); }
        .main-content {
            flex: 1;
            margin-left: 240px;
            min-height: 100vh;
            display: flex;
            flex-direction: column;
        }
        .top-bar {
            height: 64px;
            background: rgba(255,255,255,0.82);
            backdrop-filter: blur(14px);
            -webkit-backdrop-filter: blur(14px);
            padding: 0 24px;
            border-bottom: 1px solid var(--border);
            display: flex;
            align-items: center;
            gap: 12px;
            justify-content: space-between;
            position: sticky;
            top: 0;
            z-index: 50;
            flex-shrink: 0;
        }
        .top-bar .page-title { font-size: 18px; font-weight: 700; letter-spacing: -0.2px; }
        .top-bar .menu-toggle {
            display: none;
            background: none;
            border: none;
            font-size: 24px;
            cursor: pointer;
            padding: 4px 8px;
            border-radius: var(--radius-sm);
            color: var(--text);
            transition: all var(--transition);
        }
        .top-bar .menu-toggle:hover { background: var(--input-bg); }
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
        .admin-theme-toggle {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            height: 36px;
            padding: 0 12px;
            border: 1px solid var(--border);
            border-radius: var(--radius);
            background: var(--card-bg);
            color: var(--text-secondary);
            font-size: 13px;
            font-weight: 600;
            cursor: pointer;
            font-family: inherit;
            transition: all var(--transition);
        }
        .admin-theme-toggle:hover { color: var(--primary); border-color: var(--primary); background: var(--input-bg); }
        .admin-theme-toggle .tt-icon { width: 18px; height: 18px; display: inline-flex; }
        .admin-theme-toggle .tt-icon svg { width: 18px; height: 18px; stroke: currentColor; fill: none; stroke-width: 2; stroke-linecap: round; stroke-linejoin: round; }
        .admin-theme-toggle .tt-icon svg rect, .admin-theme-toggle .tt-icon svg circle { fill: none; }
        @media (max-width: 768px) {
            .admin-theme-toggle .tt-text { display: none; }
            .admin-theme-toggle { padding: 0 10px; }
        }
        .top-bar-title { display: flex; flex-direction: column; }
        .breadcrumb { font-size: 12px; color: var(--text-secondary); margin-bottom: 2px; }
        .breadcrumb a { color: var(--primary); text-decoration: none; }
        .breadcrumb a:hover { text-decoration: underline; }
        .page-content { padding: 24px; flex: 1; }
        .sidebar-overlay {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(15,23,42,0.30);
            backdrop-filter: blur(4px);
            -webkit-backdrop-filter: blur(4px);
            z-index: 99;
        }

        @keyframes cardIn {
            from { opacity: 0; transform: translateY(30px); }
            to { opacity: 1; transform: none; }
        }
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(220px, 1fr));
            gap: 16px;
            margin-bottom: 24px;
        }
        .stat-card {
            background: var(--card-bg);
            border-radius: var(--card-radius);
            padding: 20px;
            box-shadow: var(--shadow);
            border: 1px solid #f1f5f9;
            border-left: 4px solid var(--primary);
            transition: box-shadow var(--transition);
            animation: cardIn 0.7s cubic-bezier(0.2, 0.9, 0.3, 1) both;
        }
        .stat-card:hover { box-shadow: var(--shadow-md); }
        .stat-card.warning { border-left-color: var(--warning); }
        .stat-card.danger { border-left-color: var(--danger); }
        .stat-card.success { border-left-color: var(--success); }
        .stat-card.info { border-left-color: var(--info); }
        .stat-card .stat-label { font-size: 13px; color: var(--text-secondary); margin-bottom: 8px; }
        .stat-card .stat-value { font-size: 28px; font-weight: 700; letter-spacing: -0.5px; }
        .stat-card .stat-icon { font-size: 24px; float: right; opacity: 0.3; }
        .chart-card {
            background: var(--card-bg);
            border-radius: var(--card-radius);
            padding: 24px;
            box-shadow: var(--shadow);
            border: 1px solid #f1f5f9;
            margin-bottom: 24px;
            animation: cardIn 0.7s cubic-bezier(0.2, 0.9, 0.3, 1) both;
        }
        .chart-card h3 {
            font-size: 15px;
            font-weight: 600;
            padding-bottom: 10px;
            margin-bottom: 14px;
            border-bottom: 1px solid #f1f5f9;
        }
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
            border-radius: var(--radius);
            font-size: 13px;
            cursor: pointer;
            color: var(--text-secondary);
            transition: all var(--transition);
        }
        .filter-btn:hover { border-color: var(--primary); color: var(--primary); transform: translateY(-1px); }
        .filter-btn:active { transform: scale(0.97); }
        .filter-btn.active { background: var(--primary); color: #fff; border-color: var(--primary); }
        .section {
            background: var(--card-bg);
            border-radius: var(--card-radius);
            border: 1px solid #f1f5f9;
            box-shadow: var(--shadow);
            margin-bottom: 24px;
            animation: cardIn 0.7s cubic-bezier(0.2, 0.9, 0.3, 1) both;
        }
        .section-header {
            padding: 18px 24px;
            border-bottom: 1px solid #f1f5f9;
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 12px;
        }
        .section-header h3 { font-size: 15px; font-weight: 600; }
        .section-body { padding: 24px; }
        .table-wrapper { overflow-x: auto; }
        table { width: 100%; border-collapse: collapse; }
        table th, table td {
            padding: 12px 16px;
            text-align: left;
            border-bottom: 1px solid #f1f5f9;
            font-size: 14px;
        }
        table th {
            background: var(--input-bg);
            font-weight: 600;
            color: var(--text-muted);
            font-size: 12px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            white-space: nowrap;
        }
        table tbody tr { transition: background 0.2s ease; }
        table tbody tr:hover { background: var(--bg-secondary); }
        .badge {
            display: inline-block;
            padding: 3px 10px;
            border-radius: var(--radius);
            font-size: 12px;
            font-weight: 600;
        }
        .badge-success { background: var(--success-light); color: var(--success); }
        .badge-warning { background: var(--warning-light); color: var(--warning); }
        .badge-danger { background: var(--danger-light); color: var(--danger); }
        .badge-info { background: var(--info-light); color: var(--info); }
        .badge-super { background: var(--primary); color: #fff; }
        .badge-admin { background: var(--primary-light); color: var(--primary); }
        .badge-user { background: var(--input-bg); color: var(--text-muted); }
        .btn {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 8px 16px;
            border: none;
            border-radius: var(--radius);
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
        .btn-success:hover { background: #059669; }
        .btn-danger { background: var(--danger); color: #fff; }
        .btn-danger:hover { background: #dc2626; }
        .btn-warning { background: var(--warning); color: #fff; }
        .btn-warning:hover { background: #d97706; }
        .btn-outline {
            background: transparent;
            border: 1px solid var(--border);
            color: var(--text);
        }
        .btn-outline:hover { background: var(--input-bg); border-color: var(--border); }
        .btn:hover { transform: translateY(-1px); }
        .btn:active { transform: scale(0.97); }
        .btn:disabled { opacity: 0.5; cursor: not-allowed; transform: none; }
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
            border: 1.5px solid transparent;
            border-radius: var(--radius);
            font-size: 14px;
            color: var(--text);
            background: var(--input-bg);
            transition: all var(--transition);
            outline: none;
            font-family: inherit;
        }
        .form-group input:hover, .form-group select:hover, .form-group textarea:hover { background: var(--input-hover); }
        .form-group input:focus, .form-group select:focus, .form-group textarea:focus {
            background: var(--input-focus);
            border-color: var(--primary);
            box-shadow: 0 0 0 4px rgba(17,24,39,0.04);
        }
        .form-group input.error, .form-group select.error { border-color: var(--danger); }

        /* ---------- 后台表单控件统一外观（容器无关）----------
           历史上只有 `.form-group input/select/textarea` 有样式，于是直接把裸 <input>
           写在 <div class="section-body"> 里的页面（checkin_rules / growth /
           sensitive_words / title_presets）拿到的是浏览器默认控件外观：
           内边距 0、直角、白底 —— 跟别处的输入框并排一眼就能看出不是一套。
           这里以「内容区」为范围统一收口，新增页面即使忘了包 .form-group 也不会跑偏。
           用 :where() 把特异性压到 0，避免盖掉各页面自己的宽度/尺寸微调
           （如 .ck-row input[type=number]{width:120px}）。 */
        :where(.main-content) :where(input, select, textarea) {
            padding: 10px 14px;
            border: 1.5px solid transparent;
            border-radius: var(--radius);
            font-size: 14px;
            color: var(--text);
            background: var(--input-bg);
            transition: all var(--transition);
            outline: none;
            font-family: inherit;
        }
        :where(.main-content) :where(input, select, textarea):hover { background: var(--input-hover); }
        :where(.main-content) :where(input, select, textarea):focus {
            background: var(--input-focus);
            border-color: var(--primary);
            box-shadow: 0 0 0 4px rgba(17, 24, 39, 0.04);
        }
        :where(.main-content) textarea { resize: vertical; min-height: 80px; }

        /* 特例：复选框 / 单选 / 文件 / 按钮类不该套文本控件的内边距与底色
           （否则复选框会被 padding 撑大，还丢掉了键盘焦点圈） */
        :where(.main-content) input:where(
            [type="checkbox"], [type="radio"], [type="file"], [type="submit"],
            [type="button"], [type="reset"], [type="hidden"], [type="image"], [type="range"]
        ) {
            padding: 0;
            border: 0;
            background: none;
            border-radius: 0;
            box-shadow: none;
            width: auto;
            outline: revert;      /* 交还给浏览器，保留无障碍焦点圈 */
        }

        /* 取色器：给个规整的小方块，别套文本输入框的内边距 */
        :where(.main-content) input[type="color"] {
            padding: 2px;
            width: 46px;
            height: 30px;
            border-radius: 8px;
            border: 1.5px solid var(--border);
            background: var(--input-bg);
            cursor: pointer;
            vertical-align: middle;
        }
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
            border-radius: var(--radius);
            cursor: pointer;
            font-size: 13px;
            color: var(--text);
            transition: all var(--transition);
        }
        .pagination button:hover { background: var(--input-bg); transform: translateY(-1px); }
        .pagination button:active { transform: scale(0.97); }
        .pagination button.active { background: var(--primary); color: #fff; border-color: var(--primary); }
        .pagination button:disabled { opacity: 0.4; cursor: not-allowed; transform: none; }
        .pagination .page-info { font-size: 13px; color: var(--text-secondary); padding: 0 8px; }
        .modal-overlay {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(15,23,42,0.35);
            backdrop-filter: blur(8px);
            -webkit-backdrop-filter: blur(8px);
            z-index: 200;
            align-items: center;
            justify-content: center;
        }
        .modal-overlay.show { display: flex; }
        .modal {
            background: var(--card-bg);
            border-radius: var(--card-radius);
            border: 1px solid #f1f5f9;
            box-shadow: 0 24px 80px rgba(0,0,0,0.10);
            width: 90%;
            max-width: 560px;
            max-height: 80vh;
            overflow-y: auto;
            animation: modalIn 0.3s cubic-bezier(0.2, 0.9, 0.3, 1);
        }
        @keyframes modalIn {
            from { opacity: 0; transform: translateY(-12px) scale(0.96); }
            to { opacity: 1; transform: translateY(0) scale(1); }
        }
        .modal-header {
            padding: 20px 24px;
            border-bottom: 1px solid #f1f5f9;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }
        .modal-header h3 { font-size: 16px; font-weight: 600; }
        .modal-close {
            background: none;
            border: none;
            font-size: 20px;
            cursor: pointer;
            color: var(--text-secondary);
            padding: 4px 8px;
            border-radius: var(--radius-sm);
            transition: all var(--transition);
        }
        .modal-close:hover { background: var(--input-bg); color: var(--text); }
        .modal-body { padding: 24px; }
        .modal-footer {
            padding: 16px 24px;
            border-top: 1px solid #f1f5f9;
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
            border-radius: var(--radius);
            color: #fff;
            font-size: 14px;
            font-weight: 600;
            box-shadow: 0 8px 28px rgba(0,0,0,0.12);
            animation: toastIn 0.3s cubic-bezier(0.2, 0.9, 0.3, 1);
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

        /* ---------- 高级检索面板（批次 D：D11 / D12）----------
           users.php 与 posts.php 共用，所以放在公共布局里，不要在页面里重复定义。
           默认折叠（hidden 属性），点「高级检索」才展开，不占常驻空间。 */
        .adv-filter {
            margin: 0 0 16px;
            padding: 14px;
            border: 1px solid var(--border);
            border-radius: var(--radius-sm);
            background: var(--bg);
        }
        .adv-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(180px, 1fr));
            gap: 10px 14px;
        }
        .adv-field { display: flex; flex-direction: column; gap: 4px; font-size: 12.5px; color: var(--text-secondary); }
        .adv-field select,
        .adv-field input {
            padding: 6px 8px;
            border: 1px solid var(--border);
            border-radius: var(--radius-sm);
            background: var(--card-bg);
            color: var(--text);
            font-size: 13px;
            font-family: inherit;
        }
        .adv-actions { display: flex; align-items: center; gap: 12px; margin-top: 12px; flex-wrap: wrap; }
        .adv-hint { font-size: 12px; color: var(--primary); }
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
            border: 1px solid #f1f5f9;
            border-radius: var(--card-radius);
            box-shadow: var(--shadow);
            overflow: hidden;
        }
        .perm-group-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 14px 18px;
            background: var(--input-bg);
            border-bottom: 1px solid #f1f5f9;
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
            border-radius: var(--card-radius);
            box-shadow: var(--shadow);
            border: 1px solid #f1f5f9;
            transition: box-shadow var(--transition);
            overflow: hidden;
            animation: cardIn 0.7s cubic-bezier(0.2, 0.9, 0.3, 1) both;
        }
        .card:hover { box-shadow: var(--shadow-md); }
        .card-header {
            padding: 16px 20px;
            border-bottom: 1px solid #f1f5f9;
            font-weight: 600;
            font-size: 15px;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .card-body { padding: 20px; }

        .setting-section {
            background: var(--card-bg);
            border-radius: var(--card-radius);
            box-shadow: var(--shadow);
            margin-bottom: 20px;
            overflow: hidden;
            border: 1px solid #f1f5f9;
            transition: box-shadow var(--transition);
            animation: cardIn 0.7s cubic-bezier(0.2, 0.9, 0.3, 1) both;
        }
        .setting-section:hover { box-shadow: var(--shadow-md); }
        .setting-section-title {
            padding: 16px 24px;
            font-size: 15px;
            font-weight: 600;
            color: var(--text);
            border-bottom: 1px solid #f1f5f9;
            display: flex;
            align-items: center;
            gap: 10px;
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
            <?php if (checkPermission($adminUser, 'manage_user_title')): ?>
            <a href="/admin/title_presets.php" class="<?= $page === 'title_presets.php' ? 'active' : '' ?>">
                <span class="nav-icon"><svg viewBox="0 0 24 24"><path d="M20 7h-9M14 17H5M17 3l3 3-3 3M7 21l-3-3 3-3"/></svg></span> 头衔预设
            </a>
            <?php endif; ?>
            <?php if (checkPermission($adminUser, 'review_qq_change')): ?>
            <a href="/admin/qq_changes.php" class="<?= $page === 'qq_changes.php' ? 'active' : '' ?>">
                <span class="nav-icon"><svg viewBox="0 0 24 24"><polyline points="16 3 21 3 21 8"/><line x1="4" y1="20" x2="21" y2="3"/><polyline points="21 16 21 21 16 21"/><line x1="15" y1="15" x2="21" y2="21"/><line x1="4" y1="4" x2="9" y2="9"/></svg></span> QQ修改审核
            </a>
            <?php endif; ?>
            <?php endif; ?>
            <?php if (checkPermission($adminUser, 'view_posts')): ?>
            <a href="/admin/posts.php" class="<?= $page === 'posts.php' ? 'active' : '' ?>">
                <span class="nav-icon"><svg viewBox="0 0 24 24"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="8" y1="13" x2="16" y2="13"/><line x1="8" y1="17" x2="16" y2="17"/></svg></span> 帖子管理
            </a>
            <a href="/admin/comments.php" class="<?= $page === 'comments.php' ? 'active' : '' ?>">
                <span class="nav-icon"><svg viewBox="0 0 24 24"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg></span> 评论管理
            </a>
            <a href="/admin/post_votes.php" class="<?= $page === 'post_votes.php' ? 'active' : '' ?>">
                <span class="nav-icon"><svg viewBox="0 0 24 24"><path d="M9 11l3 3L22 4"/><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"/></svg></span> 帖子投票
            </a>
            <?php endif; ?>
            <div class="nav-section">设置</div>
            <a href="/admin/settings.php" class="<?= $page === 'settings.php' ? 'active' : '' ?>">
                <span class="nav-icon"><svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1 0 2.83 2 2 0 0 1-2.83 0l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-2 2 2 2 0 0 1-2-2v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83 0 2 2 0 0 1 0-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1-2-2 2 2 0 0 1 2-2h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 0-2.83 2 2 0 0 1 2.83 0l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 2-2 2 2 0 0 1 2 2v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 0 2 2 0 0 1 0 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 2 2 2 2 0 0 1-2 2h-.09a1.65 1.65 0 0 0-1.51 1z"/></svg></span> 站点设置
            </a>
            <a href="/admin/feature_votes.php" class="<?= $page === 'feature_votes.php' ? 'active' : '' ?>">
                <span class="nav-icon"><svg viewBox="0 0 24 24"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/><line x1="8" y1="9" x2="16" y2="9"/><line x1="8" y1="13" x2="14" y2="13"/></svg></span> 功能投票
            </a>
            <?php if (checkPermission($adminUser, 'manage_growth')): ?>
            <a href="/admin/growth.php" class="<?= $page === 'growth.php' ? 'active' : '' ?>">
                <span class="nav-icon"><svg viewBox="0 0 24 24"><polyline points="23 6 13.5 15.5 8.5 10.5 1 18"/><polyline points="17 6 23 6 23 12"/></svg></span> 成长体系
            </a>
            <a href="/admin/checkin_rules.php" class="<?= $page === 'checkin_rules.php' ? 'active' : '' ?>">
                <span class="nav-icon"><svg viewBox="0 0 24 24"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg></span> 签到规则
            </a>
            <?php endif; ?>
            <?php if (checkPermission($adminUser, 'manage_sensitive_words')): ?>
            <a href="/admin/sensitive_words.php" class="<?= $page === 'sensitive_words.php' ? 'active' : '' ?>">
                <span class="nav-icon"><svg viewBox="0 0 24 24"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/><line x1="9" y1="9" x2="15" y2="15"/><line x1="15" y1="9" x2="9" y2="15"/></svg></span> 敏感词库
            </a>
            <?php endif; ?>
            <?php if (checkPermission($adminUser, 'edit_announcement')): ?>
            <a href="/admin/announcements.php" class="<?= $page === 'announcements.php' ? 'active' : '' ?>">
                <span class="nav-icon"><svg viewBox="0 0 24 24"><path d="M3 11l18-5v12L3 14v-3z"/><path d="M11.6 16.8a3 3 0 1 1-5.8-1.6"/></svg></span> 公告管理
            </a>
            <?php endif; ?>
            <?php if (checkPermission($adminUser, 'manage_admins')): ?>
            <a href="/admin/admins.php" class="<?= $page === 'admins.php' ? 'active' : '' ?>">
                <span class="nav-icon"><svg viewBox="0 0 24 24"><path d="M21 2l-2 2m-7.61 7.61a5.5 5.5 0 1 1-7.778 7.778 5.5 5.5 0 0 1 7.777-7.777zm0 0L15.5 7.5m0 0l3 3L22 7l-3-3m-3.5 3.5L19 4"/></svg></span> 管理员管理
            </a>
            <a href="/admin/permission_matrix.php" class="<?= $page === 'permission_matrix.php' ? 'active' : '' ?>">
                <span class="nav-icon"><svg viewBox="0 0 24 24"><path d="M3 3h18v18H3z"/><path d="M9 3v18M15 3v18M3 9h18M3 15h18"/></svg></span> 权限矩阵
            </a>
            <?php endif; ?>
            <a href="/admin/backup.php" class="<?= ($page === 'backup.php') ? 'active' : '' ?>">
                <span class="nav-icon"><svg viewBox="0 0 24 24"><ellipse cx="12" cy="5" rx="9" ry="3"/><path d="M21 12c0 1.66-4 3-9 3s-9-1.34-9-3"/><path d="M3 5v14c0 1.66 4 3 9 3s9-1.34 9-3V5"/><path d="M3 12c0 1.66 4 3 9 3s9-1.34 9-3"/></svg></span> 数据备份/恢复
            </a>
            <div class="nav-section">日志与安全</div>
            <a href="/admin/operation_logs.php" class="<?= $page === 'operation_logs.php' ? 'active' : '' ?>">
                <span class="nav-icon"><svg viewBox="0 0 24 24"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="8" y1="13" x2="16" y2="13"/><line x1="8" y1="17" x2="16" y2="17"/></svg></span> 操作日志
            </a>
            <?php if (checkPermission($adminUser, 'view_ai_logs')): ?>
            <a href="/admin/ai_logs.php" class="<?= $page === 'ai_logs.php' ? 'active' : '' ?>">
                <span class="nav-icon"><svg viewBox="0 0 24 24"><path d="M12 3l1.9 4.6L18.5 9.5l-4.6 1.9L12 16l-1.9-4.6L5.5 9.5l4.6-1.9z"/><path d="M18 16.5l.8 1.9 1.9.8-1.9.8-.8 1.9-.8-1.9-1.9-.8 1.9-.8z"/></svg></span> AI 调用记录
            </a>
            <?php endif; ?>
            <a href="/admin/ip_blacklist.php" class="<?= $page === 'ip_blacklist.php' ? 'active' : '' ?>">
                <span class="nav-icon"><svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><line x1="4.93" y1="4.93" x2="19.07" y2="19.07"/></svg></span> IP黑名单
            </a>
            <a href="/admin/illegal_access.php" class="<?= $page === 'illegal_access.php' ? 'active' : '' ?>">
                <span class="nav-icon"><svg viewBox="0 0 24 24"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg></span> 非法访问
            </a>
            <?php if (checkPermission($adminUser, 'view_health')): ?>
            <a href="/admin/health.php" class="<?= $page === 'health.php' ? 'active' : '' ?>">
                <span class="nav-icon"><svg viewBox="0 0 24 24"><path d="M22 12h-4l-3 9L9 3l-3 9H2"/></svg></span> 系统健康
            </a>
            <a href="/admin/data_dictionary.php" class="<?= $page === 'data_dictionary.php' ? 'active' : '' ?>">
                <span class="nav-icon"><svg viewBox="0 0 24 24"><ellipse cx="12" cy="5" rx="9" ry="3"/><path d="M3 5v14c0 1.66 4 3 9 3s9-1.34 9-3V5"/><path d="M3 12c0 1.66 4 3 9 3s9-1.34 9-3"/></svg></span> 数据字典
            </a>
            <?php endif; ?>
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
                    <div class="user-role <?= $adminUser['role'] === 'super_admin' ? 'super' : '' ?>"><?= $adminUser['role'] === 'super_admin' ? '超级管理员' : ('管理员 · ' . getPermissionModeLabel(getPermissionMode($adminUser))) ?></div>
                </div>
                <button class="logout-btn" onclick="event.preventDefault();fetch('/api/auth/logout.php',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:'csrf_token='+encodeURIComponent(<?= json_encode($csrfToken) ?>)}).then(function(){location.href='/'})" title="退出登录" aria-label="退出登录"><svg viewBox="0 0 24 24"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg></button>
            </div>
        </div>
    </aside>
    <div class="main-content">
        <div class="top-bar">
            <button class="menu-toggle" id="menuToggle" aria-label="菜单"><svg viewBox="0 0 24 24"><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="18" x2="21" y2="18"/></svg></button>
            <div class="top-bar-title">
                <nav class="breadcrumb"><a href="/admin/dashboard.php">管理后台</a> / <span><?= xss_clean($title) ?></span></nav>
                <h1 class="page-title"><?= xss_clean($title) ?></h1>
            </div>
            <div class="top-actions">
                <button type="button" class="admin-theme-toggle" id="adminThemeToggle" aria-label="主题"><span class="tt-icon"></span><span class="tt-text"></span></button>
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
    </div>
    <script src="<?= function_exists('asset_url') ? asset_url('/admin/assets/js/admin.common.js') : '/admin/assets/js/admin.common.js' ?>?v=<?= function_exists('asset_ver') ? asset_ver('/admin/assets/js/admin.common.js') : '1' ?>"></script>
    <script>

    document.getElementById('menuToggle').addEventListener('click', function() {
        document.getElementById('sidebar').classList.toggle('open');
        document.getElementById('sidebarOverlay').classList.toggle('show');
    });
    var adminThemeBtn = document.getElementById('adminThemeToggle');
    if (adminThemeBtn && window.LWAdminTheme) {
        adminThemeBtn.addEventListener('click', function() { window.LWAdminTheme.next(); });
    }
    document.getElementById('sidebarOverlay').addEventListener('click', function() {
        document.getElementById('sidebar').classList.remove('open');
        document.getElementById('sidebarOverlay').classList.remove('show');
    });

    // esc / showToast / openModal / closeModal 已移到 adminHeader 的 <head> 里定义
    // （页面脚本一发 AJAX 就可能用到，放在页尾会因 Promise 微任务先跑而「未定义」）。

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
