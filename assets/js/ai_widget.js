/**
 * 站内 AI 小助手 · 全站浮窗外壳
 * ---------------------------------------------------------------------------
 * 职责边界：
 *   本文件只负责「浮窗这个壳」——创建 DOM、开合、快捷问法、移动端抽屉定位、降级。
 *   对话逻辑（发送/渲染/链接/卡片/持久化）全在 assets/js/ai_core.js，
 *   与 pages/ai_assistant.php 的完整版共用，避免两套实现行为分叉。
 *
 * 幂等（三层，缺一不可）：
 *   1. PHP 侧 includes/ai_widget.php 用 static 标记保证同一请求只输出一次；
 *   2. 本文件首行用 window.__lwAiWidget 标记；
 *   3. DOM 侧用 #lwAiFab 是否存在兜底。
 *
 * 对 window.App 零依赖：login / register / gateway / 后台等页面没有 main.js、没有 style.css，
 * 样式全在 assets/css/ai_widget.css（自包含 + 变量兜底），逻辑全在 core（探测式降级）。
 */
(function () {
  'use strict';

  if (window.__lwAiWidget) { return; }
  window.__lwAiWidget = 1;

  var Core = window.__lwAiCore;
  if (!Core) { return; }               // core 没加载成功就不挂，避免报错刷屏
  if (document.getElementById('lwAiFab')) { return; }

  var CFG = window.__lwAiCfg || {};
  var el = {};
  var chat = null;
  var lastFocus = null;

  function build() {
    var root = document.getElementById('lwAiRoot');
    if (!root) {
      root = document.createElement('div');
      root.id = 'lwAiRoot';
      document.body.appendChild(root);
    }
    root.className = 'lw-ai';
    root.setAttribute('data-state', 'closed');

    // 触发器
    var fab = document.createElement('button');
    fab.type = 'button';
    fab.className = 'lw-ai-fab';
    fab.id = 'lwAiFab';
    fab.setAttribute('aria-label', '打开 AI 小助手');
    fab.setAttribute('aria-expanded', 'false');
    fab.setAttribute('aria-controls', 'lwAiPanel');
    fab.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"'
      + ' stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'
      + '<path d="M21 11.5a8.38 8.38 0 0 1-.9 3.8 8.5 8.5 0 0 1-7.6 4.7 8.38 8.38 0 0 1-3.8-.9L3 21l1.9-5.7a8.38 8.38 0 0 1-.9-3.8 8.5 8.5 0 0 1 4.7-7.6 8.38 8.38 0 0 1 3.8-.9h.5a8.48 8.48 0 0 1 8 8z"/>'
      + '<circle cx="9" cy="12" r="1"/><circle cx="12" cy="12" r="1"/><circle cx="15" cy="12" r="1"/>'
      + '</svg>';
    root.appendChild(fab);
    el.fab = fab;

    // 面板
    var panel = document.createElement('section');
    panel.className = 'lw-ai-panel';
    panel.id = 'lwAiPanel';
    panel.setAttribute('role', 'dialog');
    panel.setAttribute('aria-modal', 'false');
    panel.setAttribute('aria-label', 'AI 小助手');
    panel.hidden = true;

    var head = document.createElement('header');
    head.className = 'lw-ai-head';
    head.innerHTML = '<div class="lw-ai-head-id">'
      + '<span class="lw-ai-avatar" aria-hidden="true">✦</span>'
      + '<div class="lw-ai-head-text">'
      + '<strong class="lw-ai-title">校园小助手</strong>'
      + '<span class="lw-ai-sub">学习 · 生活 · 站内办事</span>'
      + '</div></div>';
    var headActions = document.createElement('div');
    headActions.className = 'lw-ai-head-actions';

    var btnExpand = document.createElement('button');
    btnExpand.type = 'button';
    btnExpand.className = 'lw-ai-iconbtn';
    btnExpand.setAttribute('data-act', 'expand');
    btnExpand.title = '在完整版里继续';
    btnExpand.setAttribute('aria-label', '在完整版里继续');
    btnExpand.innerHTML = Core.icon('expand');

    var btnClear = document.createElement('button');
    btnClear.type = 'button';
    btnClear.className = 'lw-ai-iconbtn';
    btnClear.setAttribute('data-act', 'clear');
    btnClear.title = '清空对话';
    btnClear.setAttribute('aria-label', '清空对话');
    btnClear.innerHTML = Core.icon('trash');

    var btnClose = document.createElement('button');
    btnClose.type = 'button';
    btnClose.className = 'lw-ai-iconbtn';
    btnClose.setAttribute('data-act', 'close');
    btnClose.title = '收起';
    btnClose.setAttribute('aria-label', '收起');
    btnClose.innerHTML = Core.icon('close');

    // 高级选项：接入自己的模型。游客没有「自己的 AI」，直接不给入口。
    if (CFG.loggedIn && typeof Core.openAiOptions === 'function') {
      var btnOpts = document.createElement('button');
      btnOpts.type = 'button';
      btnOpts.className = 'lw-ai-iconbtn';
      btnOpts.setAttribute('data-act', 'options');
      btnOpts.title = '高级选项：接入我自己的 AI';
      btnOpts.setAttribute('aria-label', '高级选项：接入我自己的 AI');
      btnOpts.innerHTML = Core.icon('gear');
      headActions.appendChild(btnOpts);
    }

    headActions.appendChild(btnExpand);
    headActions.appendChild(btnClear);
    headActions.appendChild(btnClose);
    head.appendChild(headActions);
    panel.appendChild(head);

    // 消息区
    var msgs = document.createElement('div');
    msgs.className = 'lw-ai-msgs';
    msgs.id = 'lwAiMsgs';
    msgs.setAttribute('role', 'log');
    msgs.setAttribute('aria-live', 'polite');

    var welcome = document.createElement('div');
    welcome.className = 'lw-ai-welcome';
    welcome.id = 'lwAiWelcome';
    var tip = document.createElement('p');
    tip.className = 'lw-ai-welcome-tip';
    tip.textContent = CFG.greeting || (CFG.loggedIn
      ? '有什么想问的、想聊的，或者想让我帮你办的，说一声。'
      : '可以问站内的公开内容，也能聊学习和生活上的事。注册登录后还能让我帮你办事。');
    welcome.appendChild(tip);
    var quick = document.createElement('div');
    quick.className = 'lw-ai-quick';
    quick.id = 'lwAiQuick';
    welcome.appendChild(quick);
    msgs.appendChild(welcome);
    panel.appendChild(msgs);

    // 输入区
    var form = document.createElement('form');
    form.className = 'lw-ai-inputbar';
    form.id = 'lwAiForm';
    var input = document.createElement('textarea');
    input.className = 'lw-ai-input';
    input.id = 'lwAiInput';
    input.rows = 1;
    input.maxLength = 2000;
    input.placeholder = '问我点什么…（Enter 发送，Shift+Enter 换行）';
    input.setAttribute('aria-label', '输入你的问题');
    var send = document.createElement('button');
    send.type = 'submit';
    send.className = 'lw-ai-send';
    send.id = 'lwAiSend';
    send.setAttribute('aria-label', '发送');
    send.innerHTML = Core.icon('send');
    form.appendChild(input);
    form.appendChild(send);
    panel.appendChild(form);

    var foot = document.createElement('div');
    foot.className = 'lw-ai-foot';
    foot.id = 'lwAiFoot';
    panel.appendChild(foot);

    root.appendChild(panel);

    el.panel = panel;
    el.msgs = msgs;
    el.form = form;
    el.input = input;
    el.send = send;
    el.foot = foot;
    el.welcome = welcome;
    el.quick = quick;
    el.root = root;
  }

  function isMobile() {
    return window.matchMedia ? window.matchMedia('(max-width: 768px)').matches : false;
  }

  function open() {
    if (!el.panel.hidden) { return; }
    lastFocus = document.activeElement;
    el.panel.hidden = false;
    el.root.setAttribute('data-state', 'open');
    el.fab.setAttribute('aria-expanded', 'true');
    el.fab.setAttribute('aria-label', '收起 AI 小助手');
    if (isMobile()) { document.body.classList.add('lw-ai-locked'); }
    setTimeout(function () { if (el.input) { el.input.focus(); } }, 60);
  }

  function close() {
    if (el.panel.hidden) { return; }
    el.panel.hidden = true;
    el.root.setAttribute('data-state', 'closed');
    el.fab.setAttribute('aria-expanded', 'false');
    el.fab.setAttribute('aria-label', '打开 AI 小助手');
    document.body.classList.remove('lw-ai-locked');
    if (lastFocus && typeof lastFocus.focus === 'function') { lastFocus.focus(); }
  }

  function toggle() {
    if (el.panel.hidden) { open(); } else { close(); }
  }

  function boot() {
    build();

    chat = Core.attach({
      msgs: el.msgs,
      form: el.form,
      input: el.input,
      send: el.send,
      foot: el.foot,
      welcome: el.welcome,
      quick: el.quick
    }, {
      mode: 'widget',
      greeting: CFG.greeting
    });

    // 快捷问法：按登录态给不同的默认值；页面可用 $aiWidgetQuick 覆盖
    var defaults = CFG.loggedIn
      ? ['站里最近有什么新帖', '我的未读通知有哪些', '帮我复习一下英语作文的写法', '最近有点学不进去，怎么办']
      : ['站里最近有什么新帖', '社区规范是什么', '怎么注册账号', '给我讲讲怎么高效背单词'];
    chat.setQuick(CFG.quick && CFG.quick.length ? CFG.quick : defaults);

    chat.restore();

    el.fab.addEventListener('click', toggle);

    el.panel.addEventListener('click', function (e) {
      var btn = e.target.closest ? e.target.closest('[data-act]') : null;
      if (!btn) { return; }
      var act = btn.getAttribute('data-act');
      if (act === 'close') { close(); }
      else if (act === 'clear') { chat.clear(); }
      else if (act === 'options') { Core.openAiOptions(); }
      else if (act === 'expand') {
        // 带历史跳到完整版：两处共用同一个 sessionStorage key，过去会自动恢复
        window.location.href = Core.siteUrl() + '/pages/ai_assistant.php';
      }
    });

    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape' && !el.panel.hidden) { close(); }
    });

    // 桌面/手机切换时清掉抽屉态的滚动锁，避免卡住页面
    if (window.matchMedia) {
      var mq = window.matchMedia('(max-width: 768px)');
      var onChange = function () {
        if (!mq.matches) { document.body.classList.remove('lw-ai-locked'); }
      };
      if (mq.addEventListener) { mq.addEventListener('change', onChange); }
      else if (mq.addListener) { mq.addListener(onChange); }
    }

    // 游客态：底部常驻一条温和的说明，而不是每次弹窗打断
    if (!CFG.loggedIn) {
      var notice = document.createElement('div');
      notice.className = 'lw-ai-foottext';
      notice.textContent = '游客模式：可以问公开内容；注册后能让我帮你办事。';
      el.foot.appendChild(notice);
    }
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot, { once: true });
  } else {
    boot();
  }
})();
