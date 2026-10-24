<?php
/**
 * 用户等级与经验系统 —— 唯一来源
 *
 * 设计要点（改动前必读）：
 *   1. 经验值只存「累计总经验」一个数（user_levels.exp），**等级永远由总经验推导**，
 *      不单独落库。这样即使经验规则以后调整，也不会出现「等级和经验的账对不上」。
 *      落库的 level 字段只是为了列表页免于重复计算的缓存，任何时候都可以用
 *      lwLevelFromExp() 重算覆盖。
 *   2. 每用户一行（仿 checkins 表），键是 user_id。
 *   3. 有「每日经验上限」防刷：day 变了就重置 today_exp。上限可在后台配置。
 *   4. 本文件只声明函数，**不要 require config/config.php**（与 includes/actions/ 同一约束）。
 *      调用方保证 config 已加载（getFS / getSetting / updateSetting 可用）。
 *
 * 调用方：
 *   - includes/actions/*.php     业务动作里发经验
 *   - api/user/level.php         查询我的等级
 *   - api/user/growth.php        成长中心聚合
 *   - includes/user_level_badge.php 渲染等级徽章
 */

if (!defined('LW_LEVEL_LIB')) {
    define('LW_LEVEL_LIB', 1);

    /** 等级上限。30 级之后经验继续累加但不再升级，避免「等级通胀」失去意义 */
    if (!defined('LW_LEVEL_MAX')) {
        define('LW_LEVEL_MAX', 30);
    }
}

if (!function_exists('lwLevelDefaults')) {
    /**
     * 默认经验规则。键 => [每次经验, 中文说明]。
     * 后台「成长体系」页可覆盖（存 settings.level_exp_rules，JSON）。
     */
    function lwLevelDefaults(): array
    {
        return [
            'post_create'    => ['exp' => 10, 'label' => '发布帖子'],
            'post_comment'   => ['exp' => 5,  'label' => '发表评论'],
            'post_liked'     => ['exp' => 2,  'label' => '帖子被点赞'],
            'comment_liked'  => ['exp' => 1,  'label' => '评论被点赞'],
            'checkin'        => ['exp' => 8,  'label' => '每日签到'],
            'pm_sent'        => ['exp' => 1,  'label' => '发送私信'],
            'followed'       => ['exp' => 3,  'label' => '被他人关注'],
            'post_favorite'  => ['exp' => 2,  'label' => '帖子被收藏'],
            'invite'         => ['exp' => 20, 'label' => '成功邀请好友'],
            'invite_welcome' => ['exp' => 5,  'label' => '受邀加入'],
        ];
    }
}

if (!function_exists('lwLevelDailyCap')) {
    /** 每日经验上限（默认 200，防刷）。后台可改。 */
    function lwLevelDailyCap(): int
    {
        $v = (int)getSetting('level_daily_cap', '200');
        return $v > 0 ? $v : 200;
    }
}

if (!function_exists('lwLevelEnabled')) {
    /** 成长体系总开关。关闭后不再发经验、不显示徽章，但历史数据保留。 */
    function lwLevelEnabled(): bool
    {
        return getSetting('level_enabled', '1') !== '0';
    }
}

if (!function_exists('lwLevelRules')) {
    /**
     * 实际生效的经验规则（默认 + 后台覆盖）。
     * 后台只存「键 => 经验值」，说明文案始终取默认表，避免后台写坏文案。
     */
    function lwLevelRules(): array
    {
        $defaults = lwLevelDefaults();
        $raw = getSetting('level_exp_rules', '');
        if ($raw !== '') {
            $custom = json_decode($raw, true);
            if (is_array($custom)) {
                foreach ($custom as $k => $v) {
                    if (isset($defaults[$k]) && is_numeric($v)) {
                        $defaults[$k]['exp'] = max(0, (int)$v);
                    }
                }
            }
        }
        return $defaults;
    }
}

if (!function_exists('lwExpForLevel')) {
    /**
     * 达到第 $level 级所需的**累计**经验。
     * 曲线：cum(n) = 50 * (n-1) * n  →  1级0 / 2级100 / 3级300 / 4级600 / 5级1000 / 6级1500 …
     * 二次增长，前期升级快（给新用户正反馈），后期需要长期投入。
     */
    function lwExpForLevel(int $level): int
    {
        if ($level <= 1) {
            return 0;
        }
        if ($level > LW_LEVEL_MAX) {
            $level = LW_LEVEL_MAX;
        }
        return 50 * ($level - 1) * $level;
    }
}

