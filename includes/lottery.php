<?php
/**
 * 站内动态抽奖（B 站式）
 * ---------------------------------------------------------------------------
 * 设计（对应站长要求）：
 *   · 动态抽奖：一条抽奖动态，用户「参与」即登记（转/评/赞都可以作为参与动作，
 *     由发起人选择规则，本站不强制校验外部动作，只登记参与意向）
 *   · 到点自动开奖：访问抽奖页时若已过期且未开奖，自动随机抽取中奖者
 *   · 数据不冗余：参与记录只存 user_id + lottery_id，不复制用户资料；
 *     展示时按 id 关联读取（「用链接而不是把数据再存一遍」）
 */

if (!function_exists('lwLotteryTable')) {
    function lwLotteryRows(): array
    {
        $rows = getFS()->read('lotteries');
        return is_array($rows) ? $rows : [];
    }
}

if (!function_exists('lwLotteryGet')) {
    function lwLotteryGet(int $id): ?array
    {
        foreach (lwLotteryRows() as $r) {
            if ((int) ($r['id'] ?? 0) === $id) { return $r; }
        }
        return null;
    }
}

if (!function_exists('lwLotteryByPost')) {
    /**
     * 按来源帖子 ID 找它附带的抽奖（发帖时创建的那条）。
     * 只做线性读取，供详情页等场景按需反查；信息流列表不调用它 ——
     * 列表直接读 posts.lottery_id，避免每条帖子都扫一遍 lotteries 表。
     */
    function lwLotteryByPost(int $postId): ?array
    {
        if ($postId <= 0) { return null; }
        foreach (lwLotteryRows() as $r) {
            if ((int) ($r['post_id'] ?? 0) === $postId) { return $r; }
        }
        return null;
    }
}

if (!function_exists('lwLotteryAll')) {
    /** @return array<int,array> 按开奖时间倒序 */
    function lwLotteryAll(): array
    {
        $rows = lwLotteryRows();
        usort($rows, function ($a, $b) {
            return strcmp((string) ($b['created_at'] ?? ''), (string) ($a['created_at'] ?? ''));
        });
        return $rows;
    }
}

if (!function_exists('lwLotteryCreate')) {
    function lwLotteryCreate(int $userId, array $d): array
    {
        $fs = getFS();
        $title = trim((string) ($d['title'] ?? ''));
        $prize = trim((string) ($d['prize'] ?? ''));
        if ($title === '' || $prize === '') {
            return ['ok' => false, 'error' => '标题和奖品不能为空'];
        }
        $count = max(1, min(100, (int) ($d['prize_count'] ?? 1)));
        $drawAt = trim((string) ($d['draw_at'] ?? ''));
        $ts = $drawAt !== '' ? strtotime($drawAt) : 0;
        if (!$ts) { return ['ok' => false, 'error' => '开奖时间格式不对']; }
        if ($ts <= time()) { return ['ok' => false, 'error' => '开奖时间必须晚于现在']; }

        /* post_id：这条抽奖由「发帖时附带抽奖」创建时，记下来源帖子 ID。
         * 0 表示后台单独创建的抽奖（没有关联动态）。只存 ID 不复制帖子内容，
         * 帖子被删也不影响抽奖本身（后台列表会标注「动态已失效」）。 */
        $postId = max(0, (int) ($d['post_id'] ?? 0));

        $row = $fs->insert('lotteries', [
            'user_id'       => $userId,
            'post_id'       => $postId,
            'title'         => mb_substr($title, 0, 120),
            'prize'         => mb_substr($prize, 0, 120),
            'prize_count'   => $count,
            'description'   => mb_substr(trim((string) ($d['description'] ?? '')), 0, 2000),
            'cover_image'   => trim((string) ($d['cover_image'] ?? '')),
            'rule_like'     => !empty($d['rule_like']) ? 1 : 0,
            'rule_comment'  => !empty($d['rule_comment']) ? 1 : 0,
            'rule_repost'   => !empty($d['rule_repost']) ? 1 : 0,
            'draw_at'       => date('Y-m-d H:i:s', $ts),
            'status'        => 'open',
            'winners'       => '[]',
            'entry_count'   => 0,
        ]);
        if (!$row) { return ['ok' => false, 'error' => '创建失败']; }
        return ['ok' => true, 'id' => (int) $row['id'], 'post_id' => $postId];
    }
}

if (!function_exists('lwLotteryEntries')) {
    function lwLotteryEntries(int $lotteryId): array
    {
        $out = [];
        foreach ((array) getFS()->read('lottery_entries') as $r) {
            if ((int) ($r['lottery_id'] ?? 0) === $lotteryId) { $out[] = $r; }
        }
        return $out;
    }
}

