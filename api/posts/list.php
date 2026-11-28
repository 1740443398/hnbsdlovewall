<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/translate.php';
require_once __DIR__ . '/../../includes/post_visibility.php';
require_once __DIR__ . '/../../includes/sort_util.php';
require_once __DIR__ . '/../../includes/fields.php';

// 已登录用户可正常拉取；游客按上限截断；未登录访客跳登录（JSON 返回 401）
$user = requireLoginOrGuest();
$isGuest = ($user === null);
$page = max(1, intval($_REQUEST['page'] ?? 1));
$limit = min(50, max(1, intval($_REQUEST['limit'] ?? 10)));
$offset = ($page - 1) * $limit;
$category = $_REQUEST['category'] ?? '';
// URL 别名 → 数据键：前端拼 URL 时用 social 代替 social_chat，
// 因为本站主机对 URL 含 "chat" 的请求一律 403（映射表与说明见 assets/js/main.js 的 categoryUrlKey）。
if ($category === 'social') {
    $category = 'social_chat';
}
$sort = $_REQUEST['sort'] ?? 'latest';

$fs = getFS();
$posts = $fs->read('posts');

$search = $_REQUEST['search'] ?? '';

// 游客：不提供搜索、分类筛选、排序切换与翻页，固定只返回最新前 GUEST_VISIBLE_POSTS 条
if ($isGuest) {
    $search = '';
    $category = '';
    $sort = 'latest';
    $page = 1;
    $limit = GUEST_VISIBLE_POSTS;
    $offset = 0;

    // 公开信息流（游客视角）：响应里没有任何「我」的字段（is_liked / is_favorited /
    // is_anonymous 都是纯展示），因此可以安全地让浏览器与中间层共享 caching。
    // 为什么值得：首页信息流是本站命中最多的请求，游客占大头，每人每次都回源等于
    // 每天都在把同一批 3 条 20KB 的 JSON 重新拼一遍。
    // 60 秒 + 300 秒 stale-while-revalidate：帖子上墙最多晚 1 分钟，
    // 而「先给旧副本、后台换新」让翻页与刷新永远不等。
    lwCacheShared(
    function_exists('lwCfgInt') ? max(0, lwCfgInt('cache_ttl_feed', 120)) : 60,
    300
);
}

// 屏蔽过滤：隐藏「我屏蔽的人」与「屏蔽了我的人」发布的帖子。
// 必须放在筛选与分页**之前** —— 否则分页是按未过滤的总数算出来的，
// 会出现「这一页只剩两三条」的空洞，翻页体验直接崩掉。
if (!$isGuest && $user) {
    require_once __DIR__ . '/../../includes/user_blocks.php';
    $posts = lwFilterBlocked($posts, (int)$user['id']);
}

if ($search) {
    $keyword = mb_strtolower(trim($search));
    $posts = array_filter($posts, function($p) use ($keyword) {
        $title = mb_strtolower($p['title'] ?? '');
        $content = mb_strtolower($p['content'] ?? '');
        return mb_strpos($title, $keyword) !== false || mb_strpos($content, $keyword) !== false;
    });
}

if ($category && $category !== 'all') {
    $posts = array_filter($posts, function($p) use ($category) {
        return $p['category'] == $category;
    });
}

$posts = array_values($posts);

// 排序键一次性算好再排（装饰—排序—去装饰）。
// 原先在比较器里调 strtotime()，n 条数据要解析约 2·n·log2(n) 次；
// 现在每条只解析一次，且公告置顶与热度都并进同一个键里。
$decorated = [];
foreach ($posts as $idx => $p) {
    $announce = (($p['category'] ?? '') === 'announcement') ? 1 : 0;
    $likes    = (int)($p['likes'] ?? 0);
    $ts       = lwTimestampOf($p['created_at'] ?? null);
    $decorated[] = [$announce, $likes, $ts, $idx, $p];
}
usort($decorated, function($x, $y) use ($sort) {
    if ($x[0] !== $y[0]) return $y[0] - $x[0];          // 公告永远置顶
    if ($sort === 'hot' && $x[1] !== $y[1]) return $y[1] - $x[1]; // hot：赞多在前
    if ($x[2] !== $y[2]) return $y[2] - $x[2];          // 其次按时间倒序
    return $x[3] - $y[3];                                // 稳定排序
});
$posts = [];
foreach ($decorated as $d) {
    $posts[] = $d[4];
}
unset($decorated);

// 可见性过滤统一走 includes/post_visibility.php（原先此处有一份内联规则，
// 与搜索、AI 助手各写一份且不一致；现在三处共用同一条判定）。
$filteredPosts = lwFilterVisiblePosts($posts, $user);

/* 「关注」信息流：只保留我关注的人发的帖子。
 * 放在可见性过滤之后、分页之前 —— 保证 total/pagination 都基于关注集计算，
 * 不会出现「翻页空洞」或 total 与实际条数不符。
 * 返回结构与其他 feed 完全一致（同一套字段输出），前端零改动即可渲染。 */
