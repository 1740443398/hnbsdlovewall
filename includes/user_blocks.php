<?php
/**
 * 用户屏蔽（拉黑）系统 —— 唯一来源
 *
 * 语义（改动前必读，这决定了「谁能看到谁」）：
 *   屏蔽是**单向**的，但效果是**双向隔离**：
 *     - 我屏蔽了 A  →  我看不到 A 的动态/评论/私信（A 的内容对我隐藏）
 *     - 同时 A 也无法：给我发私信、关注我、评论我的帖子
 *   这样既保护了屏蔽者（眼不见为净），也避免了「我屏蔽了对方，对方继续骚扰我」的漏洞。
 *   被屏蔽者**不会收到通知**（不告知被拉黑，避免引发报复性行为）。
 *
 * 数据表 user_blocks：每对关系一行 {user_id(发起屏蔽者), target_id(被屏蔽者)}。
 * 为什么不用聚合行：屏蔽关系需要按「对方是谁」单独增删，聚合行会频繁重写整行；
 * 而且本站单行关系量级很小（几十到几百行），逐行更简单也更不易错。
 *
 * 本文件只声明函数，**不要 require config/config.php**。
 */

if (!defined('LW_BLOCK_LIB')) {
    define('LW_BLOCK_LIB', 1);
}

if (!function_exists('lwBlockUser')) {
    /**
     * 屏蔽某人。幂等（重复屏蔽不报错、不重复插入）。
     * 会顺带**互相取关**并**清理对方关注我的记录**，避免「已屏蔽却还在粉丝列表里」。
     *
     * @return array{ok:bool, message:string}
     */
    function lwBlockUser(int $fromId, int $toId): array
    {
        if ($fromId <= 0 || $toId <= 0) {
            return ['ok' => false, 'message' => '参数不合法'];
        }
        if ($fromId === $toId) {
            return ['ok' => false, 'message' => '不能屏蔽自己'];
        }

        $fs = getFS();
        $exists = $fs->findOne('user_blocks', ['user_id' => $fromId, 'target_id' => $toId]);
        if (!$exists) {
            if (!$fs->insert('user_blocks', ['user_id' => $fromId, 'target_id' => $toId])) {
                return ['ok' => false, 'message' => '屏蔽失败，请稍后重试'];
            }
        }

        // 双向清掉关注关系（两个方向都清）——否则粉丝/关注列表会与屏蔽状态自相矛盾
        foreach ((array)$fs->read('follows') as $f) {
            $a = (int)($f['user_id'] ?? 0);
            $b = (int)($f['target_id'] ?? 0);
            if (($a === $fromId && $b === $toId) || ($a === $toId && $b === $fromId)) {
                $fs->delete('follows', $f['id']);
            }
        }

        return ['ok' => true, 'message' => '已屏蔽，对方的内容将不再显示'];
    }
}

if (!function_exists('lwUnblockUser')) {
    /** 解除屏蔽（幂等）。@return array{ok:bool, message:string} */
    function lwUnblockUser(int $fromId, int $toId): array
    {
        if ($fromId <= 0 || $toId <= 0) {
            return ['ok' => false, 'message' => '参数不合法'];
        }
        $fs = getFS();
        $row = $fs->findOne('user_blocks', ['user_id' => $fromId, 'target_id' => $toId]);
        if ($row && !$fs->delete('user_blocks', $row['id'])) {
            return ['ok' => false, 'message' => '解除失败，请稍后重试'];
        }
        return ['ok' => true, 'message' => '已解除屏蔽'];
    }
}

if (!function_exists('lwIsBlocked')) {
    /** $fromId 是否屏蔽了 $toId（单向判断，不做对称） */
    function lwIsBlocked(int $fromId, int $toId): bool
    {
        if ($fromId <= 0 || $toId <= 0) {
            return false;
        }
        return (bool)getFS()->findOne('user_blocks', ['user_id' => $fromId, 'target_id' => $toId]);
    }
}

if (!function_exists('lwBlockRelation')) {
    /**
     * 两人的屏蔽关系。
     * @return string 'none' | 'i_blocked' | 'blocked_me' | 'mutual'
     */
    function lwBlockRelation(int $a, int $b): string
    {
        if ($a <= 0 || $b <= 0 || $a === $b) {
            return 'none';
        }
        $iBlocked = lwIsBlocked($a, $b);
        $meBlocked = lwIsBlocked($b, $a);
        if ($iBlocked && $meBlocked) {
            return 'mutual';
        }
        if ($iBlocked) {
            return 'i_blocked';
        }
        if ($meBlocked) {
            return 'blocked_me';
        }
        return 'none';
    }
}