if (!function_exists('lwLotteryJoined')) {
    function lwLotteryJoined(int $lotteryId, int $userId): bool
    {
        if ($userId <= 0) { return false; }
        foreach (lwLotteryEntries($lotteryId) as $r) {
            if ((int) ($r['user_id'] ?? 0) === $userId) { return true; }
        }
        return false;
    }
}

if (!function_exists('lwLotteryJoin')) {
    function lwLotteryJoin(int $lotteryId, int $userId, string $via = 'manual'): array
    {
        $l = lwLotteryGet($lotteryId);
        if (!$l) { return ['ok' => false, 'error' => '抽奖不存在']; }
        if (($l['status'] ?? '') !== 'open') { return ['ok' => false, 'error' => '已开奖，不能再参与']; }
        if (strtotime((string) ($l['draw_at'] ?? '')) <= time()) { return ['ok' => false, 'error' => '已到开奖时间']; }
        if (lwLotteryJoined($lotteryId, $userId)) { return ['ok' => false, 'error' => '你已经参与过啦']; }

        /* 「关注才能参与」强校验：规则里含「关注」时，要求已关注发起人。
         * 原来规则只是提示文案，实际不校验，导致"没关注也能参与"，
         * 发起人会觉得规则是摆设。这里在唯一入口 lwLotteryJoin 里兜住，
         * 手动参与和转发附带参与都会走到同一处。 */
        $rules = (array) ($l['rules'] ?? []);
        $needFollow = false;
        foreach ($rules as $r) { if (mb_strpos((string) $r, '关注') !== false) { $needFollow = true; break; } }
        if ($needFollow) {
            $ownerId = (int) ($l['created_by'] ?? 0);
            if ($ownerId > 0 && $ownerId !== $userId) {
                $follow = getFS()->findOne('follows', ['user_id' => $userId, 'target_id' => $ownerId]);
                if (!$follow) {
                    return [
                        'ok'    => false,
                        'error' => '该抽奖要求先关注发起人，关注后再来参与吧',
                        'need_follow' => $ownerId,
                    ];
                }
            }
        }

        $fs = getFS();
        $row = $fs->insert('lottery_entries', [
            'lottery_id' => $lotteryId,
            'user_id'    => $userId,
            'via'        => mb_substr($via, 0, 20),
        ]);
        if (!$row) { return ['ok' => false, 'error' => '参与失败']; }
        $n = count(lwLotteryEntries($lotteryId));
        $fs->update('lotteries', $lotteryId, ['entry_count' => $n]);
        return ['ok' => true, 'entry_count' => $n];
    }
}

if (!function_exists('lwLotteryDraw')) {
    /** 开奖：从参与记录里随机抽 prize_count 个不重复中奖者。 */
    function lwLotteryDraw(int $lotteryId): array
    {
        $l = lwLotteryGet($lotteryId);
        if (!$l) { return ['ok' => false, 'error' => '抽奖不存在']; }
        if (($l['status'] ?? '') === 'drawn') { return ['ok' => true, 'already' => true, 'winners' => json_decode((string) ($l['winners'] ?? '[]'), true)]; }

        $entries = lwLotteryEntries($lotteryId);
        $uids = [];
        foreach ($entries as $e) {
            $u = (int) ($e['user_id'] ?? 0);
            if ($u > 0) { $uids[$u] = true; }
        }
        $uids = array_keys($uids);
        $need = max(1, min((int) ($l['prize_count'] ?? 1), count($uids) ?: 1));
        $winners = [];
        if ($uids) {
            shuffle($uids);
            $winners = array_slice($uids, 0, min($need, count($uids)));
        }
        getFS()->update('lotteries', $lotteryId, [
            'status'  => 'drawn',
            'winners' => json_encode($winners),
            'drawn_at'=> date('Y-m-d H:i:s'),
        ]);

        /* 给设置了「提醒我」的同学发站内通知（中奖/未中奖文案不同） */
        try {
            $winMap = array_flip(array_map('intval', $winners));
            foreach ((array) getFS()->read('lottery_reminders') as $__r) {
                if ((int) ($__r['lottery_id'] ?? 0) !== $lotteryId) { continue; }
                $uid = (int) ($__r['user_id'] ?? 0);
                if ($uid <= 0) { continue; }
                $won = isset($winMap[$uid]);
                getFS()->insert('notifications', [
                    'user_id'    => $uid,
                    'type'       => 'lottery_result',
                    'content'    => $won
                        ? ('🎉 你参与的抽奖「' . (string) ($l['title'] ?? '') . '」已开奖：恭喜中奖！')
                        : ('抽奖「' . (string) ($l['title'] ?? '') . '」已开奖，本次未中奖，下次好运～'),
                    'post_id'    => 0,
                    'post_title' => (string) ($l['title'] ?? ''),
                    'from_user'  => '系统',
                    'is_read'    => false,
                ]);
            }
        } catch (Throwable $__e) { error_log('lottery remind notify: ' . $__e->getMessage()); }

        return ['ok' => true, 'winners' => $winners];
    }
}

