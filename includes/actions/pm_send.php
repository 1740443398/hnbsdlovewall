<?php
/**
 * 发送私信
 *
 * 调用方：api/pm.php?action=send（页面）、api/ai_action.php（AI 确认卡片）
 * 入参：$in['to_qq'] string 对方 QQ、$in['text'] string 正文
 *
 * 会话 key 的拼法必须与页面端完全一致（小号在前、'｜' 分隔），
 * 否则同一段对话会裂成两个会话。所以这里把 pm_key 也收进来统一定义。
 */
require_once __DIR__ . '/_lib.php';

if (!function_exists('pm_key')) {
    function pm_key($a, $b) {
        return $a < $b ? $a . '|' . $b : $b . '|' . $a;
    }
}

if (!function_exists('lw_do_pm_send')) {
    function lw_do_pm_send(array $user, array $in): array
    {
        $toQq = sanitizeInput(trim((string)($in['to_qq'] ?? '')));
        $text = (string)($in['text'] ?? '');

        if (!isValidQQ($toQq)) {
            return lwActionFail('接收方QQ号格式不正确');
        }
        $meQq = (string)($user['qq'] ?? '');
        if ($meQq === '') {
            return lwActionFail('你的账号缺少 QQ 号，无法发送私信', 400);
        }
        if ($toQq === $meQq) {
            return lwActionFail('不能给自己发送私信');
        }
        $len = mb_strlen($text);
        if ($len < 1 || $len > 1000) {
            return lwActionFail('私信内容需在1-1000字之间');
        }

        $fs = getFS();
        $target = $fs->findOne('users', ['qq' => $toQq]);
        if (!$target) {
            return lwActionFail('接收方不存在');
        }
        if (!empty($target['is_banned'])) {
            return lwActionFail('对方账号已被封禁，无法发送私信', 403);
        }

        // 屏蔽闸门：任一方向被屏蔽都不允许发送私信（防骚扰的核心拦截点）
        $gate = lwCanInteract((int)$user['id'], (int)$target['id'], '发送私信');
        if (!$gate['ok']) {
            return lwActionFail($gate['message'], 403);
        }

        $ts = (int)round(microtime(true) * 1000);
        $fs->insert('pm_messages', [
            'key'  => pm_key($meQq, $toQq),
            'who'  => $meQq,
            'text' => mb_substr($text, 0, 1000),
            'ts'   => $ts,
        ]);

        logUserActivity($user['id'], 'send_pm', '向 ' . $toQq . ' 发送私信');
        // 成长体系：发私信得经验 + 评估成就
        lwGrowthAward((int)$user['id'], 'pm_sent');

        // 站内消息通知接收方（新私信），使其在消息通知中心可见。
        // 若接收方对该会话开了「免打扰」，则只落库不推通知 —— 消息本身仍能收到，
        // 这才是免打扰的本意（而不是把消息吞掉）。
        if (lwPmShouldNotify((int)$target['id'], (int)$user['id'])) {
            $preview = mb_substr($text, 0, 40);
            if (mb_strlen($text) > 40) {
                $preview .= '…';
            }
            $fs->insert('notifications', [
                'user_id'   => $target['id'],
                'type'      => 'pm',
                'content'   => ($user['nickname'] ?? '用户') . ' 给你发来一条私信：' . $preview,
                'from_user' => $user['nickname'] ?? '用户',
                'is_read'   => false,
            ]);
        }

        return lwActionResult(true, '私信已发送', [
            'key'  => pm_key($meQq, $toQq),
            'to_qq' => $toQq,
            'to_nickname' => (string)($target['nickname'] ?? $toQq),
        ]);
    }
}
