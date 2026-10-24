<?php
/**
 * 后台：安全告警
 *
 * 展示 PHP2Shell（CVE-2026-109618）探测日志 —— 即「内存耗尽」告警。
 * 正常业务不会 OOM，出现记录即说明有人在试探。
 *
 * 同时给出当前 PHP 版本与缓解措施状态，方便一眼确认防护是否在位。
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/security.php';

$admin = requireAdmin();
$admin = checkBanned($admin);
if (!empty($admin['is_banned'])) {
    header('Location: /403.php');
    exit;
}
if (($admin['role'] ?? '') !== 'super_admin') {
    header('Location: /admin/dashboard.php');
    exit;
}

$page = 'security_alerts.php';
$pageTitle = '安全告警';

$logFile = __DIR__ . '/../data/_oom_alerts.log';
$alerts = [];
if (is_file($logFile)) {
    $lines = @file($logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
    $lines = array_slice(array_reverse($lines), 0, 100);
    foreach ($lines as $ln) {
        if (preg_match('/^\[([^\]]+)\]\s+(\S+)\s+(\S+)\s+#(\S+)\s+(.*)$/', $ln, $m)) {
            $alerts[] = ['time'=>$m[1],'method'=>$m[2],'uri'=>$m[3],'ip'=>$m[4],'msg'=>$m[5]];
        } else {
            $alerts[] = ['time'=>'','method'=>'','uri'=>'','ip'=>'','msg'=>$ln];
        }
    }
}
$phpVer = PHP_VERSION;
$needVer = '8.4.27';
$isVuln = version_compare($phpVer, $needVer, '<');
$memLimit = ini_get('memory_limit');
$maxExec = ini_get('max_execution_time');
$guardOn = defined('LW_PHP2SHELL_GUARD');

require __DIR__ . '/layout.php';
?>
<div class="page-header">
    <h1 class="page-title">安全告警</h1>
    <p class="page-desc">PHP2Shell（CVE-2026-109618 / CVE-2026-109619）探测监控</p>
</div>

<div class="cards-grid" style="margin-bottom:20px">
    <div class="card" style="border-left:4px solid <?= $isVuln ? '#d92633' : '#13b768' ?>">
        <div class="card-label">PHP 版本</div>
        <div class="card-value" style="font-size:1.4rem"><?= htmlspecialchars($phpVer) ?></div>
        <div style="font-size:.8rem;color:<?= $isVuln ? '#d92633' : '#13b768' ?>;margin-top:6px">
            <?= $isVuln ? '低于 ' . $needVer . '，存在风险，请升级' : '已高于修复版，安全' ?>
        </div>
    </div>
    <div class="card" style="border-left:4px solid <?= $guardOn ? '#13b768' : '#d92633' ?>">
        <div class="card-label">缓解层</div>
        <div class="card-value" style="font-size:1.4rem"><?= $guardOn ? '已启用' : '未启用' ?></div>
        <div style="font-size:.8rem;color:#666;margin-top:6px">内存 <?= htmlspecialchars($memLimit) ?> · 超时 <?= htmlspecialchars($maxExec) ?>s</div>
    </div>
    <div class="card" style="border-left:4px solid <?= empty($alerts) ? '#13b768' : '#d92633' ?>">
        <div class="card-label">OOM 探测次数</div>
        <div class="card-value" style="font-size:1.4rem"><?= count($alerts) ?></div>
        <div style="font-size:.8rem;color:#666;margin-top:6px">
            <?= empty($alerts) ? '未发现攻击痕迹' : '检测到有人在试探' ?>
        </div>
    </div>
</div>

<div class="card" style="margin-bottom:20px">
    <div style="font-weight:600;margin-bottom:8px">说明</div>
    <div style="font-size:.86rem;line-height:1.8;color:#555">
        PHP2Shell 是两个「无需认证即可远程执行代码」的漏洞（CVSS 9.2，已在野利用）。
        PHP 官方明确表示<b>没有任何配置能完全缓解</b>，唯一根治是升级到
        <b>8.4.27 / 8.3.36 / 8.2.35 / 8.5.12</b> 或更高。<br>
        本站已启用应用层缓解（压低内存上限、限制请求体、限制执行时间），
        并且会在<b>内存耗尽</b>时记录告警 —— 正常业务不会 OOM，
        所以这里一旦出现记录，说明有人在试探该漏洞。
    </div>
</div>

<div class="card">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px">
        <div style="font-weight:600">探测日志（最近 100 条）</div>
        <?php if (!empty($alerts)): ?>
        <button class="btn btn-sm btn-danger" onclick="clearAlerts()">清空日志</button>
        <?php endif; ?>
    </div>
    <?php if (empty($alerts)): ?>
        <div style="padding:24px;text-align:center;color:#999;font-size:.9rem">
            暂无记录 —— 没有检测到内存耗尽型探测
        </div>
    <?php else: ?>
    <table class="table" style="width:100%;font-size:.84rem">
        <thead>
            <tr>
                <th style="text-align:left;padding:8px">时间</th>
                <th style="text-align:left;padding:8px">方法</th>
                <th style="text-align:left;padding:8px">路径</th>
                <th style="text-align:left;padding:8px">来源 IP</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($alerts as $a): ?>
            <tr style="border-top:1px solid #eee">
                <td style="padding:8px;white-space:nowrap"><?= htmlspecialchars($a['time']) ?></td>
                <td style="padding:8px"><?= htmlspecialchars($a['method']) ?></td>
                <td style="padding:8px;font-family:monospace;font-size:.78rem"><?= htmlspecialchars($a['uri']) ?></td>
                <td style="padding:8px;font-family:monospace"><?= htmlspecialchars($a['ip']) ?></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>
</div>

<script>
function clearAlerts() {
    if (!confirm('确定清空全部告警日志？此操作不可撤销。')) return;
    fetch('/api/admin/security_alerts.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: 'action=clear&csrf_token=' + encodeURIComponent(window.CSRF_TOKEN || ''),
        credentials: 'same-origin'
    }).then(r => r.json()).then(d => {
        if (d.success) location.reload();
        else alert(d.message || '清空失败');
    }).catch(() => alert('网络错误'));
}
</script>
<?php adminFooter(); ?>
