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

        /* 统一规则数组：优先用传入的 rules；否则由布尔字段推导。
         * 这样新老调用方都能写出一致的 rules，详情页/校验都只认这一份。 */
        $rulesIn = (array) ($d['rules'] ?? []);
        if (!$rulesIn) {
            if (!empty($d['rule_follow']))  { $rulesIn[] = '关注'; }
            if (!empty($d['rule_repost']))  { $rulesIn[] = '转发'; }
            if (!empty($d['rule_comment'])) { $rulesIn[] = '评论'; }
            if (!empty($d['rule_like']))    { $rulesIn[] = '点赞'; }
        }
        /* 自定义提示条款：不参与自动核验，仅展示给参与者（如"需为本校学生"） */
        $customRules = (string) ($d['rule_custom'] ?? '');
        if ($customRules !== '') {
            foreach (preg_split('/[\n,，、;；]+/u', $customRules) as $__cr) {
                $__cr = trim($__cr);
                if ($__cr !== '') { $rulesIn[] = mb_substr($__cr, 0, 40); }
            }
        }
        $rulesIn = array_values(array_unique(array_filter(array_map('strval', $rulesIn), function ($x) { return trim($x) !== ''; })));

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
            'rules'         => $rulesIn,
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

if (!function_exists('lwLotteryUpdate')) {
    /** 编辑抽奖配置（标题/奖品/名额/开奖时间/说明/规则/封面）。已开奖的也能改，但不允许把时间改回过去。 */
    function lwLotteryUpdate(int $lotteryId, array $d): array
    {
        $l = lwLotteryGet($lotteryId);
        if (!$l) { return ['ok' => false, 'error' => '抽奖不存在']; }

        $title = trim((string) ($d['title'] ?? ($l['title'] ?? '')));
        $prize = trim((string) ($d['prize'] ?? ($l['prize'] ?? '')));
        if ($title === '' || $prize === '') { return ['ok' => false, 'error' => '标题和奖品不能为空']; }
        $count = max(1, min(100, (int) ($d['prize_count'] ?? ($l['prize_count'] ?? 1))));

        $drawAt = trim((string) ($d['draw_at'] ?? ''));
        $ts = $drawAt !== '' ? strtotime($drawAt) : (int) (strtotime((string) ($l['draw_at'] ?? '')) ?: 0);
        if (!$ts) { return ['ok' => false, 'error' => '开奖时间格式不对']; }
        if (($l['status'] ?? 'open') === 'open' && $ts <= time()) {
            return ['ok' => false, 'error' => '进行中的抽奖，开奖时间必须晚于现在'];
        }

        $rulesIn = (array) ($d['rules'] ?? []);
        if (!$rulesIn) {
            if (!empty($d['rule_follow']))  { $rulesIn[] = '关注'; }
            if (!empty($d['rule_repost']))  { $rulesIn[] = '转发'; }
            if (!empty($d['rule_comment'])) { $rulesIn[] = '评论'; }
            if (!empty($d['rule_like']))    { $rulesIn[] = '点赞'; }
        }
        $customRules = (string) ($d['rule_custom'] ?? '');
        if ($customRules !== '') {
            foreach (preg_split('/[\n,，、;；]+/u', $customRules) as $__cr) {
                $__cr = trim($__cr);
                if ($__cr !== '') { $rulesIn[] = mb_substr($__cr, 0, 40); }
            }
        }
        $rulesIn = array_values(array_unique(array_filter(array_map('strval', $rulesIn), function ($x) { return trim($x) !== ''; })));

        $patch = [
            'title'         => mb_substr($title, 0, 120),
            'prize'         => mb_substr($prize, 0, 120),
            'prize_count'   => $count,
            'description'   => mb_substr(trim((string) ($d['description'] ?? ($l['description'] ?? ''))), 0, 2000),
            'draw_at'       => date('Y-m-d H:i:s', $ts),
            'rule_like'     => in_array('点赞', $rulesIn, true) ? 1 : 0,
            'rule_comment'  => in_array('评论', $rulesIn, true) ? 1 : 0,
            'rule_repost'   => in_array('转发', $rulesIn, true) ? 1 : 0,
            'rules'         => $rulesIn,
        ];
        if (!empty($d['cover_image'])) { $patch['cover_image'] = (string) $d['cover_image']; }
        getFS()->update('lotteries', $lotteryId, $patch);
        return ['ok' => true, 'id' => $lotteryId];
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
        /* 点赞 / 评论 规则的强校验：只有当抽奖关联了动态（post_id>0）时才能核验，
         * 没有关联动态的抽奖保持"仅提示"，避免把用户拦在门外却无从操作。 */
        $needLike = false; $needComment = false; $needRepost = false;
        foreach ($rules as $r) {
            if (mb_strpos((string) $r, '点赞') !== false) { $needLike = true; }
            if (mb_strpos((string) $r, '评论') !== false) { $needComment = true; }
            if (mb_strpos((string) $r, '转发') !== false) { $needRepost = true; }
        }
        $lotPostId = (int) ($l['post_id'] ?? 0);
        if ($lotPostId > 0 && $needRepost) {
            /* 「转发」规则：检查是否有本人发布的、引用了该动态的帖子（动态套动态，不复制数据） */
            $reposted = false;
            foreach ((array) getFS()->read('posts') as $__p) {
                if (!is_array($__p) || !empty($__p['is_deleted'])) { continue; }
                if ((int) ($__p['user_id'] ?? 0) !== $userId) { continue; }
                if ((int) ($__p['quote_post_id'] ?? 0) === $lotPostId) { $reposted = true; break; }
            }
            if (!$reposted) {
                return ['ok' => false, 'error' => '该抽奖要求先转发附带动态（写一条引用它的动态）', 'need_repost' => $lotPostId];
            }
        }
        if ($lotPostId > 0 && $needLike) {
            $liked = getFS()->findOne('post_likes', ['post_id' => $lotPostId, 'user_id' => $userId]);
            if (!$liked) {
                return ['ok' => false, 'error' => '该抽奖要求先给附带动态点赞', 'need_like' => $lotPostId];
            }
        }
        if ($lotPostId > 0 && $needComment) {
            $commented = getFS()->findOne('comments', ['post_id' => $lotPostId, 'user_id' => $userId]);
            if (!$commented) {
                return ['ok' => false, 'error' => '该抽奖要求先在附带动态下评论', 'need_comment' => $lotPostId];
            }
        }

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
            $__appUids = array_map('intval', $uids);
            foreach ((array) getFS()->read('lottery_reminders') as $__r) {
                if ((int) ($__r['lottery_id'] ?? 0) !== $lotteryId) { continue; }
                $uid = (int) ($__r['user_id'] ?? 0);
                if ($uid <= 0) { continue; }
                $__appUids[] = $uid;
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
        if (!empty($__appUids)) {
            /* 开奖推送按结果拆分：中奖者和未中奖者收到不同文案，避免"中奖了却看到通用文案"的错位 */
            $__winSet = array_flip(array_map('intval', $winners));
            $__wonPush = []; $__losePush = [];
            foreach (array_unique(array_map('intval', $__appUids)) as $__pu) {
                if ($__pu <= 0) { continue; }
                if (isset($__winSet[$__pu])) { $__wonPush[] = $__pu; } else { $__losePush[] = $__pu; }
            }
            if ($__wonPush) {
                lwLotteryAppPush($__wonPush, '🎉 恭喜中奖：' . (string) ($l['title'] ?? ''), '你参与的抽奖已开奖，恭喜中奖！点开查看领奖方式', '/pages/lottery.php?id=' . $lotteryId);
            }
            if ($__losePush) {
                lwLotteryAppPush($__losePush, '🎁 抽奖开奖：' . (string) ($l['title'] ?? ''), '抽奖已开奖，本次未中奖，下次好运～', '/pages/lottery.php?id=' . $lotteryId);
            }
        }

        return ['ok' => true, 'winners' => $winners];
    }
}

if (!function_exists('lwLotteryAppPush')) {
    /**
     * 把抽奖通知写进 App 推送队列（客户端轮询拉取）。
     * 共享主机没有推送服务凭据，站内通知之外再入队一条，App 下一次轮询即可弹出。
     * 失败绝不阻断主流程。
     */
    function lwLotteryAppPush(array $uids, string $title, string $content, string $url): void
    {
        $uids = array_values(array_unique(array_filter(array_map('intval', $uids), function ($n) { return $n > 0; })));
        if (!$uids) { return; }
        try {
            getFS()->insert('app_pushes', [
                'title'      => mb_substr($title, 0, 40),
                'content'    => mb_substr($content, 0, 300),
                'url'        => $url,
                'target'     => implode(',', array_slice($uids, 0, 500)),
                'created_at' => date('Y-m-d H:i:s'),
            ]);
        } catch (Throwable $e) {
            error_log('lwLotteryAppPush failed: ' . $e->getMessage());
        }
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
            if (!empty($targets)) {
                lwLotteryAppPush(array_keys($targets), '⏰ 抽奖即将开奖', '「' . (string) ($l['title'] ?? '') . '」10 分钟内开奖，记得来看结果', '/pages/lottery.php?id=' . $lid);
            }
            try { $fs->update('lotteries', $lid, ['pre_notified' => true]); } catch (Throwable $e3) {}
        }
        return $sent;
    }
}

