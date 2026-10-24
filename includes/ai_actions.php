<?php
/**
 * AI 确认卡片 · 写操作注册表与协议
 *
 * 为什么有这一层：
 *   本站所用模型（config/ai_config.php 里的 glm-4.1v-thinking-flash）实测**不会真正发起
 *   tool_calls**，只会「嘴上说要调工具」。所以「AI 想帮用户办事」这件事只能落成
 *   **纯文本协议 + 服务端白名单执行**：
 *     - 模型在回复末尾输出一行 <lw-action>{...}</lw-action>；
 *     - 服务端严格解析（失败即丢弃，不做兜底猜测）、过注册表白名单、重新校验参数；
 *     - 参数**只存服务端会话**，前端只拿到一个不透明 token；
 *     - 用户点「确认执行」后由 api/ai_action.php 凭 token 兑换并执行。
 *
 * 三条不可动摇的安全约束：
 *   1. 白名单：不在本表的 action 一律拒绝，绝不「按名字猜接口」。
 *   2. 参数由服务端重新校验：模型给的只是「意向」，不是「命令」。
 *   3. 前端拿不到参数，所以也改不了参数（改了也没用，服务端不看前端传的参数）。
 *
 * 业务逻辑一行都不在本文件里：全部转发给 includes/actions/*.php 的 lw_do_*()，
 * 与用户在页面上自己操作走的是同一段代码，不可能出现「AI 能做的事和页面不一致」。
 */

require_once __DIR__ . '/actions/_lib.php';
require_once __DIR__ . '/actions/post_favorite.php';
require_once __DIR__ . '/actions/post_like.php';
require_once __DIR__ . '/actions/user_follow.php';
require_once __DIR__ . '/actions/checkin.php';
require_once __DIR__ . '/actions/post_vote.php';
require_once __DIR__ . '/actions/post_comment.php';
require_once __DIR__ . '/actions/post_delete.php';
require_once __DIR__ . '/actions/comment_delete.php';
require_once __DIR__ . '/actions/notification_read.php';
require_once __DIR__ . '/actions/post_create.php';
require_once __DIR__ . '/actions/pm_send.php';

/** 发卡节流：每用户每 60 秒最多 3 张 */
if (!defined('AI_CARD_ISSUE_PER_MIN')) {
    define('AI_CARD_ISSUE_PER_MIN', 3);
}
/** 会话内最多 5 张待确认卡片（超出挤掉最旧的） */
if (!defined('AI_CARD_PENDING_MAX')) {
    define('AI_CARD_PENDING_MAX', 5);
}
/** 卡片有效期（秒） */
if (!defined('AI_CARD_TTL')) {
    define('AI_CARD_TTL', 600);
}

/**
 * 操作注册表 —— 唯一真源。
 *
 * 字段说明：
 *   label        卡片上显示的操作名
 *   core         切换型内核函数名（includes/actions/*.php）
 *   core_args    调用内核前合并进去的固定参数（例如关注内核需要 action=follow）
 *   ensure       幂等语义声明：先探测当前状态，已经是目标状态就直接返回成功，不重复调用内核。
 *                没有这项的操作本身天然幂等（签到）或本身就是一次性语义（投票、删除）。
 *   params       参数规格；type=int|text，required，min/max（int 为数值范围，text 为长度）
 *   display      参数在卡片上的展示方式；resolver 同时承担「可见性/归属校验」职责，
 *                返回 null 表示拒绝发卡（例如帖子对该用户不可见）
 *   confirm      tone=primary|danger；irreversible / double_confirm 供前端做二次确认
 *   rate         业务级限流（与页面上的限流是不同维度，不冲突）
 *   audit        写入用户活动日志的动作名
 */
