(function () {
  'use strict';

  // 兼容两种声明方式：
  // 页面用 <script>const IS_LOGGED_IN=...</script> 时，const 只生成脚本级绑定，不会挂到 window 上，
  // 而其他脚本（如 enhancements.js）读取 window.IS_LOGGED_IN。因此这里显式把真实值同步到 window，
  // 确保工具脚本能拿到正确的登录态，避免“已登录仍提示未登录”。
  window.IS_LOGGED_IN = (typeof IS_LOGGED_IN !== 'undefined') ? !!IS_LOGGED_IN : false;
  window.USER_DATA = (typeof USER_DATA !== 'undefined') ? USER_DATA : null;
  // CSRF 令牌：页面内联的 const 优先，**但缺声明时不要用空串覆盖**。
  // theme_boot.php 已在 <head> 把服务端令牌统一下发到 window.CSRF_TOKEN；
  // 旧写法最后那个 `: ''` 会在没有内联 const 的页面（topic.php / ranking.php）把它抹成空串，
  // 于是该页所有 POST 都 403 —— 正是「漏内联 = 整页功能失效」的那条链。
  if (typeof CSRF_TOKEN !== 'undefined' && CSRF_TOKEN) {
    window.CSRF_TOKEN = CSRF_TOKEN;
  } else if (typeof window.CSRF_TOKEN !== 'string') {
    window.CSRF_TOKEN = '';
  }
  window.SITE_URL = (typeof SITE_URL !== 'undefined') ? SITE_URL : '';

  // CSRF 令牌热更新：页面脚本用 const 声明 CSRF_TOKEN，运行期无法重新赋值，
  // 所以换签后的新令牌存这里（fetchAPI 在 403 自愈重试时调用 applyCSRFToken 写入）。
  var _csrfOverride = '';

  // ---------------------------------------------------------------------------
  // 分类在 URL 里的写法（唯一真源，服务端对应的反向映射见 api/posts/list.php）
  //
  // 为什么需要别名：本站所在的免费主机对 URL 里含 "chat" 的请求一律返回 403
  // （已实测：/assets/js/chat_test_9x1.js → 403，而 core_test_9x1.js → 404），
  // 即禁止在免费空间上跑聊天类应用。而「交友闲聊」的数据键正好叫 social_chat，
  // 直接拼进查询串（/api/posts/list.php?category=social_chat）会被整条拦掉，
  // 表现为点这个分类永远空列表 + 控制台报错。所以 URL 里统一用别名 social。
  // 注意：**只改 URL 写法，数据键与 CSS 类名仍是 social_chat**（落库数据不动）。
  var CATEGORY_URL_ALIAS = { social_chat: 'social' };

  function categoryUrlKey(key) {
    return Object.prototype.hasOwnProperty.call(CATEGORY_URL_ALIAS, key) ? CATEGORY_URL_ALIAS[key] : key;
  }

  // ---------------------------------------------------------------------------
  // 顶栏次要按钮的归置
  // 桌面端一律留在 .header-actions（与改动前完全一致）；手机端搬进
  // [data-mount="me-panel"]（用户面板底部弹层里的「快捷操作」区），避免顶栏被挤爆。
  // 断点变化时会重新归置，横竖屏切换后不会错位。
  // ---------------------------------------------------------------------------
  var _secondaryMounts = [];
  var _secondaryMq = window.matchMedia ? window.matchMedia('(max-width: 768px)') : null;

  function _placeSecondary(entry) {
    var isMobile = !!(_secondaryMq && _secondaryMq.matches);

    // 手机端但没有可挂载的面板时，必须隐藏而不是留在顶栏：
    // 这些次要按钮（私信/签到/草稿/关注…）都被定义为「手机端搬进用户面板」，
    // 而 includes/user_dropdown.php 在**未登录时不输出任何内容**，
    // 所以游客态下 [data-mount="me-panel"] 根本不存在。
    // 此时若把它们留在 .header-actions（不换行的 flex 行）里，
    // 顶栏会被挤出视口，造成整页横向滚动/缩放（实测 tools 溢出 38px、rollcall 71px）。
    // 这些按钮本身也都是登录后才用得上的功能，游客态隐藏即可。
    if (isMobile && !entry.mobileTarget) {
      entry.el.style.display = 'none';
      return;
    }
    entry.el.style.display = '';

    var useMobile = !!(entry.mobileTarget && isMobile);
    var target = useMobile ? entry.mobileTarget : entry.desktopTarget;
    if (target && entry.el.parentNode !== target) {
      target.appendChild(entry.el);
    }
  }

  function mountSecondary(el, mobileName) {
    var desktopTarget = document.querySelector('.header-actions');
    if (!desktopTarget) { return false; }
    var entry = {
      el: el,
      desktopTarget: desktopTarget,
      mobileTarget: mobileName ? document.querySelector('[data-mount="' + mobileName + '"]') : null
    };
    _secondaryMounts.push(entry);
    _placeSecondary(entry);
    return true;
  }

  if (_secondaryMq) {
    var _onSecondaryMq = function () { _secondaryMounts.forEach(_placeSecondary); };
    if (_secondaryMq.addEventListener) { _secondaryMq.addEventListener('change', _onSecondaryMq); }
    else if (_secondaryMq.addListener) { _secondaryMq.addListener(_onSecondaryMq); }
  }

  async function fetchAPI(url, options = {}, _retried) {
    const defaultOptions = {
      headers: { 'X-Requested-With': 'XMLHttpRequest' },
    };
    const mergedOptions = {
      ...defaultOptions,
      ...options,
      headers: { ...defaultOptions.headers, ...(options.headers || {}) },
    };

    const method = (mergedOptions.method || 'GET').toUpperCase();
    if (['POST', 'PUT', 'DELETE', 'PATCH'].includes(method)) {
      if (!(mergedOptions.body instanceof FormData)) {
        mergedOptions.headers['Content-Type'] =
          mergedOptions.headers['Content-Type'] || 'application/x-www-form-urlencoded';
      }

      if (mergedOptions.body instanceof FormData) {
        if (!mergedOptions.body.has('csrf_token')) {
          const csrfToken = getCSRFToken();
          if (csrfToken) {
            mergedOptions.body.append('csrf_token', csrfToken);
          }
        }
      } else if (typeof mergedOptions.body === 'string') {
        const csrfToken = getCSRFToken();
        if (csrfToken && !mergedOptions.body.includes('csrf_token=')) {
          const separator = mergedOptions.body ? '&' : '';
          mergedOptions.body += separator + 'csrf_token=' + encodeURIComponent(csrfToken);
        }
      } else if (!mergedOptions.body) {
        const csrfToken = getCSRFToken();
        if (csrfToken) {
          mergedOptions.body = 'csrf_token=' + encodeURIComponent(csrfToken);
        }
      }
    }

    let response;
    try {
      response = await fetch(SITE_URL + url, mergedOptions);
    } catch (err) {
      showToast('网络错误，请检查网络连接', 'error');
      throw err;
    }

    // CSRF 自愈重试：服务端校验令牌失败时会在 403 响应体里附 new_csrf_token，
    // 用它热更新本地令牌并把请求体重试一次。触发场景：表单页停留太久、多标签页
    // 换签、bfcache 恢复旧页面、免费主机弄丢会话。全程用户无感。
    if (response.status === 403 && !_retried) {
      let csrfRetry = null;
      try { csrfRetry = JSON.parse(await response.clone().text()); } catch (e) { csrfRetry = null; }
      if (csrfRetry && csrfRetry.new_csrf_token) {
        applyCSRFToken(csrfRetry.new_csrf_token);
        if (options.body instanceof FormData) {
          options.body.set('csrf_token', csrfRetry.new_csrf_token);
        } else if (typeof options.body === 'string') {
          if (/csrf_token=/.test(options.body)) {
            options.body = options.body.replace(/csrf_token=[^&]*/, 'csrf_token=' + encodeURIComponent(csrfRetry.new_csrf_token));
          } else {
            options.body += (options.body ? '&' : '') + 'csrf_token=' + encodeURIComponent(csrfRetry.new_csrf_token);
          }
        } else if (!options.body) {
          options.body = 'csrf_token=' + encodeURIComponent(csrfRetry.new_csrf_token);
        }
        return fetchAPI(url, options, true);
      }
    }

    if (!response.ok) {
      if (response.status === 401) {
        showToast('请先登录', 'warning');
        setTimeout(() => {
          window.location.href = SITE_URL + '/pages/login.php';
        }, 1000);
        throw new Error('Unauthorized');
      }
      if (response.status === 403) {
        showToast('权限不足', 'error');
        throw new Error('Forbidden');
      }
      if (response.status === 429) {
        showToast('操作过于频繁，请稍后再试', 'warning');
        throw new Error('Rate limited');
      }
    }

    let data;
    let rawBody = '';
    try {
      // 先取文本再手动 JSON.parse：这样解析失败时还能拿到原始正文用于判别宿主挑战页。
      rawBody = await response.text();
      data = JSON.parse(rawBody);
    } catch (err) {
      if (isHostChallengePage(response, rawBody)) {
        // 能自己解出通行证就原地重试，不打断用户；解不出来才退回刷新自愈。
        if (!_retried && await solveHostChallenge(rawBody)) {
          return fetchAPI(url, options, true);
        }
        recoverFromHostChallenge();
        throw new Error('HostChallenge');
      }
      showToast('服务器响应异常', 'error');
      throw err;
    }

    if (!data.success) {
      throw new Error(data.message || '操作失败');
    }
    return data;
  }

  /**
   * 识别主机商（InfinityFree）的反爬挑战页。
   *
   * 背景：站点的「通行证」cookie __test 与 User-Agent 严格绑定。当 UA 变化（切换
   * 桌面版）、cookie 过期或主机节点轮换时，任何 URL（含 /api/*.php）都会返回
   * HTTP 200 + text/html 的 aes.js 挑战页（约 857 字节的固定结构），而不是 JSON。
   * 前端直接 JSON.parse 必然失败，过去就会弹出「服务器响应异常」并把用户卡死。
   * 这里把这种响应单独认出来，交给自愈流程处理。
   */
  function isHostChallengePage(response, body) {
    if (!body || body.charAt(0) !== '<') return false;
    var ct = (response && response.headers && response.headers.get('content-type')) || '';
    if (ct && ct.indexOf('html') === -1) return false;
    // 主机挑战页有三个固定特征；但主机随时可能改文案，所以再加一条更宽的判据：
    // 凡是 /api/ 接口返回 HTML，那一定是被中间层插了一页（挑战页 / 错误页 / 拦截页），
    // 正常业务永远只会返回 JSON。
    if (body.indexOf('slowAES') !== -1 || body.indexOf('__test') !== -1 || body.indexOf('aes.js') !== -1) {
      return true;
    }
    var url = (response && response.url) || '';
    return url.indexOf('/api/') !== -1;
  }

  /**
   * 自己把主机的 AES 挑战解出来，免掉一次整页刷新。
   *
   * 原理：挑战页里带了 a（密钥）、b（IV）、c（密文）三个十六进制串，
   * 它自带的 /aes.js 提供 slowAES，解密结果就是通行证 cookie __test 的值。
   * /aes.js 是同origin静态资源、不受挑战保护（实测直取返回 application/javascript），
   * 所以我们可以像挑战页那样把它挂进来，用同一套算法算出同一个值写进 cookie。
   * 这样切换 UA、cookie 过期等场景都能原地恢复，用户完全无感。
   */
  /**
   * 是否运行在自家安卓客户端（APK）的 WebView 里。
   * 客户端会注入 window.LoveWallApp 桥接对象；只要它在，就说明是「软件」而不是普通浏览器。
   * 用于：桌面版 UA 下的布局适配、APK 直装、以及放宽部分仅限 App 的交互。
   */
  function isNativeApp() {
    var b = window.LoveWallApp;
    return !!(b && typeof b.available === 'function' && b.available());
  }

  var __aesPromise = null;
  function loadHostAes() {
    if (window.slowAES) return Promise.resolve(true);
    if (__aesPromise) return __aesPromise;
    __aesPromise = new Promise(function (resolve) {
      if (window.slowAES) { resolve(true); return; }
      var s = document.createElement('script');
      var settled = false;
      var finish = function (ok) {
        if (settled) return;
        settled = true;
        resolve(!!ok);
      };
      s.src = (SITE_URL || '') + '/aes.js';
      s.async = true;
      s.onload = function () { finish(window.slowAES); };
      s.onerror = function () { finish(false); };
      window.setTimeout(function () { finish(window.slowAES); }, 5000);
      (document.head || document.documentElement).appendChild(s);
    });
    return __aesPromise;
  }

  function hexToBytes(hex) {
    var out = [];
    String(hex).replace(/(..)/g, function (pair) { out.push(parseInt(pair, 16)); });
    return out;
  }

  function bytesToHex(arr) {
    var s = '';
    for (var i = 0; i < arr.length; i++) {
      var v = arr[i] & 0xff;
      s += (v < 16 ? '0' : '') + v.toString(16);
    }
    return s.toLowerCase();
  }

  async function solveHostChallenge(body) {
    if (!body) return false;
    var m = body.match(/a\s*=\s*toNumbers\("([0-9a-fA-F]+)"\)\s*,\s*b\s*=\s*toNumbers\("([0-9a-fA-F]+)"\)\s*,\s*c\s*=\s*toNumbers\("([0-9a-fA-F]+)"\)/);
    if (!m) return false;
    var ok = await loadHostAes();
    if (!ok || !window.slowAES) return false;
    try {
      var out = window.slowAES.decrypt(hexToBytes(m[3]), 2, hexToBytes(m[1]), hexToBytes(m[2]));
      var hex = bytesToHex(out || []);
      if (!/^[0-9a-f]{32}$/.test(hex)) return false;
      // 与挑战页写法保持一致：会话级通行证，path=/
      document.cookie = '__test=' + hex + '; max-age=21600; path=/';
      return true;
    } catch (e) {
      return false;
    }
  }

  /**
   * 需要原始 Response 的场景（SSE 流式读取等）用这个。
   * 与 fetchAPI 的唯一区别是返回 Response 而不是解析后的数据，
   * 但同样会先把主机挑战页解掉再重试。
   */
  async function fetchRaw(url, options = {}, _retried) {
    const res = await fetch(SITE_URL + url, options);
    if (_retried) return res;
    const ct = (res.headers && res.headers.get && res.headers.get('content-type')) || '';
    if (res.status !== 200 || ct.indexOf('html') === -1) return res;

    // 老 WebView 没有 Response#clone，读正文会消耗掉流，直接走刷新自愈
    if (!res.clone) {
      recoverFromHostChallenge();
      throw new Error('HostChallenge');
    }
    const body = await res.clone().text();
    if (!isHostChallengePage(res, body)) return res;
    const solved = await solveHostChallenge(body);
    if (solved) return fetchRaw(url, options, true);
    recoverFromHostChallenge();
    throw new Error('HostChallenge');
  }

  /**
   * 挑战页自愈：重载当前文档。
   *
   * 挑战页自带的 JS 会用当前 UA 解出 __test、写入 cookie 并回跳 ?i=1；只要重新
   * 走一次文档导航（而非 fetch），通行证就会被重新签发，之后所有接口自动恢复。
   * 用 sessionStorage 做 30 秒一次性防重，避免挑战页自身被反复重载成死循环。
   */
  function recoverFromHostChallenge() {
    var KEY = 'host_challenge_recovery_at';
    var last = 0;
    try { last = parseInt(window.sessionStorage.getItem(KEY) || '0', 10) || 0; } catch (e) { last = 0; }
    if (Date.now() - last < 30000) {
      showToast('网络环境异常，请稍后重试', 'error');
      return;
    }
    try { window.sessionStorage.setItem(KEY, String(Date.now())); } catch (e) {}
    showToast('正在重新连接…', 'info');
    setTimeout(function () { window.location.reload(); }, 300);
  }

  function getCSRFToken() {
    if (_csrfOverride) return _csrfOverride;
    // 顺序说明：_csrfOverride 是热更新后的最新值（必须最先），
    // 其次是 theme_boot.php 下发的 window.CSRF_TOKEN（全站唯一真源，且自愈拦截器
    // 换签后也写这里），然后才是页面内联的 const、DOM 隐藏输入、localStorage。
    // 把 window.CSRF_TOKEN 排在 const 之前是安全的：两者同一来源同一请求，值相同；
    // 而自愈换签只会更新 window 那一份，const 反而会是过期的。
    if (typeof window.CSRF_TOKEN === 'string' && window.CSRF_TOKEN) return window.CSRF_TOKEN;
    if (typeof CSRF_TOKEN !== 'undefined' && CSRF_TOKEN) return CSRF_TOKEN;
    const input = document.querySelector('input[name="csrf_token"]');
    if (input) return input.value;
    return localStorage.getItem('csrf_token') || '';
  }

  /**
   * CSRF 令牌热更新：fetchAPI 收到带 new_csrf_token 的 403 时调用。
   * 页面的 const CSRF_TOKEN 改不了，这里同步改模块级覆盖值、window 属性与
   * DOM 里的隐藏 input（原生表单提交也拿到新令牌）。
   */
  function applyCSRFToken(token) {
    if (!token) return;
    _csrfOverride = token;
    window.CSRF_TOKEN = token;
    try { localStorage.setItem('csrf_token', token); } catch (e) {}
    document.querySelectorAll('input[name="csrf_token"]').forEach(function (input) {
      input.value = token;
    });
  }

  function debounce(func, wait) {
    let timeout;
    return function executedFunction(...args) {
      const later = () => {
        clearTimeout(timeout);
        func.apply(this, args);
      };
      clearTimeout(timeout);
      timeout = setTimeout(later, wait);
    };
  }

  function throttle(func, wait) {
    let timeout = null;
    let previous = 0;
    return function (...args) {
      const now = Date.now();
      const remaining = wait - (now - previous);
      if (remaining <= 0 || remaining > wait) {
        if (timeout) {
          clearTimeout(timeout);
          timeout = null;
        }
        previous = now;
        func.apply(this, args);
      } else if (!timeout) {
        timeout = setTimeout(() => {
          previous = Date.now();
          timeout = null;
          func.apply(this, args);
        }, remaining);
      }
    };
  }

  function formatTime(dateString) {
    if (!dateString) return '';
    const now = new Date();
    const date = new Date(String(dateString).replace(/-/g, '/'));
    const diff = Math.floor((now - date) / 1000);
    if (isNaN(diff)) return dateString;
    if (diff < 60) return '刚刚';
    if (diff < 3600) return Math.floor(diff / 60) + '分钟前';
    if (diff < 86400) return Math.floor(diff / 3600) + '小时前';
    if (diff < 604800) return Math.floor(diff / 86400) + '天前';
    if (diff < 2592000) return Math.floor(diff / 604800) + '周前';
    if (diff < 31536000) return Math.floor(diff / 2592000) + '个月前';
    const y = date.getFullYear();
    const m = String(date.getMonth() + 1).padStart(2, '0');
    const d = String(date.getDate()).padStart(2, '0');
    const h = String(date.getHours()).padStart(2, '0');
    const min = String(date.getMinutes()).padStart(2, '0');
    return y + '-' + m + '-' + d + ' ' + h + ':' + min;
  }

  function escapeHtml(str) {
    if (str === null || str === undefined || str === '') return '';
    const div = document.createElement('div');
    div.appendChild(document.createTextNode(String(str)));
    return div.innerHTML;
  }

  window.esc = escapeHtml;

  async function copyToClipboard(text) {
    try {
      if (navigator.clipboard && navigator.clipboard.writeText) {
        await navigator.clipboard.writeText(text);
      } else {
        const textarea = document.createElement('textarea');
        textarea.value = text;
        textarea.style.position = 'fixed';
        textarea.style.opacity = '0';
        document.body.appendChild(textarea);
        textarea.select();
        document.execCommand('copy');
        document.body.removeChild(textarea);
      }
      showToast('已复制到剪贴板', 'success');
      return true;
    } catch (err) {
      showToast('复制失败', 'error');
      return false;
    }
  }

  let toastIdCounter = 0;

  function renderUserTitle(author) {
    if (!author || !author.title_text) return '';
    let style =
      'display:inline-block;padding:1px 8px;border-radius:4px;font-size:11px;font-weight:600;margin-left:6px;vertical-align:middle;';
    if (author.title_rainbow == 1) {
      const gs = author.title_gradient_start || '#ff4757';
      const ge = author.title_gradient_end || '#a55eea';
      style +=
        'background:linear-gradient(90deg,' + gs + ',' + ge + ',' + gs + ');background-size:200% 100%;animation:titleRainbow 2s linear infinite;color:' + (author.title_color || '#fff') + ';';
    } else {
      style +=
        'background:' + (author.title_bg_color || '#4A90D9') + ';color:' + (author.title_color || '#ffffff') + ';';
    }
    return '<span style="' + style + '">' + escapeHtml(author.title_text) + '</span>';
  }

  // ===== 统一图标入口（JS 端）—— 与 PHP 的 lw_icon() 一一对应 =====
  // 规范同 PHP 端：B11 尺寸标尺 16/18/20/22/24；B12 线宽由尺寸推导；
  // B13 一律 currentColor；B14 四态 default/active/disabled/loading。
  // ⚠️ 图标路径表两处必须同步：includes/icons.php 的 lwIconPaths() 和这里的 LW_ICON_PATHS。
  var LW_ICON_SIZES = [16, 18, 20, 22, 24];
  var LW_ICON_PATHS = {
    home: '<path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/>',
    star: '<polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/>',
    plus: '<line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/>',
    message: '<path d="M21 11.5a8.38 8.38 0 0 1-.9 3.8 8.5 8.5 0 0 1-7.6 4.7 8.38 8.38 0 0 1-3.8-.9L3 21l1.9-5.7a8.38 8.38 0 0 1-.9-3.8 8.5 8.5 0 0 1 4.7-7.6 8.38 8.38 0 0 1 3.8-.9h.5a8.48 8.48 0 0 1 8 8z"/>',
    user: '<path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/>',
    login: '<path d="M15 3h4a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-4"/><polyline points="10 17 15 12 10 7"/><line x1="15" y1="12" x2="3" y2="12"/>',
    logout: '<path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/>',
    menu: '<line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="18" x2="21" y2="18"/>',
    close: '<line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/>',
    search: '<circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/>',
    bell: '<path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/>',
    heart: '<path d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 0 0 0-7.78z"/>',
    comment: '<path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/>',
    share: '<circle cx="18" cy="5" r="3"/><circle cx="6" cy="12" r="3"/><circle cx="18" cy="19" r="3"/><line x1="8.59" y1="13.51" x2="15.42" y2="17.49"/><line x1="15.41" y1="6.51" x2="8.59" y2="10.49"/>',
    sun: '<circle cx="12" cy="12" r="5"/><line x1="12" y1="1" x2="12" y2="3"/><line x1="12" y1="21" x2="12" y2="23"/><line x1="4.22" y1="4.22" x2="5.64" y2="5.64"/><line x1="18.36" y1="18.36" x2="19.78" y2="19.78"/><line x1="1" y1="12" x2="3" y2="12"/><line x1="21" y1="12" x2="23" y2="12"/><line x1="4.22" y1="19.78" x2="5.64" y2="18.36"/><line x1="18.36" y1="5.64" x2="19.78" y2="4.22"/>',
    moon: '<path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"/>',
    auto: '<circle cx="12" cy="12" r="9"/><path d="M12 3a9 9 0 0 0 0 18z" fill="currentColor" stroke="none"/>',
    'arrow-left': '<line x1="19" y1="12" x2="5" y2="12"/><polyline points="12 19 5 12 12 5"/>',
    'arrow-up': '<line x1="12" y1="19" x2="12" y2="5"/><polyline points="5 12 12 5 19 12"/>',
    'chevron-down': '<polyline points="6 9 12 15 18 9"/>',
    download: '<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/>',
    calendar: '<rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/>',
    clock: '<circle cx="12" cy="12" r="9"/><polyline points="12 7 12 12 15.5 14"/>',
    refresh: '<polyline points="23 4 23 10 17 10"/><path d="M20.49 15a9 9 0 1 1-2.12-9.36L23 10"/>',
    check: '<polyline points="20 6 9 17 4 12"/>',
    edit: '<path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.12 2.12 0 0 1 3 3L12 15l-4 1 1-4z"/>',
    trash: '<polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/>',
    image: '<rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><polyline points="21 15 16 10 5 21"/>',
    tag: '<path d="M20.59 13.41l-7.17 7.17a2 2 0 0 1-2.83 0L2 12V2h10l8.59 8.59a2 2 0 0 1 0 2.82z"/><line x1="7" y1="7" x2="7.01" y2="7"/>',
    eye: '<path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/>',
    settings: '<circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 1 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06A1.65 1.65 0 0 0 4.6 15a1.65 1.65 0 0 0-1.51-1H3a2 2 0 1 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06A1.65 1.65 0 0 0 9 4.6a1.65 1.65 0 0 0 1-1.51V3a2 2 0 1 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06A1.65 1.65 0 0 0 19.4 9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 1 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/>'
  };

  function lwIconSnapSize(size) {
    size = parseInt(size, 10) || 20;
    var best = 20, bestDiff = Infinity;
    for (var i = 0; i < LW_ICON_SIZES.length; i++) {
      var d = Math.abs(LW_ICON_SIZES[i] - size);
      if (d < bestDiff) { bestDiff = d; best = LW_ICON_SIZES[i]; }
    }
    return best;
  }

  function lwIconStroke(size) {
    if (size <= 18) return '2.2';
    if (size <= 20) return '2';
    if (size <= 22) return '1.9';
    return '1.8';
  }

  /**
   * LWIcon(name, size, opts) —— 与 PHP 端 lw_icon() 同签名、同产出结构。
   * opts: { motion, state, wrap, stroke, class, label }
   */
  function LWIcon(name, size, opts) {
    opts = opts || {};
    var paths = LW_ICON_PATHS[name];
    if (!paths) return '';
    var s = lwIconSnapSize(size);
    var stroke = opts.stroke || lwIconStroke(s);
    var state = opts.state || 'default';
    var stateClass = state === 'loading' ? ' lw-icon-spin'
      : state === 'active' ? ' is-on'
        : state === 'disabled' ? ' is-disabled' : '';
    var motions = '';
    if (opts.motion) {
      var list = Array.isArray(opts.motion) ? opts.motion : [opts.motion];
      for (var i = 0; i < list.length; i++) {
        var m = String(list[i] || '').replace(/[^a-z0-9-]/gi, '');
        if (m) motions += ' lw-icon-' + m;
      }
    }
    var extra = opts.class ? ' ' + String(opts.class).trim() : '';
    var a11y = opts.label
      ? ' role="img" aria-label="' + escapeHtml(String(opts.label)) + '"'
      : ' aria-hidden="true"';
    var modifiers = motions + stateClass + extra;
    var svg = '<svg class="lw-icon' + modifiers + '" width="' + s + '" height="' + s + '" viewBox="0 0 24 24"'
      + ' fill="none" stroke="currentColor" stroke-width="' + stroke + '" stroke-linecap="round" stroke-linejoin="round"'
      + a11y + '>' + paths + '</svg>';
    if (opts.wrap === false) return svg;
    return '<span class="lw-icon-wrap lw-icon--' + String(name) + modifiers + '">' + svg + '</span>';
  }
  window.LWIcon = LWIcon;

  // ===== 空状态插画（B17，JS 端）—— 与 PHP 的 lw_illustration() 同源同风格 =====
  // 路径表两处必须同步：includes/icons.php 的 lw_illustration() 和这里的 LW_ILLUSTRATIONS。
  var LW_ILLUSTRATIONS = {
    posts: '<rect x="18" y="14" width="46" height="42" rx="8" fill="currentColor" opacity=".08"/>'
      + '<rect x="26" y="20" width="46" height="42" rx="8" fill="none" stroke="currentColor" stroke-width="2.2" opacity=".55"/>'
      + '<line x1="34" y1="33" x2="62" y2="33" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" opacity=".45"/>'
      + '<line x1="34" y1="42" x2="56" y2="42" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" opacity=".35"/>'
      + '<line x1="34" y1="51" x2="48" y2="51" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" opacity=".25"/>'
      + '<ellipse cx="52" cy="66" rx="26" ry="4" fill="currentColor" opacity=".07"/>',
    search: '<circle cx="42" cy="30" r="17" fill="currentColor" opacity=".08"/>'
      + '<circle cx="42" cy="30" r="17" fill="none" stroke="currentColor" stroke-width="2.4" opacity=".6"/>'
      + '<line x1="54" y1="42" x2="68" y2="56" stroke="currentColor" stroke-width="3" stroke-linecap="round" opacity=".6"/>'
      + '<line x1="30" y1="58" x2="50" y2="58" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-dasharray="5 5" opacity=".35"/>'
      + '<line x1="30" y1="65" x2="44" y2="65" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-dasharray="5 5" opacity=".25"/>',
    comments: '<path d="M20 22h48a8 8 0 0 1 8 8v18a8 8 0 0 1-8 8H44l-12 10V56h-4a8 8 0 0 1-8-8V30a8 8 0 0 1 8-8z" fill="currentColor" opacity=".08"/>'
      + '<path d="M18 20h46a8 8 0 0 1 8 8v18a8 8 0 0 1-8 8H40l-11 9V54h-3a8 8 0 0 1-8-8V28a8 8 0 0 1 8-8z" fill="none" stroke="currentColor" stroke-width="2.2" opacity=".55"/>'
      + '<circle cx="32" cy="37" r="2.6" fill="currentColor" opacity=".4"/>'
      + '<circle cx="43" cy="37" r="2.6" fill="currentColor" opacity=".4"/>'
      + '<circle cx="54" cy="37" r="2.6" fill="currentColor" opacity=".4"/>',
    favorites: '<path d="M48 12l9.6 19.4 21.4 3.1-15.5 15.1 3.7 21.3L48 60.8 28.8 70.9l3.7-21.3L17 34.5l21.4-3.1z" fill="currentColor" opacity=".1"/>'
      + '<path d="M48 14l8.8 17.8 19.6 2.9-14.2 13.8 3.4 19.5L48 58.9 30.4 68l3.4-19.5L19.6 34.7l19.6-2.9z" fill="none" stroke="currentColor" stroke-width="2.2" opacity=".55" stroke-linejoin="round"/>',
    notifications: '<path d="M48 14a16 16 0 0 0-16 16c0 17-6 21-6 21h44s-6-4-6-21a16 16 0 0 0-16-16z" fill="currentColor" opacity=".08"/>'
      + '<path d="M48 14a16 16 0 0 0-16 16c0 17-6 21-6 21h44s-6-4-6-21a16 16 0 0 0-16-16z" fill="none" stroke="currentColor" stroke-width="2.2" opacity=".55" stroke-linejoin="round"/>'
      + '<path d="M42 58a6 6 0 0 0 12 0" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" opacity=".55"/>',
    error: '<path d="M40 20a14 14 0 0 0 0 28 12 12 0 0 0 22 6 11 11 0 0 0 8-20 15 15 0 0 0-30-14z" fill="currentColor" opacity=".08"/>'
      + '<path d="M40 20a14 14 0 0 0 0 28 12 12 0 0 0 22 6 11 11 0 0 0 8-20 15 15 0 0 0-30-14z" fill="none" stroke="currentColor" stroke-width="2.2" opacity=".55" stroke-linejoin="round"/>'
      + '<line x1="48" y1="30" x2="48" y2="40" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" opacity=".6"/>'
      + '<circle cx="48" cy="46" r="1.8" fill="currentColor" opacity=".6"/>'
  };
  var LW_ILLU_SPARKS = '<path d="M16 18l1.6 4.4L22 24l-4.4 1.6L16 30l-1.6-4.4L10 24l4.4-1.6z" fill="var(--macaron-sky, #9CC7F0)" opacity=".85"/>'
    + '<path d="M82 46l1.2 3.3L86.5 50.5l-3.3 1.2L82 55l-1.2-3.3L77.5 50.5l3.3-1.2z" fill="var(--macaron-mint, #8FD9C0)" opacity=".85"/>';

  function LWIllustration(kind, size) {
    var body = LW_ILLUSTRATIONS[kind] || LW_ILLUSTRATIONS.posts;
    size = Math.max(48, parseInt(size, 10) || 96);
    var h = Math.round(size * 0.75);
    return '<svg class="lw-illustration lw-illustration--' + String(kind) + '" width="' + size + '" height="' + h
      + '" viewBox="0 0 96 72" fill="none" role="img" aria-hidden="true" focusable="false">'
      + body + LW_ILLU_SPARKS + '</svg>';
  }
  window.LWIllustration = LWIllustration;

  let _svgUid = 0;
  function svgIcon(name, size) {
    size = size || 16;
    _svgUid++;
    var uid = 'sg' + _svgUid;
    var icons = {
      check:
        '<svg width="' +
        size +
        '" height="' +
        size +
        '" viewBox="0 0 24 24" fill="none"><defs><linearGradient id="' +
        uid +
        '" x1="0%" y1="0%" x2="100%" y2="100%"><stop offset="0%" stop-color="#34D058"/><stop offset="100%" stop-color="#28A745"/></linearGradient></defs><circle cx="12" cy="12" r="11" fill="url(#' +
        uid +
        ')"/><polyline points="7 12.5 10.5 16 17 8.5" fill="none" stroke="#fff" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"/></svg>',
      x:
        '<svg width="' +
        size +
        '" height="' +
        size +
        '" viewBox="0 0 24 24" fill="none"><circle cx="12" cy="12" r="11" fill="#f0f2f5"/><line x1="8" y1="8" x2="16" y2="16" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"/><line x1="16" y1="8" x2="8" y2="16" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"/></svg>',
      alert:
        '<svg width="' +
        size +
        '" height="' +
        size +
        '" viewBox="0 0 24 24" fill="none"><defs><linearGradient id="' +
        uid +
        '" x1="0%" y1="0%" x2="100%" y2="100%"><stop offset="0%" stop-color="#FFB74D"/><stop offset="100%" stop-color="#FF9800"/></linearGradient></defs><path d="M12 2 L22 20 L2 20 Z" fill="url(#' +
        uid +
        ')"/><line x1="12" y1="9" x2="12" y2="14" stroke="#fff" stroke-width="2" stroke-linecap="round"/><circle cx="12" cy="17" r="1.2" fill="#fff"/></svg>',
      info:
        '<svg width="' +
        size +
        '" height="' +
        size +
        '" viewBox="0 0 24 24" fill="none"><defs><linearGradient id="' +
        uid +
        '" x1="0%" y1="0%" x2="100%" y2="100%"><stop offset="0%" stop-color="#4FC3F7"/><stop offset="100%" stop-color="#2196F3"/></linearGradient></defs><circle cx="12" cy="12" r="11" fill="url(#' +
        uid +
        ')"/><circle cx="12" cy="7.5" r="1.4" fill="#fff"/><line x1="12" y1="11" x2="12" y2="17" stroke="#fff" stroke-width="2" stroke-linecap="round"/></svg>',
      heart:
        '<svg width="' +
        size +
        '" height="' +
        size +
        '" viewBox="0 0 24 24" fill="none"><defs><linearGradient id="' +
        uid +
        '" x1="0%" y1="0%" x2="100%" y2="100%"><stop offset="0%" stop-color="#FF6B9D"/><stop offset="100%" stop-color="#E91E63"/></linearGradient></defs><path d="M12 21.35l-1.45-1.32C5.4 15.36 2 12.28 2 8.5 2 5.42 4.42 3 7.5 3c1.74 0 3.41.81 4.5 2.09C13.09 3.81 14.76 3 16.5 3 19.58 3 22 5.42 22 8.5c0 3.78-3.4 6.86-8.55 11.54L12 21.35z" fill="url(#' +
        uid +
        ')"/></svg>',
      heartOutline:
        '<svg width="' +
        size +
        '" height="' +
        size +
        '" viewBox="0 0 24 24" fill="none"><defs><linearGradient id="' +
        uid +
        '" x1="0%" y1="0%" x2="100%" y2="100%"><stop offset="0%" stop-color="#FF6B9D"/><stop offset="100%" stop-color="#E91E63"/></linearGradient></defs><path d="M12 21.35l-1.45-1.32C5.4 15.36 2 12.28 2 8.5 2 5.42 4.42 3 7.5 3c1.74 0 3.41.81 4.5 2.09C13.09 3.81 14.76 3 16.5 3 19.58 3 22 5.42 22 8.5c0 3.78-3.4 6.86-8.55 11.54L12 21.35z" fill="none" stroke="url(#' +
        uid +
        ')" stroke-width="2" stroke-linejoin="round"/></svg>',
      message:
        '<svg width="' +
        size +
        '" height="' +
        size +
        '" viewBox="0 0 24 24" fill="none"><defs><linearGradient id="' +
        uid +
        '" x1="0%" y1="0%" x2="100%" y2="100%"><stop offset="0%" stop-color="#5BC0EB"/><stop offset="100%" stop-color="#3A8EE6"/></linearGradient></defs><path d="M21 11.5a8.38 8.38 0 0 1-.9 3.8 8.5 8.5 0 0 1-7.6 4.7 8.38 8.38 0 0 1-3.8-.9L3 21l1.9-5.7a8.38 8.38 0 0 1-.9-3.8 8.5 8.5 0 0 1 4.7-7.6 8.38 8.38 0 0 1 3.8-.9h.5a8.48 8.48 0 0 1 8 8v.5z" fill="url(#' +
        uid +
        ')"/></svg>',
      star:
        '<svg width="' +
        size +
        '" height="' +
        size +
        '" viewBox="0 0 24 24" fill="none"><defs><linearGradient id="' +
        uid +
        '" x1="0%" y1="0%" x2="100%" y2="100%"><stop offset="0%" stop-color="#FFD54F"/><stop offset="100%" stop-color="#FFA000"/></linearGradient></defs><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2" fill="url(#' +
        uid +
        ')" stroke="#FFA000" stroke-width="0.5" stroke-linejoin="round"/></svg>',
      starOutline:
        '<svg width="' +
        size +
        '" height="' +
        size +
        '" viewBox="0 0 24 24" fill="none"><defs><linearGradient id="' +
        uid +
        '" x1="0%" y1="0%" x2="100%" y2="100%"><stop offset="0%" stop-color="#FFD54F"/><stop offset="100%" stop-color="#FFA000"/></linearGradient></defs><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2" fill="none" stroke="url(#' +
        uid +
        ')" stroke-width="2" stroke-linejoin="round"/></svg>',
      copy:
        '<svg width="' +
        size +
        '" height="' +
        size +
        '" viewBox="0 0 24 24" fill="none"><defs><linearGradient id="' +
        uid +
        '" x1="0%" y1="0%" x2="100%" y2="100%"><stop offset="0%" stop-color="#A0AEC0"/><stop offset="100%" stop-color="#718096"/></linearGradient></defs><rect x="9" y="9" width="13" height="13" rx="2.5" fill="url(#' +
        uid +
        ')" stroke="#fff" stroke-width="0.5"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>',
      pin:
        '<svg width="' +
        size +
        '" height="' +
        size +
        '" viewBox="0 0 24 24" fill="none"><defs><linearGradient id="' +
        uid +
        '" x1="0%" y1="0%" x2="100%" y2="100%"><stop offset="0%" stop-color="#FF8A65"/><stop offset="100%" stop-color="#E64A19"/></linearGradient></defs><path d="M9 4h6v6l4 4H5l4-4V4z" fill="url(#' +
        uid +
        ')"/><line x1="12" y1="14" x2="12" y2="22" stroke="url(#' +
        uid +
        ')" stroke-width="2.5" stroke-linecap="round"/></svg>',
      eye:
        '<svg width="' +
        size +
        '" height="' +
        size +
        '" viewBox="0 0 24 24" fill="none"><defs><linearGradient id="' +
        uid +
        '" x1="0%" y1="0%" x2="100%" y2="100%"><stop offset="0%" stop-color="#4FC3F7"/><stop offset="100%" stop-color="#1976D2"/></linearGradient></defs><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z" fill="url(#' +
        uid +
        ')" opacity="0.15"/><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/><circle cx="12" cy="12" r="3.2" fill="url(#' +
        uid +
        ')"/></svg>',
      eyeOff:
        '<svg width="' +
        size +
        '" height="' +
        size +
        '" viewBox="0 0 24 24" fill="none"><defs><linearGradient id="' +
        uid +
        '" x1="0%" y1="0%" x2="100%" y2="100%"><stop offset="0%" stop-color="#E57373"/><stop offset="100%" stop-color="#C62828"/></linearGradient></defs><path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24" fill="none" stroke="url(#' +
        uid +
        ')" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/><line x1="1" y1="1" x2="23" y2="23" stroke="url(#' +
        uid +
        ')" stroke-width="2" stroke-linecap="round"/></svg>',
      sun:
        '<svg width="' +
        size +
        '" height="' +
        size +
        '" viewBox="0 0 24 24" fill="none"><defs><linearGradient id="' +
        uid +
        '" x1="0%" y1="0%" x2="100%" y2="100%"><stop offset="0%" stop-color="#FFD54F"/><stop offset="100%" stop-color="#FF9800"/></linearGradient><radialGradient id="' +
        uid +
        'r"><stop offset="0%" stop-color="#FFF59D"/><stop offset="100%" stop-color="#FFC107"/></radialGradient></defs><circle cx="12" cy="12" r="4.5" fill="url(#' +
        uid +
        'r)"/><g stroke="url(#' +
        uid +
        ')" stroke-width="2.2" stroke-linecap="round"><line x1="12" y1="1.5" x2="12" y2="4"/><line x1="12" y1="20" x2="12" y2="22.5"/><line x1="4.22" y1="4.22" x2="5.64" y2="5.64"/><line x1="18.36" y1="18.36" x2="19.78" y2="19.78"/><line x1="1.5" y1="12" x2="4" y2="12"/><line x1="20" y1="12" x2="22.5" y2="12"/><line x1="4.22" y1="19.78" x2="5.64" y2="18.36"/><line x1="18.36" y1="5.64" x2="19.78" y2="4.22"/></g></svg>',
      moon:
        '<svg width="' +
        size +
        '" height="' +
        size +
        '" viewBox="0 0 24 24" fill="none"><defs><linearGradient id="' +
        uid +
        '" x1="0%" y1="0%" x2="100%" y2="100%"><stop offset="0%" stop-color="#90CAF9"/><stop offset="100%" stop-color="#3949AB"/></linearGradient></defs><path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z" fill="url(#' +
        uid +
        ')"/><circle cx="15" cy="9" r="0.8" fill="#fff" opacity="0.7"/><circle cx="17.5" cy="13" r="0.6" fill="#fff" opacity="0.5"/></svg>',
      inbox:
        '<svg width="' +
        size +
        '" height="' +
        size +
        '" viewBox="0 0 24 24" fill="none"><defs><linearGradient id="' +
        uid +
        '" x1="0%" y1="0%" x2="100%" y2="100%"><stop offset="0%" stop-color="#B0BEC5"/><stop offset="100%" stop-color="#607D8B"/></linearGradient></defs><polyline points="22 12 16 12 14 15 10 15 8 12 2 12" fill="none" stroke="url(#' +
        uid +
        ')" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/><path d="M5.45 5.11L2 12v6a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2v-6l-3.45-6.89A2 2 0 0 0 16.76 4H7.24a2 2 0 0 0-1.79 1.11z" fill="none" stroke="url(#' +
        uid +
        ')" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>',
      paperclip:
        '<svg width="' +
        size +
        '" height="' +
        size +
        '" viewBox="0 0 24 24" fill="none"><defs><linearGradient id="' +
        uid +
        '" x1="0%" y1="0%" x2="100%" y2="100%"><stop offset="0%" stop-color="#81D4FA"/><stop offset="100%" stop-color="#0288D1"/></linearGradient></defs><path d="M21.44 11.05l-9.19 9.19a6 6 0 0 1-8.49-8.49l9.19-9.19a4 4 0 0 1 5.66 5.66l-9.2 9.19a2 2 0 0 1-2.83-2.83l8.49-8.48" fill="none" stroke="url(#' +
        uid +
        ')" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>',
      alertCircle:
        '<svg width="' +
        size +
        '" height="' +
        size +
        '" viewBox="0 0 24 24" fill="none"><defs><linearGradient id="' +
        uid +
        '" x1="0%" y1="0%" x2="100%" y2="100%"><stop offset="0%" stop-color="#EF5350"/><stop offset="100%" stop-color="#C62828"/></linearGradient></defs><circle cx="12" cy="12" r="11" fill="url(#' +
        uid +
        ')"/><line x1="12" y1="7" x2="12" y2="13" stroke="#fff" stroke-width="2.4" stroke-linecap="round"/><circle cx="12" cy="16.5" r="1.2" fill="#fff"/></svg>',
      trash:
        '<svg width="' +
        size +
        '" height="' +
        size +
        '" viewBox="0 0 24 24" fill="none"><defs><linearGradient id="' +
        uid +
        '" x1="0%" y1="0%" x2="100%" y2="100%"><stop offset="0%" stop-color="#EF5350"/><stop offset="100%" stop-color="#C62828"/></linearGradient></defs><polyline points="3 6 5 6 21 6" fill="none" stroke="url(#' +
        uid +
        ')" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2" fill="none" stroke="url(#' +
        uid +
        ')" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>',
      edit:
        '<svg width="' +
        size +
        '" height="' +
        size +
        '" viewBox="0 0 24 24" fill="none"><defs><linearGradient id="' +
        uid +
        '" x1="0%" y1="0%" x2="100%" y2="100%"><stop offset="0%" stop-color="#66BB6A"/><stop offset="100%" stop-color="#2E7D32"/></linearGradient></defs><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7" fill="none" stroke="url(#' +
        uid +
        ')" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z" fill="url(#' +
        uid +
        ')" opacity="0.85"/></svg>',
      user:
        '<svg width="' +
        size +
        '" height="' +
        size +
        '" viewBox="0 0 24 24" fill="none"><defs><linearGradient id="' +
        uid +
        '" x1="0%" y1="0%" x2="100%" y2="100%"><stop offset="0%" stop-color="#7C8DA4"/><stop offset="100%" stop-color="#4A5568"/></linearGradient></defs><circle cx="12" cy="8" r="4.5" fill="url(#' +
        uid +
        ')"/><path d="M3.5 21c0-4.69 4.7-8 8.5-8s8.5 3.31 8.5 8" fill="none" stroke="url(#' +
        uid +
        ')" stroke-width="2.4" stroke-linecap="round"/></svg>',
      search:
        '<svg width="' +
        size +
        '" height="' +
        size +
        '" viewBox="0 0 24 24" fill="none"><defs><linearGradient id="' +
        uid +
        '" x1="0%" y1="0%" x2="100%" y2="100%"><stop offset="0%" stop-color="#5BC0EB"/><stop offset="100%" stop-color="#1976D2"/></linearGradient></defs><circle cx="11" cy="11" r="7" fill="none" stroke="url(#' +
        uid +
        ')" stroke-width="2.4" stroke-linecap="round"/><line x1="16.5" y1="16.5" x2="21" y2="21" stroke="url(#' +
        uid +
        ')" stroke-width="2.4" stroke-linecap="round"/></svg>',
      settings:
        '<svg width="' +
        size +
        '" height="' +
        size +
        '" viewBox="0 0 24 24" fill="none"><defs><linearGradient id="' +
        uid +
        '" x1="0%" y1="0%" x2="100%" y2="100%"><stop offset="0%" stop-color="#90A4AE"/><stop offset="100%" stop-color="#455A64"/></linearGradient></defs><circle cx="12" cy="12" r="3" fill="url(#' +
        uid +
        ')"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1 0 2.83 2 2 0 0 1-2.83 0l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83 0 2 2 0 0 1 0-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 0-2.83 2 2 0 0 1 2.83 0l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 0 2 2 0 0 1 0 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z" fill="none" stroke="url(#' +
        uid +
        ')" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>',
      bell:
        '<svg width="' +
        size +
        '" height="' +
        size +
        '" viewBox="0 0 24 24" fill="none"><defs><linearGradient id="' +
        uid +
        '" x1="0%" y1="0%" x2="100%" y2="100%"><stop offset="0%" stop-color="#FFB74D"/><stop offset="100%" stop-color="#F57C00"/></linearGradient></defs><path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9" fill="url(#' +
        uid +
        ')" opacity="0.9"/><path d="M13.73 21a2 2 0 0 1-3.46 0" fill="none" stroke="url(#' +
        uid +
        ')" stroke-width="2" stroke-linecap="round"/></svg>',
      send:
        '<svg width="' +
        size +
        '" height="' +
        size +
        '" viewBox="0 0 24 24" fill="none"><defs><linearGradient id="' +
        uid +
        '" x1="0%" y1="0%" x2="100%" y2="100%"><stop offset="0%" stop-color="#4FC3F7"/><stop offset="100%" stop-color="#1976D2"/></linearGradient></defs><line x1="22" y1="2" x2="11" y2="13" stroke="url(#' +
        uid +
        ')" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/><polygon points="22 2 15 22 11 13 2 9 22 2" fill="url(#' +
        uid +
        ')" opacity="0.9"/></svg>',
      image:
        '<svg width="' +
        size +
        '" height="' +
        size +
        '" viewBox="0 0 24 24" fill="none"><defs><linearGradient id="' +
        uid +
        '" x1="0%" y1="0%" x2="100%" y2="100%"><stop offset="0%" stop-color="#81D4FA"/><stop offset="100%" stop-color="#1976D2"/></linearGradient></defs><rect x="3" y="3" width="18" height="18" rx="2" ry="2" fill="url(#' +
        uid +
        ')" opacity="0.2"/><circle cx="8.5" cy="8.5" r="1.8" fill="url(#' +
        uid +
        ')"/><polyline points="21 15 16 10 5 21" fill="none" stroke="url(#' +
        uid +
        ')" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/><rect x="3" y="3" width="18" height="18" rx="2" ry="2" fill="none" stroke="url(#' +
        uid +
        ')" stroke-width="2"/></svg>',
      logout:
        '<svg width="' +
        size +
        '" height="' +
        size +
        '" viewBox="0 0 24 24" fill="none"><defs><linearGradient id="' +
        uid +
        '" x1="0%" y1="0%" x2="100%" y2="100%"><stop offset="0%" stop-color="#EF5350"/><stop offset="100%" stop-color="#C62828"/></linearGradient></defs><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4" fill="none" stroke="url(#' +
        uid +
        ')" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/><polyline points="16 17 21 12 16 7" fill="none" stroke="url(#' +
        uid +
        ')" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/><line x1="21" y1="12" x2="9" y2="12" stroke="url(#' +
        uid +
        ')" stroke-width="2" stroke-linecap="round"/></svg>',
      lock:
        '<svg width="' +
        size +
        '" height="' +
        size +
        '" viewBox="0 0 24 24" fill="none"><defs><linearGradient id="' +
        uid +
        '" x1="0%" y1="0%" x2="100%" y2="100%"><stop offset="0%" stop-color="#FFB74D"/><stop offset="100%" stop-color="#F57C00"/></linearGradient></defs><rect x="3" y="11" width="18" height="11" rx="2.5" fill="url(#' +
        uid +
        ')"/><path d="M7 11V7a5 5 0 0 1 10 0v4" fill="none" stroke="url(#' +
        uid +
        ')" stroke-width="2.4" stroke-linecap="round"/><circle cx="12" cy="16" r="1.5" fill="#fff"/></svg>',
      tag:
        '<svg width="' +
        size +
        '" height="' +
        size +
        '" viewBox="0 0 24 24" fill="none"><defs><linearGradient id="' +
        uid +
        '" x1="0%" y1="0%" x2="100%" y2="100%"><stop offset="0%" stop-color="#BA68C8"/><stop offset="100%" stop-color="#7B1FA2"/></linearGradient></defs><path d="M20.59 13.41l-7.17 7.17a2 2 0 0 1-2.83 0L2 12V2h10l8.59 8.59a2 2 0 0 1 0 2.82z" fill="url(#' +
        uid +
        ')" opacity="0.9"/><line x1="7" y1="7" x2="7.01" y2="7" stroke="#fff" stroke-width="2.5" stroke-linecap="round"/></svg>',
      home:
        '<svg width="' +
        size +
        '" height="' +
        size +
        '" viewBox="0 0 24 24" fill="none"><defs><linearGradient id="' +
        uid +
        '" x1="0%" y1="0%" x2="100%" y2="100%"><stop offset="0%" stop-color="#4FC3F7"/><stop offset="100%" stop-color="#1976D2"/></linearGradient></defs><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z" fill="url(#' +
        uid +
        ')"/><polyline points="9 22 9 12 15 12 15 22" fill="none" stroke="#fff" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>',
      plus:
        '<svg width="' +
        size +
        '" height="' +
        size +
        '" viewBox="0 0 24 24" fill="none"><defs><linearGradient id="' +
        uid +
        '" x1="0%" y1="0%" x2="100%" y2="100%"><stop offset="0%" stop-color="#66BB6A"/><stop offset="100%" stop-color="#2E7D32"/></linearGradient></defs><circle cx="12" cy="12" r="11" fill="url(#' +
        uid +
        ')"/><line x1="12" y1="7" x2="12" y2="17" stroke="#fff" stroke-width="2.6" stroke-linecap="round"/><line x1="7" y1="12" x2="17" y2="12" stroke="#fff" stroke-width="2.6" stroke-linecap="round"/></svg>',
      back:
        '<svg width="' +
        size +
        '" height="' +
        size +
        '" viewBox="0 0 24 24" fill="none"><defs><linearGradient id="' +
        uid +
        '" x1="0%" y1="0%" x2="100%" y2="100%"><stop offset="0%" stop-color="#B0BEC5"/><stop offset="100%" stop-color="#607D8B"/></linearGradient></defs><line x1="12" y1="19" x2="12" y2="5" stroke="url(#' +
        uid +
        ')" stroke-width="2.4" stroke-linecap="round"/><polyline points="5 12 12 5 19 12" fill="none" stroke="url(#' +
        uid +
        ')" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"/></svg>',
      trophy:
        '<svg width="' +
        size +
        '" height="' +
        size +
        '" viewBox="0 0 24 24" fill="none"><defs><linearGradient id="' +
        uid +
        '" x1="0%" y1="0%" x2="100%" y2="100%"><stop offset="0%" stop-color="#FFD54F"/><stop offset="100%" stop-color="#FF8F00"/></linearGradient></defs><path d="M6 9H4.5a2.5 2.5 0 0 1 0-5H6" fill="none" stroke="url(#' +
        uid +
        ')" stroke-width="2" stroke-linecap="round"/><path d="M18 9h1.5a2.5 2.5 0 0 0 0-5H18" fill="none" stroke="url(#' +
        uid +
        ')" stroke-width="2" stroke-linecap="round"/><path d="M6 4h12v6a6 6 0 0 1-12 0z" fill="url(#' +
        uid +
        ')"/><path d="M9 21h6" stroke="url(#' +
        uid +
        ')" stroke-width="2" stroke-linecap="round"/><path d="M12 17v4" stroke="url(#' +
        uid +
        ')" stroke-width="2" stroke-linecap="round"/></svg>',
      shield:
        '<svg width="' +
        size +
        '" height="' +
        size +
        '" viewBox="0 0 24 24" fill="none"><defs><linearGradient id="' +
        uid +
        '" x1="0%" y1="0%" x2="100%" y2="100%"><stop offset="0%" stop-color="#66BB6A"/><stop offset="100%" stop-color="#2E7D32"/></linearGradient></defs><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z" fill="url(#' +
        uid +
        ')" opacity="0.9"/><polyline points="9 12 11 14 15 10" fill="none" stroke="#fff" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>',
      edit:
        '<svg width="' + size + '" height="' + size + '" viewBox="0 0 24 24" fill="none"><defs><linearGradient id="' + uid + '" x1="0%" y1="0%" x2="100%" y2="100%"><stop offset="0%" stop-color="#FFB74D"/><stop offset="100%" stop-color="#F57C00"/></linearGradient></defs><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7" fill="none" stroke="url(#' + uid + ')" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z" fill="url(#' + uid + ')" opacity="0.9"/></svg>',
      arrowUp:
        '<svg width="' + size + '" height="' + size + '" viewBox="0 0 24 24" fill="none"><defs><linearGradient id="' + uid + '" x1="0%" y1="0%" x2="100%" y2="100%"><stop offset="0%" stop-color="#4FC3F7"/><stop offset="100%" stop-color="#1976D2"/></linearGradient></defs><circle cx="12" cy="12" r="11" fill="url(#' + uid + ')"/><line x1="12" y1="17" x2="12" y2="7" stroke="#fff" stroke-width="2.4" stroke-linecap="round"/><polyline points="7 11 12 6 17 11" fill="none" stroke="#fff" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"/></svg>',
      dice:
        '<svg width="' + size + '" height="' + size + '" viewBox="0 0 24 24" fill="none"><defs><linearGradient id="' + uid + '" x1="0%" y1="0%" x2="100%" y2="100%"><stop offset="0%" stop-color="#BA68C8"/><stop offset="100%" stop-color="#7B1FA2"/></linearGradient></defs><rect x="3" y="3" width="18" height="18" rx="3" fill="url(#' + uid + ')"/><circle cx="8" cy="8" r="1.5" fill="#fff"/><circle cx="16" cy="16" r="1.5" fill="#fff"/><circle cx="8" cy="16" r="1.5" fill="#fff"/><circle cx="16" cy="8" r="1.5" fill="#fff"/></svg>',
      bookmark:
        '<svg width="' + size + '" height="' + size + '" viewBox="0 0 24 24" fill="none"><defs><linearGradient id="' + uid + '" x1="0%" y1="0%" x2="100%" y2="100%"><stop offset="0%" stop-color="#FFD54F"/><stop offset="100%" stop-color="#FF8F00"/></linearGradient></defs><path d="M19 21l-7-5-7 5V5a2 2 0 0 1 2-2h10a2 2 0 0 1 2 2z" fill="url(#' + uid + ')" opacity="0.9"/></svg>',
      mic:
        '<svg width="' + size + '" height="' + size + '" viewBox="0 0 24 24" fill="none"><defs><linearGradient id="' + uid + '" x1="0%" y1="0%" x2="100%" y2="100%"><stop offset="0%" stop-color="#EF5350"/><stop offset="100%" stop-color="#C62828"/></linearGradient></defs><path d="M12 1a3 3 0 0 0-3 3v8a3 3 0 0 0 6 0V4a3 3 0 0 0-3-3z" fill="url(#' + uid + ')"/><path d="M19 10v2a7 7 0 0 1-14 0v-2" fill="none" stroke="url(#' + uid + ')" stroke-width="2" stroke-linecap="round"/><line x1="12" y1="19" x2="12" y2="23" stroke="url(#' + uid + ')" stroke-width="2" stroke-linecap="round"/></svg>',
      share:
        '<svg width="' + size + '" height="' + size + '" viewBox="0 0 24 24" fill="none"><defs><linearGradient id="' + uid + '" x1="0%" y1="0%" x2="100%" y2="100%"><stop offset="0%" stop-color="#4FC3F7"/><stop offset="100%" stop-color="#1976D2"/></linearGradient></defs><circle cx="18" cy="5" r="3" fill="url(#' + uid + ')"/><circle cx="6" cy="12" r="3" fill="url(#' + uid + ')"/><circle cx="18" cy="19" r="3" fill="url(#' + uid + ')"/><line x1="8.59" y1="13.51" x2="15.42" y2="17.49" stroke="url(#' + uid + ')" stroke-width="2" stroke-linecap="round"/><line x1="15.41" y1="6.51" x2="8.59" y2="10.49" stroke="url(#' + uid + ')" stroke-width="2" stroke-linecap="round"/></svg>',
      users:
        '<svg width="' + size + '" height="' + size + '" viewBox="0 0 24 24" fill="none"><defs><linearGradient id="' + uid + '" x1="0%" y1="0%" x2="100%" y2="100%"><stop offset="0%" stop-color="#66BB6A"/><stop offset="100%" stop-color="#2E7D32"/></linearGradient></defs><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2" fill="none" stroke="url(#' + uid + ')" stroke-width="2" stroke-linecap="round"/><circle cx="9" cy="7" r="4" fill="url(#' + uid + ')"/><path d="M23 21v-2a4 4 0 0 0-3-3.87" fill="none" stroke="url(#' + uid + ')" stroke-width="2" stroke-linecap="round"/><path d="M16 3.13a4 4 0 0 1 0 7.75" fill="none" stroke="url(#' + uid + ')" stroke-width="2" stroke-linecap="round"/></svg>',
      fire:
        '<svg width="' + size + '" height="' + size + '" viewBox="0 0 24 24" fill="none"><defs><linearGradient id="' + uid + '" x1="0%" y1="0%" x2="100%" y2="100%"><stop offset="0%" stop-color="#FF6B6B"/><stop offset="100%" stop-color="#E91E63"/></linearGradient></defs><path d="M8.5 14.5A2.5 2.5 0 0 0 11 12c0-1.38-.5-2-1-3-1.072-2.143-.224-4.054 2-6 .5 2.5 2 4.9 4 6.5 2 1.6 3 3.5 3 5.5a7 7 0 1 1-14 0c0-1.153.433-2.294 1-3a2.5 2.5 0 0 0 2.5 2.5z" fill="url(#' + uid + ')"/></svg>',
      calendar:
        '<svg width="' + size + '" height="' + size + '" viewBox="0 0 24 24" fill="none"><defs><linearGradient id="' + uid + '" x1="0%" y1="0%" x2="100%" y2="100%"><stop offset="0%" stop-color="#4FC3F7"/><stop offset="100%" stop-color="#1976D2"/></linearGradient></defs><rect x="3" y="4" width="18" height="18" rx="2" fill="url(#' + uid + ')" opacity="0.9"/><line x1="16" y1="2" x2="16" y2="6" stroke="#fff" stroke-width="2" stroke-linecap="round"/><line x1="8" y1="2" x2="8" y2="6" stroke="#fff" stroke-width="2" stroke-linecap="round"/><line x1="3" y1="10" x2="21" y2="10" stroke="#fff" stroke-width="2"/><text x="12" y="17" text-anchor="middle" fill="#fff" font-size="6" font-weight="bold">签到</text></svg>',
      compass:
        '<svg width="' + size + '" height="' + size + '" viewBox="0 0 24 24" fill="none"><defs><linearGradient id="' + uid + '" x1="0%" y1="0%" x2="100%" y2="100%"><stop offset="0%" stop-color="#4FC3F7"/><stop offset="100%" stop-color="#1976D2"/></linearGradient></defs><circle cx="12" cy="12" r="11" fill="url(#' + uid + ')" opacity="0.9"/><polygon points="16.24 7.76 14.12 14.12 7.76 16.24 9.88 9.88 16.24 7.76" fill="#fff"/><circle cx="12" cy="12" r="1" fill="url(#' + uid + ')"/></svg>',
      userPlus:
        '<svg width="' + size + '" height="' + size + '" viewBox="0 0 24 24" fill="none"><defs><linearGradient id="' + uid + '" x1="0%" y1="0%" x2="100%" y2="100%"><stop offset="0%" stop-color="#66BB6A"/><stop offset="100%" stop-color="#2E7D32"/></linearGradient></defs><path d="M16 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2" fill="none" stroke="url(#' + uid + ')" stroke-width="2" stroke-linecap="round"/><circle cx="8.5" cy="7" r="4" fill="url(#' + uid + ')"/><line x1="20" y1="8" x2="20" y2="14" stroke="url(#' + uid + ')" stroke-width="2" stroke-linecap="round"/><line x1="17" y1="11" x2="23" y2="11" stroke="url(#' + uid + ')" stroke-width="2" stroke-linecap="round"/></svg>',
      refresh:
        '<svg width="' + size + '" height="' + size + '" viewBox="0 0 24 24" fill="none"><defs><linearGradient id="' + uid + '" x1="0%" y1="0%" x2="100%" y2="100%"><stop offset="0%" stop-color="#66BB6A"/><stop offset="100%" stop-color="#2E7D32"/></linearGradient></defs><polyline points="23 4 23 10 17 10" fill="none" stroke="url(#' + uid + ')" stroke-width="2" stroke-linecap="round"/><polyline points="1 20 1 14 7 14" fill="none" stroke="url(#' + uid + ')" stroke-width="2" stroke-linecap="round"/><path d="M3.51 9a9 9 0 0 1 14.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0 0 20.49 15" fill="none" stroke="url(#' + uid + ')" stroke-width="2" stroke-linecap="round"/></svg>',
      music:
        '<svg width="' + size + '" height="' + size + '" viewBox="0 0 24 24" fill="none"><defs><linearGradient id="' + uid + '" x1="0%" y1="0%" x2="100%" y2="100%"><stop offset="0%" stop-color="#BA68C8"/><stop offset="100%" stop-color="#7B1FA2"/></linearGradient></defs><path d="M9 18V5l12-2v13" fill="none" stroke="url(#' + uid + ')" stroke-width="2" stroke-linecap="round"/><circle cx="6" cy="18" r="3" fill="url(#' + uid + ')"/><circle cx="18" cy="16" r="3" fill="url(#' + uid + ')"/></svg>',
      game:
        '<svg width="' + size + '" height="' + size + '" viewBox="0 0 24 24" fill="none"><defs><linearGradient id="' + uid + '" x1="0%" y1="0%" x2="100%" y2="100%"><stop offset="0%" stop-color="#FFB74D"/><stop offset="100%" stop-color="#F57C00"/></linearGradient></defs><rect x="2" y="6" width="20" height="12" rx="2" fill="url(#' + uid + ')" opacity="0.9"/><line x1="6" y1="12" x2="10" y2="12" stroke="#fff" stroke-width="2" stroke-linecap="round"/><line x1="8" y1="10" x2="8" y2="14" stroke="#fff" stroke-width="2" stroke-linecap="round"/><circle cx="15" cy="11" r="1.5" fill="#fff"/><circle cx="18" cy="10" r="1.5" fill="#fff"/><circle cx="15" cy="14" r="1.5" fill="#fff"/></svg>',
      film:
        '<svg width="' + size + '" height="' + size + '" viewBox="0 0 24 24" fill="none"><defs><linearGradient id="' + uid + '" x1="0%" y1="0%" x2="100%" y2="100%"><stop offset="0%" stop-color="#EF5350"/><stop offset="100%" stop-color="#C62828"/></linearGradient></defs><rect x="2" y="2" width="20" height="20" rx="2" fill="url(#' + uid + ')" opacity="0.9"/><polygon points="10 8 16 12 10 16 10 8" fill="#fff"/></svg>',
      book:
        '<svg width="' + size + '" height="' + size + '" viewBox="0 0 24 24" fill="none"><defs><linearGradient id="' + uid + '" x1="0%" y1="0%" x2="100%" y2="100%"><stop offset="0%" stop-color="#4FC3F7"/><stop offset="100%" stop-color="#1976D2"/></linearGradient></defs><path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20" fill="none" stroke="url(#' + uid + ')" stroke-width="2" stroke-linecap="round"/><path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z" fill="url(#' + uid + ')" opacity="0.85"/></svg>',
      download:
        '<svg width="' + size + '" height="' + size + '" viewBox="0 0 24 24" fill="none"><defs><linearGradient id="' + uid + '" x1="0%" y1="0%" x2="100%" y2="100%"><stop offset="0%" stop-color="#66BB6A"/><stop offset="100%" stop-color="#2E7D32"/></linearGradient></defs><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4" fill="none" stroke="url(#' + uid + ')" stroke-width="2" stroke-linecap="round"/><polyline points="7 10 12 15 17 10" fill="none" stroke="url(#' + uid + ')" stroke-width="2" stroke-linecap="round"/><line x1="12" y1="15" x2="12" y2="3" stroke="url(#' + uid + ')" stroke-width="2" stroke-linecap="round"/></svg>',
      upload:
        '<svg width="' + size + '" height="' + size + '" viewBox="0 0 24 24" fill="none"><defs><linearGradient id="' + uid + '" x1="0%" y1="0%" x2="100%" y2="100%"><stop offset="0%" stop-color="#4FC3F7"/><stop offset="100%" stop-color="#1976D2"/></linearGradient></defs><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4" fill="none" stroke="url(#' + uid + ')" stroke-width="2" stroke-linecap="round"/><polyline points="17 8 12 3 7 8" fill="none" stroke="url(#' + uid + ')" stroke-width="2" stroke-linecap="round"/><line x1="12" y1="3" x2="12" y2="15" stroke="url(#' + uid + ')" stroke-width="2" stroke-linecap="round"/></svg>',
      clock:
        '<svg width="' + size + '" height="' + size + '" viewBox="0 0 24 24" fill="none"><defs><linearGradient id="' + uid + '" x1="0%" y1="0%" x2="100%" y2="100%"><stop offset="0%" stop-color="#B0BEC5"/><stop offset="100%" stop-color="#607D8B"/></linearGradient></defs><circle cx="12" cy="12" r="11" fill="url(#' + uid + ')" opacity="0.9"/><polyline points="12 6 12 12 16 14" fill="none" stroke="#fff" stroke-width="2" stroke-linecap="round"/></svg>',
      settings:
        '<svg width="' + size + '" height="' + size + '" viewBox="0 0 24 24" fill="none"><defs><linearGradient id="' + uid + '" x1="0%" y1="0%" x2="100%" y2="100%"><stop offset="0%" stop-color="#B0BEC5"/><stop offset="100%" stop-color="#607D8B"/></linearGradient></defs><circle cx="12" cy="12" r="3" fill="url(#' + uid + ')"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83-2.83l.06-.06A1.65 1.65 0 0 0 4.68 15a1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 2.83-2.83l.06.06A1.65 1.65 0 0 0 9 4.68a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 2.83l-.06.06A1.65 1.65 0 0 0 19.4 9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z" fill="none" stroke="url(#' + uid + ')" stroke-width="2"/></svg>',
      mail:
        '<svg width="' + size + '" height="' + size + '" viewBox="0 0 24 24" fill="none"><defs><linearGradient id="' + uid + '" x1="0%" y1="0%" x2="100%" y2="100%"><stop offset="0%" stop-color="#FFB74D"/><stop offset="100%" stop-color="#F57C00"/></linearGradient></defs><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z" fill="url(#' + uid + ')" opacity="0.9"/><polyline points="22 6 12 13 2 6" fill="none" stroke="#fff" stroke-width="2" stroke-linecap="round"/></svg>',
      mapPin:
        '<svg width="' + size + '" height="' + size + '" viewBox="0 0 24 24" fill="none"><defs><linearGradient id="' + uid + '" x1="0%" y1="0%" x2="100%" y2="100%"><stop offset="0%" stop-color="#EF5350"/><stop offset="100%" stop-color="#C62828"/></linearGradient></defs><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z" fill="url(#' + uid + ')"/><circle cx="12" cy="10" r="3" fill="#fff"/></svg>',
      link:
        '<svg width="' + size + '" height="' + size + '" viewBox="0 0 24 24" fill="none"><defs><linearGradient id="' + uid + '" x1="0%" y1="0%" x2="100%" y2="100%"><stop offset="0%" stop-color="#4FC3F7"/><stop offset="100%" stop-color="#1976D2"/></linearGradient></defs><path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71" fill="none" stroke="url(#' + uid + ')" stroke-width="2" stroke-linecap="round"/><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71" fill="none" stroke="url(#' + uid + ')" stroke-width="2" stroke-linecap="round"/></svg>',
      phone:
        '<svg width="' + size + '" height="' + size + '" viewBox="0 0 24 24" fill="none"><defs><linearGradient id="' + uid + '" x1="0%" y1="0%" x2="100%" y2="100%"><stop offset="0%" stop-color="#66BB6A"/><stop offset="100%" stop-color="#2E7D32"/></linearGradient></defs><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6A19.79 19.79 0 0 1 2.08 4.18 2 2 0 0 1 4.08 2h3a2 2 0 0 1 2 1.72 12.84 12.84 0 0 0 .7 2.81 2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45 12.84 12.84 0 0 0 2.81.7A2 2 0 0 1 22 16.92z" fill="url(#' + uid + ')" opacity="0.9"/></svg>',
      code:
        '<svg width="' + size + '" height="' + size + '" viewBox="0 0 24 24" fill="none"><defs><linearGradient id="' + uid + '" x1="0%" y1="0%" x2="100%" y2="100%"><stop offset="0%" stop-color="#BA68C8"/><stop offset="100%" stop-color="#7B1FA2"/></linearGradient></defs><polyline points="16 18 22 12 16 6" fill="none" stroke="url(#' + uid + ')" stroke-width="2" stroke-linecap="round"/><polyline points="8 6 2 12 8 18" fill="none" stroke="url(#' + uid + ')" stroke-width="2" stroke-linecap="round"/></svg>',
      grid:
        '<svg width="' + size + '" height="' + size + '" viewBox="0 0 24 24" fill="none"><defs><linearGradient id="' + uid + '" x1="0%" y1="0%" x2="100%" y2="100%"><stop offset="0%" stop-color="#B0BEC5"/><stop offset="100%" stop-color="#607D8B"/></linearGradient></defs><rect x="3" y="3" width="7" height="7" rx="1" fill="url(#' + uid + ')"/><rect x="14" y="3" width="7" height="7" rx="1" fill="url(#' + uid + ')"/><rect x="3" y="14" width="7" height="7" rx="1" fill="url(#' + uid + ')"/><rect x="14" y="14" width="7" height="7" rx="1" fill="url(#' + uid + ')"/></svg>',
      fullscreen:
        '<svg width="' + size + '" height="' + size + '" viewBox="0 0 24 24" fill="none"><defs><linearGradient id="' + uid + '" x1="0%" y1="0%" x2="100%" y2="100%"><stop offset="0%" stop-color="#4FC3F7"/><stop offset="100%" stop-color="#1976D2"/></linearGradient></defs><polyline points="15 3 21 3 21 9" fill="none" stroke="url(#' + uid + ')" stroke-width="2" stroke-linecap="round"/><polyline points="9 21 3 21 3 15" fill="none" stroke="url(#' + uid + ')" stroke-width="2" stroke-linecap="round"/><line x1="21" y1="3" x2="14" y2="10" stroke="url(#' + uid + ')" stroke-width="2" stroke-linecap="round"/><line x1="3" y1="21" x2="10" y2="14" stroke="url(#' + uid + ')" stroke-width="2" stroke-linecap="round"/></svg>',
      thumbsUp:
        '<svg width="' + size + '" height="' + size + '" viewBox="0 0 24 24" fill="none"><defs><linearGradient id="' + uid + '" x1="0%" y1="0%" x2="100%" y2="100%"><stop offset="0%" stop-color="#4FC3F7"/><stop offset="100%" stop-color="#1976D2"/></linearGradient></defs><path d="M14 9V5a3 3 0 0 0-3-3l-4 9v11h11.28a2 2 0 0 0 2-1.7l1.38-9a2 2 0 0 0-2-2.3H14zM7 22H4a2 2 0 0 1-2-2v-7a2 2 0 0 1 2-2h3" fill="url(#' + uid + ')" opacity="0.9"/></svg>',
      filter:
        '<svg width="' + size + '" height="' + size + '" viewBox="0 0 24 24" fill="none"><defs><linearGradient id="' + uid + '" x1="0%" y1="0%" x2="100%" y2="100%"><stop offset="0%" stop-color="#BA68C8"/><stop offset="100%" stop-color="#7B1FA2"/></linearGradient></defs><polygon points="22 3 2 3 10 12.46 10 19 14 21 14 12.46 22 3" fill="url(#' + uid + ')" opacity="0.9"/></svg>',
      sort:
        '<svg width="' + size + '" height="' + size + '" viewBox="0 0 24 24" fill="none"><defs><linearGradient id="' + uid + '" x1="0%" y1="0%" x2="100%" y2="100%"><stop offset="0%" stop-color="#B0BEC5"/><stop offset="100%" stop-color="#607D8B"/></linearGradient></defs><line x1="4" y1="6" x2="16" y2="6" stroke="url(#' + uid + ')" stroke-width="2" stroke-linecap="round"/><line x1="4" y1="12" x2="12" y2="12" stroke="url(#' + uid + ')" stroke-width="2" stroke-linecap="round"/><line x1="4" y1="18" x2="20" y2="18" stroke="url(#' + uid + ')" stroke-width="2" stroke-linecap="round"/></svg>',
      globe:
        '<svg width="' + size + '" height="' + size + '" viewBox="0 0 24 24" fill="none"><defs><linearGradient id="' + uid + '" x1="0%" y1="0%" x2="100%" y2="100%"><stop offset="0%" stop-color="#4FC3F7"/><stop offset="100%" stop-color="#1976D2"/></linearGradient></defs><circle cx="12" cy="12" r="11" fill="url(#' + uid + ')" opacity="0.9"/><line x1="2" y1="12" x2="22" y2="12" stroke="#fff" stroke-width="1.5"/><path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z" fill="none" stroke="#fff" stroke-width="1.5"/></svg>',
    };
    return icons[name] || icons.info;
  }

  function showToast(message, type = 'info') {
    const container = document.getElementById('toastContainer');
    if (!container) return;
    const id = 'toast-' + ++toastIdCounter;
    const iconMap = { success: 'check', error: 'x', warning: 'alert', info: 'info' };
    const iconSvg = svgIcon(iconMap[type] || 'info', 18);
    const toast = document.createElement('div');
    toast.className = 'toast toast-' + type;
    toast.id = id;
    toast.style.position = 'relative';
    toast.style.overflow = 'hidden';
    toast.innerHTML =
      '<span class="toast-icon">' + iconSvg + '</span><span class="toast-message">' + escapeHtml(message) + '</span>';
    const bar = document.createElement('div');
    bar.className = 'toast-progress';
    toast.appendChild(bar);
    container.appendChild(toast);
    const dismissTimeout = setTimeout(() => {
      dismissToast(toast);
    }, 3000);
    toast.addEventListener('click', () => {
      clearTimeout(dismissTimeout);
      dismissToast(toast);
    });
    return id;
  }

  function dismissToast(toast) {
    if (!toast || toast.classList.contains('toast-dismissing')) return;
    toast.classList.add('toast-dismissing');
    toast.addEventListener(
      'animationend',
      () => {
        if (toast.parentNode) toast.parentNode.removeChild(toast);
      },
      { once: true },
    );

    setTimeout(() => {
      if (toast.parentNode) toast.parentNode.removeChild(toast);
    }, 350);
  }

  // ===== 主题（唯一真源 window.LWTheme，见 includes/theme_boot.php）=====
  // 三态循环：跟随系统 → 浅色 → 深色 → 跟随系统。
  // 首帧上色与「系统主题实时跟随」都由 theme_boot.php 在 <head> 同步完成，
  // 这里只负责：用户点击 → 改偏好 → 同步按钮外观 → 必要时上报服务端。
  function themeLabel(pref) {
    var tr = function (k, fb) { return (typeof window.__t === 'function') ? window.__t(k) : fb; };
    if (pref === 'dark') return tr('uc.theme_dark', '深色');
    if (pref === 'light') return tr('uc.theme_light', '浅色');
    return tr('uc.theme_system', '跟随系统');
  }

  function themeIcon(pref) {
    if (pref === 'dark') return svgIcon('moon', 16);
    if (pref === 'light') return svgIcon('sun', 16);
    // 跟随系统：半明半暗的圆，一眼可辨「自动」
    return '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M12 3a9 9 0 0 0 0 18z" fill="currentColor" stroke="none"/></svg>';
  }

  function syncThemeToggleUI(pref) {
    if (window.LWTheme && !pref) pref = window.LWTheme.pref();
    var btn = document.getElementById('themeToggle');
    if (!btn) return;
    var ico = btn.querySelector('.lw-theme-ico');
    var label = btn.querySelector('.theme-toggle-label');
    if (ico) ico.innerHTML = themeIcon(pref);
    if (label) label.textContent = themeLabel(pref);
    btn.setAttribute('aria-label', themeLabel(pref));
    btn.setAttribute('data-theme-pref', pref);
  }

  function reportTheme(pref) {
    if (!IS_LOGGED_IN) return;
    fetchAPI('/api/user/update_theme.php', { method: 'POST', body: 'theme=' + encodeURIComponent(pref) }).catch(() => {});
  }

  function applyTheme(pref) {
    if (!window.LWTheme) return;
    window.LWTheme.withTransition();
    window.LWTheme.set(pref);
    syncThemeToggleUI(pref);
  }

  function toggleTheme() {
    if (!window.LWTheme) return;
    window.LWTheme.withTransition();
    var pref = window.LWTheme.next();
    syncThemeToggleUI(pref);
    reportTheme(pref);
  }

  function initTheme() {
    // 首帧已由 theme_boot.php 上色，这里只把按钮外观同步到当前偏好
    syncThemeToggleUI();
  }

  function bindThemeToggle() {
    var btn = document.getElementById('themeToggle');
    if (btn && !btn.__lwThemeBound) {
      btn.__lwThemeBound = true;
      btn.addEventListener('click', toggleTheme);
    }
    // 偏好可能被其它入口（快捷键 t / 工具条 / 命令面板 / 其它标签页）改动，
    // 订阅一次即可让按钮外观始终跟手
    if (window.LWTheme && window.LWTheme.subscribe) {
      window.LWTheme.subscribe(function (pref) { syncThemeToggleUI(pref); });
    }
    syncThemeToggleUI();
  }

  const PostFeed = {
    container: null,
    loadMoreBtn: null,
    loadMoreWrap: null,
    noMoreEl: null,
    currentCategory: 'all',
    currentSort: 'latest',
    currentPage: 1,
    hasMore: true,
    isLoading: false,
    searchKeyword: '',
    _lastPollTime: null,
    _pollTimer: null,

    init() {
      this.container = document.getElementById('postsContainer');
      this.loadMoreBtn = document.getElementById('loadMoreBtn');
      this.loadMoreWrap = document.getElementById('loadMore');
      this.noMoreEl = document.getElementById('noMore');
      if (!this.container) return;

      const hash = window.location.hash.replace('#', '');
      if (hash) {
        this.currentCategory = hash;
        this.updateCategoryButtons();
      }
      this.bindCategoryFilters();
      this.bindSortTabs();
      this.bindLoadMore();
      this.bindInfiniteScroll();
      this.loadPosts(true);
      this.startPolling();
    },

    startPolling() {
      if (!this.container) return;
      this._lastPollTime = new Date().toISOString();
      this._pollTimer = setInterval(() => {
        // 页面在后台/切到别的标签时跳过：既省流量也省电，
        // 反正用户看不见，回来时下面的 visibilitychange 会立刻补一次。
        if (document.hidden) return;
        this.doPoll();
      }, 30000);
      document.addEventListener('visibilitychange', () => {
        if (!document.hidden) this.doPoll();
      });
    },

    getVisiblePostIds() {
      const ids = [];
      document.querySelectorAll('.post-card[data-post-id]').forEach((card) => {
        ids.push(card.getAttribute('data-post-id'));
      });
      return ids;
    },

    async doPoll() {
      // 游客无权限拉取帖子动态数据，直接跳过，避免持续 401
      if (window.IS_GUEST) return;
      const ids = this.getVisiblePostIds();
      if (ids.length === 0) return;
      try {
        const url = '/api/posts/poll.php?ids=' + ids.join(',') + '&since=' + encodeURIComponent(this._lastPollTime);
        const data = await fetchAPI(url);
        this._lastPollTime = new Date().toISOString();
        if (data.data && data.data.posts) {
          for (const [postId, stats] of Object.entries(data.data.posts)) {
            this.updatePostStats(postId, stats);
          }
        }
        if (data.data && data.data.new_comments) {
          for (const [postId, comments] of Object.entries(data.data.new_comments)) {
            this.addNewCommentsInline(postId, comments);
          }
        }
      } catch (err) {}
    },

    updatePostStats(postId, stats) {
      const card = this.container.querySelector('.post-card[data-post-id="' + postId + '"]');
      if (!card) return;
      const likeCount = card.querySelector('.like-btn .action-count');
      const commentCount = card.querySelector('.comment-btn .action-count');
      if (likeCount && stats.like_count !== undefined) {
        likeCount.textContent = stats.like_count;
      }
      if (commentCount && stats.comment_count !== undefined) {
        commentCount.textContent = stats.comment_count;
      }
    },

    addNewCommentsInline(postId, comments) {
      if (!comments || !comments.length) return;
      const card = this.container.querySelector('.post-card[data-post-id="' + postId + '"]');
      if (!card) return;
      let commentPanel = card.querySelector('.inline-comments-panel');
      if (!commentPanel) {
        commentPanel = document.createElement('div');
        commentPanel.className = 'inline-comments-panel';
        commentPanel.style.cssText =
          'margin-top:8px;padding-top:8px;border-top:1px solid var(--border);max-height:200px;overflow-y:auto;';
        card.appendChild(commentPanel);
      }
      comments.forEach((c) => {
        if (commentPanel.querySelector('.inline-comment[data-comment-id="' + c.id + '"]')) return;
        const authorName = c.is_anonymous ? '匿名用户' : c.author ? c.author.nickname : '匿名用户';
        const commentEl = document.createElement('div');
        commentEl.className = 'inline-comment';
        commentEl.setAttribute('data-comment-id', c.id);
        commentEl.style.cssText =
          'padding:6px 0;font-size:13px;border-bottom:1px solid var(--border-light);animation:fadeInUp 0.3s ease;';
        commentEl.innerHTML =
          '<span style="font-weight:600;color:var(--primary);">' +
          escapeHtml(authorName) +
          '</span>' +
          '<span style="color:var(--text-secondary);margin-left:8px;">' +
          escapeHtml(c.content) +
          '</span>' +
          '<span style="color:var(--text-muted);font-size:11px;float:right;">' +
          formatTime(c.created_at) +
          '</span>';
        commentPanel.appendChild(commentEl);
      });
    },

    bindCategoryFilters() {
      const buttons = document.querySelectorAll('.cat-filter');
      buttons.forEach((btn) => {
        btn.addEventListener('click', () => {
          const cat = btn.dataset.cat;
          if (cat === this.currentCategory) return;
          this.currentCategory = cat;
          this.currentPage = 1;
          this.hasMore = true;
          window.location.hash = cat === 'all' ? '' : cat;
          this.updateCategoryButtons();
          this.loadPosts(true);
        });
      });
    },

    updateCategoryButtons() {
      document.querySelectorAll('.cat-filter').forEach((btn) => {
        btn.classList.toggle('active', btn.dataset.cat === this.currentCategory);
      });
    },

    bindSortTabs() {
      const tabs = document.querySelectorAll('.sort-tab');
      tabs.forEach((tab) => {
        tab.addEventListener('click', () => {
          const sort = tab.dataset.sort;
          if (sort === this.currentSort) return;
          this.currentSort = sort;
          this.currentPage = 1;
          this.hasMore = true;
          tabs.forEach((t) => t.classList.toggle('active', t.dataset.sort === sort));
          this.loadPosts(true);
        });
      });
    },

    bindLoadMore() {
      if (this.loadMoreBtn) {
        this.loadMoreBtn.addEventListener('click', () => {
          this.loadPosts(false);
        });
      }
    },

    bindInfiniteScroll() {
      const scrollHandler = throttle(() => {
        if (this.isLoading || !this.hasMore) return;
        const scrollTop = window.scrollY;
        const windowHeight = window.innerHeight;
        const documentHeight = document.documentElement.scrollHeight;
        if (scrollTop + windowHeight >= documentHeight - 300) {
          this.loadPosts(false);
        }
      }, 200);
      window.addEventListener('scroll', scrollHandler, { passive: true });
    },

    async loadPosts(reset) {
      if (!this.container || this.isLoading) return;
      if (reset) {
        this.currentPage = 1;
        this.hasMore = true;
      }
      if (!this.hasMore) return;
      this.isLoading = true;
      this.showLoading(reset);
      if (reset) {
        // 首次/筛选切换时用骨架屏占位，避免空白闪烁（enhancements.js 提供 SkeletonLoader）
        try {
          if (window.App && typeof App.SkeletonLoader !== 'undefined') {
            App.SkeletonLoader.show(this.container, 4);
          }
        } catch (e) { /* 忽略，无骨架屏时退化为空白 */ }
      }
      try {
        let url =
          '/api/posts/list.php?category=' +
          encodeURIComponent(categoryUrlKey(this.currentCategory)) +
          '&sort=' +
          encodeURIComponent(this.currentSort) +
          '&page=' +
          this.currentPage +
          '&limit=20';
        if (this.searchKeyword) {
          url += '&search=' + encodeURIComponent(this.searchKeyword);
        }
        const data = await fetchAPI(url);
        const posts = data.data.posts || [];
        const pagination = data.data.pagination || {};
        if (reset) {
          this.container.innerHTML = '';
        }
        if (posts.length === 0 && reset) {
          this.container.innerHTML =
            '<div class="empty-state"><div class="empty-icon">' + LWIllustration('posts') + '</div><p>暂无帖子</p></div>';
          this.hasMore = false;
          this.hideLoading();
          return;
        }
        const frag = document.createDocumentFragment();
        posts.forEach((post) => {
          frag.appendChild(this.createPostCard(post));
        });
        // 一次性挂载，避免逐条插入触发多次回流
        this.container.appendChild(frag);
        this.hasMore = pagination.has_more || false;
        this.currentPage++;
        if (this.hasMore) {
          this.showLoadMore();
        } else {
          this.showNoMore();
        }
      } catch (err) {
        if (reset) {
          this.container.innerHTML =
            '<div class="empty-state"><div class="empty-icon">' +
            LWIllustration('error') +
            '</div><p>加载失败，请刷新重试</p></div>';
        } else {
          showToast('加载更多失败', 'error');
        }
      } finally {
        this.isLoading = false;
        this.hideLoading();
      }
    },

    showLoading(reset) {
      if (this.loadMoreBtn) {
        this.loadMoreBtn.disabled = true;
        this.loadMoreBtn.textContent = '加载中...';
      }
    },

    hideLoading() {
      if (this.loadMoreBtn) {
        this.loadMoreBtn.disabled = false;
        this.loadMoreBtn.textContent = '加载更多';
      }
    },

    showLoadMore() {
      if (this.loadMoreWrap) this.loadMoreWrap.style.display = 'block';
      if (this.noMoreEl) this.noMoreEl.style.display = 'none';
    },

    showNoMore() {
      if (this.loadMoreWrap) this.loadMoreWrap.style.display = 'none';
      if (this.noMoreEl) this.noMoreEl.style.display = 'block';
    },

    createPostCard(post) {
      const card = document.createElement('div');
      card.className = 'post-card';
      card.setAttribute('data-post-id', post.id);

      const categoryNames = {
        announcement: '全站公告',
        lost_found: '寻物/失物招领',
        study_help: '学习求助',
        social_chat: '交友闲聊',
        confession: '表白',
        school_info: '校园打听',
        other: '其他',
      };

      const categoryBadge =
        post.is_pinned || post.category === 'announcement'
          ? '<span class="category-badge cat-announcement">' +
            svgIcon('bell', 12) +
            ' ' +
            (post.category === 'announcement' ? '全站公告' : '置顶') +
            '</span>'
          : '<span class="category-badge cat-' +
            post.category +
            '">' +
            (categoryNames[post.category] || post.category) +
            '</span>';

      const anonBadge = post.is_anonymous ? '<span class="anon-badge">匿名</span>' : '';

      let imagesHtml = '';
      if (post.images && post.images.length > 0) {
        const gridClass = 'grid-' + Math.min(post.images.length, 4);
        imagesHtml = '<div class="post-card-images ' + gridClass + '">';
        post.images.forEach((img, idx) => {
          imagesHtml +=
            '<img src="' +
            escapeHtml(img) +
            '" alt="图片' +
            (idx + 1) +
            '" data-full="' +
            escapeHtml(img) +
            '" data-index="' +
            idx +
            '" loading="lazy" decoding="async" width="400" height="400" onerror="this.style.display=\'none\'">';
        });
        imagesHtml += '</div>';
      }

      const authorHtml = post.author
        ? '<div class="post-card-author">' +
          (post.author.avatar
            ? '<img src="' +
              escapeHtml(post.author.avatar) +
              '" class="author-avatar"' +
              (post.author.qq && post.author.id ? ' data-qq="' + escapeHtml(post.author.qq) + '" data-name="' + escapeHtml(post.author.nickname || '同学') + '" title="发送私信"' : '') +
              ' alt="头像" decoding="async" width="32" height="32" onerror="this.style.display=\'none\'">'
            : '') +
          '<span class="author-name">' +
          escapeHtml(post.author.nickname || '匿名用户') +
          '</span>' +
          (post.author.title_text ? renderUserTitle(post.author) : '') +
          '</div>'
        : '';

      const titleHtml = post.title
        ? '<h3 class="post-card-title" style="font-size:1.05rem;margin-bottom:8px;">' + escapeHtml(post.title) + '</h3>'
        : '';

      // 公告与普通帖子一样支持点赞 / 评论 / 收藏 / 分享
      // （公告只是「管理员发布 + 永远置顶」，不再是只读展示）
      let actionsHtml = '<div class="post-card-actions">';
      actionsHtml +=
        '<button class="action-btn like-btn' +
        (post.is_liked ? ' liked' : '') +
        '" data-post-id="' +
        post.id +
        '" aria-label="点赞">' +
        '<span class="action-icon">' +
        (post.is_liked ? svgIcon('heart', 16) : svgIcon('heartOutline', 16)) +
        '</span>' +
        '<span class="action-count">' +
        (post.like_count || 0) +
        '</span>' +
        '</button>' +
        '<button class="action-btn comment-btn" data-post-id="' +
        post.id +
        '" aria-label="评论">' +
        '<span class="action-icon">' +
        svgIcon('message', 16) +
        '</span>' +
        '<span class="action-count">' +
        (post.comment_count || 0) +
        '</span>' +
        '</button>' +
        '<button class="action-btn favorite-btn' +
        (post.is_favorited ? ' favorited' : '') +
        '" data-post-id="' +
        post.id +
        '" aria-label="收藏">' +
        '<span class="action-icon">' +
        (post.is_favorited ? svgIcon('star', 16) : svgIcon('starOutline', 16)) +
        '</span>' +
        '</button>' +
        '<button class="action-btn share-btn" data-post-id="' +
        post.id +
        '" aria-label="分享">' +
        '<span class="action-icon">' +
        svgIcon('share', 16) +
        '</span>' +
        '</button>';
      actionsHtml +=
        '<button class="action-btn copy-btn" data-content="' +
        escapeHtml(post.content) +
        '" aria-label="复制内容">' +
        '<span class="action-icon">' +
        svgIcon('copy', 16) +
        '</span>' +
        '</button>' +
        '</div>';

      const pollHtml = this.renderPollWidget(post);
      card.innerHTML =
        '<div class="post-card-header">' +
        categoryBadge +
        anonBadge +
        '<span class="post-time">' +
        escapeHtml(post.timeAgo || formatTime(post.created_at)) +
        '</span>' +
        '</div>' +
        titleHtml +
        '<div class="post-card-content">' +
        escapeHtml(post.content).replace(/\n/g, '<br>') +
        '</div>' +
        imagesHtml +
        (pollHtml ? '<div class="post-card-poll">' + pollHtml + '</div>' : '') +
        actionsHtml +
        authorHtml;

      card.addEventListener('click', (e) => {
        if (e.target.closest('button, img, .action-btn, .post-card-images')) return;
        // 游客：帖子详情需注册，给出提示后再跳转注册页，避免无反馈的静默跳转
        if (window.IS_GUEST) {
          showToast(window.GUEST_BLOCKED_MSG || '需要注册账号', 'info');
          setTimeout(() => {
            window.location.href = SITE_URL + '/pages/register.php';
          }, 900);
          return;
        }
        window.location.href = '/pages/post_detail.php?id=' + post.id;
      });
      card.style.cursor = 'pointer';

      this.bindPostCardEvents(card, post);
      return card;
    },

    // 投票组件 HTML（结果在未投票时隐藏，投票后由 bindPoll 揭示）
    renderPollWidget(post) {
      const p = post.poll;
      if (!p || !Array.isArray(p.options) || p.options.length === 0) return '';
      const total = p.total || 0;
      const counts = p.counts || [];
      const voted = !!p.has_voted;
      const myVote = p.my_vote;
      let opts = '';
      p.options.forEach((opt, i) => {
        const c = counts[i] || 0;
        const pct = total > 0 ? Math.round((c / total) * 100) : 0;
        const isMine = voted && myVote === i;
        const showRes = voted;
        opts +=
          '<div class="poll-option' +
          (isMine ? ' selected' : '') +
          '" data-index="' +
          i +
          '">' +
          '<span class="poll-label">' +
          escapeHtml(opt) +
          '</span>' +
          (showRes
            ? '<span class="poll-pct">' + pct + '%</span>'
            : '<span class="poll-pct" style="display:none"></span>') +
          (showRes
            ? '<span class="poll-bar-wrap"><i class="poll-bar" style="width:' + pct + '%"></i></span>'
            : '<span class="poll-bar-wrap" style="display:none"><i class="poll-bar" style="width:0%"></i></span>') +
          '</div>';
      });
      return (
        '<div class="poll-widget' +
        (voted ? ' poll-voted' : '') +
        '" data-post-id="' +
        post.id +
        '">' +
        (p.question ? '<div class="poll-question">' + escapeHtml(p.question) + '</div>' : '') +
        '<div class="poll-results">' +
        opts +
        '</div>' +
        '<div class="poll-vote-actions">' +
        (voted
          ? '<span class="poll-voted-note">' + svgIcon('check', 14) + ' 已投票</span>'
          : '<button type="button" class="poll-vote-btn">投票</button>') +
        '<span class="poll-total">' +
        total +
        ' 人参与</span>' +
        '</div>' +
        '</div>'
      );
    },

    // 绑定投票交互
    bindPoll(card, post) {
      const poll = card.querySelector('.poll-widget');
      if (!poll) return;
      const optionEls = poll.querySelectorAll('.poll-option');
      optionEls.forEach((el) => {
        el.addEventListener('click', () => {
          if (poll.classList.contains('poll-voted')) return;
          optionEls.forEach((o) => o.classList.remove('selected'));
          el.classList.add('selected');
        });
      });
      const voteBtn = poll.querySelector('.poll-vote-btn');
      if (!voteBtn) return;
      voteBtn.addEventListener('click', async () => {
        if (poll.classList.contains('poll-voted')) return;
        if (!IS_LOGGED_IN) {
          showToast('请先登录后投票', 'warning');
          return;
        }
        const sel = poll.querySelector('.poll-option.selected');
        if (!sel) {
          showToast('请先选择一个选项', 'warning');
          return;
        }
        const postId = poll.getAttribute('data-post-id');
        const option = sel.getAttribute('data-index');
        try {
          const res = await fetchAPI('/api/posts/vote.php', {
            method: 'POST',
            body: 'post_id=' + postId + '&option=' + encodeURIComponent(option),
          });
          if (!res.success) {
            showToast(res.message || '投票失败', 'error');
            return;
          }
          poll.classList.add('poll-voted');
          const results = res.data || [];
          const total = results.reduce((s, r) => s + (r.count || 0), 0) || 1;
          optionEls.forEach((o, i) => {
            const c = results[i] ? results[i].count : 0;
            const pct = Math.round((c / total) * 100);
            const barWrap = o.querySelector('.poll-bar-wrap');
            const bar = o.querySelector('.poll-bar');
            const pctEl = o.querySelector('.poll-pct');
            if (barWrap) barWrap.style.display = '';
            if (bar) bar.style.width = pct + '%';
            if (pctEl) {
              pctEl.style.display = '';
              pctEl.textContent = pct + '%';
            }
          });
          if (voteBtn) {
            voteBtn.outerHTML = '<span class="poll-voted-note">' + svgIcon('check', 14) + ' 已投票</span>';
          }
          const totalEl = poll.querySelector('.poll-total');
          if (totalEl) totalEl.textContent = total + ' 人参与';
          showToast('投票成功', 'success');
        } catch (err) {
          showToast('网络错误，请重试', 'error');
        }
      });
    },

    bindPostCardEvents(card, post) {
      this.bindPoll(card, post);
      const likeBtn = card.querySelector('.like-btn');
      if (likeBtn) {
        likeBtn.addEventListener('click', async (e) => {
          e.preventDefault();
          if (!IS_LOGGED_IN) {
            showToast('请先登录', 'warning');
            setTimeout(() => {
              window.location.href = SITE_URL + '/pages/login.php';
            }, 1000);
            return;
          }
          try {
            const data = await fetchAPI('/api/posts/like.php', { method: 'POST', body: 'post_id=' + post.id });
            const icon = likeBtn.querySelector('.action-icon');
            const count = likeBtn.querySelector('.action-count');
            if (data.data.is_liked) {
              likeBtn.classList.add('liked');
              if (icon) icon.innerHTML = svgIcon('heart', 16);
            } else {
              likeBtn.classList.remove('liked');
              if (icon) icon.innerHTML = svgIcon('heartOutline', 16);
            }
            if (count) count.textContent = data.data.like_count;
          } catch (err) {
            showToast(err.message || '点赞失败，请重试', 'error');
          }
        });
      }

      const favBtn = card.querySelector('.favorite-btn');
      if (favBtn) {
        favBtn.addEventListener('click', async (e) => {
          e.preventDefault();
          if (!IS_LOGGED_IN) {
            showToast('请先登录', 'warning');
            setTimeout(() => {
              window.location.href = SITE_URL + '/pages/login.php';
            }, 1000);
            return;
          }
          try {
            const data = await fetchAPI('/api/posts/favorite.php', { method: 'POST', body: 'post_id=' + post.id });
            const icon = favBtn.querySelector('.action-icon');
            if (data.data.is_favorited) {
              favBtn.classList.add('favorited');
              if (icon) icon.innerHTML = svgIcon('star', 16);
              showToast('已收藏', 'success');
            } else {
              favBtn.classList.remove('favorited');
              if (icon) icon.innerHTML = svgIcon('starOutline', 16);
              showToast('已取消收藏', 'info');
            }
          } catch (err) {
            showToast(err.message || '收藏操作失败，请重试', 'error');
          }
        });
      }

      const copyBtn = card.querySelector('.copy-btn');
      if (copyBtn) {
        copyBtn.addEventListener('click', (e) => {
          e.preventDefault();
          copyToClipboard(copyBtn.dataset.content);
        });
      }

      // 分享按钮统一交给 enhancements.js 的 PostShare 面板处理（支持复制/微博/QQ/微信），
      // 此处不再直绑复制，避免同一次点击既复制又弹面板的重复触发。

      // 同一条动态的多张图作为一组：点开任意一张后可在组内左右滑切换
      const images = Array.from(card.querySelectorAll('.post-card-images img'));
      const imageSrcs = images.map((im) => im.dataset.full || im.src);
      images.forEach((img, i) => {
        img.addEventListener('click', () => {
          Lightbox.openGroup(imageSrcs, i);
        });
      });

      const authorAvatar = card.querySelector('.author-avatar');
      if (authorAvatar && authorAvatar.dataset.qq && window.App && window.App.PrivateMessage) {
        authorAvatar.addEventListener('click', (e) => {
          e.preventDefault();
          e.stopPropagation();
          App.PrivateMessage.openWith(authorAvatar.dataset.qq, {
            qq: authorAvatar.dataset.qq,
            nickname: authorAvatar.dataset.name || '同学',
            avatar: authorAvatar.src
          });
        });
      }

      const commentBtn = card.querySelector('.comment-btn');
      if (commentBtn) {
        commentBtn.addEventListener('click', () => {
          window.location.href = SITE_URL + '/pages/post_detail.php?id=' + post.id;
        });
      }
    },
  };

  const Lightbox = {
    overlay: null,
    img: null,
    counter: null,
    prevBtn: null,
    nextBtn: null,
    // 同帖图片组：左右滑 / 方向键在组内切换
    _srcs: [],
    _idx: 0,
    // 缩放与位移（缩放态下拖动平移，非缩放态下左右滑切换、下滑关闭）
    _scale: 1,
    _tx: 0,
    _ty: 0,
    _pinching: false,
    _startDist: 0,
    _startScale: 1,
    _dx0: 0,
    _dy0: 0,
    _dragging: false,
    _lastTap: 0,
    // 缩放态下每次平移的基准位移（松手时回写）
    _pendingTx: 0,
    _pendingTy: 0,

    init() {
      this.overlay = document.createElement('div');
      this.overlay.className = 'lightbox-overlay';
      this.overlay.style.cssText =
        'display:none;position:fixed;inset:0;z-index:500;background:rgba(0,0,0,0.9);' +
        'align-items:center;justify-content:center;cursor:pointer;' +
        'touch-action:none;overscroll-behavior:contain;-webkit-tap-highlight-color:transparent;';
      this.img = document.createElement('img');
      this.img.style.cssText =
        'max-width:90vw;max-height:90vh;object-fit:contain;border-radius:8px;' +
        'transition:transform 0.18s ease;will-change:transform;';
      this.img.alt = '预览图片';
      const closeBtn = document.createElement('button');
      closeBtn.innerHTML = svgIcon('x', 20);
      closeBtn.style.cssText =
        'position:absolute;top:20px;right:20px;color:#fff;font-size:28px;background:none;border:none;cursor:pointer;width:48px;height:48px;display:flex;align-items:center;justify-content:center;z-index:1;';
      this.counter = document.createElement('div');
      this.counter.style.cssText =
        'position:absolute;top:28px;left:50%;transform:translateX(-50%);color:#fff;font-size:0.85rem;' +
        'font-family:var(--font-num, monospace);letter-spacing:0.08em;opacity:0.85;pointer-events:none;';
      const chevron = (dir) => {
        const b = document.createElement('button');
        b.setAttribute('aria-label', dir === 'prev' ? '上一张' : '下一张');
        b.innerHTML = dir === 'prev'
          ? '<svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="15 18 9 12 15 6"/></svg>'
          : '<svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 18 15 12 9 6"/></svg>';
        b.style.cssText =
          'position:absolute;top:50%;transform:translateY(-50%);' + (dir === 'prev' ? 'left:14px;' : 'right:14px;') +
          'color:#fff;background:rgba(255,255,255,0.14);border:none;border-radius:50%;width:44px;height:44px;' +
          'display:flex;align-items:center;justify-content:center;cursor:pointer;z-index:1;';
        return b;
      };
      this.prevBtn = chevron('prev');
      this.nextBtn = chevron('next');
      this.overlay.appendChild(closeBtn);
      this.overlay.appendChild(this.counter);
      this.overlay.appendChild(this.prevBtn);
      this.overlay.appendChild(this.nextBtn);
      this.overlay.appendChild(this.img);
      document.body.appendChild(this.overlay);

      this.overlay.addEventListener('click', (e) => {
        if (e.target === this.overlay || e.target === closeBtn) { this.close(); return; }
        if (e.target === this.prevBtn || this.prevBtn.contains(e.target)) { this.show(this._idx - 1); return; }
        if (e.target === this.nextBtn || this.nextBtn.contains(e.target)) { this.show(this._idx + 1); }
      });

      document.addEventListener('keydown', (e) => {
        if (this.overlay.style.display !== 'flex') { return; }
        if (e.key === 'Escape') { this.close(); }
        else if (e.key === 'ArrowLeft') { this.show(this._idx - 1); }
        else if (e.key === 'ArrowRight') { this.show(this._idx + 1); }
      });

      this._bindGestures();
    },

    // 触摸手势：非缩放态左右滑切换、下滑关闭；缩放态单指平移；双指 pinch 缩放；双击放大/还原
    // 所有手势都 stopPropagation：否则右滑会冒泡到 document，被 enhancements.js 的 MobileGesture
    // 当成「右滑返回」，看着上一张图就跳出信息流。
    _bindGestures() {
      const dist = (t) => Math.hypot(t[0].clientX - t[1].clientX, t[0].clientY - t[1].clientY);

      this.overlay.addEventListener('touchstart', (e) => {
        e.stopPropagation();
        if (e.touches.length === 2) {
          this._pinching = true;
          this._dragging = false;
          this._startDist = dist(e.touches);
          this._startScale = this._scale;
          this.img.style.transition = 'none';
          return;
        }
        if (e.touches.length !== 1) { return; }
        this._dx0 = e.touches[0].clientX;
        this._dy0 = e.touches[0].clientY;
        this._dragging = true;
        this._pinching = false;
        this.img.style.transition = 'none';

        // 双击放大 / 还原
        const now = Date.now();
        if (now - this._lastTap < 300) {
          this._dragging = false;
          this._applyTransform(this._scale > 1 ? 1 : 2, 0, 0, true);
        }
        this._lastTap = now;
      }, { passive: true });

      this.overlay.addEventListener('touchmove', (e) => {
        e.stopPropagation();
        if (this._pinching && e.touches.length === 2) {
          const ratio = dist(e.touches) / (this._startDist || 1);
          const next = Math.min(4, Math.max(1, this._startScale * ratio));
          this._applyTransform(next, this._tx, this._ty, false);
          e.preventDefault();
          return;
        }
        if (!this._dragging || e.touches.length !== 1) { return; }
        const dx = e.touches[0].clientX - this._dx0;
        const dy = e.touches[0].clientY - this._dy0;
        if (this._scale > 1) {
          // 缩放态：拖动平移
          this._tx = this._pendingTx + dx;
          this._ty = this._pendingTy + dy;
          this._applyTransform(this._scale, this._tx, this._ty, false);
          e.preventDefault();
        } else if (Math.abs(dy) > Math.abs(dx)) {
          // 非缩放态：跟手下拉，松手超过阈值就关闭
          this.img.style.transform = 'translateY(' + Math.max(0, dy) + 'px)';
          e.preventDefault();
        }
      }, { passive: false });

      this.overlay.addEventListener('touchend', (e) => {
        e.stopPropagation();
        if (this._pinching && e.touches.length === 0) {
          this._pinching = false;
          if (this._scale < 1.05) { this._applyTransform(1, 0, 0, true); }
          this._pendingTx = this._tx;
          this._pendingTy = this._ty;
          return;
        }
        if (!this._dragging) { return; }
        this._dragging = false;
        this._pendingTx = this._tx;
        this._pendingTy = this._ty;
        if (this._scale > 1) { return; }
        if (e.changedTouches.length !== 1) { return; }
        const dx = e.changedTouches[0].clientX - this._dx0;
        const dy = e.changedTouches[0].clientY - this._dy0;
        if (Math.abs(dy) > 80 && Math.abs(dy) > Math.abs(dx)) { this.close(); return; }
        if (dx < -50 && this._srcs.length > 1) { this.show(this._idx + 1); return; }
        if (dx > 50 && this._srcs.length > 1) { this.show(this._idx - 1); return; }
        this._applyTransform(1, 0, 0, true);
      }, { passive: true });
    },

    _applyTransform(scale, tx, ty, animate) {
      this._scale = scale;
      this._tx = scale > 1 ? tx : 0;
      this._ty = scale > 1 ? ty : 0;
      if (scale <= 1) { this._pendingTx = 0; this._pendingTy = 0; }
      this.img.style.transition = animate ? 'transform 0.18s ease' : 'none';
      this.img.style.transform =
        scale === 1 ? 'none' : 'translate(' + this._tx + 'px,' + this._ty + 'px) scale(' + scale + ')';
      this.overlay.style.cursor = scale > 1 ? 'move' : 'pointer';
      this.prevBtn.style.display = (scale > 1 || this._srcs.length < 2) ? 'none' : 'flex';
      this.nextBtn.style.display = (scale > 1 || this._srcs.length < 2) ? 'none' : 'flex';
    },

    // 打开单张（保持旧签名可用）
    open(src) {
      this.openGroup([src], 0);
    },

    // 打开一组图片，idx 为当前张
    openGroup(srcs, idx) {
      if (!this.overlay) { this.init(); }
      this._srcs = (srcs || []).filter(Boolean);
      if (!this._srcs.length) { return; }
      this.overlay.style.display = 'flex';
      document.body.style.overflow = 'hidden';
      this._pendingTx = 0;
      this._pendingTy = 0;
      this.show(typeof idx === 'number' ? idx : 0);
    },

    show(i) {
      if (!this._srcs.length) { return; }
      const n = this._srcs.length;
      this._idx = ((i % n) + n) % n;
      this.img.src = this._srcs[this._idx];
      this._applyTransform(1, 0, 0, false);
      this.counter.textContent = n > 1 ? (this._idx + 1) + ' / ' + n : '';
    },

    close() {
      if (!this.overlay) return;
      this.overlay.style.display = 'none';
      this.img.src = '';
      this._srcs = [];
      this._applyTransform(1, 0, 0, false);
      document.body.style.overflow = '';
    },
  };

  const Search = {
    input: null,
    searchBtn: null,
    clearBtn: null,

    init() {
      this.input = document.getElementById('searchInput');
      this.searchBtn = document.getElementById('searchBtn');
      if (!this.input) return;

      this.clearBtn = document.createElement('button');
      this.clearBtn.className = 'search-clear-btn';
      this.clearBtn.innerHTML = svgIcon('x', 14);
      this.clearBtn.style.cssText =
        'display:none;position:absolute;right:8px;top:50%;transform:translateY(-50%);background:none;border:none;cursor:pointer;color:var(--text-muted);padding:4px;';
      this.input.parentNode.style.position = 'relative';
      this.input.parentNode.appendChild(this.clearBtn);

      const debouncedSearch = debounce(() => {
        this.performSearch();
      }, 300);
      this.input.addEventListener('input', () => {
        const val = this.input.value.trim();
        this.clearBtn.style.display = val ? 'inline-flex' : 'none';
        debouncedSearch();
      });

      this.clearBtn.addEventListener('click', () => {
        this.input.value = '';
        this.clearBtn.style.display = 'none';
        PostFeed.searchKeyword = '';
        PostFeed.currentPage = 1;
        PostFeed.hasMore = true;
        PostFeed.loadPosts(true);
      });

      if (this.searchBtn) {
        this.searchBtn.addEventListener('click', () => {
          this.performSearch();
        });
      }

      this.input.addEventListener('keydown', (e) => {
        if (e.key === 'Enter') {
          e.preventDefault();
          this.performSearch();
        }
      });
    },

    performSearch() {
      if (!this.input) return;
      const keyword = this.input.value.trim();
      PostFeed.searchKeyword = keyword;
      PostFeed.currentPage = 1;
      PostFeed.hasMore = true;
      PostFeed.loadPosts(true);
    },
  };

  const UserMenu = {
    btn: null,
    dropdown: null,

    init() {
      this.btn = document.getElementById('userMenuBtn');
      this.dropdown = document.getElementById('userDropdown');
      if (!this.btn || !this.dropdown) return;

      // 标记已接管：includes/site_header.php 里的兜底脚本见到此标记就自动让位，
      // 保证「不加载 main.js 的页面才有兜底绑定」，不会双重绑定。
      window.__lwUserMenuBound = true;

      this.btn.addEventListener('click', (e) => {
        e.stopPropagation();
        this.toggle();
      });

      document.addEventListener('click', (e) => {
        if (!this.btn.contains(e.target) && !this.dropdown.contains(e.target)) {
          this.hide();
        }
      });

      const logoutBtn = document.getElementById('logoutBtn');
      if (logoutBtn) {
        logoutBtn.addEventListener('click', async (e) => {
          e.preventDefault();
          try {
            await fetchAPI('/api/auth/logout.php', { method: 'POST' });
            showToast('已退出登录', 'success');
            setTimeout(() => {
              window.location.href = SITE_URL + '/';
            }, 500);
          } catch (err) {
            window.location.href = SITE_URL + '/';
          }
        });
      }
    },

    toggle() {
      if (this.dropdown) this.dropdown.classList.toggle('show');
    },

    hide() {
      if (this.dropdown) this.dropdown.classList.remove('show');
    },
  };

  function initBanBanner() {
    const countdownEl = document.querySelector('.ban-countdown');
    if (!countdownEl) return;
    const untilAttr = countdownEl.getAttribute('data-until');
    if (!untilAttr) return;

    function updateCountdown() {
      const now = new Date().getTime();
      const until = new Date(untilAttr.replace(/-/g, '/')).getTime();
      const remaining = Math.max(0, until - now);
      if (remaining <= 0) {
        countdownEl.textContent = '解封时间已到，请刷新页面';
        clearInterval(timer);
        return;
      }
      const days = Math.floor(remaining / 86400000);
      const hours = Math.floor((remaining % 86400000) / 3600000);
      const minutes = Math.floor((remaining % 3600000) / 60000);
      const seconds = Math.floor((remaining % 60000) / 1000);
      const parts = [];
      if (days > 0) parts.push(days + '天');
      if (hours > 0 || days > 0) parts.push(hours + '小时');
      if (minutes > 0 || hours > 0 || days > 0) parts.push(minutes + '分');
      parts.push(seconds + '秒');
      countdownEl.textContent = '剩余：' + parts.join('');
    }

    updateCountdown();
    const timer = setInterval(updateCountdown, 1000);
  }

  const FormHelpers = {
    init() {
      this.initQQValidation();
      this.initPasswordStrength();
      this.initPasswordToggle();
      this.initConfirmPassword();
      this.initFormSubmitDebounce();
    },

    initQQValidation() {
      const qqInput = document.querySelector('input[name="qq"], #qq');
      if (!qqInput) return;
      const qqError = document.getElementById('qqError');
      const qqErrorText = document.getElementById('qqErrorText');
      const qqAvatar = document.getElementById('qqAvatar');
      qqInput.addEventListener('input', function () {
        let val = this.value.replace(/\D/g, '');
        this.value = val;
        if (qqError) qqError.classList.remove('show');
        if (qqInput) qqInput.classList.remove('input-error');
        if (val.length === 0) {
          if (qqAvatar) qqAvatar.classList.remove('show');
          return;
        }

        if (val.length >= 1 && val[0] === '0') {
          if (qqErrorText) qqErrorText.textContent = 'QQ号不能以0开头';
          if (qqError) qqError.classList.add('show');
          if (qqInput) qqInput.classList.add('input-error');
          return;
        }

        if (val.length >= 5 && /^[1-9][0-9]{4,14}$/.test(val)) {
          if (qqAvatar) {
            qqAvatar.src = 'https://q.qlogo.cn/headimg_dl?dst_uin=' + val + '&spec=100';
            qqAvatar.classList.add('show');
          }
        } else {
          if (qqAvatar) qqAvatar.classList.remove('show');
        }
      });
    },

    initPasswordStrength() {
      const pwdInput = document.querySelector('input[name="password"], #password');
      if (!pwdInput) return;
      const strengthEl = document.getElementById('passwordStrength');
      if (!strengthEl) return;
      const bars = [
        document.getElementById('strengthBar1'),
        document.getElementById('strengthBar2'),
        document.getElementById('strengthBar3'),
      ];
      const textEl = document.getElementById('strengthText');
      pwdInput.addEventListener('input', function () {
        const pwd = this.value;
        const pwdError = document.getElementById('passwordError');
        if (pwdError) pwdError.classList.remove('show');
        if (pwd.length === 0) {
          strengthEl.style.display = 'none';
          return;
        }
        strengthEl.style.display = 'flex';
        let score = 0;
        if (pwd.length >= 8) score++;
        if (pwd.length >= 12) score++;
        if (/[a-z]/.test(pwd)) score++;
        if (/[A-Z]/.test(pwd)) score++;
        if (/[0-9]/.test(pwd)) score++;
        if (/[^a-zA-Z0-9]/.test(pwd)) score++;
        let level, colorClass;
        if (score <= 2) {
          level = '弱';
          colorClass = 'weak';
          bars.forEach((b, i) => {
            if (b) b.className = 'strength-bar' + (i === 0 ? ' weak' : '');
          });
        } else if (score <= 3) {
          level = '中等';
          colorClass = 'medium';
          bars.forEach((b, i) => {
            if (b) b.className = 'strength-bar' + (i <= 1 ? ' medium' : '');
          });
        } else {
          level = score >= 5 ? '非常强' : '强';
          colorClass = 'strong';
          bars.forEach((b) => {
            if (b) b.className = 'strength-bar strong';
          });
        }
        if (textEl) {
          textEl.textContent = level;
          textEl.className = 'strength-text ' + colorClass;
        }
      });
    },

    initPasswordToggle() {
      const toggleBtns = document.querySelectorAll('.input-icon-btn');
      toggleBtns.forEach((btn) => {
        const wrapper = btn.parentElement;
        if (!wrapper) return;
        const input = wrapper.querySelector('input');
        if (!input || (input.type !== 'password' && input.type !== 'text')) return;
        btn.addEventListener('click', () => {
          const isPassword = input.type === 'password';
          input.type = isPassword ? 'text' : 'password';
          btn.innerHTML = isPassword ? svgIcon('eyeOff', 16) : svgIcon('eye', 16);
        });
      });
    },

    initConfirmPassword() {
      const confirmInput = document.getElementById('confirmPassword');
      if (!confirmInput) return;
      const passwordInput = document.getElementById('password');
      const confirmError = document.getElementById('confirmError');
      const confirmErrorText = document.getElementById('confirmErrorText');
      const confirmSuccess = document.getElementById('confirmSuccess');
      function checkMatch() {
        if (!passwordInput || !confirmInput) return;
        const pwd = passwordInput.value;
        const confirmPwd = confirmInput.value;
        if (confirmError) confirmError.classList.remove('show');
        if (confirmSuccess) confirmSuccess.classList.remove('show');
        confirmInput.classList.remove('input-error', 'input-success');
        if (confirmPwd.length === 0) return;
        if (pwd !== confirmPwd) {
          if (confirmErrorText) confirmErrorText.textContent = '两次输入的密码不一致';
          if (confirmError) confirmError.classList.add('show');
          confirmInput.classList.add('input-error');
        } else {
          if (confirmSuccess) confirmSuccess.classList.add('show');
          confirmInput.classList.add('input-success');
        }
      }
      confirmInput.addEventListener('input', checkMatch);
      if (passwordInput) {
        passwordInput.addEventListener('input', checkMatch);
      }
    },

    initFormSubmitDebounce() {
      document.querySelectorAll('form').forEach((form) => {
        if (form.id === 'loginForm' || form.id === 'registerForm') return;
        let isSubmitting = false;
        form.addEventListener('submit', function (e) {
          if (isSubmitting) {
            e.preventDefault();
            return;
          }
          isSubmitting = true;
          setTimeout(() => {
            isSubmitting = false;
          }, 300);
        });
      });
    },
  };

  function initAnnouncementBar() {
    const bar = document.querySelector('.announcement-bar');
    if (!bar) return;
    const scroll = bar.querySelector('.announcement-scroll');
    if (!scroll) return;
    const items = scroll.innerHTML;
    scroll.innerHTML = items + items;

    bar.addEventListener('mouseenter', () => {
      scroll.style.animationPlayState = 'paused';
    });
    bar.addEventListener('mouseleave', () => {
      scroll.style.animationPlayState = 'running';
    });
  }

  function initImageUpload() {
    const fileInput = document.querySelector('input[type="file"][accept*="image"]');
    if (!fileInput) return;
    const previewContainer =
      document.getElementById('imagePreviewContainer') || document.getElementById('imagePreview');
    if (!previewContainer) return;
    const MAX_TOTAL_SIZE = 10 * 1024 * 1024;
    const MAX_SINGLE_SIZE = 5 * 1024 * 1024;

    fileInput.addEventListener('change', function () {
      const files = Array.from(this.files);
      previewContainer.innerHTML = '';
      let totalSize = 0;
      files.forEach((file, idx) => {
        totalSize += file.size;
        if (totalSize > MAX_TOTAL_SIZE) {
          showToast('图片总大小超过10MB限制', 'error');
          return;
        }
        if (file.size > MAX_SINGLE_SIZE) {
          showToast('图片 "' + file.name + '" 超过5MB限制', 'error');
          return;
        }
        if (!file.type.startsWith('image/')) {
          showToast('文件 "' + file.name + '" 不是图片', 'warning');
          return;
        }
        const reader = new FileReader();
        const previewItem = document.createElement('div');
        previewItem.className = 'image-preview-item';
        previewItem.style.cssText = 'position:relative;display:inline-block;margin:4px;';
        const img = document.createElement('img');
        img.style.cssText =
          'width:100px;height:100px;object-fit:cover;border-radius:8px;border:1px solid var(--border);';
        previewItem.appendChild(img);
        const removeBtn = document.createElement('button');
        removeBtn.textContent = '×';
        removeBtn.style.cssText =
          'position:absolute;top:2px;right:2px;background:rgba(0,0,0,0.6);color:#fff;border:none;border-radius:50%;width:20px;height:20px;cursor:pointer;font-size:14px;line-height:1;padding:0;';
        removeBtn.setAttribute('data-idx', idx);
        previewItem.appendChild(removeBtn);
        previewContainer.appendChild(previewItem);
        reader.onload = function (e) {
          img.src = e.target.result;
        };
        reader.readAsDataURL(file);
      });

      previewContainer.onclick = function (e) {
        if (e.target.hasAttribute('data-idx')) {
          const idx = parseInt(e.target.getAttribute('data-idx'));
          const dt = new DataTransfer();
          const remainingFiles = Array.from(fileInput.files).filter((_, i) => i !== idx);
          remainingFiles.forEach((f) => dt.items.add(f));
          fileInput.files = dt.files;
          if (fileInput.files.length === 0) {
            fileInput.value = '';
          }
          if (e.target.parentNode) e.target.parentNode.remove();
        }
      };
    });
  }

  const Modal = {
    stack: [],

    open(options = {}) {
      const {
        title = '',
        content = '',
        onConfirm = null,
        onCancel = null,
        confirmText = '确定',
        cancelText = '取消',
        showCancel = true,
        showClose = true,
        size = '',
        confirmClass = 'btn-primary',
      } = options;

      const overlay = document.createElement('div');
      overlay.className = 'modal-overlay show';

      const modal = document.createElement('div');
      modal.className = 'modal' + (size ? ' modal-' + size : '');
      modal.innerHTML =
        '<div class="modal-header">' +
        '<h3>' +
        escapeHtml(title) +
        '</h3>' +
        (showClose ? '<button class="modal-close">' + svgIcon('x', 16) + '</button>' : '') +
        '</div>' +
        '<div class="modal-body"></div>' +
        '<div class="modal-footer">' +
        (showCancel ? '<button class="btn btn-outline modal-cancel-btn">' + escapeHtml(cancelText) + '</button>' : '') +
        '<button class="btn ' +
        confirmClass +
        ' modal-confirm-btn">' +
        escapeHtml(confirmText) +
        '</button>' +
        '</div>';

      const bodyEl = modal.querySelector('.modal-body');
      if (typeof content === 'string') {
        bodyEl.innerHTML = content;
      } else if (content instanceof Node) {
        bodyEl.appendChild(content);
      }

      overlay.appendChild(modal);
      document.body.appendChild(overlay);
      this.stack.push({ overlay, modal, onConfirm, onCancel });

      const confirmBtn = modal.querySelector('.modal-confirm-btn');
      if (confirmBtn) confirmBtn.focus();

      const closeBtn = modal.querySelector('.modal-close');
      const cancelBtn = modal.querySelector('.modal-cancel-btn');
      const close = () => {
        this._close(overlay, false);
      };

      overlay.addEventListener('click', (e) => {
        if (e.target === overlay) close();
      });
      if (closeBtn) closeBtn.addEventListener('click', close);
      if (cancelBtn) cancelBtn.addEventListener('click', close);
      if (confirmBtn) {
        confirmBtn.addEventListener('click', () => {
          this._close(overlay, true);
        });
      }

      const escHandler = (e) => {
        if (e.key === 'Escape') {
          close();
          document.removeEventListener('keydown', escHandler);
        }
      };
      document.addEventListener('keydown', escHandler);

      this._trapFocus(modal);
      document.body.style.overflow = 'hidden';

      return { overlay, modal, close };
    },

    close(overlay) {
      this._close(overlay, false);
    },

    confirm(message, onConfirm, options = {}) {
      return this.open({
        title: options.title || '确认操作',
        content: '<p style="text-align:center;">' + escapeHtml(message) + '</p>',
        onConfirm: onConfirm,
        confirmText: options.confirmText || '确定',
        cancelText: options.cancelText || '取消',
        confirmClass: options.danger ? 'btn-danger' : 'btn-primary',
        ...options,
      });
    },

    _close(overlay, confirmed) {
      const idx = this.stack.findIndex((item) => item.overlay === overlay);
      if (idx === -1) return;
      const item = this.stack[idx];
      this.stack.splice(idx, 1);
      if (confirmed && item.onConfirm) {
        item.onConfirm();
      } else if (!confirmed && item.onCancel) {
        item.onCancel();
      }
      item.overlay.classList.remove('show');
      item.overlay.addEventListener(
        'transitionend',
        () => {
          if (item.overlay.parentNode) {
            item.overlay.parentNode.removeChild(item.overlay);
          }
        },
        { once: true },
      );

      setTimeout(() => {
        if (item.overlay.parentNode) {
          item.overlay.parentNode.removeChild(item.overlay);
        }
      }, 300);
      if (this.stack.length === 0) {
        document.body.style.overflow = '';
      }
    },

    _trapFocus(modal) {
      const focusable = modal.querySelectorAll(
        'button, [href], input, select, textarea, [tabindex]:not([tabindex="-1"])',
      );
      if (focusable.length === 0) return;
      const first = focusable[0];
      const last = focusable[focusable.length - 1];
      modal.addEventListener('keydown', (e) => {
        if (e.key === 'Tab') {
          if (e.shiftKey) {
            if (document.activeElement === first) {
              e.preventDefault();
              last.focus();
            }
          } else {
            if (document.activeElement === last) {
              e.preventDefault();
              first.focus();
            }
          }
        }
      });
    },
  };

  function initCommunityRules() {
    const hasSeen = localStorage.getItem('community_rules_accepted');
    if (hasSeen) return;
    const rulesContent = document.querySelector('.rules-preview');
    if (!rulesContent) return;

    setTimeout(() => {
      const content = document.createElement('div');
      content.innerHTML =
        '<div style="max-height:300px;overflow-y:auto;line-height:1.8;font-size:14px;color:var(--text-secondary);">' +
        rulesContent.innerHTML +
        '</div>' +
        '<div class="form-checkbox" style="margin-top:16px;">' +
        '<input type="checkbox" id="dontShowAgain">' +
        '<label for="dontShowAgain">不再显示此提示</label>' +
        '</div>';
      // 进入自动弹窗队列，避免与赞助弹窗等同时叠加弹出
      PopupQueue.push(function(done) {
        Modal.open({
          title: '社区规范',
          content: content,
          confirmText: '我已了解',
          showCancel: false,
          onConfirm: () => {
            const checkbox = document.getElementById('dontShowAgain');
            if (checkbox && checkbox.checked) {
              localStorage.setItem('community_rules_accepted', '1');
            }
            done();
          },
          onCancel: done,
        });
      });
    }, 500);
  }

  // 自动弹窗串行队列
  // 登录后会有多个自动弹窗（新手指引、赞助、社区规范等）。若各自独立弹出，
  // 会在同一时刻叠加盖在一起。这里统一排队：一次只显示一个，用户关闭后再弹下一个。
  const PopupQueue = (function () {
    const queue = [];
    let running = false;

    function next() {
      if (running || queue.length === 0) return;
      running = true;
      const show = queue.shift();
      show(function release() {
        running = false;
        // 留一点间隔，让上一个弹窗的退场动画走完，避免视觉上粘连
        setTimeout(next, 260);
      });
    }

    return {
      /**
       * 排队展示一个弹窗。
       * @param {(done: Function) => void} show 负责展示弹窗；用户关闭后必须调用 done()
       */
      push(show) {
        queue.push(show);
        next();
      },
    };
  })();
  window.PopupQueue = PopupQueue;

  function setSponsorCookie() {
    const now = new Date();
    const monthKey = now.getFullYear() + '-' + (now.getMonth() + 1);
    localStorage.setItem('sponsor_shown_month', monthKey);
  }

  function shouldShowSponsor() {
    const lastMonth = localStorage.getItem('sponsor_shown_month');
    const now = new Date();
    const currentMonth = now.getFullYear() + '-' + (now.getMonth() + 1);
    return lastMonth !== currentMonth;
  }

  // 赞助弹窗处于队列中时的「已关闭」回调（不在队列中则为 null）
  let sponsorQueueDone = null;

  function closeSponsorModal() {
    const modal = document.getElementById('sponsorModal');
    if (modal) modal.style.display = 'none';
    if (sponsorQueueDone) {
      const done = sponsorQueueDone;
      sponsorQueueDone = null;
      done();
    }
  }

  function initSponsorModal() {
    if (!IS_LOGGED_IN) return;
    if (typeof IS_SPONSOR !== 'undefined' && IS_SPONSOR) return;
    if (!shouldShowSponsor()) return;
    const modal = document.getElementById('sponsorModal');
    if (!modal) return;

    // 进入自动弹窗队列：等前一个弹窗关闭后再拉取数据并展示
    PopupQueue.push(function(done) {
      sponsorQueueDone = done;

      modal.querySelectorAll('.modal-close, .btn').forEach(function(btn) {
        btn.addEventListener('click', closeSponsorModal);
      });

      modal.addEventListener('click', function(e) {
        if (e.target === modal) closeSponsorModal();
      });

      document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape' && modal.style.display === 'flex') {
          closeSponsorModal();
        }
      });

      fetchAPI('/api/sponsor.php')
        .then((res) => {
          if (res.success && res.data) {
            const amount = res.data.current_amount || res.data.total_amount || 0;
            const el = document.getElementById('sponsorAmount');
            if (el) el.textContent = amount.toFixed(2);
            const list = res.data.sponsor_list;
            const listEl = document.getElementById('sponsorList');
            if (listEl && list && list.length > 0) {
              listEl.innerHTML =
                '<h4>赞助榜单</h4>' +
                list
                  .slice(0, 10)
                  .map((item) => {
                    const name = item.name || '匿名';
                    const amt = item.amount || 0;
                    return '<div class="sponsor-item">' + escapeHtml(name) + ' - ' + amt + '元</div>';
                  })
                  .join('');
            }
            modal.style.display = 'flex';
            setSponsorCookie();
          } else {
            // 无数据可展示，放行队列，避免后续弹窗被卡住
            closeSponsorModal();
          }
        })
        .catch(() => {
          closeSponsorModal();
        });
    });
  }

  function initScrollProgress() {
    const bar = document.createElement('div');
    bar.className = 'scroll-progress';
    document.body.prepend(bar);
    const updateProgress = throttle(() => {
      const scrollTop = window.scrollY;
      const docHeight = document.documentElement.scrollHeight - window.innerHeight;
      const progress = docHeight > 0 ? Math.min((scrollTop / docHeight) * 100, 100) : 0;
      bar.style.width = progress + '%';
    }, 50);
    window.addEventListener('scroll', updateProgress, { passive: true });
    updateProgress();
  }

  function initTimeGreeting() {
    const greetingEl = document.createElement('span');
    greetingEl.className = 'time-greeting';
    function updateGreeting() {
      const hour = new Date().getHours();
      let text, emoji;
      if (hour < 6) {
        text = '夜深了，早点休息';
        emoji = '🌙';
      } else if (hour < 9) {
        text = '早上好';
        emoji = '☀️';
      } else if (hour < 12) {
        text = '上午好';
        emoji = '🌤️';
      } else if (hour < 14) {
        text = '中午好';
        emoji = '☀️';
      } else if (hour < 18) {
        text = '下午好';
        emoji = '🌤️';
      } else if (hour < 21) {
        text = '晚上好';
        emoji = '🌅';
      } else {
        text = '夜深了';
        emoji = '🌙';
      }
      greetingEl.innerHTML = '<span class="greeting-emoji">' + emoji + '</span> ' + text;
    }
    updateGreeting();

    mountSecondary(greetingEl, 'me-panel');
  }

  const NotificationSystem = {
    bell: null,
    badge: null,
    panel: null,
    unreadCount: 0,
    _pollTimer: null,

    init() {
      if (!IS_LOGGED_IN) return;
      this.createBell();
      this.createPanel();
      this.bindEvents();
      this.fetchNotifications();
      this.startPolling();
      // 手机客户端点通知进来时地址带 #notifications，直接展开面板，
      // 省得用户再自己去找顶栏的铃铛。
      if (window.location.hash === '#notifications') {
        this.panel.classList.add('show');
        if (window.history && window.history.replaceState) {
          window.history.replaceState(null, '', window.location.pathname + window.location.search);
        }
      }
    },

    createBell() {
      this.bell = document.createElement('button');
      this.bell.className = 'notification-bell';
      this.bell.setAttribute('aria-label', '通知');
      this.bell.innerHTML = svgIcon('bell', 20);
      this.badge = document.createElement('span');
      this.badge.className = 'bell-badge';
      this.badge.style.display = 'none';
      this.bell.appendChild(this.badge);

      const headerActions = document.querySelector('.header-actions');
      if (headerActions) {
        const userMenu = headerActions.querySelector('.user-menu');
        if (userMenu) {
          headerActions.insertBefore(this.bell, userMenu);
        } else {
          headerActions.appendChild(this.bell);
        }
      }
    },

    createPanel() {
      this.panel = document.createElement('div');
      this.panel.className = 'notification-panel';
      this.panel.setAttribute('role', 'dialog');
      this.panel.setAttribute('aria-label', '消息通知');
      this.panel.innerHTML =
        '<div class="notification-panel-header"><span>消息通知</span><button type="button" class="mark-all-read">全部已读</button></div>' +
        '<div class="notification-panel-list"><div class="notification-panel-empty">' +
        LWIllustration('notifications', 40) +
        '<p>暂无通知</p></div></div>';
      // 面板必须挂到 <body> 而不是塞进铃铛按钮里，原因有两个：
      //   1) 把一个 <button> 放进另一个 <button> 是非法嵌套，部分移动端浏览器会把内层按钮
      //      的点击重定向到外层「铃铛」上 —— 表现就是「点『全部已读』没反应，面板反而关了」；
      //   2) 顶栏在桌面端带 backdrop-filter，会成为 position:fixed 后代的包含块，面板会错位。
      document.body.appendChild(this.panel);
      this.repositionPanel();
    },

    /** 桌面端把面板钉在铃铛正下方；≤768px 交给 CSS 铺满整屏（会清掉内联定位） */
    repositionPanel() {
      if (!this.panel) return;
      if (window.matchMedia('(max-width: 768px)').matches) {
        this.panel.style.top = '';
        this.panel.style.right = '';
        this.panel.style.left = '';
        this.panel.style.bottom = '';
        return;
      }
      const r = this.bell.getBoundingClientRect();
      this.panel.style.left = 'auto';
      this.panel.style.bottom = 'auto';
      this.panel.style.top = Math.round(r.bottom + 8) + 'px';
      this.panel.style.right = Math.max(8, Math.round(window.innerWidth - r.right)) + 'px';
    },

    bindEvents() {
      this.bell.addEventListener('click', (e) => {
        e.stopPropagation();
        this.repositionPanel();
        this.panel.classList.toggle('show');
      });
      document.addEventListener('click', (e) => {
        if (!this.panel.classList.contains('show')) return;
        // 面板已经不在铃铛内部了，判断「点在外面」必须同时看铃铛和面板本身
        if (this.bell.contains(e.target) || this.panel.contains(e.target)) return;
        this.panel.classList.remove('show');
      });
      document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape' && this.panel.classList.contains('show')) {
          this.panel.classList.remove('show');
        }
      });
      window.addEventListener('resize', () => {
        if (this.panel.classList.contains('show')) this.repositionPanel();
      });
      this.panel.querySelector('.mark-all-read').addEventListener('click', async (e) => {
        e.stopPropagation();
        try {
          await fetchAPI('/api/user/notifications.php', { method: 'POST', body: 'action=read_all' });
          this.unreadCount = 0;
          this.updateBadge();
          this.updatePanelList([], true);
          showToast('已全部标记为已读', 'success');
        } catch (err) {}
      });
    },

    startPolling() {
      this._pollTimer = setInterval(() => {
        if (document.hidden) return; // 后台不轮询，省流量
        this.fetchNotifications();
      }, 60000);
      document.addEventListener('visibilitychange', () => {
        if (!document.hidden) this.fetchNotifications(); // 回前台立刻刷新未读红点
      });
    },

    async fetchNotifications() {
      try {
        const data = await fetchAPI('/api/user/notifications.php?unread=1&limit=20');
        if (data.data) {
          this.unreadCount = data.data.unread_count || 0;
          this.updateBadge();
          this.updatePanelList(data.data.notifications || []);
        }
      } catch (err) {}
    },

    updateBadge() {
      if (this.unreadCount > 0) {
        this.badge.style.display = 'flex';
        this.badge.textContent = this.unreadCount > 99 ? '99+' : this.unreadCount;
      } else {
        this.badge.style.display = 'none';
      }
    },

    updatePanelList(notifications, isCleared) {
      const listEl = this.panel.querySelector('.notification-panel-list');
      if (!notifications || notifications.length === 0) {
        listEl.innerHTML =
          '<div class="notification-panel-empty">' +
          '<svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"><path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/></svg>' +
          '<p>' +
          (isCleared ? '已全部已读' : '暂无通知') +
          '</p></div>';
        return;
      }
      const typeIcons = {
        comment: svgIcon('message', 14),
        like: svgIcon('heart', 14),
        reply: svgIcon('message', 14),
        follow: svgIcon('user', 14),
        mention: svgIcon('users', 14),
        pm: svgIcon('mail', 14),
        admin: svgIcon('shield', 14),
        system: svgIcon('info', 14),
      };
      listEl.innerHTML = notifications
        .map((n) => {
          const icon = typeIcons[n.type] || typeIcons.system;
          const isUnread = !n.is_read;
          return (
            '<div class="notification-item' +
            (isUnread ? ' unread' : '') +
            '" data-id="' +
            n.id +
            '" data-post-id="' +
            (n.post_id || '') +
            '" style="position:relative;">' +
            '<div class="notif-icon">' +
            icon +
            '</div>' +
            '<div class="notif-body">' +
            '<div class="notif-text">' +
            escapeHtml(n.content || '') +
            '</div>' +
            '<div class="notif-time">' +
            (n.timeAgo || formatTime(n.created_at)) +
            '</div>' +
            '</div>' +
            '</div>'
          );
        })
        .join('');

      listEl.querySelectorAll('.notification-item').forEach((item) => {
        item.addEventListener('click', async (e) => {
          e.stopPropagation();
          const id = item.getAttribute('data-id');
          const postId = item.getAttribute('data-post-id');

          try {
            await fetchAPI('/api/user/notifications.php', { method: 'POST', body: 'action=read&id=' + id });
            item.classList.remove('unread');
            this.unreadCount = Math.max(0, this.unreadCount - 1);
            this.updateBadge();
          } catch (err) {}

          if (postId) {
            this.panel.classList.remove('show');
            window.location.href = SITE_URL + '/pages/post_detail.php?id=' + postId;
          }
        });
      });
    },
  };

  function initReadingTime() {
    const contentEl = document.querySelector('.post-detail-content');
    if (!contentEl) return;
    const text = contentEl.textContent || '';
    const wordCount = text.replace(/\s+/g, '').length;
    const minutes = Math.max(1, Math.ceil(wordCount / 300));
    const badge = document.createElement('span');
    badge.className = 'reading-time';
    badge.innerHTML = svgIcon('eye', 12) + ' 约' + minutes + '分钟阅读';
    const detailMeta = document.querySelector('.post-detail-meta');
    if (detailMeta) detailMeta.appendChild(badge);
  }

  function initHeatValue() {
    const detailMeta = document.querySelector('.post-detail-meta');
    if (!detailMeta) return;
    const likeCount = parseInt(
      (document.getElementById('likeCount') && document.getElementById('likeCount').textContent) || '0',
    );
    const commentTotalEl = document.querySelector('.comment-total');
    const commentCount = parseInt(((commentTotalEl && commentTotalEl.textContent) || '').replace(/[()]/g, '') || '0');
    const heat = likeCount * 3 + commentCount * 5;
    let level, className;
    if (heat >= 50) {
      level = '热门';
      className = 'hot';
    } else if (heat >= 20) {
      level = '热议';
      className = 'warm';
    } else {
      level = '普通';
      className = 'normal';
    }
    const badge = document.createElement('span');
    badge.className = 'heat-badge ' + className;
    badge.innerHTML = '🔥 ' + level + ' · ' + heat;
    detailMeta.appendChild(badge);
  }

  function initSearchSuggestions() {
    const input = document.getElementById('searchInput');
    if (!input) return;
    const parent = input.parentNode;
    const suggestions = document.createElement('div');
    suggestions.className = 'search-suggestions';
    parent.style.position = 'relative';
    parent.appendChild(suggestions);
    let selectedIndex = -1;
    const hotKeywords = ['失物招领', '学习', '表白', '校园', '活动', '求助'];

    input.addEventListener('focus', () => {
      if (!input.value.trim()) {
        showHotKeywords();
      }
    });
    input.addEventListener('input', () => {
      const val = input.value.trim();
      if (!val) {
        showHotKeywords();
        return;
      }
      const filtered = hotKeywords.filter((k) => k.includes(val) || val.includes(k));
      showSuggestions(filtered);
    });
    input.addEventListener('blur', () => {
      setTimeout(() => {
        suggestions.classList.remove('show');
        selectedIndex = -1;
      }, 200);
    });
    input.addEventListener('keydown', (e) => {
      if (!suggestions.classList.contains('show')) return;
      const items = suggestions.querySelectorAll('.search-suggestion-item');
      if (e.key === 'ArrowDown') {
        e.preventDefault();
        selectedIndex = Math.min(selectedIndex + 1, items.length - 1);
        updateSelection(items);
      } else if (e.key === 'ArrowUp') {
        e.preventDefault();
        selectedIndex = Math.max(selectedIndex - 1, 0);
        updateSelection(items);
      } else if (e.key === 'Enter') {
        if (selectedIndex >= 0 && items[selectedIndex]) {
          e.preventDefault();
          input.value = items[selectedIndex].textContent.trim();
          suggestions.classList.remove('show');
          PostFeed.searchKeyword = input.value.trim();
          PostFeed.currentPage = 1;
          PostFeed.hasMore = true;
          PostFeed.loadPosts(true);
        }
      }
    });

    function showHotKeywords() {
      showSuggestions(hotKeywords);
    }

    function showSuggestions(items) {
      if (items.length === 0) {
        suggestions.classList.remove('show');
        return;
      }
      suggestions.innerHTML = items
        .map((k) => '<div class="search-suggestion-item">' + escapeHtml(k) + '</div>')
        .join('');
      suggestions.classList.add('show');
      selectedIndex = -1;
      suggestions.querySelectorAll('.search-suggestion-item').forEach((item) => {
        item.addEventListener('mousedown', (e) => {
          e.preventDefault();
          input.value = item.textContent.trim();
          suggestions.classList.remove('show');
          PostFeed.searchKeyword = input.value.trim();
          PostFeed.currentPage = 1;
          PostFeed.hasMore = true;
          PostFeed.loadPosts(true);
        });
      });
    }

    function updateSelection(items) {
      items.forEach((item, i) => {
        item.classList.toggle('active', i === selectedIndex);
      });
    }
  }

  function initMentionSystem() {
    const textareas = document.querySelectorAll('textarea');
    textareas.forEach((textarea) => {
      let mentionPanel = null;
      let mentionFilter = '';
      let selectedMentionIndex = -1;
      let allUsers = [];

      textarea.addEventListener('input', () => {
        const val = textarea.value;
        const cursorPos = textarea.selectionStart;
        const beforeCursor = val.substring(0, cursorPos);
        const match = beforeCursor.match(/@(\S*)$/);
        if (match) {
          mentionFilter = match[1];
          if (mentionFilter.length >= 0) {
            showMentionPanel(match, cursorPos);
          }
        } else {
          hideMentionPanel();
        }
      });

      textarea.addEventListener('keydown', (e) => {
        if (!mentionPanel || !mentionPanel.classList.contains('show')) return;
        const items = mentionPanel.querySelectorAll('.mention-suggestion-item');
        if (e.key === 'ArrowDown') {
          e.preventDefault();
          selectedMentionIndex = Math.min(selectedMentionIndex + 1, items.length - 1);
          updateMentionSelection(items);
        } else if (e.key === 'ArrowUp') {
          e.preventDefault();
          selectedMentionIndex = Math.max(selectedMentionIndex - 1, 0);
          updateMentionSelection(items);
        } else if (e.key === 'Enter') {
          e.preventDefault();
          if (selectedMentionIndex >= 0 && items[selectedMentionIndex]) {
            insertMention(items[selectedMentionIndex].getAttribute('data-nickname'));
          }
        } else if (e.key === 'Escape') {
          hideMentionPanel();
        }
      });

      async function showMentionPanel(match, cursorPos) {
        if (!mentionPanel) {
          mentionPanel = document.createElement('div');
          mentionPanel.className = 'mention-suggestions';
          textarea.parentNode.appendChild(mentionPanel);
        }
        if (allUsers.length === 0) {
          try {
            const data = await fetchAPI('/api/user/list.php?limit=100');
            allUsers = data.data && data.data.users ? data.data.users : [];
          } catch (err) {
            allUsers = [];
          }
        }
        const filtered = allUsers
          .filter((u) => {
            const name = (u.nickname || '').toLowerCase();
            return name.includes(mentionFilter.toLowerCase());
          })
          .slice(0, 8);
        if (filtered.length === 0) {
          hideMentionPanel();
          return;
        }
        mentionPanel.innerHTML = filtered
          .map((u) => {
            return (
              '<div class="mention-suggestion-item" data-nickname="' +
              escapeHtml(u.nickname || '') +
              '">' +
              '<img src="' +
              escapeHtml(u.avatar || '/assets/images/default-avatar.svg') +
              '" alt="" loading="lazy" decoding="async" width="20" height="20" onerror="this.src=\'/assets/images/default-avatar.svg\'">' +
              '<span>' +
              escapeHtml(u.nickname || '') +
              '</span>' +
              '</div>'
            );
          })
          .join('');
        mentionPanel.classList.add('show');
        selectedMentionIndex = -1;
        mentionPanel.querySelectorAll('.mention-suggestion-item').forEach((item) => {
          item.addEventListener('mousedown', (e) => {
            e.preventDefault();
            insertMention(item.getAttribute('data-nickname'));
          });
        });
      }

      function insertMention(nickname) {
        const val = textarea.value;
        const cursorPos = textarea.selectionStart;
        const beforeCursor = val.substring(0, cursorPos);
        const afterCursor = val.substring(cursorPos);
        const lastAt = beforeCursor.lastIndexOf('@');
        const newVal = beforeCursor.substring(0, lastAt) + '@' + nickname + ' ' + afterCursor;
        textarea.value = newVal;
        const newPos = lastAt + nickname.length + 2;
        textarea.setSelectionRange(newPos, newPos);
        textarea.focus();
        hideMentionPanel();
      }

      function hideMentionPanel() {
        if (mentionPanel) {
          mentionPanel.classList.remove('show');
          selectedMentionIndex = -1;
        }
      }

      function updateMentionSelection(items) {
        items.forEach((item, i) => {
          item.classList.toggle('active', i === selectedMentionIndex);
        });
      }
    });
  }

  function initKeyboardShortcuts() {
    document.addEventListener('keydown', (e) => {
      if (e.ctrlKey && e.key === 'Enter') {
        const commentInput = document.getElementById('commentInput');
        if (commentInput && document.activeElement === commentInput) {
          e.preventDefault();
          const submitBtn = document.getElementById('submitCommentBtn');
          if (submitBtn) submitBtn.click();
        }
      }

      if (e.key === '/' && !e.ctrlKey && !e.metaKey && !e.altKey) {
        const activeTag = document.activeElement.tagName;
        if (activeTag !== 'INPUT' && activeTag !== 'TEXTAREA' && activeTag !== 'SELECT') {
          e.preventDefault();
          const searchInput = document.getElementById('searchInput');
          if (searchInput) {
            searchInput.focus();
            searchInput.select();
          }
        }
      }

      if (e.key === 'Escape') {
        if (Modal.stack.length > 0) {
          const last = Modal.stack[Modal.stack.length - 1];
          Modal._close(last.overlay, false);
        }
      }
    });
  }

  function initCommentLikes() {
    document.querySelectorAll('.comment-item').forEach((item) => {
      const commentId = item.id.replace('comment-', '');
      if (!commentId) return;
      const header = item.querySelector('.comment-header');
      if (!header) return;

      if (header.querySelector('.comment-like-btn')) return;
      const likeBtn = document.createElement('button');
      likeBtn.className = 'comment-like-btn';
      likeBtn.innerHTML = svgIcon('heartOutline', 12) + ' <span class="cl-count">0</span>';
      likeBtn.setAttribute('data-comment-id', commentId);
      header.appendChild(likeBtn);

      fetchAPI('/api/posts/comment_like.php?comment_id=' + commentId)
        .then((res) => {
          if (res.data) {
            const span = likeBtn.querySelector('.cl-count');
            if (span) span.textContent = res.data.like_count || 0;
            if (res.data.is_liked) {
              likeBtn.classList.add('liked');
              likeBtn.innerHTML =
                svgIcon('heart', 12) + ' <span class="cl-count">' + (res.data.like_count || 0) + '</span>';
            }
          }
        })
        .catch(() => {});
      likeBtn.addEventListener('click', async (e) => {
        e.stopPropagation();
        if (!IS_LOGGED_IN) {
          showToast('请先登录', 'warning');
          return;
        }
        try {
          const res = await fetchAPI('/api/posts/comment_like.php', {
            method: 'POST',
            body: 'comment_id=' + commentId,
          });
          if (res.data) {
            const count = res.data.like_count;
            if (res.data.is_liked) {
              likeBtn.classList.add('liked');
              likeBtn.innerHTML = svgIcon('heart', 12) + ' <span class="cl-count">' + count + '</span>';
            } else {
              likeBtn.classList.remove('liked');
              likeBtn.innerHTML = svgIcon('heartOutline', 12) + ' <span class="cl-count">' + count + '</span>';
            }
          }
        } catch (err) {
          showToast(err.message || '点赞失败，请重试', 'error');
        }
      });
    });
  }

  const AdminExport = {
    init() {
      const isAdminPage = window.location.pathname.includes('/admin/');
      if (!isAdminPage) return;
      if (!IS_LOGGED_IN || (USER_DATA && USER_DATA.role !== 'super_admin' && USER_DATA.role !== 'admin')) return;

      const pageContent = document.querySelector('.page-content');
      if (!pageContent) return;

      if (document.getElementById('exportSection')) return;
      const exportSection = document.createElement('div');
      exportSection.id = 'exportSection';
      exportSection.className = 'section';
      exportSection.style.marginTop = '24px';
      exportSection.innerHTML =
        '<div class="section-header"><h3>数据备份 / 恢复</h3></div>' +
        '<div class="section-body" style="display:flex;gap:12px;flex-wrap:wrap;flex-direction:column;">' +
        '<div style="display:flex;gap:12px;flex-wrap:wrap;">' +
        '<button class="btn btn-primary" onclick="AdminExport.exportData(\'posts\')">导出帖子数据</button>' +
        '<button class="btn btn-outline" onclick="AdminExport.exportData(\'users\')">导出用户数据</button>' +
        '<button class="btn btn-outline" onclick="AdminExport.exportData(\'comments\')">导出评论数据</button>' +
        '<button class="btn btn-success" onclick="AdminExport.exportData(\'full\')">📦 完整备份（全部数据）</button>' +
        '</div>' +
        '<div style="font-size:13px;color:var(--text-secondary);"><a href="/pages/download_backup.php" style="color:var(--primary);">&gt;_ 公开申请下载完整数据（需邮箱验证码）</a></div>' +
        '<div style="margin-top:16px;border-top:1px solid var(--border);padding-top:16px;">' +
        '<div class="form-group">' +
        '<label style="font-weight:600;">导入完整备份</label>' +
        '<small style="display:block;color:var(--text-secondary);margin:6px 0;">从“完整备份”导出的 JSON 文件中复制全部内容粘贴到下方，点击恢复。此操作会覆盖现有数据，请谨慎操作，仅建议迁移/维护使用。</small>' +
        '<textarea id="importDataArea" rows="6" class="form-input" placeholder="在此粘贴完整备份的 JSON 内容..." style="font-family:monospace;font-size:12px;"></textarea>' +
        '</div>' +
        '<div style="display:flex;gap:12px;align-items:center;">' +
        '<button class="btn btn-primary" onclick="AdminExport.importData()">恢复数据</button>' +
        '<button class="btn btn-outline" onclick="AdminExport.loadExampleStructure()">粘贴示例结构</button>' +
        '</div>' +
        '</div>' +
        '</div>';
      pageContent.appendChild(exportSection);
    },

    async exportData(type) {
      try {
        const data = await fetchAPI('/api/admin/export.php', {
          method: 'POST',
          body: 'type=' + encodeURIComponent(type)
        });
        if (data.success && data.data) {
          const jsonStr = JSON.stringify(data.data, null, 2);
          const blob = new Blob(['\ufeff' + jsonStr], { type: 'application/json' });
          const url = URL.createObjectURL(blob);
          const a = document.createElement('a');
          a.href = url;
          a.download = type + '_export_' + new Date().toISOString().slice(0, 10) + '.json';
          document.body.appendChild(a);
          a.click();
          document.body.removeChild(a);
          URL.revokeObjectURL(url);
          showToast(type + '数据导出成功', 'success');
        }
      } catch (err) {
        showToast('导出失败', 'error');
      }
    },

    // 粘贴完整备份的示例结构，方便用户了解格式
    loadExampleStructure() {
      const area = document.getElementById('importDataArea');
      if (!area) return;
      area.value = JSON.stringify({
        users: [{ id: 1, qq: '1740443398', nickname: '示例', password_hash: '...', role: 'user', security_stamp: '...', is_banned: 0, created_at: '2026-01-01 00:00:00' }],
        posts: [],
        comments: [],
        settings: [],
        pm_messages: [],
        pm_read: []
      }, null, 2);
      showToast('已填入示例结构，请替换为自己的备份内容', 'info');
    },

    async importData() {
      const area = document.getElementById('importDataArea');
      if (!area) return;
      const content = (area.value || '').trim();
      if (!content) {
        showToast('请先粘贴完整备份的 JSON 内容', 'warning');
        return;
      }
      let parsed;
      try {
        parsed = JSON.parse(content);
      } catch (e) {
        showToast('JSON 格式不正确，请检查', 'error');
        return;
      }
      // 二次确认
      const confirmed = window.confirm('即将覆盖现有数据并恢复备份。此操作不可撤销，请确认你已备份好当前数据。是否继续？');
      if (!confirmed) return;
      try {
        const res = await fetchAPI('/api/admin/import.php', {
          method: 'POST',
          body: 'data=' + encodeURIComponent(JSON.stringify(parsed))
        });
        showToast(res.message || '恢复完成', res.success ? 'success' : 'error');
      } catch (err) {
        showToast('导入失败', 'error');
      }
    },
  };

  window.AdminExport = AdminExport;

  function initMobileSidebar() {
    var header = document.querySelector('.site-header');
    var contentWrapper = document.querySelector('.content-wrapper');
    if (!header || !contentWrapper) return;
    var sidebar = contentWrapper.querySelector('.sidebar');
    if (!sidebar) return;

    var overlay = document.createElement('div');
    overlay.className = 'main-sidebar-overlay';
    overlay.setAttribute('aria-hidden', 'true');
    document.body.appendChild(overlay);

    // 汉堡按钮现在由 includes/site_header.php 静态输出；这里只在缺失时兜底创建，
    // 否则会出现两个菜单按钮。
    var menuBtn = header.querySelector('.mobile-menu-btn');
    if (!menuBtn) {
      menuBtn = document.createElement('button');
      menuBtn.className = 'mobile-menu-btn';
      menuBtn.setAttribute('aria-label', '菜单');
      menuBtn.innerHTML = '<svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="18" x2="21" y2="18"/></svg>';
      var headerInner = header.querySelector('.header-inner');
      if (headerInner) {
        headerInner.insertBefore(menuBtn, headerInner.firstChild);
      }
    }

    var isOpen = false;

    function openSidebar() {
      isOpen = true;
      sidebar.classList.add('sidebar-open');
      overlay.classList.add('overlay-visible');
      overlay.setAttribute('aria-hidden', 'false');
      document.body.style.overflow = 'hidden';
      menuBtn.innerHTML = '<svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>';
    }

    function closeSidebar() {
      isOpen = false;
      sidebar.classList.remove('sidebar-open');
      overlay.classList.remove('overlay-visible');
      overlay.setAttribute('aria-hidden', 'true');
      document.body.style.overflow = '';
      menuBtn.innerHTML = '<svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="18" x2="21" y2="18"/></svg>';
    }

    function toggleSidebar() {
      if (isOpen) { closeSidebar(); } else { openSidebar(); }
    }

    menuBtn.addEventListener('click', function(e) {
      e.preventDefault();
      e.stopPropagation();
      toggleSidebar();
    });

    overlay.addEventListener('click', function(e) {
      e.preventDefault();
      closeSidebar();
    });

    var touchStartX = 0;
    var touchStartY = 0;

    sidebar.addEventListener('touchstart', function(e) {
      if (!isOpen) return;
      touchStartX = e.touches[0].clientX;
      touchStartY = e.touches[0].clientY;
    }, { passive: true });

    sidebar.addEventListener('touchmove', function(e) {
      if (!isOpen) return;
      var deltaX = e.touches[0].clientX - touchStartX;
      var deltaY = e.touches[0].clientY - touchStartY;
      if (Math.abs(deltaX) > Math.abs(deltaY) && deltaX < -20) {
        closeSidebar();
      }
    }, { passive: true });

    var resizeHandler = debounce(function() {
      if (window.innerWidth > 768 && isOpen) {
        closeSidebar();
      }
    }, 150);
    window.addEventListener('resize', resizeHandler);

    document.addEventListener('keydown', function(e) {
      if (e.key === 'Escape' && isOpen) {
        closeSidebar();
      }
    });

    window.App.mobileSidebar = {
      toggle: toggleSidebar,
      open: openSidebar,
      close: closeSidebar,
      isOpen: function() { return isOpen; }
    };
  }

  function initSponsorCloseButtons() {
    document.querySelectorAll('.sponsor-close').forEach(function(btn) {
      btn.addEventListener('click', closeSponsorModal);
    });
  }

  function init() {
    initTheme();
    bindThemeToggle();
    UserMenu.init();
    initMobileSidebar();
    initSponsorCloseButtons();
    initScrollProgress();

    requestAnimationFrame(function() {
      PostFeed.init();
      Search.init();
      // 已停用：搜索框旁的热门关键词推荐（失物/学习/表白等）
      // initSearchSuggestions();
      initBanBanner();
      initAnnouncementBar();
      Lightbox.init();
    });

    setTimeout(function() {
      initTimeGreeting();
      initSponsorModal();
      AdminExport.init();
      FormHelpers.init();
      initImageUpload();
      initCommunityRules();
      initMentionSystem();
      initKeyboardShortcuts();
      initReadingTime();
      initHeatValue();
      initCommentLikes();

      setTimeout(function() {
        NotificationSystem.init();
      }, 500);
    }, 200);
  }

  window.App = {
    IS_LOGGED_IN: window.IS_LOGGED_IN,
    USER_DATA: window.USER_DATA,
    svgIcon,
    LWIcon,
    LWIllustration,
    // 分类 → URL 写法（social_chat → social），供 enhancements.js 等复用
    categoryUrlKey,
    showToast,
    copyToClipboard,
    fetchAPI,
    fetchRaw,
    applyCSRFToken,
    // 站内是否跑在自家安卓客户端里（供布局适配与 APK 直装判断）
    isNativeApp,
    formatTime,
    escapeHtml,
    esc: escapeHtml,
    debounce,
    throttle,
    Modal,
    PostFeed,
    NotificationSystem,
    toggleTheme,
    applyTheme,
    closeSponsorModal,
  };

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }

  // ===== 顶栏「申请头衔」弹窗 =====
  // 菜单与弹窗结构来自公共组件 includes/user_dropdown.php，全站各页面共用同一份逻辑。
  (function () {
    // lang_ui.php 在 </body> 前才定义 window.__t，这里做兜底避免取不到文案
    function tr(key, fallback) {
      return (typeof window.__t === 'function') ? window.__t(key) : fallback;
    }

    function mount() {
      var openBtn = document.getElementById('titleRequestBtn');
      var modal = document.getElementById('titleRequestModal');
      if (!openBtn || !modal) return;

      var tTitle = document.getElementById('trTitle');
      var tReason = document.getElementById('trReason');
      var closeBtn = document.getElementById('titleReqClose');
      var cancelBtn = document.getElementById('trCancel');
      var submitBtn = document.getElementById('trSubmit');
      if (!tTitle || !tReason || !submitBtn) return;

      function openModal() {
        var dd = document.getElementById('userDropdown');
        if (dd) dd.classList.remove('show');
        tTitle.value = '';
        tReason.value = '';
        modal.style.display = 'flex';
        setTimeout(function () { tTitle.focus(); }, 50);
      }

      function closeModal() {
        modal.style.display = 'none';
      }

      openBtn.addEventListener('click', openModal);
      if (closeBtn) closeBtn.addEventListener('click', closeModal);
      if (cancelBtn) cancelBtn.addEventListener('click', closeModal);
      modal.addEventListener('click', function (e) {
        if (e.target === modal) closeModal();
      });

      submitBtn.addEventListener('click', function () {
        var text = tTitle.value.trim();
        if (!text) {
          window.alert(tr('tr.empty', '请输入想申请的头衔文字'));
          tTitle.focus();
          return;
        }
        var fd = new FormData();
        // CSRF_TOKEN 由各页面内联脚本声明，未声明的页面跳过以免 ReferenceError
        if (typeof CSRF_TOKEN !== 'undefined') fd.append('csrf_token', CSRF_TOKEN);
        fd.append('action', 'submit');
        fd.append('title_text', text);
        fd.append('reason', tReason.value.trim());
        submitBtn.disabled = true;
        fetch(SITE_URL + '/api/title_request.php', { method: 'POST', body: fd })
          .then(function (r) { return r.json(); })
          .then(function (res) {
            window.alert(res.message || tr(res.success ? 'tr.success' : 'tr.fail',
              res.success ? '提交成功' : '提交失败'));
            if (res.success) closeModal();
          })
          .catch(function () { window.alert(tr('common.net_error', '网络错误，请重试')); })
          .finally(function () { submitBtn.disabled = false; });
      });
    }

    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', mount);
    else mount();
  })();

  // ===== 彩蛋：连点标题/Logo、连点页脚、搜索框神秘指令 =====
  (function () {
    var EGG_VIDEO = 'https://www.bilibili.com/video/BV1GJ411x7h7/';
    var LOGO_CLICKS = 0, FOOTER_CLICKS = 0, LAST = 0;
    var COOLDOWN = 8000;

    function go() {
      var now = Date.now();
      if (now - LAST < COOLDOWN) return;
      LAST = now;
      // 跳前仅提示触发了彩蛋
      if (window.confirm('触发彩蛋，是否前往？')) {
        window.location.href = EGG_VIDEO;
      }
    }

    function resetTrack() {
      LOGO_CLICKS = 0; FOOTER_CLICKS = 0;
    }

    function mount() {
      // 触发一：连点顶部标题/Logo 5 次
      var logo = document.querySelector('.site-logo');
      if (logo) {
        logo.addEventListener('click', function (e) {
          e.preventDefault(); // 阻止跳到首页，避免每次点击刷新清零
          LOGO_CLICKS++;
          if (LOGO_CLICKS >= 5) { resetTrack(); go(); }
          if (LOGO_CLICKS === 1) setTimeout(function () { if (LOGO_CLICKS < 5) LOGO_CLICKS = 0; }, 2200);
        });
      }
      // 触发二：连点页脚 5 次
      var footer = document.querySelector('.site-footer');
      if (footer) {
        footer.addEventListener('click', function () {
          FOOTER_CLICKS++;
          if (FOOTER_CLICKS >= 5) { resetTrack(); go(); }
          if (FOOTER_CLICKS === 1) setTimeout(function () { if (FOOTER_CLICKS < 5) FOOTER_CLICKS = 0; }, 2200);
        });
      }
      // 触发三：搜索框输入神秘指令（awa / awsl / loose / rick）后回车
      var si = document.getElementById('searchInput');
      if (si) {
        si.addEventListener('keyup', function (e) {
          var v = (si.value || '').trim().toLowerCase();
          if (e.key === 'Enter' && (v === 'awa' || v === 'awsl' || v === 'loose' || v === 'rick')) {
            si.value = '';
            go();
          }
        });
      }
    }

    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', mount);
    else mount();
  })();
})();

