<?php
/**
 * 每日签到
 *
 * 调用方：api/checkin.php（页面）、api/ai_action.php（AI 确认卡片）
 * 入参：无（签到对象恒为当前用户）
 *
 * 签到状态以服务端 checkins 表为唯一可信来源，前端不使用 localStorage，
 * 因此同一账号在任意设备、任意页面看到的签到状态都一致。
 *
 * 说明：旧接口用 action=status 查询、action=checkin 执行；本内核只负责「执行签到」，
 * 状态查询由 api/checkin.php 自己算（它需要 checkinState() 这个输出结构）。
 */
require_once __DIR__ . '/_lib.php';

if (!function_exists('lw_checkinState')) {
    /** 组装签到状态（api/checkin.php 与 AI 数据层共用同一口径） */
    function lw_checkinState($record, string $today = ''): array
    {
        if ($today === '') {
            $today = date('Y-m-d');
        }
        $last = (string)($record['last_date'] ?? '');
        return [
            'checked_today' => $last === $today,
            'streak'        => (int)($record['streak'] ?? 0),
            'total'         => (int)($record['total'] ?? 0),
            'last_date'     => $last,
            'today'         => $today,
        ];
    }
}

if (!function_exists('lw_do_checkin')) {
    /**
     * 执行签到。幂等：今天已签到时直接返回当前状态与「今日已签到」，不重复累加。
     */
    function lw_do_checkin(array $user, array $in = []): array
    {
        $today = date('Y-m-d');
        $fs = getFS();

        // D30：签到总开关（checkin_rules.enabled）。关闭后拒绝签到，状态查询不受影响。
        $ckRules = json_decode((string) getSetting('checkin_rules', ''), true);
        if (is_array($ckRules) && isset($ckRules['enabled']) && $ckRules['enabled'] == 0) {
            return lwActionFail('签到功能已关闭', 403);
        }

        $rows = $fs->find('checkins', ['user_id' => $user['id']]);

        // 历史重复行兜底：只保留最早的一行，其余清掉，避免状态读取歧义
        if (count($rows) > 1) {
            for ($i = 1; $i < count($rows); $i++) {
                $fs->delete('checkins', $rows[$i]['id']);
            }
            $rows = [$rows[0]];
        }
        $record = $rows ? $rows[0] : null;

        if ($record && (string)$record['last_date'] === $today) {
            return lwActionResult(true, '今日已签到', lw_checkinState($record, $today));
        }

        // 连续天数：昨天签过则累加，否则从 1 重新开始
        $yesterday = date('Y-m-d', strtotime('-1 day'));
        $lastDate = $record ? (string)($record['last_date'] ?? '') : '';
        $newStreak = ($lastDate === $yesterday) ? (int)$record['streak'] + 1 : 1;
        $newTotal = ($record ? (int)$record['total'] : 0) + 1;

        $payload = [
            'user_id'   => $user['id'],
            'last_date' => $today,
            'streak'    => $newStreak,
            'total'     => $newTotal,
        ];

        if ($record) {
            if (!$fs->update('checkins', $record['id'], $payload)) {
                return lwActionFail('签到失败，请稍后重试', 500);
            }
            $payload['id'] = $record['id'];
        } else {
            $created = $fs->insert('checkins', $payload);
            if (!$created) {
                return lwActionFail('签到失败，请稍后重试', 500);
            }
            $payload = $created;
        }

        $state = lw_checkinState($payload, $today);
        // 成长体系：签到得经验（幂等分支上面已提前 return，不会重复发）
        lwGrowthAward((int)$user['id'], 'checkin');
        // 文案保持与旧接口一致（前端会直接展示这句）
        return lwActionResult(true, '签到成功', $state);
    }
}
