<?php
/**
 * 帖子可见性判定 —— 唯一来源
 *
 * 背景：同一套「谁能看到这条帖子」的规则原先在三个地方各写了一遍，且已经各不相同：
 *   - api/posts/list.php      首页信息流（主口径）
 *   - api/search/quick.php    搜索框实时联想
 *   - includes/ai_site_data.php  AI 助手的数据接入
 * 差异表现为「作者能否看到自己未过审的帖子」「作者是否豁免可见范围限制」两点不一致，
 * 会导致「站内搜不到但 AI 搜到了」「作者在自己的帖子列表里看得到、搜索里搜不到」这类诡异现象。
 * 这里收敛为一份，三处都改调本文件，从此不可能再分叉。
 *
 * 口径（以首页信息流 list.php 的既有行为为准，因为它是最主要的用户可见入口）：
 *   1) 管理员 / 超级管理员：全部可见（含未过审）。
 *   2) 作者本人：自己的帖子全部可见（含 pending / rejected，个人中心要显示「审核中」）。
 *   3) 其他登录用户：仅 published，且需通过可见范围（public / visible_to 白名单 / exclude_to 黑名单）。
 *   4) 游客（$user === null）：仅 published，且只能过 public 的帖子
 *      （visible_to / exclude_to 依赖 qq 判定，游客没有 qq，一律不可见）。
 *
 * 注意：状态值只有 published / pending / rejected 三种（见 api/posts/create.php:157、
 * api/posts/edit.php:105、api/admin/posts.php:105,121）。
 */

if (!function_exists('lwIsAdminUser')) {
    /** 是否为管理员角色 */
    function lwIsAdminUser(?array $user)
    {
        return $user && in_array($user['role'] ?? '', ['admin', 'super_admin'], true);
    }
}

if (!function_exists('lwPostIsAuthor')) {
    /** 当前用户是否为该帖作者 */
    function lwPostIsAuthor(array $post, ?array $user)
    {
        return $user && (int)($user['id'] ?? 0) > 0
            && (int)($post['user_id'] ?? 0) === (int)$user['id'];
    }
}

if (!function_exists('lwPostVisibilityAllows')) {
    /**
     * 只看可见范围（不看状态、不看作者），判断该用户是否在这条帖子的可见名单里。
     * $user 为 null（游客）时只有 public 通过。
     */
    function lwPostVisibilityAllows(array $post, ?array $user)
    {
        $vis = (string)($post['visibility'] ?? 'public');
        if ($vis === 'public' || $vis === '') {
            return true;
        }
        if (!$user) {
            return false;
        }
        $myQq = (string)($user['qq'] ?? '');
        if ($myQq === '') {
            return false;
        }
        if ($vis === 'visible_to') {
            $allowed = array_map('trim', explode(',', (string)($post['visible_to'] ?? '')));
            return in_array($myQq, $allowed, true);
        }
        if ($vis === 'exclude_to') {
            $excluded = array_map('trim', explode(',', (string)($post['exclude_to'] ?? '')));
            return !in_array($myQq, $excluded, true);
        }
        // 未知的可见性取值：按最严格处理，只有作者/管理员能看（由调用方判定）
        return false;
    }
}

if (!function_exists('lwPostVisible')) {
    /**
     * 判断单条帖子对当前用户（可为 null = 游客）是否可见。
     */
    function lwPostVisible(array $post, ?array $user)
    {
        if (lwIsAdminUser($user)) {
            return true;
        }
        if (lwPostIsAuthor($post, $user)) {
            return true;
        }
        if ((string)($post['status'] ?? 'published') !== 'published') {
            return false;
        }
        return lwPostVisibilityAllows($post, $user);
    }
}

if (!function_exists('lwFilterVisiblePosts')) {
    /**
     * 过滤出当前用户可见的帖子。
     *
     * @param array    $posts      原始帖子数组
     * @param array|null $user     当前用户；null 表示游客
     * @param int      $guestLimit 仅对游客生效的条数上限。默认 0 = 不截断
     *                             （调用方通常自己已有 slice/limit 逻辑，这里再截一次会重复）。
     *                             非游客忽略此项。
     * @return array 已重排索引的数组
     */
    function lwFilterVisiblePosts(array $posts, ?array $user, int $guestLimit = 0)
    {
        $out = [];
        foreach ($posts as $p) {
            if (!is_array($p)) {
                continue;
            }
            if (lwPostVisible($p, $user)) {
                $out[] = $p;
            }
        }
        if ($user === null && $guestLimit > 0) {
            $out = array_slice($out, 0, $guestLimit);
        }
        return $out;
    }
}
