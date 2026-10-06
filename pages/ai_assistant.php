<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/security.php';

// 完整版入口：登录用户与游客都能用（游客只读，写操作会被服务端与提示词双重拦下）
$user = requireLoginOrGuest();
$isGuest = ($user === null);
$bannedMsg = '';
if ($user) {
    $user = checkBanned($user);
    if ($user['is_banned']) {
        $bannedMsg = '账号已被封禁：' . ($user['ban_reason'] ?? '');
        if ($user['ban_until']) {
            $bannedMsg .= '（解封时间：' . $user['ban_until'] . '）';
        }
    }
}

$siteName = getSetting('site_name', SITE_NAME);
$csrfToken = generateCSRFToken();

// 本页自己就是 AI 的完整版，不要再挂全站浮窗，否则会出现两份 AI
$aiWidgetMode = 'off';
?>
<!DOCTYPE html>
<html lang="<?= htmlspecialchars($LANG_CODE) ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <?php require_once __DIR__ . '/../includes/pwa_head.php'; ?>
    <link rel="icon" href="/icon.ico" type="image/x-icon">
    <title>AI 助手 - <?= htmlspecialchars($siteName) ?></title>
    <link rel="stylesheet" href="<?= asset_url('/assets/css/style.css') ?>?v=<?= asset_ver('/assets/css/style.css') ?>">
    <link rel="stylesheet" href="<?= asset_url('/assets/css/enhancements.css') ?>?v=<?= asset_ver('/assets/css/enhancements.css') ?>">
    <link rel="stylesheet" href="<?= asset_url('/assets/css/ai_widget.css') ?>?v=<?= asset_ver('/assets/css/ai_widget.css') ?>">
    <script>
        const SITE_URL = '<?= SITE_URL ?>';
        const IS_LOGGED_IN = <?= $user ? 'true' : 'false' ?>;
        const CSRF_TOKEN = <?= json_encode($csrfToken) ?>;
        const USER_DATA = <?= $user ? json_encode(['id' => $user['id'], 'qq' => $user['qq'], 'uuid' => $user['uuid'] ?? '', 'nickname' => $user['nickname'], 'avatar' => $user['avatar'], 'role' => $user['role']], JSON_HEX_TAG | JSON_UNESCAPED_UNICODE) : 'null' ?>;
        window.IS_LOGGED_IN = IS_LOGGED_IN;
        window.USER_DATA = USER_DATA;
        window.CSRF_TOKEN = CSRF_TOKEN;
        // 共享核心（assets/js/ai_core.js）从这里取配置，不依赖上面的顶层 const
        window.__lwAiCfg = {
            siteUrl: SITE_URL,
            siteName: <?= json_encode($siteName, JSON_UNESCAPED_UNICODE) ?>,
            loggedIn: IS_LOGGED_IN,
            isGuest: <?= $isGuest ? 'true' : 'false' ?>,
            role: <?= json_encode($user['role'] ?? 'guest') ?>,
            nickname: <?= json_encode($user ? ($user['nickname'] ?? '') : '', JSON_UNESCAPED_UNICODE) ?>,
            userId: <?= $user ? (int)$user['id'] : 0 ?>,
            isAdmin: <?= ($user && in_array($user['role'] ?? '', ['admin', 'super_admin'], true)) ? 'true' : 'false' ?>,
            csrf: CSRF_TOKEN,
            greeting: '',
            quick: [],
            version: '1'
        };
    </script>
    <style>
        /* ===== 本页只负责「整页布局」，组件外观全部复用 assets/css/ai_widget.css ===== */
        .ai-main {
            padding: var(--space-lg) 0 var(--space-xl);
        }
        .ai-container {
            max-width: 860px;
            margin: 0 auto;
            padding: 0 var(--space-md);
            display: flex;
            flex-direction: column;
        }
        .ai-header {
            text-align: center;
            padding: 0.75rem 0 1.25rem;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.875rem;
            flex-wrap: wrap;
        }
        .ai-header-main { flex: 1 1 18rem; min-width: 0; }
        .ai-opts-btn {
            flex: 0 0 auto;
            display: inline-flex;
            align-items: center;
            gap: 0.375rem;
            padding: 0.45rem 0.875rem;
            font-size: 0.82rem;
            font-weight: 600;
            color: var(--text-secondary);
            background: var(--glass-bg);
            border: 1px solid var(--glass-border-soft);
            border-radius: var(--radius-full);
            cursor: pointer;
            transition: color .18s ease, border-color .18s ease, background .18s ease;
        }
        .ai-opts-btn:hover {
            color: var(--accent);
            border-color: var(--accent);
        }
        .ai-opts-ico { display: inline-flex; width: 16px; height: 16px; }
        .ai-opts-ico svg { width: 16px; height: 16px; stroke: currentColor; }
        .ai-header h1 {
            font-size: 1.9rem;
            font-weight: 700;
            color: var(--text);
            margin-bottom: 0.375rem;
            letter-spacing: 1px;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.625rem;
            flex-wrap: wrap;
        }
        .ai-header h1 .ai-badge {
            font-size: 0.7rem;
            font-weight: 600;
            color: #fff;
            background: var(--accent);
            border-radius: var(--radius-full);
            padding: 0.1875rem 0.625rem;
            letter-spacing: 0.5px;
        }
        .ai-header .subtitle {
            font-size: 0.92rem;
            color: var(--text-secondary);
        }
        /* 页面版把浮窗的固定定位与尺寸约束解开，改成占满容器 */
        .ai-shell .lw-ai-msgs {
            height: clamp(22rem, 58vh, 40rem);
        }
        .ai-shell {
            display: flex;
            flex-direction: column;
            border: 1px solid var(--glass-border-soft);
            border-radius: var(--radius-xl);
            background: var(--glass-bg);
            box-shadow: var(--shadow-md);
            backdrop-filter: saturate(180%) blur(var(--glass-blur));
            -webkit-backdrop-filter: saturate(180%) blur(var(--glass-blur));
            overflow: hidden;
        }
        @media (max-width: 600px) {
            .ai-header h1 { font-size: 1.5rem; }
        }
    </style>
