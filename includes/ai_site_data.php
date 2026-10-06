<?php
/**
 * AI 助手 · 站点数据接入（只读）
 *
 * 用途：把站内数据与既有公开接口包装成「可直接注入对话上下文」的文本块，
 *       由 api/ai_assistant.php 在每次提问时按需取数。全程服务端直读，不经过浏览器。
 *
 * 为什么不是 function calling：本站所用模型（config/ai_config.php 里的 glm-4.1v-thinking-flash）
 * 实测不会真正发起 tool_calls（只会嘴上说要调工具），所以采用
 * 「服务端按提问判定意图 → 取数 → 作为可信数据注入」的方式，稳定、可测、还省一次模型往返。
 *
 * 安全边界（改动本文件时请一并维护）：
 *   - 只读，不写入任何数据；
 *   - 帖子严格复用 includes/post_visibility.php 的可见性规则（与首页信息流、搜索同一条）；
 *   - 匿名帖不暴露作者；「我的数据」只取当前登录用户自己的记录；
 *   - **隐私红线**：绝不注入 QQ / 邮箱 / 密码哈希 / 登录IP / 2FA 状态；私信只给计数与对端昵称，
 *     绝不注入正文；绝不读 waf_logs / rate_limits / 各类审核日志等运维表。
 *   - 注入前所有用户产生内容都要过 aiSanitizeDataSource()（防 Prompt Injection）。
 *
 * 性能预算（共享虚机上 data/*.json 是大文件，每次提问都会跑一遍取数）：
 *   - 最多触碰 6 张表、注入上下文不超过 12000 字、单块不超过 1200 字；
 *   - 取数总耗时超过 250ms 就丢掉剩余的可选块，只保留已完成的。
 */

require_once __DIR__ . '/post_visibility.php';
require_once __DIR__ . '/text_linkify.php';
require_once __DIR__ . '/ai_actions.php';

/** 注入上下文的总字符上限 */
if (!defined('AI_CTX_MAX_CHARS')) {
    define('AI_CTX_MAX_CHARS', 12000);
}
/** 单个数据块的字符上限 */
if (!defined('AI_CTX_MAX_BLOCK_CHARS')) {
    define('AI_CTX_MAX_BLOCK_CHARS', 1200);
}
/** 一次提问最多触碰的表数 */
if (!defined('AI_CTX_MAX_TABLES')) {
    define('AI_CTX_MAX_TABLES', 6);
}
/** 取数时间预算（秒）。这是「避免可选取数把响应拖慢」的软闸门，不是硬上限：
 *  共享主机上第一次冷读几个 JSON 文件就可能几百毫秒，卡太紧会把整轮取数饿死。 */
if (!defined('AI_CTX_TIME_BUDGET')) {
    define('AI_CTX_TIME_BUDGET', 1.0);
}
/** 单表扫描条数上限（超出只取尾部，并标注） */
if (!defined('AI_SCAN_MAX_ROWS')) {
    define('AI_SCAN_MAX_ROWS', 8000);
}

// ===========================================================================
// 基础工具
// ===========================================================================

/** 帖子分类中文名（与 api/search/quick.php 保持一致） */
function aiCategoryName($key)
{
    $map = [
        'announcement' => '全站公告',
        'lost_found'   => '寻物/失物招领',
        'study_help'   => '学习求助',
        'social_chat'  => '交友闲聊',
        'confession'   => '表白',
        'school_info'  => '校园打听',
        'other'        => '其他',
    ];
    return $map[$key] ?? (string)$key;
}

/** 全部分类 key => 中文名 */
function aiCategoryMap()
{
    return [
        'announcement' => aiCategoryName('announcement'),
        'lost_found'   => aiCategoryName('lost_found'),
        'study_help'   => aiCategoryName('study_help'),
        'social_chat'  => aiCategoryName('social_chat'),
        'confession'   => aiCategoryName('confession'),
        'school_info'  => aiCategoryName('school_info'),
        'other'        => aiCategoryName('other'),
    ];
}

/** 取当前用户可见的帖子（可见性规则与首页信息流、搜索共用 includes/post_visibility.php） */
function aiVisiblePosts(?array $user)
{
    return lwFilterVisiblePosts(getFS()->read('posts'), $user);
}

/** id => 昵称 映射（仅用于非匿名帖显示作者） */
function aiUserNames()
{
    static $map = null;
    if ($map !== null) {
        return $map;
    }
    $map = [];
    foreach ((array)getFS()->read('users') as $u) {
        if (isset($u['id'])) {
            $map[(int)$u['id']] = trim((string)($u['nickname'] ?? '')) ?: ('用户' . $u['id']);
        }
    }
    return $map;
}

/** id => 用户记录 映射 */
function aiUserIndex()
{
    static $idx = null;
    if ($idx !== null) {
        return $idx;
    }
    $idx = [];
    foreach ((array)getFS()->read('users') as $u) {
        if (isset($u['id'])) {
            $idx[(int)$u['id']] = $u;
        }
    }
    return $idx;
}

/** 按时间倒序 */
function aiSortByTimeDesc($a, $b)
{
    return strtotime((string)($b['created_at'] ?? '2000-01-01')) - strtotime((string)($a['created_at'] ?? '2000-01-01'));
}

/** 按热度排序：公告优先 → 赞多在前 → 时间新在前（与 api/posts/list.php 的 hot 口径一致） */
function aiSortByHot($a, $b)
{
    $aAnn = (($a['category'] ?? '') === 'announcement') ? 1 : 0;
    $bAnn = (($b['category'] ?? '') === 'announcement') ? 1 : 0;
    if ($aAnn !== $bAnn) {
        return $bAnn - $aAnn;
    }
    $la = (int)($a['likes'] ?? 0);
    $lb = (int)($b['likes'] ?? 0);
    if ($la !== $lb) {
        return $lb - $la;
    }
    return aiSortByTimeDesc($a, $b);
}

/** 把一条帖子压成一行摘要（匿名帖不出现作者名） */
function aiPostLine(array $p, array $names, bool $withBody = false)
{
    $parts = [];
    $parts[] = '#' . (int)($p['id'] ?? 0);
    $parts[] = '《' . mb_substr((string)($p['title'] ?? '（无标题）'), 0, 40) . '》';
    $parts[] = '分类：' . aiCategoryName($p['category'] ?? 'other');
    if (!empty($p['is_anonymous'])) {
        $parts[] = '作者：匿名';
    } elseif ($withBody) {
        $uid = (int)($p['user_id'] ?? 0);
        $parts[] = '作者：' . ($names[$uid] ?? ('用户' . $uid));
    }
    $parts[] = '发布：' . mb_substr((string)($p['created_at'] ?? ''), 0, 16);
    $parts[] = '赞 ' . (int)($p['likes'] ?? 0) . ' / 评 ' . (int)($p['comments'] ?? 0) . ' / 浏览 ' . (int)($p['views'] ?? 0);
    return implode(' · ', $parts);
}

/** 相对时间（复用站内 timeAgo） */
function aiAgo($ts)
{
    if (!$ts) {
        return '';
    }
    return timeAgo(is_numeric($ts) ? date('Y-m-d H:i:s', (int)$ts) : (string)$ts);
}

/** 统一的块返回 */
function aiBlock(string $text, string $source): array
{
    $text = trim($text);
    if ($text === '') {
        return ['text' => '', 'source' => ''];
    }
    if (mb_strlen($text) > AI_CTX_MAX_BLOCK_CHARS) {
        $text = mb_substr($text, 0, AI_CTX_MAX_BLOCK_CHARS) . '…（内容过长，已截断）';
    }
    return ['text' => $text, 'source' => $source];
}

/** 「这个功能要登录」的统一引导块 */
function aiNeedLogin(string $source, string $what, bool $isGuest): array
{
    if (!$isGuest) {
        return ['text' => '', 'source' => ''];
    }
    return aiBlock(
        '【' . $source . '】当前是游客模式（未登录），看不到' . $what
        . '。可以温和说明需要注册登录后才能查看，并给出链接 [注册/登录](/pages/register.php)。',
        $source
    );
}

// ===========================================================================
// 帖子索引（一次遍历产出多个视图，避免 N 次全表扫描）
// ===========================================================================

/**
 * 请求内缓存的帖子视图。多个意图共享同一份，避免每次意图都重新遍历 posts。
 * 注意 FileStorage::read() 本身有请求内缓存，所以这里只需保证「不重复遍历」。
 */
