<?php
/**
 * 后台：网盘中转配置
 *
 * 让开源版用户可以自己填小飞机网盘的账号，
 * 不用改代码、不用懂 PHP。
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../includes/filehelper.php';

$admin = requireAdmin();
$admin = checkBanned($admin);

$cfgFile = __DIR__ . '/../config/feijipan_config.php';
$msg = '';
$msgType = 'ok';

// 一键清理待删队列
if (($_GET['flush'] ?? '') === '1') {
    if (!verifyCSRFToken((string)($_GET['csrf_token'] ?? ''))) {
        $msg = 'CSRF 校验失败';
        $msgType = 'err';
    } else {
        $r = lwFhPendingFlush(true);
        if ($r['ok'] > 0) {
            $msg = "已清理 {$r['ok']} 个文件";
        } elseif ($r['skipped'] > 0) {
            $msg = '网盘删除队列被锁住了（「任务进行中」），这是网盘服务端的问题，'
                 . '稍后重试或联系网盘客服。' . "队列里还有 {$r['pending']} 个文件。";
            $msgType = 'err';
        } elseif ($r['fail'] > 0) {
            $msg = "清理失败 {$r['fail']} 个";
            $msgType = 'err';
        } else {
            $msg = '队列是空的，没有需要清理的文件';
        }
    }
}

// 保存
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        $msg = 'CSRF 校验失败，请刷新重试';
        $msgType = 'err';
    } else {
        $enabled = !empty($_POST['enabled']);
        $account = trim((string)($_POST['account'] ?? ''));
        $password = (string)($_POST['password'] ?? '');
        $userId = (int)($_POST['user_id'] ?? 0);
        $folderId = trim((string)($_POST['folder_id'] ?? '0'));
        if ($folderId === '') {
            $folderId = '0';
        }

        if ($account === '' || $password === '') {
            $msg = '账号和密码都必须填写';
            $msgType = 'err';
        } else {
            $php = "<?php\n"
                 . "// 小飞机网盘配置（含账密，请勿提交到公开仓库）\n"
                 . "return [\n"
                 . "    'enabled'  => " . ($enabled ? 'true' : 'false') . ",\n"
                 . "    'account'  => " . var_export($account, true) . ",\n"
                 . "    'password' => " . var_export($password, true) . ",\n"
                 . "    'user_id'  => " . $userId . ",\n"
                 . "    'folder_id' => " . var_export($folderId, true) . ",\n"
                 . "];\n";

            if (@file_put_contents($cfgFile, $php, LOCK_EX) !== false) {
                @chmod($cfgFile, 0600);
                $msg = '已保存';
            } else {
                $msg = '保存失败：config 目录不可写，请检查权限';
                $msgType = 'err';
            }
        }
    }
}

// 读取当前配置
$cur = ['enabled' => false, 'account' => '', 'password' => '', 'user_id' => 0, 'folder_id' => '0'];
if (is_file($cfgFile)) {
    try {
        $c = require $cfgFile;
        if (is_array($c)) {
            $cur = array_merge($cur, $c);
        }
    } catch (Throwable $e) {
    }
}

// 网盘积压情况
$pendingIds = lwFhPendingRead();
$pendingCount = count($pendingIds);

// 读配置判断是否启用
$cloudOn = !empty($cur['enabled']) && !empty($cur['account']);
$quota = null;
$quotaErr = '';
if ($cloudOn && isset($_GET['quota'])) {
    require_once __DIR__ . '/../includes/feijipan.php';
    $fpq = new LWFeijiPan();
    if ($fpq->login()) {
        $quota = $fpq->quota();
    } else {
        $quotaErr = '网盘登录失败，读不到容量';
    }
}

// 测试连接
$testResult = null;
if (isset($_GET['test'])) {
    require_once __DIR__ . '/../includes/feijipan.php';
    $fp = new LWFeijiPan();
    if (!$fp->isConfigured()) {
        $testResult = ['ok' => false, 'msg' => '还没配置账号'];
    } else {
        $t0 = microtime(true);
        $ok = $fp->login();
        $ms = round((microtime(true) - $t0) * 1000);
        if ($ok) {
            // 顺便把容量带出来 —— 这是最容易踩的坑
            $q = $fp->quota();
            $freeMb = round($q['free'] / 1048576, 2);
            $totalMb = round($q['total'] / 1048576, 2);
            $usedMb = round($q['used'] / 1048576, 2);
            $testResult = [
                'ok' => true,
                'msg' => "登录成功（{$ms}ms）· 容量 {$usedMb}MB / {$totalMb}MB，剩余 {$freeMb}MB",
            ];
        } else {
            $testResult = ['ok' => false, 'msg' => '登录失败，请检查账号密码'];
        }
    }
}
?>
<?php
/* 接入统一后台布局（侧边栏 + 顶栏 + 主题变量）。
 *
 * 原来这些页面自带一份完整 <!DOCTYPE html> 和自己的配色，
 * 跟 layout.php 的视觉体系对不上 —— 没有侧边栏、没有顶栏、
 * 暗色模式也不跟随。现在统一走 adminHeader / adminFooter。
 */
