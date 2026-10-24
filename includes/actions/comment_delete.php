<?php
/**
 * 删除评论
 *
 * 调用方：api/posts/delete_comment.php（页面）、api/ai_action.php（AI 确认卡片，需强确认）
 * 入参：$in['comment_id'] int 已校验
 *
 * 权限：作者本人；或持有 delete_comments 权限的管理员（T1/T2 不含该权限）。
 */
require_once __DIR__ . '/_lib.php';

if (!function_exists('lw_do_comment_delete')) {
    function lw_do_comment_delete(array $user, array $in): array
    {
        $commentId = (int)($in['comment_id'] ?? 0);
        if (!$commentId) {
            return lwActionFail('评论ID无效');
        }

        $fs = getFS();
        $comment = $fs->findById('comments', $commentId);
        if (!$comment) {
            return lwActionFail('评论不存在');
        }

        $isAuthor = (int)$user['id'] === (int)$comment['user_id'];
        $isAdmin = lwIsAdminUser($user) && checkPermission($user, 'delete_comments');

        if (!$isAuthor && !$isAdmin) {
            return lwActionFail('没有权限删除此评论', 403);
        }

        $fs->delete('comments', $commentId);

        $post = $fs->findById('posts', $comment['post_id']);
        if ($post) {
            $fs->update('posts', $post['id'], ['comments' => max(0, ($post['comments'] ?? 0) - 1)]);
        }

        if ($isAdmin) {
            logOperation($user['id'], $user['qq'], 'delete_comment', 'comment', $commentId, '删除评论');
        }

        return lwActionResult(true, '删除成功', ['comment_id' => $commentId]);
    }
}