function aiPostIndex(?array $user): array
{
    static $cache = [];
    $key = $user ? ('u' . (int)$user['id']) : 'guest';
    if (isset($cache[$key])) {
        return $cache[$key];
    }

    $posts = aiVisiblePosts($user);
    $byTime = $posts;
    usort($byTime, 'aiSortByTimeDesc');
    $byHot = $posts;
    usort($byHot, 'aiSortByHot');

    $today = date('Y-m-d');
    $weekAgo = strtotime('-7 days');
    $catCnt = [];
    $catWeek = [];
    $todayCnt = 0;
    $weekCnt = 0;
    $totalViews = 0;
    $totalLikes = 0;
    foreach ($posts as $p) {
        $cat = (string)($p['category'] ?? 'other');
        $catCnt[$cat] = ($catCnt[$cat] ?? 0) + 1;
        $totalViews += (int)($p['views'] ?? 0);
        $totalLikes += (int)($p['likes'] ?? 0);
        $ts = strtotime((string)($p['created_at'] ?? ''));
        if ($ts === false) {
            continue;
        }
        if (date('Y-m-d', $ts) === $today) {
            $todayCnt++;
        }
        if ($ts >= $weekAgo) {
            $weekCnt++;
            $catWeek[$cat] = ($catWeek[$cat] ?? 0) + 1;
        }
    }

    $cache[$key] = [
        'posts'      => $posts,
        'byTime'     => $byTime,
        'byHot'      => $byHot,
        'catCnt'     => $catCnt,
        'catWeek'    => $catWeek,
        'todayCnt'   => $todayCnt,
        'weekCnt'    => $weekCnt,
        'totalViews' => $totalViews,
        'totalLikes' => $totalLikes,
    ];
    return $cache[$key];
}

/**
 * 数据健康检查：posts.json 文件存在但读出来是空数组，说明 JSON 解析失败被留档了。
 * 这种情况下绝不能对用户说「站内还没有帖子」——那是把数据损坏说成业务事实。
 */
function aiDataHealth(): ?string
{
    static $checked = null;
    if ($checked !== null) {
        return $checked;
    }
    $checked = '';
    $file = __DIR__ . '/../data/posts.json';
    if (is_file($file) && filesize($file) > 2) {
        $posts = getFS()->read('posts');
        if (!is_array($posts) || count($posts) === 0) {
            $checked = 'posts';
        }
    }
    return $checked;
}

// ===========================================================================
// 取数函数
// ===========================================================================

/** 站点概况：公告 / 社区规范 / 规模统计 / 数据健康 */
function aiSiteOverview(?array $user)
{
    $fs = getFS();
    $idx = aiPostIndex($user);
    $users = (array)$fs->read('users');
    $weekAgo = strtotime('-7 days');
    $newUsers = 0;
    foreach ($users as $u) {
        $ts = strtotime((string)($u['created_at'] ?? ''));
        if ($ts !== false && $ts >= $weekAgo) {
            $newUsers++;
        }
    }
    $visits = $fs->read('visit_stats');
    $visit = is_array($visits) && isset($visits[0]) ? $visits[0] : [];

    $lines = ['【站点概况】'];
    $announcement = trim((string)getSetting('announcement', ''));
    $lines[] = '- 全站公告：' . ($announcement !== '' ? $announcement : '（暂无）');
    $rules = trim((string)getSetting('community_rules', ''));
    if ($rules !== '') {
        $lines[] = '- 社区规范：' . str_replace("\n", ' / ', mb_substr($rules, 0, 400));
    }
    // 游客只看得到前若干条，数量要以「你能看到的」为准，避免说出他看不到的规模
    $scope = $user ? '全站' : '你可以浏览的';
    $lines[] = '- ' . $scope . '帖子：' . count($idx['posts']) . ' 篇（今日新增 ' . $idx['todayCnt']
        . ' 篇，近 7 天 ' . $idx['weekCnt'] . ' 篇；累计浏览 ' . $idx['totalViews'] . '，累计点赞 ' . $idx['totalLikes'] . '）';
    $lines[] = '- 注册用户：' . count($users) . ' 人（近 7 天新增 ' . $newUsers . ' 人）';
    if (!empty($visit)) {
        $lines[] = '- 访问量：累计 ' . (int)($visit['total'] ?? 0) . ' 次，今日 ' . (int)($visit['today'] ?? 0) . ' 次';
    }
    if (aiDataHealth() !== '') {
        $lines[] = '- ⚠️ 读取异常：帖子数据文件存在但读取为空，可能是数据文件损坏。请如实告知用户「我这边读到站点数据异常」，不要报 0 篇。';
    }
    if ($user === null) {
        $lines[] = '- 当前是游客模式：只能浏览最新前 ' . (defined('GUEST_VISIBLE_POSTS') ? GUEST_VISIBLE_POSTS : 3) . ' 条动态。';
    }
    return aiBlock(implode("\n", $lines), '站点概况');
}

/** 最新帖子 */
function aiPostsLatest(?array $user, int $limit = 8)
{
    $idx = aiPostIndex($user);
    $names = aiUserNames();
    $lines = ['【最新帖子】'];
    foreach (array_slice($idx['byTime'], 0, $limit) as $p) {
        $lines[] = '- ' . aiPostLine($p, $names, true);
    }
    if (count($lines) === 1) {
        $lines[] = '（你能看到的范围内还没有帖子）';
    }
    return aiBlock(implode("\n", $lines), '最新帖子');
}

/** 热门帖子（公告优先 → 赞多 → 时间新） */
function aiPostsHot(?array $user, int $limit = 8)
{
    $idx = aiPostIndex($user);
    $names = aiUserNames();
    $lines = ['【热门帖子】按「公告优先 → 点赞数 → 发布时间」排序：'];
    $n = 0;
    foreach ($idx['byHot'] as $p) {
        if ($n >= $limit) {
            break;
        }
        $lines[] = '- ' . aiPostLine($p, $names, true);
        $n++;
    }
    if ($n === 0) {
        $lines[] = '（你能看到的范围内还没有帖子）';
    }
    return aiBlock(implode("\n", $lines), '热门帖子');
}

/** 站内公告（category=announcement） */
function aiAnnouncements(?array $user, int $limit = 8)
{
    $idx = aiPostIndex($user);
    $lines = ['【站内公告】'];
    $n = 0;
    foreach ($idx['byTime'] as $p) {
        if (($p['category'] ?? '') !== 'announcement') {
            continue;
        }
        if ($n >= $limit) {
            break;
        }
        $lines[] = '- #' . (int)$p['id'] . ' 《' . mb_substr((string)($p['title'] ?? ''), 0, 40) . '》 发布：'
            . mb_substr((string)($p['created_at'] ?? ''), 0, 16)
            . ' 正文：' . mb_substr(aiSanitizeDataSource((string)($p['content'] ?? '')), 0, 120);
        $n++;
    }
    if ($n === 0) {
        $lines[] = '（暂无公告）';
    }
    return aiBlock(implode("\n", $lines), '站内公告');
}

/** 分类统计 */
function aiCategoryStats(?array $user)
{
    $idx = aiPostIndex($user);
    $lines = ['【分类统计】'];
    foreach (aiCategoryMap() as $key => $name) {
        $c = (int)($idx['catCnt'][$key] ?? 0);
        $w = (int)($idx['catWeek'][$key] ?? 0);
        $lines[] = '- ' . $name . '（' . $key . '）：共 ' . $c . ' 篇，近 7 天 ' . $w . ' 篇';
    }
    return aiBlock(implode("\n", $lines), '分类统计');
}

/** 活跃分（近 $days 天发帖 3 分 + 评论 1 分，降序）。
 *  与 pages/ranking.php 的活跃榜同一口径；$visibleIds 非 null 时只统计这些帖子下的评论，
 *  避免把用户看不到的帖子里的互动算进榜（游客尤其重要）。 */
function aiActiveScores(int $days, ?array $visibleIds = null): array
{
    $fs = getFS();
    $since = strtotime('-' . max(1, $days) . ' days');
    $score = [];

    $posts = $fs->read('posts');
    if (count($posts) > AI_SCAN_MAX_ROWS) {
        $posts = array_slice($posts, -AI_SCAN_MAX_ROWS);
    }
    foreach ($posts as $p) {
        if (($p['status'] ?? 'published') !== 'published' || !empty($p['is_anonymous'])) {
            continue;
        }
        $ts = strtotime((string)($p['created_at'] ?? ''));
        if ($ts === false || $ts < $since) {
            continue;
        }
        $uid = (int)($p['user_id'] ?? 0);
        if ($uid > 0) {
            $score[$uid] = ($score[$uid] ?? 0) + 3;
        }
    }

    $comments = $fs->read('comments');
    if (count($comments) > AI_SCAN_MAX_ROWS) {
        $comments = array_slice($comments, -AI_SCAN_MAX_ROWS);
    }
    foreach ($comments as $c) {
        $ts = strtotime((string)($c['created_at'] ?? ''));
        if ($ts === false || $ts < $since || !empty($c['is_anonymous'])) {
            continue;
        }
        if ($visibleIds !== null && !isset($visibleIds[(int)($c['post_id'] ?? 0)])) {
            continue;
        }
        $uid = (int)($c['user_id'] ?? 0);
        if ($uid > 0) {
            $score[$uid] = ($score[$uid] ?? 0) + 1;
        }
    }

    arsort($score);
    return $score;
}

