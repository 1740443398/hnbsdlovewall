/**
 * polish.js —— 体验增强模块（渐进增强，不依赖 main.js）
 * ==========================================================================
 * 设计原则（重要）：
 *   1) 本文件**只做增强，不做必需功能**。任何一个 IIFE 抛错都不该影响页面主流程，
 *      所以每块都包了自己的 try/catch。
 *   2) 不改 main.js / enhancements.js 的任何现有行为 —— 它们 9000+ 行且彼此耦合，
 *      与其往里塞代码，不如用「事后修饰 DOM」的方式独立成模块，出问题也好定位。
 *   3) 所有 UI 元素都是 JS 现场创建的：JS 挂了这些元素就不存在，页面不会留下空壳。
 * ==========================================================================
 */
(function () {
  'use strict';
  if (window.__lwPolishInit) { return; }
  window.__lwPolishInit = true;

  var SCROLL_SHOW_AT = 600;          // 返回顶部按钮出现阈值（px）
  var storage = {
    get: function (k, d) { try { var v = localStorage.getItem(k); return v === null ? d : v; } catch (e) { return d; } },
    set: function (k, v) { try { localStorage.setItem(k, v); } catch (e) { /* 隐私模式 */ } }
  };

  /* ======================================================================
   * 1. 顶栏滚动阴影
   *     内容顶到顶栏下面时加投影，让「浮层」这一层级看得见；
   *     未滚动时保持干净的无影状态，视觉层级更分明。
   * ==================================================================== */
  try {
    var header = document.querySelector('.site-header');
    if (header) {
      var raf = 0;
      var onScroll = function () {
        if (raf) { return; }
        raf = requestAnimationFrame(function () {
          raf = 0;
          header.classList.toggle('is-scrolled', (window.scrollY || 0) > 8);
        });
      };
      window.addEventListener('scroll', onScroll, { passive: true });
      onScroll();
    }
  } catch (e) { /* ignore */ }

  /* ======================================================================
   * 2. 统一浮动操作按钮（FAB）—— 全站唯一的右下角浮动入口
   *     点开才展开：发帖 / 回到顶部 / 随便看看 / 字号 / AI 小助手。
   *     （「发帖」是主操作，放在最贴近主按钮的位置并用主色高亮。）
   *
   *     背景：此前站内同时存在多套互不知情的浮动件 ——
   *       enhancements.js 的 5 图标竖条（🏠 ✏️ ⬆️ 🌓 🎲）、快捷操作面板 QAP、
   *       两个各自独立的「回到顶部」（.stt-btn / .btt-progress）、
   *       polish.js 自己的返回顶部与字号组、ai_widget.js 的 AI 助手 FAB。
   *     右侧一列最多能叠到 8 个按钮，噪声极大，而且功能彼此重复。
   *     现在统一收敛到这里；上面那些注入点已全部移除，不要再各自加回来。
   * ==================================================================== */
  try {
    // 登录/注册这类纯表单页不需要浮动操作
    var isFormPage = /\/(login|register|forgot_password|gateway)\.php/.test(location.pathname);
    if (!isFormPage && !document.querySelector('.lw-fab')) {
      var FS_LEVELS = [
        { cls: '',         label: '标准' },
        { cls: 'lw-fs-lg', label: '大' },
        { cls: 'lw-fs-xl', label: '特大' }
      ];
      var SVG = function (d) {
        return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"'
          + ' stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' + d + '</svg>';
      };
      var ICONS = {
        more: '<path d="M12 5v14"/><path d="M5 12h14"/>',
        top: '<path d="M12 19V5"/><path d="M5 12l7-7 7 7"/>',
        random: '<path d="M16 3h5v5"/><path d="M4 20L21 3"/><path d="M21 16v5h-5"/><path d="M15 15l6 6"/><path d="M4 4l5 5"/>',
        font: '<polyline points="4 7 4 4 20 4 20 7"/><line x1="9" y1="20" x2="15" y2="20"/><line x1="12" y1="4" x2="12" y2="20"/>',
        ai: '<path d="M12 3l1.7 4.3L18 9l-4.3 1.7L12 15l-1.7-4.3L6 9l4.3-1.7z"/><path d="M18.5 15.5l.7 1.8 1.8.7-1.8.7-.7 1.8-.7-1.8-1.8-.7 1.8-.7z"/>',
        compose: '<path d="M12 20h9"/><path d="M16.5 3.5a2.12 2.12 0 0 1 3 3L7 19l-4 1 1-4z"/>'
      };

      var fab = document.createElement('div');
      fab.className = 'lw-fab';
      fab.id = 'lwFab';

      var menu = document.createElement('div');
      menu.className = 'lw-fab-menu';
      menu.id = 'lwFabMenu';
      menu.setAttribute('role', 'menu');
      menu.setAttribute('aria-label', '快捷操作');

      // 顺序 = 从菜单顶部到底部；越靠下离主按钮越近、越好按。
      // 「发帖」放在最后（默认视作主操作，用主色高亮）；它藏在最后还有一个好处：
      // 「回到顶部」会在页面顶部时隐藏，发帖的位置不会因此跳动。
      var ITEM_DEFS = [
        { act: 'ai',      icon: ICONS.ai,      label: 'AI 小助手' },
        { act: 'random',  icon: ICONS.random,  label: '随便看看' },
        { act: 'font',    icon: ICONS.font,    label: '字号' },
        { act: 'top',     icon: ICONS.top,     label: '回到顶部' },
        { act: 'compose', icon: ICONS.compose, label: '发帖', primary: true }
      ];
      var fabItems = {};
      ITEM_DEFS.forEach(function (def) {
        var b = document.createElement('button');
        b.type = 'button';
        b.className = 'lw-fab-item' + (def.primary ? ' lw-fab-item--primary' : '');
        b.setAttribute('role', 'menuitem');
        b.setAttribute('data-act', def.act);
        b.innerHTML = '<span class="lw-fab-ico">' + SVG(def.icon) + '</span>'
          + '<span class="lw-fab-txt">' + def.label + '</span>';
        menu.appendChild(b);
        fabItems[def.act] = b;
      });

      var main = document.createElement('button');
      main.type = 'button';
      main.className = 'lw-fab-btn';
      main.setAttribute('aria-label', '快捷操作');
      main.setAttribute('aria-expanded', 'false');
      main.setAttribute('aria-controls', 'lwFabMenu');
      main.setAttribute('title', '快捷操作');
      main.innerHTML = SVG(ICONS.more);

      fab.appendChild(menu);
      fab.appendChild(main);
      document.body.appendChild(fab);

      /* ---- 开合 ---- */
      var isOpen = false;
      var setOpen = function (open) {
        isOpen = open;
        fab.classList.toggle('is-open', open);
        main.setAttribute('aria-expanded', open ? 'true' : 'false');
      };
      main.addEventListener('click', function (e) {
        e.stopPropagation();
        setOpen(!isOpen);
      });
      document.addEventListener('click', function (e) {
        if (isOpen && !fab.contains(e.target)) { setOpen(false); }
      });
      document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && isOpen) { setOpen(false); }
      });

      /* ---- 回到顶部：滚过阈值才出现；点击后就地收起 ---- */
      var syncTopItem = function () {
        var need = (window.scrollY || 0) > SCROLL_SHOW_AT;
        fabItems.top.hidden = !need;
      };
      window.addEventListener('scroll', syncTopItem, { passive: true });
      syncTopItem();
      fabItems.top.addEventListener('click', function () {
        var reduce = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
        window.scrollTo({ top: 0, behavior: reduce ? 'auto' : 'smooth' });
        setOpen(false);
      });

      /* ---- 随便看看：优先用 enhancements.js 的 RandomPost；它没加载时自己兜底取一次列表 ----
         注意：polish.js 在文档里排在 main.js / enhancements.js 之前（都是 defer，按文档序执行），
         此刻 window.App 还不存在，所以只能在「点击时」判断，不能在 init 里判断。 */
      fabItems.random.addEventListener('click', function () {
        setOpen(false);
        if (window.App && App.RandomPost && typeof App.RandomPost._go === 'function') {
          try { App.RandomPost._go(); return; } catch (e) { /* 落到下面的兜底 */ }
        }
        fetch('/api/posts/list.php?category=all&sort=latest&page=1&limit=100', {
          headers: { 'X-Requested-With': 'XMLHttpRequest' },
          credentials: 'same-origin'
        })
          .then(function (r) { return r.json(); })
          .then(function (d) {
            var posts = (d && d.data && d.data.posts) || [];
            if (!posts.length) { return; }
            var one = posts[Math.floor(Math.random() * posts.length)];
            location.href = '/pages/post_detail.php?id=' + one.id;
          })
          .catch(function () { /* ignore */ });
      });

      /* ---- 字号：三档循环，沿用旧的 localStorage 键与 html class，老用户设置不丢 ---- */
      var fsIdx = parseInt(storage.get('lw_fontsize', '0'), 10) || 0;
      if (!FS_LEVELS[fsIdx]) { fsIdx = 0; }
      var applyFontSize = function (idx) {
        var root = document.documentElement;
        root.classList.remove('lw-fs-lg', 'lw-fs-xl');
        if (FS_LEVELS[idx].cls) { root.classList.add(FS_LEVELS[idx].cls); }
        storage.set('lw_fontsize', String(idx));
        fabItems.font.querySelector('.lw-fab-txt').textContent = '字号 · ' + FS_LEVELS[idx].label;
        fabItems.font.setAttribute('title', '当前：' + FS_LEVELS[idx].label + '（点击切换）');
      };
      applyFontSize(fsIdx);
      fabItems.font.addEventListener('click', function () {
        fsIdx = (fsIdx + 1) % FS_LEVELS.length;
        applyFontSize(fsIdx);   // 字号不好切一次就到位，点完保持展开让用户连点
      });

      /* ---- AI 小助手：面板与逻辑仍归 ai_widget.js，这里只做触发器 ----
         ai_widget.js 比本文件晚执行，所以同样在点击时判断；它没挂上就退到完整版页面。 */
      fabItems.ai.addEventListener('click', function () {
        var aiFab = document.getElementById('lwAiFab');
        setOpen(false);
        if (aiFab) { aiFab.click(); } else { location.href = '/pages/ai_assistant.php'; }
      });

      /* ---- 发帖：主操作。游客交给 pages/post.php 的 requireMember 提示并引导注册，
             这里不必额外判登录态（polish.js 执行时 IS_LOGGED_IN 还没定义）。 */
      fabItems.compose.addEventListener('click', function () {
        setOpen(false);
        location.href = '/pages/post.php';
      });
    }
  } catch (e) { /* ignore */ }

  /* ======================================================================
   * 3. 阅读进度条（仅长文页面：帖子详情/公告正文）
   *     判定条件：页面存在正文容器且内容高度超过 2 屏，否则进度条没有意义。
   * ==================================================================== */
  try {
    var article = document.querySelector('.pd-content, .post-detail, .post-content');
    if (article && article.scrollHeight > window.innerHeight * 2) {
      var bar = document.createElement('div');
      bar.className = 'lw-readbar';
      bar.setAttribute('aria-hidden', 'true');
      bar.innerHTML = '<i></i>';
      document.body.appendChild(bar);
      var fill = bar.firstChild;

      var lastPct = -1;
      var syncBar = function () {
        var doc = document.documentElement;
        var total = (doc.scrollHeight - window.innerHeight);
        var pct = total > 0 ? Math.min(100, Math.max(0, (window.scrollY / total) * 100)) : 0;
        var rounded = Math.round(pct);
        if (rounded !== lastPct) {
          lastPct = rounded;
          fill.style.width = rounded + '%';
          bar.classList.toggle('is-visible', rounded > 1 && rounded < 99);
        }
      };
      window.addEventListener('scroll', syncBar, { passive: true });
      window.addEventListener('resize', syncBar, { passive: true });
      syncBar();
    }
  } catch (e) { /* ignore */ }

  /* ======================================================================
   * 4. 字号调节 —— 已并入第 2 节「统一浮动操作按钮」的菜单项，不再单独浮动。
   *     档位 class（lw-fs-lg / lw-fs-xl）与 localStorage 键（lw_fontsize）保持不变，
   *     因此老的用户设置与 polish.css 里的字号规则都照旧生效。
   * ==================================================================== */


  /* ======================================================================
   * 5. 键盘快捷键面板（按 ? 呼出）
   * ==================================================================== */
  try {
    var overlay = document.createElement('div');
    overlay.className = 'lw-kbd-overlay';
    overlay.setAttribute('role', 'dialog');
    overlay.setAttribute('aria-modal', 'true');
    overlay.setAttribute('aria-label', '键盘快捷键');
    var rows = [
      ['?', '打开 / 关闭本面板'],
      ['g', '回到顶部'],
      ['G', '跳到底部'],
      ['t', '切换亮 / 暗主题'],
      ['/', '聚焦搜索框'],
      ['Esc', '关闭弹层 / 灯箱']
    ];
    var html = '<div class="lw-kbd-panel"><h2 class="lw-kbd-title">键盘快捷键'
      + '<button type="button" aria-label="关闭">×</button></h2>';
    rows.forEach(function (r) {
      html += '<div class="lw-kbd-row"><kbd>' + r[0] + '</kbd><span>' + r[1] + '</span></div>';
    });
    html += '</div>';
    overlay.innerHTML = html;
    document.body.appendChild(overlay);

    var closeKbd = function () { overlay.classList.remove('is-open'); };
    overlay.querySelector('.lw-kbd-title button').addEventListener('click', closeKbd);
    overlay.addEventListener('click', function (e) { if (e.target === overlay) { closeKbd(); } });

    document.addEventListener('keydown', function (e) {
      var tag = (e.target && e.target.tagName || '').toLowerCase();
      var typing = tag === 'input' || tag === 'textarea' || tag === 'select'
        || (e.target && e.target.isContentEditable);
      if (typing || e.ctrlKey || e.metaKey || e.altKey) { return; }

      if (e.key === '?' || (e.key === '/' && e.shiftKey)) {
        e.preventDefault();
        overlay.classList.toggle('is-open');
        return;
      }
      if (e.key === 'Escape') {
        closeKbd();
        // 顺带关掉灯箱
        var lb = document.querySelector('.lw-lightbox.is-open');
        if (lb) { lb.classList.remove('is-open'); }
        return;
      }
      var reduce = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
      if (e.key === 'g') {
        window.scrollTo({ top: 0, behavior: reduce ? 'auto' : 'smooth' });
      } else if (e.key === 'G') {
        window.scrollTo({ top: document.documentElement.scrollHeight, behavior: reduce ? 'auto' : 'smooth' });
      } else if (e.key === 't') {
        var btn = document.getElementById('themeToggle');
        if (btn) { btn.click(); }
      } else if (e.key === '/') {
        var search = document.querySelector('input[type="search"], .header-search-slot input, #searchInput');
        if (search) { e.preventDefault(); search.focus(); }
      }
    });
  } catch (e) { /* ignore */ }

  /* ======================================================================
   * 6. 图片灯箱：点正文里的图片全屏放大
   *     只对「非头像、非图标」的图片生效（头像点开放大反而碍事）。
   * ==================================================================== */
  try {
    var lb = document.createElement('div');
    lb.className = 'lw-lightbox';
    lb.setAttribute('role', 'dialog');
    lb.setAttribute('aria-modal', 'true');
    lb.setAttribute('aria-label', '图片预览');
    lb.innerHTML = '<img alt=""><div class="lw-lightbox-hint">点击任意处或按 Esc 关闭</div>'
      + '<button type="button" class="lw-lightbox-close" aria-label="关闭">×</button>';
    document.body.appendChild(lb);
    var lbImg = lb.querySelector('img');

    var closeLb = function () { lb.classList.remove('is-open'); lbImg.src = ''; };
    lb.addEventListener('click', closeLb);
    lb.querySelector('.lw-lightbox-close').addEventListener('click', function (e) {
      e.stopPropagation(); closeLb();
    });
    // 防止手势缩放图片时误关
    lbImg.addEventListener('click', function (e) { e.stopPropagation(); });

    var isTiny = function (img) {
      // 小于 64px 的多半是头像/图标/徽章
      var r = img.getBoundingClientRect();
      return r.width < 64 || r.height < 64;
    };

    document.addEventListener('click', function (e) {
      var img = e.target && e.target.closest ? e.target.closest('img') : null;
      if (!img || isTiny(img)) { return; }
      // 已有原生链接包裹的图片不拦（比如点击图片跳转）
      if (img.closest('a')) { return; }
      // AI 助手/编辑器内部的图片不拦
      if (img.closest('.ai-panel, .ai-widget, [contenteditable]')) { return; }

      var src = img.currentSrc || img.src;
      if (!src || src.indexOf('data:') === 0) { return; }
      e.preventDefault();
      lbImg.src = src;
      lbImg.alt = img.alt || '';
      lb.classList.add('is-open');
    });
  } catch (e) { /* ignore */ }

  /* ======================================================================
   * 7. 帖子卡片「复制链接」按钮
   *     原站只能通过分享菜单拿链接；长按/右键在手机上都很别扭。
   * ==================================================================== */
  try {
    var cards = document.querySelectorAll('.post-card[data-post-id]');
    Array.prototype.forEach.call(cards, function (card) {
      if (card.querySelector('.lw-copy-link')) { return; }
      var pid = card.getAttribute('data-post-id');
      var btn = document.createElement('button');
      btn.type = 'button';
      btn.className = 'lw-copy-link';
      btn.title = '复制帖子链接';
      btn.setAttribute('aria-label', '复制帖子链接');
      btn.innerHTML = '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/></svg>';
      btn.addEventListener('click', function (ev) {
        ev.preventDefault();
        ev.stopPropagation();
        var url = location.origin + '/pages/post_detail.php?id=' + encodeURIComponent(pid);
        var done = function () {
          btn.classList.add('is-copied');
          btn.title = '已复制';
          setTimeout(function () { btn.classList.remove('is-copied'); btn.title = '复制帖子链接'; }, 1600);
          if (window.App && App.showToast) { App.showToast('链接已复制', 'success'); }
        };
        if (navigator.clipboard && navigator.clipboard.writeText) {
          navigator.clipboard.writeText(url).then(done, function () { fallbackCopy(url); done(); });
        } else { fallbackCopy(url); done(); }
      });
      card.appendChild(btn);
    });

    function fallbackCopy(text) {
      var ta = document.createElement('textarea');
      ta.value = text;
      ta.setAttribute('readonly', '');
      ta.style.cssText = 'position:fixed;left:-9999px;top:0;opacity:0;';
      document.body.appendChild(ta);
      ta.select();
      try { document.execCommand('copy'); } catch (e) { /* ignore */ }
      document.body.removeChild(ta);
    }
  } catch (e) { /* ignore */ }

  /* ======================================================================
   * 8. 点赞心形弹跳动画：给「已点赞」状态挂上动画 class
   *     只对包含心形路径的点赞按钮生效（通过 aria-label / title 判断，
   *     避免误伤其它按钮）。
   * ==================================================================== */
  try {
    document.addEventListener('click', function (e) {
      var btn = e.target && e.target.closest ? e.target.closest('.like-btn, [data-action="like"], .post-like-btn') : null;
      if (!btn) { return; }
      var reduce = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
      if (reduce) { return; }
      btn.classList.remove('is-on');
      // 强制重排以重启动画
      void btn.offsetWidth;
      btn.classList.add('is-on');
    }, true);
  } catch (e) { /* ignore */ }

  /* ======================================================================
   * 9. 有未读通知时摇一下铃铛
   * ==================================================================== */
  try {
    var badge = document.querySelector('.notif-badge, [data-unread-count]');
    if (badge) {
      var n = parseInt((badge.textContent || '').replace(/\D/g, ''), 10);
      if (n > 0) {
        var bell = badge.closest('.lw-icon-bell') || document.querySelector('.notif-btn, [data-nav="msg"]');
        if (bell) { bell.classList.add('lw-icon-bell', 'has-new'); }
      }
    }
  } catch (e) { /* ignore */ }

  /* ======================================================================
   * 10. 卡片交错进场
   *     首屏前 8 张依次延迟 40ms，制造「内容逐个到位」的节奏感。
   *     只对首屏做，无限滚动追加的不做（否则越滚越卡）。
   * ==================================================================== */
  try {
    var reduceM = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    if (!reduceM && !('IntersectionObserver' in window === false)) {
      var items = document.querySelectorAll('.post-card');
      var cap = Math.min(items.length, 8);
      for (var i = 0; i < cap; i++) {
        (function (el, idx) {
          el.style.animationDelay = (idx * 40) + 'ms';
          el.classList.add('lw-rise');
        })(items[i], i);
      }
    }
  } catch (e) { /* ignore */ }
})();
