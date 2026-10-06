<?php
/**
 * 主题引导 —— 全站主题的**唯一真源**。
 *
 * 为什么必须是一段「同步阻塞」脚本：
 *   浏览器解析 <head> 时若还没拿到主题类，就会先用默认浅色画一帧，等页面底部的
 *   main.js 跑完再变深 —— 深色用户看到的是「先白后黑」的闪屏（FOUC）。
 *   把这段脚本放在所有 <link rel="stylesheet"> 之前同步执行，首帧就已经是正确的深/浅色。
 *
 * 三条约定：
 *  1) 主题类挂在 <html>（documentElement）上，不再挂 body —— 挂 body 时首帧还没解析到
 *     body，等于没赶上首帧。CSS 侧一律用 `.dark-theme .xxx` 后代选择器（html 是祖先，天然覆盖）。
 *  2) 只认三个偏好值：system / light / dark。system 表示「跟随操作系统」，不写死具体深浅。
 *  3) 偏好存储优先级：localStorage.theme（用户在本机的选择）→ window.LW_SERVER_THEME
 *     （登录用户存在服务端的偏好，用于换设备后保持一致）→ system。
 *
 * 对外 API（window.LWTheme）：pref / effective / isDark / apply / set / next / subscribe / withTransition
 *
 * 附加职责：本文件同时是全站**最早执行的同步脚本**，因此在这里顺带安装一个「全局 fetch
 * 拦截器」，负责 CSRF 令牌过期后的自愈（403 响应体带 new_csrf_token 时静默换签重试一次）。
 * 为什么放这里：它被 pwa_head.php 与各独立页在 <head> 同步 require，早于任何 fetch 发生，
 * 一处安装即可覆盖全站所有裸 fetch 调用，无需逐页改造。与后台 admin/layout.php 的同名拦截器一致。
 *
 * 还有一条附加职责：把「允许访问的域名白名单」下发给前端反劫持守卫
 * （assets/js/anti_hijack.js）。以前那份白名单在前端脚本里**抄了一份**，
 * 两边一改就分叉；现在以 config/constants.php 的 EXPECTED_HOSTS 为唯一真源。
 */

// 反劫持白名单 + 规范域名（前端守卫的输入）
$lwAllowedHosts = (defined('EXPECTED_HOSTS') && is_array(EXPECTED_HOSTS)) ? array_values(EXPECTED_HOSTS) : [];
$lwCanonicalHost = '';
foreach ($lwAllowedHosts as $lwH) {
    if (!in_array($lwH, ['127.0.0.1', 'localhost', '::1'], true)) { $lwCanonicalHost = $lwH; break; }
}
?>
<script>
/* 反劫持守卫的输入（唯一真源：config/constants.php 的 EXPECTED_HOSTS）。
   放在主题脚本之前、任何 <script src> 之前同步执行，保证 anti_hijack.js 跑的时候一定拿得到。 */
window.LW_ALLOWED_HOSTS  = <?= json_encode($lwAllowedHosts, JSON_UNESCAPED_SLASHES) ?>;
window.LW_CANONICAL_HOST = <?= json_encode($lwCanonicalHost, JSON_UNESCAPED_SLASHES) ?>;

/* ── CSRF 令牌：全站**唯一真源** ──────────────────────────────────────────
   以前是每个页面各自内联一份 `const CSRF_TOKEN`，谁漏了谁就整页 POST 全废：
   pages/topic.php 与 pages/ranking.php 都没有内联，导致该页的签到 / 点赞 / 收藏 /
   关注等一切 POST 收到 403（实测 topic.php 的 window.CSRF_TOKEN 为空串）。
   更糟的是「空令牌」这条分支不换发 new_csrf_token，前端自愈拦截器也就救不回来，
   表现为该页功能永久失效。
   这里统一下发一次：theme_boot.php 被 pwa_head.php 与各独立页在 <head> 同步 require，
   覆盖全站每一个 HTML 页面。页面自己的 `const CSRF_TOKEN` 值与它**同一个来源**
   （都是 generateCSRFToken()，同一请求里返回同一个值），因此两边不会打架。
   注意 `function_exists` 守卫：pages/maintenance.php 刻意设计成「不依赖 config 也能单独引用」
   （它自己用 function_exists 兜所有依赖），这里必须保持同样的健壮性，否则维护页会白屏。 */
