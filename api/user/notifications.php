<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/actions/notification_read.php';
require_once __DIR__ . '/../../includes/fields.php';

$user = requireLogin();
$user = checkBanned($user);
if ($user['is_banned']) {
    jsonError('账号已被封禁');
}

$fs = getFS();

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $page = intval($_REQUEST['page'] ?? 1);
    $limit = intval($_REQUEST['limit'] ?? 20);
    $unreadOnly = ($_REQUEST['unread'] ?? '0') === '1';
    /* 分类过滤：切换标签时直接按分类取，避免"前端只过滤当前 20 条"的错觉 */
    $category = (string) ($_REQUEST['category'] ?? '');

    $notifications = $fs->find('notifications', ['user_id' => $user['id']]);

    usort($notifications, function($a, $b) {
        return strtotime($b['created_at']) - strtotime($a['created_at']);
    });

    /* 分类未读数：给前端标签页展示「全部/互动/抽奖/系统」小红点用。
     * 必须在分页切片之前统计，否则只能数到当前页的 20 条。 */
    $__cats = ['all' => 0, 'interact' => 0, 'lottery' => 0, 'system' => 0];
    foreach ($notifications as $__n) {
        if (!empty($__n['is_read'])) { continue; }
        $__t = (string) ($__n['type'] ?? 'system');
        $__cats['all']++;
        if (in_array($__t, ['comment', 'reply', 'mention', 'follow', 'like'], true)) { $__cats['interact']++; }
        elseif ($__t === 'lottery_result') { $__cats['lottery']++; }
        else { $__cats['system']++; }
    }

    if ($unreadOnly) {
        $notifications = array_filter($notifications, function($n) { return empty($n['is_read']); });
    }
    if (in_array($category, ['interact', 'lottery', 'system'], true)) {
        $notifications = array_filter($notifications, function ($n) use ($category) {
            $t = (string) ($n['type'] ?? 'system');
            if ($category === 'interact') { return in_array($t, ['comment', 'reply', 'mention', 'follow', 'like'], true); }
            if ($category === 'lottery') { return $t === 'lottery_result'; }
            return !in_array($t, ['comment', 'reply', 'mention', 'follow', 'like', 'lottery_result'], true);
        });
    }

    $notifications = array_values($notifications);
    $total = count($notifications);
    $unreadCount = count(array_filter($notifications, function($n) { return empty($n['is_read']); }));
    $notifications = array_slice($notifications, ($page - 1) * $limit, $limit);

    $formatted = array_map(function($n) {
        return [
            'id' => $n['id'],
            'type' => $n['type'] ?? 'comment',
            'content' => $n['content'] ?? '',
            'post_id' => $n['post_id'] ?? '',
            'post_title' => $n['post_title'] ?? '',
            'from_user' => $n['from_user'] ?? '',
            'link' => $n['link'] ?? '',
            'is_read' => !empty($n['is_read']),
            'created_at' => $n['created_at'] ?? '',
            'timeAgo' => timeAgo($n['created_at'] ?? '')
        ];
    }, $notifications);

    // 字段投影（E14）：不传 fields/top 时与改造前完全一致。
    // 典型用法：后台轮询只要未读数 → `?unread=1&limit=1&top=unread_count`，
    // 响应从「一条完整通知 + 分页元数据」缩到只剩下数字。
    jsonSuccess(lwProjectTop(
        [
            'notifications' => lwProjectRows(
                $formatted,
                lwFieldsRequest(),
                ['id', 'type', 'content', 'post_id', 'post_title', 'from_user', 'link',
                 'is_read', 'created_at', 'timeAgo'],
                ['id']
            ),
            'unread_count' => $unreadCount,
            'type_counts' => $__cats,
            'total' => $total,
            'page' => $page,
            'has_more' => ($page * $limit) < $total,
        ],
        lwTopRequest(),
        [] // top 的语义就是「我只要这几个顶层键」，不强制保留任何一个
    ));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrfToken = $_POST['csrf_token'] ?? '';
    if (!verifyCSRFToken($csrfToken)) {
        jsonError('CSRF验证失败', 403);
    }

    $action = $_REQUEST['action'] ?? 'read';

    // 业务逻辑统一在 includes/actions/notification_read.php，与 AI 确认卡片共用同一份
    $result = lw_do_notification_read($user, [
        'action' => $action,
        'id'     => $_REQUEST['id'] ?? '',
    ]);
    if (!$result['ok']) {
        jsonError($result['message'], $result['code']);
    }
    jsonSuccess(['message' => $result['message']]);
}