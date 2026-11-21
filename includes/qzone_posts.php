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
                $qcomments[$t][] = [
                    'name'    => (string) ($c['author'] ?? ''),
                    'content' => (string) ($c['content'] ?? ''),
                    'time'    => (string) ($c['time'] ?? ''),
                ];
            }
        }

        $created = 0; $updated = 0; $skipped = 0; $hidden = 0;
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

            /* QQ 转发的也要过敏感词（站长要求）。文本取 OCR 识别结果 ——
               这些说说正文为空，内容全在聊天截图里，只查 content 等于没查。 */
            $profStatus = 'published';
            $profFields = [];
            if (function_exists('lwProfanityEnabled') || is_file(__DIR__ . '/profanity.php')) {
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

            $meta = [
                'source'      => 'qzone',
                'source_tid'  => $tid,
                'source_url'  => 'https://user.qzone.qq.com/3893174881/mood/' . $tid,
                'qq_likes'    => (int) ($it['likes'] ?? 0),
                'qq_comments' => $imgs === null ? [] : ($qcomments[$tid] ?? []),
                'qq_comment_num' => (int) ($it['comment_num'] ?? 0),
            ];

            $exist = $fs->findOne('posts', ['source_tid' => $tid]);
            if ($exist) {
                $patch = $meta;
                if (!empty($profFields)) { $patch = array_merge($patch, $profFields); $patch['status'] = 'pending'; }
                if (trim((string) ($exist['title'] ?? '')) === '') {
                    $patch['title'] = lwQzonePostTitle($tid);
                }
                $patch['images'] = json_encode(lwQzonePostImages($tid, $imgs), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                $fs->update('posts', (int) $exist['id'], $patch);
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
                'likes'       => 0,      /* 站内点赞从 0 开始；QQ 原赞数在 qq_likes 里单独展示 */
                'comments'    => 0,
                'views'       => 0,
            ]);
            $new = $fs->insert('posts', $rec);
            if (!$new) { $skipped++; continue; }
            /* insert() 会强制把 created_at 写成当前时间 —— 必须改回 QQ 发布时间，
               否则信息流顺序会全变成「刚刚」。 */
            $fs->update('posts', (int) $new['id'], ['created_at' => $postedAt]);
            $created++;
        }
        return ['created' => $created, 'updated' => $updated, 'skipped' => $skipped, 'hidden' => $hidden];
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
