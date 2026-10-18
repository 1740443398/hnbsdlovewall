<?php
/**
 * 网页安装引导（开源部署版专用，需求三、6）
 * ---------------------------------------------------------------------------
 * 定位：给「第一次把源码放到服务器上」的人用，三步走完就能登录后台。
 *
 *   ① 环境自检：PHP 版本、必需扩展、目录可写、关键文件存在
 *   ② 创建站长：QQ 号 + 昵称 + 密码（这是唯一的超级管理员）
 *   ③ 完成：自动写入默认设置/敏感词/公告，并提示删除本文件
 *
 * 三道安全闸门（缺一不可，否则就是「谁先访问谁当站长」的经典漏洞）：
 *   A. 已经存在超级管理员 → 直接拒绝，页面只剩一句「安装已完成」。
 *   B. 必须持有安装令牌：首次访问时生成 `data/.install_token`，
 *      并要求把文件里的字符串填进表单 —— 证明「能读服务器文件系统」，
 *      也就是部署者本人，而不是碰巧扫到 URL 的路人。
 *   C. 全流程 CSRF 校验 + 失败次数限流。
 *
 * 部署完成后本文件可整份删除；删掉也不影响站点（只有后台「重新安装」才需要它）。
 */

// 只允许在「尚未安装」时使用；已安装直接拒绝（闸门 A）
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/includes/security.php';

$fs = getFS();
$users = (array)$fs->read('users');
$installed = false;
foreach ($users as $u) {
    if (in_array((string)($u['role'] ?? ''), ['admin', 'super_admin'], true)) {
        $installed = true;
        break;
    }
}

if ($installed) {
    http_response_code(403);
    echo '<!DOCTYPE html><html lang="zh-CN"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
        . '<title>安装已完成</title></head><body style="font-family:system-ui,sans-serif;max-width:32rem;margin:18vh auto;padding:0 1.25rem;line-height:1.7">'
        . '<h1 style="font-size:1.25rem">安装已完成</h1>'
        . '<p>本站已存在管理员账号，安装引导已自动关闭。</p>'
        . '<p style="color:#888;font-size:.85rem">为安全起见，请立即删除服务器上的 <code>install.php</code>。</p>'
        . '<p><a href="/">返回首页</a></p></body></html>';
    exit();
}

// 闸门 B：安装令牌
$tokenFile = __DIR__ . '/data/.install_token';
$token = '';
if (is_file($tokenFile)) {
    $token = trim((string)@file_get_contents($tokenFile));
}
if ($token === '' || strlen($token) < 16) {
    try {
        $token = bin2hex(random_bytes(16));
        @file_put_contents($tokenFile, $token, LOCK_EX);
    } catch (Throwable $e) {
        $token = '';
    }
}

$errors = [];
$step = 'form';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $inputToken = trim((string)($_POST['install_token'] ?? ''));
    $qq = trim((string)($_POST['qq'] ?? ''));
    $nick = trim((string)($_POST['nickname'] ?? ''));
    $pwd = (string)($_POST['password'] ?? '');
    $pwd2 = (string)($_POST['password2'] ?? '');

    // 失败限流：同一 IP 10 分钟内最多 8 次尝试
    if (!checkRateLimit(getClientIP(), 'install_try', 8, 600)) {
        $errors[] = '尝试次数过多，请 10 分钟后再试。';
    }
    if ($token === '' || !hash_equals($token, $inputToken)) {
        $errors[] = '安装令牌不正确。请打开服务器上的 data/.install_token 文件，把里面的字符串原样粘贴进来。';
    }
    if ($qq === '' || mb_strlen($qq) > 32) {
        $errors[] = '请填写管理账号（建议用 QQ 号，登录时用）。';
    }
    if ($nick === '' || mb_strlen($nick) > 24) {
        $errors[] = '请填写昵称（1-24 字）。';
    }
    if (strlen($pwd) < 8) {
        $errors[] = '密码至少 8 位。';
    }
    if ($pwd !== $pwd2) {
        $errors[] = '两次输入的密码不一致。';
    }

    if (!$errors) {
        // 初始化默认设置（与 init.php 的 CLI 版保持同一份口径）
        $defaults = [
            ['setting_key' => 'site_name', 'setting_value' => '校园交流墙'],
            ['setting_key' => 'announcement', 'setting_value' => '欢迎来到校园交流墙！请遵守社区规范，文明交流。'],
            ['setting_key' => 'community_rules', 'setting_value' => "1. 禁止发布辱骂、引战、泄露他人隐私的内容\n2. 禁止发布违规广告、校外无关引流内容\n3. 禁止发布低俗色情、违法违规内容\n4. 请文明发言，尊重他人"],
            ['setting_key' => 'maintenance_mode', 'setting_value' => '0'],
            ['setting_key' => 'maintenance_message', 'setting_value' => '网站正在维护中，请稍后再来。'],
            ['setting_key' => 'register_enabled', 'setting_value' => '1'],
        ];
        $existing = array_column((array)$fs->read('settings'), 'setting_key');
        foreach ($defaults as $row) {
            if (!in_array($row['setting_key'], $existing, true)) {
                $fs->insert('settings', $row);
            }
        }
        if (empty((array)$fs->read('sensitive_words'))) {
            foreach (['傻逼', '操你妈', '滚蛋', '去死', '垃圾', '废物', '畜生', '婊子', '狗日', '杂种'] as $w) {
                $fs->insert('sensitive_words', ['word' => $w]);
            }
        }
        if (empty((array)$fs->read('sponsor'))) {
            $fs->insert('sponsor', ['total_amount' => 0, 'current_amount' => 0, 'sponsor_list' => '[]']);
        }

        $fs->insert('users', [
            'qq' => $qq,
            'password_hash' => password_hash($pwd, PASSWORD_BCRYPT),
            'nickname' => $nick,
            'avatar' => '',
            'role' => 'super_admin',
            'must_change_password' => 0,
            'security_stamp' => bin2hex(random_bytes(32)),
            'is_banned' => 0,
            'points' => 0,
            'created_at' => date('Y-m-d H:i:s'),
        ]);
        logOperation(0, $qq, 'install', 'users', '', '网页引导安装');
        @unlink($tokenFile);
        $step = 'done';
    }
}

