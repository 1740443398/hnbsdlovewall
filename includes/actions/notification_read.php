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

        if ($action === 'read_all') {
            $all = $fs->find('notifications', ['user_id' => $user['id']]);
            $n = 0;
            foreach ($all as $item) {
                if (!empty($item['is_read'])) {
                    continue;
                }
                $item['is_read'] = true;
                $fs->update('notifications', $item['id'], $item);
                $n++;
            }
            // 文案保持与旧接口一致（前端会直接展示这句），条数放在 data 里供 UI 自行使用
            return lwActionResult(true, '已全部标记为已读', ['marked' => $n]);
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
            $n['is_read'] = true;
            $fs->update('notifications', $id, $n);
            return lwActionResult(true, '已标记为已读', ['marked' => 1, 'id' => $id]);
        }

        return lwActionFail('无效操作');
    }
}
