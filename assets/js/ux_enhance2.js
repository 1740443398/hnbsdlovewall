
/**
 * 用户体验增强 · 第二批
 */
(function () {
    'use strict';
    if (window.__lwUx2) return;
    window.__lwUx2 = true;

    /* 20. 图片懒加载兜底（老浏览器） */
    function lazyFallback() {
        if ('loading' in HTMLImageElement.prototype) return;
        var imgs = document.querySelectorAll('img[loading="lazy"]');
        if (!imgs.length || !('IntersectionObserver' in window)) return;
        var io = new IntersectionObserver(function (es) {
            es.forEach(function (e) {
                if (!e.isIntersecting) return;
                var img = e.target;
                if (img.dataset.src) { img.src = img.dataset.src; }
                io.unobserve(img);
            });
        });
        imgs.forEach(function (i) { io.observe(i); });
    }

    /* 21. 返回顶部记忆：从详情页返回时恢复滚动位置 */
    function restoreScroll() {
        var KEY = 'lw_scroll_' + location.pathname;
        try {
            var saved = sessionStorage.getItem(KEY);
            if (saved && !location.hash) {
                var y = parseInt(saved, 10);
                if (y > 100) {
                    setTimeout(function () { window.scrollTo(0, y); }, 60);
                }
            }
        } catch (e) {}

        var timer = null;
        window.addEventListener('scroll', function () {
            clearTimeout(timer);
            timer = setTimeout(function () {
                try { sessionStorage.setItem(KEY, String(window.scrollY)); } catch (e) {}
            }, 200);
        }, { passive: true });
    }

    /* 22. 帖子卡片整块可点 */
    function cardClickable() {
        document.querySelectorAll('.post-card').forEach(function (card) {
            if (card.dataset.clickable) return;
            card.dataset.clickable = '1';
            card.style.cursor = 'pointer';
            card.addEventListener('click', function (e) {
                if (e.target.closest('a, button, input, textarea, img, .post-actions, .poll-box')) return;
                var link = card.querySelector('a[href*="post_detail"]');
                if (link) window.location.href = link.href;
            });
        });
    }

    /* 23. 点赞/收藏按钮防重复点击 */
    function debounceActions() {
        document.addEventListener('click', function (e) {
            var btn = e.target.closest('.like-btn, .fav-btn, .follow-btn, [data-action]');
            if (!btn) return;
            if (btn.dataset.busy === '1') {
                e.preventDefault();
                e.stopPropagation();
                return;
            }
            btn.dataset.busy = '1';
            btn.style.opacity = '.6';
            setTimeout(function () {
                btn.dataset.busy = '';
                btn.style.opacity = '';
            }, 1200);
        }, true);
    }

    /* 24. 表单提交按钮防重复 */
    function guardSubmit() {
        document.addEventListener('submit', function (e) {
            var form = e.target;
            if (form.dataset.submitting === '1') {
                e.preventDefault();
                return;
            }
            form.dataset.submitting = '1';
            var btn = form.querySelector('[type="submit"], button:not([type="button"])');
            if (btn) {
                btn.disabled = true;
                var orig = btn.textContent;
                btn.textContent = '处理中…';
                setTimeout(function () {
                    form.dataset.submitting = '';
                    btn.disabled = false;
                    btn.textContent = orig;
                }, 8000);
            }
        }, true);
    }

    /* 25. 图片加载失败兜底 */
    function imgErrorFallback() {
        document.querySelectorAll('img').forEach(function (img) {
            if (img.dataset.errBound) return;
            img.dataset.errBound = '1';
            img.addEventListener('error', function () {
                if (this.dataset.failed) return;
                this.dataset.failed = '1';
                this.style.background = '#EEF2F7';
                this.style.minHeight = '40px';
                this.alt = this.alt || '图片加载失败';
            });
        });
    }

    /* 26. 输入框清空按钮 */
    function clearButtons() {
        document.querySelectorAll('input[type="search"], .search-box input').forEach(function (inp) {
            if (inp.dataset.clearBound) return;
            inp.dataset.clearBound = '1';
            var wrap = inp.parentNode;
            if (getComputedStyle(wrap).position === 'static') {
                wrap.style.position = 'relative';
            }
            var btn = document.createElement('button');
            btn.type = 'button';
            btn.innerHTML = '×';
            btn.setAttribute('aria-label', '清空');
            btn.style.cssText = 'position:absolute;right:8px;top:50%;transform:translateY(-50%);'
                + 'width:18px;height:18px;line-height:16px;text-align:center;border-radius:50%;'
                + 'border:0;background:rgba(15,23,42,.12);color:#fff;font-size:13px;cursor:pointer;'
                + 'display:none;padding:0;';
            wrap.appendChild(btn);
            function upd() { btn.style.display = inp.value ? 'block' : 'none'; }
            inp.addEventListener('input', upd);
            btn.addEventListener('click', function () {
                inp.value = '';
                inp.dispatchEvent(new Event('input', { bubbles: true }));
                inp.focus();
                upd();
            });
            upd();
        });
    }

    /* 27. 复制帖子内容（纯文本） */
    function copyContent() {
        document.querySelectorAll('.post-detail-content').forEach(function (el) {
            if (el.dataset.copyBound) return;
            el.dataset.copyBound = '1';
            var btn = document.createElement('button');
            btn.type = 'button';
            btn.textContent = '复制正文';
            btn.className = 'lw-copy-content-btn';
            btn.style.cssText = 'margin-top:12px;padding:6px 14px;font-size:12.5px;border-radius:7px;'
                + 'border:1px solid rgba(47,91,154,.24);background:rgba(47,91,154,.05);'
                + 'color:#2F5B9A;cursor:pointer;font-family:inherit;';
            btn.addEventListener('click', function () {
                var txt = el.innerText || el.textContent || '';
                if (navigator.clipboard) {
                    navigator.clipboard.writeText(txt.trim()).then(function () {
                        btn.textContent = '已复制';
                        setTimeout(function () { btn.textContent = '复制正文'; }, 1400);
                    });
                }
            });
            el.parentNode.insertBefore(btn, el.nextSibling);
        });
    }

    /* 28. 键盘导航：j/k 上下帖，Enter 打开 */
    function keyboardNav() {
        var cards = Array.prototype.slice.call(document.querySelectorAll('.post-card'));
        if (cards.length < 2) return;
        var idx = -1;
        document.addEventListener('keydown', function (e) {
            var tag = (e.target.tagName || '').toUpperCase();
            if (tag === 'INPUT' || tag === 'TEXTAREA' || e.target.isContentEditable) return;

            if (e.key === 'j' || e.key === 'ArrowDown' && e.altKey) {
                idx = Math.min(cards.length - 1, idx + 1);
                cards[idx].scrollIntoView({ block: 'center', behavior: 'smooth' });
                cards.forEach(function (c, i) {
                    c.style.outline = i === idx ? '2px solid rgba(47,91,154,.4)' : '';
                    c.style.outlineOffset = i === idx ? '3px' : '';
                });
            }
            if (e.key === 'k' || e.key === 'ArrowUp' && e.altKey) {
                idx = Math.max(0, idx - 1);
                cards[idx].scrollIntoView({ block: 'center', behavior: 'smooth' });
                cards.forEach(function (c, i) {
                    c.style.outline = i === idx ? '2px solid rgba(47,91,154,.4)' : '';
                    c.style.outlineOffset = i === idx ? '3px' : '';
                });
            }
            if (e.key === 'Enter' && idx >= 0) {
                var link = cards[idx].querySelector('a[href*="post_detail"]');
                if (link) window.location.href = link.href;
            }
        });
    }

    /* 29. 长列表分页记忆 */
    function pageMemory() {
        try {
            var KEY = 'lw_page_' + location.pathname;
            var urlPage = new URLSearchParams(location.search).get('page');
            if (!urlPage) {
                var saved = sessionStorage.getItem(KEY);
                if (saved && parseInt(saved, 10) > 1) {
                    var u = new URL(location.href);
                    u.searchParams.set('page', saved);
                    // 只在用户主动返回时提示，不自动跳
                }
            }
            if (urlPage) sessionStorage.setItem(KEY, urlPage);
        } catch (e) {}
    }

    /* 30. 数字滚动动画（统计卡片） */
    function countUp() {
        if (matchMedia('(prefers-reduced-motion: reduce)').matches) return;
        if (!('IntersectionObserver' in window)) return;
        var els = document.querySelectorAll('.stat-num, .u-stat b, .profile-stat b');
        if (!els.length) return;
        var io = new IntersectionObserver(function (entries) {
            entries.forEach(function (e) {
                if (!e.isIntersecting) return;
                var el = e.target;
                io.unobserve(el);
                var target = parseInt((el.textContent || '').replace(/[^\d]/g, ''), 10);
                if (isNaN(target) || target <= 0 || target > 999999) return;
                var cur = 0;
                var step = Math.max(1, Math.ceil(target / 22));
                var t = setInterval(function () {
                    cur += step;
                    if (cur >= target) { cur = target; clearInterval(t); }
                    el.textContent = String(cur);
                }, 26);
            });
        }, { threshold: 0.5 });
        els.forEach(function (e) { io.observe(e); });
    }

    /* 31. 复制成功统一提示 */
    function toastHelper() {
        window.lwToast = function (msg) {
            if (window.showToast) { window.showToast(msg); return; }
            var d = document.createElement('div');
            d.textContent = msg;
            d.style.cssText = 'position:fixed;bottom:24px;left:50%;transform:translateX(-50%);'
                + 'background:rgba(30,37,48,.94);color:#fff;padding:10px 20px;border-radius:10px;'
                + 'font-size:13px;z-index:99999;box-shadow:0 8px 24px rgba(0,0,0,.25);'
                + 'opacity:0;transition:opacity .2s;';
            document.body.appendChild(d);
            requestAnimationFrame(function () { d.style.opacity = '1'; });
            setTimeout(function () {
                d.style.opacity = '0';
                setTimeout(function () { d.remove(); }, 220);
            }, 1900);
        };
    }

    /* 32. 页面可见性：切回标签页时刷新未读 */
    function visibilityRefresh() {
        document.addEventListener('visibilitychange', function () {
            if (document.visibilityState !== 'visible') return;
            if (window.NotificationCenter && window.NotificationCenter.load) {
                try { window.NotificationCenter.load(); } catch (e) {}
            }
        });
    }

    /* 33. 滚动时暂停动画（省电） */
    function pauseOnScroll() {
        var scrolling = false;
        window.addEventListener('scroll', function () {
            if (!scrolling) {
                document.body.classList.add('is-scrolling');
                scrolling = true;
            }
            clearTimeout(window.__lwScrollTimer);
            window.__lwScrollTimer = setTimeout(function () {
                document.body.classList.remove('is-scrolling');
                scrolling = false;
            }, 160);
        }, { passive: true });
    }

    /* 34. 焦点可见性（键盘用户） */
    function focusVisible() {
        var hadKeyboard = false;
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Tab') {
                hadKeyboard = true;
                document.body.classList.add('using-keyboard');
            }
        });
        document.addEventListener('mousedown', function () {
            if (hadKeyboard) {
                hadKeyboard = false;
                document.body.classList.remove('using-keyboard');
            }
        });
    }

    /* 35. 打印友好 */
    function printFriendly() {
        document.querySelectorAll('.post-detail-content').forEach(function (el) {
            el.classList.add('lw-printable');
        });
    }


    /* 慢请求提示：超过阈值就告诉用户「正在重试」，避免以为卡死 */
    function slowHint() {
        var limit = (typeof window.LW_SLOW_HINT === 'number' && window.LW_SLOW_HINT > 0)
            ? window.LW_SLOW_HINT : 3000;
        var timer = null;
        var shown = null;

        var origFetch = window.fetch;
        if (!origFetch) return;

        window.fetch = function (input, init) {
            var url = typeof input === 'string' ? input : (input && input.url) || '';
            var sameOrigin = url.indexOf('/') === 0 || url.indexOf(location.origin) === 0
                || url.indexOf('http') !== 0;
            if (!sameOrigin) return origFetch.apply(this, arguments);

            clearTimeout(timer);
            timer = setTimeout(function () {
                if (shown) return;
                shown = document.createElement('div');
                shown.textContent = '网络较慢，正在重试…';
                shown.style.cssText = 'position:fixed;bottom:22px;left:50%;transform:translateX(-50%);'
                    + 'background:rgba(30,37,48,.92);color:#fff;padding:9px 18px;border-radius:9px;'
                    + 'font-size:12.5px;z-index:99998;box-shadow:0 6px 20px rgba(0,0,0,.22);'
                    + 'opacity:0;transition:opacity .2s;';
                document.body.appendChild(shown);
                requestAnimationFrame(function () { shown.style.opacity = '1'; });
            }, limit);

            var p = origFetch.apply(this, arguments);
            function done() {
                clearTimeout(timer);
                if (shown) {
                    shown.style.opacity = '0';
                    var el = shown;
                    setTimeout(function () { el.remove(); }, 220);
                    shown = null;
                }
            }
            return p.then(function (r) { done(); return r; },
                          function (e) { done(); throw e; });
        };
    }

    /* 启动 */
    function boot() {
        lazyFallback();
        restoreScroll();
        cardClickable();
        debounceActions();
        guardSubmit();
        imgErrorFallback();
        clearButtons();
        copyContent();
        keyboardNav();
        pageMemory();
        countUp();
        toastHelper();
        visibilityRefresh();
        pauseOnScroll();
        focusVisible();
        printFriendly();
        slowHint();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot, { once: true });
    } else {
        boot();
    }

    window.LWUx2 = { refresh: boot };
})();
