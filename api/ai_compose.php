<?php
/**
 * 发帖「智能成稿」服务端代理
 * 浏览器只发来一段零散素材（+ 已选分类），由本文件拼装提示词、调用模型、返回标题与正文。
 *
 * 三条边界：
 *   1. 只服务已登录用户——发帖本身就需要正式账号，游客没有可用的落点；
 *   2. 提示词与身份全部在服务端拼装，浏览器传来的 system 消息一律丢弃（本接口不接收 messages）；
 *   3. 返回值只做「减法」清洗（去标签/去 Markdown/限长），绝不执行模型给出的任何指令或链接。
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../includes/ai_client.php';
require_once __DIR__ . '/../includes/ai_compose.php';

$AICFG = [];
$aiCfgFile = __DIR__ . '/../config/ai_config.php';
if (is_file($aiCfgFile)) {
    require $aiCfgFile;
}
$apiKeys = aiResolveApiKeys($AICFG);
$model   = isset($AICFG['model']) ? trim((string)$AICFG['model']) : '';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonError('仅支持 POST 请求', 405);
}

// 同源校验（与 api/ai_assistant.php 同一套归一化逻辑，避免把同源请求误判成跨源）
$reqHost = $_SERVER['HTTP_HOST'] ?? '';
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if ($origin !== '') {
    $originHost = parse_url($origin, PHP_URL_HOST);
    $originPort = parse_url($origin, PHP_URL_PORT);
    $reqHostOnly = parse_url('http://' . $reqHost, PHP_URL_HOST);
    $reqPort = parse_url('http://' . $reqHost, PHP_URL_PORT);
    $originDefaultPort = (parse_url($origin, PHP_URL_SCHEME) === 'https') ? 443 : 80;
    $originPort = $originPort ?: $originDefaultPort;
    $reqPort = $reqPort ?: $originDefaultPort;
    if ($originHost === false || $originHost !== $reqHostOnly || $originPort !== $reqPort) {
        error_log('AI 成稿：拒绝跨源请求，origin=' . $origin);
        jsonError('请求来源不合法', 403);
    }
}

// ---- CSRF ----
// 与 AI 对话同一套规则：令牌放在 JSON 体的 csrf_token 字段。
// 成稿会真实消耗站点 Key 的额度，没有这一层就等於对外开放了一个免费烧钱接口。
$rawBody = (string)file_get_contents('php://input');
$data = json_decode($rawBody, true);
if (!verifyCSRFTokenEnhanced(is_array($data) ? (string)($data['csrf_token'] ?? '') : '')) {
    jsonError('页面已过期，请刷新后重试', 403);
}

$user = requireMember('智能成稿需要注册账号后才能使用');
$user = checkBanned($user);
if (!empty($user['is_banned'])) {
    jsonError('账号已被封禁，无法使用智能成稿', 403);
}

// 限流：按 IP 10 次/分（整栋楼共用出口 IP，阈值不能太紧，服务端另有整体预算兜底）
if (!checkRateLimit(getClientIP(), 'ai_compose', 10, 60)) {
    jsonError('生成得有点频繁，稍等一会儿再试', 429);
}

if (!$apiKeys || $model === '') {
    error_log('AI 成稿：config/ai_config.php 未配置 api_keys / model');
    jsonError('AI 服务未配置，请联系管理员', 503);
}

// 请求体已在上面的 CSRF 段读过，这里直接复用
if (!is_array($data)) {
    jsonError('请求参数缺失', 400);
}

$idea = isset($data['idea']) ? trim((string)$data['idea']) : '';
if ($idea === '') {
    jsonError('请先用一句话说说你想写什么', 400);
}
if (mb_strlen($idea) > 800) {
    jsonError('素材太长了，精简到 800 字以内再试', 400);
}

// 分类白名单：只认服务端定义的 key，客户端传名字一律忽略
$catKey = isset($data['category']) ? trim((string)$data['category']) : '';
$cats = aiComposeCategories();
if ($catKey !== '' && !isset($cats[$catKey])) {
    $catKey = '';
}

$nickName = trim((string)($user['nickname'] ?? ''));
if ($nickName === '') {
    $nickName = '用户' . $user['id'];
}

$systemPrompt = aiComposeSystemPrompt([
    'site_name'    => getSetting('site_name', SITE_NAME),
    'category_key' => $catKey,
    'nickname'     => $nickName,
]);

$messages = [
    ['role' => 'system', 'content' => $systemPrompt],
    ['role' => 'user', 'content' => aiComposeUserMessage($idea)],
];

$aiT0 = microtime(true);
$aiResult = aiChatCompletion($AICFG, $messages, 60);
$aiElapsedMs = (int)round((microtime(true) - $aiT0) * 1000);

$message = $aiResult['ok'] ? ($aiResult['resp']['choices'][0]['message'] ?? []) : [];
$rawReply = isset($message['content']) ? trim((string)$message['content']) : '';
$parsed = $aiResult['ok'] ? aiParseComposeResult($rawReply) : null;

// 后台审计：与 AI 助手同表同口径（提问原文截断，不存成稿全文）
logAiCall([
    'user_id'     => (int)$user['id'],
    'nickname'    => $nickName,
    'is_guest'    => false,
    'ip'          => getClientIP(),
    'success'     => $parsed !== null,
    'http_status' => (int)($aiResult['status'] ?? 0),
    'error'       => $aiResult['ok']
        ? ($parsed === null ? '模型输出无法解析为标题/正文' : '')
        : (string)$aiResult['error'],
    'elapsed_ms'  => $aiElapsedMs,
    'key_tries'   => (int)($aiResult['key_tries'] ?? 0),
    'sources'     => [],
    'intent'      => 'compose' . ($catKey !== '' ? ':' . $catKey : ''),
    'question'    => $idea,
    'page'        => '/pages/post.php',
]);

if (!$aiResult['ok']) {
    error_log('AI 成稿：请求失败，HTTP ' . $aiResult['status'] . '，已尝试 ' . $aiResult['key_tries']
        . ' 把 Key，错误：' . mb_substr((string)$aiResult['error'], 0, 120));
    $errMsg = trim((string)$aiResult['error']);
    $userMsg = $errMsg !== '' ? 'AI 服务返回错误：' . mb_substr($errMsg, 0, 150) : 'AI 服务暂时不可用，请稍后再试';
    jsonError($userMsg, 502);
}

if ($parsed === null) {
    error_log('AI 成稿：模型输出无法解析，原始前 200 字：' . mb_substr($rawReply, 0, 200));
    jsonError('这次没生成好，换个说法再试一次', 502);
}

jsonSuccess([
    'title'   => $parsed['title'],
    'content' => $parsed['content'],
]);
