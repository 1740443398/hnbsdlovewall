<?php
/**
 * 定时群发：把「到点才发」的通知存队列，靠页面访问顺带执行。
 * 共享主机没有 cron，心跳（api/heartbeat.php）每页都会打，挂在那里最稳。
 */
if (!function_exists('lwBroadcastQueue')) {
    function lwBroadcastQueue(): array
    {
        $rows = getFS()->read('scheduled_broadcasts');
        return is_array($rows) ? $rows : [];
    }

    function lwBroadcastSaveQueue(array $rows): void
    {
        getFS()->write('scheduled_broadcasts', array_values($rows));
    }

    /** 立即执行一条队列任务，返回实际触达人数 */
    function lwBroadcastDeliver(array $q): int
    {
        $users = (array) getFS()->read('users');
        $target = (string) ($q['target'] ?? 'all');
        $singleId = (int) ($q['single_id'] ?? 0);
        $ids = [];
        foreach ($users as $u) {
            if (!empty($u['is_bot'])) { continue; }
            $role = (string) ($u['role'] ?? 'user');
            if ($target === 'role_admin' && !in_array($role, ['admin', 'super_admin'], true)) { continue; }
            if ($target === 'role_user' && in_array($role, ['admin', 'super_admin'], true)) { continue; }
            if ($target === 'single' && (int) ($u['id'] ?? 0) !== $singleId) { continue; }
            $ids[] = (int) ($u['id'] ?? 0);
        }
        $ids = array_values(array_filter($ids));

        $content = (string) ($q['content'] ?? '');
        $link = (string) ($q['link'] ?? '');
        $__nickMap = [];
        foreach ($users as $__u) { $__nickMap[(int)($__u['id'] ?? 0)] = (string)($__u['nickname'] ?? ''); }
        $ok = 0;
        foreach ($ids as $uid) {
            try {
                $__nick = $__nickMap[$uid] ?? '';
                $__content = str_replace(['{昵称}', '{nickname}'], $__nick !== '' ? $__nick : '同学', $content);
                getFS()->insert('notifications', [
                    'user_id'    => $uid,
                    'type'       => 'system',
                    'content'    => $__content,
                    'post_id'    => 0,
                    'post_title' => '',
                    'link'       => $link,
                    'from_user'  => (string) ($q['admin_name'] ?? '管理员'),
                    'is_read'    => false,
                    'created_at' => date('Y-m-d H:i:s'),
                ]);
                $ok++;
            } catch (Throwable $e) {}
        }

        if (!empty($q['app_push'])) {
            try {
                getFS()->insert('app_pushes', [
                    'title'      => mb_substr((string) ($q['push_title'] ?? '站内公告'), 0, 40),
                    'content'    => mb_substr(str_replace(['{昵称}', '{nickname}'], '同学', $content), 0, 300),
                    'url'        => $link,
                    'target'     => ($target === 'all') ? '' : implode(',', array_slice($ids, 0, 500)),
                    'created_at' => date('Y-m-d H:i:s'),
                ]);
            } catch (Throwable $e) {}
        }

        try {
            getFS()->insert('broadcast_logs', [
                'admin_id'   => (int) ($q['admin_id'] ?? 0),
                'admin_name' => (string) ($q['admin_name'] ?? ''),
                'target'     => $target,
                'single_id'  => $target === 'single' ? $singleId : 0,
                'content'    => $content,
                'link'       => $link,
                'sent_count' => $ok,
                'app_push'   => !empty($q['app_push']) ? 1 : 0,
                'created_at' => date('Y-m-d H:i:s'),
            ]);
        } catch (Throwable $e) {}
        return $ok;
    }

    /** 处理到期的队列任务（最多 5 条/次） */
    function lwBroadcastTick(): int
    {
        $now = time();
        $rows = lwBroadcastQueue();
        $done = 0;
        foreach ($rows as $i => $q) {
            if ((string) ($q['status'] ?? 'pending') !== 'pending') { continue; }
            $at = strtotime((string) ($q['send_at'] ?? ''));
            if ($at === false || $at > $now) { continue; }
            $n = lwBroadcastDeliver($q);
            $rows[$i]['status'] = 'sent';
            $rows[$i]['sent_at'] = date('Y-m-d H:i:s');
            $rows[$i]['sent_count'] = $n;
            $done++;
            if ($done >= 5) { break; }
        }
        if ($done > 0) { lwBroadcastSaveQueue($rows); }
        return $done;
    }

    /** 节流：同一分钟内只跑一次（心跳频率很高） */
    function lwBroadcastTickThrottled(): void
    {
        $marker = dirname(__DIR__) . '/data/.broadcast_tick';
        $now = time();
        if (is_file($marker)) {
            $last = (int) @file_get_contents($marker);
            if ($now - $last < 60) { return; }
        }
        @file_put_contents($marker, (string) $now);
        lwBroadcastTick();
    }
}