if (!$isGuest && $user && ($feedMode = ($_REQUEST['feed'] ?? '')) === 'following') {
    $followingIds = [];
    foreach ((array) $fs->find('follows', ['user_id' => (int) $user['id']]) as $fr) {
        $tid = (int) ($fr['target_id'] ?? 0);
        if ($tid > 0) { $followingIds[$tid] = true; }
    }
    $filteredPosts = array_values(array_filter($filteredPosts, function ($p) use ($followingIds) {
        return isset($followingIds[(int) ($p['user_id'] ?? 0)]);
    }));
}

/* 总数与「本次返回条数」要分开算。
 *
 * 【原来这里有个严重的设计缺陷】
 * 游客时把 $total 也截断成 GUEST_VISIBLE_POSTS（5），
 * 于是响应里 pagination.total = 5、has_more = false。
 *
 * 前端拿到这个响应会认为「全站只有 5 条帖子」——
 * 既不显示「登录后看更多」，也不会再请求下一页。
 * 用户看到的就是「帖子显示不全」，而且永远看不到其余内容，
 * 除非自己意识到要去登录。
 *
 * 【现在】
 *   total         = 真实可见总数（前端据此显示「还有 N 条」）
 *   guest_limited = true 时，前端会主动提示登录
 *   返回条数仍按 GUEST_VISIBLE_POSTS 截断（这是产品策略，保留）
 */
$total = count($filteredPosts);
$guestTotal = $total;   // 真实总数，给前端做「登录后可看更多」提示
$posts = array_slice($filteredPosts, $offset, $limit);

$result = [];

$allUsers = $fs->read('users');
$userIndex = [];
foreach ($allUsers as $u) {
    if (isset($u['id'])) {
        $userIndex[(int)$u['id']] = $u;
    }
}

// 「我是否赞过 / 收藏过」预先一次性建成索引。
// 原先是在下面的循环里对每条帖子各查一次 post_likes / post_favorites，
// 20 条帖子就是 40 次全表扫描（N+1），列表越长越慢。
$myLikedPosts = [];
$myFavPosts = [];
if ($user) {
    foreach ($fs->read('post_likes') as $row) {
        if ((int)($row['user_id'] ?? 0) === (int)$user['id']) {
            $myLikedPosts[(int)($row['post_id'] ?? 0)] = true;
        }
    }
    foreach ($fs->read('post_favorites') as $row) {
        if ((int)($row['user_id'] ?? 0) === (int)$user['id']) {
            $myFavPosts[(int)($row['post_id'] ?? 0)] = true;
        }
    }
}

