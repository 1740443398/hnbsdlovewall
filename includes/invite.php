<?php
/**
 * 邀请裂变系统 —— 邀请码生成 / 归属绑定 / 邀请统计（唯一真源）
 * ---------------------------------------------------------------------------
 * 为什么单独一层：邀请涉及三处使用场景 —— 注册接口写「归属关系」、个人中心展示
 * 「我的邀请码 + 战果」、注册页据 ?invite= 预热表单。若各写各的，码的格式、
 * 唯一性校验、奖励规则迟早分叉。这里收口成一组 lwInvite* 函数，谁用谁调。
 *
 * 存储（零 schema 迁移负担）：
 *   - users 记录上挂两个字段：
 *       invite_code  本用户对外分享的码（老用户缺失时由 lwInviteEnsureCode 懒补）
 *       invited_by   邀请人 user_id，0/空 表示自然注册
 *   - invites 表（按需创建）记明细：{inviter_id, invitee_id, code}，便于审计与排行榜。
 *
 * 约定：本文件只声明函数，**不 require config/config.php**（由调用方保证已加载）。
 */

require_once __DIR__ . '/achievements.php';

if (!defined('LW_INVITE_LIB')) {
    define('LW_INVITE_LIB', 1);
}

if (!function_exists('lwInviteEnabled')) {
    /** 邀请功能总开关（后台可关）。关闭后注册页不再收邀请码，个人中心隐藏邀请卡。 */
    function lwInviteEnabled(): bool
    {
        return getSetting('invite_enabled', '1') !== '0';
    }
}

if (!function_exists('lwInviteAlphabet')) {
    /**
     * 邀请码字符集：去掉了 0/O、1/I/L 等肉眼易混字符，
     * 用户口头转述 / 手抄邀请码时不会「抄错一位导致注册不上」。
     */
    function lwInviteAlphabet(): string
    {
        return 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';
    }
}

if (!function_exists('lwInviteNormalize')) {
    /** 归一化用户输入的邀请码：去空白、转大写、剔除非法字符。 */
    function lwInviteNormalize($code): string
    {
        $code = strtoupper(trim((string)$code));
        return preg_replace('/[^A-Z0-9]/', '', $code) ?? '';
    }
}

if (!function_exists('lwInviteCodeValid')) {
    /** 格式校验：长度 4–12，且只用字符集内的字符。 */
    function lwInviteCodeValid($code): bool
    {
        $code = lwInviteNormalize($code);
        $len = strlen($code);
        if ($len < 4 || $len > 12) {
            return false;
        }
        return strspn($code, lwInviteAlphabet()) === $len;
    }
}

if (!function_exists('lwInviteGenerateCode')) {
    /** 生成一个候选码（不查重，仅供 lwInviteAllocate 使用）。 */
    function lwInviteGenerateCode(int $len = 7): string
    {
        $alpha = lwInviteAlphabet();
        $max = strlen($alpha) - 1;
        $out = '';
        for ($i = 0; $i < $len; $i++) {
            $out .= $alpha[random_int(0, $max)];
        }
        return $out;
    }
}

if (!function_exists('lwInviteAllocate')) {
    /** 分配一个全局唯一的邀请码；连续撞码 12 次才放弃（概率可忽略）。 */
    function lwInviteAllocate($fs): string
    {
        for ($i = 0; $i < 12; $i++) {
            $c = lwInviteGenerateCode();
            if (!$fs->findOne('users', ['invite_code' => $c])) {
                return $c;
            }
        }
        return '';
    }
}

if (!function_exists('lwInviteEnsureCode')) {
    /**
     * 取某用户的邀请码，没有就现生成并落库（老用户懒补）。
     * @return string 邀请码；用户不存在或分配失败时返回 ''
     */
    function lwInviteEnsureCode(int $userId): string
    {
        $userId = (int)$userId;
        if ($userId <= 0) {
            return '';
        }
        $fs = getFS();
        $u = $fs->findById('users', $userId);
        if (!$u) {
            return '';
        }
        $code = lwInviteNormalize($u['invite_code'] ?? '');
        if (lwInviteCodeValid($code)) {
            return $code;
        }
        $code = lwInviteAllocate($fs);
        if ($code === '') {
            return '';
        }
        $fs->update('users', $userId, ['invite_code' => $code]);
        return $code;
    }
}

if (!function_exists('lwInviteLink')) {
    /** 拼出带邀请码的注册链接（供复制/分享/海报使用）。 */
    function lwInviteLink(string $code): string
    {
        $base = defined('SITE_URL') ? rtrim(SITE_URL, '/') : '';
        return $base . '/pages/register.php?invite=' . urlencode(lwInviteNormalize($code));
    }
}

if (!function_exists('lwInviteFindInviter')) {
    /** 按邀请码找邀请人；码无效、查无此人、或该人被禁封时返回 null。 */
    function lwInviteFindInviter($code)
    {
        if (!lwInviteEnabled()) {
            return null;
        }
        $code = lwInviteNormalize($code);
        if (!lwInviteCodeValid($code)) {
            return null;
        }
        $u = getFS()->findOne('users', ['invite_code' => $code]);
        if (!$u) {
            return null;
        }
        if ((int)($u['is_banned'] ?? 0) === 1) {
            return null;
        }
        return $u;
    }
}

