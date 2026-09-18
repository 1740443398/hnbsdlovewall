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
    if (!checkPermission($admin, 'view_posts')) {
        jsonError('无权限查看评论', 403);
    }
    $page = max(1, intval($_REQUEST['page'] ?? 1));
    $limit = 20;
    $offset = ($page - 1) * $limit;
    $keyword = sanitizeInput($_REQUEST['keyword'] ?? '');
    $postId = intval($_REQUEST['post_id'] ?? 0);

    $comments = $fs->read('comments');

    if ($postId > 0) {
        $comments = array_filter($comments, function($c) use ($postId) {
            return (int)($c['post_id'] ?? 0) === $postId;
        });
    }

    if ($keyword !== '') {
        $comments = array_filter($comments, function($c) use ($keyword) {
            return stripos($c['content'] ?? '', $keyword) !== false;
        });
    }

    $comments = array_values($comments);
    usort($comments, function($a, $b) {
        return strtotime($b['created_at'] ?? '2000-01-01') - strtotime($a['created_at'] ?? '2000-01-01');
    });

    $total = count($comments);
    $totalPages = ceil($total / $limit);
    $comments = array_slice($comments, $offset, $limit);

    $allUsers = $fs->read('users');
    $userIndex = [];
    foreach ($allUsers as $u) {
        if (isset($u['id'])) {
            $userIndex[(int)$u['id']] = $u;
        }
    }
    $posts = $fs->read('posts');
    $postIndex = [];
    foreach ($posts as $p) {
        if (isset($p['id'])) {
            $postIndex[(int)$p['id']] = $p;
        }
    }

    $result = [];
    foreach ($comments as $c) {
        $uid = (int)($c['user_id'] ?? 0);
        $user = $userIndex[$uid] ?? null;
        $post = $postIndex[(int)($c['post_id'] ?? 0)] ?? null;
        $result[] = [
            'id' => $c['id'],
            'post_id' => $c['post_id'],
            'post_title' => $post ? ($post['title'] ?? '') : '',
            'content' => $c['content'],
            'content_short' => mb_substr($c['content'] ?? '', 0, 60) . (mb_strlen($c['content'] ?? '') > 60 ? '...' : ''),
            'is_anonymous' => !empty($c['is_anonymous']),
            'nickname' => $user ? ($user['nickname'] ?? '') : '',
            'qq' => $user ? ($user['qq'] ?? '') : '',
            'created_at' => $c['created_at']
        ];
    }

    jsonSuccess(['comments' => $result, 'total' => $total, 'page' => $page, 'total_pages' => $totalPages]);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonError('请求方法不允许', 405);
}

$csrfToken = $_POST['csrf_token'] ?? '';
if (!verifyCSRFToken($csrfToken)) {
    jsonError('CSRF验证失败', 403);
}

if ($action === 'delete') {
    if (!checkPermission($admin, 'delete_posts')) {
        jsonError('无权限执行此操作', 403);
    }
    $commentId = intval($_POST['comment_id'] ?? 0);

    if (!$commentId) {
        jsonError('评论ID无效');
    }

    $comment = $fs->findById('comments', $commentId);
    if (!$comment) {
        jsonError('评论不存在');
    }

    $postId = (int)($comment['post_id'] ?? 0);
    $fs->delete('comments', $commentId);

    // 同步帖子评论数
    if ($postId > 0) {
        $post = $fs->findById('posts', $postId);
        if ($post) {
            $newCount = max(0, intval($post['comments'] ?? 0) - 1);
            $fs->update('posts', $postId, ['comments' => $newCount]);
        }
    }

    logOperation($admin['id'], $admin['qq'], 'delete_comment', 'comment', $commentId, '删除评论');
    jsonSuccess([], '删除成功');
}

if ($action === 'batch') {
    if (!checkPermission($admin, 'delete_posts')) {
        jsonError('无权限执行此操作', 403);
    }
    $commentIds = json_decode($_POST['comment_ids'] ?? '[]', true) ?: [];
    if (empty($commentIds)) {
        jsonError('请选择评论');
    }

    $affected = 0;
    $postCountAdjust = [];
    foreach ($commentIds as $cid) {
        $comment = $fs->findById('comments', $cid);
        if (!$comment) {
            continue;
        }
        $postId = (int)($comment['post_id'] ?? 0);
        $fs->delete('comments', $cid);
        if ($postId > 0) {
            $postCountAdjust[$postId] = ($postCountAdjust[$postId] ?? 0) + 1;
        }
        $affected++;
    }
    foreach ($postCountAdjust as $postId => $delta) {
        $post = $fs->findById('posts', $postId);
        if ($post) {
            $fs->update('posts', $postId, ['comments' => max(0, intval($post['comments'] ?? 0) - $delta)]);
        }
    }

    logOperation($admin['id'], $admin['qq'], 'batch_delete_comments', 'comment', 0, '批量删除评论 ' . $affected . ' 条');
    jsonSuccess(['affected' => $affected], '批量删除完成');
}

jsonError('未知操作');