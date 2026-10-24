<?php
/**
 * 用户关系绑定（需求二、5）
 * ---------------------------------------------------------------------------
 * 「新增用户关系绑定功能，支持用户自主设置关系状态对外可见权限」
 *
 * 设计口径：
 *   - 关系是**单向申请 + 双向确认**：A 申请绑定 B，B 同意后双方各有一条记录；
 *   - 关系类型可自定义标签（如「同桌」「好友」「队友」），但长度受限；
 *   - **可见权限**由每个人自己设，互不影响：
 *       public  所有人可见
 *       friends 仅共同好友可见（本实现落地为「仅已登录用户」）
 *       private 仅自己可见（对方也看不到你这条）
 *   - 解除关系：任意一方可单方面解除（不需要对方同意），避免"绑了就甩不掉"。
 *
 * 数据表 user_relations：
 *   { id, user_id, target_id, type, status(pending|accepted|rejected),
 *     visibility, initiator(bool 是否发起方), ts, updated_at }
 */

if (!defined('LW_RELATION_TYPES')) {
    define('LW_RELATION_TYPES', ['好友', '同桌', '队友', '同班', '搭档', '闺蜜', '兄弟']);
}

if (!defined('LW_RELATION_VISIBILITY')) {
    define('LW_RELATION_VISIBILITY', ['public', 'friends', 'private']);
}

if (!function_exists('lwRelationVisibilityLabel')) {
    function lwRelationVisibilityLabel(string $v): string
    {
        $map = ['public' => '所有人可见', 'friends' => '仅登录用户可见', 'private' => '仅自己可见'];
        return $map[$v] ?? '所有人可见';
    }
}

if (!function_exists('lwRelationNormType')) {
    function lwRelationNormType(string $type): string
    {
        $type = trim($type);
        if ($type === '' || mb_strlen($type) > 10) {
            return '好友';
        }
        return $type;
    }
}

if (!function_exists('lwRelationApply')) {
    /**
     * 发起绑定申请。
     * @return array{ok:bool,error:string,status:string}
     */
    function lwRelationApply(int $userId, int $targetId, string $type): array
    {
        if ($userId <= 0 || $targetId <= 0 || $userId === $targetId) {
            return ['ok' => false, 'error' => '不能和自己绑定关系', 'status' => ''];
        }
        $fs = getFS();
        if (!$fs->findById('users', $targetId)) {
            return ['ok' => false, 'error' => '对方不存在', 'status' => ''];
        }
        $type = lwRelationNormType($type);
        $now = date('Y-m-d H:i:s');

        // 已有记录：任意方向
        $existing = null;
        foreach ((array)$fs->read('user_relations') as $r) {
            $a = (int)($r['user_id'] ?? 0);
            $b = (int)($r['target_id'] ?? 0);
            if (($a === $userId && $b === $targetId) || ($a === $targetId && $b === $userId)) {
                $existing = $r;
                break;
            }
        }
        if ($existing) {
            $st = (string)($existing['status'] ?? '');
            if ($st === 'accepted') {
                return ['ok' => false, 'error' => '你们已经绑定关系了', 'status' => $st];
            }
            if ($st === 'pending') {
                // 对方已经申请过 → 视为互相同意，直接建立
                if ((int)($existing['user_id'] ?? 0) === $targetId) {
                    $fs->update('user_relations', (int)$existing['id'], [
                        'status' => 'accepted', 'type' => $type, 'updated_at' => $now,
                    ]);
                    return ['ok' => true, 'error' => '', 'status' => 'accepted'];
                }
                return ['ok' => false, 'error' => '已发送申请，等待对方确认', 'status' => 'pending'];
            }
            // rejected 或其它：重新发起
            $fs->update('user_relations', (int)$existing['id'], [
                'user_id' => $userId, 'target_id' => $targetId, 'type' => $type,
                'status' => 'pending', 'visibility' => 'public',
                'initiator' => 1, 'updated_at' => $now,
            ]);
            return ['ok' => true, 'error' => '', 'status' => 'pending'];
        }

        try {
            $fs->insert('user_relations', [
                'user_id' => $userId,
                'target_id' => $targetId,
                'type' => $type,
                'status' => 'pending',
                'visibility' => 'public',
                'initiator' => 1,
                'ts' => $now,
                'updated_at' => $now,
            ]);
            return ['ok' => true, 'error' => '', 'status' => 'pending'];
        } catch (Exception $e) {
            error_log('[love_wall] 关系申请失败：' . $e->getMessage());
            return ['ok' => false, 'error' => '操作失败，请稍后重试', 'status' => ''];
        }
    }
}

if (!function_exists('lwRelationRespond')) {
    /**
     * 被申请方同意 / 拒绝。
     * @return array{ok:bool,error:string}
     */
    function lwRelationRespond(int $userId, int $fromUserId, bool $accept): array
    {
        $fs = getFS();
        $row = null;
        foreach ((array)$fs->read('user_relations') as $r) {
            if ((int)($r['user_id'] ?? 0) === $fromUserId
                && (int)($r['target_id'] ?? 0) === $userId
                && (string)($r['status'] ?? '') === 'pending') {
                $row = $r;
                break;
            }
        }
        if (!$row) {
            return ['ok' => false, 'error' => '没有待处理的关系申请'];
        }
        try {
            $fs->update('user_relations', (int)$row['id'], [
                'status' => $accept ? 'accepted' : 'rejected',
                'updated_at' => date('Y-m-d H:i:s'),
            ]);
            return ['ok' => true, 'error' => ''];
        } catch (Exception $e) {
            return ['ok' => false, 'error' => '操作失败，请稍后重试'];
        }
    }
}

