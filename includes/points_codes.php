<?php
/**
 * 积分兑换码（需求二、5：后台支持批量生成积分兑换码）
 * ---------------------------------------------------------------------------
 * 设计要点：
 *   - 码格式 `LW` + 12 位大写字母数字（去掉易混淆的 0/O/1/I/L），可口头转述；
 *   - 一码一用：redeemed_by / redeemed_at 落库，重复兑换直接拒绝；
 *   - 批次号（batch）便于后台按批统计与作废；
 *   - 只存码的明文（不是密码凭证，泄露等同于"钱"，但管理员本就能改积分，
 *     所以不做不可逆哈希 —— 否则后台无法查码、无法补发）。
 */

if (!defined('LW_CODE_CHARSET')) {
    define('LW_CODE_CHARSET', 'ABCDEFGHJKMNPQRSTUVWXYZ23456789');
}

if (!function_exists('lwPointsCodeGen')) {
    /** 生成一个随机码（LW + 12 位）。 */
    function lwPointsCodeGen(): string
    {
        $cs = LW_CODE_CHARSET;
        $len = strlen($cs);
        $s = '';
        for ($i = 0; $i < 12; $i++) {
            $s .= $cs[random_int(0, $len - 1)];
        }
        return 'LW' . $s;
    }
}

if (!function_exists('lwPointsCodeBatch')) {
    /**
     * 批量生成兑换码。
     *
     * @param int    $count    数量（1-500）
     * @param int    $points   每码积分
     * @param int    $adminId  操作人
     * @param string $note     备注
     * @return array{ok:bool,error:string,codes:array,batch:string}
     */
    function lwPointsCodeBatch(int $count, int $points, int $adminId, string $note = ''): array
    {
        if ($count < 1 || $count > 500) {
            return ['ok' => false, 'error' => '数量需在 1-500 之间', 'codes' => [], 'batch' => ''];
        }
        if ($points < 1 || $points > 100000) {
            return ['ok' => false, 'error' => '单码积分需在 1-100000 之间', 'codes' => [], 'batch' => ''];
        }

        $fs = getFS();
        // 现有码集合，用于去重
        $used = [];
        foreach ((array)$fs->read('points_codes') as $r) {
            $used[(string)($r['code'] ?? '')] = true;
        }

        $batch = 'B' . date('YmdHis') . '-' . substr(bin2hex(random_bytes(3)), 0, 6);
        $made = [];
        $tries = 0;
        while (count($made) < $count && $tries < $count * 20) {
            $tries++;
            $code = lwPointsCodeGen();
            if (isset($used[$code])) {
                continue;
            }
            $used[$code] = true;
            try {
                $fs->insert('points_codes', [
                    'code'        => $code,
                    'points'      => $points,
                    'batch'       => $batch,
                    'note'        => mb_substr($note, 0, 80),
                    'created_by'  => $adminId,
                    'redeemed_by' => 0,
                    'redeemed_at' => 0,
                    'ts'          => time(),
                ]);
                $made[] = $code;
            } catch (Exception $e) {
                // 单条失败跳过，继续造剩下的
            }
        }

        if (!$made) {
            return ['ok' => false, 'error' => '生成失败，请重试', 'codes' => [], 'batch' => ''];
        }
        return ['ok' => true, 'error' => '', 'codes' => $made, 'batch' => $batch];
    }
}

if (!function_exists('lwPointsCodeRedeem')) {
    /**
     * 用户兑换一个码。
     * @return array{ok:bool,error:string,points:int,balance:int}
     */
    function lwPointsCodeRedeem(int $userId, string $rawCode): array
    {
        $code = strtoupper(trim($rawCode));
        // 容错：允许用户带空格/连字符输入
        $code = preg_replace('/[\s\-]+/', '', $code) ?? '';
        if ($code === '' || strlen($code) < 6 || strlen($code) > 24) {
            return ['ok' => false, 'error' => '兑换码格式不正确', 'points' => 0, 'balance' => 0];
        }

        $fs = getFS();
        $row = $fs->findOne('points_codes', ['code' => $code]);
        if (!$row) {
            return ['ok' => false, 'error' => '兑换码不存在', 'points' => 0, 'balance' => 0];
        }
        if ((int)($row['redeemed_by'] ?? 0) > 0) {
            return ['ok' => false, 'error' => '该兑换码已被使用', 'points' => 0, 'balance' => 0];
        }

        $points = (int)($row['points'] ?? 0);
        if ($points <= 0) {
            return ['ok' => false, 'error' => '兑换码无效', 'points' => 0, 'balance' => 0];
        }

        // 先占位再入账：并发下若两人同时提交，只有 update 成功的那次能继续
        try {
            $fs->update('points_codes', (int)$row['id'], [
                'redeemed_by' => $userId,
                'redeemed_at' => time(),
            ]);
        } catch (Exception $e) {
            return ['ok' => false, 'error' => '兑换失败，请重试', 'points' => 0, 'balance' => 0];
        }

        // 复查：确保这次 update 之后这行确实记的是自己（防并发串号）
        $check = $fs->findById('points_codes', (int)$row['id']);
        if (!$check || (int)($check['redeemed_by'] ?? 0) !== $userId) {
            return ['ok' => false, 'error' => '该兑换码已被使用', 'points' => 0, 'balance' => 0];
        }

        if (!lwPointsAdd($userId, $points, '兑换码充值', 'code:' . $code)) {
            // 入账失败要回滚占位，否则码白白作废
            try {
                $fs->update('points_codes', (int)$row['id'], ['redeemed_by' => 0, 'redeemed_at' => 0]);
            } catch (Exception $e) {}
            return ['ok' => false, 'error' => '入账失败，请重试', 'points' => 0, 'balance' => 0];
        }

        return [
            'ok' => true,
            'error' => '',
            'points' => $points,
            'balance' => lwUserPoints($userId),
        ];
    }
}
