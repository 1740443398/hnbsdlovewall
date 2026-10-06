<?php
/**
 * D31 帖子投票管理（后台运营接口）
 * 前台投票：api/posts/vote.php + includes/actions/post_vote.php（poll 存于 posts[].poll）。
 * 这里提供后台视角：查看全部投票、关闭/开启、清空票数、移除投票。
 *
 * 关闭投票后，前台 PollSystem 会因 poll.closed 为真而禁止再投（需前台配合，见下方说明）。
 */
require_once __DIR__ . '/../../config/config.php';

$admin = requireAdmin();
$admin = checkBanned($admin);
if (!empty($admin['is_banned'])) {
    jsonError('账号已被封禁');
}
if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
    jsonError('CSRF验证失败', 403);
}

$fs     = getFS();
$action = $_POST['action'] ?? '';

if ($action === 'list') {
    if (!checkPermission($admin, 'view_posts')) {
        jsonError('无权限', 403);
    }
    $posts = $fs->getAll('posts');
    $out = [];
    foreach ($posts as $p) {
        if (empty($p['poll']) || !is_array($p['poll']) || empty($p['poll']['options'])) {
            continue;
        }
        $poll   = $p['poll'];
        $options = $poll['options'] ?? [];
        $votes  = $poll['votes'] ?? [];
        $counts = array_fill(0, count($options), 0);
        foreach ($votes as $idx) {
            $i = (int) $idx;
            if ($i >= 0 && $i < count($options)) {
                $counts[$i]++;
            }
        }
        $out[] = [
            'id'         => $p['id'],
            'author'     => $p['nickname'] ?? ($p['qq'] ?? ''),
            'question'   => $poll['question'] ?? '',
            'options'    => $options,
            'counts'     => $counts,
            'total'      => count($votes),
            'status'     => $p['status'] ?? '',
            'closed'     => !empty($poll['closed']),
            'created_at' => $p['created_at'] ?? '',
        ];
    }
    jsonSuccess(['list' => $out]);
}

// 以下写操作需要 delete_posts 权限
if (!checkPermission($admin, 'delete_posts')) {
    jsonError('无权限执行该操作', 403);
}

$postId = (int) ($_POST['post_id'] ?? 0);
$post   = $fs->findById('posts', $postId);
if (!$post || empty($post['poll']) || !is_array($post['poll'])) {
    jsonError('投票不存在');
}

switch ($action) {
    case 'close':
        $post['poll']['closed'] = 1;
        $fs->update('posts', $postId, ['poll' => $post['poll']]);
        jsonSuccess(['msg' => '已关闭投票']);
    case 'open':
        $post['poll']['closed'] = 0;
        $fs->update('posts', $postId, ['poll' => $post['poll']]);
        jsonSuccess(['msg' => '已重新开启投票']);
    case 'reset':
        $post['poll']['votes'] = [];
        $fs->update('posts', $postId, ['poll' => $post['poll']]);
        jsonSuccess(['msg' => '已清空所有票数']);
    case 'delete':
        $post['poll'] = null; // 前台 PollSystem 对 !is_array(poll) 视为「无投票」
        $fs->update('posts', $postId, ['poll' => null]);
        jsonSuccess(['msg' => '已移除投票']);
    default:
        jsonError('未知操作');
}
