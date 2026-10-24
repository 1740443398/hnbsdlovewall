<?php
/**
 * 文件传输助手
 *
 * 访问：/filehelper（.htaccess 自动路由到本文件）
 *
 * =====================================================================
 * 三种传输通道
 * =====================================================================
 *
 *   ① ≤20MB          服务器中转（最稳，不依赖网络环境）
 *   ② 20MB ~ 1GB      WebRTC P2P 直连（文件不上服务器）
 *   ③ 1GB ~ 3GB       小飞机网盘（走第三方，需要登录态）
 *
 * 优先级设计：能走①就走①，因为共享主机虽然限制 20MB，
 * 但同一局域网外的 P2P 在校园网下经常建连失败。
 * 用户不需要理解这些，页面自动选最快能通的路。
 *
 * 【为什么不用 WebSocket】
 * InfinityFree 免费主机不支持 WebSocket（Apache 没有反向代理）。
 * 所以状态同步用短轮询：两端每 1.2 秒拉一次房间状态。
 * 对文件传输本身没有影响（文件走 HTTP/P2P，不走这条通道）。
 * =====================================================================
 */
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/includes/filehelper.php';

// 无需登录
$fhCode = strtoupper(trim((string)($_GET['code'] ?? '')));
$fhCode = preg_replace('/[^A-Z0-9]/', '', $fhCode);

$pageTitle = '文件传输助手 · ' . SITE_NAME;
$LANG_CODE = $LANG_CODE ?? 'zh_CN';