foreach ($posts as $post) {
    $isAnonymous = !empty($post['is_anonymous']);
    $postUser = $isAnonymous ? null : ($userIndex[(int)$post['user_id']] ?? null);

    $liked = $user ? isset($myLikedPosts[(int)$post['id']]) : false;
    $favorited = $user ? isset($myFavPosts[(int)$post['id']]) : false;

    $author = null;
    if (!$isAnonymous && $postUser) {
        $author = [
            'id' => $postUser['id'],
            'qq' => $postUser['qq'] ?? '',
            'nickname' => $postUser['nickname'] ?? '',
            'avatar' => $postUser['avatar'] ?? '/assets/images/default-avatar.svg',
            'title_text' => $postUser['title_text'] ?? '',
            'title_color' => $postUser['title_color'] ?? '',
            'title_bg_color' => $postUser['title_bg_color'] ?? '',
            'title_rainbow' => intval($postUser['title_rainbow'] ?? 0),
            'title_gradient_start' => $postUser['title_gradient_start'] ?? '',
            'title_gradient_end' => $postUser['title_gradient_end'] ?? '',
            // 头像框（商城挂件）：列表页头像要能显示正在装备的框
            'frame' => (function () use ($postUser) {
                if (!function_exists('lwUserDecorBundle')) {
                    require_once __DIR__ . '/../../includes/widget_render.php';
                }
                $d = lwUserDecorBundle((int)$postUser['id']);
                return $d['frame'] ? [
                    'css_class' => (string)($d['frame']['css_class'] ?? ''),
                    'asset' => (string)($d['frame']['asset'] ?? ''),
                ] : null;
            })(),
        ];
    } else {
        $author = [
            'id' => 0,
            'qq' => '',
            'nickname' => '匿名用户',
            'avatar' => '/assets/images/default-avatar.svg',
            'title_text' => '',
            'title_color' => '',
            'title_bg_color' => '',
            'title_rainbow' => 0,
            'title_gradient_start' => '',
            'title_gradient_end' => '',
        ];
    }

    // 投票数据透传
    $pollData = null;
    if (!empty($post['poll']) && is_array($post['poll']) && !empty($post['poll']['options'])) {
        $options = array_values($post['poll']['options']);
        $votes = isset($post['poll']['votes']) && is_array($post['poll']['votes']) ? $post['poll']['votes'] : [];
        $counts = array_fill(0, count($options), 0);
        $totalVotes = 0;
        foreach ($votes as $idx) {
            $idx = (int)$idx;
            if ($idx >= 0 && $idx < count($options)) {
                $counts[$idx]++;
                $totalVotes++;
            }
        }
        $myVote = -1;
        if ($user) {
            $myVote = isset($votes[(string)$user['id']]) ? (int)$votes[(string)$user['id']] : -1;
        }
        $pollData = [
            'question' => $post['poll']['question'] ?? '',
            'options' => $options,
            'counts' => $counts,
            'total' => $totalVotes,
            'my_vote' => $myVote,
            'has_voted' => $myVote >= 0,
        ];
    }

    $item = [
        'id' => $post['id'],
        'category' => $post['category'],
        'title' => $post['title'] ?? '',
        'content' => $post['content'] ?? '',
        'images' => postImagesExisting($post),
        'is_anonymous' => $isAnonymous,
        'is_pinned' => !empty($post['is_pinned']),
        'visibility' => $post['visibility'] ?? 'public',
        // visible_to / exclude_to 为 QQ 号白/黑名单，属隐私数据，不下发给前端；
        // 可见性过滤已在上文的 $filteredPosts 阶段用原始值完成，此处仅做输出脱敏。
        'author' => $author,
        'like_count' => intval($post['likes'] ?? 0),
        'comment_count' => intval($post['comments'] ?? 0),
        'is_liked' => $liked,
        'is_favorited' => $favorited,
        'created_at' => $post['created_at'] ?? '',
        'status' => $post['status'] ?? 'published',
    ];

    /* 引用动态：只给「指向原帖」的摘要（id/标题/作者/首图），
       前端点进原帖看全文 —— 不复制正文与图片，存储占用为 0 增长。 */
    $quoteId = (int) ($post['quote_post_id'] ?? 0);
    if ($quoteId > 0) {
        static $__qcache = [];
        if (!array_key_exists($quoteId, $__qcache)) {
            $__qcache[$quoteId] = getFS()->findById('posts', $quoteId);
        }
        $q = $__qcache[$quoteId];
        if ($q && (($q['status'] ?? '') === 'published')) {
            $qu = getFS()->findById('users', (int) ($q['user_id'] ?? 0));
            $qimgs = postImagesExisting($q);
            $item['quote'] = [
                'id'       => (int) $q['id'],
                'title'    => (string) ($q['title'] ?? ''),
                'content'  => mb_substr((string) ($q['content'] ?? ''), 0, 120),
                'image'    => $qimgs[0] ?? '',
                'nickname' => (string) ($qu['nickname'] ?? ''),
                'avatar'   => (string) ($qu['avatar'] ?? '/assets/images/default-avatar.svg'),
                'user_id'  => (int) ($qu['user_id'] ?? 0),
            ];
        } else {
            $item['quote'] = ['id' => $quoteId, 'deleted' => true];
        }
    }
    // 仅在确有投票数据时才带上 poll，避免每条普通帖子都白送一个 "poll":null 字段
    if ($pollData !== null) {
        $item['poll'] = $pollData;
    }
    /* 附带抽奖：只给 lotteries.id，不复制奖品/开奖时间等数据。
     * 卡片只用来拼 /pages/lottery.php?id=…，详情按 id 打开时再读。
     * 同 quote 的思路：帖子侧只留一个外键标记。 */
    $lotteryId = (int) ($post['lottery_id'] ?? 0);
    if ($lotteryId > 0) {
        $item['lottery_id'] = $lotteryId;
    }
    $result[] = $item;
}

// 分页信息统一收敛到 pagination 一个对象：前端 main.js 只读 data.pagination.has_more，
// 原先顶层那份重复的 total/page/limit/has_more 无任何消费者，属冗余字段，予以删除。
//
// 字段投影（E14）：不传 fields/top 时与改造前完全一致。
// `?fields=id,title` 这类用法适合「只要列表骨架」的场景（搜索结果页、第三方调用）。
jsonSuccess(lwProjectTop(
    [
        'posts' => lwProjectRows(
            $result,
            lwFieldsRequest(),
            ['id', 'category', 'title', 'content', 'images', 'is_anonymous', 'is_pinned',
             'visibility', 'author', 'like_count', 'comment_count', 'is_liked',
             'is_favorited', 'created_at', 'status', 'poll', 'lottery_id'],
            ['id']
        ),
        'guest_limited' => $isGuest,
        /* 真实总数：游客时前端用它显示「还有 N 条，登录后可见」 */
        'guest_total' => $isGuest ? $guestTotal : null,
        'pagination' => [
            'total' => $total,
            'page' => $page,
            'limit' => $limit,
            'has_more' => $offset + $limit < $total
        ]
    ],
    lwTopRequest(),
    []
));