/** 活跃用户榜（近 30 天发帖 + 评论加权；只输出昵称与计数） */
function aiActiveUsers(int $limit = 10)
{
    $score = aiActiveScores(30);
    $names = aiUserNames();
    $lines = ['【活跃榜】按近 30 天发帖(3分)+评论(1分)加权：'];
    $n = 0;
    foreach ($score as $uid => $s) {
        if ($n >= $limit) {
            break;
        }
        $lines[] = '- ' . ($names[$uid] ?? ('用户' . $uid)) . '：活跃分 ' . $s;
        $n++;
    }
    if ($n === 0) {
        $lines[] = '（近 30 天没有可统计的活跃记录）';
    }
    return aiBlock(implode("\n", $lines), '活跃榜');
}

/**
 * 热榜（周榜 / 月榜）：热门帖子 + 活跃用户 + 热门话题。
 * 口径与 pages/ranking.php 完全一致：可见性同一口径、公告不参与、匿名不进活跃榜、
 * 帖子按 赞→评→阅→时间 逐级比较（不发明权重），活跃分沿用 3/1。
 * $range 只认 'week'（近 7 天）与 'month'（近 30 天），其他值按周处理。
 */
function aiTrendingBoard(?array $user, string $range = 'week', int $limit = 5)
{
    $days  = ($range === 'month') ? 30 : 7;
    $label = ($range === 'month') ? '月榜（近 30 天）' : '周榜（近 7 天）';
    $since = strtotime('-' . $days . ' days');

    $visible = aiVisiblePosts($user);
    $visibleIds = [];   // 全部可见帖子（评论归属用，不受时间窗口限制）
    foreach ($visible as $p) {
        if (is_array($p) && isset($p['id'])) {
            $visibleIds[(int)$p['id']] = true;
        }
    }

    $cands = [];
    foreach ($visible as $p) {
        if (!is_array($p)) {
            continue;
        }
        if ((string)($p['status'] ?? 'published') !== 'published') {
            continue;
        }
        if ((string)($p['category'] ?? '') === 'announcement') {
            continue;
        }
        $ts = strtotime((string)($p['created_at'] ?? ''));
        if ($ts === false || $ts < $since) {
            continue;
        }
        $cands[(int)($p['id'] ?? 0)] = $p;
    }

    $postBoard = array_values($cands);
    usort($postBoard, function ($a, $b) {
        $la = (int)($a['likes'] ?? 0);
        $lb = (int)($b['likes'] ?? 0);
        if ($la !== $lb) {
            return $lb - $la;
        }
        $ca = (int)($a['comments'] ?? 0);
        $cb = (int)($b['comments'] ?? 0);
        if ($ca !== $cb) {
            return $cb - $ca;
        }
        $va = (int)($a['views'] ?? 0);
        $vb = (int)($b['views'] ?? 0);
        if ($va !== $vb) {
            return $vb - $va;
        }
        return aiSortByTimeDesc($a, $b);
    });

    $names = aiUserNames();
    // 注意：数据块进模型前会过 aiSanitizeDataBlocks()，Markdown 链接会被剥成纯文字，
    // 所以这里给的是裸路径（模型据此自己组 [热榜](/pages/ranking.php) 这类站内链接）。
    $lines = ['【热榜 · ' . $label . '】（对应页面路径 /pages/ranking.php?range=' . $range . '）'];

    $lines[] = '热门帖子（赞→评→阅）：';
    $n = 0;
    foreach ($postBoard as $p) {
        if ($n >= $limit) {
            break;
        }
        $lines[] = '- ' . aiPostLine($p, $names, true);
        $n++;
    }
    if ($n === 0) {
        $lines[] = '（你能看到的范围内，近 ' . $days . ' 天没有可排名的帖子）';
    }

    $lines[] = '活跃用户（发帖 3 分 + 评论 1 分）：';
    $score = aiActiveScores($days, $visibleIds);
    $n = 0;
    foreach ($score as $uid => $s) {
        if ($n >= $limit) {
            break;
        }
        $lines[] = '- ' . ($names[$uid] ?? ('用户' . $uid)) . '：活跃分 ' . $s;
        $n++;
    }
    if ($n === 0) {
        $lines[] = '（近 ' . $days . ' 天没有可统计的活跃记录）';
    }

    $tagCount = [];
    foreach ($cands as $p) {
        foreach (array_keys(lwExtractTags(($p['title'] ?? '') . "\n" . ($p['content'] ?? ''))) as $tg) {
            $tagCount[$tg] = ($tagCount[$tg] ?? 0) + 1;
        }
    }
    arsort($tagCount);
    $lines[] = '热门话题（按覆盖帖子数）：';
    $n = 0;
    foreach ($tagCount as $tg => $c) {
        if ($n >= $limit) {
            break;
        }
        $lines[] = '- ' . aiSanitizeDataSource((string)$tg) . '：' . $c . ' 篇（话题页路径 /pages/topic.php?tag=' . rawurlencode((string)$tg) . '）';
        $n++;
    }
    if ($n === 0) {
        $lines[] = '（近 ' . $days . ' 天没有带 #话题 的帖子）';
    }

    return aiBlock(implode("\n", $lines), '热榜');
}

/** 用户公开资料（严格不输出 QQ / 邮箱 / IP / 2FA 等隐私字段） */
function aiUserProfile(?array $user, int $targetId)
{
    $u = getFS()->findById('users', $targetId);
    if (!$u) {
        return aiBlock('【用户资料】没有找到这个用户（ID ' . $targetId . '）。', '用户资料');
    }
    $fs = getFS();
    $posts = 0;
    foreach ((array)$fs->read('posts') as $p) {
        if ((int)($p['user_id'] ?? 0) === $targetId && ($p['status'] ?? 'published') === 'published') {
            $posts++;
        }
    }
    $comments = 0;
    foreach ((array)$fs->read('comments') as $c) {
        if ((int)($c['user_id'] ?? 0) === $targetId) {
            $comments++;
        }
    }
    $nick = trim((string)($u['nickname'] ?? '')) ?: ('用户' . $targetId);
    $lines = ['【用户资料】'];
    $lines[] = '- 昵称：' . $nick . '（ID ' . $targetId . '）';
    if (!empty($u['created_at'])) {
        $lines[] = '- 注册时间：' . mb_substr((string)$u['created_at'], 0, 7) . '（只到月份）';
    }
    $lines[] = '- 公开发帖 ' . $posts . ' 篇，公开评论 ' . $comments . ' 条';
    if (!empty($u['title_text'])) {
        $lines[] = '- 头衔：' . (string)$u['title_text'];
    }
    $lines[] = '- 粉丝 ' . count((array)$fs->find('follows', ['target_id' => $targetId]))
        . ' 人，关注 ' . count((array)$fs->find('follows', ['user_id' => $targetId])) . ' 人';
    if (!empty($u['is_banned'])) {
        $lines[] = '- 该账号当前受限';
    }
    $lines[] = '- （提醒：不要向任何人输出 QQ、邮箱、登录IP、2FA 状态等隐私信息）';
    return aiBlock(implode("\n", $lines), '用户资料');
}

/** 帖子评论 */
function aiPostComments(?array $user, int $postId, int $limit = 10)
{
    $fs = getFS();
    $post = $fs->findById('posts', $postId);
    if (!$post || !lwPostVisible($post, $user)) {
        return aiBlock('【帖子评论】#' . $postId . ' 不存在或不可见。', '帖子评论');
    }
    $rows = $fs->find('comments', ['post_id' => $postId]);
    usort($rows, 'aiSortByTimeDesc');
    $names = aiUserNames();
    $lines = ['【帖子评论】#' . $postId . ' 《' . mb_substr((string)($post['title'] ?? ''), 0, 30) . '》共 '
        . count($rows) . ' 条，下面是最近 ' . min($limit, count($rows)) . ' 条：'];
    $n = 0;
    foreach ($rows as $c) {
        if ($n >= $limit) {
            break;
        }
        $who = !empty($c['is_anonymous']) ? '匿名同学' : ($names[(int)($c['user_id'] ?? 0)] ?? '用户');
        $body = mb_substr(aiSanitizeDataSource((string)($c['content'] ?? '')), 0, 120);
        $lines[] = '- ' . $who . '：' . $body . '（' . mb_substr((string)($c['created_at'] ?? ''), 0, 16) . '）';
        $n++;
    }
    if ($n === 0) {
        $lines[] = '（这条帖子还没有评论）';
    }
    return aiBlock(implode("\n", $lines), '帖子评论');
}

/** 单帖详情 */
function aiPostDetail(?array $user, int $postId)
{
    $fs = getFS();
    $post = $fs->findById('posts', $postId);
    if (!$post || !lwPostVisible($post, $user)) {
        return aiBlock('【帖子详情】#' . $postId . ' 不存在或不可见。', '帖子详情');
    }
    $names = aiUserNames();
    $lines = ['【帖子详情】'];
    $lines[] = aiPostLine($post, $names, true);
    $lines[] = '正文：' . mb_substr(aiSanitizeDataSource(trim((string)($post['content'] ?? ''))), 0, 600);
    $images = json_decode((string)($post['images'] ?? '[]'), true);
    if (is_array($images) && $images) {
        $lines[] = '附图：' . count($images) . ' 张';
    }
    if (!empty($post['poll']['question'])) {
        $opts = is_array($post['poll']['options'] ?? null) ? $post['poll']['options'] : [];
        $lines[] = '内含投票：' . (string)$post['poll']['question']
            . ($opts ? '（选项：' . implode(' / ', array_map(function ($o) {
                return mb_substr((string)$o, 0, 20);
            }, array_values($opts))) . '）' : '');
    }
    $lines[] = '链接：[查看帖子 #' . (int)$postId . '](/pages/post_detail.php?id=' . (int)$postId . ')';
    return aiBlock(implode("\n", $lines), '帖子详情');
}