if (!function_exists('lwLotteryPreDrawNotify')) {
    /**
     * 「即将开奖」提前提醒：开奖前 10 分钟内、且还没提醒过的抽奖，
     * 给「设了提醒的人 + 已参与者」各发一条站内通知（同一抽奖只发一次）。
     * 免费主机没有真 cron，所以挂在页面访问时的 lwLotteryAutoDraw() 里兜底。
     */
    function lwLotteryPreDrawNotify(): int
    {
        $fs = getFS();
        $now = time();
        $sent = 0;
        foreach ((array) $fs->read('lotteries') as $l) {
            if (($l['status'] ?? '') !== 'open' || !empty($l['pre_notified'])) { continue; }
            $ts = strtotime((string) ($l['draw_at'] ?? '')) ?: 0;
            if ($ts <= 0 || $ts - $now > 600 || $ts <= $now) { continue; }
            $lid = (int) ($l['id'] ?? 0);
            if ($lid <= 0) { continue; }

            $targets = [];
            foreach ((array) $fs->read('lottery_reminders') as $r) {
                if ((int) ($r['lottery_id'] ?? 0) === $lid) { $targets[(int) $r['user_id']] = true; }
            }
            foreach ((array) $fs->read('lottery_entries') as $e) {
                if ((int) ($e['lottery_id'] ?? 0) === $lid) { $targets[(int) ($e['user_id'] ?? 0)] = true; }
            }
            unset($targets[0]);

            foreach (array_keys($targets) as $uid) {
                try {
                    $fs->insert('notifications', [
                        'user_id'    => $uid,
                        'type'       => 'lottery_result',
                        'content'    => '⏰ 抽奖「' . (string) ($l['title'] ?? '') . '」即将开奖（10 分钟内），记得回来看结果～',
                        'post_id'    => 0,
                        'post_title' => (string) ($l['title'] ?? ''),
                        'from_user'  => '系统',
                        'is_read'    => false,
                    ]);
                    $sent++;
                } catch (Throwable $e2) {}
            }
            try { $fs->update('lotteries', $lid, ['pre_notified' => true]); } catch (Throwable $e3) {}
        }
        return $sent;
    }
}

if (!function_exists('lwLotteryRemindSet')) {
    /** 设置/取消「开奖提醒」。返回是否已设置。 */
    function lwLotteryRemindSet(int $userId, int $lotteryId, bool $on = true): bool
    {
        $fs = getFS();
        foreach ((array) $fs->read('lottery_reminders') as $r) {
            if ((int) ($r['user_id'] ?? 0) === $userId && (int) ($r['lottery_id'] ?? 0) === $lotteryId) {
                if ($on) { return true; }
                try { $fs->delete('lottery_reminders', (int) ($r['id'] ?? 0)); } catch (Throwable $e) {}
                return false;
            }
        }
        if (!$on) { return false; }
        $fs->insert('lottery_reminders', [
            'user_id'    => $userId,
            'lottery_id' => $lotteryId,
            'created_at' => date('Y-m-d H:i:s'),
        ]);
        return true;
    }

    function lwLotteryRemindHas(int $userId, int $lotteryId): bool
    {
        foreach ((array) getFS()->read('lottery_reminders') as $r) {
            if ((int) ($r['user_id'] ?? 0) === $userId && (int) ($r['lottery_id'] ?? 0) === $lotteryId) { return true; }
        }
        return false;
    }
}

if (!function_exists('lwLotteryAutoDraw')) {
    /** 访问时兜底开奖：到点且未开奖的自动抽。 */
    function lwLotteryAutoDraw(): int
    {
        try { lwLotteryPreDrawNotify(); } catch (Throwable $e) {}
        $n = 0;
        $now = time();
        foreach (lwLotteryRows() as $l) {
            if (($l['status'] ?? '') !== 'open') { continue; }
            $ts = strtotime((string) ($l['draw_at'] ?? ''));
            if ($ts > 0 && $ts <= $now) {
                lwLotteryDraw((int) $l['id']);
                $n++;
            }
        }
        return $n;
    }
}
