<?php
/**
 * 成就徽章系统 —— 唯一来源
 *
 * 设计要点（改动前必读）：
 *   1. 成就**没有单独的经验入口**：判定所需的统计量（发帖数、获赞数、粉丝数…）全部
 *      从既有表实时算出来，不额外维护计数器 —— 计数器一定会漂移，实时算虽然多几次
 *      文件读，但本站数据量小（几万行以内）完全承受得起，且永远不会算错。
 *   2. 解锁记录按用户**聚合一行**（user_achievements.unlocked 是 {key: 时间} 的对象），
 *      而不是「一个成就一行」。本站量级下这样读最快，也方便整行原子写入。
 *   3. 解锁是幂等的：已解锁的不重复写、不重复发通知。
 *   4. 本文件只声明函数，**不要 require config/config.php**。
 *
 * 调用方：
 *   - includes/level.php 的调用点附近（业务动作后顺带 lwCheckAchievements）
 *   - api/user/achievements.php
 *   - api/user/growth.php
 *   - admin/growth.php（后台查看/重置）
 */

require_once __DIR__ . '/level.php';
require_once __DIR__ . '/text_linkify.php';

if (!defined('LW_ACH_LIB')) {
    define('LW_ACH_LIB', 1);
}

if (!function_exists('lwAchievementDefs')) {
    /**
     * 全部成就定义。
     *
     * 字段说明：
     *   key      唯一键，落库用，**不可改**（改了等于所有用户重新解锁）
     *   name     中文名
     *   desc     解锁条件说明（给用户看）
     *   icon     展示用 emoji
     *   group    分组（展示时归类）
     *   metric   统计量键（见 lwAchievementStats）
     *   need     达到该值即解锁
     *   flag     true 表示 metric 是布尔量（>=1 即解锁）
     */
    function lwAchievementDefs(): array
    {
        return [
            ['key' => 'first_post',   'name' => '初来乍到', 'desc' => '发布第一条动态',        'icon' => '🌱', 'group' => '内容', 'metric' => 'post_count',      'need' => 1],
            ['key' => 'post_10',      'name' => '笔耕不辍', 'desc' => '累计发布 10 条动态',    'icon' => '✍️', 'group' => '内容', 'metric' => 'post_count',      'need' => 10],
            ['key' => 'post_50',      'name' => '内容创作者', 'desc' => '累计发布 50 条动态',  'icon' => '📚', 'group' => '内容', 'metric' => 'post_count',      'need' => 50],
            ['key' => 'comment_10',   'name' => '热心互动', 'desc' => '发表 10 条评论',        'icon' => '💬', 'group' => '互动', 'metric' => 'comment_count',   'need' => 10],
            ['key' => 'comment_100',  'name' => '评论家',   'desc' => '发表 100 条评论',       'icon' => '🗣️', 'group' => '互动', 'metric' => 'comment_count',   'need' => 100],
            ['key' => 'likes_50',     'name' => '人气之星', 'desc' => '累计收到 50 个赞',      'icon' => '⭐', 'group' => '互动', 'metric' => 'likes_received',  'need' => 50],
            ['key' => 'likes_500',    'name' => '众望所归', 'desc' => '累计收到 500 个赞',     'icon' => '🌟', 'group' => '互动', 'metric' => 'likes_received',  'need' => 500],
            ['key' => 'fans_10',      'name' => '初露锋芒', 'desc' => '获得 10 位关注者',      'icon' => '👥', 'group' => '社交', 'metric' => 'fans',            'need' => 10],
            ['key' => 'fans_100',     'name' => '意见领袖', 'desc' => '获得 100 位关注者',     'icon' => '🎯', 'group' => '社交', 'metric' => 'fans',            'need' => 100],
            ['key' => 'checkin_7',    'name' => '七日之约', 'desc' => '连续签到 7 天',         'icon' => '📅', 'group' => '坚持', 'metric' => 'checkin_streak',  'need' => 7],
            ['key' => 'checkin_30',   'name' => '月度坚持', 'desc' => '连续签到 30 天',        'icon' => '🏅', 'group' => '坚持', 'metric' => 'checkin_streak',  'need' => 30],
            ['key' => 'checkin_100',  'name' => '百炼成钢', 'desc' => '累计签到 100 天',       'icon' => '💎', 'group' => '坚持', 'metric' => 'checkin_total',   'need' => 100],
            ['key' => 'level_5',      'name' => '小有成就', 'desc' => '成长等级达到 Lv.5',     'icon' => '🔰', 'group' => '成长', 'metric' => 'level',           'need' => 5],
            ['key' => 'level_10',     'name' => '达人之路', 'desc' => '成长等级达到 Lv.10',    'icon' => '🚀', 'group' => '成长', 'metric' => 'level',           'need' => 10],
            ['key' => 'level_20',     'name' => '登峰造极', 'desc' => '成长等级达到 Lv.20',    'icon' => '👑', 'group' => '成长', 'metric' => 'level',           'need' => 20],
            ['key' => 'topic_5',      'name' => '话题达人', 'desc' => '在 5 个不同话题下发过帖', 'icon' => '🏷️', 'group' => '内容', 'metric' => 'topic_count',     'need' => 5],
            ['key' => 'night_owl',    'name' => '夜猫子',   'desc' => '在 0:00–5:00 之间发过动态', 'icon' => '🦉', 'group' => '彩蛋', 'metric' => 'night_post', 'need' => 1],
            ['key' => 'early_bird',   'name' => '早起的鸟儿', 'desc' => '在 5:00–7:00 之间发过动态', 'icon' => '🐦', 'group' => '彩蛋', 'metric' => 'early_post', 'need' => 1],
            ['key' => 'pioneer',      'name' => '元老级用户', 'desc' => '注册满 365 天',        'icon' => '🕰️', 'group' => '成长', 'metric' => 'days_since_reg',  'need' => 365],
            ['key' => 'anon_help',    'name' => '匿名天使', 'desc' => '发布过匿名求助或失物招领', 'icon' => '🎭', 'group' => '彩蛋', 'metric' => 'anon_helpful',    'need' => 1],
        ];
    }
}