if (!function_exists('aiActionRegistry')) {
    function aiActionRegistry(): array
    {
        static $reg = null;
        if ($reg !== null) {
            return $reg;
        }

        $reg = [
            '收藏帖子' => [
                'label'     => '收藏帖子',
                'core'      => 'lw_do_post_favorite',
                'ensure'    => [
                    'table' => 'post_favorites', 'match' => ['post_id' => 'post_id'],
                    'want' => true, 'already' => '这篇已经在你的收藏里了',
                ],
                'params'    => [
                    'post_id' => ['type' => 'int', 'required' => true, 'min' => 1, 'max' => 9999999,
                                   'resolver' => 'aiPostLabel'],
                ],
                'confirm'   => ['tone' => 'primary', 'irreversible' => false, 'double_confirm' => false],
                'rate'      => ['key' => 'ai_act_fav', 'limit' => 20, 'window' => 300],
                'audit'     => 'ai_action_favorite',
            ],
            '取消收藏' => [
                'label'     => '取消收藏',
                'core'      => 'lw_do_post_favorite',
                'ensure'    => [
                    'table' => 'post_favorites', 'match' => ['post_id' => 'post_id'],
                    'want' => false, 'already' => '这篇本来就不在收藏里',
                ],
                'params'    => [
                    'post_id' => ['type' => 'int', 'required' => true, 'min' => 1, 'max' => 9999999,
                                   'resolver' => 'aiPostLabel'],
                ],
                'confirm'   => ['tone' => 'primary', 'irreversible' => false, 'double_confirm' => false],
                'rate'      => ['key' => 'ai_act_fav', 'limit' => 20, 'window' => 300],
                'audit'     => 'ai_action_unfavorite',
            ],
            '点赞帖子' => [
                'label'     => '点赞帖子',
                'core'      => 'lw_do_post_like',
                'ensure'    => [
                    'table' => 'post_likes', 'match' => ['post_id' => 'post_id'],
                    'want' => true, 'already' => '这篇你已经点过赞了',
                ],
                'params'    => [
                    'post_id' => ['type' => 'int', 'required' => true, 'min' => 1, 'max' => 9999999,
                                   'resolver' => 'aiPostLabel'],
                ],
                'confirm'   => ['tone' => 'primary', 'irreversible' => false, 'double_confirm' => false],
                'rate'      => ['key' => 'ai_act_like', 'limit' => 30, 'window' => 300],
                'audit'     => 'ai_action_like',
            ],
            '取消点赞' => [
                'label'     => '取消点赞',
                'core'      => 'lw_do_post_like',
                'ensure'    => [
                    'table' => 'post_likes', 'match' => ['post_id' => 'post_id'],
                    'want' => false, 'already' => '这篇你本来就没点赞',
                ],
                'params'    => [
                    'post_id' => ['type' => 'int', 'required' => true, 'min' => 1, 'max' => 9999999,
                                   'resolver' => 'aiPostLabel'],
                ],
                'confirm'   => ['tone' => 'primary', 'irreversible' => false, 'double_confirm' => false],
                'rate'      => ['key' => 'ai_act_like', 'limit' => 30, 'window' => 300],
                'audit'     => 'ai_action_unlike',
            ],
            '关注用户' => [
                'label'     => '关注用户',
                'core'      => 'lw_do_user_follow',
                'core_args' => ['action' => 'follow'],
                // 内核参数名是 target_id，而给模型看/让模型填的名字用更自然的 user_id，
                // 这里做一次显式映射，避免把内部命名暴露给模型的同时又要它猜对字段名。
                'core_param_map' => ['target_id' => 'user_id'],
                'ensure'    => [
                    'table' => 'follows', 'match' => ['target_id' => 'user_id'],
                    'want' => true, 'already' => '你已经关注 TA 了',
                ],
                'params'    => [
                    'user_id' => ['type' => 'int', 'required' => true, 'min' => 1, 'max' => 9999999,
                                   'resolver' => 'aiUserLabel'],
                ],
                'confirm'   => ['tone' => 'primary', 'irreversible' => false, 'double_confirm' => false],
                'rate'      => ['key' => 'ai_act_follow', 'limit' => 20, 'window' => 300],
                'audit'     => 'ai_action_follow',
            ],
            '取消关注' => [
                'label'     => '取消关注',
                'core'      => 'lw_do_user_follow',
                'core_args' => ['action' => 'unfollow'],
                'core_param_map' => ['target_id' => 'user_id'],
                'ensure'    => [
                    'table' => 'follows', 'match' => ['target_id' => 'user_id'],
                    'want' => false, 'already' => '你本来就没关注 TA',
                ],
                'params'    => [
                    'user_id' => ['type' => 'int', 'required' => true, 'min' => 1, 'max' => 9999999,
                                   'resolver' => 'aiUserLabel'],
                ],
                'confirm'   => ['tone' => 'primary', 'irreversible' => false, 'double_confirm' => false],
                'rate'      => ['key' => 'ai_act_follow', 'limit' => 20, 'window' => 300],
                'audit'     => 'ai_action_unfollow',
            ],
            '每日签到' => [
                'label'     => '每日签到',
                'core'      => 'lw_do_checkin',
                'params'    => [],
                'confirm'   => ['tone' => 'primary', 'irreversible' => false, 'double_confirm' => false],
                'rate'      => ['key' => 'ai_act_checkin', 'limit' => 5, 'window' => 60],
                'audit'     => 'ai_action_checkin',
            ],
            '给帖子投票' => [
                'label'     => '给帖子投票',
                'core'      => 'lw_do_post_vote',
                'params'    => [
                    'post_id' => ['type' => 'int', 'required' => true, 'min' => 1, 'max' => 9999999,
                                   'resolver' => 'aiPostLabel'],
                    // 选项下标从 0 开始；上限在 aiValidateActionParams 里按该帖实际选项数收紧
                    'option'  => ['type' => 'int', 'required' => true, 'min' => 0, 'max' => 50,
                                   'resolver' => 'aiPollOptionLabel'],
                ],
                'confirm'   => ['tone' => 'primary', 'irreversible' => true, 'double_confirm' => true],
                'rate'      => ['key' => 'ai_act_vote', 'limit' => 10, 'window' => 300],
                'audit'     => 'ai_action_vote',
            ],
            '发表评论' => [
                'label'     => '发表评论',
                'core'      => 'lw_do_post_comment',
                'params'    => [
                    'post_id' => ['type' => 'int', 'required' => true, 'min' => 1, 'max' => 9999999,
                                   'resolver' => 'aiPostLabel'],
                    'content' => ['type' => 'text', 'required' => true, 'min' => 1, 'max' => 500,
                                   'echo' => true],
                ],
                'confirm'   => ['tone' => 'primary', 'irreversible' => false, 'double_confirm' => false],
                'rate'      => ['key' => 'ai_act_comment', 'limit' => 10, 'window' => 300],
                'audit'     => 'ai_action_comment',
            ],
            '发布帖子' => [
                'label'     => '发布帖子',
                'core'      => 'lw_do_post_create',
                // AI 发的内容一律公开：不开放 visible_to / exclude_to，
                // 免得模型按一句话就替用户把某个同学屏蔽掉。
                'core_args' => ['visibility' => 'public'],
                'params'    => [
                    'category' => ['type' => 'enum', 'required' => true,
                                   'values' => ['lost_found', 'study_help', 'social_chat', 'confession', 'school_info', 'other']],
                    'content'  => ['type' => 'text', 'required' => true, 'min' => 5, 'max' => 2000,
                                   'echo' => true, 'echo_label' => '正文'],
                    'title'    => ['type' => 'text', 'required' => false, 'min' => 2, 'max' => 200,
                                   'echo' => true, 'echo_label' => '标题'],
                    'is_anonymous' => ['type' => 'bool', 'required' => false, 'default' => false,
                                       'echo' => true, 'echo_label' => '匿名发布'],
                    'poll_question' => ['type' => 'text', 'required' => false, 'min' => 1, 'max' => 100,
                                        'echo' => true, 'echo_label' => '投票主题'],
                    'poll_options'  => ['type' => 'text', 'required' => false, 'min' => 1, 'max' => 300,
                                        'echo' => true, 'echo_label' => '投票选项'],
                ],
                'confirm'   => ['tone' => 'primary', 'irreversible' => true, 'double_confirm' => true],
                'rate'      => ['key' => 'ai_act_post_create', 'limit' => 5, 'window' => 900],
                'audit'     => 'ai_action_post_create',
            ],
            '发送私信' => [
                'label'     => '发送私信',
                'core'      => 'lw_do_pm_send',
                'params'    => [
                    'to_qq' => ['type' => 'text', 'required' => true, 'min' => 5, 'max' => 12,
                                'resolver' => 'aiUserByQqLabel'],
                    'text'  => ['type' => 'text', 'required' => true, 'min' => 1, 'max' => 500,
                                'echo' => true, 'echo_label' => '内容'],
                ],
                'confirm'   => ['tone' => 'primary', 'irreversible' => true, 'double_confirm' => true],
                'rate'      => ['key' => 'ai_act_pm_send', 'limit' => 5, 'window' => 600],
                'audit'     => 'ai_action_pm_send',
            ],
            '删除我的帖子' => [
                'label'     => '删除帖子',
                'core'      => 'lw_do_post_delete',
                'params'    => [
                    'post_id' => ['type' => 'int', 'required' => true, 'min' => 1, 'max' => 9999999,
                                   'resolver' => 'aiPostLabel', 'owned_by_user' => true],
                ],
                'confirm'   => ['tone' => 'danger', 'irreversible' => true, 'double_confirm' => true],
                'rate'      => ['key' => 'ai_act_post_delete', 'limit' => 3, 'window' => 600],
                'audit'     => 'ai_action_post_delete',
            ],
            '删除我的评论' => [
                'label'     => '删除评论',
                'core'      => 'lw_do_comment_delete',
                'params'    => [
                    'comment_id' => ['type' => 'int', 'required' => true, 'min' => 1, 'max' => 9999999,
                                      'resolver' => 'aiCommentLabel', 'owned_by_user' => true],
                ],
                'confirm'   => ['tone' => 'danger', 'irreversible' => true, 'double_confirm' => true],
                'rate'      => ['key' => 'ai_act_comment_delete', 'limit' => 5, 'window' => 600],
                'audit'     => 'ai_action_comment_delete',
            ],
            '标记通知已读' => [
                'label'     => '标记通知已读',
                'core'      => 'lw_do_notification_read',
                'params'    => [
                    'action' => ['type' => 'enum', 'required' => false, 'default' => 'read_all',
                                  'values' => ['read', 'read_all']],
                    'id'     => ['type' => 'int', 'required' => false, 'min' => 1, 'max' => 99999999,
                                  'resolver' => 'aiNotificationLabel'],
                ],
                'confirm'   => ['tone' => 'primary', 'irreversible' => false, 'double_confirm' => false],
                'rate'      => ['key' => 'ai_act_notify', 'limit' => 20, 'window' => 300],
                'audit'     => 'ai_action_notification_read',
            ],
        ];

        return $reg;
    }
}