if (!function_exists('lwLevelFromExp')) {
    /** 由累计总经验推导等级。用求根公式而非循环，避免高经验时循环过多。 */
    function lwLevelFromExp(int $exp): int
    {
        if ($exp <= 0) {
            return 1;
        }
        // 解 50*(n-1)*n <= exp  →  n^2 - n - exp/50 <= 0
        $n = (int)floor((1 + sqrt(1 + 4 * ($exp / 50))) / 2);
        if ($n < 1) {
            $n = 1;
        }
        if ($n > LW_LEVEL_MAX) {
            $n = LW_LEVEL_MAX;
        }
        // 浮点误差兜底：确保 cum(n) <= exp 且 cum(n+1) > exp
        while ($n > 1 && lwExpForLevel($n) > $exp) {
            $n--;
        }
        while ($n < LW_LEVEL_MAX && lwExpForLevel($n + 1) <= $exp) {
            $n++;
        }
        return $n;
    }
}

if (!function_exists('lwLevelTitles')) {
    /** 等级段位表： [最低等级, 段位名]。从高到低匹配。 */
    function lwLevelTitles(): array
    {
        return [
            [25, '传奇'],
            [20, '元老'],
            [15, '资深'],
            [10, '达人'],
            [6,  '活跃'],
            [3,  '常客'],
            [1,  '新人'],
        ];
    }
}

if (!function_exists('lwLevelTitle')) {
    /** 等级对应段位名 */
    function lwLevelTitle(int $level): string
    {
        foreach (lwLevelTitles() as $row) {
            if ($level >= $row[0]) {
                return $row[1];
            }
        }
        return '新人';
    }
}

if (!function_exists('lwLevelProgress')) {
    /**
     * 组装等级信息（纯函数，不查库）。
     *
     * @return array{level:int,title:string,exp:int,level_start:int,level_end:int,
     *               progress:int,to_next:int,percent:float,is_max:bool}
     */
    function lwLevelProgress(int $exp): array
    {
        if ($exp < 0) {
            $exp = 0;
        }
        $level     = lwLevelFromExp($exp);
        $levelStart = lwExpForLevel($level);
        $isMax     = $level >= LW_LEVEL_MAX;
        $levelEnd  = $isMax ? $levelStart : lwExpForLevel($level + 1);

        $span     = $isMax ? 0 : max(1, $levelEnd - $levelStart);
        $gained   = $exp - $levelStart;
        $toNext   = $isMax ? 0 : max(0, $levelEnd - $exp);
        $percent  = $isMax ? 100.0 : round(min(100, max(0, $gained / $span * 100)), 1);

        return [
            'level'       => $level,
            'title'       => lwLevelTitle($level),
            'exp'         => $exp,
            'level_start' => $levelStart,
            'level_end'   => $levelEnd,
            'progress'    => $gained,
            'to_next'     => $toNext,
            'percent'     => $percent,
            'is_max'      => $isMax,
        ];
    }
}

if (!function_exists('lwLevelRow')) {
    /**
     * 读取某用户的等级行（不存在则返回 exp=0 的空壳，不落库）。
     * 若库里的 level 与 exp 推出来的不一致，以 exp 为准并顺手修正缓存字段。
     */
    function lwLevelRow(int $userId): array
    {
        $fs = getFS();
        $row = $fs->findOne('user_levels', ['user_id' => $userId]);
        $exp = $row ? (int)($row['exp'] ?? 0) : 0;
        $info = lwLevelProgress($exp);

        if ($row && (int)($row['level'] ?? 0) !== $info['level']) {
            // 缓存字段漂移（多半是经验规则改过）→ 修正，失败也不影响本次返回
            $fs->update('user_levels', $row['id'], ['level' => $info['level']]);
            $row['level'] = $info['level'];
        }

        return [
            'user_id'    => $userId,
            'exp'        => $exp,
            'day'        => $row ? (string)($row['day'] ?? '') : '',
            'today_exp'  => $row ? (int)($row['today_exp'] ?? 0) : 0,
            'row_id'     => $row ? $row['id'] : null,
        ] + $info;
    }
}

if (!function_exists('lwBatchLevels')) {
    /**
     * 批量取多个用户的等级信息（评论列表/帖子列表用，避免逐条查库）。
     *
     * @param  int[] $userIds
     * @return array<int, array>  userId => levelInfo
     */
    function lwBatchLevels(array $userIds): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $userIds))));
        if (!$ids) {
            return [];
        }
        $wanted = array_flip($ids);
        $out = [];
        foreach ((array)getFS()->read('user_levels') as $row) {
            $uid = (int)($row['user_id'] ?? 0);
            if (isset($wanted[$uid])) {
                $out[$uid] = lwLevelProgress((int)($row['exp'] ?? 0));
            }
        }
        // 没记录的一律 1 级 0 经验（新用户）
        foreach ($ids as $uid) {
            if (!isset($out[$uid])) {
                $out[$uid] = lwLevelProgress(0);
            }
        }
        return $out;
    }
}

