<?php
/**
 * 给帖子投票（单选，一人一票，不可改）
 *
 * 调用方：api/posts/vote.php（页面）、api/ai_action.php（AI 确认卡片）
 * 入参：$in['post_id'] int、$in['option'] int（选项下标，从 0 开始）
 */
require_once __DIR__ . '/_lib.php';

if (!function_exists('lw_do_post_vote')) {
    function lw_do_post_vote(array $user, array $in): array
    {
        $postId = (int)($in['post_id'] ?? 0);
        $option = (int)($in['option'] ?? -1);

        if ($postId <= 0) {
            return lwActionFail('帖子ID无效');
        }

        $fs = getFS();
        $post = $fs->findById('posts', $postId);
        if (!$post) {
            return lwActionFail('帖子不存在');
        }
        if (($post['category'] ?? '') === 'announcement') {
            return lwActionFail('公告不支持投票', 403);
        }

        $poll = $post['poll'] ?? null;
        if (!is_array($poll) || empty($poll['options']) || !is_array($poll['options'])) {
            return lwActionFail('该帖子没有可投票的内容');
        }
        if (!empty($poll['closed'])) {
            return lwActionFail('该投票已关闭', 403);
        }

        $optionsCount = count($poll['options']);
        if ($option < 0 || $option >= $optionsCount) {
            return lwActionFail('选项无效');
        }

        $votes = isset($poll['votes']) && is_array($poll['votes']) ? $poll['votes'] : [];
        $uid = (string)$user['id'];
        if (isset($votes[$uid])) {
            return lwActionFail('您已投过票，不能重复投票', 400);
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

        return lwActionResult(true, '投票成功', $result);
    }
}