/** 供 system prompt 使用的「可用操作清单」文本 */
if (!function_exists('aiActionPromptCatalog')) {
    function aiActionPromptCatalog(): string
    {
        $lines = [];
        foreach (aiActionRegistry() as $action => $spec) {
            $names = [];
            foreach ($spec['params'] as $k => $p) {
                $t = $p['type'] ?? 'text';
                if ($t === 'int') {
                    $type = '(整数)';
                } elseif ($t === 'enum') {
                    $type = '(只能是：' . implode('|', $p['values']) . ')';
                } elseif ($t === 'bool') {
                    $type = '(true/false)';
                } else {
                    $type = '(文字)';
                }
                $names[] = $k . $type . (empty($p['required']) ? '〔可选〕' : '');
            }
            $lines[] = '- ' . $action . '：参数 ' . ($names ? implode('、', $names) : '无');
        }
        return implode("\n", $lines);
    }
}

// ---------------------------------------------------------------------------
// 展示 / 校验用的解析器
// 约定：返回字符串 = 通过；返回 null = 拒绝发卡（对象不存在、不可见、不属于本人等）
// 这样「卡片上的对象名」与「权限校验」出自同一处，不会出现「卡片显示得出来但执行失败」。
// ---------------------------------------------------------------------------

if (!function_exists('aiPostLabel')) {
    /** 帖子 → 《标题》 #id；不可见则 null */
    function aiPostLabel($postId, array $user, array $ctx = []): ?string
    {
        $post = getFS()->findById('posts', (int)$postId);
        if (!$post) {
            return null;
        }
        if (!lwPostVisible($post, $user)) {
            return null;
        }
        $title = trim((string)($post['title'] ?? ''));
        if ($title === '') {
            $title = '（无标题）';
        }
        return '《' . mb_substr($title, 0, 40) . '》 #' . (int)$post['id'];
    }
}

