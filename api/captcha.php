<?php
require_once __DIR__ . '/../config/config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    jsonError('请求方法不允许', 405);
}

// 验证码仅用于表单安全校验，GET 请求太重开限流；但防御直接抓取题目做 OCR
$ip = getClientIP();
if (!checkRateLimit($ip, 'captcha', 20, 60)) {
    jsonError('请求过于频繁，请稍后再试', 429);
}

$scene = preg_match('/^[a-z_]+$/', $_POST['scene'] ?? '') ? $_POST['scene'] : ($_GET['scene'] ?? 'default');
// 白名单场景，防止任意 session key
$allowedScenes = ['login', 'register', 'default'];
if (!in_array($scene, $allowedScenes, true)) {
    $scene = 'default';
}

$challenge = generateCaptcha($scene);
jsonSuccess($challenge);