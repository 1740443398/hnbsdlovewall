<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/fields.php';
// 评论要带 author_frame（头像框），lwUserDecorBundle 在 widget_render.php 里
require_once __DIR__ . '/../../includes/widget_render.php';

$postId = intval($_REQUEST['id'] ?? 0);
if (!$postId) {
    jsonError('帖子ID无效');
}

// 帖子详情仅对已注册用户开放，游客会被引导注册
$user = requireMember('帖子详情需要注册账号后才能查看');
$fs = getFS();
$post = $fs->findById('posts', $postId);

if (!$post) {
    jsonError('帖子不存在');
}

$isAuthor = $user && $user['id'] == $post['user_id'];
$userIsAdmin = $user && in_array($user['role'], ['admin', 'super_admin']);

if ($post['status'] !== 'published' && !$isAuthor && !$userIsAdmin) {
    jsonError('帖子尚未发布');
}

if (!$userIsAdmin && !$isAuthor) {
    $vis = $post['visibility'] ?? 'public';
    if ($vis === 'visible_to') {
        $allowedQQs = array_map('trim', explode(',', $post['visible_to'] ?? ''));
        if (!$user || !in_array($user['qq'], $allowedQQs)) {
            jsonError('无权查看该帖子', 403);
        }
    } elseif ($vis === 'exclude_to') {
        $excludedQQs = array_map('trim', explode(',', $post['exclude_to'] ?? ''));
        if ($user && in_array($user['qq'], $excludedQQs)) {
            jsonError('无权查看该帖子', 403);
        }
    }
}

$isAnonymous = !empty($post['is_anonymous']);
$postUser = $isAnonymous ? null : $fs->findById('users', $post['user_id']);

$liked = false;
if ($user) {
    $likes = $fs->find('post_likes', ['user_id' => $user['id'], 'post_id' => $post['id']]);
    $liked = !empty($likes);
}

$favorited = false;
if ($user) {
    $favs = $fs->find('post_favorites', ['user_id' => $user['id'], 'post_id' => $post['id']]);
    $favorited = !empty($favs);
}

// 预览模式（?preview=1）：只回帖子本体，**完全跳过 comments 表的读取与排序**。
// 供列表页的 hover 预览卡使用 —— 预览只需要正文，把整张评论表读进来纯属浪费。
// 不传 preview 时行为与改造前完全一致（响应里不会多出任何键）。
// 复用本接口而不是在 JS 里拼摘要，是为了让预览同样受「状态 + 可见权限」约束：
// 非 published / visible_to / exclude_to 都会在这里被拦掉，预览不会成为绕过可见性的旁路。
$preview = ((string)($_REQUEST['preview'] ?? '')) === '1';

