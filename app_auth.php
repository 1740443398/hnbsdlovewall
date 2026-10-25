<?php
/**
 * App 授权（浏览器登录 -> 把会话交给 App）。
 *
 * 【为什么不能只发一个 token】
 * 站点的登录态存在 PHP session 里（$_SESSION['user_id']），而 session 是靠
 * PHPSESSID cookie 认人的。App 的 OkHttp 请求如果不带这个 cookie，就是游客。
 *
 * 所以本页在用户已登录时，做两件事：
 *   1. 生成一次性 token 存进 app_tokens（App 稍后用它换信息）
 *   2. **把当前的 session_id 也记进 token 行**，App 拿到后直接作为
 *      PHPSESSID 使用 —— 这样 App 的请求立刻就是同一个登录会话。
 *
 * 安全说明：
 *   - token 一次性、10 分钟过期、换取后立即删除
 *   - session_id 不是密码，且只通过 HTTPS 传输
 *   - 换取时会把 session 重新生成为 App 专用（见 api/app_auth.php）
 */
require_once __DIR__ . '/config/config.php';

header('Content-Type: text/html; charset=utf-8');

$code = trim($_GET['code'] ?? '');
$device = trim($_GET['device'] ?? '');

if (!preg_match('/^[0-9a-f]{32}$/', $code)) {
    $code = preg_replace('/[^0-9a-f]/', '', $_COOKIE['lw_pair_code'] ?? '');
    $device = $_COOKIE['lw_pair_device'] ?? '';
}

if (!preg_match('/^[0-9a-f]{32}$/', $code)) {
    echo '<!doctype html><meta charset="utf-8"><title>授权</title>';
    echo '<div style="font-family:system-ui;padding:40px 20px;text-align:center">';
    echo '<h3>授权参数无效</h3><p>请从 App 内重新发起登录。</p></div>';
    exit;
}

setcookie('lw_pair_code', $code, time() + 600, '/');
setcookie('lw_pair_device', $device, time() + 600, '/');
// 页面本身带了一次性配对码并会写 Cookie：绝不进任何缓存
lwMarkSessionCookieWritten();
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

$user = getCurrentUser();

if (!$user) {
    $loginUrl = '/pages/login.php';
    echo '<!doctype html><html><head><meta charset="utf-8">';
    echo '<meta name="viewport" content="width=device-width,initial-scale=1">';
    echo '<title>正在跳转登录…</title></head><body style="font-family:system-ui;padding:40px 20px;text-align:center">';
    echo '<p>正在前往登录页…</p>';
    echo '<p><a href="' . htmlspecialchars($loginUrl) . '">如果没有自动跳转，点这里</a></p>';
    echo '<script>location.replace(' . json_encode($loginUrl) . ');</script>';
    echo '</body></html>';
    exit;
}

// 已登录：签发 token + 记录当前 session_id
$fs = getFS();
$token = bin2hex(random_bytes(24));
$sid = session_id();

$fs->insert('app_tokens', [
    'token' => $token,
    'code' => $code,
    'device_id' => $device,
    'user_id' => (int)$user['id'],
    'session_id' => $sid,
    'expires_at' => date('Y-m-d H:i:s', time() + 600),
    'used' => 0,
]);

setcookie('lw_pair_code', '', time() - 3600, '/');
setcookie('lw_pair_device', '', time() - 3600, '/');

$deep = 'lovewall://auth?token=' . urlencode($token) . '&code=' . urlencode($code);

echo '<!doctype html><html><head><meta charset="utf-8">';
echo '<meta name="viewport" content="width=device-width,initial-scale=1">';
echo '<title>登录成功</title></head>';
echo '<body style="font-family:system-ui;padding:40px 20px;text-align:center">';
echo '<h3 style="color:#2F5B9A">✓ 已登录「' . htmlspecialchars($user['nickname'] ?? '用户') . '」</h3>';
echo '<p>正在返回 App…</p>';
echo '<script>';
echo 'setTimeout(function(){location.href=' . json_encode($deep) . ';},300);';
echo 'setTimeout(function(){var f=document.getElementById("fb");if(f)f.style.display="block";},2500);';
echo '</script>';
echo '<div id="fb" style="display:none;margin-top:24px">';
echo '<p style="color:#C25B2A">没能自动返回 App？点下面按钮：</p>';
echo '<p><a href="' . htmlspecialchars($deep) . '" style="display:inline-block;padding:12px 24px;'
    . 'background:#2F5B9A;color:#fff;border-radius:8px;text-decoration:none">返回 App</a></p>';
echo '</div>';
echo '</body></html>';
