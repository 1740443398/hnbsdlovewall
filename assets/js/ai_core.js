/**
 * 站内 AI 小助手 · 共享聊天核心
 * ---------------------------------------------------------------------------
 * 被两处复用，避免「浮窗」与「完整版页面」各写一套而行为分叉：
 *   1. assets/js/ai_widget.js   —— 全站右下角浮窗
 *   2. pages/ai_assistant.php   —— 完整版页面
 *
 * 设计约束（重要）：
 *   - **对 window.App 零强依赖**。站点里 login / register / gateway / 后台 等页面不加载
 *     main.js，也没有 style.css。所以 showToast / escapeHtml / svgIcon / fetchAPI 全部走
 *     「有就用、没有就降级」的探测式取用。
 *   - 只做渲染与请求，不做任何权限判断。**服务端才是唯一真源**：提示词、取数、
 *     操作白名单、卡片参数都在 PHP 侧；前端拿不到参数，只有一个不透明 token。
 *   - 所有模型输出一律先转义再渲染；链接只允许站内白名单路径。
 */
(function (global) {
  'use strict';

  if (global.__lwAiCore) { return; }

  var App = global.App || {};

  // ---------------------------------------------------------------------------
  // 配置与工具
  // ---------------------------------------------------------------------------
  var CFG = global.__lwAiCfg || {};

  function siteUrl() {
    return CFG.siteUrl || global.SITE_URL || '';
  }

  /** CSRF 令牌：优先用服务端直供的，其次兼容页面的顶层 const，最后 window 上的显式赋值 */
  function csrfToken() {
    if (CFG.csrf) { return CFG.csrf; }
    try {
      if (typeof CSRF_TOKEN !== 'undefined' && CSRF_TOKEN) { return CSRF_TOKEN; }
    } catch (e) { /* 未声明，忽略 */ }
    return global.CSRF_TOKEN || '';
  }

  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }

  function escAttr(s) {
    return esc(s).replace(/`/g, '&#96;');
  }

  function icon(name) {
    var paths = {
      close: '<line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/>',
      trash: '<polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/><path d="M10 11v6M14 11v6"/><path d="M9 6V4a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v2"/>',
      expand: '<path d="M15 3h6v6"/><path d="M9 21H3v-6"/><path d="M21 3l-7 7"/><path d="M3 21l7-7"/>',
      send: '<line x1="22" y1="2" x2="11" y2="13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/>',
      link: '<path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/><polyline points="15 3 21 3 21 9"/><line x1="10" y1="14" x2="21" y2="3"/>',
      gear: '<circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 2.83-2.83l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/>'
    };
    // 顺序很重要：先用自己的表，再去问站点的 App.svgIcon。
    // 反过来的话，App 的图标集里没有 expand / send / close / gear 这几个键，
    // 会返回空串 —— 表现为浮窗头部三个按钮和发送按钮**全都是空白的**。
    if (paths[name]) {
      var c2 = name === 'link' ? 'lw-ai-link-icon' : '';
      return '<svg class="' + c2 + '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"'
        + ' stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' + paths[name] + '</svg>';
    }
    if (typeof App.svgIcon === 'function') {
      return App.svgIcon(name);
    }
    var cls = name === 'link' ? 'lw-ai-link-icon' : '';
    return '<svg class="' + cls + '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"'
      + ' stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' + (paths[name] || '') + '</svg>';
  }

  /**
   * 站内链接白名单校验：只允许同源相对路径。
   * 明确拒绝 javascript: / data: / vbscript: / blob: / mailto: / tel: / file: 与 http(s) 外链，
   * 以及协议相对地址 //evil.com、含反斜杠或控制字符的地址、解码后出现 // 的地址。
   */
  function safeHref(raw) {
    if (typeof raw !== 'string') { return null; }
    var href = raw.trim();
    if (href === '' || href.length > 300) { return null; }
    if (/[\s\u0000-\u001f]/.test(href)) { return null; }
    if (href.indexOf('\\') !== -1) { return null; }
    if (href.charAt(0) !== '/') { return null; }       // 挡掉所有带协议的地址
    if (href.charAt(1) === '/') { return null; }       // 挡掉 //evil.com
    var decoded;
    try { decoded = decodeURIComponent(href); } catch (e) { return null; }
    if (/[\s\u0000-\u001f\\]/.test(decoded)) { return null; }
    if (decoded.indexOf('//') !== -1) { return null; }
    if (/^\/+[^/]*:/.test(decoded)) { return null; }
    var okPrefix = /^\/(pages\/|download\/|zanzhu\/|assets\/|index\.php)/;
    if (decoded !== '/' && !okPrefix.test(decoded)) { return null; }
    // 双保险：解析后必须仍是同源
    try {
      if (new URL(decoded, global.location.origin).origin !== global.location.origin) { return null; }
    } catch (e) { return null; }
    return decoded;
  }

  /**
   * 把一段纯文本渲染成安全 HTML：
   *   - [文字](/站内路径) → 可点的站内链接（带小箭头图标）
   *   - 本站绝对地址 → 降级成站内链接
   *   - 其他 http(s) 链接 → 不可点文本 + 「外部」徽标（站点内容可能被注入外链，AI 不该当跳板）
   *   - 其余文本一律转义
   * 顺序很重要：不能「先整体转义再正则替换」，否则 URL 里的 & 会被二次转义。
   */
  function renderText(text) {
    var s = String(text == null ? '' : text);
    // 兜底：万一有 <lw-action> 残留（服务端已剥离），前端再剥一层
    s = s.replace(/<lw-action>[\s\S]*?<\/lw-action>/g, '');
    var out = '';
    var i = 0;
    var re = /\[([^\]\n]{1,120})\]\(([^)\s]{1,300})\)|(https?:\/\/[^\s<>"'）)】\]]+)/g;
    var m;
    while ((m = re.exec(s)) !== null) {
      out += esc(s.slice(i, m.index));
      if (m[1] !== undefined) {
        var href = safeHref(m[2]);
        out += href ? linkHtml(href, m[1]) : esc(m[0]);
      } else {
        var abs = m[3];
        var origin = global.location.origin;
        if (abs.indexOf(origin) === 0) {
          var rel = safeHref(abs.slice(origin.length) || '/');
          out += rel ? linkHtml(rel, rel) : esc(abs);
        } else {
          out += '<span class="lw-ai-ext" title="外部站点，请谨慎访问">' + esc(abs)
            + '<span class="lw-ai-ext-badge">外部</span></span>';
        }
      }
      i = re.lastIndex;
    }
    out += esc(s.slice(i));
    return out;
  }

  /**
   * 流式期间 <lw-action> 块是一个字符一个字符吐出来的，
   * 直接显示会闪出原始标签。这里剥掉已完整的块，再把末尾半截开标签掐掉。
   */
  function stripActionPartial(s) {
    s = s.replace(/<lw-action>[\s\S]*?<\/lw-action>/g, '');
    var i = s.indexOf('<lw-action');
    if (i !== -1) { s = s.slice(0, i); }
    // 末尾可能是 '<' / '<l' / '<lw'… 这种半截开标签，一并掐掉
    var lt = s.lastIndexOf('<');
    if (lt !== -1 && lt > s.length - 12) {
      var tail = s.slice(lt);
      if ('<lw-action>'.indexOf(tail) === 0 || '</lw-action>'.indexOf(tail) === 0) {
        s = s.slice(0, lt);
      }
    }
    return s;
  }

  function linkHtml(href, label) {
    // 非管理员不给后台链接（服务端同样会挡，这里只是别把入口画出来）
    var isAdmin = CFG.role === 'admin' || CFG.role === 'super_admin';
    if (href.indexOf('/admin/') === 0 && !isAdmin) { return esc(label); }
    return '<a class="lw-ai-link" href="' + escAttr(href) + '" title="站内页面"'
      + ' data-lw-nav="1">' + esc(label) + icon('link') + '</a>';
  }

  // ---------------------------------------------------------------------------
  // 会话持久化（sessionStorage：同标签页跨页保留，新标签页为空）
  // ---------------------------------------------------------------------------
  var STORE_KEY = 'lw_ai_hist_v1';
  var MAX_MSGS = 40;
  var MAX_CHARS = 12000;
  var MAX_ONE = 4000;

  function loadHistory() {
    try {
      var raw = global.sessionStorage.getItem(STORE_KEY);
      if (!raw) { return { messages: [], draft: '' }; }
      var obj = JSON.parse(raw);
      if (!obj || !Array.isArray(obj.messages)) { return { messages: [], draft: '' }; }
      return {
        messages: obj.messages.filter(function (m) {
          return m && (m.role === 'user' || m.role === 'assistant') && typeof m.content === 'string';
        }),
        draft: typeof obj.draft === 'string' ? obj.draft : ''
      };
    } catch (e) {
      return { messages: [], draft: '' }; // 隐私模式等写入受限时静默降级
    }
  }

  function saveHistory(messages, draft) {
    try {
      global.sessionStorage.setItem(STORE_KEY, JSON.stringify({
        v: 1,
        ts: Date.now(),
        messages: messages.slice(-MAX_MSGS),
        draft: draft || ''
      }));
    } catch (e) { /* 静默降级 */ }
  }

  function clearHistory() {
    try { global.sessionStorage.removeItem(STORE_KEY); } catch (e) { /* 忽略 */ }
  }

  /**
   * 三层裁剪，避免把上下文撑爆：
   *   ① 条数上限 40；
   *   ② 累计超 12000 字时从最旧开始**成对丢弃**，保持首条必为 user（否则模型看到孤立的 assistant 回复）；
   *   ③ 单条超 4000 字直接丢弃——服务端硬上限就是 4000，截断会让模型看到半句话，比丢掉更糟。
   */
  function trimMessages(messages) {
    var list = messages.filter(function (m) {
      return m && typeof m.content === 'string' && m.content.length <= MAX_ONE;
    });
    if (list.length > MAX_MSGS) {
      list = list.slice(-MAX_MSGS);
    }
    var total = 0;
    var cut = 0;
    for (var i = list.length - 1; i >= 0; i--) {
      total += list[i].content.length;
      if (total > MAX_CHARS) { cut = i + 1; break; }
    }
    if (cut > 0) {
      list = list.slice(cut);
    }
    if (list.length && list[0].role === 'assistant') {
      list = list.slice(1);
    }
    return list;
  }

  // ---------------------------------------------------------------------------
  // 请求
  // ---------------------------------------------------------------------------
  function rawFetch(url, options) {
    return fetch(url, options);
  }

  /**
   * 需要原始 Response 的请求（要读 res.headers / res.body）一律走这里。
   *
   * 注意：不能用 App.fetchAPI —— 它返回的是**解析后的数据对象**，
   * 拿它去 res.json() / res.headers.get() 会直接抛 TypeError，
   * 表现为「确认卡片点了没反应」这类诡异问题。所以这里走 App.fetchRaw
   * （返回 Response，且已在内部处理主机挑战页），取不到再退回裸 fetch。
   */
  function respFetch(path, options) {
    var opts = options || {};
    if (typeof App !== 'undefined' && typeof App.fetchRaw === 'function') {
      return App.fetchRaw(path, opts);   // 内部会拼 SITE_URL，这里传以 / 开头的路径
    }
    return rawFetch(siteUrl() + path, opts);
  }

  function postJson(path, body) {
    return respFetch(path, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'Accept': 'application/json',
        'X-Requested-With': 'XMLHttpRequest'
      },
      credentials: 'same-origin',
      body: JSON.stringify(body)
    });
  }

  // ---------------------------------------------------------------------------
  // SSE（流式）读取
  // ---------------------------------------------------------------------------
  /** 是否能用 fetch 流式读取。老 Android System WebView 没有 ReadableStream。 */
  function canStream() {
    return typeof global.ReadableStream === 'function'
      && typeof global.TextDecoder === 'function';
  }

  /** 解析一帧 SSE（event: xxx\ndata: {...}） */
  function parseSseFrame(frame, onEvent) {
    var event = 'message';
    var data = '';
    frame.split('\n').forEach(function (line) {
      if (line.indexOf('event:') === 0) {
        event = line.slice(6).trim();
      } else if (line.indexOf('data:') === 0) {
        data += line.slice(5).trim();
      }
    });
    if (data === '') { return; }
    var payload = null;
    try { payload = JSON.parse(data); } catch (e) { return; }
    onEvent(event, payload);
  }

  /**
   * 边收边解析。SSE 以「空行」分帧，而 chunk 边界不一定落在帧边界上，
   * 所以没凑齐一帧的部分要留在 buf 里等下一块。
   */
  function readSse(res, onEvent) {
    var reader = res.body.getReader();
    var decoder = new global.TextDecoder('utf-8');
    var buf = '';

    function pump() {
      return reader.read().then(function (r) {
        if (r.done) {
          if (buf.trim() !== '') { parseSseFrame(buf, onEvent); }
          return;
        }
        buf += decoder.decode(r.value, { stream: true });
        var idx;
        while ((idx = buf.indexOf('\n\n')) !== -1) {
          var frame = buf.slice(0, idx);
          buf = buf.slice(idx + 2);
          parseSseFrame(frame, onEvent);
        }
        return pump();
      });
    }
    return pump();
  }

  // ---------------------------------------------------------------------------
  // 高级选项：接入自己的 AI
  // ---------------------------------------------------------------------------
  /**
   * 让用户把自己的模型接进小助手。
   *
   * 设计取舍：
   *   - 密钥只在「用户敲进输入框 → 提交」这一瞬离开本机，服务端存密文、
   *     回读时只给末 4 位，前端永远拿不到明文（读回来的是占位符，不是真 Key）。
   *   - 「一键还原」单独一个按钮，且要先确认：这是防误操作的最后一道闸。
   *   - 未登录不给入口 —— 游客没有「自己的 AI」这回事，给了也存不下。
   */
  var optsState = { loaded: false, cfg: null, providers: null };

  /** 面板外的提示（模块级版本；面板内的那个在 attach() 里，够不着） */
  function notify(msg) {
    if (typeof App !== 'undefined' && typeof App.showToast === 'function') {
      App.showToast(msg);
      return;
    }
    try { global.alert(msg); } catch (e) { /* 无弹窗能力时静默 */ }
  }

  function postForm(body) {
    var fd = new FormData();
    Object.keys(body).forEach(function (k) { fd.append(k, body[k]); });
    fd.append('csrf_token', csrfToken());
    return respFetch('/api/user/ai_options.php', {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'X-Requested-With': 'XMLHttpRequest' },
      body: fd
    }).then(function (res) {
      return res.json().catch(function () { return null; }).then(function (d) {
        return { status: res.status, data: d };
      });
    });
  }

  function mk(tag, cls, text) {
    var n = document.createElement(tag);
    if (cls) { n.className = cls; }
    if (text !== undefined && text !== null) { n.textContent = text; }
    return n;
  }

  function openAiOptions() {
    if (!CFG.loggedIn) {
      notify('登录后才能接入自己的 AI');
      return;
    }
    var overlay = document.querySelector('.lw-ai-opts');
    if (overlay) { overlay.parentNode.removeChild(overlay); return; }

    var wrap = mk('div', 'lw-ai-opts');
    var box = mk('div', 'lw-ai-opts-box');

    var head = mk('div', 'lw-ai-opts-head');
    head.appendChild(mk('strong', null, '高级选项 · 接入我自己的 AI'));
    var close = mk('button', 'lw-ai-opts-x', '✕');
    close.type = 'button';
    close.title = '关闭';
    head.appendChild(close);
    box.appendChild(head);

    var note = mk('p', 'lw-ai-opts-note');
    note.textContent = '填了你自己的模型之后，小助手就改用你的额度回答，站点不收费。'
      + '密钥只提交到本站服务器并加密保存，回读时只显示末四位。';
    box.appendChild(note);

    var status = mk('div', 'lw-ai-opts-status');
    box.appendChild(status);

    function field(labelText, control, hintText) {
      var row = mk('label', 'lw-ai-opts-row');
      row.appendChild(mk('span', 'lw-ai-opts-label', labelText));
      row.appendChild(control);
      if (hintText) { row.appendChild(mk('span', 'lw-ai-opts-hint', hintText)); }
      box.appendChild(row);
      return row;
    }

    var sel = mk('select', 'lw-ai-opts-input');
    var urlInput = mk('input', 'lw-ai-opts-input');
    urlInput.type = 'text';
    urlInput.placeholder = 'https://…/chat/completions';
    var modelInput = mk('input', 'lw-ai-opts-input');
    modelInput.type = 'text';
    modelInput.placeholder = '例如 glm-4.1v-thinking-flash';
    var keyInput = mk('input', 'lw-ai-opts-input');
    keyInput.type = 'password';
    keyInput.placeholder = '留空表示不修改已保存的密钥';
    keyInput.autocomplete = 'new-password';
    var enableWrap = mk('span', 'lw-ai-opts-check');
    var enableBox = mk('input');
    enableBox.type = 'checkbox';
    enableBox.id = 'lwAiOptsEnabled';
    enableWrap.appendChild(enableBox);
    enableWrap.appendChild(mk('span', null, ' 启用我自己的模型'));

    field('服务商', sel);
    field('接口地址', urlInput, '必须是 https 的公网地址，不能是 IP 或内网地址');
    field('模型名', modelInput);
    field('API Key', keyInput);
    field('', enableWrap);

    var btns = mk('div', 'lw-ai-opts-btns');
    var bTest = mk('button', 'lw-ai-opts-btn', '测试连接');
    var bSave = mk('button', 'lw-ai-opts-btn lw-ai-opts-btn--primary', '保存');
    var bReset = mk('button', 'lw-ai-opts-btn lw-ai-opts-btn--ghost', '一键还原');
    [bTest, bSave, bReset].forEach(function (b) { b.type = 'button'; btns.appendChild(b); });
    box.appendChild(btns);

    var lastErr = mk('div', 'lw-ai-opts-err');
    box.appendChild(lastErr);

    wrap.appendChild(box);
    document.body.appendChild(wrap);

    function setStatus(msg, kind) {
      status.textContent = msg || '';
      status.className = 'lw-ai-opts-status' + (kind ? ' is-' + kind : '');
    }

    function busy(on) {
      [bTest, bSave, bReset].forEach(function (b) { b.disabled = !!on; });
    }

    function closeIt() {
      if (wrap.parentNode) { wrap.parentNode.removeChild(wrap); }
    }

    close.addEventListener('click', closeIt);
    wrap.addEventListener('click', function (e) {
      if (e.target === wrap) { closeIt(); }
    });

    sel.addEventListener('change', function () {
      var p = (optsState.providers || {})[sel.value];
      if (p && sel.value !== 'custom') {
        urlInput.value = p.base_url || '';
        if (!modelInput.value || modelInput.value === p.model) { modelInput.value = p.model || ''; }
      }
      var hint = el2Hint(p);
      setStatus(hint, '');
    });

    function el2Hint(p) {
      if (!p) { return ''; }
      var s = '';
      if (p.hint) { s += p.hint; }
      if (p.model) { s += (s ? ' · 默认模型：' : '默认模型：') + p.model; }
      return s;
    }

    bSave.addEventListener('click', function () {
      setStatus('保存中…', '');
      busy(true);
      postForm({
        action: 'save',
        provider: sel.value,
        base_url: urlInput.value.trim(),
        model: modelInput.value.trim(),
        api_key: keyInput.value.trim(),
        enabled: enableBox.checked ? '1' : '0'
      }).then(function (r) {
        busy(false);
        if (r.data && r.data.success === true) {
          keyInput.value = '';
          optsState.cfg = r.data.data.config;
          renderCfg(optsState.cfg);
          setStatus('已保存' + (enableBox.checked ? '，之后用你自己的模型回答' : '（已停用，仍用站内模型）'), 'ok');
        } else {
          setStatus((r.data && r.data.message) || '保存失败', 'bad');
        }
      }).catch(function () { busy(false); setStatus('网络异常，请稍后重试', 'bad'); });
    });

    bTest.addEventListener('click', function () {
      setStatus('测试中…', '');
      busy(true);
      postForm({
        action: 'test',
        provider: sel.value,
        base_url: urlInput.value.trim(),
        model: modelInput.value.trim(),
        api_key: keyInput.value.trim()
      }).then(function (r) {
        busy(false);
        if (r.data && r.data.success === true) {
          setStatus((r.data.message || '连接成功'), 'ok');
        } else {
          setStatus((r.data && r.data.message) || '连接失败', 'bad');
        }
      }).catch(function () { busy(false); setStatus('网络异常，请稍后重试', 'bad'); });
    });

    bReset.addEventListener('click', function () {
      // 一键还原是破坏性的：清空别人的自定义配置只需要一秒，重建却要重新找 Key，
      // 所以这里必须二次确认，不能顺手一点就没了。
      if (!global.confirm('确定还原吗？这会删除你填写的自定义模型配置，之后改用站内默认模型。')) {
        return;
      }
      setStatus('还原中…', '');
      busy(true);
      postForm({ action: 'restore' }).then(function (r) {
        busy(false);
        if (r.data && r.data.success === true) {
          optsState.cfg = { has: false, enabled: false };
          renderCfg(optsState.cfg);
          setStatus('已还原为站内默认模型', 'ok');
        } else {
          setStatus((r.data && r.data.message) || '还原失败', 'bad');
        }
      }).catch(function () { busy(false); setStatus('网络异常，请稍后重试', 'bad'); });
    });

    function renderCfg(cfg) {
      cfg = cfg || {};
      var has = !!cfg.has;
      sel.value = cfg.provider || 'zhipu';
      urlInput.value = cfg.base_url || '';
      modelInput.value = cfg.model || '';
      keyInput.value = '';
      keyInput.placeholder = has && cfg.key_hint
        ? '已保存（' + cfg.key_hint + '），留空表示不修改'
        : '粘贴你的 API Key';
      enableBox.checked = !!cfg.enabled;
      if (has && cfg.last_error) {
        lastErr.textContent = '上次调用失败：' + cfg.last_error;
        lastErr.style.display = '';
      } else {
        lastErr.style.display = 'none';
      }
      setStatus(has ? (cfg.enabled ? '正在使用你自己的模型' : '已保存但已停用，当前用站内模型') : '当前使用站内默认模型', '');
    }

    function load() {
      setStatus('读取中…', '');
      respFetch('/api/user/ai_options.php', { credentials: 'same-origin' })
        .then(function (res) { return res.json(); })
        .then(function (d) {
          if (!d || d.success !== true) {
            setStatus((d && d.message) || '读取失败', 'bad');
            return;
          }
          var data = d.data || {};
          optsState.providers = data.providers || {};
          optsState.cfg = data.config;
          optsState.loaded = true;
          Object.keys(optsState.providers).forEach(function (k) {
            var o = document.createElement('option');
            o.value = k;
            o.textContent = optsState.providers[k].label || k;
            sel.appendChild(o);
          });
          renderCfg(data.config);
        })
        .catch(function () { setStatus('网络异常，请稍后重试', 'bad'); });
    }

    if (optsState.loaded) {
      if (!sel.options.length) {
        Object.keys(optsState.providers || {}).forEach(function (k) {
          var o = document.createElement('option');
          o.value = k;
          o.textContent = optsState.providers[k].label || k;
          sel.appendChild(o);
        });
      }
      renderCfg(optsState.cfg);
    } else {
      load();
    }
  }

  // ---------------------------------------------------------------------------
  // 聊天实例
  // ---------------------------------------------------------------------------
  /**
   * @param {Object} ui  必须提供：msgs, form, input, send, foot（可选 welcome）
   * @param {Object} opt { mode: 'widget'|'page', greeting: string, quick: string[] }
   */
  function attach(ui, opt) {
    opt = opt || {};
    var mode = opt.mode || 'widget';
    var state = loadHistory();
    var messages = state.messages;
    var busy = false;
    var lastCard = null;   // 只允许最后一条回复里的卡片可点，更早的置灰

    var el = {
      msgs: ui.msgs,
      form: ui.form,
      input: ui.input,
      send: ui.send,
      foot: ui.foot,
      welcome: ui.welcome || null,
      quick: ui.quick || null
    };

    function scrollBottom() {
      if (el.msgs) { el.msgs.scrollTop = el.msgs.scrollHeight; }
    }

    function hideWelcome() {
      if (el.welcome && el.welcome.parentNode) { el.welcome.style.display = 'none'; }
    }

    /** 顶部/底部提示。站点有 showToast 就用站点样式，否则在面板底部给一条内联提示 */
    function notify(msg) {
      if (typeof App.showToast === 'function') {
        App.showToast(msg);
        return;
      }
      if (!el.foot) { return; }
      var tip = document.createElement('div');
      tip.className = 'lw-ai-inline-tip';
      tip.textContent = msg;
      el.foot.appendChild(tip);
      setTimeout(function () {
        if (tip.parentNode) { tip.parentNode.removeChild(tip); }
      }, 4000);
    }

    function appendUser(text) {
      hideWelcome();
      var row = document.createElement('div');
      row.className = 'lw-ai-msg lw-ai-msg--user';
      var bubble = document.createElement('div');
      bubble.className = 'lw-ai-bubble';
      bubble.textContent = text;
      row.appendChild(bubble);
      el.msgs.appendChild(row);
      scrollBottom();
      return row;
    }

    function appendAi(html) {
      hideWelcome();
      var row = document.createElement('div');
      row.className = 'lw-ai-msg lw-ai-msg--ai';
      var bubble = document.createElement('div');
      bubble.className = 'lw-ai-bubble';
      bubble.innerHTML = html;   // 已由 renderText 转义 + 白名单链接
      row.appendChild(bubble);
      el.msgs.appendChild(row);
      scrollBottom();
      return row;
    }

    function appendSources(sources) {
      if (!sources || !sources.length) { return; }
      var box = document.createElement('div');
      box.className = 'lw-ai-src';
      var label = document.createElement('span');
      label.textContent = '已读取站点数据：';
      box.appendChild(label);
      sources.forEach(function (s) {
        var chip = document.createElement('span');
        chip.className = 'lw-ai-srcchip';
        chip.textContent = s;
        box.appendChild(chip);
      });
      el.msgs.appendChild(box);
      scrollBottom();
    }

    /**
     * 思考过程区。流式时返回一个句柄，可以边收边往里追加文字；
     * 收尾后（或一次性返回时）把它收起，避免长篇思考占满对话区。
     */
    function openThinking() {
      var row = document.createElement('div');
      row.className = 'lw-ai-msg lw-ai-msg--ai';
      var details = document.createElement('details');
      details.className = 'lw-ai-think';
      details.open = true;                 // 流式期间保持展开，让用户看见它在动
      details.setAttribute('data-streaming', '1');
      var summary = document.createElement('summary');
      summary.textContent = '思考中…';
      var body = document.createElement('div');
      body.className = 'lw-ai-think-body';
      details.appendChild(summary);
      details.appendChild(body);
      row.appendChild(details);
      el.msgs.appendChild(row);
      scrollBottom();

      return {
        text: '',
        push: function (t) {
          this.text += t;
          body.textContent = this.text;
          body.scrollTop = body.scrollHeight;   // 思考区自身有滚动上限，跟着最新内容走
          scrollBottom();
        },
        /** 用服务端最终版覆盖（可能被截断到 4000 字），并保持收起 */
        setFinal: function (t) {
          this.text = t || '';
          body.textContent = this.text;
        },
        finish: function () {
          details.removeAttribute('data-streaming');
          if (this.text === '') {
            if (row.parentNode) { row.parentNode.removeChild(row); }
            return;
          }
          summary.textContent = '思考过程（点击展开）';
          details.open = false;
        }
      };
    }

    /** 一次性渲染思考过程（非流式路径 / 降级路径用） */
    function appendThinking(reasoning) {
      var t = openThinking();
      t.setFinal(reasoning);
      t.finish();
    }

    /**
     * 正文气泡。流式期间按纯文本逐字追加（快、安全），
     * 收到 done 帧后再用 renderText 整体重绘一次，把链接/Markdown 补全。
     */
    function openReply() {
      hideWelcome();
      var row = document.createElement('div');
      row.className = 'lw-ai-msg lw-ai-msg--ai';
      var bubble = document.createElement('div');
      bubble.className = 'lw-ai-bubble';
      row.appendChild(bubble);
      el.msgs.appendChild(row);
      scrollBottom();
      return {
        row: row,
        bubble: bubble,
        text: '',
        push: function (t) {
          this.text += t;
          bubble.classList.add('is-streaming');
          bubble.textContent = stripActionPartial(this.text);
          scrollBottom();
        },
        setHtml: function (html) {
          bubble.classList.remove('is-streaming');
          bubble.innerHTML = html;
          scrollBottom();
        },
        remove: function () { if (row.parentNode) { row.parentNode.removeChild(row); } }
      };
    }

    /**
     * 渲染确认卡片。**绝不在渲染时执行任何操作**——必须用户点「确认执行」。
     * 只渲染服务端给回的展示文案，参数在前端根本不存在。
     */
    function appendCard(action, isLatest) {
      var card = document.createElement('div');
      card.className = 'lw-ai-card';
      card.setAttribute('data-tone', action.tone || 'primary');
      card.setAttribute('data-state', isLatest ? 'pending' : 'expired');
      card.setAttribute('data-token', action.token || '');

      var head = document.createElement('div');
      head.className = 'lw-ai-card-head';
      head.textContent = action.tone === 'danger' ? '⚠ 这一步不可撤销，确认一下' : '要我帮你做这件事吗？';
      card.appendChild(head);

      var body = document.createElement('div');
      body.className = 'lw-ai-card-body';
      (action.lines || []).forEach(function (line) {
        var row = document.createElement('div');
        row.className = 'lw-ai-card-line';
        var k = document.createElement('span');
        k.className = 'lw-ai-k';
        k.textContent = line.k || '';
        var v = document.createElement('span');
        v.className = 'lw-ai-v';
        v.textContent = line.v || '';
        row.appendChild(k);
        row.appendChild(v);
        body.appendChild(row);
      });
      card.appendChild(body);

      if (isLatest) {
        var actions = document.createElement('div');
        actions.className = 'lw-ai-card-actions';

        var cancel = document.createElement('button');
        cancel.type = 'button';
        cancel.className = 'lw-ai-btn lw-ai-btn--ghost';
        cancel.setAttribute('data-card-act', 'cancel');
        cancel.textContent = '先不用';

        var confirm = document.createElement('button');
        confirm.type = 'button';
        confirm.className = 'lw-ai-btn ' + (action.tone === 'danger' ? 'lw-ai-btn--danger' : 'lw-ai-btn--primary');
        confirm.setAttribute('data-card-act', 'confirm');
        confirm.textContent = action.irreversible ? '确认执行（不可撤销）' : '确认执行';

        actions.appendChild(cancel);
        actions.appendChild(confirm);
        card.appendChild(actions);

        var foot = document.createElement('div');
        foot.className = 'lw-ai-card-foot';
        foot.textContent = '执行前服务端会再校验一次权限；这个按钮只在本次打开期间有效。';
        card.appendChild(foot);
      } else {
        var st = document.createElement('div');
        st.className = 'lw-ai-card-status';
        st.textContent = '（这张卡片已过期，请让小助手重新生成）';
        card.appendChild(st);
      }

      el.msgs.appendChild(card);
      scrollBottom();
      return card;
    }

    function markCard(card, stateName, text) {
      card.setAttribute('data-state', stateName);
      var actions = card.querySelector('.lw-ai-card-actions');
      if (actions && actions.parentNode) { actions.parentNode.removeChild(actions); }
      var foot = card.querySelector('.lw-ai-card-foot');
      if (foot && foot.parentNode) { foot.parentNode.removeChild(foot); }
      var st = document.createElement('div');
      st.className = 'lw-ai-card-status';
      st.textContent = text;
      card.appendChild(st);
      scrollBottom();
    }

    /** 点「确认执行」→ 凭 token 兑换执行 */
    function runCard(card, btn) {
      var token = card.getAttribute('data-token') || '';
      if (!token) {
        markCard(card, 'failed', '这张卡片已失效，请让小助手重新生成');
        return;
      }
      btn.disabled = true;
      btn.classList.add('is-busy');
      btn.textContent = '执行中…';

      var fd = new FormData();
      fd.append('token', token);
      fd.append('csrf_token', csrfToken());

      respFetch('/api/ai_action.php', {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'X-Requested-With': 'XMLHttpRequest' },
        body: fd
      }).then(function (res) {
        return res.json().catch(function () { return null; }).then(function (data) {
          return { status: res.status, data: data };
        });
      }).then(function (r) {
        if (r.status === 200 && r.data && r.data.success === true) {
          markCard(card, 'done', '✓ ' + ((r.data && r.data.message) || '已完成'));
          return;
        }
        var msg = (r.data && r.data.message) || '执行失败，请稍后重试';
        if (r.status === 401) {
          markCard(card, 'failed', msg);
          showGate();
          return;
        }
        if (r.status === 410) {
          markCard(card, 'failed', msg);
          return;
        }
        markCard(card, 'failed', msg);
      }).catch(function () {
        markCard(card, 'failed', '网络异常，请稍后重试');
      });
    }

    /** 面板内的登录引导条（不再粗暴跳转登录页） */
    function showGate() {
      if (!el.foot || el.foot.querySelector('.lw-ai-gate')) { return; }
      var gate = document.createElement('div');
      gate.className = 'lw-ai-gate';
      gate.innerHTML = '这条需要登录后才能用 <a href="' + escAttr(siteUrl() + '/pages/login.php')
        + '">去登录</a> · <a href="' + escAttr(siteUrl() + '/pages/register.php') + '">去注册</a>';
      el.foot.appendChild(gate);
    }

    function showTyping() {
      var row = document.createElement('div');
      row.className = 'lw-ai-msg lw-ai-msg--ai';
      row.setAttribute('data-lw-typing', '1');
      row.innerHTML = '<div class="lw-ai-typing"><span class="lw-ai-dot"></span>'
        + '<span class="lw-ai-dot"></span><span class="lw-ai-dot"></span></div>';
      el.msgs.appendChild(row);
      scrollBottom();
    }

    function removeTyping() {
      var t = el.msgs.querySelector('[data-lw-typing]');
      if (t && t.parentNode) { t.parentNode.removeChild(t); }
    }

    function autoGrow() {
      if (!el.input) { return; }
      el.input.style.height = 'auto';
      el.input.style.height = Math.min(el.input.scrollHeight, 130) + 'px';
    }

    /**
     * 一次性结果的渲染（非流式端点 / 流式降级后共用）。
     * 流式路径收到 done 帧后也走这里，只是正文气泡已经存在，由调用方避免重复创建。
     */
    function finishWith(d, bubble) {
      var reply = d.reply || '（没有返回内容，换个问法试试）';
      messages.push({ role: 'assistant', content: reply });
      saveHistory(messages, '');
      if (bubble) {
        bubble.setHtml(renderText(reply));
      } else {
        appendAi(renderText(reply));
      }
      if (d.action && d.action.token) {
        appendCard(d.action, true);
        lastCard = d.action.token;
      }
      if (d.notice) { setFoot(d.notice); }
      return reply;
    }

    function showGateMsg() {
      removeTyping();
      // 不再跳登录页打断对话，改为面板内引导
      appendAi(renderText('登录状态已失效。请重新登录后继续，我也能帮你办事。'));
      showGate();
    }

    /** 降级：老 WebView 不支持 fetch 流 / 流式端点异常时，退回一次性 JSON 端点 */
    function fallbackOnce(payload) {
      return postJson('/api/ai_assistant.php', payload).then(function (res) {
        return res.json().catch(function () { return null; }).then(function (data) {
          return { status: res.status, data: data };
        });
      }).then(function (r) {
        removeTyping();
        if (r.status === 401) {
          showGateMsg();
          return;
        }
        if (r.data && r.data.success === true && r.data.data) {
          var d = r.data.data;
          if (d.reasoning) { appendThinking(d.reasoning); }
          finishWith(d, null);
          appendSources(d.sources);
        } else {
          appendAi(renderText((r.data && r.data.message) || 'AI 服务暂时不可用，请稍后再试'));
        }
      });
    }

    /** 流式消费：open → delta(reasoning/content) → done / error */
    function streamChat(res, payload) {
      var think = null;
      var thinkDone = false;
      var bubble = null;
      var finalData = null;
      var fatal = null;
      var gotAny = false;
      var sourcesShown = false;

      return readSse(res, function (event, data) {
        if (event === 'open') {
          removeTyping();
          if (data && data.sources && data.sources.length) {
            appendSources(data.sources);
            sourcesShown = true;
          }
          return;
        }
        if (event === 'delta') {
          gotAny = true;
          if (data && data.type === 'reasoning') {
            if (!think) { think = openThinking(); }
            think.push(data.text || '');
          } else {
            if (think && !thinkDone) { think.finish(); thinkDone = true; }
            if (!bubble) { bubble = openReply(); }
            bubble.push(data.text || '');
          }
          return;
        }
        if (event === 'done') {
          finalData = data;
          return;
        }
        if (event === 'error') {
          fatal = (data && data.message) || 'AI 服务暂时不可用，请稍后再试';
        }
      }).then(function () {
        removeTyping();
        if (think && !thinkDone) { think.finish(); thinkDone = true; }

        if (fatal) {
          // 已经吐了一半内容却中断：保留已吐出的部分，再补一句提示
          if (bubble) {
            bubble.setHtml(renderText(bubble.text) + '<span class="lw-ai-warn">…（回答中断）</span>');
          } else {
            appendAi(renderText(fatal));
          }
          return;
        }
        if (!finalData) {
          // 一个字节都没收到（代理掐断 / 提前关闭）：退回一次性端点
          if (bubble) { bubble.remove(); }
          return gotAny ? undefined : fallbackOnce(payload);
        }

        if (think && finalData.reasoning) { think.setFinal(finalData.reasoning); }
        if (!think && finalData.reasoning) { appendThinking(finalData.reasoning); }
        finishWith(finalData, bubble);
        if (!sourcesShown) { appendSources(finalData.sources); }
      });
    }

    function send(text) {
      text = String(text || '').trim();
      if (busy || text === '') { return; }
      busy = true;
      if (el.send) { el.send.disabled = true; }

      appendUser(text);
      if (el.input) {
        el.input.value = '';
        autoGrow();
      }
      messages.push({ role: 'user', content: text });
      var history = trimMessages(messages).slice(-MAX_MSGS);
      saveHistory(messages, '');
      showTyping();

      // csrf_token 必须带：对话接口是 POST + JSON 体，没有它就成了可被跨站调用的
      // 「免费烧 AI 额度」入口。服务端在 aiChatPrepare() 里统一校验。
      var payload = {
        messages: history,
        page: global.location.pathname + global.location.search,
        csrf_token: csrfToken()
      };

      var chain;
      if (canStream()) {
        chain = respFetch('/api/ai_stream.php', {
          method: 'POST',
          headers: {
            'Content-Type': 'application/json',
            'Accept': 'text/event-stream',
            'X-Requested-With': 'XMLHttpRequest'
          },
          credentials: 'same-origin',
          body: JSON.stringify(payload)
        }).then(function (res) {
          if (res.status === 401) {
            showGateMsg();
            return;
          }
          var ct = (res.headers.get('content-type') || '').toLowerCase();
          if (ct.indexOf('text/event-stream') !== -1 && res.body && typeof res.body.getReader === 'function') {
            return streamChat(res, payload);
          }
          // 服务端在发 SSE 头之前就拒了（限流 / 参数不合法 / AI 未配置），
          // 或中间层把响应当成普通响应：按 JSON 读一次，读不出来再降级。
          return res.json().catch(function () { return null; }).then(function (data) {
            removeTyping();
            if (data && data.success === true && data.data) {
              var d = data.data;
              if (d.reasoning) { appendThinking(d.reasoning); }
              finishWith(d, null);
              appendSources(d.sources);
              return;
            }
            if (res.status === 429 || res.status === 403 || res.status === 503) {
              appendAi(renderText((data && data.message) || '请求被拒绝，请稍后再试'));
              return;
            }
            return fallbackOnce(payload);
          });
        });
      } else {
        chain = fallbackOnce(payload);
      }

      chain.catch(function () {
        removeTyping();
        appendAi(renderText('网络异常，请稍后再试。'));
      }).then(function () {
        busy = false;
        if (el.send) { el.send.disabled = false; }
        if (el.input) { el.input.focus(); }
      });
    }

    function setFoot(text) {
      if (!el.foot) { return; }
      var existing = el.foot.querySelector('.lw-ai-foottext');
      if (existing && existing.parentNode) { existing.parentNode.removeChild(existing); }
      if (!text) { return; }
      var span = document.createElement('div');
      span.className = 'lw-ai-foottext';
      span.textContent = text;
      el.foot.insertBefore(span, el.foot.firstChild);
    }

    /** 恢复历史对话（跨页跳转、浮窗↔完整版切换） */
    function restore() {
      messages = trimMessages(messages);
      if (!messages.length) { return; }
      hideWelcome();
      messages.forEach(function (m) {
        if (m.role === 'user') {
          appendUser(m.content);
        } else {
          appendAi(renderText(m.content));
        }
      });
    }

    // ---- 事件绑定（只绑一次） ----
    if (el.form) {
      el.form.addEventListener('submit', function (e) {
        e.preventDefault();
        send(el.input ? el.input.value : '');
      });
    }
    if (el.input) {
      el.input.addEventListener('keydown', function (e) {
        if (e.key === 'Enter' && !e.shiftKey) {
          e.preventDefault();
          send(el.input.value);
        }
      });
      el.input.addEventListener('input', autoGrow);
    }
    if (el.msgs) {
      el.msgs.addEventListener('click', function (e) {
        var btn = e.target.closest ? e.target.closest('[data-card-act]') : null;
        if (!btn) { return; }
        var card = btn.closest('.lw-ai-card');
        if (!card) { return; }
        if (btn.getAttribute('data-card-act') === 'cancel') {
          markCard(card, 'expired', '已取消，没有执行任何操作');
          return;
        }
        runCard(card, btn);
      });
    }

    var instance = {
      mode: mode,
      el: el,
      send: send,
      restore: restore,
      clear: function () {
        clearHistory();
        messages = [];
        var keep = el.welcome;
        el.msgs.innerHTML = '';
        if (keep) {
          el.msgs.appendChild(keep);
          keep.style.display = '';
        }
        notify('对话已清空');
      },
      getMessages: function () { return messages.slice(); },
      setQuick: function (list) {
        if (!el.quick || !list || !list.length) { return; }
        el.quick.innerHTML = '';
        list.forEach(function (q) {
          var b = document.createElement('button');
          b.type = 'button';
          b.className = 'lw-ai-qchip';
          b.textContent = q;
          b.addEventListener('click', function () { send(q); });
          el.quick.appendChild(b);
        });
      },
      showGate: showGate
    };
    return instance;
  }

  global.__lwAiCore = {
    attach: attach,
    renderText: renderText,
    safeHref: safeHref,
    esc: esc,
    escAttr: escAttr,
    icon: icon,
    csrfToken: csrfToken,
    siteUrl: siteUrl,
    openAiOptions: openAiOptions,
    loadHistory: loadHistory,
    saveHistory: saveHistory,
    clearHistory: clearHistory,
    trimMessages: trimMessages,
    STORE_KEY: STORE_KEY
  };
})(window);