if (!function_exists('lwAchievementByKey')) {
    /** 取单个成就定义（不存在返回 null） */
    function lwAchievementByKey(string $key): ?array
    {
        foreach (lwAchievementDefs() as $d) {
            if ($d['key'] === $key) {
                return $d;
            }
        }
        return null;
    }
}

if (!function_exists('lwAchievementStats')) {
    /**
     * 实时汇总某用户的成就统计量。**一次读全表**，避免每个成就单独查。
     *
     * @return array<string,int|bool>
     */
    function lwAchievementStats(int $userId): array
    {
        if ($userId <= 0) {
            return [];
        }
        $fs = getFS();

        // ---- 帖子：数量 / 话题覆盖 / 时段彩蛋 / 匿名求助 ----
        $postCount = 0;
        $topics = [];
        $night = 0;
        $early = 0;
        $anonHelp = 0;
        $myPostIds = [];
        $helpfulCats = ['lost_found', 'study_help', 'study', 'help'];
        foreach ((array)$fs->read('posts') as $p) {
            if ((int)($p['user_id'] ?? 0) !== $userId) {
                continue;
            }
            // 只统计未删除的帖子，删帖不应影响成就（否则可反复刷）
            if ((string)($p['status'] ?? 'published') === 'deleted') {
                continue;
            }
            $postCount++;
            $pid = (int)($p['id'] ?? 0);
            if ($pid > 0) {
                $myPostIds[$pid] = true;
            }

            $tags = function_exists('lwExtractTags') ? lwExtractTags((string)($p['content'] ?? '') . ' ' . (string)($p['title'] ?? '')) : [];
            foreach ((array)$tags as $t) {
                $topics[mb_strtolower((string)$t)] = true;
            }

            $ts = strtotime((string)($p['created_at'] ?? ''));
            if ($ts) {
                $h = (int)date('G', $ts);
                if ($h >= 0 && $h < 5) {
                    $night = 1;
                } elseif ($h >= 5 && $h < 7) {
                    $early = 1;
                }
            }

            if (!empty($p['is_anonymous']) && in_array((string)($p['category'] ?? ''), $helpfulCats, true)) {
                $anonHelp = 1;
            }
        }

        // ---- 评论数 ----
        $commentCount = 0;
        $myCommentIds = [];
        foreach ((array)$fs->read('comments') as $c) {
            if ((int)($c['user_id'] ?? 0) === $userId) {
                $commentCount++;
                $cid = (int)($c['id'] ?? 0);
                if ($cid > 0) {
                    $myCommentIds[$cid] = true;
                }
            }
        }

        // ---- 收到的赞：帖子赞 + 评论赞 ----
        $likesReceived = 0;
        foreach ((array)$fs->read('post_likes') as $l) {
            if (isset($myPostIds[(int)($l['post_id'] ?? 0)])) {
                $likesReceived++;
            }
        }
        if ($myCommentIds) {
            foreach ((array)$fs->read('comment_likes') as $l) {
                if (isset($myCommentIds[(int)($l['comment_id'] ?? 0)])) {
                    $likesReceived++;
                }
            }
        }

        // ---- 粉丝 ----
        $fans = 0;
        foreach ((array)$fs->read('follows') as $f) {
            if ((int)($f['target_id'] ?? 0) === $userId) {
                $fans++;
            }
        }

        // ---- 签到 ----
        $streak = 0;
        $checkinTotal = 0;
        $ck = $fs->findOne('checkins', ['user_id' => $userId]);
        if ($ck) {
            $streak = (int)($ck['streak'] ?? 0);
            $checkinTotal = (int)($ck['total'] ?? 0);
        }

        // ---- 等级 ----
        $level = lwLevelFromExp(0);
        $lr = $fs->findOne('user_levels', ['user_id' => $userId]);
        if ($lr) {
            $level = lwLevelFromExp((int)($lr['exp'] ?? 0));
        }

        // ---- 注册天数 ----
        $days = 0;
        $u = $fs->findById('users', $userId);
        if ($u && !empty($u['created_at'])) {
            $ts = strtotime((string)$u['created_at']);
            if ($ts) {
                $days = (int)floor((time() - $ts) / 86400);
            }
        }

        return [
            'post_count'      => $postCount,
            'comment_count'   => $commentCount,
            'likes_received'  => $likesReceived,
            'fans'            => $fans,
            'checkin_streak'  => $streak,
            'checkin_total'   => $checkinTotal,
            'level'           => $level,
            'topic_count'     => count($topics),
            'night_post'      => $night,
            'early_post'      => $early,
            'anon_helpful'    => $anonHelp,
            'days_since_reg'  => $days,
        ];
    }
}

