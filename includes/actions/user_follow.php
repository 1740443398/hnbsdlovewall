<?php
/**
 * 关注 / 取消关注用户
 *
 * 调用方：api/user/follow.php（页面）、api/ai_action.php（AI 确认卡片）
 * 入参：$in['target_id'] int 已校验；$in['action'] 'follow' | 'unfollow'
 */
require_once __DIR__ . '/_lib.php';

if (!function_exists('lw_do_user_follow')) {
    function lw_do_user_follow(array $user, array $in): array
    {
        $action = (string)($in['action'] ?? '');
        $targetId = (int)($in['target_id'] ?? 0);

        if (!in_array($action, ['follow', 'unfollow'], true)) {
            return lwActionFail('操作无效');
        }
        if ($targetId <= 0) {
            return lwActionFail('目标用户无效');
        }
        if ($targetId == $user['id']) {
            return lwActionFail('不能关注自己', 400);
        }

        $fs = getFS();
        $target = $fs->findById('users', $targetId);
        if (!$target) {
            return lwActionFail('目标用户不存在');
        }

        $existing = $fs->findOne('follows', ['user_id' => $user['id'], 'target_id' => $targetId]);
        $message = '已关注';
        $followed = false;

        if ($action === 'follow') {
            // 屏蔽闸门：任一方向被屏蔽都不允许建立关注关系
            $gate = lwCanInteract((int)$user['id'], $targetId, '关注该用户');
            if (!$gate['ok']) {
                return lwActionFail($gate['message'], 403);
            }
            if (!$existing) {
                $followed = true;
                $fs->insert('follows', ['user_id' => $user['id'], 'target_id' => $targetId]);
                // 通知被关注者
                $fs->insert('notifications', [
                    'user_id'   => $targetId,
                    'type'      => 'follow',
                    'content'   => ($user['nickname'] ?? '用户') . ' 关注了你',
                    'from_user' => $user['nickname'] ?? '用户',
                    'is_read'   => false,
                ]);
                // 成长体系：给被关注者发经验（取消关注不扣）
                lwGrowthAward($targetId, 'followed');
                $message = '关注成功';
            }
        } else {
            if ($existing) {
                $followed = true;
                $fs->delete('follows', $existing['id']);
                $message = '已取消关注';
            }
        }

        // 动态计算目标用户的粉丝数，避免计数不一致
        $followerCount = count($fs->find('follows', ['target_id' => $targetId]));

        return lwActionResult(true, $message, [
            'follower_count' => $followerCount,
            'followed'       => $followed,
            'is_following'   => $action === 'follow' && $followed,
        ]);
    }
}
