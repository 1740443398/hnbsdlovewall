<?php
/**
 * 全屏时钟 —— 带 NTP 校准与每日一言
 * ================================================================
 *
 * 需求来源：参考 mapchaxun.cn/beijingshijian 的大屏时钟，
 * 但做了三点改进：
 *   1) 时间用国家授时中心 ntp.ntsc.ac.cn 校准（可选微软/谷歌/苹果/阿里）
 *   2) 红框里显示一言 API 的随机句子，每次打开都换
 *   3) 全屏 + 毫秒模式 + 隐藏日期都可切换
 *
 * 【为什么不用全站样式】
 * 这个页面是「一块黑屏上的数字」，需要绝对控制配色与排版；
 * 引全站 CSS 会让继承下来的字体/间距干扰大号数字的对齐。
 * 所以这里只引 theme_boot（保证深浅色不闪）和极少量自定义样式，自包含。
 * ================================================================ */

require_once __DIR__ . '/../config/config.php';

// 游客也能用（不需要登录）
$user = getCurrentUser();
$pageTitle = '时间校准 · ' . SITE_NAME;
$LANG_CODE = $LANG_CODE ?? 'zh_CN';
?>
<!DOCTYPE html>
<html lang="<?= $LANG_CODE ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
<meta name="theme-color" content="#0A0B0E">
<title><?= htmlspecialchars($pageTitle) ?></title>
<meta name="description" content="大屏时钟 · 国家授时中心 NTP 校准 · 每日一言">
<?php require_once __DIR__ . '/../includes/theme_boot.php'; ?>
<link rel="icon" href="/icon.ico" type="image/x-icon">
<style>
/* ==========================================================================
 * 配色：接近纯黑，让数字自己发光
 * ==========================================================================
 * 不用 #000 —— 纯黑在 OLED 上会让边框和文字边缘产生「割裂感」，
 * #0A0B0E 稍微给一点底色，观感更柔和。
 */
:root {
    --c-bg: #0A0B0E;
    --c-date: #B9BEC7;
    --c-time: #FFFFFF;
    --c-zone: #6B7280;
    --c-ring: #E4501C;          /* 红框 */
    --c-btn-bg: #1C1E23;
    --c-btn-bg-hover: #262930;
    --c-btn-bg-active: #303440;
    --c-btn-fg: #E5E7EB;
    --c-btn-border: #2E3239;
    --c-quote: #D7DBE2;

    /* ColorOS 17 那套手感：快出慢收、末端微回弹 */
    --ease-out-soft: cubic-bezier(0.16, 1, 0.3, 1);
    --ease-spring: cubic-bezier(0.34, 1.56, 0.64, 1);
}

*, *::before, *::after { box-sizing: border-box; }

html, body {
    height: 100%;
    margin: 0;
    background: var(--c-bg);
    color: var(--c-time);
    overflow: hidden;   /* 时钟页不滚动 */
    -webkit-font-smoothing: antialiased;
    -moz-osx-font-smoothing: grayscale;
}

body {
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    /* 安全区：竖屏手机刘海/手势条 */
    padding: env(safe-area-inset-top, 0px) 20px calc(env(safe-area-inset-bottom, 0px) + 18px);
    font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", "PingFang SC",
                 "Hiragino Sans GB", "Microsoft YaHei", system-ui, sans-serif;
    user-select: none;
    -webkit-user-select: none;
    -webkit-tap-highlight-color: transparent;
}

/* ---- 中间主体 ---- */
.clk-main {
    display: flex;
    flex-direction: column;
    align-items: center;
    /* 数字切换时不抖：用 grid 固定宽度，比 min-width 稳 */
    width: 100%;
    max-width: 1200px;
    flex: 1 1 auto;
    justify-content: center;
    gap: clamp(8px, 1.6vh, 20px);
}

/* ---- 日期 ---- */
.clk-date {
    font-size: clamp(17px, 2.1vw, 27px);
    color: var(--c-date);
    letter-spacing: 0.02em;
    font-weight: 400;
    /* 隐藏日期时用这个过渡（不做 display:none 硬切，会跳） */
    max-height: 60px;
    opacity: 1;
    transform: translateY(0);
    overflow: hidden;
    transition: max-height 420ms var(--ease-out-soft),
                opacity 280ms var(--ease-out-soft),
                transform 420ms var(--ease-out-soft),
                margin 420ms var(--ease-out-soft);
}
body.hide-date .clk-date {
    max-height: 0;
    opacity: 0;
    transform: translateY(-6px);
    margin-bottom: -8px;
}