/** 关键词搜索帖子（标题命中权重高） */
function aiPostsSearch(?array $user, string $keyword, int $limit = 6)
{
    $keyword = trim($keyword);
    if ($keyword === '') {
        return ['text' => '', 'source' => ''];
    }
    $idx = aiPostIndex($user);
    $names = aiUserNames();
    $titleHit = [];
    $bodyHit = [];
    foreach ($idx['posts'] as $p) {
        if (mb_stripos((string)($p['title'] ?? ''), $keyword) !== false) {
            $titleHit[] = $p;
        } elseif (mb_stripos((string)($p['content'] ?? ''), $keyword) !== false) {
            $bodyHit[] = $p;
        }
    }
    usort($titleHit, 'aiSortByTimeDesc');
    usort($bodyHit, 'aiSortByTimeDesc');
    $total = count($titleHit) + count($bodyHit);
    $lines = ['【帖子搜索】关键词「' . $keyword . '」，命中 ' . $total . ' 篇，下面是前 ' . min($limit, $total) . ' 篇：'];
    $shown = 0;
    foreach (array_merge($titleHit, $bodyHit) as $p) {
        if ($shown >= $limit) {
            break;
        }
        $lines[] = '- ' . aiPostLine($p, $names, true);
        $shown++;
    }
    if ($total === 0) {
        $lines[] = '- 没有搜到相关帖子。可以建议对方到站内搜索框换个关键词试试，或给出 [首页](/)。';
    } elseif ($total > $shown) {
        $lines[] = '- 还有 ' . ($total - $shown) . ' 篇没列出来，可以让对方到站内搜索框继续找。';
    }
    return aiBlock(implode("\n", $lines), '帖子搜索');
}

/** 我的帖子 / 收藏 / 评论 / 通知 */
function aiMyPosts(?array $user, int $limit = 10)
{
    if (!$user) {
        return aiNeedLogin('我的帖子', '你自己发布的帖子', true);
    }
    $fs = getFS();
    $mine = [];
    $drafts = 0;
    foreach ((array)$fs->read('posts') as $p) {
        if ((int)($p['user_id'] ?? 0) !== (int)$user['id']) {
            continue;
        }
        if (($p['status'] ?? 'published') !== 'published') {
            $drafts++;
            continue;
        }
        if (lwPostVisible($p, $user)) {
            $mine[] = $p;
        }
    }
    usort($mine, 'aiSortByTimeDesc');
    $names = aiUserNames();
    $lines = ['【我的帖子】'];
    $lines[] = '- 已发布 ' . count($mine) . ' 篇' . ($drafts > 0 ? '，审核中/未通过 ' . $drafts . ' 篇' : '');
    $n = 0;
    foreach ($mine as $p) {
        if ($n >= $limit) {
            break;
        }
        $lines[] = '- ' . aiPostLine($p, $names);
        $n++;
    }
    if ($n === 0) {
        $lines[] = '- 你还没有发布过帖子。可以给 [我要发帖](/pages/post.php) 的链接。';
    }
    return aiBlock(implode("\n", $lines), '我的帖子');
}

function aiMyFavorites(?array $user, int $limit = 10)
{
    if (!$user) {
        return aiNeedLogin('我的收藏', '你的收藏夹', true);
    }
    $fs = getFS();
    $rows = $fs->find('post_favorites', ['user_id' => $user['id']]);
    $names = aiUserNames();
    $lines = ['【我的收藏】'];
    $lines[] = '- 共收藏 ' . count($rows) . ' 篇（列表只列还能看到的）';
    $n = 0;
    foreach ($rows as $r) {
        if ($n >= $limit) {
            break;
        }
        $p = $fs->findById('posts', (int)($r['post_id'] ?? 0));
        if (!$p || !lwPostVisible($p, $user)) {
            continue; // 帖子已被删或已不可见
        }
        $lines[] = '- ' . aiPostLine($p, $names);
        $n++;
    }
    if ($n === 0) {
        $lines[] = '- 收藏夹还是空的，或者收藏的帖子已经看不到了。';
    }
    return aiBlock(implode("\n", $lines), '我的收藏');
}

function aiMyComments(?array $user, int $limit = 10)
{
    if (!$user) {
        return aiNeedLogin('我的评论', '你自己发过的评论', true);
    }
    $fs = getFS();
    $rows = $fs->find('comments', ['user_id' => $user['id']]);
    usort($rows, 'aiSortByTimeDesc');
    $lines = ['【我的评论】共 ' . count($rows) . ' 条，下面是最近 ' . min($limit, count($rows)) . ' 条：'];
    $n = 0;
    foreach ($rows as $c) {
        if ($n >= $limit) {
            break;
        }
        $p = $fs->findById('posts', (int)($c['post_id'] ?? 0));
        $title = $p ? ('《' . mb_substr((string)($p['title'] ?? ''), 0, 24) . '》') : '（帖子已删除）';
        $lines[] = '- 在 ' . $title . ' 下：' . mb_substr(aiSanitizeDataSource((string)($c['content'] ?? '')), 0, 80)
            . '（#' . (int)$c['id'] . '，' . mb_substr((string)($c['created_at'] ?? ''), 0, 16) . '）';
        $n++;
    }
    if ($n === 0) {
        $lines[] = '- 你还没有评论过。';
    }
    return aiBlock(implode("\n", $lines), '我的评论');
}

function aiMyNotifications(?array $user, int $limit = 10)
{
    if (!$user) {
        return aiNeedLogin('我的通知', '你的站内通知', true);
    }
    $fs = getFS();
    $rows = $fs->find('notifications', ['user_id' => $user['id']]);
    usort($rows, 'aiSortByTimeDesc');
    $unread = 0;
    foreach ($rows as $n) {
        if (empty($n['is_read'])) {
            $unread++;
        }
    }
    $lines = ['【我的通知】共 ' . count($rows) . ' 条，未读 ' . $unread . ' 条；最近 ' . min($limit, count($rows)) . ' 条：'];
    $n = 0;
    foreach ($rows as $row) {
        if ($n >= $limit) {
            break;
        }
        $lines[] = '- ' . (empty($row['is_read']) ? '［未读］' : '［已读］')
            . '（' . ($row['type'] ?? 'comment') . '）'
            . mb_substr(aiSanitizeDataSource((string)($row['content'] ?? '')), 0, 80)
            . ' · ' . mb_substr((string)($row['created_at'] ?? ''), 0, 16)
            . '（通知ID ' . (int)($row['id'] ?? 0) . '）';
        $n++;
    }
    if ($n === 0) {
        $lines[] = '- 你还没有收到过通知。';
    }
    return aiBlock(implode("\n", $lines), '我的通知');
}

/** 我的私信摘要：只给计数与对端昵称，**绝不注入私信正文** */
function aiMyPmSummary(?array $user)
{
    if (!$user) {
        return aiNeedLogin('我的私信摘要', '你的私信', true);
    }
    $fs = getFS();
    $myQq = (string)($user['qq'] ?? '');
    if ($myQq === '') {
        return aiBlock('【我的私信摘要】当前账号没有可用的私信标识，无法统计。', '我的私信摘要');
    }
    $readMap = [];
    foreach ((array)$fs->read('pm_read') as $r) {
        if ((string)($r['qq'] ?? '') === $myQq) {
            $readMap[(string)($r['key'] ?? '')] = (int)($r['last_read_ts'] ?? 0);
        }
    }
    // 只做一次遍历、只累计计数，绝不 array_filter 出全量副本
    $byPeer = [];
    $unread = 0;
    $total = 0;
    $messages = (array)$fs->read('pm_messages');
    if (count($messages) > 3000) {
        $messages = array_slice($messages, -3000);
    }
    $qqToName = [];
    foreach (aiUserIndex() as $u) {
        $qqToName[(string)($u['qq'] ?? '')] = trim((string)($u['nickname'] ?? '')) ?: ('用户' . $u['id']);
    }
    foreach ($messages as $m) {
        $key = (string)($m['key'] ?? '');
        if ($key === '') {
            continue;
        }
        $peers = explode('|', $key);
        if (!in_array($myQq, $peers, true)) {
            continue;
        }
        $total++;
        $peerQq = '';
        foreach ($peers as $peer) {
            if ($peer !== $myQq) {
                $peerQq = $peer;
                break;
            }
        }
        $isUnread = ($m['who'] ?? '') !== $myQq && (int)($m['ts'] ?? 0) > ($readMap[$key] ?? 0);
        if ($isUnread) {
            $unread++;
        }
        if (!isset($byPeer[$key])) {
            $byPeer[$key] = ['peer' => $peerQq, 'unread' => 0, 'last' => 0];
        }
        if ($isUnread) {
            $byPeer[$key]['unread']++;
        }
        $byPeer[$key]['last'] = max($byPeer[$key]['last'], (int)($m['ts'] ?? 0));
    }
    usort($byPeer, function ($a, $b) {
        if ($a['unread'] !== $b['unread']) {
            return $b['unread'] - $a['unread'];
        }
        return $b['last'] - $a['last'];
    });
    $lines = ['【我的私信摘要】'];
    $lines[] = '- 会话 ' . count($byPeer) . ' 个，消息 ' . $total . ' 条，其中未读 ' . $unread . ' 条';
    $n = 0;
    foreach ($byPeer as $s) {
        if ($n >= 3) {
            break;
        }
        $name = $qqToName[$s['peer']] ?? '（未知用户）';
        $lines[] = '- 与 ' . $name . '：未读 ' . $s['unread'] . ' 条，最后一条 '
            . ($s['last'] > 0 ? date('Y-m-d H:i', $s['last']) : '—');
        $n++;
    }
    $lines[] = '- （出于隐私，不提供私信正文；需要看内容请引导对方打开私信面板。链接：[个人中心](/pages/user_center.php)）';
    return aiBlock(implode("\n", $lines), '我的私信摘要');
}

