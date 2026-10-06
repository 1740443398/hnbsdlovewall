<?php
/**
 * 删除帖子（连带清理其评论、点赞、收藏）
 *
 * 调用方：api/posts/delete.php（页面）、api/ai_action.php（AI 确认卡片，需强确认）
 * 入参：$in['post_id'] int 已校验
 *
 * 权限：作者本人；或持有 delete_posts 权限的管理员。
 * 只判断 role 会让 T1（只读）/T2 管理员越权删除任意用户的帖子，所以必须过 checkPermission()。
 */
require_once __DIR__ . '/_lib.php';

if (!function_exists('lw_do_post_delete')) {
    function lw_do_post_delete(array $user, array $in): array
    {
        $postId = (int)($in['post_id'] ?? 0);
        if (!$postId) {
            return lwActionFail('帖子ID无效');
        }

        $fs = getFS();
        $post = $fs->findById('posts', $postId);
        if (!$post) {
            return lwActionFail('帖子不存在');
        }

        $isAuthor = (int)$user['id'] === (int)$post['user_id'];
        $isAdmin = lwIsAdminUser($user) && checkPermission($user, 'delete_posts');

        if (!$isAuthor && !$isAdmin) {
            return lwActionFail('没有权限删除此帖子', 403);
        }

        $fs->delete('posts', $postId);

        foreach ((array)$fs->find('comments', ['post_id' => $postId]) as $c) {
            $fs->delete('comments', $c['id']);
        }
        foreach ((array)$fs->find('post_likes', ['post_id' => $postId]) as $l) {
            $fs->delete('post_likes', $l['id']);
        }
        foreach ((array)$fs->find('post_favorites', ['post_id' => $postId]) as $f) {
            $fs->delete('post_favorites', $f['id']);
        }

        if ($isAdmin) {
            logOperation($user['id'], $user['qq'], 'delete_post', 'post', $postId, '删除帖子');
        } else {
            logUserActivity($user['id'], 'post_delete', '删除自己的帖子');
        }

        return lwActionResult(true, '删除成功', ['post_id' => $postId]);
    }
}