if (!function_exists('lwAchievementRow')) {
    /** 读取用户的解锁聚合行（无则返回空结构，不落库） */
    function lwAchievementRow(int $userId): array
    {
        $row = getFS()->findOne('user_achievements', ['user_id' => $userId]);
        $unlocked = ($row && is_array($row['unlocked'] ?? null)) ? $row['unlocked'] : [];
        return ['row_id' => $row ? $row['id'] : null, 'unlocked' => $unlocked];
    }
}

if (!function_exists('lwCheckAchievements')) {
    /**
     * 评估并解锁成就。**幂等**，可在任意业务动作后放心调用。
     *
     * @param  int   $userId
     * @param  bool  $notify 解锁时是否写站内通知
     * @return array 本次新解锁的成就定义列表
     */
    function lwCheckAchievements(int $userId, bool $notify = true): array
    {
        if ($userId <= 0) {
            return [];
        }

        $stats = lwAchievementStats($userId);
        if (!$stats) {
            return [];
        }

        $cur = lwAchievementRow($userId);
        $unlocked = $cur['unlocked'];
        $new = [];

        foreach (lwAchievementDefs() as $def) {
            $k = $def['key'];
            if (isset($unlocked[$k])) {
                continue;                                   // 已解锁，跳过
            }
            $metric = $def['metric'];
            $val = $stats[$metric] ?? 0;
            if ((int)$val >= (int)$def['need']) {
                $unlocked[$k] = date('Y-m-d H:i:s');
                $new[] = $def;
            }
        }

        if (!$new) {
            return [];
        }

        $fs = getFS();
        $payload = ['user_id' => $userId, 'unlocked' => $unlocked];
        $saved = $cur['row_id']
            ? $fs->update('user_achievements', $cur['row_id'], ['unlocked' => $unlocked])
            : (bool)$fs->insert('user_achievements', $payload);

        if (!$saved) {
            return [];                                      // 写失败就当没解锁，下次再来
        }

        if ($notify) {
            foreach ($new as $def) {
                try {
                    $fs->insert('notifications', [
                        'user_id'    => $userId,
                        'type'       => 'achievement',
                        'content'    => '解锁成就「' . $def['name'] . '」' . $def['icon'] . '：' . $def['desc'],
                        'post_id'    => 0,
                        'post_title' => '',
                        'from_user'  => '系统',
                        'is_read'    => false,
                    ]);
                } catch (Exception $e) {
                    error_log('achievement notify failed: ' . $e->getMessage());
                }
            }
        }

        return $new;
    }
}