require_once __DIR__ . '/layout.php';
$csrfToken = generateCSRFToken();
adminHeader('网盘中转配置', $admin, $csrfToken);
?>

<style>
/* 本页特有样式（表格 / 卡片细节）。
 * 颜色优先用 layout.php 的 CSS 变量，跟随明暗主题。 */
*{box-sizing:border-box}
body{margin:0;background:var(--bg);color:var(--text);font-family:-apple-system,BlinkMacSystemFont,"PingFang SC","Microsoft YaHei",sans-serif;font-size:14px;line-height:1.65}
.wrap{max-width:680px;margin:0 auto;padding:24px 18px 60px}
h1{font-size:20px;margin:0 0 4px}
.sub{color:var(--text-secondary);font-size:13px;margin:0 0 22px}
.back{display:inline-block;margin-bottom:16px;color:var(--text-secondary);text-decoration:none;font-size:13px}
.back:hover{color:var(--primary)}
.card{background:var(--card-bg);border:1px solid var(--border);border-radius:12px;padding:22px 20px;margin-bottom:16px}
.field{margin-bottom:18px}
.field:last-child{margin-bottom:0}
label{display:block;font-size:13px;font-weight:600;margin-bottom:5px}
.hint{font-size:12px;color:var(--text-muted);margin:0 0 7px}
input[type=text],input[type=password],input[type=number]{
    width:100%;padding:10px 12px;border:1px solid var(--border);border-radius:9px;
    font-size:14px;font-family:inherit;color:var(--text);background:var(--bg-secondary);outline:none;
    transition:border-color .15s;
}
input:focus{border-color:var(--primary);background:var(--card-bg)}
.switch{display:flex;align-items:center;gap:9px}
.switch input{width:18px;height:18px;margin:0;cursor:pointer}
.switch label{margin:0;font-weight:500;cursor:pointer}
.actions{display:flex;gap:9px;margin-top:20px}
.btn{padding:10px 20px;border:0;border-radius:9px;font-size:14px;font-weight:600;cursor:pointer;font-family:inherit;text-decoration:none;display:inline-flex;align-items:center}
.btn-primary{background:linear-gradient(135deg,var(--info),var(--primary));color:var(--card-bg)}
.btn-ghost{background:var(--border-light);color:var(--text-secondary)}
.btn:hover{opacity:.92}
.msg{padding:11px 14px;border-radius:9px;font-size:13px;margin-bottom:16px}
.msg.ok{background:var(--success-light);color:var(--success);border:1px solid var(--success-light)}
.msg.err{background:var(--danger-light);color:var(--danger);border:1px solid var(--danger-light)}
.note{background:var(--bg-secondary);border-left:3px solid var(--info);padding:12px 14px;border-radius:0 8px 8px 0;font-size:12.5px;color:var(--text-secondary);margin-top:16px}
.note b{color:var(--text)}
.stat-card{padding:20px 20px 18px}
.stat-head{display:flex;align-items:center;justify-content:space-between;margin-bottom:16px}
.stat-head h2{font-size:15px;margin:0}
.mini-btn{font-size:12px;color:var(--primary);text-decoration:none;padding:4px 10px;border-radius:7px;background:#F0F4FA}
.mini-btn:hover{background:#E4ECF8}
.stat-grid{display:flex;gap:26px;flex-wrap:wrap;margin-bottom:16px}
.stat-item{flex:1;min-width:96px}
.stat-label{font-size:12px;color:var(--text-muted);margin-bottom:4px}
.stat-value{font-size:22px;font-weight:700;color:var(--text);line-height:1.2}
.stat-value .unit{font-size:12px;font-weight:400;color:var(--text-muted)}
.stat-value.warn{color:var(--warning)}
.stat-value.good{color:var(--success)}
.bar-wrap{height:7px;background:#EDF1F7;border-radius:99px;overflow:hidden;margin-bottom:6px}
.bar-fill{height:100%;background:linear-gradient(90deg,var(--info),var(--primary));border-radius:99px;transition:width .3s}
.bar-tip{font-size:11.5px;color:var(--text-muted);margin-bottom:14px}
.stat-ok{font-size:13px;color:var(--success);margin:0;padding-top:4px}
.stat-err{font-size:12.5px;color:var(--danger);margin:10px 0 0}
.pending-box{margin-top:14px;padding-top:14px;border-top:1px solid var(--bg-secondary)}
.pending-tip{font-size:13px;color:var(--text-secondary);margin:0 0 10px;line-height:1.6}
.pending-tip b{color:var(--warning)}
.pending-note{font-size:11.5px;color:var(--text-muted);margin:10px 0 0;line-height:1.6}
.warn{background:var(--warning-light);border-left:3px solid #F2994A;padding:12px 14px;border-radius:0 8px 8px 0;font-size:12.5px;color:#8A5A1F;margin-top:12px}
</style>

<div class="wrap">
    <a class="back" href="/admin/dashboard.php">← 返回仪表盘</a>
    <h1>网盘中转配置</h1>
    <p class="sub">配置小飞机网盘账号后，文件传输助手就能中转超过 20MB 的大文件。</p>

    <?php if ($msg !== ''): ?>
        <div class="msg <?= $msgType ?>"><?= htmlspecialchars($msg) ?></div>
    <?php endif; ?>

    <?php if ($testResult !== null): ?>
        <div class="msg <?= $testResult['ok'] ? 'ok' : 'err' ?>">
            <?= htmlspecialchars($testResult['msg']) ?>
        </div>
    <?php endif; ?>

    <!-- ============ 网盘积压情况 ============ -->
    <?php if ($cloudOn): ?>
    <div class="card stat-card">
        <div class="stat-head">
            <h2>网盘中转状态</h2>
            <a class="mini-btn" href="?quota=1">刷新容量</a>
        </div>

        <div class="stat-grid">
            <div class="stat-item">
                <div class="stat-label">待删文件</div>
                <div class="stat-value <?= $pendingCount > 0 ? 'warn' : '' ?>">
                    <?= $pendingCount ?> <span class="unit">个</span>
                </div>
            </div>
            <?php if ($quota !== null): ?>
            <div class="stat-item">
                <div class="stat-label">网盘已用</div>
                <div class="stat-value">
                    <?= round($quota['used'] / 1073741824, 2) ?> <span class="unit">GB</span>
                </div>
            </div>
            <div class="stat-item">
                <div class="stat-label">剩余空间</div>
                <div class="stat-value <?= $quota['free'] < 1073741824 ? 'warn' : 'good' ?>">
                    <?= round($quota['free'] / 1073741824, 2) ?> <span class="unit">GB</span>
                </div>
            </div>
            <?php endif; ?>
        </div>

        <?php if ($quota !== null): ?>
        <div class="bar-wrap">
            <div class="bar-fill" style="width: <?= $quota['total'] > 0 ? round($quota['used'] / $quota['total'] * 100, 1) : 0 ?>%"></div>
        </div>
        <div class="bar-tip">
            总容量 <?= round($quota['total'] / 1073741824, 2) ?> GB
        </div>
        <?php endif; ?>

        <?php if ($quotaErr !== ''): ?>
        <p class="stat-err"><?= htmlspecialchars($quotaErr) ?></p>
        <?php endif; ?>

        <?php if ($pendingCount > 0): ?>
        <div class="pending-box">
            <p class="pending-tip">
                有 <b><?= $pendingCount ?></b> 个文件传完后没能从网盘删掉。
                这些文件会占着网盘空间，点下面按钮重试清理。
            </p>
            <a class="btn btn-ghost"
               href="?flush=1&csrf_token=<?= htmlspecialchars(generateCSRFToken()) ?>"
               onclick="return confirm('确定要重试清理这 <?= $pendingCount ?> 个文件吗？')">
               立即清理
            </a>
            <p class="pending-note">
                如果提示「任务进行中」，说明网盘的删除队列被一个卡住的任务锁住了，
                这是网盘服务端的问题，等它恢复后重试即可。文件引用已保存，不会丢。
            </p>
        </div>
        <?php else: ?>
        <p class="stat-ok">网盘里没有积压文件 ✓</p>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <form method="post" class="card">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(generateCSRFToken()) ?>">

        <div class="field">
            <div class="switch">
                <input type="checkbox" id="enabled" name="enabled" value="1"
                    <?= !empty($cur['enabled']) ? 'checked' : '' ?>>
                <label for="enabled">启用网盘中转</label>
            </div>
            <p class="hint" style="margin-top:6px">不开也能用，只是超过 20MB 的文件传不了。</p>
        </div>

        <div class="field">
            <label for="account">账号（手机号）</label>
            <input type="text" id="account" name="account"
                   value="<?= htmlspecialchars((string)$cur['account']) ?>"
                   placeholder="填小飞机网盘的登录手机号" autocomplete="off">
        </div>

        <div class="field">
            <label for="password">密码</label>
            <input type="password" id="password" name="password"
                   value="<?= htmlspecialchars((string)$cur['password']) ?>"
                   placeholder="登录密码" autocomplete="new-password">
        </div>

        <div class="field">
            <label for="user_id">用户 ID</label>
            <p class="hint">登录后在网盘「账号设置」里能看到，用于拼上传路径。不知道就留 0。</p>
            <input type="number" id="user_id" name="user_id"
                   value="<?= (int)$cur['user_id'] ?>" placeholder="0">
        </div>

        <div class="field">
            <label for="folder_id">目标目录 ID</label>
            <p class="hint">中转文件只能存到这个目录，留空则使用默认的「服务器文件中转」（1989982846）。</p>
            <input type="text" id="folder_id" name="folder_id"
                   value="<?= htmlspecialchars((string)$cur['folder_id']) ?>" placeholder="1989982846">
        </div>

        <div class="actions">
            <button type="submit" class="btn btn-primary">保存</button>
            <a class="btn btn-ghost" href="?test=1">测试连接</a>
        </div>
    </form>

    <div class="note">
        <b>这个账号用来做什么？</b><br>
        超过 20MB 的文件会先上传到这个小飞机网盘账号，再给对方一个下载入口。
        文件传完会调用删除接口清理，不在网盘长期留存。
    </div>

    <div class="warn">
        <b>安全提示</b><br>
        密码会明文存到 <code>config/feijipan_config.php</code>，该文件已被 .gitignore 排除。
        建议单独注册一个小号专用，不要用主账号。
    </div>
</div>
<?php adminFooter(); ?>