</head>
<body>
    <?php if ($bannedMsg): ?>
    <div class="ban-banner">
        <div class="container">
            <span class="ban-text"><?= htmlspecialchars($bannedMsg) ?></span>
        </div>
    </div>
    <?php endif; ?>

    <?php
    $headerBackHref = SITE_URL . '/';
    $headerBackText = t('nav.back_home');
    require __DIR__ . '/../includes/site_header.php';

    // 本页不挂全站浮窗（$aiWidgetMode='off'），但仍 require 一次以获得统一的配置注入逻辑；
    // ai_widget.php 见到 off 会直接 return，不会输出任何 DOM。
    require __DIR__ . '/../includes/ai_widget.php';
    ?>

    <main class="ai-main">
        <div class="ai-container">
            <div class="ai-header">
                <div class="ai-header-main">
                    <h1>AI 助手 <span class="ai-badge">校园版</span></h1>
                    <p class="subtitle">学习、生活、站内办事都能问；要动手的事我会先给你一张确认卡片</p>
                </div>
                <?php if (!$isGuest): ?>
                <button type="button" class="ai-opts-btn" id="lwAiOptsBtn"
                        title="接入我自己的模型（高级选项）">
                    <span class="ai-opts-ico" id="lwAiOptsIco" aria-hidden="true"></span>
                    高级选项
                </button>
                <?php endif; ?>
            </div>

            <!-- 组件结构与浮窗完全一致（同一套 .lw-ai-* 类名 + 同一个共享核心渲染） -->
            <div class="ai-shell lw-ai" id="lwAiRoot" data-state="open">
                <div class="lw-ai-msgs" id="lwAiMsgs" role="log" aria-live="polite">
                    <div class="lw-ai-welcome" id="lwAiWelcome">
                        <p class="lw-ai-welcome-tip">
                            你好，我是<?= htmlspecialchars($siteName) ?>的小助手。<br>
                            可以问我站里的动态、公告与功能怎么用，也能聊学习方法和生活上的事。<br>
                            <?php if ($isGuest): ?>
                            你现在是游客模式，只能看公开内容；注册登录后还能让我帮你办事。
                            <?php else: ?>
                            要我替你收藏、点赞、关注、签到、评论或删自己的帖子，我会先出一张确认卡片，你点了才会真执行。
                            <?php endif; ?>
                        </p>
                        <div class="lw-ai-quick" id="lwAiQuick"></div>
                    </div>
                </div>
                <form class="lw-ai-inputbar" id="lwAiForm">
                    <textarea class="lw-ai-input" id="lwAiInput" rows="1" maxlength="2000"
                              placeholder="输入你的问题，按 Enter 发送…" aria-label="输入你的问题"></textarea>
                    <button type="submit" class="lw-ai-send" id="lwAiSend" aria-label="发送"></button>
                </form>
                <div class="lw-ai-foot" id="lwAiFoot"></div>
            </div>
        </div>
    </main>

    <footer class="site-footer">
        <div class="container">
            <p>&copy; <?= date('Y') ?> <?= htmlspecialchars($siteName) ?></p>
        </div>
    </footer>

    <!-- 核心脚本文件名不含 "chat"：本站所在主机对 URL 含 chat 的请求一律 403（见 includes/ai_widget.php 的说明），改名后即恢复正常，切勿改回 ai_chat_core.js -->
    <script src="<?= asset_url('/assets/js/ai_core.js') ?>?v=<?= asset_ver('/assets/js/ai_core.js') ?>" defer></script>
    <script src="<?= asset_url('/assets/js/main.js') ?>?v=<?= asset_ver('/assets/js/main.js') ?>" defer></script>
    <?php require_once __DIR__ . '/../includes/lang_ui.php'; ?>
    <script src="<?= asset_url('/assets/js/enhancements.js') ?>?v=<?= asset_ver('/assets/js/enhancements.js') ?>" defer></script>
    <script>
    // 等共享核心就绪后挂载页面版对话。defer 脚本按顺序执行，但核心是 defer、
    // 本段是普通内联脚本（会在 defer 之前执行），所以轮询等待。
    (function () {
        var tries = 0;
        function boot() {
            var Core = window.__lwAiCore;
            if (!Core) {
                if (++tries > 200) { return; }
                return setTimeout(boot, 20);
            }
            var sendBtn = document.getElementById('lwAiSend');
            sendBtn.innerHTML = Core.icon('send');

            // 高级选项：接入自己的模型（游客没有，服务端也不给存）
            var optsBtn = document.getElementById('lwAiOptsBtn');
            if (optsBtn && typeof Core.openAiOptions === 'function') {
                var ico = document.getElementById('lwAiOptsIco');
                if (ico) { ico.innerHTML = Core.icon('gear'); }
                optsBtn.addEventListener('click', function () { Core.openAiOptions(); });
            }

            var chat = Core.attach({
                msgs: document.getElementById('lwAiMsgs'),
                form: document.getElementById('lwAiForm'),
                input: document.getElementById('lwAiInput'),
                send: sendBtn,
                foot: document.getElementById('lwAiFoot'),
                welcome: document.getElementById('lwAiWelcome'),
                quick: document.getElementById('lwAiQuick')
            }, { mode: 'page' });

            var isGuest = <?= $isGuest ? 'true' : 'false' ?>;
            chat.setQuick(isGuest
                ? ['站里最近有什么新帖', '社区规范是什么', '怎么注册账号', '给我讲讲怎么高效背单词']
                : ['站里最近有什么新帖', '我的未读通知有哪些', '帮我复习一下英语作文的写法', '最近有点学不进去，怎么办']);

            chat.restore();

            var foot = document.getElementById('lwAiFoot');
            foot.textContent = isGuest
                ? '游客模式：可以问公开内容；注册后能让我帮你办事。对话只保存在本标签页，关闭即清空。'
                : '对话只保存在本标签页，关闭即清空；每次回复均由服务端代理，密钥不下发前端。';
        }
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', boot, { once: true });
        } else {
            boot();
        }
    })();
    </script>
</body>
</html>
