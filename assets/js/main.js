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
    // cacheTtl 是本模块自己的约定（毫秒），不是 fetch 的选项：合并后立刻摘掉，
    // 否则它会被原样塞进 fetch()，个别 WebView 会因为这个未知键直接抛 TypeError。
    const cacheTtl = Number(options.cacheTtl) || 0;
    delete mergedOptions.cacheTtl;

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
      // 读类接口走统一的请求缓存层（并发去重 + 短时缓存）：
      // 首屏有多个模块会同时拉同一份数据（角标 / 通知 / 在线数），
      // 合并后只回源一次；POST 类写操作仍然原样直发，不被合并、不被缓存。
      // 调用方可传 cacheTtl（毫秒）显式开启短时缓存；不传则只做并发去重。
      const canUseCache = window.LWRequestCache && method === 'GET';
      response = canUseCache
        ? await window.LWRequestCache.request(SITE_URL + url, mergedOptions, { ttl: cacheTtl })
        : await fetch(SITE_URL + url, mergedOptions);
    } catch (err) {
      // 网络层失败（断网 / 主机抖动 / 页面正在跳转）——**不再无脑报「网络错误」**。
      // 2026-10-22：登录失败的用户看到的就是这一条，实际原因是 WAF 429 + 主机挑战页，
      // 真因被这句话盖住，连站长都无从下手。现在：
      //   ① 对 POST 做一次带退避的重试（丢包/瞬时 5xx 能自己恢复）；
      //   ② 重试仍失败才提示，并区分「离线」与「服务器暂时没响应」。
      const isAbort = err && (err.name === 'AbortError' || err.name === 'TypeError' && /aborted/i.test(err.message || ''));
      if (!isAbort && method !== 'GET' && !_retried) {
        await new Promise(function (r) { setTimeout(r, 600); });
        return fetchAPI(url, options, true);
      }
      var offline = (typeof navigator !== 'undefined' && navigator.onLine === false);
      showToast(offline ? '当前没有网络连接，请检查网络后重试' : '服务器暂时没有响应，请稍后重试',
        'error', 5000);
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

  /**
   * 在一组背景色里挑「最不容易糊」的前景色，返回 '#FFFFFF' 或 '#10161F'。
   *
   * 用途：称号徽章的底色由站长自选（默认还是 #ff4757→#a55eea 的彩虹渐变），
   * 前景色过去写死 #fff —— 实测白字落在这些默认底色上只有 3.34~3.90:1，不达
   * WCAG AA 正文 4.5:1；站长选偏亮的渐变时称号直接糊掉。这里按 WCAG 取
   * 「在全部候选底色上的最小对比度」最大的那个前景色。
   * 与 PHP 侧 lwReadableFg() 同一算法（includes/user_title.php），两端结果一致。
   */
  function pickReadableFg(...backgrounds) {
    const lum = (hex) => {
      let h = String(hex || '').replace('#', '');
      if (h.length === 3) h = h[0] + h[0] + h[1] + h[1] + h[2] + h[2];
      if (!/^[0-9a-f]{6}$/i.test(h)) return null;
      const c = [0, 2, 4].map((i) => {
        const v = parseInt(h.substr(i, 2), 16) / 255;
        return v <= 0.03928 ? v / 12.92 : Math.pow((v + 0.055) / 1.055, 2.4);
      });
      return 0.2126 * c[0] + 0.7152 * c[1] + 0.0722 * c[2];
    };
    let best = '#FFFFFF';
    let bestWorst = -1;
    for (const fg of ['#FFFFFF', '#10161F']) {
      const lf = lum(fg);
      let worst = null;
      for (const bg of backgrounds) {
        const lb = lum(bg);
        if (lb === null) continue;   // rgb()/hsl()/具名色解析不了，跳过
        const ratio = (Math.max(lf, lb) + 0.05) / (Math.min(lf, lb) + 0.05);
        worst = worst === null ? ratio : Math.min(worst, ratio);
      }
      if (worst !== null && worst > bestWorst) { bestWorst = worst; best = fg; }
    }
    return best;
  }

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

  /* ── 数字滚动 ────────────────────────────────────────────────────────────
   * 点赞数从 3 → 4 原本是瞬间跳变，和按钮的弹跳（is-pop）不同步：
   * 图标在弹，数字在闪。这里让数字在 ~220ms 内数过去。
   *
   * 三个边界：
   *   · 差值 > 9 不数（从 3 数到 52 会显得拖沓，直接落位更干净）
   *   · 系统开了「减少动效」→ 直接落位
   *   · 连续点击 → 取消上一帧的 rAF，从当前显示值接着数
   * 暴露到 window.lwCountUp 方便其它脚本复用（也便于自动化验证）。
   */
  function animateCount(el, to) {
    if (!el) { return; }
    var target = parseInt(to, 10);
    if (!isFinite(target)) { el.textContent = String(to); return; }
    var from = parseInt(el.textContent, 10);
    if (!isFinite(from)) { el.textContent = String(target); return; }
    var reduce = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    if (reduce || from === target || Math.abs(target - from) > 9) {
      el.textContent = String(target);
      return;
    }

    if (el.__lwCountRaf) { cancelAnimationFrame(el.__lwCountRaf); el.__lwCountRaf = 0; }
    el.classList.remove('mp-count-pop');
    void el.offsetWidth;
    el.classList.add('mp-count-pop');
    window.setTimeout(function () { el.classList.remove('mp-count-pop'); }, 340);

    var t0 = 0;
    var dur = 220;
    var step = function (ts) {
      if (!t0) { t0 = ts; }
      var p = Math.min(1, (ts - t0) / dur);
      /* easeOutCubic：起步快、收尾稳 */
      var e = 1 - Math.pow(1 - p, 3);
      el.textContent = String(Math.round(from + (target - from) * e));
      if (p < 1) {
        el.__lwCountRaf = requestAnimationFrame(step);
      } else {
        el.__lwCountRaf = 0;
        el.textContent = String(target);
      }
    };
    el.__lwCountRaf = requestAnimationFrame(step);
  }
  window.lwCountUp = animateCount;

  function renderUserTitle(author) {
    if (!author || !author.title_text) return '';
    let style =
      'display:inline-block;padding:1px 8px;border-radius:4px;font-size:11px;font-weight:600;margin-left:6px;vertical-align:middle;';
    // 站长没指定 title_color 时按底色自动选前景（白/近黑），别再一律写死白字
    const fg = author.title_color || '';
    if (author.title_rainbow == 1) {
      const gs = author.title_gradient_start || '#ff4757';
      const ge = author.title_gradient_end || '#a55eea';
      style +=
        'background:linear-gradient(90deg,' + gs + ',' + ge + ',' + gs + ');background-size:200% 100%;animation:titleRainbow 2s linear infinite;color:' + (fg || pickReadableFg(gs, ge)) + ';';
    } else {
      const bg = author.title_bg_color || '#4A90D9';
      style +=
        'background:' + bg + ';color:' + (fg || pickReadableFg(bg)) + ';';
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

  function showToast(message, type = 'info', duration = 3000) {
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
    // duration 可调：错误提示需要比默认 3 秒多留一会儿，让人看清原因
    const dismissTimeout = setTimeout(() => {
      dismissToast(toast);
    }, Number(duration) > 0 ? Number(duration) : 3000);
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
      (e) => {
        /* 只认退出动画。进入动画（toastSlideIn 0.28s）结束时也会冒泡一次 animationend，
         * 如果 toast 在 280ms 内就被关掉，那个事件会把节点提前删掉、退出动画只播一半。 */
        if (e.animationName !== 'toastSlideOut') return;
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

  function toggleTheme(btn) {
    if (!window.LWTheme) return;
    /* 用 LWTheme.reveal()：View Transitions 做「圆形从按钮扩散」的切换。
     * 不支持 View Transitions 或系统开了「减少动效」时，reveal 内部自动退回原来的硬切。 */
    var pref = (typeof window.LWTheme.reveal === 'function')
      ? window.LWTheme.reveal(btn)
      : (window.LWTheme.withTransition(), window.LWTheme.next());
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
      btn.addEventListener('click', function (e) { toggleTheme(e.currentTarget || btn); });
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
      // 页面即将离开（点链接跳转/刷新/关标签）时停掉轮询。
      // 不停的话：跳转瞬间发出的请求会被浏览器中断，控制台留下
      //「net::ERR_ABORTED .../api/posts/poll.php」—— 它其实不是 bug，
      // 但会让人误以为接口挂了。主动 clear 掉就彻底没有这条噪音。
      window.addEventListener('pagehide', () => this.stopPolling());
      window.addEventListener('beforeunload', () => this.stopPolling());
    },

    stopPolling() {
      this._leaving = true;
      if (this._pollTimer) {
        clearInterval(this._pollTimer);
        this._pollTimer = null;
      }
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
      // 页面已经在离开的路上：这次请求必然被浏览器中断，干脆不发。
      // 发出去的话控制台会多一条 net::ERR_ABORTED，看起来像接口挂了。
      if (this._leaving) return;
      if (this._polling) return;   // 上一次还没回来，别叠加
      this._polling = true;
      try {
        const url = '/api/posts/poll.php?ids=' + ids.join(',') + '&since=' + encodeURIComponent(this._lastPollTime);
        const data = await fetchAPI(url);
        // 请求回来时页面可能已经开始跳转，此时不再动 DOM
        if (this._leaving) return;
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
      } catch (err) {
      } finally {
        this._polling = false;
      }
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
        /* 空结果要分清「真的没有」和「这次没拿到」。
         *
         * 【原来的 bug】
         * 只要 posts.length === 0 就设 hasMore = false。
         * 但「这次返回空」可能是：
         *   - 游客模式的条数上限刚好卡在当前页
         *   - 反爬挑战失败后接口返回了空数组
         *   - 服务端瞬时故障（这台主机偶发 502）
         * 这些情况下 hasMore 被永久置为 false，用户看到
         * 「没有更多帖子了」，但刷新一次内容又全出来了。
         *
         * 【现在怎么区分】
         * 看服务端的 pagination 字段：
         *   total > 已加载数  → 还有，只是这次没拿到，保留 hasMore
         *   total === 已加载 → 真的到底了
         * 同时给「空但还有」的情况一个重试按钮，
         * 而不是让用户去猜「是不是要刷新」。
         */
        const __total = Number(pagination.total || 0);
        const __loaded = (this.currentPage - 1) * 20;

        if (posts.length === 0 && reset) {
          const reallyEmpty = (__total === 0);
          if (reallyEmpty) {
            this.container.innerHTML =
              '<div class="empty-state"><div class="empty-icon">' + LWIllustration('posts') + '</div><p>暂无帖子</p></div>';
            this.hasMore = false;
          } else {
            // 有内容但这次没拿到 —— 保留 hasMore，给重试入口
            this.container.innerHTML =
              '<div class="empty-state">' +
              '<div class="empty-icon">' + LWIllustration('error') + '</div>' +
              '<p>这次没加载出来，共 ' + __total + ' 条</p>' +
              '<button type="button" class="btn btn-primary" style="margin-top:12px;" ' +
              'onclick="PostFeed.loadPosts(true)">重新加载</button>' +
              '</div>';
            this.hasMore = true;
          }
          this.hideLoading();
          return;
        }
        const frag = document.createDocumentFragment();
        posts.forEach((post) => {
          frag.appendChild(this.createPostCard(post));
        });
        // 一次性挂载，避免逐条插入触发多次回流
        this.container.appendChild(frag);
        /* has_more 优先，但没有这个字段时用 total 兜底推算 ——
         * 早期接口不返回 has_more，直接 || false 会导致
         * 「明明还有却显示没有更多」。 */
        if (typeof pagination.has_more !== 'undefined') {
          this.hasMore = !!pagination.has_more;
        } else if (__total > 0) {
          this.hasMore = (__loaded + posts.length) < __total;
        } else {
          this.hasMore = posts.length >= 20;
        }
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

      // 头像：与 PHP 端 lwAvatarComposite 输出**同一套结构**（.lw-av / .lw-av-img / .lw-widget-frame）。
      // 两边结构必须逐字一致，否则列表页和详情页的头像框会大小不一。
      // 尺寸同样内联写死（28px）：pwa_head 的 img{height:auto} 与"样式表没加载"
      // 这两种情况都出现过 —— 只靠外部 CSS 会让头像按原图尺寸铺开成大方块。
      const AV = 32;
      // 图片型头像框是「图案围着人像」的构图，镂空口径只占槽位六成左右，
      // 头像按普通槽位的 88% 铺满会被框压住脸 —— 所以单列一个更小的内缩。
      const IS_IMG_FRAME = !!(post.author && post.author.frame && post.author.frame.asset);
      const AV_INSET = IS_IMG_FRAME ? Math.round(AV * 0.17) : 2;
      const AV_D = AV - AV_INSET * 2;
      // 头像框 DOM 拼装（与 avatar.php 的输出逐字对齐）。
      //
      // 旧写法有个致命 bug：图片框分支里先在 class 字符串中把引号闭合，
      // 再拼一段 style，末尾又无条件补一个 style —— 结果是
      //   <span class="... lw-frame-img" style="background-image:..." style="position:...">
      // 标签里出现**两个 style 属性**，浏览器只认第一个，框的位置与尺寸全部失效，
      // 图片型头像框在列表页直接看不见。后端 php 用的是「先算 attrs 再拼」的写法，
      // 所以详情页正常、列表页不正常 —— 这就是「有时候装扮不显示」。
      function frameHtml(frame) {
        if (!frame) return '';
        const isImg = !!(frame.asset && String(frame.asset).trim());
        const cls = 'lw-widget lw-widget-frame' + (isImg ? ' lw-frame-img' : ' ' + escapeHtml(frame.css_class || ''));
        let st = 'position:absolute;inset:0;border-radius:50%';
        if (isImg) {
          st = 'background-image:url(&quot;' + escapeHtml(frame.asset) + '&quot;);background-size:100% 100%;background-repeat:no-repeat;background-position:center;' + st;
        }
        return '<span class="' + cls + '" aria-hidden="true" style="' + st + '"></span>';
      }
      // 作者头像 / 昵称 → 个人主页（/pages/u.php?id=）。
      // 只把这两个节点包进 <a>，.post-card-author 仍是唯一 flex 容器，
      // .lw-av / .lw-av-img / .lw-widget-frame 的同心结构完全不动。
      const authorProfileUrl =
        post.author && post.author.id
          ? '/pages/u.php?id=' + encodeURIComponent(String(post.author.id))
          : '';
      const avatarSlotHtml =
        '<span class="lw-av' + (post.author.frame ? ' has-frame' : '') + (IS_IMG_FRAME ? ' has-img-frame' : '') + '" style="--lw-av:' + AV + 'px;width:' + AV + 'px;height:' + AV + 'px;position:relative;display:inline-flex;align-items:center;justify-content:center;border-radius:50%">' +
        frameHtml(post.author.frame) +
        '<img src="' + escapeHtml(post.author.avatar || '/assets/images/default-avatar.svg') + '"' +
        ' class="lw-av-img"' +
        ' width="' + AV_D + '" height="' + AV_D + '"' +
        ' style="position:absolute;left:' + AV_INSET + 'px;top:' + AV_INSET + 'px;width:' + AV_D + 'px;height:' + AV_D + 'px;border-radius:50%;object-fit:cover"' +
        ' alt="头像" decoding="async" loading="lazy" onerror="this.onerror=null;this.src=\'/assets/images/default-avatar.svg\'">' +
        '</span>';
      const avatarLinkedHtml = authorProfileUrl
        ? '<a class="post-author-avatar" href="' + authorProfileUrl + '" aria-label="查看用户主页">' + avatarSlotHtml + '</a>'
        : avatarSlotHtml;
      const authorNameHtml = authorProfileUrl
        ? '<a class="author-name author-name-link" href="' + authorProfileUrl + '">' + escapeHtml(post.author.nickname || '匿名用户') + '</a>'
        : '<span class="author-name">' + escapeHtml(post.author.nickname || '匿名用户') + '</span>';
      // 私信入口从「整颗头像」挪到独立小按钮：头像已改为跳主页，
      // 旧监听会把头像的点击吞掉，导致点头像永远到不了个人主页。
      const authorPmHtml =
        post.author.qq && post.author.id
          ? '<button type="button" class="post-author-pm" data-qq="' + escapeHtml(post.author.qq) +
            '" data-name="' + escapeHtml(post.author.nickname || '同学').replace(/"/g, '&quot;') +
            '" title="发送私信" aria-label="发送私信">' + svgIcon('message', 14) + '</button>'
          : '';
      const authorHtml = post.author
        ? '<div class="post-card-author">' + avatarLinkedHtml + authorNameHtml +
          (post.author.title_text ? renderUserTitle(post.author) : '') +
          authorPmHtml +
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

      // 引用转发（动态套动态）：正文之后的引用小卡片。
      // 数据来自 api/posts/list.php 的 post.quote 摘要；被删时后端只给 {deleted:true}。
      // 整块是一个 <a>，点击直接进入原动态详情；所有文本/图片 URL 都经过 escapeHtml。
      let quoteHtml = '';
      if (post.quote) {
        const q = post.quote;
        if (q.deleted) {
          quoteHtml =
            '<div class="post-quote post-quote-deleted" role="note">' +
            '<span class="post-quote-deleted-text">原动态已删除</span>' +
            '</div>';
        } else {
          const qId = parseInt(q.id, 10) || 0;
          if (qId > 0) {
            const qTitle = String(q.title || '').trim();
            const qContent = String(q.content || '').trim().replace(/\s*\n+\s*/g, ' ');
            const qNick = String(q.nickname || '').trim() || '匿名用户';
            const qImage = String(q.image || '').trim();
            const qMain = qTitle || qContent || '查看原动态';
            quoteHtml =
              '<a class="post-quote" href="/pages/post_detail.php?id=' + qId + '" title="查看被转发的动态">' +
              '<span class="post-quote-avatar">' +
              '<img src="' + escapeHtml(q.avatar || '/assets/images/default-avatar.svg') + '"' +
              ' alt="原动态作者头像" width="28" height="28" loading="lazy" decoding="async"' +
              ' onerror="this.onerror=null;this.src=\'/assets/images/default-avatar.svg\'">' +
              '</span>' +
              '<span class="post-quote-main">' +
              '<span class="post-quote-nick">' + escapeHtml(qNick) + '</span>' +
              '<span class="post-quote-title">' + escapeHtml(qMain) + '</span>' +
              (qTitle && qContent ? '<span class="post-quote-desc">' + escapeHtml(qContent) + '</span>' : '') +
              '</span>' +
              (qImage
                ? '<span class="post-quote-thumb"><img src="' + escapeHtml(qImage) + '" alt="原动态图片"' +
                  ' loading="lazy" decoding="async" onerror="this.parentNode.style.display=\'none\'"></span>'
                : '') +
              '</a>';
          }
        }
      }

      // 附带抽奖：api/posts/list.php 只给 lottery_id（不复制奖品等数据），
      // 这里只拼「🎁 抽奖」徽章 + 入口，详情/倒计时/参与都在 /pages/lottery.php。
      const lotteryHtml = this.renderLotteryBadge(post);

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
        quoteHtml +
        lotteryHtml +
        imagesHtml +
        (pollHtml ? '<div class="post-card-poll">' + pollHtml + '</div>' : '') +
        actionsHtml +
        authorHtml;

      card.addEventListener('click', (e) => {
        if (e.target.closest('button, a, img, .action-btn, .post-card-images')) return;
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

    /* 附带抽奖徽章 HTML。
     * 数据只有 post.lottery_id（列表接口轻量输出），不重复下发奖品/开奖时间；
     * 整块是一个链接，点进抽奖详情页参与。没有抽奖的帖子返回空串。 */
    renderLotteryBadge(post) {
      const lotteryId = parseInt(post.lottery_id, 10) || 0;
      if (lotteryId <= 0) return '';
      return (
        '<a class="post-lottery-badge" href="/pages/lottery.php?id=' + lotteryId + '"' +
        ' title="这条动态附带抽奖，点此参与">' +
        '<span class="post-lottery-tag">🎁 抽奖</span>' +
        '<span class="post-lottery-link">参与抽奖 →</span>' +
        '</a>'
      );
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
        /* 防重入：请求期间按钮已禁用，但仍可能被键盘/连点触发 */
        if (voteBtn.disabled) return;
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

        /* 这个主机每个请求固定约 2 秒。原来点完按钮毫无变化，
         * 用户会以为没点上、反复点（还会连发多个请求）。
         * 现在立刻禁用 + 改文案，给出「已经收到了」的明确信号。 */
        const voteOrigText = voteBtn.textContent;
        voteBtn.disabled = true;
        voteBtn.textContent = '投票中…';
        voteBtn.classList.add('is-busy');

        const restoreVoteBtn = function () {
          voteBtn.disabled = false;
          voteBtn.textContent = voteOrigText;
          voteBtn.classList.remove('is-busy');
        };

        try {
          const res = await fetchAPI('/api/posts/vote.php', {
            method: 'POST',
            body: 'post_id=' + postId + '&option=' + encodeURIComponent(option),
          });
          if (!res.success) {
            restoreVoteBtn();
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
          /* 请求失败：松开按钮，让用户能重试（原来失败后按钮仍是禁用状态） */
          if (voteBtn.isConnected) restoreVoteBtn();
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
          /* 【乐观更新】先改界面，再发请求。
           *
           * 这台主机每个请求固定约 2 秒。原来的写法是
           *   await fetchAPI(...) → 成功后才 classList.add('liked')
           * 点完要盯着屏幕等 2 秒才看到图标变红，中途会以为没点着、反复点。
           *
           * 现在点击瞬间就变色/变数字/弹一下，请求在后台跑；失败再回滚。
           * 这才是原生 App 的反馈节奏。
           */
          const icon = likeBtn.querySelector('.action-icon');
          const count = likeBtn.querySelector('.action-count');
          const wasLiked = likeBtn.classList.contains('liked');
          const oldCount = parseInt(count ? count.textContent : '0', 10) || 0;

          if (wasLiked) {
            likeBtn.classList.remove('liked');
            if (icon) icon.innerHTML = svgIcon('heartOutline', 16);
            if (count) animateCount(count, Math.max(0, oldCount - 1));
          } else {
            likeBtn.classList.add('liked');
            if (icon) icon.innerHTML = svgIcon('heart', 16);
            if (count) animateCount(count, oldCount + 1);
            /* 一次性弹跳：先移除再强制重排，保证连点也能重播 */
            likeBtn.classList.remove('is-pop');
            void likeBtn.offsetWidth;
            likeBtn.classList.add('is-pop');
            setTimeout(() => likeBtn.classList.remove('is-pop'), 480);
          }

          try {
            const data = await fetchAPI('/api/posts/like.php', { method: 'POST', body: 'post_id=' + post.id });
            /* 用服务端真实值校准（期间可能有别人点赞） */
            if (data.data.is_liked) {
              likeBtn.classList.add('liked');
              if (icon) icon.innerHTML = svgIcon('heart', 16);
            } else {
              likeBtn.classList.remove('liked');
              if (icon) icon.innerHTML = svgIcon('heartOutline', 16);
            }
            if (count && data.data.like_count !== undefined) animateCount(count, data.data.like_count);
          } catch (err) {
            /* 回滚到点击前的状态 */
            if (wasLiked) {
              likeBtn.classList.add('liked');
              if (icon) icon.innerHTML = svgIcon('heart', 16);
            } else {
              likeBtn.classList.remove('liked');
              if (icon) icon.innerHTML = svgIcon('heartOutline', 16);
            }
            if (count) animateCount(count, oldCount);
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
          /* 与点赞同样用乐观更新 —— 收藏也是高频操作，不能等 2 秒 */
          const icon = favBtn.querySelector('.action-icon');
          const wasFav = favBtn.classList.contains('favorited');

          if (wasFav) {
            favBtn.classList.remove('favorited');
            if (icon) icon.innerHTML = svgIcon('starOutline', 16);
          } else {
            favBtn.classList.add('favorited');
            if (icon) icon.innerHTML = svgIcon('star', 16);
            favBtn.classList.remove('is-pop');
            void favBtn.offsetWidth;
            favBtn.classList.add('is-pop');
            setTimeout(() => favBtn.classList.remove('is-pop'), 480);
          }
          showToast(wasFav ? '已取消收藏' : '已收藏', wasFav ? 'info' : 'success');

          try {
            const data = await fetchAPI('/api/posts/favorite.php', { method: 'POST', body: 'post_id=' + post.id });
            if (data.data.is_favorited) {
              favBtn.classList.add('favorited');
              if (icon) icon.innerHTML = svgIcon('star', 16);
            } else {
              favBtn.classList.remove('favorited');
              if (icon) icon.innerHTML = svgIcon('starOutline', 16);
            }
          } catch (err) {
            /* 回滚 */
            if (wasFav) {
              favBtn.classList.add('favorited');
              if (icon) icon.innerHTML = svgIcon('star', 16);
            } else {
              favBtn.classList.remove('favorited');
              if (icon) icon.innerHTML = svgIcon('starOutline', 16);
            }
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
          /* 第三参数是「源图元素」：查看器会从它当前的位置/尺寸展开过去，
           * 关闭时再飞回它。这样点击缩略图 → 全屏不是「黑屏一闪」，而是
           * 这张图连续长到满屏（SPT 的 Shared-element Image Expansion）。 */
          Lightbox.openGroup(imageSrcs, i, img);
        });
      });

      // 头像现在包在 .post-author-avatar 链接里（跳个人主页），私信入口独立成
      // .post-author-pm 小按钮；不能再把点击监听挂在头像上，否则头像永远打不开主页。
      const authorPm = card.querySelector('.post-author-pm');
      if (authorPm && window.App && window.App.PrivateMessage) {
        const authorAvatarImg = card.querySelector('.post-card-author .lw-av-img');
        authorPm.addEventListener('click', (e) => {
          e.preventDefault();
          e.stopPropagation();
          App.PrivateMessage.openWith(authorPm.dataset.qq, {
            qq: authorPm.dataset.qq,
            nickname: authorPm.dataset.name || '同学',
            avatar: authorAvatarImg ? authorAvatarImg.src : ''
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

    // 打开单张（保持旧签名可用）。第二个参数可选：源图元素，用于展开动画
    open(src, originEl) {
      this.openGroup([src], 0, originEl);
    },

    /* 打开一组图片，idx 为当前张。
     *
     * 【幂等保护 —— 解决「要点两次才能关掉」】
     *
     * 站点历史上出现过 4 套图片查看器（main.js 的 Lightbox、polish.js 的
     * lw-lightbox、enhancements.js 的代理、post_detail.php 的内联 overlay）。
     * 它们的监听器会同时接住同一次点击，于是：
     *   - 遮罩层被叠了两层
     *   - 关掉上面一层，下面那层还在
     *   - 用户必须再点一次才真的关掉
     *
     * 前面的修复是「让其他几套不要触发」，但那依赖 class 名匹配，
     * 一旦页面结构变化就会失效。这里再加一道兜底：
     * **同一个浏览器 tick 内，openGroup 只真正执行一次**。
     *
     * 为什么用 tick 而不是长期锁：用户确实可能关掉后再点开另一张，
     * 那是合法的第二次打开。同一次点击产生的多次调用都发生在同一 tick 内，
     * 隔一个宏任务（setTimeout 0）就重置，不会误伤正常操作。
     */
    _lastOpenAt: 0,

    /* 【图片展开】记录源图，用于「从缩略图连续扩展到全屏」。
     *
     * 参考 SPT 的 Shared-element Image Expansion：
     *   确认源图与目标图对应，记录源图在视口内的位置、尺寸。
     *   将源区域连续过渡至目标区域，不拉伸图片主体。
     *   关闭时回到仍存在的源图；源项消失时使用合理退场。
     *
     * 实现用 FLIP（First-Last-Invert-Play）：
     *   先把 img 摆到最终位置（Last），
     *   再用 transform 把它「倒推」回源图的位置和尺寸（Invert），
     *   最后动画到 transform: none（Play）。
     *   这样只动 transform，不触发重排，也没有拉伸失真
     *   （因为 transform 的 scale 是等比视觉缩放，不是真的改宽高）。
     */
    _originEl: null,
    _closing: false,

    openGroup(srcs, idx, originEl) {
      // 同一 tick 内的重复调用直接忽略（120ms 足够覆盖同一次点击的所有同步监听）
      const now = Date.now();
      if (now - this._lastOpenAt < 120) {
        return;
      }
      this._lastOpenAt = now;

      if (!this.overlay) { this.init(); }

      // 保险：把可能同时存在的其它查看器关掉
      this._closeForeignViewers();

      this._srcs = (srcs || []).filter(Boolean);
      if (!this._srcs.length) { return; }
      this._closing = false;
      this._originEl = (originEl && originEl.getBoundingClientRect) ? originEl : null;
      this._originRect = this._originEl ? this._originEl.getBoundingClientRect() : null;
      this.overlay.style.display = 'flex';
      document.body.style.overflow = 'hidden';
      this._pendingTx = 0;
      this._pendingTy = 0;
      this.show(typeof idx === 'number' ? idx : 0);
      this._expandIn();
    },

    /* 从源图位置展开到最终位置 */
    _expandIn() {
      const img = this.img;
      const from = this._originRect;
      /* 源图太小（图标级）时不做展开，否则会像「从一粒芝麻里蹦出来」 */
      if (!img || !from || from.width < 40 || from.height < 40) { return; }
      if (window.matchMedia('(prefers-reduced-motion: reduce)').matches) { return; }

      const run = () => {
        if (!img || !to) { return; }
        const to = img.getBoundingClientRect();
        if (!to.width || !to.height) { return; }

        const sx = from.width / to.width;
        const sy = from.height / to.height;
        const dx = from.left - to.left;
        const dy = from.top - to.top;

        /* 等比缩放的「起点」取较大的那个比例，避免图片被拉扁 */
        img.style.transform = 'none';
        if (typeof img.animate !== 'function') { return; }
        try {
          img.animate([
            {
              transform: 'translate(' + dx + 'px,' + dy + 'px) scale(' + sx + ',' + sy + ')',
              opacity: 0.6
            },
            { transform: 'none', opacity: 1 }
          ], {
            duration: 330,
            easing: 'cubic-bezier(0.22, 0.61, 0.36, 1)'
          });
        } catch (e) { /* 老浏览器：直接显示 */ }
      };

      if (img.complete && img.naturalWidth > 0) {
        /* 已缓存：等一帧让布局落定 */
        requestAnimationFrame(run);
      } else {
        img.addEventListener('load', function () { requestAnimationFrame(run); }, { once: true });
      }
    },

    /** 关掉其它几套查看器留下的遮罩（如果它们被意外触发过）。 */
    _closeForeignViewers() {
      try {
        document.querySelectorAll('.lw-lightbox.is-open').forEach(function (el) {
          el.classList.remove('is-open');
          const img = el.querySelector('img');
          if (img) img.src = '';
        });
        document.querySelectorAll('.pw-lightbox').forEach(function (el) { el.remove(); });
        document.querySelectorAll('.lb-lightbox.show').forEach(function (el) { el.classList.remove('show'); });
      } catch (e) { /* 忽略 */ }
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
      if (this._closing) { return; }   /* 关门过程中重复触发（点遮罩+点按钮）不重入 */

      const img = this.img;
      const el = this._originEl;
      const self = this;

      const hide = function () {
        self.overlay.style.display = 'none';
        img.src = '';
        self._srcs = [];
        self._applyTransform(1, 0, 0, false);
        document.body.style.overflow = '';
        self._originEl = null;
        self._originRect = null;
        self._closing = false;
        self._closeForeignViewers();
      };

      /* 源图还在、且还在视口内 —— 才飞回去。
       * 如果源图已被移除（列表刷新）或滚出视口，飞向过期坐标会很怪，
       * 所以那种情况直接淡出。 */
      if (el && el.isConnected && img && img.complete && typeof img.animate === 'function' &&
          !window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
        const from = el.getBoundingClientRect();
        const to = img.getBoundingClientRect();
        const vh = window.innerHeight || 800;
        const inView = from.width > 40 && from.bottom > 0 && from.top < vh && to.width > 0;

        if (inView) {
          const sx = from.width / to.width;
          const sy = from.height / to.height;
          const dx = from.left - to.left;
          const dy = from.top - to.top;
          this._closing = true;
          try {
            const anim = img.animate([
              { transform: 'none', opacity: 1 },
              {
                transform: 'translate(' + dx + 'px,' + dy + 'px) scale(' + sx + ',' + sy + ')',
                opacity: 0.35
              }
            ], { duration: 250, easing: 'cubic-bezier(0.4, 0, 1, 1)' });
            anim.onfinish = hide;
            anim.oncancel = hide;
            return;
          } catch (e) {
            this._closing = false;
          }
        }
      }

      hide();
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

    // 【公告栏必须一直滚动】
    // 之前做过一版「内容没超出容器就静止居中」的优化 —— 确实避免了
    // 「同一句公告并排写两遍」的观感，但代价是：公告短一点就完全不动，
    // 站长看到的就是「通知栏不滚动了」。信息条不滚动会让人以为坏了。
    //
    // 现在的做法：无论长短都做成无缝走马灯 ——
    //   内容 + 间隔 + 内容 + 间隔，用 translateX(-50%) 循环；
    //   · 长内容：与原来的滚动完全一致
    //   · 短内容：两份之间有 35% 容器宽的空白，读起来是「走马灯重播」，
    //     而不是「一句话写了两遍」
    // 速度按距离算（约 60px/秒），长公告不会一闪而过。
    const items = scroll.innerHTML.trim();
    if (!items) return;

    const vw = scroll.clientWidth || 360;
    const gap = Math.max(48, Math.round(vw * 0.35));
    const gapHtml = '<span class="ann-gap" aria-hidden="true" style="display:inline-block;width:' + gap + 'px"></span>';

    scroll.innerHTML = items + gapHtml + items + gapHtml;
    // 单份宽度 = 总宽的一半
    const half = Math.round(items.length > 0 ? scroll.scrollWidth / 2 : scroll.scrollWidth);
    const dist = Math.max(120, Math.round(scroll.scrollWidth / 2));
    const dur = Math.max(10, Math.round(dist / 60)) + 's';

    /* 先标类再写行内 !important：
       类是给没开行内样式的场景兜底；行内 !important 用来压过
       prefers-reduced-motion 的通配规则（信息条不该被装饰性动效规则关掉）。 */
    scroll.classList.add('is-scrolling');
    const force = (prop, val) => scroll.style.setProperty(prop, val, 'important');
    force('animation-name', 'announcementScroll');
    force('animation-duration', dur);
    force('animation-timing-function', 'linear');
    force('animation-iteration-count', 'infinite');
    force('animation-play-state', 'running');
    /* 元素自身也是 flex 子项：只设 width:max-content 会被 flex-shrink 压回容器宽，
       导致 -50% 位移只走容器一半就回跳（实测 1168px 内容只滚了 183px）。
       必须同时禁止收缩，宽度才真的是内容宽。 */
    force('width', 'max-content');
    force('flex', '0 0 auto');

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

    // 关掉任意一个自动弹窗后，10 分钟内不再自动弹任何弹窗。
    // 与 enhancements.js 的 LWPopupCooldown 共用同一个 localStorage 键，
    // 两个文件谁先加载都不影响判断。
    const COOLDOWN_MS = 10 * 60 * 1000;
    const COOLDOWN_KEY = 'lw_popup_closed_at';

    function lastClosedAt() {
      try { return parseInt(localStorage.getItem(COOLDOWN_KEY) || '0', 10) || 0; }
      catch (e) { return 0; }
    }
    function inCooldown() {
      return (Date.now() - lastClosedAt()) < COOLDOWN_MS;
    }
    function markClosed() {
      try { localStorage.setItem(COOLDOWN_KEY, String(Date.now())); } catch (e) {}
    }

    function next() {
      if (running || queue.length === 0) return;
      // 冷却期内：整队丢弃，不再打扰
      if (inCooldown()) { queue.length = 0; return; }
      running = true;
      const show = queue.shift();
      show(function release() {
        running = false;
        // 用户刚关掉一个 → 记时间并清空剩余队列（这就是「10 分钟内别再弹」）
        markClosed();
        queue.length = 0;
        setTimeout(next, 260);
      });
    }

    return {
      /**
       * 排队展示一个弹窗。
       * @param {(done: Function) => void} show 负责展示弹窗；用户关闭后必须调用 done()
       */
      push(show) {
        if (inCooldown()) return;   // 刚被关过 → 本次直接不排队
        queue.push(show);
        next();
      },
      inCooldown,
      markClosed,
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

  /**
   * 是否允许自动弹出赞助窗。
   *
   * 【为什么加这道闸】原先只要「本月没弹过」就在登录后的第一个页面自动弹，
   * 结果学生每次打开首页先被整屏二维码糊脸，首页内容全被盖住 —— 这是最伤体验的
   * 一处「打扰式运营」。现在改为**只在首页、且用户已读过一点内容（滚动超过 60%）
   * 或停留 25 秒后**，才礼貌地弹一次。
   * 想在别的页面主动看，点页脚的「支持我们」入口即可。
   */
  let sponsorArmed = false;
  function armSponsorModal() {
    if (!IS_LOGGED_IN) return;
    if (typeof IS_SPONSOR !== 'undefined' && IS_SPONSOR) return;
    if (!shouldShowSponsor()) return;
    if (location.pathname !== '/' && location.pathname !== '/index.php') return;
    if (sponsorArmed) return;
    sponsorArmed = true;

    let fired = false;
    const fire = function () {
      if (fired) return;
      fired = true;
      window.removeEventListener('scroll', onScroll);
      initSponsorModal();
    };
    const onScroll = function () {
      const doc = document.documentElement;
      const total = doc.scrollHeight - window.innerHeight;
      if (total > 0 && window.scrollY / total > 0.6) fire();
    };
    window.addEventListener('scroll', onScroll, { passive: true });
    setTimeout(fire, 25000);
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
    // 通知系统升级：分类标签页 / 静默偏好 / 历史分页
    _tab: 'all',            // all | interact | system
    _page: 1,
    _limit: 20,
    _hasMore: false,
    _items: [],             // 当前已加载通知（含已读，用于历史）
    _seenIds: null,         // 已知通知 id，用于「到达动效」判定
    _st: null,              // 面板内元素引用缓存

    PREFS_KEY: 'lw_notify_prefs',

    init() {
      if (!IS_LOGGED_IN) return;
      this.createBell();
      this.createPanel();
      this.bindEvents();
      this.fetchNotifications();
      this.startPolling();
      // 云端偏好拉取完成后刷新静默 UI（流体云多端同步）
      window.addEventListener('lw:ui-prefs', () => { try { this.syncSilentUI(); } catch (e) {} });
      // 手机客户端点通知进来时地址带 #notifications，直接展开面板，
      // 省得用户再自己去找顶栏的铃铛。
      if (window.location.hash === '#notifications') {
        this.panel.classList.add('show');
        if (window.history && window.history.replaceState) {
          window.history.replaceState(null, '', window.location.pathname + window.location.search);
        }
      }
    },

    /* ---------- 静默偏好（本地） ---------- */
    prefs() {
      const def = { silent: false, silentStart: 23, silentEnd: 7, unreadFirst: false, unreadOnly: false };
      try {
        const o = JSON.parse(localStorage.getItem(this.PREFS_KEY) || '{}');
        return Object.assign(def, o || {});
      } catch (e) { return def; }
    },
    savePrefs(p) {
      try { localStorage.setItem(this.PREFS_KEY, JSON.stringify(p)); } catch (e) {}
      // 流体云：通知偏好变更上云
      try { if (window.LWLiquidGlass && typeof window.LWLiquidGlass.syncCloud === 'function') window.LWLiquidGlass.syncCloud(); } catch (e) {}
    },
    /** 未读优先按钮状态同步 */
    syncUnreadUI() {
      if (!this._st || !this._st.unread) return;
      const on = !!this.prefs().unreadFirst;
      this._st.unread.classList.toggle('is-on', on);
      this._st.unread.setAttribute('aria-pressed', on ? 'true' : 'false');
      this._st.unread.innerHTML = '未读优先' + (on ? ' ✓' : '');
    },

    /** 「只看未读」按钮态 */
    syncUnreadOnlyUI() {
      if (!this._st || !this._st.onlyUnread) return;
      const on = !!this.prefs().unreadOnly;
      this._st.onlyUnread.classList.toggle('is-on', on);
      this._st.onlyUnread.setAttribute('aria-pressed', on ? 'true' : 'false');
      this._st.onlyUnread.innerHTML = '只看未读' + (on ? ' ✓' : '');
    },

    /** 当前是否处于静默时段（跨零点用 OR 判定） */
    isQuietNow() {
      const p = this.prefs();
      if (!p.silent) return false;
      const h = new Date().getHours();
      return p.silentStart <= p.silentEnd
        ? (h >= p.silentStart && h < p.silentEnd)
        : (h >= p.silentStart || h < p.silentEnd);
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
        '<div class="lw-ntf-head">' +
          '<span class="lw-ntf-title">消息通知</span>' +
          '<button type="button" class="lw-ntf-silent" data-ntf-silent aria-pressed="false" title="静默通知（23:00-次日7:00 不打扰）">' +
            svgIcon('bell', 15) + '<span>静默</span>' +
          '</button>' +
          '<button type="button" class="lw-ntf-unread" aria-pressed="false" title="未读通知优先显示">未读优先</button>' +
          '<button type="button" class="lw-ntf-onlyunread" data-ntf-onlyunread aria-pressed="false" title="只显示未读通知">只看未读</button>' +
          '<button type="button" class="mark-all-read">全部已读</button>' +
        '</div>' +
        '<div class="lw-ntf-tabs" role="tablist">' +
          '<button type="button" class="lw-ntf-tab is-active" data-ntf-tab="all" role="tab">全部</button>' +
          '<button type="button" class="lw-ntf-tab" data-ntf-tab="interact" role="tab">互动</button>' +
          '<button type="button" class="lw-ntf-tab" data-ntf-tab="lottery" role="tab">抽奖</button>' +
          '<button type="button" class="lw-ntf-tab" data-ntf-tab="system" role="tab">系统</button>' +
        '</div>' +
        '<div class="notification-panel-list lw-ntf-list"><div class="notification-panel-empty">' +
        LWIllustration('notifications', 40) +
        '<p>暂无通知</p></div></div>' +
        '<div class="lw-ntf-foot"><button type="button" class="lw-ntf-more" data-ntf-more disabled>没有更多了</button></div>';
      // 面板必须挂到 <body> 而不是塞进铃铛按钮里，原因有两个：
      //   1) 把一个 <button> 放进另一个 <button> 是非法嵌套，部分移动端浏览器会把内层按钮
      //      的点击重定向到外层「铃铛」上 —— 表现就是「点『全部已读』没反应，面板反而关了」；
      //   2) 顶栏在桌面端带 backdrop-filter，会成为 position:fixed 后代的包含块，面板会错位。
      document.body.appendChild(this.panel);
      this._st = {
        list: this.panel.querySelector('.lw-ntf-list'),
        more: this.panel.querySelector('[data-ntf-more]'),
        silent: this.panel.querySelector('[data-ntf-silent]'),
        unread: this.panel.querySelector('.lw-ntf-unread'),
        onlyUnread: this.panel.querySelector('[data-ntf-onlyunread]'),
        tabs: Array.prototype.slice.call(this.panel.querySelectorAll('[data-ntf-tab]')),
      };
      this.syncSilentUI();
      this.syncUnreadUI();
      this.syncUnreadOnlyUI();
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
        // 打开时若还停在历史里，回到最新一页，避免看到过期内容
        if (this.panel.classList.contains('show') && this._page !== 1) {
          this._page = 1;
          this.fetchNotifications();
        }
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

      // 分类标签页切换
      this._st.tabs.forEach((tabEl) => {
        tabEl.addEventListener('click', (e) => {
          e.stopPropagation();
          const tab = tabEl.getAttribute('data-ntf-tab');
          if (tab === this._tab) return;
          this._tab = tab;
          this._st.tabs.forEach((t) => t.classList.toggle('is-active', t === tabEl));
          /* 按分类向服务端重新取：只过滤已加载的 20 条会让人误以为"抽奖通知只有这几条" */
          this._page = 1;
          this._items = [];
          this._seenIds = null;
          this.fetchNotifications();
          this.renderList();
        });
      });

      // 未读优先排序（本地偏好）
      if (this._st.unread) {
        this._st.unread.addEventListener('click', (e) => {
          e.stopPropagation();
          const p = this.prefs();
          p.unreadFirst = !p.unreadFirst;
          this.savePrefs(p);
          this.syncUnreadUI();
          this.renderList();
        });
      }

      // 只看未读
      if (this._st.onlyUnread) {
        this._st.onlyUnread.addEventListener('click', (e) => {
          e.stopPropagation();
          const p = this.prefs();
          p.unreadOnly = !p.unreadOnly;
          this.savePrefs(p);
          this.syncUnreadOnlyUI();
          this._page = 1;
          this._items = [];
          this._seenIds = null;
          this.fetchNotifications();
        });
      }

      // 静默开关（本地偏好，跨设备由「流体云」同步）
      this._st.silent.addEventListener('click', (e) => {
        e.stopPropagation();
        const p = this.prefs();
        p.silent = !p.silent;
        this.savePrefs(p);
        this.syncSilentUI();
        showToast(p.silent ? '已开启静默通知' : '已关闭静默通知', 'info');
      });

      // 加载更多（历史）
      this._st.more.addEventListener('click', (e) => {
        e.stopPropagation();
        if (this._st.more.disabled) return;
        this._page += 1;
        this.fetchNotifications(true);
      });

      this.panel.querySelector('.mark-all-read').addEventListener('click', async (e) => {
        e.stopPropagation();
        try {
          await fetchAPI('/api/user/notifications.php', { method: 'POST', body: 'action=read_all' });
          this.unreadCount = 0;
          this._srvCounts = { all: 0, interact: 0, system: 0, lottery: 0 };
          this.updateBadge();
          this._items = this._items.map((n) => Object.assign({}, n, { is_read: true }));
          this.renderList(true);
          showToast('已全部标记为已读', 'success');
        } catch (err) {}
      });
    },

    /** 静默按钮态 + 面板内「静默时段」提示条 */
    syncSilentUI() {
      const p = this.prefs();
      if (!this._st || !this._st.silent) return;
      this._st.silent.classList.toggle('is-on', !!p.silent);
      this._st.silent.setAttribute('aria-pressed', p.silent ? 'true' : 'false');
      const quietNow = this.isQuietNow();
      let bar = this.panel.querySelector('.lw-ntf-quiet');
      if (quietNow && !bar) {
        bar = document.createElement('div');
        bar.className = 'lw-ntf-quiet';
        bar.innerHTML = svgIcon('bell', 14) + '<span>静默时段 · 新通知不会打扰你</span>';
        this.panel.insertBefore(bar, this._st.list);
      } else if (!quietNow && bar) {
        bar.remove();
      }
    },

    startPolling() {
      this._pollTimer = setInterval(() => {
        if (document.hidden) return; // 后台不轮询，省流量
        this._page = 1;              // 轮询只刷最新一页，避免与「加载更多」错页
        this.fetchNotifications();
      }, 60000);
      document.addEventListener('visibilitychange', () => {
        if (!document.hidden) { this._page = 1; this.fetchNotifications(); } // 回前台立刻刷新未读红点
      });
    },

    /** 加载骨架：避免切标签/首次打开时先闪"暂无通知"再跳成列表 */
    renderSkeleton() {
      if (!this._st) return;
      const row = '<div class="lw-ntf-skeleton"><span class="sk-avatar"></span><span class="sk-lines"><i></i><i></i></span></div>';
      this._st.list.innerHTML = row + row + row;
    },

    async fetchNotifications(isMore) {
      try {
        if (!isMore) {
          this._loading = true;
          this.renderSkeleton();
        }
        // 拉取含已读的完整列表（不再用 unread=1），既拿未读数也保留历史记录
        const cat = (this._tab && this._tab !== 'all') ? ('&category=' + encodeURIComponent(this._tab)) : '';
        const onlyUnread = this.prefs().unreadOnly ? '&unread=1' : '';
        const data = await fetchAPI(
          '/api/user/notifications.php?page=' + this._page + '&limit=' + this._limit + cat + onlyUnread
        );
        if (!data.data) return;
        this.unreadCount = data.data.unread_count || 0;
        this.updateBadge();

        const batch = data.data.notifications || [];
        const hasMore = !!data.data.has_more;

        // 到达动效：记录「上一轮未出现过」的 id（且为未读），仅对这些播放柔和滑入
        if (this._seenIds === null) {
          this._seenIds = new Set(batch.map((n) => String(n.id)));
          this._newIds = new Set();
        } else {
          const fresh = new Set();
          batch.forEach((n) => {
            const k = String(n.id);
            if (!this._seenIds.has(k) && !n.is_read) fresh.add(k);
            this._seenIds.add(k);
          });
          this._newIds = fresh;
        }

        this._loading = false;
        this._srvCounts = (data && data.data && data.data.type_counts) || null;
        this._items = isMore ? this._items.concat(batch) : batch;
        this._hasMore = hasMore;
        if (this._st) {
          this._st.more.disabled = !hasMore;
          this._st.more.textContent = hasMore ? '加载更多历史' : '没有更多了';
        }
        this.renderList();

        // 到达提醒：非静默时段且有新未读时刷新静默提示条状态
        if (this._newIds.size > 0) this.syncSilentUI();
      } catch (err) {}
    },

    /** 左滑/点击动作条：标为已读（服务端会直接删除该行） */
    async markReadBySwipe(item) {
      const id = item.getAttribute('data-id');
      const wasUnread = item.classList.contains('unread');
      if (!id) return;
      if (wasUnread) {
        try {
          await fetchAPI('/api/user/notifications.php', { method: 'POST', body: 'action=read&id=' + id });
          this.unreadCount = Math.max(0, this.unreadCount - 1);
          this.updateBadge();
          const rec = this._items.find((n) => String(n.id) === String(id));
          if (rec) rec.is_read = true;
          if (this._srvCounts && rec) {
            const c2 = this.classify(rec)[0];
            this._srvCounts.all = Math.max(0, (this._srvCounts.all || 0) - 1);
            this._srvCounts[c2] = Math.max(0, (this._srvCounts[c2] || 0) - 1);
          }
        } catch (err) { return; }
      }
      item.style.height = item.offsetHeight + 'px';
      item.classList.add('is-removing');
      setTimeout(() => {
        item.remove();
        this._items = (this._items || []).filter((n) => String(n.id) !== String(id));
        this.updateTabCounts(this._items);
        if (!this._st.list.children.length) this.renderList();
      }, 200);
    },

    updateBadge() {
      if (this.unreadCount > 0) {
        this.badge.style.display = 'flex';
        this.badge.textContent = this.unreadCount > 99 ? '99+' : this.unreadCount;
      } else {
        this.badge.style.display = 'none';
      }
    },

    /** 通知归类与优先级：由既有 type 推导，无需改动后端数据 */
    classify(n) {
      const t = n.type || 'system';
      const map = {
        comment: ['interact', 'normal', '评论'],
        reply:   ['interact', 'normal', '回复'],
        mention: ['interact', 'high',   '@提到我'],
        follow:  ['interact', 'normal', '关注'],
        like:    ['interact', 'low',    '点赞'],
        pm:      ['system',   'high',   '私信'],
        admin:   ['system',   'high',   '管理'],
        system:  ['system',   'low',    '系统'],
        lottery_result: ['lottery', 'high', '抽奖'],
      };
      return map[t] || ['system', 'normal', '通知'];
    },

    /** 依据当前标签页重绘列表 */
    renderList(isCleared) {
      if (!this._st) return;
      const all = this._items || [];
      let list = all;
      if (this.prefs().unreadFirst) {
        list = list.slice().sort((a, b) => (a.is_read ? 1 : 0) - (b.is_read ? 1 : 0));
      }
      if (this.prefs().unreadOnly) list = list.filter((n) => !n.is_read);
      if (this._tab === 'interact') list = list.filter((n) => this.classify(n)[0] === 'interact');
      else if (this._tab === 'system') list = list.filter((n) => this.classify(n)[0] === 'system');
      else if (this._tab === 'lottery') list = list.filter((n) => this.classify(n)[0] === 'lottery');

      // 更新标签上的未读数
      this.updateTabCounts(all);
      this.updatePanelList(list, isCleared);
    },

    /** 服务端全量未读分类数优先；没有就用当前已加载的近似值 */
    applySrvCounts() { if (this._srvCounts) this.updateTabCounts(this._items);
    },

    updateTabCounts(all) {
      if (!this._st) return;
      const counts = { all: 0, interact: 0, system: 0, lottery: 0 };
      if (this._srvCounts) {
        Object.keys(counts).forEach((k) => { counts[k] = Number(this._srvCounts[k] || 0); });
      } else (all || []).forEach((n) => {
        if (n.is_read) return;
        const cat = this.classify(n)[0];
        counts.all++;
        if (cat === 'interact') counts.interact++;
        else if (cat === 'lottery') counts.lottery++;
        else if (cat === 'system') counts.system++;
      });
      this._st.tabs.forEach((t) => {
        const key = t.getAttribute('data-ntf-tab');
        const base = { all: '全部', interact: '互动', system: '系统', lottery: '抽奖' }[key];
        const c = counts[key] || 0;
        t.innerHTML = base + (c > 0 ? ' <span class="lw-ntf-tab-n">' + c + '</span>' : '');
      });
    },

    updatePanelList(notifications, isCleared) {
      if (this._loading) return;
      const listEl = this._st ? this._st.list : this.panel.querySelector('.notification-panel-list');
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
        lottery_result: '🎁',
        achievement: '🏅',
        invite: '🎫',
      };
      const fresh = this._newIds || new Set();
      listEl.innerHTML = notifications
        .map((n) => {
          const icon = typeIcons[n.type] || typeIcons.system;
          const isUnread = !n.is_read;
          const cls = this.classify(n);
          const isNew = fresh.has(String(n.id));
          return (
            '<div class="notification-item lw-ntf-item' +
            (isUnread ? ' unread' : '') +
            (isNew ? ' lw-ntf-new' : '') +
            '" data-id="' +
            n.id +
            '" data-post-id="' +
            (n.post_id || '') +
            '" data-link="' +
            (n.link || '') +
            '" data-type="' +
            (n.type || '') +
            '" data-cat="' + cls[0] + '" data-pri="' + cls[1] + '">' +
            '<div class="notif-icon">' +
            icon +
            '</div>' +
            '<div class="notif-body">' +
            '<div class="notif-text">' +
            '<span class="lw-ntf-cat">' + cls[2] + '</span>' +
            escapeHtml(n.content || '') +
            '</div>' +
            '<div class="notif-time">' +
            (n.timeAgo || formatTime(n.created_at)) +
            '</div>' +
            '</div>' +
            '<span class="lw-ntf-dot" aria-hidden="true"></span>' +
            '<span class="ntf-swipe-action" role="button" tabindex="0" aria-label="标为已读">已读</span>' +
            '</div>'
          );
        })
        .join('');
      this._newIds = new Set();

      // 左滑标为已读（移动端手势）：位移超过阈值才触发，纵向滚动不干扰
      listEl.querySelectorAll('.notification-item').forEach((item) => {
        const action = item.querySelector('.ntf-swipe-action');
        if (!action) return;
        let sx = 0, sy = 0, dx = 0, swiping = false, decided = false;
        const reset = () => { item.style.transform = ''; item.classList.remove('is-swiped'); dx = 0; swiping = false; decided = false; };
        item.addEventListener('touchstart', (e) => {
          if (e.touches.length !== 1) return;
          sx = e.touches[0].clientX; sy = e.touches[0].clientY; dx = 0; decided = false; swiping = false;
        }, { passive: true });
        item.addEventListener('touchmove', (e) => {
          if (e.touches.length !== 1) return;
          const cx = e.touches[0].clientX - sx;
          const cy = e.touches[0].clientY - sy;
          if (!decided) {
            if (Math.abs(cx) < 8 && Math.abs(cy) < 8) return;
            decided = true;
            swiping = Math.abs(cx) > Math.abs(cy) && cx < 0;
            if (!swiping) return;
          }
          if (!swiping) return;
          dx = Math.max(-84, Math.min(0, cx));
          item.style.transform = 'translateX(' + dx + 'px)';
          item.classList.add('is-swiped');
        }, { passive: true });
        item.addEventListener('touchend', () => {
          if (!swiping) { reset(); return; }
          if (dx <= -48) { markReadBySwipe(item); }
          reset();
        });
        action.addEventListener('click', (e) => { e.stopPropagation(); markReadBySwipe(item); });
      });

      listEl.querySelectorAll('.notification-item').forEach((item) => {
        item.addEventListener('click', async (e) => {
          e.stopPropagation();
          const id = item.getAttribute('data-id');
          const postId = item.getAttribute('data-post-id');
          const wasUnread = item.classList.contains('unread');

          if (wasUnread) {
            try {
              await fetchAPI('/api/user/notifications.php', { method: 'POST', body: 'action=read&id=' + id });
              item.classList.remove('unread');
              this.unreadCount = Math.max(0, this.unreadCount - 1);
              this.updateBadge();
              const rec = this._items.find((n) => String(n.id) === String(id));
              if (rec) rec.is_read = true;
              if (this._srvCounts && rec) {
                const c2 = this.classify(rec)[0];
                this._srvCounts.all = Math.max(0, (this._srvCounts.all || 0) - 1);
                this._srvCounts[c2] = Math.max(0, (this._srvCounts[c2] || 0) - 1);
              }
              this.updateTabCounts(this._items);
            } catch (err) {}
          }

          /* 点击通知后的跳转。
           *
           * 【原来只处理 post_id】
           * 互动通知（评论/点赞）带 post_id，能跳；
           * 但系统消息（admin 类型，占全部通知的 555/710）没有 post_id，
           * 点了完全没反应 —— 用户以为坏了。
           *
           * 【现在三级兜底】
           *   1. 有 link 字段 → 直接跳（举报、封禁这类指向具体页面）
           *   2. 有 post_id → 跳帖子详情
           *   3. 都没有 → 按类型给一个合理的落地页
           */
          const link = item.getAttribute('data-link') || '';
          const ntfType = item.getAttribute('data-type') || '';

          let target = '';
          if (link) {
            target = link.charAt(0) === '/' ? (SITE_URL + link) : link;
          } else if (postId) {
            target = SITE_URL + '/pages/post_detail.php?id=' + postId;
          } else if (ntfType === 'admin' || ntfType === 'system') {
            // 系统消息：站务通知大多指向后台或公告
            target = SITE_URL + '/pages/notifications.php';
          } else if (ntfType === 'achievement') {
            target = SITE_URL + '/pages/user_center.php?tab=achievements';
          } else if (ntfType === 'invite') {
            target = SITE_URL + '/pages/user_center.php?tab=invite';
          } else if (ntfType === 'follow') {
            target = SITE_URL + '/pages/notifications.php';
          } else if (ntfType === 'pm') {
            target = SITE_URL + '/pages/messages.php';
          } else if (ntfType === 'lottery_result') {
            target = SITE_URL + '/pages/lottery.php';
          }

          if (target) {
            this.panel.classList.remove('show');
            // 站内相对路径统一处理，避免 // 或漏协议
            window.location.href = target;
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
        /* 乐观更新：评论点赞同样是高频操作，不能等 2 秒才变色 */
        const wasLiked = likeBtn.classList.contains('liked');
        const clCountEl = likeBtn.querySelector('.cl-count');
        const clOld = clCountEl ? (parseInt(clCountEl.textContent, 10) || 0) : 0;

        if (wasLiked) {
          likeBtn.classList.remove('liked');
          likeBtn.innerHTML = svgIcon('heartOutline', 12) + ' <span class="cl-count">' + Math.max(0, clOld - 1) + '</span>';
        } else {
          likeBtn.classList.add('liked');
          likeBtn.innerHTML = svgIcon('heart', 12) + ' <span class="cl-count">' + (clOld + 1) + '</span>';
          likeBtn.classList.remove('is-pop');
          void likeBtn.offsetWidth;
          likeBtn.classList.add('is-pop');
          setTimeout(function () { likeBtn.classList.remove('is-pop'); }, 480);
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
          /* 回滚 */
          if (wasLiked) {
            likeBtn.classList.add('liked');
            likeBtn.innerHTML = svgIcon('heart', 12) + ' <span class="cl-count">' + clOld + '</span>';
          } else {
            likeBtn.classList.remove('liked');
            likeBtn.innerHTML = svgIcon('heartOutline', 12) + ' <span class="cl-count">' + clOld + '</span>';
          }
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
      armSponsorModal();
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
    svgIcon: svgIcon,
    LWIcon: LWIcon,
    LWIllustration: LWIllustration,
    // 分类 → URL 写法（social_chat → social），供 enhancements.js 等复用
    categoryUrlKey: categoryUrlKey,
    showToast: showToast,
    copyToClipboard: copyToClipboard,
    fetchAPI: fetchAPI,
    fetchRaw: fetchRaw,
    // 手动作废某个读请求的缓存（点赞/发帖后让列表重拉时用）
    invalidateCache: function (url) {
      if (window.LWRequestCache) { window.LWRequestCache.invalidate(url); }
    },
    applyCSRFToken: applyCSRFToken,
    // 站内是否跑在自家安卓客户端里（供布局适配与 APK 直装判断）
    isNativeApp: isNativeApp,
    formatTime: formatTime,
    escapeHtml: escapeHtml,
    esc: escapeHtml,
    pickReadableFg: pickReadableFg,
    debounce: debounce,
    throttle: throttle,
    Modal: Modal,
    /* 图片查看器。
     *
     * 【为什么必须显式暴露】
     * Lightbox 原本只是本文件内的 const，从未挂到 window.App，
     * 而 enhancements.js / polish.js / post_detail.php 都写的是
     * if (window.App && window.App.Lightbox) —— 判断恒为 false，
     * 于是它们各自回退到自己的简易灯箱，一次点击叠出两层遮罩。
     * 这就是「要点两次才能关掉」的根因。
     *
     * 位置必须在 const Lightbox 之后（TDZ 约束），window.App 赋值在文件尾部，安全。
     */
    Lightbox: Lightbox,
    PostFeed: PostFeed,
    NotificationSystem: NotificationSystem,
    toggleTheme: toggleTheme,
    applyTheme: applyTheme,
    closeSponsorModal: closeSponsorModal,
  };

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }

  // ===== 顶栏「申请头衔」弹窗 =====
  // 【已下线】头衔改为用户在个人中心直接自定义（服务端只做违规词校验），
  // 不再有「提交申请 → 站长审核」这一步，因此这里不再挂载任何弹窗逻辑。
  // 保留这一段注释是为了让后来者知道：删除的是流程，不是 bug。

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
      // 注意：**不能 preventDefault** —— 站名是「全站回首页」的官方入口，
      // 早期这里为了计数阻止了默认跳转，结果全站点站名都没反应。现在照常跳转。
      var logo = document.querySelector('.site-logo');
      if (logo) {
        logo.addEventListener('click', function () {
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
  /* ==================================================================
     图片放大查看
     ------------------------------------------------------------------
     任何贴子图片（.post-card / .post-images / .pd-gallery / .comment-text 内的 img）
     点击后全屏查看：
       · 点图片在 1x / 2.2x 之间切换，放大后可拖动
       · Esc / 点背景 / 点关闭按钮退出
       · 打开的是原图（去掉卡片用的 w=420 缩略参数）
     ================================================================== */
  function initImageZoom() {
    var overlay = null, imgEl = null, barEl = null;
    var zoomed = false, scale = 1, tx = 0, ty = 0;
    var dragging = false, moved = false;
    var startX = 0, startY = 0, baseX = 0, baseY = 0;

    function fullSrc(src) {
      // 卡片用 /api/qzone/image.php?...&w=420 缩略图；放大时去掉 w 取原图
      return src.replace(/([?&])w=\\d+&?/, '$1').replace(/[?&]$/, '');
    }

    function build() {
      overlay = document.createElement('div');
      overlay.className = 'lw-zoom-overlay';
      overlay.setAttribute('role', 'dialog');
      overlay.setAttribute('aria-label', '查看大图');
      overlay.innerHTML =
        '<img class="lw-zoom-img" alt="">' +
        '<button class="lw-zoom-close" aria-label="关闭">✕</button>' +
        '<div class="lw-zoom-bar">' +
        '  <button class="lw-zoom-btn" data-act="toggle">放大</button>' +
        '  <button class="lw-zoom-btn lw-zoom-btn-close" data-act="close">关闭</button>' +
        '</div>' +
        '<div class="lw-zoom-hint">双击放大 · 单击图片退出 · 拖动查看</div>';
      document.body.appendChild(overlay);
      imgEl = overlay.querySelector('.lw-zoom-img');
      barEl = overlay.querySelector('.lw-zoom-bar');

      // 背景 / 按钮点击关闭
      overlay.addEventListener('click', function (e) {
        var act = e.target && e.target.getAttribute && e.target.getAttribute('data-act');
        if (e.target === overlay || act === 'close' || e.target.classList.contains('lw-zoom-close')) {
          close();
        } else if (act === 'toggle') {
          toggleZoom(overlay);
        }
      });

      // 图片：未放大时双击放大；已放大时单击退出（保证任何情况下都能退出来）
      var lastTap = 0;
      imgEl.addEventListener('click', function (e) {
        e.stopPropagation();
        if (zoomed) { close(); return; }
        var now = Date.now();
        if (now - lastTap < 320) {
          toggleZoom(e);
          lastTap = 0;
        } else {
          lastTap = now;
        }
      });

      imgEl.addEventListener('pointerdown', function (e) {
        dragging = true; moved = false;
        startX = e.clientX; startY = e.clientY; baseX = tx; baseY = ty;
      });
      imgEl.addEventListener('pointermove', function (e) {
        if (!dragging) return;
        var dx = e.clientX - startX, dy = e.clientY - startY;
        if (Math.abs(dx) + Math.abs(dy) > 8) { moved = true; }
        if (zoomed) {
          tx = baseX + dx; ty = baseY + dy;
          imgEl.style.transform = 'translate(' + tx + 'px,' + ty + 'px) scale(' + scale + ')';
        }
      });
      imgEl.addEventListener('pointerup', function (e) {
        if (!dragging) return;
        dragging = false;
        var dy = e.clientY - startY;
        // 未放大时向下滑 80px 直接关闭（手机端的自然手势）
        if (!zoomed && moved && dy > 80) { close(); }
      });
      imgEl.addEventListener('pointercancel', function () { dragging = false; });

      document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && overlay.classList.contains('is-open')) { close(); }
      });
    }

    function toggleZoom(e) {
      if (!overlay) return;
      zoomed = !zoomed;
      if (zoomed) {
        scale = 2.2;
        var r = imgEl.getBoundingClientRect();
        var px = e && e.clientX ? (e.clientX - r.left) / r.width : 0.5;
        var py = e && e.clientY ? (e.clientY - r.top) / r.height : 0.5;
        tx = (0.5 - px) * r.width * (scale - 1);
        ty = (0.5 - py) * r.height * (scale - 1);
      } else {
        scale = 1; tx = 0; ty = 0;
      }
      imgEl.classList.toggle('is-zoomed', zoomed);
      imgEl.style.transform = 'translate(' + tx + 'px,' + ty + 'px) scale(' + scale + ')';
      var tb = overlay.querySelector('[data-act="toggle"]');
      if (tb) { tb.textContent = zoomed ? '还原' : '放大'; }
    }

    function open(src) {
      if (!overlay) { build(); }
      zoomed = false; scale = 1; tx = 0; ty = 0;
      imgEl.classList.remove('is-zoomed');
      imgEl.style.transform = '';
      imgEl.src = fullSrc(src);
      var tb = overlay.querySelector('[data-act="toggle"]');
      if (tb) { tb.textContent = '放大'; }
      overlay.classList.add('is-open');
      document.body.style.overflow = 'hidden';
    }

    function close() {
      if (!overlay) return;
      overlay.classList.remove('is-open');
      zoomed = false; scale = 1; tx = 0; ty = 0;
      imgEl.style.transform = '';
      imgEl.src = '';
      document.body.style.overflow = '';
    }

    document.addEventListener('click', function (e) {
      var t = e.target;
      if (!t || t.tagName !== 'IMG') { return; }
      if (!t.closest('.post-card, .post-images, .pd-gallery, .comment-text, .qz-source-imgs, .pending-item')) { return; }
      if (t.closest('.lw-zoom-overlay')) { return; }
      var src = t.currentSrc || t.src;
      if (!src) { return; }
      e.preventDefault();
      open(src);
    });

    window.openZoom = open;
  }

  initImageZoom();



  /* 卡片聚光灯（RareUI 风格）：跟随指针写入 --mx/--my，CSS 用它们画高光 */
  function initCardSpotlight() {
    var cards = document.querySelectorAll('.post-card, .tool-card, .lz-card');
    if (!cards.length || window.matchMedia('(prefers-reduced-motion: reduce)').matches) return;
    cards.forEach(function (card) {
      if (card.__ruSpot) return;
      card.__ruSpot = true;
      card.addEventListener('pointermove', function (e) {
        var r = card.getBoundingClientRect();
        card.style.setProperty('--mx', ((e.clientX - r.left) / r.width * 100).toFixed(1) + '%');
        card.style.setProperty('--my', ((e.clientY - r.top) / r.height * 100).toFixed(1) + '%');
      });
    });
  }

  /* 全局轻提示：window.ruToast('已复制') */
  function initRuToast() {
    window.ruToast = function (msg) {
      var t = document.querySelector('.ru-toast');
      if (!t) { t = document.createElement('div'); t.className = 'ru-toast'; document.body.appendChild(t); }
      t.textContent = msg;
      t.classList.add('show');
      clearTimeout(t.__timer);
      t.__timer = setTimeout(function () { t.classList.remove('show'); }, 2000);
    };
  }

  initRuToast();

  /* 全局提示：优先用 RareUI Toast，浏览器过老/异常时退回原生 alert。
     各页面脚本里的 alert('xxx') 统一改成 window.lwAlert('xxx')，避免每个页面都弹系统框。 */
  window.lwAlert = function (msg) {
    try {
      if (window.ruToast) { window.ruToast(String(msg == null ? '' : msg)); return; }
    } catch (e) {}
    try { alert(msg); } catch (e) {}
  };
  initCardSpotlight();
  document.addEventListener('DOMContentLoaded', initCardSpotlight);



  /* ==================================================================
     全局命令面板（Ctrl/⌘ + K）
     ------------------------------------------------------------------
     视频里提到「后台/工具菜单太多找不到」。这里做一个轻量命令面板：
       · Ctrl/⌘+K 或 点右上角 ⌘K 打开
       · 输入即过滤（页面 + 工具 + 常用操作）
       · ↑↓ 选择，Enter 打开，Esc 关闭
     工具条目来自工具页 DOM；其它页面只列页面与常用操作，避免空面板。
     ================================================================== */
  function initCommandPalette() {
    var items = [
      { t: '✍️ 发帖 / 写动态', u: '/pages/post.php' },
      { t: '🎁 站内抽奖', u: '/pages/lottery.php' },
      { t: '🧰 实用工具', u: '/pages/tools.php' },
      { t: '📚 学习专区', u: '/pages/learning.php' },
      { t: '🔍 搜索帖子', u: '/pages/search.php' },
      { t: '🏆 排行榜', u: '/pages/ranking.php' },
      { t: '👤 个人中心', u: '/pages/user_center.php' },
      { t: '🛠 管理后台', u: '/admin/dashboard.php' },
      { t: '🏠 返回首页', u: '/' }
    ];
    document.querySelectorAll('.tool-card').forEach(function (c) {
      var nameEl = c.querySelector('.tool-name');
      var onclick = c.getAttribute('onclick') || '';
      var m = /openTool\('([a-z0-9_]+)'\)/.exec(onclick);
      if (nameEl && m) { items.push({ t: '工具 · ' + nameEl.textContent.trim(), tool: m[1] }); }
      else if (nameEl && c.getAttribute('href')) { items.push({ t: '工具 · ' + nameEl.textContent.trim(), u: c.getAttribute('href') }); }
    });

    var mask = document.createElement('div');
    mask.className = 'ru-cmd-mask';
    mask.innerHTML = '<div class="ru-cmd"><div class="ru-cmd-input"><svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="7"/><path d="M21 21l-4.3-4.3"/></svg><input id="ruCmdInput" placeholder="搜索页面、工具，或直接搜帖子内容…（↑↓ 选择，Enter 打开）" autocomplete="off"><span class="ru-cmd-esc">Esc</span></div><div class="ru-cmd-list" id="ruCmdList"></div></div>';
    document.body.appendChild(mask);
    var input = mask.querySelector('#ruCmdInput');
    var list = mask.querySelector('#ruCmdList');
    var sel = 0, filtered = [], postItems = [], fetchTimer = null, lastQuery = '';

    function render() {
      var q = (input.value || '').trim().toLowerCase();
      var base = items.filter(function (it) { return !q || it.t.toLowerCase().indexOf(q) >= 0; });
      filtered = base.concat(postItems).slice(0, 14);
      if (sel >= filtered.length) sel = Math.max(0, filtered.length - 1);
      list.innerHTML = (base.length ? '' : '') + filtered.map(function (it, i) {
        return '<div class="ru-cmd-item' + (i === sel ? ' is-sel' : '') + '" data-i="' + i + '">' + it.t + '</div>';
      }).join('') || '<div class="ru-cmd-empty">没有匹配项</div>';
      Array.prototype.forEach.call(list.children, function (el) { if (el.dataset.i !== undefined) { el.onclick = function () { go(filtered[+el.dataset.i]); }; } });
    }
    function queryPosts() {
      var q = (input.value || '').trim();
      if (q.length < 2 || q === lastQuery) { return; }
      lastQuery = q;
      if (window.IS_GUEST) { postItems = []; render(); return; }
      fetch(SITE_URL + '/api/search/quick.php?q=' + encodeURIComponent(q), { credentials: 'same-origin' })
        .then(function (r) { return r.json(); })
        .then(function (j) {
          var posts = (j && j.data && j.data.posts) || (j && j.posts) || [];
          postItems = posts.slice(0, 6).map(function (p2) {
            return { t: '📄 ' + (p2.title || '（无标题）'), u: '/pages/post_detail.php?id=' + p2.id };
          });
          render();
        }).catch(function () { postItems = []; render(); });
    }
    function go(it) {
      if (!it) return;
      close();
      if (it.tool && window.openTool) { window.openTool(it.tool); }
      else if (it.u) { location.href = it.u; }
    }
    function open() { mask.classList.add('is-open'); input.value = ''; sel = 0; postItems = []; lastQuery = ''; render(); setTimeout(function () { input.focus(); }, 20); document.body.style.overflow = 'hidden'; }
    function close() { mask.classList.remove('is-open'); document.body.style.overflow = ''; }
    function isOpen() { return mask.classList.contains('is-open'); }

    document.addEventListener('keydown', function (e) {
      var k = (e.key || '').toLowerCase();
      if ((e.ctrlKey || e.metaKey) && k === 'k') { e.preventDefault(); isOpen() ? close() : open(); return; }
      if (!isOpen()) return;
      if (k === 'escape') { close(); }
      else if (k === 'arrowdown') { e.preventDefault(); sel = Math.min(sel + 1, filtered.length - 1); render(); }
      else if (k === 'arrowup') { e.preventDefault(); sel = Math.max(sel - 1, 0); render(); }
      else if (k === 'enter') { e.preventDefault(); go(filtered[sel]); }
    });
    input.addEventListener('input', function () {
      sel = 0;
      clearTimeout(fetchTimer);
      fetchTimer = setTimeout(queryPosts, 260);
      render();
    });
    mask.addEventListener('click', function (e) { if (e.target === mask) close(); });

    try {
      var bar = document.querySelector('.site-header .header-actions, .site-header, header');
      if (bar) {
        var btn = document.createElement('button');
        btn.className = 'ru-cmd-trigger';
        btn.type = 'button';
        btn.innerHTML = '<svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="7"/><path d="M21 21l-4.3-4.3"/></svg><span>⌘K</span>';
        btn.onclick = open;
        bar.appendChild(btn);
      }
    } catch (err) {}
  }



  initCommandPalette();



  /* 顶部加载进度条：跳转/提交时先给 30%，页面加载完成收尾到 100% 并淡出 */
  function initProgressBar() {
    if (document.getElementById('ruProgress')) return;
    var bar = document.createElement('div');
    bar.id = 'ruProgress';
    bar.className = 'ru-progress';
    document.body.appendChild(bar);
    var timer = null, val = 0;
    function start() {
      clearInterval(timer);
      bar.classList.remove('is-done');
      val = 8;
      bar.style.width = val + '%';
      timer = setInterval(function () {
        val = Math.min(90, val + Math.max(0.4, (90 - val) * 0.06));
        bar.style.width = val + '%';
      }, 180);
    }
    function done() {
      clearInterval(timer);
      bar.style.width = '100%';
      setTimeout(function () { bar.classList.add('is-done'); bar.style.width = '0'; }, 260);
    }
    document.addEventListener('click', function (e) {
      var a = e.target.closest && e.target.closest('a[href]');
      if (!a) return;
      var href = a.getAttribute('href') || '';
      if (!href || href[0] === '#' || a.target === '_blank' || /^(javascript|mailto|tel):/i.test(href)) return;
      if (a.hasAttribute('download')) return;
      start();
    }, true);
    document.addEventListener('submit', function (e) {
      var f = e.target;
      if (!f || f.target === '_blank') return;
      start();
    }, true);
    window.addEventListener('load', done);
    window.addEventListener('pageshow', done);
  }

  initProgressBar();


  /* 信息流骨架屏：首屏空白期先给三张占位卡，真实卡片/DOM 变化后自动移除 */
  function initFeedSkeleton() {
    var box = document.getElementById('postsContainer');
    if (!box || box.querySelector('.post-card, .post-item, .ssr-card')) return;
    if (box.__skel) return;
    box.__skel = true;
    var tpl = '<div class="ru-skeleton-card"><div class="sk-row"><span class="sk-avatar"></span><span class="sk-line sk-short"></span></div>' +
      '<div class="sk-line sk-mid"></div><div class="sk-line sk-block"></div></div>';
    var holder = document.createElement('div');
    holder.className = 'ru-skeleton-holder';
    holder.innerHTML = tpl + tpl + tpl;
    box.appendChild(holder);
    function clear() {
      if (holder.parentNode) holder.parentNode.removeChild(holder);
      box.__skel = false;
    }
    var mo = new MutationObserver(function () {
      if (box.querySelector('.post-card, .post-item, .ssr-card')) { mo.disconnect(); clear(); }
    });
    mo.observe(box, { childList: true, subtree: true });
    setTimeout(function () { mo.disconnect(); clear(); }, 8000);
  }

  if (document.readyState === 'loading') window.addEventListener('DOMContentLoaded', initFeedSkeleton);
  else initFeedSkeleton();

})();
