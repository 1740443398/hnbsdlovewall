<?php
require_once __DIR__ . '/../../config/config.php';
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

$total = count($filteredPosts);
// 游客的总数也按上限封顶，避免前端据此判断还有更多内容
if ($isGuest) {
    $total = min($total, GUEST_VISIBLE_POSTS);
}
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
    // 仅在确有投票数据时才带上 poll，避免每条普通帖子都白送一个 "poll":null 字段
    if ($pollData !== null) {
        $item['poll'] = $pollData;
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
             'is_favorited', 'created_at', 'status', 'poll'],
            ['id']
        ),
        'guest_limited' => $isGuest,
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