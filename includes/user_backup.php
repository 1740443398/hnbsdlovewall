<?php
/**
 * 用户自助数据备份与恢复（需求二、6）
 * ---------------------------------------------------------------------------
 * 与「站长全站备份」是两件事，别混：
 *   admin/backup.php  → 整站所有表 + 全部图片，覆盖式还原，只给站长
 *   本文件            → **单个用户自己的**数据，导入时只写回「属于他」的行，只给本人
 *
 * 设计约束（每一条都是防事故的）：
 *   ① 导出按表逐一「归属过滤」，不导出别人的私信、通知、日志。
 *   ② 导入只做 **upsert 到本人名下**：行里 user_id / sender_id / receiver_id 不是本人一律丢弃，
 *      无法伪造出他人的数据；也不会删除服务端现有记录（恢复 = 补齐，不是回滚整站）。
 *   ③ 账号安全字段（密码哈希、security_stamp、角色、封禁态、积分）不允许被备份包覆盖 ——
 *      否则一份旧备份就能把「已被封禁的账号」洗白，或把自己的角色提成管理员。
 *   ④ 附件类（图片、文件）不随包走：体积不可控且主机有上传白名单，包内只保留 URL 引用。
 */

if (!class_exists('LWUserBackup')) {
    class LWUserBackup
    {
        /** 备份格式版本，未来结构变化时用来判新旧 */
        const FORMAT = 1;

        /**
         * 每张表如何判断「这一行属于我」
         *   keys:  命中任一字段等于「本人标识」即算本人的行
         *   ident: 'id' = 用数字用户 id；'qq' = 用 QQ 号（私信相关表以 QQ 为维度）
         *   mode:  'own' = 可回写；'read' = 只导出不回收（日志类，回写没有意义）
         */
        public static function tableRules(): array
        {
            return [
                'users'            => ['keys' => ['id'], 'ident' => 'id', 'mode' => 'own'],
                'posts'            => ['keys' => ['user_id'], 'ident' => 'id', 'mode' => 'own'],
                'comments'         => ['keys' => ['user_id'], 'ident' => 'id', 'mode' => 'own'],
                'post_likes'       => ['keys' => ['user_id'], 'ident' => 'id', 'mode' => 'own'],
                'post_favorites'   => ['keys' => ['user_id'], 'ident' => 'id', 'mode' => 'own'],
                'notifications'    => ['keys' => ['user_id'], 'ident' => 'id', 'mode' => 'own'],
                'checkins'         => ['keys' => ['user_id'], 'ident' => 'id', 'mode' => 'own'],
                'checkin_records'  => ['keys' => ['user_id'], 'ident' => 'id', 'mode' => 'own'],
                'user_widgets'     => ['keys' => ['user_id'], 'ident' => 'id', 'mode' => 'own'],
                'user_relations'   => ['keys' => ['user_id', 'target_id'], 'ident' => 'id', 'mode' => 'own'],
                // 私信以 QQ 为维度：pm_messages.who 是发送者 QQ，pm_read 是「按会话 + QQ」的已读游标
                'pm_messages'      => ['keys' => ['who'], 'ident' => 'qq', 'mode' => 'own'],
                'pm_read'          => ['keys' => ['qq'], 'ident' => 'qq', 'mode' => 'own'],
                'user_activity_logs' => ['keys' => ['user_id'], 'ident' => 'id', 'mode' => 'read'],
                'ai_logs'          => ['keys' => ['user_id'], 'ident' => 'id', 'mode' => 'read'],
            ];
        }

        /** 取某个用户的两种标识（数字 id + QQ 号） */
        public static function identities($fs, int $uid): array
        {
            $u = $fs->findById('users', $uid) ?: [];
            return ['id' => $uid, 'qq' => (string)($u['qq'] ?? '')];
        }

        /** 判断一行是否属于本人（按 ident 类型取对应字段） */
        public static function isMine(array $row, array $rule, array $ids): bool
        {
            $me = ($rule['ident'] ?? 'id') === 'qq' ? $ids['qq'] : $ids['id'];
            if ($me === '' || $me === 0) { return false; }
            foreach ($rule['keys'] as $k) {
                if (!array_key_exists($k, $row)) { continue; }
                if (($rule['ident'] ?? 'id') === 'qq') {
                    if ((string)$row[$k] === $me) { return true; }
                } else {
                    if ((int)$row[$k] === (int)$me) { return true; }
                }
            }
            return false;
        }

        /** 账号安全字段：导出时脱敏，导入时无条件忽略 */
        public static function protectedUserFields(): array
        {
            return [
                'password', 'password_hash', 'security_stamp', 'role', 'is_banned',
                'ban_reason', 'ban_until', 'points', 'growth', 'level',
            ];
        }

        /**
         * 导出指定用户的全部数据。
         * @return array{_meta:array, tables:array}
         */
        public static function export($fs, int $uid): array
        {
            $tables = [];
            $counts = [];
            $ids = self::identities($fs, $uid);
            foreach (self::tableRules() as $table => $rule) {
                $rows = [];
                foreach ((array)$fs->read($table) as $row) {
                    if (!self::isMine($row, $rule, $ids)) { continue; }
                    if ($table === 'users') {
                        foreach (self::protectedUserFields() as $f) { unset($row[$f]); }
                    }
                    $rows[] = $row;
                }
                if ($rows) {
                    $tables[$table] = $rows;
                    $counts[$table] = count($rows);
                }
            }
            $u = $fs->findById('users', $uid);
            return [
                '_meta' => [
                    'app'          => 'love_wall',
                    'kind'         => 'user_backup',
                    'version'      => self::FORMAT,
                    'exported_at'  => date('Y-m-d H:i:s'),
                    'user_id'      => $uid,
                    'nickname'     => (string)($u['nickname'] ?? ''),
                    'qq'           => (string)($u['qq'] ?? ''),
                    'counts'       => $counts,
                    // 附件不打包，只记录引用，恢复后原链接仍然可用
                    'attachments'  => 'referenced_only',
                ],
                'tables' => $tables,
            ];
        }

        /**
         * 从备份恢复。返回 {imported:表=>条数, skipped:表=>条数, rejected:int}
         * @param array $payload 导出的 JSON 解码结果
         */
        public static function restore($fs, int $uid, array $payload): array
        {
            $tables = $payload['tables'] ?? [];
            $meta = $payload['_meta'] ?? [];
            if (($meta['kind'] ?? '') !== 'user_backup') {
                return ['error' => '这不是个人备份文件（缺少 kind=user_backup 标记）'];
            }
            // 允许恢复到自己名下：备份归属人必须就是当前用户，
            // 否则 A 的备份能被 B 导入成 B 的数据（冒充他人帖子）
            if ((int)($meta['user_id'] ?? 0) !== $uid) {
                return ['error' => '备份文件属于其他账号，不能恢复到当前账号'];
            }

            $rules = self::tableRules();
            $ids = self::identities($fs, $uid);
            $imported = [];
            $skipped = [];
            $rejected = 0;

            foreach ($tables as $table => $rows) {
                $rule = $rules[$table] ?? null;
                if (!$rule || $rule['mode'] !== 'own') {
                    // 非白名单表 / 只读表：一律不写
                    $skipped[$table] = count((array)$rows);
                    continue;
                }
                if (!is_array($rows)) { continue; }
                $n = 0;
                foreach ($rows as $row) {
                    if (!is_array($row)) { continue; }
                    if ($table === 'users') {
                        // 用户主表只补「展示类」字段，安全字段全部忽略
                        $patch = [];
                        foreach (['nickname', 'avatar', 'bio', 'signature', 'theme', 'ui_prefs', 'pm_prefs'] as $f) {
                            if (array_key_exists($f, $row)) { $patch[$f] = $row[$f]; }
                        }
                        if ($patch) { $fs->update('users', $uid, $patch); $n++; }
                        continue;
                    }
                    // 归属校验：必须是本人名下的行，否则丢弃（防伪造他人数据）
                    if (!self::isMine($row, $rule, $ids)) { $rejected++; continue; }

                    // 主键冲突 → 更新；否则插入
                    $id = (int)($row['id'] ?? 0);
                    if ($id > 0 && $fs->findById($table, $id)) {
                        $fs->update($table, $id, $row);
                    } else {
                        $fs->insert($table, $row);
                    }
                    $n++;
                }
                if ($n > 0) { $imported[$table] = $n; }
            }
            // 索引在请求结束时自然失效；这里不做任何缓存清理（FileStorage 的索引是私有的）
            return ['imported' => $imported, 'skipped' => $skipped, 'rejected' => $rejected];
        }
    }
}
