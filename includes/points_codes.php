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
    /**
     * 批量生成兑换码。
     *
     * @param int    $count    数量（1-500）
     * @param int    $points   每码积分（商品码时可为 0）
     * @param int    $adminId  操作人
     * @param string $note     备注
     * @param int    $widgetId 可选：指定商品 ID。>0 时生成「商品兑换码」，
     *                         兑换后直接把该装扮发给用户，而不是加积分。
     * @return array{ok:bool,error:string,codes:array,batch:string}
     */
    function lwPointsCodeBatch(int $count, int $points, int $adminId, string $note = '', int $widgetId = 0): array
    {
        if ($count < 1 || $count > 500) {
            return ['ok' => false, 'error' => '数量需在 1-500 之间', 'codes' => [], 'batch' => ''];
        }
        // 商品码不要求积分；积分码必须有积分
        if ($widgetId <= 0 && ($points < 1 || $points > 100000)) {
            return ['ok' => false, 'error' => '单码积分需在 1-100000 之间', 'codes' => [], 'batch' => ''];
        }

        // 商品码：校验商品存在
        if ($widgetId > 0) {
            $w = getFS()->findById('widgets', $widgetId);
            if (!$w) {
                return ['ok' => false, 'error' => '指定的商品不存在（ID ' . $widgetId . '）', 'codes' => [], 'batch' => ''];
            }
            $points = 0;   // 商品码不加积分
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
                    'widget_id'   => $widgetId,     // 0 = 积分码，>0 = 商品码
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

        $points   = (int)($row['points'] ?? 0);
        $widgetId = (int)($row['widget_id'] ?? 0);

        // 两种码：积分码（points>0）或商品码（widget_id>0）
        if ($points <= 0 && $widgetId <= 0) {
            return ['ok' => false, 'error' => '兑换码无效', 'points' => 0, 'balance' => 0];
        }

        // 商品码：先确认商品还在
        $widget = null;
        if ($widgetId > 0) {
            $widget = $fs->findById('widgets', $widgetId);
            if (!$widget) {
                return ['ok' => false, 'error' => '该兑换码对应的商品已下架，请联系管理员', 'points' => 0, 'balance' => 0];
            }
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

        // ---- 商品码：直接把装扮发给用户 ----
        if ($widgetId > 0 && $widget) {
            $granted = false;
            $grantErr = '';

            // 优先用现成的发放函数（与商城购买走同一套逻辑）
            if (function_exists('lwGrantWidget')) {
                try {
                    $granted = (bool)lwGrantWidget($userId, $widgetId);
                } catch (Throwable $e) {
                    $grantErr = $e->getMessage();
                }
            }

            // 没有统一函数时，自己写 user_widgets 记录
            if (!$granted) {
                try {
                    $days = (int)($widget['days'] ?? 30);
                    $expire = $days > 0 ? date('Y-m-d H:i:s', time() + $days * 86400) : null;

                    $existing = $fs->findOne('user_widgets', [
                        'user_id' => $userId,
                        'widget_id' => $widgetId,
                    ]);
                    if ($existing) {
                        $fs->update('user_widgets', (int)$existing['id'], [
                            'expires_at' => $expire,
                            'active' => 1,
                        ]);
                    } else {
                        $fs->insert('user_widgets', [
                            'user_id'    => $userId,
                            'widget_id'  => $widgetId,
                            'slot'       => (string)($widget['slot'] ?? ''),
                            'css_class'  => (string)($widget['css_class'] ?? ''),
                            'expires_at' => $expire,
                            'active'     => 1,
                            'source'     => 'redeem_code',
                            'ts'         => time(),
                        ]);
                    }
                    $granted = true;
                } catch (Throwable $e) {
                    $grantErr = $e->getMessage();
                }
            }

            if (!$granted) {
                // 发放失败要回滚占位
                try {
                    $fs->update('points_codes', (int)$row['id'], ['redeemed_by' => 0, 'redeemed_at' => 0]);
                } catch (Exception $e) {}
                return ['ok' => false,
                        'error' => '发放商品失败' . ($grantErr !== '' ? '：' . $grantErr : '，请重试'),
                        'points' => 0, 'balance' => 0];
            }

            return [
                'ok' => true,
                'error' => '',
                'points' => 0,
                'balance' => lwUserPoints($userId),
                'widget' => [
                    'id'   => $widgetId,
                    'name' => (string)($widget['name'] ?? ''),
                    'slot' => (string)($widget['slot'] ?? ''),
                ],
            ];
        }

        // ---- 积分码：加积分 ----
        /* lwPointsAdd() 定义在 includes/widgets.php。
         *
         * 【为什么这里要主动引入】
         * 原来没引入，导致兑换积分码时抛
         * 「Call to undefined function lwPointsAdd()」——
         * 码被占用了但积分没加上，用户既拿不到分、码也废了。
         * 现在按需加载，保证函数一定可用。
         */
        if (!function_exists('lwPointsAdd')) {
            $__widgetsFile = __DIR__ . '/widgets.php';
            if (is_file($__widgetsFile)) {
                require_once $__widgetsFile;
            }
        }

        if (!function_exists('lwPointsAdd')) {
            // 还是找不到就回滚，绝不能让码白废
            try {
                $fs->update('points_codes', (int)$row['id'], ['redeemed_by' => 0, 'redeemed_at' => 0]);
            } catch (Exception $e) {}
            return ['ok' => false, 'error' => '积分系统未加载，请联系管理员', 'points' => 0, 'balance' => 0];
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
