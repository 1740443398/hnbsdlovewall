<?php
require_once __DIR__ . '/../../config/config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonError('请求方法不允许', 405);
}

$csrfToken = $_POST['csrf_token'] ?? '';
if (!verifyCSRFToken($csrfToken)) {
    jsonError('CSRF验证失败', 403);
}

$_SESSION = [];
session_destroy();

if (isset($_COOKIE['remember_token'])) {
    $token = $_COOKIE['remember_token'];
    $fs = getFS();
    $tokenHash = hash('sha256', $token);
    $tokens = $fs->find('remember_tokens', ['token_hash' => $tokenHash]);
    foreach ($tokens as $t) {
        $fs->delete('remember_tokens', $t['id']);
    }
    $secure = defined('IS_SECURE') ? IS_SECURE : false;
    setcookie('remember_token', '', time() - 3600, '/', '', $secure, true);
}

$secure = defined('IS_SECURE') ? IS_SECURE : false;
setcookie(session_name(), '', time() - 3600, '/', '', $secure, true);

jsonSuccess([], '已安全退出登录');