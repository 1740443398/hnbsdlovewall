<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/sort_util.php';

$admin = requireAdmin();
$admin = checkBanned($admin);
if ($admin['is_banned']) {
    jsonError('账号已被封禁');
}

$fs = getFS();

$action = $_POST['action'] ?? 'list';

if ($action === 'list') {
    if (!checkPermission($admin, 'view_posts')) {
        jsonError('无权限查看帖子列表', 403);
    }
    $page = max(1, intval($_REQUEST['page'] ?? 1));
    $limit = 20;
    $offset = ($page - 1) * $limit;
    $status = sanitizeInput($_REQUEST['status'] ?? '');
    $category = sanitizeInput($_REQUEST['category'] ?? '');
    $keyword = sanitizeInput($_REQUEST['keyword'] ?? '');

    // ── 高级检索条件（批次 D：D11）——全部可选，多条件 AND 组合 ──
    $fAuthor = sanitizeInput($_REQUEST['author'] ?? '');
    $fDateFrom = sanitizeInput($_REQUEST['date_from'] ?? '');
    $fDateTo = sanitizeInput($_REQUEST['date_to'] ?? '');
    $fAnonymous = isset($_REQUEST['anonymous']) ? (string)$_REQUEST['anonymous'] : '';
    $fHasImage = isset($_REQUEST['has_image']) ? (string)$_REQUEST['has_image'] : '';
    $fVisibility = sanitizeInput($_REQUEST['visibility'] ?? '');
    $fMinLikes = isset($_REQUEST['min_likes']) && $_REQUEST['min_likes'] !== '' ? (int)$_REQUEST['min_likes'] : null;
    $fMinComments = isset($_REQUEST['min_comments']) && $_REQUEST['min_comments'] !== '' ? (int)$_REQUEST['min_comments'] : null;
    $fSort = sanitizeInput($_REQUEST['sort'] ?? '');
    $fOrder = (sanitizeInput($_REQUEST['order'] ?? 'desc') === 'asc') ? 'asc' : 'desc';

    $posts = $fs->read('posts');

    // 作者过滤要先把 user_id → qq/nickname 映射建好（帖子表里只有 user_id）
    $authorIndex = [];
    if ($fAuthor !== '') {
        foreach ($fs->read('users') as $u) {
            $authorIndex[(int)($u['id'] ?? 0)] = $u;
        }
    }

    if ($status) {
        $posts = array_filter($posts, function($p) use ($status) {
            return ($p['status'] ?? '') === $status;
        });
    }

    if ($category) {
        $posts = array_filter($posts, function($p) use ($category) {
            return ($p['category'] ?? '') === $category;
        });
    }

    if ($keyword) {
        $posts = array_filter($posts, function($p) use ($keyword) {
            return stripos($p['title'] ?? '', $keyword) !== false ||
                   stripos($p['content'] ?? '', $keyword) !== false;
        });
    }

    if ($fAuthor !== '') {
        $kw = mb_strtolower($fAuthor);
        $posts = array_filter($posts, function ($p) use ($authorIndex, $kw) {
            $u = $authorIndex[(int)($p['user_id'] ?? 0)] ?? null;
            if (!$u) { return false; }
            return mb_stripos((string)($u['nickname'] ?? ''), $kw) !== false
                || mb_stripos((string)($u['qq'] ?? ''), $kw) !== false;
        });
    }
    if ($fDateFrom !== '') {
        $posts = array_filter($posts, function ($p) use ($fDateFrom) {
            return substr((string)($p['created_at'] ?? ''), 0, 10) >= $fDateFrom;
        });
    }
    if ($fDateTo !== '') {
        $posts = array_filter($posts, function ($p) use ($fDateTo) {
            return substr((string)($p['created_at'] ?? ''), 0, 10) <= $fDateTo;
        });
    }
    if ($fAnonymous === '0' || $fAnonymous === '1') {
        $want = ($fAnonymous === '1');
        $posts = array_filter($posts, function ($p) use ($want) {
            return !empty($p['is_anonymous']) === $want;
        });
    }
    if ($fHasImage === '0' || $fHasImage === '1') {
        $want = ($fHasImage === '1');
        $posts = array_filter($posts, function ($p) use ($want) {
            return (!empty($p['images'])) === $want;
        });
    }
    if ($fVisibility !== '') {
        $posts = array_filter($posts, function ($p) use ($fVisibility) {
            return ($p['visibility'] ?? 'public') === $fVisibility;
        });
    }
    if ($fMinLikes !== null) {
        $posts = array_filter($posts, function ($p) use ($fMinLikes) {
            return (int)($p['likes'] ?? 0) >= $fMinLikes;
        });
    }
    if ($fMinComments !== null) {
        $posts = array_filter($posts, function ($p) use ($fMinComments) {
            return (int)($p['comments'] ?? 0) >= $fMinComments;
        });
    }

    $posts = array_values($posts);

    // 排序：默认按发布时间倒序；支持点赞 / 评论 / 浏览
    $sortKeyMap = ['created_at' => 'created_at', 'likes' => 'likes', 'comments' => 'comments', 'views' => 'views'];
    $sortKey = $sortKeyMap[$fSort] ?? '';
    if ($sortKey === '') {
        lwSortByCreatedAtDesc($posts);
    } else {
        usort($posts, function ($a, $b) use ($sortKey, $fOrder) {
            if ($sortKey === 'created_at') {
                $cmp = strcmp((string)($a['created_at'] ?? ''), (string)($b['created_at'] ?? ''));
            } else {
                $cmp = (int)($a[$sortKey] ?? 0) <=> (int)($b[$sortKey] ?? 0);
            }
            return $fOrder === 'asc' ? $cmp : -$cmp;
        });
    }

    $total = count($posts);
    $totalPages = ceil($total / $limit);
    $posts = array_slice($posts, $offset, $limit);

    $allUsers = $fs->read('users');
    $userIndex = [];
    foreach ($allUsers as $u) {
        if (isset($u['id'])) {
            $userIndex[(int)$u['id']] = $u;
        }
    }

    $result = [];
    foreach ($posts as $p) {
        $uid = (int)($p['user_id'] ?? 0);
        $user = $userIndex[$uid] ?? null;
        $result[] = [
            'id' => $p['id'] ?? '',
            'title' => $p['title'] ?? '',
            'content' => $p['content'] ?? '',
            'content_short' => mb_substr($p['content'] ?? '', 0, 60) . (mb_strlen($p['content'] ?? '') > 60 ? '...' : ''),
            'category' => $p['category'] ?? '',
            'status' => $p['status'] ?? '',
            'nickname' => $user ? ($user['nickname'] ?? '') : '',
            'qq' => $user ? ($user['qq'] ?? '') : '',
            'is_anonymous' => !empty($p['is_anonymous']),
            'like_count' => intval($p['likes'] ?? 0),
            'comment_count' => intval($p['comments'] ?? 0),
            'created_at' => $p['created_at'] ?? '',
            'images' => postImagesExisting($p)
        ];
    }

    jsonSuccess(['posts' => $result, 'total' => $total, 'page' => $page, 'total_pages' => $totalPages]);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonError('请求方法不允许', 405);
}

