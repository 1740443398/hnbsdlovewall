<?php
/**
 * 被 @提及 时的站内通知 —— 唯一来源
 *
 * 调用方：includes/actions/post_comment.php（发表评论）、api/posts/create.php（发布帖子）。
 * 提取规则复用 includes/text_linkify.php 的 lwExtractMentions()，与页面上真正能点开的
 * @链接完全一致 —— 不会出现「看起来@了人，但对方收不到」或反过来「通知了页面上没链到的人」。
 *
 * 通知字段沿用站内既有约定（user_id / type / content / post_id / post_title / from_user / is_read），
 * 因此通知面板里的渲染、点击跳转、未读计数都不需要额外改动。
 */

require_once __DIR__ . '/text_linkify.php';

if (!function_exists('lwNotifyMentions')) {
    /**
     * @param string $text        正文（帖子内容或评论内容，原始文本，未转义）
     * @param array  $actor       发帖/评论的人
     * @param int    $postId     所属帖子 ID
     * @param string $postTitle  帖子标题（通知里展示用，可为空）
     * @param string $scene      'post' 或 'comment'，只影响通知文案
     * @param array  $skipIds    已经因其他原因通知过的用户 ID，避免同一次操作重复打扰
     * @return int 实际写入的通知条数
     */
    function lwNotifyMentions(string $text, array $actor, int $postId, string $postTitle, string $scene = 'comment', array $skipIds = []): int
    {
        $mentions = lwExtractMentions($text);
        if (!$mentions) {
            return 0;
        }

        $fs = getFS();
        $byNick = [];                                   // 小写昵称 => 用户
        foreach ((array)$fs->read('users') as $u) {
            if (empty($u['id'])) {
                continue;
            }
            // 封禁账号不再收通知，否则等于给违规账号留一条推送通道
            if (!empty($u['is_banned'])) {
                continue;
            }
            $nick = trim((string)($u['nickname'] ?? ''));
            if ($nick === '') {
                continue;
            }
            $byNick[mb_strtolower($nick)] = $u;
        }

        $actorId = (int)($actor['id'] ?? 0);
        $actorNick = trim((string)($actor['nickname'] ?? '')) ?: '有人';
        $snippet = mb_substr(trim(preg_replace('/\s+/u', ' ', $text)), 0, 40);
        $skip = array_flip(array_map('intval', $skipIds));
        $where = $scene === 'post' ? '帖子' : '评论';

        $sent = 0;
        $done = [];
        foreach (array_keys($mentions) as $nick) {
            $target = $byNick[mb_strtolower($nick)] ?? null;
            if (!$target) {
                continue;                               // 昵称不存在（或已被改掉），不产生通知
            }
            $uid = (int)$target['id'];
            if ($uid === $actorId || isset($done[$uid]) || isset($skip[$uid])) {
                continue;                               // 不通知自己；同一人多次提及只发一条
            }
            $done[$uid] = true;

            $fs->insert('notifications', [
                'user_id'    => $uid,
                'type'       => 'mention',
                'content'    => $actorNick . ' 在' . $where . '里提到了你：' . $snippet,
                'post_id'    => $postId,
                'post_title' => (string)$postTitle,
                'from_user'  => $actorNick,
                'is_read'    => false,
            ]);
            $sent++;
        }
        return $sent;
    }
}
