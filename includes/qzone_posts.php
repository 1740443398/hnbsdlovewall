<?php
/**
 * QQ 空间动态 → 站内真实帖子
 * ---------------------------------------------------------------------------
 * 站长要的不是「外挂卡片」，是**和普通帖子一样**的帖子：
 *   · 能点赞、能评论
 *   · 按发布时间排进信息流（不置顶）
 *   · 详情页写明「自动爬取自 QQ 空间」并给原链接
 *
 * 所以这里把 data/qzone_items.json 里的说说映射成 posts 表记录，
 * 作者统一是机器人账号（昵称 / 头像见 lwQzoneBotUser()）。
 *
 * 幂等：按 source_tid 去重，重复跑只更新（图片地址、QQ 原赞数、原评论），不会重复发帖。
 */

if (!function_exists('lwQzonePostTitle')) {
    /**
     * 爬取帖的标题。
     * 这些说说本身没有标题（正文也常为空），列表/详情页 h1 会空白很难看。
     * 用 OCR 首行当标题（多为「墙墙投稿」「表白墙」这类开头），没有就退固定文案。
     */
    function lwQzonePostTitle(string $tid): string
    {
        $ocrInc = __DIR__ . '/qzone_ocr.php';
        if (is_file($ocrInc)) {
            require_once $ocrInc;
            if (function_exists('lwQzoneOcrText')) {
                $t = trim(lwQzoneOcrText($tid));
                if ($t !== '') {
                    $first = trim((string) strtok($t, "\n"));
                    $first = preg_replace('/\s+/u', ' ', $first);
                    if ($first !== '' && mb_strlen($first) >= 2) {
                        return mb_substr($first, 0, 24);
                    }
                }
            }
        }
        return '表白墙投稿';
    }
}

if (!function_exists('lwQzoneBotUser')) {
    /** 取（或创建）机器人账号：A.淮南北师大最美墙墙 / 3893174881 的 QQ 头像。 */
    function lwQzoneBotUser(): ?array
    {
        $fs = getFS();
        $found = $fs->findOne('users', ['is_bot' => 1, 'qq' => '3893174881']);
        if ($found) { return $found; }

        $rec = [
            'qq'              => '3893174881',
            'uuid'            => generateUUID(),
            'password_hash'   => hashPassword(bin2hex(random_bytes(16))), // 随机密码，不对外可登录
            'nickname'        => 'A.淮南北师大最美墙墙',
            'avatar'          => 'https://q.qlogo.cn/headimg_dl?dst_uin=3893174881&spec=100',
            'role'            => 'user',
            'security_stamp'  => generateSecurityStamp(),
            'is_banned'       => 0,
            'invite_code'     => 'QZONE' . substr(sha1('3893174881'), 0, 4),
            'invited_by'      => 0,
            'is_bot'          => 1,
            'bot_kind'        => 'qzone',
            'bio'             => 'QQ 空间表白墙自动同步账号',
        ];
        $created = $fs->insert('users', $rec);
        return $created ?: null;
    }
}

if (!function_exists('lwQzoneItemComments')) {
    /**
     * 取一条说说的评论列表。
     *
     * 优先用 qzone_comments 表（同步时按评论抓取、可能补全更多字段），
     * 表里没有时回退到 item 自带的 comment_list JSON ——
     * 否则「评论被同步进 comment_list 但没写表」这种情形会一条都导不进来。
     */
    function lwQzoneItemComments(string $tid, array $item, array $byTid): array
    {
        if (isset($byTid[$tid]) && is_array($byTid[$tid]) && $byTid[$tid]) {
            return $byTid[$tid];
        }
        $raw = $item['comment_list'] ?? '';
        if (is_string($raw) && $raw !== '') {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) { return $decoded; }
        }
        return [];
    }
}