/* ---- 大号时间 ---- */
.clk-time {
    font-size: clamp(74px, 17.5vw, 210px);
    font-weight: 700;
    line-height: 0.96;
    letter-spacing: -0.03em;
    color: var(--c-time);
    /* 等宽数字：不加这个，秒数跳动时整体宽度会抖 */
    font-variant-numeric: tabular-nums;
    font-feature-settings: "tnum" 1, "zero" 1;
    display: flex;
    align-items: baseline;
    justify-content: center;
    white-space: nowrap;
    /* 数字有轻微发光，像 OLED 上的光晕 */
    text-shadow: 0 0 42px rgba(255, 255, 255, 0.06);
}

/* 毫秒：默认隐藏，切到毫秒时才占位。
 * 用 width 过渡而不是 display，避免切换瞬间整行位置乱跳。 */
.clk-ms {
    font-size: 0.34em;
    font-weight: 500;
    color: var(--c-zone);
    letter-spacing: 0;
    margin-left: 0.06em;
    display: inline-block;
    width: 0;
    opacity: 0;
    overflow: hidden;
    transform: translateX(-6px);
    transition: width 340ms var(--ease-out-soft),
                opacity 260ms var(--ease-out-soft),
                transform 340ms var(--ease-out-soft);
}
body.show-ms .clk-ms {
    width: 2.6em;
    opacity: 1;
    transform: translateX(0);
}

/* ---- 时区 ---- */
.clk-zone {
    font-size: clamp(12px, 1.15vw, 15px);
    color: var(--c-zone);
    letter-spacing: 0.08em;
    transition: opacity 300ms var(--ease-out-soft);
}

/* ---- 红框：一言 ---- */
.clk-quote {
    position: relative;
    margin-top: clamp(14px, 2.4vh, 30px);
    width: min(390px, 78vw);
    min-height: clamp(96px, 15vh, 122px);
    border: 2px solid var(--c-ring);
    border-radius: 3px;   /* 图里是接近方角的细边 */
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 14px 20px;
    /* 极淡的内发光，让框「贴着」黑底 */
    box-shadow: 0 0 0 0 rgba(228, 80, 28, 0), inset 0 0 24px rgba(228, 80, 28, 0.05);
    transition: border-color 300ms var(--ease-out-soft),
                box-shadow 300ms var(--ease-out-soft);
}
/* 拿到一言后轻轻亮一下，替代「加载中…」这种 AI 腔提示 */
.clk-quote.is-loaded {
    box-shadow: 0 0 0 0 rgba(228, 80, 28, 0), inset 0 0 30px rgba(228, 80, 28, 0.09);
}

.clk-quote-text {
    font-size: clamp(14px, 1.35vw, 17px);
    line-height: 1.72;
    color: var(--c-quote);
    text-align: center;
    font-weight: 400;
    /* 换句子时淡入，不跳 */
    opacity: 0;
    transform: translateY(4px);
    transition: opacity 480ms var(--ease-out-soft), transform 480ms var(--ease-out-soft);
    max-height: 4.2em;
    overflow: hidden;
}
.clk-quote-text.is-in {
    opacity: 1;
    transform: translateY(0);
}
.clk-quote-from {
    position: absolute;
    right: 10px;
    bottom: 6px;
    font-size: 11px;
    color: rgba(228, 80, 28, 0.62);
    letter-spacing: 0.04em;
    opacity: 0;
    transition: opacity 400ms var(--ease-out-soft);
}
.clk-quote.is-loaded .clk-quote-from { opacity: 1; }

/* ---- 底部按钮 ---- */
.clk-actions {
    display: flex;
    flex-wrap: wrap;
    justify-content: center;
    gap: 10px;
    margin-top: clamp(14px, 2.2vh, 26px);
    padding-bottom: 2px;
}

