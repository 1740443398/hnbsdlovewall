
/**
 * 前台体验增强包
 *
 * 全部是「不打断用户」的小改进：不弹窗、不跳转、不改变已有行为，
 * 只是在用户想做什么的时候少挡一道。
 */
(function () {
    'use strict';
    if (window.__lwUxEnhanced) return;
    window.__lwUxEnhanced = true;

    var isTouch = matchMedia('(pointer: coarse)').matches;

    /* ── 1. 图片点击放大（灯箱）────────────────────────────── */
    function initLightbox() {
        var imgs = document.querySelectorAll('.post-card img, .post-detail-content img, .lw-rich img');
        if (!imgs.length) return;

        var box = null;
        function close() {
            if (box) { box.remove(); box = null; document.body.style.overflow = ''; }
            document.removeEventListener('keydown', onKey);
        }
        function onKey(e) { if (e.key === 'Escape') close(); }

        imgs.forEach(function (img) {
            if (img.closest('a')) return;   // 已经是链接的不处理
            img.style.cursor = 'zoom-in';
            img.addEventListener('click', function (e) {
                e.preventDefault();
                e.stopPropagation();
                if (box) close();

                box = document.createElement('div');
                box.style.cssText = [
                    'position:fixed', 'inset:0', 'z-index:99998',
                    'background:rgba(10,14,20,.88)', 'backdrop-filter:blur(6px)',
                    'display:flex', 'align-items:center', 'justify-content:center',
                    'padding:20px', 'cursor:zoom-out', 'opacity:0',
                    'transition:opacity .2s'
                ].join(';');

                var big = document.createElement('img');
                big.src = img.src;
                big.style.cssText = 'max-width:100%;max-height:100%;border-radius:8px;box-shadow:0 20px 60px rgba(0,0,0,.5);';
                box.appendChild(big);
                box.addEventListener('click', close);
                document.body.appendChild(box);
                document.body.style.overflow = 'hidden';
                document.addEventListener('keydown', onKey);
                requestAnimationFrame(function () { box.style.opacity = '1'; });
            });
        });
    }

    /* ── 2. 双击/长按快速复制帖子链接 ───────────────────────── */
    function initQuickCopy() {
        document.querySelectorAll('.post-card').forEach(function (card) {
            card.addEventListener('dblclick', function (e) {
                if (e.target.closest('button, a, input, textarea, img')) return;
                var link = card.querySelector('a[href*="post_detail"]');
                if (!link) return;
                var url = link.href;
                if (navigator.clipboard) {
                    navigator.clipboard.writeText(url).then(function () {
                        if (window.showToast) showToast('帖子链接已复制');
                    });
                }
            });
        });
    }

    /* ── 3. 输入框字符计数 ──────────────────────────────────── */
    function initCharCount() {
        document.querySelectorAll('textarea[maxlength]').forEach(function (ta) {
            var max = parseInt(ta.getAttribute('maxlength'), 10);
            if (!max || max < 50) return;
            var badge = document.createElement('div');
            badge.style.cssText = 'font-size:11.5px;color:#98A2B3;text-align:right;margin-top:3px;';
            function upd() {
                var n = (ta.value || '').length;
                badge.textContent = n + ' / ' + max;
                badge.style.color = n > max * 0.9 ? '#C0392B' : '#98A2B3';
            }
            ta.parentNode.insertBefore(badge, ta.nextSibling);
            ta.addEventListener('input', upd);
            upd();
        });
    }

    /* ── 4. 表单未保存提醒 ──────────────────────────────────── */
    function initDirtyGuard() {
        var forms = document.querySelectorAll('form[data-guard-dirty], .post-form, #postForm');
        var dirty = false;
        forms.forEach(function (f) {
            f.addEventListener('input', function () { dirty = true; });
            f.addEventListener('submit', function () { dirty = false; });
        });
        if (!forms.length) return;
        window.addEventListener('beforeunload', function (e) {
            if (!dirty) return;
            e.preventDefault();
            e.returnValue = '';
            return '';
        });
    }

    /* ── 5. 回到顶部 + 阅读进度条（长页面）──────────────────── */
    function initScrollAid() {
        var h = document.documentElement.scrollHeight;
        if (h < window.innerHeight * 2.5) return;

        var bar = document.createElement('div');
        bar.style.cssText = [
            'position:fixed', 'top:0', 'left:0', 'height:2px', 'width:0',
            'background:linear-gradient(90deg,#3B6FB6,#58A8D6)',
            'z-index:99997', 'transition:width .1s linear', 'pointer-events:none'
        ].join(';');
        document.body.appendChild(bar);

        var ticking = false;
        window.addEventListener('scroll', function () {
            if (ticking) return;
            ticking = true;
            requestAnimationFrame(function () {
                var max = document.documentElement.scrollHeight - window.innerHeight;
                var p = max > 0 ? (window.scrollY / max) * 100 : 0;
                bar.style.width = Math.min(100, p) + '%';
                ticking = false;
            });
        }, { passive: true });
    }

    /* ── 6. 相对时间（3 分钟前）─────────────────────────────── */
    function initRelativeTime() {
        var els = document.querySelectorAll('.post-time, .comment-time, time[datetime]');
        if (!els.length) return;

        function fmt(d) {
            var diff = (Date.now() - d.getTime()) / 1000;
            if (diff < 60) return '刚刚';
            if (diff < 3600) return Math.floor(diff / 60) + ' 分钟前';
            if (diff < 86400) return Math.floor(diff / 3600) + ' 小时前';
            if (diff < 86400 * 7) return Math.floor(diff / 86400) + ' 天前';
            return null;
        }

        els.forEach(function (el) {
            var raw = el.getAttribute('datetime') || el.textContent.trim();
            var d = new Date(raw.replace(/-/g, '/'));
            if (isNaN(d.getTime())) return;
            var rel = fmt(d);
            if (!rel) return;
            el.title = raw;
            el.textContent = rel;
        });
    }

    /* ── 7. 搜索框快捷键 ────────────────────────────────────── */
    document.addEventListener('keydown', function (e) {
        var tag = (e.target.tagName || '').toUpperCase();
        if (tag === 'INPUT' || tag === 'TEXTAREA' || e.target.isContentEditable) return;

        if (e.key === '/') {
            var s = document.querySelector('.search-box input, input[name="q"], #searchInput, .header-search input');
            if (s) { e.preventDefault(); s.focus(); }
        }
        if (e.key === 'Escape') {
            var open = document.querySelector('.user-dropdown.show, .modal.show');
            if (open) open.classList.remove('show');
        }
    });

    /* ── 8. 长文折叠「展开全文」─────────────────────────────── */
    function initReadMore() {
        document.querySelectorAll('.post-card-body, .post-content-preview').forEach(function (el) {
            if (el.scrollHeight < 420 || el.dataset.collapsed) return;
            el.dataset.collapsed = '1';
            var origH = el.style.maxHeight;
            el.style.maxHeight = '220px';
            el.style.overflow = 'hidden';
            el.style.position = 'relative';

            var fade = document.createElement('div');
            fade.style.cssText = 'position:absolute;left:0;right:0;bottom:0;height:60px;'
                + 'background:linear-gradient(transparent,' + (getComputedStyle(el).backgroundColor || '#fff') + ');'
                + 'pointer-events:none;';
            el.appendChild(fade);

            var btn = document.createElement('button');
            btn.type = 'button';
            btn.textContent = '展开全文';
            btn.style.cssText = 'margin-top:8px;padding:5px 14px;font-size:12.5px;border-radius:7px;'
                + 'border:1px solid rgba(47,91,154,.25);background:rgba(47,91,154,.06);'
                + 'color:#2F5B9A;cursor:pointer;font-family:inherit;';
            btn.addEventListener('click', function () {
                var expanded = el.style.maxHeight === 'none';
                el.style.maxHeight = expanded ? '220px' : 'none';
                fade.style.display = expanded ? 'block' : 'none';
                btn.textContent = expanded ? '展开全文' : '收起';
            });
            el.parentNode.insertBefore(btn, el.nextSibling);
        });
    }

    /* ── 9. 触摸端改善：点击延迟消除 + 滚动惯性 ─────────────── */
    if (isTouch) {
        document.documentElement.style.webkitTapHighlightColor = 'transparent';
    }

    /* ── 10. 网络状态提示 ───────────────────────────────────── */
    var offlineToast = null;
    window.addEventListener('offline', function () {
        if (offlineToast) return;
        offlineToast = document.createElement('div');
        offlineToast.textContent = '网络已断开，恢复后会自动重试';
        offlineToast.style.cssText = [
            'position:fixed', 'bottom:16px', 'left:50%', 'transform:translateX(-50%)',
            'background:rgba(30,37,48,.94)', 'color:#fff', 'padding:10px 18px',
            'border-radius:10px', 'font-size:13px', 'z-index:99999',
            'box-shadow:0 8px 24px rgba(0,0,0,.25)'
        ].join(';');
        document.body.appendChild(offlineToast);
    });
    window.addEventListener('online', function () {
        if (offlineToast) { offlineToast.remove(); offlineToast = null; }
    });

    /* ── 启动 ───────────────────────────────────────────────── */
    function boot() {
        initLightbox();
        initQuickCopy();
        initCharCount();
        initDirtyGuard();
        initScrollAid();
        initRelativeTime();
        initReadMore();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot, { once: true });
    } else {
        boot();
    }

    window.LWUx = {
        lightbox: initLightbox,
        refresh: boot
    };
})();