window.CSRF_TOKEN = <?= json_encode(function_exists('generateCSRFToken') ? generateCSRFToken() : '') ?>;
</script>
<script>
(function () {
    'use strict';

    var KEY = 'theme';
    var PREFS = ['system', 'light', 'dark'];
    var mq = window.matchMedia ? window.matchMedia('(prefers-color-scheme: dark)') : null;
    var listeners = [];

    function readPref() {
        var v = null;
        try { v = localStorage.getItem(KEY); } catch (e) { /* 隐私模式禁用存储：退回服务端/系统 */ }
        if (PREFS.indexOf(v) < 0) { v = null; }
        if (!v && PREFS.indexOf(window.LW_SERVER_THEME) >= 0) { v = window.LW_SERVER_THEME; }
        return v || 'system';
    }

    function effectiveOf(pref) {
        if (pref === 'system') { return (mq && mq.matches) ? 'dark' : 'light'; }
        return pref;
    }

    // 地址栏/状态栏颜色跟随主题：深色用近黑的蓝底，浅色用学院蓝
    function syncColorMeta(eff) {
        var metas = document.querySelectorAll('meta[name="theme-color"]');
        if (!metas.length) { return; }
        var color = (eff === 'dark') ? '#0F1520' : '#2F5B9A';
        for (var i = 0; i < metas.length; i++) { metas[i].setAttribute('content', color); }
    }

    function paint(pref) {
        var eff = effectiveOf(pref);
        var el = document.documentElement;
        el.classList.toggle('dark-theme', eff === 'dark');
        el.classList.toggle('light-theme', eff !== 'dark');
        el.setAttribute('data-theme-pref', pref);
        el.setAttribute('data-theme', eff);
        syncColorMeta(eff);
        for (var i = 0; i < listeners.length; i++) {
            try { listeners[i](pref, eff); } catch (e) { /* 订阅者异常不影响主题本身 */ }
        }
    }

    function persist(pref) {
        try { localStorage.setItem(KEY, pref); } catch (e) {}
    }

    var LWTheme = {
        prefs: PREFS,
        pref: readPref,
        effective: function () { return effectiveOf(readPref()); },
        isDark: function () { return effectiveOf(readPref()) === 'dark'; },
        apply: function (pref, doPersist) {
            if (PREFS.indexOf(pref) < 0) { pref = 'system'; }
            if (doPersist) { persist(pref); }
            paint(pref);
        },
        set: function (pref) { LWTheme.apply(pref, true); },
        next: function () {
            var idx = PREFS.indexOf(readPref());
            var nxt = PREFS[(idx + 1) % PREFS.length];
            LWTheme.set(nxt);
            return nxt;
        },
        subscribe: function (fn) {
            if (typeof fn !== 'function') { return function () {}; }
            listeners.push(fn);
            return function () {
                var i = listeners.indexOf(fn);
                if (i >= 0) { listeners.splice(i, 1); }
            };
        },
        // 切换瞬间抑制全局过渡动画，避免配色一起「渐隐渐变」造成的拖影
        withTransition: function () {
            var el = document.documentElement;
            el.classList.add('theme-transitioning');
            window.clearTimeout(LWTheme._tt);
            LWTheme._tt = window.setTimeout(function () {
                el.classList.remove('theme-transitioning');
            }, 350);
        }
    };
    window.LWTheme = LWTheme;

    // 首帧上色：不写 localStorage（system 跟随不该把用户偏好锁死成具体深浅）
    paint(readPref());

    // 系统主题实时跟随：仅当用户选的是 system 时才响应
    if (mq) {
        var onSysChange = function () { if (readPref() === 'system') { paint('system'); } };
        if (mq.addEventListener) { mq.addEventListener('change', onSysChange); }
        else if (mq.addListener) { mq.addListener(onSysChange); }
    }

    // 跨标签页同步：一个标签页改了偏好，其它标签页跟着变
    window.addEventListener('storage', function (e) {
        if (e.key === KEY) { paint(readPref()); }
    });

    // 文档就绪后整体重绘一次：既补上脚本执行时尚未解析到的 <meta name="theme-color">，
    // 也顺带纠正任何在解析期被外部脚本（浏览器扩展 / 页面编辑器等）改写的 theme 属性。
    document.addEventListener('DOMContentLoaded', function () {
        paint(readPref());
    });
})();
</script>
<script>
/* ── CSRF 自愈：全局 fetch 拦截器 ──────────────────────────────────────────
   背景：会话令牌会过期（LW_CSRF_TTL），或被主机回收 / Cookie 未持久化，
   此时页面里携带的旧令牌会让任意 POST 收到 403，用户看到「CSRF验证失败」。
   服务端在 403 响应体里回传 new_csrf_token；这里捕获它、静默换签并重试一次，
   用户全程无感。只重试一次（__lwCsrfRetried 标记），异常时不会死循环。
   实际用例：登录 / 注册 / 发帖 / 头像上传等所有 POST 接口，无需逐页改造。 */
(function () {
    if (window.__lwCsrfFetchPatched || typeof window.fetch !== 'function') { return; }
    window.__lwCsrfFetchPatched = true;
    var _fetch = window.fetch.bind(window);
    function swapBody(body, tok) {
        try {
            if (body instanceof FormData || body instanceof URLSearchParams) { body.set('csrf_token', tok); return body; }
            if (typeof body === 'string' && body.indexOf('csrf_token=') !== -1) {
                return body.replace(/(^|&)csrf_token=[^&]*/, '$1csrf_token=' + encodeURIComponent(tok));
            }
        } catch (e) { /* 跨域/受限 body：原样返回，走服务端既有逻辑 */ }
        return body;
    }
    window.fetch = function (input, init) {
        init = init || {};
        return _fetch(input, init).then(function (res) {
            if (res.status !== 403 || init.__lwCsrfRetried || typeof input !== 'string') { return res; }
            return res.clone().json().then(function (data) {
                if (!data || !data.new_csrf_token) { return res; }
                try { window.CSRF_TOKEN = data.new_csrf_token; } catch (e) {}
                try { window.__lwCsrfToken = data.new_csrf_token; } catch (e) {}
                var ni = Object.assign({}, init);
                ni.__lwCsrfRetried = true;
                ni.body = swapBody(init.body, data.new_csrf_token);
                return _fetch(input, ni);
            }).catch(function () { return res; });
        });
    };
})();
</script>