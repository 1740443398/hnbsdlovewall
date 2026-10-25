<?php
/**
 * 投稿的异步 AI 复核
 *
 * =====================================================================
 * 为什么要异步
 * =====================================================================
 * 原来发帖是同步跑 AI 审核的：GLM 接口 1~3 秒，加上主机本身 1 秒的响应，
 * 用户点「发布」要等 4~6 秒才看到结果 —— 体感就是「卡住了、发不出去」，
 * 于是反复点，最后撞上限流「发帖过于频繁」。
 *
 * 但审核并不需要挡在用户面前：
 *   - 关键词层 / 结构层是纯本地计算，几毫秒出结果，**它们才该做门禁**
 *   - AI 层是用来「捞漏网的」，晚几秒完全不影响体验
 *
 * 所以：
 *   1. 同步只跑关键词 + 结构层（毫秒级）→ 决定 published / pending / reject
 *   2. 帖子立刻返回给用户
 *   3. AI 在响应结束后异步复核，只有评分 >= 60 才回滚成待审
 *
 * AI 依然**没有最终裁决权** —— 它最多把帖子转成待审，不会直接拒绝。
 * =====================================================================
 */

if (!defined('LW_AI_REVIEW_LOADED')) {
    define('LW_AI_REVIEW_LOADED', 1);

    /**
     * 把 AI 复核排进「响应之后」执行。
     *
     * 用 register_shutdown_function + fastcgi_finish_request：
     * 先把响应吐给浏览器，再继续跑 AI，用户完全无感。
     *
     * @param int    $postId  帖子 ID
     * @param string $title   标题
     * @param string $content 正文
     */
    function lwQueueAiReview(int $postId, string $title, string $content): void
    {
        register_shutdown_function(function () use ($postId, $title, $content) {
            // 响应已经发完，继续跑不会拖慢用户
            if (function_exists('fastcgi_finish_request')) {
                @fastcgi_finish_request();
            }
            @ignore_user_abort(true);
            @set_time_limit(30);

            try {
                lwRunAiReview($postId, $title, $content);
            } catch (Throwable $e) {
                error_log('[lw] AI 异步复核失败 #' . $postId . ': ' . $e->getMessage());
            }
        });
    }

    /**
     * 真正执行 AI 复核。
     *
     * 只有在 AI 判定「明显不当」以上（>=60）才回滚成待审，
     * 避免 AI 误判把正常帖子打下去。
     */
    function lwRunAiReview(int $postId, string $title, string $content): void
    {
        if (!function_exists('lwAiScan') || !function_exists('lwViolationTypes')) {
            return;
        }

        $ai = lwAiScan($title, $content);
        if (empty($ai['ok'])) {
            return;   // AI 不可用就跳过，不影响帖子
        }

        $score = (int)($ai['score'] ?? 0);
        if ($score < 60) {
            return;   // 60 分以下不动，正常内容
        }

        $fs = getFS();
        $post = $fs->findOne('posts', (string)$postId);
        if (!$post) {
            return;
        }
        // 已经是待审 / 已拒绝就不用再改
        if (($post['status'] ?? '') !== 'published') {
            return;
        }

        $defs = lwViolationTypes();
        $types = [];
        foreach ((array)($ai['types'] ?? []) as $t) {
            if (isset($defs[(string)$t])) {
                $types[(string)$t] = $defs[(string)$t];
            }
        }

        $post['status'] = 'pending';
        $post['mod_score'] = $score;
        $post['mod_types'] = $types;
        $post['mod_reason'] = (string)($ai['reason'] ?? '');
        $post['mod_source'] = 'ai_async';
        $post['mod_at'] = date('Y-m-d H:i:s');

        try {
            $fs->update('posts', (string)$postId, $post);
            error_log('[lw] AI 异步复核把帖子 #' . $postId . ' 转为待审（评分 ' . $score . '）');
        } catch (Throwable $e) {
            error_log('[lw] 回滚待审失败 #' . $postId . ': ' . $e->getMessage());
        }
    }
}