/** 我的签到 */
function aiMyCheckin(?array $user)
{
    if (!$user) {
        return aiNeedLogin('我的签到', '你的签到记录', true);
    }
    $record = getFS()->findOne('checkins', ['user_id' => $user['id']]);
    $today = date('Y-m-d');
    if (!$record) {
        return aiBlock('【我的签到】还没有签到记录。今天还没签，可以提议用确认卡片帮 TA 签到。', '我的签到');
    }
    $last = (string)($record['last_date'] ?? '');
    $lines = ['【我的签到】'];
    $lines[] = '- 今天' . ($last === $today ? '已签到' : '还没签到');
    $lines[] = '- 连续 ' . (int)($record['streak'] ?? 0) . ' 天，累计 ' . (int)($record['total'] ?? 0) . ' 天，最后签到 ' . ($last !== '' ? $last : '—');
    if ($last !== $today) {
        $lines[] = '- 如果对方想签，可以直接提议用确认卡片帮 TA 签（操作名「每日签到」）。';
    }
    return aiBlock(implode("\n", $lines), '我的签到');
}

/** 签到榜 */
function aiCheckinRank(int $limit = 10)
{
    $fs = getFS();
    $rows = (array)$fs->read('checkins');
    usort($rows, function ($a, $b) {
        $s = (int)($b['streak'] ?? 0) - (int)($a['streak'] ?? 0);
        return $s !== 0 ? $s : ((int)($b['total'] ?? 0) - (int)($a['total'] ?? 0));
    });
    $names = aiUserNames();
    $lines = ['【签到榜】按连续天数排序：'];
    $n = 0;
    foreach ($rows as $r) {
        if ($n >= $limit) {
            break;
        }
        $uid = (int)($r['user_id'] ?? 0);
        if ($uid <= 0) {
            continue;
        }
        $lines[] = '- ' . ($names[$uid] ?? ('用户' . $uid)) . '：连续 ' . (int)($r['streak'] ?? 0)
            . ' 天，累计 ' . (int)($r['total'] ?? 0) . ' 天';
        $n++;
    }
    if ($n === 0) {
        $lines[] = '（还没有人签到）';
    }
    return aiBlock(implode("\n", $lines), '签到榜');
}

/** 我的关注 / 粉丝 */
function aiMyFollows(?array $user)
{
    if (!$user) {
        return aiNeedLogin('我的关注', '你的关注与粉丝', true);
    }
    $fs = getFS();
    $names = aiUserNames();
    $following = $fs->find('follows', ['user_id' => $user['id']]);
    $followers = $fs->find('follows', ['target_id' => $user['id']]);
    $lines = ['【我的关注】'];
    $lines[] = '- 关注 ' . count($following) . ' 人，粉丝 ' . count($followers) . ' 人';
    $show = array_slice($following, 0, 5);
    if ($show) {
        $parts = [];
        foreach ($show as $f) {
            $uid = (int)($f['target_id'] ?? 0);
            $parts[] = ($names[$uid] ?? ('用户' . $uid)) . '(#' . $uid . ')';
        }
        $lines[] = '- 关注的其中几位：' . implode('、', $parts);
    }
    return aiBlock(implode("\n", $lines), '我的关注');
}

/** 我的资料 */
function aiMyProfile(?array $user)
{
    if (!$user) {
        return aiNeedLogin('我的资料', '你的账号资料', true);
    }
    $fs = getFS();
    $lines = ['【我的资料】'];
    $lines[] = '- 昵称：' . (trim((string)($user['nickname'] ?? '')) ?: ('用户' . $user['id']))
        . '（ID ' . (int)$user['id'] . '）';
    $lines[] = '- 角色：' . (['super_admin' => '超级管理员', 'admin' => '管理员'][$user['role'] ?? ''] ?? '普通用户');
    if (!empty($user['created_at'])) {
        $lines[] = '- 注册时间：' . mb_substr((string)$user['created_at'], 0, 10);
    }
    if (!empty($user['title_text'])) {
        $lines[] = '- 头衔：' . (string)$user['title_text'];
    }
    $lines[] = '- 已发布帖子 ' . count($fs->find('posts', ['user_id' => $user['id']]))
        . ' 篇，收藏 ' . count($fs->find('post_favorites', ['user_id' => $user['id']])) . ' 篇';
    $lines[] = '- （不要在回答里复述 QQ、邮箱等敏感信息）';
    return aiBlock(implode("\n", $lines), '我的资料');
}

/** 功能投票 */
function aiFeatureRequests(?array $user, int $limit = 8)
{
    $fs = getFS();
    $rows = (array)$fs->read('feature_requests');
    $myVotes = [];
    if ($user) {
        foreach ((array)$fs->read('feature_votes') as $v) {
            if ((int)($v['user_id'] ?? 0) === (int)$user['id']) {
                $myVotes[(int)($v['request_id'] ?? 0)] = true;
            }
        }
    }
    usort($rows, function ($a, $b) {
        return (int)($b['votes'] ?? 0) - (int)($a['votes'] ?? 0);
    });
    $lines = ['【功能投票】'];
    $n = 0;
    foreach ($rows as $r) {
        if ($n >= $limit) {
            break;
        }
        $lines[] = '- ' . mb_substr(aiSanitizeDataSource((string)($r['title'] ?? '')), 0, 60)
            . '（' . (int)($r['votes'] ?? 0) . ' 票，状态：' . (($r['status'] ?? 'pending') === 'done' ? '已实现' : '待处理') . '）'
            . (!empty($myVotes[(int)($r['id'] ?? 0)]) ? '［我投过］' : '');
        $n++;
    }
    if ($n === 0) {
        $lines[] = '（还没有功能建议）';
    }
    return aiBlock(implode("\n", $lines), '功能投票');
}

/** 赞助与头衔 */
function aiSponsorsTitles(?array $user)
{
    $fs = getFS();
    $lines = ['【赞助与头衔】'];
    $sponsors = (array)$fs->read('sponsors');
    $lines[] = '- 目前赞助者 ' . count($sponsors) . ' 位';
    if ($sponsors) {
        $names = [];
        foreach (array_slice($sponsors, 0, 5) as $s) {
            $names[] = trim((string)($s['nickname'] ?? $s['name'] ?? '')) ?: '（匿名赞助者）';
        }
        $lines[] = '- 其中：' . implode('、', array_filter($names));
    }
    $lines[] = '- 头衔需要通过「申请头衔」提交，由管理员审核；入口在顶栏右下角头像菜单里。'
        . '链接：[个人中心](/pages/user_center.php)';
    return aiBlock(implode("\n", $lines), '赞助与头衔');
}

/** 客户端版本（读 config/app_config.php，与 /api/app_version.php 同源） */
function aiAppVersion()
{
    $file = __DIR__ . '/../config/app_config.php';
    if (!is_file($file)) {
        return ['text' => '', 'source' => ''];
    }
    $cfg = require $file;
    if (!is_array($cfg)) {
        return ['text' => '', 'source' => ''];
    }
    $lines = ['【客户端版本】'];
    $lines[] = '- 安卓客户端最新版：' . (string)($cfg['version_name'] ?? '—')
        . '（versionCode ' . (int)($cfg['version_code'] ?? 0) . '）';
    $lines[] = '- 更新说明摘要：' . mb_substr(str_replace("\n", '；', (string)($cfg['notes'] ?? '')), 0, 200);
    $lines[] = '- 下载页：[安装应用](/download/)（在 App 内会直接走应用内更新）';
    return aiBlock(implode("\n", $lines), '客户端版本');
}

