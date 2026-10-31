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

    /* 在给定的一组背景色里挑「最不容易糊」的前景色，返回 '#FFFFFF' 或 '#10161F'。
       用途：称号徽章的底色是站长自选的（默认彩虹渐变 #ff4757→#a55eea），
       而前景色过去写死 #fff —— 实测白字落在这些默认底色上只有 3.34~3.90:1，
       不达 WCAG AA 正文 4.5:1，站长一选偏亮的底色称号就糊在背景里。
       与前台 main.js 的 pickReadableFg、PHP 的 lwReadableFg 同一算法。 */
    function readableFg() {
        var lum = function (hex) {
            var h = String(hex || '').replace('#', '');
            if (h.length === 3) h = h[0] + h[0] + h[1] + h[1] + h[2] + h[2];
            if (!/^[0-9a-f]{6}$/i.test(h)) return null;
            var c = [0, 2, 4].map(function (i) {
                var v = parseInt(h.substr(i, 2), 16) / 255;
                return v <= 0.03928 ? v / 12.92 : Math.pow((v + 0.055) / 1.055, 2.4);
            });
            return 0.2126 * c[0] + 0.7152 * c[1] + 0.0722 * c[2];
        };
        var best = '#FFFFFF', bestWorst = -1;
        var cands = ['#FFFFFF', '#10161F'];
        for (var i = 0; i < cands.length; i++) {
            var lf = lum(cands[i]), worst = null;
            for (var j = 0; j < arguments.length; j++) {
                var lb = lum(arguments[j]);
                if (lb === null) continue;   // rgb()/hsl()/具名色，解析不了就跳过
                var ratio = (Math.max(lf, lb) + 0.05) / (Math.min(lf, lb) + 0.05);
                worst = (worst === null) ? ratio : Math.min(worst, ratio);
            }
            if (worst !== null && worst > bestWorst) { bestWorst = worst; best = cands[i]; }
        }
        return best;
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
            /* 语义色取「深阶」：后台原用 Tailwind 500 亮阶（#10b981 / #f59e0b / #ef4444 / #3b82f6），
               白字压在上面只有 2.5~3.8:1、它们自己当徽章文字压浅底也只有 2.5~3.7:1，全不达标。
               改为与前台 style.css 同一组深阶（白字 4.8~5.9:1，压浅底同样 ≥4.7:1），
               顺带把两套 UI 的语义配色拉齐。 */
            --success: #277A4C;
            --success-light: #ecfdf5;
            --warning: #8A5A0B;
            --warning-light: #fffbeb;
            --danger: #C2453F;
            --danger-light: #fef2f2;
            --info: #2773C4;
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

            /* ── 「实底 + 文字」成对前景色 / 反白容器 ─────────────────────────────
               后台有自己的令牌集（不加载前台 style.css），所以这一组必须在这里重复定义，
               否则页面里 color: var(--on-solid, #fff) 取不到值、直接退化成 #fff —— 等于白改。

               浅色：--on-solid 用白字（语义色都够深，5.5:1 以上）。
               暗色：语义色为了在深底上可见被提亮成亮阶（--primary:#5B8CC9、
               --success:#4CAF7D、--warning:#D0A24C、--danger:#E06A64），白字只剩
               2.35~3.48:1，不达 WCAG AA 正文 4.5:1；换成近黑字后是 5.2~7.7:1。 */
            --on-solid: #FFFFFF;
            --on-accent: #10161F;
            --invert-bg: #1e293b;
            --invert-fg: #FFFFFF;
            --invert-hover: rgba(255, 255, 255, 0.16);

            /* 提示条四型（与前台 style.css 同名同色，保持两套 UI 观感一致） */
            --toast-fg: #FFFFFF;
            --toast-success: #1E7A4C;
            --toast-warning: #8A5A0B;
            --toast-danger: #A9312B;
            --toast-info: #1D5A9E;
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

            /* 暗色下换成「亮底近黑字」：语义实底 5.2~7.7:1（白字只剩 2.35~3.48:1）。
               --invert-* 用于反白容器（toast/气泡）：暗色必须翻成亮底近黑字。 */
            --on-solid: #10161F;
            --on-accent: #10161F;
            --invert-bg: #E4E9F0;
            --invert-fg: #10161F;
            --invert-hover: rgba(0, 0, 0, 0.10);

            /* 提示条同步翻成亮底近黑字 */
            --toast-fg: #10161F;
            --toast-success: #4CAF7D;
            --toast-warning: #D0A24C;
            --toast-danger: #E06A64;
            --toast-info: #5C9BE0;
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
            color: var(--on-solid, #fff);
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
            /* 以前是 display:none，只靠图标。图标 SVG 一旦没注入（脚本报错/缓存旧版），
           这个按钮就变成一个**空白的圆角方块**，用户完全不知道它是什么 ——
           站长发来的截图正是这个现象。改成始终显示文字，图标丢了也不影响可用性。 */
        .admin-theme-toggle .tt-text { display: inline; }
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
        .filter-btn.active { background: var(--primary); color: var(--on-solid, #fff); border-color: var(--primary); }
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
        .badge-super { background: var(--primary); color: var(--on-solid, #fff); }
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
        .btn-primary { background: var(--primary); color: var(--on-solid, #fff); }
        .btn-primary:hover { background: var(--primary-hover); }
        .btn-success { background: var(--success); color: var(--on-solid, #fff); }
        .btn-success:hover { background: #1F6A42; }
        .btn-danger { background: var(--danger); color: var(--on-solid, #fff); }
        .btn-danger:hover { background: #A63A34; }
        .btn-warning { background: var(--warning); color: var(--on-solid, #fff); }
        .btn-warning:hover { background: #744C09; }
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
        .pagination button.active { background: var(--primary); color: var(--on-solid, #fff); border-color: var(--primary); }
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
            /* 后台 .modal 的背景是 var(--card-bg)（不透明），但底部按钮区在滚动容器内，
               窄屏吸底时若不显式给底色，正文会从按钮下方透出。这里统一给实心底色，
               与 .modal 同色，视觉无缝且不透明。 */
            background: var(--card-bg, #ffffff);
        }
        /* 窄屏：弹窗改为「头尾固定、正文内滚」，避免按钮区随内容滚走或被正文透出 */
        @media (max-width: 768px) {
            .modal {
                display: flex;
                flex-direction: column;
                overflow: hidden;
            }
            .modal-header {
                flex-shrink: 0;
                position: sticky;
                top: 0;
                z-index: 2;
                background: var(--card-bg, #ffffff);
            }
            .modal-body {
                flex: 1 1 auto;
                min-height: 0;
                overflow-y: auto;
                -webkit-overflow-scrolling: touch;
            }
            .modal-footer {
                flex-shrink: 0;
                position: sticky;
                bottom: 0;
                z-index: 2;
                background: var(--card-bg, #ffffff);
            }
        }
        .toast {
            position: fixed;
            top: 20px;
            right: 20px;
            z-index: 300;
            padding: 14px 20px;
            border-radius: var(--radius);
            color: var(--toast-fg, #fff);
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
        .toast-success { background: var(--toast-success, #1E7A4C); }
        .toast-error { background: var(--toast-danger, #A9312B); }
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
            border: solid var(--on-solid, #fff);
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
    
/* 后台侧边栏菜单筛选 */
.nav-filter-wrap { padding: 0 14px 10px; }
.nav-filter {
    width: 100%; padding: 7px 11px; font-size: 12.5px; font-family: inherit;
    border: 1px solid rgba(255,255,255,.12); border-radius: 8px;
    background: rgba(255,255,255,.07); color: #D6DEE8; outline: none;
    transition: border-color .15s, background .15s;
}
.nav-filter::placeholder { color: #7A879A; }
.nav-filter:focus {
    border-color: rgba(120,170,240,.5);
    background: rgba(255,255,255,.11);
}
.sidebar-nav .nav-item-hidden { display: none !important; }
.sidebar-nav .nav-section-empty { display: none !important; }

/* ==========================================================================
 * 统一组件规范（v2.4.4）
 * ==========================================================================
 * 【为什么需要这一层】
 * 后台 40 多个页面，每个都自定义了 rp- / bs- / pc- / cl- / cfg- 这类前缀，
 * 于是同样一个「按钮」在不同页面长得不一样，同样一个「卡片」圆角不同，
 * 同样一个「状态徽章」配色不同 —— 用户点来点去会觉得像好几个网站。
 *
 * 这一层定义一套标准组件，所有页面都应该优先用它们：
 *   .adm-card     卡片容器
 *   .adm-toolbar  工具栏（搜索 + 筛选按钮）
 *   .adm-table    表格
 *   .adm-badge    状态徽章（配 -ok/-warn/-err/-info/-muted）
 *   .adm-empty    空状态
 *   .adm-form-*   表单
 *
 * 老页面不用一次性全改 —— 新写的用这套，改到的顺手换。
 * ========================================================================== */

/* ---- 卡片 ---- */
.adm-card {
    background: var(--card-bg);
    border: 1px solid var(--border);
    border-radius: var(--radius);
    overflow: hidden;
    margin-bottom: 16px;
}
.adm-card-head {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    flex-wrap: wrap;
    padding: 14px 18px;
    border-bottom: 1px solid var(--border-light);
    background: var(--bg-secondary);
}
.adm-card-title {
    font-size: 14px;
    font-weight: 700;
    color: var(--text);
    margin: 0;
}
.adm-card-desc {
    font-size: 12px;
    color: var(--text-muted);
    margin-top: 3px;
    line-height: 1.6;
}
.adm-card-body { padding: 18px; }
.adm-card-body.is-flush { padding: 0; }

/* ---- 工具栏 ---- */
.adm-toolbar {
    display: flex;
    gap: 10px;
    align-items: center;
    flex-wrap: wrap;
    margin-bottom: 16px;
}
.adm-toolbar-spacer { flex: 1 1 auto; }

.adm-input {
    padding: 8px 12px;
    font-size: 13px;
    font-family: inherit;
    color: var(--text);
    background: var(--input-bg);
    border: 1px solid var(--border);
    border-radius: var(--radius-sm);
    outline: none;
    transition: border-color var(--transition), background var(--transition);
}
.adm-input:focus {
    border-color: var(--primary);
    background: var(--input-focus);
}
.adm-input::placeholder { color: var(--text-muted); }

/* ---- 表格 ---- */
.adm-table-wrap {
    overflow-x: auto;
    border-radius: var(--radius);
    border: 1px solid var(--border);
}
.adm-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 13px;
}
.adm-table thead th {
    padding: 10px 14px;
    text-align: left;
    font-size: 12px;
    font-weight: 600;
    color: var(--text-muted);
    background: var(--bg-secondary);
    border-bottom: 1px solid var(--border);
    white-space: nowrap;
}
.adm-table tbody td {
    padding: 11px 14px;
    border-bottom: 1px solid var(--border-light);
    color: var(--text);
    vertical-align: middle;
}
.adm-table tbody tr:last-child td { border-bottom: 0; }
.adm-table tbody tr:hover { background: var(--bg-secondary); }

/* ---- 徽章 ---- */
.adm-badge {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    padding: 3px 9px;
    border-radius: 999px;
    font-size: 11.5px;
    font-weight: 600;
    line-height: 1.5;
    white-space: nowrap;
}
.adm-badge-ok    { background: var(--success-light); color: var(--success); }
.adm-badge-warn  { background: var(--warning-light); color: var(--warning); }
.adm-badge-err   { background: var(--danger-light);  color: var(--danger); }
.adm-badge-info  { background: var(--info-light);    color: var(--info); }
.adm-badge-muted { background: var(--bg-secondary);  color: var(--text-muted); }

/* ---- 空状态 ---- */
.adm-empty {
    padding: 44px 20px;
    text-align: center;
    color: var(--text-muted);
    font-size: 13px;
    line-height: 1.8;
}

/* ---- 表单 ---- */
.adm-field { margin-bottom: 16px; }
.adm-label {
    display: block;
    font-size: 13px;
    font-weight: 600;
    color: var(--text);
    margin-bottom: 6px;
}
.adm-hint {
    font-size: 12px;
    color: var(--text-muted);
    line-height: 1.65;
    margin-bottom: 6px;
}
.adm-field .adm-input,
.adm-field textarea,
.adm-field select { width: 100%; }
.adm-field textarea {
    padding: 9px 12px;
    font-size: 13.5px;
    font-family: inherit;
    line-height: 1.7;
    color: var(--text);
    background: var(--input-bg);
    border: 1px solid var(--border);
    border-radius: var(--radius-sm);
    outline: none;
    resize: vertical;
}
.adm-field textarea:focus {
    border-color: var(--primary);
    background: var(--input-focus);
}

/* ---- 栅格 ---- */
.adm-grid { display: grid; gap: 14px; }
.adm-grid-2 { grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); }
.adm-grid-3 { grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); }

/* ---- 提示条 ---- */
.adm-alert {
    padding: 11px 14px;
    border-radius: var(--radius-sm);
    font-size: 13px;
    line-height: 1.7;
    margin-bottom: 16px;
    border: 1px solid transparent;
}
.adm-alert-ok   { background: var(--success-light); color: var(--success); border-color: var(--success-light); }
.adm-alert-warn { background: var(--warning-light); color: var(--warning); border-color: var(--warning-light); }
.adm-alert-err  { background: var(--danger-light);  color: var(--danger);  border-color: var(--danger-light); }
.adm-alert-info { background: var(--info-light);    color: var(--info);    border-color: var(--info-light); }

/* ---- 分页 ---- */
.adm-pager {
    display: flex;
    gap: 8px;
    align-items: center;
    justify-content: center;
    margin-top: 16px;
    font-size: 13px;
    color: var(--text-muted);
}
.adm-pager a, .adm-pager button {
    padding: 6px 13px;
    border-radius: var(--radius-sm);
    font-size: 13px;
    font-family: inherit;
    text-decoration: none;
    color: var(--text-secondary);
    background: var(--bg-secondary);
    border: 1px solid var(--border);
    cursor: pointer;
    transition: background var(--transition), color var(--transition);
}
.adm-pager a:hover, .adm-pager button:hover:not(:disabled) {
    background: var(--primary);
    color: var(--on-solid);
    border-color: var(--primary);
}
.adm-pager .is-current {
    background: var(--primary);
    color: var(--on-solid);
    border-color: var(--primary);
}
.adm-pager button:disabled { opacity: .45; cursor: not-allowed; }

/* ---- 移动端 ---- */
@media (max-width: 640px) {
    .adm-card-body { padding: 14px; }
    .adm-table thead th, .adm-table tbody td { padding: 9px 10px; }
    .adm-toolbar { gap: 8px; }
}
</style>
    <?php // 交互式图表：必须在 </head> 之前，否则 dashboard 的初始化脚本会因 LwChart 未定义而静默失败 ?>
    <link rel="stylesheet" href="/assets/css/lw_chart.css?v=<?= @filemtime(__DIR__ . '/../assets/css/lw_chart.css') ?: 1 ?>">
    <script src="/assets/js/lw_chart.js?v=<?= @filemtime(__DIR__ . '/../assets/js/lw_chart.js') ?: 1 ?>"></script>
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
        <div class="nav-filter-wrap">
            <input type="text" class="nav-filter" id="adminNavFilter"
                   placeholder="筛选菜单…" autocomplete="off">
        </div>

            <div class="nav-section">内容与用户</div>
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
            <div class="nav-section">站点配置</div>
            <a href="/admin/settings.php" class="<?= $page === 'settings.php' ? 'active' : '' ?>">
                <span class="nav-icon"><svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1 0 2.83 2 2 0 0 1-2.83 0l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-2 2 2 2 0 0 1-2-2v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83 0 2 2 0 0 1 0-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1-2-2 2 2 0 0 1 2-2h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 0-2.83 2 2 0 0 1 2.83 0l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 2-2 2 2 0 0 1 2 2v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 0 2 2 0 0 1 0 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 2 2 2 2 0 0 1-2 2h-.09a1.65 1.65 0 0 0-1.51 1z"/></svg></span> 站点设置
            </a>
            <?php if (checkPermission($adminUser, 'manage_liquid_glass')): ?>
            <a href="/admin/liquid_glass.php" class="<?= $page === 'liquid_glass.php' ? 'active' : '' ?>">
                <span class="nav-icon"><svg viewBox="0 0 24 24"><path d="M12 21c-5 0-9-3.6-9-8 0-3.6 3.6-9 9-9s9 5.4 9 9c0 1.4-1.1 2.4-2.4 2.4h-1.7c-.8 0-1.4.6-1.4 1.4 0 .3.1.6.3.9.2.3.3.6.3 1 0 1.1-1.1 2.3-3.1 2.3z"/></svg></span> 液态玻璃管控
            </a>
            <?php endif; ?>
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
            <a href="/admin/widgets.php" class="<?= $page === 'widgets.php' ? 'active' : '' ?>">
                <span class="nav-icon"><svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><path d="M12 3v4"/><path d="M12 17v4"/><path d="M3 12h4"/><path d="M17 12h4"/><circle cx="12" cy="12" r="2.5"/></svg></span> 装扮管理
            </a>
            <a href="/admin/shop.php" class="<?= $page === 'shop.php' ? 'active' : '' ?>">
                <span class="nav-icon"><svg viewBox="0 0 24 24"><path d="M3 6h18l-1.5 12.5a2 2 0 0 1-2 1.5h-11a2 2 0 0 1-2-1.5z"/><path d="M8 6a4 4 0 0 1 8 0"/><line x1="9" y1="13" x2="15" y2="13"/></svg></span> 商城管理
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
            <div class="nav-section">运营工具</div>
            <a href="/admin/analytics.php" class="<?= $page === 'analytics.php' ? 'active' : '' ?>">
                <span class="nav-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="20" x2="18" y2="10"/><line x1="12" y1="20" x2="12" y2="4"/><line x1="6" y1="20" x2="6" y2="14"/></svg></span> 数据看板
            </a>
            <a href="/admin/moderation.php" class="<?= $page === 'moderation.php' ? 'active' : '' ?>">
                <span class="nav-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/><path d="M9 12l2 2 4-4"/></svg></span> 内容审核
            </a>
            <a href="/admin/review_queue.php" class="<?= $page === 'review_queue.php' ? 'active' : '' ?>">
                <span class="nav-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 11l3 3L22 4"/><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"/></svg></span> 审核队列
            </a>
            <a href="/admin/filehelper_config.php" class="<?= $page === 'filehelper_config.php' ? 'active' : '' ?>">
                <span class="nav-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 15a3 3 0 1 0 0-6 3 3 0 0 0 0 6z"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83-2.83l.06-.06A1.65 1.65 0 0 0 4.6 15a1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 2.83-2.83l.06.06A1.65 1.65 0 0 0 9 4.6a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 2.83l-.06.06A1.65 1.65 0 0 0 19.4 9c.14.63.73 1.1 1.51 1H21a2 2 0 0 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/></svg></span> 网盘中转配置
            </a>
            <a href="/admin/selfheal.php" class="<?= $page === 'selfheal.php' ? 'active' : '' ?>">
                <span class="nav-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 12h-4l-3 9L9 3l-3 9H2"/></svg></span> 自恢复守护
            </a>
            <a href="/admin/invite_graph.php" class="<?= $page === 'invite_graph.php' ? 'active' : '' ?>">
                <span class="nav-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="5" r="3"/><circle cx="5" cy="19" r="3"/><circle cx="19" cy="19" r="3"/><line x1="12" y1="8" x2="5" y2="16"/><line x1="12" y1="8" x2="19" y2="16"/></svg></span> 邀请关系图
            </a>
        <a href="/admin/points_codes.php" class="<?= $page === 'points_codes.php' ? 'active' : '' ?>">
            <span class="nav-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M15 5v2m0 4v2m0 4v2M5 5h14a2 2 0 0 1 2 2v3a2 2 0 0 0 0 4v3a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-3a2 2 0 0 0 0-4V7a2 2 0 0 1 2-2z"/></svg></span> 兑换码
        </a>
        <a href="/admin/mail_settings.php" class="<?= $page === 'mail_settings.php' ? 'active' : '' ?>">
            <span class="nav-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="4" width="20" height="16" rx="2"/><path d="m22 7-8.97 5.7a1.94 1.94 0 0 1-2.06 0L2 7"/></svg></span> 邮件系统
        </a>
        <a href="/admin/changelog.php" class="<?= $page === 'changelog.php' ? 'active' : '' ?>">
            <span class="nav-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/></svg></span> 更新日志
        </a>
            <a href="/admin/config_center.php" class="<?= $page === 'config_center.php' ? 'active' : '' ?>">
                <span class="nav-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 2.83-2.83l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/></svg></span> 全站配置
            </a>
            <div class="nav-section">安全与日志</div>
            <a href="/admin/filehelper_log.php" class="<?= $page === 'filehelper_log.php' ? 'active' : '' ?>">
                <span class="nav-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg></span> 文件传输记录
            </a>

            <a href="/admin/operation_logs.php" class="<?= $page === 'operation_logs.php' ? 'active' : '' ?>">
                <span class="nav-icon"><svg viewBox="0 0 24 24"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="8" y1="13" x2="16" y2="13"/><line x1="8" y1="17" x2="16" y2="17"/></svg></span> 操作日志
            </a>
            <?php if (($adminUser['role'] ?? '') === 'super_admin'): ?>
            <a href="/admin/security_alerts.php" class="<?= $page === 'security_alerts.php' ? 'active' : '' ?>">
                <span class="nav-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg></span> 安全告警
            </a>
            <?php endif; ?>
            <?php if (checkPermission($adminUser, 'view_ai_logs')): ?>
            <a href="/admin/ai_logs.php" class="<?= $page === 'ai_logs.php' ? 'active' : '' ?>">
                <span class="nav-icon"><svg viewBox="0 0 24 24"><path d="M12 3l1.9 4.6L18.5 9.5l-4.6 1.9L12 16l-1.9-4.6L5.5 9.5l4.6-1.9z"/><path d="M18 16.5l.8 1.9 1.9.8-1.9.8-.8 1.9-.8-1.9-1.9-.8 1.9-.8z"/></svg></span> AI 调用记录
            </a>
            <?php endif; ?>
            <?php if ($adminUser['role'] === 'super_admin'): ?>
            <a href="/admin/ai_settings.php" class="<?= $page === 'ai_settings.php' ? 'active' : '' ?>">
                <span class="nav-icon"><svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83-2.83l.06-.06A1.65 1.65 0 0 0 4.6 15a1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 2.83-2.83l.06.06A1.65 1.65 0 0 0 9 4.6a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 2.83l-.06.06A1.65 1.65 0 0 0 19.4 9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/></svg></span> AI 设置
            </a>
            <?php endif; ?>
            <?php if ($adminUser['role'] === 'super_admin'): ?>
            <a href="/admin/layout_settings.php" class="<?= $page === 'layout_settings.php' ? 'active' : '' ?>">
                <span class="nav-icon"><svg viewBox="0 0 24 24"><rect x="3" y="3" width="18" height="18" rx="2"/><line x1="3" y1="9" x2="21" y2="9"/><line x1="9" y1="21" x2="9" y2="9"/></svg></span> 自适应布局
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
            <?php if (checkPermission($adminUser, 'view_app_devices') || checkPermission($adminUser, 'manage_app_push')): ?>
            <div class="nav-section">安卓客户端</div>
            <?php if (checkPermission($adminUser, 'view_app_devices')): ?>
            <a href="/admin/app_users.php" class="<?= $page === 'app_users.php' ? 'active' : '' ?>">
                <span class="nav-icon"><svg viewBox="0 0 24 24"><rect x="5" y="2" width="14" height="20" rx="2"/><line x1="12" y1="18" x2="12.01" y2="18"/></svg></span> App 装机台账
            </a>
            <?php endif; ?>
            <?php if (checkPermission($adminUser, 'manage_app_push')): ?>
            <a href="/admin/app_push.php" class="<?= $page === 'app_push.php' ? 'active' : '' ?>">
                <span class="nav-icon"><svg viewBox="0 0 24 24"><path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/></svg></span> 系统通知推送
            </a>
            <?php endif; ?>
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
                <button type="button" class="admin-theme-toggle" id="adminThemeToggle" aria-label="主题"><span class="tt-icon"></span><span class="tt-text">主题</span></button>
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
    
    <?php // 图标动效 CSS（位置无所谓，纯样式）?>
    <link rel="stylesheet" href="/assets/css/icon-motion.css?v=<?= @filemtime(__DIR__ . '/../assets/css/icon-motion.css') ?: 1 ?>">
<script src="<?= function_exists('asset_url') ? asset_url('/admin/assets/js/admin.common.js') : '/admin/assets/js/admin.common.js' ?>?v=<?= function_exists('asset_ver') ? asset_ver('/admin/assets/js/admin.common.js') : '1' ?>"></script>
    <?php // 交互式图表（必须在任何使用它的脚本之前加载）?>
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

<script>
/* 后台侧边栏菜单筛选
 *
 * 后台有 42 个页面，靠分组已经能收窄，但「我记得有个页面叫 xx」
 * 还是要一组一组翻。加个输入框，打字就过滤 —— 三秒找到。
 */
(function () {
    var box = document.getElementById('adminNavFilter');
    if (!box) return;
    var nav = box.closest('.sidebar-nav') || document.querySelector('.sidebar-nav');
    if (!nav) return;
    var items = Array.prototype.slice.call(nav.querySelectorAll('a[href^="/admin/"]'));
    var sections = Array.prototype.slice.call(nav.querySelectorAll('.nav-section'));

    function apply() {
        var q = box.value.trim().toLowerCase();
        if (!q) {
            items.forEach(function (a) { a.classList.remove('nav-item-hidden'); });
            sections.forEach(function (sec) { sec.classList.remove('nav-section-empty'); });
            return;
        }
        items.forEach(function (a) {
            var txt = (a.textContent || '').toLowerCase();
            var href = (a.getAttribute('href') || '').toLowerCase();
            var hit = txt.indexOf(q) !== -1 || href.indexOf(q) !== -1;
            a.classList.toggle('nav-item-hidden', !hit);
        });
        // 分组标题：下面一个可见项都没有就隐藏
        sections.forEach(function (sec) {
            var next = sec.nextElementSibling;
            var any = false;
            while (next && !next.classList.contains('nav-section')) {
                if (next.tagName === 'A' && !next.classList.contains('nav-item-hidden')) {
                    any = true;
                    break;
                }
                next = next.nextElementSibling;
            }
            sec.classList.toggle('nav-section-empty', !any);
        });
    }

    box.addEventListener('input', apply);
    box.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') { box.value = ''; apply(); box.blur(); }
    });
})();
</script>
<script>
/**
 * 后台通用增强（细节改进包）
 *
 * 这里的每一项都是「用过一次就回不去」的小东西。
 * 全部是渐进增强：不支持的浏览器上不影响任何功能。
 */
(function () {
    'use strict';

    if (window.__lwAdminEnhanced) return;
    window.__lwAdminEnhanced = true;

    /* ── 1. 键盘快捷键 ──────────────────────────────────────── */
    document.addEventListener('keydown', function (e) {
        var tag = (e.target.tagName || '').toUpperCase();
        var typing = tag === 'INPUT' || tag === 'TEXTAREA' || tag === 'SELECT' || e.target.isContentEditable;

        // "/" 聚焦搜索框
        if (e.key === '/' && !typing) {
            var s = document.getElementById('adminNavFilter')
                 || document.querySelector('.nav-filter')
                 || document.querySelector('input[type="search"]')
                 || document.querySelector('input[type="text"]');
            if (s) { e.preventDefault(); s.focus(); s.select && s.select(); }
        }

        // Ctrl/Cmd + S：提交当前表单（防丢草稿）
        if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 's') {
            var form = e.target.closest && e.target.closest('form');
            if (!form) form = document.querySelector('form.main-form, .section-body form');
            if (form) {
                e.preventDefault();
                if (form.requestSubmit) form.requestSubmit();
                else form.submit();
            }
        }

        // Esc：关闭任何可见弹窗
        if (e.key === 'Escape') {
            document.querySelectorAll('.modal.show, .rp-modal.show, .lw-modal.show')
                .forEach(function (m) { m.classList.remove('show'); });
        }

        // Alt + 数字：跳到第 N 个分组（快速定位）
        if (e.altKey && /^[1-9]$/.test(e.key)) {
            var secs = document.querySelectorAll('.sidebar-nav .nav-section');
            var idx = parseInt(e.key, 10) - 1;
            if (secs[idx]) {
                e.preventDefault();
                secs[idx].scrollIntoView({ block: 'center', behavior: 'smooth' });
            }
        }
    });

    /* ── 2. 表格行高亮与点击整行 ────────────────────────────── */
    document.querySelectorAll('table tbody tr').forEach(function (tr) {
        if (tr.querySelectorAll('td').length < 2) return;
        tr.style.cursor = 'default';
        tr.addEventListener('mouseenter', function () {
            this.style.transition = 'background .12s';
        });
        // 点击行内空白处：如果行里有「详情」按钮就触发它
        tr.addEventListener('dblclick', function (e) {
            if (e.target.closest('button, a, input, select')) return;
            var btn = this.querySelector('button[onclick*="how"], button[onclick*="etail"], a[href*="id="]');
            if (btn) btn.click();
        });
    });

    /* ── 3. 长表格自动加「回到顶部」 ─────────────────────────── */
    if (document.body.scrollHeight > window.innerHeight * 2.2) {
        var top = document.createElement('button');
        top.type = 'button';
        top.innerHTML = '↑';
        top.setAttribute('aria-label', '回到顶部');
        top.style.cssText = [
            'position:fixed', 'right:24px', 'bottom:24px', 'width:42px', 'height:42px',
            'border-radius:50%', 'border:1px solid rgba(15,23,42,.1)',
            'background:rgba(255,255,255,.92)', 'backdrop-filter:blur(8px)',
            'color:#2F5B9A', 'font-size:18px', 'cursor:pointer', 'z-index:9000',
            'box-shadow:0 4px 16px -6px rgba(16,24,40,.25)',
            'opacity:0', 'pointer-events:none', 'transition:opacity .2s'
        ].join(';');
        document.body.appendChild(top);

        var ticking = false;
        window.addEventListener('scroll', function () {
            if (ticking) return;
            ticking = true;
            requestAnimationFrame(function () {
                var show = window.scrollY > 400;
                top.style.opacity = show ? '1' : '0';
                top.style.pointerEvents = show ? 'auto' : 'none';
                ticking = false;
            });
        }, { passive: true });

        top.addEventListener('click', function () {
            window.scrollTo({ top: 0, behavior: 'smooth' });
        });
    }

    /* ── 4. 表单离开提醒（防丢改动）─────────────────────────── */
    var dirtyForms = new WeakSet();
    document.querySelectorAll('form').forEach(function (f) {
        var hasSubmit = f.querySelector('[type="submit"], button:not([type="button"])');
        if (!hasSubmit) return;
        f.addEventListener('input', function () { dirtyForms.add(f); });
        f.addEventListener('submit', function () { dirtyForms.delete(f); });
    });
    window.addEventListener('beforeunload', function (e) {
        var dirty = false;
        document.querySelectorAll('form').forEach(function (f) {
            if (dirtyForms.has(f)) dirty = true;
        });
        if (dirty) {
            e.preventDefault();
            e.returnValue = '';
            return '';
        }
    });

    /* ── 5. 数字输入框：滚轮不再误改值 ──────────────────────── */
    document.querySelectorAll('input[type="number"]').forEach(function (inp) {
        inp.addEventListener('wheel', function (e) {
            // 只有聚焦时才允许滚轮改值
            if (document.activeElement !== inp) return;
            e.preventDefault();
            var d = e.deltaY < 0 ? 1 : -1;
            var v = parseFloat(inp.value) || 0;
            var step = parseFloat(inp.step) || 1;
            var min = inp.min !== '' ? parseFloat(inp.min) : -Infinity;
            var max = inp.max !== '' ? parseFloat(inp.max) : Infinity;
            var nv = Math.min(max, Math.max(min, v + d * step));
            inp.value = nv;
            inp.dispatchEvent(new Event('input', { bubbles: true }));
        }, { passive: false });
    });

    /* ── 6. 复制按钮：任意 code / pre 可一键复制 ────────────── */
    document.querySelectorAll('pre, code.pc-code, .copyable').forEach(function (el) {
        if (el.closest('.no-copy')) return;
        el.style.position = 'relative';
        var btn = document.createElement('button');
        btn.type = 'button';
        btn.textContent = '复制';
        btn.style.cssText = [
            'position:absolute', 'top:6px', 'right:6px', 'padding:3px 9px',
            'font-size:11px', 'border-radius:6px', 'cursor:pointer',
            'border:1px solid rgba(15,23,42,.14)', 'background:#fff',
            'color:#5A6472', 'opacity:0', 'transition:opacity .15s', 'z-index:2'
        ].join(';');
        el.appendChild(btn);
        el.addEventListener('mouseenter', function () { btn.style.opacity = '1'; });
        el.addEventListener('mouseleave', function () { btn.style.opacity = '0'; });
        btn.addEventListener('click', function (e) {
            e.stopPropagation();
            var txt = el.textContent.replace('复制', '').trim();
            if (navigator.clipboard) {
                navigator.clipboard.writeText(txt).then(function () {
                    btn.textContent = '已复制';
                    setTimeout(function () { btn.textContent = '复制'; }, 1200);
                });
            }
        });
    });

    /* ── 7. 时间显示：悬停显示完整时间 ──────────────────────── */
    document.querySelectorAll('td, .meta, .rp-time, .mail-table td').forEach(function (el) {
        var t = (el.textContent || '').trim();
        // 匹配 MM-DD HH:MM 或 YYYY-MM-DD
        if (/^\d{2}-\d{2} \d{2}:\d{2}$/.test(t) || /^\d{4}-\d{2}-\d{2}$/.test(t)) {
            el.title = '完整时间：' + t;
        }
    });

    /* ── 8. 表格排序（点击表头）─────────────────────────────── */
    document.querySelectorAll('table').forEach(function (table) {
        var thead = table.querySelector('thead');
        var tbody = table.querySelector('tbody');
        if (!thead || !tbody) return;
        var ths = thead.querySelectorAll('th');
        if (ths.length < 2 || tbody.rows.length < 3) return;

        ths.forEach(function (th, col) {
            // 跳过「操作」列
            if (/操作|action/i.test(th.textContent)) return;
            th.style.cursor = 'pointer';
            th.title = '点击排序';
            var asc = true;
            th.addEventListener('click', function () {
                var rows = Array.prototype.slice.call(tbody.rows);
                rows.sort(function (a, b) {
                    var av = (a.cells[col] ? a.cells[col].textContent : '').trim();
                    var bv = (b.cells[col] ? b.cells[col].textContent : '').trim();
                    var an = parseFloat(av.replace(/[^\d.-]/g, ''));
                    var bn = parseFloat(bv.replace(/[^\d.-]/g, ''));
                    var cmp;
                    if (!isNaN(an) && !isNaN(bn) && /^[\d.,\s-]+$/.test(av) && /^[\d.,\s-]+$/.test(bv)) {
                        cmp = an - bn;
                    } else {
                        cmp = av.localeCompare(bv, 'zh-CN');
                    }
                    return asc ? cmp : -cmp;
                });
                asc = !asc;
                rows.forEach(function (r) { tbody.appendChild(r); });
                ths.forEach(function (x) { x.textContent = x.textContent.replace(/\s*[▲▼]$/, ''); });
                th.textContent = th.textContent + (asc ? ' ▲' : ' ▼');
            });
        });
    });

    /* ── 9. 侧边栏折叠状态记忆 ──────────────────────────────── */
    var SB_KEY = 'lw_admin_sidebar_collapsed';
    var sb = document.querySelector('.sidebar');
    if (sb) {
        try {
            if (localStorage.getItem(SB_KEY) === '1') document.body.classList.add('sidebar-collapsed');
        } catch (e) {}
    }
})();
</script>
<script src="<?= asset_url('/assets/js/admin_enhance.js') ?>?v=<?= asset_ver('/assets/js/admin_enhance.js') ?>" defer></script>
<script>
/**
 * 后台滚动位置记忆
 *
 * =====================================================================
 * 解决什么问题
 * =====================================================================
 * 后台很多页面用 href="?p=2" 这类相对链接翻页/切换。
 * 点击后浏览器重新加载整个页面，滚动位置归零 —— 用户在
 * 第 50 行点了「下一页」，跳过去又回到顶部，得重新滚下来。
 *
 * 这个脚本把「离开前的滚动位置」存进 sessionStorage，
 * 新页面加载后自动恢复。对用户来说就是「点了还在原地」。
 *
 * 只对「同路径、仅 query 不同」的跳转生效 —— 跨页面跳转不该恢复，
 * 那会让用户莫名其妙停在半中间。
 * =====================================================================
 */
(function () {
    var KEY = 'lw_admin_scroll_' + location.pathname;
    var lastUrl = null;

    // 记录当前滚动位置（防抖）
    var timer = null;
    window.addEventListener('scroll', function () {
        clearTimeout(timer);
        timer = setTimeout(function () {
            try {
                sessionStorage.setItem(KEY, JSON.stringify({
                    y: window.scrollY,
                    url: location.href
                }));
            } catch (e) {}
        }, 120);
    }, { passive: true });

    // 页面加载后恢复（仅当来源是同一路径）
    try {
        var saved = sessionStorage.getItem(KEY);
        if (saved) {
            var d = JSON.parse(saved);
            var samePath = d && d.url && d.url.split('?')[0] === location.href.split('?')[0];
            if (samePath && d.y > 80 && !location.hash) {
                // 等布局稳定再滚
                requestAnimationFrame(function () {
                    setTimeout(function () {
                        window.scrollTo({ top: d.y, behavior: 'instant' });
                    }, 30);
                });
            }
        }
    } catch (e) {}

    // 表单提交后清掉记忆（提交后应该回到顶部看结果）
    document.addEventListener('submit', function () {
        try { sessionStorage.removeItem(KEY); } catch (e) {}
    }, true);
})();
</script>
</body>
</html>
<?php
}

}