/* ===== 在线状态心跳 =====
 * 供后台仪表盘统计「当前在线」。三件事保证后台看到的是实时的：
 *   1. 前台每 25 秒、后台标签页每 60 秒回报一次（在线窗口 120 秒，见 includes/online.php）；
 *   2. 页面关闭/离开时用 sendBeacon 打一次 action=leave —— 关闭标签页后后台**立刻**减 1，
 *      不必等窗口超时（原来最长要挂 5 分钟才掉）；
 *   3. pageshow 时立刻补一拍：同站跳转先 leave 再 beat，用户不会在名单里闪一下。
 *
 * 为什么首拍不放在解析阶段：未登录访问会立刻被重定向（gateway → login/register），
 * 解析阶段发出的请求会被跳转打断，Chrome 控制台留一条 net::ERR_ABORTED 红字。
 * 挂在 pageshow（文档已加载完）上既能避开这个报错，又比原先的 load + 4 秒更早。
 */
(function () {
  if (window.__lwHeartbeat) return;
  window.__lwHeartbeat = true;

  var BEAT_VISIBLE = 25000;   // 前台：每 25 秒
  var BEAT_HIDDEN = 60000;    // 后台标签页：每 60 秒（页面还开着就算在线，但少发请求）
  var timer = null;

  function beat() {
    try {
      fetch('/api/heartbeat.php', {
        method: 'POST',
        keepalive: true,
        headers: {
          'X-Requested-With': 'XMLHttpRequest',
          'Content-Type': 'application/x-www-form-urlencoded'
        },
        body: ''
      }).catch(function () { /* 离线/超时忽略 */ });
    } catch (e) { /* 老浏览器不支持 keepalive 时静默放弃本次 */ }
  }

  function loop() {
    beat();
    timer = setTimeout(loop, document.hidden ? BEAT_HIDDEN : BEAT_VISIBLE);
  }

  function restart(delay) {
    if (timer) { clearTimeout(timer); timer = null; }
    timer = setTimeout(loop, delay);
  }

  // 主动下线：sendBeacon 在页面卸载时也能把请求送出去（fetch 会被取消）。
  // action 放在 query 上 —— 各家对 sendBeacon 的 body 编码处理不一致，query 最稳。
  var left = false;
  function leave() {
    if (left) return;
    left = true;
    try {
      var url = '/api/heartbeat.php?action=leave';
      if (navigator.sendBeacon) {
        navigator.sendBeacon(url);
      } else {
        fetch(url, { method: 'POST', keepalive: true }).catch(function () {});
      }
    } catch (e) { /* ignore */ }
  }

  window.addEventListener('pagehide', leave);
  window.addEventListener('beforeunload', leave);

  // 页面每次显示（首次加载、同站跳转、bfcache 恢复）都立刻补一拍并重置循环
  window.addEventListener('pageshow', function () {
    left = false;
    restart(0);
  });

  // 切回前台立即补一拍，之后按前台频率走
  document.addEventListener('visibilitychange', function () {
    if (!document.hidden) { restart(0); }
  });
})();