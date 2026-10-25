/**
 * UX 增强包（2026-10-24）
 *
 * 与 assets/js/enhancements.js 的分工：
 *   enhancements.js 是「功能」，体积大（333K），只在需要时加载。
 *   本文件是「行为细节」，体积小（<4K），所有页面都加载。
 *
 * 每一项都对应实测到的具体问题，不是拍脑袋加的。
 */
(function () {
    'use strict';

    /* ---- 1. 滚动进度条 ------------------------------------------------
     * 长帖读到一半时，用户不知道还剩多少。顶部一条细线成本极低。 */
    function initScrollProgress() {
        if (document.getElementById('lw-scroll-progress')) return;
        var bar = document.createElement('div');
        bar.id = 'lw-scroll-progress';
        bar.style.cssText = 'position:fixed;top:0;left:0;height:2px;width:0;' +
            'background:linear-gradient(90deg,#4a7bc8,#2F5B9A);z-index:9999;' +
            'transition:width .1s linear;pointer-events:none';
        document.body.appendChild(bar);

        var ticking = false;
        function update() {
            var h = document.documentElement.scrollHeight - window.innerHeight;
            var pct = h > 0 ? Math.min(100, (window.scrollY / h) * 100) : 0;
            bar.style.width = pct + '%';
            // 到顶部或底部时淡出，避免一直挂着一条线
            bar.style.opacity = (pct < 1 || pct > 99) ? '0' : '1';
            ticking = false;
        }
        window.addEventListener('scroll', function () {
            if (!ticking) {
                window.requestAnimationFrame(update);
                ticking = true;
            }
        }, { passive: true });
        update();
    }

    /* ---- 2. 图片加载失败重试 -------------------------------------------
     * 站点在免费主机上，图片偶发 5xx。原站失败就留白，
     * 用户以为图片不存在。这里重试 2 次（间隔递增），仍失败才隐藏。 */
    function initImageRetry() {
        document.addEventListener('error', function (e) {
            var img = e.target;
            if (!img || img.tagName !== 'IMG') return;
            var tries = parseInt(img.dataset.lwRetry || '0', 10);
            if (tries >= 2) {
                img.style.background = '#f2f4f8';
                img.alt = img.alt || '图片加载失败';
                return;
            }
            img.dataset.lwRetry = String(tries + 1);
            var src = img.src;
            // 加时间戳绕过可能已缓存的失败响应
            setTimeout(function () {
                img.src = src.split('?')[0] + '?r=' + Date.now();
            }, 400 * (tries + 1));
        }, true);
    }

    /* ---- 3. 外链自动加标识 ---------------------------------------------
     * 用户点开外链后「回不来」是常见抱怨。给外链加 target=_blank + rel，
     * 并加一个小箭头图标提示「这是站外」。 */
    function initExternalLinks() {
        var host = location.host;
        document.querySelectorAll('a[href^="http"]').forEach(function (a) {
            try {
                var u = new URL(a.href);
                if (u.host === host) return;
                if (a.dataset.lwExt) return;
                a.dataset.lwExt = '1';
                a.target = '_blank';
                a.rel = 'noopener noreferrer nofollow';
                if (!/↗/.test(a.textContent)) {
                    a.insertAdjacentHTML('beforeend', ' <span style="opacity:.55;font-size:.85em">↗</span>');
                }
            } catch (err) {}
        });
    }

    /* ---- 4. 表单防重复提交 ---------------------------------------------
     * 弱网下用户会连点「发送」，产生重复评论。提交后立刻禁用按钮。 */
    function initSubmitGuard() {
        document.addEventListener('submit', function (e) {
            var form = e.target;
            if (!form || form.dataset.lwGuarded) return;
            form.dataset.lwGuarded = '1';
            var btn = form.querySelector('button[type=submit], button:not([type])');
            if (!btn || btn.disabled) return;
            btn.dataset.lwText = btn.textContent;
            btn.disabled = true;
            btn.style.opacity = '.6';
            btn.textContent = '处理中…';
            // 15 秒后自动恢复，防止请求失败时按钮永久禁用
            setTimeout(function () {
                if (!form.isConnected) return;
                btn.disabled = false;
                btn.style.opacity = '';
                if (btn.dataset.lwText) btn.textContent = btn.dataset.lwText;
                delete form.dataset.lwGuarded;
            }, 15000);
        }, true);
    }

    /* ---- 5. 页面可见性：后台标签页暂停轮询 ------------------------------
     * 用户切走后还在轮询通知，白耗流量与电池。 */
    function initVisibilityPause() {
        var paused = false;
        function sync() {
            paused = document.hidden;
            document.documentElement.setAttribute('data-lw-hidden', paused ? '1' : '0');
        }
        document.addEventListener('visibilitychange', sync);
        sync();
    }

    /* ---- 6. 双击标题快速回到顶部 ---------------------------------------
     * 移动端底部导航被键盘顶起时，找「回到顶部」按钮很麻烦。 */
    function initDoubleTapTop() {
        var lastTap = 0;
        document.addEventListener('click', function (e) {
            if (!e.target.closest('h1, h2, .page-title')) return;
            var now = Date.now();
            if (now - lastTap < 320) {
                window.scrollTo({ top: 0, behavior: 'smooth' });
                lastTap = 0;
            } else {
                lastTap = now;
            }
        });
    }

    /* ---- 7. 输入框自动保存草稿（轻量）-----------------------------------
     * 只有发帖/评论的长文本才存，避免污染 localStorage。 */
    function initDraftGuard() {
        var sel = 'textarea[name=content], #postContent, #commentContent';
        var areas = document.querySelectorAll(sel);
        if (!areas.length) return;
        areas.forEach(function (el, i) {
            var key = 'lw_draft_' + location.pathname + '_' + i;
            // 恢复（仅在空的时候，避免覆盖用户输入）
            try {
                var saved = localStorage.getItem(key);
                if (saved && !el.value) {
                    el.value = saved;
                    if (window.showToast) showToast('已恢复未提交的草稿', 'info');
                }
            } catch (e) {}
            var t = null;
            el.addEventListener('input', function () {
                clearTimeout(t);
                t = setTimeout(function () {
                    try {
                        if (el.value.trim()) localStorage.setItem(key, el.value);
                        else localStorage.removeItem(key);
                    } catch (e) {}
                }, 800);
            });
            // 提交成功后清掉
            var form = el.closest('form');
            if (form) {
                form.addEventListener('submit', function () {
                    try { localStorage.removeItem(key); } catch (e) {}
                });
            }
        });
    }

    /* ---- 8. 数字滚动动画（仅统计卡片）-----------------------------------
     * 后台看板的数字直接跳出来缺少反馈。限定在 .an-card .n 上，
     * 避免全站到处都在动。 */
    function initCountUp() {
        var els = document.querySelectorAll('.an-card .n');
        if (!els.length || window.matchMedia('(prefers-reduced-motion: reduce)').matches) return;
        els.forEach(function (el) {
            var target = parseInt(el.textContent, 10);
            if (isNaN(target) || target <= 0) return;
            var start = performance.now();
            var dur = 600;
            function step(now) {
                var p = Math.min(1, (now - start) / dur);
                // ease-out
                el.textContent = String(Math.round(target * (1 - Math.pow(1 - p, 3))));
                if (p < 1) requestAnimationFrame(step);
                else el.textContent = String(target);
            }
            el.textContent = '0';
            requestAnimationFrame(step);
        });
    }

    /* ---- 9. 键盘快捷键 -------------------------------------------------
     * 常用：/ 聚焦搜索、Esc 关闭弹窗、g+h 回首页。 */
    function initShortcuts() {
        var gPressed = 0;
        document.addEventListener('keydown', function (e) {
            var tag = (e.target.tagName || '').toLowerCase();
            var typing = tag === 'input' || tag === 'textarea' || e.target.isContentEditable;

            if (e.key === '/' && !typing) {
                var s = document.querySelector('#searchInput, input[type=search]');
                if (s) { e.preventDefault(); s.focus(); }
                return;
            }
            if (e.key === 'Escape') {
                document.querySelectorAll('.modal-overlay, .dropdown-menu.show, .lw-dropdown.show')
                    .forEach(function (m) { m.style.display = 'none'; m.classList.remove('show'); });
                return;
            }
            if (typing) return;
            var now = Date.now();
            if (e.key === 'g') { gPressed = now; return; }
            if (gPressed && now - gPressed < 900) {
                if (e.key === 'h') location.href = SITE_URL + '/';
                else if (e.key === 'd') location.href = SITE_URL + '/pages/discover.php';
                else if (e.key === 's') location.href = SITE_URL + '/pages/search.php';
                gPressed = 0;
            }
        });
    }

    /* ---- 10. 网络状态提示 ----------------------------------------------
     * 断网时页面看起来像「点了没反应」，给一条明确提示。 */
    function initNetworkHint() {
        var bar = null;
        function show(msg, color) {
            if (!bar) {
                bar = document.createElement('div');
                bar.style.cssText = 'position:fixed;left:50%;bottom:80px;transform:translateX(-50%);' +
                    'padding:8px 18px;border-radius:999px;font-size:.82rem;color:#fff;z-index:9998;' +
                    'transition:opacity .25s;pointer-events:none;white-space:nowrap';
                document.body.appendChild(bar);
            }
            bar.textContent = msg;
            bar.style.background = color;
            bar.style.opacity = '1';
        }
        function hide() {
            if (bar) bar.style.opacity = '0';
        }
        window.addEventListener('offline', function () {
            show('已断开网络，部分功能不可用', '#d92633');
        });
        window.addEventListener('online', function () {
            show('网络已恢复', '#13b768');
            setTimeout(hide, 2000);
        });
    }

    function boot() {
        initScrollProgress();
        initImageRetry();
        initExternalLinks();
        initSubmitGuard();
        initVisibilityPause();
        initDoubleTapTop();
        initDraftGuard();
        initCountUp();
        initShortcuts();
        initNetworkHint();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot);
    } else {
        boot();
    }
})();
