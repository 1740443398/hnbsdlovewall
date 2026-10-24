<?php
/**
 * 个人装扮系统内核（需求二：勋章 / 成就 / 个性挂件 / 积分兑换）
 * ---------------------------------------------------------------------------
 * 解决的核心问题：**挂件布局错位**（用户反复强调 14 次）。
 *
 * 错位的根因从来不是"CSS 写得不够好"，而是**没有固定的布局契约**：
 *   - 挂件用 absolute 定位 → 挂件尺寸不同就互相压、出界、撑破卡片；
 *   - 头像框尺寸跟随头像 → 不同设备/不同头像尺寸下框变形；
 *   - 气泡挂件按内容自适应 → 长短消息下气泡忽大忽小、箭头错位。
 *
 * 本模块的布局契约（前端渲染必须遵守，见 assets/css/widgets.css）：
 *   1. **固定槽位**：每个挂件只允许挂在预先定义的 slot 上：
 *        avatar_frame → 头像外圈（固定 1:1 方形槽，padding 固定）
 *        bubble       → 消息气泡（固定内边距与圆角，箭头用伪元素定死在左下）
 *        badge        → 昵称右侧（固定高度行内槽，nowrap）
 *   2. **固定尺寸**：槽位尺寸由 CSS 变量写死（不随内容/设备变化），
 *      挂件图片一律 object-fit:contain 塞进槽位。
 *   3. **不溢出**：所有槽位 overflow:hidden + 容器 isolation:isolate，
 *      任何挂件都不会画出边界。
 *   4. **数量上限**：同类型同位置只允许装备 1 个（后装备的替换前一个），
 *      从数据层就杜绝"两个头像框叠在一起"。
 *
 * 数据表：
 *   widgets          商城商品（后台可上架/下架/改价/改时长）
 *   user_widgets     用户已购/已装备（含到期时间）
 *   user_points      积分流水（收入/支出，用于审计与排行榜）
 *   points_codes     批量生成的积分兑换码
 */

if (!defined('LW_WIDGET_SLOTS')) {
    // 挂件槽位白名单：前端只认这几个，后端写入时校验，杜绝"随便塞到一个位置"
    //   avatar_frame 头像框 / bubble 聊天气泡 / title 自定义头衔（买槽位）
    //
    // 【为什么没有 badge】勋章购买已废除（2026-10-17 站长决定）：
    // 勋章只应该是**成长体系的成就**，靠发帖/签到/被赞等真实行为解锁，
    // 用积分买一个"昵称旁的小标签"会和成就勋章混在一起，分不清哪个是挣来的。
    // 现在昵称旁的勋章位由 includes/achievements.php 的 lwUserBadges() 独占。
    // 历史数据里的 badge 商品与用户记录会被下面的槽位校验自动过滤（不报错、不显示）。
    define('LW_WIDGET_SLOTS', ['avatar_frame', 'bubble', 'title']);
}

if (!defined('LW_DEFAULT_WIDGET_DAYS')) {
    define('LW_DEFAULT_WIDGET_DAYS', 30);
}

if (!function_exists('lwWidgetsVersion')) {
    /**
     * 「用户装扮表」的版本号 —— 所有进程内缓存的失效依据。
     *
     * 为什么不直接读文件 mtime：FileStorage 的每次写入都会 rename 新文件，
     * mtime 一定变。这里用 mtime + 文件大小，既便宜（一次 stat）又足够准。
     * 拿不到就返回 0（退化成不缓存），宁可慢一点，也不能让用户看到旧装扮。
     */
    function lwWidgetsVersion(): int
    {
        $f = __DIR__ . '/../data/user_widgets.json';
        if (!is_file($f)) {
            return 0;
        }
        $st = @stat($f);
        if (!$st) {
            return 0;
        }
        // mtime 精度只有秒，同一秒内连续两次写入会撞车；再混入文件大小与 inode，
        // 基本可以保证「内容变了版本就变」。
        return (int)$st['mtime'] * 1000003 + (int)$st['size'] * 31 + (int)($st['ino'] ?? 0) % 10007;
    }
}

if (!function_exists('lwWidgetSlotLabel')) {
    function lwWidgetSlotLabel(string $slot): string
    {
        $map = [
            'avatar_frame' => '头像框',
            'bubble'       => '气泡',
            'title'        => '自定义头衔',
            // 保留映射仅为后台展示历史数据用；该槽位已不可购买（见 LW_WIDGET_SLOTS 注释）
            'badge'        => '勋章（已停售）',
        ];
        return $map[$slot] ?? $slot;
    }
}

