/* ==========================================================================
   liquid-glass.js —— 全局液态玻璃引擎（独立作用域，可整体摘除）
   职责：
     1) 设备区分：桌面才渲染 Dock；移动端仅启用「组件玻璃」等轻量效果。
     2) Canvas 流体背景：帧率节流 + 页面隐藏暂停 + 动态降质 + 可销毁释放。
     3) 开关状态：总开关 + 分项开关，持久化 localStorage('lw_liquid_glass')。
     4) MIUIX 回退：总开关关闭时摘玻璃、上 MIUIX 色板、销毁 Canvas 释放内存。
     5) 设置面板 API：window.LWLiquidGlass 供个人中心/后台调用。
   说明：所有 DOM id/class 均带 lw-lg / lw-dock 前缀，关闭即全部移除，零残留。
   ========================================================================== */
(function () {
  'use strict';

  var STORE_KEY = 'lw_liquid_glass';
  var root = document.documentElement;

  /* ---------- 状态：默认开启总开关 + 三个分项 ---------- */
  var DEFAULTS = {
    master: true,        // 总开关：物理上决定是否绘制流体 + 玻璃材质
    bgFluid: true,       // 分项：背景流体
    dockFluid: true,     // 分项：Dock 流体光
    gloss: true,         // 分项：组件玻璃材质
    dockPos: null,       // Dock 位置 { x: 百分比, y: 像素 }
    speed: 1,            // 流体速度倍率（后台可下发）
    light: 1             // 光强倍率（后台可下发）
  };

  var state = read();
  var canvas = null, ctx = null, rafId = 0, running = false;
  var blobs = [];
  var quality = 1;                 // 1 正常 → 0.6 降质
  var lastFrame = 0, frameBudget = 1000 / 60;
  var resizeRaf = 0;

  /* ---------- 工具 ---------- */
  function read() {
    try {
      var raw = localStorage.getItem(STORE_KEY);
      if (!raw) return Object.assign({}, DEFAULTS);
      var o = JSON.parse(raw);
      var s = Object.assign({}, DEFAULTS);
      ['master', 'bgFluid', 'dockFluid', 'gloss'].forEach(function (k) {
        if (typeof o[k] === 'boolean') s[k] = o[k];
      });
      if (o.dockPos && typeof o.dockPos.x === 'number') s.dockPos = o.dockPos;
      if (typeof o.speed === 'number' && o.speed > 0) s.speed = Math.min(3, o.speed);
      if (typeof o.light === 'number' && o.light >= 0) s.light = Math.min(2, o.light);
      // 用户在低配设备上手动开启过流体：记住这个选择，别每次刷新又给关回去
      if (o.userForced === true) s.userForced = true;
      return s;
    } catch (e) { return Object.assign({}, DEFAULTS); }
  }

  function save() {
    try { localStorage.setItem(STORE_KEY, JSON.stringify(state)); } catch (e) {}
  }

  function isDesktop() {
    if (window.LoveWallApp && window.LoveWallApp.available && window.LoveWallApp.available()) {
      // 原生套壳：按屏幕尺寸判定，>=900px 视为桌面布局
      return Math.min(window.innerWidth, window.innerHeight) >= 900;
    }
    return window.matchMedia('(min-width: 900px) and (hover: hover) and (pointer: fine)').matches;
  }

  function reduceMotion() {
    return window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  }

  /* ---------- 低配设备判定（需求三、7「低性能设备性能适配」）----------
     判据只用三个「零成本」信号，全部读自 navigator，不触发任何布局或渲染：
       ① navigator.deviceMemory  < 4GB   —— 内存太小，WebGL 与多层模糊一起会掉帧
       ② navigator.hardwareConcurrency <= 4 —— 逻辑核少，主线程跑不动逐帧绘制
       ③ WebGL 上下文拿不到 —— 设备根本不支持，硬开会一直白屏重试
     任一条命中即降级：不启动 Canvas 流体背景，只保留静态玻璃层（CSS 侧照常）。
     用户仍可在个人中心手动开启 —— 降级是「默认值」，不是「禁用」。 */
  var lowPerfCache = null;
  function isLowPerf() {
    if (lowPerfCache !== null) { return lowPerfCache; }
    var low = false;
    try {
      var mem = navigator.deviceMemory;
      if (typeof mem === 'number' && mem > 0 && mem < 4) { low = true; }
      var cores = navigator.hardwareConcurrency;
      if (!low && typeof cores === 'number' && cores > 0 && cores <= 4) { low = true; }
    } catch (e) {}
    if (!low) {
      try {
        var c = document.createElement('canvas');
        var gl = c.getContext('webgl') || c.getContext('experimental-webgl');
        if (!gl) { low = true; }
      } catch (e) { low = true; }
    }
    lowPerfCache = low;
    return low;
  }
  /* 对外暴露：其他脚本（如通知/挂件）也可据此砍掉自己的动效 */
  try { window.LWIsLowPerf = isLowPerf; } catch (e) {}

  /* 页面切到后台时暂停逐帧绘制：手机息屏 / 切 App 后仍在跑 RAF 是最常见的耗电来源 */
  function bindVisibility() {
    document.addEventListener('visibilitychange', function () {
      if (document.hidden) {
        if (rafId) { cancelAnimationFrame(rafId); rafId = 0; }
        running = false;
      } else if (state.master && state.bgFluid && canvas) {
        if (!running) { running = true; lastFrame = 0; schedule(); }
      }
    });
  }
  bindVisibility();

  /* ---------- 站点根地址（兼容子目录部署） ---------- */
  function baseUrl() {
    return (window.SITE_URL && String(window.SITE_URL).replace(/\/$/, '')) || '';
  }

  /* ---------- 流体云：多端同步（效果开关 / Dock 位置） ---------- */
  var pushTimer = 0, cloudPulled = false;

  function collectPrefs() {
    var prefs = {
      master: state.master, bgFluid: state.bgFluid,
      dockFluid: state.dockFluid, gloss: state.gloss,
      speed: state.speed, light: state.light,
      dockPos: state.dockPos
    };
    // 合并通知偏好（静默）、主题，实现同一份云端快照
    try {
      var np = localStorage.getItem('lw_notify_prefs');
      if (np) prefs.notify = JSON.parse(np);
    } catch (e) {}
    try { prefs.theme = localStorage.getItem('theme') || localStorage.getItem('lw_theme') || ''; } catch (e) {}
    return prefs;
  }

  /** 首次进入：本地无记录时，采用云端偏好；本地有记录则本地优先（随后上云） */
  function hydrateFromCloud() {
    try {
      fetch(baseUrl() + '/api/user/ui_prefs.php', {
        method: 'GET', credentials: 'same-origin',
        headers: { 'Accept': 'application/json' }
      }).then(function (r) { return r.json(); }).then(function (res) {
        if (!res || res.success !== true || !res.data) return;
        var p = (res.data.prefs && typeof res.data.prefs === 'object') ? res.data.prefs : null;
        var hasLocal = false;
        try { hasLocal = !!localStorage.getItem(STORE_KEY); } catch (e) {}
        if (p && !hasLocal) {
          ['master', 'bgFluid', 'dockFluid', 'gloss'].forEach(function (k) {
            if (typeof p[k] === 'boolean') state[k] = p[k];
          });
          if (typeof p.speed === 'number' && p.speed > 0) state.speed = Math.min(3, p.speed);
          if (typeof p.light === 'number' && p.light >= 0) state.light = Math.min(2, p.light);
          if (p.dockPos && typeof p.dockPos.x === 'number') state.dockPos = p.dockPos;
          save();
          paint();
          if (state.master && isDesktop()) ensureDock();
          // 通知偏好落本地（由 NotificationSystem 生效）
          if (p.notify && typeof p.notify === 'object') {
            try { localStorage.setItem('lw_notify_prefs', JSON.stringify(p.notify)); } catch (e) {}
            try { window.dispatchEvent(new CustomEvent('lw:ui-prefs', { detail: p.notify })); } catch (e) {}
          }
          // 主题同步（仅当云端有明确值）
          try {
            if (p.theme === 'light' || p.theme === 'dark' || p.theme === 'system') {
              localStorage.setItem('theme', p.theme);
              if (window.LWTheme && typeof window.LWTheme.apply === 'function') window.LWTheme.apply(p.theme);
            }
          } catch (e) {}
          emit();
        }
        cloudPulled = true;
        pushToCloud();   // 把当前（本地或合并后）状态回写云端，达成一致
      }).catch(function () {});
    } catch (e) {}
  }

  /** 变更后防抖上云（匿名/未登录静默失败，不影响本地体验） */
  function pushToCloud() {
    if (pushTimer) clearTimeout(pushTimer);
    pushTimer = setTimeout(function () {
      pushTimer = 0;
      try {
        var token = window.CSRF_TOKEN || '';
        var body = 'csrf_token=' + encodeURIComponent(token) +
          '&prefs=' + encodeURIComponent(JSON.stringify({ glass: collectPrefs() }));
        fetch(baseUrl() + '/api/user/ui_prefs.php', {
          method: 'POST', credentials: 'same-origin',
          headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
          body: body
        }).catch(function () {});
      } catch (e) {}
    }, 800);
  }

  /* ---------- 后台下发的全局参数（速度/光强/强制关闭） ---------- */
  var remote = { enabled: true, force_off: false, speed: 1, light: 1 };
  var reportedAt = 0;

  /**
   * 「页面正在离开」标记。
   *
   * 为什么不是"主动 abort 掉在途请求"：
   *   主动 abort 依然会让浏览器打出 net::ERR_ABORTED（只是原因从 navigation 变成 abort），
   *   控制台里看起来完全一样，等于白做。
   *   真正干净的做法是：页面一进入离开状态就不再发新请求；
   *   已经发出去的交给浏览器自然处理（它的结果我们也不再使用了）。
   */
  var leaving = false;
  window.addEventListener('pagehide', function () { leaving = true; });
  window.addEventListener('beforeunload', function () { leaving = true; });

  function fetchRemoteConfig() {
    if (leaving) return;
    try {
      fetch(baseUrl() + '/api/liquid_glass.php?action=config', {
        method: 'GET', credentials: 'same-origin',
        headers: { 'Accept': 'application/json' }
      }).then(function (r) { return r.json(); }).then(function (res) {
        if (!res || res.success !== true || !res.data) return;
        var d = res.data;
        remote.enabled = d.enabled !== false;
        remote.force_off = !!d.force_off;
        if (typeof d.speed === 'number' && d.speed > 0) remote.speed = Math.min(3, d.speed);
        if (typeof d.light === 'number' && d.light >= 0) remote.light = Math.min(2, d.light);
        state.speed = remote.speed;
        state.light = remote.light;
        if (remote.force_off || !remote.enabled) {
          set({ master: false });
        } else {
          paint();
        }
      }).catch(function () {});
    } catch (e) {}
  }

  /* ---------- 匿名上报分项开关状态（仅计数，不含用户身份） ---------- */
  function report() {
    var now = Date.now();
    if (now - reportedAt < 60000) return;   // 1 分钟节流
    if (leaving) return;                     // 页面在走，别发
    reportedAt = now;
    try {
      var token = window.CSRF_TOKEN || '';
      var body = 'action=report&csrf_token=' + encodeURIComponent(token) +
        '&states=' + encodeURIComponent(JSON.stringify({
          master: state.master, bgFluid: state.bgFluid,
          dockFluid: state.dockFluid, gloss: state.gloss
        }));
      fetch(baseUrl() + '/api/liquid_glass.php', {
        method: 'POST', credentials: 'same-origin',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: body
      }).catch(function () {});
    } catch (e) {}
  }

  /* ---------- 状态应用到 DOM ---------- */
  function paint() {
    var on = !!state.master;
    root.classList.toggle('lw-lg-on', on);
    root.classList.toggle('lw-miuix', !on);
    root.classList.toggle('lw-lg-no-gloss', on && !state.gloss);
    root.classList.toggle('lw-dock-on', on && isDesktop());

    if (on) {
      if (state.bgFluid) startFluid(); else stopFluid(true);
    } else {
      stopFluid(false);           // 总开关关闭：销毁 Canvas 释放内存
    }
    if (on && state.dockFluid) ensureDockLight(); else removeDockLight();
    syncDockPosition();
  }

  /* ---------- Canvas 流体背景 ---------- */
  function startFluid() {
    if (reduceMotion()) { stopFluid(true); return; }
    // 低配设备默认不跑逐帧流体（用户手动开启时 master 已被显式置为 true，不在此拦）
    if (isLowPerf() && !state.userForced) { stopFluid(true); return; }
    ensureCanvas();
    if (!running) { running = true; lastFrame = 0; schedule(); }
  }

  function stopFluid(keepCanvas) {
    running = false;
    if (rafId) { cancelAnimationFrame(rafId); rafId = 0; }
    if (!keepCanvas) destroyCanvas();
  }

  function destroyCanvas() {
    if (canvas && canvas.parentNode) canvas.parentNode.removeChild(canvas);
    canvas = null; ctx = null; blobs = [];
    root.classList.remove('lw-lg-hidden');
  }

  function ensureCanvas() {
    if (canvas || !state.bgFluid) return;
    try {
      canvas = document.createElement('canvas');
      canvas.id = 'lw-lg-canvas';
      canvas.setAttribute('aria-hidden', 'true');
      document.body.appendChild(canvas);
      ctx = canvas.getContext('2d', { alpha: true, desynchronized: true });
      if (!ctx) { destroyCanvas(); return; }
      sizeCanvas();
      seedBlobs();
      window.addEventListener('resize', onResize, { passive: true });
    } catch (e) { destroyCanvas(); }
  }

  function sizeCanvas() {
    if (!canvas) return;
    var dpr = Math.min(quality, window.devicePixelRatio || 1);
    var w = window.innerWidth, h = window.innerHeight;
    canvas.width = Math.max(1, Math.floor(w * dpr));
    canvas.height = Math.max(1, Math.floor(h * dpr));
    canvas.style.width = w + 'px';
    canvas.style.height = h + 'px';
    if (ctx) ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
  }

  function seedBlobs() {
    var n = 3;
    blobs = [];
    var palette = [
      [47, 91, 154], [201, 169, 110], [88, 158, 200]
    ];
    for (var i = 0; i < n; i++) {
      blobs.push({
        x: Math.random(), y: Math.random(),
        r: 0.28 + Math.random() * 0.18,
        vx: (Math.random() - 0.5) * 0.00035,
        vy: (Math.random() - 0.5) * 0.00035,
        c: palette[i % palette.length]
      });
    }
  }

  function onResize() {
    if (resizeRaf) return;
    resizeRaf = requestAnimationFrame(function () {
      resizeRaf = 0; sizeCanvas();
    });
  }

  function schedule() {
    if (!running) return;
    rafId = requestAnimationFrame(frame);
  }

  function frame(t) {
    if (!running || !ctx) return;
    if (!lastFrame) lastFrame = t;
    var dt = t - lastFrame;
    if (dt >= frameBudget) {
      lastFrame = t;
      draw(dt);
      adaptQuality(dt);
    }
    schedule();
  }

  function draw(dt) {
    var w = window.innerWidth, h = window.innerHeight;
    if (w === 0 || h === 0) return;
    ctx.clearRect(0, 0, w, h);
    var sp = state.speed, li = state.light;
    for (var i = 0; i < blobs.length; i++) {
      var b = blobs[i];
      b.x += b.vx * dt * sp;
      b.y += b.vy * dt * sp;
      if (b.x < -0.2 || b.x > 1.2) b.vx *= -1;
      if (b.y < -0.2 || b.y > 1.2) b.vy *= -1;
      var cx = b.x * w, cy = b.y * h, rr = b.r * Math.max(w, h);
      var g = ctx.createRadialGradient(cx, cy, 0, cx, cy, rr);
      var a = 0.42 * li;
      g.addColorStop(0, 'rgba(' + b.c[0] + ',' + b.c[1] + ',' + b.c[2] + ',' + a + ')');
      g.addColorStop(1, 'rgba(' + b.c[0] + ',' + b.c[1] + ',' + b.c[2] + ',0)');
      ctx.fillStyle = g;
      ctx.beginPath();
      ctx.arc(cx, cy, rr, 0, 6.2832);
      ctx.fill();
    }
  }

  /* 动态降质：单帧超 28ms 时降采样，恢复后再提回 */
  function adaptQuality(dt) {
    if (dt > 28 && quality > 0.6) { quality = 0.6; sizeCanvas(); }
    else if (dt < 17 && quality < 1) { quality = 1; sizeCanvas(); }
  }

  /* 页面不可见：暂停绘制 + 隐藏层，回来自动恢复 */
  document.addEventListener('visibilitychange', function () {
    var hidden = document.hidden;
    root.classList.toggle('lw-lg-hidden', hidden);
    if (hidden) { stopFluid(true); }
    else if (state.master && state.bgFluid) { startFluid(); }
  });

  /* ---------- Dock ---------- */
  var dockEl = null;
  function ensureDock() {
    if (dockEl || !state.master || !isDesktop()) return;
    var d = document.createElement('nav');
    d.className = 'lw-dock';
    d.setAttribute('aria-label', '快捷 Dock');
    var items = [
      { href: '/index.php', label: '首页', path: 'M3 10.5 12 3l9 7.5V21a1 1 0 0 1-1 1h-5v-7H9v7H4a1 1 0 0 1-1-1Z' },
      { href: '/pages/plaza.php', label: '广场', path: 'M4 4h16v4H4Zm0 6h10v10H4Zm12 0h4v10h-4Z' },
      { href: '/pages/user_center.php', label: '我的', path: 'M12 12a4 4 0 1 0 0-8 4 4 0 0 0 0 8Zm0 2c-4 0-8 2-8 5v1h16v-1c0-3-4-5-8-5Z' }
    ];
    items.forEach(function (it) {
      var a = document.createElement('a');
      a.className = 'lw-dock-item';
      a.href = it.href;
      a.setAttribute('aria-label', it.label);
      a.title = it.label;
      a.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="' + it.path + '"/></svg>';
      d.appendChild(a);
    });
    var light = document.createElement('div');
    light.className = 'lw-dock-light';
    d.appendChild(light);

    document.body.appendChild(d);
    dockEl = d;
    bindDock(d);
    restoreDockPos(d);
  }

  function removeDock() {
    if (dockEl && dockEl.parentNode) dockEl.parentNode.removeChild(dockEl);
    dockEl = null;
  }

  function ensureDockLight() { ensureDock(); }
  function removeDockLight() { /* 流光随 Dock 一起存在，Dock 移除即消失 */ }

  function bindDock(d) {
    var light = d.querySelector('.lw-dock-light');
    var dragging = false, sx = 0, sy = 0, ox = 0, oy = 0;

    function moveLight(e) {
      if (!light || !state.dockFluid) return;
      var r = d.getBoundingClientRect();
      var px = ((e.clientX - r.left) / r.width) * 100;
      var py = ((e.clientY - r.top) / r.height) * 100;
      light.style.setProperty('--lw-dock-light-x', px + '%');
      light.style.setProperty('--lw-dock-light-y', py + '%');
    }

    d.addEventListener('mousemove', moveLight, { passive: true });

    // 仅空白区（Dock 自身，非图标）按下可拖动
    d.addEventListener('pointerdown', function (e) {
      if (e.target.closest('.lw-dock-item')) return;   // 点图标不拖拽
      dragging = true;
      d.classList.add('is-dragging');
      d.setPointerCapture(e.pointerId);
      var r = d.getBoundingClientRect();
      sx = e.clientX; sy = e.clientY; ox = r.left; oy = r.top;
    });

    d.addEventListener('pointermove', function (e) {
      if (!dragging) return;
      var dx = e.clientX - sx, dy = e.clientY - sy;
      var nx = ox + dx, ny = oy + dy;
      // 液体拉伸形变：位移越大越「拉长」
      var stretch = Math.min(1.6, 1 + Math.abs(dx) / 420);
      d.style.transform = 'translate(' + nx + 'px,' + ny + 'px) scale(' + stretch + ',' + (2 - stretch) + ')';
      d.style.borderRadius = (22 + stretch * 6) + 'px';
      moveLight(e);
    });

    d.addEventListener('pointerup', function (e) {
      if (!dragging) return;
      dragging = false;
      d.classList.remove('is-dragging');
      // 回弹归位：改为居中对齐的最终位置
      d.style.transform = '';
      d.style.borderRadius = '';
      var r = d.getBoundingClientRect();
      state.dockPos = { x: (r.left + r.width / 2) / window.innerWidth, y: r.top };
      save();
      syncDockPosition();
      try { d.releasePointerCapture(e.pointerId); } catch (er) {}
    });
  }

  function syncDockPosition() {
    if (!dockEl) return;
    if (state.dockPos) {
      dockEl.style.left = (state.dockPos.x * 100) + '%';
      dockEl.style.top = state.dockPos.y + 'px';
      dockEl.style.bottom = 'auto';
    }
  }
  function restoreDockPos() { syncDockPosition(); }

  /* ---------- 对外 API（个人中心 / 后台调用） ---------- */
  function set(patch) {
    var needDockRebuild = false;
    if (typeof patch.master === 'boolean' && patch.master !== state.master) {
      // 关闭总开关：整体回收
      needDockRebuild = true;
      // 用户亲手打开 → 记下「已越过低配降级」，本次会话不再自动拦回
      if (patch.master) { state.userForced = true; save(); }
    }
    Object.keys(patch).forEach(function (k) {
      if (k in state) state[k] = patch[k];
    });
    save();
    if (!state.master) { removeDock(); }
    else if (needDockRebuild) { removeDock(); }
    paint();
    if (state.master) { ensureDock(); applyEntrance(); } else { clearEntrance(); }
    report();
    pushToCloud();
    emit();
  }

  function get() { return Object.assign({}, state); }

  function emit() {
    try {
      window.dispatchEvent(new CustomEvent('lw:liquidglass', { detail: get() }));
    } catch (e) {}
  }

  /* ---------- 入场动效 + 平滑滚动 ---------- */
  function applyEntrance() {
    if (!state.master || reduceMotion()) return;
    root.classList.add('lw-lg-smooth');
    // 给内容主容器加入场动效（幂等，仅标记未标记的）
    var sel = ['main', '.site-main', 'main.container', '.uc-section'];
    for (var i = 0; i < sel.length; i++) {
      var nodes = document.querySelectorAll(sel[i]);
      for (var j = 0; j < nodes.length; j++) {
        if (!nodes[j].classList.contains('lw-lg-enter')) nodes[j].classList.add('lw-lg-enter');
      }
    }
  }
  function clearEntrance() {
    root.classList.remove('lw-lg-smooth');
  }

  /* ---------- 初始化 ---------- */
  function init() {
    paint();
    if (state.master && isDesktop()) ensureDock();
    applyEntrance();
    // 屏幕方向/尺寸变化时重新评估桌面/移动版
    var mq = window.matchMedia('(min-width: 900px)');
    var onMq = function () {
      root.classList.toggle('lw-dock-on', state.master && isDesktop());
      if (state.master && isDesktop()) ensureDock(); else removeDock();
    };
    if (mq.addEventListener) mq.addEventListener('change', onMq);
    else if (mq.addListener) mq.addListener(onMq);
    // 拉取后台全局参数（速度/光强/强制关闭），并匿名上报一次
    fetchRemoteConfig();
    report();
    // 流体云：多端同步个人开关/Dock 位置/通知偏好
    hydrateFromCloud();
  }

  window.LWLiquidGlass = {
    get: get, set: set, paint: paint,
    isDesktop: isDesktop, defaults: DEFAULTS,
    syncCloud: pushToCloud,
    destroy: function () { stopFluid(false); removeDock(); root.classList.remove('lw-lg-on', 'lw-dock-on'); }
  };

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else { init(); }
})();