if (!function_exists('lwLotteryTrackView')) {
    /** 详情浏览计数：同一会话内同一抽奖只记一次，避免刷量 */
    function lwLotteryTrackView(int $lotteryId): void
    {
        if ($lotteryId <= 0) { return; }
        $seen = $_SESSION['lottery_viewed'] ?? [];
        if (isset($seen[$lotteryId])) { return; }
        $seen[$lotteryId] = 1;
        $_SESSION['lottery_viewed'] = $seen;
        try {
            $l = lwLotteryGet($lotteryId);
            if ($l) { getFS()->update('lotteries', $lotteryId, ['views' => (int) ($l['views'] ?? 0) + 1]); }
        } catch (Throwable $e) {}
    }
}

if (!function_exists('lwLotteryRuleStatus')) {
    /**
     * 计算当前用户对某抽奖规则的完成情况（用于详情页规则芯片打勾）。
     * 返回 ['关注' => true, '点赞' => false, ...]；无法判断的规则不返回（前端保持中性）。
     */
    function lwLotteryRuleStatus(array $l, int $userId): array
    {
        $out = [];
        if ($userId <= 0) { return $out; }
        $fs = getFS();
        $postId = (int) ($l['post_id'] ?? 0);
        $ownerId = (int) ($l['created_by'] ?? 0);
        foreach ((array) ($l['rules'] ?? []) as $r) {
            $r = (string) $r;
            if (mb_strpos($r, '关注') !== false && $ownerId > 0 && $ownerId !== $userId) {
                $out['关注'] = (bool) $fs->findOne('follows', ['user_id' => $userId, 'target_id' => $ownerId]);
            } elseif (mb_strpos($r, '点赞') !== false && $postId > 0) {
                $out['点赞'] = (bool) $fs->findOne('post_likes', ['post_id' => $postId, 'user_id' => $userId]);
            } elseif (mb_strpos($r, '评论') !== false && $postId > 0) {
                $out['评论'] = (bool) $fs->findOne('comments', ['post_id' => $postId, 'user_id' => $userId]);
            } elseif (mb_strpos($r, '转发') !== false && $postId > 0) {
                $done = false;
                foreach ((array) $fs->read('posts') as $__p) {
                    if (!is_array($__p) || !empty($__p['is_deleted'])) { continue; }
                    if ((int) ($__p['user_id'] ?? 0) === $userId && (int) ($__p['quote_post_id'] ?? 0) === $postId) { $done = true; break; }
                }
                $out['转发'] = $done;
            }
        }
        return $out;
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