$payload = [
    'post' => [
        'id' => $post['id'],
        'category' => $post['category'],
        'title' => $post['title'],
        'content' => $post['content'],
        'images' => postImagesExisting($post),
        'is_anonymous' => $isAnonymous,
        'author_nickname' => $isAnonymous ? '匿名用户' : ($postUser['nickname'] ?? ''),
        'author_avatar' => $isAnonymous ? '/assets/images/default-avatar.svg' : ($postUser['avatar'] ?? ''),
        'author_title_text' => $isAnonymous ? '' : ($postUser['title_text'] ?? ''),
        'author_title_color' => $isAnonymous ? '' : ($postUser['title_color'] ?? ''),
        'author_title_bg_color' => $isAnonymous ? '' : ($postUser['title_bg_color'] ?? ''),
        'author_title_rainbow' => $isAnonymous ? 0 : intval($postUser['title_rainbow'] ?? 0),
        'author_title_gradient_start' => $isAnonymous ? '' : ($postUser['title_gradient_start'] ?? ''),
        'author_title_gradient_end' => $isAnonymous ? '' : ($postUser['title_gradient_end'] ?? ''),
        'is_author' => $isAuthor,
        'likes' => $post['likes'] ?? 0,
        'comments' => $post['comments'] ?? 0,
        'is_liked' => $liked,
        'is_favorited' => $favorited,
        'created_at' => $post['created_at'],
        'status' => $post['status'],
        // 阅读数：列表页有、详情页原来漏了，导致点进详情后阅读数消失
        'views' => (int)($post['views'] ?? 0),
        /* 投票：原来这个接口完全没返回 poll 字段。
         *
         * 帖子里明明带着投票，网页版也看得到，但 App 点进详情就不显示 ——
         * 因为 App 拿到的 post 对象里压根没有 poll 这个键。
         *
         * 这里按 App 期望的结构输出：
         *   question / options[] / counts[] / total / my_vote
         * counts 与 options 一一对应；my_vote 没投过是 -1。
         */
        'poll' => (function () use ($post, $user) {
            $poll = $post['poll'] ?? null;
            if (!is_array($poll)) {
                return null;
            }
            $question = trim((string)($poll['question'] ?? ''));
            $options = is_array($poll['options'] ?? null) ? $poll['options'] : [];
            if ($question === '' || count($options) < 2) {
                return null;
            }
            $votes = (is_array($poll['votes'] ?? null)) ? $poll['votes'] : [];

            $counts = [];
            foreach ($options as $i => $_) {
                $counts[] = (int)($votes[(string)$i] ?? $votes[$i] ?? 0);
            }
            $total = array_sum($counts);

            // 我投了哪一项（没投过 -1）
            $myVote = -1;
            $uid = (int)($user['id'] ?? 0);
            if ($uid > 0) {
                foreach ($votes as $k => $v) {
                    if (is_array($v) && (int)($v['user_id'] ?? 0) === $uid) {
                        $myVote = (int)$k;
                        break;
                    }
                    // 兼容「按用户 id 记录选项」的存法
                    if (!is_array($v) && (string)$k === (string)$uid) {
                        $myVote = (int)$v;
                        break;
                    }
                }
            }

            return [
                'question' => $question,
                'options'  => array_values(array_map(static function ($x) {
                    return (string)$x;
                }, $options)),
                'counts'   => $counts,
                'total'    => $total,
                'my_vote'  => $myVote,
            ];
        })(),
    ],
];

if (!$preview) {
    $comments = $fs->find('comments', ['post_id' => $post['id']]);
    usort($comments, function($a, $b) {
        return strtotime($a['created_at']) - strtotime($b['created_at']);
    });

    $commentList = [];

    $allUsers = $fs->read('users');
    $userIndex = [];
    foreach ($allUsers as $u) {
        if (isset($u['id'])) {
            $userIndex[(int)$u['id']] = $u;
        }
    }
    foreach ($comments as $c) {
        $cIsAnonymous = !empty($c['is_anonymous']);
        $cUser = $cIsAnonymous ? null : ($userIndex[(int)$c['user_id']] ?? null);
        // 评论头像框：前端 commentFrameHtml() 依赖这个字段画框。
        // 之前不返回它，导致「同一个人在首页有框、评论区没框」。
        $cDecor = (!$cIsAnonymous && $cUser) ? lwUserDecorBundle((int)$c['user_id']) : ['frame' => null];
        $commentList[] = [
            'id' => $c['id'],
            'content' => $c['content'],
            'parent_id' => intval($c['parent_id'] ?? 0),
            'is_anonymous' => $cIsAnonymous,
            'author_nickname' => $cIsAnonymous ? '匿名用户' : ($cUser['nickname'] ?? ''),
            'author_avatar' => $cIsAnonymous ? '/assets/images/default-avatar.svg' : ($cUser['avatar'] ?? ''),
            'author_frame' => $cDecor['frame'] ?? null,
            'created_at' => $c['created_at'],
            'is_author' => $user && $user['id'] == $c['user_id']
        ];
    }

    // 字段投影（E14）：不传 fields/top 时与改造前完全一致。
    $payload['comments'] = lwProjectRows($commentList, lwFieldsRequest(),
        ['id', 'content', 'parent_id', 'is_anonymous', 'author_nickname',
         'author_avatar', 'author_frame', 'created_at', 'is_author'], ['id']);
}

// post 本体是单个对象，走 lwProjectRow（同样是「不传即原样」）。
$payload['post'] = lwProjectRow($payload['post'], lwFieldsRequest(),
    ['id', 'category', 'title', 'content', 'images', 'is_anonymous',
     'author_nickname', 'author_avatar', 'author_title_text', 'author_title_color',
     'author_title_bg_color', 'author_title_rainbow', 'author_title_gradient_start',
     'author_title_gradient_end', 'is_author', 'likes', 'comments', 'is_liked',
     'is_favorited', 'created_at', 'status'], ['id']);
jsonSuccess(lwProjectTop($payload, lwTopRequest(), ['post', 'comments']));