if (!function_exists('lwRelationRemove')) {
    /**
     * 解除关系（任意一方可单方面解除）。
     */
    function lwRelationRemove(int $userId, int $targetId): bool
    {
        $fs = getFS();
        foreach ((array)$fs->read('user_relations') as $r) {
            $a = (int)($r['user_id'] ?? 0);
            $b = (int)($r['target_id'] ?? 0);
            if (($a === $userId && $b === $targetId) || ($a === $targetId && $b === $userId)) {
                try { $fs->delete('user_relations', (int)$r['id']); } catch (Exception $e) { return false; }
            }
        }
        return true;
    }
}

if (!function_exists('lwRelationSetVisibility')) {
    /**
     * 设置「我对别人的关系」的对外可见性。
     * 只改自己作为发起方的那条记录，不干涉对方的设置。
     */
    function lwRelationSetVisibility(int $userId, int $targetId, string $visibility): bool
    {
        if (!in_array($visibility, LW_RELATION_VISIBILITY, true)) {
            return false;
        }
        $fs = getFS();
        foreach ((array)$fs->read('user_relations') as $r) {
            if ((int)($r['user_id'] ?? 0) === $userId && (int)($r['target_id'] ?? 0) === $targetId) {
                try {
                    $fs->update('user_relations', (int)$r['id'], ['visibility' => $visibility]);
                    return true;
                } catch (Exception $e) {
                    return false;
                }
            }
        }
        return false;
    }
}

if (!function_exists('lwUserRelations')) {
    /**
     * 取某用户已建立的关系，并按**查看者**过滤可见性。
     *
     * @param int      $profileUserId 主页主人
     * @param int|null $viewerId      查看者（游客为 null）
     * @param bool     $isSelf        是否本人查看
     * @return array 关系列表（含对方昵称/头像）
     */
    function lwUserRelations(int $profileUserId, ?int $viewerId, bool $isSelf = false): array
    {
        $fs = getFS();
        $users = [];
        foreach ((array)$fs->read('users') as $u) {
            if (isset($u['id'])) {
                $users[(int)$u['id']] = $u;
            }
        }
        $out = [];
        foreach ((array)$fs->read('user_relations') as $r) {
            if ((string)($r['status'] ?? '') !== 'accepted') continue;
            $a = (int)($r['user_id'] ?? 0);
            $b = (int)($r['target_id'] ?? 0);
            if ($a !== $profileUserId && $b !== $profileUserId) continue;
            $peerId = ($a === $profileUserId) ? $b : $a;

            // 可见性：主人对外的视角由「主人作为发起方那条」的 visibility 决定；
            // 双方各有一条记录时，任一条可见即展示（更符合直觉）。
            $vis = (string)($r['visibility'] ?? 'public');
            if (!$isSelf) {
                if ($vis === 'private') continue;
                if ($vis === 'friends' && !$viewerId) continue;
            }

            $peer = $users[$peerId] ?? null;
            $out[] = [
                'user_id' => $peerId,
                'nickname' => (string)($peer['nickname'] ?? ('用户' . $peerId)),
                'avatar' => (string)($peer['avatar'] ?? ''),
                'type' => (string)($r['type'] ?? '好友'),
                'visibility' => $vis,
                'visibility_label' => lwRelationVisibilityLabel($vis),
                'ts' => (string)($r['ts'] ?? ''),
            ];
        }
        // 去重（双方各一条时只展示一次）
        $seen = [];
        $uniq = [];
        foreach ($out as $o) {
            if (isset($seen[$o['user_id']])) continue;
            $seen[$o['user_id']] = true;
            $uniq[] = $o;
        }
        return $uniq;
    }
}

if (!function_exists('lwRelationPending')) {
    /** 待我确认的申请列表。 */
    function lwRelationPending(int $userId): array
    {
        $fs = getFS();
        $users = [];
        foreach ((array)$fs->read('users') as $u) {
            if (isset($u['id'])) $users[(int)$u['id']] = $u;
        }
        $out = [];
        foreach ((array)$fs->read('user_relations') as $r) {
            if ((int)($r['target_id'] ?? 0) !== $userId) continue;
            if ((string)($r['status'] ?? '') !== 'pending') continue;
            $fromId = (int)($r['user_id'] ?? 0);
            $from = $users[$fromId] ?? null;
            $out[] = [
                'user_id' => $fromId,
                'nickname' => (string)($from['nickname'] ?? ('用户' . $fromId)),
                'avatar' => (string)($from['avatar'] ?? ''),
                'type' => (string)($r['type'] ?? '好友'),
                'ts' => (string)($r['ts'] ?? ''),
            ];
        }
        return $out;
    }
}
