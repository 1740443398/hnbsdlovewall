<?php
/**
 * 点赞 / 取消点赞帖子（切换语义）
 *
 * 调用方：api/posts/like.php（页面）、api/ai_action.php（AI 确认卡片）
 * 入参：$in['post_id']  int 已校验
 *
 * 计数口径：单一可信来源是 post_likes 表。切换后回查该帖真实点赞行数并同步 posts.likes，
 * 避免并发/重复请求导致计数只增不减或漂移（沿用旧接口的既有做法）。
 */
require_once __DIR__ . '/_lib.php';

if (!function_exists('lw_do_post_like')) {
    function lw_do_post_like(array $user, array $in): array
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

        $likeRows = $fs->find('post_likes', ['user_id' => $user['id'], 'post_id' => $postId]);
        if (!empty($likeRows)) {
            // 取消点赞：清掉该用户在此帖下的全部点赞行（含历史重复行），回查真实数
            foreach ($likeRows as $row) {
                $fs->delete('post_likes', $row['id']);
            }
            $likedNow = false;
        } else {
            $fs->insert('post_likes', [
                'user_id' => $user['id'],
                'post_id' => $postId,
            ]);
            $likedNow = true;
        }

        $newLikes = count($fs->find('post_likes', ['post_id' => $postId]));
        $fs->update('posts', $postId, ['likes' => $newLikes]);

        if ($likedNow && $post['user_id'] != $user['id']) {
            $postAuthor = $fs->findById('users', $post['user_id']);
            if ($postAuthor) {
                $postTitle = $post['title'] ?? '无标题';
                $notifyContent = ($user['nickname'] ?? '用户') . ' 赞了你的帖子「'
                    . mb_substr($postTitle, 0, 30) . (mb_strlen($postTitle) > 30 ? '...' : '') . '」';
                $fs->insert('notifications', [
                    'user_id'     => $post['user_id'],
                    'type'        => 'like',
                    'content'     => $notifyContent,
                    'post_id'     => $postId,
                    'post_title'  => $postTitle,
                    'from_user'   => $user['nickname'] ?? '用户',
                    'is_read'     => false,
                ]);
            }
            // 成长体系：只有「新增点赞」才给作者发经验，取消点赞不扣（避免反复点赞刷经验）
            lwGrowthAward((int)$post['user_id'], 'post_liked');
        }

        return lwActionResult(true, $likedNow ? '已点赞' : '已取消点赞', [
            'is_liked'    => $likedNow,
            'like_count'  => $newLikes,
        ]);
    }
}