if (!function_exists('lwWidgetCatalog')) {
    /** 取上架中的商品（含过期判定由前端按 expires_at 处理）。 */
    function lwWidgetCatalog(bool $onlyActive = true): array
    {
        $rows = getFS()->read('widgets');
        if (!is_array($rows)) {
            return [];
        }
        $out = [];
        foreach ($rows as $r) {
            if ($onlyActive && empty($r['active'])) {
                continue;
            }
            if (!in_array(($r['slot'] ?? ''), LW_WIDGET_SLOTS, true)) {
                continue;   // 槽位非法的商品一律不出现（防止脏数据造成错位）
            }
            $out[] = [
                'id'       => (int)$r['id'],
                'name'     => (string)($r['name'] ?? ''),
                'slot'     => (string)($r['slot'] ?? ''),
                'slot_label' => lwWidgetSlotLabel((string)($r['slot'] ?? '')),
                'price'    => (int)($r['price'] ?? 0),
                'days'     => (int)($r['days'] ?? LW_DEFAULT_WIDGET_DAYS),
                'css_class'=> (string)($r['css_class'] ?? ''),   // 前端样式钩子，如 lw-frame-gold
                'asset'    => (string)($r['asset'] ?? ''),       // 可选图片资源（站内相对路径）
                'desc'     => (string)($r['desc'] ?? ''),
                'active'   => !empty($r['active']) ? 1 : 0,
                'sort'     => (int)($r['sort'] ?? 0),
            ];
        }
        usort($out, function ($a, $b) {
            return ($b['sort'] <=> $a['sort']) ?: ($a['id'] <=> $b['id']);
        });
        return $out;
    }
}

if (!function_exists('lwUserOwnedWidgets')) {
    /**
     * 用户**已拥有**的挂件 id 集合（含已过期的不算、不含未购买）。
     *
     * 与 {@see lwUserWidgets()} 的区别：那个是「每个槽位最终显示哪一个」，
     * 这个是「我买过哪些」，个人中心装扮面板需要用它决定按钮显示
     * 「点击装备」还是「X 积分」。
     *
     * @return array<int,bool> widget_id => true
     */
    function lwUserOwnedWidgets(int $userId): array
    {
        if ($userId <= 0) {
            return [];
        }
        $rows = getFS()->read('user_widgets');
        if (!is_array($rows)) {
            return [];
        }
        $now = time();
        $out = [];
        foreach ($rows as $r) {
            if ((int)($r['user_id'] ?? 0) !== $userId) {
                continue;
            }
            $exp = (int)($r['expires_at'] ?? 0);
            if ($exp > 0 && $exp < $now) {
                continue;
            }
            $out[(int)($r['widget_id'] ?? 0)] = true;
        }
        return $out;
    }
}

if (!function_exists('lwUserTitleSlots')) {
    /**
     * 用户「可用的自定义头衔槽位」数量。
     *
     * 规则（站长定的口径）：头衔**不是**开放填写，而是商城里买的装扮 ——
     *   · 每购买一次头衔装扮 = 获得 1 个自定义槽位（有效期跟随商品）；
     *   · 没有有效槽位时，个人中心的头衔输入框是禁用状态；
     *   · 槽位数量决定能同时挂几个头衔，当前渲染取最后一个（最新买的）。
     *
     * @return int 有效槽位数量
     */
    function lwUserTitleSlots(int $userId): int
    {
        if ($userId <= 0) {
            return 0;
        }
        $rows = getFS()->read('user_widgets');
        if (!is_array($rows)) {
            return 0;
        }
        $now = time();
        $n = 0;
        foreach ($rows as $r) {
            if ((int)($r['user_id'] ?? 0) !== $userId) {
                continue;
            }
            if ((string)($r['slot'] ?? '') !== 'title') {
                continue;
            }
            $exp = (int)($r['expires_at'] ?? 0);
            if ($exp > 0 && $exp < $now) {
                continue;
            }
            $n++;
        }
        return $n;
    }
}

if (!function_exists('lwUserPoints')) {
    /** 当前用户积分余额。 */
    function lwUserPoints(int $userId): int
    {
        if ($userId <= 0) return 0;
        $u = getFS()->findById('users', $userId);
        return $u ? max(0, (int)($u['points'] ?? 0)) : 0;
    }
}