if (!function_exists('lwQzoneShadowUser')) {
    /**
     * QQ 评论人 → 站内「影子用户」。
     *
     * 站长的要求：把 QQ 空间那条帖子下的评论一并搬过来，评论人就用 QQ 名 + QQ 头像。
     * 站内的评论渲染依赖 comments.user_id → users 记录（取昵称与头像），
     * 所以这里给每个 QQ 评论人建一条轻量用户记录：
     *   · 昵称用 QQ 名，头像按 uin 拼 QQ 头像（q.qlogo.cn）
     *   · 随机密码 + 随机 security_stamp → 无法登录（不是可登录账号）
     *   · 按 qq（uin）去重，同一个人只建一次
     *   · source 标记来源，方便后台区分「真实注册用户」与「同步来的评论人」
     */
    function lwQzoneShadowUser(string $uin, string $nick, string $avatar = ''): ?array
    {
        $uin = preg_replace('/\D/', '', $uin);
        if ($uin === '') { return null; }
        /* 昵称里也可能带 QQ 表情码（实测有个评论人叫「实习盖章打卡论文[em]e258679[/em]🤗🤗」），
           显示在墙上就是乱码，剥掉。 */
        $nick = trim((string) preg_replace('/\[em\]e\d+\[\/em\]/i', '', $nick));
        $fs = getFS();
        $found = $fs->findOne('users', ['qq' => $uin]);
        if ($found) { return $found; }

        if ($avatar === '') {
            $avatar = 'https://q.qlogo.cn/headimg_dl?dst_uin=' . $uin . '&spec=100';
        }
        $rec = [
            'qq'             => $uin,
            'uuid'           => generateUUID(),
            'password_hash'  => hashPassword(bin2hex(random_bytes(16))),
            'nickname'       => $nick !== '' ? $nick : ('QQ用户' . substr($uin, -4)),
            'avatar'         => $avatar,
            'role'           => 'user',
            'security_stamp' => generateSecurityStamp(),
            'is_banned'      => 0,
            'invite_code'    => 'QZC' . substr(sha1($uin), 0, 5),
            'invited_by'     => 0,
            'is_bot'         => 0,
            'bio'            => '',
            'source'         => 'qzone_comment_import',
        ];
        $created = $fs->insert('users', $rec);
        return $created ?: null;
    }
}

if (!function_exists('lwQzoneImportComments')) {
    /**
     * 把「帖子里挂着的 QQ 评论」变成站内真实评论。返回新增条数。
     *
     * $map: [post_id => [评论数组, ...]]
     *
     * 幂等：按 comments.qq_tid 去重，重复同步不会重复插入。
     * 性能：先在内存里建立已存在集合，再一次性 insertMany —— 几百条评论也只写一次盘。
     */
    function lwQzoneImportComments(array $map): int
    {
        if (!$map) { return 0; }
        $fs = getFS();
        $known = [];
        $postTimes = [];
        foreach ((array) $fs->read('posts') as $pp) {
            $pt = (string) ($pp['created_at'] ?? '');
            if ($pt !== '') { $postTimes[(int) ($pp['id'] ?? 0)] = (int) strtotime($pt); }
        }
        /* 【去重键必须带上 post_id】
         * 原来只用 qq_tid 做全局去重：同一条评论 id 只要在任意帖子下出现过，
         * 其它帖子下的同 id 评论就被当成「已存在」直接跳过。
         * 实测结果就是 50 条帖子只有 9 条挂上了评论，其余全是「还没有评论」。
         * 按 (post_id, qq_tid) 去重才是正确的语义。 */
        foreach ((array) $fs->read('comments') as $c) {
            if (!empty($c['qq_tid'])) {
                $known[(int) ($c['post_id'] ?? 0) . '|' . (string) $c['qq_tid']] = 1;
            }
        }
        $rows = [];
        foreach ($map as $postId => $list) {
            foreach ((array) $list as $c) {
                /* 【字段兼容】评论有两个来源，字段名不一样：
                 *   · API/comment_list：tid / author / author_uin / author_avatar / created_at
                 *   · qzone_comments 表：item_tid / author / author_uin / posted_ts（没有 tid）
                 * 原来只认 tid，走表路径时全部被当成空 id 跳过 —— 一条都导不进来。 */
                $rawId = (string) ($c['tid'] ?? ($c['qq_tid'] ?? ($c['id'] ?? '')));
                $uin  = (string) ($c['author_uin'] ?? ($c['uin'] ?? ''));
                $name = (string) ($c['author'] ?? ($c['name'] ?? ($c['nickname'] ?? '')));
                $av   = (string) ($c['author_avatar'] ?? ($c['avatar'] ?? ($c['icon'] ?? '')));
                $text = trim((string) ($c['content'] ?? ''));
                /* 早期抓取存下来的评论里可能带 QQ 表情码 [em]e258679[/em]，
                   API 路径已在归一化时剥离，这里再兜一层（表格路径进来的没剥过）。 */
                $text = trim((string) preg_replace('/\[em\]e\d+\[\/em\]/i', '', $text));
                if ($text === '') { continue; }
                $ts   = (int) ($c['created_at'] ?? ($c['posted_ts'] ?? 0));
                if ($ts <= 0) { $ts = (int) ($postTimes[(int) $postId] ?? 0); }
                /* 没有 QQ 评论 id 时，用「帖子+作者+内容+时间」生成稳定指纹做去重 */
                $qtid = $rawId !== '' ? $rawId
                      : 'h' . substr(md5($postId . '|' . $uin . '|' . $name . '|' . $text . '|' . $ts), 0, 20);
                $dedupeKey = (int) $postId . '|' . $qtid;
                if (isset($known[$dedupeKey])) { continue; }
                /* 没有 uin 的评论人（早期抓取或对方隐藏 QQ 号）也要能显示：
                   用「昵称+评论id」合成一个稳定假 uin，避免整条评论被丢弃。 */
                if ($uin === '') {
                    $uin = 'x' . substr(md5($name . '|' . $qtid), 0, 15);
                }
                $u = lwQzoneShadowUser($uin, $name, $av);
                if (!$u) { continue; }
                $rows[] = [
                    'post_id'      => (int) $postId,
                    'user_id'      => (int) $u['id'],
                    'content'      => $text,
                    'is_anonymous' => 0,
                    'parent_id'    => 0,
                    'qq_tid'       => $qtid,
                    'source'       => 'qzone',
                    /* 保留 QQ 评论原时间（insertMany 的第三参数会尊重它） */
                    'created_at'   => $ts > 0 ? date('Y-m-d H:i:s', $ts) : '',
                ];
                $known[$dedupeKey] = 1;
            }
        }
        if (!$rows) { return 0; }
        $inserted = $fs->insertMany('comments', $rows, true);
        return count($inserted);
    }
}

