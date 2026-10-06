<?php
/**
 * 写操作业务内核 —— 公共部分
 *
 * 为什么要有这一层：
 *   站内每个写接口（收藏、点赞、关注、签到、投票、评论、删除…）原先都把「业务逻辑」
 *   直接写在 api/*.php 里。AI 助手要能替用户执行同样的操作，如果另写一份，
 *   两份逻辑迟早分叉，出现「AI 能做的事和页面上的行为不一致」。
 *   所以把业务内核抽成 lw_do_*()，由旧接口与 api/ai_action.php 共同调用，
 *   参数校验、权限判定、副作用（通知/计数/审计日志）只有一份实现。
 *
 * 约定（非常重要）：
 *   1. 本目录下的文件只声明函数，**不要 require config/config.php**
 *      （会二次 session_start() 导致 header 警告或 500）。由调用方保证 config 已加载。
 *   2. 内核函数只接「已经由服务端确认过的用户」和「已经过类型/范围校验的参数」，
 *      绝不自己读 $_POST / $_GET。这样 AI 路径与页面路径的入参来源就不会混。
 *   3. 内核不输出响应、不 die、不 header()，只 return 结果数组，由调用方决定怎么回。
 */

require_once __DIR__ . '/../post_visibility.php';
// 成长体系（经验 + 成就）。在这里统一引入，业务动作里只需一行 lwGrowthAward()，
// 避免每个动作文件各自 require 时漏掉其中一个而导致成就解锁不了。
require_once __DIR__ . '/../achievements.php';
// 屏蔽闸门（lwCanInteract）：关注/私信/评论等主动行为都要先过这道判定。
require_once __DIR__ . '/../user_blocks.php';
// 私信偏好（lwPmShouldNotify）：发送私信时判断接收方是否开了免打扰。
require_once __DIR__ . '/../pm_settings.php';

if (!defined('LW_ACTIONS_LIB')) {
    define('LW_ACTIONS_LIB', 1);
}

if (!function_exists('lwActionResult')) {
    /**
     * 统一的内核返回结构。
     *
     * @param bool   $ok     成功与否
     * @param string $message 给用户看的中文提示
     * @param array  $data    成功时的业务数据
     * @param int    $code    失败时的 HTTP 状态码（沿用旧接口的取值，默认 400）
     */
    function lwActionResult(bool $ok, string $message = '', array $data = [], int $code = 0): array
    {
        return [
            'ok'      => $ok,
            'message' => $message,
            'data'    => $data,
            'code'    => $ok ? 200 : ($code > 0 ? $code : 400),
        ];
    }
}

if (!function_exists('lwActionFail')) {
    /** 失败结果的简写 */
    function lwActionFail(string $message, int $code = 400): array
    {
        return lwActionResult(false, $message, [], $code);
    }
}

if (!function_exists('lwActionPostOwnVisibleToUser')) {
    /**
     * 帖子对当前用户是否「可交互」（收藏/点赞/评论共用）——与页面上的判定完全一致：
     * 管理员放行；作者放行；其余人要求已发布且通过可见范围。
     * 返回 null 表示放行，返回数组表示应当直接返回的失败结果（含原来的文案与状态码）。
     *
     * @param string $denyMessage 可见范围不通过时的文案（各接口措辞不同，如「无权操作该帖子」/「无权评论该帖子」）
     */
    function lwActionPostOwnVisibleToUser(array $post, array $user, string $denyMessage)
    {
        if (lwIsAdminUser($user) || lwPostIsAuthor($post, $user)) {
            return null;
        }
        if ((string)($post['status'] ?? 'published') !== 'published') {
            return lwActionFail('帖子不存在或未发布');
        }
        if (!lwPostVisibilityAllows($post, $user)) {
            return lwActionFail($denyMessage, 403);
        }
        return null;
    }
}
