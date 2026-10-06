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

  // 非白名单域名：隐藏正文 + 引导回规范域名，防止在仿冒站泄露账号密码
  try { document.documentElement.style.display = "none"; } catch (e) {}
  var canon = window.LW_CANONICAL_HOST || "";
  if (window.location.replace) {
    window.location.replace(canon ? ("https://" + canon + "/") : "https://hnbsd.ct.ws/");
  }
})();
