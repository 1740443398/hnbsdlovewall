<?php
/**
 * 每日访问统计
 *
 * ==========================================================================
 * 为什么需要这张表
 * ==========================================================================
 * users.last_visit 只记「最后一次访问时间」，一个问题回答不了：
 *   「今天有多少人来过？」
 *
 * 因为一个人今天下午来过、晚上又来过，last_visit 只剩晚上那一次；
 * 而昨天来过、今天没来的人，last_visit 停在昨天 —— 逻辑上能用
 * last_visit LIKE '今天%' 数出「今天有活动的人」，但如果用户一天内
 * 多次访问、或跨天边界，就容易漏算。
 *
 * 更重要的是：
 *   · 想知道「今天来了多少人」→ 需要按天去重的用户集合
 *   · 想知道「这周每天来了多少人」→ 需要保留历史
 *   · 想知道「新访客 vs 回访」→ 需要区分首次访问
 * 这些 last_visit 都给不了。
 *
 * ==========================================================================
 * 数据结构
 * ==========================================================================
 * 一行 = 一个用户在某一天的一次访问聚合：
 *   date      YYYY-MM-DD
 *   user_key  u{id} 或 g{session指纹}（与 online.php 的口径一致）
 *   user_id   登录用户 id，游客为 0
 *   nickname  昵称快照（用户改名后历史记录仍可读）
 *   visits    当天访问次数
 *   first_at  当天首次访问时间戳
 *   last_at   当天最后访问时间戳
 *   is_new    当天是否是该用户首次访问站点
 *
 * 为什么按 (date, user_key) 聚合而不是每次访问一行：
 *   一次访问一行的话，115 个用户每天刷新 20 次 = 2300 行/天，
 *   一个月就 7 万行，JSON 文件读写会明显变慢。
 *   聚合后每天最多「用户数」行，可控。
 * ==========================================================================
 */