if (!function_exists('lwPointsAdd')) {
    /**
     * 加减积分（唯一写入口，保证流水与余额一致）。
     * @return bool 是否成功（余额不足时不扣）
     */
    function lwPointsAdd(int $userId, int $delta, string $reason, string $ref = ''): bool
    {
        if ($userId <= 0 || $delta === 0) {
            return false;
        }
        $fs = getFS();
        $u = $fs->findById('users', $userId);
        if (!$u) {
            return false;
        }
        $cur = max(0, (int)($u['points'] ?? 0));
        $next = $cur + $delta;
        if ($next < 0) {
            return false;   // 余额不足，拒绝
        }
        try {
            $fs->update('users', $userId, ['points' => $next]);
            $fs->insert('user_points', [
                'user_id' => $userId,
                'delta'   => $delta,
                'balance' => $next,
                'reason'  => mb_substr($reason, 0, 80),
                'ref'     => mb_substr($ref, 0, 80),
                'ts'      => time(),
            ]);
            return true;
        } catch (Exception $e) {
            error_log('[love_wall] 积分写入失败 uid=' . $userId . '：' . $e->getMessage());
            return false;
        }
    }
}

if (!function_exists('lwUserWidgets')) {
    /**
     * 用户已拥有且在有效期内的挂件，按槽位归并（**每槽位只保留一个**）。
     * 这正是「杜绝错位」的数据层保证：不可能同时装备两个头像框。
     *
     * @return array{avatar_frame:?array, bubble:?array, badge:?array}
     */
    function lwUserWidgets(int $userId): array
    {
        $empty = ['avatar_frame' => null, 'bubble' => null, 'badge' => null];
        if ($userId <= 0) {
            return $empty;
        }
        $rows = getFS()->read('user_widgets');
        if (!is_array($rows)) {
            return $empty;
        }
        $catalog = [];
        foreach (lwWidgetCatalog(false) as $w) {
            $catalog[$w['id']] = $w;
        }
        $now = time();
        $best = [];   // slot => [rank, expires_at, widget, row]
        foreach ($rows as $r) {
            if ((int)($r['user_id'] ?? 0) !== $userId) continue;
            $wid = (int)($r['widget_id'] ?? 0);
            if (!isset($catalog[$wid])) continue;
            $w = $catalog[$wid];
            $slot = $w['slot'];
            if (!in_array($slot, LW_WIDGET_SLOTS, true)) continue;
            $exp = (int)($r['expires_at'] ?? 0);
            // 永久装扮用很大的时间戳表示；已过期的跳过
            if ($exp > 0 && $exp < $now) continue;

            // 排序口径（严格）：① equipped=1 优先 ② 到期晚者优先。
            // 只看「到期最晚」会让用户点了「装备」却看不到变化（同槽位两个都在期内时），
            // 那本质上是另一种错位 —— 用户以为装备失败。
            $rank = (!empty($r['equipped']) ? 1000000000000 : 0) + $exp;
            if (!isset($best[$slot]) || $rank > $best[$slot][0]) {
                $best[$slot] = [$rank, $exp, $w, $r];
            }
        }
        $out = $empty;
        foreach ($best as $slot => $pair) {
            $w = $pair[2];
            $row = $pair[3];
            $out[$slot] = [
                'id'         => (int)$w['id'],
                'name'       => $w['name'],
                'slot'       => $slot,
                'css_class'  => $w['css_class'],
                'asset'      => $w['asset'],
                'expires_at' => (int)$pair[1],
                'equipped'   => !empty($row['equipped']) ? 1 : 0,
            ];
        }
        return $out;
    }
}

