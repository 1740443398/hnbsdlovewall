<?php
/**
 * 私信偏好（免打扰 / 会话置顶）—— 唯一来源
 *
 * 数据表 pm_settings：**每用户一行**，两个映射字段：
 *   muted  : { "对方用户id": 设置时间戳 }   免打扰 —— 对方来信不再写入通知中心
 *   pinned : { "对方用户id": 设置时间戳 }   置顶   —— 会话列表排在最前
 *
 * 为什么用「每用户一行 + 两个映射」而不是「一条设置一行」：
 *   会话级设置天然是「按人查询、频繁切换」的读多写少场景，整行读一次就能拿到全部偏好，
 *   不用扫全表。本站单用户的会话数很小（几十个），整行重写代价可忽略。
 *
 * 与 pm_read（已读游标）的关系：pm_read 记录「读到哪条」，本表只管「显示与提醒的偏好」，
 * 两者职责不重叠，互不覆盖。
 *
 * 本文件只声明函数，**不要 require config/config.php**。
 */

if (!defined('LW_PM_PREF_LIB')) {
    define('LW_PM_PREF_LIB', 1);
}

if (!function_exists('lwPmKey')) {
    /** 会话键：与既有 pm_messages.key 口径一致 —— 两个用户 id 取小|大。 */
    function lwPmKey(int $a, int $b): string
    {
        return min($a, $b) . '|' . max($a, $b);
    }
}

if (!function_exists('lwPmPrefs')) {
    /**
     * 读取私信偏好（无记录返回空结构，不落库）。
     * @return array{row_id:mixed, muted:array, pinned:array}
     */
    function lwPmPrefs(int $userId): array
    {
        if ($userId <= 0) {
            return ['row_id' => null, 'muted' => [], 'pinned' => []];
        }
        $row = getFS()->findOne('pm_settings', ['user_id' => $userId]);
        return [
            'row_id' => $row ? $row['id'] : null,
            'muted'  => ($row && is_array($row['muted'] ?? null)) ? $row['muted'] : [],
            'pinned' => ($row && is_array($row['pinned'] ?? null)) ? $row['pinned'] : [],
        ];
    }
}

if (!function_exists('lwPmSetFlag')) {
    /**
     * 开关某个偏好标记。
     *
     * @param int    $userId  设置的人
     * @param int    $peerId  会话对方
     * @param string $flag    'muted' 或 'pinned'
     * @param bool   $on      true 开启 / false 关闭
     * @return array{ok:bool, message:string, on:bool}
     */
    function lwPmSetFlag(int $userId, int $peerId, string $flag, bool $on): array
    {
        if ($userId <= 0 || $peerId <= 0) {
            return ['ok' => false, 'message' => '参数不合法', 'on' => false];
        }
        if ($userId === $peerId) {
            return ['ok' => false, 'message' => '不能对自己设置', 'on' => false];
        }
        if (!in_array($flag, ['muted', 'pinned'], true)) {
            return ['ok' => false, 'message' => '不支持的设置项', 'on' => false];
        }

        $fs = getFS();
        $prefs = lwPmPrefs($userId);
        $map = $prefs[$flag];
        $key = (string)$peerId;

        if ($on) {
            $map[$key] = time();
        } else {
            unset($map[$key]);
        }

        $payload = ['user_id' => $userId, $flag => $map];
        $saved = $prefs['row_id']
            ? $fs->update('pm_settings', $prefs['row_id'], [$flag => $map])
            : (bool)$fs->insert('pm_settings', $payload);

        if (!$saved) {
            return ['ok' => false, 'message' => '设置保存失败，请稍后重试', 'on' => false];
        }

        $labels = ['muted' => '免打扰', 'pinned' => '置顶'];
        return [
            'ok'      => true,
            'message' => $labels[$flag] . ($on ? '已开启' : '已关闭'),
            'on'      => $on,
        ];
    }
}

if (!function_exists('lwPmIsMuted')) {
    /** 我对某会话是否开启了免打扰 */
    function lwPmIsMuted(int $userId, int $peerId): bool
    {
        $prefs = lwPmPrefs($userId);
        return isset($prefs['muted'][(string)$peerId]);
    }
}

if (!function_exists('lwPmIsPinned')) {
    /** 我是否置顶了某会话 */
    function lwPmIsPinned(int $userId, int $peerId): bool
    {
        $prefs = lwPmPrefs($userId);
        return isset($prefs['pinned'][(string)$peerId]);
    }
}

if (!function_exists('lwPmShouldNotify')) {
    /**
     * 判断「$peerId 给 $userId 发私信」时是否应写入通知中心。
     * 免打扰开启时**仍然收消息**（打开会话能看到），只是不推通知 —— 这才是「免打扰」的本意。
     */
    function lwPmShouldNotify(int $userId, int $peerId): bool
    {
        if (lwPmIsMuted($userId, $peerId)) {
            return false;
        }
        return true;
    }
}

if (!function_exists('lwPmDecoratePrefs')) {
    /**
     * 给会话列表批量附加 muted / pinned 标记，并按「置顶优先 + 原有时间序」排序。
     *
     * @param array $list  会话数组，元素需含 'peer_id' 与用于排序的时间字段
     * @param int   $userId
     * @param string $timeField 排序用的时间字段名（默认 'last_time'，取不到则保持原顺序）
     */
    function lwPmDecoratePrefs(array $list, int $userId, string $timeField = 'last_time'): array
    {
        $prefs = lwPmPrefs($userId);
        foreach ($list as &$it) {
            $pid = (string)($it['peer_id'] ?? '');
            $it['muted']  = isset($prefs['muted'][$pid]);
            $it['pinned'] = isset($prefs['pinned'][$pid]);
        }
        unset($it);

        // 置顶在前；同组内保持原有顺序（用稳定排序：先记录原下标）
        $order = [];
        foreach ($list as $i => $it) {
            $order[$i] = $it;
        }
        uksort($order, function ($a, $b) use ($list) {
            $pa = !empty($list[$a]['pinned']) ? 0 : 1;
            $pb = !empty($list[$b]['pinned']) ? 0 : 1;
            if ($pa !== $pb) {
                return $pa <=> $pb;
            }
            return $a <=> $b;
        });
        return array_values($order);
    }
}