if (!function_exists('aiUserLabel')) {
    /** 用户 → 昵称 #id；只能拿到公开字段 */
    function aiUserLabel($userId, array $user, array $ctx = []): ?string
    {
        $u = getFS()->findById('users', (int)$userId);
        if (!$u) {
            return null;
        }
        if ((int)$u['id'] === (int)$user['id']) {
            return null; // 不能关注自己，内核也会拒，这里提前挡掉让卡片不发出来
        }
        $nick = trim((string)($u['nickname'] ?? ''));
        if ($nick === '') {
            $nick = '用户' . (int)$u['id'];
        }
        return $nick . ' #' . (int)$u['id'];
    }
}

if (!function_exists('aiUserByQqLabel')) {
    /**
     * QQ 号 → 昵称 #id；用于私信收件人。
     * 挡掉三种情况：QQ 不存在、是自己、对方已被封禁（内核还会再查一次，这里提前不发卡）。
     */
    function aiUserByQqLabel($qq, array $user, array $ctx = []): ?string
    {
        $qq = trim((string)$qq);
        if (!isValidQQ($qq)) {
            return null;
        }
        $u = getFS()->findOne('users', ['qq' => $qq]);
        if (!$u) {
            return null;
        }
        if ((string)($u['qq'] ?? '') === (string)($user['qq'] ?? '')) {
            return null; // 不能给自己发私信
        }
        if (!empty($u['is_banned'])) {
            return null;
        }
        $nick = trim((string)($u['nickname'] ?? ''));
        if ($nick === '') {
            $nick = 'QQ' . $qq;
        }
        return $nick . '（QQ ' . $qq . '）';
    }
}

if (!function_exists('aiPollOptionLabel')) {
    /** 投票选项 → 第 N 项「选项文字」；同时把上限收紧为该帖真实选项数 */
    function aiPollOptionLabel($option, array $user, array $ctx = []): ?string
    {
        $postId = (int)($ctx['post_id'] ?? 0);
        if ($postId <= 0) {
            return null;
        }
        $post = getFS()->findById('posts', $postId);
        if (!$post || !lwPostVisible($post, $user)) {
            return null;
        }
        $options = $post['poll']['options'] ?? null;
        if (!is_array($options) || !$options) {
            return null;
        }
        $idx = (int)$option;
        if (!array_key_exists($idx, array_values($options))) {
            return null;
        }
        $text = (string)array_values($options)[$idx];
        return '第 ' . ($idx + 1) . ' 项「' . mb_substr(trim($text), 0, 40) . '」';
    }
}

