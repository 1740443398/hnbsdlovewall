<?php
/**
 * 发布动态 / 帖子
 *
 * 调用方：api/posts/create.php（页面表单）、api/ai_action.php（AI 确认卡片）
 * 入参（全部由调用方校验过类型与范围，内核不读 $_POST / $_FILES）：
 *   $in['category']    string 分类键
 *   $in['title']       string 可为空
 *   $in['content']     string 正文 5-5000 字
 *   $in['is_anonymous']bool
 *   $in['visibility']  string public | visible_to | exclude_to
 *   $in['visible_to'] / $in['exclude_to']  string
 *   $in['images']      array  已上传完成的图片 URL 列表（AI 路径通常为空）
 *   $in['poll_question'] / $in['poll_options'] string 可选投票
 *
 * 说明：图片上传留在 api/posts/create.php（要处理 $_FILES 与 move_uploaded_file），
 * 内核只认「已经是站内 URL」的图片列表 —— 这样 AI 发帖（天然没有附件）
 * 与页面发帖走的是同一段落库逻辑。
 */
require_once __DIR__ . '/_lib.php';

if (!function_exists('lw_do_post_create')) {
    function lw_do_post_create(array $user, array $in): array
    {
        /* 自愈前置检查：磁盘写满时直接拒绝，不要走到一半失败。
         *
         * 原来磁盘满了会怎样：写入返回 false，但上层没检查，
         * 页面照样跳转「发布成功」，用户以为发出去了，
         * 刷新一看帖子没了 —— 这是最伤人的一类 bug。
         * 宁可当面说「暂时发不了」，也不能假装成功。
         */
        if (class_exists('LWSelfHeal')) {
            if (!LWSelfHeal::diskOk()) {
                return lwActionFail('服务器存储空间不足，暂时无法发布。请联系管理员清理。');
            }
        }

        $category = sanitizeInput(trim((string)($in['category'] ?? '')));
        $title    = trim((string)($in['title'] ?? ''));
        $content  = trim((string)($in['content'] ?? ''));
        $isAnonymous = !empty($in['is_anonymous']);
        $visibility  = sanitizeInput(trim((string)($in['visibility'] ?? 'public')));
        $visibleTo   = sanitizeInput(trim((string)($in['visible_to'] ?? '')));
        $excludeTo   = sanitizeInput(trim((string)($in['exclude_to'] ?? '')));

        $categories = ['lost_found', 'study_help', 'social_chat', 'confession', 'school_info', 'other'];
        // 只有持有「编辑置顶公告」权限的管理员才能发公告分类；T1（只读）不能
        $isAdmin = in_array($user['role'] ?? '', ['admin', 'super_admin'], true)
            && checkPermission($user, 'edit_announcement');
        if ($isAdmin) {
            $categories[] = 'announcement';
        }
        if (!in_array($category, $categories, true)) {
            return lwActionFail('分类选择无效');
        }
        if (!in_array($visibility, ['public', 'visible_to', 'exclude_to'], true)) {
            return lwActionFail('可见权限设置无效');
        }
        if ($title !== '' && (mb_strlen($title) < 2 || mb_strlen($title) > 200)) {
            return lwActionFail('标题长度应在2-200字符之间');
        }
        if (mb_strlen($content) < 5 || mb_strlen($content) > 5000) {
            return lwActionFail('内容长度应在5-5000字符之间');
        }

        // 图片：只接受站内相对路径，且必须是 /uploads/images/ 下的图片后缀，
        // 防止把任意 URL（外链 / javascript:）塞进帖子正文。
        $imageUrls = [];
        foreach ((array)($in['images'] ?? []) as $u) {
            $u = trim((string)$u);
            if ($u === '') {
                continue;
            }
            if (strpos($u, '/uploads/images/') !== 0) {
                continue;
            }
            if (!preg_match('/\.(jpe?g|png|gif|webp)$/i', $u)) {
                continue;
            }
            $imageUrls[] = $u;
            if (count($imageUrls) >= 9) {
                break;
            }
        }

        // 投票字段（可选）
        $poll = null;
        $pollQuestion = trim((string)($in['poll_question'] ?? ''));
        $pollOptionsRaw = trim((string)($in['poll_options'] ?? ''));
        if ($pollQuestion !== '' || $pollOptionsRaw !== '') {
            if ($pollQuestion === '') {
                return lwActionFail('请填写投票标题');
            }
            if (mb_strlen($pollQuestion) > 200) {
                return lwActionFail('投票标题不能超过200字符');
            }
            $pollOptions = array_values(array_filter(array_map('trim', preg_split('/[\r\n,，]/u', $pollOptionsRaw)), function ($o) {
                return $o !== '';
            }));
            if (count($pollOptions) < 2) {
                return lwActionFail('投票至少需要2个选项');
            }
            if (count($pollOptions) > 10) {
                return lwActionFail('投票最多支持10个选项');
            }
            foreach ($pollOptions as $opt) {
                if (mb_strlen($opt) > 50) {
                    return lwActionFail('单个选项不能超过50字符');
                }
            }
            $poll = [
                'question' => $pollQuestion,
                'options'  => array_slice($pollOptions, 0, 10),
                'votes'    => new stdClass(), // JSON 空对象，避免被当成数组
            ];
        }

        /* ── 三层内容审核（2026-10-24）─────────────────────────────────
         *
         * 原来是「命中敏感词就 pending，否则 published」的二元判断。
         * 问题：只查词表，识别不了变体/谐音/语义违规，也分不清
         * 「轻微脏话」和「色情交易」——两者都被塞进同一个 pending 队列，
         * 运营看到一堆无标注的待审帖，没法排优先级。
         *
         * 现在改为 AI + 关键词 + 结构三层打分：
         *   pass    直接发布
         *   review  发布但标记待查（用户无感，后台可见）
         *   pending 暂不发布，等人工（用户看到「审核中」）
         *   reject  直接拒绝（仅限确定性硬规则，AI 无权单独拒绝）
         *
         * 公告类内容由管理员发布，跳过 AI 以免慢接口拖慢后台，但仍走关键词。
         */
        require_once __DIR__ . '/../moderation.php';

        /* 【审核改为「同步门禁 + 异步复核」】
         *
         * 原来这里同步跑 AI 审核：GLM 接口 1~3 秒，加上主机 1 秒响应，
         * 用户点「发布」要等 4~6 秒才看到结果 —— 体感就是「卡住发不出去」。
         *
         * 但审核不必挡在用户面前：
         *   - 关键词层 / 结构层是纯本地计算，几毫秒出结果，**它们才该做门禁**
         *   - AI 层是用来「捞漏网的」，晚几秒完全不影响体验
         *
         * 所以现在：
         *   1. 同步只跑关键词 + 结构层（毫秒级），决定 published / pending / reject
         *   2. 帖子立刻返回给用户
         *   3. AI 在响应结束后异步复核，只有 >=60 分才回滚成待审
         *
         * AI 依然没有最终裁决权 —— 它最多把帖子转成待审，不会直接拒绝。
         */
        $modResult = lwModerateContent($title, $content, $user, ['skip_ai' => true]);

        // 硬拒绝：直接返回错误，不写库
        if ($modResult['action'] === 'reject') {
            $labels = array_map(static function ($t) {
                return $t['label'];
            }, $modResult['types']);
            return lwActionFail(
                '内容未通过审核' . ($labels ? '（' . implode('、', $labels) . '）' : '')
                . '，请修改后重试。如有疑问可联系管理员。'
            );
        }

        $status = ($modResult['action'] === 'pending') ? 'pending' : 'published';
        $modFields = lwModerationFields($modResult);

        $postData = [
            'user_id'      => $user['id'],
            'category'     => $category,
            'title'        => sanitizeInput($title),
            'content'      => sanitizeInput($content),
            'images'       => json_encode($imageUrls, JSON_UNESCAPED_UNICODE),
            'is_anonymous' => $isAnonymous ? 1 : 0,
            'visibility'   => $visibility,
            'visible_to'   => $visibleTo,
            'exclude_to'   => $excludeTo,
            'status'       => $status,
            'likes'        => 0,
            'comments'     => 0,
            'poll'         => $poll,
        ];
        // 审核结果落到帖子记录上，供后台按「违规类型」筛选与排序
        $postData = array_merge($postData, $modFields);

        $dataDir = dirname(__DIR__, 2) . '/data/';
        if (!is_dir($dataDir)) {
            if (!@mkdir($dataDir, 0755, true)) {
                return lwActionFail('发布失败：data 目录创建失败，请联系管理员检查文件权限', 500);
            }
        }
        if (!is_writable($dataDir)) {
            return lwActionFail('发布失败：data 目录不可写，请联系管理员检查文件权限', 500);
        }

        $fs = getFS();
        try {
            $post = $fs->insert('posts', $postData);
        } catch (Exception $e) {
            error_log('Post creation failed: ' . $e->getMessage());
            return lwActionFail('发布失败，请稍后重试', 500);
        }
        if (!$post) {
            $freeSpace = @disk_free_space($dataDir);
            $msg = '发布失败：数据写入失败';
            if ($freeSpace !== false && $freeSpace < 1048576) {
                $msg .= '（磁盘空间不足）';
            } else {
                $msg .= '，请稍后重试或联系管理员';
            }
            return lwActionFail($msg, 500);
        }

        logUserActivity($user['id'], 'post_create', '发布帖子ID:' . $post['id']);

        /* 静默翻译：响应发出后再跑，不阻塞用户。
         *
         * 只在「已发布」时翻译 —— 待审核的内容可能被驳回，
         * 翻了也是浪费配额。
         * 整个翻译过程不提示用户，失败也不影响任何功能。
         */
        if ($status === 'published') {
            require_once __DIR__ . '/../translate.php';
            lwQueueTranslation((int)$post['id']);

            /* 繁简静默互转：中文↔中文同语系，翻译很快，
             * 且几乎一半读者会受益，所以这一对值得预生成。
             * 其他语言（英/日/韩）懒生成 —— 谁真的切过去才翻，省配额。 */
            lwQueueZhVariant((int)$post['id']);

            /* AI 异步复核：排在响应之后，用户完全无感。
             * 只有 AI 给出 >=60 分才回滚成待审，避免误伤正常内容。 */
            if ($category !== 'announcement') {   // 公告是管理员发的，不必复核
                require_once __DIR__ . '/../ai_review.php';
                lwQueueAiReview((int)$post['id'], $title, $content);
            }
        }

        // 正文里 @到的同学 → 站内通知。
        // 匿名帖不发（不暴露匿名作者）；待审核帖也不发（内容可能被驳回，先别打扰别人）。
        if (empty($postData['is_anonymous']) && $status === 'published') {
            require_once __DIR__ . '/../mention_notify.php';
            lwNotifyMentions(
                (string)$postData['content'],
                $user,
                (int)$post['id'],
                (string)($postData['title'] ?? ''),
                'post'
            );
        }

        // 仪表盘数据变化提醒：有新动态发布（待审核时更需管理员关注）
        try {
            $adminTip = '新动态发布：' . mb_substr((string)($postData['title'] ?? '无标题'), 0, 30)
                . '（作者：' . ($user['nickname'] ?? '用户') . '）';
            if ($status === 'pending') {
                $adminTip .= '，待审核';
            }
            notifyAdmins($adminTip, 'admin');
        } catch (Exception $e) {
            error_log('notifyAdmins on post_create failed: ' . $e->getMessage());
        }

        // 成长体系：发帖得经验 + 顺带评估成就
        lwGrowthAward((int)$user['id'], 'post_create');

        return lwActionResult(
            true,
            $hasSensitive ? '发布成功，内容需审核' : '发布成功',
            ['id' => $post['id'], 'status' => $status, 'category' => $category]
        );
    }
}