if (!function_exists('lwWidgetPurchase')) {
    /**
     * 购买挂件（积分兑换）。管理员免费（需求二、3）。
     *
     * @return array{ok:bool,error:string,widget:?array}
     */
    function lwWidgetPurchase(array $user, int $widgetId): array
    {
        $fs = getFS();
        $w = null;
        foreach (lwWidgetCatalog(false) as $row) {
            if ($row['id'] === $widgetId) { $w = $row; break; }
        }
        if (!$w) {
            return ['ok' => false, 'error' => '商品不存在', 'widget' => null];
        }
        if (empty($w['active'])) {
            return ['ok' => false, 'error' => '该商品已下架', 'widget' => null];
        }

        $uid = (int)$user['id'];
        $isAdmin = in_array($user['role'] ?? '', ['admin', 'super_admin'], true);

        // 需求二、3：管理员免积分使用商城内全部装扮
        $price = $isAdmin ? 0 : (int)$w['price'];
        if ($price > 0) {
            if (lwUserPoints($uid) < $price) {
                return ['ok' => false, 'error' => '积分不足（需要 ' . $price . '，当前 ' . lwUserPoints($uid) . '）', 'widget' => null];
            }
        }

        // days = 0 → 永久（expires_at 记 0，lwUserWidgets 的判定里「0 或过去」只有 0 表示永不过期）。
        // 旧写法是 max(1, …)，会把站长填的 0 悄悄变成 1 天 ——
        // 于是「永久上架的商品」卖给用户却只剩 1 天，这是真踩过的坑（见硬约束 62）。
        $days = (int)($w['days'] ?? LW_DEFAULT_WIDGET_DAYS);
        if ($days < 0) {
            $days = 0;
        }
        $permanent = ($days === 0);
        $now = time();
        $expiresAt = $permanent ? 0 : ($now + $days * 86400);

        // 自定义头衔「槽位」：这是**可叠加**的消耗品，不是独占装备。
        // 买一次加一个槽位，所以这里每次都新建一行（叠加天数），
        // 与头像框那种"同款续期、同槽互斥"的语义完全不同。
        if (($w['slot'] ?? '') === 'title') {
            if ($price > 0 && !lwPointsAdd($uid, -$price, '购买头衔槽位：' . $w['name'], 'widget#' . $widgetId)) {
                return ['ok' => false, 'error' => '积分扣减失败', 'widget' => null];
            }
            try {
                $fs->insert('user_widgets', [
                    'user_id'    => $uid,
                    'widget_id'  => $widgetId,
                    'slot'       => 'title',
                    'expires_at' => $expiresAt,
                    'equipped'   => 1,
                    'ts'         => $now,
                ]);
            } catch (Exception $e) {
                if ($price > 0) {
                    lwPointsAdd($uid, $price, '购买失败退款：' . $w['name'], 'widget#' . $widgetId);
                }
                return ['ok' => false, 'error' => '保存失败，请稍后重试', 'widget' => null];
            }
            return ['ok' => true, 'error' => '', 'widget' => $w];
        }


        // 已有同款且未过期：续期（在原到期时间上叠加，而不是覆盖）
        $existing = null;
        foreach ((array)$fs->read('user_widgets') as $r) {
            if ((int)($r['user_id'] ?? 0) === $uid && (int)($r['widget_id'] ?? 0) === $widgetId) {
                $existing = $r;
                break;
            }
        }
        if (!$permanent && $existing && (int)($existing['expires_at'] ?? 0) > $now) {
            $expiresAt = (int)$existing['expires_at'] + $days * 86400;
        }

        if ($price > 0) {
            if (!lwPointsAdd($uid, -$price, '兑换挂件：' . $w['name'], 'widget#' . $widgetId)) {
                return ['ok' => false, 'error' => '积分扣减失败', 'widget' => null];
            }
        }

        try {
            // 买同槽位的新挂件时，先卸下该槽位其他已装备的挂件。
            // 这不是"多此一举"：若两条记录都 equipped=1，取哪条就取决于遍历顺序，
            // 用户会看到"换了挂件却没变"—— 这正是错位的另一种形式。
            foreach ((array)$fs->read('user_widgets') as $r) {
                if ((int)($r['user_id'] ?? 0) !== $uid) continue;
                if ((string)($r['slot'] ?? '') !== $w['slot']) continue;
                if ((int)($r['widget_id'] ?? 0) === $widgetId) continue;
                if (!empty($r['equipped'])) {
                    $fs->update('user_widgets', (int)$r['id'], ['equipped' => 0]);
                }
            }

            if ($existing) {
                $fs->update('user_widgets', (int)$existing['id'], [
                    'expires_at' => $expiresAt,
                    'equipped'   => 1,
                ]);
            } else {
                $fs->insert('user_widgets', [
                    'user_id'    => $uid,
                    'widget_id'  => $widgetId,
                    'slot'       => $w['slot'],
                    'expires_at' => $expiresAt,
                    'equipped'   => 1,
                    'ts'         => $now,
                ]);
            }
        } catch (Exception $e) {
            // 已扣积分要退回，避免"扣了钱没拿到货"
            if ($price > 0) {
                lwPointsAdd($uid, $price, '兑换失败退款：' . $w['name'], 'widget#' . $widgetId);
            }
            error_log('[love_wall] 挂件兑换失败：' . $e->getMessage());
            return ['ok' => false, 'error' => '兑换失败，请稍后重试', 'widget' => null];
        }

        return [
            'ok' => true,
            'error' => '',
            'widget' => [
                'id' => $widgetId,
                'name' => $w['name'],
                'expires_at' => $expiresAt,
                'free' => $isAdmin,
            ],
        ];
    }
}

