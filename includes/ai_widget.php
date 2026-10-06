<?php
/**
 * 全站 AI 小助手浮窗 —— 唯一来源
 *
 * 作用域契约（调用方只需准备变量，其余一律在本文件内兜底）：
 *   可选：$aiWidgetMode     'bubble'（默认，右下角浮窗）| 'off'（本页不挂）
 *   可选：$aiWidgetGreeting 首屏欢迎语（默认按登录态生成）
 *   可选：$aiWidgetQuick    快捷问法数组（默认按登录态生成）
 *
 * 为什么单独成文件、而不是塞进 includes/site_header.php：
 *   1. 顶栏只被 7 个页面 require，而 login / register / gateway / forgot_password /
 *      maintenance 这些页面（没有顶栏）恰恰最需要「找不着北时问一句」；
 *   2. 顶栏本身是 sticky + backdrop-filter（会创建层叠上下文），
 *      把 fixed 浮窗放进去会有层叠陷阱；
 *   3. 顶栏已有自己的自包含兜底脚本，再往里塞第二个重量级脚本会放大故障半径。
 *
 * 为什么不在 lang_ui.php 里挂：那个文件职责是语言字典 + 切换器，且只在 </body> 前输出，
 * 浮窗若那么晚才注入，首屏会出现「按钮 0.5 秒后突然长出来」的跳变。
 *
 * 为什么不是纯 JS 全局注入：拿不到 CSRF 令牌、站点地址与登录态——
 * 页面里用的是顶层 `const CSRF_TOKEN` / `const SITE_URL`，**不会**成为 window 属性。
 *
 * 幂等三层：本文件的 static 标记 → ai_widget.js 的 window.__lwAiWidget → DOM 的 #lwAiFab。
 */

