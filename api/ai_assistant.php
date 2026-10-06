<?php
/**
 * AI 助手服务端代理（非流式）
 * 浏览器只请求本同源端点，由本文件转发至智谱 AI HTTP 接口。
 * 说明：密钥只存在于服务器端（config/ai_config.php），绝不下发前端。
 *
 * 两个关键设计：
 *   1. **身份与规则只由服务端拼装**。浏览器传来的 system 消息一律丢弃，
 *      所以对话内容无法改写 AI 的身份、能力边界与安全纪律。
 *   2. **AI 想替用户办事时不直接执行**，而是在回复末尾输出 <lw-action> 块；
 *      服务端严格解析后只发一张「待确认卡片」（参数存会话，前端只拿 token），
 *      真正执行要等用户在卡片上点确认，走 api/ai_action.php。
 *
 * 关于记录：每次调用会把结构化元数据（时间/用户/游客/IP/成功失败/耗时/数据来源/意图）
 * 与**提问原文截断 200 字**写入 ai_logs，供后台「AI 调用记录」审计。
 * 绝不落库：AI 回复全文、system 提示词、API Key。
 *
 * 【重构说明】鉴权/限流/校验/上下文/审计/发卡这些规则原先写在本文件里。
 * 新增流式端点 api/ai_stream.php 后，为避免「改了非流式忘改流式」导致两边规则走偏，
 * 统一收敛到 includes/ai_chat_flow.php 的 aiChatPrepare() / aiChatFinalize()。
 * 本文件现在只负责「一次性把 JSON 结果返回」，与流式端点共用同一套规则。
 *
 * 前端优先走流式端点；本端点保留为降级通道（不支持 fetch 流的老浏览器 / APK 内嵌 WebView）。
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/ai_chat_flow.php';

header('Content-Type: application/json; charset=utf-8');

// 准备：鉴权 + 限流 + 消息校验 + 系统提示词 + 站点上下文（失败时内部已终止请求）
$ctx = aiChatPrepare();

$timeout = 60;

// 发起请求：多 Key 轮换、HTTPS 证书缺失时的降级、cURL / stream 双通道
// 全部收敛在 includes/ai_client.php，其他需要调模型的入口共用同一份实现。
$aiT0 = microtime(true);
$aiResult = aiChatCompletion($ctx['cfg'], $ctx['messages'], $timeout);
$aiElapsedMs = (int)round((microtime(true) - $aiT0) * 1000);

// 后台审计与错误日志都在 aiChatFinalize() 里（logAiCall 落库 + error_log 摘要），
// 保证流式与非流式的审计口径完全一致。
$message   = $aiResult['resp']['choices'][0]['message'] ?? [];
$content   = isset($message['content']) ? (string)$message['content'] : '';
$reasoning = isset($message['reasoning_content']) ? (string)$message['reasoning_content'] : '';

$result = aiChatFinalize($ctx, $content, $reasoning, $aiResult, $aiElapsedMs);

if (isset($result['error'])) {
    jsonError($result['error'], $result['code'] ?? 502);
}

jsonSuccess($result);