$csrfToken = $_POST['csrf_token'] ?? '';
if (!verifyCSRFToken($csrfToken)) {
    jsonError('CSRF验证失败', 403);
}

$action = $_POST['action'] ?? '';

if ($action === 'approve') {
    if (!checkPermission($admin, 'audit_posts')) {
        jsonError('无权限执行此操作', 403);
    }
    $postId = intval($_POST['post_id'] ?? 0);

    if (!$postId) {
        jsonError('帖子ID无效');
    }

    $fs->update('posts', $postId, ['status' => 'published']);
    logOperation($admin['id'], $admin['qq'], 'approve_post', 'post', $postId, '审核通过');

    jsonSuccess([], '审核通过');
}

if ($action === 'reject') {
    if (!checkPermission($admin, 'audit_posts')) {
        jsonError('无权限执行此操作', 403);
    }
    $postId = intval($_POST['post_id'] ?? 0);

    if (!$postId) {
        jsonError('帖子ID无效');
    }

    $fs->update('posts', $postId, ['status' => 'rejected']);
    logOperation($admin['id'], $admin['qq'], 'reject_post', 'post', $postId, '审核拒绝');

    jsonSuccess([], '已拒绝');
}

if ($action === 'delete') {
    if (!checkPermission($admin, 'delete_posts')) {
        jsonError('无权限执行此操作', 403);
    }
    $postId = intval($_POST['post_id'] ?? 0);

    if (!$postId) {
        jsonError('帖子ID无效');
    }

    $allComments = $fs->read('comments');
    $allLikes = $fs->read('post_likes');
    $allFavorites = $fs->read('post_favorites');

    $commentsChanged = false;
    $likesChanged = false;
    $favsChanged = false;
    foreach ($allComments as $i => $c) {
        if (($c['post_id'] ?? 0) == $postId) {
            unset($allComments[$i]);
            $commentsChanged = true;
        }
    }
    foreach ($allLikes as $i => $l) {
        if (($l['post_id'] ?? 0) == $postId) {
            unset($allLikes[$i]);
            $likesChanged = true;
        }
    }
    foreach ($allFavorites as $i => $f) {
        if (($f['post_id'] ?? 0) == $postId) {
            unset($allFavorites[$i]);
            $favsChanged = true;
        }
    }

    if ($commentsChanged) $fs->write('comments', array_values($allComments));
    if ($likesChanged) $fs->write('post_likes', array_values($allLikes));
    if ($favsChanged) $fs->write('post_favorites', array_values($allFavorites));

    $fs->delete('posts', $postId);
    logOperation($admin['id'], $admin['qq'], 'delete_post', 'post', $postId, '删除帖子');

    jsonSuccess([], '删除成功');
}