if (!function_exists('lwAwardExp')) {
    /**
     * 给用户发经验。这是**唯一的经验入口**，所有业务动作都走这里。
     *
     * @param int    $userId 收经验的用户
     * @param string $reason 规则键（lwLevelDefaults 的键）
     * @param int    $amount 覆盖默认值（不传则用规则表）
     * @param array  $meta   ['actor'=>昵称, 'url'=>跳转链接]，用于升级通知文案
     * @return array{ok:bool, awarded:int, capped:bool, level_up:bool, info:array}
     */
    function lwAwardExp(int $userId, string $reason, int $amount = -1, array $meta = []): array
    {
        $empty = [
            'ok' => false, 'awarded' => 0, 'capped' => false,
            'level_up' => false, 'info' => lwLevelProgress(0),
        ];

        if ($userId <= 0 || !lwLevelEnabled()) {
            return $empty;
        }

        $rules = lwLevelRules();
        if (!isset($rules[$reason])) {
            return $empty;
        }
        $exp = $amount >= 0 ? $amount : (int)$rules[$reason]['exp'];
        if ($exp <= 0) {
            return $empty;
        }

        $fs  = getFS();
        $row = $fs->findOne('user_levels', ['user_id' => $userId]);
        $today = date('Y-m-d');

        $before  = $row ? (int)($row['exp'] ?? 0) : 0;
        $day     = $row ? (string)($row['day'] ?? '') : '';
        $todayExp = ($day === $today) ? (int)($row['today_exp'] ?? 0) : 0;

        // 每日上限：超出部分直接丢弃（不报错，业务照常成功）
        $cap = lwLevelDailyCap();
        $remain = $cap - $todayExp;
        $capped = false;
        if ($remain <= 0) {
            return ['ok' => true, 'awarded' => 0, 'capped' => true, 'level_up' => false,
                    'info' => lwLevelProgress($before)];
        }
        if ($exp > $remain) {
            $exp = $remain;
            $capped = true;
        }

        $after = $before + $exp;

        $payload = [
            'user_id'   => $userId,
            'exp'       => $after,
            'level'     => lwLevelFromExp($after),   // 缓存字段，真源仍是 exp
            'day'       => $today,
            'today_exp' => $todayExp + $exp,
        ];

        if ($row) {
            if (!$fs->update('user_levels', $row['id'], $payload)) {
                return $empty;
            }
        } else {
            $created = $fs->insert('user_levels', $payload);
            if (!$created) {
                return $empty;
            }
        }

        $beforeInfo = lwLevelProgress($before);
        $afterInfo  = lwLevelProgress($after);
        $levelUp    = $afterInfo['level'] > $beforeInfo['level'];

        if ($levelUp) {
            lwNotifyLevelUp($userId, $afterInfo, $meta);
        }

        return [
            'ok'       => true,
            'awarded'  => $exp,
            'capped'   => $capped,
            'level_up' => $levelUp,
            'info'     => $afterInfo,
        ];
    }
}

if (!function_exists('lwNotifyLevelUp')) {
    /** 升级通知（写 notifications 表，复用既有通知面板，无需前端额外改动）。 */
    function lwNotifyLevelUp(int $userId, array $info, array $meta = []): void
    {
        try {
            getFS()->insert('notifications', [
                'user_id'    => $userId,
                'type'       => 'level_up',
                'content'    => '恭喜升到 Lv.' . $info['level'] . '「' . $info['title'] . '」！继续加油～',
                'post_id'    => 0,
                'post_title' => '',
                'from_user'  => '系统',
                'is_read'    => false,
            ]);
        } catch (Exception $e) {
            // 通知失败绝不能影响业务主流程
            error_log('lwNotifyLevelUp failed: ' . $e->getMessage());
        }
    }
}

if (!function_exists('lwLevelRank')) {
    /** 我的等级排名（经验榜）。返回 [rank, total]，rank 从 1 开始；无记录返回 [0, total]。 */
    function lwLevelRank(int $userId): array
    {
        $rows = (array)getFS()->read('user_levels');
        $total = 0;
        $mine = 0;
        $better = 0;
        foreach ($rows as $r) {
            $e = (int)($r['exp'] ?? 0);
            if ($e <= 0) {
                continue;
            }
            $total++;
            if ((int)($r['user_id'] ?? 0) === $userId) {
                $mine = $e;
            }
        }
        if ($mine <= 0) {
            return [0, $total];
        }
        foreach ($rows as $r) {
            if ((int)($r['exp'] ?? 0) > $mine) {
                $better++;
            }
        }
        return [$better + 1, $total];
    }
}

if (!function_exists('lwLevelTop')) {
    /**
     * 经验榜前 N 名（只返回 id/exp/level，昵称由调用方按需补）。
     * @return array<int, array{user_id:int,exp:int,level:int}>
     */
    function lwLevelTop(int $limit = 10): array
    {
        $rows = [];
        foreach ((array)getFS()->read('user_levels') as $r) {
            $e = (int)($r['exp'] ?? 0);
            if ($e <= 0) {
                continue;
            }
            $rows[] = [
                'user_id' => (int)($r['user_id'] ?? 0),
                'exp'     => $e,
                'level'   => lwLevelFromExp($e),
            ];
        }
        usort($rows, function ($a, $b) { return $b['exp'] <=> $a['exp']; });
        return array_slice($rows, 0, max(1, $limit));
    }
}
