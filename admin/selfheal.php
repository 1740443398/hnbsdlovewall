<?php
/**
 * 后台：自恢复守护监控
 *
 * 把 includes/selfheal.php 的熔断状态、错误率、磁盘水位、
 * 数据自愈事件、保护模式都摊开给管理员看，并提供一键重置。
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/security.php';

$admin = requireAdmin();
$admin = checkBanned($admin);

$msg = '';
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        $msg = 'CSRF 校验失败';
    } else {
        $act = (string)($_POST['act'] ?? '');
        if ($act === 'reset') {
            LWSelfHeal::reset();
            $msg = '已重置自愈状态';
        } elseif ($act === 'clear_log') {
            @unlink(__DIR__ . '/../data/_selfheal_log.jsonl');
            $msg = '已清空日志';
        }
        header('Location: /admin/selfheal.php?m=' . urlencode($msg));
        exit;
    }
}
$msg = (string)($_GET['m'] ?? $msg);

$snap = LWSelfHeal::snapshot();
$safe = LWSelfHeal::inSafeMode();
$diskOk = $snap['_disk_ok'] ?? true;
$diskFree = $snap['_disk_free_mb'] ?? 0;

// 读事件日志（最后 60 条）
$logPath = __DIR__ . '/../data/_selfheal_log.jsonl';
$logs = [];
if (is_file($logPath)) {
    $lines = @file($logPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
    $lines = array_slice($lines, -60);
    foreach (array_reverse($lines) as $l) {
        $j = json_decode($l, true);
        if (is_array($j)) $logs[] = $j;
    }
}

// 熔断器
$breakers = $snap['breakers'] ?? [];
$now = time();
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
adminHeader('自恢复守护', $admin, $csrfToken);
?>

<style>
/* 本页特有样式（表格 / 卡片细节）。
 * 颜色优先用 layout.php 的 CSS 变量，跟随明暗主题。 */
