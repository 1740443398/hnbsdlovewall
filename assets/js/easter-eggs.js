/**
 * 校园交流墙 · 彩蛋脚本（站点自实现，无任何第三方依赖）
 *
 * 两个彩蛋：
 *   一、隐藏密令 —— 桌面端输入 Konami 代码，移动端 2 秒内连点顶栏 logo 5 次，
 *       触发全屏彩带 + 一句随机文案；约 2.5 秒后彻底清理（DOM / 监听 / rAF 全停）。
 *   二、404 互动场景 —— 点击页面中央教室场景里的灯 / 窗 / 纸飞机，出现一句暖心文案。
 *
 * 文案不写死在本文件里：由页面（pwa_head.php / pages/404.php）用 t() 渲染成
 * window.LW_EGG_STRINGS / window.LW_EGG_SCENE_TEXTS 后注入，本文件读取即可，
 * 取不到时退回内置的简体兜底文案（保证脚本独立可用、不会静默失效）。
 */
(function () {
  'use strict';

  // 后台路径完全不加载彩蛋，避免干扰管理操作
  if (window.location.pathname.indexOf('/admin') === 0) return;

  // 兜底文案（简体）。正常情况下会被页面注入的翻译覆盖。
  var FALLBACK_CELEBRATE = [
    '这一页被你遇见了，也算今天的巧合。',
    '灯还亮着，故事就还没讲完。',
    '慢一点也没关系，走廊本来就该慢慢走。',
    '有些答案不在地图上，在拐角处。',
    '路过一件小小的好事，记得收进口袋。'
  ];
  var FALLBACK_SCENE = [
    '你要找的页面不在这里，但你来过，这一趟就不算白走。',
    '拐错一个弯，有时也会遇见一处安静的风景。',
    '教室的灯替你留着，先回首页吧。'
  ];

  var ANIM_MS = 2500;          // 彩带动画时长
  var RATE_LIMIT_MS = 60000;   // 同一会话内彩蛋一的最小触发间隔
  var TAP_WINDOW_MS = 2000;    // 连点 logo 的计时窗口
  var TAP_NEEDED = 5;          // 需要的连点次数

  var RATE_KEY = 'lovewall_egg_last';
  var BAG_KEY = 'lovewall_egg_bag';
  var SCENE_BAG_KEY = 'lovewall_egg_scene_bag';

  /* ---------- 小工具 ---------- */

  function readStrings(globalName, fallback) {
    var v = window[globalName];
    if (Object.prototype.toString.call(v) === '[object Array]' && v.length) return v;
    return fallback;
  }

  function reducedMotion() {
    return !!(window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches);
  }

  // 会话级存储读写：localStorage / sessionStorage 不可用时一律静默降级，绝不抛错
  function storeGet(store, key) {
    try {
      return window[store].getItem(key);
    } catch (e) {
      return null;
    }
  }
  function storeSet(store, key, val) {
    try {
      window[store].setItem(key, val);
    } catch (e) { /* 无痕模式 / 配额满：忽略 */ }
  }

  /**
   * 从 list 里随机取一条，并保证「本轮内不重复」：
   * 用一个袋子记录已取过的下标，取满一轮后清空重来。
   * 存储不可用时退化为纯随机。
   */
  function pickNoRepeat(list, bagKey) {
    if (!list || !list.length) return '';
    var raw = storeGet('localStorage', bagKey);
    var bag = [];
    // raw 为 null 既可能是「第一次使用」，也可能是存储不可用；
    // 两种情况都从空袋子开始：前者正常记录，后者每次都是纯随机，都不会抛错。
    if (raw) {
      try {
        bag = JSON.parse(raw);
      } catch (e) {
        bag = [];
      }
    }
    if (Object.prototype.toString.call(bag) !== '[object Array]') bag = [];
    bag = bag.filter(function (i) {
      return typeof i === 'number' && i >= 0 && i < list.length;
    });
    if (bag.length >= list.length) bag = []; // 一轮取完，重新开始

    var remaining = [];
    for (var i = 0; i < list.length; i++) {
      if (bag.indexOf(i) === -1) remaining.push(i);
    }
    var pick = remaining[Math.floor(Math.random() * remaining.length)];
    bag.push(pick);
    storeSet('localStorage', bagKey, JSON.stringify(bag));
    return list[pick];
  }

  /* ---------- 文案气泡 ---------- */

  function showToast(text, ms) {
    if (!text) return;
    var el = document.createElement('div');
    el.className = 'lw-egg-toast';
    el.setAttribute('role', 'status');
    el.textContent = text;
    document.body.appendChild(el);
    // 强制回流，确保淡入过渡生效
    void el.offsetWidth;
    el.classList.add('lw-egg-toast-show');
    window.setTimeout(function () {
      el.classList.remove('lw-egg-toast-show');
      window.setTimeout(function () {
        if (el.parentNode) el.parentNode.removeChild(el);
      }, 450);
    }, ms);
  }

  /* ---------- 彩带 ---------- */

  var activeConfetti = null;

  function tokenColors() {
    var names = ['--primary', '--accent', '--accent-dark', '--info', '--success', '--warning'];
    var out = [];
    try {
      var cs = window.getComputedStyle(document.documentElement);
      for (var i = 0; i < names.length; i++) {
        var v = (cs.getPropertyValue(names[i]) || '').trim();
        if (v) out.push(v);
      }
    } catch (e) { /* 忽略，走兜底色 */ }
    if (!out.length) {
      out = ['#2F5B9A', '#C9A96E', '#A8843F', '#2773C4', '#2E8B57', '#C7902A'];
    }
    return out;
  }

  function launchConfetti() {
    var colors = tokenColors();
    var dpr = Math.min(window.devicePixelRatio || 1, 2);
    var canvas = document.createElement('canvas');
    canvas.className = 'lw-egg-canvas';
    document.body.appendChild(canvas);
    var ctx = canvas.getContext('2d');

    var W = 0, H = 0;
    function resize() {
      W = window.innerWidth;
      H = window.innerHeight;
      canvas.width = Math.round(W * dpr);
      canvas.height = Math.round(H * dpr);
      canvas.style.width = W + 'px';
      canvas.style.height = H + 'px';
      ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
    }
    resize();

    var COUNT = 100; // 控制在 80~120
    var parts = [];
    for (var i = 0; i < COUNT; i++) {
      parts.push({
        x: Math.random() * W,
        y: -20 - Math.random() * H * 0.4,
        vx: (Math.random() - 0.5) * 1.6,
        vy: 1.4 + Math.random() * 2.4,
        w: 5 + Math.random() * 5,
        h: 8 + Math.random() * 8,
        rot: Math.random() * Math.PI,
        vr: (Math.random() - 0.5) * 0.22,
        color: colors[i % colors.length]
      });
    }

    var start = performance.now();
    var raf = null;
    var stopped = false;

    function stop() {
      if (stopped) return;
      stopped = true;
      if (raf !== null) {
        window.cancelAnimationFrame(raf);
        raf = null;
      }
      document.removeEventListener('visibilitychange', onVisibility);
      window.removeEventListener('resize', resize);
      if (canvas.parentNode) canvas.parentNode.removeChild(canvas);
      if (activeConfetti && activeConfetti.stop === stop) activeConfetti = null;
    }

    // 切到后台就立即停掉，不留 rAF 空转
    function onVisibility() {
      if (document.hidden) stop();
    }

    function frame() {
      if (stopped) return;
      var t = performance.now() - start;
      ctx.clearRect(0, 0, W, H);
      var fade = t > ANIM_MS - 400 ? Math.max(0, (ANIM_MS - t) / 400) : 1;
      for (var i = 0; i < parts.length; i++) {
        var p = parts[i];
        p.x += p.vx;
        p.y += p.vy;
        p.vy += 0.028;
        p.rot += p.vr;
        ctx.save();
        ctx.translate(p.x, p.y);
        ctx.rotate(p.rot);
        ctx.globalAlpha = fade;
        ctx.fillStyle = p.color;
        ctx.fillRect(-p.w / 2, -p.h / 2, p.w, p.h);
        ctx.restore();
      }
      if (t >= ANIM_MS) {
        stop();
        return;
      }
      raf = window.requestAnimationFrame(frame);
    }

    document.addEventListener('visibilitychange', onVisibility);
    window.addEventListener('resize', resize, { passive: true });
    raf = window.requestAnimationFrame(frame);

    return { stop: stop };
  }

  /* ---------- 彩蛋一：触发与限流 ---------- */

  function rateAllowed() {
    var last = storeGet('sessionStorage', RATE_KEY);
    if (last === null) return true;
    var t = parseInt(last, 10);
    if (isNaN(t)) return true;
    return Date.now() - t >= RATE_LIMIT_MS;
  }

  function markTriggered() {
    storeSet('sessionStorage', RATE_KEY, String(Date.now()));
  }

  function celebrate() {
    if (!rateAllowed()) return;
    markTriggered();

    var texts = readStrings('LW_EGG_STRINGS', FALLBACK_CELEBRATE);
    showToast(pickNoRepeat(texts, BAG_KEY), ANIM_MS);

    // 降低动态效果：只留文案，不放彩带
    if (reducedMotion()) return;

    if (activeConfetti) activeConfetti.stop();
    activeConfetti = launchConfetti();
  }

  // 键盘密令：↑↑↓↓←→←→ B A（用 event.key 判断，大小写不敏感）
  var KONAMI = ['arrowup', 'arrowup', 'arrowdown', 'arrowdown',
                'arrowleft', 'arrowright', 'arrowleft', 'arrowright', 'b', 'a'];

  function isEditable(el) {
    if (!el) return false;
    var tag = el.tagName;
    return tag === 'INPUT' || tag === 'TEXTAREA' || tag === 'SELECT' || el.isContentEditable === true;
  }

  var konamiPos = 0;

  function onKeydown(e) {
    if (isEditable(e.target)) {
      konamiPos = 0;
      return;
    }
    var key = (e.key || '').toLowerCase();
    if (key === KONAMI[konamiPos]) {
      konamiPos++;
      if (konamiPos === KONAMI.length) {
        konamiPos = 0;
        celebrate();
      }
    } else {
      // 允许从序列首键重新开始，避免中间一次误按就前功尽弃
      konamiPos = (key === KONAMI[0]) ? 1 : 0;
    }
  }

  // 移动端：2 秒内连点顶栏 logo 5 次
  function initLogoTaps() {
    var logo = document.querySelector('a.site-logo');
    if (!logo) {
      initCornerTaps();
      return;
    }
    var taps = [];
    var navTimer = null;

    logo.addEventListener('click', function (e) {
      var now = Date.now();
      taps = taps.filter(function (ts) { return now - ts < TAP_WINDOW_MS; });
      taps.push(now);

      // 始终接管这次点击：单次点击延迟放行跳转，连点凑够 5 次则改为触发彩蛋。
      // 否则第一次点击就会跳走，永远凑不满 5 次。
      e.preventDefault();

      if (taps.length >= TAP_NEEDED) {
        taps = [];
        if (navTimer) {
          window.clearTimeout(navTimer);
          navTimer = null;
        }
        celebrate();
        return;
      }

      var href = logo.getAttribute('href');
      if (navTimer) window.clearTimeout(navTimer);
      navTimer = window.setTimeout(function () {
        navTimer = null;
        if (href) window.location.href = href;
      }, 300);
    });
  }

  // logo 选择器不可用时的降级：连点页面左上角区域 5 次
  function initCornerTaps() {
    var taps = [];
    document.addEventListener('click', function (e) {
      var x = e.clientX;
      var y = e.clientY;
      if (x > window.innerWidth * 0.25 || y > window.innerHeight * 0.15) return;
      var now = Date.now();
      taps = taps.filter(function (ts) { return now - ts < TAP_WINDOW_MS; });
      taps.push(now);
      if (taps.length >= TAP_NEEDED) {
        taps = [];
        celebrate();
      }
    });
  }

  /* ---------- 彩蛋二：404 互动场景 ---------- */

  function initScene() {
    var scene = document.getElementById('lw404Scene');
    if (!scene) return;
    var caption = document.getElementById('lwSceneCaption');
    var texts = readStrings('LW_EGG_SCENE_TEXTS', FALLBACK_SCENE);
    var hotspots = scene.querySelectorAll('[data-lw-egg]');

    function pick() {
      return pickNoRepeat(texts, SCENE_BAG_KEY);
    }

    function show(el) {
      var text = pick();
      if (!text) return;
      var all = scene.querySelectorAll('[data-lw-egg]');
      for (var i = 0; i < all.length; i++) {
        all[i].classList.remove('lw-egg-active');
      }
      if (el) el.classList.add('lw-egg-active');
      if (caption) {
        caption.classList.remove('lw-scene-caption-show');
        caption.textContent = text;
        // 强制回流后再加类，让每次点击都能重新淡入
        void caption.offsetWidth;
        caption.classList.add('lw-scene-caption-show');
      }
    }

    for (var i = 0; i < hotspots.length; i++) {
      (function (el) {
        el.addEventListener('click', function () { show(el); });
        el.addEventListener('keydown', function (e) {
          if (e.key === 'Enter' || e.key === ' ' || e.key === 'Spacebar') {
            e.preventDefault();
            show(el);
          }
        });
      })(hotspots[i]);
    }
  }

  /* ---------- 启动 ---------- */

  function init() {
    document.addEventListener('keydown', onKeydown);

    // logo 连点仅针对触屏（指针粗）设备，桌面端交给键盘密令、不干扰 logo 正常跳转
    var coarse = !!(window.matchMedia && window.matchMedia('(pointer: coarse)').matches);
    if (coarse) initLogoTaps();

    initScene();
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init, { once: true });
  } else {
    init();
  }
})();