if (!function_exists('lwGrowthAward')) {
    /**
     * 成长体系统一入口：发经验 + 顺带评估成就。
     *
     * 为什么把两件事合成一个函数：业务动作（点赞/评论/发帖…）里若各写两行，
     * 总有人只写发经验、忘了查成就，导致成就永远解锁不了。合成一个就不会漏。
     * 任何异常都被吞掉 —— 成长体系是**旁路功能**，绝不能让它拖垮主业务。
     *
     * @param int    $userId 收益用户（注意是「获赞的作者」而不是「点赞的人」）
     * @param string $reason 经验规则键（lwLevelDefaults 的键）
     * @param array  $meta   附加信息（暂未使用，留给升级通知文案扩展）
     * @return array{exp:array, achievements:array}
     */
    function lwGrowthAward(int $userId, string $reason, array $meta = []): array
    {
        $out = ['exp' => [], 'achievements' => []];
        if ($userId <= 0) {
            return $out;
        }
        try {
            $out['exp'] = lwAwardExp($userId, $reason, -1, $meta);
        } catch (Exception $e) {
            error_log('lwGrowthAward exp failed: ' . $e->getMessage());
        }
        try {
            $out['achievements'] = lwCheckAchievements($userId, true);
        } catch (Exception $e) {
            error_log('lwGrowthAward ach failed: ' . $e->getMessage());
        }
        return $out;
    }
}

if (!function_exists('lwAchievementSummary')) {
    /**
     * 成就墙数据：全部成就 + 是否解锁 + 当前进度（用于「还差 X」提示）。
     *
     * @return array{items:array, unlocked:int, total:int, percent:float}
     */
    function lwAchievementSummary(int $userId): array
    {
        $stats = lwAchievementStats($userId);
        $cur = lwAchievementRow($userId);
        $unlocked = $cur['unlocked'];

        $items = [];
        $count = 0;
        foreach (lwAchievementDefs() as $def) {
            $isOn = isset($unlocked[$def['key']]);
            if ($isOn) {
                $count++;
            }
            $metric = $def['metric'];
            $val = (int)($stats[$metric] ?? 0);
            $need = max(1, (int)$def['need']);
            $items[] = [
                'key'          => $def['key'],
                'name'         => $def['name'],
                'desc'         => $def['desc'],
                'icon'         => $def['icon'],
                'group'        => $def['group'],
                'unlocked'     => $isOn,
                'unlocked_at'  => $isOn ? (string)$unlocked[$def['key']] : '',
                'current'      => min($val, $need),
                'need'         => $need,
                'remaining'    => $isOn ? 0 : max(0, $need - $val),
                'percent'      => $isOn ? 100.0 : round(min(100, $val / $need * 100), 1),
            ];
        }

        $total = count($items);
        return [
            'items'    => $items,
            'unlocked' => $count,
            'total'    => $total,
            'percent'  => $total > 0 ? round($count / $total * 100, 1) : 0.0,
        ];
    }
}

if (!function_exists('lwBatchAchievementCounts')) {
    /**
     * 批量取「已解锁成就数」（用户主页/列表用，避免逐用户全表扫描）。
     * @param  int[] $userIds
     * @return array<int,int> userId => 已解锁数量
     */
    function lwBatchAchievementCounts(array $userIds): array
    {
        $ids = array_flip(array_values(array_unique(array_filter(array_map('intval', $userIds)))));
        $out = [];
        if (!$ids) {
            return $out;
        }
        foreach ((array)getFS()->read('user_achievements') as $row) {
            $uid = (int)($row['user_id'] ?? 0);
            if (isset($ids[$uid])) {
                $out[$uid] = is_array($row['unlocked'] ?? null) ? count($row['unlocked']) : 0;
            }
        }
        foreach (array_keys($ids) as $uid) {
            if (!isset($out[$uid])) {
                $out[$uid] = 0;
            }
        }
        return $out;
    }
}