if (!defined('LW_VISIT_STATS_LOADED')) {
    define('LW_VISIT_STATS_LOADED', 1);

    /** 保留多少天历史。超过就自动清理，避免文件无限增长。 */
    if (!defined('LW_VISIT_KEEP_DAYS')) {
        define('LW_VISIT_KEEP_DAYS', 120);
    }

    /**
     * 记录一次访问。
     *
     * 由 api/heartbeat.php 调用。节流：同一用户同一天只在
     * 「距上次记录超过 5 分钟」时才真正写盘，避免每次心跳都写文件。
     *
     * @param array|null $user 当前用户，null 表示游客
     * @return bool 是否真的写入了
     */
    function lwVisitTrack(?array $user): bool
    {
        try {
            $fs = getFS();
            $now = time();
            $today = date('Y-m-d', $now);

            $isGuest = ($user === null);
            if ($isGuest) {
                // 游客不记个人身份，只按会话指纹去重（不落 IP、不落 session id）
                $key = 'g' . substr(sha1(session_id()), 0, 16);
                $uid = 0;
                $nick = '';
            } else {
                $uid = (int)($user['id'] ?? 0);
                if ($uid <= 0) return false;
                $key = 'u' . $uid;
                $nick = mb_substr(trim((string)($user['nickname'] ?? '')), 0, 40);
                if ($nick === '') $nick = '用户' . $uid;
            }

            $rows = $fs->read('visit_stats');
            if (!is_array($rows)) $rows = [];

            // 找今天这个人是否已有记录
            $idx = -1;
            foreach ($rows as $i => $r) {
                if (($r['date'] ?? '') === $today && ($r['user_key'] ?? '') === $key) {
                    $idx = $i;
                    break;
                }
            }

            if ($idx >= 0) {
                // 节流：5 分钟内不重复累加，只更新 last_at
                $lastAt = (int)($rows[$idx]['last_at'] ?? 0);
                if ($now - $lastAt < 300) {
                    return false;
                }
                $rows[$idx]['visits'] = (int)($rows[$idx]['visits'] ?? 1) + 1;
                $rows[$idx]['last_at'] = $now;
                if ($nick !== '') $rows[$idx]['nickname'] = $nick;
                $fs->update('visit_stats', (int)($rows[$idx]['id'] ?? 0), $rows[$idx]);
            } else {
                // 判断是否首次访问站点（历史里有没有这个 key）
                $isNew = true;
                foreach ($rows as $r) {
                    if (($r['user_key'] ?? '') === $key) { $isNew = false; break; }
                }

                $fs->insert('visit_stats', [
                    'date'     => $today,
                    'user_key' => $key,
                    'user_id'  => $uid,
                    'nickname' => $nick,
                    'is_guest' => $isGuest ? 1 : 0,
                    'visits'   => 1,
                    'first_at' => $now,
                    'last_at'  => $now,
                    'is_new'   => $isNew ? 1 : 0,
                    'ts'       => $now,
                ]);
            }

            // 定期清理过期数据（约 1% 概率触发，避免每次都扫全表）
            if (random_int(1, 100) === 1) {
                lwVisitPrune();
            }

            return true;
        } catch (Throwable $e) {
            // 统计失败绝不能影响主流程
            return false;
        }
    }

    /**
     * 清理超过保留天数的旧记录。
     */
    function lwVisitPrune(): int
    {
        try {
            $fs = getFS();
            $cut = date('Y-m-d', time() - LW_VISIT_KEEP_DAYS * 86400);
            $rows = $fs->read('visit_stats');
            if (!is_array($rows)) return 0;

            $keep = [];
            $removed = 0;
            foreach ($rows as $r) {
                if ((string)($r['date'] ?? '') >= $cut) $keep[] = $r;
                else $removed++;
            }
            if ($removed > 0) {
                $fs->write('visit_stats', $keep);
            }
            return $removed;
        } catch (Throwable $e) {
            return 0;
        }
    }

    /**
     * 今日访问汇总。
     *
     * @return array{
     *   total:int, members:int, guests:int, new_users:int,
     *   visits:int, users:array
     * }
     */
    function lwVisitToday(): array
    {
        $out = [
            'total' => 0, 'members' => 0, 'guests' => 0,
            'new_users' => 0, 'visits' => 0, 'users' => [],
        ];
        try {
            $fs = getFS();
            $today = date('Y-m-d');
            $rows = $fs->read('visit_stats');
            if (!is_array($rows)) return $out;

            foreach ($rows as $r) {
                if ((string)($r['date'] ?? '') !== $today) continue;
                $out['total']++;
                $out['visits'] += (int)($r['visits'] ?? 1);
                if (!empty($r['is_guest'])) $out['guests']++;
                else $out['members']++;
                if (!empty($r['is_new'])) $out['new_users']++;
                if (empty($r['is_guest'])) {
                    $out['users'][] = [
                        'id'       => (int)($r['user_id'] ?? 0),
                        'nickname' => (string)($r['nickname'] ?? ''),
                        'visits'   => (int)($r['visits'] ?? 1),
                        'last_at'  => (int)($r['last_at'] ?? 0),
                        'is_new'   => !empty($r['is_new']),
                    ];
                }
            }

            // 按最后访问时间倒序，最近活跃的排前面
            usort($out['users'], function ($a, $b) {
                return ($b['last_at'] ?? 0) <=> ($a['last_at'] ?? 0);
            });
        } catch (Throwable $e) {
        }
        return $out;
    }

    /**
     * 最近 N 天的访问趋势（按天聚合）。
     *
     * @return array<int, array{date:string, label:string, total:int, members:int}>
     */
    function lwVisitTrend(int $days = 7): array
    {
        $days = max(1, min(90, $days));
        $out = [];
        try {
            $fs = getFS();
            $rows = $fs->read('visit_stats');
            if (!is_array($rows)) $rows = [];

            // 先按日期聚合
            $byDate = [];
            foreach ($rows as $r) {
                $d = (string)($r['date'] ?? '');
                if ($d === '') continue;
                if (!isset($byDate[$d])) $byDate[$d] = ['total' => 0, 'members' => 0];
                $byDate[$d]['total']++;
                if (empty($r['is_guest'])) $byDate[$d]['members']++;
            }

            for ($i = $days - 1; $i >= 0; $i--) {
                $d = date('Y-m-d', strtotime("-$i days"));
                $out[] = [
                    'date'    => $d,
                    'label'   => date('m/d', strtotime($d)),
                    'total'   => $byDate[$d]['total'] ?? 0,
                    'members' => $byDate[$d]['members'] ?? 0,
                ];
            }
        } catch (Throwable $e) {
        }
        return $out;
    }
}