if ($action === 'batch') {
    $batchAction = $_POST['batch_action'] ?? '';
    $postIds = json_decode($_POST['post_ids'] ?? '[]', true) ?: [];

    if (empty($postIds)) {
        jsonError('请选择帖子');
    }

    $affected = 0;
    if ($batchAction === 'approve') {
        if (!checkPermission($admin, 'audit_posts')) {
            jsonError('无权限执行此操作', 403);
        }
        foreach ($postIds as $pid) {
            $fs->update('posts', $pid, ['status' => 'published']);
            $affected++;
        }
    } elseif ($batchAction === 'delete') {
        if (!checkPermission($admin, 'delete_posts')) {
            jsonError('无权限执行此操作', 403);
        }

        $allComments = $fs->read('comments');
        $allLikes = $fs->read('post_likes');
        $allFavorites = $fs->read('post_favorites');
        foreach ($postIds as $pid) {
            foreach ($allComments as $cm) {
                if (($cm['post_id'] ?? 0) == $pid) { $fs->delete('comments', $cm['id']); }
            }
            foreach ($allLikes as $lk) {
                if (($lk['post_id'] ?? 0) == $pid) { $fs->delete('post_likes', $lk['id']); }
            }
            foreach ($allFavorites as $fv) {
                if (($fv['post_id'] ?? 0) == $pid) { $fs->delete('post_favorites', $fv['id']); }
            }
            $fs->delete('posts', $pid);
            $affected++;
        }
    }

    jsonSuccess(['affected' => $affected], '批量操作完成');
}

if ($action === 'detail') {
    if (!checkPermission($admin, 'view_posts')) {
        jsonError('无权限查看帖子详情', 403);
    }
    $postId = intval($_POST['post_id'] ?? 0);

    if (!$postId) {
        jsonError('帖子ID无效');
    }

    $p = $fs->findById('posts', $postId);
    if (!$p) {
        jsonError('帖子不存在');
    }

    $user = $fs->findById('users', $p['user_id']);

    $allUsers = $fs->read('users');
    $userIndex = [];
    foreach ($allUsers as $u) {
        if (isset($u['id'])) {
            $userIndex[(int)$u['id']] = $u;
        }
    }

    $comments = [];
    $allComments = $fs->read('comments');
    foreach ($allComments as $c) {
        if (($c['post_id'] ?? 0) == $postId) {
            $cUser = $userIndex[(int)($c['user_id'] ?? 0)] ?? null;
            $comments[] = [
                'id' => $c['id'],
                'content' => $c['content'],
                'is_anonymous' => !empty($c['is_anonymous']),
                'nickname' => $cUser['nickname'] ?? '',
                'qq' => $cUser['qq'] ?? '',
                'created_at' => $c['created_at']
            ];
        }
    }

    jsonSuccess([
        'id' => $p['id'],
        'title' => $p['title'],
        'content' => $p['content'],
        'category' => $p['category'],
        'status' => $p['status'],
        'is_anonymous' => !empty($p['is_anonymous']),
        'nickname' => $user['nickname'] ?? '',
        'qq' => $user['qq'] ?? '',
        'images' => postImagesExisting($p),
        'comments' => $comments,
        'created_at' => $p['created_at']
    ]);
}

jsonError('未知操作');