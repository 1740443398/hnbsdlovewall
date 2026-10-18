<?php
/**
 * 标记通知已读（单条 / 全部）
 *
 * 调用方：api/user/notifications.php（页面）、api/ai_action.php（AI 确认卡片）
 * 入参：$in['action'] 'read' | 'read_all'；$in['id'] 通知ID（action=read 时必填）
 */
require_once __DIR__ . '/_lib.php';

if (!function_exists('lw_do_notification_read')) {
    function lw_do_notification_read(array $user, array $in): array
    {
        $action = (string)($in['action'] ?? 'read');
        $fs = getFS();

        // 【2026-10-18 站长要求】已读即从列表移除，不保留任何数据。
        // 以前是 is_read=true 留在表里，结果：通知表只增不减（线上已经 658 条），
        // 用户点了「全部已读」列表还在，等于什么都没发生。
        // 现在 read 直接删那一行，read_all 直接清空该用户的全部通知。
        // 好处：存储只跟「未读」成正比，不会再无限膨胀。
        if ($action === 'read_all') {
            $all = $fs->find('notifications', ['user_id' => $user['id']]);
            $n = 0;
            foreach ($all as $item) {
                try {
                    $fs->delete('notifications', (int)$item['id']);
                    $n++;
                } catch (Exception $e) {
                    error_log('notification purge failed: ' . $e->getMessage());
                }
            }
            return lwActionResult(true, $n > 0 ? ('已清理 ' . $n . ' 条通知') : '没有需要清理的通知',
                ['marked' => $n, 'removed' => $n]);
        }

        if ($action === 'read') {
            $id = $in['id'] ?? '';
            if ($id === '' || $id === 0) {
                return lwActionFail('缺少通知ID');
            }
            $n = $fs->findById('notifications', $id);
            // 归属校验：只能读自己的通知
            if (!$n || (int)($n['user_id'] ?? 0) !== (int)$user['id']) {
                return lwActionFail('通知不存在');
            }
            // 读了就删 —— 用户不需要「已读历史」，服务器也不需要替他们存着
            $fs->delete('notifications', (int)$n['id']);
            return lwActionResult(true, '已读', ['marked' => 1, 'removed' => 1, 'id' => $id]);
        }

        return lwActionFail('无效操作');
    }
}