if (!function_exists('aiCommentLabel')) {
    /** 评论 → 内容摘要；必须属于当前用户（AI 只允许删自己的评论） */
    function aiCommentLabel($commentId, array $user, array $ctx = []): ?string
    {
        $c = getFS()->findById('comments', (int)$commentId);
        if (!$c) {
            return null;
        }
        if ((int)($c['user_id'] ?? 0) !== (int)$user['id']) {
            return null;
        }
        return '你在他处的评论「' . mb_substr(trim((string)($c['content'] ?? '')), 0, 40) . '」 #' . (int)$c['id'];
    }
}

if (!function_exists('aiNotificationLabel')) {
    /** 通知 → 内容摘要；必须属于当前用户 */
    function aiNotificationLabel($id, array $user, array $ctx = []): ?string
    {
        $n = getFS()->findById('notifications', (int)$id);
        if (!$n) {
            return null;
        }
        if ((int)($n['user_id'] ?? 0) !== (int)$user['id']) {
            return null;
        }
        return '「' . mb_substr(trim((string)($n['content'] ?? '')), 0, 40) . '」';
    }
}

// ---------------------------------------------------------------------------
// 协议解析
// ---------------------------------------------------------------------------

if (!function_exists('aiExtractActionBlock')) {
    /**
     * 从模型回复里抽出至多 1 个 action 块。
     * **严格**：只认最规范的形态（标签之间是单行合法 JSON），其余一律返回 null。
     * 不做「尝试修复」——修错一个参数就是一次误操作。
     */
    function aiExtractActionBlock(string $reply): ?array
    {
        if ($reply === '' || strpos($reply, 'lw-action') === false) {
            return null;
        }
        // 标签之间只允许单个 JSON 对象，且不含换行（防止把大段正文塞进来）
        if (!preg_match('/<lw-action>\s*(\{[^\n\r]{2,2000}\})\s*<\/lw-action>/u', $reply, $m)) {
            return null;
        }
        $json = json_decode($m[1], true);
        if (!is_array($json) || json_last_error() !== JSON_ERROR_NONE) {
            error_log('[love_wall] AI 卡片：action 块 JSON 解析失败，已丢弃');
            return null;
        }
        $action = isset($json['action']) ? trim((string)$json['action']) : '';
        $params = (isset($json['params']) && is_array($json['params'])) ? $json['params'] : [];
        if ($action === '') {
            return null;
        }
        // 参数个数与键长做硬限制，避免被塞入超长内容
        if (count($params) > 6) {
            return null;
        }
        foreach ($params as $k => $v) {
            if (!is_string($k) || mb_strlen($k) > 32) {
                return null;
            }
            if (is_array($v)) {
                return null; // 本站所有操作的参数都是标量
            }
        }
        return [
            'action' => $action,
            'params' => $params,
            'say'    => mb_substr(trim((string)($json['say'] ?? '')), 0, 80),
        ];
    }
}

if (!function_exists('aiStripActionBlock')) {
    /** 把 action 块从可见正文里剥离，防止前端把 JSON 当正文渲染出来 */
    function aiStripActionBlock(string $reply): string
    {
        $clean = preg_replace('/<lw-action>.*?<\/lw-action>/su', '', $reply);
        // 模型有时会把块写在代码栅栏里，一并清掉
        $clean = preg_replace('/```(?:json)?\s*\{\s*"action".*?\}\s*```/su', '', (string)$clean);
        return trim((string)$clean);
    }
}

// ---------------------------------------------------------------------------
// 参数校验 + 卡片数据
// ---------------------------------------------------------------------------

