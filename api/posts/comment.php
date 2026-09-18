<?php
require_once __DIR__ . '/../../config/config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonError('请求方法不允许', 405);
}

$csrfToken = $_POST['csrf_token'] ?? '';
if (!verifyCSRFToken($csrfToken)) {
    jsonError('CSRF验证失败', 403);
}

$user = requireLogin();

$user = checkBanned($user);
if ($user['is_banned']) {
    jsonError('账号已被封禁，无法评论');
}

$postId = intval($_POST['post_id'] ?? 0);
$content = sanitizeInput(trim($_POST['content'] ?? ''));
$isAnonymous = ($_POST['is_anonymous'] ?? '0') === '1';

if (!$postId) {
    jsonError('帖子ID无效');
}

if (mb_strlen($content) < 1 || mb_strlen($content) > 500) {
    jsonError('评论内容应在1-500字符之间');
}

$fs = getFS();
$post = $fs->findById('posts', $postId);

if (!$post) {
    jsonError('帖子不存在');
}

// 公告为官方通知，只读展示，不支持评论
if (($post['category'] ?? '') === 'announcement') {
    jsonError('公告为官方通知，暂不支持评论', 403);
}

$userIsAdmin = in_array($user['role'], ['admin', 'super_admin']);
$isAuthor = $user['id'] == $post['user_id'];

if (!$userIsAdmin && !$isAuthor) {
    if ($post['status'] !== 'published') {
        jsonError('帖子不存在或未发布');
    }
    $vis = $post['visibility'] ?? 'public';
    if ($vis === 'visible_to') {
        $allowedQQs = array_map('trim', explode(',', $post['visible_to'] ?? ''));
        if (!in_array($user['qq'], $allowedQQs)) {
            jsonError('无权评论该帖子', 403);
        }
    } elseif ($vis === 'exclude_to') {
        $excludedQQs = array_map('trim', explode(',', $post['exclude_to'] ?? ''));
        if (in_array($user['qq'], $excludedQQs)) {
            jsonError('无权评论该帖子', 403);
        }
    }
}

$sensitiveWords = checkSensitiveWords($content);
if (!empty($sensitiveWords)) {
    jsonError('评论包含敏感词汇：' . implode(', ', $sensitiveWords));
}

$comment = $fs->insert('comments', [
    'post_id' => $postId,
    'user_id' => $user['id'],
    'content' => $content,
    'is_anonymous' => $isAnonymous ? 1 : 0
]);

$fs->update('posts', $postId, ['comments' => ($post['comments'] ?? 0) + 1]);

logUserActivity($user['id'], 'comment_create', '评论帖子ID:' . $postId);

if (!$isAnonymous && $post['user_id'] != $user['id']) {
    $postAuthor = $fs->findById('users', $post['user_id']);
    if ($postAuthor) {
        $postTitle = $post['title'] ?? '无标题';
        $notifyContent = ($user['nickname'] ?? '用户') . ' 评论了你的帖子「' . mb_substr($postTitle, 0, 30) . (mb_strlen($postTitle) > 30 ? '...' : '') . '」';
        $fs->insert('notifications', [
            'user_id' => $post['user_id'],
            'type' => 'comment',
            'content' => $notifyContent,
            'post_id' => $postId,
            'post_title' => $postTitle,
            'from_user' => $user['nickname'] ?? '用户',
            'is_read' => false
        ]);
    }
}

$cIsAnonymous = !empty($comment['is_anonymous']);
$cUser = $cIsAnonymous ? null : $fs->findById('users', $comment['user_id']);

jsonSuccess([
    'comment' => [
        'id' => $comment['id'],
        'content' => $comment['content'],
        'is_anonymous' => $cIsAnonymous,
        'author' => [
            'id' => $cIsAnonymous ? 0 : ($cUser['id'] ?? 0),
            'nickname' => $cIsAnonymous ? '匿名用户' : ($cUser['nickname'] ?? ''),
            'avatar' => $cIsAnonymous ? '/assets/images/default-avatar.svg' : ($cUser['avatar'] ?? ''),
            'title_text' => $cIsAnonymous ? '' : ($cUser['title_text'] ?? ''),
            'title_color' => $cIsAnonymous ? '' : ($cUser['title_color'] ?? ''),
            'title_bg_color' => $cIsAnonymous ? '' : ($cUser['title_bg_color'] ?? ''),
            'title_rainbow' => $cIsAnonymous ? 0 : intval($cUser['title_rainbow'] ?? 0),
            'title_gradient_start' => $cIsAnonymous ? '' : ($cUser['title_gradient_start'] ?? ''),
            'title_gradient_end' => $cIsAnonymous ? '' : ($cUser['title_gradient_end'] ?? ''),
        ],
        'created_at' => $comment['created_at'],
        'is_author' => true
    ]
], '评论成功');