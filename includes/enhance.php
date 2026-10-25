<?php
/**
 * 站点增强层：日志、订阅、推荐、书签、系列、标签、通知聚合。
 * =====================================================================
 *
 * 设计原则：
 *   1. 不重复已有能力 —— 已有 search/favorite/draft/announcement/growth 等，这里只补缺口；
 *   2. 全部走 JSON 文件存储（与站点一致，无数据库）；
 *   3. 每个函数都要能承受「表不存在」「字段缺失」「并发写」三种情况；
 *   4. 读路径尽量命中 HTTP 缓存，写路径一律 no-store。
 *
 * 新增数据表（首次使用自动创建）：
 *   bookmarks       收藏夹（多集合，区别于 post_favorites 的单一收藏）
 *   series          专栏/连载（把多篇帖子组织成有序系列）
 *   series_items    系列与帖子的关联
 *   tags            标签表（名称、slug、计数）
 *   post_tags       帖子-标签关联
 *   subscriptions   订阅（RSS / 邮件 / 站内）
 *   user_prefs      用户偏好（推荐算法的输入）
 *   view_log        浏览记录（用于推荐与「最近浏览」）
 *   hot_cache       热度排行缓存（定时重算）
 */

if (!defined('LW_ENHANCE_LOADED')) {
    define('LW_ENHANCE_LOADED', 1);

    /** 安全读表：不存在返回空数组，绝不抛异常。 */
    function lwEnhRead($table, $where = [])
    {
        try {
            $fs = getFS();
            return $where ? $fs->find($table, $where) : $fs->read($table);
        } catch (Throwable $e) {
            return [];
        }
    }

    /** 安全写：失败返回 null，调用方自己决定是否提示。 */
    function lwEnhInsert($table, array $row)
    {
        try {
            $fs = getFS();
            // insert 会自动补 id / created_at / updated_at
            $fs->insert($table, $row);
            return true;
        } catch (Throwable $e) {
            return false;
        }
    }

    /** slug 化：中文保留，空格与符号转连字符。 */
    function lwSlugify(string $s): string
    {
        $s = trim($s);
        // 保留字母数字中文，其余转 -
        $s = preg_replace('/[^\p{L}\p{N}]+/u', '-', $s);
        $s = trim((string)$s, '-');
        return mb_strtolower($s, 'UTF-8');
    }

    /**
     * 从帖子内容抽取标签候选。
     *
     * 规则：先看作者手填的 tags 字段；没有就从标题里取 #话题# 与常见关键词。
     * 刻意保守 —— 宁可少打标签，也不要把「的」「了」这类字当标签。
     */
    function lwExtractTags(array $post): array
    {
        $tags = [];
        if (!empty($post['tags'])) {
            if (is_array($post['tags'])) {
                $tags = $post['tags'];
            } else {
                $tags = preg_split('/[,，\s#]+/u', (string)$post['tags']) ?: [];
            }
        }
        // #话题# 形式
        $title = (string)($post['title'] ?? '');
        if (preg_match_all('/#([^#\s]{2,16})#/u', $title, $m)) {
            $tags = array_merge($tags, $m[1]);
        }
        $out = [];
        foreach ($tags as $t) {
            $t = trim((string)$t);
            if ($t === '' || mb_strlen($t) > 16) {
                continue;
            }
            // 过滤纯符号/纯数字/单字
            if (mb_strlen($t) < 2 || preg_match('/^[\p{P}\p{S}\d]+$/u', $t)) {
                continue;
            }
            $out[lwSlugify($t)] = $t;
        }

        // 【兜底】没有显式标签时，用分类名当标签。
        //
        // 为什么需要：标签云若完全依赖作者手写 #话题#，一旦大家懒得写，
        // 「主题」页就是空白 —— 功能没坏，但用户看到的是「什么都没有」。
        // 分类本身是有意义的聚合维度（学习求助 / 交友闲聊 / 表白 …），
        // 拿它当默认标签能保证任何有帖子的站都有可点的主题入口。
        if (empty($out)) {
            $cat = (string)($post['category'] ?? '');
            $map = [
                'announcement' => '全站公告',
                'lost_found' => '寻物启事',
                'study_help' => '学习求助',
                'social_chat' => '交友闲聊',
                'confession' => '表白',
                'school_info' => '校园打听',
                'other' => '其他',
            ];
            $label = $map[$cat] ?? '';
            if ($label !== '') {
                $out[lwSlugify($label)] = $label;
            }
        }
        return array_values($out);
    }

    /**
     * 记录一次浏览（用于推荐与最近浏览）。
     *
     * 去重策略：同一用户同一帖 1 小时内只记一次，
     * 否则刷一下页面就把推荐权重刷爆了。
     */
    function lwRecordView(int $postId, ?array $user): void
    {
        if ($postId <= 0) {
            return;
        }
        $uid = (int)($user['id'] ?? 0);
        $key = $uid > 0 ? (string)$uid : ('ip:' . substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 40));
        $now = time();
        // 用内存标记 + 表内时间窗双重去重（内存省一次读）
        static $seen = [];
        if (isset($seen[$key . ':' . $postId])) {
            return;
        }
        $seen[$key . ':' . $postId] = true;

        $rows = lwEnhRead('view_log');
        foreach ($rows as $r) {
            if ((int)($r['post_id'] ?? 0) === $postId
                && (string)($r['viewer'] ?? '') === $key
                && $now - (int)($r['viewed_at'] ?? 0) < 3600) {
                return;
            }
        }
        lwEnhInsert('view_log', [
            'post_id' => $postId,
            'user_id' => $uid,
            'viewer' => $key,
            'viewed_at' => $now,
        ]);
        // 控制表大小：超过 5000 条就砍掉最旧的 20%
        if (count($rows) > 5000) {
            try {
                $fs = getFS();
                $sorted = $rows;
                usort($sorted, static fn($a, $b) => ((int)($a['viewed_at'] ?? 0)) <=> ((int)($b['viewed_at'] ?? 0)));
                $drop = (int)(count($sorted) * 0.2);
                for ($i = 0; $i < $drop; $i++) {
                    $fs->delete('view_log', $sorted[$i]['id']);
                }
            } catch (Throwable $e) {
            }
        }
    }

    /**
     * 「你可能感兴趣」推荐。
     *
     * 算法（轻量、无 ML、可解释）：
     *   1. 取用户最近浏览/点赞/收藏的帖子 -> 收集它们的标签
     *   2. 按标签频次降序，找出带这些标签、且用户没看过的帖子
     *   3. 同标签越多分越高；同分类 +2；热度（赞+评论）加成
     *   4. 冷启动（新用户/无记录）：退回全站热门
     *
     * 为什么不用协同过滤：本站用户量小、数据稀疏，协同过滤效果反而差，
     * 而且每次都要全表扫。标签匹配在这个规模下既快又准。
     */
    function lwRecommend(array $posts, ?array $user, int $limit = 6): array
    {
        $uid = (int)($user['id'] ?? 0);
        $liked = [];
        $seenIds = [];

        if ($uid > 0) {
            foreach (lwEnhRead('view_log', ['user_id' => $uid]) as $r) {
                $seenIds[(int)($r['post_id'] ?? 0)] = true;
            }
            foreach (lwEnhRead('post_likes', ['user_id' => $uid]) as $r) {
                $pid = (int)($r['post_id'] ?? 0);
                $liked[$pid] = true;
                $seenIds[$pid] = true;
            }
            foreach (lwEnhRead('post_favorites', ['user_id' => $uid]) as $r) {
                $pid = (int)($r['post_id'] ?? 0);
                $liked[$pid] = true;
                $seenIds[$pid] = true;
            }
        }

        // 已看过的帖子才有「标签偏好」，没看过的谈不上兴趣
        $tagWeight = [];
        $catWeight = [];
        foreach ($posts as $p) {
            $pid = (int)($p['id'] ?? 0);
            if (!isset($liked[$pid]) && !isset($seenIds[$pid])) {
                continue;
            }
            foreach (lwExtractTags($p) as $t) {
                $k = lwSlugify($t);
                $tagWeight[$k] = ($tagWeight[$k] ?? 0) + 1;
            }
            $c = (string)($p['category'] ?? '');
            if ($c !== '') {
                $catWeight[$c] = ($catWeight[$c] ?? 0) + 1;
            }
        }

        // 冷启动：全站热门
        if (empty($tagWeight) && empty($catWeight)) {
            $hot = $posts;
            usort($hot, static function ($a, $b) {
                $sa = (int)($a['likes'] ?? 0) * 2 + (int)($a['comments'] ?? 0) * 3 + (int)($a['views'] ?? 0);
                $sb = (int)($b['likes'] ?? 0) * 2 + (int)($b['comments'] ?? 0) * 3 + (int)($b['views'] ?? 0);
                return $sb <=> $sa;
            });
            return array_slice($hot, 0, $limit);
        }

        $scored = [];
        foreach ($posts as $p) {
            $pid = (int)($p['id'] ?? 0);
            if ($pid <= 0 || isset($seenIds[$pid])) {
                continue;
            }
            $score = 0.0;
            foreach (lwExtractTags($p) as $t) {
                $score += ($tagWeight[lwSlugify($t)] ?? 0) * 3;
            }
            $score += ($catWeight[(string)($p['category'] ?? '')] ?? 0) * 2;
            // 热度加成（上限 10 分，避免一条老爆款长期霸榜）
            $heat = (int)($p['likes'] ?? 0) * 2 + (int)($p['comments'] ?? 0) * 3;
            $score += min(10, $heat / 20);
            if ($score > 0) {
                $scored[] = ['score' => $score, 'post' => $p];
            }
        }
        usort($scored, static fn($a, $b) => $b['score'] <=> $a['score']);
        return array_slice(array_column($scored, 'post'), 0, $limit);
    }

    /**
     * 热度排行（带时间衰减）。
     *
     * score = (赞*2 + 评论*3 + 浏览*0.2) / (小时数 + 2)^1.5
     *
     * 分母的 ^1.5 是关键：它让「昨天的高分帖」快速沉下去，
     * 而不是像纯累加那样永远霸占榜首。这是 Hacker News 用的同一套思路。
     */
    function lwHotScore(array $p): float
    {
        $raw = (int)($p['likes'] ?? 0) * 2.0
            + (int)($p['comments'] ?? 0) * 3.0
            + (int)($p['views'] ?? 0) * 0.2;
        $ts = strtotime((string)($p['created_at'] ?? 'now')) ?: time();
        $hours = max(0.5, (time() - $ts) / 3600.0);
        return $raw / pow($hours + 2.0, 1.5);
    }


    /**
     * 帖子列表的输出投影（三个新接口共用）。
     *
     * 为什么要统一：bookmarks / tags / recommend 都要把帖子数组返回给前端，
     * 如果各自手拼字段，很容易出现「这里少了个 like_count、那里漏了 author」
     * 的碎片化问题。统一走这里，字段永远是同一套。
     *
     * 字段选择原则：只给 UI 真正要用的，不下发 visible_to / exclude_to 这类隐私字段
     * （与 api/posts/list.php 的脱敏口径一致）。
     */
    function lwPostListPayload(array $posts, int $limit = 0): array
    {
        if ($limit > 0) {
            $posts = array_slice($posts, 0, $limit);
        }
        $userNames = [];
        $userAvatars = [];
        try {
            foreach (getFS()->read('users') as $u) {
                $userNames[(int)$u['id']] = (string)($u['nickname'] ?? $u['qq'] ?? '');
                $userAvatars[(int)$u['id']] = (string)($u['avatar'] ?? '');
            }
        } catch (Throwable $e) {
        }

        $out = [];
        foreach ($posts as $p) {
            if (!is_array($p)) {
                continue;
            }
            $uid = (int)($p['user_id'] ?? 0);
            $out[] = [
                'id' => (int)($p['id'] ?? 0),
                'title' => (string)($p['title'] ?? ''),
                'content' => mb_substr(trim((string)($p['content'] ?? '')), 0, 400),
                'category' => (string)($p['category'] ?? ''),
                'author' => [
                    'id' => $uid,
                    'nickname' => !empty($p['is_anonymous']) ? '匿名同学' : ($userNames[$uid] ?? ''),
                    'avatar' => !empty($p['is_anonymous']) ? '' : ($userAvatars[$uid] ?? ''),
                ],
                'images' => is_array($p['images'] ?? null) ? array_values($p['images']) : [],
                'like_count' => (int)($p['likes'] ?? 0),
                'comment_count' => (int)($p['comments'] ?? 0),
                'view_count' => (int)($p['views'] ?? 0),
                'created_at' => (string)($p['created_at'] ?? ''),
                'tags' => lwExtractTags($p),
            ];
        }
        return $out;
    }

    /** 按热度排序。 */
    function lwSortByHot(array $posts): array
    {
        usort($posts, static fn($a, $b) => lwHotScore($b) <=> lwHotScore($a));
        return $posts;
    }
}