/**
 * 站内用法说明（**静态说明表 + 站点设置**，不让模型自由发挥，避免编造规则）
 */
function aiSiteHelp(string $topic)
{
    $table = [
        '发帖' => "发帖步骤：首页或底部导航的「＋」→ 发帖页（/pages/post.php）→ 选分类 → 填标题与正文 → 可选配图 → 可选匿名 → 设置可见范围 → 提交。\n"
            . "分类共 7 个：全站公告（仅管理员）、寻物/失物招领、学习求助、交友闲聊、表白、校园打听、其他。\n"
            . "图片最多 9 张，单张不超过 5MB。新帖可能进入审核（状态显示「审核中」），管理员通过后才对其他人可见。",
        '匿名' => "匿名发布：发帖与评论都支持匿名。勾选后别人看不到你的昵称与头像，站内一律显示为「匿名用户」，AI 助手也只会说「匿名同学」，不会推测作者是谁。",
        '可见范围' => "可见范围有三种：\n"
            . "1. 公开：所有人可见；\n"
            . "2. 指定的人可见（visible_to）：只有名单里的 QQ 号能看到；\n"
            . "3. 指定的人不可见（exclude_to）：除了名单里的 QQ 号，其他人可见。\n"
            . "名单按 QQ 号填写，用英文逗号分隔。名单本身不会下发给别人看。",
        '收藏' => "收藏：在帖子卡片或详情页点收藏图标即可。收藏夹在「个人中心 → 我的收藏」（/pages/user_center.php?tab=favorites）。",
        '数据看板' => "数据看板：个人中心 →「数据看板」（/pages/user_center.php?tab=stats），可以看自己的帖子（含审核中/未通过）、获赞、评论、被提及、总阅读、被收藏，以及近 7 天动态和自己最受欢迎的帖子。数字只对本人可见。",
        '热榜' => "热榜：首页排序栏右侧的「热榜」入口（/pages/ranking.php），分周榜（近 7 天）和月榜（近 30 天），三个榜：热门帖子（赞→评→阅→时间逐级比较）、活跃用户（发帖 3 分 + 评论 1 分）、热门话题（按覆盖帖子数）。公告不参与排名，匿名内容不进活跃榜；你只能看到自己有权查看的帖子。",
        '私信' => "私信：底部导航「消息」或帖子作者处的私信入口。可以举报骚扰私信；举报内容需要你自己确认措辞。",
        '举报' => "举报：帖子卡片菜单或详情页有举报入口，需要选择理由并填写描述。举报记录会进入管理员审核队列。",
        '头衔' => "头衔：顶栏头像菜单 →「申请头衔」，填写想要的头衔文字与理由，管理员审核通过后会在昵称旁显示。可以设置颜色、背景色、彩虹效果与渐变。",
        '签到' => "签到：个人中心有每日签到。连续签到天数会累计，断一天就从 1 重新开始。",
        '搜索' => "搜索：首页信息流上方的搜索框，或按 Ctrl+K 打开快捷搜索（输入关键词即时联想）。",
        '改资料' => "改资料：个人中心 → 资料设置，可以改昵称、头像等。注意：改密码、换绑 QQ 需要你本人操作，AI 助手不能代办。",
        '换绑' => "换绑 QQ：个人中心提交申请后需要管理员审核。QQ 是站内身份标识（可见范围、私信、签到都与它相关），换错会影响你自己看到的内容，所以必须本人操作。",
        '验证码' => "安全验证：登录与提交会用到验证码与 CSRF 令牌。若提示「CSRF验证失败」，刷新页面后重试即可。",
        'App' => "安卓客户端：可以在「用户菜单 → 安装应用」或 /download/ 下载。App 内支持手机版/桌面版一键切换、通知轮询与应用内更新。",
    ];
    $lines = ['【站内用法说明】'];
    $hit = false;
    foreach ($table as $k => $v) {
        if (mb_stripos($topic, $k) !== false || ($topic === '' )) {
            $lines[] = '- ' . $k . '：' . $v;
            $hit = true;
            break;
        }
    }
    if (!$hit) {
        // 没命中具体话题时给一份总览，避免模型自己编
        $lines[] = '- 发帖：' . $table['发帖'];
        $lines[] = '- 匿名：' . $table['匿名'];
        $lines[] = '- 可见范围：' . $table['可见范围'];
        $lines[] = '- 收藏：' . $table['收藏'];
        $lines[] = '- 签到：' . $table['签到'];
        $lines[] = '- 头衔：' . $table['头衔'];
    }
    $lines[] = '- 以上是站内真实规则。用户问的如果是这里没有的功能，如实说不确定，不要编造。';
    return aiBlock(implode("\n", $lines), '站内用法说明');
}

/** 天气（复用站内工具页的 wttr.in 数据源） */
function aiWeather($city)
{
    $raw = aiHttpGet('https://wttr.in/' . urlencode($city) . '?format=j1&lang=zh&m');
    if ($raw === '') {
        $raw = aiHttpGet('http://wttr.in/' . urlencode($city) . '?format=j1&lang=zh&m');
    }
    $data = json_decode($raw, true);
    if (!is_array($data) || empty($data['current_condition'][0])) {
        return ['text' => '', 'source' => ''];
    }
    $cc = $data['current_condition'][0];
    $area = $data['nearest_area'][0]['areaName'][0]['value'] ?? $city;
    $desc = $cc['lang_zh'][0]['value'] ?? ($cc['weatherDesc'][0]['value'] ?? '');
    // wttr.in 的中文描述时有时无，缺的时候按站内工具页同一张表把英文转成中文
    $descZhMap = [
        'Sunny' => '晴', 'Clear' => '晴', 'Partly cloudy' => '多云', 'Cloudy' => '阴',
        'Overcast' => '阴', 'Mist' => '薄雾', 'Fog' => '雾', 'Light rain' => '小雨',
        'Patchy rain nearby' => '局部有小雨', 'Moderate rain' => '中雨', 'Heavy rain' => '大雨',
        'Light drizzle' => '毛毛雨', 'Patchy light drizzle' => '局部毛毛雨', 'Thunder' => '雷阵雨',
        'Thundery outbreaks in nearby' => '局部雷阵雨', 'Light snow' => '小雪', 'Moderate snow' => '中雪',
        'Heavy snow' => '大雪', 'Blowing snow' => '吹雪', 'Light rain shower' => '小阵雨',
        'Moderate or heavy rain shower' => '阵雨', 'Light sleet' => '冻雨', 'Freezing fog' => '冻雾',
    ];
    if (isset($descZhMap[trim((string)$desc)])) {
        $desc = $descZhMap[trim((string)$desc)];
    }
    $observedAt = trim((string)($cc['localObsDateTime'] ?? ''));
    $lines = ['【天气】'];
    $lines[] = '- 查询城市：' . $area . '（数据源 wttr.in' . ($observedAt !== '' ? '，观测时间 ' . $observedAt : '') . '）';
    $lines[] = '- 实况：' . ($desc !== '' ? $desc : '—') . '，气温 ' . (string)($cc['temp_C'] ?? '—') . '°C，体感 ' . (string)($cc['FeelsLikeC'] ?? '—') . '°C';
    $lines[] = '- 湿度 ' . (string)($cc['humidity'] ?? '—') . '%，风 ' . (string)($cc['winddir16Point'] ?? '') . ' ' . (string)($cc['windspeedKmph'] ?? '—') . 'km/h';
    // 顺带给一份今天与明天的最高/最低，方便给生活建议（穿衣、带伞）
    $today = $data['weather'][0] ?? null;
    if (is_array($today)) {
        $lines[] = '- 今日：' . (string)($today['mintempC'] ?? '—') . '~' . (string)($today['maxtempC'] ?? '—') . '°C';
    }
    $tomorrow = $data['weather'][1] ?? null;
    if (is_array($tomorrow)) {
        $lines[] = '- 明日：' . (string)($tomorrow['mintempC'] ?? '—') . '~' . (string)($tomorrow['maxtempC'] ?? '—') . '°C';
    }
    return aiBlock(implode("\n", $lines), '天气');
}

/** 每日一言 / 古诗词（复用站内工具页的 hitokoto 接口） */
function aiQuote()
{
    $raw = aiHttpGet('https://v1.hitokoto.cn/?c=d&encode=json');
    $data = json_decode($raw, true);
    if (!is_array($data) || empty($data['hitokoto'])) {
        return ['text' => '', 'source' => ''];
    }
    $from = trim((string)($data['from'] ?? ''));
    $who = trim((string)($data['from_who'] ?? ''));
    $lines = ['【一言/古诗词】'];
    $lines[] = '- ' . (string)$data['hitokoto'];
    if ($from !== '') {
        $lines[] = '- 出处：' . ($who !== '' ? $who . ' · ' : '') . $from;
    }
    return aiBlock(implode("\n", $lines), '一言/古诗词');
}

