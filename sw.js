/*!
 * 校园交流墙 · Service Worker
 *
 * 为什么需要它：
 *   1) Chrome 判断站点「可安装」的条件之一，是存在一个带 fetch 事件的 Service Worker
 *      （纯空的 handler 会被 Chrome 当作 no-op 跳过，不算数）。没有它，
 *      beforeinstallprompt 永远不会触发 —— 这就是「电脑上看不到安装应用」的根因。
 *   2) 顺带提供离线兜底：断网/弱网时不再白屏。
 *
 * 缓存策略（保守优先，绝不能出现「看到旧页面」）：
 *   - 页面导航：**完全不拦截**（必须）。SW 一旦对导航调 respondWith，
 *     Chrome 就会跳过跨文档 View Transition，全站页面切换动画失效；
 *     实测绕过 SW 后 hasViewTransition 立刻变 true。离线页兜底因此取消，
 *     断网时交给浏览器默认处理 —— 这是为「页面转场」做的取舍。
 *   - /assets/ 下的静态资源（css/js/图片/字体）：**网络优先，失败才回退缓存**；
 *   - 其余一切同源 GET（/api/、manifest.webmanifest、东西向的杂项请求）：
 *     完全不拦截，交回浏览器自己处理。
 *   - 外部域名（天气、一言等）：不拦截。
 *
 * ⚠️ 两条必须守住的铁律（都踩过坑）：
 *   1) 绝对不要用 new Response('', { status: 504, statusText: 'Offline' }) 这种
 *      «伪响应» 收尾。SW 一旦把失败请求变成「空 body + 504」，浏览器会当成真实
 *      服务器响应：manifest.webmanifest 被这么处理过一次，就变成
 *      «Manifest: Line: 1, column: 1, Syntax error»，manifest 直接报废、站点不再
 *      可安装，表现为「安装应用突然不管用了」。要表示失败只能用 Response.error()。
 *   2) install 里的预缓存必须逐个 catch。cache.addAll() 只要有一个文件拉不到就整体
 *      reject，SW 装不上；没有可用的 SW，Chrome 一样判定不可安装。
 */

// v3：v2 仍沿用「缓存优先」，而主机商的防护页会以 403 + text/html 返回某个真实存在的
// 资源地址（不是资源本身的内容）。缓存优先一旦把这种响应存下来，就会长期拿「HTML 当
// JS/CSS」喂给页面：表现为脚本语法错误、AI 助手与图标全部消失、页面彻底不可用，
// 而且**无法自愈**——缓存在网络之前先命中，服务器恢复了也永远轮不到它。
// 本版改成网络优先 + 只缓存正常响应，并允许拿缓存里的干净副本兜底。
// 另外：缓存名升到 v3 会让 activate 阶段把旧的（可能已被污染的）缓存整体删掉，
// 受影响用户打开一次页面即自动恢复，不需要手动清缓存。
var CACHE = 'lovewall-static-v3';
/* offline.html 保留在磁盘上（如果将来改回拦截导航可以直接用），
   但当前导航不拦截，因此断网时浏览器不会显示它。 */
var OFFLINE_URL = '/offline.html';
var PRECACHE = [OFFLINE_URL, '/assets/images/icons/icon-192.png'];
// 缓存条目上限，超出按最旧的删（避免 ?v= 版本更迭导致缓存无限膨胀）
var MAX_ENTRIES = 80;

self.addEventListener('install', function (event) {
  event.waitUntil(
    caches.open(CACHE).then(function (cache) {
      // 逐个 add 并各自吞掉失败：预缓存只是锦上添花，
      // 任何一个文件拉不到都不该让整个 SW 装不上。
      return Promise.all(PRECACHE.map(function (url) {
        return cache.add(url).catch(function () {});
      }));
    }).then(function () {
      return self.skipWaiting();
    })
  );
});

self.addEventListener('activate', function (event) {
  event.waitUntil(
    caches.keys().then(function (keys) {
      return Promise.all(keys.map(function (k) {
        return k === CACHE ? null : caches.delete(k);
      }));
    }).then(function () {
      return self.clients.claim();
    })
  );
});

function isCacheableAsset(pathname) {
  return /^\/assets\//.test(pathname) || pathname === '/icon.ico';
}

