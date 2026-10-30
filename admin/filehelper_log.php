<?php
/**
 * 后台：文件传输记录
 *
 * 展示 data/filehelper/log.jsonl 里的传输记录：
 * IP、时间、配对码、文件名、大小、通道。
 *
 * 只读，不提供删除（日志本身按 2MB 自动滚动）。
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../includes/filehelper.php';

$admin = requireAdmin();
$admin = checkBanned($admin);

$logFile = LW_FH_DIR . '/log.jsonl';
$rows = [];
$total = 0;
$totalBytes = 0;

if (is_file($logFile)) {
    $fh = @fopen($logFile, 'r');
    if ($fh) {
        while (($line = fgets($fh)) !== false) {
            $j = json_decode(trim($line), true);
            if (!is_array($j)) {
                continue;
            }
            $total++;
            $totalBytes += (int)($j['size'] ?? 0);
            $rows[] = $j;
        }
        fclose($fh);
    }
}

// 最新的在前
$rows = array_reverse($rows);

// 简单分页
$perPage = 50;
$page = max(1, (int)($_GET['p'] ?? 1));
$pages = max(1, (int)ceil(count($rows) / $perPage));
$page = min($page, $pages);
$slice = array_slice($rows, ($page - 1) * $perPage, $perPage);

function fhSize($b)
{
    $b = (int)$b;
    if ($b < 1024) return $b . ' B';
    if ($b < 1048576) return round($b / 1024, 1) . ' KB';
    if ($b < 1073741824) return round($b / 1048576, 1) . ' MB';
    return round($b / 1073741824, 2) . ' GB';
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
adminHeader('文件传输记录', $admin, $csrfToken);
?>

<style>
/* 本页特有样式（表格 / 卡片细节）。
 * 颜色优先用 layout.php 的 CSS 变量，跟随明暗主题。 */
*{box-sizing:border-box}
body{margin:0;background:#F5F7FA;color:#1F2733;font-family:-apple-system,BlinkMacSystemFont,"PingFang SC","Microsoft YaHei",sans-serif;font-size:14px;line-height:1.6}
.wrap{max-width:1100px;margin:0 auto;padding:24px 18px 60px}
h1{font-size:20px;margin:0 0 4px}
.sub{color:#6B7684;font-size:13px;margin:0 0 22px}
.stats{display:flex;gap:12px;flex-wrap:wrap;margin-bottom:20px}
.stat{background:#fff;border:1px solid #E4E9F0;border-radius:12px;padding:14px 18px;min-width:140px}
.stat .n{font-size:22px;font-weight:700;color:#2F5B9A;font-variant-numeric:tabular-nums}
.stat .l{font-size:12px;color:#9AA4B2;margin-top:2px}
.table-wrap{background:#fff;border:1px solid #E4E9F0;border-radius:12px;overflow:hidden}
table{width:100%;border-collapse:collapse;font-size:13px}
th{text-align:left;padding:11px 14px;background:#F8FAFC;color:#5B667A;font-weight:600;font-size:12px;border-bottom:1px solid #E4E9F0;white-space:nowrap}
td{padding:10px 14px;border-bottom:1px solid #F0F3F8;vertical-align:top}
tr:last-child td{border-bottom:0}
tr:hover td{background:#FAFBFD}
.mono{font-family:ui-monospace,SFMono-Regular,Menlo,monospace;font-size:12px}
.chip{display:inline-block;padding:2px 8px;border-radius:6px;font-size:11px;font-weight:600}
.chip-relay{background:#E8F0FE;color:#2F5B9A}
.chip-text{background:#EAF7F1;color:#178A62}
.chip-p2p{background:#FEF3E8;color:#B46211}
.empty{text-align:center;padding:60px 20px;color:#9AA4B2}
.pager{display:flex;gap:8px;justify-content:center;margin-top:18px}
.pager a,.pager span{padding:7px 14px;border-radius:8px;background:#fff;border:1px solid #E4E9F0;color:#5B667A;text-decoration:none;font-size:13px}
.pager a:hover{background:#F0F3F8}
.pager .cur{background:#2F5B9A;color:#fff;border-color:#2F5B9A}
.back{display:inline-block;margin-bottom:16px;color:#5B667A;text-decoration:none;font-size:13px}
.back:hover{color:#2F5B9A}
</style>

<div class="wrap">
    <a class="back" href="/admin/dashboard.php">← 返回仪表盘</a>
    <h1>文件传输记录</h1>
    <p class="sub">记录每一次通过文件传输助手产生的上传与下载。中转文件本身 1 分钟内删除，此处仅留元信息。</p>

    <div class="stats">
        <div class="stat"><div class="n"><?= $total ?></div><div class="l">总传输次数</div></div>
        <div class="stat"><div class="n"><?= htmlspecialchars(fhSize($totalBytes)) ?></div><div class="l">累计传输量</div></div>
        <div class="stat"><div class="n"><?= count(array_unique(array_column($rows, 'ip'))) ?></div><div class="l">不同 IP</div></div>
    </div>

    <?php if (!$slice): ?>
        <div class="table-wrap"><div class="empty">还没有传输记录</div></div>
    <?php else: ?>
    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th style="width:150px">时间</th>
                    <th style="width:130px">IP</th>
                    <th style="width:90px">配对码</th>
                    <th style="width:80px">通道</th>
                    <th>文件名 / 内容</th>
                    <th style="width:90px">大小</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($slice as $r): ?>
                <tr>
                    <td class="mono"><?= htmlspecialchars((string)($r['at'] ?? '')) ?></td>
                    <td class="mono"><?= htmlspecialchars((string)($r['ip'] ?? '-')) ?></td>
                    <td class="mono"><?= htmlspecialchars((string)($r['code'] ?? '-')) ?></td>
                    <td>
                        <?php
                        $ch = (string)($r['channel'] ?? 'relay');
                        $cls = $ch === 'text' ? 'chip-text' : ($ch === 'p2p' ? 'chip-p2p' : 'chip-relay');
                        $label = $ch === 'text' ? '文字' : ($ch === 'p2p' ? 'P2P' : '中转');
                        ?>
                        <span class="chip <?= $cls ?>"><?= $label ?></span>
                    </td>
                    <td><?= htmlspecialchars((string)($r['name'] ?? '-')) ?></td>
                    <td class="mono"><?= htmlspecialchars(fhSize($r['size'] ?? 0)) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <?php if ($pages > 1): ?>
    <div class="pager">
        <?php for ($i = max(1, $page - 3); $i <= min($pages, $page + 3); $i++): ?>
            <?php if ($i === $page): ?>
                <span class="cur"><?= $i ?></span>
            <?php else: ?>
                <a href="?p=<?= $i ?>"><?= $i ?></a>
            <?php endif; ?>
        <?php endfor; ?>
    </div>
    <?php endif; ?>
    <?php endif; ?>
</div>
<?php adminFooter(); ?>