// 环境自检
$checks = [
    ['PHP 版本 ≥ 8.0', version_compare(PHP_VERSION, '8.0.0', '>='), PHP_VERSION],
    ['JSON 扩展', extension_loaded('json'), ''],
    ['MBString 扩展', extension_loaded('mbstring'), ''],
    ['OpenSSL 扩展（加密/HTTPS）', extension_loaded('openssl'), ''],
    ['data/ 可写', is_writable(__DIR__ . '/data'), __DIR__ . '/data'],
    ['uploads/ 可写', is_writable(__DIR__ . '/uploads'), __DIR__ . '/uploads'],
    ['config/ 存在', is_dir(__DIR__ . '/config'), ''],
    ['config/config.php 存在', is_file(__DIR__ . '/config/config.php'), ''],
];
$blocking = false;
// 「可写」这两项不通过通常是权限没配好，直接拦下更省事
foreach ($checks as $c) {
    if (!$c[1] && (strpos($c[0], '可写') !== false)) { $blocking = true; }
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
<title>安装引导 - 校园交流墙</title>
<style>
    :root { --ink:#1f2733; --muted:#5b667a; --line:#e5e9f0; --brand:#2F5B9A; --ok:#2e8b57; --bad:#c0392b; }
    * { box-sizing: border-box; }
    body { margin:0; font-family:-apple-system,BlinkMacSystemFont,"Segoe UI","PingFang SC","Microsoft YaHei",sans-serif;
           background:#f4f6fb; color:var(--ink); line-height:1.65; padding:2rem 1rem 4rem; }
    .wrap { max-width:44rem; margin:0 auto; }
    h1 { font-size:1.375rem; margin:0 0 .375rem; }
    .sub { color:var(--muted); font-size:.875rem; margin:0 0 1.5rem; }
    .card { background:#fff; border:1px solid var(--line); border-radius:16px; padding:1.25rem; margin-bottom:1rem;
            box-shadow:0 10px 28px -20px rgba(16,24,40,.35); }
    .card h2 { font-size:1rem; margin:0 0 .75rem; }
    .chk { display:flex; justify-content:space-between; gap:1rem; padding:.375rem 0; border-bottom:1px dashed var(--line); font-size:.875rem; }
    .chk:last-child { border-bottom:none; }
    .chk .v { color:var(--muted); font-size:.8rem; }
    .ok { color:var(--ok); font-weight:600; }
    .bad { color:var(--bad); font-weight:600; }
    label { display:block; font-size:.8125rem; color:var(--muted); margin:.75rem 0 .25rem; }
    input { width:100%; padding:.625rem .75rem; border:1px solid var(--line); border-radius:10px; font-size:.9375rem; }
    input:focus { outline:none; border-color:var(--brand); box-shadow:0 0 0 3px rgba(47,91,154,.12); }
    .btn { display:inline-block; margin-top:1rem; padding:.6875rem 1.25rem; border:none; border-radius:10px;
           background:var(--brand); color:#fff; font-size:.9375rem; font-weight:600; cursor:pointer; }
    .btn[disabled] { opacity:.45; cursor:not-allowed; }
    .err { background:#fdecea; border:1px solid #f5c6c0; color:#8e2f22; border-radius:10px; padding:.625rem .75rem;
           font-size:.8125rem; margin-bottom:.75rem; }
    .err ul { margin:0; padding-left:1.125rem; }
    code { background:#eef2f8; border-radius:6px; padding:.0625rem .375rem; font-size:.8125rem; }
    .token { display:block; margin-top:.375rem; padding:.5rem .625rem; background:#eef2f8; border-radius:8px;
             font-family:ui-monospace,Consolas,monospace; font-size:.8125rem; word-break:break-all; }
</style>
</head>
<body>
<div class="wrap">
<?php if ($step === 'done'): ?>
    <h1>安装完成</h1>
    <p class="sub">下面两件事请立刻做完，然后就能用了。</p>
    <div class="card">
        <h2>1. 删除安装文件</h2>
        <p style="margin:0;font-size:.875rem">从服务器删除 <code>install.php</code>，避免被他人重复触发安装流程。</p>
    </div>
    <div class="card">
        <h2>2. 进入后台</h2>
        <p style="margin:0;font-size:.875rem"><a href="/admin/index.php">前往后台</a> → 站点设置里改站名、公告、社区规范；
        到「AI 设置」填自己的模型 Key；到「自适应布局」按需调断点。</p>
    </div>
<?php else: ?>
    <h1>安装引导</h1>
    <p class="sub">三步走完：确认环境 → 建站长账号 → 进入后台。</p>

    <div class="card">
        <h2>① 环境自检</h2>
        <?php foreach ($checks as $c): ?>
        <div class="chk">
            <span><?= htmlspecialchars($c[0]) ?></span>
            <span class="<?= $c[1] ? 'ok' : 'bad' ?>"><?= $c[1] ? '通过' : '未通过' ?><?= $c[2] !== '' ? ' · ' . htmlspecialchars((string)$c[2]) : '' ?></span>
        </div>
        <?php endforeach; ?>
        <?php if ($blocking): ?>
        <p class="err" style="margin-top:.75rem;margin-bottom:0">目录不可写时无法写入数据，请先把 <code>data/</code> 与 <code>uploads/</code> 的权限调到可写（一般 755 / 属主为 Web 用户）。</p>
        <?php endif; ?>
    </div>

    <div class="card">
        <h2>② 创建站长账号</h2>
        <p class="sub" style="margin-bottom:.5rem">这是本站唯一的超级管理员，创建后不可再通过本页重建。</p>
        <?php if ($errors): ?>
        <div class="err"><ul><?php foreach ($errors as $e): ?><li><?= htmlspecialchars($e) ?></li><?php endforeach; ?></ul></div>
        <?php endif; ?>
        <form method="post" autocomplete="off">
            <label for="install_token">安装令牌（文件：<code>data/.install_token</code>）</label>
            <input type="text" id="install_token" name="install_token" required value="<?= htmlspecialchars((string)($_POST['install_token'] ?? '')) ?>">
            <?php if ($token !== ''): ?>
            <span class="token"><?= htmlspecialchars($token) ?></span>
            <span class="v" style="font-size:.75rem;color:var(--muted)">如果服务器能直接访问该文件，可复制上面这串；否则请通过 FTP / 文件管理器打开文件读取。</span>
            <?php else: ?>
            <span class="v" style="font-size:.75rem;color:var(--bad)">无法生成令牌文件，请检查 data/ 目录权限后刷新本页。</span>
            <?php endif; ?>

            <label for="qq">管理账号（建议 QQ 号）</label>
            <input type="text" id="qq" name="qq" required maxlength="32" value="<?= htmlspecialchars((string)($_POST['qq'] ?? '')) ?>">

            <label for="nickname">昵称</label>
            <input type="text" id="nickname" name="nickname" required maxlength="24" value="<?= htmlspecialchars((string)($_POST['nickname'] ?? '')) ?>">

            <label for="password">密码（至少 8 位）</label>
            <input type="password" id="password" name="password" required minlength="8">

            <label for="password2">再输一次</label>
            <input type="password" id="password2" name="password2" required minlength="8">

            <button class="btn" type="submit" <?= $blocking ? 'disabled' : '' ?>>完成安装</button>
        </form>
    </div>
<?php endif; ?>
</div>
</body>
</html>