*{box-sizing:border-box}
body{margin:0;background:#F5F7FA;color:#1F2733;font-family:-apple-system,BlinkMacSystemFont,"PingFang SC","Microsoft YaHei",sans-serif;font-size:14px;line-height:1.65}
.wrap{max-width:900px;margin:0 auto;padding:24px 18px 60px}
a.back{display:inline-block;margin-bottom:16px;color:#5B667A;text-decoration:none;font-size:13px}
h1{font-size:20px;margin:0 0 4px}
.sub{color:#6B7684;font-size:13px;margin:0 0 22px}
.card{background:#fff;border:1px solid #E4E9F0;border-radius:12px;padding:20px;margin-bottom:16px}
.card h2{font-size:15px;margin:0 0 14px;display:flex;align-items:center;gap:8px}
.grid{display:flex;gap:24px;flex-wrap:wrap}
.item{flex:1;min-width:120px}
.label{font-size:12px;color:#9AA4B2;margin-bottom:4px}
.val{font-size:22px;font-weight:700}
.val.ok{color:#178A62}
.val.warn{color:#E07B39}
.val.bad{color:#C0392B}
.unit{font-size:12px;font-weight:400;color:#9AA4B2}
.badge{display:inline-block;padding:3px 10px;border-radius:99px;font-size:12px;font-weight:600}
.badge.on{background:#FDECEC;color:#C0392B;border:1px solid #F5C6C6}
.badge.off{background:#EAF7F1;color:#178A62;border:1px solid #C5E9D8}
.msg{background:#EAF7F1;color:#178A62;border:1px solid #C5E9D8;padding:11px 14px;border-radius:9px;font-size:13px;margin-bottom:16px}
table{width:100%;border-collapse:collapse;font-size:12.5px}
th,td{text-align:left;padding:8px 10px;border-bottom:1px solid #EEF1F6}
th{color:#9AA4B2;font-weight:600;font-size:11.5px}
tr:last-child td{border-bottom:0}
.btn{display:inline-block;padding:9px 18px;border:0;border-radius:9px;font-size:13px;font-weight:600;cursor:pointer;font-family:inherit;text-decoration:none}
.btn-primary{background:linear-gradient(135deg,#4F7FE0,#2F5B9A);color:#fff}
.btn-ghost{background:#F0F3F8;color:#4A5568}
.actions{display:flex;gap:9px;margin-top:8px}
.empty{color:#9AA4B2;font-size:13px;padding:12px 0}
.tip{background:#F8FAFC;border-left:3px solid #4F7FE0;padding:12px 14px;border-radius:0 8px 8px 0;font-size:12.5px;color:#5B667A;margin-top:12px;line-height:1.7}
code{background:#F0F3F8;padding:1px 5px;border-radius:4px;font-size:12px}
.kind{display:inline-block;padding:1px 7px;border-radius:5px;font-size:11px;font-weight:600}
.kind.breaker{background:#FDECEC;color:#C0392B}
.kind.heal{background:#FEF8EC;color:#B06A16}
.kind.disk{background:#FEF8EC;color:#B06A16}
.kind.safe_mode{background:#EEF1F6;color:#5B667A}
.kind.write{background:#FDECEC;color:#C0392B}
.kind.reset,.kind.state{background:#EEF1F6;color:#5B667A}
</style>

<div class="wrap">
    <a class="back" href="/admin/dashboard.php">← 返回仪表盘</a>
    <h1>自恢复守护</h1>
    <p class="sub">熔断、数据自愈、磁盘保护、保护模式 —— 出问题后自动往回走。</p>

    <?php if ($msg !== ''): ?><div class="msg"><?= htmlspecialchars($msg) ?></div><?php endif; ?>

    <div class="card">
        <h2>当前状态
            <?php if ($safe): ?>
                <span class="badge on">保护模式运行中</span>
            <?php else: ?>
                <span class="badge off">正常</span>
            <?php endif; ?>
        </h2>
        <div class="grid">
            <div class="item">
                <div class="label">保护模式</div>
                <div class="val <?= $safe ? 'bad' : 'ok' ?>"><?= $safe ? '开启' : '关闭' ?></div>
            </div>
            <div class="item">
                <div class="label">磁盘剩余</div>
                <div class="val <?= $diskOk ? 'ok' : 'bad' ?>">
                    <?= number_format($diskFree, 1) ?> <span class="unit">MB</span>
                </div>
            </div>
            <div class="item">
                <div class="label">熔断中</div>
                <div class="val <?= count($breakers) > 0 ? 'warn' : 'ok' ?>">
                    <?= count($breakers) ?> <span class="unit">个</span>
                </div>
            </div>
            <div class="item">
                <div class="label">错误率窗口</div>
                <div class="val">
                    <?php
                    $ok = (int)($snap['samples']['ok'] ?? 0);
                    $err = (int)($snap['samples']['err'] ?? 0);
                    $tot = $ok + $err;
                    echo $tot > 0 ? round($err / $tot * 100) . '<span class="unit">%</span>' : '—';
                    ?>
                </div>
            </div>
        </div>

        <div class="actions">
            <form method="post" style="display:inline">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(generateCSRFToken()) ?>">
                <input type="hidden" name="act" value="reset">
                <button class="btn btn-ghost" onclick="return confirm('确定重置所有熔断和错误计数吗？')">重置状态</button>
            </form>
            <form method="post" style="display:inline">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(generateCSRFToken()) ?>">
                <input type="hidden" name="act" value="clear_log">
                <button class="btn btn-ghost" onclick="return confirm('清空事件日志？')">清空日志</button>
            </form>
        </div>

        <div class="tip">
            <b>保护模式会自动关闭非核心功能</b>（AI、搜索、推荐、小组件），
            只保留浏览和发帖 —— 用户来这个站就是为了发帖，关掉就本末倒置。<br>
            触发条件：60 秒窗口内错误率 ≥ 35% 且样本 ≥ 20 次。持续 2 分钟后自动退出。
        </div>
    </div>

    <div class="card">
        <h2>熔断器</h2>
        <?php if (!$breakers): ?>
            <div class="empty">没有熔断中的操作 ✓</div>
        <?php else: ?>
            <table>
                <tr><th>操作</th><th>连续失败</th><th>恢复时间</th><th>最后失败</th></tr>
                <?php foreach ($breakers as $op => $b): ?>
                    <?php $left = max(0, (int)($b['until'] ?? 0) - $now); ?>
                    <tr>
                        <td><code><?= htmlspecialchars((string)$op) ?></code></td>
                        <td><?= (int)($b['fails'] ?? 0) ?></td>
                        <td><?= $left > 0 ? $left . ' 秒后' : '已可试探' ?></td>
                        <td><?= htmlspecialchars((string)($b['last'] ?? '-')) ?></td>
                    </tr>
                <?php endforeach; ?>
            </table>
        <?php endif; ?>
    </div>

    <div class="card">
        <h2>事件日志（最近 <?= count($logs) ?> 条）</h2>
        <?php if (!$logs): ?>
            <div class="empty">暂无事件。一切正常时不会产生任何记录 ✓</div>
        <?php else: ?>
            <table>
                <tr><th style="width:140px">时间</th><th style="width:90px">类型</th><th>内容</th></tr>
                <?php foreach ($logs as $l): ?>
                    <tr>
                        <td><?= htmlspecialchars((string)($l['at'] ?? '')) ?></td>
                        <td><span class="kind <?= htmlspecialchars((string)($l['kind'] ?? '')) ?>"><?= htmlspecialchars((string)($l['kind'] ?? '')) ?></span></td>
                        <td><?= htmlspecialchars(mb_substr((string)($l['msg'] ?? ''), 0, 200)) ?></td>
                    </tr>
                <?php endforeach; ?>
            </table>
        <?php endif; ?>
    </div>
</div>
<?php adminFooter(); ?>

