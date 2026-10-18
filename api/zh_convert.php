<?php
/**
 * 简繁转换接口（需求一、4：帖子提交组件的简繁双向切换）
 *
 * 为什么转换放服务端：字表在 includes/zh_convert.php（单一真源），
 * 前端另存一份字表会立刻产生两份口径、日后必然不同步。
 * 这里只做一次轻量 POST，返回转换后的文本，前端直接回填文本框。
 *
 * 限流：同一用户/IP 60 秒 60 次（正常编辑远达不到）。
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../includes/zh_convert.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonError('仅支持 POST 请求', 405);
}

if (!verifyCSRFToken((string)($_POST['csrf_token'] ?? ''))) {
    jsonError('页面已过期，请刷新后重试', 403);
}

$user = requireLoginOrGuest();
if (!checkRateLimit(getClientIP(), 'zh_convert', 60, 60)) {
    jsonError('操作过于频繁，请稍后再试', 429);
}

$text = (string)($_POST['text'] ?? '');
if (mb_strlen($text) > 6000) {
    jsonError('文本过长（最多 6000 字）', 400);
}

$to = (string)($_POST['to'] ?? 'trad');
$to = $to === 'simp' ? 'simp' : 'trad';

$converted = lwConvertZh($text, $to);

jsonSuccess([
    'text' => $converted,
    'to' => $to,
    'detected' => lwDetectZhVariant($text),
], $to === 'trad' ? '已转为繁体' : '已转为简体');
