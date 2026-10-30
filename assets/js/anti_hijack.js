/* 反DNS劫持 / 防仿冒守卫
 * ---------------------------------------------------------------------------
 * 真正的 DNS 劫持（域名被解析到攻击者 IP 并返回恶意页）时本脚本根本不会被执行，
 * 那种情况要在 DNS 服务商处开 DNSSEC + 全站 HTTPS 才能根治。
 * 本脚本防的是「整站被搬到非法域名 / 仿冒站 / 镜像站」：校验地址栏域名，
 * 不在白名单内就阻止渲染并引导回真实域名，避免用户在仿冒站输入账号密码。
 *
 * 白名单来源（唯一真源）：
 *   window.LW_ALLOWED_HOSTS / window.LW_CANONICAL_HOST —— 由 includes/theme_boot.php
 *   从 config/constants.php 的 EXPECTED_HOSTS 注入。以前这里硬编码了一份，改域名要改两处、
 *   迟早分叉；现在只改服务端一处，前端自动跟上。
 *
 * 额外放行的「本地 / 内网」情形（比赛演示、离线部署、局域网访问全靠它）：
 *   127.0.0.1 / localhost / ::1、IPv6 字面量、*.local、RFC1918 私有网段
 *   （10/8、172.16/12、192.168/16）、链路本地 169.254/16，以及不带点的纯主机名。
 *   没有这一段的话，用「本机内网 IP」打开演示站会被误判成仿冒站 —— 页面直接隐藏并跳走。
 */
(function () {
  function isLocalOrPrivate(h) {
    if (!h) return true;                                   // 空 host
    if (h === '127.0.0.1' || h === 'localhost' || h === '::1') return true;
    if (h.charAt(0) === '[') return true;                  // IPv6 字面量 [::1]
    if (/\.local$/.test(h)) return true;                   // mDNS
    if (/^10\./.test(h)) return true;                      // 10.0.0.0/8
    if (/^192\.168\./.test(h)) return true;                // 192.168.0.0/16
    if (/^172\.(1[6-9]|2\d|3[01])\./.test(h)) return true; // 172.16.0.0/12
    if (/^169\.254\./.test(h)) return true;                // 169.254.0.0/16
    if (h.indexOf('.') === -1) return true;                // 无点的内网主机名
    return false;
  }

  var allowed = (window.LW_ALLOWED_HOSTS && window.LW_ALLOWED_HOSTS.length)
    ? window.LW_ALLOWED_HOSTS
    : ["hnbsd.ct.ws", "www.hnbsd.ct.ws", "127.0.0.1", "localhost"];
  var host = (window.location.hostname || "").toLowerCase();

  // 暴露出来便于自测与线上排错（纯函数，无副作用）
  window.LWIsLocalHost = isLocalOrPrivate;

  if (isLocalOrPrivate(host)) return;

  // 命中白名单（裸 host 或带子域）即放行
  var ok = allowed.some(function (d) {
    d = String(d || '').toLowerCase();
    return d !== '' && (host === d || host.slice(-(d.length + 1)) === "." + d);
  });
  if (ok) return;

  /* 非白名单域名：不再「隐藏正文 + 立刻跳走」。
   *
   * 【原来的做法有两个实际问题】
   *
   *   1. 强制跳到 https:// 规范域名
   *      如果你在局域网用 http://192.168.x.x 访问（路由器 NAT 回环、
   *      或者服务器只开了 80 端口），会被无条件跳到 https，
   *      而那边根本没有证书 —— 用户看到的是「无法访问此网站」。
   *      表现就是「网站自动 https 重定向，然后打不开」。
   *
   *   2. display:none 隐藏整个页面
   *      跳转失败时（网络慢、被扩展拦截）用户看到的是一片空白，
   *      连「发生了什么」都不知道，只能反复刷新。
   *
   * 【现在的做法】
   *   - 保留同协议：当前是 http 就跳 http，是 https 才跳 https
   *   - 不隐藏页面，改为顶部横幅提示（页面照常可用）
   *   - 只有用户主动点「前往」才跳转
   *
   * 安全上并没有退让：仿冒站拿到的仍然是「一个带警告横幅的页面」，
   * 而且所有表单提交都会打到真实域名（SITE_URL 由服务端白名单决定），
   * 账号密码不会落到攻击者手里。反而因为页面能正常显示，
   * 用户更可能看懂「你正在访问一个非官方地址」这句提示。
   */
  var canon = (window.LW_CANONICAL_HOST || "").toLowerCase();
  if (!canon) return;

  var proto = (window.location.protocol === "https:") ? "https:" : "http:";
  var target = proto + "//" + canon + "/";

  function showBanner() {
    try {
      var bar = document.createElement("div");
      bar.setAttribute("role", "alert");
      bar.style.cssText = [
        "position:fixed", "left:0", "right:0", "top:0", "z-index:2147483647",
        "padding:10px 14px", "font:14px/1.5 -apple-system,BlinkMacSystemFont,'PingFang SC',sans-serif",
        "background:#FFF4E5", "color:#8A5300", "border-bottom:1px solid #F0D9B5",
        "display:flex", "align-items:center", "gap:10px", "flex-wrap:wrap"
      ].join(";");

      var txt = document.createElement("span");
      txt.style.flex = "1";
      txt.textContent = "你正在访问非官方地址 " + host + "，为保护账号安全请使用官方站点。";

      var go = document.createElement("a");
      go.href = target;
      go.textContent = "前往官方站点";
      go.style.cssText = "color:#8A5300;font-weight:600;text-decoration:underline;white-space:nowrap";

      bar.appendChild(txt);
      bar.appendChild(go);

      // body 还没解析出来时挂到 documentElement，保证一定能显示
      (document.body || document.documentElement).appendChild(bar);
    } catch (e) {}
  }

  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", showBanner, { once: true });
  } else {
    showBanner();
  }
})();
