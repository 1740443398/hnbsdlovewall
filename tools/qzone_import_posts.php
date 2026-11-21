<?php
/**
 * 把 QQ 空间动态导入成站内帖子（CLI）
 *   php tools/qzone_import_posts.php
 * 幂等：按 source_tid 去重。
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/qzone_sync.php';
require_once __DIR__ . '/../includes/qzone_posts.php';

@set_time_limit(300);
$t0 = microtime(true);
$r = lwQzoneSyncToPosts();
$dt = microtime(true) - $t0;
printf("导入完成: 新建 %d, 更新 %d, 跳过 %d（%.2fs）\n", $r['created'] ?? 0, $r['updated'] ?? 0, $r['skipped'] ?? 0, $dt);
if (!empty($r['error'])) { echo "错误: {$r['error']}\n"; exit(1); }

$fs = getFS();
$bot = lwQzoneBotUser();
echo "机器人账号: id=" . ($bot['id'] ?? '?') . " 昵称=" . ($bot['nickname'] ?? '?') . "\n";
$myposts = array_filter($fs->read('posts'), function ($p) { return ($p['source'] ?? '') === 'qzone'; });
usort($myposts, function ($a, $b) { return strcmp($b['created_at'], $a['created_at']); });
echo "站内 QQ 来源帖子: " . count($myposts) . " 条\n";
foreach (array_slice($myposts, 0, 5) as $p) {
    printf("  #%d %s 图%d QQ赞%d 评论%d\n", $p['id'], $p['created_at'], count(json_decode($p['images'] ?? '[]', true) ?: []), $p['qq_likes'] ?? 0, count($p['qq_comments'] ?? []));
}