if (!function_exists('aiValidateActionParams')) {
    /**
     * 按注册表规格校验并规范化参数。返回 null 表示拒绝。
     *
     * @return array|null ['params' => 规范化后的参数, 'labels' => [参数名 => 展示文案], 'lines' => 卡片行]
     */
    function aiValidateActionParams(array $spec, array $params, array $user): ?array
    {
        $clean = [];
        $labels = [];

        foreach ($spec['params'] as $key => $rule) {
            $required = !empty($rule['required']);
            $has = array_key_exists($key, $params) && $params[$key] !== '' && $params[$key] !== null;

            if (!$has) {
                if ($required) {
                    return null;
                }
                if (array_key_exists('default', $rule)) {
                    $clean[$key] = $rule['default'];
                }
                continue;
            }

            $raw = $params[$key];
            $type = $rule['type'] ?? 'text';

            if ($type === 'int') {
                if (!is_numeric($raw) || (string)(int)$raw !== trim((string)$raw)) {
                    return null; // 严格要求「纯整数字符串」，挡掉 "12abc" / 1.5 / true
                }
                $v = (int)$raw;
                if (isset($rule['min']) && $v < $rule['min']) {
                    return null;
                }
                if (isset($rule['max']) && $v > $rule['max']) {
                    return null;
                }
                $clean[$key] = $v;
            } elseif ($type === 'enum') {
                $v = (string)$raw;
                if (!in_array($v, $rule['values'] ?? [], true)) {
                    return null;
                }
                $clean[$key] = $v;
            } elseif ($type === 'bool') {
                // 模型可能给 JSON 布尔、也可能给 "1"/"true" 字符串，统一收敛成真布尔
                $v = filter_var($raw, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
                if ($v === null) {
                    return null;
                }
                $clean[$key] = $v;
            } else { // text
                if (!is_scalar($raw)) {
                    return null;
                }
                $v = trim((string)$raw);
                $len = mb_strlen($v);
                if (isset($rule['min']) && $len < $rule['min']) {
                    return null;
                }
                if (isset($rule['max']) && $len > $rule['max']) {
                    return null;
                }
                $clean[$key] = $v;
            }

            // 归属校验：只允许操作自己的东西（删帖/删评论）
            if (!empty($rule['owned_by_user'])) {
                $ownerField = ($key === 'comment_id') ? 'comments' : (($key === 'post_id') ? 'posts' : '');
                if ($ownerField !== '') {
                    $row = getFS()->findById($ownerField, $clean[$key]);
                    if (!$row || (int)($row['user_id'] ?? 0) !== (int)$user['id']) {
                        return null;
                    }
                }
            }
        }

        // 展示解析：既生成卡片文案，也当作一次额外的可见性/归属闸门
        foreach ($spec['params'] as $key => $rule) {
            if (!isset($rule['resolver']) || !array_key_exists($key, $clean)) {
                continue;
            }
            $fn = $rule['resolver'];
            if (!function_exists($fn)) {
                return null;
            }
            $label = $fn($clean[$key], $user, $clean);
            if ($label === null || $label === '') {
                return null;
            }
            $labels[$key] = $label;
        }

        return ['params' => $clean, 'labels' => $labels];
    }
}

if (!function_exists('aiActionCardLines')) {
    /**
     * 组装卡片要显示的行。只输出「操作」「对象」和用户需知道的正文，
     * 不把内部参数名暴露成技术术语。
     */
    function aiActionCardLines(array $spec, array $validated): array
    {
        $lines = [['k' => '操作', 'v' => $spec['label']]];
        $ctx = [];

        foreach ($validated['labels'] as $key => $label) {
            if ($key === 'option' || $key === 'id') {
                $ctx[] = $label;
                continue;
            }
            if (isset($spec['params'][$key]['echo'])) {
                continue; // 有 echo 的参数在下面单独整段展示
            }
            $lines[] = ['k' => counting_label($key), 'v' => $label];
        }

        if ($ctx) {
            $lines[] = ['k' => '选择', 'v' => implode(' · ', $ctx)];
        }

        // 用户自己写出去的正文必须完整展示——用户得看清自己要发出去的是什么
        // （按注册表里 echo 的顺序渲染，保证「标题→正文→投票」的顺序稳定）
        foreach ($spec['params'] as $key => $rule) {
            if (empty($rule['echo']) || !array_key_exists($key, $validated['params'])) {
                continue;
            }
            $v = $validated['params'][$key];
            if (is_bool($v)) {
                $v = $v ? '是' : '否';
            }
            $v = trim((string)$v);
            if ($v === '' || $v === '否') {
                continue;
            }
            $lines[] = ['k' => (string)($rule['echo_label'] ?? '内容'), 'v' => $v];
        }

        return $lines;
    }
}

if (!function_exists('counting_label')) {
    function counting_label(string $key): string
    {
        $map = [
            'post_id' => '帖子', 'user_id' => '用户', 'comment_id' => '评论', 'id' => '对象',
            'to_qq' => '接收方',
        ];
        return $map[$key] ?? '对象';
    }
}

// ---------------------------------------------------------------------------
// 卡片 token（核心安全机制）
// ---------------------------------------------------------------------------

if (!function_exists('aiIssueActionToken')) {
    /**
     * 校验并发放一张待确认卡片。
     * 参数只写进 $_SESSION['ai_pending']，返回给前端的只有不透明 token 与展示文案。
     *
     * @return array|null null 表示拒绝发卡（不在白名单 / 权限不足 / 参数非法 / 触发节流）
     */
    function aiIssueActionToken(?array $user, string $actionKey, array $params, int $maxPerMin = 0): ?array
    {
        if (!$user || !empty($user['is_banned'])) {
            return null; // 游客与封禁用户一律不发卡
        }
        $reg = aiActionRegistry();
        if (!isset($reg[$actionKey])) {
            return null;
        }
        $spec = $reg[$actionKey];

        $validated = aiValidateActionParams($spec, $params, $user);
        if ($validated === null) {
            return null;
        }

        // 发卡节流：防止用户靠诱导话术让 AI 连发卡片刷操作
        $now = time();
        $issued = $_SESSION['ai_act_issued'] ?? [];
        $issued = array_values(array_filter($issued, function ($t) use ($now) {
            return $t > $now - 60;
        }));
        // 自带模型的用户走自己的额度，发卡节流可以放宽；默认仍用站点保守值
        $limit = $maxPerMin > 0 ? $maxPerMin : AI_CARD_ISSUE_PER_MIN;
        if (count($issued) >= $limit) {
            return null;
        }
        $issued[] = $now;
        $_SESSION['ai_act_issued'] = $issued;

        // 入队（一次会话最多 5 张待确认，超出挤掉最旧的）
        $pending = $_SESSION['ai_pending'] ?? [];
        foreach ($pending as $tk => $it) {
            if (($it['exp'] ?? 0) <= $now) {
                unset($pending[$tk]);
            }
        }
        while (count($pending) >= AI_CARD_PENDING_MAX) {
            array_shift($pending);
        }

        $token = bin2hex(random_bytes(16));
        $pending[$token] = [
            'action' => $actionKey,
            'params' => $validated['params'],
            'uid'    => (int)$user['id'],
            'issue'  => $now,
            'exp'    => $now + AI_CARD_TTL,
        ];
        $_SESSION['ai_pending'] = $pending;

        return [
            'token'        => $token,
            'action'       => $actionKey,
            'label'        => $spec['label'],
            'lines'        => aiActionCardLines($spec, $validated),
            'tone'         => $spec['confirm']['tone'] ?? 'primary',
            'irreversible' => !empty($spec['confirm']['irreversible']),
            'double'       => !empty($spec['confirm']['double_confirm']),
            'expires_in'   => AI_CARD_TTL,
        ];
    }
}

if (!function_exists('aiTakePendingAction')) {
    /**
     * 取出并**立即消费**一个待确认操作（一次性：先删除再执行，避免重放）。
     * 返回 null 表示 token 不存在 / 已过期 / 不属于当前用户。
     *
     * @return array|null ['action' => 键, 'params' => [...]]
     */
    function aiTakePendingAction(?array $user, string $token): ?array
    {
        if (!$user || $token === '' || !preg_match('/^[a-f0-9]{32}$/', $token)) {
            return null;
        }
        $pending = $_SESSION['ai_pending'] ?? [];
        $item = $pending[$token] ?? null;
        if (!$item) {
            return null;
        }
        unset($pending[$token]);
        $_SESSION['ai_pending'] = $pending;

        if ((int)($item['uid'] ?? 0) !== (int)$user['id']) {
            return null;
        }
        if ((int)($item['exp'] ?? 0) < time()) {
            return null;
        }
        return [
            'action' => (string)($item['action'] ?? ''),
            'params' => (array)($item['params'] ?? []),
        ];
    }
}

// ---------------------------------------------------------------------------
// 执行入口（api/ai_action.php 唯一调用点）
// ---------------------------------------------------------------------------

if (!function_exists('aiExecuteAction')) {
    /**
     * 执行一个已授权（已过 token 兑换）的操作。
     *
     * 这里是**唯一的**执行入口，负责：
     *   1. 再次过白名单（防注册表被人为绕过）
     *   2. 再次做参数校验 + 归属/可见性闸门（发卡到点确认之间，帖子可能已被删、权限可能已变）
     *   3. 业务级限流（注册表 rate）
     *   4. 幂等语义 ensure：先探测当前状态，已经是目标状态就直接返回成功，
     *      不去调用「切换型」内核（否则「收藏」会被切换成「取消收藏」）
     *   5. 转发给 includes/actions/*.php 的业务内核
     *
     * @param array  $user      服务端会话得到的真实用户
     * @param string $actionKey 注册表键
     * @param array  $params    已由 aiTakePendingAction 取出的参数
     * @return array{ok:bool,message:string,data:array,code:int}
     */
    function aiExecuteAction(?array $user, string $actionKey, array $params): array
    {
        if (!$user) {
            return lwActionFail('请先登录后再操作', 401);
        }
        if (!empty($user['is_banned'])) {
            return lwActionFail('账号已被封禁，无法操作', 403);
        }

        $reg = aiActionRegistry();
        if (!isset($reg[$actionKey])) {
            return lwActionFail('不支持的操作', 400);
        }
        $spec = $reg[$actionKey];

        $validated = aiValidateActionParams($spec, $params, $user);
        if ($validated === null) {
            return lwActionFail('操作条件已变化，无法执行（帖子可能已删除或已超出你的权限）', 409);
        }
        $clean = $validated['params'];

        // 业务级限流：与页面上操作走的是同一维度（按用户）
        if (!empty($spec['rate'])) {
            $r = $spec['rate'];
            if (!checkRateLimit('u' . (int)$user['id'], 'ai_' . ($r['key'] ?? 'act'), (int)($r['limit'] ?? 10), (int)($r['window'] ?? 300))) {
                return lwActionFail('这个操作有点频繁，过一会儿再试', 429);
            }
        }

        // 幂等：已经是目标状态就不动，避免「切换型」内核把状态反向切回去
        if (!empty($spec['ensure'])) {
            $e = $spec['ensure'];
            $cond = ['user_id' => (int)$user['id']];
            foreach (($e['match'] ?? []) as $tableField => $paramKey) {
                if (!array_key_exists($paramKey, $clean)) {
                    return lwActionFail('操作参数不完整', 400);
                }
                $cond[$tableField] = $clean[$paramKey];
            }
            $exists = (bool)getFS()->findOne($e['table'], $cond);
            if ($exists === (bool)$e['want']) {
                return lwActionResult(true, (string)($e['already'] ?? '已经是你想要的状态了'),
                    ['noop' => true, 'already' => true]);
            }
        }

        $fn = (string)($spec['core'] ?? '');
        if ($fn === '' || !function_exists($fn)) {
            error_log('[love_wall] AI 卡片：内核函数缺失 ' . $fn . '（action=' . $actionKey . '）');
            return lwActionFail('服务暂时不可用，请稍后再试', 500);
        }

        $callArgs = $clean;
        // 参数名映射：给模型看的是自然命名（user_id），内核用的是自己的字段名（target_id）
        if (!empty($spec['core_param_map'])) {
            foreach ($spec['core_param_map'] as $coreKey => $paramKey) {
                if (array_key_exists($paramKey, $callArgs)) {
                    $callArgs[$coreKey] = $callArgs[$paramKey];
                    unset($callArgs[$paramKey]);
                }
            }
        }
        if (!empty($spec['core_args'])) {
            $callArgs = array_merge($callArgs, $spec['core_args']);
        }

        $result = $fn($user, $callArgs);
        if (!is_array($result) || !isset($result['ok'])) {
            error_log('[love_wall] AI 卡片：内核返回结构异常 action=' . $actionKey);
            return lwActionFail('服务暂时不可用，请稍后再试', 500);
        }

        logUserActivity((int)$user['id'], (string)($spec['audit'] ?? 'ai_action'),
            'AI 确认卡片执行：' . $actionKey . ' 结果=' . ($result['ok'] ? 'ok' : 'fail'));

        return $result;
    }
}

// ---------------------------------------------------------------------------
// 数据源沙箱化（防 Prompt Injection）
// ---------------------------------------------------------------------------

if (!function_exists('aiSanitizeDataSource')) {
    /**
     * 清洗要注入 AI 上下文的**用户产生内容**（帖子标题/正文、评论、昵称等）。
     *
     * 威胁模型：帖子正文里可以写 <lw-action>{...}</lw-action> 或钓鱼链接，
     * 若原样注入，模型可能被诱导「照着输出」，或把链接转述给用户。
     * 这里在进上下文之前就中和掉：
     *   - 动作标签 → 全角形式（模型仍能看到有人在写这类文本，但不会当成协议）
     *   - Markdown 链接 → 只留文字、去掉地址
     *   - 裸 URL → 占位符
     *   - 代码栅栏 → 去掉栅栏符号，避免模型以为是「可执行片段」
     */
    function aiSanitizeDataSource(string $text): string
    {
        if ($text === '') {
            return '';
        }
        // 动作标签（大小写与多余空白都中和）
        $text = preg_replace('/<\s*\/?\s*lw-action\s*>/iu', '［lw-action］', $text);
        // Markdown 链接：[文字](地址) → 文字
        $text = preg_replace('/\[([^\]\n]{0,120})\]\((?:[^)\s]{0,300})\)/u', '$1', $text);
        // 裸 URL
        $text = preg_replace('#https?://[^\s<>"\'）)】\]]+#iu', '[已移除链接]', $text);
        // 代码栅栏：只去掉 ``` 与语言标记，保留里面的内容
        // （不能整行删——单行的 ```code``` 会被连内容一起删掉）
        $text = preg_replace('/`{3,}[a-zA-Z0-9+#.-]*/u', '', $text);
        return trim((string)$text);
    }
}

if (!function_exists('aiSanitizeDataBlocks')) {
    /** 对一组数据块批量清洗（在拼接进 system 提示词之前调用） */
    function aiSanitizeDataBlocks(array $blocks): array
    {
        $out = [];
        foreach ($blocks as $b) {
            $out[] = aiSanitizeDataSource((string)$b);
        }
        return $out;
    }
}
