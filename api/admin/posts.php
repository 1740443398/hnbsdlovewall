<?php
require_once __DIR__ . '/../../config/config.php';

$admin = requireAdmin();
$admin = checkBanned($admin);
if ($admin['is_banned']) {
    jsonError('账号已被封禁');
}

$fs = getFS();

$action = $_POST['action'] ?? 'list';

if ($action === 'list') {
    $page = max(1, intval($_REQUEST['page'] ?? 1));
    $limit = 20;
    $offset = ($page - 1) * $limit;
    $status = sanitizeInput($_REQUEST['status'] ?? '');
    $category = sanitizeInput($_REQUEST['category'] ?? '');
    $keyword = sanitizeInput($_REQUEST['keyword'] ?? '');

    $posts = $fs->read('posts');

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

    $posts = array_values($posts);
    usort($posts, function($a, $b) {
        return strtotime($b['created_at'] ?? '2000-01-01') - strtotime($a['created_at'] ?? '2000-01-01');
    });

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
            'id' => $p['id'],
            'title' => $p['title'],
            'content' => $p['content'],
            'content_short' => mb_substr($p['content'] ?? '', 0, 60) . (mb_strlen($p['content'] ?? '') > 60 ? '...' : ''),
            'category' => $p['category'],
            'status' => $p['status'],
            'nickname' => $user ? ($user['nickname'] ?? '') : '',
            'qq' => $user ? ($user['qq'] ?? '') : '',
            'is_anonymous' => !empty($p['is_anonymous']),
            'like_count' => intval($p['likes'] ?? 0),
            'comment_count' => intval($p['comments'] ?? 0),
            'created_at' => $p['created_at'],
            'images' => json_decode($p['images'] ?? '[]', true) ?: []
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
        'images' => json_decode($p['images'] ?? '[]', true) ?: [],
        'comments' => $comments,
        'created_at' => $p['created_at']
    ]);
}

jsonError('未知操作');