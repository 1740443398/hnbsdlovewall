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
    jsonError('账号已被封禁，无法投票');
}

$postId = intval($_POST['post_id'] ?? 0);
$option = intval($_POST['option'] ?? -1);
if ($postId <= 0) {
    jsonError('帖子ID无效');
}

$fs = getFS();
$post = $fs->findById('posts', $postId);
if (!$post) {
    jsonError('帖子不存在');
}
if (($post['category'] ?? '') === 'announcement') {
    jsonError('公告不支持投票', 403);
}

$poll = $post['poll'] ?? null;
if (!is_array($poll) || empty($poll['options']) || !is_array($poll['options'])) {
    jsonError('该帖子没有可投票的内容');
}

$optionsCount = count($poll['options']);
if ($option < 0 || $option >= $optionsCount) {
    jsonError('选项无效');
}

$votes = isset($poll['votes']) && is_array($poll['votes']) ? $poll['votes'] : [];
$uid = (string)$user['id'];
if (isset($votes[$uid])) {
    jsonError('您已投过票，不能重复投票', 400);
}

$votes[$uid] = $option;
$poll['votes'] = $votes;
$fs->update('posts', $postId, ['poll' => $poll]);

// 逐条统计
$counts = array_fill(0, $optionsCount, 0);
foreach ($votes as $idx) {
    $idx = (int)$idx;
    if ($idx >= 0 && $idx < $optionsCount) {
        $counts[$idx]++;
    }
}
$result = [];
foreach ($counts as $c) {
    $result[] = ['count' => $c];
}

jsonSuccess($result, '投票成功');