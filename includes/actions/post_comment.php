<?php
/**
 * 发表评论 / 回复评论
 *
 * 调用方：api/posts/comment.php（页面）、api/ai_action.php（AI 确认卡片）
 * 入参：$in['post_id'] int、$in['content'] string 已 trim、$in['is_anonymous'] bool、
 *      $in['parent_id'] int（可选，0 表示顶层评论）
 */
require_once __DIR__ . '/_lib.php';

if (!function_exists('lw_do_post_comment')) {
    function lw_do_post_comment(array $user, array $in): array
    {
        $postId = (int)($in['post_id'] ?? 0);
        $content = sanitizeInput(trim((string)($in['content'] ?? '')));
        $isAnonymous = !empty($in['is_anonymous']);
        $parentId = (int)($in['parent_id'] ?? 0);

        if (!$postId) {
            return lwActionFail('帖子ID无效');
        }
        if (mb_strlen($content) < 1 || mb_strlen($content) > 500) {
            return lwActionFail('评论内容应在1-500字符之间');
        }

        $fs = getFS();
        $post = $fs->findById('posts', $postId);
        if (!$post) {
            return lwActionFail('帖子不存在');
        }

        $denied = lwActionPostOwnVisibleToUser($post, $user, '无权评论该帖子');
        if ($denied !== null) {
            return $denied;
        }

        // 屏蔽闸门：与楼主之间存在屏蔽关系时不允许评论。
        // 作者本人不受限（自己评论自己的帖子天经地义）。
        if ((int)$post['user_id'] !== (int)$user['id']) {
            $gate = lwCanInteract((int)$user['id'], (int)$post['user_id'], '评论该帖子');
            if (!$gate['ok']) {
                return lwActionFail($gate['message'], 403);
            }
        }

        $sensitiveWords = checkSensitiveWords($content);
        if (!empty($sensitiveWords)) {
            return lwActionFail('评论包含敏感词汇：' . implode(', ', $sensitiveWords));
        }

        // 楼中楼回复：parent_id>0 表示回复某条顶层评论（禁止回复再回复，拉平为一层）
        if ($parentId > 0) {
            $parentComment = $fs->findById('comments', $parentId);
            if (!$parentComment || (int)($parentComment['post_id'] ?? 0) !== $postId) {
                return lwActionFail('回复的评论不存在');
            }
            if (!empty($parentComment['parent_id'])) {
                return lwActionFail('暂不支持回复二层以上评论', 400);
            }
        }

        $comment = $fs->insert('comments', [
            'post_id'      => $postId,
            'user_id'      => $user['id'],
            'content'      => $content,
            'is_anonymous' => $isAnonymous ? 1 : 0,
            'parent_id'    => $parentId,
        ]);

        $fs->update('posts', $postId, ['comments' => ($post['comments'] ?? 0) + 1]);

        logUserActivity($user['id'], 'comment_create', '评论帖子ID:' . $postId);

        if (!$isAnonymous && $post['user_id'] != $user['id']) {
            $postAuthor = $fs->findById('users', $post['user_id']);
            if ($postAuthor) {
                $postTitle = $post['title'] ?? '无标题';
                $notifyContent = ($user['nickname'] ?? '用户') . ' 评论了你的帖子「'
                    . mb_substr($postTitle, 0, 30) . (mb_strlen($postTitle) > 30 ? '...' : '') . '」';
                $fs->insert('notifications', [
                    'user_id'    => $post['user_id'],
                    'type'       => 'comment',
                    'content'    => $notifyContent,
                    'post_id'    => $postId,
                    'post_title' => $postTitle,
                    'from_user'  => $user['nickname'] ?? '用户',
                    'is_read'    => false,
                ]);
            }
        }

        // 评论里 @到的同学 → 站内通知。
        // 匿名评论一律不发：否则等于凭空多出一条「匿名者提到你」的线索，与匿名承诺相冲。
        // 楼主刚刚已收到「评论了你的帖子」，这里把他排除，避免同一次评论打扰两遍。
        if (!$isAnonymous) {
            require_once __DIR__ . '/../mention_notify.php';
            lwNotifyMentions(
                $content,
                $user,
                $postId,
                (string)($post['title'] ?? ''),
                'comment',
                [(int)$post['user_id']]
            );
        }

        $cIsAnonymous = !empty($comment['is_anonymous']);
        $cUser = $cIsAnonymous ? null : $fs->findById('users', $comment['user_id']);

        // 成长体系：评论者本人得经验（匿名评论同样计入，身份不落库但行为本身有效）
        lwGrowthAward((int)$user['id'], 'post_comment');

        return lwActionResult(true, '评论成功', [
            'comment' => [
                'id'                  => $comment['id'],
                'content'             => $comment['content'],
                'parent_id'           => $parentId,
                'is_anonymous'        => $cIsAnonymous,
                'author_nickname'     => $cIsAnonymous ? '匿名用户' : ($cUser['nickname'] ?? ''),
                'author_avatar'       => $cIsAnonymous ? '/assets/images/default-avatar.svg' : ($cUser['avatar'] ?? ''),
                'author_title_text'   => $cIsAnonymous ? '' : ($cUser['title_text'] ?? ''),
                'author_title_color'  => $cIsAnonymous ? '' : ($cUser['title_color'] ?? ''),
                'author_title_bg_color' => $cIsAnonymous ? '' : ($cUser['title_bg_color'] ?? ''),
                'author_title_rainbow'  => $cIsAnonymous ? 0 : intval($cUser['title_rainbow'] ?? 0),
                'author_title_gradient_start' => $cIsAnonymous ? '' : ($cUser['title_gradient_start'] ?? ''),
                'author_title_gradient_end'   => $cIsAnonymous ? '' : ($cUser['title_gradient_end'] ?? ''),
                'created_at'          => $comment['created_at'],
                'is_author'           => true,
            ],
        ]);
    }
}
