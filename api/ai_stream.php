<?php
/**
 * AI 助手流式端点（SSE）——把「思考过程」实时同步到对话框
 * ---------------------------------------------------------------------------
 * 与 api/ai_assistant.php 的关系：
 *   两者共用 includes/ai_chat_flow.php 的 aiChatPrepare() / aiChatFinalize()，
 *   鉴权、限流、消息校验、系统提示词、站点上下文、审计落库、确认卡片规则完全一致，
 *   区别只在「怎么把结果送出去」——那边一次性 JSON，这边边收边推。
 *
 * 为什么不用 EventSource：它只支持 GET，而对话历史 + 当前页面要放请求体里（POST），
 * 而且 2 万字的上下文塞 URL 也不现实。所以前端用 fetch + ReadableStream 读 SSE。
 *
 * 三个必须提前处理好的环境细节：
 *   1. gzip 必须关。ob_gzhandler 会把响应攒够一块才发，流式会退化成一次性返回，
 *      思考过程就再也「实时」不了。
 *   2. session 锁必须早释放。一次 AI 请求可能持续几十秒，若全程持有会话文件锁，
 *      该用户后续的心跳、通知、翻页会全部排队堵住（表现为整站卡死）。
 *   3. 但确认卡片要写 $_SESSION，所以流结束后还得把会话加回来 —— 见下方注释。
 */
// ---- 以下两件事必须发生在 require config/config.php 之前 ----

// 1) 关 gzip（config/config.php 里会判断这个常量）
define('LW_NO_GZIP', 1);

// 2) 让 session 可以在「响应头已发出」之后重新打开。
//    session_start() 默认要额外发两个响应头：会话 Cookie 与 Cache-Limiter。
//    头一旦发出就会失败（PHP 8 会 warning 并返回 false），所以这里提前关掉它们，
//    并显式指定浏览器带来的那个会话 ID —— 关掉 use_cookies 后 PHP 不会再自动读 Cookie。
//    浏览器还没带会话 Cookie 时（首访）保持默认行为，让它正常下发 Cookie。
$LW_SID = isset($_COOKIE[session_name()]) ? trim((string)$_COOKIE[session_name()]) : '';
$LW_CAN_REOPEN = ($LW_SID !== '' && preg_match('/^[A-Za-z0-9,\-]{10,128}$/', $LW_SID) === 1);
if ($LW_CAN_REOPEN) {
    @ini_set('session.use_cookies', '0');
    @ini_set('session.cache_limiter', '');
    @session_id($LW_SID);
}

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/ai_chat_flow.php';

// 一次流式请求可能远长于默认 30s 脚本上限
@set_time_limit(150);

/**
 * 推送一个 SSE 事件。JSON 会把换行转义掉，不会破坏 SSE 的「双空行分帧」格式。
 * 上游偶尔会返回非法 UTF-8，用 JSON_INVALID_UTF8_SUBSTITUTE 兜住，避免整帧变 null。
 */
function lwSseEmit(string $event, array $data): void
{
    $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    if ($json === false) {
        $json = '{}';
    }
    echo 'event: ' . $event . "\n";
    echo 'data: ' . $json . "\n\n";
    if (ob_get_level() > 0) {
        @ob_flush();
    }
    @flush();
}

// 清掉 PHP 默认 output_buffering 可能残留的缓冲层，否则 flush() 推不出去
while (ob_get_level() > 0) {
    @ob_end_clean();
}

// ---- 准备阶段：鉴权 / 限流 / 校验 / 拼上下文。失败时内部 jsonError 直接终止，
//      此时还没发 SSE 头，所以前端仍会收到一份正常的 JSON 错误。----
$ctx = aiChatPrepare();

// ---- 正式开始流式输出 ----
header('Content-Type: text/event-stream; charset=utf-8');
header('Cache-Control: no-cache, no-store, must-revalidate, max-age=0');
header('Pragma: no-cache');
// 有些反向代理（nginx / CDN）会攒够一块才转发，这一头是给它们看的
header('X-Accel-Buffering: no');
header('Connection: keep-alive');

// 先发一帧，让前端立刻知道「连接已通、服务器已接单」，
// 顺便把本次读取了哪些站点数据提前告知（思考阶段往往要等好几秒才有第一个字）。
lwSseEmit('open', [
    'sources' => $ctx['siteCtx']['sources'] ?? [],
    'guest'   => $ctx['isGuest'],
]);

// 释放会话文件锁：之后几十秒里该用户的其它请求不会被这一路堵住。
// 注意此时 $_SESSION 仍是内存里的快照，只是不再加锁、也不会再自动写回。
if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}

$timeout = 90;
$aiT0 = microtime(true);

// 每来一个增量就推一帧。type 为 'reasoning'（思考过程）或 'content'（正式回复），
// 前端据此分别渲染到「思考过程」区和正文气泡里。
$onDelta = function (string $type, string $text) {
    if ($text === '') {
        return;
    }
    lwSseEmit('delta', ['type' => $type, 'text' => $text]);
};

$aiResult = aiStreamCompletion($ctx['cfg'], $ctx['messages'], $timeout, $onDelta);
$aiElapsedMs = (int)round((microtime(true) - $aiT0) * 1000);

// ---- 收尾：把会话加回来，否则确认卡片（写 $_SESSION）无法落盘 ----
if ($LW_CAN_REOPEN && session_status() !== PHP_SESSION_ACTIVE) {
    @session_id($LW_SID);
    @session_start();
}
$sessionActive = (session_status() === PHP_SESSION_ACTIVE);
if (!$sessionActive) {
    // 首访（浏览器还没拿到会话 Cookie）时会走到这里：会话无法重开，
    // 本次就不发卡片，避免前端出现一张点了必然失效的卡。
    error_log('[love_wall] AI 流式：会话未能重开，本次跳过确认卡片签发');
}

$result = aiChatFinalize(
    $ctx,
    (string)($aiResult['content'] ?? ''),
    (string)($aiResult['reasoning'] ?? ''),
    $aiResult,
    $aiElapsedMs,
    $sessionActive
);

if (isset($result['error'])) {
    lwSseEmit('error', ['message' => $result['error'], 'code' => $result['code'] ?? 502]);
} else {
    // done 帧带的是「收尾之后」的最终态：已剥离动作块、已截断、已签发卡片。
    // 前端在收到它时会用最终正文重绘一次气泡，把流式期间未渲染的链接/Markdown 补全。
    lwSseEmit('done', $result);
}

if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}
exit;
