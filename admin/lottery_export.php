<?php
/**
 * 后台：单个抽奖的复盘导出（CSV）
 * 一次性导出该抽奖的概况 + 参与名单（含是否中奖、来源、时间），方便做活动复盘。
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../includes/lottery.php';

$adminUser = requireAdmin();
$adminUser = checkBanned($adminUser);
if (!checkPermission($adminUser, 'manage_settings')) { http_response_code(403); die('403 Forbidden'); }

$id = (int) ($_GET['id'] ?? 0);
$l = $id > 0 ? lwLotteryGet($id) : null;
if (!$l) { http_response_code(404); die('抽奖不存在'); }

$entries = lwLotteryEntries($id);
$winners = array_map('intval', (array) json_decode((string) ($l['winners'] ?? '[]'), true));
$fs = getFS();

header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="lottery-' . $id . '-report.csv"');
$out = fopen('php://output', 'w');
fwrite($out, "\xEF\xBB\xBF"); // BOM，Excel 打开不乱码

fputcsv($out, ['抽奖复盘报告']);
fputcsv($out, ['ID', $id]);
fputcsv($out, ['标题', (string) ($l['title'] ?? '')]);
fputcsv($out, ['奖品', (string) ($l['prize'] ?? '') . ' × ' . (int) ($l['prize_count'] ?? 1)]);
fputcsv($out, ['状态', ($l['status'] ?? 'open') === 'open' ? '进行中' : '已开奖']);
fputcsv($out, ['开奖时间', (string) ($l['draw_at'] ?? '')]);
fputcsv($out, ['规则', implode(' / ', (array) ($l['rules'] ?? []))]);
fputcsv($out, ['浏览', (int) ($l['views'] ?? 0)]);
fputcsv($out, ['分享浏览', (int) ($l['share_views'] ?? 0)]);
fputcsv($out, ['参与人数', count($entries)]);
$__remind = 0;
foreach ((array) $fs->read('lottery_reminders') as $r) { if ((int) ($r['lottery_id'] ?? 0) === $id) { $__remind++; } }
fputcsv($out, ['提醒人数', $__remind]);
fputcsv($out, ['中奖人数', count($winners)]);
fputcsv($out, []);

fputcsv($out, ['#', '用户ID', '昵称', 'QQ', '是否中奖', '来源', '参与时间']);
$i = 0;
foreach ($entries as $e) {
    $i++;
    $u = $fs->findById('users', (int) ($e['user_id'] ?? 0));
    fputcsv($out, [
        $i,
        (int) ($e['user_id'] ?? 0),
        (string) (($u['nickname'] ?? '') !== '' ? $u['nickname'] : ''),
        (string) ($u['qq'] ?? ''),
        in_array((int) ($e['user_id'] ?? 0), $winners, true) ? '是' : '',
        (string) (($e['via'] ?? '') === 'share' ? '分享链接' : '直接参与'),
        (string) ($e['created_at'] ?? ''),
    ]);
}
fclose($out);
exit;
