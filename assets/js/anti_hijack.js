/* 反DNS劫持/防仿冒守卫
 * 说明：真正的 DNS 劫持（域名被解析到攻击者 IP 并返回恶意页）时，本脚本不会被执行，
 * 需要在 DNS 服务商处启用 DNSSEC + HTTPS 才能根治。
 * 本脚本用于防御「站被搬到非法域名/仿冒站/镜像站」的情况：校验地址栏域名，
 * 不在白名单内即阻止渲染并提示用户访问真实域名，避免在仿冒站输入账号。
 */
(function () {
  var allowed = ["hnbsd.ct.ws", "www.hnbsd.ct.ws", "127.0.0.1", "localhost"];
  // 去掉端口号取纯 hostname
  var host = (window.location.hostname || "").toLowerCase();

  // 空 host 或本地地址一律放行（开发/内网场景）
  var isLocal = host === "" || host === "127.0.0.1" || host === "localhost" || host === "::1";
  if (isLocal) return;
  // 匹配白名单（含裸 host 以及带子域的前缀）
  var ok = allowed.some(function (d) {
    return host === d || host.indexOf("." + d) === host.length - ("." + d).length;
  });
  if (ok) return;

  // 非白名单域名：隐藏正文并提示，防止在仿冒站泄露账号密码
  try {
    document.documentElement.style.display = "none";
  } catch (e) {}
  if (window.location.replace) {
    window.location.replace("https://hnbsd.ct.ws/");
  }
})();