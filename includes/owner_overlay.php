<?php
/**
 * 站长账号的展示值覆盖（粉丝数 / 等级 / 积分）
 *
 * =====================================================================
 * 为什么需要这个文件
 * =====================================================================
 * 站长的粉丝数写的是「91.78 亿」、等级 100、积分 ∞ —— 这些是**荣誉数字**，
 * 不是真实统计。真实关注记录只有几十条，不可能真有九亿人来点关注。
 *
 * 如果直接把 follows.json 塞满九亿条，先不说磁盘，count() 一次就卡死。
 * 所以做法是：**读的时候覆盖，不碰真实数据**。
 *
 * =====================================================================
 * 优先级
 * =====================================================================
 *   users 表上的显式字段（owner_followers / owner_level / owner_points）
 *      ↓ 有就用
 *   默认值（91.78亿 / 100 / ∞）
 *      ↓ 没有就用
 *   真实统计
 *
 * 这样站长可以在后台改数字，也可以某天关掉覆盖回到真实值。
 * =====================================================================
 */

if (!defined('LW_OWNER_OVERLAY_LOADED')) {
    define('LW_OWNER_OVERLAY_LOADED', 1);

    /** 站长 QQ —— 与站点档案里的一致。 */
    if (!defined('LW_OWNER_QQ')) {
        define('LW_OWNER_QQ', '1740443398');
    }

    /** 粉丝数默认展示值：91.78 亿 */
    if (!defined('LW_OWNER_FOLLOWERS')) {
        define('LW_OWNER_FOLLOWERS', 9178000000);
    }

    /** 等级默认展示值 */
    if (!defined('LW_OWNER_LEVEL')) {
        define('LW_OWNER_LEVEL', 100);
    }

    /**
     * 这个用户是不是站长。
     */
    function lwIsOwner($user): bool
    {
        if (!is_array($user)) {
            return false;
        }
        return (string)($user['qq'] ?? '') === LW_OWNER_QQ;
    }

    /**
     * 取站长覆盖值。
     *
     * @param array  $user 用户记录
     * @param string $key  followers | level | points
     * @return mixed|null 不是站长或没配置时返回 null（表示「用真实统计」）
     */
    function lwOwnerOverlay($user, string $key)
    {
        if (!lwIsOwner($user)) {
            return null;
        }

        // 后台可逐项覆盖
        $map = [
            'followers' => 'owner_followers',
            'level'     => 'owner_level',
            'points'    => 'owner_points',
        ];
        $field = $map[$key] ?? null;
        if ($field !== null && isset($user[$field]) && $user[$field] !== '') {
            return $user[$field];
        }

        // 没有显式配置就用默认值
        switch ($key) {
            case 'followers':
                return LW_OWNER_FOLLOWERS;
            case 'level':
                return LW_OWNER_LEVEL;
            case 'points':
                return '∞';
        }
        return null;
    }

    /**
     * 统一取「粉丝数」。
     *
     * 所有页面的粉丝数都应该走这里，避免有的地方显示 91.78 亿、
     * 有的地方显示真实的 3 —— 那种不一致比不显示还糟。
     *
     * @param array $fs       文件存储
     * @param array $target   被查看的用户
     * @return int|string     真实数字，或站长的覆盖值
     */
    function lwFollowerCount($fs, $target)
    {
        $overlay = lwOwnerOverlay($target, 'followers');
        if ($overlay !== null) {
            return $overlay;
        }
        $id = (int)($target['id'] ?? 0);
        if ($id <= 0) {
            return 0;
        }
        return count((array)$fs->find('follows', ['target_id' => $id]));
    }

    /**
     * 统一取「关注数」（站长关注了多少人）。
     * 这个不覆盖 —— 站长关注的是真实的人。
     */
    function lwFollowingCount($fs, $target): int
    {
        $id = (int)($target['id'] ?? 0);
        if ($id <= 0) {
            return 0;
        }
        return count((array)$fs->find('follows', ['user_id' => $id]));
    }

    /**
     * 统一取「等级」。
     *
     * 站长的等级是荣誉性质，不走经验公式（100 级需要的经验是天文数字）。
     */
    function lwUserLevel($user, ?int $exp = null): int
    {
        $overlay = lwOwnerOverlay($user, 'level');
        if ($overlay !== null) {
            return (int)$overlay;
        }
        if ($exp === null) {
            $exp = (int)($user['exp'] ?? ($user['experience'] ?? 0));
        }
        if (function_exists('lwLevelFromExp')) {
            return (int)lwLevelFromExp((int)$exp);
        }
        return 1;
    }

    /**
     * 等级对应的段位名。
     *
     * 站长 100 级超出了段位表的上限（25 级「传奇」），
     * 所以给他一个专属称号，而不是硬套「传奇」。
     */
    function lwUserLevelTitle($user, ?int $level = null): string
    {
        if (lwIsOwner($user)) {
            return '站长';
        }
        if ($level === null) {
            $level = lwUserLevel($user);
        }
        if (function_exists('lwLevelTitle')) {
            return (string)lwLevelTitle((int)$level);
        }
        return '';
    }

    /**
     * 统一取「积分」。
     *
     * 站长的积分显示为 ∞ —— 符号而不是数字，
     * 因为任何具体数字都会被人拿去比较「我离站长还差多少」。
     */
    function lwUserPoints($user)
    {
        $overlay = lwOwnerOverlay($user, 'points');
        if ($overlay !== null) {
            return $overlay;
        }
        return $user['points'] ?? 0;
    }

    /**
     * 数字美化：9178000000 -> "91.78亿"
     *
     * 九位数字直接显示会占满一行，而且在手机上会被截断。
     * 中文习惯用「亿」，英文用「B」。
     */
    function lwFormatCount($n, string $lang = ''): string
    {
        if ($n === '∞' || !is_numeric($n)) {
            return (string)$n;
        }
        $n = (float)$n;
        if ($n < 10000) {
            return (string)(int)$n;
        }
        if ($lang === '' && function_exists('currentLang')) {
            $lang = (string)currentLang();
        }
        $isCn = ($lang === '' || $lang === 'zh_CN' || $lang === 'zh_TW');

        if ($n >= 100000000) {
            $v = $n / 100000000;
            $s = rtrim(rtrim(number_format($v, 2, '.', ''), '0'), '.');
            return $isCn ? ($s . '亿') : ($s . 'B');
        }
        if ($n >= 10000) {
            $v = $n / 10000;
            $s = rtrim(rtrim(number_format($v, 1, '.', ''), '0'), '.');
            return $isCn ? ($s . '万') : ($s . 'K');
        }
        return (string)(int)$n;
    }

    /**
     * 新用户默认关注站长。
     *
     * 站长是唯一会发全站公告的账号（版本更新、规则调整、故障说明）。
     * 新用户不关注他，就收不到任何官方信息 —— 这不是「涨粉」，
     * 是让新人有渠道知道站点发生了什么。
     *
     * 幂等：已经关注过就什么都不做；站长自己注册也不关注自己。
     */
    function lwDefaultFollowOwner(int $newUserId): bool
    {
        if ($newUserId <= 0) {
            return false;
        }
        if (!function_exists('getFS')) {
            return false;
        }

        $fs = getFS();
        $owner = null;
        foreach ((array)$fs->read('users') as $u) {
            if ((string)($u['qq'] ?? '') === LW_OWNER_QQ) {
                $owner = $u;
                break;
            }
        }
        if (!$owner) {
            return false;
        }
        $ownerId = (int)($owner['id'] ?? 0);
        if ($ownerId <= 0 || $ownerId === $newUserId) {
            return false;
        }

        // 已经关注过就不重复插
        $exists = $fs->findOne('follows', ['user_id' => $newUserId, 'target_id' => $ownerId]);
        if ($exists) {
            return false;
        }

        $now = date('Y-m-d H:i:s');
        $fs->insert('follows', [
            'user_id'    => $newUserId,
            'target_id'  => $ownerId,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        return true;
    }

}