if (!function_exists('lwInviteAttribute')) {
    /**
     * 把新注册用户归到邀请人名下：写归属、记明细、发奖励、通知邀请人。
     * 全程容错 —— 邀请是**旁路功能**，任何失败都不应影响注册主流程。
     *
     * @return array{ok:bool, inviter_id:int, inviter_name:string, awarded:int, message:string}
     */
    function lwInviteAttribute(int $newUserId, $code): array
    {
        $out = ['ok' => false, 'inviter_id' => 0, 'inviter_name' => '', 'awarded' => 0, 'message' => ''];
        $newUserId = (int)$newUserId;
        if ($newUserId <= 0 || !lwInviteEnabled()) {
            return $out;
        }
        try {
            $inviter = lwInviteFindInviter($code);
            if (!$inviter) {
                $out['message'] = '邀请码无效或已失效';
                return $out;
            }
            $inviterId = (int)$inviter['id'];
            if ($inviterId === $newUserId) {
                return $out;
            }

            $fs = getFS();
            $newUser = $fs->findById('users', $newUserId);
            if (!$newUser) {
                return $out;
            }
            // 已绑定过就不重复绑定/重复发奖（防重复提交或码被二次使用）
            if (!empty($newUser['invited_by'])) {
                return $out;
            }

            $fs->update('users', $newUserId, [
                'invited_by' => $inviterId,
                'invited_at' => date('Y-m-d H:i:s'),
            ]);

            $fs->insert('invites', [
                'inviter_id' => $inviterId,
                'invitee_id' => $newUserId,
                'code'       => lwInviteNormalize($code),
                'ip'         => function_exists('getClientIP') ? getClientIP() : '',
            ]);

            // 奖励邀请人经验（成长体系关闭时静默跳过）
            $awarded = 0;
            if (function_exists('lwGrowthAward')) {
                $res = lwGrowthAward($inviterId, 'invite', ['invitee' => (int)$newUserId]);
                if (isset($res['exp']['awarded'])) {
                    $awarded = (int)$res['exp']['awarded'];
                }
            }
            // 新人礼包：给被邀请人一点起步经验，强化「注册即有获得感」
            if (function_exists('lwGrowthAward')) {
                lwGrowthAward($newUserId, 'invite_welcome', ['inviter' => $inviterId]);
            }

            // 通知邀请人
            try {
                $fs->insert('notifications', [
                    'user_id'    => $inviterId,
                    'type'       => 'invite',
                    'content'    => '你邀请的好友「' . (string)($newUser['nickname'] ?? '') . '」已加入，获得 ' . $awarded . ' 经验奖励 🎉',
                    'post_id'    => 0,
                    'post_title' => '',
                    'from_user'  => '系统',
                    'is_read'    => false,
                ]);
            } catch (Exception $e) {
                error_log('invite notify failed: ' . $e->getMessage());
            }

            $out['ok'] = true;
            $out['inviter_id'] = $inviterId;
            $out['inviter_name'] = (string)($inviter['nickname'] ?? '');
            $out['awarded'] = $awarded;
            return $out;
        } catch (Exception $e) {
            error_log('lwInviteAttribute failed: ' . $e->getMessage());
            return $out;
        }
    }
}

if (!function_exists('lwInviteStats')) {
    /**
     * 某用户的邀请战果：码、链接、已邀请人数、被邀请人列表（按时间倒序）。
     * @return array{code:string, link:string, count:int, list:array}
     */
    function lwInviteStats(int $userId): array
    {
        $userId = (int)$userId;
        $code = lwInviteEnsureCode($userId);
        $fs = getFS();
        $invitees = $userId > 0 ? $fs->find('users', ['invited_by' => $userId]) : [];

        $list = [];
        foreach ($invitees as $u) {
            $list[] = [
                'id'       => (int)($u['id'] ?? 0),
                'nickname' => (string)($u['nickname'] ?? ''),
                'avatar'   => (string)($u['avatar'] ?? ''),
                'uuid'     => (string)($u['uuid'] ?? ''),
                'time'     => (string)($u['invited_at'] ?? ($u['created_at'] ?? '')),
            ];
        }
        usort($list, function ($a, $b) {
            return strcmp((string)$b['time'], (string)$a['time']);
        });

        return [
            'code'  => $code,
            'link'  => $code !== '' ? lwInviteLink($code) : '',
            'count' => count($list),
            'list'  => $list,
        ];
    }
}

if (!function_exists('lwInviteLeaderboard')) {
    /**
     * 邀请排行榜：按邀请人数取前 N 名（同分按更早达成者优先保持稳定）。
     * 数据量小，直接全表扫 users 统计 invited_by，不做额外缓存。
     * @return array<int, array{user_id:int, nickname:string, avatar:string, count:int}>
     */
    function lwInviteLeaderboard(int $limit = 10): array
    {
        $limit = max(1, min(100, $limit));
        $counts = [];
        foreach (getFS()->read('users') as $u) {
            $by = (int)($u['invited_by'] ?? 0);
            if ($by > 0) {
                $counts[$by] = ($counts[$by] ?? 0) + 1;
            }
        }
        if (!$counts) {
            return [];
        }
        arsort($counts);
        $rows = [];
        foreach (array_slice($counts, 0, $limit, true) as $uid => $cnt) {
            $u = getFS()->findById('users', (int)$uid);
            if (!$u) {
                continue;
            }
            $rows[] = [
                'user_id'  => (int)$uid,
                'nickname' => (string)($u['nickname'] ?? ''),
                'avatar'   => (string)($u['avatar'] ?? ''),
                'uuid'     => (string)($u['uuid'] ?? ''),
                'count'    => (int)$cnt,
            ];
        }
        return $rows;
    }
}