if (!function_exists('lwQzonePostImages')) {
    /** 说说里的图片 → 站内代理地址（走本地缓存，避免 QQ 防盗链与过期签名） */
    function lwQzonePostImages(string $tid, array $urls): array
    {
        $out = [];
        foreach (array_values($urls) as $i => $u) {
            if (!is_string($u) || $u === '') { continue; }
            $out[] = '/api/qzone/image.php?tid=' . urlencode($tid) . '&i=' . $i;
        }
        return $out;
    }
}

if (!function_exists('lwQzoneSyncToPosts')) {
    /**
     * 把 qzone_items 导入 posts。返回 ['created'=>n,'updated'=>n,'skipped'=>n]。
     */
    function lwQzoneSyncToPosts(): array
    {
        $fs  = getFS();
        $bot = lwQzoneBotUser();
        if (!$bot) {
            return ['created' => 0, 'updated' => 0, 'skipped' => 0, 'error' => '机器人账号创建失败'];
        }
        $items = $fs->read('qzone_items');
        if (!is_array($items)) { $items = []; }

        /* 导入条数上限 —— 复用后台「混入首页」那个设置，避免出现「设置了却没用」的假开关：
         *   show_home=0        → 0 条（不导入）
         *   home_count = -1    → 全部
         *   home_count = N>0   → 最新 N 条
         * 超出上限的旧帖子会被置为 hidden，从信息流消失（不删除，保留数据）。 */
        $cfg = lwQzoneConfig();
        $limit = 0;
        if ((int) $cfg['show_home'] === 1) {
            $n = (int) $cfg['home_count'];
            $limit = $n < 0 ? 9999 : max(0, $n);
        }
        usort($items, function ($a, $b) { return ((int) ($b['posted_ts'] ?? 0)) <=> ((int) ($a['posted_ts'] ?? 0)); });
        $allowTids = [];
        foreach ($items as $i => $it) {
            if ($i >= $limit) { break; }
            $allowTids[(string) ($it['tid'] ?? '')] = true;
        }

        /* 原空间评论按 tid 归组 */
        $qcomments = [];
        $allComments = $fs->read('qzone_comments');
        if (is_array($allComments)) {
            foreach ($allComments as $c) {
                /* 注意：qzone_comments 里关联帖子的字段是 item_tid（不是 tid，
                   tid 是这条评论自己的序号）。这里按 item_tid 归组。 */
                $t = (string) ($c['item_tid'] ?? '');
                if ($t === '') { continue; }
                /* 原样透传：QQ 评论 id / 评论人 uin / 原始时间都要保留，
                   否则后面「评论人建影子用户（需要 uin）+ 去重（需要 tid）+ 时间（需要 posted_ts）」
                   全部失效 —— 实测就是因为这里只留了 name/content/time，评论一条都导不进来。 */
                $qcomments[$t][] = [
                    'tid'           => (string) ($c['tid'] ?? ''),
                    'author'        => (string) ($c['author'] ?? ''),
                    'author_uin'    => (string) ($c['author_uin'] ?? ''),
                    'author_avatar' => (string) ($c['author_avatar'] ?? ''),
                    'content'       => (string) ($c['content'] ?? ''),
                    'created_at'    => (int) ($c['posted_ts'] ?? 0),
                ];
            }
        }

        $created = 0; $updated = 0; $skipped = 0; $hidden = 0;
        $cmtMap  = [];   /* post_id => QQ 评论列表，循环结束后一次性批量导入 */

        /* 【批量读写】导入几十条时，逐条 findOne/update 每次都要整文件读+写，
         * 实测 50 条要 37 秒，直接把免费主机 60 秒请求上限吃穿（线上同步因此被 kill）。
         * 这里改成：先一次性读 posts 建 tid 索引，循环里只算 patch，
         * 最后 insertMany + updateMany 各写一次盘。 */
        $postByTid = [];
        foreach ((array) $fs->read('posts') as $pp) {
            $pt = (string) ($pp['source_tid'] ?? '');
            if ($pt !== '') { $postByTid[$pt] = $pp; }
        }
        $patchMap  = [];   /* post_id => patch */
        $newRows   = [];   /* 待批量插入的新帖 */
        $cmtByTid  = [];   /* tid => QQ 评论列表 */
        $profBudget = 15;  /* 本轮最多跑几次敏感词接口（每次都是一次外网请求） */
        foreach ($items as $it) {
            $tid = (string) ($it['tid'] ?? '');
            if ($tid === '') { $skipped++; continue; }
            if ($limit >= 0 && !isset($allowTids[$tid])) {
                $old = $fs->findOne('posts', ['source_tid' => $tid]);
                if ($old && ($old['status'] ?? '') !== 'hidden') {
                    $fs->update('posts', (int) $old['id'], ['status' => 'hidden']);
                    $hidden++;
                }
                $skipped++;
                continue;
            }
            $imgs = json_decode((string) ($it['images'] ?? '[]'), true);
            if (!is_array($imgs)) { $imgs = []; }
            $postedTs = (int) ($it['posted_ts'] ?? 0);
            $postedAt = $postedTs > 0 ? date('Y-m-d H:i:s', $postedTs) : date('Y-m-d H:i:s');
            $exist = $postByTid[$tid] ?? null;

            /* QQ 转发的也要过敏感词（站长要求）。文本取 OCR 识别结果 ——
               这些说说正文为空，内容全在聊天截图里，只查 content 等于没查。 */
            $profStatus = 'published';
            $profFields = [];
            /* 敏感词检测每条都要发一次外网请求 —— 50 条就是 50 次，
               线上单请求 60 秒上限会被它吃光（实测同步被 kill）。
               所以：只对新帖检测，且每轮限 15 条；剩下的下一轮继续。 */
            if (!$exist && $profBudget > 0 && (function_exists('lwProfanityEnabled') || is_file(__DIR__ . '/profanity.php'))) {
                $profBudget--;
                require_once __DIR__ . '/profanity.php';
                if (lwProfanityEnabled()) {
                    $scanText = lwQzonePostTitle($tid);
                    $ocrInc = __DIR__ . '/qzone_ocr.php';
                    if (is_file($ocrInc)) {
                        require_once $ocrInc;
                        if (function_exists('lwQzoneOcrText')) { $scanText .= "\n" . lwQzoneOcrText($tid); }
                    }
                    try {
                        $pr = lwProfanityCheck($scanText);
                        if (!empty($pr['forbidden'])) {
                            $profStatus = 'pending';
                            $profFields = lwProfanityModerationFields($pr);
                        }
                    } catch (Throwable $e) {
                        /* fail-open：接口挂了照常发布 */
                    }
                }
            }

            /* QQ 侧的数量（点赞 / 评论总数）—— 站内显示要把这两个数当基数，
               再叠加本站用户自己点的赞、自己发的评论。 */
            $qqLikes = (int) ($it['likes'] ?? 0);
            $qqCmtNum = (int) ($it['comments'] ?? ($it['comment_num'] ?? 0));

            $meta = [
                'source'         => 'qzone',
                'source_tid'     => $tid,
                'source_url'     => 'https://user.qzone.qq.com/3893174881/mood/' . $tid,
                'qq_likes'       => $qqLikes,
                'qq_comments'    => lwQzoneItemComments($tid, $it, $qcomments),
                'qq_comment_num' => $qqCmtNum,
            ];

            if ($exist) {
                $patch = $meta;
                if (!empty($profFields)) {
                    $patch = array_merge($patch, $profFields);
                    $patch['status'] = 'pending';
                } elseif (($exist['status'] ?? '') === 'hidden') {
                    /* 【必须能「解除隐藏」】
                     * 站长把「导入条数上限」从小往大调（或从 0 / N 调回 -1）时，
                     * 这些帖子之前被本函数置成了 hidden。如果这里不恢复，
                     * 它们会永远看不见 —— 实测 home_count=5 时可见 0 条、
                     * 恢复 -1 后只有 2/19 可见，就是这个原因。
                     * hidden 是本模块专用的语义（后台拒绝用的是 rejected），
                     * 所以恢复成 published 是安全的。 */
                    $patch['status'] = 'published';
                }
                if (trim((string) ($exist['title'] ?? '')) === '') {
                    $patch['title'] = lwQzonePostTitle($tid);
                }
                $patch['images'] = json_encode(lwQzonePostImages($tid, $imgs), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

                /* 【数量叠加】显示数 = QQ 原数 + 本站新增量。
                 * 本站新增量 = 当前总数 - 上一轮记录的 QQ 数（若为负则按 0 处理）。
                 * 这样 QQ 那边涨了会跟着涨，本站用户点的赞/发的评论也不会被覆盖掉。 */
                $prevQqLikes = (int) ($exist['qq_likes'] ?? 0);
                $prevQqCmt   = (int) ($exist['qq_comment_num'] ?? 0);
                $localLikes  = max(0, (int) ($exist['likes'] ?? 0) - $prevQqLikes);
                $localCmt    = max(0, (int) ($exist['comments'] ?? 0) - $prevQqCmt);
                $patch['likes']    = $qqLikes + $localLikes;
                $patch['comments'] = $qqCmtNum + $localCmt;

                $patchMap[(int) $exist['id']] = $patch;
                $cmtByTid[$tid] = $patch['qq_comments'];
                $updated++;
                continue;
            }

            $rec = array_merge($meta, $profFields, [
                'user_id'     => (int) $bot['id'],
                'category'    => 'confession',
                'title'       => lwQzonePostTitle($tid),
                'content'     => '',
                'images'      => json_encode(lwQzonePostImages($tid, $imgs), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'is_anonymous' => 0,
                'visibility'  => 'public',
                'visible_to'  => '',
                'exclude_to'  => '',
                'status'      => $profStatus,
                /* 从 QQ 基数起步（之后本站用户操作在此之上叠加） */
                'likes'       => $qqLikes,
                'comments'    => $qqCmtNum,
                'views'       => 0,
            ]);
            /* insert() 会用当前时间覆盖 created_at，但 insertMany 支持保留给定值，
               所以这里直接把 QQ 发布时间塞进去，省掉一次「改回时间」的更新。 */
            $rec['created_at'] = $postedAt;
            $rec['updated_at'] = $postedAt;
            $newRows[] = $rec;
            $cmtByTid[$tid] = $rec['qq_comments'];
            $created++;
        }
        /* 批量落盘：新帖一次插入，旧帖一次更新 */
        if ($newRows) {
            $inserted = $fs->insertMany('posts', $newRows, true);
            foreach ($inserted as $np) {
                $tid = (string) ($np['source_tid'] ?? '');
                $cmtMap[(int) $np['id']] = $cmtByTid[$tid] ?? [];
            }
        }
        if ($patchMap) {
            $fs->updateMany('posts', $patchMap);
            foreach ($patchMap as $pid => $pp) {
                $cmtMap[(int) $pid] = $pp['qq_comments'] ?? [];
            }
        }

        /* 循环结束再统一把 QQ 评论导成站内评论（一次批量写盘，避免每条帖子都读写文件） */
        $importedComments = lwQzoneImportComments($cmtMap);

        return ['created' => $created, 'updated' => $updated, 'skipped' => $skipped,
                'hidden' => $hidden, 'comments' => $importedComments];
    }
}

if (!function_exists('lwQzonePostMeta')) {
    /** 读一条帖子的 QQ 来源元数据；非爬取帖返回 null */
    function lwQzonePostMeta(array $post): ?array
    {
        if (($post['source'] ?? '') !== 'qzone') { return null; }
        return [
            'tid'      => (string) ($post['source_tid'] ?? ''),
            'url'      => (string) ($post['source_url'] ?? ''),
            'likes'    => (int) ($post['qq_likes'] ?? 0),
            'comments' => is_array($post['qq_comments'] ?? null) ? $post['qq_comments'] : [],
        ];
    }
}