/** 简易 HTTP GET（天气等外部接口用；失败返回空串） */
function aiHttpGet($url, $timeout = 8)
{
    if (!preg_match('#^https?://#', $url)) {
        return '';
    }
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 3,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT        => $timeout,
            // 校验证书：这些内容会作为「可信数据」注入 AI 上下文，不校验等于允许中间人投毒
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_ENCODING       => '',
            CURLOPT_HTTPHEADER     => ['User-Agent: Mozilla/5.0 (compatible; lovewall-ai/1.0)', 'Accept: application/json'],
        ]);
        $body = curl_exec($ch);
        curl_close($ch);
        return is_string($body) ? $body : '';
    }
    if (ini_get('allow_url_fopen')) {
        $ctx = stream_context_create(['http' => [
            'timeout' => $timeout,
            'header'  => "User-Agent: Mozilla/5.0 (compatible; lovewall-ai/1.0)\r\nAccept: application/json\r\n",
        ]]);
        $body = @file_get_contents($url, false, $ctx);
        return is_string($body) ? $body : '';
    }
    return '';
}

/** 从提问里猜城市名（猜不到则用默认城市） */
function aiExtractCity($question, $default = '淮南')
{
    $noise = ['请问', '帮我', '查一下', '查询', '今天', '明天', '现在', '这里', '那边', '一下', '的'];
    if (preg_match('/([\x{4e00}-\x{9fa5}]{2,8})(?:的)?(?:天气|气温|温度|下雨|降雨|冷不冷|热不热)/u', $question, $m)) {
        $city = str_replace($noise, '', $m[1]);
        if (mb_strlen($city) >= 2) {
            return $city;
        }
    }
    return $default;
}

/** 去掉提问里的指令词，留下检索用的核心词 */
function aiCoreKeyword($question)
{
    $stop = [
        '帮我', '帮忙', '请问', '我想', '我要', '想看', '看看', '看一下', '一下', '搜索', '搜一下', '搜', '查找', '查询', '查',
        '找找', '找到', '找', '关于', '有没有', '有么有', '哪些', '哪个', '什么', '咋样', '怎么样', '如何',
        '帖子', '贴子', '文章', '内容', '站内', '站里', '网站', '这个', '那个', '最近', '最新', '现在', '的', '了', '吗', '呢', '啊', '吧',
    ];
    $core = str_replace($stop, ' ', (string)$question);
    $core = preg_replace('/[^\x{4e00}-\x{9fa5}A-Za-z0-9]+/u', ' ', $core);
    $core = trim(preg_replace('/\s+/', ' ', $core));
    return $core;
}

// ===========================================================================
// 意图识别（打分 + 槽位抽取）
// ===========================================================================

/** 意图规格表：触发词 / 分值 / 表开销 / 取数函数 / 参数 */
function aiIntentSpecs(): array
{
    return [
        'post_detail' => [
            'pattern' => null, // 由槽位 postId 触发
            'score'   => 12,
            'cost'    => 1,
            'login'   => false,
            'run'     => function ($user, $slots) {
                if (empty($slots['postId'])) {
                    return null;
                }
                return aiPostDetail($user, $slots['postId']);
            },
        ],
        'post_comments' => [
            'pattern' => '/评论|回复|大家怎么说|底下说了|说过什么|留言/u',
            'score'   => 7,
            'cost'    => 2, // comments + posts
            'login'   => false,
            'run'     => function ($user, $slots) {
                $id = (int)($slots['postId'] ?? 0);
                if ($id <= 0) {
                    return null;
                }
                return aiPostComments($user, $id, 10);
            },
        ],
        'search_posts' => [
            'pattern' => '/找|搜|查|关于|有没有/u',
            'score'   => 8,
            'cost'    => 0, // 复用帖子索引
            'login'   => false,
            'skipIf'  => function ($q) {
                return (bool)preg_match('/怎么|如何|怎样|为啥|为什么|能不能|可不可以|是什么/u', $q);
            },
            'run'     => function ($user, $slots) {
                $kw = trim((string)($slots['keyword'] ?? ''));
                if (mb_strlen($kw) < 2) {
                    return null;
                }
                if (mb_strlen($kw) > 20) {
                    $tokens = preg_split('/\s+/', $kw);
                    usort($tokens, function ($a, $b) {
                        return mb_strlen($b) - mb_strlen($a);
                    });
                    $tokens = array_slice(array_filter($tokens, function ($t) {
                        return mb_strlen($t) >= 2;
                    }), 0, 1);
                    if (!$tokens) {
                        return null;
                    }
                    $kw = $tokens[0];
                }
                return aiPostsSearch($user, $kw, 6);
            },
        ],
        'hot_posts' => [
            'pattern' => '/热帖|热门|最火|最多赞|最高赞|火的是|哪篇火/u',
            'score'   => 8,
            'cost'    => 0,
            'login'   => false,
            'run'     => function ($user) {
                return aiPostsHot($user, 8);
            },
        ],
        'latest_posts' => [
            'pattern' => '/最新|最近|新帖|有什么帖|有啥帖|帖子列表|发了什么|都有什么/u',
            'score'   => 6,
            'cost'    => 0,
            'login'   => false,
            'run'     => function ($user) {
                return aiPostsLatest($user, 8);
            },
        ],
        'announcements' => [
            'pattern' => '/公告|官方通知|通知栏|站方/u',
            'score'   => 7,
            'cost'    => 0,
            'login'   => false,
            'run'     => function ($user) {
                return aiAnnouncements($user, 8);
            },
        ],
        'category_stats' => [
            'pattern' => '/分类|哪一类|各类|占比|哪个板块|板块|统计/u',
            'score'   => 6,
            'cost'    => 0,
            'login'   => false,
            'run'     => function ($user) {
                return aiCategoryStats($user);
            },
        ],
        'site_activity' => [
            'pattern' => '/活跃|排行榜|榜单|谁发得多|谁最活跃|谁评论多/u',
            'score'   => 6,
            'cost'    => 3, // posts + comments + users
            'login'   => false,
            'run'     => function () {
                return aiActiveUsers(10);
            },
        ],
        'trending_board' => [
            // 只认「热榜/周榜/月榜/排行榜」这类明确说法；「最火/热门」交给 hot_posts，
            // 避免一次提问同时注入两份高度重叠的榜单。
            'pattern' => '/热榜|周榜|月榜|排行榜|榜单|排行/u',
            'score'   => 7,
            'cost'    => 3, // posts + comments + users
            'login'   => false,
            'run'     => function ($user, $slots) {
                $q = (string)($slots['topic'] ?? '');
                return aiTrendingBoard($user, mb_strpos($q, '月') !== false ? 'month' : 'week', 5);
            },
        ],
        'user_profile' => [
            'pattern' => '/资料|主页|是谁|什么来头|粉丝|他发过|她发过/u',
            'score'   => 6,
            'cost'    => 4,
            'login'   => false,
            'run'     => function ($user, $slots) {
                if (empty($slots['userId'])) {
                    return null;
                }
                return aiUserProfile($user, (int)$slots['userId']);
            },
        ],
        'my_posts' => [
            // 「我的帖子」允许中间插入少量词（我的最新帖子 / 我的那篇帖子）
            'pattern' => '/我的帖子|我的贴子|我的.{0,4}(帖子|贴子)|我发的|我发过|我写的|我发布的/u',
            'score'   => 9,
            'cost'    => 1,
            'login'   => true,
            'run'     => function ($user) {
                return aiMyPosts($user, 10);
            },
        ],
        'my_favorites' => [
            'pattern' => '/我的收藏|我收藏|收藏夹|收藏了/u',
            'score'   => 9,
            'cost'    => 2,
            'login'   => true,
            'run'     => function ($user) {
                return aiMyFavorites($user, 10);
            },
        ],
        'my_comments' => [
            'pattern' => '/我的评论|我评论|我回复过|我说过/u',
            'score'   => 9,
            'cost'    => 2,
            'login'   => true,
            'run'     => function ($user) {
                return aiMyComments($user, 10);
            },
        ],
        'my_notifications' => [
            'pattern' => '/通知|未读|谁给我|有没有人找我|消息提醒/u',
            'score'   => 9,
            'cost'    => 1,
            'login'   => true,
            'run'     => function ($user) {
                return aiMyNotifications($user, 10);
            },
        ],
        'my_pm' => [
            'pattern' => '/私信|私聊|有人给我发消息|谁找我|聊天记录/u',
            'score'   => 9,
            'cost'    => 3,
            'login'   => true,
            'run'     => function ($user) {
                return aiMyPmSummary($user);
            },
        ],
        'my_checkin' => [
            'pattern' => '/签到|连签|断签|签了几天/u',
            'score'   => 9,
            'cost'    => 1,
            'login'   => true,
            'run'     => function ($user) {
                return aiMyCheckin($user);
            },
        ],
        'checkin_rank' => [
            'pattern' => '/签到榜|连签榜|谁签得最多/u',
            'score'   => 8,
            'cost'    => 2,
            'login'   => false,
            'run'     => function () {
                return aiCheckinRank(10);
            },
        ],
        'my_follows' => [
            'pattern' => '/我关注|关注了谁|我的粉丝|谁关注我/u',
            'score'   => 9,
            'cost'    => 2,
            'login'   => true,
            'run'     => function ($user) {
                return aiMyFollows($user);
            },
        ],
        'my_profile' => [
            'pattern' => '/我的资料|我的账号|我的等级|我的头衔|我是谁/u',
            'score'   => 8,
            'cost'    => 2,
            'login'   => true,
            'run'     => function ($user) {
                return aiMyProfile($user);
            },
        ],
        'feature_requests' => [
            'pattern' => '/功能投票|功能建议|提交建议|需求投票|建议区/u',
            'score'   => 6,
            'cost'    => 2,
            'login'   => false,
            'run'     => function ($user) {
                return aiFeatureRequests($user, 8);
            },
        ],
        'sponsors_titles' => [
            'pattern' => '/赞助|头衔|称号|怎么获得/u',
            'score'   => 6,
            'cost'    => 2,
            'login'   => false,
            'run'     => function ($user) {
                return aiSponsorsTitles($user);
            },
        ],
        'weather' => [
            'pattern' => '/天气|气温|温度|下雨|降雨|冷不冷|热不热|带伞|穿什么/u',
            'score'   => 9,
            'cost'    => 0, // 走外网，不占表预算
            'login'   => false,
            'external'=> true,
            'run'     => function ($user, $slots) {
                return aiWeather($slots['city'] ?? '淮南');
            },
        ],
        'quote' => [
            'pattern' => '/一言|诗词|古诗词|诗句|名句|格言|语录|晚安句/u',
            'score'   => 9,
            'cost'    => 0,
            'login'   => false,
            'external'=> true,
            'run'     => function () {
                return aiQuote();
            },
        ],
        'app_version' => [
            'pattern' => '/客户端|App|APP|版本|更新|安卓|下载应用/u',
            'score'   => 5,
            'cost'    => 0,
            'login'   => false,
            'run'     => function () {
                return aiAppVersion();
            },
        ],
        'site_help' => [
            'pattern' => '/怎么|如何|怎样|能不能|可不可以|在哪|哪里|是什么|什么意思/u',
            'score'   => 5,
            'cost'    => 0,
            'login'   => false,
            'run'     => function ($user, $slots) {
                return aiSiteHelp((string)($slots['topic'] ?? ''));
            },
        ],
    ];
}