/**
 * 响应内容是否值得进缓存。
 *
 * /assets/ 下不会有 HTML 资源；一旦响应是 text/html，说明拿到的是主机商反爬挑战页
 * 或站点错误页，而非真实资源。放它进缓存就会「HTML 当 JS/CSS」，页面直接报废。
 */
function isFreshAssetBody(res) {
  var ct = '';
  try { ct = (res.headers.get('content-type') || '').toLowerCase(); } catch (e) { ct = ''; }
  return ct.indexOf('text/html') === -1;
}

function trimCache() {
  return caches.open(CACHE).then(function (cache) {
    return cache.keys().then(function (keys) {
      if (keys.length <= MAX_ENTRIES) { return; }
      var extra = keys.length - MAX_ENTRIES;
      var tasks = [];
      for (var i = 0; i < extra; i++) { tasks.push(cache.delete(keys[i])); }
      return Promise.all(tasks);
    });
  });
}

self.addEventListener('fetch', function (event) {
  var req = event.request;

  // 只处理同源 GET
  if (req.method !== 'GET') { return; }
  var url;
  try { url = new URL(req.url); } catch (e) { return; }
  if (url.origin !== self.location.origin) { return; }

  /* ① 页面导航：**不拦截**（直接放行给浏览器）
   *
   * 【为什么改成放行】
   * 实测：当 SW 对导航请求调用 respondWith() 时，Chrome 会**跳过跨文档
   * View Transition**（@view-transition { navigation: auto } 不生效）。
   * 用 CDP 绕过 SW 后同一路径立刻 hasViewTransition = true，
   * 说明「页面切换动画」和「SW 导航拦截」二选一。
   *
   * 本站是动态 PHP 站：
   *   · 页面切换动画正是站长要的「ColorOS 式丝滑」，观感收益最大
   *   · 离线兜底只影响断网场景，而校园墙用户基本都在线
   *   · 而且 HTML 进 SW 缓存本身就有脏数据风险（挑战页/登录态串号）
   * 所以选择放行导航，换取全站页面转场。
   *
   * 注意：不调 respondWith 只是「不介入」，浏览器照常走网络；
   * 断网时浏览器显示自己的离线页（各平台默认行为）。
   */
  if (req.mode === 'navigate') {
    return;
  }

  // ② /assets/ 静态资源：网络优先 + 只用缓存兜底
  //
  // 为什么不能是「缓存优先」：本站所在主机带反爬挑战，未通过挑战时它会对**任何**资源地址
  // 回一张 200 + text/html 的挑战页（里面是 /aes.js）。缓存优先一旦把这张挑战页存成
  // ai_chat_core.js / ai_widget.css，就会永远拿「HTML 当 JS/CSS」喂给页面 ——
  // 脚本语法错误、助手与图标全消失，而且服务器恢复了也轮不到它（缓存先命中），
  // 用户只能手动清站点数据，表现就是「一直坏、修不好」。
  //
  // 现在的次序：先走网络；拿到的是真资源才用它并顺手更新缓存；拿到的不是真资源
  // （挑战页/错误页）就退回去用缓存里的干净副本，缓存也没有才如实返回网络响应。
  // 这样服务器一正常、或用户过了挑战，下一次请求立刻自愈，不需要任何手动操作。
  if (isCacheableAsset(url.pathname)) {
    event.respondWith(
      caches.open(CACHE).then(function (cache) {
        return fetch(req).then(function (res) {
          if (res && res.ok && res.type === 'basic' && isFreshAssetBody(res)) {
            cache.put(req, res.clone()).then(trimCache);
            return res;
          }
          // 网络给的不是真资源：优先拿缓存里的干净副本顶上
          return cache.match(req).then(function (cached) {
            if (cached && isFreshAssetBody(cached)) { return cached; }
            // 缓存里也没有干净副本（例如缓存里存的正是挑战页），
            // 那就清掉这条坏记录并如实返回，下一轮请求就有机会自愈。
            return cache.delete(req).then(function () { return res; });
          });
        }).catch(function () {
          // 断网/超时：用缓存兜底；没有缓存就如实报网络错误，不伪造响应体
          return cache.match(req).then(function (cached) {
            return (cached && isFreshAssetBody(cached)) ? cached : Response.error();
          });
        });
      })
    );
    return;
  }

  // ③ 其余同源 GET 一律放行（含 /api/、manifest.webmanifest、上传的图片等）。
  //    不调 respondWith 就是完全不介入，请求由浏览器按正常流程发出。
});