// 站点版本号（页脚用）
$fhVersion = '2.4.1';
$fhCfgFile = __DIR__ . '/config/app_config.php';
if (is_file($fhCfgFile)) {
    $fhCfg = require $fhCfgFile;
    if (is_array($fhCfg) && !empty($fhCfg['version_name'])) {
        $fhVersion = (string)$fhCfg['version_name'];
    }
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
<meta name="theme-color" content="#2F5B9A">
<title><?= htmlspecialchars($pageTitle) ?></title>
<meta name="description" content="手机与电脑互传文件，无需登录，扫码即用。">
<link rel="icon" href="/icon.ico">
<link rel="stylesheet" href="/assets/css/filehelper.css?v=1">
</head>
<body class="fh-body" data-code="<?= htmlspecialchars($fhCode) ?>">

<header class="fh-header">
    <a class="fh-logo" href="/" aria-label="返回首页">
        <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/>
        </svg>
    </a>
    <h1 class="fh-title">文件传输助手</h1>
    <button type="button" class="fh-icon-btn" id="fhHelpBtn" aria-label="使用说明">
        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="12" cy="12" r="10"/><path d="M9.09 9a3 3 0 0 1 5.83 1c0 2-3 3-3 3"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
    </button>
</header>

<main class="fh-main">

<section class="fh-panel" id="fhPairPanel">
    <div class="fh-pair-grid">

        <div class="fh-pair-card">
            <div class="fh-pair-icon fh-pair-icon-pc">
                <svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>
            </div>
            <h2>发起连接</h2>
            <p class="fh-pair-desc">生成配对码，让另一台设备扫或输入</p>
            <div class="fh-code-box" id="fhCodeBox" hidden>
                <div class="fh-code-line">
                    配对码：<b class="fh-code" id="fhCodeText">------</b>
                </div>
                <div class="fh-qr" id="fhQrBox"></div>
                <div class="fh-code-tip">另一台设备扫码，或手动输入上面的配对码</div>
            </div>
            <button type="button" class="fh-btn fh-btn-primary" id="fhCreateBtn">生成配对码</button>
            <p class="fh-pair-foot">页面显示配对码后，可截图或口头告诉对方</p>
        </div>

        <div class="fh-pair-card">
            <div class="fh-pair-icon fh-pair-icon-mobile">
                <svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="7"/><line x1="16.5" y1="16.5" x2="21" y2="21"/></svg>
            </div>
            <h2>加入连接</h2>
            <p class="fh-pair-desc">输入对方设备上显示的 6 位配对码</p>
            <input class="fh-code-input" id="fhJoinInput" maxlength="6" placeholder="ABCD23"
                   autocomplete="off" autocapitalize="characters" spellcheck="false">
            <button type="button" class="fh-btn fh-btn-primary" id="fhJoinBtn">连接</button>
            <p class="fh-pair-foot">两台设备都可以发起，谁先打开都一样</p>
        </div>

    </div>

    <div class="fh-tips">
        <p><strong>不用登录，传完即焚。</strong>文件不长期保存，中转文件 1 分钟内自动删除。</p>
        <p class="fh-tips-sub">手机 ↔ 手机 · 电脑 ↔ 电脑 · 手机 ↔ 电脑，任意两台能上网的设备都能传</p>
    </div>
</section>

<section class="fh-panel" id="fhRoomPanel" hidden>
    <div class="fh-room-head">
        <div class="fh-room-code">配对码 <b id="fhRoomCode">------</b></div>
        <div class="fh-peers">
            <span class="fh-peer" id="fhPeerSelf"><i></i>本机</span>
            <span class="fh-peer" id="fhPeerOther"><i></i>等待对方…</span>
            <span class="fh-link-badge" id="fhLinkBadge" hidden></span>
        </div>
        <button type="button" class="fh-btn fh-btn-ghost fh-btn-sm" id="fhLeaveBtn">断开</button>
    </div>

    <div class="fh-drop" id="fhDrop">
        <svg class="fh-drop-icon" width="42" height="42" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round">
            <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/>
        </svg>
        <p class="fh-drop-title">点击选择文件，或拖到这里</p>
        <p class="fh-drop-sub">单个文件最大 3GB</p>
        <input type="file" id="fhFileInput" multiple hidden>
    </div>

    <div class="fh-text-row">
        <textarea id="fhTextInput" placeholder="也可以发文字…" rows="1"></textarea>
        <button type="button" class="fh-btn fh-btn-primary fh-btn-send" id="fhSendTextBtn">发送</button>
    </div>

    <div class="fh-list" id="fhList"></div>
    <div class="fh-empty" id="fhEmpty">还没有传输记录</div>
</section>

</main>

<div class="fh-modal" id="fhHelpModal" hidden>
    <div class="fh-modal-mask" data-close></div>
    <div class="fh-modal-box">
        <h3>怎么用</h3>
        <ol>
            <li>在<b>任意一台设备</b>上打开本页，点「生成配对码」</li>
            <li>在<b>另一台设备</b>上打开同一页，扫码或输入配对码</li>
            <li>两边都能看到同一个房间，直接选文件发送</li>
        </ol>
        <p style="color:#9AA4B2;font-size:12px;margin-top:6px">
            手机 ↔ 手机、电脑 ↔ 电脑、手机 ↔ 电脑都可以，没有固定哪台必须是"电脑"。
        </p>
        <h3>文件会存多久</h3>
        <p>≤20MB 的文件暂存服务器，<b>1 分钟</b>后自动删除，下载中会自动续期。<br>
           20MB 以上点对点直传，文件不经过服务器。</p>
        <h3>常见问题</h3>
        <p><b>手机扫码后没反应？</b>检查手机和电脑是否都能访问本网站。</p>
        <p><b>大文件传不动？</b>20MB~1GB 需要两端网络能直连。校园网限制严格时会失败，此时请改用同一 WiFi。</p>
        <button type="button" class="fh-btn fh-btn-primary" data-close>知道了</button>
    </div>
</div>

<footer class="fh-footer">
    <span>LoveWall v<?= htmlspecialchars($fhVersion) ?></span>
    <span class="fh-footer-dot">·</span>
    <a href="/">返回首页</a>
</footer>

<script>
window.FH = {
    code: <?= json_encode($fhCode, JSON_UNESCAPED_UNICODE) ?>,
    siteUrl: <?= json_encode(defined('SITE_URL') ? SITE_URL : '', JSON_UNESCAPED_UNICODE) ?>,
    maxFile: 3221225472,
    relayMax: 20971520,
    p2pMax: 1073741824
};
</script>
<script src="/assets/js/qrcode.min.js?v=1"></script>
<script src="/assets/js/filehelper.js?v=1" defer></script>
</body>
</html>
