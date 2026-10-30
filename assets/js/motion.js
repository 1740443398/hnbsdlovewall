/**
 * 动效层 —— 滚动显现 / 骨架屏 / 顶部加载条
 *
 * ==========================================================================
 * 三个观察者，各管一件事
 * ==========================================================================
 *   1. IntersectionObserver（显现）：元素进入视口才播动画。
 *      滚出视口后再进来不重播 —— 反复播会让人烦，也浪费性能。
 *   2. MutationObserver（骨架屏）：动态插入的内容自动套骨架屏。
 *   3. 加载条：任何 fetch / 表单提交都显示一条细进度条。
 *
 * ==========================================================================
 * 为什么不引 AOS / animate.css
 * ==========================================================================
 * 那些库动辄几十 KB，而这里要的只有「淡入 + 上移 14px」一个效果。
 * 自己写 200 行能做到同样的事，还省掉一个网络请求 ——
 * 在「每个请求固定 1 秒」的主机上，这点尤其重要。
 * ==========================================================================
 */
(function () {
  'use strict';

  var reduce = !!(window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches);

  /* ---------------------------------------------------------------- 显现 */

  var io = null;

  function ensureIO() {
    if (io || reduce || !('IntersectionObserver' in window)) return io;
    io = new IntersectionObserver(function (entries) {
      for (var i = 0; i < entries.length; i++) {
        var e = entries[i];
        if (!e.isIntersecting) continue;
        var el = e.target;
        el.classList.add('is-in');
        // 动画结束后撤掉 will-change，释放合成层
        window.setTimeout(function (node) {
          return function () { node.classList.add('is-done'); };
        }(el), 700);
        io.unobserve(el);
      }
    }, {
      rootMargin: '0px 0px -8% 0px',
      threshold: 0.06
    });
    return io;
  }

  /**
   * 给需要显现的元素打标。
   *
   * 只挑「列表项 / 卡片 / 独立段落」这类块级元素，
   * 不给行内元素和已经很小的东西加 —— 那会让页面看起来在抖。
   */
  function markReveal(root) {
    if (reduce) return;
    var sel = [
      '.post-card',
      '.u-post',
      '.comment-item',
      '.tool-card',
      '.bk-card',
      '.stat-card',
      '.section',
      '.me-panel',
      '.message-item'
    ].join(',');

    var nodes = (root || document).querySelectorAll(sel);
    var ob = ensureIO();
    if (!ob) return;

    for (var i = 0; i < nodes.length; i++) {
      var n = nodes[i];
      if (n.classList.contains('lw-reveal') || n.hasAttribute('data-no-reveal')) continue;
      n.classList.add('lw-reveal');
      ob.observe(n);
    }
  }

  /* -------------------------------------------------------------- 骨架屏 */

  /**
   * 生成一段骨架屏 HTML。
   * @param {number} lines 行数
   */
  function skeletonHtml(lines) {
    var out = '<div class="lw-skel-wrap" style="padding:16px;">';
    for (var i = 0; i < (lines || 3); i++) {
      out += '<div class="lw-skel lw-skel-line ' + (i === 0 ? 'w80' : (i % 2 ? 'w60' : 'w40')) + '"></div>';
    }
    out += '</div>';
    return out;
  }

  /** 把一个容器临时填成骨架屏。返回还原函数。 */
  function skelInto(el, lines) {
    if (!el || reduce) return function () {};
    var prev = el.innerHTML;
    el.innerHTML = skeletonHtml(lines);
    return function () {
      // 只在「内容还没被真实数据替换」时还原，避免覆盖掉新渲染的内容
      if (el.querySelector('.lw-skel-wrap')) el.innerHTML = prev;
    };
  }

  /* ------------------------------------------------------------ 顶部加载条 */

  var bar = null;
  var barTimer = null;
  var barRunning = 0;

  function ensureBar() {
    if (reduce) return null;
    if (bar) return bar;
    bar = document.createElement('div');
    bar.id = 'lw-progress';
    (document.body || document.documentElement).appendChild(bar);
    return bar;
  }

  function barStart() {
    var b = ensureBar();
    if (!b) return;
    barRunning++;
    b.classList.remove('is-done');
    b.style.opacity = '1';
    b.style.width = '12%';
    clearTimeout(barTimer);
    // 缓慢爬升到 80%，不真的到 100% —— 100% 等数据回来才给
    barTimer = setTimeout(function () {
      if (barRunning > 0 && b) b.style.width = '72%';
    }, 260);
  }

  function barDone() {
    barRunning = Math.max(0, barRunning - 1);
    if (barRunning > 0) return;
    var b = ensureBar();
    if (!b) return;
    b.style.width = '100%';
    setTimeout(function () {
      if (!b) return;
      b.classList.add('is-done');
      setTimeout(function () {
        if (b && barRunning === 0) b.style.width = '0';
      }, 340);
    }, 160);
  }

  /* --------------------------------------------------------- 接管 fetch */

  // 让所有 fetch 自动带加载条（不含跨域，避免给第三方请求加条）
  if (window.fetch && !window.__lwFetchWrapped) {
    window.__lwFetchWrapped = true;
    var origFetch = window.fetch;
    window.fetch = function (input, init) {
      var url = typeof input === 'string' ? input : (input && input.url) || '';
      var sameOrigin = url.indexOf('/') === 0 ||
        url.indexOf(location.origin) === 0 ||
        url.indexOf('http') !== 0;   // 相对路径
      if (!sameOrigin) return origFetch.apply(this, arguments);

      barStart();
      var p;
      try {
        p = origFetch.apply(this, arguments);
      } catch (e) {
        barDone();
        throw e;
      }
      return p.then(function (r) {
        barDone();
        return r;
      }, function (e) {
        barDone();
        throw e;
      });
    };
  }

  /* ------------------------------------------------------- 顶栏滚动投影 */

  function initHeaderShadow() {
    var header = document.querySelector('.site-header');
    if (!header) return;
    var last = -1;
    function onScroll() {
      var y = window.scrollY || window.pageYOffset || 0;
      var on = y > 8;
      if (on !== last) {
        header.classList.toggle('is-scrolled', on);
        last = on;
      }
    }
    window.addEventListener('scroll', onScroll, { passive: true });
    onScroll();
  }

  /* ------------------------------------------------------------ 启动 */

  function boot() {
    if (!reduce) document.body.classList.add('lw-page-enter');

    markReveal(document);
    initHeaderShadow();

    // 动态插入的内容（翻页、AJAX 渲染）也要能被观察到
    if ('MutationObserver' in window) {
      var mo = new MutationObserver(function (muts) {
        var has = false;
        for (var i = 0; i < muts.length; i++) {
          if (muts[i].addedNodes && muts[i].addedNodes.length) { has = true; break; }
        }
        if (has) markReveal(document);
      });
      mo.observe(document.body, { childList: true, subtree: true });
    }

    // 站内链接点击 / 表单提交：显示加载条，让「点了有反应」
    document.addEventListener('click', function (e) {
      var a = e.target && e.target.closest ? e.target.closest('a') : null;
      if (!a) return;
      var href = a.getAttribute('href') || '';
      if (!href || href.charAt(0) === '#' || a.target === '_blank' ||
          href.indexOf('javascript:') === 0 || a.hasAttribute('download')) return;
      if (href.indexOf(location.origin) === 0 || href.charAt(0) === '/') barStart();
    }, true);

    document.addEventListener('submit', function () { barStart(); }, true);

    // 页面从 bfcache 恢复时，把加载条收掉（否则会一直挂着）
    window.addEventListener('pageshow', function (e) {
      if (e.persisted) {
        barRunning = 0;
        var b = ensureBar();
        if (b) { b.style.width = '0'; b.classList.add('is-done'); }
      }
    });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot, { once: true });
  } else {
    boot();
  }

  // 对外暴露，供页面按需使用
  window.LWMotion = {
    markReveal: markReveal,
    skeleton: skeletonHtml,
    skelInto: skelInto,
    barStart: barStart,
    barDone: barDone,
  };
})();