if (!function_exists('lw_ai_widget_render')) {
    /**
     * @param array $opt 见上面的作用域契约
     */
    function lw_ai_widget_render(array $opt = [])
    {
        static $done = false;
        if ($done) {
            return;
        }
        $done = true;

        $mode = $opt['mode'] ?? 'bubble';
        if ($mode === 'off') {
            return;
        }

        // ---- 站点上下文兜底（各页面变量命名不完全一致，逐级回退） ----
        $siteUrl = defined('SITE_URL') ? SITE_URL : '';
        $siteName = '';
        if (isset($opt['site_name']) && $opt['site_name'] !== '') {
            $siteName = $opt['site_name'];
        } elseif (isset($GLOBALS['siteName']) && $GLOBALS['siteName'] !== '') {
            $siteName = $GLOBALS['siteName'];
        } elseif (defined('SITE_NAME') && SITE_NAME !== '') {
            $siteName = SITE_NAME;
        } elseif (function_exists('getSetting')) {
            $siteName = getSetting('site_name', '校园交流墙');
        } else {
            $siteName = '校园交流墙';
        }

        $user = null;
        if (array_key_exists('user', $opt)) {
            $user = $opt['user'];
        } elseif (function_exists('getCurrentUser')) {
            $user = getCurrentUser();
        }
        $isGuest = ($user === null);
        $role = $user ? (string)($user['role'] ?? 'user') : 'guest';
        $nickname = '';
        if ($user) {
            $nickname = trim((string)($user['nickname'] ?? ''));
            if ($nickname === '') {
                $nickname = '用户' . $user['id'];
            }
        }

        // CSRF 令牌：由服务端现取并直供前端，避免依赖各页面自己声明的顶层 const
        $csrf = function_exists('generateCSRFToken') ? generateCSRFToken() : '';

        $cfg = [
            'siteUrl'  => $siteUrl,
            'siteName' => $siteName,
            'loggedIn' => (bool)$user,
            'isGuest'  => $isGuest,
            'role'     => $role,
            'nickname' => $nickname,
            'userId'   => $user ? (int)$user['id'] : 0,
            'isAdmin'  => in_array($role, ['admin', 'super_admin'], true),
            'csrf'     => $csrf,
            'greeting' => (string)($opt['greeting'] ?? ''),
            'quick'    => array_values((array)($opt['quick'] ?? [])),
            'version'  => '1',
        ];

        // 路径统一走 asset_url()：有新鲜 .min 就输出 .min，否则回退源文件。
        // 版本号仍用 asset_ver()，它内部也会解析到实际输出的那个文件。
        $au = function ($p) { return function_exists('asset_url') ? asset_url($p) : $p; };
        $av = function ($p) { return function_exists('asset_ver') ? asset_ver($p) : '1'; };

        $cssHref = $siteUrl . $au('/assets/css/ai_widget.css') . '?v=' . $av('/assets/css/ai_widget.css');
        $coreSrc = $siteUrl . $au('/assets/js/ai_core.js') . '?v=' . $av('/assets/js/ai_core.js');
        $appSrc  = $siteUrl . $au('/assets/js/ai_widget.js') . '?v=' . $av('/assets/js/ai_widget.js');
        ?>
<!-- 站内 AI 小助手（全站浮窗） -->
<div id="lwAiRoot" class="lw-ai" data-state="closed" aria-live="polite"></div>
<script>window.__lwAiCfg = <?= json_encode($cfg, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;</script>
<link rel="stylesheet" href="<?= htmlspecialchars($cssHref) ?>">
        <script>
        /* AI 助手脚本的容错加载
         *
         * ⚠️ 命名铁律：核心脚本叫 ai_core.js，**文件名里绝不能出现 chat**。
         *    本站所在的免费主机对 URL 里含 "chat" 的请求一律返回 403（已实测：
         *    `/assets/js/chat_test_9x1.js` → 403，而同目录 `/assets/js/core_test_9x1.js` → 404），
         *    据其政策应为禁止在免费空间上跑聊天类应用。这个脚本原名叫 ai_chat_core.js，
         *    结果整站每页都加载不到核心 → 浮窗图标全白、助手点开是死的，正是「图标不显示 +
         *    助手无法使用」的根因。改名即恢复，切勿改回。
         *
         * 另外这里做三件事，防的是「单个资源取不到就把整个助手拖死」：
         *   ① 按 core → app 的顺序加载，保持依赖关系；
         *   ② core 失败时换一个 URL 再试一次（换 URL 同时绕开浏览器缓存与可能被
         *      Service Worker 缓存的坏副本）；
         *   ③ 两次都失败就降级成一个「能点开的入口」，不留空白按钮、不静默失败。 */
        (function () {
            var CORE = <?= json_encode($coreSrc) ?>;
            var APP  = <?= json_encode($appSrc) ?>;
            var AI_PAGE = <?= json_encode($siteUrl . '/pages/ai_assistant.php') ?>;
            var busted = false;

            function fallback() {
                var root = document.getElementById('lwAiRoot');
                if (!root || root.getAttribute('data-lw-fallback') === '1') { return; }
                root.setAttribute('data-lw-fallback', '1');
                root.className = 'lw-ai';
                root.innerHTML = '<a href="' + AI_PAGE + '" title="打开 AI 助手" aria-label="打开 AI 助手"'
                    + ' style="position:fixed;right:18px;bottom:18px;z-index:9998;display:flex;'
                    + 'align-items:center;justify-content:center;width:52px;height:52px;border-radius:50%;'
                    + 'text-decoration:none;color:#fff;background:#2F5B9A;box-shadow:0 6px 20px rgba(0,0,0,.18);">'
                    + '<svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor"'
                    + ' stroke-width="2" stroke-linecap="round" stroke-linejoin="round">'
                    + '<path d="M21 11.5a8.4 8.4 0 0 1-9 8.4 9.9 9.9 0 0 1-3.9-.8L3 21l1.9-4.6A8.2 8.2 0 0 1 3 11.5'
                    + ' 8.4 8.4 0 0 1 12 3a8.4 8.4 0 0 1 9 8.5z"/></svg></a>';
            }

            function load(src, onFail) {
                var s = document.createElement('script');
                s.src = src;
                s.async = false; // 动态插入时靠它保持执行顺序
                s.onerror = onFail;
                (document.body || document.head || document.documentElement).appendChild(s);
            }

            load(CORE, function () {
                if (busted) { fallback(); return; }
                busted = true;
                load(CORE + (CORE.indexOf('?') === -1 ? '?' : '&') + 'lwretry=' + Date.now(), function () {
                    fallback();
                });
            });
            load(APP, function () { /* core 起不来时 app 也无意义，交给上面的 fallback 兜底 */ });
        })();
        </script>
        <?php
    }
}

// 调用方可以在 require 之前设置 $aiWidgetMode / $aiWidgetGreeting / $aiWidgetQuick
lw_ai_widget_render([
    'mode'     => isset($aiWidgetMode) ? $aiWidgetMode : 'bubble',
    'greeting' => isset($aiWidgetGreeting) ? $aiWidgetGreeting : '',
    'quick'    => isset($aiWidgetQuick) ? $aiWidgetQuick : [],
]);