if (!function_exists('lwBlockedIds')) {
    /**
     * 我屏蔽的所有人（int 数组）。
     * @return int[]
     */
    function lwBlockedIds(int $userId): array
    {
        if ($userId <= 0) {
            return [];
        }
        $out = [];
        foreach ((array)getFS()->read('user_blocks') as $r) {
            if ((int)($r['user_id'] ?? 0) === $userId) {
                $out[] = (int)($r['target_id'] ?? 0);
            }
        }
        return array_values(array_filter($out));
    }
}

if (!function_exists('lwBlockerIds')) {
    /**
     * 屏蔽了我的人（int 数组）。用于「谁不能给我发私信」这类判定。
     * @return int[]
     */
    function lwBlockerIds(int $userId): array
    {
        if ($userId <= 0) {
            return [];
        }
        $out = [];
        foreach ((array)getFS()->read('user_blocks') as $r) {
            if ((int)($r['target_id'] ?? 0) === $userId) {
                $out[] = (int)($r['user_id'] ?? 0);
            }
        }
        return array_values(array_filter($out));
    }
}

if (!function_exists('lwHiddenUserIds')) {
    /**
     * 对某用户**需要隐藏内容的作者集合** = 我屏蔽的人 ∪ 屏蔽了我的人。
     *
     * 为什么把「屏蔽了我的人」也算进来：如果只单向隐藏，被屏蔽者仍然能看到屏蔽者的
     * 全部动态并持续纠缠（对方只是收不到回复）。把两边一起隐藏才算真正隔离。
     *
     * @return array<int,bool>  id => true，便于 isset() 快速判断
     */
    function lwHiddenUserIds(int $userId): array
    {
        if ($userId <= 0) {
            return [];
        }
        $set = [];
        foreach ((array)getFS()->read('user_blocks') as $r) {
            $a = (int)($r['user_id'] ?? 0);
            $b = (int)($r['target_id'] ?? 0);
            if ($a === $userId) {
                $set[$b] = true;
            } elseif ($b === $userId) {
                $set[$a] = true;
            }
        }
        unset($set[$userId]);
        return $set;
    }
}

if (!function_exists('lwFilterBlocked')) {
    /**
     * 通用内容过滤：按归属用户字段剔除被屏蔽者产生的条目。
     * 帖子列表、评论列表、通知列表都可用它，保证「屏蔽」在各处表现一致。
     *
     * @param array    $items
     * @param int      $userId  当前用户
     * @param string   $field   条目里表示作者 id 的字段名
     * @return array 重新索引后的数组
     */
    function lwFilterBlocked(array $items, int $userId, string $field = 'user_id'): array
    {
        $hidden = lwHiddenUserIds($userId);
        if (!$hidden) {
            return $items;
        }
        $out = [];
        foreach ($items as $it) {
            $uid = (int)($it[$field] ?? 0);
            if ($uid > 0 && isset($hidden[$uid])) {
                continue;
            }
            $out[] = $it;
        }
        return $out;
    }
}

if (!function_exists('lwCanInteract')) {
    /**
     * 交互闸门：B 能否对 A 发起「私信 / 关注 / 评论」这类主动行为。
     * 任一方向存在屏蔽即拒绝（屏蔽者不想被打扰；被屏蔽者也不该继续纠缠）。
     *
     * @return array{ok:bool, message:string}
     */
    function lwCanInteract(int $actorId, int $targetId, string $action = ''): array
    {
        if ($actorId <= 0 || $targetId <= 0) {
            return ['ok' => false, 'message' => '参数不合法'];
        }
        if ($actorId === $targetId) {
            return ['ok' => true, 'message' => ''];
        }
        $rel = lwBlockRelation($actorId, $targetId);
        if ($rel === 'i_blocked') {
            $hint = $action !== '' ? ('无法' . $action) : '操作失败';
            return ['ok' => false, 'message' => '你已屏蔽对方，' . $hint . '前请先解除屏蔽'];
        }
        if ($rel === 'blocked_me' || $rel === 'mutual') {
            $hint = $action !== '' ? ('无法' . $action) : '操作失败';
            // 刻意不说明「对方屏蔽了你」，避免变成探测工具
            return ['ok' => false, 'message' => $hint . '（对方已限制互动）'];
        }
        return ['ok' => true, 'message' => ''];
    }
}
