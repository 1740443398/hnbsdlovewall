<?php
/**
 * 群发邮件任务队列
 *
 * ==========================================================================
 * 解决什么问题
 * ==========================================================================
 * 原来「分批」的进度（offset）存在浏览器内存里。
 * 一旦页面被关、刷新、切后台被杀，进度就丢了 ——
 * 用户看到「发送了 30/115」，剩下 85 个永远发不出去。
 *
 * 表现就是站长反复反馈的：「选了全部用户，但只发了一部分」。
 *
 * ==========================================================================
 * 现在的做法：任务落盘
 * ==========================================================================
 * 点「开始群发」时：
 *   1. 服务端立刻创建一条任务记录（含完整收件人名单、已发偏移、状态）
 *   2. 返回 task_id
 *   3. 前端拿着 task_id 反复调 run 接口，每次发一小批
 *   4. 每批结束后把 offset 写回任务记录
 *
 * 关键收益：
 *   · 页面关掉 / 断网 / 手机关机 —— 任务还在，重新打开能继续
 *   · 可以精确知道「还剩多少个没发」
 *   · 不会重复发（offset 是服务端唯一真相）
 *   · 出问题能查到具体卡在哪一批
 *
 * ==========================================================================
 * 为什么不用后台定时任务（cron）
 * ==========================================================================
 * InfinityFree 免费主机不提供 cron。
 * 替代方案是「前端驱动 + 服务端记账」：
 * 前端负责触发每一批，服务端负责记录进度。
 * 只要用户不关页面，就能一口气发完；
 * 关了页面也能在「任务列表」里手动继续。
 * ==========================================================================
 */

