<?php
require_once __DIR__ . '/../../config/config.php';

$user = getCurrentUser();
$page = max(1, intval($_REQUEST['page'] ?? 1));
$limit = min(50, max(1, intval($_REQUEST['limit'] ?? 10)));
$offset = ($page - 1) * $limit;
$category = $_REQUEST['category'] ?? '';
$sort = $_REQUEST['sort'] ?? 'latest';

$fs = getFS();
$posts = $fs->read('posts');

$search = $_REQUEST['search'] ?? '';
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

if ($sort === 'hot') {
    usort($posts, function($a, $b) {
        $aAnnounce = ($a['category'] ?? '') === 'announcement' ? 1 : 0;
        $bAnnounce = ($b['category'] ?? '') === 'announcement' ? 1 : 0;
        if ($aAnnounce !== $bAnnounce) return $bAnnounce - $aAnnounce;
        $scoreA = ($a['likes'] ?? 0) + (($a['comments'] ?? 0) * 2);
        $scoreB = ($b['likes'] ?? 0) + (($b['comments'] ?? 0) * 2);
        return $scoreB - $scoreA;
    });
} else {
    usort($posts, function($a, $b) {
        $aAnnounce = ($a['category'] ?? '') === 'announcement' ? 1 : 0;
        $bAnnounce = ($b['category'] ?? '') === 'announcement' ? 1 : 0;
        if ($aAnnounce !== $bAnnounce) return $bAnnounce - $aAnnounce;
        return strtotime($b['created_at']) - strtotime($a['created_at']);
    });
}

$userIsAdmin = $user && in_array($user['role'], ['admin', 'super_admin']);

$filteredPosts = [];
foreach ($posts as $post) {
    if (!$userIsAdmin && $post['status'] === 'published') {
        $vis = $post['visibility'] ?? 'public';
        if ($vis === 'visible_to') {
            $allowedQQs = array_map('trim', explode(',', $post['visible_to'] ?? ''));
            if (!$user || !in_array($user['qq'], $allowedQQs)) continue;
        } elseif ($vis === 'exclude_to') {
            $excludedQQs = array_map('trim', explode(',', $post['exclude_to'] ?? ''));
            if ($user && in_array($user['qq'], $excludedQQs)) continue;
        }
    }

    if (!$userIsAdmin && $post['status'] !== 'published') {
        if (!$user || $post['user_id'] != $user['id']) continue;
    }
    $filteredPosts[] = $post;
}

$total = count($filteredPosts);
$posts = array_slice($filteredPosts, $offset, $limit);

$result = [];

$allUsers = $fs->read('users');
$userIndex = [];
foreach ($allUsers as $u) {
    if (isset($u['id'])) {
        $userIndex[(int)$u['id']] = $u;
    }
}
foreach ($posts as $post) {
    $isAnonymous = !empty($post['is_anonymous']);
    $postUser = $isAnonymous ? null : ($userIndex[(int)$post['user_id']] ?? null);

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

    $author = null;
    if (!$isAnonymous && $postUser) {
        $author = [
            'id' => $postUser['id'],
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

    $result[] = [
        'id' => $post['id'],
        'category' => $post['category'],
        'title' => $post['title'] ?? '',
        'content' => $post['content'] ?? '',
        'images' => json_decode($post['images'] ?? '[]', true) ?: [],
        'is_anonymous' => $isAnonymous,
        'is_pinned' => !empty($post['is_pinned']),
        'visibility' => $post['visibility'] ?? 'public',
        'visible_to' => $post['visible_to'] ?? '',
        'exclude_to' => $post['exclude_to'] ?? '',
        'author' => $author,
        'like_count' => intval($post['likes'] ?? 0),
        'comment_count' => intval($post['comments'] ?? 0),
        'is_liked' => $liked,
        'is_favorited' => $favorited,
        'created_at' => $post['created_at'] ?? '',
        'status' => $post['status'] ?? 'published',
        'poll' => $pollData
    ];
}

jsonSuccess([
    'posts' => $result,
    'total' => $total,
    'page' => $page,
    'limit' => $limit,
    'has_more' => $offset + $limit < $total,
    'pagination' => [
        'total' => $total,
        'page' => $page,
        'limit' => $limit,
        'has_more' => $offset + $limit < $total
    ]
]);