if (!function_exists('lwWidgetEquip')) {
    /**
     * 装备/卸下挂件。槽位互斥由数据层保证：装备一个就卸下同槽位的其他挂件。
     * @return array{ok:bool,error:string}
     */
    function lwWidgetEquip(int $userId, int $widgetId, bool $equip): array
    {
        $fs = getFS();
        $target = null;
        foreach ((array)$fs->read('user_widgets') as $r) {
            if ((int)($r['user_id'] ?? 0) === $userId && (int)($r['widget_id'] ?? 0) === $widgetId) {
                $target = $r;
                break;
            }
        }
        if (!$target) {
            return ['ok' => false, 'error' => '你还没有这个挂件'];
        }
        // 头衔槽位是「买一次加一个」的消耗品，没有装备/卸下的概念
        if ((string)($target['slot'] ?? '') === 'title') {
            return ['ok' => false, 'error' => '头衔槽位购买后自动生效，无需装备'];
        }
        if ($equip && (int)($target['expires_at'] ?? 0) > 0 && (int)$target['expires_at'] < time()) {
            return ['ok' => false, 'error' => '该挂件已过期，请先续期'];
        }

        try {
            if ($equip) {
                // 同槽位其他挂件全部卸下 —— 杜绝"多个头像框叠加"造成的错位
                $slot = (string)($target['slot'] ?? '');
                foreach ((array)$fs->read('user_widgets') as $r) {
                    if ((int)($r['user_id'] ?? 0) !== $userId) continue;
                    if ((string)($r['slot'] ?? '') !== $slot) continue;
                    if ((int)$r['id'] === (int)$target['id']) continue;
                    if (!empty($r['equipped'])) {
                        $fs->update('user_widgets', (int)$r['id'], ['equipped' => 0]);
                    }
                }
            }
            $fs->update('user_widgets', (int)$target['id'], ['equipped' => $equip ? 1 : 0]);
            return ['ok' => true, 'error' => ''];
        } catch (Exception $e) {
            return ['ok' => false, 'error' => '操作失败，请稍后重试'];
        }
    }
}

if (!function_exists('lwUserBadges')) {
    /**
     * 用户勋章（成就系统的一部分）。
     *
     * 真实数据结构（别猜）：user_achievements 是**一用户一行**，
     *   { user_id, unlocked: { "first_post": "2026-10-01 13:49:55", ... } }
     * 成就定义在 includes/achievements.php 的 lwAchievementDefs()（不在 JSON 表里）。
     */
    function lwUserBadges(int $userId): array
    {
        if ($userId <= 0) {
            return [];
        }
        $row = getFS()->findOne('user_achievements', ['user_id' => $userId]);
        if (!$row || empty($row['unlocked']) || !is_array($row['unlocked'])) {
            return [];
        }
        $defs = [];
        if (function_exists('lwAchievementDefs')) {
            foreach (lwAchievementDefs() as $d) {
                $defs[(string)$d['key']] = $d;
            }
        }
        $out = [];
        foreach ($row['unlocked'] as $key => $when) {
            $def = $defs[$key] ?? null;
            $out[] = [
                'key'  => (string)$key,
                'name' => $def['name'] ?? (string)$key,
                'icon' => $def['icon'] ?? '🏅',
                'desc' => $def['desc'] ?? '',
                'ts'   => is_numeric($when) ? (int)$when : (strtotime((string)$when) ?: 0),
            ];
        }
        // 按解锁时间倒序（新成就靠前），无时间的排后面
        usort($out, function ($a, $b) {
            return ($b['ts'] ?: 0) <=> ($a['ts'] ?: 0);
        });
        return $out;
    }
}