if (!defined('LW_MAIL_TASK_LOADED')) {
    define('LW_MAIL_TASK_LOADED', 1);

    /** 任务保留多少天 */
    if (!defined('LW_MAIL_TASK_KEEP_DAYS')) {
        define('LW_MAIL_TASK_KEEP_DAYS', 30);
    }

    /**
     * 创建一个群发任务。
     *
     * @param array  $ids     收件人 user_id 列表
     * @param string $subject 邮件主题
     * @param array  $data    邮件正文数据（title / lines / link / linkText）
     * @param string $scope   范围标识，用于展示
     * @param int    $adminId 操作人
     * @return array{ok:bool, id:int, error:string}
     */
    function lwMailTaskCreate(array $ids, string $subject, array $data, string $scope, int $adminId): array
    {
        try {
            $ids = array_values(array_unique(array_map('intval', $ids)));
            if (!$ids) {
                return ['ok' => false, 'id' => 0, 'error' => '没有可发送的收件人'];
            }

            $fs = getFS();
            $row = $fs->insert('mail_tasks', [
                'subject'    => mb_substr($subject, 0, 200),
                'data'       => json_encode($data, JSON_UNESCAPED_UNICODE),
                'scope'      => $scope,
                'recipients' => json_encode($ids),
                'total'      => count($ids),
                'offset'     => 0,
                'sent'       => 0,
                'failed'     => 0,
                'skipped'    => 0,
                'status'     => 'pending',   // pending | running | done | error
                'last_error' => '',
                'created_by' => $adminId,
                'ts'         => time(),
                'updated_at' => date('Y-m-d H:i:s'),
            ]);

            $id = is_array($row) && isset($row['id']) ? (int)$row['id'] : 0;
            if ($id <= 0) {
                // insert 可能只返回 bool，重新查一次最后一条
                $all = $fs->read('mail_tasks');
                $last = end($all);
                $id = is_array($last) ? (int)($last['id'] ?? 0) : 0;
            }

            return ['ok' => $id > 0, 'id' => $id, 'error' => $id > 0 ? '' : '任务创建失败'];
        } catch (Throwable $e) {
            return ['ok' => false, 'id' => 0, 'error' => $e->getMessage()];
        }
    }

    /** 读取一个任务。 */
    function lwMailTaskGet(int $id): ?array
    {
        if ($id <= 0) return null;
        try {
            $r = getFS()->findById('mail_tasks', $id);
            return $r ?: null;
        } catch (Throwable $e) {
            return null;
        }
    }

    /**
     * 执行任务的一批。
     *
     * @param int $id    任务 id
     * @param int $batch 本批最多发多少封
     * @return array 进度信息
     */
    function lwMailTaskRun(int $id, int $batch = 10): array
    {
        $task = lwMailTaskGet($id);
        if (!$task) {
            return ['ok' => false, 'error' => '任务不存在', 'done' => true];
        }

        if (($task['status'] ?? '') === 'done') {
            return [
                'ok' => true, 'done' => true,
                'total' => (int)$task['total'],
                'offset' => (int)$task['offset'],
                'sent' => (int)$task['sent'],
                'failed' => (int)$task['failed'],
                'skipped' => (int)$task['skipped'],
                'sent_now' => 0, 'remaining' => 0,
            ];
        }

        $ids = json_decode((string)$task['recipients'], true);
        if (!is_array($ids)) $ids = [];
        $data = json_decode((string)$task['data'], true);
        if (!is_array($data)) $data = [];

        $offset = (int)$task['offset'];
        $slice = array_slice($ids, $offset, $batch);
        $remainingBefore = max(0, count($ids) - $offset);

        if (!$slice) {
            // 没有更多了，标记完成
            getFS()->update('mail_tasks', $id, [
                'status' => 'done', 'updated_at' => date('Y-m-d H:i:s'),
            ]);
            return [
                'ok' => true, 'done' => true,
                'total' => count($ids), 'offset' => $offset,
                'sent' => (int)$task['sent'], 'failed' => (int)$task['failed'],
                'skipped' => (int)$task['skipped'],
                'sent_now' => 0, 'remaining' => 0,
            ];
        }

        // 标记为运行中 + 释放 session 锁（长任务不能占着锁）
        getFS()->update('mail_tasks', $id, [
            'status' => 'running', 'updated_at' => date('Y-m-d H:i:s'),
        ]);
        if (session_status() === PHP_SESSION_ACTIVE) { session_write_close(); }
        @set_time_limit(0);
        @ignore_user_abort(true);

        $r = lwMailBroadcast($slice, (string)$task['subject'], [
            'title'    => (string)($data['title'] ?? ''),
            'lines'    => (array)($data['lines'] ?? []),
            'link'     => (string)($data['link'] ?? ''),
            'linkText' => (string)($data['linkText'] ?? '打开网站'),
        ], $batch);

        $newOffset = $offset + count($slice);
        $newSent = (int)$task['sent'] + (int)$r['sent'];
        $newFailed = (int)$task['failed'] + (int)$r['failed'];
        $newSkipped = (int)$task['skipped'] + (int)$r['skipped'];
        $remaining = max(0, count($ids) - $newOffset);

        /* 熔断：本批一封都没发出去，说明 SMTP 有问题。
         * 标记 error 并保留 offset —— 修好后可以点「继续」从断点接着发。 */
        $abort = ((int)$r['sent'] === 0 && (int)$r['failed'] > 0);
        $errMsg = '';
        if ($abort) {
            foreach ((array)($r['results'] ?? []) as $rr) {
                if (empty($rr['ok']) && ($rr['err'] ?? '') !== '') {
                    $errMsg = (string)$rr['err'];
                    break;
                }
            }
        }

        $status = $abort ? 'error' : ($remaining > 0 ? 'running' : 'done');

        getFS()->update('mail_tasks', $id, [
            // 熔断时不推进 offset，否则这一批的人就永久漏发了
            'offset'     => $abort ? $offset : $newOffset,
            'sent'       => $newSent,
            'failed'     => $newFailed,
            'skipped'    => $newSkipped,
            'status'     => $status,
            'last_error' => $abort ? mb_substr($errMsg, 0, 200) : '',
            'updated_at' => date('Y-m-d H:i:s'),
        ]);

        return [
            'ok'        => true,
            'done'      => ($status === 'done'),
            'abort'     => $abort,
            'error'     => $errMsg,
            'total'     => count($ids),
            'offset'    => $abort ? $offset : $newOffset,
            'sent'      => $newSent,
            'failed'    => $newFailed,
            'skipped'   => $newSkipped,
            'sent_now'  => (int)$r['sent'],
            'remaining' => $abort ? $remainingBefore : $remaining,
            'status'    => $status,
        ];
    }

    /** 最近的任务列表（后台展示用）。 */
    function lwMailTaskList(int $limit = 20): array
    {
        try {
            $rows = getFS()->read('mail_tasks');
            if (!is_array($rows)) return [];
            usort($rows, function ($a, $b) {
                return (int)($b['ts'] ?? 0) <=> (int)($a['ts'] ?? 0);
            });
            return array_slice($rows, 0, $limit);
        } catch (Throwable $e) {
            return [];
        }
    }

    /** 清理老任务。 */
    function lwMailTaskPrune(): int
    {
        try {
            $fs = getFS();
            $cut = time() - LW_MAIL_TASK_KEEP_DAYS * 86400;
            $rows = $fs->read('mail_tasks');
            if (!is_array($rows)) return 0;
            $keep = []; $removed = 0;
            foreach ($rows as $r) {
                if ((int)($r['ts'] ?? 0) >= $cut) $keep[] = $r;
                else $removed++;
            }
            if ($removed > 0) $fs->write('mail_tasks', $keep);
            return $removed;
        } catch (Throwable $e) {
            return 0;
        }
    }
}