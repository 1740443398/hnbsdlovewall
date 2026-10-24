/*!
 * 前端请求缓存（输入侧命中层）
 * =====================================================================
 *
 * 解决两种真实浪费：
 *   ① **同一请求同时发两遍**：侧栏角标、通知、在线数在首屏会由好几个模块各拉一次，
 *      互相之间不知道对方在拉。这里做「并发去重」（in-flight 合并）：同一 URL 的
 *      第二个请求等第一个的结果，不发第二枪。
 *   ② **明明没变却反复回源**：翻回上一页、在两个标签页之间来回切、反复打开同一个
 *      好友卡片 —— 数据在 N 秒内几乎不可能变。这里做「短时缓存」（TTL）。
 *
 * 三条刻意的保守设计：
 *   - **只缓存 GET**。POST/PUT/DELETE 有副作用，合并它们等于吞掉用户的第二次操作。
 *   - **默认 TTL 为 0**：调用方必须显式传 TTL 才会缓存，避免「不知道哪个接口被缓存了」。
 *   - **失败不缓存**：fetch 抛错或 HTTP 非 2xx 一律不进缓存，下次照常回源。
 *
 * 与 Service Worker 的关系：SW 只管 /assets/ 静态资源，不碰 /api/，两者不冲突。
 */
(function (global) {
  'use strict';

  if (global.LWRequestCache) { return; }

  /** "METHOD url" -> { promise, expiresAt } */
  var store = Object.create(null);

  function now() {
    return Date.now();
  }

  function dropIfSame(k, p) {
    if (store[k] && store[k].promise === p) { delete store[k]; }
  }

  function cloneResponse(res) {
    if (res && typeof res.clone === 'function') {
      try { return res.clone(); } catch (e) { /* body 已被读过，退回原对象 */ }
    }
    return res;
  }

  /** 清空。登录/退出等身份切换时必须调用，否则会拿到上一个账号的数据。 */
  function clear(prefix) {
    if (!prefix) {
      store = Object.create(null);
      return;
    }
    Object.keys(store).forEach(function (k) {
      if (k.indexOf(prefix) !== -1) { delete store[k]; }
    });
  }

  /** 把某个 URL 的结果作废（例如刚点完赞，列表里的计数要重拉） */
  function invalidate(url) {
    clear(url);
  }

  /**
   * 带并发去重与短时缓存的 fetch。
   *
   * @param {string} url
   * @param {Object} [options]  fetch 选项（method 默认 GET）
   * @param {Object} [opts]
   * @param {number} [opts.ttl=0]      结果缓存毫秒数；0 = 只做并发去重，不缓存
   * @param {boolean} [opts.force]     跳过已有缓存，强制回源（下拉刷新用）
   * @param {Function} [opts.validate] 拿到 Response 后判定是否值得缓存，返回 false 则不存
   * @returns {Promise<Response>} 每次调用拿到的是 **clone**，调用方可以随意读 body
   */
  function request(url, options, opts) {
    options = options || {};
    opts = opts || {};
    var method = String(options.method || 'GET').toUpperCase();
    var ttl = Math.max(0, Number(opts.ttl) || 0);

    // 有副作用的请求：原样发出，永不合并、永不缓存
    if (method !== 'GET') {
      return global.fetch(url, options);
    }

    var k = method + ' ' + url;
    var hit = store[k];

    if (hit && !opts.force) {
      if (hit.expiresAt && hit.expiresAt > now()) {
        return hit.promise.then(cloneResponse);
      }
      // 过期：删掉后走下面的回源
      delete store[k];
    }

    var p = global.fetch(url, options).then(function (res) {
      var keep = res && res.ok && ttl > 0 && (!opts.validate || opts.validate(res));
      if (keep) {
        store[k] = { promise: p, expiresAt: now() + ttl };
      } else {
        // 不值得缓存时要把「进行中的请求」登记清掉，
        // 否则下一次调用会拿到这次的（可能失败的）结果
        dropIfSame(k, p);
      }
      return res;
    }, function (err) {
      dropIfSame(k, p);
      throw err;
    });

    // 先登记「进行中」，后一个同 URL 请求直接等它 —— 这就是并发去重
    store[k] = { promise: p, expiresAt: ttl > 0 ? now() + ttl : 0 };
    return p.then(cloneResponse);
  }

  global.LWRequestCache = {
    request: request,
    clear: clear,
    invalidate: invalidate
  };
})(window);
