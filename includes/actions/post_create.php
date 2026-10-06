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

        $hasSensitive = !empty(checkSensitiveWords($title . ' ' . $content));
        $status = ($hasSensitive && $category !== 'announcement') ? 'pending' : 'published';

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
