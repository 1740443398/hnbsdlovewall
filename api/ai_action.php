<?php
/**
 * AI 确认卡片 · 唯一写入口
 *
 * 为什么不让前端直接打 api/posts/favorite.php 这类老接口：
 *   1. 参数不下前端——卡片参数只存在服务端会话里，前端拿的是一个不透明 token，
 *      所以前端拿不到也改不了参数（改也没用，服务端压根不看前端传的参数）；
 *   2. 权限二次校验集中在这一处，不用在 9 个老接口里各写一遍 AI 专用分支；
 *   3. 限流与审计集中一处；
 *   4. 以后新增可执行操作只改 includes/ai_actions.php 的注册表。
 *
 * 请求：POST，FormData：token=<32位十六进制>，csrf_token=<令牌>
 * 返回：{ success, message, data }
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../includes/ai_actions.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonError('仅支持 POST 请求', 405);
}

// 同源校验（含 Origin/Referer 主机名比对，已处理 HTTP_HOST 带端口的坑）
if (!verifyCSRFTokenEnhanced((string)($_POST['csrf_token'] ?? ''))) {
    jsonError('CSRF验证失败，请刷新页面后重试', 403);
}

// 游客没有会话态的用户，卡片也不可能发给他；这里直接 401 让前端引导注册
$user = requireLogin();
$user = checkBanned($user);
if (!empty($user['is_banned'])) {
    jsonError('账号已被封禁，无法执行操作', 403);
}

// 双维度限流：共享校园网下 IP 维度不可靠，用户维度才是主约束
if (!checkRateLimit(getClientIP(), 'ai_action', 15, 60)) {
    jsonError('操作过于频繁，请稍后再试', 429);
}
if (!checkRateLimit('u' . (int)$user['id'], 'ai_action_user', 30, 300)) {
    jsonError('操作过于频繁，请稍后再试', 429);
}

$token = trim((string)($_POST['token'] ?? ''));
if ($token === '') {
    jsonError('缺少操作令牌', 400);
}

// 取出并**立即消费**（一次性：先删再执行，杜绝重放）
$pending = aiTakePendingAction($user, $token);
if ($pending === null) {
    jsonError('这个操作已失效或已执行过，请让小助手重新生成', 410);
}

// 执行（注册表白名单 + 参数二次校验 + 业务级限流 + 幂等语义都在 aiExecuteAction 内）
$result = aiExecuteAction($user, $pending['action'], $pending['params']);

if (empty($result['ok'])) {
    error_log('[love_wall] AI 卡片执行失败：action=' . $pending['action']
        . ' code=' . (int)($result['code'] ?? 400)
        . ' msg=' . mb_substr((string)($result['message'] ?? ''), 0, 120));
    jsonError((string)($result['message'] ?? '执行失败'), (int)($result['code'] ?? 400));
}

jsonSuccess($result['data'] ?? [], (string)($result['message'] ?? '操作成功'));