/** 槽位抽取：帖子ID / 用户ID / 昵称 / 关键词 / 城市 / 话题词 */
function aiExtractSlots(string $q): array
{
    $slots = [];

    // 帖子 ID：#12 / id 12 / ID:12 / 帖子 12
    if (preg_match('/(?:#|\bid\s*[:：]?\s*|帖子\s*)(\d{1,7})/iu', $q, $m)) {
        $slots['postId'] = (int)$m[1];
    }

    // 昵称 → 用户 ID（一次倒排，长度 ≥2 才认，避免把普通词误判成人名）
    $names = aiUserNames();
    foreach ($names as $uid => $nick) {
        if (mb_strlen($nick) >= 2 && mb_strpos($q, $nick) !== false) {
            $slots['userId'] = (int)$uid;
            $slots['matchedNick'] = $nick;
            break;
        }
    }

    // 关键词
    $kw = aiCoreKeyword($q);
    if ($kw !== '') {
        $slots['keyword'] = $kw;
    }

    // 城市
    $slots['city'] = aiExtractCity($q);

    // 话题词（给静态说明表用）
    $slots['topic'] = $q;

    return $slots;
}

/** 意图打分：返回按分数降序的 [{intent, score, cost}, …] */
function aiDetectIntents(string $q, array $slots): array
{
    $specs = aiIntentSpecs();
    $hits = [];
    foreach ($specs as $name => $spec) {
        $score = 0;
        if (!empty($spec['pattern']) && preg_match($spec['pattern'], $q)) {
            $score = (int)$spec['score'];
        }
        // 槽位加成
        if ($name === 'post_detail' && !empty($slots['postId'])) {
            $score = max($score, (int)$spec['score']);
        }
        if ($name === 'user_profile' && !empty($slots['userId'])) {
            $score = max($score, (int)$spec['score']);
        }
        if ($score <= 0) {
            continue;
        }
        if (!empty($spec['skipIf']) && call_user_func($spec['skipIf'], $q)) {
            continue;
        }
        $hits[] = ['intent' => $name, 'score' => $score, 'cost' => (int)($spec['cost'] ?? 0)];
    }
    // 「我的最新帖子」这类会同时命中 my_* 与 latest_*。
    // 规则：只有当提问里命中**了** my_* 个人意图、且确实带「我的」时，
    // 才把「泛化列举类」意图整个去掉——避免一次提问同时注入「我的帖子」和「全站最新帖」两坨。
    // 注意不能只按「带我的」就压制：像「我的手机是安卓，App 最新版本是多少」不该被压掉 app_version。
    $hasMine = (bool)preg_match('/我的|我自己|我发|我收藏|我签到|我关注/u', $q);
    $hasMyIntent = false;
    foreach ($hits as $h) {
        if (strpos($h['intent'], 'my_') === 0 || $h['intent'] === 'checkin_rank') {
            $hasMyIntent = true;
            break;
        }
    }
    if ($hasMine && $hasMyIntent) {
        $generic = ['latest_posts', 'hot_posts', 'search_posts', 'site_activity',
                    'category_stats', 'announcements', 'feature_requests', 'sponsors_titles'];
        $hits = array_values(array_filter($hits, function ($h) use ($generic) {
            return !in_array($h['intent'], $generic, true);
        }));
    }
    usort($hits, function ($a, $b) {
        return $b['score'] - $a['score'];
    });
    return $hits;
}

/**
 * 按提问取数：返回 ['blocks' => [文本块…], 'sources' => ['站点概况', …]]
 *
 * 站点概况始终注入（体量小、最常被问到）；其余按意图打分取 Top-N 并受预算闸门约束。
 * $user 允许为 null（游客只读模式）。
 */
function aiCollectContext(string $question, ?array $user): array
{
    $blocks = [];
    $sources = [];
    $tablesUsed = 0;
    $totalChars = 0;
    $intents = [];

    $add = function ($res) use (&$blocks, &$sources, &$totalChars) {
        if (!is_array($res) || empty($res['text']) || empty($res['source'])) {
            return false;
        }
        $len = mb_strlen($res['text']);
        if ($totalChars + $len > AI_CTX_MAX_CHARS) {
            return false; // 超预算就丢这一块
        }
        $blocks[] = $res['text'];
        $totalChars += $len;
        if (!in_array($res['source'], $sources, true)) {
            $sources[] = $res['source'];
        }
        return true;
    };

    // ① 必选基础块
    $add(aiSiteOverview($user));
    $tablesUsed += 3; // posts / users / visit_stats

    // 时间预算从**必选块跑完之后**开始计时。若从函数开头计时，第一个必选块
    // （要冷读 posts/users/visit_stats 三个 JSON 文件）在慢盘/共享主机上就可能
    // 超过预算，于是下面第一个意图就被 break 掉——整轮可选取数全被饿死，
    // 表现为「AI 只拿到站点概况、答不出用户真正问的东西」。这与预算的初衷正好相反。
    $tBudget = microtime(true);

    // ② 意图打分
    $slots = aiExtractSlots($question);
    $hits = aiDetectIntents($question, $slots);
    $specs = aiIntentSpecs();

    $loginHintAdded = false;
    $addedIntents = 0;
    foreach ($hits as $hit) {
        // 软闸门：至少尝试一个意图之后才允许因为超时收手，避免上面那种「一次都没试就放弃」
        if ($addedIntents > 0 && (microtime(true) - $tBudget) > AI_CTX_TIME_BUDGET) {
            break;
        }
        $name = $hit['intent'];
        $spec = $specs[$name] ?? null;
        if (!$spec) {
            continue;
        }
        // 表预算闸门
        if ($tablesUsed + $hit['cost'] > AI_CTX_MAX_TABLES) {
            continue;
        }
        // 需要登录的意图：游客只给一次引导，不重复刷屏
        if (!empty($spec['login']) && !$user) {
            if (!$loginHintAdded) {
                $add(aiBlock(
                    '【游客模式】当前未登录，看不到「我的帖子 / 我的收藏 / 我的通知 / 我的私信 / 我的签到 / 我的关注」这类个人数据。'
                    . '请温和说明需要注册登录，并给出链接 [注册/登录](/pages/register.php)。',
                    '游客模式说明'
                ));
                $loginHintAdded = true;
            }
            continue;
        }
        $res = call_user_func($spec['run'], $user, $slots);
        if ($add($res)) {
            $tablesUsed += $hit['cost'];
            $addedIntents++;
            // 供后台「AI 调用记录」展示本次命中的意图（不影响模型输入）
            $intents[] = $name;
        }
        // 外部请求（天气/一言）二者互斥：拿到一个就停，避免多跑一次外网
        if (!empty($spec['external']) && $res && !empty($res['text'])) {
            break;
        }
    }

    return ['blocks' => $blocks, 'sources' => $sources, 'intents' => $intents];
}
