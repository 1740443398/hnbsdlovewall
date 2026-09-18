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
    jsonError('账号已被封禁，无法编辑帖子');
}

$postId = intval($_POST['post_id'] ?? 0);
if (!$postId) {
    jsonError('帖子ID无效');
}

$fs = getFS();
$post = $fs->findById('posts', $postId);

if (!$post) {
    jsonError('帖子不存在');
}

if ($user['id'] != $post['user_id'] && !in_array($user['role'], ['admin', 'super_admin'])) {
    jsonError('没有权限编辑此帖子', 403);
}

$title = trim($_POST['title'] ?? '');
$content = trim($_POST['content'] ?? '');
$category = sanitizeInput($_POST['category'] ?? $post['category']);

if (empty($content)) {
    jsonError('帖子内容不能为空');
}

if (mb_strlen($content) > 5000) {
    jsonError('帖子内容不能超过5000字');
}

$hasSensitive = !empty(checkSensitiveWords($title . ' ' . $content));
$isAdmin = in_array($user['role'], ['admin', 'super_admin']);

if ($category === 'announcement' && !$isAdmin) {
    $category = 'other';
}

$updateData = [
    'title' => $title,
    'content' => $content,
    'category' => $category,
    'visibility' => sanitizeInput($_POST['visibility'] ?? $post['visibility']),
    'is_anonymous' => (($_POST['is_anonymous'] ?? '0') === '1') ? 1 : 0,
    'visible_to' => sanitizeInput($_POST['visible_to'] ?? $post['visible_to']),
    'exclude_to' => sanitizeInput($_POST['exclude_to'] ?? $post['exclude_to']),
];

// 投票字段（可选）：与 create.php 一致；修改投票会重置投票结果
$pollQuestion = trim($_POST['poll_question'] ?? '');
$pollOptionsRaw = trim($_POST['poll_options'] ?? '');
if ($pollQuestion === '' && $pollOptionsRaw === '') {
    if (isset($post['poll'])) {
        $updateData['poll'] = null;
    }
} else {
    if ($pollQuestion === '') {
        jsonError('请填写投票标题');
    }
    if (mb_strlen($pollQuestion) > 200) {
        jsonError('投票标题不能超过200字符');
    }
    $pollOptions = array_values(array_filter(array_map('trim', preg_split('/[\r\n,，]/u', $pollOptionsRaw)), function ($o) {
        return $o !== '';
    }));
    if (count($pollOptions) < 2) {
        jsonError('投票至少需要2个选项');
    }
    if (count($pollOptions) > 10) {
        jsonError('投票最多支持10个选项');
    }
    foreach ($pollOptions as $opt) {
        if (mb_strlen($opt) > 50) {
            jsonError('单个选项不能超过50字符');
        }
    }
    $updateData['poll'] = [
        'question' => $pollQuestion,
        'options' => array_slice($pollOptions, 0, 10),
        'votes' => new stdClass(),
    ];
}

if ($hasSensitive && $category !== 'announcement') {
    $updateData['status'] = 'pending';
}

$fs->update('posts', $postId, $updateData);

if ($isAdmin && $user['id'] != $post['user_id']) {
    logOperation($user['id'], $user['qq'], 'edit_post', 'post', $postId, '管理员编辑帖子');
} else {
    logUserActivity($user['id'], 'post_edit', '编辑帖子 #' . $postId);
}

jsonSuccess(['id' => $postId], '帖子已更新');