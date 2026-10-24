<?php
/**
 * 收藏 / 取消收藏帖子（切换语义）
 *
 * 调用方：api/posts/favorite.php（页面）、api/ai_action.php（AI 确认卡片）
 * 入参：$in['post_id']  int 已校验
 */
require_once __DIR__ . '/_lib.php';

if (!function_exists('lw_do_post_favorite')) {
    function lw_do_post_favorite(array $user, array $in): array
    {
        $postId = (int)($in['post_id'] ?? 0);
        if ($postId <= 0) {
            return lwActionFail('帖子ID无效');
        }

        $fs = getFS();
        $post = $fs->findById('posts', $postId);
        if (!$post) {
            return lwActionFail('帖子不存在');
        }

        $denied = lwActionPostOwnVisibleToUser($post, $user, '无权操作该帖子');
        if ($denied !== null) {
            return $denied;
        }

        $existing = $fs->findOne('post_favorites', ['user_id' => $user['id'], 'post_id' => $postId]);
        if ($existing) {
            $fs->delete('post_favorites', $existing['id']);
            return lwActionResult(true, '已取消收藏', ['is_favorited' => false]);
        }

        $fs->insert('post_favorites', [
            'user_id' => $user['id'],
            'post_id' => $postId,
        ]);
        // 成长体系：收藏是「真实兴趣」信号，给作者发少量经验（取消收藏不扣）
        if ((int)$post['user_id'] !== (int)$user['id']) {
            lwGrowthAward((int)$post['user_id'], 'post_favorite');
        }
        return lwActionResult(true, '收藏成功', ['is_favorited' => true]);
    }
}