.clk-btn {
    appearance: none;
    border: 1px solid var(--c-btn-border);
    background: var(--c-btn-bg);
    color: var(--c-btn-fg);
    font-size: clamp(12px, 1.1vw, 14px);
    font-family: inherit;
    padding: 0 20px;
    height: 34px;
    border-radius: 999px;   /* 图里是完全药丸形 */
    cursor: pointer;
    white-space: nowrap;
    /* ColorOS 那种「按下去立刻缩、松开回弹」 */
    transition: transform 140ms var(--ease-spring),
                background-color 180ms var(--ease-out-soft),
                border-color 180ms var(--ease-out-soft),
                color 180ms var(--ease-out-soft);
}
@media (hover: hover) {
    .clk-btn:hover { background: var(--c-btn-bg-hover); border-color: #3A3F47; }
}
.clk-btn:active {
    transform: scale(0.955);
    background: var(--c-btn-bg-active);
    transition-duration: 80ms;
}
/* 已激活的按钮：只换描边与字色，不填充，保持图里的克制感 */
.clk-btn.is-on {
    border-color: rgba(228, 80, 28, 0.72);
    color: #F0A082;
}

/* ---- 校时状态（左上角，低调） ---- */
.clk-ntp {
    position: fixed;
    top: calc(env(safe-area-inset-top, 0px) + 14px);
    left: 50%;
    transform: translateX(-50%);
    display: flex;
    align-items: center;
    gap: 7px;
    font-size: 11.5px;
    color: #5A6270;
    letter-spacing: 0.02em;
    cursor: pointer;
    padding: 6px 12px;
    border-radius: 999px;
    transition: background-color 180ms var(--ease-out-soft), color 180ms var(--ease-out-soft);
}
.clk-ntp:hover { background: rgba(255, 255, 255, 0.04); color: #8A93A3; }
.clk-ntp:active { transform: translateX(-50%) scale(0.97); }

.clk-dot {
    width: 6px;
    height: 6px;
    border-radius: 50%;
    background: #5A6270;
    flex: 0 0 auto;
    transition: background-color 300ms var(--ease-out-soft), box-shadow 300ms var(--ease-out-soft);
}
.clk-dot.ok { background: #35C46A; box-shadow: 0 0 8px rgba(53, 196, 106, 0.55); }
.clk-dot.warn { background: #D0A24C; box-shadow: 0 0 8px rgba(208, 162, 76, 0.5); }
.clk-dot.bad { background: #E06A64; box-shadow: 0 0 8px rgba(224, 106, 100, 0.5); }
.clk-dot.sync {
    background: #5B8CC9;
    animation: clkPulse 1s ease-in-out infinite;
}
@keyframes clkPulse {
    0%, 100% { opacity: 0.35; }
    50% { opacity: 1; }
}

/* ---- 校时服务器选择面板 ---- */
.clk-sheet-mask {
    position: fixed;
    inset: 0;
    background: rgba(0, 0, 0, 0.55);
    backdrop-filter: blur(3px);
    -webkit-backdrop-filter: blur(3px);
    opacity: 0;
    visibility: hidden;
    transition: opacity 240ms var(--ease-out-soft), visibility 240ms;
    z-index: 40;
}
.clk-sheet-mask.is-open { opacity: 1; visibility: visible; }

.clk-sheet {
    position: fixed;
    left: 50%;
    bottom: calc(env(safe-area-inset-bottom, 0px) + 22px);
    transform: translate(-50%, 18px) scale(0.97);
    width: min(340px, 88vw);
    background: #15171C;
    border: 1px solid #262A31;
    border-radius: 18px;
    padding: 8px;
    box-shadow: 0 24px 60px -18px rgba(0, 0, 0, 0.85);
    opacity: 0;
    visibility: hidden;
    transition: opacity 240ms var(--ease-out-soft),
                transform 320ms var(--ease-spring),
                visibility 240ms;
    z-index: 41;
}
.clk-sheet.is-open {
    opacity: 1;
    visibility: visible;
    transform: translate(-50%, 0) scale(1);
}

.clk-sheet-title {
    font-size: 11.5px;
    color: #6B7280;
    padding: 8px 14px 6px;
    letter-spacing: 0.04em;
}

.clk-srv {
    display: flex;
    align-items: center;
    gap: 10px;
    width: 100%;
    padding: 11px 14px;
    border: 0;
    border-radius: 12px;
    background: transparent;
    color: #D7DBE2;
    font-size: 14px;
    font-family: inherit;
    text-align: left;
    cursor: pointer;
    transition: background-color 150ms var(--ease-out-soft);
}
.clk-srv:hover { background: rgba(255, 255, 255, 0.05); }
.clk-srv:active { background: rgba(255, 255, 255, 0.09); }
.clk-srv.is-on { color: #F0A082; }
.clk-srv .tick {
    margin-left: auto;
    opacity: 0;
    color: var(--c-ring);
    transition: opacity 180ms var(--ease-out-soft);
}
.clk-srv.is-on .tick { opacity: 1; }
.clk-srv-host {
    font-size: 10.5px;
    color: #555C68;
    margin-top: 2px;
    font-variant-numeric: tabular-nums;
}

/* ---- 手机端 ---- */
@media (max-width: 640px) {
    body { padding-left: 16px; padding-right: 16px; }
    .clk-time { font-size: clamp(58px, 19vw, 96px); letter-spacing: -0.02em; }
    .clk-date { font-size: 15px; }
    .clk-quote { width: min(100%, 340px); min-height: 92px; padding: 12px 16px; }
    .clk-quote-text { font-size: 13.5px; line-height: 1.66; }
    .clk-actions { gap: 8px; margin-top: 16px; }
    .clk-btn { padding: 0 15px; height: 32px; font-size: 12.5px; }
}
/* 横屏手机：把整体压扁一点，保证按钮和红框都在一屏内 */
@media (max-height: 480px) and (orientation: landscape) {
    .clk-time { font-size: clamp(52px, 12vh, 96px); }
    .clk-quote { min-height: 64px; margin-top: 10px; }
    .clk-quote-text { font-size: 12.5px; max-height: 2.6em; }
    .clk-actions { margin-top: 10px; }
    .clk-btn { height: 29px; font-size: 12px; }
}

/* ---- 减动效偏好 ---- */
@media (prefers-reduced-motion: reduce) {
    * { transition-duration: 0.01ms !important; animation-duration: 0.01ms !important; }
    .clk-dot.sync { animation: none; opacity: 1; }
}

/* ---- 打印 ---- */
@media print {
    .clk-actions, .clk-ntp, .clk-sheet, .clk-sheet-mask { display: none !important; }
    html, body { background: #fff; color: #000; }
    .clk-time { color: #000; text-shadow: none; }
}
</style>
</head>
<body>
<div class="clk-ntp" id="ntpStatus" role="button" tabindex="0" aria-label="校时服务器">
    <span class="clk-dot sync" id="ntpDot"></span>
    <span id="ntpText">校时中</span>
</div>

<div class="clk-main">
    <div class="clk-date" id="dateEl">&nbsp;</div>
    <div class="clk-time"><span id="timeEl">--:--:--</span><span class="clk-ms" id="elMs">.000</span></div>
    <div class="clk-zone">北京时间 UTC+8</div>

    <div class="clk-quote" id="quoteBox">
        <div class="clk-quote-text" id="quoteText"></div>
        <span class="clk-quote-from" id="quoteFrom"></span>
    </div>
</div>

<div class="clk-actions">
    <button type="button" class="clk-btn is-on" data-act="sec">精确到秒</button>
    <button type="button" class="clk-btn" data-act="ms">精确到毫秒</button>
    <button type="button" class="clk-btn" data-act="date">隐藏日期</button>
    <button type="button" class="clk-btn" data-act="fs">进入全屏</button>
</div>

<div class="clk-sheet-mask" id="sheetMask"></div>
<div class="clk-sheet" id="sheet">
    <div class="clk-sheet-title">校时服务器</div>
    <div id="srvList"></div>
</div>

<script>
(function () {
    "use strict";

    /* ==============================================================
     * 一、时间计算
     * ==============================================================
     * 全部按北京时间 UTC+8 算，不跟用户本地时区走 ——
     * 页面上已经写明 UTC+8，如果跟着用户时区（比如美国用户）
     * 数字和标签就对不上了。
     *
     * 做法：时间戳 + 8 小时，再用 getUTC* 取值。
     * ============================================================== */

    var TZ_OFFSET = 8 * 3600 * 1000;
    var WEEK = ["日", "一", "二", "三", "四", "五", "六"];

    function pad(n, w) {
        var s = String(n);
        while (s.length < (w || 2)) { s = "0" + s; }
        return s;
    }

    function partsOf(ms) {
        var d = new Date(ms + TZ_OFFSET);
        return {
            y: d.getUTCFullYear(),
            mo: d.getUTCMonth() + 1,
            da: d.getUTCDate(),
            w: d.getUTCDay(),
            h: d.getUTCHours(),
            mi: d.getUTCMinutes(),
            s: d.getUTCSeconds(),
            ms: d.getUTCMilliseconds()
        };
    }

    var elDate = document.getElementById("dateEl");
    var elTime = document.getElementById("timeEl");
    var elMs = document.getElementById("elMs");

    var lastDateStr = "";
    var lastTimeStr = "";
    var lastMsStr = "";

    /* 只改 textContent，不动 DOM 结构 —— 每帧重排会掉帧 */
    function render(nowMs, withMs) {
        var p = partsOf(nowMs);

        var ds = p.y + "年" + p.mo + "月" + p.da + "日 星期" + WEEK[p.w];
        if (ds !== lastDateStr) {
            elDate.textContent = ds;
            lastDateStr = ds;
        }

        var ts = pad(p.h) + ":" + pad(p.mi) + ":" + pad(p.s);
        if (ts !== lastTimeStr) {
            elTime.textContent = ts;
            lastTimeStr = ts;
        }

        if (withMs) {
            var mss = "." + pad(p.ms, 3);
            if (mss !== lastMsStr) {
                elMs.textContent = mss;
                lastMsStr = mss;
            }
        }
    }

    /* ==============================================================
     * 二、NTP 校准
     * ==============================================================
     * 服务端已算好「本地钟快了 offset 毫秒」。
     * 之后每次 now = Date.now() + offset 即可。
     *
     * 每 10 分钟重新对一次：浏览器长开时系统钟会被守护进程微调，
     * offset 会慢慢不准。
     * ============================================================== */

    var clock = {
        offsetMs: 0,
        synced: false,
        server: "ntsc",
        quality: ""
    };

    var SERVERS = [
        { id: "ntsc",    name: "国家授时中心", host: "ntp.ntsc.ac.cn" },
        { id: "aliyun",  name: "阿里云",       host: "ntp.aliyun.com" },
        { id: "windows", name: "微软",         host: "time.windows.com" },
        { id: "google",  name: "谷歌",         host: "time.google.com" },
        { id: "apple",   name: "苹果",         host: "time.apple.com" },
        { id: "pool",    name: "NTP Pool",     host: "pool.ntp.org" }
    ];

    var elNtp = document.getElementById("ntpStatus");
    var elNtpText = document.getElementById("ntpText");
    var elDot = document.getElementById("ntpDot");

    function setDot(cls) {
        elDot.className = "clk-dot " + cls;
    }

    function serverName(id) {
        for (var i = 0; i < SERVERS.length; i++) {
            if (SERVERS[i].id === id) { return SERVERS[i].name; }
        }
        return id;
    }

    function syncTime(id) {
        clock.server = id || clock.server;
        setDot("sync");
        elNtpText.textContent = "校时中";

        var url = "/api/ntp.php?srv=" + encodeURIComponent(clock.server);
        var t0 = Date.now();   /* 请求发出（浏览器本地钟） */

        fetch(url, { cache: "no-store", headers: { "Accept": "application/json" } })
            .then(function (r) { return r.json(); })
            .then(function (d) {
                var t1 = Date.now();   /* 收到响应（浏览器本地钟） */
                var rtt = Math.max(0, t1 - t0);

                if (d && d.ok && d.std_time_ms) {
                    /* 服务端给的 std_time_ms 是「它算完那一刻的标准时间」。
                     * 假设去程=回程，那一刻 ≈ t0 + rtt/2。
                     * 所以「此刻（t1）」的标准时间 = std_time_ms + rtt/2。
                     * offset = 浏览器钟 - 标准时间。 */
                    var stdAtT1 = d.std_time_ms + rtt / 2;
                    clock.offsetMs = t1 - stdAtT1;
                    clock.synced = true;
                    clock.quality = d.precision;

                    /* 显示实际精度：NTP 时是真毫秒级，HTTP 时是几百毫秒级。
                     * 这里必须如实标，否则毫秒模式会显得「很准」其实在乱跳。 */
                    var acc = (typeof d.accuracy_ms === "number")
                        ? d.accuracy_ms
                        : (d.precision === "good" ? 3 : (d.precision === "fair" ? 60 : 300));
                    var label = d.method === "ntp" ? serverName(clock.server) : "网络校时";
                    var accText = acc < 10 ? String(Math.max(1, Math.round(acc))) : String(Math.round(acc));
                    elNtpText.textContent = label + " ±" + accText + "ms";
                    setDot(d.precision === "good" ? "ok" : (d.precision === "fair" ? "warn" : "bad"));

                    /* 精度差时，毫秒位没有意义 —— 提示一次，不用反复念 */
                    if (d.method === "http" && !clock.msWarned) {
                        clock.msWarned = true;
                        clock.msMeaningless = true;
                    }
                } else {
                    clock.synced = false;
                    elNtpText.textContent = "随意走时";
                    setDot("bad");
                }
                refreshSrvList();
            })
            .catch(function () {
                clock.synced = false;
                elNtpText.textContent = "随意走时";
                setDot("bad");
                refreshSrvList();
            });
    }

    /* ==============================================================
     * 三、渲染循环
     * ==============================================================
     * 秒模式：250ms 刷一次（人眼分不出更细）
     * 毫秒模式：requestAnimationFrame 跟屏幕刷新率
     *
     * 用 rAF 而不是 setInterval：页面切后台时浏览器自动暂停 rAF，
     * 省电，切回来也不会补跳一堆帧。
     * ============================================================== */

    var showMs = false;
    var rafId = 0;
    var timerId = 0;

    function nowMs() {
        return Date.now() + (clock.synced ? clock.offsetMs : 0);
    }

    function loopMs() {
        render(nowMs(), true);
        rafId = requestAnimationFrame(loopMs);
    }

    function loopSec() {
        render(nowMs(), false);
    }

    function startLoop() {
        stopLoop();
        if (showMs) {
            rafId = requestAnimationFrame(loopMs);
        } else {
            loopSec();
            timerId = setInterval(loopSec, 250);
        }
    }

    function stopLoop() {
        if (rafId) { cancelAnimationFrame(rafId); rafId = 0; }
        if (timerId) { clearInterval(timerId); timerId = 0; }
    }

    document.addEventListener("visibilitychange", function () {
        if (!document.hidden) { render(nowMs(), showMs); }
    });

    /* ==============================================================
     * 四、每日一言
     * ==============================================================
     * hitokoto.cn 公开接口。每次打开都换一句：
     * 请求带时间戳 + 随机串 + cache:no-store，绕开浏览器和 CDN 缓存。
     *
     * 外网可能被校园网拦，所以准备了本地兜底；
     * 兜底文案刻意不带 AI 腔。
     * ============================================================== */

    var elQuote = document.getElementById("quoteBox");
    var elQuoteText = document.getElementById("quoteText");
    var elQuoteFrom = document.getElementById("quoteFrom");

    var FALLBACK = [
        "把今天过好，明天自然不会太差。",
        "慢一点也没关系，方向对了就行。",
        "所有值得做的事，一开始都很难。",
        "别急，饭要一口一口吃。",
        "你现在担心的，多半不会发生。",
        "天塌不下来，先去睡一觉。",
        "能坚持到这里的，已经比很多人强了。",
        "少想一点，多做一点。",
        "今天也要好好吃饭。",
        "路是一步一步走出来的。"
    ];

    function showQuote(text, from) {
        elQuoteText.classList.remove("is-in");
        /* 先淡出，换完再淡入 —— 直接替换会「啪」地跳一下 */
        setTimeout(function () {
            elQuoteText.textContent = text;
            elQuoteFrom.textContent = from || "";
            elQuote.classList.add("is-loaded");
            requestAnimationFrame(function () {
                requestAnimationFrame(function () {
                    elQuoteText.classList.add("is-in");
                });
            });
        }, 180);
    }

    function loadQuote() {
        var url = "https://v1.hitokoto.cn/?encode=json&charset=utf-8&_t=" + Date.now() +
                  "&_r=" + Math.random().toString(36).slice(2);

        fetch(url, { cache: "no-store", mode: "cors" })
            .then(function (r) { return r.json(); })
            .then(function (d) {
                if (d && d.hitokoto) {
                    var from = d.from ? "— " + d.from : (d.from_who ? "— " + d.from_who : "");
                    showQuote(d.hitokoto, from);
                } else {
                    showQuote(FALLBACK[Math.floor(Math.random() * FALLBACK.length)], "");
                }
            })
            .catch(function () {
                showQuote(FALLBACK[Math.floor(Math.random() * FALLBACK.length)], "");
            });
    }

    /* ==============================================================
     * 五、按钮与全屏
     * ============================================================== */

    var btnSec  = document.querySelector('[data-act="sec"]');
    var btnMs   = document.querySelector('[data-act="ms"]');
    var btnDate = document.querySelector('[data-act="date"]');
    var btnFs   = document.querySelector('[data-act="fs"]');

    function setMsMode(on) {
        showMs = on;
        document.body.classList.toggle("show-ms", on);
        btnSec.classList.toggle("is-on", !on);
        btnMs.classList.toggle("is-on", on);
        if (!on) { elMs.textContent = ".000"; }
        startLoop();
    }

    btnSec.addEventListener("click", function () { setMsMode(false); });
    btnMs.addEventListener("click", function () { setMsMode(true); });

    var dateHidden = false;
    btnDate.addEventListener("click", function () {
        dateHidden = !dateHidden;
        document.body.classList.toggle("hide-date", dateHidden);
        btnDate.textContent = dateHidden ? "显示日期" : "隐藏日期";
        btnDate.classList.toggle("is-on", dateHidden);
    });

    function isFs() {
        return !!(document.fullscreenElement || document.webkitFullscreenElement);
    }

    function toggleFs() {
        var el = document.documentElement;
        if (isFs()) {
            if (document.exitFullscreen) { document.exitFullscreen(); }
            else if (document.webkitExitFullscreen) { document.webkitExitFullscreen(); }
        } else {
            if (el.requestFullscreen) { el.requestFullscreen().catch(function () {}); }
            else if (el.webkitRequestFullscreen) { el.webkitRequestFullscreen(); }
        }
    }

    btnFs.addEventListener("click", toggleFs);

    document.addEventListener("fullscreenchange", function () {
        var fs = isFs();
        btnFs.textContent = fs ? "退出全屏" : "进入全屏";
        btnFs.classList.toggle("is-on", fs);
        render(nowMs(), showMs);
    });

    /* ==============================================================
     * 六、校时服务器选择
     * ============================================================== */

    var elSheet = document.getElementById("sheet");
    var elMask = document.getElementById("sheetMask");
    var elSrvList = document.getElementById("srvList");

    function refreshSrvList() {
        var html = "";
        for (var i = 0; i < SERVERS.length; i++) {
            var s = SERVERS[i];
            var on = (s.id === clock.server);
            html += '<button type="button" class="clk-srv' + (on ? " is-on" : "") + '" data-srv="' + s.id + '">'
                 +  '<span><span>' + s.name + '</span><div class="clk-srv-host">' + s.host + '</div></span>'
                 +  '<span class="tick">✓</span>'
                 +  '</button>';
        }
        elSrvList.innerHTML = html;
    }

    function openSheet() {
        refreshSrvList();
        elSheet.classList.add("is-open");
        elMask.classList.add("is-open");
    }

    function closeSheet() {
        elSheet.classList.remove("is-open");
        elMask.classList.remove("is-open");
    }

    elNtp.addEventListener("click", openSheet);
    elNtp.addEventListener("keydown", function (e) {
        if (e.key === "Enter" || e.key === " ") { e.preventDefault(); openSheet(); }
    });
    elMask.addEventListener("click", closeSheet);

    elSrvList.addEventListener("click", function (e) {
        var b = e.target.closest ? e.target.closest("[data-srv]") : null;
        if (!b) { return; }
        closeSheet();
        syncTime(b.getAttribute("data-srv"));
    });

    document.addEventListener("keydown", function (e) {
        if (e.key === "Escape") {
            if (elSheet.classList.contains("is-open")) { closeSheet(); return; }
            if (isFs()) { toggleFs(); }
            return;
        }
        if (e.target && /^(INPUT|TEXTAREA)$/.test(e.target.tagName)) { return; }
        if (e.key === "f" || e.key === "F") { toggleFs(); }
        if (e.key === "m" || e.key === "M") { setMsMode(!showMs); }
        if (e.key === "d" || e.key === "D") { btnDate.click(); }
    });

    /* 点空白处进入全屏（桌面常见操作），但避开按钮/红框/状态栏 */
    document.addEventListener("click", function (e) {
        if (e.target.closest && e.target.closest(".clk-btn, .clk-ntp, .clk-sheet, .clk-sheet-mask, .clk-quote")) { return; }
        if (!isFs()) { toggleFs(); }
    });

    /* ==============================================================
     * 七、启动
     * ============================================================== */

    render(Date.now(), false);
    syncTime("ntsc");
    loadQuote();
    startLoop();

    setInterval(function () {
        if (!document.hidden) { syncTime(clock.server); }
    }, 10 * 60 * 1000);

})();
</script>
</body>
</html>
