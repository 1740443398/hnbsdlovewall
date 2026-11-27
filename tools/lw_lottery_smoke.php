<?php
/**
 * 抽奖模块冒烟测试（可重复执行）
 * 用法：php tools/lw_lottery_smoke.php
 * 只在测试环境跑：会在 data/ 里创建临时记录，跑完自动清理。
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/lottery.php';

if (PHP_SAPI !== 'cli') { http_response_code(403); die('CLI only'); }
$fs = getFS();
$createdLots = [];
$createdTop = [];
$pass = 0; $fail = 0;

function check(string $name, bool $ok, string $extra = ''): void {
    global $pass, $fail;
    if ($ok) { $pass++; echo "  [PASS] {$name}\n"; }
    else { $fail++; echo "  [FAIL] {$name}" . ($extra !== '' ? " => {$extra}" : '') . "\n"; }
}

try {
    echo "1. 创建抽奖（绑定临时动态，规则：点赞+自定义条款）\n";
    /* 点赞/评论/转发 规则只有在绑定动态时才可核验，所以先造一条临时动态，
     * 避免误把"未绑定动态=仅提示"当成校验失败。 */
    $tmpPost = $fs->insert('posts', [
        'user_id' => 1, 'title' => 'SMOKE-动态', 'content' => 'smoke', 'category' => 'other',
        'status' => 'published', 'visibility' => 'public',
        'created_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s'),
    ]);
    $tmpPid = (int) ($tmpPost['id'] ?? 0);
    $r = lwLotteryCreate(1, [
        'title' => 'SMOKE-抽奖', 'prize' => '测试奖品', 'prize_count' => 2,
        'draw_at' => date('Y-m-d H:i:s', time() + 3600),
        'post_id' => $tmpPid,
        'rule_like' => 1, 'rule_custom' => '需为本校学生',
    ]);
    check('创建成功', !empty($r['ok']), json_encode($r, JSON_UNESCAPED_UNICODE));
    $lid = (int) ($r['id'] ?? 0);
    if ($lid > 0) { $createdLots[] = $lid; }
    $l = $lid > 0 ? lwLotteryGet($lid) : null;
    check('rules 含点赞', in_array('点赞', (array) ($l['rules'] ?? []), true));
    check('rules 含自定义条款', in_array('需为本校学生', (array) ($l['rules'] ?? []), true));

    echo "2. 参与门槛（点赞规则，未点赞应被拒）\n";
    $j1 = lwLotteryJoin($lid, 2, 'manual');
    check('未点赞被拒绝', empty($j1['ok']) && ($j1['need_like'] ?? 0) > 0, json_encode($j1, JSON_UNESCAPED_UNICODE));

    echo "3. 参与成功后重复参与被拒\n";
    $pid = (int) ($l['post_id'] ?? 0);
    if ($pid > 0) { $fs->insert('post_likes', ['post_id' => $pid, 'user_id' => 2]); }
    $j2 = lwLotteryJoin($lid, 2, 'share');
    check('点赞后可参与', !empty($j2['ok']), json_encode($j2, JSON_UNESCAPED_UNICODE));
    $j3 = lwLotteryJoin($lid, 2, 'manual');
    check('重复参与被拒', empty($j3['ok']));

    echo "4. 提醒订阅 + 推送偏好\n";
    lwLotteryRemindSet(2, $lid);
    check('已设提醒', lwLotteryRemindHas(2, $lid));
    lwLotteryPushSet(3, false);
    check('关闭推送生效', !lwLotteryPushEnabled(3));
    lwLotteryPushSet(3, true);
    check('恢复推送生效', lwLotteryPushEnabled(3));

    echo "5. 开奖 + 通知/推送入队\n";
    $before = count((array) $fs->read('app_pushes'));
    $d = lwLotteryDraw($lid);
    check('开奖成功', !empty($d['ok']), json_encode($d, JSON_UNESCAPED_UNICODE));
    $after = count((array) $fs->read('app_pushes'));
    check('产生 App 推送', $after > $before, "before=$before after=$after");
    $notif = array_values(array_filter((array) $fs->read('notifications'), function ($n) use ($lid, $l) {
        return ($n['type'] ?? '') === 'lottery_result' && strpos((string) ($n['content'] ?? ''), (string) $l['title']) !== false;
    }));
    check('产生站内通知', count($notif) > 0, 'count=' . count($notif));

    echo "6. 编辑抽奖\n";
    $u = lwLotteryUpdate($lid, ['title' => 'SMOKE-抽奖-改', 'prize' => '新奖品', 'prize_count' => 1,
        'draw_at' => date('Y-m-d H:i:s', time() + 7200)]);
    check('编辑成功', !empty($u['ok']), json_encode($u, JSON_UNESCAPED_UNICODE));
} finally {
    echo "7. 清理临时数据\n";
    foreach ($createdLots as $id) {
        foreach ((array) $fs->read('lottery_entries') as $e) { if ((int) ($e['lottery_id'] ?? 0) === $id) { $fs->delete('lottery_entries', (int) $e['id']); } }
        foreach ((array) $fs->read('lottery_reminders') as $e) { if ((int) ($e['lottery_id'] ?? 0) === $id) { $fs->delete('lottery_reminders', (int) $e['id']); } }
        $fs->delete('lotteries', $id);
    }
    if (!empty($tmpPid)) {
        foreach ((array) $fs->read('post_likes') as $e) { if ((int) ($e['post_id'] ?? 0) === $tmpPid) { $fs->delete('post_likes', (int) $e['id']); } }
        foreach ((array) $fs->read('comments') as $e) { if ((int) ($e['post_id'] ?? 0) === $tmpPid) { $fs->delete('comments', (int) $e['id']); } }
        foreach ((array) $fs->read('posts') as $e) { if ((int) ($e['id'] ?? 0) === $tmpPid) { $fs->delete('posts', $tmpPid); } }
    }
}

echo "\n结果：PASS={$pass} FAIL={$fail}\n";
exit($fail > 0 ? 1 : 0);
