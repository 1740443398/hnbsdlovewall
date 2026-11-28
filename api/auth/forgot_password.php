<?php
require_once __DIR__ . '/../../config/config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonError('请求方法不允许', 405);
}

$csrfToken = $_POST['csrf_token'] ?? '';
if (!verifyCSRFToken($csrfToken)) {
    jsonError('CSRF验证失败', 403);
}

$ip = getClientIP();
if (!checkRateLimit($ip, 'forgot_password', 8, 300)) {
    jsonError('请求过于频繁，请稍后再试', 429);
}

$qq = sanitizeInput($_POST['qq'] ?? '');

if (!isValidQQ($qq)) {
    jsonError('QQ号格式不正确');
}

$fs = getFS();
$user = $fs->findOne('users', ['qq' => $qq]);

// 防账号枚举：无论该QQ是否注册，对外都返回同一文案与同一 200 状态码；
// 仅当账号存在时才真正写入验证码/重置 token 并发送邮件。
$genericMessage = '若该QQ号已注册，我们已发送验证邮件';

if (!$user) {
    // 账号不存在：补一段与后续发信开销量级相近的耗时，弱化时间侧信道差异
    usleep(random_int(200000, 450000));
    jsonSuccess([], $genericMessage);
}

$user = checkBanned($user);

$email = $qq . '@qq.com';

// 生成 6 位数字验证码，15 分钟有效
// 【为什么从 5 分钟放宽到 15 分钟】邮件送达、用户看到、点开链接、手抄 6 位码——
// 全程都可能超过 5 分钟，而重置链接本身有 1 小时有效期。验证码比链接先过期，
// 用户点开链接却被告知"验证码已过期"，这就是"忘记密码用不了"的直接原因。
$code = (string)random_int(100000, 999999);
$expiresAt = time() + 900;

// 同一 QQ 的旧验证码立即作废：只保留最新一枚，避免多枚并存导致"输哪个都提示错误"
foreach ($fs->find('password_reset_codes', ['qq' => $qq]) as $old) {
    if (empty($old['used'])) {
        $fs->update('password_reset_codes', $old['id'], ['used' => 1]);
    }
}

// 写入验证码表（FileStorage 首次 insert 自动建表）
$fs->insert('password_reset_codes', [
    'qq' => $qq,
    'code' => $code,
    'expires_at' => $expiresAt,
    'created_at' => date('Y-m-d H:i:s'),
    'used' => 0,
]);

// 先为本次请求生成一次性重置 token（1 小时有效），供邮件/站内通知携带重置链接
/* 「重新发送验证码」时沿用当前 token：
 * 否则用户点一下"重新发送"，旧 token 被作废、手里那条链接立刻失效，
 * 必须切到新邮件才能继续 —— 白白多绕一圈。 */
$token = '';
$keepToken = sanitizeInput($_POST['keep_token'] ?? '');
$keepQq = sanitizeInput($_POST['keep_qq'] ?? '');
if ($keepToken !== '' && $keepQq !== '' && $keepQq === $qq) {
    $keepRow = $fs->findOne('password_reset_tokens', ['token' => $keepToken]);
    if ($keepRow && (int)$keepRow['user_id'] === (int)$user['id']
        && strtotime((string)$keepRow['expires_at']) >= time()) {
        $token = $keepToken;
        $fs->update('password_reset_tokens', $keepRow['id'], [
            'expires_at' => date('Y-m-d H:i:s', time() + 3600),
        ]);
    }
}
if ($token === '') {
    $token = generateRandomString(64);
    $existing = $fs->find('password_reset_tokens', ['user_id' => $user['id']]);
    foreach ($existing as $t) {
        $fs->delete('password_reset_tokens', $t['id']);
    }
    $fs->insert('password_reset_tokens', [
        'user_id' => $user['id'],
        'token' => $token,
        'expires_at' => date('Y-m-d H:i:s', time() + 3600)
    ]);
}
// 重置页同时需要 token 与 qq 才展示重置表单，故链接中携带两者；
// 验证码也一并带上 —— 用户点开链接即可自动填入，不必从邮件里手抄 6 位数字
// （手抄是重置失败最常见的原因：错一位、少一位、被当成乱码）。
$resetUrl = SITE_URL . '/pages/forgot_password.php?token=' . $token . '&qq=' . $qq . '&code=' . $code;
$resetUrlEsc = htmlspecialchars($resetUrl, ENT_QUOTES | ENT_HTML5, 'UTF-8');

// 尝试发送邮件验证码
$mailOk = false;
if (QQMailer::isConfigured()) {
    // 邮件正文统一由 includes/mail_templates.php 提供（后台「测试邮件」发的是同一份）
    require_once __DIR__ . '/../../includes/mail_templates.php';
    $mail = lwMailBuild('verify_reset', [
        'qq'        => $qq,
        'code'      => $code,
        'reset_url' => $resetUrl,
    ]);
/* 释放 session 锁：发邮件要 1 秒以上，持锁期间同用户的其他请求
 * 会全部排队，多用户并发时整站卡死。 */
if (session_status() === PHP_SESSION_ACTIVE) { session_write_close(); }

    $mailOk = QQMailer::send($email, $mail['subject'], $mail['html']);
}

if (!$mailOk) {
    /* 发信失败：站内通知兜底 + 落失败日志。
     *
     * 注意：站内通知对"忘记密码"场景基本无效——用户此时还没登录，
     * 根本看不到站内消息。所以真正的后路是：管理员在后台看到这条失败日志，
     * 主动联系用户协助重置；页面上也给出了"联系管理员"的引导。
     * 对外文案仍与"账号不存在"完全一致，不泄露账号是否存在。 */
    $err = (string)(class_exists('QQMailer') ? QQMailer::lastError() : 'SMTP 未配置');
    $logDir = dirname(__DIR__, 2) . '/data/logs';
    if (!is_dir($logDir)) { @mkdir($logDir, 0755, true); }
    @file_put_contents(
        $logDir . '/password_reset_mail_fail.log',
        date('Y-m-d H:i:s') . " | qq=" . $qq . " | user_id=" . (int)$user['id']
            . " | ip=" . $ip . " | reason=" . str_replace(["\r", "\n", "|"], ['', '', '/'], $err) . "\n",
        FILE_APPEND | LOCK_EX
    );
    error_log('[forgot_password] mail delivery failed: ' . $err);

    $fs->insert('notifications', [
        'user_id' => $user['id'],
        'type' => 'password_reset',
        'content' => '密码重置链接：' . $resetUrl . '，验证码：' . $code,
        'is_read' => false
    ]);
    jsonSuccess([], $genericMessage);
}

// 邮件发送成功（对外文案与账号不存在时保持一致，避免枚举）
jsonSuccess([], $genericMessage);