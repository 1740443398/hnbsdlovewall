<?php
/**
 * App 用授权 token 换会话凭证。
 *
 * POST token=xxx
 * 返回 { success, data: { user, csrf_token, session_id } }
 *
 * App 拿到 session_id 后，把它当作 PHPSESSID 写进自己的 CookieJar，
 * 之后的原生请求就与浏览器上的登录是**同一个会话**。
 */
require_once __DIR__ . '/../config/config.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonError('请求方法不允许', 405);
}

$token = trim($_POST['token'] ?? '');
if (!preg_match('/^[0-9a-f]{48}$/', $token)) {
    jsonError('授权码格式不正确', 400);
}

$fs = getFS();
$rows = $fs->find('app_tokens', ['token' => $token]);
$row = $rows[0] ?? null;

if (!$row) {
    jsonError('授权码不存在或已被使用', 401);
}
if (!empty($row['used'])) {
    jsonError('授权码已被使用', 401);
}
if (strtotime($row['expires_at']) < time()) {
    $fs->delete('app_tokens', $row['id']);
    jsonError('授权码已过期，请重新登录', 401);
}

$users = $fs->find('users', ['id' => (int)$row['user_id']]);
$user = $users[0] ?? null;
if (!$user) {
    jsonError('用户不存在', 404);
}

$sid = $row['session_id'] ?? '';
$fs->delete('app_tokens', $row['id']);

jsonSuccess([
    'user' => [
        'id' => (int)$user['id'],
        'qq' => $user['qq'] ?? '',
        'nickname' => $user['nickname'] ?? '',
        'avatar' => $user['avatar'] ?? '',
    ],
    'session_id' => $sid,
    'csrf_token' => generateCSRFToken(),
]);
